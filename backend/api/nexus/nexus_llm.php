<?php
/**
 * =============================================================================
 * nexus/nexus_llm.php — Parser semántico LLM (API compatible-OpenAI).
 * =============================================================================
 *
 * El LLM NO responde al usuario ni toca la BD: traduce texto libre → intent de
 * la taxonomía + entidades. La salida entra al pipeline de intents (DSM → SCP →
 * planner → RBAC → SQL read-only), así que un parseo errado nunca puede
 * escribir ni saltarse autorización.
 *
 * Proveedor agnóstico vía env:
 *   NLU_LLM_URL    base URL OpenAI-compatible (default https://api.groq.com/openai/v1)
 *   NLU_LLM_KEY    API key (vacío ⇒ LLM deshabilitado)
 *   NLU_LLM_MODEL  modelo (default qwen/qwen3.8-27b — Groq free 1k req/día,
 *                  8k tokens/min; alternativas en la cuenta: openai/gpt-oss-20b,
 *                  openai/gpt-oss-120b)
 *   NLU_LLM_MODE   off | on  ('primary'/'fallback' se aceptan como 'on' por
 *                  compatibilidad de env)
 *   NLU_LLM_TIMEOUT_MS  (default 6000)
 */

function nxLlmCfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $mode = strtolower((string)(getenv('NLU_LLM_MODE') ?: 'on'));
    $c = [
        'url'   => rtrim((string)(getenv('NLU_LLM_URL') ?: 'https://api.groq.com/openai/v1'), '/'),
        'key'   => (string)(getenv('NLU_LLM_KEY') ?: ''),
        // GROQ_MODEL aceptado como alias — evita el fallo silencioso de
        // configurar el nombre del proveedor en Render y no verlo aplicado
        'model' => (string)(getenv('NLU_LLM_MODEL') ?: getenv('GROQ_MODEL') ?: 'qwen/qwen3.8-27b'),
        'mode'  => $mode === 'off' ? 'off' : 'on',
        'ms'    => max(500, (int)(getenv('NLU_LLM_TIMEOUT_MS') ?: 6000)),
    ];
    return $c;
}

function nxLlmEnabled(): bool {
    $c = nxLlmCfg();
    return $c['key'] !== '' && $c['mode'] !== 'off';
}

/* ============================================================================
 * Transporte compartido — presupuesto de cuota.
 * ----------------------------------------------------------------------------
 * Groq free: 8.000 tokens/min y 1.000 req/día POR CLAVE (compartida por todos
 * los usuarios de la institución). Un 429 no se reintenta a ciegas: se
 * espera el Retry-After solo si es corto (parser) y se abre un cooldown en
 * Redis para que los turnos siguientes vayan directo al respaldo
 * determinista en vez de chocar otra vez contra el límite.
 * ========================================================================== */
function nxLlmRedis() {
    static $r = false;
    if ($r !== false) return $r;
    $r = null;
    try { if (function_exists('getRedisConnection')) $r = getRedisConnection(); } catch (Throwable $e) { $r = null; }
    return $r;
}

function nxLlmCooling(): bool {
    static $localUntil = 0;
    if (getenv('NX_LLM_IGNORE_COOLDOWN') === '1') return false;
    if (time() < $localUntil) return true;
    try {
        $rd = nxLlmRedis();
        $until = $rd ? (int)$rd->get('nx:llm:cooldown_until') : 0;
        if ($until > time()) { $localUntil = $until; return true; }
    } catch (Throwable $e) {}
    return false;
}

function nxLlmSetCooldown(int $secs): void {
    $until = time() + max(2, min(90, $secs));
    try { $rd = nxLlmRedis(); if ($rd) $rd->setex('nx:llm:cooldown_until', max(2, min(90, $secs)), (string)$until); } catch (Throwable $e) {}
}

/** Última falla del proveedor — diagnóstico en traza (sin datos sensibles). */
function nxLlmLastError(?string $set = null): ?string {
    static $last = null;
    if ($set !== null) $last = $set;
    return $last;
}

/**
 * POST /chat/completions → arreglo decodificado o null.
 * $retry429: un reintento si el proveedor pide esperar ≤ 2.5 s.
 */
function nxLlmPost(array $payload, bool $retry429 = false): ?array {
    if (!nxLlmEnabled()) { nxLlmLastError('disabled'); return null; }
    if (nxLlmCooling()) { nxLlmLastError('cooldown'); return null; }
    $c = nxLlmCfg();
    for ($attempt = 0; $attempt < ($retry429 ? 2 : 1); $attempt++) {
        $hdr = [];
        $ch = curl_init($c['url'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['key']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT_MS => $c['ms'],
            CURLOPT_CONNECTTIMEOUT_MS => min(1500, $c['ms']),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
                $p = strpos($line, ':');
                if ($p !== false) $hdr[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res !== false && $code === 200) {
            $d = json_decode((string)$res, true);
            return is_array($d) ? $d : null;
        }
        if ($code === 429) {
            $ra = (float)($hdr['retry-after'] ?? 0);
            if ($ra <= 0 && is_string($res) && preg_match('/try again in ([\d.]+)(ms|s)/i', $res, $m))
                $ra = $m[2] === 'ms' ? (float)$m[1] / 1000 : (float)$m[1];
            if ($retry429 && $attempt === 0 && $ra > 0 && $ra <= 2.5) { usleep((int)($ra * 1e6) + 150000); continue; }
            nxLlmSetCooldown((int)ceil($ra > 0 ? $ra : 12));
            nxLlmLastError('429');
            return null;
        }
        nxLlmLastError($res === false ? 'timeout' : "http_{$code}");
        return null;
    }
    return null;
}

/** Contenido JSON del mensaje del asistente (o null). */
function nxLlmJsonContent(?array $d): ?array {
    $content = $d['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') return null;
    // algunos modelos envuelven el JSON en ```json … ``` o anteponen <think>
    $content = preg_replace('/^.*?<\/think>/s', '', $content);
    $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
    $j = json_decode($content, true);
    if (!is_array($j) && preg_match('/\{.*\}/s', $content, $m)) $j = json_decode($m[0], true);
    return is_array($j) ? $j : null;
}

/* Taxonomía de intents del chat — whitelist que valida la salida del LLM. */
const NX_LLM_FORMAL = [
    'day_summary','attendance_today','late_today','count_events','list_events',
    'student_field','student_summary','group_summary','risk_students',
    'trackings','permissions','citations','devices_status',
    'notifications_unread','audit_query','students_count','groups_list',
    'teachers_list','schedule_info','export_data','derive_action',
    'about_me','help','capabilities','security_probe',
    'random_student','staff_lookup','start_operation','count_present',
    'count_trackings','students_in_group','top_offenders','pending_returns',
    'sos_alerts','biometric_spam','group_student_count','birthdays_today',
    'my_activity','failed_messages','risk_config','attendance_ranking',
    'session_summary','pending_tasks','whatsapp_status','frequency_table',
    'system_incidents','guardian_replies',
    // intents derivados del modelo de datos (tabla × interrogativa)
    'risk_reason','incident_excuses','exit_detail','trip_info',
    'school_calendar','staff_contact','teacher_schedule','student_consent',
    'tracking_detail','citations_by','alert_resolution','enrollment_stats',
    'reports_log','sos_detail','guardian_messages','device_detail',
    'attendance_trend',
];
const NX_LLM_INFORMAL = [
    'greeting','greeting_time','wellbeing','wellbeing_reply','joke','fun_fact',
    'about_nexus','name_meaning','creator','age','thanks','goodbye','yes','no',
    'apology','compliment','insult','bored','love','human_check','do_for_me',
    'emotion_sad','weather','news_sports','food_music','meaning_life',
    'confused','repeat','insult_back','sing','dance','story','motivation',
    'time','date','out_of_scope','colombia_capital','colombia_department',
    'colombia_president','colombia_history','colombia_geography',
    'colombia_culture','colombia_fun_fact','foreign_culture','math_operation',
];

/* ============================================================================
 * LLM #2 — RESPONSE COMPOSER
 * ----------------------------------------------------------------------------
 * Reescribe el reply determinista (verdad verificada por NEXO) en español
 * natural. NUNCA genera datos: solo reformula `verified_reply`. Las tarjetas,
 * filas y números las renderiza el pipeline — el modelo no las toca.
 *
 * Config: NLU_LLM_COMPOSE = off | data | all
 *   off  → composer deshabilitado (0 cuota)
 *   data → solo intents operativos + clarify (smalltalk ya suena natural)
 *   all  → cualquier reply
 * ========================================================================== */
function nxLlmComposeMode(): string {
    // defecto 'data': el composer reformula las respuestas de datos — es lo
    // que separa "sistema que responde" de "tabla con letrero". NLU_LLM_
    // COMPOSE=off lo apaga si la cuota del proveedor se vuelve el problema.
    $m = strtolower((string)(getenv('NLU_LLM_COMPOSE') ?: 'data'));
    return in_array($m, ['off','data','all'], true) ? $m : 'data';
}

function nxLlmComposerPrompt(): string {
    return <<<'PROMPT'
Eres el compositor de respuestas de Nexus (asistente del sistema escolar NEXO). Recibes la pregunta del usuario, la RESPUESTA VERIFICADA del sistema (verdad absoluta) y "facts" con lo que el usuario ve en las tablas. Reescríbela en español colombiano natural y breve. Devuelve {"reply":"..."}.
REGLAS:
- Conserva TODAS las cifras, nombres y fechas de verified_reply. No agregues cifras, nombres ni fechas que no estén en verified_reply o facts.
- Si facts.rows > 0, NUNCA digas que no hay datos, que no tienes la información o que no se registró nada.
- No inventes causas, explicaciones ni contexto (nada de "el día apenas empieza", "falló la red", "aún no").
- No hagas preguntas ni ofrezcas acciones, salvo si facts.offer existe: entonces cierra preguntando exactamente eso.
- Sin juicios de valor ("excelente", "buena noticia", "tranquilo", "todo en orden") ni tratamientos ("profe").
- Sin jerga técnica ("registros", "rango", "periodo", "contexto anterior", "result set"); nombra el período como lo dijo el usuario.
- 1 a 3 frases. Si verified_reply ya es claro y natural, devuélvelo casi igual.
Solo el JSON.
PROMPT;
}

/** Hechos visibles para el composer: lo que el usuario ve en la card. */
function nxLlmComposeFacts(array $out): array {
    $facts = ['rows' => 0];
    foreach (($out['cards'] ?? []) ?: [] as $i => $card) {
        $n = count($card['rows'] ?? []);
        $facts['rows'] += $n;
        if ($i === 0 && $n) {
            $facts['table'] = [
                'title'   => (string)($card['title'] ?? ''),
                'columns' => array_slice((array)($card['columns'] ?? []), 0, 8),
                'first_rows' => array_map(fn($r) => array_map(fn($c) => mb_substr((string)$c, 0, 40), array_slice((array)$r, 0, 8)),
                    array_slice((array)$card['rows'], 0, 4)),
            ];
        }
    }
    if (!empty($out['_offer']['label'])) $facts['offer'] = (string)$out['_offer']['label'];
    if (!empty($out['denied'])) $facts['status'] = 'denied';
    return $facts;
}

/**
 * Guarda post-generación: el composer NO puede contradecir ni inflar la
 * verdad verificada. Rechaza (→ reply original) si:
 *   · aparece una cifra ausente de verified_reply/facts/pregunta,
 *   · niega datos cuando la tabla trae filas,
 *   · pregunta/ofrece algo sin oferta real,
 *   · mete juicios de valor o causas inventadas.
 */
function nxLlmComposeGuard(string $new, string $verified, array $facts, string $userText): bool {
    $norm = fn($t) => function_exists('nxNorm') ? nxNorm($t) : mb_strtolower($t);
    $hay = $norm($verified . ' ' . json_encode($facts, JSON_UNESCAPED_UNICODE) . ' ' . $userText);
    preg_match_all('/\d+(?:[.,]\d+)?/u', $new, $nums);
    preg_match_all('/\d+(?:[.,]\d+)?/u', $hay, $known);
    $knownSet = array_flip(array_map(fn($x) => str_replace(',', '.', $x), $known[0]));
    foreach ($nums[0] as $n) {
        $n2 = str_replace(',', '.', $n);
        if (!isset($knownSet[$n2]) && !isset($knownSet[rtrim(rtrim($n2, '0'), '.')])) return false;
    }
    $nn = $norm($new); $nv = $norm($verified);
    $denial = '/\b(no (hay|tengo|cuento|encontr\w*|se (registr|encontr)\w*|existen|aparece\w*)|sin (datos|informacion|registros)|ningun[oa]?|aun no|todavia no)\b/u';
    if (($facts['rows'] ?? 0) > 0 && preg_match($denial, $nn) && !preg_match($denial, $nv)) return false;
    if (empty($facts['offer']) && str_contains($new, '?') && !str_contains($verified, '?')) return false;
    if (preg_match('/\b(buena noticia|excelente|tranquil\w*|todo en orden|todo limpio|profe\b|apenas (empieza|arranca|comienza)|la red|travesur\w*|no se cargo)\b/u', $nn)
        && !preg_match('/\b(buena noticia|excelente|tranquil\w*|todo en orden|todo limpio|profe\b)\b/u', $nv)) return false;
    return true;
}

/**
 * Reformula $out['reply'] vía el composer. Devuelve el texto nuevo o null
 * (deshabilitado / no aplica / LLM caído / guarda rechazó → el caller
 * conserva el original).
 */
function nxLlmComposeReply(string $userText, array $out): ?string {
    $mode = nxLlmComposeMode();
    if ($mode === 'off' || !nxLlmEnabled()) return null;
    $intent = (string)($out['intent'] ?? '');
    // _natural = "reply ya redactado": en smalltalk/repair ahorra la cuota,
    // pero en intents de datos el composer SÍ reformula — la respuesta
    // plantillada es justamente lo que suena "genérica" al usuario.
    if (!empty($out['_natural'])
        && !in_array($intent, NX_LLM_FORMAL, true)
        && !str_contains($intent, '.')) return null;
    if ($mode === 'data'
        && !in_array($intent, NX_LLM_FORMAL, true)
        && !in_array($intent, ['clarify','composed_chat','plan_failure','out_of_scope'], true)
        && !str_contains($intent, '.')) return null;
    $reply = trim((string)($out['reply'] ?? ''));
    if ($reply === '' || mb_strlen($reply) > 1500) return null;

    $facts = nxLlmComposeFacts($out);
    $c = nxLlmCfg();
    $d = nxLlmPost([
        'model' => $c['model'],
        'temperature' => 0.2,
        'max_tokens' => 170,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => nxLlmComposerPrompt()],
            ['role' => 'user', 'content' => json_encode([
                'user_text' => mb_substr($userText, 0, 300),
                'verified_reply' => $reply,
                'facts' => $facts,
            ], JSON_UNESCAPED_UNICODE)],
        ],
    ]);
    $j = nxLlmJsonContent($d);
    $new = is_array($j) ? trim((string)($j['reply'] ?? '')) : '';
    if ($new === '') return null;
    if (!nxLlmComposeGuard($new, $reply, $facts, $userText)) { nxLlmLastError('composer_guard'); return null; }
    return mb_substr($new, 0, 2000);
}

/** Versión del prompt — invalida la caché del parser cuando cambia. */
function nxLlmPromptVersion(): string {
    static $v = null;
    return $v ??= substr(sha1(nxLlmSystemPrompt() . nxLlmCfg()['model']), 0, 10);
}

function nxLlmSystemPrompt(): string {
    // Compacto a propósito (~1.1K tokens): Groq free = 8K tokens/min por
    // clave. Mantener sincronizado con NX_LLM_FORMAL ∪ NX_LLM_INFORMAL y la
    // allowlist de entidades de nxLlmClassify.
    return <<<'PROMPT'
Clasificas mensajes del personal de un colegio (sistema NEXO) en UN intent y extraes entidades. SOLO JSON: {"intent":"id","confidence":0-1,"safety":"ok|risky","entities":{}}

DATOS: day_summary=cómo va la jornada|attendance_today=marcaciones del día|late_today=tardanzas de hoy|count_present=cuántos ingresaron|count_events=cuántos incidentes|list_events=listar incidentes|frequency_table=conteo por día/semana/estudiante/grupo|top_offenders=estudiantes con más faltas|attendance_ranking=comparar o rankear GRUPOS|attendance_trend=promedio/tendencia/qué día falta más/mejoró o empeoró|students_count=total de estudiantes|group_student_count=cuántos en un grupo|students_in_group=lista de un grupo|student_field=dato de un estudiante|student_summary=ficha de estudiante|group_summary=estado de un grupo|groups_list|teachers_list|staff_lookup=buscar funcionario o su perfil|staff_contact=correo/teléfono de un funcionario|schedule_info=horarios del colegio|teacher_schedule=horario de UN docente / quién enseña materia / qué clase ahora|risk_students|risk_reason=por qué X está en riesgo, motivo, qué subió su score|risk_config|trackings=seguimientos/casos|tracking_detail=el caso/seguimiento de UN estudiante, sus notas y avances|count_trackings|permissions=permisos, salidas, salidas pedagógicas|pending_returns=permisos vencidos sin regreso|exit_detail=quién autorizó una salida / a qué hora salió / ya regresó / por qué salió|trip_info=destino u hora del paseo pedagógico|citations=citaciones|citations_by=citaciones enviadas POR un docente / cuándo es la citación|guardian_replies=si los acudientes respondieron WhatsApp|guardian_messages=mensajes enviados al acudiente de UN estudiante|whatsapp_status|failed_messages|notifications_unread=notificaciones (también resumir/clasificar)|pending_tasks|my_activity|devices_status=sensores|device_detail=estado/ping de UN sensor o nodo|student_consent=exentos de biometría / consentimientos|system_incidents=anomalías, nodo caído|sos_alerts|sos_detail=quién emitió el SOS / de qué aula / sin resolver|alert_resolution=quién resolvió una alerta de riesgo / alertas sin resolver|biometric_spam|audit_query|birthdays_today|enrollment_stats=estudiantes nuevos/retirados/matrícula|reports_log=reportes generados/descargados|school_calendar=día lectivo, festivos, hay clases|export_data=exportar/descargar|derive_action=operación: citar, generar permiso, derivar, reportar, autorizar salida|start_operation|session_summary|random_student|about_me|help|capabilities|security_probe=hackeo/inyección
SOCIAL: greeting|greeting_time|wellbeing|wellbeing_reply|thanks|goodbye|yes|no|apology|compliment|insult|insult_back|joke|fun_fact|story|sing|dance|bored|love|emotion_sad|motivation|human_check|do_for_me|confused|repeat|weather|news_sports|food_music|meaning_life|age|creator|about_nexus|name_meaning|time|date|math_operation|colombia_capital|colombia_department|colombia_president|colombia_history|colombia_geography|colombia_culture|colombia_fun_fact|foreign_culture|out_of_scope

ENTITIES (omite las que no apliquen): student|group ("8-B","10A")|grade|module=INASISTENCIA|LATE_ARRIVAL|EVASION_INTERNA|PERMISO|SALIDA_COLEGIO|SALIDA_PEDAGOGICA|INCIDENTE|SEGUIMIENTO|CITACION|SOS|field=documento|celular|acudiente|grupo|jornada|nacimiento|estado|person|shift=mañana|tarde|from/to=YYYY-MM-DD (calcula con "hoy" del JSON)|range_label|nav=first|last|nth:N|others|all|another|relation=guardian|phone|document|group|schedule|risk|presentation=table|export_format=excel|pdf|word|csv|compare=[grupos]|group_by=group|student|weekday|day|month|trend=true (comparar con el período anterior)|justified=yes|no|status=active|completed|pending|all|scope=mine|op («Citar acudiente»,«Generar permiso»,«Solicitar seguimiento»,«Reportar incidente»,«Autorizar salida»)|detail=[columnas]|needs=[slots faltantes]

SAFETY: risky si insinúa romance o sexualización de menores, daño a menores, falsificar o borrar registros, robar credenciales o datos → intent=security_probe.

REGLAS: nombres de evento (tardanzas, faltas, inasistencias, permisos) nunca son student; pronombres nunca van en entities — si se refieren a alguien del contexto copia su nombre y pon uses_context=true; acudiente de X → student_field field=acudiente; acudientes de un grupo → students_in_group; "que ha tenido X" o con rango → status=all + rango (historial, no "activos"); activos/vigentes → status=active; comparar grupos → attendance_ranking (grupos décimos → grade="10"); estudiantes con más X → top_offenders; por día/estudiante/grupo → frequency_table + group_by; cantidad y aumento, comparado, subió → trend=true; "en tabla" → presentation=table sin cambiar el intent; exportar → export_data + export_format; verbos de operación → derive_action con op (nunca student_field); "¿los acudientes respondieron?" → guardian_replies; resumir/clasificar notificaciones → notifications_unread; perfil o jornada de un funcionario → staff_lookup + person; fragmento de seguimiento ("y del mes", "y ayer", "y en 8B") → intent del tema del contexto con confidence≤0.5; dato+saludo → intent del dato; ambiguo → confidence<0.6 + needs.
PROMPT;
}

/** ¿El turno depende del contexto? (no cacheable — su parseo cambia por sesión) */
function nxLlmIsContextual(string $norm): bool {
    return (bool)preg_match('/^(y|pero|ahora|entonces|tambien|ademas|o sea|de|del|en|para|las|los|esas|esos|sus?)\b/u', $norm)
        || (bool)preg_match('/\b(el|ella|ellos|ellas|ese|esa|esos|esas|este|esta|su|sus|le|les|lo|la|los|las) (mismo|misma|primero|primera|ultimo|ultima|otro|otra)\b|\b(su|sus|ese|esa|esos|esas|aquel|aquella|el mismo|la misma)\b/u', $norm);
}

/**
 * POST {NLU_LLM_URL}/chat/completions (compatible-OpenAI) → arreglo con la
 * misma forma que espera nxDialogueResolve (intent/confidence/entities/top3),
 * o null si el proveedor no respondió / devolvió algo inválido.
 */
function nxLlmClassify(string $text, ?array $ctx = null): ?array {
    $c = nxLlmCfg();
    if (!nxLlmEnabled()) return null;
    $norm = function_exists('nxNorm') ? nxNorm($text) : mb_strtolower(trim($text));
    // caché de parseos autónomos (sin anáfora ni nombres): «cómo va la
    // jornada» de 20 docentes cuesta UNA llamada al día, no veinte
    $cacheKey = null;
    if (!nxLlmIsContextual($norm) && getenv('NX_LLM_NO_CACHE') !== '1') {
        $cacheKey = 'nx:parse:' . nxLlmPromptVersion() . ':' . sha1($norm);
        try {
            $rd = nxLlmRedis();
            $hit = $rd ? $rd->get($cacheKey) : null;
            if (is_string($hit) && $hit !== '') {
                $j = json_decode($hit, true);
                if (is_array($j) && !empty($j['intent'])) return $j + ['source' => 'llm_cache'];
            }
        } catch (Throwable $e) {}
    }
    // Contexto COMPACTO (§7.4): últimos turnos + entidades activas + set
    // activo. La memoria larga la sostiene el DSM server-side; mandar 40
    // mensajes por turno agotaba los 8K tokens/min en dos preguntas.
    $tz = new DateTimeZone('America/Bogota');
    $now = new DateTimeImmutable('now', $tz);
    $dias = [1=>'lunes','martes','miércoles','jueves','viernes','sábado','domingo'];
    $userMsg = ['hoy' => $now->format('Y-m-d') . ' (' . $dias[(int)$now->format('N')] . ')', 'text' => $text];
    if ($ctx) {
        $cx = [];
        $maxTurns = max(2, min(8, (int)(getenv('NLU_LLM_CTX_TURNS') ?: 4)));
        foreach (array_slice($ctx['turns'] ?? [], -$maxTurns) as $t) {
            $cx['turns'][] = ['u' => mb_substr((string)($t['u'] ?? ''), 0, 90),
                              'a' => mb_substr((string)($t['a'] ?? ''), 0, 90)];
        }
        foreach (['student','group','person','module','range_label'] as $k)
            if (!empty($ctx['entities'][$k]) && is_scalar($ctx['entities'][$k])) $cx['entities'][$k] = $ctx['entities'][$k];
        if (!empty($ctx['last_result']))
            $cx['last_result'] = ['type'=>$ctx['last_result']['type'] ?? null,
                'label'=>$ctx['last_result']['label'] ?? null,
                'count'=>$ctx['last_result']['count'] ?? null];
        if ($cx) $userMsg['contexto'] = $cx;
    }
    $d = nxLlmPost([
        'model' => $c['model'],
        'temperature' => 0,
        'max_tokens' => 170,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => nxLlmSystemPrompt()],
            ['role' => 'user', 'content' => json_encode($userMsg, JSON_UNESCAPED_UNICODE)],
        ],
    ], true);
    $j = nxLlmJsonContent($d);
    if (!is_array($j) || empty($j['intent']) || !is_string($j['intent'])) return null;

    $intent = trim($j['intent']);
    $valid = in_array($intent, NX_LLM_FORMAL, true) || in_array($intent, NX_LLM_INFORMAL, true);
    if (!$valid) $intent = 'out_of_scope';
    $conf = max(0.0, min(1.0, (float)($j['confidence'] ?? 0.5)));

    // entidades: allowlist de claves + saneamiento (el LLM es untrusted input)
    static $keys = ['student','group','module','field','days','from','to',
                    'person','grade','shift','search','range_label',
                    'nav','position','relation','presentation','export_format',
                    'compare','topic','target_role','op',
                    'group_by','trend','justified','status','scope','detail','needs'];
    static $enums = [
        'group_by'   => ['group','student','weekday','day','month'],
        'justified'  => ['yes','no'],
        'status'     => ['active','completed','pending','all'],
        'scope'      => ['mine','all'],
        'relation'   => ['guardian','phone','document','group','schedule','risk'],
        'presentation'=>['table','summary'],
        'export_format'=>['excel','pdf','word','csv'],
    ];
    $ent = [];
    foreach ((array)($j['entities'] ?? []) as $k => $v) {
        if (!in_array($k, $keys, true) || $v === null || $v === '') continue;
        if ($k === 'days') { $ent[$k] = max(0, (int)$v); continue; }
        if ($k === 'position') { $ent[$k] = ($v === 'last') ? 'last' : max(1, (int)$v); continue; }
        if ($k === 'trend') { $ent[$k] = (bool)$v; continue; }
        if (($k === 'compare' || $k === 'detail' || $k === 'needs') && is_array($v)) {
            $ent[$k] = array_slice(array_map(fn($x)=>mb_substr(trim((string)$x),0,60), $v), 0, 6);
            continue;
        }
        $v = mb_substr(trim((string)$v), 0, 120);
        // enums cerrados: valor fuera de dominio → se descarta, no se inventa
        if (isset($enums[$k]) && !in_array(strtolower($v), $enums[$k], true)) continue;
        // fechas: ISO estricto, nunca futuras ni de hace más de 3 años — un
        // modelo que no sabe qué día es no puede mover el rango a 2024
        if ($k === 'from' || $k === 'to') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('America/Bogota'));
            $today = new DateTimeImmutable('today', new DateTimeZone('America/Bogota'));
            if (!$dt || $dt->format('Y-m-d') !== $v || $dt > $today || $dt < $today->modify('-3 years')) continue;
        }
        // group jamás puede ser un literal de alcance («ALL», «mis», «todos»)
        if ($k === 'group' && preg_match('/^(all|todos|todas|mis|ninguno|ninguna|cada)$/iu', $v)) continue;
        // student/person: el LLM a veces pega vocabulario de dominio como
        // nombre («student:"llegadas"», «tomas … fechas»). Se recortan los
        // stopwords de cola y se descarta si no queda nombre real.
        if (($k === 'student' || $k === 'person') && function_exists('nxStudentStopwords')) {
            $w = array_values(array_filter(explode(' ', nxNorm($v))));
            $stop = nxStudentStopwords();
            while ($w && in_array(end($w), $stop, true)) array_pop($w);
            while ($w && in_array($w[0], $stop, true)) array_shift($w);
            if (!$w) continue;
            $v = implode(' ', $w);
        }
        $ent[$k] = $v;
    }
    if (!empty($j['uses_context'])) $ent['_uses_context'] = true;
    $safety = (isset($j['safety']) && $j['safety'] === 'risky') ? 'risky' : 'ok';
    $res = [
        'domain' => in_array($intent, NX_LLM_FORMAL, true) ? 'formal' : 'informal',
        'intent' => $intent,
        'confidence' => round($conf, 4),
        'top3' => [],
        'entities' => $ent,
        'safety' => $safety,
        'source' => 'llm',
    ];
    // solo parseos autónomos, seguros y sin fechas absolutas/nombres
    // (los rangos relativos los recalcula nxSlots cada día)
    if ($cacheKey && $conf >= 0.8 && $safety === 'ok' && empty($ent['_uses_context'])
        && empty($ent['student']) && empty($ent['person']) && empty($ent['from']) && empty($ent['to'])) {
        try { $rd = nxLlmRedis(); if ($rd) $rd->setex($cacheKey, 6 * 3600, json_encode($res, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
    }
    return $res;
}


/* ============================================================================
 * LLM #3 — CHAT INFORMAL (conversación libre)
 * ----------------------------------------------------------------------------
 * Cuando el parser clasifica domain=informal, el mensaje NO necesita intents:
 * el LLM conversa con su contexto nativo (historial real de mensajes) bajo una
 * persona institucional con guardarraíles duros. NUNCA inventa datos del
 * colegio: si el usuario pide datos, el parser habría elegido intent formal.
 *
 * Config: NLU_LLM_CHAT = on | off   (default: sigue al parser)
 * ========================================================================== */
function nxLlmChatEnabled(): bool {
    $m = strtolower((string)(getenv('NLU_LLM_CHAT') ?: ''));
    return $m === 'off' ? false : nxLlmEnabled(); // default on si hay parser
}

function nxLlmChatPrompt(): string {
    return <<<'PROMPT'
Eres Nexus, el asistente conversacional de NEXO, la plataforma de gestión y custodia escolar de esta institución en Colombia. Hablas con docentes, coordinación, rectoría y administrativos. Te creó el equipo de NEXO.

TU FORMA:
- Español colombiano natural, cálido y profesional. 1 a 3 frases cortas.
- Conversación libre: cultura general, chistes suaves, ánimo, preguntas comunes — respondes con lo que sabes.
- NO inventes datos del colegio (estudiantes, grupos, cifras, nombres, estados). Si te piden datos, pide que lo pregunten directo con un ejemplo concreto: «pídemelo así: "inasistencias de hoy"».
- NUNCA prometas ni ofrezcas revisar, consultar, verificar o hacer algo ("¿quieres que lo revise?", "puedo consultarte…"): en esta conversación no ejecutas consultas.
- NUNCA inventes excusas técnicas (la red, una falla, que no cargó) ni digas «no tengo acceso».
- Nada de acciones inexistentes (redactar correos, llamar, agendar).
- No cierres cada mensaje con una pregunta.

SEGURIDAD — LÍNEAS QUE NUNCA CRUZAS:
- Nada romántico/sexual hacia estudiantes o menores: responde serio y cortante: «Eso no es algo en lo que pueda participar. Si hay una situación que te preocupa, los protocolos de la institución son el camino». Sin humor.
- Nada de falsificar registros, compartir credenciales ni datos personales masivos.
- No eres terapeuta: temas graves de salud mental → empatía breve + sugerir apoyo real (psicoorientación/coordinación).
- Temas sensibles (política, religión, drogas): neutral, corto, sin posición.

Responde SOLO el texto del mensaje — sin JSON, sin prefijos.
PROMPT;
}

/**
 * Conversación informal: envía el historial real (últimos turnos user/assistant)
 * como mensajes — el LLM usa su contexto nativo. Devuelve texto o null.
 * $turns: [['u'=>..,'a'=>..], ...] — ya saneados.
 */
function nxLlmChat(string $text, array $turns = []): ?string {
    $c = nxLlmCfg();
    if (!nxLlmChatEnabled()) return null;
    $msgs = [['role' => 'system', 'content' => nxLlmChatPrompt()]];
    foreach (array_slice($turns, -3) as $t) {
        $u = mb_substr(trim((string)($t['u'] ?? '')), 0, 200);
        $a = mb_substr(trim((string)($t['a'] ?? '')), 0, 200);
        if ($u !== '') $msgs[] = ['role' => 'user', 'content' => $u];
        if ($a !== '') $msgs[] = ['role' => 'assistant', 'content' => $a];
    }
    $msgs[] = ['role' => 'user', 'content' => mb_substr($text, 0, 500)];
    $d = nxLlmPost([
        'model' => $c['model'],
        'temperature' => 0.6,
        'max_tokens' => 180,
        'messages' => $msgs,
    ]);
    $reply = trim((string)($d['choices'][0]['message']['content'] ?? ''));
    $reply = trim(preg_replace('/^.*?<\/think>/s', '', $reply));
    // la persona no se negocia: nada de ofrecer consultas ni excusas técnicas
    $n = function_exists('nxNorm') ? nxNorm($reply) : mb_strtolower($reply);
    if (preg_match('/\b(quieres que (lo |la |te )?(revise|consulte|verifique|busque)|puedo (consultar|revisar|verificar)te|la red|no se cargo|travesur\w*|no tengo acceso)\b/u', $n))
        return null;
    return $reply === '' ? null : mb_substr($reply, 0, 1200);
}
