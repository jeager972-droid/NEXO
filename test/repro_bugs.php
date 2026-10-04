<?php
/* test/repro_bugs.php — reproduce los 6 fallos reales de la conversación.
 * Uso: php test/repro_bugs.php  (sin LLM — fixture vacía = reglas puras)
 */
define('ROLE', 'COORDINATOR');
require_once __DIR__ . '/../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

// sin fixture → reglas puras (nada de cuota LLM)
putenv('NX_CLASSIFY_FIXTURE=');

function show(array $r): string {
    $tr = $r['trace'];
    return 'intent=' . $r['intent']
        . ' | turn=' . ($tr['turn_type'] ?? '')
        . ' | slots=' . json_encode($tr['9_slots_finales'] ?? [], JSON_UNESCAPED_UNICODE);
}

echo "═══ Reproducción de los 6 bugs ═══\n\n";

// Bug 1: «quiero su acudiente» tras «el tercero» debe ser student_field(acudiente)
echo "── Bug 1: quiero su acudiente (referente) ──\n";
$ctx = null; $last = null;
$r = simulateTurn('muéstrame los estudiantes de 10A', $ctx, $last); echo '  T1: ' . show($r) . "\n";
$r = simulateTurn('el tercero', $ctx, $last); echo '  T2: ' . show($r) . "\n";
$r = simulateTurn('quiero su acudiente', $ctx, $last); echo '  T3: ' . show($r) . "\n";
// el verbo de acción sí debe seguir siendo operación («quiero citar a su
// acudiente», «necesito reportar un incidente») — regresión del guard
foreach (['quiero citar al acudiente de juan', 'necesito reportar un incidente'] as $q) {
    $c2 = null; $l2 = null;
    $r2 = simulateTurn($q, $c2, $l2); echo '  «' . $q . '»: ' . show($r2) . "\n";
}

// Bug 2: fechas que se leen como nombre de estudiante
echo "\n── Bug 2: meses como nombre ──\n";
foreach (['quién nació el 14 de febrero de 2023',
          'cumpleaños del 14 de febrero',
          'ausencias del 3 de septiembre'] as $q) {
    $ctx = null; $last = null;
    $r = simulateTurn($q, $ctx, $last); echo '  «' . $q . '»: ' . show($r) . "\n";
    echo '      nxSlots.student=' . var_export(nxSlots(nxNorm($q))['student'] ?? null, true) . "\n";
}

// Bug 3: horario del 8A → schedule.of_group
echo "\n── Bug 3: horario del 8A ──\n";
foreach (['horario del 8A', 'el horario del 10B'] as $q) {
    $ctx = null; $last = null;
    $r = simulateTurn($q, $ctx, $last); echo '  «' . $q . '»: ' . show($r) . "\n";
}

// Bug 4: «y por grupo?» tras tabla de frecuencia → frecuencia por grupo
echo "\n── Bug 4: y por grupo? (contexto frequency_table) ──\n";
$ctx = null; $last = null;
$r = simulateTurn('tabla de frecuencia de tardanzas por día', $ctx, $last); echo '  T1: ' . show($r) . "\n";
$r = simulateTurn('y por grupo?', $ctx, $last); echo '  T2: ' . show($r) . "\n";
$r = simulateTurn('ahora por mes', $ctx, $last); echo '  T3: ' . show($r) . "\n";
$r = simulateTurn('y por día de la semana?', $ctx, $last); echo '  T4: ' . show($r) . "\n";

// Bug 5: dos grupos explícitos → groups.compare
echo "\n── Bug 5: comparación de grupos ──\n";
foreach (['entre 10B y 10C quién tiene más tardanzas',
          'qué grupo tiene más evasiones internas: 10A o 8C',
          'compara el 8A con el 9B en tardanzas'] as $q) {
    $ctx = null; $last = null;
    $r = simulateTurn($q, $ctx, $last); echo '  «' . $q . '»: ' . show($r) . "\n";
    echo '      semGroups=' . json_encode(nxSemGroups(nxNorm($q))) . "\n";
}

// Bug 6: comparación temporal → _compare_ranges
echo "\n── Bug 6: comparación temporal ──\n";
foreach (['octubre vs septiembre en tardanzas',
          'este mes vs el mes pasado en tardanzas',
          'compara las tardanzas de septiembre con las de octubre'] as $q) {
    $ctx = null; $last = null;
    $r = simulateTurn($q, $ctx, $last); echo '  «' . $q . '»: ' . show($r) . "\n";
    echo '      ranges=' . json_encode(nxTimeRanges(nxNorm($q)), JSON_UNESCAPED_UNICODE) . "\n";
}
