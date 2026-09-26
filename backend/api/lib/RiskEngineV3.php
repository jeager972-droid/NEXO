<?php
/**
 * =============================================================================
 * lib/RiskEngineV3.php — Motor de Análisis de Riesgo Pedagógico v3.0
 * =============================================================================
 *
 * ARQUITECTURA DE 7 CAPAS:
 *   Capa 1: Ingesta, deduplicación y filtros de contexto (SQL trigger)
 *   Capa 2: Políticas institucionales configurables (versionadas) — esta clase
 *   Capa 3: Scoring con decaimiento exponencial + clustering (SQL fn_)
 *   Capa 4: Combinación/correlación entre categorías — esta clase
 *   Capa 5: Escalamiento y alertas (máquina de estados) (SQL fn_)
 *   Capa 6: Auditoría y versionado de políticas — esta clase
 *   Capa 7: Decisión humana (Coordinación) — via API + Frontend
 *   Capa 8 (opcional): Anomalía estadística (z-score individual) — esta clase
 *
 * NIVELES DE GRAVEDAD (ontología NEXO, no editable):
 *   SIN_IMPORTANCIA: no alimenta el cálculo de riesgo
 *   LEVE:           4 reincidencias en 7 días activa alerta a coordinación
 *   MODERADA:        3 reincidencias en 10 días activa alerta a coordinación
 *   ALTA:            2 reincidencias en 15 días, requiere revisión humana
 *   MUY_ALTA:        1 ocurrencia activa alerta inmediata a coordinación
 *
 * LO QUE LA INSTITUCIÓN CONFIGURA:
 *   - Mapeo evento → nivel (qué evento es LEVE, MODERADA, etc.)
 *   - Vida media (dentro de rangos protegidos)
 *   - Umbrales (dentro de rangos protegidos)
 *   - Cooldowns
 *   - Textos de notificación
 *
 * LO PROTEGIDO (no editable):
 *   - Definición abstracta de los 5 niveles
 *   - MUY_ALTA siempre single-occurrence
 *   - Rangos límite (pisos y techos)
 *   - Requisitos de deduplicación y filtros de contexto
 *   - Registro de auditoría
 *   - Lenguaje no-diagnóstico de las alertas
 *
 * DEPENDENCIAS:
 *   - PostgreSQL con funciones fn_evaluate_student_risk, fn_calculate_category_risk
 *   - Tablas: risk_policies, risk_event_level_mapping, risk_rules,
 *     risk_combination_rules, risk_active_snapshot, risk_alerts,
 *     risk_justifications, risk_audit_log, school_calendar
 */
class RiskEngineV3
{
    // ── Niveles de riesgo (ontología NEXO) ────────────────────────────────
    const LEVEL_NONE           = 'NONE';
    const LEVEL_SIN_IMPORTANCIA = 'SIN_IMPORTANCIA';
    const LEVEL_LEVE           = 'LEVE';
    const LEVEL_MODERADA       = 'MODERADA';
    const LEVEL_ALTA           = 'ALTA';
    const LEVEL_MUY_ALTA       = 'MUY_ALTA';

    // ── Estados de escalamiento (máquina de estados) ──────────────────────
    const STATE_OBSERVACION              = 'OBSERVACION';
    const STATE_ALERTA_PEDAGOGICA        = 'ALERTA_PEDAGOGICA';
    const STATE_SEGUIMIENTO              = 'SEGUIMIENTO';
    const STATE_INTERVENCION_PRIORITARIA = 'INTERVENCION_PRIORITARIA';
    const STATE_ATENCION_INMEDIATA       = 'ATENCION_INMEDIATA';

    // ── Estados de alerta ─────────────────────────────────────────────────
    const ALERT_ABIERTA        = 'abierta';
    const ALERT_EN_SEGUIMIENTO = 'en_seguimiento';
    const ALERT_RESUELTA       = 'resuelta';
    const ALERT_DESCARTADA     = 'descartada';

    // ── Orden de severidad de niveles ─────────────────────────────────────
    private static $levelOrder = [
        'NONE' => 0, 'SIN_IMPORTANCIA' => 0, 'LEVE' => 1,
        'MODERADA' => 2, 'ALTA' => 3, 'MUY_ALTA' => 4
    ];

    /**
     * Compara dos niveles: retorna -1, 0, o 1.
     */
    public static function compareLevels(string $a, string $b): int
    {
        $oa = self::$levelOrder[$a] ?? 0;
        $ob = self::$levelOrder[$b] ?? 0;
        return $oa <=> $ob;
    }

    /**
     * Retorna el nivel más alto entre dos.
     */
    public static function maxLevel(string $a, string $b): string
    {
        return self::compareLevels($a, $b) >= 0 ? $a : $b;
    }

    // =========================================================================
    // CAPA 2: GESTIÓN DE POLÍTICAS INSTITUCIONALES (VERSIONADAS)
    // =========================================================================

    /**
     * Obtiene la política activa de una escuela.
     * @return array|null
     */
    public static function getActivePolicy(PDO $conn, string $schoolId): ?array
    {
        $stmt = $conn->prepare("
            SELECT policy_id, version, is_active, activated_at, snapshot_json,
                   created_by, created_at, change_reason
            FROM risk_policies
            WHERE school_id = ? AND is_active = TRUE
            ORDER BY version DESC LIMIT 1
        ");
        $stmt->execute([$schoolId]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);
        return $policy ?: null;
    }

    /**
     * Obtiene todas las versiones de política de una escuela (historial).
     */
    public static function getPolicyHistory(PDO $conn, string $schoolId): array
    {
        $stmt = $conn->prepare("
            SELECT policy_id, version, is_active, activated_at, deactivated_at,
                   created_by, created_at, change_reason
            FROM risk_policies
            WHERE school_id = ?
            ORDER BY version DESC
        ");
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene la configuración completa de una política:
     * reglas por nivel, mapeo evento-nivel, reglas de combinación.
     */
    public static function getPolicyConfig(PDO $conn, string $policyId): array
    {
        // Reglas por nivel
        $rulesStmt = $conn->prepare("
            SELECT risk_level, weight_base, half_life_days, activation_threshold,
                   cooldown_days, single_occurrence, requires_human_review,
                   recurrence_count, window_days,
                   min_recurrence, max_recurrence, min_window_days, max_window_days,
                   min_weight, max_weight, min_half_life, max_half_life,
                   min_threshold, max_threshold
            FROM risk_rules WHERE policy_id = ?
            ORDER BY
                CASE risk_level
                    WHEN 'LEVE' THEN 1 WHEN 'MODERADA' THEN 2
                    WHEN 'ALTA' THEN 3 WHEN 'MUY_ALTA' THEN 4
                END
        ");
        $rulesStmt->execute([$policyId]);
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Mapeo evento → nivel
        $mappingStmt = $conn->prepare("
            SELECT elm.risk_level, elm.student_id, elm.override_reason,
                   ret.type_code, ret.display_name, ret.description, ret.category
            FROM risk_event_level_mapping elm
            JOIN risk_event_types ret ON ret.event_type_id = elm.event_type_id
            WHERE elm.policy_id = ? AND elm.student_id IS NULL
            ORDER BY ret.category, ret.type_code
        ");
        $mappingStmt->execute([$policyId]);
        $mapping = $mappingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Overrides individuales
        $overrideStmt = $conn->prepare("
            SELECT elm.risk_level, elm.student_id, elm.override_reason,
                   ret.type_code, ret.display_name,
                   s.first_name, s.last_name
            FROM risk_event_level_mapping elm
            JOIN risk_event_types ret ON ret.event_type_id = elm.event_type_id
            JOIN students s ON s.student_id = elm.student_id
            WHERE elm.policy_id = ? AND elm.student_id IS NOT NULL
            ORDER BY s.last_name, s.first_name
        ");
        $overrideStmt->execute([$policyId]);
        $overrides = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);

        // Reglas de combinación
        $comboStmt = $conn->prepare("
            SELECT combo_rule_id, rule_name, condition_json, result_level,
                   result_reason, is_active
            FROM risk_combination_rules WHERE policy_id = ? AND is_active = TRUE
        ");
        $comboStmt->execute([$policyId]);
        $combos = $comboStmt->fetchAll(PDO::FETCH_ASSOC);

        // Parsear condition_json (PostgreSQL JSONB llega como string)
        foreach ($combos as &$combo) {
            $combo['condition'] = json_decode($combo['condition_json'], true);
            unset($combo['condition_json']);
        }

        return [
            'rules'      => $rules,
            'mapping'    => $mapping,
            'overrides'  => $overrides,
            'combos'     => $combos,
        ];
    }

    /**
     * Crea una nueva versión de política (nunca edita in-place).
     * Recibe la configuración completa y crea un snapshot versionado.
     *
     * @param PDO $conn
     * @param string $schoolId
     * @param string $userId
     * @param string $reason  Motivo obligatorio del cambio
     * @param array $config   {rules: [...], mapping: [...], combos: [...]}
     * @param string $actorRole Rol del actor para el audit log (la API es JWT, no hay $_SESSION)
     * @return string policy_id de la nueva política
     */
    public static function createPolicyVersion(
        PDO $conn,
        string $schoolId,
        string $userId,
        string $reason,
        array $config,
        string $actorRole = 'COORDINATOR'
    ): string {
        $startedTx = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedTx = true;
        }
        try {
            // Capturar política anterior ANTES de desactivarla (para audit log)
            $prevPolicy = self::getActivePolicy($conn, $schoolId);
            $prevSnapshot = $prevPolicy ? $prevPolicy['snapshot_json'] : null;

            // Desactivar política anterior
            $conn->prepare("
                UPDATE risk_policies SET is_active = FALSE, deactivated_at = NOW()
                WHERE school_id = ? AND is_active = TRUE
            ")->execute([$schoolId]);

            // Obtener siguiente versión
            $vStmt = $conn->prepare("
                SELECT COALESCE(MAX(version), 0) + 1 FROM risk_policies WHERE school_id = ?
            ");
            $vStmt->execute([$schoolId]);
            $newVersion = (int)$vStmt->fetchColumn();

            // Crear snapshot JSON
            $snapshot = json_encode([
                'engine_version' => '3.0',
                'version'        => $newVersion,
                'rules'          => $config['rules'] ?? [],
                'mapping'         => $config['mapping'] ?? [],
                'combos'         => $config['combos'] ?? [],
            ], JSON_UNESCAPED_UNICODE);

            // Insertar nueva política
            $pStmt = $conn->prepare("
                INSERT INTO risk_policies (school_id, version, is_active, snapshot_json, created_by, change_reason)
                VALUES (?, ?, TRUE, ?::jsonb, ?, ?)
                RETURNING policy_id
            ");
            $pStmt->execute([$schoolId, $newVersion, $snapshot, $userId, $reason]);
            $policyId = $pStmt->fetchColumn();

            // Insertar reglas por nivel (validando rangos protegidos)
            foreach (($config['rules'] ?? []) as $rule) {
                self::validateRuleRanges($rule);
                // PDO con EMULATE_PREPARES convierte false de PHP a string
                // vacío, y PostgreSQL rechaza ''::boolean. Convertir a 'true'/'false'.
                $singleOcc = !empty($rule['single_occurrence']) ? 'true' : 'false';
                $humanReview = !empty($rule['requires_human_review']) ? 'true' : 'false';
                $detectOnly = !empty($rule['detect_only']) ? 'true' : 'false';
                $conn->prepare("
                    INSERT INTO risk_rules (policy_id, school_id, risk_level,
                        weight_base, half_life_days, activation_threshold, cooldown_days,
                        single_occurrence, requires_human_review, detect_only,
                        recurrence_count, window_days,
                        min_recurrence, max_recurrence, min_window_days, max_window_days,
                        min_weight, max_weight, min_half_life, max_half_life,
                        min_threshold, max_threshold)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?::boolean, ?::boolean, ?::boolean, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $policyId, $schoolId, $rule['risk_level'],
                    $rule['weight_base'], $rule['half_life_days'],
                    $rule['activation_threshold'], $rule['cooldown_days'],
                    $singleOcc,
                    $humanReview,
                    $detectOnly,
                    $rule['recurrence_count'] ?? 4,
                    $rule['window_days'] ?? 7,
                    $rule['min_recurrence'] ?? 1,
                    $rule['max_recurrence'] ?? 20,
                    $rule['min_window_days'] ?? 1,
                    $rule['max_window_days'] ?? 60,
                    $rule['min_weight'], $rule['max_weight'],
                    $rule['min_half_life'], $rule['max_half_life'],
                    $rule['min_threshold'], $rule['max_threshold'],
                ]);
            }

            // Insertar mapeo evento → nivel
            foreach (($config['mapping'] ?? []) as $map) {
                $typeId = self::getEventTypeId($conn, $map['type_code']);
                $conn->prepare("
                    INSERT INTO risk_event_level_mapping (policy_id, school_id, event_type_id, risk_level)
                    VALUES (?, ?, ?, ?)
                ")->execute([$policyId, $schoolId, $typeId, $map['risk_level']]);
            }

            // Reglas de combinación (correlación entre categorías)
            foreach (($config['combos'] ?? []) as $combo) {
                if (empty($combo['rule_name']) || empty($combo['result_level']) || empty($combo['condition'])) {
                    continue;
                }
                if (!in_array($combo['result_level'], ['MODERADA', 'ALTA', 'MUY_ALTA'])) {
                    throw new InvalidArgumentException("result_level de combinación inválido: " . $combo['result_level']);
                }
                $conn->prepare("
                    INSERT INTO risk_combination_rules
                        (policy_id, school_id, rule_name, condition_json, result_level, result_reason)
                    VALUES (?, ?, ?, ?::jsonb, ?, ?)
                ")->execute([
                    $policyId, $schoolId, $combo['rule_name'],
                    json_encode($combo['condition'], JSON_UNESCAPED_UNICODE),
                    $combo['result_level'],
                    $combo['result_reason'] ?? $combo['rule_name'],
                ]);
            }

            // Registrar en audit log (Capa 6) — usar savepoint para que un fallo
            // de auditoría no aborte el guardado de la política.
            try {
                if ($startedTx) {
                    $conn->exec("SAVEPOINT nx_audit");
                }
                $conn->prepare("
                    INSERT INTO risk_audit_log (school_id, actor_id, actor_role,
                        entity_modified, entity_id, action,
                        previous_config, new_config, change_reason, policy_version)
                    VALUES (?, ?, ?, 'risk_policy', ?, 'CREATE',
                        ?::jsonb, ?::jsonb, ?, ?)
                ")->execute([
                    $schoolId, $userId, $actorRole,
                    $policyId,
                    $prevSnapshot,
                    $snapshot, $reason, $newVersion,
                ]);
                if ($startedTx) {
                    $conn->exec("RELEASE SAVEPOINT nx_audit");
                }
            } catch (Throwable $auditEx) {
                // El audit log es best-effort: no debe impedir el guardado.
                if ($startedTx) {
                    try { $conn->exec("ROLLBACK TO SAVEPOINT nx_audit"); } catch (Exception $ignore) {}
                }
                error_log("RISK_AUDIT_LOG_FAILED: " . $auditEx->getMessage());
            }

            if ($startedTx) {
                $conn->commit();
            }
            return $policyId;
        } catch (Throwable $e) {
            if ($startedTx) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Valida que los valores de una regla estén dentro de los rangos protegidos.
     * Lanza excepción si algún valor está fuera de rango.
     */
    private static function validateRuleRanges(array $rule): void
    {
        $level = $rule['risk_level'];
        $errors = [];

        // Validar reincidencias
        $recurrence = $rule['recurrence_count'] ?? 4;
        $minRec = $rule['min_recurrence'] ?? 1;
        $maxRec = $rule['max_recurrence'] ?? 20;
        if ($recurrence < $minRec || $recurrence > $maxRec) {
            $errors[] = "$level: reincidencias fuera de rango [$minRec-$maxRec]";
        }

        // Validar plazo de dias
        $window = $rule['window_days'] ?? 7;
        $minWin = $rule['min_window_days'] ?? 1;
        $maxWin = $rule['max_window_days'] ?? 60;
        if ($window < $minWin || $window > $maxWin) {
            $errors[] = "$level: plazo de dias fuera de rango [$minWin-$maxWin]";
        }

        // MUY_ALTA siempre debe ser single_occurrence (protegido por NEXO)
        if ($level === 'MUY_ALTA' && empty($rule['single_occurrence'])) {
            $errors[] = "MUY_ALTA: debe ser siempre single_occurrence (protegido por NEXO)";
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    private static function getEventTypeId(PDO $conn, string $typeCode): int
    {
        $stmt = $conn->prepare("SELECT event_type_id FROM risk_event_types WHERE type_code = ?");
        $stmt->execute([$typeCode]);
        $id = $stmt->fetchColumn();
        if (!$id) throw new InvalidArgumentException("Tipo de evento desconocido: $typeCode");
        return (int)$id;
    }

    // =========================================================================
    // CAPA 3-5: EVALUACIÓN DE RIESGO (delega a SQL)
    // =========================================================================

    /**
     * Evalúa el riesgo de un estudiante (delega a fn_evaluate_student_risk).
     * El trigger SQL ya lo hace automáticamente tras cada incidente,
     * pero este método permite evaluación manual o re-evaluación.
     */
    public static function evaluateStudent(PDO $conn, string $studentId, string $schoolId): array
    {
        $stmt = $conn->prepare("SELECT fn_evaluate_student_risk(?, ?)");
        $stmt->execute([$studentId, $schoolId]);
        $result = $stmt->fetchColumn();
        return json_decode($result, true) ?: ['error' => 'evaluation_failed'];
    }

    /**
     * Recalcula el riesgo de todos los estudiantes de una escuela.
     */
    public static function recalculateSchool(PDO $conn, string $schoolId): int
    {
        $stmt = $conn->prepare("SELECT fn_recalculate_school_risk_v3(?)");
        $stmt->execute([$schoolId]);
        return (int)$stmt->fetchColumn();
    }

    // =========================================================================
    // CAPA 4: EVALUACIÓN DE REGLAS DE COMBINACIÓN (en PHP para flexibilidad)
    // =========================================================================

    /**
     * Evalúa las reglas de combinación para un estudiante.
     * Verifica si múltiples categorías superan niveles mínimos simultáneamente.
     *
     * @return array Lista de reglas que se dispararon
     */
    public static function evaluateCombinationRules(
        PDO $conn,
        string $studentId,
        string $schoolId,
        string $policyId
    ): array {
        // Obtener snapshots actuales por categoría
        $snapStmt = $conn->prepare("
            SELECT category, active_score, metadata_json->>'triggered_level' AS triggered_level
            FROM risk_active_snapshot
            WHERE student_id = ? AND school_id = ?
        ");
        $snapStmt->execute([$studentId, $schoolId]);
        $snapshots = [];
        foreach ($snapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshots[$row['category']] = $row['triggered_level'] ?? 'NONE';
        }

        // Obtener reglas de combinación activas
        $comboStmt = $conn->prepare("
            SELECT * FROM risk_combination_rules
            WHERE policy_id = ? AND is_active = TRUE
        ");
        $comboStmt->execute([$policyId]);
        $combos = $comboStmt->fetchAll(PDO::FETCH_ASSOC);

        $triggered = [];
        foreach ($combos as $combo) {
            $condition = json_decode($combo['condition_json'], true);
            $allMet = true;

            foreach (($condition['categories'] ?? []) as $catCond) {
                $cat = $catCond['category'];
                $minLevel = $catCond['min_level'];
                $actualLevel = $snapshots[$cat] ?? 'NONE';
                if (self::compareLevels($actualLevel, $minLevel) < 0) {
                    $allMet = false;
                    break;
                }
            }

            if ($allMet) {
                $triggered[] = [
                    'combo_rule_id' => $combo['combo_rule_id'],
                    'rule_name'     => $combo['rule_name'],
                    'result_level'  => $combo['result_level'],
                    'result_reason' => $combo['result_reason'],
                ];
            }
        }

        return $triggered;
    }

    // =========================================================================
    // CAPA 6: JUSTIFICACIONES Y AUDITORÍA
    // =========================================================================

    /**
     * Justifica un evento (filtro de contexto — el evento no suma al riesgo).
     * Recalcula el riesgo automáticamente.
     */
    public static function justifyEvent(
        PDO $conn,
        string $schoolId,
        string $studentId,
        string $incidentType,
        string $incidentDate,
        string $justifiedBy,
        string $justificationType,
        string $reason
    ): string {
        $stmt = $conn->prepare("SELECT fn_justify_risk_event(?, ?, ?, ?::timestamptz, ?, ?, ?)");
        $stmt->execute([
            $schoolId, $studentId, $incidentType, $incidentDate,
            $justifiedBy, $justificationType, $reason
        ]);
        return $stmt->fetchColumn();
    }

    /**
     * Resuelve una alerta (acción humana explícita — Capa 7).
     * Bajar de nivel de escalamiento requiere acción humana, nunca es automático.
     */
    public static function resolveAlert(
        PDO $conn,
        string $alertId,
        string $resolvedBy,
        string $resolutionNotes
    ): bool {
        $stmt = $conn->prepare("
            UPDATE risk_alerts
            SET status = 'resuelta', resolved_at = NOW(),
                resolved_by = ?, resolution_notes = ?
            WHERE alert_id = ? AND status IN ('abierta', 'en_seguimiento')
        ");
        $stmt->execute([$resolvedBy, $resolutionNotes, $alertId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cambia el estado de escalamiento de una alerta (subir o bajar).
     * Bajar requiere acción humana explícita.
     */
    public static function changeEscalationState(
        PDO $conn,
        string $alertId,
        string $newState,
        string $changedBy,
        string $reason
    ): bool {
        $validStates = [
            self::STATE_OBSERVACION, self::STATE_ALERTA_PEDAGOGICA,
            self::STATE_SEGUIMIENTO, self::STATE_INTERVENCION_PRIORITARIA,
            self::STATE_ATENCION_INMEDIATA
        ];
        if (!in_array($newState, $validStates)) {
            throw new InvalidArgumentException("Estado de escalamiento inválido: $newState");
        }

        $startedTx = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $conn->prepare("
                UPDATE risk_alerts SET escalation_state = ?
                WHERE alert_id = ? AND status != 'resuelta'
                RETURNING school_id, student_id, escalation_state AS prev_state
            ");
            $stmt->execute([$newState, $alertId]);
            $prev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$prev) {
                if ($startedTx) {
                    $conn->rollBack();
                }
                return false;
            }

            // Auditar el cambio de estado
            $conn->prepare("
                INSERT INTO risk_audit_log (school_id, actor_id, entity_modified,
                    entity_id, action, previous_config, new_config, change_reason)
                VALUES (?, ?, 'risk_alert', ?, 'ESCALATION_CHANGE',
                    ?::jsonb, ?::jsonb, ?)
            ")->execute([
                $prev['school_id'], $changedBy, $alertId,
                json_encode(['escalation_state' => $prev['prev_state']]),
                json_encode(['escalation_state' => $newState]),
                $reason
            ]);

            // Una alerta escalada a SEGUIMIENTO instancia el caso de
            // seguimiento (derivación automática a coordinación).
            if ($newState === self::STATE_SEGUIMIENTO) {
                $chk = $conn->prepare("
                    SELECT 1 FROM student_tracking
                    WHERE student_id = ? AND school_id = ? AND status = 'en proceso'
                ");
                $chk->execute([$prev['student_id'], $prev['school_id']]);
                if (!$chk->fetchColumn()) {
                    $conn->prepare("
                        INSERT INTO student_tracking (school_id, student_id, status, dependency, origin_type, origin_id)
                        VALUES (?, ?, 'en proceso', 'coordinacion', 'risk_alert', ?)
                    ")->execute([$prev['school_id'], $prev['student_id'], $alertId]);
                }
            }

            if ($startedTx) {
                $conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($startedTx) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // CAPA 8 (OPCIONAL): ANOMALÍA ESTADÍSTICA (z-score individual)
    // =========================================================================

    /**
     * Calcula un z-score del comportamiento actual del estudiante contra
     * su propia línea base histórica. NO dispara alertas — es informativo.
     *
     * @return array {z_score, is_anomalous, baseline_mean, current_value}
     */
    public static function calculateAnomalyScore(
        PDO $conn,
        string $studentId,
        string $schoolId,
        string $category,
        int $baselineDays = 180
    ): array {
        // Línea base: promedio de eventos por ventana de 30 días en el histórico
        $stmt = $conn->prepare("
            WITH baseline AS (
                SELECT DATE_TRUNC('month', detected_at) AS month, COUNT(*) AS cnt
                FROM attendance_incidents
                WHERE student_id = ? AND school_id = ?
                  AND detected_at >= NOW() - (? || ' days')::INTERVAL
                  AND incident_type NOT LIKE 'RISK_ALERT%'
                GROUP BY 1
            ),
            current AS (
                SELECT COUNT(*) AS cnt
                FROM attendance_incidents
                WHERE student_id = ? AND school_id = ?
                  AND detected_at >= NOW() - INTERVAL '30 days'
                  AND incident_type NOT LIKE 'RISK_ALERT%'
            )
            SELECT
                COALESCE(AVG(b.cnt), 0) AS baseline_mean,
                COALESCE(stddev(b.cnt), 0) AS baseline_stddev,
                COALESCE(c.cnt, 0) AS current_count
            FROM baseline b
            CROSS JOIN current c
        ");
        $stmt->execute([$studentId, $schoolId, $baselineDays, $studentId, $schoolId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $mean = (float)($row['baseline_mean'] ?? 0);
        $stddev = (float)($row['baseline_stddev'] ?? 0);
        $current = (float)($row['current_count'] ?? 0);

        $zScore = $stddev > 0 ? ($current - $mean) / $stddev : 0.0;

        return [
            'z_score'       => round($zScore, 2),
            'is_anomalous'  => $zScore > 2.0,  // >2 desviaciones estándar
            'baseline_mean' => round($mean, 2),
            'baseline_stddev' => round($stddev, 2),
            'current_value' => $current,
            'category'      => $category,
        ];
    }

    // =========================================================================
    // CONSULTAS DE LECTURA
    // =========================================================================

    /**
     * Obtiene las alertas activas de una escuela.
     */
    public static function getActiveAlerts(PDO $conn, string $schoolId, ?string $level = null): array
    {
        $sql = "
            SELECT ra.alert_id, ra.alert_level, ra.escalation_state, ra.status,
                   ra.trigger_category, ra.trigger_rule, ra.trigger_score,
                   ra.created_at, ra.cooldown_until,
                   ra.involved_events, ra.metadata_json,
                   s.student_id, s.first_name, s.last_name,
                   COALESCE(ag.group_name, 'Sin grupo') AS group_name,
                   u.email AS resolved_by_email
            FROM risk_alerts ra
            INNER JOIN students s ON s.student_id = ra.student_id
            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
            LEFT JOIN users u ON u.user_id = ra.resolved_by
            WHERE ra.school_id = ? AND ra.status IN ('abierta', 'en_seguimiento')
        ";
        $params = [$schoolId];
        if ($level) {
            $sql .= " AND ra.alert_level = ?";
            $params[] = $level;
        }
        $sql .= " ORDER BY ra.alert_level DESC, ra.trigger_score DESC, ra.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene el perfil de riesgo completo de un estudiante (vector por categoría).
     */
    public static function getStudentRiskProfile(PDO $conn, string $studentId, string $schoolId): array
    {
        $stmt = $conn->prepare("
            SELECT category, active_score, event_count, clustering_factor,
                   last_calculated_at, metadata_json
            FROM risk_active_snapshot
            WHERE student_id = ? AND school_id = ?
        ");
        $stmt->execute([$studentId, $schoolId]);
        $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $profile = [];
        foreach ($snapshots as $s) {
            $meta = json_decode($s['metadata_json'], true);
            $profile[$s['category']] = [
                'score'     => (float)$s['active_score'],
                'count'     => (int)$s['event_count'],
                'clustering' => (float)$s['clustering_factor'],
                'level'     => $meta['triggered_level'] ?? 'NONE',
                'last_calc' => $s['last_calculated_at'],
            ];
        }

        // Calcular nivel máximo
        $maxLevel = self::LEVEL_NONE;
        foreach ($profile as $cat => $data) {
            $maxLevel = self::maxLevel($maxLevel, $data['level']);
        }

        return [
            'student_id' => $studentId,
            'categories' => $profile,
            'max_level'  => $maxLevel,
        ];
    }

    /**
     * Tipos de evento que NO deben aparecer en la configuración de riesgo.
     * La inasistencia se detecta automáticamente (worker_absence_detector) y
     * la salida no autorizada se gestiona vía notifications; ninguno de los
     * dos debe ser clasificado manualmente por el rector.
     */
    private const EXCLUDED_EVENT_TYPES = [
        'INASISTENCIA',
        'INASISTENCIA_JUSTIFICADA',
        'INASISTENCIA_NO_JUSTIFICADA',
        'UNAUTHORIZED_ABSENCE',
        'SALIDA_NO_AUTORIZADA',
        'UNAUTHORIZED_EXIT',
    ];

    /**
     * Obtiene los tipos de evento disponibles para configuración.
     */
    public static function getEventTypes(PDO $conn): array
    {
        // Excluir tipos de evento que no deben ser clasificados manualmente.
        // Se construye la lista de placeholders dinámicamente.
        $excluded = self::EXCLUDED_EVENT_TYPES;
        $placeholders = implode(',', array_fill(0, count($excluded), '?'));
        $stmt = $conn->prepare("
            SELECT event_type_id, type_code, display_name, description, category
            FROM risk_event_types
            WHERE is_system = TRUE
              AND type_code NOT IN ($placeholders)
            ORDER BY category, display_name
        ");
        $stmt->execute($excluded);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
