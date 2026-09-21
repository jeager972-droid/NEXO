<?php
/**
 * test/harness_turn.php — simulateTurn compartido.
 * Extraído de chat_forensic_harness.php para reutilizar en op_eval.php.
 * Requiere: nexus_nlu.php + routes/chat.php cargados, const ROLE, $TRACES global.
 */

/**
 * Réplica fiel del flujo /chat/message hasta el punto de dispatch.
 * $ctx = sessionStorage simulado (se pasa por referencia entre turnos).
 */
function simulateTurn(string $text, ?array &$ctx, ?array $lastPayload): array {
    global $TRACES;
    $tr = [];
    $tr['1_texto'] = $text;
    $q0 = nxNorm($text);
    $tr['2_normalizado'] = $q0;
    $tr['10_ctx_recibido'] = $ctx;
    $tr['11_last_intent_previo'] = $ctx['last_intent'] ?? null;

    /* ── follow-up «dame otro» — chat.php:263-277 ── */
    if (preg_match('/^(dame |dime )?(otro|otra|uno mas|una mas|mas|siguiente|otra vez|y otro|y otra|de nuevo|dame mas|dime mas|continua|sigue|y eso|y ese|y esa)[.! ]*$/u', $q0)) {
        $tr['followup_detectado'] = true;
        $tr['last_payload_db'] = $lastPayload;
        if ($lastPayload && !empty($lastPayload['intent'])) {
            $tr['5_intent_final'] = $lastPayload['intent'];
            $tr['slots'] = $lastPayload['entities'] ?? [];
            $tr['slots']['_repeat'] = true;
            $tr['17_handler'] = 'chat_' . $lastPayload['intent'];
            $tr['18_fuente'] = 'followup_db';
            $TRACES[] = $tr;
            return ['intent' => $lastPayload['intent'], 'trace' => $tr];
        }
    }
    $tr['followup_detectado'] = false;

    /* ── clasificación — chat.php:279 (servicio→php-model→none) ── */
    $cls = nxClassify($text);
    $tr['3_partes_multi'] = array_map(fn($p) => ['text' => $p['text'] ?? null,
        'intent' => $p['intent'], 'conf' => $p['confidence'] ?? null], $cls['parts'] ?? []);
    $tr['4_dominio'] = $cls['domain'] ?? null;
    $tr['5_intent_crudo'] = $cls['intent'];
    $tr['6_confianza'] = $cls['confidence'] ?? 0;
    $tr['7_top3'] = $cls['top3'] ?? [];
    $tr['8_entidades'] = $cls['entities'] ?? [];
    $tr['fallback_nlu'] = $cls['fallback'] ?? false;
    $tr['fuente_clasif'] = $cls['source'] ?? 'service';

    /* ── Dialogue State Manager — fuente única nxDialogueResolve (paridad
       con chat.php por construcción: mismo código) ── */
    $interp = nxDialogueResolve($cls, $ctx, $q0);
    $intent = $interp['resolved']['intent'];
    $slots  = $interp['resolved']['slots'];
    /* paridad chat.php: «su grupo» → resolución estudiante→grupo.
       En producción es chatResolveStudent (BD+scope); aquí, fixture
       determinista documentada (el blind asume juan→8A en T2). */
    if (($slots['_ref'] ?? null) === 'group_of_student' && !empty($slots['student'])) {
        $fixture = ['juan' => '8A', 'maria' => '7B', 'pedro' => '10A'];
        if (isset($fixture[$slots['student']])) $slots['group'] = $fixture[$slots['student']];
    }
    $inherited = $interp['resolved']['inherited'];
    $nuevos    = $interp['resolved']['new_slots'];
    $tr['turn_type'] = $interp['turn_type'];
    $tr['requires_clarification'] = $interp['requires_clarification'];
    if ($interp['requires_clarification']) {
        $tr['5_intent_final'] = 'clarify';
        $tr['17_handler'] = 'nxClarify';
        $ctx = $interp['ctx'];
        $TRACES[] = $tr;
        return ['intent' => 'clarify', 'trace' => $tr, 'operation' => null];
    }
    /* ── confirmación/cancelación de operación pendiente — paridad chat.php ── */
    $pendingOp = $ctx['entities']['_op'] ?? ($slots['_op'] ?? null);
    if ($interp['turn_type'] === 'confirmation' && $pendingOp) {
        $tr['5_intent_final'] = 'confirm_op';
        $tr['16_operacion'] = $pendingOp;
        $tr['17_handler'] = 'confirm_op(chip)';
        $tr['9_slots_finales'] = $slots;
        $ctx = $interp['ctx'];
        $TRACES[] = $tr;
        return ['intent'=>'confirm_op','trace'=>$tr,'operation'=>$pendingOp,'slots'=>$slots];
    }
    if ($interp['turn_type'] === 'cancel') {
        $tr['5_intent_final'] = 'cancel';
        $tr['17_handler'] = 'cancel';
        $ctx = $interp['ctx'];
        unset($ctx['entities']['_op']);
        $TRACES[] = $tr;
        return ['intent'=>'cancel','trace'=>$tr,'operation'=>null];
    }
    if ($interp['turn_type'] === 'op_repeat' && $pendingOp) {
        $tr['5_intent_final'] = 'repeat_op';
        $tr['16_operacion'] = $pendingOp;
        $tr['17_handler'] = 'repeat_op(chip)';
        $tr['9_slots_finales'] = $slots;
        $ctx = $interp['ctx'];
        if (is_array($ctx['entities'] ?? null)) $ctx['entities']['_op'] = $pendingOp;
        $TRACES[] = $tr;
        return ['intent'=>'repeat_op','trace'=>$tr,'operation'=>$pendingOp,'slots'=>$slots];
    }
    // operación resuelta → pending_op al ctx (paridad: el front lo guarda)
    if (in_array($intent, ['start_operation','derive_action'], true))
        $slots['_op'] = $slots['_op'] ?? chatOperationCmd($q0);

    $tr['12_heredados'] = $inherited;
    $tr['13_slots_nuevos'] = $nuevos;
    $tr['9_slots_finales'] = $slots;
    $tr['5_intent_final'] = $intent;

    /* ── RBAC estático (nxAllowed — la política escolar necesita DB) ── */
    $tr['rbac_estatico'] = nxAllowed($intent, ROLE);

    /* ── resolución de operación ── */
    if ($intent === 'start_operation' || $intent === 'derive_action') {
        $tr['16_operacion'] = chatOperationCmd($q0);
        $tr['16_accion_permitida'] = chatCanAction($tr['16_operacion'], ROLE);
    }

    /* ── handler que despacharía chatDispatch ── */
    $smalltalk = ['greeting','greeting_time','wellbeing','wellbeing_reply','joke',
        'fun_fact','about_nexus','name_meaning','creator','age','thanks','goodbye',
        'yes','no','apology','compliment','insult','bored','love','human_check',
        'do_for_me','emotion_sad','weather','news_sports','food_music',
        'meaning_life','confused','repeat','insult_back','sing','dance','story',
        'motivation','out_of_scope','security_probe','foreign_culture'];
    if (in_array($intent, ['help','capabilities'], true)) $tr['17_handler'] = 'chatHelp($role)';
    elseif (in_array($intent, $smalltalk, true)) $tr['17_handler'] = 'nxSmalltalk(' . $intent . ')';
    elseif (function_exists('chat_' . $intent)) $tr['17_handler'] = 'chat_' . $intent;
    else $tr['17_handler'] = 'nxSmalltalk(out_of_scope) [sin handler]';

    /* ── ctx resultante (saveCtx del front) — lo entrega el DSM;
       si el turno resolvió operación, el _op persistido también viaja ── */
    $ctx = $interp['ctx'];
    if (isset($slots['_op']) && is_array($ctx['entities'] ?? null))
        $ctx['entities']['_op'] = $slots['_op'];
    $tr['15_ctx_resultante'] = $ctx;

    $TRACES[] = $tr;
    return ['intent' => $intent, 'trace' => $tr, 'operation' => $tr['16_operacion'] ?? null];
}

