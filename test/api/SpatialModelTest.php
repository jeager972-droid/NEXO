<?php
/**
 * =============================================================================
 * SpatialModelTest.php — Validación F-01 (modelo espacial faseado).
 * =============================================================================
 *
 * F-01a: CRUD classrooms/subjects/schedules expuestos en /school/*.
 * F-01b: el ingest de eventos biométricos resuelve classroom_id + schedule_id.
 * F-01c: enforcement wrong_classroom tras flag schools.spatial_enforcement.
 *
 * Contratos estáticos + aserciones sobre el SQL de resolución espacial.
 * =============================================================================
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

class SpatialModelTest extends PHPUnit\Framework\TestCase
{
    private string $schoolCfgSrc;
    private string $schemaSrc;
    private string $workerSrc;

    protected function setUp(): void {
        $root = __DIR__ . '/../../';
        $this->schoolCfgSrc = file_get_contents($root . 'backend/api/routes/school_config.php');
        $this->schemaSrc    = file_get_contents($root . 'sql/schema.sql');
        $this->workerSrc    = file_get_contents($root . 'backend/api/workers/worker_biometric.php');
    }

    // ── F-01a: CRUD disponible ──

    public function testClassroomsCrudEndpointsExist(): void {
        $this->assertStringContainsString("/school/classrooms' && \$method === 'GET'", $this->schoolCfgSrc);
        $this->assertStringContainsString("/school/classrooms' && \$method === 'POST'", $this->schoolCfgSrc);
        $this->assertStringContainsString("/school/classrooms' && \$method === 'DELETE'", $this->schoolCfgSrc);
        $this->assertStringContainsString('INSERT INTO classrooms', $this->schoolCfgSrc);
        $this->assertStringContainsString('DELETE FROM classrooms', $this->schoolCfgSrc);
    }

    public function testSubjectsEndpointsExist(): void {
        $this->assertStringContainsString("/school/subjects' && \$method === 'GET'", $this->schoolCfgSrc);
        $this->assertStringContainsString("/school/subjects' && \$method === 'POST'", $this->schoolCfgSrc);
        $this->assertStringContainsString('INSERT INTO subjects', $this->schoolCfgSrc);
    }

    public function testSchedulesEndpointsExistAndValidate(): void {
        $this->assertStringContainsString("/school/schedules' && \$method === 'GET'", $this->schoolCfgSrc);
        $this->assertStringContainsString("/school/schedules' && \$method === 'POST'", $this->schoolCfgSrc);
        $this->assertStringContainsString("/school/schedules' && \$method === 'DELETE'", $this->schoolCfgSrc);
        $this->assertStringContainsString('INSERT INTO schedules', $this->schoolCfgSrc);
        // Validación de pertenencia a la escuela antes de insertar
        $this->assertStringContainsString('FROM academic_groups WHERE group_id', $this->schoolCfgSrc);
        $this->assertStringContainsString('FROM classrooms WHERE classroom_id', $this->schoolCfgSrc);
        $this->assertStringContainsString('FROM users WHERE user_id', $this->schoolCfgSrc);
        // El DELETE solo puede tocar horarios de la propia escuela (tenant scope)
        $this->assertStringContainsString('group_id IN (SELECT group_id FROM academic_groups WHERE school_id', $this->schoolCfgSrc);
    }

    // ── F-01b: eventos llevan contexto espacial ──

    public function testWorkerResolvesSpatialContextOnInsert(): void {
        $this->assertStringContainsString('classroom_id', $this->workerSrc);
        $this->assertStringContainsString('schedule_id', $this->workerSrc);
        // La resolución usa dispositivo→grupo→schedule con día ISO y ventana horaria
        $this->assertStringContainsString('EXTRACT(ISODOW', $this->workerSrc);
        $this->assertStringContainsString('BETWEEN sch.start_time AND sch.end_time', $this->workerSrc);
        $this->assertStringContainsString('INSERT INTO biometric_events(event_id,school_id,student_id,device_id,classroom_id,schedule_id', $this->workerSrc);
    }

    public function testSpatialResolutionIsNonBlocking(): void {
        // La resolución espacial está en try/catch — nunca tumba el ingest
        $this->assertMatchesRegularExpression(
            '/catch \(Exception \$e\).*resolución falló \(no bloqueante\)/s',
            $this->workerSrc
        );
    }

    // ── F-01c: enforcement tras flag ──

    public function testSpatialEnforcementFlagExists(): void {
        $this->assertStringContainsString('spatial_enforcement', $this->schemaSrc);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS spatial_enforcement', $this->schemaSrc);
        $this->assertStringContainsString('/school/spatial-enforcement', $this->schoolCfgSrc);
    }

    public function testWrongClassroomOnlyWhenFlagOn(): void {
        // El flag se consulta antes de marcar wrong_classroom
        $this->assertStringContainsString('SELECT spatial_enforcement FROM schools', $this->workerSrc);
        $this->assertStringContainsString('wrong_classroom', $this->workerSrc);
        // Orden: comparar aulas → consultar flag → marcar metadata
        $cmpPos = strpos($this->workerSrc, 'device_classroom');
        $flagPos = strpos($this->workerSrc, 'SELECT spatial_enforcement');
        $markPos = strpos($this->workerSrc, "'wrong_classroom'] = true");
        $this->assertNotFalse($cmpPos);
        $this->assertNotFalse($flagPos);
        $this->assertNotFalse($markPos);
        $this->assertLessThan($flagPos, $cmpPos);
        $this->assertLessThan($markPos, $flagPos);
    }

    public function testActivationRequiresSpatialData(): void {
        // El POST de activación exige aulas+horarios poblados
        $this->assertStringContainsString('primero debe crear aulas y horarios', $this->schoolCfgSrc);
    }
}
