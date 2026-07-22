<?php
/**
 * =============================================================================
 * lib/RiskScoreEngine.php — Motor de cálculo de riesgo estudiantil.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Centraliza TODA la lógica de negocio del cálculo de métricas de riesgo
 * estudiantil. La base de datos únicamente almacena los resultados.
 *
 * Pesos del modelo:
 *   - WEIGHT_LATE     = 5.0  : puntos por llegada tarde.
 *   - WEIGHT_ABSENCE  = 15.0 : puntos por inasistencia.
 *   - WEIGHT_OVERFLOW = 0.5  : penalización por eventos adicionales > BASELINE_EVENTS.
 *   - BASELINE_EVENTS = 20   : línea base de eventos sin penalización.
 *   - MAX_SCORE       = 100.0: techo absoluto del puntaje.
 *
 * Umbrales de nivel:
 *   - CRITICAL: score >= 80
 *   - HIGH:     score >= 60
 *   - MEDIUM:   score >= 30
 *   - LOW:      score <  30
 *
 * Alertas:
 *   - ALERT_THRESHOLD = 70
 *   - ALERT_COOLDOWN_DAYS = 7 (mínimo entre alertas RISK_ALERT_ del mismo estudiante)
 *
 * Funciones:
 *   - computeScore(int, int, int): float
 *   - scoreToLevel(float): string
 *   - shouldAlert(float): bool
 *   - calculateAndStore(PDO, string, string, int): array
 *   - recalculateSchool(PDO, string): int
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - Conexión PDO pasada a los métodos estáticos.
 *   - Tablas: students, biometric_events, student_behavior_metrics, attendance_incidents.
 *
 * Es utilizado por:
 *   - routes/admin.php : recálculo manual/escuela.
 *   - routes/behavior.php : consulta de métricas HIGH/CRITICAL.
 *   - Futuro: cron job o worker para recálculo automático.
 */
class RiskScoreEngine
{
    // ── Pesos del modelo ──────────────────────────────────────────────────
    // Modifica estos valores sin tocar la base de datos.
    const WEIGHT_LATE     = 5.0;   // Puntos por cada llegada tarde
    const WEIGHT_ABSENCE  = 15.0;  // Puntos por cada inasistencia
    const WEIGHT_OVERFLOW = 0.5;   // Penalización por eventos adicionales > 20
    const BASELINE_EVENTS = 20;    // Línea base de eventos sin penalización
    const MAX_SCORE       = 100.0; // Techo absoluto del puntaje

    // ── Umbrales de nivel de riesgo ───────────────────────────────────────
    const THRESHOLD_CRITICAL   = 80.0;
    const THRESHOLD_HIGH       = 60.0;
    const THRESHOLD_MEDIUM     = 30.0;
    const ALERT_THRESHOLD      = 70.0; // Puntaje mínimo para emitir alerta
    const ALERT_COOLDOWN_DAYS  = 7;    // Días mínimos entre alertas del mismo tipo

    // ── Ventana de análisis por defecto ───────────────────────────────────
    const DEFAULT_WINDOW_DAYS  = 30;

    /**
     * Calcula el puntaje de riesgo a partir de conteos crudos.
     */
    public static function computeScore(int $lateCount, int $absenceCount, int $totalEvents): float
    {
        $overflow = max(0, $totalEvents - self::BASELINE_EVENTS) * self::WEIGHT_OVERFLOW;
        $raw      = ($lateCount * self::WEIGHT_LATE)
                  + ($absenceCount * self::WEIGHT_ABSENCE)
                  + $overflow;

        return min(self::MAX_SCORE, $raw);
    }

    /**
     * Traduce un puntaje numérico a un nivel de riesgo categórico.
     */
    public static function scoreToLevel(float $score): string
    {
        if ($score >= self::THRESHOLD_CRITICAL) return 'CRITICAL';
        if ($score >= self::THRESHOLD_HIGH)     return 'HIGH';
        if ($score >= self::THRESHOLD_MEDIUM)   return 'MEDIUM';
        return 'LOW';
    }

    /**
     * Determina si el puntaje supera el umbral de alerta.
     */
    public static function shouldAlert(float $score): bool
    {
        return $score >= self::ALERT_THRESHOLD;
    }

    /**
     * Calcula y persiste las métricas de riesgo de UN estudiante.
     *
     * @param PDO    $conn
     * @param string $studentId
     * @param string $schoolId
     * @param int    $windowDays
     * @return array  { metric_id, risk_score, risk_level, late_count, absence_count, threshold_exceeded }
     */
    public static function calculateAndStore(
        PDO $conn,
        string $studentId,
        string $schoolId,
        int $windowDays = self::DEFAULT_WINDOW_DAYS
    ): array {
        // 1. Obtener conteos crudos de la DB (pura persistencia, sin lógica)
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) FILTER (WHERE event_type LIKE 'INGRESO_TARDE%') AS late_count,
                COUNT(*) FILTER (WHERE event_type LIKE 'INASISTENCIA%')  AS absence_count,
                COUNT(*)                                                  AS total_events
            FROM biometric_events
            WHERE student_id = ?
              AND school_id  = ?
              AND event_timestamp >= NOW() - (? || ' days')::INTERVAL
        ");
        $stmt->execute([$studentId, $schoolId, $windowDays]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC);

        $lateCount    = (int)($counts['late_count']    ?? 0);
        $absenceCount = (int)($counts['absence_count'] ?? 0);
        $totalEvents  = (int)($counts['total_events']  ?? 0);

        // 2. Calcular con la lógica de negocio en PHP
        $riskScore = self::computeScore($lateCount, $absenceCount, $totalEvents);
        $riskLevel = self::scoreToLevel($riskScore);

        // 3. Persistir resultado en student_behavior_metrics (UPSERT)
        $upsert = $conn->prepare("
            INSERT INTO student_behavior_metrics
                (school_id, student_id, calculated_at, late_count, absence_count,
                 total_events, risk_score, risk_level, calculation_window_days, metadata_json)
            VALUES
                (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (student_id, calculation_window_days) DO UPDATE SET
                calculated_at        = EXCLUDED.calculated_at,
                late_count           = EXCLUDED.late_count,
                absence_count        = EXCLUDED.absence_count,
                total_events         = EXCLUDED.total_events,
                risk_score           = EXCLUDED.risk_score,
                risk_level           = EXCLUDED.risk_level,
                metadata_json        = EXCLUDED.metadata_json
            RETURNING metric_id
        ");
        $meta = json_encode([
            'engine_version'   => '2.0',
            'weights'          => [
                'late'     => self::WEIGHT_LATE,
                'absence'  => self::WEIGHT_ABSENCE,
                'overflow' => self::WEIGHT_OVERFLOW,
            ],
            'alert_threshold'  => self::ALERT_THRESHOLD,
            'window_days'      => $windowDays,
        ], JSON_UNESCAPED_UNICODE);

        $upsert->execute([
            $schoolId, $studentId,
            $lateCount, $absenceCount, $totalEvents,
            $riskScore, $riskLevel, $windowDays,
            $meta
        ]);
        $metricId = $upsert->fetchColumn();

        // 4. Emitir alerta si supera el umbral (con cooldown de 7 días)
        if (self::shouldAlert($riskScore)) {
            $alertStmt = $conn->prepare("
                INSERT INTO attendance_incidents
                    (incident_id, school_id, student_id, incident_type, detected_at, metadata_json)
                SELECT
                    uuid_generate_v4(), ?, ?, ?, NOW(), ?::jsonb
                WHERE NOT EXISTS (
                    SELECT 1 FROM attendance_incidents
                    WHERE student_id  = ?
                      AND school_id   = ?
                      AND incident_type LIKE 'RISK_ALERT%'
                      AND detected_at >= NOW() - INTERVAL '" . self::ALERT_COOLDOWN_DAYS . " days'
                )
            ");
            $alertMeta = json_encode([
                'risk_score'        => $riskScore,
                'metric_id'         => $metricId,
                'late_count'        => $lateCount,
                'absence_count'     => $absenceCount,
                'trigger_threshold' => self::ALERT_THRESHOLD,
            ], JSON_UNESCAPED_UNICODE);

            $alertStmt->execute([
                $schoolId, $studentId,
                'RISK_ALERT_' . $riskLevel,
                $alertMeta,
                $studentId, $schoolId,
            ]);
        }

        return [
            'metric_id'         => $metricId,
            'risk_score'        => $riskScore,
            'risk_level'        => $riskLevel,
            'late_count'        => $lateCount,
            'absence_count'     => $absenceCount,
            'total_events'      => $totalEvents,
            'threshold_exceeded'=> self::shouldAlert($riskScore),
        ];
    }

    /**
     * Recalcula las métricas de todos los estudiantes activos de una escuela.
     *
     * @return int Número de estudiantes procesados
     */
    public static function recalculateSchool(PDO $conn, string $schoolId): int
    {
        $studentStmt = $conn->prepare("
            SELECT student_id FROM students WHERE school_id = ? AND active = TRUE
        ");
        $studentStmt->execute([$schoolId]);
        $students = $studentStmt->fetchAll(PDO::FETCH_COLUMN);

        $processed = 0;
        foreach ($students as $studentId) {
            try {
                self::calculateAndStore($conn, $studentId, $schoolId);
                $processed++;
            } catch (Throwable $e) {
                error_log("[RiskScoreEngine] Error en student $studentId: " . $e->getMessage());
            }
        }

        return $processed;
    }
}
