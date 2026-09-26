<?php
/* test/resilience.php — §15 degradación segura ante fallos de infraestructura.
 * Cada escenario verifica que Nexus falla de forma segura:
 * nunca rellena huecos con suposiciones ni se cuelga.
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

$P = 0; $F = 0;
function ok(bool $c, string $label): void {
    global $P, $F;
    if ($c) { $P++; echo "  ✓ $label\n"; }
    else    { $F++; echo "  ✗ $label\n"; }
}

echo "── Parser LLM ausente → out_of_scope honesto, nunca inventa ──\n";
putenv('NX_CLASSIFY_FIXTURE');  // replay apagado → path real del parser
putenv('NLU_LLM_KEY=');         // sin key → proveedor deshabilitado
$t0 = microtime(true);
$c = nxClassify('cuantas tardanzas hubo hoy');
$dt = (microtime(true) - $t0) * 1000;
ok($c['intent'] === 'out_of_scope', 'sin parser → out_of_scope: ' . $c['intent']);
ok(($c['source'] ?? '') === 'none' || ($c['fallback'] ?? false), 'marcado como fallback');
ok($dt < 3000, "sin espera de red ({$dt}ms < 3000)");
// con parser caído, un sustantivo de módulo recupera determinista el intent
// de su consulta («cuántas tardanzas» → count_events) — eso es resiliencia
// reglada, no invención: fuera de dominio sigue siendo out_of_scope
$i = nxDialogueResolve($c, null, nxNorm('cuantas tardanzas hubo hoy'));
ok(in_array($i['resolved']['intent'], ['count_events','list_events'], true),
   'DSM recupera intent de dominio sin parser: ' . $i['resolved']['intent']);
$c2 = nxClassify('de que color es el cielo de noche');
$i2 = nxDialogueResolve($c2, null, nxNorm('de que color es el cielo de noche'));
ok($i2['resolved']['intent'] === 'out_of_scope',
   'fuera de dominio sigue honesto: ' . $i2['resolved']['intent']);
putenv('NX_CLASSIFY_FIXTURE=' . __DIR__ . '/fixtures/llm_intents.json'); // restaurar replay

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

echo "\n── Parser caído no bloquea la conversación ──\n";
$ctx = null; $last = null;
putenv('NX_CLASSIFY_FIXTURE');   // replay apagado → path real
putenv('NLU_LLM_KEY=');          // proveedor deshabilitado
$t0 = microtime(true);
$r = simulateTurn('cuantas evasiones hoy', $ctx, $last);
$dt = (microtime(true) - $t0) * 1000;
ok($dt < 5000, "turno completo en {$dt}ms con parser caído");
ok($r['intent'] !== 'confused', 'degrada honesto: ' . $r['intent']);
putenv('NX_CLASSIFY_FIXTURE=' . __DIR__ . '/fixtures/llm_intents.json');

echo "\n═══════════════════════════════════════════\n";
printf("  RESULTADO: %d PASS · %d FAIL\n", $P, $F);
exit($F === 0 ? 0 : 1);
