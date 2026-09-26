<?php
/**
 * =============================================================================
 * workers/worker_absence_followup.php — Seguimiento de inasistencias sin
 * respuesta del acudiente.
 * =============================================================================
 * Flujo (sin respuesta → reintento/escalación configurable):
 *   1. attendance_incidents INASISTENCIA sin guardian_response en metadata y
 *      detected_at > ABSENCE_FOLLOWUP_MINUTES → reenvía el menú WhatsApp al
 *      acudiente (máx ABSENCE_FOLLOWUP_MAX recordatorios, espaciados
 *      ABSENCE_FOLLOWUP_MINUTES).
 *   2. Si agotó recordatorios y detected_at > ABSENCE_ESCALATE_MINUTES →
 *      notificación interna a las rutas ABSENCE_NO_REPLY (default
 *      COORDINATOR+RECTOR) + metadata escalated=true.
 *   3. Incidentes pending_context (anomalía grupal) NO se siguen aquí:
 *      esperan triaje humano, no WhatsApp.
 * =============================================================================
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/../lib/notify_routing.php';
// operations.php referencia securityLog() (definida en api.php) en sus catch —
// shim mínimo para contexto worker.
if (!function_exists('securityLog')) {
    function securityLog($event, $details = '', $actorId = null, $schoolId = null, $requestId = null) {
        error_log("[SECURITY_LOG] $event $details");
    }
}
require_once __DIR__ . '/../routes/operations.php'; // enqueueTwilioJob

$conn = $pdo; // enqueueTwilioJob usa global $conn (igual que api.php)

function logFU(string $e, string $m = ''): void { error_log("[ABSENCE_FOLLOWUP] {$e} | {$m}"); }

$MODE     = getenv('ABSENCE_FOLLOWUP_MODE') ?: 'cron';
$INTERVAL = (int)(getenv('ABSENCE_FOLLOWUP_INTERVAL') ?: 300); // 5 min default
$FOLLOWUP_MIN = (int)(getenv('ABSENCE_FOLLOWUP_MINUTES') ?: 60);
$FOLLOWUP_MAX = (int)(getenv('ABSENCE_FOLLOWUP_MAX') ?: 2);
$ESCALATE_MIN = (int)(getenv('ABSENCE_ESCALATE_MINUTES') ?: 240);

function absenceFollowupOnce(PDO $pdo, int $followupMin, int $followupMax, int $escalateMin): array {
    $out = ['reminded' => 0, 'escalated' => 0];
    $schools = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($schools as $sid) {
        // Contexto de sesión (no SET LOCAL): el loop no abre transacción, así
        // que `true` moriría al terminar el statement en autocommit.
        $pdo->exec("SELECT set_config('app.current_school_id', " . $pdo->quote($sid) . ", false)");
        $pdo->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', false)");

        // Inasistencias sin respuesta del acudiente, no-resueltas, no anomalía
        $stmt = $pdo->prepare("
            SELECT ai.incident_id, ai.student_id, ai.detected_at, ai.metadata_json,
                   s.first_name || ' ' || s.last_name AS student_name,
                   g.whatsapp_phone AS guardian_phone, g.guardian_id
            FROM attendance_incidents ai
            JOIN students s ON s.student_id = ai.student_id
            LEFT JOIN guardian_student_relationships gsr
                   ON gsr.student_id = s.student_id AND gsr.primary_guardian = TRUE
            LEFT JOIN guardians g ON g.guardian_id = gsr.guardian_id
            WHERE ai.school_id = ? AND ai.incident_type = 'INASISTENCIA'
              AND ai.resolved = FALSE
              AND ai.detected_at > NOW() - INTERVAL '2 days'
              AND COALESCE(ai.metadata_json->>'guardian_response', '') = ''
              AND COALESCE((ai.metadata_json->>'pending_context')::boolean, FALSE) = FALSE
              AND COALESCE((ai.metadata_json->>'escalated')::boolean, FALSE) = FALSE
            ORDER BY ai.detected_at ASC
        ");
        $stmt->execute([$sid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inc) {
            $meta = json_decode($inc['metadata_json'] ?? '{}', true) ?: [];
            $ageMin = (time() - strtotime($inc['detected_at'])) / 60;
            $sent = (int)($meta['reminders_sent'] ?? 0);
            $lastAt = isset($meta['last_reminder_at']) ? strtotime($meta['last_reminder_at']) : 0;

            // ── Escalación: agotó recordatorios y superó el tiempo ──
            if ($sent >= $followupMax && $ageMin >= $escalateMin) {
                $routeStmt = $pdo->prepare("
                    SELECT DISTINCT u.user_id FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE u.school_id = ? AND UPPER(r.role_name) IN (
                        SELECT UPPER(target_role) FROM school_notification_routes
                        WHERE school_id = ? AND event_kind = 'ABSENCE_NO_REPLY' AND enabled = TRUE
                        UNION ALL
                        SELECT 'COORDINATOR' WHERE NOT EXISTS (
                            SELECT 1 FROM school_notification_routes
                            WHERE school_id = ? AND event_kind = 'ABSENCE_NO_REPLY' AND enabled = TRUE)
                        UNION ALL
                        SELECT 'RECTOR' WHERE NOT EXISTS (
                            SELECT 1 FROM school_notification_routes
                            WHERE school_id = ? AND event_kind = 'ABSENCE_NO_REPLY' AND enabled = TRUE)
                    ) AND u.active = TRUE
                ");
                $routeStmt->execute([$sid, $sid, $sid, $sid]);
                foreach ($routeStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $pdo->prepare("
                        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, dedup_key)
                        VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, ?)
                        ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING
                    ")->execute([$sid, $uid,
                        'Inasistencia sin respuesta del acudiente',
                        "{$inc['student_name']} acumula una inasistencia sin respuesta del acudiente tras {$sent} recordatorios. Requiere gestión directa.",
                        json_encode(['incident_id' => $inc['incident_id'], 'student_id' => $inc['student_id'], 'reminders' => $sent]),
                        hash('sha256', 'noreply|' . $inc['incident_id'])]);
                    $out['escalated']++;
                }
                $meta['escalated'] = true;
                $pdo->prepare("UPDATE attendance_incidents SET metadata_json = ?::jsonb WHERE incident_id = ?::uuid AND school_id = ?")
                    ->execute([json_encode($meta), $inc['incident_id'], $sid]);
                logFU('ESCALATED', "incident={$inc['incident_id']} student={$inc['student_name']}");
                continue;
            }

            // ── Recordatorio: toca si pasó followupMin desde el último envío ──
            if ($ageMin >= $followupMin && $sent < $followupMax
                && (!$lastAt || (time() - $lastAt) >= $followupMin * 60)
                && !empty($inc['guardian_phone'])) {
                $phone = $inc['guardian_phone'];
                $msg = "📋 *NEXO — Recordatorio de inasistencia*\n\n"
                     . "Estudiante: {$inc['student_name']}\n\n"
                     . "Aún no hemos recibido su respuesta sobre la inasistencia.\n"
                     . "Responda:\n  *1* — La inasistencia está justificada\n  *2* — No estoy al tanto";
                $res = enqueueTwilioJob($phone, $msg, $sid, $inc['student_id'], $inc['guardian_id'], null, 'INASISTENCIA');
                if ($res['ok']) {
                    $meta['reminders_sent'] = $sent + 1;
                    $meta['last_reminder_at'] = date('c');
                    $pdo->prepare("UPDATE attendance_incidents SET metadata_json = ?::jsonb WHERE incident_id = ?::uuid AND school_id = ?")
                        ->execute([json_encode($meta), $inc['incident_id'], $sid]);
                    $out['reminded']++;
                    logFU('REMINDER_SENT', "incident={$inc['incident_id']} n=" . ($sent + 1));
                }
            }
        }
    }
    return $out;
}

if ($MODE === 'daemon') {
    logFU('INFO', "daemon iniciado — intervalo {$INTERVAL}s followup={$FOLLOWUP_MIN}min max={$FOLLOWUP_MAX} escal={$ESCALATE_MIN}min");
    while (true) {
        try {
            $redis = null;
            try { $redis = getRedisConnection(); } catch (Exception $e) {}
            if ($redis) try { $redis->set('worker:absence_followup:last_heartbeat', time()); } catch (Exception $e) {}
            $r = absenceFollowupOnce($pdo, $FOLLOWUP_MIN, $FOLLOWUP_MAX, $ESCALATE_MIN);
            if ($r['reminded'] || $r['escalated']) logFU('INFO', json_encode($r));
        } catch (Exception $e) { logFU('ERROR', $e->getMessage()); }
        sleep($INTERVAL);
    }
} else {
    try { absenceFollowupOnce($pdo, $FOLLOWUP_MIN, $FOLLOWUP_MAX, $ESCALATE_MIN); }
    catch (Exception $e) { logFU('ERROR', $e->getMessage()); }
}
