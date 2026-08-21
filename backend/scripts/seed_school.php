<?php
/**
 * =============================================================================
 * scripts/seed_school.php — Limpieza y población de datos de prueba.
 * =============================================================================
 *
 * EJECUCIÓN: php scripts/seed_school.php
 *
 * QUÉ HACE:
 *   1. DELETE: limpia estudiantes, eventos biométricos, incidentes, consultas,
 *      tracking, mensajes, permisos, autorizaciones, sensores de grupos.
 *      MANTIENE: usuarios staff (rector, coordinador, docentes, etc.), grupos
 *      académicos, horarios, bloques, motor de riesgo, configuración escolar.
 *   2. SEED: crea 5 estudiantes por grupo con datos aleatorios, asigna
 *      profesores (aleatorios + Roberto docente@nexo.edu), crea consultas
 *      (5 por tipo, 2 viejas), 4 estudiantes en casos activos, teléfonos
 *      aleatorios excepto Laura y Jhon (3243607948).
 *
 * REQUIERE: variables de entorno de DB configuradas.
 */

require_once __DIR__ . '/../api/core/db.php';

// ── Helpers ──────────────────────────────────────────────────────────────

function logS(string $msg): void {
    echo "[" . date('H:i:s') . "] $msg\n";
}

function randomName(array $firstNames, array $lastNames): array {
    return [
        'first' => $firstNames[array_rand($firstNames)],
        'last' => $lastNames[array_rand($lastNames)],
    ];
}

function randomPhone(): string {
    $prefixes = ['300', '301', '302', '310', '311', '312', '313', '314', '315', '316', '317', '318', '320', '321', '322', '323', '324', '350', '351'];
    $p = $prefixes[array_rand($prefixes)];
    $num = str_pad((string)mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
    return "+57{$p}{$num}";
}

function randomDoc(): string {
    return str_pad((string)mt_rand(1000000000, 1999999999), 10, '0', STR_PAD_LEFT);
}

// ── Datos ────────────────────────────────────────────────────────────────

$FIRST_NAMES_F = ['Laura', 'María', 'Sofía', 'Valentina', 'Camila', 'Isabella', 'Daniela', 'Gabriela', 'Salomé', 'Manuela', 'Antonia', 'Catalina'];
$FIRST_NAMES_M = ['Santiago', 'Mateo', 'Juan', 'Diego', 'Andrés', 'Sebastián', 'Tomás', 'Felipe', 'Samuel', 'Alejandro', 'Nicolás', 'Emilio'];
$LAST_NAMES = ['García', 'Rodríguez', 'Martínez', 'López', 'González', 'Pérez', 'Sánchez', 'Ramírez', 'Torres', 'Flores', 'Rivera', 'Castro', 'Vargas', 'Morales', 'Ortiz', 'Gómez', 'Herrera', 'Jiménez', 'Álvarez', 'Rojas'];

// ── 1. DELETE ────────────────────────────────────────────────────────────

logS("═══ FASE 1: LIMPIEZA ═══");

$conn->exec("SET session_replication_role = 'replica'");

$tablesToClean = [
    'biometric_events',
    'attendance_incidents',
    'student_tracking_notes',
    'student_tracking',
    'student_record_audit',
    'twilio_messages',
    'internal_messages',
    'sos_alerts',
    'security_incidents',
    'class_exit_authorizations',
    'school_exit_authorizations',
    'pedagogical_trip_authorizations',
    'notifications',
    'user_commands',
    'daily_schedule_config',
    'risk_alerts',
    'risk_event_occurrences',
    'device_commands',
    'sensor_revocation_requests',
];

foreach ($tablesToClean as $table) {
    try {
        $conn->exec("DELETE FROM {$table}");
        logS("  ✓ Limpiada: {$table}");
    } catch (Exception $e) {
        logS("  ⚠ Skip: {$table} ({$e->getMessage()})");
    }
}

// Limpiar estudiantes y sus relaciones (orden por FK)
try { $conn->exec("DELETE FROM student_group_assignments"); logS("  ✓ student_group_assignments"); } catch (Exception $e) { logS("  ⚠ student_group_assignments"); }
try { $conn->exec("DELETE FROM guardian_student_relationships"); logS("  ✓ guardian_student_relationships"); } catch (Exception $e) { logS("  ⚠ guardian_student_relationships"); }
try { $conn->exec("DELETE FROM guardians"); logS("  ✓ guardians"); } catch (Exception $e) { logS("  ⚠ guardians"); }

// Limpiar users con rol GUARDIAN (los acudientes son users también)
try {
    $conn->exec("DELETE FROM users WHERE role_id IN (SELECT role_id FROM roles WHERE role_name = 'GUARDIAN')");
    logS("  ✓ users (GUARDIAN)");
} catch (Exception $e) { logS("  ⚠ users (GUARDIAN)"); }

try { $conn->exec("DELETE FROM students"); logS("  ✓ students"); } catch (Exception $e) { logS("  ⚠ students"); }

// Limpiar teacher_group_access y re-crear
try { $conn->exec("DELETE FROM teacher_group_access"); logS("  ✓ teacher_group_access"); } catch (Exception $e) { logS("  ⚠ teacher_group_access"); }

// Limpiar sensores edge de grupos (mantener secretaría/coordinador)
try {
    $conn->exec("DELETE FROM edge_devices WHERE group_id IS NOT NULL");
    logS("  ✓ edge_devices (grupo)");
} catch (Exception $e) { logS("  ⚠ edge_devices"); }

$conn->exec("SET session_replication_role = 'origin'");
logS("Limpieza completada.\n");

// ── 2. SEED ──────────────────────────────────────────────────────────────

logS("═══ FASE 2: POBLACIÓN ═══");

$school = $conn->query("SELECT school_id FROM schools LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$school) die("No hay escuela en la DB.\n");
$schoolId = $school['school_id'];
$currentYear = (int)date('Y');
logS("Escuela: $schoolId | Año: $currentYear");

// Obtener grupos
$groups = $conn->query("
    SELECT group_id, group_name, grade_level, work_shift
    FROM academic_groups
    WHERE school_id = '{$schoolId}' AND academic_year = {$currentYear}
    ORDER BY grade_level::INT, group_name
")->fetchAll(PDO::FETCH_ASSOC);
logS("Grupos: " . count($groups));

// Obtener docentes
$teachers = $conn->query("
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.work_shift
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE r.role_name = 'TEACHER' AND u.active = TRUE AND u.school_id = '{$schoolId}'
")->fetchAll(PDO::FETCH_ASSOC);
logS("Docentes: " . count($teachers));

// Roberto
$roberto = null;
foreach ($teachers as $t) {
    if (strtolower($t['email'] ?? '') === 'docente@nexo.edu') {
        $roberto = $t;
        break;
    }
}
if ($roberto) logS("Roberto: {$roberto['first_name']} {$roberto['last_name']}");
else logS("⚠ Roberto no encontrado");

// Role GUARDIAN
$guardianRoleId = $conn->query("SELECT role_id FROM roles WHERE role_name = 'GUARDIAN' LIMIT 1")->fetchColumn();
if (!$guardianRoleId) {
    // Crear rol GUARDIAN si no existe
    $conn->exec("INSERT INTO roles (role_id, role_name, description) VALUES (uuid_generate_v4(), 'GUARDIAN', 'Parent/Guardian') ON CONFLICT DO NOTHING");
    $guardianRoleId = $conn->query("SELECT role_id FROM roles WHERE role_name = 'GUARDIAN' LIMIT 1")->fetchColumn();
}

// Crear estudiantes
$studentCount = 0;
$createdStudents = [];
$lauraId = null;
$jhonId = null;

foreach ($groups as $group) {
    $groupId = $group['group_id'];
    $groupName = $group['group_name'];
    $workShift = $group['work_shift'];

    for ($i = 0; $i < 5; $i++) {
        $isFemale = ($i % 2 === 0);
        $names = $isFemale
            ? randomName($FIRST_NAMES_F, $LAST_NAMES)
            : randomName($FIRST_NAMES_M, $LAST_NAMES);

        $isLaura = ($studentCount === 0);
        $isJhon = ($studentCount === 1);

        if ($isLaura) $names = ['first' => 'Laura Victoria', 'last' => 'Arbaláez Salazar'];
        elseif ($isJhon) $names = ['first' => 'Jhon Edison', 'last' => 'Álvarez Roldán'];

        $firstName = $names['first'];
        $lastName = $names['last'];
        $document = randomDoc();
        $phone = ($isLaura || $isJhon) ? '+573243607948' : randomPhone();

        $studentId = $conn->query("SELECT uuid_generate_v4()")->fetchColumn();

        $conn->prepare("
            INSERT INTO students (student_id, school_id, document_number, first_name, last_name, work_shift, grade_level, active, enrollment_date)
            VALUES (?::uuid, ?::uuid, ?, ?, ?, ?, ?, TRUE, NOW())
        ")->execute([$studentId, $schoolId, $document, $firstName, $lastName, $workShift, $group['grade_level']]);

        // Asignar al grupo
        $conn->prepare("INSERT INTO student_group_assignments (student_id, group_id, active, assigned_date) VALUES (?::uuid, ?::uuid, TRUE, NOW())")
            ->execute([$studentId, $groupId]);

        // Crear user acudiente + guardian
        $guardianDoc = randomDoc();
        $lockedHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $guardianUserId = $conn->query("SELECT uuid_generate_v4()")->fetchColumn();
        $conn->prepare("
            INSERT INTO users (user_id, school_id, role_id, document_number, first_name, last_name, phone, password_hash, password_salt, active)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?, '', TRUE)
        ")->execute([$guardianUserId, $schoolId, $guardianRoleId, $guardianDoc, "Acudiente de $firstName", $lastName, $phone, $lockedHash]);

        $guardianId = $conn->query("SELECT uuid_generate_v4()")->fetchColumn();
        $conn->prepare("INSERT INTO guardians (guardian_id, user_id, whatsapp_phone) VALUES (?::uuid, ?::uuid, ?)")
            ->execute([$guardianId, $guardianUserId, $phone]);

        $conn->prepare("INSERT INTO guardian_student_relationships (guardian_id, student_id, relationship_type, primary_guardian) VALUES (?::uuid, ?::uuid, 'padre', TRUE)")
            ->execute([$guardianId, $studentId]);

        $createdStudents[] = [
            'student_id' => $studentId,
            'name' => "$firstName $lastName",
            'group' => $groupName,
            'phone' => $phone,
            'guardian_id' => $guardianId,
        ];

        if ($isLaura) $lauraId = $studentId;
        if ($isJhon) $jhonId = $studentId;
        $studentCount++;
    }
}
logS("Estudiantes creados: $studentCount");
logS("Laura: $lauraId");
logS("Jhon: $jhonId");

// ── 3. Asignar profesores ────────────────────────────────────────────────

logS("\nAsignando profesores...");
foreach ($groups as $group) {
    $groupId = $group['group_id'];
    $workShift = $group['work_shift'];

    if ($roberto) {
        $conn->prepare("INSERT INTO teacher_group_access (school_id, group_id, teacher_user_id, work_shift, academic_year) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?) ON CONFLICT DO NOTHING")
            ->execute([$schoolId, $groupId, $roberto['user_id'], $workShift, $currentYear]);
    }

    $available = array_filter($teachers, fn($t) => (!$roberto || $t['user_id'] !== $roberto['user_id']) && (!$t['work_shift'] || $t['work_shift'] === 'completa' || $t['work_shift'] === $workShift));
    $shuffled = array_values($available);
    shuffle($shuffled);
    $numExtra = min(count($shuffled), mt_rand(1, 2));
    for ($i = 0; $i < $numExtra; $i++) {
        $conn->prepare("INSERT INTO teacher_group_access (school_id, group_id, teacher_user_id, work_shift, academic_year) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?) ON CONFLICT DO NOTHING")
            ->execute([$schoolId, $groupId, $shuffled[$i]['user_id'], $workShift, $currentYear]);
    }
}
logS("Profesores asignados.");

// ── 4. Consultas (5 por tipo, 2 viejas) ─────────────────────────────────

logS("\nConsultas...");
$consultationTypes = ['attendance_status', 'attendance_rate', 'late_count', 'student_list', 'risk_profile'];
$anyUserId = $roberto['user_id'] ?? ($teachers[0]['user_id'] ?? $conn->query("SELECT user_id FROM users LIMIT 1")->fetchColumn());
$consultationCount = 0;

foreach ($consultationTypes as $type) {
    for ($i = 0; $i < 5; $i++) {
        $student = $createdStudents[array_rand($createdStudents)];
        $isOld = ($i < 2);
        $dateOffset = $isOld ? '-35 days' : '-3 days';

        $conn->prepare("
            INSERT INTO student_record_audit (audit_id, school_id, student_id, performed_by_user_id, action_type, previous_data, new_data, performed_at)
            VALUES (uuid_generate_v4(), ?::uuid, ?::uuid, ?::uuid, ?, NULL, ?::jsonb, (NOW() + INTERVAL '{$dateOffset}')::timestamptz)
        ")->execute([
            $schoolId, $student['student_id'], $anyUserId,
            'CONSULTA_' . strtoupper($type),
            json_encode(['type' => $type, 'student_name' => $student['name'], 'seed' => true, 'old' => $isOld]),
        ]);
        $consultationCount++;
    }
}
logS("Consultas: $consultationCount (2 viejas por tipo)");

// ── 5. Casos activos (4 estudiantes) ────────────────────────────────────

logS("\nCasos activos...");
$caseReasons = [
    'Bajo rendimiento académico generalizado',
    'Comportamiento disruptivo en clase',
    'Inasistencias recurrentes',
    'Dificultad de integración social con compañeros',
];

for ($i = 0; $i < 4 && $i < count($createdStudents); $i++) {
    $student = $createdStudents[$i];
    $trackingId = $conn->query("SELECT uuid_generate_v4()")->fetchColumn();

    $conn->prepare("
        INSERT INTO student_tracking (tracking_id, school_id, student_id, status, created_at, updated_at)
        VALUES (?::uuid, ?::uuid, ?::uuid, 'en proceso', NOW() - INTERVAL '7 days', NOW() - INTERVAL '6 days')
    ")->execute([$trackingId, $schoolId, $student['student_id']]);

    $conn->prepare("
        INSERT INTO student_tracking_notes (note_id, tracking_id, user_id, note_text, created_at)
        VALUES (uuid_generate_v4(), ?::uuid, ?::uuid, ?, NOW() - INTERVAL '6 days')
    ")->execute([$trackingId, $anyUserId, "Seguimiento iniciado: {$caseReasons[$i]}"]);

    logS("  ✓ {$student['name']} — {$caseReasons[$i]}");
}
logS("Casos activos: " . min(4, count($createdStudents)));

// ── 6. Sensores de grupo ────────────────────────────────────────────────

logS("\nSensores...");
foreach ($groups as $group) {
    $deviceId = $conn->query("SELECT uuid_generate_v4()")->fetchColumn();
    $conn->prepare("INSERT INTO edge_devices (device_id, school_id, group_id, device_name, active, configured, status) VALUES (?::uuid, ?::uuid, ?::uuid, ?, TRUE, FALSE, 'unknown') ON CONFLICT DO NOTHING")
        ->execute([$deviceId, $schoolId, $group['group_id'], "Sensor {$group['group_name']}"]);
}
logS("Sensores: " . count($groups));

// ── Resumen ──────────────────────────────────────────────────────────────

logS("\n═══ RESUMEN ═══");
logS("Estudiantes: $studentCount");
logS("Grupos: " . count($groups));
logS("Consultas: $consultationCount");
logS("Casos activos: " . min(4, count($createdStudents)));
logS("Sensores: " . count($groups));
logS("Laura: $lauraId (+573243607948)");
logS("Jhon: $jhonId (+573243607948)");
logS("\n✓ Seed completado.");
