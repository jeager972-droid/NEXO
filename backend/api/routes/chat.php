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
];

/** Política efectiva: tabla escuela → default TRUE. Cache por request. */
function chatPolicyEnabled(PDO $conn, string $schoolId, string $key): bool {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $conn->prepare("SELECT enabled FROM school_chat_policies WHERE school_id=? AND policy_key=?");
    $st->execute([$schoolId, $key]);
    $v = $st->fetchColumn();
    return $cache[$key] = ($v === false) ? true : (bool)$v;
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
        'seguimiento' => in_array($role, ['RECTOR','COORDINATOR','TEACHER','COUNSELOR'], true),
        'citacion'    => in_array($role, ['RECTOR','COORDINATOR','TEACHER','COUNSELOR','SECRETARY'], true),
        'permiso'     => in_array($role, ['TEACHER','COORDINATOR','RECTOR'], true),
        'incidente'   => in_array($role, ['TEACHER','COUNSELOR','RECTOR','COORDINATOR'], true),
        default       => false,
    };
}

/** Chips de acción → navegación a /operacion con comando precargado. */
function chatActionChip(string $cmd, string $label, ?array $student = null): array {
    $q = "/operacion?cmd={$cmd}";
    if ($student) $q .= '&student=' . urlencode($student['student_id']);
    return ['kind' => 'nav', 'label' => $label, 'to' => $q];
}

/** Chips derivados cuando un resultado cruza umbral. */
function chatDerivedActions(array $u, ?array $student, string $reason): array {
    $a = [];
    if ($student) {
        if (chatCanAction('seguimiento', $u['role'])) $a[] = chatActionChip('seguimiento', 'Derivar a seguimiento', $student);
        if (chatCanAction('citacion', $u['role']))    $a[] = chatActionChip('citacion', 'Citar acudiente', $student);
        if (chatCanAction('incidente', $u['role']))   $a[] = chatActionChip('incidente', 'Reportar incidente', $student);
    } else {
        if (chatCanAction('seguimiento', $u['role'])) $a[] = chatActionChip('seguimiento', 'Abrir seguimiento');
        if (chatCanAction('citacion', $u['role']))    $a[] = chatActionChip('citacion', 'Citar acudiente');
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

    $cls = nxClassify($text);
    $intent  = $cls['intent'];
    $slots   = $cls['entities'] ?? [];
    $conf    = $cls['confidence'] ?? 0;

    $firstName = explode(' ', trim((string)($authUser['nombre'] ?? $authUser['first_name'] ?? '')))[0] ?: '';
    $vars = ['_q' => nxNorm($text), 'name' => $firstName ? ', ' . $firstName : '', 'daypart' => (function(){ $h=(int)date('G'); return $h<12?'Buenos días':($h<18?'Buenas tardes':'Buenas noches'); })()];

    // ── RBAC + políticas institucionales ──
    if (!chatAllowed($conn, $authUser, $intent, $role)) {
        $reply = nxSmalltalk('denied', $vars);
        $out = ['reply'=>$reply,'intent'=>$intent,'confidence'=>$conf,'denied'=>true];
        chatLog($conn, $schoolId, $userId, $text, $out);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    // ── Smalltalk / meta ──
    $smalltalkIntents = ['greeting','greeting_time','wellbeing','wellbeing_reply','joke',
        'fun_fact','about_nexus','name_meaning','creator','age','thanks','goodbye',
        'yes','no','apology','compliment','insult','bored','love','human_check',
        'do_for_me','emotion_sad','weather','news_sports','food_music',
        'meaning_life','confused','repeat','insult_back','sing','dance','story',
        'motivation','out_of_scope','help','capabilities','security_probe',
        'foreign_culture'];

    $out = null;
    if (in_array($intent, ['help','capabilities'], true)) {
        $out = ['reply' => chatHelp($role), 'intent'=>$intent];
    } elseif (in_array($intent, $smalltalkIntents, true)) {
        if ($intent === 'security_probe') {
            securityLog('CHAT_SECURITY_PROBE', mb_substr($text,0,200) . ' | user ' . $userId);
        }
        $out = ['reply' => nxSmalltalk($intent, $vars), 'intent'=>$intent];
    } else {
        // ── Data intents ──
        $handler = 'chat_' . $intent;
        if (function_exists($handler)) {
            try {
                $out = $handler($conn, $authUser, $slots, $vars);
            } catch (Throwable $e) {
                securityLog('CHAT_HANDLER_ERROR', $intent . ': ' . $e->getMessage());
                $out = ['reply'=>'No pude consultar eso ahora — intenta de nuevo en un momento.', 'intent'=>$intent];
            }
        } else {
            $out = ['reply' => nxSmalltalk('out_of_scope', $vars), 'intent'=>$intent];
        }
    }
    $out['intent'] = $intent;
    $out['confidence'] = $conf;

    chatLog($conn, $schoolId, $userId, $text, $out);
    echo json_encode(['status'=>'ok','data'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================================
 * GET /chat/history — últimos 30 mensajes del usuario
 * ========================================================================== */
if ($cleanPath === '/chat/history' && $method === 'GET') {
    $authUser = requireAuth();
    $stmt = $conn->prepare("
        SELECT role, content, payload_json, created_at
        FROM chat_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 60
    ");
    $stmt->execute([$authUser['id']]);
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
function chatLog(PDO $conn, string $schoolId, string $userId, string $text, array $out): void {
    try {
        $conn->prepare("INSERT INTO chat_messages (school_id,user_id,role,content,payload_json) VALUES (?,?,?,'user',?)")
            ->execute([$schoolId,$userId,$text, json_encode(['text'=>$text], JSON_UNESCAPED_UNICODE)]);
        $conn->prepare("INSERT INTO chat_messages (school_id,user_id,role,content,payload_json) VALUES (?,?,?,'assistant',?,?)")
            ->execute([$schoolId,$userId,$out['reply'], json_encode($out, JSON_UNESCAPED_UNICODE)]);
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
    $late = $q('LATE_ARRIVAL'); $abs = $q('INASISTENCIA'); $eva = $q('EVASION_INTERNA');
    $st = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
        JOIN students s ON s.student_id=be.student_id
        WHERE be.school_id=? AND be.event_timestamp::date=? AND be.event_type LIKE 'INGRESO%' {$scope['sql']}");
    $st->execute([$u['school_id'],$today]); $present = (int)$st->fetchColumn();
    $st = $conn->prepare("SELECT COUNT(*) FROM class_exit_authorizations c JOIN students s ON s.student_id=c.student_id WHERE c.school_id=? AND c.status='ACTIVE' {$scope['sql']}"); $st->execute([$u['school_id']]); $perm = (int)$st->fetchColumn();
    $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL"); $st->execute([$u['id']]); $notif = (int)$st->fetchColumn();

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
    if (!$rows) return ['reply'=>"Sin {$label} en el rango — todo limpio."];
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
    $reply = "{$n} {$mlabel}{$who}{$grp} ({$rl}).";
    $actions = [];
    if ($n >= 2 && $student) { $reply .= " Eso ya cruza el umbral de atención — ¿quieres actuar?"; $actions = chatDerivedActions($u,$student,$mlabel); }
    elseif ($n >= 5) { $reply .= " Es bastante — puedo darte el detalle por estudiante."; }
    return ['reply'=>$reply,'actions'=>$actions];
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
    if(!$rows) return ['reply'=>"No hay {$mlabel} en ese rango — todo limpio."];
    return ['reply'=>count($rows)." ".(count($rows)===1?'registro':'registros')." de {$mlabel} (" . ($s['range_label']??'hoy') . "):",
        'cards'=>[['title'=>ucfirst($mlabel),'columns'=>['Estudiante','Grupo','Fecha','Hora'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['d'],substr($r['t'],0,5)],$rows)]]];
}

function chat_student_field(PDO $conn, array $u, array $s, array $v): array {
    $found = chatResolveStudent($conn,$u,$s['student'] ?? null);
    if (!$found) return ['reply'=>"¿De qué estudiante hablas? Dame nombre o apellido."];
    if (count($found)>1) return chatAmbiguous($found);
    $st = $found[0];
    $field = $s['field'] ?? 'datos';
    $name = "{$st['first_name']} {$st['last_name']}";

    // acudiente
    if ($field === 'acudiente' || $field === 'celular') {
        $g = $conn->prepare("SELECT u.first_name||' '||u.last_name AS name, g.whatsapp_phone, u.phone
            FROM guardian_student_relationships r JOIN guardians g ON g.guardian_id=r.guardian_id
            JOIN users u ON u.user_id=g.user_id
            WHERE r.student_id=? ORDER BY r.primary_guardian DESC LIMIT 1");
        $g->execute([$st['student_id']]); $guard=$g->fetch(PDO::FETCH_ASSOC);
    }
    return match($field) {
        'documento' => ['reply'=>"{$name}: documento *{$st['document_number']}* — grupo {$st['group_name']}, grado {$st['grade_level']}."],
        'acudiente' => ['reply'=>$guard ? "Acudiente de {$name}: *{$guard['name']}* — WhatsApp {$guard['whatsapp_phone']}" . ($guard['phone']&&$guard['phone']!==$guard['whatsapp_phone']?" · tel {$guard['phone']}":'') . "." : "{$name} no tiene acudiente registrado — te tocaría registrarlo primero."],
        'celular'   => ['reply'=>$guard ? "Contacto de {$name}: acudiente {$guard['name']}, WhatsApp *{$guard['whatsapp_phone']}*." : "{$name}: sin acudiente registrado — no tengo número de contacto."],
        'grupo'     => ['reply'=>"{$name} está en {$st['group_name']} (grado {$st['grade_level']}, jornada {$st['work_shift']})."],
        'jornada'   => ['reply'=>"{$name} va en jornada {$st['work_shift']} — grupo {$st['group_name']}."],
        'nacimiento'=> ['reply'=>$st['birth_date'] ? "{$name} nació el {$st['birth_date']}." : "{$name}: no tengo fecha de nacimiento registrada."],
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
    return ['reply'=>implode(' ',$bits),'actions'=>$actions];
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
    // qué acción pidió: seguimiento | citacion | permiso | incidente
    $q=$v['_q']??'';
    $cmd = str_contains($q,'cit')?'citacion':(str_contains($q,'permiso')?'permiso':(str_contains($q,'incidente')||str_contains($q,'report')?'incidente':'seguimiento'));
    if(!chatCanAction($cmd,$u['role'])) return ['reply'=>'Esa acción no está disponible para tu rol — la gestiona coordinación o rectoría.'];
    $name=$student?" para {$student['first_name']} {$student['last_name']}":'';
    return ['reply'=>"Te llevo al formulario de {$cmd}{$name} — confirmas ahí y queda registrado.",
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
    $t = nxTopic($s);
    if (!$t) return ['reply'=>'El presidente actual es *Gustavo Petro* (desde 2022) — el primero de izquierda en la historia de Colombia. ¿Quieres saber de otro presidente?'];
    // busca por nombre parcial
    $first = explode(' ', $t)[0] ?? '';
    foreach (NX_KB_PRESIDENTS as $name => $bio)
        if (str_contains($name, $t) || str_contains($t, $name)
            || (mb_strlen($first) > 3 && str_contains($name, $first)))
            return ['reply'=>$bio];
    return ['reply'=>"De «{$t}» no tengo ficha presidencial — mi memoria llega de Bolívar a Petro. Pregúntame por apellido: «quién fue Betancur», «Santos», «Lleras Restrepo»…"];
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
    return ['reply'=>NX_KB_FACTS[array_rand(NX_KB_FACTS)]];
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
