<?php
/**
 * worker_biometric.php — Reliable Queue Pattern (Zero-Data-Loss).
 * Usa RPOPLPUSH para mover atómicamente de ingest -> processing.
 * Solo elimina de processing tras commit() exitoso en PostgreSQL.
 */

declare(ticks=1);
require_once __DIR__ . '/db.php';

$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });

function logW(string $e, string $m): void {
    error_log("[BIOMETRIC_WORKER] {$e} | {$m}");
}

function getRedis() {
    $r = new Redis();
    $r->connect(getenv('REDISHOST') ?: '127.0.0.1', getenv('REDISPORT') ?: 6379);
    if ($pass = getenv('REDIS_PASSWORD')) $r->auth($pass);
    return $r;
}

function processJob(array $job, PDO $conn): bool {
    $action = $job['action'] ?? 'UNKNOWN';
    $data   = $job['data'] ?? [];
    $instId = $job['school_id'] ?? 0;
    $capturedAt = (int)($data['captured_at'] ?? 0);

    switch ($action) {
        case 'SYNC_ATTENDANCE':
            $doc = trim($data['doc'] ?? '');
            $evt = strtoupper($data['event'] ?? '');
            $fingerprint = hash('sha256', implode(':', [
                (string)$instId,
                $doc,
                $evt,
                (string)$capturedAt
            ]));

            // FIX (SRE-1): Idempotencia vía fingerprint + ON CONFLICT DO NOTHING.
            // Si el worker re-procesa un job (ej. tras GC de zombies), el INSERT
            // es idempotente y no crea duplicados con distinto UUID.
            $stmt = $conn->prepare(
                "INSERT INTO biometric_events(event_id,school_id,student_id,event_type,event_timestamp,source_device,event_fingerprint)
                 SELECT uuid_generate_v4(),school_id,student_id,?,to_timestamp(?),'EDGE',?
                 FROM students WHERE document_number=? LIMIT 1
                 ON CONFLICT (event_fingerprint) DO NOTHING"
            );
            $stmt->execute([$evt, $capturedAt, $fingerprint, $doc]);
            return $stmt->rowCount() > 0;

        case 'REGISTER_STUDENT':
            $schoolId = $instId; $doc = trim($data['doc'] ?? '');
            $nombre = trim($data['nombre'] ?? '');
            $parentTel = trim($data['parent_tel'] ?? '');
            $parentDoc = trim($data['parent_doc'] ?? '');
            $parentName = trim($data['parent_name'] ?? '');
            if ($schoolId <= 0 || empty($doc) || empty($nombre)) return false;

            $conn->beginTransaction();
            try {
                $stmt = $conn->prepare("INSERT INTO students(school_id,document_number,first_name,last_name,active) VALUES(?,?,?,'',TRUE) ON CONFLICT(document_number) DO UPDATE SET first_name=EXCLUDED.first_name,active=TRUE RETURNING student_id");
                $stmt->execute([$schoolId, $doc, $nombre]);
                $studentId = $stmt->fetchColumn();

                if (!empty($parentDoc) && !empty($parentName)) {
                    $stmt = $conn->prepare("SELECT guardian_id FROM guardians WHERE document_number=?");
                    $stmt->execute([$parentDoc]);
                    $guardianId = $stmt->fetchColumn();
                    if ($guardianId) {
                        $stmt = $conn->prepare("UPDATE guardians SET full_name=?, whatsapp_phone=COALESCE(?,whatsapp_phone) WHERE guardian_id=?");
                        $stmt->execute([$parentName, $parentTel, $guardianId]);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO guardians(document_number,full_name,whatsapp_phone) VALUES(?,?,?) RETURNING guardian_id");
                        $stmt->execute([$parentDoc, $parentName, $parentTel]);
                        $guardianId = $stmt->fetchColumn();
                    }
                    $stmt = $conn->prepare("SELECT 1 FROM guardian_student_relationships WHERE student_id=? AND guardian_id=?");
                    $stmt->execute([$studentId, $guardianId]);
                    if (!$stmt->fetchColumn()) {
                        $stmt = $conn->prepare("INSERT INTO guardian_student_relationships(student_id,guardian_id,primary_guardian,relationship_type) VALUES(?,?,TRUE,'ACUDIENTE')");
                        $stmt->execute([$studentId, $guardianId]);
                    }
                }
                $conn->commit(); return true;
            } catch (Exception $e) {
                $conn->rollBack(); throw $e;
            }

        case 'DELETE_STUDENT':
            $doc = trim($data['doc'] ?? ''); $schoolId = $instId;
            if (empty($doc)) return false;
            if ($schoolId > 0) {
                $stmt = $conn->prepare("UPDATE students SET active=FALSE,biometric_hash=NULL WHERE document_number=? AND school_id=? RETURNING student_id");
                $stmt->execute([$doc, $schoolId]);
            } else {
                $stmt = $conn->prepare("UPDATE students SET active=FALSE,biometric_hash=NULL WHERE document_number=? RETURNING student_id");
                $stmt->execute([$doc]);
            }
            return true;

        default:
            logW('UNKNOWN', $action); return false;
    }
}

// ============================================================
// Reliable Queue: RPOPLPUSH atomically moves ingest -> processing
// ============================================================

// FIX (SRE-2): Script Lua atómico que hace RPOPLPUSH + inyecta timestamp.
// Esto garantiza que, si el worker muere, el GC pueda medir cuánto tiempo
// lleva el item en processing y reinsertarlo.
$scriptReliablePop = <<<'LUA'
local ingest = KEYS[1]
local processing = KEYS[2]
local now = tonumber(ARGV[1])
local item = redis.call('RPOPLPUSH', ingest, processing)
if item then
    local ok, job = pcall(cjson.decode, item)
    if ok and job then
        job.processing_since = now
        local newItem = cjson.encode(job)
        redis.call('LREM', processing, 0, item)
        redis.call('LPUSH', processing, newItem)
        return newItem
    end
    return item
end
return nil
LUA;

// FIX (SRE-2): Garbage Collector — reinserta en ingest los jobs zombies
// (más de 5 minutos en processing sin commit exitoso).
$scriptGc = <<<'LUA'
local processing = KEYS[1]
local ingest = KEYS[2]
local maxAge = tonumber(ARGV[1])
local now = tonumber(ARGV[2])
local items = redis.call('LRANGE', processing, 0, -1)
local recovered = 0
for i = 1, #items do
    local item = items[i]
    local ok, job = pcall(cjson.decode, item)
    if ok and job and job.processing_since then
        if (now - job.processing_since) > maxAge then
            redis.call('LREM', processing, 0, item)
            redis.call('LPUSH', ingest, item)
            recovered = recovered + 1
        end
    else
        redis.call('LREM', processing, 0, item)
        redis.call('LPUSH', ingest, item)
        recovered = recovered + 1
    end
end
return recovered
LUA;

$GC_MAX_AGE_SEC = (int)(getenv('BIOMETRIC_GC_MAX_AGE') ?: 300);

logW('START', 'Biometric async worker started');
$redis = getRedis();
$iterations = 0;
$lastGc = 0;

while (!$shutdown) {
    try {
        // FIX (SRE-2): Atomic Lua pop + timestamp injection.
        $item = $redis->eval($scriptReliablePop, ['queue:biometric_ingest', 'queue:biometric_processing', time()], 2);
        if (!$item) { usleep(50000); continue; }

        $job = json_decode($item, true);
        if (!$job) {
            $redis->lRem('queue:biometric_processing', $item, 0);
            continue;
        }

        try {
            $ok = processJob($job, $pdo);
            if ($ok) {
                // ONLY remove from processing AFTER successful commit()
                $redis->lRem('queue:biometric_processing', $item, 0);
                logW('OK', sprintf("action=%s req=%s", $job['action'] ?? '?', $job['request_id'] ?? 'n/a'));
            } else {
                // Logic failure: move to retry, remove from processing
                $redis->lPush('queue:biometric_ingest_retry', $item);
                $redis->lRem('queue:biometric_processing', $item, 0);
            }
        } catch (Exception $e) {
            logW('ERR', $e->getMessage());
            // PHP crashed or DB failed: requeue to main, remove from processing
            $redis->lPush('queue:biometric_ingest', $item);
            $redis->lRem('queue:biometric_processing', $item, 0);
        }
    } catch (Exception $e) {
        logW('FATAL', $e->getMessage());
        sleep(2); $redis = getRedis();
    }

    // FIX (SRE-2): Ejecutar GC de zombies cada 60 segundos.
    if (time() - $lastGc >= 60) {
        $lastGc = time();
        try {
            $recovered = $redis->eval($scriptGc, ['queue:biometric_processing', 'queue:biometric_ingest'], 2, $GC_MAX_AGE_SEC, time());
            if ($recovered > 0) {
                logW('GC_ZOMBIE', "Recovered {$recovered} zombie job(s) after {$GC_MAX_AGE_SEC}s");
            }
        } catch (Exception $e) {
            logW('GC_ERR', $e->getMessage());
        }
    }

    if (++$iterations % 1000 === 0) {
        gc_collect_cycles();
        $mem = memory_get_peak_usage(true) / 1024 / 1024;
        if ($mem > 256) { logW('MEM', "{$mem}MB > 256MB restart"); exit(0); }
    }
}
logW('STOP', 'Biometric worker shutdown'); exit(0);
