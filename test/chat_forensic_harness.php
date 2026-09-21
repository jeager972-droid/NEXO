<?php
/**
 * test/chat_forensic_harness.php — HARNESS FORENSE conversacional.
 *
 * Replica EXACTAMENTE la lógica de POST /chat/message (routes/chat.php
 * líneas 239-347) usando las funciones REALES de producción
 * (nxClassify, nxSlots, nxAllowed, chatOperationCmd, chatAllowed no
 * aplica — necesita DB; se registra el gate con nxAllowed estático).
 *
 * NO ejecuta handlers (requieren PDO real). Registra el handler que
 * chatDispatch elegiría: 'chat_' . $intent.
 *
 * Traza por turno (los 19 campos del plan forense):
 *   1 texto, 2 normalizado, 3 segmentos, 4 dominio, 5 intent, 6 confianza,
 *   7 top3, 8 entidades NLU, 9 slots PHP, 10 ctx recibido, 11 last_intent,
 *   12 slots heredados, 13 slots reemplazados, 14 slots nuevos,
 *   15 ctx resultante, 16 operación, 17 handler, 18 fuente, 19 fallback.
 *
 * Uso:  php test/chat_forensic_harness.php
 * Salida: stdout + /tmp/forensic_traces.json
 */

require __DIR__ . '/../backend/api/lib/nexus_nlu.php';
require __DIR__ . '/../backend/api/routes/chat.php';   // solo define funciones — sin $cleanPath no enruta

const ROLE = 'TEACHER';   // rol simulado — configurable

$TRACES = [];

require __DIR__ . '/harness_turn.php';

/* ═══════════ SUITE DE REGRESIÓN CONVERSACIONAL ═══════════ */

$SUITE = [];

/* GRUPO 1 — cambio de métrica sobre el mismo sujeto */
$SUITE['G1_cambio_metrica'] = [
    ['text' => 'Pásame las evasiones internas de Juan.',
     'expect' => ['intent' => ['list_events','count_events'], 'module' => 'EVASION_INTERNA', 'student' => 'juan']],
    ['text' => 'Ahora las inasistencias.',
     'expect' => ['intent' => ['list_events','count_events'], 'module' => 'INASISTENCIA', 'student' => 'juan']],
    ['text' => 'Ahora las tardanzas.',
     'expect' => ['intent' => ['list_events','count_events','late_today'], 'module' => 'LATE_ARRIVAL']],
    ['text' => 'Ahora solamente las del 8A.',
     'expect' => ['intent' => ['list_events','count_events'], 'group' => '8A']],
    ['text' => '¿Y las del mes pasado?',
     'expect' => ['intent' => ['list_events','count_events'], 'days' => 60]],
];

/* GRUPO 2 — cambio de operación (no quedar atrapada por la anterior) */
$SUITE['G2_cambio_operacion'] = [
    ['text' => 'Quiero citar a un acudiente.',      'expect' => ['intent' => ['start_operation'], 'op' => 'Citar acudiente']],
    ['text' => 'Ahora quiero enviar una solicitud a un docente.', 'expect' => ['intent' => ['start_operation'], 'op' => 'Mandar solicitud']],
    ['text' => 'Ahora quiero generar un permiso.',  'expect' => ['intent' => ['start_operation'], 'op' => 'Generar permiso']],
    ['text' => 'Ahora quiero reportar un incidente.','expect' => ['intent' => ['start_operation'], 'op' => 'Reportar incidente']],
    ['text' => 'Ahora quiero autorizar una salida.', 'expect' => ['intent' => ['start_operation'], 'op' => 'Autorizar salida']],
];

/* GRUPO 3 — paráfrasis de la misma capacidad */
$SUITE['G3_parafrasis_evasion'] = [
    ['text' => '¿Cuántas evasiones tuvo Juan?', 'expect' => ['intent' => ['count_events','student_summary'], 'module' => 'EVASION_INTERNA', 'student' => 'juan']],
    ['text' => '¿Cuántas veces salió de clase Juan?', 'expect' => ['intent' => ['count_events','student_summary','list_events'], 'student' => 'juan']],
    ['text' => '¿Cuántas fugas internas registra Juan?', 'expect' => ['intent' => ['count_events','list_events','student_summary'], 'module' => 'EVASION_INTERNA']],
    ['text' => '¿Cuántas veces abandonó el aula Juan?', 'expect' => ['intent' => ['count_events','list_events','student_summary'], 'module' => 'EVASION_INTERNA']],
    ['text' => 'Muéstrame las evasiones internas.', 'expect' => ['intent' => ['list_events','count_events'], 'module' => 'EVASION_INTERNA']],
];

/* GRUPO 4 — follow-ups cortos tras semilla */
$SUITE['G4_followups'] = [
    ['text' => 'Muéstrame las evasiones de Juan.', 'expect' => ['intent' => ['list_events','count_events'], 'module' => 'EVASION_INTERNA']],
    ['text' => '¿Y las de hoy?', 'expect' => ['intent' => ['list_events','count_events'], 'days' => 0]],
    ['text' => '¿Y del mes pasado?', 'expect' => ['intent' => ['list_events','count_events'], 'days' => 60]],
    ['text' => 'Ahora las del grupo 8A.', 'expect' => ['intent' => ['list_events','count_events'], 'group' => '8A']],
    ['text' => '¿Y las tardanzas?', 'expect' => ['intent' => ['list_events','count_events','late_today'], 'module' => 'LATE_ARRIVAL']],
];

/* GRUPO 5 — fronteras de intents */
$SUITE['G5_fronteras'] = [
    ['text' => 'Quiero citar a un acudiente.', 'expect' => ['intent' => ['start_operation'], 'op' => 'Citar acudiente']],
    ['text' => '¿Cuántas citaciones hay pendientes?', 'expect' => ['intent' => ['citations','list_events','count_events'], 'not_intent' => ['start_operation']]],
    // consulta autónoma sin marcador: si no supera umbral → abstención
    // honesta (out_of_scope) en vez de heredar la consulta de citaciones
    ['text' => '¿Cuántas tardanzas hubo hoy?', 'expect' => ['intent' => ['late_today','count_events','out_of_scope'], 'not_intent' => ['list_events','citations']]],
    ['text' => 'Muéstrame la lista de tardanzas.', 'expect' => ['intent' => ['list_events'], 'not_intent' => ['count_events','late_today']]],
    ['text' => 'Documento de Juan.', 'expect' => ['intent' => ['student_field'], 'field' => 'documento', 'not_intent' => ['student_summary']]],
    ['text' => 'Dame toda la ficha de Juan.', 'expect' => ['intent' => ['student_summary'], 'not_intent' => ['student_field']]],
];

/* GRUPO 6 — contexto + reemplazo de entidad */
$SUITE['G6_entidad_reemplazo'] = [
    ['text' => 'Muéstrame las evasiones de Juan.', 'expect' => ['intent' => ['list_events','count_events'], 'student' => 'juan']],
    ['text' => 'Ahora de María.', 'expect' => ['student' => 'maria', 'student_not' => 'juan']],
];
$SUITE['G6b_grupo_reemplazo'] = [
    ['text' => 'Muéstrame las evasiones de Juan.', 'expect' => ['student' => 'juan']],
    ['text' => 'Ahora del 8A.', 'expect' => ['group' => '8A', 'student' => 'juan']],
];
$SUITE['G6c_rango_reemplazo'] = [
    ['text' => 'Muéstrame las evasiones de Juan.', 'expect' => ['student' => 'juan']],
    ['text' => 'Ahora del mes pasado.', 'expect' => ['days' => 60, 'student' => 'juan']],
];

/* GRUPO 7 — cambio de intención + entidad */
$SUITE['G7_intent_switch'] = [
    ['text' => 'Muéstrame las evasiones de Juan.', 'expect' => ['intent' => ['list_events','count_events']]],
    ['text' => 'Ahora quiero citar a su acudiente.', 'expect' => ['intent' => ['start_operation'], 'op' => 'Citar acudiente']],
];
$SUITE['G7b_switch_inverso'] = [
    // derive_action resuelve la misma operación (chatOperationCmd) — equivalente
    ['text' => 'Quiero citar al acudiente de Juan.', 'expect' => ['intent' => ['start_operation','derive_action'], 'op' => 'Citar acudiente', 'student' => 'juan']],
    // sin modelo nuevo, «ver sus inasistencias» con ctx de operación debe
    // resolver la consulta o caer a fallback honesto — jamás a op equivocada
    ['text' => 'Ahora quiero ver sus inasistencias.', 'expect' => ['intent' => ['list_events','count_events','student_summary','out_of_scope'], 'module' => 'INASISTENCIA', 'student' => 'juan', 'not_intent' => ['start_operation','derive_action'], 'no_op' => true]],
];

/* ── ejecución ── */
$RESULTS = [];
$totP = $totF = 0;
foreach ($SUITE as $gname => $turns) {
    echo "\n╔═ {$gname} " . str_repeat('═', max(0, 60 - strlen($gname))) . "\n";
    $ctx = null; $lastPayload = null;
    foreach ($turns as $i => $t) {
        $r = simulateTurn($t['text'], $ctx, $lastPayload);
        $tr = $r['trace'];
        $exp = $t['expect'];
        $slots = $tr['9_slots_finales'] ?? [];
        $intent = $r['intent'];

        $fails = [];
        if (isset($exp['intent']) && !in_array($intent, $exp['intent'], true))
            $fails[] = "intent={$intent} ∉ [" . implode(',', $exp['intent']) . "]";
        if (isset($exp['not_intent']) && in_array($intent, $exp['not_intent'], true))
            $fails[] = "intent={$intent} prohibido";
        foreach (['module','student','group','days','field'] as $k) {
            if (isset($exp[$k])) {
                $got = $slots[$k] ?? null;
                if ($k === 'student' && $got) $got = mb_strtolower($got);
                if ($got !== $exp[$k]) $fails[] = "{$k}=" . var_export($got, true) . " ≠ " . var_export($exp[$k], true);
            }
        }
        if (isset($exp['student_not']) && mb_strtolower($slots['student'] ?? '') === $exp['student_not'])
            $fails[] = "student sigue siendo {$exp['student_not']} (no reemplazado)";
        if (isset($exp['op']) && ($tr['16_operacion'] ?? null) !== $exp['op'])
            $fails[] = "op=" . var_export($tr['16_operacion'] ?? null, true) . " ≠ {$exp['op']}";
        if (!empty($exp['no_op']) && isset($tr['16_operacion']) && $tr['16_operacion'])
            $fails[] = "operación no debía resolverse, obtuvo {$tr['16_operacion']}";

        $status = $fails ? 'FAIL' : 'PASS';
        $fails ? $totF++ : $totP++;
        echo sprintf("║ %s T%d «%s»\n", $status, $i + 1, $t['text']);
        echo sprintf("║   intent=%s conf=%.2f src=%s slots=%s\n",
            $intent, $tr['6_confianza'] ?? 0, $tr['fuente_clasif'] ?? '?',
            json_encode(array_diff_key($slots, ['_inherited' => 1]), JSON_UNESCAPED_UNICODE));
        if ($tr['12_heredados']) echo "║   heredó: " . implode(',', $tr['12_heredados']) . "\n";
        if (isset($tr['16_operacion'])) echo "║   operación: {$tr['16_operacion']}\n";
        echo "║   handler: {$tr['17_handler']}\n";
        if ($fails) foreach ($fails as $f) echo "║   ✗ {$f}\n";

        // el handler devolvería entities resueltas → ctx del front usa $out['entities']
        $lastPayload = ['intent' => $intent, 'entities' => $slots, 'reply' => '…'];
        $RESULTS[$gname][] = ['turn' => $i + 1, 'text' => $t['text'], 'status' => $status,
            'fails' => $fails, 'trace' => $tr];
    }
}

echo "\n╔══════════════════════════════════════════════════════════════╗\n";
printf("║  RESULTADO: %d PASS · %d FAIL · %.1f%%\n", $totP, $totF,
    ($totP + $totF) ? $totP / ($totP + $totF) * 100 : 0);
echo "╚══════════════════════════════════════════════════════════════╝\n";

file_put_contents('/tmp/forensic_traces.json',
    json_encode($RESULTS, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "→ trazas completas: /tmp/forensic_traces.json\n";
exit($totF ? 1 : 0);
