<?php
/* test/resilience.php — §15 degradación segura ante fallos de infraestructura.
 * Cada escenario verifica que Nexus falla de forma segura:
 * nunca rellena huecos con suposiciones ni se cuelga.
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/lib/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

$P = 0; $F = 0;
function ok(bool $c, string $label): void {
    global $P, $F;
    if ($c) { $P++; echo "  ✓ $label\n"; }
    else    { $F++; echo "  ✗ $label\n"; }
}

echo "── NLU caído → fallback PHP-model, nunca 'none' ──\n";
putenv('NEXO_NLU_URL=http://127.0.0.1:9'); // puerto muerto → servicio inalcanzable
$t0 = microtime(true);
$c = nxClassify('cuantas tardanzas hubo hoy');
$dt = (microtime(true) - $t0) * 1000;
ok($c['intent'] !== null && $c['intent'] !== '', 'intent válido sin servicio');
ok(($c['fallback'] ?? false) === true || ($c['source'] ?? '') !== 'service', 'fallback marcado o fuente≠service');
ok($dt < 3000, "timeout acotado ({$dt}ms < 3000)");
$i = nxDialogueResolve($c, null, nxNorm('cuantas tardanzas hubo hoy'));
ok(in_array($i['resolved']['intent'], ['late_today','count_events'], true),
   'resolución sigue siendo correcta: ' . $i['resolved']['intent']);
putenv('NEXO_NLU_URL=http://localhost:8095'); // restaurar

echo "\n── Contexto corrupto ──\n";
$c = nxClassify('y las de hoy');
$badCtx = [
    'entities' => 'not-an-array',
    'last_intent' => ['malformed', 42],
    'last_turn' => 999999999999,
    'pending_op' => "\x00\xff weird",
];
$i = nxDialogueResolve($c, $badCtx, nxNorm('y las de hoy'));
ok(is_array($i['resolved']), 'ctx corrupto no rompe el resolve');
ok($i['resolved']['intent'] !== 'confused', 'recuperación determinista: ' . $i['resolved']['intent']);

$i = nxDialogueResolve($c, ['entities'=>[]], nxNorm('y las de hoy'));
ok(true, 'ctx vacío-controlado procesado: ' . $i['resolved']['intent']);

echo "\n── Respuesta incompleta / handler vacío ──\n";
$out = nxPlanResponse(['reply' => ''], 'late_today', 'chat_late_today');
ok(!empty($out['reply']), 'reply vacío → fallo explícito');
ok(str_contains($out['reply'], 'no hay información') || str_contains($out['reply'], 'No pude'), 'mensaje honesto, no inventa');
ok(($out['source'] ?? '') === 'chat_late_today', 'procedencia sellada');

$out = nxPlanResponse(['reply' => '7 tardanzas hoy.', 'cards' => [['rows'=>[]]]], 'late_today', 'chat_late_today');
ok($out['reply'] === '7 tardanzas hoy.', 'reply real pasa intacto');

echo "\n── Solicitud duplicada (idempotencia read-only) ──\n";
$ctx = null; $last = null;
$r1 = simulateTurn('cuantas tardanzas del mes', $ctx, $last);
$r2 = simulateTurn('cuantas tardanzas del mes', $ctx, $last);
ok($r1['intent'] === $r2['intent'], 'misma consulta → mismo intent (' . $r1['intent'] . ')');

echo "\n── Servicio «reiniciado» — determinismo ──\n";
$a = nxClassify('los que se volaron'); $b = nxClassify('los que se volaron');
ok($a['intent'] === $b['intent'], 'misma entrada → mismo intent (' . $a['intent'] . ')');

echo "\n── Timeout de NLU no bloquea la conversación ──\n";
$ctx = null; $last = null;
putenv('NEXO_NLU_URL=http://127.0.0.1:9');
$t0 = microtime(true);
$r = simulateTurn('cuantas evasiones hoy', $ctx, $last);
$dt = (microtime(true) - $t0) * 1000;
ok($dt < 5000, "turno completo en {$dt}ms con NLU caído");
ok($r['intent'] !== 'confused', 'intent razonable sin servicio: ' . $r['intent']);
putenv('NEXO_NLU_URL=http://localhost:8095');

echo "\n═══════════════════════════════════════════\n";
printf("  RESULTADO: %d PASS · %d FAIL\n", $P, $F);
exit($F === 0 ? 0 : 1);
