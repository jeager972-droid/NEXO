<?php
/**
 * =============================================================================
 * CierreVerificacionesTest.php — Validación del cierre de ítems V-xxx.
 * =============================================================================
 *
 * Cubre las correcciones de la re-verificación final de la lista de 652:
 *   - V-030/045: cambio de horario notifica a acudientes (tipo HORARIO).
 *   - V-031/063: permiso guarda schedule_id y el retorno valida el espacio.
 *   - V-530/531/574: INASISTENCIA reconciliada + REAPARICION_TARDIA.
 *   - V-069/151: student_tracking con dependency/origin + derivación automática
 *     desde risk_alert en estado SEGUIMIENTO + endpoint /tracking/derive.
 *   - V-166/378/436/564: risk_combination_rules evaluadas de verdad.
 *   - V-377: detect_only (detectar sin alertar).
 *   - V-493/495: resync_required en /devices/ping.
 *   - V-183/196: franjas horarias publicadas al edge en el ping.
 *   - V-614: /admin/config-check (diff config vs realidad).
 *   - V-523/526/534/535: /devices/reassign + /devices/reprovision.
 *   - RLS: teacher_alert_rules + school_notification_routes.
 *
 * Corre sin BD real: aserciones de contrato sobre el código + schema.
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class CierreVerificacionesTest extends PHPUnit\Framework\TestCase
{
    private string $opsSrc, $schemaSrc, $migSrc, $permSrc, $bioSrc, $riskSrc, $devSrc, $trackSrc, $adminSrc, $recSrc, $apiSrc;

    protected function setUp(): void {
        $root = __DIR__ . '/../../';
        $this->opsSrc    = file_get_contents($root . 'backend/api/routes/operations.php');
        $this->schemaSrc = file_get_contents($root . 'sql/schema.sql');
        // La migración 002 ya está consolidada dentro de schema.sql (ETAPA 7)
        $this->migSrc    = $this->schemaSrc;
        $this->permSrc   = file_get_contents($root . 'backend/api/workers/worker_permission_status.php');
        $this->bioSrc    = file_get_contents($root . 'backend/api/workers/worker_biometric.php')
                         . file_get_contents($root . 'backend/api/lib/attendance_reconcile.php');
        $this->apiSrc    = file_get_contents($root . 'backend/api/api.php');
        $this->riskSrc   = file_get_contents($root . 'backend/api/lib/RiskEngineV3.php');
        $this->devSrc    = file_get_contents($root . 'backend/api/routes/devices.php');
        $this->trackSrc  = file_get_contents($root . 'backend/api/routes/tracking.php');
        $this->adminSrc  = file_get_contents($root . 'backend/api/routes/admin.php');
    }

    // ── V-030/045: horario → acudientes ──
    public function testHorarioNotifiesGuardians(): void {
        $this->assertStringContainsString('HORARIO', $this->opsSrc);
        // El bloque horario debe encolar WhatsApp por grupo
        $this->assertMatchesRegularExpression(
            "/case 'horario'[\s\S]*?enqueueTwilioJob\([\s\S]*?'HORARIO'/",
            $this->opsSrc,
            'El comando horario debe encolar mensajes HORARIO a acudientes'
        );
        $this->assertStringContainsString("'HORARIO'", $this->schemaSrc);
        $this->assertStringContainsString("'HORARIO'", $this->migSrc);
        // Camino simple (Operation.jsx: group+time) también genera changes
        $this->assertStringContainsString("\$params['group']", $this->opsSrc);
    }

    // ── V-031/063: contexto espacial del permiso y retorno validado ──
    public function testPermissionStoresScheduleAndValidatesReturn(): void {
        $this->assertStringContainsString('schedule_id', $this->schemaSrc);
        // INSERT en class_exit_authorizations incluye schedule_id
        $this->assertMatchesRegularExpression(
            "/INSERT INTO class_exit_authorizations[\s\S]*?schedule_id/",
            $this->opsSrc
        );
        // El worker valida aula esperada vs aula del evento de retorno
        $this->assertStringContainsString('expected_classroom_id', $this->permSrc);
        $this->assertStringContainsString('RETURN_WRONG_SPACE', $this->permSrc);
        $this->assertStringContainsString('actual_return_time', $this->permSrc);
        $this->assertStringContainsString('return_space_validated', $this->permSrc);
        $this->assertStringContainsString('actual_return_time', $this->migSrc);
    }

    // ── V-530/531/574: reconciliación de inasistencia ──
    public function testAbsenceReconciledOnLateArrival(): void {
        $this->assertStringContainsString('REAPARICION_TARDIA', $this->bioSrc);
        $this->assertMatchesRegularExpression(
            "/UPDATE attendance_incidents[\s\S]*?resolved = TRUE[\s\S]*?INASISTENCIA/",
            $this->bioSrc,
            'Un INGRESO tardío debe resolver la INASISTENCIA abierta del día'
        );
        $this->assertStringContainsString('reconciled_by', $this->bioSrc);
    }

    // ── V-069/151: derivación a seguimiento ──
    public function testTrackingDerivation(): void {
        // Columnas nuevas
        foreach (['dependency', 'assigned_to_user_id', 'origin_type', 'origin_id'] as $col) {
            $this->assertStringContainsString($col, $this->schemaSrc, "student_tracking.$col falta en schema");
            $this->assertStringContainsString($col, $this->migSrc, "student_tracking.$col falta en migración");
        }
        // Endpoint de derivación
        $this->assertStringContainsString("/tracking/derive", $this->trackSrc);
        $this->assertStringContainsString('origin_type', $this->trackSrc);
        // Derivación automática desde alerta SEGUIMIENTO (motor PHP + función SQL)
        $this->assertStringContainsString('STATE_SEGUIMIENTO', $this->riskSrc);
        $this->assertMatchesRegularExpression(
            "/STATE_SEGUIMIENTO[\s\S]*?INSERT INTO student_tracking/",
            $this->riskSrc,
            'changeEscalationState debe instanciar tracking al escalar a SEGUIMIENTO'
        );
        $this->assertMatchesRegularExpression(
            "/v_escalation = 'SEGUIMIENTO'[\s\S]*?INSERT INTO student_tracking/",
            $this->schemaSrc,
            'fn_evaluate_student_risk debe derivar a tracking en MODERADA→SEGUIMIENTO'
        );
    }

    // ── V-166/378/436/564: combinaciones reales ──
    public function testRiskCombinationRulesEvaluated(): void {
        // El stub NULL; ya no puede existir
        $this->assertStringNotContainsString("LOOP\n        NULL;", $this->schemaSrc,
            'El loop de combinaciones ya no puede ser un stub');
        $this->assertStringContainsString('fn_risk_level_rank', $this->schemaSrc);
        $this->assertStringContainsString('risk_combination_rules', $this->schemaSrc);
        $this->assertMatchesRegularExpression(
            "/condition_json->'categories'/", $this->schemaSrc,
            'Las combinaciones deben evaluar condiciones por categoría'
        );
        // createPolicyVersion persiste combos
        $this->assertStringContainsString('risk_combination_rules', $this->riskSrc);
        $this->assertMatchesRegularExpression(
            "/config\['combos'\][\s\S]*?INSERT INTO risk_combination_rules/",
            $this->riskSrc
        );
    }

    // ── V-377: detect_only ──
    public function testDetectOnlyMode(): void {
        $this->assertStringContainsString('detect_only', $this->schemaSrc);
        $this->assertStringContainsString('detect_only', $this->migSrc);
        $this->assertStringContainsString('detect_only', $this->riskSrc);
        $this->assertStringContainsString('RISK_DETECTED_', $this->schemaSrc,
            'detect_only debe registrar incidente RISK_DETECTED_* sin risk_alert');
    }

    // ── V-493/495 + V-183/196: ping con resync y franjas ──
    public function testPingResyncAndSchedulePush(): void {
        $this->assertStringContainsString('resync_required', $this->devSrc);
        $this->assertStringContainsString('CLOCK_RESYNC_THRESHOLD_S', $this->devSrc);
        $this->assertStringContainsString('server_time', $this->devSrc);
        $this->assertStringContainsString('sched_punctual_start', $this->devSrc);
        $this->assertStringContainsString('school_schedule_config', $this->devSrc);
    }

    // ── V-614 + V-523/526/534/535: config-check + gestión de nodo ──
    public function testConfigCheckAndNodeManagement(): void {
        $this->assertStringContainsString('/admin/config-check', $this->adminSrc);
        $this->assertStringContainsString('findings', $this->adminSrc);
        $this->assertStringContainsString('/devices/reassign', $this->devSrc);
        $this->assertStringContainsString('/devices/reprovision', $this->devSrc);
        $this->assertStringContainsString('DEVICE_REPROVISIONED', $this->devSrc);
    }

    // ── RLS de las tablas nuevas ──
    public function testRlsOnNewTables(): void {
        foreach (['teacher_alert_rules', 'school_notification_routes'] as $t) {
            $this->assertStringContainsString("ALTER TABLE $t ENABLE ROW LEVEL SECURITY", $this->schemaSrc);
            $this->assertStringContainsString("ALTER TABLE $t ENABLE ROW LEVEL SECURITY", $this->migSrc);
            $this->assertMatchesRegularExpression("/CREATE POLICY \w+ ON $t FOR SELECT/", $this->schemaSrc);
            $this->assertMatchesRegularExpression("/CREATE POLICY \w+ ON $t FOR INSERT/", $this->schemaSrc);
        }
    }
}
