<?php
/**
 * =============================================================================
 * workers/contingency_lib.php — Librería de contingencia operativa.
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ----------------
 * Centraliza la lógica de salud de nodos edge y de anomalías agregadas:
 *   - Decisión PURA (sin BD): qué grupos quedan sin cobertura de nodo, qué
 *     dispositivos cambiaron de estado, cuándo un cluster de ausencias es
 *     anomalía operativa. Funciones testeables unitariamente.
 *   - Helpers BD: fetch de dispositivos, marcado SIN_DATOS_NODO, incidentes de
 *     seguridad, notificación a coordinación.
 *
 * REGLA DE NEGOCIO (gate de salud de nodo)
 * --------------------------------------
 *   Un grupo se considera SIN DATOS DE NODO solo si tiene AL MENOS un
 *   dispositivo asignado (edge_devices.group_id) Y TODOS sus dispositivos
 *   asignados están offline (last_ping NULL o más viejo que el umbral).
 *   Un grupo SIN dispositivo asignado NO se suprime: no hay forma de saber
 *   si tiene cobertura, así que se conserva la aproximación por group_id
 *   (TODO: modelar aula↔nodo completo).
 *
 * FLAGS (variables de entorno)
 * ----------------------------
 *   NODE_HEALTH_GATE          '1' (default) habilita el gate en detectores.
 *   NODE_OFFLINE_MINUTES      10 (default) minutos sin ping → nodo offline.
 *   ANOMALY_MIN_ABSENCES      5 (default) mínimo absoluto de ausencias nuevas.
 *   ANOMALY_GROUP_FRACTION    0.5 (default) fracción mínima del grupo ausente.
 * =============================================================================
 */

require_once __DIR__ . '/../lib/notify_routing.php';

// ──────────────────────────── Funciones puras ────────────────────────────

/**
 * Config del gate desde entorno (permite override en tests).
 */
function ctGateEnabled(): bool {
    return getenv('NODE_HEALTH_GATE') !== '0';
}

function ctOfflineSeconds(): int {
    // NODE_OFFLINE_SECONDS tiene precedencia (entornos de prueba/simulación);
    // en producción se usa NODE_OFFLINE_MINUTES (default 10 min).
    $secs = getenv('NODE_OFFLINE_SECONDS');
    if ($secs !== false && (int)$secs > 0) return (int)$secs;
    return ((int)(getenv('NODE_OFFLINE_MINUTES') ?: 10)) * 60;
}

/**
 * Dado el estado de los dispositivos, retorna los group_ids sin cobertura.
 * @param array $deviceRows [{device_id, group_id, active, last_ping_ts(int|null)}]
 * @return array group_id => true (set)
 */
function ctOfflineGroupIds(array $deviceRows, int $nowTs, int $offlineSeconds): array {
    $byGroup = [];
    foreach ($deviceRows as $d) {
        $gid = $d['group_id'] ?? null;
        if ($gid === null || $gid === '') continue;          // sin asignar → no aplica gate
        if (!($d['active'] ?? true)) continue;               // dispositivo inactivo → no cuenta
        $byGroup[$gid][] = $d;
    }
    $offline = [];
    foreach ($byGroup as $gid => $devs) {
        $allDown = true;
        foreach ($devs as $d) {
            $ping = $d['last_ping_ts'];
            if ($ping !== null && ($nowTs - (int)$ping) <= $offlineSeconds) {
                $allDown = false;
                break;
            }
        }
        if ($allDown) $offline[$gid] = true;
    }
    return $offline;
}

/**
 * Transiciones de salud de nodos (bidireccional):
 *   went_offline → dispositivos activos offline SIN incidente NODO_OFFLINE abierto.
 *   came_online  → dispositivos con incidente abierto que ya reportan ping fresco.
 * @param array $deviceRows             rows de ctFetchDeviceRows
 * @param array $openOfflineDeviceIds   device_ids con NODO_OFFLINE sin resolver (set: id=>true)
 * @return array ['went_offline'=>row[], 'came_online'=>row[]]
 */
function ctNodeTransitions(array $deviceRows, array $openOfflineDeviceIds, int $nowTs, int $offlineSeconds): array {
    $went = [];
    $came = [];
    foreach ($deviceRows as $d) {
        if (!($d['active'] ?? true)) continue;
        $ping = $d['last_ping_ts'];
        $isOffline = ($ping === null) || (($nowTs - (int)$ping) > $offlineSeconds);
        $hasOpen = isset($openOfflineDeviceIds[$d['device_id']]);
        if ($isOffline && !$hasOpen) {
            $went[] = $d;
        } elseif (!$isOffline && $hasOpen) {
            $came[] = $d;
        }
    }
    return ['went_offline' => $went, 'came_online' => $came];
}

/**
 * ¿Un conjunto de ausencias nuevas califica como anomalía operativa?
 * Ambos criterios deben cumplirse: mínimo absoluto Y fracción del grupo.
 */
function ctIsAnomalyCluster(int $absenteeCount, int $groupSize, int $minAbsences, float $minFraction): bool {
    if ($groupSize <= 0 || $absenteeCount < $minAbsences) return false;
    return ($absenteeCount / $groupSize) >= $minFraction;
}

// ──────────────────────────── Helpers BD ────────────────────────────
// Contrato: los helpers aceptan cualquier objeto con ->prepare() estilo PDO
// (@param PDO en producción; duck-typing permite los dobles de prueba sin la
// extensión pdo cargada). Asumen que el caller ya abrió transacción con
// contexto RLS (app.current_school_id + app.current_role) cuando aplique.

/**
 * Lee los dispositivos de una escuela como filas normalizadas para las
 * funciones puras. last_ping_ts = epoch (null si nunca reportó).
 */
function ctFetchDeviceRows($conn, string $schoolId): array {
    $stmt = $conn->prepare("
        SELECT ed.device_id, ed.device_name, ed.group_id, ed.active,
               ed.last_ping, ed.location,
               ag.group_name
        FROM edge_devices ed
        LEFT JOIN academic_groups ag ON ag.group_id = ed.group_id
        WHERE ed.school_id = ?
    ");
    $stmt->execute([$schoolId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'device_id'    => $r['device_id'],
            'device_name'  => $r['device_name'],
            'group_id'     => $r['group_id'],
            'group_name'   => $r['group_name'],
            'location'     => $r['location'],
            'active'       => (bool)$r['active'],
            'last_ping_ts' => $r['last_ping'] !== null ? strtotime((string)$r['last_ping']) : null,
        ];
    }
    return $rows;
}

/**
 * group_ids sin cobertura de nodo (fetch + decisión pura).
 * @return array group_id => true
 */
function ctGetOfflineGroupIds($conn, string $schoolId, int $offlineSeconds): array {
    return ctOfflineGroupIds(ctFetchDeviceRows($conn, $schoolId), time(), $offlineSeconds);
}

/**
 * Devuelve el group_id cuyo/los nodos están todos caídos para el estudiante,
 * o null si tiene cobertura viva / no se puede determinar.
 */
function ctStudentOfflineGroupId($conn, string $schoolId, string $studentId, int $offlineSeconds): ?string {
    $stmt = $conn->prepare("
        SELECT sga.group_id, ag.group_name FROM student_group_assignments sga
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE sga.student_id = ? AND sga.active = TRUE
    ");
    $stmt->execute([$studentId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($groups)) return null;
    $offline = ctGetOfflineGroupIds($conn, $schoolId, $offlineSeconds);
    $offlineGid = null;
    foreach ($groups as $g) {
        if (!isset($offline[$g['group_id']])) return null; // algún grupo con cobertura viva
        $offlineGid = $g['group_id'];
    }
    return $offlineGid;
}

function ctIsStudentNodeOffline($conn, string $schoolId, string $studentId, int $offlineSeconds): bool {
    return ctStudentOfflineGroupId($conn, $schoolId, $studentId, $offlineSeconds) !== null;
}

/**
 * Crea un security_incident. Retorna incident_id (uuid v4).
 */
function ctCreateSecurityIncident($conn, string $schoolId, string $type, string $severity, string $description, array $meta = []): string {
    $id = bin2hex(random_bytes(16));
    $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20, 12);
    $stmt = $conn->prepare("
        INSERT INTO security_incidents (incident_id, school_id, incident_type, severity_level, description, detected_at, metadata_json)
        VALUES (?::uuid, ?, ?, ?, ?, NOW(), ?::jsonb)
    ");
    $stmt->execute([$id, $schoolId, $type, $severity, $description, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
    return $id;
}

/**
 * Marca SIN_DATOS_NODO para un grupo (máx. 1 sin resolver por grupo/día).
 * Retorna true si se creó uno nuevo.
 */
function ctMarkNoNodeData($conn, string $schoolId, string $groupId, string $groupName, string $reason = ''): bool {
    $dup = $conn->prepare("
        SELECT 1 FROM security_incidents
        WHERE school_id = ? AND incident_type = 'SIN_DATOS_NODO' AND resolved = FALSE
          AND (detected_at AT TIME ZONE 'America/Bogota')::date
              = (NOW() AT TIME ZONE 'America/Bogota')::date
          AND metadata_json->>'group_id' = ?
        LIMIT 1
    ");
    $dup->execute([$schoolId, $groupId]);
    if ($dup->fetchColumn()) return false;
    ctCreateSecurityIncident($conn, $schoolId, 'SIN_DATOS_NODO', 'MEDIUM',
        "Sin datos del nodo del grupo {$groupName}: detección de asistencia/evasión suspendida hasta que el nodo reporte.",
        ['group_id' => $groupId, 'group_name' => $groupName, 'reason' => $reason]);
    return true;
}

/**
 * Registra presencia manual — inserta el evento INGRESO_MANUAL (cuenta
 * como presencia porque matchea event_type LIKE 'INGRESO_%') y el incidente
 * REGISTRO_MANUAL para trazabilidad. Ambas escrituras en una sola llamada
 * (dentro de la transacción/RLS context del caller).
 */
function ctRegisterManualPresence($conn, string $schoolId, string $studentId, string $deviceId, string $metaJson): void {
    $conn->prepare("
        INSERT INTO biometric_events (event_id, school_id, student_id, device_id, event_type, event_result, event_timestamp, metadata_json)
        VALUES (uuid_generate_v4(), ?, ?, ?, 'INGRESO_MANUAL', 'PROCESSED', NOW(), ?::jsonb)
    ")->execute([$schoolId, $studentId, $deviceId, $metaJson]);

    $conn->prepare("
        INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
        VALUES (uuid_generate_v4(), ?, ?, 'REGISTRO_MANUAL', NOW(), ?::jsonb)
    ")->execute([$schoolId, $studentId, $metaJson]);
}

// ──────────────────────────── Telemetría del nodo ───────────────────────────
// Contrato de telemetría (edge → /devices/ping → ctProcessTelemetry):
//   clock_drift_s, disk_free_mb, pending_events, dlq_count, cpu_temp_c,
//   power_state (MAINS|BATTERY|LOW_BATTERY|CRITICAL|UNKNOWN),
//   cell {interface_up, registered, signal_pct, carrier, tech}

/** Umbrales de telemetría (configurables por env). */
function ctTelemetryThresholds(): array {
    return [
        'temp_high_c'     => (int)(getenv('TELEM_TEMP_HIGH_C')   ?: 80),
        'temp_crit_c'     => (int)(getenv('TELEM_TEMP_CRIT_C')   ?: 90),
        'disk_low_mb'     => (int)(getenv('TELEM_DISK_LOW_MB')   ?: 512),
        'disk_crit_mb'    => (int)(getenv('TELEM_DISK_CRIT_MB')  ?: 128),
        'clock_drift_s'   => (int)(getenv('TELEM_CLOCK_DRIFT_S') ?: 300),
        'dlq_backlog'     => (int)(getenv('TELEM_DLQ_BACKLOG')   ?: 20),
        'signal_low_pct'  => (int)(getenv('TELEM_SIGNAL_LOW_PCT')?: 15),
    ];
}

/**
 * Decisión pura: dado un payload de telemetría, devuelve las violaciones
 * [incident_type, severity, description]. No toca BD — testable directo.
 * Regla: solo se alerta sobre datos realmente presentes (sensor ausente no alerta).
 */
function ctTelemetryViolations(array $t, array $th): array {
    $viol = [];
    if (isset($t['cpu_temp_c']) && $t['cpu_temp_c'] !== null && $t['cpu_temp_c'] !== '') {
        $c = (int)$t['cpu_temp_c'];
        if ($c >= $th['temp_crit_c']) {
            $viol[] = ['TEMP_CRITICA', 'HIGH', "Temperatura del nodo crítica: {$c}°C"];
        } elseif ($c >= $th['temp_high_c']) {
            $viol[] = ['TEMP_ALTA', 'MEDIUM', "Temperatura del nodo elevada: {$c}°C"];
        }
    }
    if (isset($t['disk_free_mb']) && (int)$t['disk_free_mb'] >= 0) {
        $mb = (int)$t['disk_free_mb'];
        if ($mb < $th['disk_crit_mb']) {
            $viol[] = ['DISCO_CRITICO', 'HIGH', "Disco del nodo casi lleno: {$mb}MB libres — riesgo de pérdida de datos"];
        } elseif ($mb < $th['disk_low_mb']) {
            $viol[] = ['DISCO_BAJO', 'MEDIUM', "Disco del nodo bajo: {$mb}MB libres"];
        }
    }
    if (isset($t['clock_drift_s']) && (int)$t['clock_drift_s'] > $th['clock_drift_s']) {
        $viol[] = ['RELOJ_DESVIADO', 'MEDIUM', "Reloj del nodo desviado " . (int)$t['clock_drift_s'] . "s — los eventos pueden quedar fuera de ventana"];
    }
    if (isset($t['dlq_count']) && (int)$t['dlq_count'] > $th['dlq_backlog']) {
        $viol[] = ['DLQ_BACKLOG', 'HIGH', "Cola de errores (DLQ) del nodo con " . (int)$t['dlq_count'] . " registros — revisar sincronización"];
    }
    $ps = $t['power_state'] ?? 'UNKNOWN';
    if ($ps === 'CRITICAL') {
        $viol[] = ['ENERGIA_CRITICA', 'HIGH', 'Nodo en batería crítica — apagado ordenado inminente'];
    } elseif ($ps === 'LOW_BATTERY' || $ps === 'BATTERY') {
        $viol[] = ['ENERGIA_RESPALDO', $ps === 'LOW_BATTERY' ? 'HIGH' : 'MEDIUM', 'Nodo operando con batería de respaldo (UPS)'];
    }
    if (!empty($t['tamper_open']) && $t['tamper_open'] !== 'false') {
        $viol[] = ['TAMPER_OPEN', 'HIGH', 'Apertura física del gabinete del nodo detectada — posible manipulación'];
    }
    $cell = $t['cell'] ?? [];
    if (is_array($cell)) {
        if (array_key_exists('interface_up', $cell) && $cell['interface_up'] === false) {
            $viol[] = ['SENAL_PERDIDA', 'HIGH', 'Interfaz celular del nodo caída (operstate=down)'];
        } elseif (isset($cell['signal_pct']) && $cell['signal_pct'] >= 0 && $cell['signal_pct'] < $th['signal_low_pct']) {
            $viol[] = ['SENAL_BAJA', 'MEDIUM', "Señal celular débil: {$cell['signal_pct']}%"];
        }
    }
    return $viol;
}

/**
 * Procesa la telemetría de un nodo: persiste en edge_devices.telemetry_json y
 * crea security_incidents deduplicados (máx 1 sin resolver por tipo/día/nodo).
 * Notifica a coordinación en severidad HIGH.
 * @return array incident_types creados (para tests/logs).
 */
function ctProcessTelemetry($conn, string $schoolId, string $deviceId, array $telemetry): array {
    // Persistir telemetría cruda (observabilidad — aunque no haya violaciones)
    $conn->prepare("UPDATE edge_devices SET telemetry_json = ?::jsonb, telemetry_at = NOW() WHERE device_id = ?::uuid")
        ->execute([json_encode($telemetry, JSON_UNESCAPED_UNICODE), $deviceId]);

    $th = ctTelemetryThresholds();
    $created = [];
    foreach (ctTelemetryViolations($telemetry, $th) as [$type, $sev, $desc]) {
        // Dedup: mismo tipo+nodo sin resolver hoy → no duplicar
        $dup = $conn->prepare("
            SELECT 1 FROM security_incidents
            WHERE school_id = ? AND incident_type = ? AND resolved = FALSE
              AND (detected_at AT TIME ZONE 'America/Bogota')::date
                  = (NOW() AT TIME ZONE 'America/Bogota')::date
              AND metadata_json->>'device_id' = ?
            LIMIT 1
        ");
        $dup->execute([$schoolId, $type, $deviceId]);
        if ($dup->fetchColumn()) continue;

        $incidentId = ctCreateSecurityIncident($conn, $schoolId, $type, $sev, $desc, [
            'device_id' => $deviceId, 'telemetry' => $telemetry,
        ]);
        $created[] = $type;
        if ($sev === 'HIGH') {
            ctNotifyCoordinators($conn, $schoolId, "Alerta de nodo: $type", $desc, [
                'device_id' => $deviceId, 'incident_type' => $type, 'security_incident_id' => $incidentId,
            ], "telem_{$deviceId}_{$type}_" . date('Ymd'), 'NODE_TELEMETRY');
        }
    }
    return $created;
}

/**
 * Inserta notificación interna a los destinatarios configurados por la escuela
 * para $eventKind en school_notification_routes (default COORDINATOR+RECTOR).
 * $dedupKey evita repetidos (uq_notifications_dedup).
 */
function ctNotifyCoordinators($conn, string $schoolId, string $title, string $message, array $meta = [], ?string $dedupKey = null, string $eventKind = 'SECURITY_INCIDENT'): int {
    $userIds = nexoRouteUserIds($conn, $schoolId, $eventKind, ['COORDINATOR', 'RECTOR']);
    $sent = 0;
    foreach ($userIds as $uid) {
        $key = $dedupKey !== null ? hash('sha256', $uid . '|' . $dedupKey) : null;
        if ($key !== null) {
            $stmt = $conn->prepare("
                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at, dedup_key)
                VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, NOW(), ?)
                ON CONFLICT (dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING
            ");
            $stmt->execute([$schoolId, $uid, $title, $message, json_encode($meta, JSON_UNESCAPED_UNICODE), $key]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
                VALUES (?, ?, ?, ?, 'ALERT', ?::jsonb, NOW())
            ");
            $stmt->execute([$schoolId, $uid, $title, $message, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        }
        $sent += $stmt->rowCount();
    }
    return $sent;
}
