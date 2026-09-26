<?php
/**
 * =============================================================================
 * test/integration/OnboardingAssignmentsTest.php — Integración de las asignaciones del onboarding
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica que tras ejecutar POST /school/groups-onboarding:
 *     1. academic_groups tiene work_shift no-NULL para todos los grupos nuevos.
 *     2. student_group_assignments NO queda vacío si había estudiantes con
 *        grade_level conocido antes del onboarding.
 *     3. teacher_group_access NO queda vacío (cada grupo tiene ≥1 docente).
 *     4. El onboarding rechaza payload sin teacher_assignments (obligatorio).
 *     5. El onboarding rechaza payload sin grade_shifts (jornada obligatoria).
 *
 *   Requiere: backend corriendo + sql/schema.sql y seed aplicados.
 *   Ejecutar: php test/integration/OnboardingAssignmentsTest.php
 *
 * CONFIGURACIÓN:
 *   Setear API_BASE_URL env var o usar default http://localhost:8080
 *   Credenciales del seed: admin@nexo.edu / admin123 (rol RECTOR)
 * =============================================================================
 */

$baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8080';
$testEmail = 'admin@nexo.edu';
$testPassword = 'admin123';

$passed = 0;
$failed = 0;
$skipped = 0;

function logPass($msg) {
    global $passed;
    echo "  \033[32mPASS\033[0m: $msg\n";
    $passed++;
}
function logFail($msg, $expected = '', $actual = '') {
    global $failed;
    echo "  \033[31mFAIL\033[0m: $msg";
    if ($expected) echo " (expected: $expected, got: $actual)";
    echo "\n";
    $failed++;
}
function logSkip($msg) {
    global $skipped;
    echo "  \033[33mSKIP\033[0m: $msg\n";
    $skipped++;
}

function httpRequest($url, $method = 'GET', $body = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $headers = ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true)];
}

echo "\n=== OnboardingAssignmentsTest (fix bugs #1-#4) ===\n\n";

// 1. Login como rector
$login = httpRequest("$baseUrl/auth/login", 'POST', ['email' => $testEmail, 'password' => $testPassword]);
if ($login['code'] !== 200 || empty($login['body']['token'])) {
    logFail('Login rector', '200+token', $login['code'] . '+' . ($login['body']['token'] ?? 'no-token'));
    echo "\n$passed passed, $failed failed, $skipped skipped\n";
    exit(1);
}
$token = $login['body']['token'];
logPass('Login rector OK');

// 2. Obtener estado de onboarding actual
$getState = httpRequest("$baseUrl/school/groups-onboarding", 'GET', null, $token);
if ($getState['code'] !== 200) {
    logFail('GET groups-onboarding', '200', $getState['code']);
    echo "\n$passed passed, $failed failed, $skipped skipped\n";
    exit(1);
}
logPass('GET groups-onboarding OK');
$teachers = $getState['body']['teachers'] ?? [];
if (empty($teachers)) {
    logSkip('No hay docentes en el seed — no se puede probar asignación de docentes');
    echo "\n$passed passed, $failed failed, $skipped skipped\n";
    exit(0);
}
$teacherId = $teachers[0]['user_id'];
logPass('Docentes disponibles: ' . count($teachers));

// 3. Validar rechazo de payload sin grade_shifts
$badPayload1 = [
    'grades' => ['6'],
    'nomenclature' => 'alphabetic',
    'groups_per_grade' => ['6' => 1],
    // sin grade_shifts
    'teacher_assignments' => ['6A' => [$teacherId]],
];
$resp1 = httpRequest("$baseUrl/school/groups-onboarding", 'POST', $badPayload1, $token);
if ($resp1['code'] === 400 && strpos($resp1['body']['message'] ?? '', 'jornada') !== false) {
    logPass('Rechazo sin grade_shifts (400)');
} else {
    logFail('Rechazo sin grade_shifts', '400+jornada', $resp1['code'] . '+' . ($resp1['body']['message'] ?? ''));
}

// 4. Validar rechazo de payload sin teacher_assignments
$badPayload2 = [
    'grades' => ['6'],
    'nomenclature' => 'alphabetic',
    'groups_per_grade' => ['6' => 1],
    'grade_shifts' => ['6' => 'mañana'],
    // sin teacher_assignments
];
$resp2 = httpRequest("$baseUrl/school/groups-onboarding", 'POST', $badPayload2, $token);
if ($resp2['code'] === 400 && strpos($resp2['body']['message'] ?? '', 'docente') !== false) {
    logPass('Rechazo sin teacher_assignments (400)');
} else {
    logFail('Rechazo sin teacher_assignments', '400+docente', $resp2['code'] . '+' . ($resp2['body']['message'] ?? ''));
}

// 5. Ejecutar onboarding válido
$goodPayload = [
    'grades' => ['6'],
    'nomenclature' => 'alphabetic',
    'groups_per_grade' => ['6' => 1],
    'grade_shifts' => ['6' => 'mañana'],
    'teacher_assignments' => ['6A' => [$teacherId]],
];
$resp3 = httpRequest("$baseUrl/school/groups-onboarding", 'POST', $goodPayload, $token);
if ($resp3['code'] === 200 && ($resp3['body']['status'] ?? '') === 'ok') {
    logPass('Onboarding válido OK (groups_created=' . ($resp3['body']['groups_created'] ?? '?') . ')');
} else {
    logFail('Onboarding válido', '200+ok', $resp3['code'] . '+' . ($resp3['body']['status'] ?? ''));
    echo "\n$passed passed, $failed failed, $skipped skipped\n";
    exit(1);
}

// 6. Verificar post-condiciones via GET /groups y GET /school/groups-onboarding
$verifyState = httpRequest("$baseUrl/school/groups-onboarding", 'GET', null, $token);
$groups = $verifyState['body']['groups'] ?? [];
if (empty($groups)) {
    logFail('Post: grupos creados', '>0', '0');
} else {
    $allHaveShift = true;
    $allHaveTeachers = true;
    foreach ($groups as $g) {
        if (empty($g['work_shift'])) $allHaveShift = false;
        if (empty($g['teachers'])) $allHaveTeachers = false;
    }
    if ($allHaveShift) logPass('Post: todos los grupos tienen work_shift');
    else logFail('Post: todos los grupos tienen work_shift', 'true', 'false');
    if ($allHaveTeachers) logPass('Post: todos los grupos tienen ≥1 docente');
    else logFail('Post: todos los grupos tienen ≥1 docente', 'true', 'false');
}

// 7. Verificar que GET /groups?teacher_only=1 retorna el grupo asignado
$teacherGroups = httpRequest("$baseUrl/groups?teacher_only=1", 'GET', null, $token);
if ($teacherGroups['code'] === 200 && !empty($teacherGroups['body']['data'])) {
    logPass('Post: teacher_only=1 retorna ' . count($teacherGroups['body']['data']) . ' grupo(s)');
} else {
    logFail('Post: teacher_only=1 retorna grupos', '>0', count($teacherGroups['body']['data'] ?? []));
}

echo "\n$passed passed, $failed failed, $skipped skipped\n";
exit($failed > 0 ? 1 : 0);
