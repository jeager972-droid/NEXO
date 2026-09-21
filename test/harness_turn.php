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

    $intent = $cls['intent'];
    $slots = $cls['entities'] ?? [];

    /* ── herencia de ctx — chat.php (paridad exacta) ── */
    $inherited = []; $replaced = []; $nuevos = [];
    if (is_array($ctx) && is_array($ctx['entities'] ?? null)) {
        foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
            if (empty($slots[$k]) && !empty($ctx['entities'][$k])) {
                $slots[$k] = $ctx['entities'][$k];
                $inherited[] = $k;
            } elseif (!empty($slots[$k])) {
                $nuevos[] = $k;
            }
        }
        $queryIntents = ['list_events','count_events','trackings','permissions','citations',
            'student_field','student_summary','group_summary','top_offenders','pending_returns',
            'attendance_ranking','group_student_count','students_count','devices_status',
            'notifications_unread','audit_query','sos_alerts','biometric_spam','birthdays_today',
            'failed_messages','whatsapp_status','my_activity','pending_tasks','schedule_info',
            'risk_students','export_data'];
        $inheritable = !empty($ctx['last_intent'])
            && in_array($ctx['last_intent'], $queryIntents, true);
        $followupMark = preg_match('/^(y|ahora|pero|tambien|ademas|solo|solamente|entonces|o sea|'
            . 'las|los|esas|esos|estas|estos|esa|ese|este|sus?|del|de la|de lo)\b/u', $q0);
        if (($intent === 'out_of_scope' || ($cls['confidence'] ?? 0) < NX_NLU_THRESHOLD)
            && $inheritable && $followupMark) {
            $intent = $ctx['last_intent'];
            $inherited[] = 'intent';
        }
        // modificación contextual — paridad con chat.php
        $genericIntents = ['day_summary','attendance_today','late_today','count_present'];
        $explicitAction = preg_match('/\b(quiero|deseo|necesito|puedes|podrias|citar|generar|'
            . 'enviar|mandar|reportar|autorizar|crear|abrir|registrar|derivar|exportar|'
            . 'descargar|hacer|empezar|iniciar|lanzar)\b/u', $q0);
        if ($inheritable && $intent !== $ctx['last_intent']
            && in_array($intent, $genericIntents, true)
            && $followupMark && !$explicitAction
            && str_word_count($q0, 0, 'áéíóúñü') <= 8) {
            $intent = $ctx['last_intent'];
            $inherited[] = 'intent_ctx_generic';
        }
    }
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

    /* ── ctx resultante (saveCtx del front) ── */
    if ($intent !== 'out_of_scope') {
        $ctx = [
            'last_intent' => $intent,
            'entities' => array_intersect_key($slots, array_flip(
                ['student','group','module','days','from','to','range_label','field'])),
            'ts' => time(),
        ];
    }
    $tr['15_ctx_resultante'] = $ctx;

    $TRACES[] = $tr;
    return ['intent' => $intent, 'trace' => $tr, 'operation' => $tr['16_operacion'] ?? null];
}

