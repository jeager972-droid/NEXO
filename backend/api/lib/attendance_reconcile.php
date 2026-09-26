<?php
/**
 * =============================================================================
 * attendance_reconcile.php — Reconciliación INASISTENCIA ↔ ingreso tardío.
 * =============================================================================
 * Un INGRESO_* posterior a una INASISTENCIA abierta del día no puede dejar al
 * estudiante marcado como ausente. Esta función:
 *   1. Resuelve (resolved=TRUE) las inasistencias abiertas del día del
 *      estudiante, anotando en metadata_json el evento que la reconcilió y el
 *      espacio (classroom/schedule) donde reapareció.
 *   2. Genera un incidente REAPARICION_TARDIA cuando la reconciliación ocurre
 *      — visible para coordinación (llegó tarde o por otro espacio).
 *
 * Llamada desde dos puntos: el ingest síncrono de api.php (SYNC_ATTENDANCE se
 * procesa directo en PG aunque Redis esté caído) y worker_biometric.php
 * (procesamiento de cola; cubre eventos que solo viajaron por Redis).
 *
 * Requiere: transacción abierta por el llamador (o autocommit) + contexto RLS
 * ya configurado (app.current_school_id / app.current_role).
 * =============================================================================
 */

if (!function_exists('nexoReconcileAbsence')) {
    /**
     * @return int número de inasistencias reconciliadas
     */
    function nexoReconcileAbsence(PDO $conn, string $schoolId, ?string $studentId,
                                  string $eventType, ?string $classroomId = null,
                                  ?string $scheduleId = null): int {
        if (!$studentId || strpos($eventType, 'INGRESO_') !== 0) return 0;

        // Ventana "hoy" en Bogotá comparando fechas locales — no instants UTC
        // con corte de sesión (el patrón `>= (NOW() AT TIME ZONE ...)::date`
        // desplaza el límite ~5h y excluye eventos de la noche).
        $recStmt = $conn->prepare("
            UPDATE attendance_incidents
            SET resolved = TRUE,
                metadata_json = COALESCE(metadata_json, '{}'::jsonb) || ?::jsonb
            WHERE school_id = ? AND student_id = ?
              AND incident_type IN ('INASISTENCIA','INASISTENCIA_JUSTIFICADA')
              AND resolved = FALSE
              AND (detected_at AT TIME ZONE 'America/Bogota')::date
                  = (NOW() AT TIME ZONE 'America/Bogota')::date
            RETURNING incident_id
        ");
        $recStmt->execute([
            json_encode([
                'reconciled_by'           => $eventType,
                'reconciled_classroom_id' => $classroomId,
                'reconciled_schedule_id'  => $scheduleId,
            ], JSON_UNESCAPED_UNICODE),
            $schoolId, $studentId,
        ]);
        $reconciled = $recStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($reconciled)) return 0;

        error_log("[BIOMETRIC] INASISTENCIA reconciled for student {$studentId} (" . count($reconciled) . " incidents)");

        $reaStmt = $conn->prepare("
            INSERT INTO attendance_incidents (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
            VALUES (uuid_generate_v4(), ?, ?, 'REAPARICION_TARDIA', NOW(), ?::jsonb)
        ");
        $reaStmt->execute([$schoolId, $studentId, json_encode([
            'reconciled_incidents' => $reconciled,
            'event_type'           => $eventType,
            'classroom_id'         => $classroomId,
            'schedule_id'          => $scheduleId,
        ], JSON_UNESCAPED_UNICODE)]);

        return count($reconciled);
    }
}
