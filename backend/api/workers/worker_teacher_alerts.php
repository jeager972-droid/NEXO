<?php
/**
 * =============================================================================
 * workers/worker_teacher_alerts.php — Evaluador de criterios de aviso docente.
 * =============================================================================
 * F-18 / Documento §4.5-§9.9: el docente configura "N eventos en M días" por
 * grupo/estudiante (o todos los suyos). Este worker evalúa las reglas activas
 * cada TEACHER_ALERTS_INTERVAL segundos y crea una notificación interna al
 * docente cuando un estudiante alcanza el umbral — dedup por regla+estudiante+día.
 *
 * Mapeo event_kind → fuente real:
 *   LATE              → biometric_events.event_type = 'INGRESO_TARDE'
 *   ABSENCE           → attendance_incidents.incident_type LIKE 'INASISTENCIA%'
 *   EVASION           → attendance_incidents.incident_type = 'EVASION_INTERNA'
 *   EXIT              → biometric_events.event_type = 'SALIDA_AULA'
 *   PERMISSION_EXPIRY → class_exit_authorizations.status = 'EXPIRED'
 * =============================================================================
 */

require_once __DIR__ . '/../core/db.php';

function logTA(string $e, string $m = ''): void {
    error_log("[TEACHER_ALERTS] {$e} | {$m}");
}

$MODE = getenv('TEACHER_ALERTS_MODE') ?: 'cron';
$INTERVAL = (int)(getenv('TEACHER_ALERTS_INTERVAL') ?: 60);

$KIND_SQL = [
    'LATE'    => "SELECT s.student_id, s.first_name, s.last_name, sga.group_id, COUNT(*) AS n
                  FROM biometric_events be
                  JOIN students s ON s.student_id = be.student_id
                  JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                  WHERE be.school_id = :sid AND be.event_type = 'INGRESO_TARDE'
                    AND be.event_timestamp > NOW() - make_interval(days => :win)
                  GROUP BY s.student_id, s.first_name, s.last_name, sga.group_id",
    'ABSENCE' => "SELECT s.student_id, s.first_name, s.last_name, sga.group_id, COUNT(*) AS n
                  FROM attendance_incidents ai
                  JOIN students s ON s.student_id = ai.student_id
                  JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                  WHERE ai.school_id = :sid AND ai.incident_type LIKE 'INASISTENCIA%'
                    AND ai.detected_at > NOW() - make_interval(days => :win)
                  GROUP BY s.student_id, s.first_name, s.last_name, sga.group_id",
    'EVASION' => "SELECT s.student_id, s.first_name, s.last_name, sga.group_id, COUNT(*) AS n
                  FROM attendance_incidents ai
                  JOIN students s ON s.student_id = ai.student_id
                  JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                  WHERE ai.school_id = :sid AND ai.incident_type = 'EVASION_INTERNA'
                    AND ai.detected_at > NOW() - make_interval(days => :win)
                  GROUP BY s.student_id, s.first_name, s.last_name, sga.group_id",
    'EXIT'    => "SELECT s.student_id, s.first_name, s.last_name, sga.group_id, COUNT(*) AS n
                  FROM biometric_events be
                  JOIN students s ON s.student_id = be.student_id
                  JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                  WHERE be.school_id = :sid AND be.event_type = 'SALIDA_AULA'
                    AND be.event_timestamp > NOW() - make_interval(days => :win)
                  GROUP BY s.student_id, s.first_name, s.last_name, sga.group_id",
    'PERMISSION_EXPIRY' => "SELECT s.student_id, s.first_name, s.last_name, sga.group_id, COUNT(*) AS n
                  FROM class_exit_authorizations cea
                  JOIN students s ON s.student_id = cea.student_id
                  JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
                  WHERE cea.school_id = :sid AND cea.status = 'EXPIRED'
                    AND cea.exit_time > NOW() - make_interval(days => :win)
                  GROUP BY s.student_id, s.first_name, s.last_name, sga.group_id",
];

$KIND_LABEL = [
    'LATE' => 'llegadas tarde', 'ABSENCE' => 'inasistencias', 'EVASION' => 'evasiones',
    'EXIT' => 'salidas del aula', 'PERMISSION_EXPIRY' => 'permisos vencidos sin retorno',
];

function evaluateRules(PDO $pdo): int {
    global $KIND_SQL, $KIND_LABEL;
    $notified = 0;
    $schools = $pdo->query("SELECT school_id FROM schools WHERE active = TRUE")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($schools as $sid) {
        // RLS: operar como SYSTEM_WORKER en el contexto de la escuela
        // Contexto de sesión (no SET LOCAL): sin transacción abierta, el modo
        // local moriría al final del statement en autocommit.
        $pdo->exec("SELECT set_config('app.current_school_id', " . $pdo->quote($sid) . ", false)");
        $pdo->exec("SELECT set_config('app.current_role', 'SYSTEM_WORKER', false)");

        $rules = $pdo->prepare("SELECT * FROM teacher_alert_rules WHERE school_id = ? AND active = TRUE");
        $rules->execute([$sid]);
        foreach ($rules->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $sql = $KIND_SQL[$rule['event_kind']] ?? null;
            if (!$sql) continue;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid' => $sid, ':win' => (int)$rule['window_days']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // Filtrar por alcance de la regla (grupo/estudiante/todos)
                if ($rule['group_id'] && $row['group_id'] !== $rule['group_id']) continue;
                if ($rule['student_id'] && $row['student_id'] !== $rule['student_id']) continue;
                if ((int)$row['n'] < (int)$rule['threshold_count']) continue;

                $dedup = hash('sha256', 'talert|' . $rule['rule_id'] . '|' . $row['student_id'] . '|' . date('Ymd'));
                $msg = sprintf(
                    "%s %s acumula %d %s en %d días (umbral: %d).",
                    $row['first_name'], $row['last_name'], (int)$row['n'],
                    $KIND_LABEL[$rule['event_kind']], (int)$rule['window_days'], (int)$rule['threshold_count']
                );
                $ins = $pdo->prepare("
                    INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, dedup_key)
                    VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, ?)
                    ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING
                ");
                $ins->execute([$sid, $rule['teacher_user_id'],
                    'Criterio docente alcanzado', $msg,
                    json_encode(['rule_id' => $rule['rule_id'], 'student_id' => $row['student_id'],
                                 'event_kind' => $rule['event_kind'], 'count' => (int)$row['n']]),
                    $dedup]);
                $notified += $ins->rowCount();
            }
        }
    }
    return $notified;
}

if ($MODE === 'daemon') {
    logTA('INFO', '[teacher-alerts] daemon iniciado, intervalo ' . $INTERVAL . 's');
    require_once __DIR__ . '/../core/redis.php';
    while (true) {
        try {
            $redis = null;
            try { $redis = getRedisConnection(); } catch (Exception $e) {}
            if ($redis) try { $redis->set('worker:teacher_alerts:last_heartbeat', time()); } catch (Exception $e) {}
            $n = evaluateRules($pdo); if ($n) logTA('INFO', "[teacher-alerts] $n notificaciones");
        } catch (Exception $e) { logTA('ERROR', '[teacher-alerts] ' . $e->getMessage()); }
        sleep($INTERVAL);
    }
} else {
    try { evaluateRules($pdo); } catch (Exception $e) { logTA('ERROR', '[teacher-alerts] ' . $e->getMessage()); }
}
