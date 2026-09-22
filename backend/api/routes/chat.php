<?php
/**
 * =============================================================================
 * routes/chat.php — Nexus Chat (intent-based NLU) · «Pregúntale a Nexus».
 * =============================================================================
 *
 * RESPONSABILIDAD
 * ---------------
 * POST /chat/message  {text}  → clasifica (NLU), valida rol, ejecuta handler
 *                               sobre datos reales, responde con texto +
 *                               cards + actions. Persiste historial.
 * GET  /chat/history          → últimos mensajes del usuario.
 * POST /chat/action           → chips de acción (navegación a Operaciones con
 *                               el formulario correcto — nunca ejecuta sin
 *                               confirmación del usuario en la UI destino).
 *
 * SEGURIDAD
 * ---------
 * - RBAC por intent (nxAllowed) antes del handler — negación con razón.
 * - Docentes/psy: scope a sus grupos (teacher_group_access) en todo query.
 * - Nada de SQL libre: handlers parametrizados sobre tablas existentes.
 * - Auditoría: cada mensaje → global_audit_logs (CHAT_QUERY, intent, entidades).
 * - Rate limit por usuario: 60 msg / 10 min (Redis).
 */

global $cleanPath, $conn, $input, $method;
require_once __DIR__ . '/_auth_middleware.php';
require_once __DIR__ . '/../lib/nexus_nlu.php';
require_once __DIR__ . '/../lib/nexus_semantic.php';
require_once __DIR__ . '/../lib/kb_colombia.php';
require_once __DIR__ . '/../lib/calculator.php';

/* ============================================================================
 * Helpers
 * ========================================================================== */

/** Scope del rol: docente/psicoorientador ven solo sus grupos. */
function chatScope(PDO $conn, array $u): array {
    $global = in_array($u['role'], ['RECTOR','COORDINATOR','SECRETARY'], true);
    if ($global) return ['sql' => '', 'params' => []];
    $uid = $conn->quote((string)$u['id']);
    return [
        'sql' => " AND s.student_id IN (
            SELECT sga.student_id FROM student_group_assignments sga
            JOIN teacher_group_access tga ON tga.group_id = sga.group_id
            WHERE tga.teacher_user_id = {$uid} AND sga.active = TRUE)",
        'params' => [],
    ];
}

/** Resuelve estudiante por nombre fuzzy dentro del scope del usuario. */
function chatResolveStudent(PDO $conn, array $u, ?string $name): ?array {
    if (!$name) return null;
    $scope = chatScope($conn, $u);
    $like = '%' . mb_strtolower($name) . '%';
    $stmt = $conn->prepare("
        SELECT s.student_id, s.first_name, s.last_name, s.document_number,
               s.birth_date, s.work_shift, s.grade_level,
               ag.group_name
        FROM students s
        LEFT JOIN student_group_assignments sga
               ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ?
          AND s.deleted_at IS NULL
          AND (translate(lower(s.first_name || ' ' || s.last_name),'áéíóú','aeiou') LIKE ?
            OR translate(lower(s.last_name || ' ' || s.first_name),'áéíóú','aeiou') LIKE ?
            OR s.document_number = ?)
          {$scope['sql']}
        ORDER BY s.last_name, s.first_name
        LIMIT 3
    ");
    $stmt->execute(array_merge([$u['school_id'], $like, $like, $name], $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: null; // null = no encontrado; >1 = ambiguo
}

/** Resuelve grupo por nombre (6A, 7-1, prescolar…) */
function chatResolveGroup(PDO $conn, array $u, ?string $g): ?array {
    if (!$g) return null;
    $g = strtoupper(trim($g));
    $stmt = $conn->prepare("
        SELECT group_id, group_name, grade_level FROM academic_groups
        WHERE school_id = ? AND (
            upper(group_name) = ? OR upper(replace(group_name,'-','')) = ? OR
            upper(grade_level || group_name) = ? OR upper(group_name) = 'GRADO ' || ?
        ) LIMIT 1
    ");
    $stmt->execute([$u['school_id'], $g, $g, $g, $g]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    // fallback LIKE
    $stmt = $conn->prepare("
        SELECT group_id, group_name, grade_level FROM academic_groups
        WHERE school_id = ? AND upper(group_name) LIKE ? LIMIT 1
    ");
    $stmt->execute([$u['school_id'], '%' . $g . '%']);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Políticas institucionales del chat (school_chat_policies).
 * Mapa intent→policy_key SOLO para roles acotados; los globales no se tocan.
 * Defaults = permitido (matriz base) — la escuela puede desactivar.
 */
const NX_CHAT_POLICY_MAP = [
    'risk_students'   => 'chat.teacher.risk_students',
    'student_field'   => 'chat.teacher.student_fields',
    'student_summary' => 'chat.teacher.student_fields',
    'day_summary'     => 'chat.teacher.aggregates',
    'attendance_today'=> 'chat.teacher.aggregates',
    'late_today'      => 'chat.teacher.aggregates',
    'group_summary'   => 'chat.teacher.aggregates',
    'students_count'  => 'chat.teacher.aggregates',
    'trackings'       => 'chat.teacher.aggregates',
    'citations'       => 'chat.teacher.aggregates',
    'derive_action'   => 'chat.teacher.derive_actions',
    'random_student'  => 'chat.teacher.student_fields',
    'staff_lookup'    => 'chat.teacher.aggregates',
    'start_operation' => 'chat.teacher.derive_actions',
    'count_present'   => 'chat.teacher.aggregates',
    'count_trackings' => 'chat.teacher.aggregates',
    'top_offenders'   => 'chat.teacher.aggregates',
    'pending_returns' => 'chat.teacher.aggregates',
    'group_student_count' => 'chat.teacher.aggregates',
    'session_summary' => 'chat.teacher.aggregates',
    'pending_tasks'   => 'chat.teacher.aggregates',
];

/** Política efectiva: tabla escuela → default TRUE. Cache por request. */
function chatPolicyEnabled(PDO $conn, string $schoolId, string $key): bool {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $conn->prepare("SELECT enabled FROM school_chat_policies WHERE school_id=? AND policy_key=?");
        $st->execute([$schoolId, $key]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v === false) ? true : (bool)$v;
    } catch (Throwable $e) {
        // tabla ausente en despliegues antiguos → política por defecto (TRUE)
        return $cache[$key] = true;
    }
}

/** Gate completo: matriz de rol + política institucional. */
function chatAllowed(PDO $conn, array $u, string $intent, string $role): bool {
    if (!nxAllowed($intent, $role)) return false;
    // políticas solo acotan roles no-globales
    if (in_array($role, ['TEACHER','COUNSELOR'], true)) {
        $key = NX_CHAT_POLICY_MAP[$intent] ?? null;
        if ($key && !chatPolicyEnabled($conn, $u['school_id'], $key)) return false;
    }
    // smalltalk puede desactivarse globalmente por la escuela
    if (!chatPolicyEnabled($conn, $u['school_id'], 'chat.smalltalk.enabled')) {
        $dataIntents = array_merge(array_keys(NX_CHAT_POLICY_MAP), ['count_events','list_events','permissions','notifications_unread','devices_status','audit_query','groups_list','teachers_list','schedule_info','export_data','about_me','time','date']);
        if (!in_array($intent, $dataIntents, true) && $intent !== 'out_of_scope') return false;
    }
    return true;
}

/** ¿Puede el rol ejecutar esta acción derivada? */
function chatCanAction(string $action, string $role): bool {
    return match ($action) {
        'Solicitar seguimiento' => in_array($role, ['RECTOR','COORDINATOR','TEACHER','COUNSELOR'], true),
        'Citar acudiente'       => in_array($role, ['RECTOR','COORDINATOR','TEACHER','COUNSELOR','SECRETARY'], true),
        'Generar permiso'       => in_array($role, ['TEACHER','COORDINATOR','RECTOR'], true),
        'Reportar incidente'    => in_array($role, ['TEACHER','COUNSELOR','RECTOR','COORDINATOR'], true),
        'Reportar daño'         => in_array($role, ['RECTOR','COORDINATOR','SECURITY','AUXILIARY'], true),
        'Mandar solicitud'      => in_array($role, ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR','SECURITY','AUXILIARY'], true),
        'Salida pedagógica'     => in_array($role, ['TEACHER','COORDINATOR','RECTOR'], true),
        'Cambio de horario'     => in_array($role, ['RECTOR','COORDINATOR','SECRETARY'], true),
        'Autorizar salida'      => in_array($role, ['RECTOR','COORDINATOR'], true),
        'Registro manual'       => in_array($role, ['TEACHER','COORDINATOR','SECRETARY','SECURITY','RECTOR'], true),
        'Fusionar bloque'       => $role === 'TEACHER',
        'Extender bloque'       => in_array($role, ['RECTOR','COORDINATOR'], true),
        'Situación Crítica'     => in_array($role, ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR','SECURITY','AUXILIARY'], true),
        default                 => false,
    };
}

/**
 * Palabra clave → título exacto del comando en Operation.jsx (?cmd= coincide con title).
 *
 * Reglas:
 *  - Se evalúan con límite de palabra (\b) — una subcadena jamás dispara una
 *    operación distinta (caso forense: «solicitud» contiene «cit»).
 *  - Las operaciones más específicas se evalúan antes que las genéricas:
 *    «solicitud» antes que «citar»; «autorizar … salida» antes que «salida».
 *  - Las formulaciones naturales admiten palabras intermedias:
 *    «autorizar una salida», «unir los bloques», «extender el bloque».
 */
function chatOperationCmd(string $q): string {
    return match(true) {
        // crítico primero — nada puede competir con una emergencia
        preg_match('/\b(sos|panico|emergencia|emergencias|situacion|situaciones|critica|critico)\b/u', $q) === 1 => 'Situación Crítica',
        // salidas de largo alcance antes que permisos/salidas simples
        preg_match('/\b(salidas? pedagogicas?|paseo|paseos|excursion|excursiones)\b/u', $q) === 1 => 'Salida pedagógica',
        // autorizar salida — admite artículos entre medio
        preg_match('/\bautoriz\w*\b[^.]*\bsalid\w*\b|\bsalida anticipada\b|\bse retira temprano\b|\bretiro anticipado\b/u', $q) === 1 => 'Autorizar salida',
        // solicitud/petición ANTES de citación — «solicitud» contiene «cit»
        preg_match('/\b(solicitud|solicitudes|solicitar|solicito|solicite|peticion|peticiones|tramite|tramites|requerimiento|requerimientos)\b/u', $q) === 1 => 'Mandar solicitud',
        // citación con boundary — formas reales incluido el imperativo «cita»
        preg_match('/\b(citar|cita|citalo|citala|cite|cito|citas|citamos|citemos|citacion|citaciones|convoque?|convocar|convoca|convoco|agenda(r|mos)? cita|llamar a citacion)\b/u', $q) === 1 => 'Citar acudiente',
        preg_match('/\b(permiso|permisos|salida de clase|salio al bano|salio del salon|permiso de salida)\b/u', $q) === 1 => 'Generar permiso',
        preg_match('/\b(dano|danos|danado|rompio|rompieron|roto|averiado|averia|destrozado|vandalismo)\b/u', $q) === 1 => 'Reportar daño',
        preg_match('/\b(cambio de horario|cambiar (la |el |mi )?hor(a|ario)|horario|jornada|reprogramar|reagendar)\b/u', $q) === 1 => 'Cambio de horario',
        preg_match('/\b(fusionar|unir|juntar|combinar)\w*\s+\w*\s*bloques?\b|\bfusionar bloque\b|\bunir bloques\b/u', $q) === 1 => 'Fusionar bloque',
        preg_match('/\b(extender|alargar|prolongar)\w*\s+\w*\s*bloques?\b|\bextender bloque\b|\balargar bloque\b/u', $q) === 1 => 'Extender bloque',
        preg_match('/\b(registro manual|marcar entrada|marca manual|sin huella|registrar llegada)\b/u', $q) === 1 => 'Registro manual',
        preg_match('/\b(incidente|incidentes|report(ar|e|o|amos)|pelea|peleas|problema|problemas|rina|agresion|agresiones|conflicto)\b/u', $q) === 1 => 'Reportar incidente',
        default => 'Solicitar seguimiento',
    };
}

/** Etiquetas legibles por comando (para el chip y la respuesta). */
function chatOperationLabel(string $cmd): string { return $cmd; }

/** Chips de acción → navegación a /operacion con comando precargado. */
function chatActionChip(string $cmd, string $label, ?array $student = null): array {
    $q = '/operacion?cmd=' . urlencode($cmd);
    if ($student) $q .= '&student=' . urlencode($student['student_id']);
    return ['kind' => 'nav', 'label' => $label, 'to' => $q];
}

/** Chips derivados cuando un resultado cruza umbral. */
function chatDerivedActions(array $u, ?array $student, string $reason): array {
    $a = [];
    if ($student) {
        if (chatCanAction('Solicitar seguimiento', $u['role'])) $a[] = chatActionChip('Solicitar seguimiento', 'Derivar a seguimiento', $student);
        if (chatCanAction('Citar acudiente', $u['role']))       $a[] = chatActionChip('Citar acudiente', 'Citar acudiente', $student);
        if (chatCanAction('Reportar incidente', $u['role']))    $a[] = chatActionChip('Reportar incidente', 'Reportar incidente', $student);
    } else {
        if (chatCanAction('Solicitar seguimiento', $u['role'])) $a[] = chatActionChip('Solicitar seguimiento', 'Abrir seguimiento');
        if (chatCanAction('Citar acudiente', $u['role']))       $a[] = chatActionChip('Citar acudiente', 'Citar acudiente');
    }
    return $a;
}

/** Nombre legible de módulo (type_code → español) */
const NX_MODULE_LABEL = [
    'LATE_ARRIVAL'=>'llegadas tarde','INASISTENCIA'=>'inasistencias',
    'INASISTENCIA_JUSTIFICADA'=>'inasistencias justificadas',
    'INASISTENCIA_NO_JUSTIFICADA'=>'inasistencias sin justificar',
    'EVASION_INTERNA'=>'evasiones internas','PERMISO'=>'permisos',
    'SALIDA_BAÑO'=>'salidas al baño','SALIDA_COLEGIO'=>'salidas del colegio',
    'SOS'=>'alertas SOS','CITACION'=>'citaciones','SEGUIMIENTO'=>'seguimientos',
    'INCIDENTE'=>'incidentes','DAÑO'=>'daños','INGRESO'=>'ingresos',
    'SALIDA_PEDAGOGICA'=>'salidas pedagógicas','SPAM_BIOMETRICO'=>'spam biométrico',
];

/* ============================================================================
 * POST /chat/message
 * ========================================================================== */
if ($cleanPath === '/chat/message' && $method === 'POST') {
    $authUser = requireAuth();
    $schoolId = $authUser['school_id'];
    $userId   = $authUser['id'];
    $role     = strtoupper($authUser['role'] ?? '');

    $text = trim((string)($input['text'] ?? ''));
    if ($text === '' || mb_strlen($text) > 500) {
        http_response_code(400);
        exit(json_encode(['status'=>'error','message'=>'Texto requerido (máx. 500 caracteres)']));
    }
    // sesión de conversación — el front manda la suya o se crea una nueva
    $sessionId = (string)($input['session_id'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $sessionId)) $sessionId = chatNewSessionId();

    // Rate limit: 60 msg / 10 min por usuario
    try {
        $redis = getRedisConnection();
        $rk = "chat_rl:{$userId}";
        $n = $redis->incr($rk);
        if ($n === 1) $redis->expire($rk, 600);
        if ($n > 60) {
            http_response_code(429);
            exit(json_encode(['status'=>'error','message'=>'Demasiados mensajes — espera un momento.']));
        }
    } catch (Throwable $e) { /* redis caído → no bloquea el chat */ }

    $firstName = explode(' ', trim((string)($authUser['nombre'] ?? $authUser['first_name'] ?? '')))[0] ?: '';
    $vars = ['_q' => nxNorm($text), 'name' => $firstName ? ', ' . $firstName : '', 'daypart' => (function(){ $h=(int)date('G'); return $h<12?'Buenos días':($h<18?'Buenas tardes':'Buenas noches'); })()];

    // ── Seguimiento contextual: «dame otro», «otra», «más», «siguiente» ──
    $q0 = nxNorm($text);
    $dsPre = chatLoadDs($conn, $userId, $sessionId);
    $hasNavableSet = !empty($dsPre['last_result']['items']);
    if (!$hasNavableSet
        && preg_match('/^(dame |dime )?(otro|otra|uno mas|una mas|mas|siguiente|otra vez|y otro|y otra|de nuevo|dame mas|dime mas|continua|sigue|y eso|y ese|y esa)[.! ]*$/u', $q0)) {
        $last = chatLastPayload($conn, $userId, $sessionId);
        if ($last && !empty($last['intent'])) {
            $vars['_last_reply'] = $last['reply'] ?? '';
            $slots = $last['entities'] ?? [];
            $slots['_repeat'] = true; // los handlers aleatorios eligen otro valor
            $out = chatDispatch($conn, $authUser, $last['intent'], $slots, $vars, $role);
            $out['confidence'] = 1.0;
            $out['session_id'] = $sessionId;
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // ── telemetría por capa (NLU → DSM → auth → dispatch) ─────────────
    $tNlu = microtime(true);
    $cls = nxClassify($text);
    $tNlu = microtime(true) - $tNlu;

    // ── Multi-intención: «hola quién eres y quién soy yo», «tardanzas y evasiones del 8A» ──
    if (!empty($cls['parts']) && count($cls['parts']) > 1) {
        $outs = [];
        foreach ($cls['parts'] as $p) {
            if (!chatAllowed($conn, $authUser, $p['intent'], $role)) {
                $outs[] = ['reply'=>nxSmalltalk('denied',$vars),'intent'=>$p['intent'],'denied'=>true];
                continue;
            }
            // slots por segmento: nxSlots completa module/field/dates que Python no extrae
            $pslots = array_merge(nxSlots(nxNorm($p['text'] ?? '')), $p['entities'] ?? []);
            $outs[] = chatDispatch($conn, $authUser, $p['intent'], $pslots, $vars, $role);
        }
        $out = [
            'reply' => implode("\n\n—\n\n", array_column($outs,'reply')),
            'cards' => array_merge(...array_map(fn($o)=>$o['cards']??[], $outs)) ?: null,
            'actions' => array_merge(...array_map(fn($o)=>$o['actions']??[], $outs)) ?: null,
            'intent' => implode('+', array_column($outs,'intent')),
            'confidence' => min(array_column($cls['parts'],'confidence')),
            'session_id' => $sessionId,
        ];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    $intent  = $cls['intent'];
    $slots   = $cls['entities'] ?? [];
    $conf    = $cls['confidence'] ?? 0;

    // ── Dialogue State Manager — el estado real lo guarda el SERVIDOR en
    // payload_json._ds (result_set, entidad activa, cursor). El ctx del front
    // es solo respaldo/compatibilidad, nunca la fuente de verdad.
    $ds = chatLoadDs($conn, $userId, $sessionId);
    $ctx = $ds ? ['entities' => $ds['entities'] ?? [], 'last_intent' => $ds['intent'] ?? null, '_ds' => $ds]
               : ($input['ctx'] ?? null);
    if (is_array($ctx) && !empty($ctx['last_reply'])) $vars['_last_reply'] = $ctx['last_reply'];
    $tDsm = microtime(true);
    $interp = nxDialogueResolve($cls, is_array($ctx) ? $ctx : null, $q0);
    $tDsm = microtime(true) - $tDsm;
    $intent = $interp['resolved']['intent'];
    $slots  = $interp['resolved']['slots'];
    if (!empty($interp['resolved']['inherited'])) $slots['_inherited'] = $interp['resolved']['inherited'];
    $out['_interpretation'] = [
        'turn_type' => $interp['turn_type'],
        'nlu_intent' => $cls['intent'],
        'inherited' => $interp['resolved']['inherited'],
        'timing_ms' => ['nlu' => round($tNlu * 1000, 2), 'dsm' => round($tDsm * 1000, 2)],
    ];
    // clarify provisional: si el texto YA contiene señales estructurales
    // suficientes para un plan (lista+grupo+presentación), no hay ambigüedad
    // real — el clarify de intent es un falso positivo (ej. «chicos del 7-B
    // por documento» → student_field pide persona pero es una lista)
    if ($interp['requires_clarification']) {
        $provisional = nxSemanticCompose($q0, $intent, (float)$conf, $slots, $interp, $ds);
        if (!$provisional) {
            $out = ['reply'=>$interp['clarify'],'intent'=>'clarify','confidence'=>$conf,
                    'session_id'=>$sessionId,'entities'=>$slots,
                    '_interpretation'=>$out['_interpretation']];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // ── Confirmación / cancelación de operación pendiente ────────────────
    // «confirmo la solicitud» confirma la pendiente; «cancela eso» la descarta.
    // El ctx guarda _op cuando se resolvió una operación el turno anterior.
    $pendingOp = is_array($ctx['entities'] ?? null)
        ? ($ctx['entities']['_op'] ?? ($slots['_op'] ?? null))
        : ($slots['_op'] ?? null);
    if ($interp['turn_type'] === 'confirmation' && $pendingOp) {
        if (chatCanAction($pendingOp, $role)) {
            $out = ['reply'=>"Confirmado — te abro *{$pendingOp}* para terminarla ahí.",
                    'actions'=>[chatActionChip($pendingOp,'Continuar → '.$pendingOp,null)],
                    'intent'=>'confirm_op','confidence'=>$conf,'session_id'=>$sessionId,
                    'entities'=>['_op'=>$pendingOp],'_interpretation'=>$out['_interpretation']];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }
    if ($interp['turn_type'] === 'cancel') {
        $out = ['reply'=>'Cancelado — no quedó registrada ninguna operación.',
                'intent'=>'cancel','confidence'=>$conf,'session_id'=>$sessionId,
                'entities'=>[], '_interpretation'=>$out['_interpretation']];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }
    // repetición de la operación pendiente con parámetros nuevos
    // («otro para camila», «uno mas para pedro», «genera uno nuevo»)
    if ($interp['turn_type'] === 'op_repeat' && $pendingOp) {
        if (chatCanAction($pendingOp, $role)) {
            $student = null;
            if (!empty($slots['student'])) {
                $found = chatResolveStudent($conn, $authUser, $slots['student']);
                if ($found && count($found) === 1) $student = $found[0];
                elseif ($found) { $out = chatAmbiguous($found); $out['session_id']=$sessionId;
                    chatLog($conn,$schoolId,$userId,$text,$out,$sessionId);
                    exit(json_encode(['status'=>'ok','data'=>$out])); }
            }
            $nm = $student ? " para {$student['first_name']} {$student['last_name']}" : '';
            $out = ['reply'=>"Otra «{$pendingOp}»{$nm} — te abro el formulario.",
                    'actions'=>[chatActionChip($pendingOp,'Continuar → '.$pendingOp,$student)],
                    'intent'=>'repeat_op','confidence'=>$conf,'session_id'=>$sessionId,
                    'entities'=>['_op'=>$pendingOp],'_interpretation'=>$out['_interpretation']];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }
    // operación resuelta → persistir pending_op en el ctx para el próximo turno
    if (in_array($intent, ['start_operation','derive_action'], true)) {
        $slots['_op'] = $slots['_op'] ?? chatOperationCmd($q0);
    }

    // ── navegación sobre el result-set guardado («dame otro», «el primero»,
    // «los demás», «su nombre») — consulta informativa sobre contexto,
    // no requiere nuevo intent ni clasificador.
    // Salvo: si el turno nombra un GRUPO distinto al activo, la posición se
    // resuelve contra ese grupo (consulta nueva), no contra el set viejo.
    $rsGroup = $ds['last_result']['_filters']['group'] ?? ($ds['entities']['group'] ?? null);
    $navGroupClash = !empty($slots['group']) && $rsGroup
        && strtoupper((string)$slots['group']) !== strtoupper((string)$rsGroup);
    // verbo de evento / sustantivo de serie / slice-N en el enunciado →
    // consulta NUEVA, no navegación del set: «quién llegó primero»,
    // «el primer incidente», «los cinco primeros de 6-A» ≠ «el primero»
    $navEventVerb = (bool)preg_match('/\b(llego|llegaron|entro|entraron|falto|faltaron|marco|marcaron|salio|salieron|registro|registraron|asistio|asistieron|vino|vinieron)\b/u', $q0)
        || preg_match('/\b(incidente|incidentes|tardanza|tardanzas|inasistencia|inasistencias|evasion|evasiones|evento|eventos|registro|registros|novedad|novedades)\b/u', $q0)
        || preg_match('/\b(?:los|las)\s+(\d+|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(primer[oa]?s?|ultim[oa]s?)\b/u', $q0);
    if (!empty($slots['_nav']) && $ds && !empty($ds['last_result']['items']) && !$navGroupClash && !$navEventVerb) {
        $out = chatResultNav($ds, $slots['_nav'], $vars);
        $out['session_id'] = $sessionId;
        $out['_ds'] = chatBuildDs($interp, $out, $ds);
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    // ── referencia «su grupo» → resolución determinista estudiante→grupo ──
    // La BD conoce la relación (student_group_assignments + scope del rol);
    // el NLU solo marcó la referencia (_ref). Casos:
    //   encontrado      → group = grupo real del estudiante
    //   ambiguo         → aclarar con nombres, nunca adivinar
    //   sin grupo       → fallo explícito (dato real, no silencio)
    //   no encontrado   → fallo explícito
    if (($slots['_ref'] ?? null) === 'group_of_student' && !empty($slots['student'])) {
        $found = chatResolveStudent($conn, $authUser, $slots['student']);
        if ($found === null) {
            $out = ['reply'=>"No encontré a «{$slots['student']}» entre tus estudiantes. "
                    . "¿Puedes darme el nombre completo o el documento?",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        if (count($found) > 1) {
            $opts = implode(', ', array_map(
                fn($r) => trim($r['first_name'] . ' ' . $r['last_name']) . ' (' . ($r['group_name'] ?? 'sin grupo') . ')',
                array_slice($found, 0, 3)));
            $out = ['reply'=>"Hay varios estudiantes llamados {$slots['student']}: $opts. ¿A cuál te refieres?",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        $grp = $found[0]['group_name'] ?? null;
        if (!$grp) {
            $out = ['reply'=>"{$found[0]['first_name']} {$found[0]['last_name']} no tiene grupo asignado actualmente.",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        $slots['group'] = $grp;
        $slots['student'] = trim($found[0]['first_name'] . ' ' . $found[0]['last_name']);
        $slots['_ref_resolved'] = 'student→group: ' . $grp;
    }

    // ── Capa semántica — composición estructural sobre el registro de
    // capacidades (posición/cardinalidad/presentación/relación/filtro
    // compuesto). Devuelve null → el pipeline de intents decide. §50: el
    // plan es una estructura verificable antes de ejecutar.
    $plan = nxSemanticCompose($q0, $intent, (float)$conf, $slots, $interp, $ds);
    if ($plan) {
        $plan['_ctx_person'] = $ds['person'] ?? null;
        if (!nxPlanAllowed($conn, $authUser, $plan, $role)) {
            $out = ['reply'=>nxSmalltalk('denied',$vars),'intent'=>$plan['capability'],
                    'confidence'=>$conf,'denied'=>true,'session_id'=>$sessionId,
                    '_plan'=>$plan,'_interpretation'=>$out['_interpretation']];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        $tDisp = microtime(true);
        $out = nxPlanExecute($conn, $authUser, $plan, $vars);
        $tDisp = microtime(true) - $tDisp;
        $out = nxPlanResponse($out, $plan['capability'], 'plan:' . $plan['capability']);
        $out['intent'] = $plan['capability'];
        $out['confidence'] = $conf;
        $out['session_id'] = $sessionId;
        $out['entities'] = array_merge($slots, $out['entities'] ?? []);
        $out['_ds'] = chatBuildDs($interp, $out, $ds);
        if (isset($out['_interpretation']['timing_ms']))
            $out['_interpretation']['timing_ms']['dispatch'] = round($tDisp * 1000, 2);
        $out['_interpretation']['plan'] = $plan;
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        echo json_encode(['status'=>'ok','data'=>$out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── RBAC + políticas institucionales ──
    if (!chatAllowed($conn, $authUser, $intent, $role)) {
        $reply = nxSmalltalk('denied', $vars);
        $out = ['reply'=>$reply,'intent'=>$intent,'confidence'=>$conf,'denied'=>true,'session_id'=>$sessionId];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    $tDisp = microtime(true);
    $out = chatDispatch($conn, $authUser, $intent, $slots, $vars, $role);
    $tDisp = microtime(true) - $tDisp;
    // response planner: el handler es la fuente de verdad — reply vacío
    // → fallo explícito, nunca datos inventados; sello de procedencia.
    $out = nxPlanResponse($out, $intent, 'chat_' . $intent);
    $out['intent'] = $intent;
    $out['confidence'] = $conf;
    $out['session_id'] = $sessionId;
    $out['entities'] = array_merge($slots, $out['entities'] ?? []); // el handler resuelve nombres reales
    $out['_ds'] = chatBuildDs($interp, $out, $ds);
    if (isset($out['_interpretation']['timing_ms']))
        $out['_interpretation']['timing_ms']['dispatch'] = round($tDisp * 1000, 2);

    chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
    echo json_encode(['status'=>'ok','data'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

/** §13 variación lingüística controlada — los hechos nunca cambian,
 *  solo la forma. Determinista por (usuario, intent, slot-hash) para
 *  que repeticiones consecutivas no suenen idénticas. */
function nxVary(array $variants, string $seed): string {
    return $variants[crc32($seed) % count($variants)];
}
function nxVaryClean(string $what, string $where, string $seed): string {
    return nxVary([
        "No hay {$what} {$where} — todo limpio.",
        "Sin {$what} {$where} — todo en orden.",
        "No registra {$what} {$where} — tranquilo.",
        "Cero {$what} {$where} — buena noticia.",
    ], $seed);
}

/** Estado conversacional persistido — leído del último payload del asistente. */
function chatLoadDs(PDO $conn, string $userId, string $sessionId): ?array {
    static $ok = null;
    if ($ok === false) return null;
    try {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages
            WHERE user_id=? AND session_id=? AND role='assistant' AND jsonb_exists(payload_json, '_ds')
            ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId,$sessionId]);
        $r = $st->fetchColumn();
    } catch (Throwable $e) { $ok = false; return null; }
    $ok = true;
    if (!$r) return null;
    $p = json_decode($r, true);
    return $p['_ds'] ?? null;
}

/**
 * Construye el _ds para el próximo turno — §3 estado conversacional real.
 * Guarda entidades activas, campo pedido, result-set y cursor, pendientes.
 */
function chatBuildDs(array $interp, array $out, ?array $prev): array {
    $slots = $interp['resolved']['slots'] ?? [];
    $ent   = $out['entities'] ?? [];
    // turnos de navegación/deícticos traen slots casi vacíos — el tema
    // se hereda SOLO en continuaciones; un tema nuevo («háblame del
    // sistema») no arrastra entidades ajenas (§26)
    $merged = array_filter(array_merge($slots, $ent), fn($v) => $v !== null && $v !== []);
    $tt = $interp['turn_type'] ?? '';
    $isCont = $tt === 'context_modify' || !empty($slots['_nav']) || !empty($out['_result_nav'])
        || in_array($tt, ['op_repeat','correction','confirmation','deictic','followup'], true);
    if ($isCont) {
        foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
            if (empty($merged[$k]) && !empty($prev['entities'][$k])) $merged[$k] = $prev['entities'][$k];
        }
    }
    $ds = [
        // turnos de navegación no cambian el tema: el intent queda del
        // último query real — «la última» no convierte el tema en sos_alerts
        'intent'      => !empty($slots['_nav']) || !empty($out['_result_nav'])
            ? ($prev['intent'] ?? ($interp['resolved']['intent'] ?? null))
            : ($interp['resolved']['intent'] ?? ($out['intent'] ?? null)),
        'prev_intent' => $prev['intent'] ?? null,
        'entities'    => $merged,
        'goal'        => $slots['field'] ?? $slots['goal'] ?? ($prev['goal'] ?? null),
        'last_result' => $out['_result_set'] ?? ($prev['last_result'] ?? null),
        'cursor'      => $out['_result_set'] ? 0
                        : ($out['_result_cursor'] ?? ($prev['cursor'] ?? 0)),
        'pending_op'  => $slots['_op'] ?? ($prev['pending_op'] ?? null),
    ];
    // persona referenciada (acudiente/docente) — el handler la declara
    if (!empty($ent['_person'])) $ds['person'] = $ent['_person'];
    elseif (!empty($prev['person'])) $ds['person'] = $prev['person'];
    return $ds;
}

/**
 * Navegación informativa sobre el último result-set — §9/§10.
 * next|prev|first|nth|rest|all|count|name — siempre read-only.
 */
function chatResultNav(array $ds, string $nav, array $vars): array {
    $rs   = $ds['last_result'];
    $items = $rs['items'] ?? [];
    $n    = count($items);
    $cur  = (int)($ds['cursor'] ?? 0);
    $lbl  = $rs['label'] ?? 'resultados';
    $one  = fn($i) => $items[$i]['label'] . (!empty($items[$i]['sub']) ? ' — ' . $items[$i]['sub'] : '');

    if ($nav === 'count')
        return ['reply'=>"En esa consulta hay {$n} {$lbl}." . ($n === 1 ? " Es {$items[0]['label']}." : ''),
                'intent'=>'result_nav', '_result_nav'=>'count'];
    if ($nav === 'name') {
        if ($n === 1)
            return ['reply'=>"Se llama {$items[0]['label']}" . (!empty($items[0]['sub']) ? " ({$items[0]['sub']})" : '') . ".",
                    'intent'=>'result_nav', '_result_nav'=>'name'];
        $names = array_map(fn($it)=>$it['label'], $items);
        return ['reply'=>"Son " . count($names) . ": " . implode(', ', $names) . ".",
                'intent'=>'result_nav', '_result_nav'=>'name'];
    }
    if ($nav === 'first' || $nav === 'prev' && $cur === 0) {
        if ($nav === 'first' || $cur === 0)
            return ['reply'=>"El primero es {$one(0)}.", 'intent'=>'result_nav', '_result_nav'=>'first', '_result_cursor'=>0];
    }
    if ($nav === 'prev') { $nav = 'nth'; $idx = max(0, $cur - 1); }
    if (preg_match('/^nth:(\d+)$/', $nav, $m)) $idx = max(0, (int)$m[1] - 1);
    if ($nav === 'next') $idx = $cur + 1;
    if ($nav === 'table' || $nav === 'all') {
        // §10: la tabla completa es una capacidad — si el set trae filas
        // materializadas (columns/rows) se emiten TODAS en una tarjeta.
        if (!empty($rs['rows']) && !empty($rs['columns']))
            return ['reply'=>"Tabla completa: {$n} {$lbl}.",
                    'cards'=>[['title'=>ucfirst($lbl),'columns'=>$rs['columns'],'rows'=>$rs['rows']]],
                    'intent'=>'result_nav','_result_nav'=>'table'];
        $all = array_map(fn($it) => '• ' . $it['label'] . (!empty($it['sub']) ? ' — ' . $it['sub'] : ''), $items);
        return ['reply'=>"Todos los {$lbl} ({$n}):
" . implode("
", $all),
                'intent'=>'result_nav','_result_nav'=>'table','_result_cursor'=>$n - 1];
    }
    if ($nav === 'rest') {
        if ($n <= $cur + 1)
            return ['reply'=>"Ya te mostré todos los {$lbl} — no quedan más.", 'intent'=>'result_nav','_result_nav'=>'rest'];
        $rest = array_slice($items, $cur + 1);
        $lines = array_map(fn($it) => '• ' . $it['label'] . (!empty($it['sub']) ? ' — ' . $it['sub'] : ''), $rest);
        return ['reply'=>"Los demás (" . count($rest) . "):
" . implode("
", $lines),
                'intent'=>'result_nav','_result_nav'=>'rest','_result_cursor'=>$n - 1];
    }
    // next / nth
    $idx = $idx ?? ($cur + 1);
    if ($idx >= $n)
        return ['reply'=>"No hay más {$lbl} — ya te mostré los {$n} que encontré.",
                'intent'=>'result_nav','_result_nav'=>'next','_result_cursor'=>$cur];
    return ['reply'=>$one($idx) . ($idx < $n - 1 ? ". ¿El siguiente?" : '. Era el último de la lista.'),
            'intent'=>'result_nav','_result_nav'=>'next','_result_cursor'=>$idx];
}

/** Último payload del asistente en esta sesión — seguimiento contextual («dame otro»). */
function chatLastPayload(PDO $conn, string $userId, ?string $sessionId = null): ?array {
    if ($sessionId) {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages WHERE user_id=? AND session_id=? AND role='assistant' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId,$sessionId]);
    } else {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages WHERE user_id=? AND role='assistant' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId]);
    }
    $r = $st->fetchColumn();
    return $r ? (json_decode($r, true) ?: null) : null;
}

/** Despacho único: smalltalk/meta o handler de datos. */
function chatDispatch(PDO $conn, array $authUser, string $intent, array $slots, array $vars, string $role): array {
    $smalltalkIntents = ['greeting','greeting_time','wellbeing','wellbeing_reply','joke',
        'fun_fact','about_nexus','name_meaning','creator','age','thanks','goodbye',
        'yes','no','apology','compliment','insult','bored','love','human_check',
        'do_for_me','emotion_sad','weather','news_sports','food_music',
        'meaning_life','confused','repeat','insult_back','sing','dance','story',
        'motivation','out_of_scope','security_probe','foreign_culture'];

    if (in_array($intent, ['help','capabilities'], true))
        return ['reply' => chatHelp($role), 'intent'=>$intent];
    if (in_array($intent, $smalltalkIntents, true)) {
        if ($intent === 'security_probe')
            securityLog('CHAT_SECURITY_PROBE', mb_substr($vars['_q'] ?? '',0,200) . ' | user ' . ($authUser['id'] ?? '?'));
        return ['reply' => nxSmalltalk($intent, $vars), 'intent'=>$intent];
    }
    // alias: el intent del corpus no siempre coincide 1:1 con el handler
    $handlerMap = ['notifications_unread'=>'chat_notifications','audit_query'=>'chat_audit'];
    $handler = $handlerMap[$intent] ?? ('chat_' . $intent);
    if (!function_exists($handler))
        return ['reply' => nxSmalltalk('out_of_scope', $vars), 'intent'=>$intent];
    try {
        return $handler($conn, $authUser, $slots, $vars);
    } catch (Throwable $e) {
        securityLog('CHAT_HANDLER_ERROR', $intent . ': ' . $e->getMessage());
        return ['reply'=>'No pude consultar eso ahora — intenta de nuevo en un momento.', 'intent'=>$intent];
    }
}

/* ============================================================================
 * GET /chat/history — últimos 30 mensajes del usuario
 * ========================================================================== */
/* ============================================================================
 * GET /chat/sessions — lista de conversaciones del usuario (barra lateral)
 * ========================================================================== */
if ($cleanPath === '/chat/sessions' && $method === 'GET') {
    $authUser = requireAuth();
    $st = $conn->prepare("
        SELECT session_id,
               MIN(created_at) AS started_at,
               MAX(created_at) AS last_at,
               COUNT(*) AS n,
               (SELECT content FROM chat_messages c2
                 WHERE c2.session_id = c.session_id AND c2.role='user'
                 ORDER BY c2.created_at LIMIT 1) AS first_msg
        FROM chat_messages c
        WHERE user_id = ? AND session_id IS NOT NULL
        GROUP BY session_id ORDER BY last_at DESC LIMIT 30
    ");
    $st->execute([$authUser['id']]);
    echo json_encode(['status'=>'ok','data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($cleanPath === '/chat/history' && $method === 'GET') {
    $authUser = requireAuth();
    $sessionId = (string)($_GET['session_id'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $sessionId)) {
        $stmt = $conn->prepare("
            SELECT role, content, payload_json, created_at
            FROM chat_messages WHERE user_id = ? AND session_id = ?
            ORDER BY created_at ASC LIMIT 200
        ");
        $stmt->execute([$authUser['id'], $sessionId]);
    } else {
        $stmt = $conn->prepare("
            SELECT role, content, payload_json, created_at
            FROM chat_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 60
        ");
        $stmt->execute([$authUser['id']]);
    }
    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['status'=>'ok','data'=>array_map(fn($r)=>[
        'from' => $r['role'] === 'user' ? 'user' : 'bot',
        'text' => $r['content'],
        'cards' => ($r['payload_json']['cards'] ?? null),
        'actions' => ($r['payload_json']['actions'] ?? null),
        'intent' => ($r['payload_json']['intent'] ?? null),
        'ts' => $r['created_at'],
    ], $rows)]);
    exit;
}

/* ============================================================================
 * POST /chat/action — chips ejecutan navegación; la escritura real ocurre en
 * /operations/execute con su propio RBAC + confirmación. Aquí solo se valida
 * que el rol pueda y se devuelve el destino.
 * ========================================================================== */
if ($cleanPath === '/chat/action' && $method === 'POST') {
    $authUser = requireAuth();
    $action = (string)($input['action'] ?? '');
    if (!chatCanAction($action, strtoupper($authUser['role'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['status'=>'error','message'=>'Tu rol no permite esa acción.']));
    }
    echo json_encode(['status'=>'ok','data'=>['to'=>'/operacion?cmd=' . urlencode($action)]]);
    exit;
}

/* ============================================================================
 * GET/POST /chat/policies — interruptores del asistente (rector + coordinador)
 * ========================================================================== */
if ($cleanPath === '/chat/policies' && $method === 'GET') {
    $authUser = requireAuth();
    if (!in_array(strtoupper($authUser['role']), ['RECTOR','COORDINATOR'], true)) {
        http_response_code(403);
        exit(json_encode(['status'=>'error','message'=>'Solo rectoría o coordinación']));
    }
    $keys = array_merge(array_values(NX_CHAT_POLICY_MAP), ['chat.smalltalk.enabled']);
    $keys = array_values(array_unique($keys));
    $st = $conn->prepare("SELECT policy_key, enabled FROM school_chat_policies WHERE school_id=?");
    $st->execute([$authUser['school_id']]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($keys as $k) $out[$k] = array_key_exists($k,$rows) ? (bool)$rows[$k] : true;
    exit(json_encode(['status'=>'ok','data'=>$out]));
}

if ($cleanPath === '/chat/policies' && $method === 'POST') {
    $authUser = requireAuth();
    if (!in_array(strtoupper($authUser['role']), ['RECTOR','COORDINATOR'], true)) {
        http_response_code(403);
        exit(json_encode(['status'=>'error','message'=>'Solo rectoría o coordinación']));
    }
    $allowed = array_values(array_unique(array_merge(array_values(NX_CHAT_POLICY_MAP), ['chat.smalltalk.enabled'])));
    $policies = $input['policies'] ?? [];
    if (!is_array($policies)) { http_response_code(400); exit(json_encode(['status'=>'error','message'=>'policies requerido'])); }
    $up = $conn->prepare("INSERT INTO school_chat_policies (school_id,policy_key,enabled,updated_by)
        VALUES (?,?,?,?)
        ON CONFLICT (school_id,policy_key) DO UPDATE SET enabled=EXCLUDED.enabled, updated_by=EXCLUDED.updated_by, updated_at=NOW()");
    foreach ($policies as $k=>$v) {
        if (!in_array($k,$allowed,true)) continue;
        $up->execute([$authUser['school_id'],$k,(bool)$v,$authUser['id']]);
    }
    $conn->prepare("INSERT INTO global_audit_logs (log_id,school_id,performed_by_user_id,action_type,action_details,created_at)
        VALUES (uuid_generate_v4(),?,?,'CHAT_POLICIES_UPDATED',?,NOW())")
        ->execute([$authUser['school_id'],$authUser['id'],json_encode($policies)]);
    exit(json_encode(['status'=>'ok','data'=>['saved'=>count($policies)]]));
}

/* ============================================================================
 * Persistencia + auditoría
 * ========================================================================== */
function chatNewSessionId(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff));
}

function chatLog(PDO $conn, string $schoolId, string $userId, string $text, array $out, ?string $sessionId = null): void {
    // session_id puede no existir aún en DBs sin el patch — degradar sin romper
    static $hasSession = null;
    if ($hasSession === null) {
        try {
            $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='chat_messages' AND column_name='session_id'");
            $st->execute();
            $hasSession = (bool)$st->fetchColumn();
        } catch (Throwable $e) { $hasSession = false; }
    }
    try {
        if ($hasSession) {
            $conn->prepare("INSERT INTO chat_messages (school_id,user_id,session_id,role,content,payload_json) VALUES (?,?,?,'user',?,?)")
                ->execute([$schoolId,$userId,$sessionId,$text, json_encode(['text'=>$text,'intent'=>$out['intent']??null], JSON_UNESCAPED_UNICODE)]);
            $conn->prepare("INSERT INTO chat_messages (school_id,user_id,session_id,role,content,payload_json) VALUES (?,?,?,'assistant',?,?)")
                ->execute([$schoolId,$userId,$sessionId,$out['reply'], json_encode($out, JSON_UNESCAPED_UNICODE)]);
        } else {
            $conn->prepare("INSERT INTO chat_messages (school_id,user_id,role,content,payload_json) VALUES (?,?,?,'user',?)")
                ->execute([$schoolId,$userId,$text, json_encode(['text'=>$text,'intent'=>$out['intent']??null], JSON_UNESCAPED_UNICODE)]);
            $conn->prepare("INSERT INTO chat_messages (school_id,user_id,role,content,payload_json) VALUES (?,?,?,'assistant',?,?)")
                ->execute([$schoolId,$userId,$out['reply'], json_encode($out, JSON_UNESCAPED_UNICODE)]);
        }
        $conn->prepare("INSERT INTO global_audit_logs (log_id,school_id,performed_by_user_id,action_type,action_details,created_at)
                        VALUES (uuid_generate_v4(),?,?,'CHAT_QUERY',?,NOW())")
            ->execute([$schoolId,$userId, json_encode([
                'text'=>mb_substr($text,0,200),'intent'=>$out['intent']??null,
                'confidence'=>$out['confidence']??null], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* logging no bloquea */ }
}

/* ============================================================================
 * Respuesta de ayuda — capacidades reales según rol
 * ========================================================================== */
function chatHelp(string $role): string {
    $data = in_array($role, ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR'], true);
    $rector = $role === 'RECTOR';
    $lines = ["Puedo hacer bastante — todo con datos reales del sistema:"];
    if ($data) {
        $lines[] = "• *Resúmenes*: «¿cómo va la jornada?», «¿quiénes llegaron tarde?», «¿quiénes faltaron hoy?»";
        $lines[] = "• *Estudiantes*: «datos de Pérez», «celular del acudiente de Camila», «¿cuántas evasiones tiene X del 7A en 15 días?»";
        $lines[] = "• *Grupos*: «¿cómo va el 9B?», «qué grupos hay»";
        $lines[] = "• *Avisos*: «notificaciones pendientes», «casos abiertos», «estudiantes en riesgo»";
        $lines[] = "• *Acciones*: «deriva a seguimiento a X», «cita al acudiente de X» — te llevo al formulario listo";
    }
    if ($rector) $lines[] = "• *Institución*: sensores, auditoría, exportaciones";
    $lines[] = "• *Y también charlamos*: chistes, datos curiosos, o simplemente cómo va tu día.";
    return implode("\n", $lines);
}

/* ============================================================================
 * HANDLERS — uno por intent data. Todos devuelven {reply, cards?, actions?}
 * ========================================================================== */

function chat_day_summary(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $today = gmdate('Y-m-d');
    $q = function(string $type, string $extra='') use ($conn,$u,$today,$scope) {
        $st = $conn->prepare("SELECT COUNT(*) FROM attendance_incidents ai
            JOIN students s ON s.student_id=ai.student_id
            WHERE ai.school_id=? AND ai.incident_type=? AND ai.detected_at::date=? {$extra} {$scope['sql']}");
        $st->execute([$u['school_id'],$type,$today]);
        return (int)$st->fetchColumn();
    };
    $safe = function(callable $f): int { try { return (int)$f(); } catch (Throwable $e) { return 0; } };
    $late = $safe(fn() => $q('LATE_ARRIVAL'));
    $abs  = $safe(fn() => $q('INASISTENCIA'));
    $eva  = $safe(fn() => $q('EVASION_INTERNA'));
    $present = $safe(function() use ($conn,$u,$today,$scope) {
        $st = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
            JOIN students s ON s.student_id=be.student_id
            WHERE be.school_id=? AND be.event_timestamp::date=? AND be.event_type LIKE 'INGRESO%' {$scope['sql']}");
        $st->execute([$u['school_id'],$today]); return (int)$st->fetchColumn();
    });
    $perm = $safe(function() use ($conn,$u,$scope) {
        $st = $conn->prepare("SELECT COUNT(*) FROM class_exit_authorizations c JOIN students s ON s.student_id=c.student_id WHERE c.school_id=? AND c.status='ACTIVE' {$scope['sql']}"); $st->execute([$u['school_id']]); return (int)$st->fetchColumn();
    });
    $notif = $safe(function() use ($conn,$u) {
        $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL"); $st->execute([$u['id']]); return (int)$st->fetchColumn();
    });

    $parts = ["{$present} presentes", "{$abs} inasistencias", "{$late} tardanzas", "{$eva} evasiones", "{$perm} permisos activos"];
    $reply = "La jornada de hoy: " . implode(' · ', $parts) . ".";
    if ($notif) $reply .= " Tienes {$notif} notificaciones sin leer.";
    $reply .= ($eva > 0 || $abs > 5) ? " Hay cosas que revisar — dime si quieres el detalle." : " Todo dentro de lo normal.";
    return ['reply'=>$reply, 'cards'=>[['title'=>'Hoy','columns'=>['Presentes','Inasistencias','Tardanzas','Evasiones','Permisos'],'rows'=>[[$present,$abs,$late,$eva,$perm]]]],
            'actions'=> $eva>0 ? chatDerivedActions($u, null, 'evasiones hoy') : []];
}

function chat_attendance_today(PDO $conn, array $u, array $s, array $v): array {
    return chatListIncidents($conn,$u,$s,'INASISTENCIA','inasistencias hoy');
}
function chat_late_today(PDO $conn, array $u, array $s, array $v): array {
    return chatListIncidents($conn,$u,$s,'LATE_ARRIVAL','llegadas tarde hoy');
}

function chatListIncidents(PDO $conn, array $u, array $s, string $type, string $label): array {
    $scope = chatScope($conn, $u);
    [$from,$to] = chatRange($s);
    $gf = ''; $params = [$u['school_id'],$type,$from,$to];
    if (!empty($s['group']) && ($g = chatResolveGroup($conn,$u,$s['group']))) { $gf = " AND ai.group_id = ?"; $params[] = $g['group_id']; }
    $st = $conn->prepare("
        SELECT s.first_name||' '||s.last_name AS name, ag.group_name, ai.detected_at::time(0) AS t
        FROM attendance_incidents ai
        JOIN students s ON s.student_id=ai.student_id
        LEFT JOIN academic_groups ag ON ag.group_id=ai.group_id
        WHERE ai.school_id=? AND ai.incident_type=? AND ai.detected_at::date BETWEEN ? AND ? {$gf} {$scope['sql']}
        ORDER BY ai.detected_at DESC LIMIT 25");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply'=>nxVaryClean($label, 'en el rango', ($v['_q']??'').$label)];
    $n = count($rows);
    return ['reply'=>"{$n} " . ($n===1?'registro':'registros') . " de {$label}:", 'cards'=>[['title'=>ucfirst($label),'columns'=>['Estudiante','Grupo','Hora'],'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',substr($r['t'],0,5)],$rows)]]];
}

function chatRange(array $s): array {
    return [$s['from'] ?? gmdate('Y-m-d'), $s['to'] ?? gmdate('Y-m-d')];
}

function chat_count_events(PDO $conn, array $u, array $s, array $v): array {
    $module = $s['module'] ?? null;
    if (!$module) return ['reply'=>'¿De qué quieres el conteo? Por ejemplo: «¿cuántas evasiones tuvo X este mes?», «¿cuántas tardanzas hubo hoy?»'];
    $scope = chatScope($conn, $u);
    [$from,$to] = chatRange($s);
    $params = [$u['school_id'],$module,$from,$to]; $extra='';
    $student = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn,$u,$s['student']);
        if (!$found) return ['reply'=>"No encuentro a «{$s['student']}» dentro de tu alcance — revisa el nombre o el grupo."];
        if (count($found)>1) return chatAmbiguous($found);
        $student=$found[0]; $extra .= " AND ai.student_id = ?"; $params[] = $student['student_id'];
    }
    if (!empty($s['group']) && ($g=chatResolveGroup($conn,$u,$s['group']))) { $extra .= " AND ai.group_id = ?"; $params[]=$g['group_id']; }
    $st = $conn->prepare("SELECT COUNT(*) FROM attendance_incidents ai JOIN students s ON s.student_id=ai.student_id
        WHERE ai.school_id=? AND ai.incident_type=? AND ai.detected_at::date BETWEEN ? AND ? {$extra} {$scope['sql']}");
    $st->execute($params); $n=(int)$st->fetchColumn();
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $rl = $s['range_label'] ?? 'hoy';
    $who = $student ? " para {$student['first_name']} {$student['last_name']}" : '';
    $grp = !empty($s['group']) ? " en {$s['group']}" : '';
    $inh = !empty($s['_inherited']) ? ' (siguiendo con el contexto anterior)' : '';
    if ($n === 0)
        return ['reply'=>($student
            ? "Profe, excelente noticia — {$student['first_name']} {$student['last_name']} no registra {$mlabel} en ese periodo{$inh}."
            : nxVary(["Excelente noticia — no hay {$mlabel} registradas{$grp} en ese periodo{$inh}.",
                      "No hay {$mlabel} registradas{$grp} en ese periodo{$inh} — buena noticia.",
                      "Sin {$mlabel}{$grp} en ese periodo{$inh} — tranquilo.",
                      "Cero {$mlabel}{$grp} en ese periodo{$inh} — todo limpio."], ($v['_q']??'').$mlabel))];
    $reply = "{$n} {$mlabel}{$who}{$grp} ({$rl}){$inh}.";
    $actions = [];
    $entOut = array_filter(['student'=>$student?mb_strtolower($student['first_name'].' '.$student['last_name']):null,'group'=>$s['group']??null]);
    $retEntities = $entOut ? ['entities'=>$entOut] : [];
    if ($n >= 2 && $student) { $reply .= " Eso ya cruza el umbral de atención — ¿quieres actuar?"; $actions = chatDerivedActions($u,$student,$mlabel); }
    elseif ($n >= 5) { $reply .= " Es bastante — puedo darte el detalle por estudiante."; }
    return ['reply'=>$reply,'actions'=>$actions] + $retEntities;
}

function chat_list_events(PDO $conn, array $u, array $s, array $v): array {
    $module = $s['module'] ?? null;
    if (!$module) return ['reply'=>'¿Qué quieres ver? Ejemplo: «muéstrame las evasiones de X» o «lista de permisos de hoy».'];
    $scope = chatScope($conn,$u); [$from,$to]=chatRange($s);
    $params=[$u['school_id'],$module,$from,$to]; $extra='';
    if (!empty($s['student'])) {
        $found=chatResolveStudent($conn,$u,$s['student']);
        if (!$found) return ['reply'=>"No encuentro a «{$s['student']}» dentro de tu alcance."];
        if (count($found)>1) return chatAmbiguous($found);
        $extra.=" AND ai.student_id=?"; $params[]=$found[0]['student_id'];
    }
    if (!empty($s['group']) && ($g=chatResolveGroup($conn,$u,$s['group']))) { $extra.=" AND ai.group_id=?"; $params[]=$g['group_id']; }
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name, ai.detected_at::date AS d, ai.detected_at::time(0) AS t
        FROM attendance_incidents ai JOIN students s ON s.student_id=ai.student_id
        LEFT JOIN academic_groups ag ON ag.group_id=ai.group_id
        WHERE ai.school_id=? AND ai.incident_type=? AND ai.detected_at::date BETWEEN ? AND ? {$extra} {$scope['sql']}
        ORDER BY ai.detected_at DESC LIMIT 30");
    $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $mlabel=NX_MODULE_LABEL[$module]??strtolower($module);
    if(!$rows) return ['reply'=>nxVaryClean($mlabel, 'en ese rango', ($v['_q']??'').$mlabel)];
    $items = array_map(fn($r)=>['id'=>null,'label'=>$r['name'],
        'sub'=>($r['group_name']??'—').' · '.$r['d'].' '.substr($r['t'],0,5)],$rows);
    return ['reply'=>count($rows)." ".(count($rows)===1?'registro':'registros')." de {$mlabel} (" . ($s['range_label']??'hoy') . "):",
        'cards'=>[['title'=>ucfirst($mlabel),'columns'=>['Estudiante','Grupo','Fecha','Hora'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['d'],substr($r['t'],0,5)],$rows)]],
        '_result_set'=>['type'=>'events','label'=>"registros de {$mlabel}",'items'=>$items,'count'=>count($items)]];
}

function chat_student_field(PDO $conn, array $u, array $s, array $v): array {
    $found = chatResolveStudent($conn,$u,$s['student'] ?? null);
    if (!$found) return ['reply'=>"¿De qué estudiante hablas? Dame nombre o apellido."];
    if (count($found)>1) return chatAmbiguous($found);
    $st = $found[0];
    $field = $s['field'] ?? 'datos';
    $name = "{$st['first_name']} {$st['last_name']}";

    // acudiente — también cuando el campo pide datos DEL acudiente
    $isGuardField = str_contains($field, 'acudiente') || $field === 'celular';
    if ($isGuardField) {
        $g = $conn->prepare("SELECT g.guardian_id AS gid, u.first_name||' '||u.last_name AS name, u.document_number AS doc, u.phone, g.whatsapp_phone
            FROM guardian_student_relationships r JOIN guardians g ON g.guardian_id=r.guardian_id
            JOIN users u ON u.user_id=g.user_id
            WHERE r.student_id=? ORDER BY r.primary_guardian DESC LIMIT 1");
        $g->execute([$st['student_id']]); $guard=$g->fetch(PDO::FETCH_ASSOC);
    }
    $ent = ['student'=>mb_strtolower($name),'group'=>$st['group_name']];
    // el acudiente queda como persona referenciada — «su número» siguiente
    // se refiere a él, no al estudiante; gid sostiene students.of_guardian
    if (!empty($guard['name'])) $ent['_person'] = ['type'=>'guardian','name'=>$guard['name'],'gid'=>$guard['gid'] ?? null,'student'=>$st['student_id']];
    return match($field) {
        'documento' => ['reply'=>"{$name}: documento *{$st['document_number']}* — grupo {$st['group_name']}, grado {$st['grade_level']}.",'entities'=>$ent],
        'acudiente' => ['reply'=>$guard ? "Acudiente de {$name}: *{$guard['name']}* — WhatsApp {$guard['whatsapp_phone']}" . ($guard['phone']&&$guard['phone']!==$guard['whatsapp_phone']?" · tel {$guard['phone']}":'') . "." : "{$name} no tiene acudiente registrado — te tocaría registrarlo primero.",'entities'=>$ent],
        'celular'   => ['reply'=>$guard ? "Contacto de {$name}: acudiente {$guard['name']}, WhatsApp *{$guard['whatsapp_phone']}*." : "{$name}: sin acudiente registrado — no tengo número de contacto.",'entities'=>$ent],
        // campos sobre el ACUDIENTE — la entidad objetivo cambió (§7)
        'documento_acudiente' => ['reply'=>$guard ? ($guard['doc'] ? "El documento del acudiente ({$guard['name']}) es *{$guard['doc']}*." : "El acudiente {$guard['name']} no tiene documento registrado.") : "{$name} no tiene acudiente registrado.",'entities'=>$ent],
        'celular_acudiente'   => ['reply'=>$guard ? "El número del acudiente {$guard['name']} es *{$guard['whatsapp_phone']}*" . ($guard['phone']&&$guard['phone']!==$guard['whatsapp_phone']?" · también {$guard['phone']}":'') . "." : "{$name} no tiene acudiente registrado — no tengo número.",'entities'=>$ent],
        'nombre_acudiente'    => ['reply'=>$guard ? "El acudiente de {$name} es {$guard['name']}." : "{$name} no tiene acudiente registrado.",'entities'=>$ent],
        'nombre'   => ['reply'=>"Se llama {$name}." . ($st['group_name'] ? " Va en {$st['group_name']}." : ''),'entities'=>$ent],
        'grupo'     => ['reply'=>"{$name} está en {$st['group_name']} (grado {$st['grade_level']}, jornada {$st['work_shift']}).",'entities'=>$ent],
        'jornada'   => ['reply'=>"{$name} va en jornada {$st['work_shift']} — grupo {$st['group_name']}.",'entities'=>$ent],
        'nacimiento'=> ['reply'=>$st['birth_date'] ? "{$name} nació el {$st['birth_date']}." : "{$name}: no tengo fecha de nacimiento registrada.",'entities'=>$ent],
        default     => chatStudentCard($st,$guard??null),
    };
}

function chatStudentCard(array $st, ?array $guard): array {
    return ['reply'=>"{$st['first_name']} {$st['last_name']} — {$st['group_name']} · grado {$st['grade_level']} · doc {$st['document_number']} · jornada {$st['work_shift']}" . ($guard ? " · acudiente {$guard['name']} ({$guard['whatsapp_phone']})" : '') . ". ¿Qué más quieres saber de esta ficha?"];
}

function chat_student_summary(PDO $conn, array $u, array $s, array $v): array {
    $found=chatResolveStudent($conn,$u,$s['student']??null);
    if(!$found) return ['reply'=>"¿De qué estudiante hablas? Dame nombre o apellido — y el grupo si hay homónimos."];
    if(count($found)>1) return chatAmbiguous($found);
    $st=$found[0];
    // conteos últimos 30 días
    $cnt=$conn->prepare("SELECT incident_type, COUNT(*) FROM attendance_incidents
        WHERE student_id=? AND detected_at::date >= ? GROUP BY incident_type");
    $cnt->execute([$st['student_id'], gmdate('Y-m-d', time()-30*86400)]);
    $counts=$cnt->fetchAll(PDO::FETCH_KEY_PAIR);
    // riesgo
    $risk=$conn->prepare("SELECT risk_level, risk_score FROM student_behavior_metrics WHERE student_id=? ORDER BY calculated_at DESC LIMIT 1");
    $risk->execute([$st['student_id']]); $rk=$risk->fetch(PDO::FETCH_ASSOC);
    // seguimiento activo
    $tr=$conn->prepare("SELECT tracking_id, status, dependency FROM student_tracking WHERE student_id=? AND status='en proceso' LIMIT 1");
    $tr->execute([$st['student_id']]); $track=$tr->fetch(PDO::FETCH_ASSOC);

    $name="{$st['first_name']} {$st['last_name']}";
    $bits=["{$name} — {$st['group_name']} (grado {$st['grade_level']}, {$st['work_shift']})"];
    $bits[]="Últimos 30 días: " . ($counts ? implode(' · ', array_map(fn($k,$c)=>"$c ".strtolower(NX_MODULE_LABEL[$k]??$k), array_keys($counts), $counts)) : "sin incidentes");
    if ($rk) $bits[]="Riesgo: *{$rk['risk_level']}* (score {$rk['risk_score']}).";
    if ($track) $bits[]="Tiene seguimiento activo ({$track['dependency']}).";
    $actions = chatDerivedActions($u,$st,'resumen');
    return ['reply'=>implode(' ',$bits),'actions'=>$actions,
            'entities'=>['student'=>mb_strtolower($name),'group'=>$st['group_name']]];
}

function chatAmbiguous(array $found): array {
    $opts=array_map(fn($r)=>"{$r['first_name']} {$r['last_name']} ({$r['group_name']})",$found);
    return ['reply'=>"Encontré varios: " . implode(' · ',$opts) . ". ¿Cuál? Dime el nombre completo o el grupo."];
}

function chat_group_summary(PDO $conn, array $u, array $s, array $v): array {
    $g=chatResolveGroup($conn,$u,$s['group']??'');
    if(!$g) return ['reply'=>"¿Qué grupo? Ejemplo: «¿cómo va el 7A?» o «estado del 9B»."];
    // docente scope check
    if (in_array($u['role'],['TEACHER','COUNSELOR'],true)) {
        $chk=$conn->prepare("SELECT 1 FROM teacher_group_access WHERE teacher_user_id=? AND group_id=? LIMIT 1");
        $chk->execute([$u['id'],$g['group_id']]);
        if (!$chk->fetchColumn()) return ['reply'=>"El grupo {$g['group_name']} no está en tu alcance — solo puedo mostrarte los tuyos."];
    }
    $today=gmdate('Y-m-d');
    $n=$conn->prepare("SELECT COUNT(*) FROM student_group_assignments WHERE group_id=? AND active=TRUE");
    $n->execute([$g['group_id']]); $total=(int)$n->fetchColumn();
    $st=$conn->prepare("SELECT incident_type,COUNT(*) FROM attendance_incidents WHERE group_id=? AND detected_at::date=? GROUP BY incident_type");
    $st->execute([$g['group_id'],$today]); $inc=$st->fetchAll(PDO::FETCH_KEY_PAIR);
    $det=$inc?implode(' · ',array_map(fn($k,$c)=>"$c ".strtolower(NX_MODULE_LABEL[$k]??$k),array_keys($inc),$inc)):'sin incidentes';
    return ['reply'=>"{$g['group_name']} ({$total} estudiantes): {$det} hoy."];
}

function chat_risk_students(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name, bm.risk_level, bm.risk_score
        FROM student_behavior_metrics bm JOIN students s ON s.student_id=bm.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE bm.school_id=? AND bm.risk_level IN ('HIGH','CRITICAL') {$scope['sql']}
        ORDER BY bm.risk_score DESC LIMIT 12");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'El motor de riesgo no tiene alertas activas — ningún patrón supera los umbrales configurados.'];
    return ['reply'=>count($rows)." estudiante(s) en riesgo alto o crítico:",
        'cards'=>[['title'=>'Riesgo activo','columns'=>['Estudiante','Grupo','Nivel','Score'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['risk_level'],$r['risk_score']],$rows)]],
        'actions'=>chatDerivedActions($u,null,'riesgo')];
}

function chat_trackings(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name, t.dependency, t.status, t.created_at::date AS d
        FROM student_tracking t JOIN students s ON s.student_id=t.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE t.school_id=? AND t.status='en proceso' {$scope['sql']} ORDER BY t.created_at DESC LIMIT 20");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay seguimientos abiertos — todo resuelto o descartado.'];
    return ['reply'=>count($rows)." seguimiento(s) en proceso:",
        'cards'=>[['title'=>'Seguimientos','columns'=>['Estudiante','Grupo','Origen','Desde'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['dependency']??'—',$r['d']],$rows)]]];
}

function chat_permissions(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name, c.exit_time::time(0) AS t, c.authorization_reason
        FROM class_exit_authorizations c JOIN students s ON s.student_id=c.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE c.school_id=? AND c.status='ACTIVE' {$scope['sql']} ORDER BY c.exit_time DESC LIMIT 20");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay permisos activos ahora — nadie está fuera con autorización.'];
    return ['reply'=>count($rows)." permiso(s) activos:",
        'cards'=>[['title'=>'Permisos','columns'=>['Estudiante','Grupo','Salió','Motivo'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',substr($r['t'],0,5),mb_strimwidth($r['authorization_reason']??'—',0,40,'…')],$rows)]]];
}

function chat_citations(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    // docente/psy: solo las citaciones que ellos enviaron (scope honesto)
    $own = in_array($u['role'],['TEACHER','COUNSELOR'],true) ? ' AND sender_user_id = ?' : '';
    $st=$conn->prepare("SELECT COUNT(*) FROM internal_messages WHERE school_id=? AND sent_at::date BETWEEN ? AND ? {$own}");
    $st->execute($own ? [$u['school_id'],$from,$to,$u['id']] : [$u['school_id'],$from,$to]); $n=(int)$st->fetchColumn();
    $rl=$s['range_label']??'hoy';
    return ['reply'=>$n ? "{$n} citación(es) enviadas ({$rl}). ¿Quieres el detalle?" : "No se enviaron citaciones ({$rl})."];
}

function chat_devices_status(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT device_name, location, status, last_ping FROM edge_devices WHERE school_id=? AND active=TRUE ORDER BY device_name");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay sensores registrados todavía — se registran desde «Sensores».'];
    $off=array_filter($rows,fn($r)=>!$r['last_ping']||strtotime($r['last_ping'])<time()-600);
    $reply=count($rows)." sensor(es) registrados — ".(count($off)?"⚠ ".count($off)." sin reportar en los últimos 10 min":'todos reportando').".";
    return ['reply'=>$reply,'cards'=>[['title'=>'Sensores','columns'=>['Nombre','Ubicación','Último ping'],
        'rows'=>array_map(fn($r)=>[$r['device_name'],$r['location']??'—',$r['last_ping']?date('H:i',strtotime($r['last_ping'])):'—'],$rows)]]];
}

function chat_notifications(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT title, type, created_at::date AS d FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT 10");
    $st->execute([$u['id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No tienes notificaciones pendientes — todo leído.'];
    return ['reply'=>count($rows)." notificación(es) sin leer:",
        'cards'=>[['title'=>'Pendientes','columns'=>['Aviso','Tipo','Fecha'],
        'rows'=>array_map(fn($r)=>[mb_strimwidth($r['title'],0,50,'…'),$r['type'],$r['d']],$rows)]],
        'actions'=>[['kind'=>'nav','label'=>'Ver todas','to'=>'/notificaciones']]];
}

function chat_audit(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT action_type AS event_type, COUNT(*) c FROM global_audit_logs WHERE school_id=? AND created_at::date BETWEEN ? AND ? GROUP BY action_type ORDER BY c DESC LIMIT 10");
    $st->execute([$u['school_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'Sin actividad auditada en ese rango.'];
    return ['reply'=>"Actividad auditada (" . ($s['range_label']??'hoy') . "):",
        'cards'=>[['title'=>'Auditoría','columns'=>['Evento','Veces'],'rows'=>array_map(fn($r)=>[$r['event_type'],$r['c']],$rows)]]];
}

function chat_students_count(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT COUNT(*) FROM students s WHERE s.school_id=? AND s.deleted_at IS NULL {$scope['sql']}");
    $st->execute([$u['school_id']]); $n=(int)$st->fetchColumn();
    return ['reply'=>"{$n} estudiantes " . ($scope['sql']?'en tus grupos':'registrados en la institución') . "."];
}

function chat_groups_list(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    if (in_array($u['role'],['TEACHER','COUNSELOR'],true)) {
        $st=$conn->prepare("SELECT ag.group_name, ag.grade_level, COUNT(sga.student_id) n FROM teacher_group_access tga
            JOIN academic_groups ag ON ag.group_id=tga.group_id
            LEFT JOIN student_group_assignments sga ON sga.group_id=ag.group_id AND sga.active=TRUE
            WHERE ag.school_id=? GROUP BY ag.group_id, ag.group_name, ag.grade_level ORDER BY ag.grade_level, ag.group_name");
        $st->execute([$u['school_id']]);
    } else {
        $st=$conn->prepare("SELECT ag.group_name, ag.grade_level, COUNT(sga.student_id) n FROM academic_groups ag
            LEFT JOIN student_group_assignments sga ON sga.group_id=ag.group_id AND sga.active=TRUE
            WHERE ag.school_id=? GROUP BY ag.group_id, ag.group_name, ag.grade_level ORDER BY ag.grade_level, ag.group_name");
        $st->execute([$u['school_id']]);
    }
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay grupos registrados — se crean en la configuración inicial.'];
    return ['reply'=>count($rows)." grupos:",'cards'=>[['title'=>'Grupos','columns'=>['Grupo','Grado','Estudiantes'],
        'rows'=>array_map(fn($r)=>[$r['group_name'],$r['grade_level'],$r['n']],$rows)]]];
}

function chat_teachers_list(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT u.first_name||' '||u.last_name AS name, string_agg(ag.group_name, ', ') groups
        FROM users u LEFT JOIN teacher_group_access tga ON tga.teacher_user_id=u.user_id
        LEFT JOIN academic_groups ag ON ag.group_id=tga.group_id
        JOIN roles r ON r.role_id=u.role_id WHERE u.school_id=? AND r.role_name='TEACHER' AND u.deleted_at IS NULL GROUP BY u.user_id, u.first_name, u.last_name ORDER BY u.last_name LIMIT 25");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay docentes registrados todavía.'];
    return ['reply'=>count($rows)." docente(s):",'cards'=>[['title'=>'Docentes','columns'=>['Nombre','Grupos'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['groups']??'—'],$rows)]]];
}

function chat_schedule_info(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT work_shift AS shift_name, entry_time, exit_time FROM school_schedule_config WHERE school_id=? ORDER BY work_shift LIMIT 6");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'Aún no hay jornadas configuradas — eso se define en la configuración inicial.'];
    return ['reply'=>"Jornadas configuradas:",'cards'=>[['title'=>'Horarios','columns'=>['Jornada','Entrada','Salida'],
        'rows'=>array_map(fn($r)=>[$r['shift_name'],substr($r['entry_time'],0,5),substr($r['exit_time'],0,5)],$rows)]]];
}

function chat_export_data(PDO $conn, array $u, array $s, array $v): array {
    return ['reply'=>'Para exportar te llevo a la sección con los filtros listos — ahí eliges formato (Excel, Word, PDF).',
        'actions'=>[['kind'=>'nav','label'=>'Abrir exportación','to'=>'/operacion']]];
}

function chat_derive_action(PDO $conn, array $u, array $s, array $v): array {
    $student=null;
    if(!empty($s['student'])){
        $found=chatResolveStudent($conn,$u,$s['student']);
        if(!$found) return ['reply'=>"No encuentro a «{$s['student']}» dentro de tu alcance — revisa el nombre."];
        if(count($found)>1) return chatAmbiguous($found);
        $student=$found[0];
    }
    // qué acción pidió → deep-link a la operación exacta
    $q=$v['_q']??'';
    $cmd = chatOperationCmd($q);
    if(!chatCanAction($cmd,$u['role'])) return ['reply'=>'Esa acción no está disponible para tu rol — la gestiona coordinación o rectoría.'];
    $name=$student?" para {$student['first_name']} {$student['last_name']}":'';
    return ['reply'=>"Te llevo a «{$cmd}»{$name} — confirmas ahí y queda registrado.",
        'actions'=>[chatActionChip($cmd,'Continuar → '.$cmd,$student)]];
}

function chat_about_me(PDO $conn, array $u, array $s, array $v): array {
    $name=trim(($u['first_name']??'').' '.($u['last_name']??''));
    $roleNames=['RECTOR'=>'Rector','COORDINATOR'=>'Coordinador','TEACHER'=>'Docente','SECRETARY'=>'Secretaría','COUNSELOR'=>'Psicoorientador','SECURITY'=>'Portero','AUXILIARY'=>'Auxiliar'];
    $rl=$roleNames[$u['role']??'']??$u['role'];
    $bits=["{$name} — {$rl} de esta institución."];
    if(in_array($u['role'],['TEACHER','COUNSELOR'],true)){
        $st=$conn->prepare("SELECT string_agg(ag.group_name,', ') FROM teacher_group_access tga JOIN academic_groups ag ON ag.group_id=tga.group_id WHERE tga.teacher_user_id=?");
        $st->execute([$u['id']]); $g=$st->fetchColumn();
        if($g) $bits[]="Tus grupos: {$g}.";
    }
    $bits[]="Todo lo que me pides sale de datos reales del sistema — nada inventado.";
    return ['reply'=>implode(' ',$bits)];
}

/* ══ Cultura general colombiana + matemáticas ══════════════════════════════ */

function nxTopic(array $s): ?string {
    return $s['topic'] ?? $s['student'] ?? null;
}

function chat_colombia_capital(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    if (str_contains($q,'colombia') || str_contains($q,'bogota') || str_contains($q,'del pais'))
        return ['reply'=>'*Bogotá* — capital de Colombia y del departamento de Cundinamarca, a 2.640 m de altura. ~8 millones de habitantes.'];
    $t = nxTopic($s);
    if ($t && isset(NX_KB_DEPTS[$t])) {
        [$cap,$reg] = NX_KB_DEPTS[$t];
        return ['reply'=>"La capital de " . ucfirst($t) . " es *{$cap}* — región {$reg} de Colombia."];
    }
    if ($t)
        return ['reply'=>"De «{$t}» no conservo la ficha completa en mi memoria patria — mi conocimiento cubre los 32 departamentos y sus capitales. ¿Quizá quisiste preguntar por otra región?"];
    return ['reply'=>'Colombia tiene 32 departamentos — dime de cuál quieres la capital: «capital de Antioquia», «capital del Chocó»…'];
}

function chat_colombia_department(PDO $conn, array $u, array $s, array $v): array {
    $t = nxTopic($s);
    if ($t && isset(NX_KB_DEPTS[$t])) {
        [$cap,$reg] = NX_KB_DEPTS[$t];
        return ['reply'=>"*" . ucfirst($t) . "* — región {$reg}, capital {$cap}."];
    }
    if ($t && isset(NX_KB_CITIES[$t]))
        return ['reply'=>NX_KB_CITIES[$t]];
    if ($t)
        return ['reply'=>"«{$t}» se me escapa del archivo — conozco departamentos, capitales y las ciudades principales. Prueba con «departamento de Cali» o «dónde queda Boyacá»."];
    return ['reply'=>'Colombia tiene *32 departamentos* en seis regiones: Caribe, Pacífico, Andina, Orinoquía, Amazonía e Insular. Pregúntame por cualquiera — «¿dónde queda el Cauca?», «departamento de Medellín».'];
}

function chat_colombia_president(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    if (str_contains($q,'actual') || str_contains($q,'en ejercicio') || str_contains($q,'hoy') || str_contains($q,'ahora') || str_contains($q,'ultimo presidente'))
        return ['reply'=>'No cargo información de figuras en ejercicio — mi memoria llega hasta los presidentes históricos. Pregúntame por Bolívar, Santander, Lleras Restrepo, Santos, Uribe…'];
    if (str_contains($q,'primer presidente'))
        return ['reply'=>'*Simón Bolívar* — primer presidente de la Gran Colombia (1819-1830), tras consolidar la independencia en Boyacá.'];
    $t = nxTopic($s);
    if (!$t) return ['reply'=>'Colombia ha tenido ~40 presidentes — mi memoria va de Bolívar (1819) a Duque (2022). No sigo figuras en ejercicio. Pregúntame por apellido: «quién fue Betancur», «Santos», «Lleras Restrepo»…'];
    // busca por nombre parcial
    $first = explode(' ', $t)[0] ?? '';
    foreach (NX_KB_PRESIDENTS as $name => $bio)
        if (str_contains($name, $t) || str_contains($t, $name)
            || (mb_strlen($first) > 3 && str_contains($name, $first)))
            return ['reply'=>$bio];
    return ['reply'=>"De «{$t}» no tengo ficha presidencial — mi memoria va de Bolívar a Duque. Pregúntame por apellido: «quién fue Betancur», «Santos», «Lleras Restrepo»…"];
}

function chat_colombia_history(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $t = nxTopic($s) ?? '';
    foreach (NX_KB_HISTORY as $k => $r)
        if (($t && str_contains($k, $t)) || str_contains($q, $k))
            return ['reply'=>$r];
    if ($t)
        return ['reply'=>"«{$t}» aún no está en mi archivo histórico — sé de la independencia, la Gran Colombia, el Bogotazo, la Constitución del 91 y el acuerdo de paz. ¿Alguno de esos?"];
    return ['reply'=>'Historia de Colombia en breve: independencia el *20 jul 1810* sellada en *Boyacá 1819*; la Gran Colombia se disolvió en 1831; Panamá se separó en 1903; la Constitución actual es de *1991*; el acuerdo de paz llegó en *2016*. ¿Quieres profundizar en algún evento?'];
}

function chat_colombia_geography(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    foreach (NX_KB_GEOGRAPHY as $k => $r)
        if (str_contains($q, $k)) return ['reply'=>$r];
    $t = nxTopic($s);
    if ($t) return ['reply'=>"De «{$t}» no tengo la ficha geográfica exacta — conozco ríos, cordilleras, regiones, páramos, desiertos e islas. ¿Cuál te cuento?"];
    return ['reply'=>'Geografía colombiana: *6 regiones* (Caribe, Pacífico, Andina, Orinoquía, Amazonía, Insular), los *Andes* en tres cordilleras, costas en *dos océanos*, el *Magdalena* como arteria y el pico más alto en la *Sierra Nevada* (5.775 m). ¿Cuál te cuento?'];
}

function chat_colombia_culture(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    foreach (NX_KB_CULTURE as $k => $r)
        if (str_contains($q, $k)) return ['reply'=>$r];
    $t = nxTopic($s);
    if ($t) return ['reply'=>"«{$t}» aún no está en mi repertorio cultural — sé de música, festivales, comida, literatura y deportistas. Pregúntame por vallenato, Gabo o la bandeja paisa."];
    return ['reply'=>'Cultura colombiana: vallenato, cumbia, Carnaval de Barranquilla, Feria de las Flores, García Márquez, Botero, bandeja paisa, ajiaco, el café del Eje, las esmeraldas de Boyacá… ¿de cuál te cuento más?'];
}

function chat_colombia_fun_fact(PDO $conn, array $u, array $s, array $v): array {
    return ['reply'=>nxPickNoRepeat(NX_KB_FACTS, $v['_last_reply'] ?? '')];
}

function chat_math_operation(PDO $conn, array $u, array $s, array $v): array {
    $math = $s['math'] ?? null;
    if (!$math) {
        return ['reply'=>'¿Qué operación? Puedo resolver aritmética, potencias, raíces, porcentajes, trigonometría, logaritmos, factoriales, mcm/mcd, regla de tres, áreas, Pitágoras y cuadráticas — o una expresión literal como `(3+5)*2`.'];
    }
    $r = nxCalc($math);
    if (!$r['ok']) return ['reply'=>$r['error']];
    return ['reply'=>$r['display'] . '. ¿Algo más?'];
}

function chat_time(PDO $conn, array $u, array $s, array $v): array {
    return ['reply'=>'Son las '.date('H:i').' — '.date('d/m/Y').'.'];
}
function chat_date(PDO $conn, array $u, array $s, array $v): array {
    $dias=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    return ['reply'=>'Hoy es '.$dias[date('w')].' '.date('d/m/Y').' — son las '.date('H:i').'.'];
}

/* ══ Nuevos intents: aleatorios, staff, operaciones, conteos ═══════════════ */

/** Estudiante aleatorio — acotado al scope del rol (docente solo sus grupos). */
function chat_random_student(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $params = [$u['school_id']]; $extra = '';
    if (!empty($s['group'])) {
        $g = chatResolveGroup($conn, $u, $s['group']);
        if (!$g) return ['reply'=>"No encuentro el grupo «{$s['group']}» — dime como «8A» u «octavo B»."];
        if (in_array($u['role'],['TEACHER','COUNSELOR'],true)) {
            $chk=$conn->prepare("SELECT 1 FROM teacher_group_access WHERE teacher_user_id=? AND group_id=? LIMIT 1");
            $chk->execute([$u['id'],$g['group_id']]);
            if (!$chk->fetchColumn()) return ['reply'=>"El grupo {$g['group_name']} no está en tu alcance — solo puedo darte estudiantes de tus grupos."];
        }
        $extra = " AND ag.group_id = ?"; $params[] = $g['group_id'];
        $where = "sga.active=TRUE AND ag.group_id=?"; $params = [$u['school_id'], $g['group_id']];
    }
    $st = $conn->prepare("
        SELECT s.first_name||' '||s.last_name AS name, ag.group_name
        FROM students s
        JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE s.school_id=? AND s.deleted_at IS NULL {$extra} {$scope['sql']}
        ORDER BY RANDOM() LIMIT 1");
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['reply'=>'No hay estudiantes en ese alcance — revisa el grupo o tu asignación.'];
    return ['reply'=>"Te doy a *{$r['name']}* del {$r['group_name']}. Si quieres otro, dime «dame otro».",
            'entities'=>['student'=>mb_strtolower($r['name']),'group'=>$r['group_name']]];
}

/** Quién es el rector/coordinador/psicoorientador… — datos reales de staff. */
function chat_staff_lookup(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $roleMap = ['rector'=>'RECTOR','director'=>'RECTOR','coordinador'=>'COORDINATOR','coordinadora'=>'COORDINATOR',
        'psicologo'=>'COUNSELOR','psicologa'=>'COUNSELOR','orientador'=>'COUNSELOR','psicoorientador'=>'COUNSELOR',
        'consejer'=>'COUNSELOR','secretari'=>'SECRETARY','portero'=>'SECURITY','auxiliar'=>'AUXILIARY','docente'=>'TEACHER'];
    $role = null;
    foreach ($roleMap as $k=>$r) if (str_contains($q,$k)) { $role=$r; break; }
    if ($role) {
        $st=$conn->prepare("SELECT u.first_name||' '||u.last_name AS name, r.role_name FROM users u
            JOIN roles r ON r.role_id=u.role_id
            WHERE u.school_id=? AND u.deleted_at IS NULL AND r.role_name=? ORDER BY u.last_name LIMIT 5");
        $st->execute([$u['school_id'],$role]);
    } else {
        $st=$conn->prepare("SELECT u.first_name||' '||u.last_name AS name, r.role_name FROM users u
            JOIN roles r ON r.role_id=u.role_id
            WHERE u.school_id=? AND u.deleted_at IS NULL AND r.role_name IN ('RECTOR','COORDINATOR','COUNSELOR') ORDER BY r.role_name LIMIT 10");
        $st->execute([$u['school_id']]);
    }
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No encuentro personal registrado con ese cargo en la institución.'];
    $labels=['RECTOR'=>'rectoría','COORDINATOR'=>'coordinación','COUNSELOR'=>'psicoorientación','SECRETARY'=>'secretaría','SECURITY'=>'portería','AUXILIARY'=>'auxiliar'];
    $bits=array_map(fn($r)=>"*{$r['name']}* ({$labels[$r['role_name']]})", $rows);
    return ['reply'=>(count($rows)===1?'Es ':'Están ').implode(' · ',$bits).'.'];
}

/** Iniciar operación — deep-link al formulario correcto dentro de /operacion. */
function chat_start_operation(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $cmd = chatOperationCmd($q);
    if (!chatCanAction($cmd,$u['role']))
        return ['reply'=>"Esa operación no está habilitada para tu rol — la gestiona coordinación o rectoría."];
    $student = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn,$u,$s['student']);
        if ($found && count($found)===1) $student=$found[0];
        elseif ($found) return chatAmbiguous($found);
    }
    return ['reply'=>"Vamos con *{$cmd}* — te abro el formulario listo para confirmar.",
        'actions'=>[chatActionChip($cmd,'Continuar → '.$cmd,$student)]];
}

/** Cuántos ingresaron (biometría INGRESO%) — no confundir con total matriculado. */
function chat_count_present(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u); [$from,$to]=chatRange($s);
    $params=[$u['school_id'],$from,$to]; $extra='';
    if (!empty($s['group']) && ($g=chatResolveGroup($conn,$u,$s['group']))) { $extra=" AND sga.group_id=?"; $params[]=$g['group_id']; }
    $st=$conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
        JOIN students s ON s.student_id=be.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        WHERE be.school_id=? AND be.event_timestamp::date BETWEEN ? AND ? AND be.event_type LIKE 'INGRESO%' {$extra} {$scope['sql']}");
    $st->execute($params); $n=(int)$st->fetchColumn();
    $rl=$s['range_label']??'hoy';
    return ['reply'=>"{$n} estudiante(s) ingresaron ({$rl})" . ($scope['sql']?' — en tus grupos':'') . "."];
}

/** Conteo de seguimientos — abiertos por defecto; «resueltos/cerrados» filtra. */
function chat_count_trackings(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u); $q=$v['_q']??'';
    $closed = preg_match('/resuelt|cerrad|terminad|solucionad/u',$q);
    $status = $closed ? "t.status <> 'en proceso'" : "t.status = 'en proceso'";
    $st=$conn->prepare("SELECT COUNT(*) FROM student_tracking t JOIN students s ON s.student_id=t.student_id
        WHERE t.school_id=? AND {$status} {$scope['sql']}");
    $st->execute([$u['school_id']]); $n=(int)$st->fetchColumn();
    $label = $closed ? 'resuelto(s)/cerrado(s)' : 'en seguimiento activo';
    return ['reply'=>"{$n} caso(s) {$label}" . ($scope['sql']?' en tus grupos':' en la institución') . "."];
}

/** Departamento o ciudad colombiana al azar. */
function chat_random_department(PDO $conn, array $u, array $s, array $v): array {
    $q=$v['_q']??'';
    if (str_contains($q,'ciudad') || str_contains($q,'municipio')) {
        $k=array_rand(NX_KB_CITIES);
        return ['reply'=>NX_KB_CITIES[$k]];
    }
    $k=array_rand(NX_KB_DEPTS);
    [$cap,$reg]=NX_KB_DEPTS[$k];
    return ['reply'=>"*".ucfirst($k)."* — capital {$cap}, región {$reg} de Colombia. ¿Quieres otro? Dime «otro»."];
}

/** Número aleatorio — con rango si lo pide («entre 1 y 100», «un dado»). */
function chat_random_number(PDO $conn, array $u, array $s, array $v): array {
    $q=$v['_q']??'';
    if (preg_match('/dado|moneda|cara|sello/u',$q)) {
        if (str_contains($q,'moneda')||str_contains($q,'cara')||str_contains($q,'sello'))
            return ['reply'=>random_int(0,1) ? 'Cara.' : 'Sello.'];
        return ['reply'=>'El dado cayó en *'.random_int(1,6).'*.'];
    }
    if (preg_match('/entre\s+(\d+)\s+y\s+(\d+)/u',$q,$m)) [$lo,$hi]=[(int)$m[1],(int)$m[2]];
    elseif (preg_match('/del\s+(\d+)\s+al\s+(\d+)/u',$q,$m)) [$lo,$hi]=[(int)$m[1],(int)$m[2]];
    else [$lo,$hi]=[1,100];
    if ($hi<$lo) [$lo,$hi]=[$hi,$lo];
    return ['reply'=>'Tu número: *'.random_int($lo,$hi)."* (entre {$lo} y {$hi}). ¿Otro?"];
}

/* ══ Segunda ola — intents de capacidad real del sistema ═══════════════════ */

/** Ranking de estudiantes por incidentes — top N real del rango/scope. */
function chat_top_offenders(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u); [$from,$to]=chatRange($s);
    $module = $s['module'] ?? null;
    $params=[$u['school_id'],$from,$to]; $extra='';
    if ($module) { $extra=" AND ai.incident_type=?"; $params[]=$module; }
    if (!empty($s['group']) && ($g=chatResolveGroup($conn,$u,$s['group']))) { $extra.=" AND ai.group_id=?"; $params[]=$g['group_id']; }
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name, COUNT(*) c
        FROM attendance_incidents ai JOIN students s ON s.student_id=ai.student_id
        LEFT JOIN academic_groups ag ON ag.group_id=ai.group_id
        WHERE ai.school_id=? AND ai.detected_at::date BETWEEN ? AND ? {$extra} {$scope['sql']}
        GROUP BY s.student_id, s.first_name, s.last_name, ag.group_name ORDER BY c DESC LIMIT 8");
    $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $mlabel = $module ? strtolower(NX_MODULE_LABEL[$module]??$module) : 'incidentes';
    if(!$rows) return ['reply'=>nxVaryClean($mlabel, 'en ese periodo', ($v['_q']??'').$mlabel)];
    return ['reply'=>"Top de {$mlabel} (" . ($s['range_label']??'hoy') . "):",
        'cards'=>[['title'=>'Ranking','columns'=>['Estudiante','Grupo','#'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['c']],$rows)]]];
}

/** Permisos de salida activos que ya pasaron su hora de retorno. */
function chat_pending_returns(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name,
        c.exit_time::time(0) AS t, c.expected_return_time::time(0) AS r
        FROM class_exit_authorizations c JOIN students s ON s.student_id=c.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE c.school_id=? AND c.status='ACTIVE' AND c.expected_return_time IS NOT NULL
          AND c.expected_return_time < NOW() {$scope['sql']} ORDER BY c.expected_return_time LIMIT 15");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'Todos los permisos están dentro de tiempo — nadie se ha pasado.'];
    return ['reply'=>count($rows)." permiso(s) vencidos sin retorno:",
        'cards'=>[['title'=>'Vencidos','columns'=>['Estudiante','Grupo','Salió','Debía volver'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',substr($r['t'],0,5),substr($r['r'],0,5)],$rows)]],
        'actions'=>chatDerivedActions($u,null,'permisos vencidos')];
}

/** Alertas SOS / pánico del periodo. */
function chat_sos_alerts(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT event_type, location, created_at::date AS d, created_at::time(0) AS t, status
        FROM school_panic_events WHERE school_id=? AND created_at::date BETWEEN ? AND ?
        ORDER BY created_at DESC LIMIT 15");
    $st->execute([$u['school_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rl=$s['range_label']??'hoy';
    if(!$rows) return ['reply'=>"Sin alertas SOS ni pánico en {$rl} — la institución tranquila."];
    return ['reply'=>count($rows)." alerta(s) SOS ({$rl}):",
        'cards'=>[['title'=>'SOS','columns'=>['Tipo','Ubicación','Fecha','Estado'],
        'rows'=>array_map(fn($r)=>[$r['event_type']??'SOS',$r['location']??'—',$r['d'],$r['status']??'—'],$rows)]]];
}

/** Intentos biométricos rechazados / spam del periodo. */
function chat_biometric_spam(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT device_id, COUNT(*) c, MAX(event_timestamp)::time(0) AS last
        FROM biometric_events WHERE school_id=? AND event_timestamp::date BETWEEN ? AND ?
          AND (event_type LIKE 'SPAM%' OR event_type LIKE '%RECHAZ%' OR event_type LIKE '%FAIL%' OR event_type LIKE '%DENIED%')
        GROUP BY device_id ORDER BY c DESC LIMIT 10");
    $st->execute([$u['school_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'Sin intentos fallidos ni spam biométrico en ese rango — sensores limpios.'];
    return ['reply'=>"Intentos rechazados/fallidos por sensor:",
        'cards'=>[['title'=>'Biometría rechazada','columns'=>['Sensor','Intentos','Último'],
        'rows'=>array_map(fn($r)=>[$r['device_id'],$r['c'],$r['last']],$rows)]]];
}

/** Cuántos estudiantes hay en un grupo puntual. */
/** «qué estudiantes hay en el 6-A» — lista real + result-set navegable. */
function chat_students_in_group(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $g = !empty($s['group']) ? chatResolveGroup($conn, $u, $s['group']) : null;
    if (!$g) return ['reply'=>"¿De qué grupo hablas? Por ejemplo: «estudiantes del 8A»."];
    $st = $conn->prepare("SELECT s.student_id, s.first_name, s.last_name, s.document_number
        FROM students s
        JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        WHERE s.school_id=? AND sga.group_id=? AND s.deleted_at IS NULL {$scope['sql']}
        ORDER BY s.last_name, s.first_name LIMIT 60");
    $st->execute([$u['school_id'],$g['group_id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply'=>"No encontré estudiantes en {$g['group_name']} dentro de tu alcance.",
                        'entities'=>['group'=>$g['group_name']]];
    $items = array_map(fn($r)=>['id'=>$r['student_id'],
        'label'=>trim($r['first_name'].' '.$r['last_name']),
        'sub'=>'doc '.$r['document_number']], $rows);
    $n = count($items);
    $show = array_slice($items, 0, 5);
    $reply = "{$g['group_name']} tiene {$n} estudiante" . ($n === 1 ? '' : 's') . ":";
    if ($n === 1) {
        $it = $items[0];
        $reply .= " {$it['label']} ({$it['sub']}).";
    } else {
        $reply .= "
" . implode("
", array_map(fn($i)=>'• '.$i['label'], $show))
                . ($n > 5 ? "
… y " . ($n - 5) . " más — dime «los demás» para verlos." : '');
    }
    return ['reply'=>$reply,
            'entities'=>['group'=>$g['group_name']],
            '_result_set'=>['type'=>'students','label'=>'estudiantes','items'=>$items,'count'=>$n]];
}

function chat_group_student_count(PDO $conn, array $u, array $s, array $v): array {
    $g=chatResolveGroup($conn,$u,$s['group']??'');
    if(!$g) return ['reply'=>'¿Qué grupo? Dime algo como «8A» u «octavo B».'];
    if (in_array($u['role'],['TEACHER','COUNSELOR'],true)) {
        $chk=$conn->prepare("SELECT 1 FROM teacher_group_access WHERE teacher_user_id=? AND group_id=? LIMIT 1");
        $chk->execute([$u['id'],$g['group_id']]);
        if (!$chk->fetchColumn()) return ['reply'=>"El grupo {$g['group_name']} no está en tu alcance."];
    }
    $n=$conn->prepare("SELECT COUNT(*) FROM student_group_assignments WHERE group_id=? AND active=TRUE");
    $n->execute([$g['group_id']]); $c=(int)$n->fetchColumn();
    return ['reply'=>"{$g['group_name']} tiene *{$c} estudiante(s)* activos."];
}

/** Cumpleaños de estudiantes — hoy o esta semana. */
function chat_birthdays_today(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    $st=$conn->prepare("SELECT s.first_name||' '||s.last_name AS name, ag.group_name,
        to_char(s.birth_date,'DD Mon') AS d
        FROM students s
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE s.school_id=? AND s.deleted_at IS NULL AND s.birth_date IS NOT NULL
          AND to_char(s.birth_date,'MM-DD') BETWEEN to_char(CURRENT_DATE,'MM-DD')
              AND to_char(CURRENT_DATE + INTERVAL '7 days','MM-DD')
        {$scope['sql']} ORDER BY to_char(s.birth_date,'MM-DD') LIMIT 15");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay cumpleaños de estudiantes en los próximos 7 días.'];
    return ['reply'=>"Cumpleaños próximos:",
        'cards'=>[['title'=>'Cumpleaños','columns'=>['Estudiante','Grupo','Fecha'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['d']],$rows)]]];
}

/** Actividad propia del usuario en el sistema (auditoría). */
function chat_my_activity(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT action_type, COUNT(*) c FROM global_audit_logs
        WHERE performed_by_user_id=? AND created_at::date BETWEEN ? AND ?
        GROUP BY action_type ORDER BY c DESC LIMIT 12");
    $st->execute([$u['id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rl=$s['range_label']??'hoy';
    if(!$rows) return ['reply'=>"No registras actividad en {$rl} — el día apenas arranca."];
    return ['reply'=>"Tu actividad ({$rl}):",
        'cards'=>[['title'=>'Tu actividad','columns'=>['Acción','Veces'],
        'rows'=>array_map(fn($r)=>[$r['action_type'],$r['c']],$rows)]]];
}

/** Mensajes/citaciones que fallaron en envío. */
function chat_failed_messages(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT COUNT(*) FROM internal_messages WHERE school_id=? AND sent_at::date BETWEEN ? AND ? AND status='FAILED'");
    try { $st->execute([$u['school_id'],$from,$to]); $n=(int)$st->fetchColumn(); }
    catch (Throwable $e) { $n=0; }
    $rl=$s['range_label']??'hoy';
    return ['reply'=>$n ? "⚠ {$n} mensaje(s) fallaron en envío ({$rl}) — el worker reintenta automáticamente." : "Ningún mensaje fallido ({$rl}) — la mensajería va limpia."];
}

/** Configuración de riesgo de la escuela — solo rectoría/coordinación. */
function chat_risk_config(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT metric, threshold, window_days FROM risk_rules WHERE school_id=? AND active=TRUE ORDER BY metric LIMIT 15");
    try { $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $rows=[]; }
    if(!$rows) return ['reply'=>'La configuración de riesgo usa los umbrales por defecto del sistema — no hay reglas personalizadas todavía.'];
    return ['reply'=>"Umbrales de riesgo configurados:",
        'cards'=>[['title'=>'Motor de riesgo','columns'=>['Métrica','Umbral','Ventana (días)'],
        'rows'=>array_map(fn($r)=>[$r['metric'],$r['threshold'],$r['window_days']],$rows)]]];
}

/** Ranking de grupos por incidentes del periodo. */
function chat_attendance_ranking(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT ag.group_name, ai.incident_type, COUNT(*) c
        FROM attendance_incidents ai JOIN academic_groups ag ON ag.group_id=ai.group_id
        WHERE ai.school_id=? AND ai.detected_at::date BETWEEN ? AND ?
        GROUP BY ag.group_name, ai.incident_type ORDER BY ag.group_name, c DESC LIMIT 40");
    $st->execute([$u['school_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'Sin incidentes registrados en ese rango en ningún grupo — todos limpios.'];
    $by=[]; foreach($rows as $r){ $by[$r['group_name']]=($by[$r['group_name']]??0)+$r['c']; }
    arsort($by); $by=array_slice($by,0,8,true);
    return ['reply'=>"Grupos con más incidentes (" . ($s['range_label']??'hoy') . "):",
        'cards'=>[['title'=>'Ranking de grupos','columns'=>['Grupo','Incidentes'],
        'rows'=>array_map(fn($k,$c)=>[$k,$c],array_keys($by),$by)]]];
}

/** Resumen de la conversación actual — meta-del-chat. */
function chat_session_summary(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT payload_json->>'intent' AS i FROM chat_messages
        WHERE user_id=? AND role='user' AND created_at::date=CURRENT_DATE ORDER BY created_at DESC LIMIT 30");
    try { $st->execute([$u['id']]); $ints=array_filter(array_column($st->fetchAll(PDO::FETCH_ASSOC),'i')); } catch (Throwable $e) { $ints=[]; }
    if(!$ints) return ['reply'=>'Apenas empezamos — aún no me has pedido nada concreto hoy.'];
    $counts=array_count_values($ints); arsort($counts); $counts=array_slice($counts,0,6,true);
    $labels=['count_events'=>'conteos de incidentes','list_events'=>'listados','student_summary'=>'fichas de estudiantes',
        'student_field'=>'datos de estudiantes','random_student'=>'estudiantes al azar','day_summary'=>'resumen de jornada',
        'math_operation'=>'cálculos','colombia_capital'=>'capitales','joke'=>'chistes','fun_fact'=>'datos curiosos',
        'staff_lookup'=>'personal de la escuela','trackings'=>'seguimientos','count_present'=>'ingresos del día'];
    $bits=array_map(function($k,$c) use ($labels){ $l=$labels[$k]??$k; return "{$l} ({$c})"; },array_keys($counts),$counts);
    return ['reply'=>"Hoy hemos hablado de: ".implode(' · ',$bits).'.'];
}

/** Lo que el usuario tiene pendiente — notificaciones + seguimientos propios. */
function chat_pending_tasks(PDO $conn, array $u, array $s, array $v): array {
    $bits=[];
    $st=$conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
    $st->execute([$u['id']]); $n=(int)$st->fetchColumn();
    if($n) $bits[]="{$n} notificación(es) sin leer";
    if(in_array($u['role'],['TEACHER','COUNSELOR'],true)){
        $st=$conn->prepare("SELECT COUNT(*) FROM student_tracking t JOIN students st ON st.student_id=t.student_id
            WHERE t.school_id=? AND t.status='en proceso' AND t.assigned_to_user_id=?");
        try { $st->execute([$u['school_id'],$u['id']]); $t2=(int)$st->fetchColumn(); if($t2) $bits[]="{$t2} seguimiento(s) asignados a ti"; } catch (Throwable $e) {}
    }
    $scope=chatScope($conn,$u); $today=gmdate('Y-m-d');
    $st=$conn->prepare("SELECT COUNT(*) FROM class_exit_authorizations c JOIN students s ON s.student_id=c.student_id
        WHERE c.school_id=? AND c.status='ACTIVE' AND c.expected_return_time < NOW() {$scope['sql']}");
    $st->execute([$u['school_id']]); $p=(int)$st->fetchColumn();
    if($p) $bits[]="{$p} permiso(s) vencidos sin retorno";
    return ['reply'=>$bits ? "Pendiente ahora: ".implode(' · ',$bits).'.' : 'Todo al día — nada pendiente de tu lado.'];
}

/** Estado de la cola de mensajería (Twilio/WhatsApp). */
function chat_whatsapp_status(PDO $conn, array $u, array $s, array $v): array {
    $today=gmdate('Y-m-d');
    $st=$conn->prepare("SELECT COUNT(*) FROM notifications WHERE school_id=? AND created_at::date=?");
    $st->execute([$u['school_id'],$today]); $n=(int)$st->fetchColumn();
    return ['reply'=>"Mensajería del día: {$n} notificación(es) generadas. La cola procesa con reintentos — si algo falla lo ves en «mensajes fallidos»."];
}
