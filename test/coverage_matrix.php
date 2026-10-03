<?php
/* test/coverage_matrix.php — matriz de cobertura del sistema Nexus (AGENTS.md F1).
 *
 * Verifica, por cada intent de datos del sistema:
 *   1. handler:   existe chat_{intent}() en routes/chat.php (o handlerMap)
 *   2. rbac:      tiene entrada en nxIntentRoles() — sin ella niega por defecto
 *   3. prompt:    aparece en el catálogo de intents del system prompt del LLM
 *   4. reachable: está en NX_QUERY_INTENTS o es smalltalk/meta/utilidad
 *
 * Falla si cualquier celda queda hueca: un intent que el LLM puede emitir sin
 * handler, o un handler huérfano que ningún intent alcanza.
 *
 * Uso: php test/coverage_matrix.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/backend/api/nexus/nexus_nlu.php';
require_once $root . '/backend/api/nexus/nexus_llm.php';
require_once $root . '/backend/api/routes/chat.php';

$pass = 0; $fail = 0; $fails = [];
function chk(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($ok) { $pass++; return; }
    $fail++; $fails[] = "$label — $detail";
    fwrite(STDERR, "  FAIL  $label — $detail\n");
}

/* ── fuentes de verdad ─────────────────────────────────────────────────── */

$chatSrc   = file_get_contents($root . '/backend/api/routes/chat.php');
$llmSrc    = file_get_contents($root . '/backend/api/nexus/nexus_llm.php');
$intentRoles = nxIntentRoles();

// intents que el LLM puede emitir según el system prompt
$prompt = nxLlmSystemPrompt();
preg_match_all('/\b([a-z][a-z_]{2,40})\b/', $prompt, $m);
$promptIntents = array_unique($m[1]);

// intents heredables/contextuales (tema activo)
$queryIntents = NX_QUERY_INTENTS;

// intents de datos = los que tienen matriz RBAC + los del enum del prompt
// menos smalltalk/meta/utilidades (no son "datos institucionales")
$meta = array_merge(NX_SMALLTALK_INTENTS, [
         'farewell','ack','affirm','deny','clarify',
         'result_nav','confirm_op','cancel','repeat_op','deictic','out_of_scope',
         'security_probe','about_me','smalltalk','composed',
         'time','date','math_operation','random_number','random_department',
         'colombia_capital','colombia_culture','colombia_department',
         'colombia_fun_fact','colombia_geography','colombia_history',
         'colombia_president','random_student']);

/* ── 1. todo intent con RBAC tiene handler ─────────────────────────────── */
foreach (array_keys($intentRoles) as $intent) {
    if (in_array($intent, ['time','date'], true)) continue; // utilities internas
    $fn = 'chat_' . $intent;
    $hasHandler = function_exists($fn)
        || str_contains($chatSrc, "'$intent'=>'chat_")
        || str_contains($chatSrc, "\"$intent\"=>'chat_");
    chk("handler:$intent", $hasHandler, "$fn() no existe ni está en handlerMap");
}

/* ── 2. todo handler chat_* es alcanzable por un intent ────────────────── */
preg_match_all('/function (chat_[a-z_]+)\s*\(/', $chatSrc, $hm);
$reachable = [];
// intents con RBAC + intents abiertos (smalltalk/utilidades) — todos
// despachan por convención 'chat_' . $intent
foreach (array_merge(array_keys($intentRoles), $meta) as $i) $reachable['chat_' . $i] = true;
preg_match_all('/\'([a-z_]+)\'=>\'(chat_[a-z_]+)\'/', $chatSrc, $mm);
foreach ($mm[2] as $h) $reachable[$h] = true;
// helpers internos que NO son handlers de intent (firma distinta / uso directo)
$helpers = ['chat_last_payload','chat_result_nav','chat_build_ds','chat_scope',
            'chat_resolve_student','chat_resolve_staff','chat_resolve_group',
            'chat_group_scope','chat_grade_summary','chat_incident_filter',
            'chat_inc_group_join','chat_inc_group_col','chat_excuse_expr',
            'chat_derived_actions','chat_ambiguous','chat_pick_candidate',
            'chat_module_delegate','chat_short_range_offer','chat_offer',
            'chat_operation_cmd','chat_staff_profile','chat_list_incidents',
            'chat_who','chat_range','chat_range_label','chat_d','chat_ts',
            'chat_help','chat_last_card','chatNotificationsDetail',
            'chat_notifications_detail','chat_vary','chat_vary_clean'];
foreach ($hm[1] as $fn) {
    if (in_array($fn, $helpers, true)) continue;
    $intent = substr($fn, 5);
    $ok = isset($reachable[$fn]) || in_array($intent, array_keys($intentRoles), true);
    chk("reachable:$fn", $ok, "handler huérfano — ningún intent lo despacha");
}

/* ── 3. todo intent de datos aparece en el prompt del LLM ──────────────── */
foreach (array_keys($intentRoles) as $intent) {
    if (in_array($intent, ['time','date'], true)) continue;
    chk("prompt:$intent", in_array($intent, $promptIntents, true),
        "el LLM no puede emitirlo — falta en el catálogo del prompt");
}

/* ── 4. intents del prompt que no existen en RBAC (el LLM podría
         emitir algo sin handler autorizado) ─────────────────────────────── */
$knownExtra = ['set_ref','nav','order','limit','group_by','compare','clarify_answer',
               'uses_context','needs','entities','confidence','intent','module',
               'student','group','grade','person','field','from','to','range_label',
               'days','position','relation','presentation','export_format','trend',
               'justified','status','scope','op','detail','shift','evasion','permiso',
               'salida','incidente','seguimiento','citacion','sos','inasistencia',
               'late_arrival','evasion_interna','salida_colegio','salida_pedagogica'];
foreach ($promptIntents as $tok) {
    if (isset($intentRoles[$tok]) || in_array($tok, $meta, true) || in_array($tok, $knownExtra, true)) continue;
    if (in_array($tok, $queryIntents, true)) continue;
    // tokens sueltos del prompt que parecen intent: snake_case y largos
    if (preg_match('/^[a-z]+_[a-z_]+$/', $tok) && strlen($tok) > 5) {
        chk("prompt_extra:$tok", false, "el prompt menciona «{$tok}» pero no tiene RBAC/handler — ¿intent fantasma?");
    }
}

/* ── 5. NX_QUERY_INTENTS ⊆ intents con RBAC (nada heredable sin permiso) ── */
foreach ($queryIntents as $intent) {
    if (in_array($intent, ['result_nav'], true)) continue; // nav no es RBAC-data
    chk("rbac:$intent", isset($intentRoles[$intent]),
        "heredable por contexto pero sin entrada en nxIntentRoles");
}

/* ── resumen ───────────────────────────────────────────────────────────── */
echo "\nCOVERAGE  pass=$pass  fail=$fail\n";
if ($fails) { foreach ($fails as $f) echo "  · $f\n"; exit(1); }
echo "Matriz completa: cada intent tiene handler + RBAC + prompt.\n";
exit(0);
