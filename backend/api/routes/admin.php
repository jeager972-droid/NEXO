<?php
/**
 * =============================================================================
 * routes/admin.php — Endpoints administrativos de gestión institucional.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone operaciones administrativas restringidas a roles de alto privilegio
 * (RECTOR / COORDINATOR). Actualmente contiene el endpoint para recalcular las
 * métricas de riesgo estudiantil usando RiskScoreEngine.
 *
 * FLUJO GENERAL
 * -------------
 *   POST /admin/recalc-risk
 *        │
 *        ▼
 *   requireAuth(['RECTOR','COORDINATOR'])
 *        │
 *        ▼
 *   ¿school_id presente?
 *        ├── SI ──► RiskScoreEngine::recalculateSchool($conn, $schoolId)
 *        └── NO ──► Iterar todas las escuelas activas y recalcular cada una
 *        │
 *        ▼
 *   Registrar auditoría y retornar {processed, errors, meta}
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - _auth_middleware.php : autenticación y control de roles.
 *   - lib/RiskScoreEngine.php : motor de cálculo de riesgo.
 *   - $conn : conexión PDO global a PostgreSQL.
 *   - $input : payload JSON decodificado (definido en api.php).
 *
 * Es utilizado por:
 *   - backend/api/api.php : lo incluye por routing basado en URI.
 *   - Frontend React: panel administrativo (Admin.jsx).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/RiskScoreEngine.php';

// ============================================================================
// POST /admin/recalc-risk
// Recalcula métricas de riesgo para una escuela específica o todas las activas.
// ============================================================================
if ($cleanPath === '/admin/recalc-risk') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $input['school_id'] ?? null;

    try {
        $processed = 0;
        $errors    = [];

        if ($schoolId) {
            // Recalcular una escuela específica
            $processed = RiskScoreEngine::recalculateSchool($conn, $schoolId);
        } else {
            // Recalcular todas las escuelas activas
            $stmt    = $conn->query("SELECT school_id, school_name FROM schools WHERE active = TRUE");
            $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($schools as $school) {
                try {
                    $count     = RiskScoreEngine::recalculateSchool($conn, $school['school_id']);
                    $processed += $count;
                } catch (Exception $e) {
                    $errors[] = [
                        'school_id'   => $school['school_id'],
                        'school_name' => $school['school_name'],
                        'error'       => $e->getMessage()
                    ];
                }
            }
        }

        securityLog('ADMIN_RECALC_RISK', "Processed: {$processed} students", $authUser['id'], $schoolId);

        echo json_encode([
            'status'    => 'ok',
            'processed' => $processed,
            'errors'    => $errors,
            'meta'      => [
                'triggered_by' => $authUser['email'],
                'timestamp'    => gmdate('c'),
                'engine'       => 'RiskScoreEngine v2.0',
            ]
        ]);
    } catch (Exception $e) {
        securityLog('ADMIN_RECALC_RISK_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error al recalcular métricas']);
    }
    exit;
}

// ============================================================================
// GET /admin/config-check — V-614: diff configuración declarada vs realidad
// operativa. Reporta inconsistencias accionables por categoría.
// ============================================================================
if ($cleanPath === '/admin/config-check' && $method === 'GET') {
    $authUser = requireAuth(['RECTOR', 'COORDINATOR']);
    $schoolId = $authUser['school_id'];
    $findings = [];

    try {
        // Grupos activos sin ningún bloque de horario configurado
        $q = $conn->prepare("
            SELECT ag.group_name FROM academic_groups ag
            WHERE ag.school_id = ? AND ag.active = TRUE
              AND NOT EXISTS (SELECT 1 FROM schedules s WHERE s.group_id = ag.group_id)
        ");
        $q->execute([$schoolId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $g) {
            $findings[] = ['severity' => 'HIGH', 'category' => 'schedule', 'finding' => "Grupo '$g' sin bloques de horario — las ausencias no pueden evaluarse por bloque"];
        }

        // Schedules que referencian aulas inexistentes
        $q = $conn->prepare("
            SELECT s.schedule_id FROM schedules s
            LEFT JOIN classrooms c ON c.classroom_id = s.classroom_id
            WHERE s.school_id = ? AND s.classroom_id IS NOT NULL AND c.classroom_id IS NULL
        ");
        $q->execute([$schoolId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $findings[] = ['severity' => 'MEDIUM', 'category' => 'schedule', 'finding' => "Horario $sid apunta a un aula inexistente"];
        }

        // Nodos edge sin grupo ni aula asignada
        $q = $conn->prepare("
            SELECT device_name FROM edge_devices
            WHERE school_id = ? AND active = TRUE AND group_id IS NULL AND classroom_id IS NULL
        ");
        $q->execute([$schoolId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $findings[] = ['severity' => 'MEDIUM', 'category' => 'device', 'finding' => "Nodo '$d' sin grupo ni aula — sus eventos no tendrán contexto espacial"];
        }

        // Nodos activos que nunca han hecho ping
        $q = $conn->prepare("
            SELECT device_name FROM edge_devices
            WHERE school_id = ? AND active = TRUE AND last_ping IS NULL
        ");
        $q->execute([$schoolId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $findings[] = ['severity' => 'HIGH', 'category' => 'device', 'finding' => "Nodo '$d' registrado pero nunca en línea"];
        }

        // Estudiantes activos sin grupo asignado
        $q = $conn->prepare("
            SELECT COUNT(*) FROM students s
            WHERE s.school_id = ? AND s.active = TRUE AND s.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM student_group_assignments a WHERE a.student_id = s.student_id AND a.active = TRUE)
        ");
        $q->execute([$schoolId]);
        if (($n = (int)$q->fetchColumn()) > 0) {
            $findings[] = ['severity' => 'HIGH', 'category' => 'enrollment', 'finding' => "$n estudiante(s) activos sin grupo — sus eventos no se interpretan"];
        }

        // Estudiantes biométricos sin huella registrada
        $q = $conn->prepare("
            SELECT COUNT(*) FROM students s
            WHERE s.school_id = ? AND s.active = TRUE AND s.deleted_at IS NULL
              AND COALESCE(s.biometric_exempt, FALSE) = FALSE
              AND NOT EXISTS (SELECT 1 FROM student_fingerprints f WHERE f.student_id = s.student_id)
        ");
        $q->execute([$schoolId]);
        if (($n = (int)$q->fetchColumn()) > 0) {
            $findings[] = ['severity' => 'HIGH', 'category' => 'enrollment', 'finding' => "$n estudiante(s) sin huella ni exención biométrica"];
        }

        // Grupos con estudiantes pero sin docente asignado
        $q = $conn->prepare("
            SELECT ag.group_name FROM academic_groups ag
            WHERE ag.school_id = ? AND ag.active = TRUE
              AND EXISTS (SELECT 1 FROM student_group_assignments a WHERE a.group_id = ag.group_id AND a.active = TRUE)
              AND NOT EXISTS (SELECT 1 FROM teacher_group_access t WHERE t.group_id = ag.group_id)
        ");
        $q->execute([$schoolId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $g) {
            $findings[] = ['severity' => 'MEDIUM', 'category' => 'access', 'finding' => "Grupo '$g' con estudiantes pero sin docente asignado"];
        }

        // Política de riesgo activa vs flag de onboarding
        $q = $conn->prepare("
            SELECT s.risk_config_completed,
                   EXISTS(SELECT 1 FROM risk_policies p WHERE p.school_id = s.school_id AND p.is_active = TRUE) AS has_policy
            FROM schools s WHERE s.school_id = ?
        ");
        $q->execute([$schoolId]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r && $r['risk_config_completed'] && !$r['has_policy']) {
            $findings[] = ['severity' => 'HIGH', 'category' => 'risk', 'finding' => "Onboarding marca riesgo configurado pero no hay política activa"];
        }
        if ($r && !$r['risk_config_completed'] && $r['has_policy']) {
            $findings[] = ['severity' => 'LOW', 'category' => 'risk', 'finding' => "Hay política activa pero risk_config_completed=FALSE — la escuela quedaría bloqueada"];
        }

        // Enrutamiento de notificaciones sin configurar (defaults en uso)
        $q = $conn->prepare("SELECT COUNT(*) FROM school_notification_routes WHERE school_id = ? AND enabled = TRUE");
        $q->execute([$schoolId]);
        if ((int)$q->fetchColumn() === 0) {
            $findings[] = ['severity' => 'LOW', 'category' => 'notification', 'finding' => "Sin rutas de notificación configuradas — se usan los defaults (COORDINATOR+RECTOR)"];
        }

        echo json_encode([
            'status' => 'ok',
            'school_id' => $schoolId,
            'findings' => $findings,
            'counts' => [
                'HIGH' => count(array_filter($findings, fn($f) => $f['severity'] === 'HIGH')),
                'MEDIUM' => count(array_filter($findings, fn($f) => $f['severity'] === 'MEDIUM')),
                'LOW' => count(array_filter($findings, fn($f) => $f['severity'] === 'LOW')),
            ],
        ]);
    } catch (Exception $e) {
        securityLog('ADMIN_CONFIG_CHECK_ERROR', $e->getMessage(), $authUser['id'], $schoolId);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error en verificación de configuración']);
    }
    exit;
}
