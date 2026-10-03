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
require_once __DIR__ . '/../nexus/nexus_nlu.php';
require_once __DIR__ . '/../nexus/nexus_semantic.php';
require_once __DIR__ . '/../nexus/nexus_scp.php';
require_once __DIR__ . '/../lib/kb_colombia.php';
require_once __DIR__ . '/../lib/calculator.php';

/* ============================================================================
 * Helpers
 * ========================================================================== */

/* Etiquetas ES de roles — a nivel de archivo ARRIBA de todo: `const` es
 * sentencia runtime y el dispatch hace exit antes de alcanzar líneas
 * inferiores; declararlo aquí garantiza que exista en cualquier handler. */
const NX_ROLE_ES = ['RECTOR' => 'rectoría', 'COORDINATOR' => 'coordinación', 'COUNSELOR' => 'psicoorientación',
    'SECRETARY' => 'secretaría', 'SECURITY' => 'portería', 'AUXILIARY' => 'auxiliar', 'TEACHER' => 'docente'];

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
    // la columna se compara sin acentos — el parámetro también debe ir sin
    // acentos o «Tomás» jamás coincide con «tomas» traducido en la BD
    $like = '%' . mb_strtolower(strtr($name, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','ü'=>'u','Ü'=>'u','ñ'=>'n','Ñ'=>'n'])) . '%';
    $nameDoc = trim(preg_replace('/[^\d]/','', (string)$name));
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
          AND (translate(lower(s.first_name || ' ' || s.last_name),'áéíóúüñ','aeiouun') LIKE ?
            OR translate(lower(s.last_name || ' ' || s.first_name),'áéíóúüñ','aeiouun') LIKE ?
            OR s.document_number = ?)
          {$scope['sql']}
        ORDER BY s.last_name, s.first_name
        LIMIT 3
    ");
    $stmt->execute(array_merge([$u['school_id'], $like, $like, $nameDoc !== '' ? $nameDoc : $name], $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) return $rows; // >1 = ambiguo
    return chatResolveStudentFuzzy($conn, $u, $name, $scope);
}

/**
 * Forma fonética del español para nombres: «Gonzales»≈«González»,
 * «Zuluaga»≈«Suluaga», «Yepes»≈«Llepes», «Valencia»≈«Balencia».
 */
function chatPhonetic(string $t): string {
    $t = mb_strtolower(strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n']));
    $t = preg_replace('/[^a-z ]/', '', $t);
    $t = preg_replace(['/ll/','/qu/','/c([ei])/','/z/','/v/','/h/','/c/','/k/','/x/','/y\b/','/(.)\1+/'],
                      ['y','k','s$1','s','b','','k','k','ks','i','$1'], $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/**
 * Segundo intento cuando el LIKE literal falla: cada palabra del nombre
 * pedido debe coincidir fonéticamente (o con distancia ≤1) con alguna del
 * nombre real. Solo devuelve candidatos claros — nunca adivina entre muchos.
 */
function chatResolveStudentFuzzy(PDO $conn, array $u, string $name, array $scope): ?array {
    $want = array_values(array_filter(explode(' ', chatPhonetic($name)), fn($w) => strlen($w) >= 3));
    if (!$want) return null;
    try {
        $st = $conn->prepare("SELECT s.student_id, s.first_name, s.last_name, s.document_number,
                   s.birth_date, s.work_shift, s.grade_level, ag.group_name
            FROM students s
            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE s.school_id = ? AND s.deleted_at IS NULL {$scope['sql']}
            LIMIT 5000");
        $st->execute(array_merge([$u['school_id']], $scope['params']));
    } catch (Throwable $e) { return null; }
    $scored = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $have = explode(' ', chatPhonetic($r['first_name'] . ' ' . $r['last_name']));
        $hit = 0;
        foreach ($want as $w) {
            foreach ($have as $h) {
                if ($h === $w || (strlen($w) >= 5 && levenshtein($h, $w) <= 1)) { $hit++; break; }
            }
        }
        if ($hit === count($want)) $scored[] = $r + ['_exact' => count($have) === count($want)];
    }
    if (!$scored) return null;
    // exactos primero (mismas palabras) — si hay uno solo, ese es
    $exact = array_values(array_filter($scored, fn($r) => $r['_exact']));
    $pick = count($exact) === 1 ? $exact : $scored;
    return array_slice(array_map(fn($r) => array_diff_key($r, ['_exact' => 1]), $pick), 0, 3);
}

/* ── SQL compartido ───────────────────────────────────────────────────────
 * Zona horaria: la sesión de PostgreSQL corre en UTC; la institución vive en
 * America/Bogota (−5h). `ts::date` en UTC parte el día a las 7 p. m. locales
 * y las horas mostradas salían 5 h adelantadas. Todo filtro/visualización
 * de fecha-hora del chat pasa por estas funciones. */
const NX_TZ_SQL = "'America/Bogota'";
function chatD(string $col): string { return "({$col} AT TIME ZONE " . NX_TZ_SQL . ")::date"; }
function chatTs(string $col): string { return "to_char({$col} AT TIME ZONE " . NX_TZ_SQL . ", 'YYYY-MM-DD HH24:MI')"; }
function chatHm(string $col): string { return "to_char({$col} AT TIME ZONE " . NX_TZ_SQL . ", 'HH24:MI')"; }

/**
 * Grupo real del incidente: los workers insertan attendance_incidents sin
 * group_id — filtrar/mostrar por ai.group_id daba «0 inasistencias en 10-A»
 * con el grupo entero ausente. COALESCE con la asignación activa.
 */
function chatIncGroupJoin(string $ai = 'ai'): string {
    return " LEFT JOIN student_group_assignments sgx ON sgx.student_id = {$ai}.student_id AND sgx.active = TRUE
             LEFT JOIN academic_groups ag ON ag.group_id = COALESCE({$ai}.group_id, sgx.group_id) ";
}
function chatIncGroupCol(string $ai = 'ai'): string { return "COALESCE({$ai}.group_id, sgx.group_id)"; }

/**
 * Excusa de un incidente: soporte registrado (risk_justifications) o
 * justificación del acudiente por WhatsApp (metadata_json.justificada).
 */
function chatExcuseExpr(string $ai = 'ai'): string {
    return "COALESCE((SELECT rj.reason FROM risk_justifications rj
                WHERE rj.student_id = {$ai}.student_id AND rj.incident_type = {$ai}.incident_type
                  AND rj.incident_date = " . chatD("{$ai}.detected_at") . " AND rj.school_id = {$ai}.school_id LIMIT 1),
             CASE WHEN {$ai}.metadata_json->>'justificada' = 'true'
                  THEN COALESCE(NULLIF({$ai}.metadata_json->>'motivo',''), 'Justificada por el acudiente') END)";
}
function chatJustifiedSql(string $want, string $ai = 'ai'): string {
    $has = "(EXISTS (SELECT 1 FROM risk_justifications rj WHERE rj.student_id = {$ai}.student_id
                AND rj.incident_type = {$ai}.incident_type AND rj.incident_date = " . chatD("{$ai}.detected_at") . "
                AND rj.school_id = {$ai}.school_id)
             OR {$ai}.metadata_json->>'justificada' = 'true')";
    return $want === 'yes' ? " AND {$has}" : ($want === 'no' ? " AND NOT {$has}" : '');
}

/** Período en lenguaje natural para respuestas («hoy», «la semana pasada»). */
function chatRangeLabel(array $s): string {
    if (!empty($s['range_label'])) return (string)$s['range_label'];
    [$from, $to] = chatRange($s);
    if ($from === $to) return $from === nxToday() ? 'hoy' : ($from === nxToday(1) ? 'ayer' : "el {$from}");
    return "del {$from} al {$to}";
}

/** «en 10-A» / «para Ana Pérez» — sujeto legible del filtro aplicado. */
function chatWho(?array $student, ?array $group): string {
    if ($student) return " de {$student['first_name']} {$student['last_name']}";
    if ($group) return " en {$group['group_name']}";
    return '';
}

/** Oferta registrable: «sí» en el turno siguiente ejecuta esto (sin LLM). */
function chatOffer(string $label, string $intent, array $slots): array {
    return ['label' => $label, 'intent' => $intent, 'slots' => $slots];
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
    $ok = nxAllowed($intent, $role);
    // políticas solo acotan roles no-globales
    if ($ok && in_array($role, ['TEACHER','COUNSELOR'], true)) {
        $key = NX_CHAT_POLICY_MAP[$intent] ?? null;
        if ($key && !chatPolicyEnabled($conn, $u['school_id'], $key)) $ok = false;
    }
    // smalltalk puede desactivarse globalmente por la escuela
    if ($ok && !chatPolicyEnabled($conn, $u['school_id'], 'chat.smalltalk.enabled')) {
        $dataIntents = array_merge(array_keys(NX_CHAT_POLICY_MAP), ['count_events','list_events','permissions','notifications_unread','devices_status','audit_query','groups_list','teachers_list','schedule_info','export_data','about_me','time','date','system_incidents','sos_alerts','pending_returns','top_offenders','frequency_table','guardian_replies',
            'risk_reason','incident_excuses','exit_detail','trip_info','school_calendar',
            'staff_contact','teacher_schedule','student_consent','tracking_detail',
            'citations_by','alert_resolution','enrollment_stats','reports_log',
            'sos_detail','guardian_messages','device_detail','attendance_trend']);
        if (!in_array($intent, $dataIntents, true) && $intent !== 'out_of_scope') $ok = false;
    }
    // un probe negado también es evidencia — el log no depende del smalltalk
    if (!$ok && $intent === 'security_probe' && function_exists('securityLog')) {
        securityLog('CHAT_SECURITY_PROBE', 'denied-by-gate | user ' . ($u['id'] ?? '?'));
    }
    return $ok;
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

/**
 * Últimos turnos user/assistant de la sesión — contexto conversacional
 * para el parser y el chat informal del LLM (texto ya persistido, no PII
 * nueva: es la misma conversación del usuario).
 */
function chatRecentTurns(PDO $conn, string $userId, string $sessionId, int $n = 3): array {
    try {
        $st = $conn->prepare("SELECT role, content FROM chat_messages
            WHERE user_id=? AND session_id=? AND role IN ('user','assistant')
            ORDER BY created_at DESC LIMIT " . (int)($n * 2));
        $st->execute([$userId, $sessionId]);
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
    $turns = []; $cur = [];
    foreach ($rows as $r) {
        if ($r['role'] === 'user') { if ($cur) { $turns[] = $cur; } $cur = ['u'=>$r['content'],'a'=>'']; }
        else { if ($cur) $cur['a'] = $r['content']; }
    }
    if ($cur) $turns[] = $cur;
    return array_slice($turns, -$n);
}

/**
 * Red de seguridad DETERMINISTA para contenido riesgoso — backstop del flag
 * `safety` del parser LLM (que puede fallar o estar apagado). Generalista:
 * categorías de riesgo, nunca un intent por tema.
 */
function nxSafetyScreen(string $q): bool {
    return (bool)(
        // atracción/romance/sexualización hacia estudiantes o menores
        preg_match('/\b(me (gusta|enamore|encanta|atrae|prende)|enamorado|enamorada|novia|novio|salir con|besar|beso|linda|bonita|buena|rica|sexy|hot)\b[^.!?]{0,40}\b(estudiante|alumn[ao]s?|niñ[oa]s?|menor(?:es)?|pelada|muchacha|chica del|quinceañera)/u', $q)
        || preg_match('/\b(estudiante|alumn[ao]s?|niñ[oa]s?|menor(?:es)?|pelada|muchacha)\b[^.!?]{0,30}\b(est[áa] (buena|rica|linda|muy bien)|me (gusta|enamore|encanta|atrae)|novia|novio)/u', $q)
        // falsificación/eliminación de registros o extracción de credenciales
        || preg_match('/\b(borra|borrar|elimina|eliminar|quita|quitar|falsifica|falsificar|modifica|alterar|cambia|inventa|inventar)\w*\b[^.!?]{0,30}\b(inasistencia|tardanza|registro|evasion|incidente|historial|asistencia|datos)/u', $q)
        || preg_match('/\b(contraseña|password|clave|credenciales|token|pin)\b[^.!?]{0,25}\b(de |del |otro|rector|docente|admin|coordinador|usuario)/u', $q)
        || preg_match('/\b(como|puedo|ayudame a|ensename a)\b[^.!?]{0,25}\b(hackear|piratear|entrar sin|saltar|burlar|falsificar)/u', $q)
    );
}

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
    // Contexto compacto para el parser LLM (§7.4): entidades activas +
    // descriptor del result-set + últimos turnos — el modelo resuelve
    // referencias por sí mismo; el DSM sigue siendo la autoridad.
    $llmCtx = [
        'entities'    => $dsPre['entities'] ?? [],
        'last_result' => isset($dsPre['last_result'])
            ? ['type'=>$dsPre['last_result']['type'] ?? null,
               'label'=>$dsPre['last_result']['label'] ?? null,
               'count'=>$dsPre['last_result']['count'] ?? count($dsPre['last_result']['items'] ?? [])]
            : null,
        'turns'       => chatRecentTurns($conn, $userId, $sessionId,
                        max(3, min(40, (int)(getenv('NLU_LLM_CTX_TURNS') ?: 20)))),
    ];
    $vars['_history'] = $llmCtx['turns'];   // historial para el chat informal LLM
    $vars['_raw'] = $text;                  // texto original (sin normalizar)
    $hasNavableSet = !empty($dsPre['last_result']['items']);
    if (!$hasNavableSet
        && preg_match('/^(dame |dime )?(otro|otra|uno mas|una mas|mas|siguiente|otra vez|y otro|y otra|de nuevo|dame mas|dime mas|continua|sigue|y eso|y ese|y esa)[.! ]*$/u', $q0)) {
        $last = chatLastPayload($conn, $userId, $sessionId);
        if ($last && !empty($last['intent'])) {
            // la repetición hereda el intent — la autorización no se hereda:
            // el gate corre de nuevo antes de despachar (políticas pueden
            // haber cambiado y el payload anterior pudo ser smalltalk)
            if (!chatAllowed($conn, $authUser, $last['intent'], $role)) {
                $out = ['reply'=>nxSmalltalk('denied',$vars),'intent'=>$last['intent'],
                        'denied'=>true,'confidence'=>1.0,'session_id'=>$sessionId];
                if ($dsPre) $out['_ds'] = $dsPre;   // denied no altera el estado
                chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
                exit(json_encode(['status'=>'ok','data'=>$out]));
            }
            $vars['_last_reply'] = $last['reply'] ?? '';
            $slots = $last['entities'] ?? [];
            $slots['_repeat'] = true; // los handlers aleatorios eligen otro valor
            $out = chatDispatch($conn, $authUser, $last['intent'], $slots, $vars, $role);
            $out['confidence'] = 1.0;
            $out['session_id'] = $sessionId;
            // _ds: la repetición refresca el result-set del intent heredado
            $out['_ds'] = chatBuildDs(
                ['resolved'=>['intent'=>$last['intent'],'slots'=>$slots],
                 'turn_type'=>'context_modify'], $out, $dsPre);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // ── Turnos conversacionales que no necesitan al parser ─────────────
    // Deterministas: 0 cuota de LLM y sin margen de interpretación.

    // (a) oferta del turno anterior + afirmación → ejecutar lo ofrecido
    $pendingOffer = $dsPre['offer'] ?? null;
    if (is_array($pendingOffer) && !empty($pendingOffer['intent'])
        && preg_match('/^(si|dale|ok|okay|bueno|listo|va|vamos|claro|porfa|por favor|hazlo|hazla|adelante|vale|muestramel[oa]s?|muestrame|quiero ver)[.! ]*$/iu', $q0)) {
        $oIntent = (string)$pendingOffer['intent'];
        $oSlots = is_array($pendingOffer['slots'] ?? null) ? $pendingOffer['slots'] : [];
        if (!chatAllowed($conn, $authUser, $oIntent, $role)) {
            $out = ['reply'=>nxSmalltalk('denied',$vars),'intent'=>$oIntent,'denied'=>true,
                    'confidence'=>1.0,'session_id'=>$sessionId];
            if ($dsPre) $out['_ds'] = $dsPre;
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        $out = chatDispatch($conn, $authUser, $oIntent, $oSlots, $vars, $role);
        $out = nxPlanResponse($out, $oIntent, 'offer:' . $oIntent);
        $out['intent'] = $oIntent; $out['confidence'] = 1.0; $out['session_id'] = $sessionId;
        $out['entities'] = array_merge($oSlots, $out['entities'] ?? []);
        $out['_ds'] = chatBuildDs(['resolved'=>['intent'=>$oIntent,'slots'=>$oSlots],
            'turn_type'=>'context_modify'], $out, $dsPre);
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    // (b) reparación: «repite», «¿seguro?», «como que no», «no te pregunté eso»
    $repair = nxRepairKind($q0);
    if ($repair === 'complaint') {
        // reclamo + petición real en el mismo turno («no te dije fechas… pero
        // dime spam de los lectores los últimos 30 días»): el reclamo se
        // reconoce y la consulta sigue su camino normal
        $segs = array_values(array_filter(array_map('trim', preg_split('/[.!?]+/u', $q0))));
        $keep = array_filter($segs, fn($seg) => nxRepairKind($seg) === null
            && !preg_match('/^(pero bueno|bueno|pero|asi que|en fin|mira)$/u', $seg));
        $rest = trim(implode(' ', $keep));
        if (mb_strlen($rest) >= 8 && $rest !== $q0 && (nxRuleClassify($rest) || nxSlots($rest))) {
            $text = $rest; $q0 = $rest;
            $vars['_q'] = $rest; $vars['_raw'] = $rest;
            $repair = null;
        }
    }
    if ($repair !== null) {
        $lastP = chatLastPayload($conn, $userId, $sessionId);
        if ($repair === 'repeat') {
            $rp = trim((string)($lastP['reply'] ?? ''));
            $out = $rp !== ''
                ? ['reply'=>$rp,'cards'=>$lastP['cards'] ?? null,'actions'=>$lastP['actions'] ?? null,
                   'intent'=>'repeat_op','confidence'=>1.0,'_natural'=>true]
                : ['reply'=>'Todavía no te he dicho nada en esta conversación — ¿qué quieres saber?',
                   'intent'=>'repeat_op','confidence'=>1.0,'_natural'=>true];
        } elseif ($repair === 'verify') {
            // «¿seguro?»/«como que no» con consulta activa → re-ejecutarla tal cual
            $li = nxCapToIntent($dsPre['intent'] ?? '') ?? ($dsPre['intent'] ?? null);
            if ($li && !str_contains((string)$li, '+')
                && in_array($li, NX_QUERY_INTENTS, true)
                && chatAllowed($conn, $authUser, $li, $role)) {
                $vSlots = (array)($dsPre['entities'] ?? []);
                $out = chatDispatch($conn, $authUser, $li, $vSlots, $vars, $role);
                $out = nxPlanResponse($out, $li, 'verify:' . $li);
                $out['reply'] = 'Lo acabo de verificar de nuevo — ' . lcfirst((string)$out['reply']);
                $out['intent'] = $li; $out['_verified'] = true;
            } else {
                $out = ['reply'=>'Sí — eso es lo que hay registrado ahora mismo. Si quieres lo miro por otro rango o grupo.',
                        'intent'=>'verify','confidence'=>1.0,'_natural'=>true];
            }
        } elseif ($repair === 'complaint') {
            $out = ['reply'=>'Perdón, me fui por otro lado — dime qué necesitas y voy directo al dato.',
                    'intent'=>'complaint','confidence'=>1.0,'_natural'=>true];
        } else {
            $out = ['reply'=>'Entendido.','intent'=>'repair_off','confidence'=>1.0,'_natural'=>true];
        }
        $out['session_id'] = $sessionId;
        // la reparación conserva el estado — no es un tema nuevo ni lo borra
        $out['_ds'] = $dsPre ?? [];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    // (c) filtro sobre el set de PERSONAL activo («¿cuál de ellos es de la
    // tarde?», «¿quién es la coordinadora?») — el set ya trae shift/role
    $lrPre = $dsPre['last_result'] ?? null;
    if (($lrPre['type'] ?? null) === 'staff' && !empty($lrPre['items'])
        && !preg_match('/\b(estudiante|alumn|grupo|curso)\b/u', $q0)) {
        $wantShift = null;
        if (preg_match('/\b(jornada|turno|horario|trabaja|esta)?\s*(de la |del |en la )?(manana|tarde|noche)\b/u', $q0, $sm)
            && preg_match('/\b(manana|tarde|noche)\b/u', $q0)) $wantShift = $sm[3] ?? null;
        $wantRole = null;
        foreach (['rector' => 'RECTOR', 'coordinador' => 'COORDINATOR', 'psicolog|orientador|psicoorientador|consejer' => 'COUNSELOR',
                  'secretari' => 'SECRETARY', 'porter|celador|vigilante' => 'SECURITY', 'auxiliar' => 'AUXILIARY',
                  'docente|profesor|profe|maestr' => 'TEACHER'] as $pat => $rc)
            if (preg_match('/\b(' . $pat . ')\w*\b/u', $q0)) { $wantRole = $rc; break; }
        $deictic = (bool)preg_match('/\b(ellos|ellas|esos|esas|estos|estas|cual|cuales|quien|quienes|alguno|alguna|ningun|de esos|de ellos)\b/u', $q0);
        if ($deictic && ($wantShift || $wantRole)) {
            $hits = array_values(array_filter($lrPre['items'], function ($it) use ($wantShift, $wantRole) {
                if ($wantShift && nxNorm((string)($it['shift'] ?? '')) !== $wantShift) return false;
                if ($wantRole && ($it['role'] ?? null) !== $wantRole) return false;
                return true;
            }));
            $names = array_map(fn($it) => $it['label'], $hits);
            $lbl = $wantShift ? "la jornada de la {$wantShift}" : 'ese cargo';
            $reply = !$names ? "Ninguno de ellos figura con {$lbl}."
                    : (count($names) === 1 ? "{$names[0]}." : ucfirst("{$lbl}: " . implode(', ', $names) . '.'));
            $noShift = count(array_filter($lrPre['items'], fn($it) => empty($it['shift'])));
            if ($wantShift && $noShift) $reply .= " ({$noShift} no tienen jornada registrada en el sistema.)";
            $out = ['reply'=>$reply,'intent'=>'staff_filter','confidence'=>1.0,'_natural'=>true,
                    'session_id'=>$sessionId];
            $out['_ds'] = $dsPre;
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // ── telemetría por capa (NLU → DSM → auth → dispatch) ─────────────
    $tNlu = microtime(true);
    // (d) comparación anafórica con el tema activo («compáralas con las de
    // hoy», «vs la semana pasada») — no hay par de grupos explícito ni tema
    // nuevo: es la misma métrica en dos períodos. Sintético, sin LLM.
    $cls = null;
    // Un solo rango explícito = comparación ANAFÓRICA («vs la pasada») que
    // toma la métrica del contexto. Dos+ rangos = consulta autocontenida
    // («este mes vs el mes pasado») → la clasifica el parser, que tiene un
    // intent dedicado (attendance_trend) para comparación de períodos.
    if ($dsPre && in_array($dsPre['intent'] ?? '', NX_QUERY_INTENTS, true)
        && preg_match('/\b(compar\w*|versus|\bvs\b|frente a|respecto a|respecto al|contra)\b/u', $q0)
        && !preg_match('/\b\d{1,2}\s*-?\s*[a-z]\s+(y|con|e|vs|versus|contra|frente)\s+\d{1,2}\s*-?\s*[a-z]\b/u', $q0)
        && count(nxTimeRanges($q0)) === 1) {
        $cls = ['intent'=>'count_events','confidence'=>0.92,'entities'=>[],
                'domain'=>'formal','top3'=>[],'source'=>'ctx_compare'];
    }
    $cls = $cls ?? nxClassify($text, $llmCtx);
    $tNlu = microtime(true) - $tNlu;

    // ── Multi-intención: «hola quién eres y quién soy yo», «tardanzas y evasiones del 8A» ──
    // enumeración conjuntiva ≠ composición: «compara 6-A y 7-B» tiene una sola
    // meta (los grupos son UN filtro); el splitter del NLU parte en «y»
    // ingenuamente — rearmar antes de componer pasos.
    $q0chk = nxNorm($text);
    if (!empty($cls['parts']) && count($cls['parts']) > 1
        && (preg_match('/\b(compar|versus| vs |entre)\b/u', $q0chk)
            || preg_match('/\b\d{1,2}[-\s]?[a-z]\s+y\s+\d{1,2}[-\s]?[a-z]\b/u', $q0chk))) {
        unset($cls['parts']);
    }
    if (!empty($cls['parts']) && count($cls['parts']) > 1) {
        // §27 — primero intentar open composition: si TODAS las partes
        // componen planes semánticos, el conjunto es un plan multi-paso
        // (con refs e herencia), no dos intents independientes.
        $dsPre2 = chatLoadDs($conn, $userId, $sessionId);
        $mpSteps = []; $mpOk = true;
        $fieldMap = ['acudiente'=>'acudiente','tutor'=>'acudiente','responsable'=>'acudiente',
            'telefono'=>'celular','celular'=>'celular','whatsapp'=>'celular','numero'=>'celular',
            'documento'=>'documento','cedula'=>'documento','grupo'=>'grupo','jornada'=>'jornada',
            'nombre'=>'nombre','edad'=>'edad','nacimiento'=>'nacimiento'];
        foreach ($cls['parts'] as $pi => $p) {
            $pN = nxNorm($p['text'] ?? '');
            // cláusula referencial («del primero», «de esos»): neutralizar el
            // fragmento para que no contamine la señal del propio paso
            $ref = $pi > 0 ? nxSemRefOf($pN) : null;
            if ($ref) {
                $pN = trim(preg_replace('/\s{2,}/u',' ', preg_replace(
                    '/\b(del|de los|de las|de ese|de esa|de esos|de esas|de cada)\s*(primer[oa]?s?|segund[oa]?s?|tercer[oa]?s?|cuart[oa]?s?|quint[oa]?s?|ultim[oa]s?|penultim[oa]s?|estudiantes?|alumn[oa]s?)?\b/u',
                    ' ', $pN)));
            }
            $pIp = nxDialogueResolve(['intent'=>$p['intent'],'confidence'=>$p['confidence'] ?? 0,'entities'=>$p['entities'] ?? []],
                $dsPre2 ? ['entities'=>$dsPre2['entities'] ?? [],'last_intent'=>$dsPre2['intent'] ?? null,'_ds'=>$dsPre2] : null, $pN);
            // herencia de scope DESDE pasos previos — antes de componer
            if ($pi > 0 && $mpSteps) {
                foreach (['group','module','status','days','range_label','from','to'] as $fk)
                    if (empty($pIp['resolved']['slots'][$fk]))
                        foreach ($mpSteps as $sp)
                            if (!empty($sp['filters'][$fk])) { $pIp['resolved']['slots'][$fk] = $sp['filters'][$fk]; break; }
            }
            // pseudo-plan delegado: «del primero dime el acudiente» es un
            // field-lookup sobre el ítem referenciado, no una posición nueva
            if ($ref) {
                $fld = null;
                foreach ($fieldMap as $w => $fk) if (preg_match('/\b'.$w.'\b/u', $pN)) { $fld = $fk; break; }
                if ($fld) {
                    $pPl = ['capability'=>'guardian.of_student','_delegate_intent'=>'student_field',
                            'entity'=>'students','op'=>'field','filters'=>['field'=>$fld,'student'=>'@ref'],
                            '_ref'=>['step'=>0]+$ref,'conf'=>0.8,'evidence'=>['ref_clause:'.$fld]];
                    $mpSteps[] = $pPl;
                    continue;
                }
            }
            if ($ref) $pIp['resolved']['slots']['student'] = '@ref';
            $pPl = nxSemanticCompose($pN, $pIp['resolved']['intent'], (float)($p['confidence'] ?? 0),
                $pIp['resolved']['slots'], $pIp, $dsPre2, true);
            if (!$pPl) { $mpOk = false; break; }
            if ($ref) $pPl['_ref'] = ['step'=>0] + $ref;
            $pexec = nxCapabilityRegistry()[$pPl['capability']]['exec'] ?? null;
            if ($pexec && str_starts_with($pexec, 'intent:'))
                $pPl['_delegate_intent'] = substr($pexec, 7);
            $mpSteps[] = $pPl;
        }
        if ($mpOk && $mpSteps) {
            $plan = ['capability'=>'composed','entity'=>'composed','steps'=>$mpSteps,
                     'read_only'=>true,'_src'=>'semantic','presentation'=>'multi',
                     'conf'=>min(array_map(fn($x)=>$x['conf'] ?? 0.7, $mpSteps)),
                     'evidence'=>['compound:' . count($mpSteps) . ' parts']];
            [$planOk, $planWhy] = nxPlanValidate($plan);
            if ($planOk && nxPlanAllowed($conn, $authUser, $plan, $role)) {
                $tDisp = microtime(true);
                $out = nxPlanExecute($conn, $authUser, $plan, $vars);
                $tDisp = microtime(true) - $tDisp;
                $out = nxPlanResponse($out, 'composed', 'plan:composed');
                $out['intent'] = 'composed'; $out['confidence'] = $plan['conf'];
                $out['session_id'] = $sessionId;
                $out['_interpretation'] = ['timing_ms'=>['nlu'=>round($tNlu*1000,2),'dispatch'=>round($tDisp*1000,2)],'plan'=>$plan];
                $interp = ['resolved'=>['intent'=>'composed','slots'=>[], 'inherited'=>[]],'turn_type'=>'autonomous'];
                $out['_ds'] = chatBuildDs($interp, $out, $dsPre2);
                chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
                exit(json_encode(['status'=>'ok','data'=>$out]));
            }
        }
        // fallback por partes: cada cláusula pasa por el DSM para que las
        // reglas de cobertura y el downgrade destructivo→security_probe
        // apliquen igual que en la vía principal (una cláusula mutativa
        // nunca se despacha como intent de datos)
        $outs = []; $seenPart = []; $prevPartSlots = null; $prevPartIntent = null;
        $buckets = []; $seq = [];
        foreach ($cls['parts'] as $p) {
            $pN = nxNorm($p['text'] ?? '');
            // segmento repetido («X y X» tras un split por «/» o conector) —
            // una sola respuesta, no dos cards iguales
            if (isset($seenPart[$pN . '|' . ($p['intent'] ?? '')])) continue;
            $seenPart[$pN . '|' . ($p['intent'] ?? '')] = true;
            // contexto rodante: la cláusula previa del MISMO turno es tema
            // activo («analiza mis avisos y dime su prioridad» — la 2ª
            // cláusula hereda el buzón, no el ds del turno anterior)
            $pCtx = $dsPre2 ? ['entities'=>$dsPre2['entities'] ?? [],
                             'last_intent'=>$dsPre2['intent'] ?? null,'_ds'=>$dsPre2] : null;
            if ($prevPartIntent !== null) {
                $pCtx = ['entities'=>array_merge($dsPre2['entities'] ?? [], $prevPartSlots ?? []),
                         'last_intent'=>$prevPartIntent,'_ds'=>$dsPre2];
            }
            $pIp = nxDialogueResolve(
                ['intent'=>$p['intent'],'confidence'=>$p['confidence'] ?? 0,
                 'entities'=>$p['entities'] ?? [],'top3'=>$p['top3'] ?? [],
                 'domain'=>$p['domain'] ?? null],
                $pCtx,
                $pN);
            $pIntent = $pIp['resolved']['intent'];
            if (!empty($pIp['requires_clarification'])) {
                $seq[] = ['out'=>['reply'=>$pIp['clarify'],'intent'=>'clarify']];
                continue;
            }
            if (!chatAllowed($conn, $authUser, $pIntent, $role)) {
                $seq[] = ['out'=>['reply'=>nxSmalltalk('denied',$vars),'intent'=>$pIntent,'denied'=>true]];
                continue;
            }
            // slots por segmento: nxSlots completa module/field/dates que el parser no extrae
            $pslots = array_merge(nxSlots($pN), $pIp['resolved']['slots'] ?? []);
            // parte que solo cambia de MÓDULO («y permisos», «y llegadas
            // tarde») hereda estudiante y rango de la parte anterior
            if ($prevPartSlots && empty($pslots['student']) && empty($pslots['group'])
                && !empty($prevPartSlots['student']) && !empty($pIp['resolved']['inherited']))
                $pslots['student'] = $prevPartSlots['student'];
            if ($prevPartSlots && empty($pslots['from']) && !isset($pslots['days']))
                foreach (['from','to','range_label','days'] as $fk)
                    if (isset($prevPartSlots[$fk])) $pslots[$fk] = $prevPartSlots[$fk];
            // misma consulta con palabras distintas («analiza mis avisos» +
            // «dime su prioridad» → mismo intent+mismos filtros): se
            // agrupa — las flags de presentación de ambas cláusulas se
            // fusionan en un ÚNICO dispatch, nunca dos outs idénticos
            $sig = $pIntent . '|' . json_encode(array_intersect_key($pslots,
                array_flip(['group','module','student','field','days','from','to','status','_mine'])));
            if (!isset($buckets[$sig])) {
                $buckets[$sig] = ['intent'=>$pIntent,'slots'=>$pslots];
                $seq[] = ['sig'=>$sig];
            } else {
                $buckets[$sig]['slots'] += array_filter($pslots,
                    fn($v) => $v !== null && $v !== '' && $v !== false);
            }
            $prevPartSlots = $pslots;
            $prevPartIntent = $pIntent;
        }
        foreach ($seq as $e) {
            $o = isset($e['out']) ? $e['out']
                : chatDispatch($conn, $authUser, $buckets[$e['sig']]['intent'], $buckets[$e['sig']]['slots'], $vars, $role);
            $o += ['intent' => $e['out']['intent'] ?? $buckets[$e['sig']]['intent'] ?? null];
            $outs[] = $o;
        }
        $out = [
            'reply' => implode("\n\n—\n\n", array_column($outs,'reply')),
            'cards' => array_merge(...array_map(fn($o)=>$o['cards']??[], $outs)) ?: null,
            'actions' => array_merge(...array_map(fn($o)=>$o['actions']??[], $outs)) ?: null,
            'intent' => implode('+', array_column($outs,'intent')),
            'confidence' => min(array_column($cls['parts'],'confidence')),
            'session_id' => $sessionId,
        ];
        // _ds: el estado lo define la última parte resuelta (interp propia)
        $allEnt = [];
        foreach ($outs as $o) $allEnt = array_merge($allEnt, $o['entities'] ?? []);
        if ($allEnt) $out['entities'] = $allEnt;
        $out['_ds'] = chatBuildDs(
            $pIp ?? ['resolved'=>['slots'=>[]],'turn_type'=>'autonomous'],
            $out, $dsPre2);
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }

    // ── Compuerta de seguridad generalista (antes de DSM/SCP/dispatch) ──
    // Dos detectores independientes: flag `safety` del parser LLM +
    // backstop determinista. Ninguno es un intent de tema — es un flag
    // transversal que bloquea con una respuesta seria fija.
    if (($cls['safety'] ?? 'ok') === 'risky' || nxSafetyScreen($q0)
        || (!empty($cls['parts']) && array_filter($cls['parts'], fn($p) => ($p['safety'] ?? 'ok') === 'risky'))) {
        securityLog('CHAT_SAFETY_GUARD', mb_substr($q0,0,200) . ' | user ' . $userId);
        $out = ['reply'=>'Eso no es algo en lo que pueda ayudarte. Si hay una situación que te preocupa, los canales y protocolos de la institución son el camino — y si necesitas reportar algo, coordinación está para eso.',
                'intent'=>'safety_guard','confidence'=>1.0,'session_id'=>$sessionId,'denied'=>true];
        if ($dsPre) $out['_ds'] = $dsPre;  // el bloqueo no borra el contexto
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
    // fallback de compatibilidad: el ctx del cliente solo aporta entidades
    // visibles — jamás operaciones pendientes, intents ni result-sets
    // (esos nacen del estado server-side; el cliente no los puede fabricar)
    $clientCtx = null;
    if (!$ds && is_array($input['ctx'] ?? null)) {
        $ce = is_array($input['ctx']['entities'] ?? null) ? $input['ctx']['entities'] : [];
        unset($ce['_op'], $ce['_ds']);
        $clientCtx = ['entities' => $ce];
    }
    $ctx = $ds ? ['entities' => $ds['entities'] ?? [], 'last_intent' => $ds['intent'] ?? null, '_ds' => $ds]
               : $clientCtx;
    if (is_array($ctx) && !empty($ctx['last_reply'])) $vars['_last_reply'] = $ctx['last_reply'];
    $tDsm = microtime(true);
    $interp = nxDialogueResolve($cls, is_array($ctx) ? $ctx : null, $q0);
    $tDsm = microtime(true) - $tDsm;
    $intent = $interp['resolved']['intent'];
    $slots  = $interp['resolved']['slots'];
    $slots['_q'] = $q0;
    $interp['resolved']['slots']['_q'] = $q0;
    if (!empty($interp['resolved']['inherited'])) $slots['_inherited'] = $interp['resolved']['inherited'];

    // Rescate de dominio: «sensores del colegio», «los nodos», «anomalías»
    // caen a out_of_scope en el parser pese a ser datos consultables — un
    // sustantivo inequívoco del dominio reencauza al intent correcto antes
    // de que el canal informal improvise una respuesta.
    if ($intent === 'out_of_scope' && ($resc = nxDomainRescue($q0))) {
        $intent = $resc;
    }

    // ── verbo de operación + op del parser → ES una operación, no una
    // consulta de campo («cita al acudiente de X» cae a student_field por
    // la entidad acudiente). El intent se corrige antes de plan/ejecución.
    if (!empty($slots['_op'])
        && in_array($intent, ['student_field','student_summary','list_events','citations','trackings','permissions','count_events'], true)
        && preg_match('/\b(cita\w*|citamos|convoca\w*|convoc\w*|deriva\w*|genera\w*|tramit\w*|autoriza\w*|reporta\w*|registra\w*|agenda\w*|llama\w* a citaci\w*)\b/u', $q0)) {
        $intent = 'derive_action';
        $interp['resolved']['intent'] = $intent;
        $interp['resolved']['slots']  = $slots;
    }

    // ── SCP — Semantic Conversational Parsing ──
    // Interpretar → validar → planificar → ejecutar. El frame normaliza el
    // significado del turno contra el estado conversacional; NO ejecuta ni
    // inventa datos. Salidas: (a) correcciones que actualizan el contexto,
    // (b) intent+slots refinados cuando el frame tiene soporte estructural,
    // (c) plan especializado (compare) que pasa por validate+allowed igual
    // que cualquier otro plan. RBAC intacto: sigue en nxPlanAllowed/chatAllowed.
    $scpFrame = null; $scpPlan = null; $scpForced = false;
    try {
        $scpFrame = nxScpFrame($q0, $cls, $interp, $ds);
        [$scpOk, $scpWhy] = nxScpValidate($scpFrame);
        nxScpTrace('FRAME', ['task'=>$scpFrame['task'],'domain'=>$scpFrame['domain'],
            'subject'=>$scpFrame['subject'],'filters'=>$scpFrame['filters'],
            'scope'=>$scpFrame['scope'],'time'=>$scpFrame['time_range'],
            'ranking'=>$scpFrame['ranking'],'corrections'=>$scpFrame['corrections'],
            'targets'=>$scpFrame['targets'],'conf'=>$scpFrame['confidence'],
            'valid'=>$scpOk,'why'=>$scpWhy]);
        if ($scpOk) {
            // el frame viaja en la interpretación: el ds registra la
            // última corrección del usuario y su tarea normalizada (§4/§10)
            if (!empty($scpFrame['corrections']))
                $interp['resolved']['corrections'] = array_map(
                    fn($cc) => $cc['kind'] ?? null, $scpFrame['corrections']);
            $interp['resolved']['scp_task'] = $scpFrame['task'];
            // correcciones primero: actualizan el plan/contexto activo
            $corr = chatScpCorrections($conn, $authUser, $scpFrame, $ds, $interp,
                $vars, $role, $sessionId, $q0, $conf);
            if ($corr !== null) {
                $corr['_ds'] = $corr['_ds'] ?? chatBuildDs($interp, $corr, $ds);
                $corr['_interpretation'] = ['turn_type'=>$interp['turn_type'],
                    'nlu_intent'=>$cls['intent'], 'scp'=>['task'=>$scpFrame['task'],
                    'timing_ms'=>['nlu'=>round($tNlu*1000,2),'dsm'=>round($tDsm*1000,2)]],
                    'inherited'=>$interp['resolved']['inherited']];
                chatLog($conn, $schoolId, $userId, $text, $corr, $sessionId);
                exit(json_encode(['status'=>'ok','data'=>$corr]));
            }
            [$cIntent, $cSlots, $cForced] = nxScpToSlots($scpFrame);
            if ($cForced && $cIntent) {
                // un frame de relación/consulta («acudiente de X» →
                // student_field; «exporta tardanzas» → incidents.list) NO
                // degrada una operación, exportación o consulta dedicada que
                // el DSM ya resolvió por sustantivo/verbo («cita al
                // acudiente», «exporta…», «las citaciones de X»). Los slots
                // sí se fusionan — sujetan el objetivo de la operación.
                // intents dedicados del modelo de datos — el frame SCP ve
                // «en riesgo» y propone risk_students genérico, pero la
                // interrogativa dedicada (motivo/emisor/resolución) ya fue
                // resuelta por regla inequívoca: no se degrada
                $opResolved = in_array($intent, ['derive_action','start_operation','export_data',
                    'permissions','citations','trackings','frequency_table',
                    'groups_list','attendance_ranking','pending_returns',
                    'risk_reason','incident_excuses','exit_detail','trip_info',
                    'school_calendar','staff_contact','teacher_schedule','student_consent',
                    'tracking_detail','citations_by','alert_resolution','enrollment_stats',
                    'reports_log','sos_detail','guardian_messages','device_detail',
                    'attendance_trend'], true)
                    && $cIntent !== $intent;
                // downgrade = el frame propone un intent GENÉRICO (lista/
                // conteo/ficha) sobre un intent dedicado ya resuelto. Si el
                // frame también propone dedicado, se permite el override.
                $downgradeToQuery = !in_array($cIntent, ['derive_action','start_operation',
                    'export_data','permissions','citations','trackings','frequency_table',
                    'groups_list','attendance_ranking','pending_returns',
                    'risk_reason','incident_excuses','exit_detail','trip_info',
                    'school_calendar','staff_contact','teacher_schedule','student_consent',
                    'tracking_detail','citations_by','alert_resolution','enrollment_stats',
                    'reports_log','sos_detail','guardian_messages','device_detail',
                    'attendance_trend'], true);
                $slots = array_merge($slots, $cSlots);
                if (!empty($scpFrame['subject']['name']) && in_array($scpFrame['task'], ['relation','count'], true))
                    $slots['student'] = $scpFrame['subject']['name'];
                // la referencia materializada por el frame es de ENTIDAD —
                // un nav posicional («la primera», «el último») aún debe
                // resolver el ítem del set activo: no se descarta aquí
                if ($scpFrame['task'] === 'relation' && !empty($slots['_nav'])
                    && !(preg_match('/^(nth:\d+|first)$/', (string)$slots['_nav'])
                         && !empty($ds['last_result']['items'])))
                    unset($slots['_nav']);
                if (!($opResolved && $downgradeToQuery)) $intent = $cIntent;
                $interp['resolved']['intent'] = $intent;
                $interp['resolved']['slots'] = $slots;
                $interp['requires_clarification'] = false;
                $scpForced = true; // el frame decidió — el compose clásico no lo pisa
                nxScpTrace('TRANSLATED', ['intent'=>$intent,'slots'=>$cSlots,'task'=>$scpFrame['task'],
                    'op_kept'=>$opResolved && $downgradeToQuery]);
            }
            $scpPlan = nxScpToPlan($scpFrame);
        } else {
            nxScpTrace('INVALID', ['why'=>$scpWhy,'task'=>$scpFrame['task']]);
        }
    } catch (Throwable $scpE) {
        nxScpTrace('ERROR', ['msg'=>$scpE->getMessage()]); // SCP nunca rompe el turno
    }

    // ── «mi grupo» (*mine*) → scope real del usuario (§9-10) ────────────
    // 1 grupo → ese; varios → aclaración explícita; ninguno → fallo honesto.
    if (($slots['group'] ?? null) === '*mine*') {
        $st = $conn->prepare("SELECT ag.group_name FROM teacher_group_access tga
            JOIN academic_groups ag ON ag.group_id=tga.group_id
            WHERE tga.teacher_user_id=? AND ag.school_id=? ORDER BY ag.group_name");
        $st->execute([$userId, $schoolId]);
        $mine = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'group_name');
        if (count($mine) === 1) {
            $slots['group'] = $mine[0];
        } elseif (count($mine) > 1) {
            $out = ['reply'=>"Tienes " . count($mine) . " grupos asignados: " . implode(', ', $mine)
                    . ". ¿De cuál hablas?", 'intent'=>'clarify','confidence'=>$conf,
                    'session_id'=>$sessionId,'_interpretation'=>$out['_interpretation'] ?? null];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        } else {
            $out = ['reply'=>"No tienes grupos asignados en el sistema todavía — eso lo gestiona coordinación.",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // ── nav + campo relacional: «el acudiente del primero» ──────────────
    // La posición materializa el ÍTEM del set; el campo corre sobre él
    // (relación target=guardian), nunca una consulta nueva de «grado 1».
    if (!empty($slots['_nav']) && !empty($slots['field'])
        && preg_match('/^(nth:\d+|first)$/', (string)$slots['_nav'])
        && $ds && !empty($ds['last_result']['items'])) {
        $idx = $slots['_nav'] === 'first' ? 0 : max(0, (int)substr($slots['_nav'], 4) - 1);
        $it = $ds['last_result']['items'][$idx] ?? null;
        if ($it) {
            $slots['student'] = $it['label'];
            $slots['_ref'] = $slots['_ref'] ?? 'guardian';
            unset($slots['_nav']);
            // con verbo/_op de operación («cita al acudiente del último») el
            // ítem materializa el SUJETO de la operación, no una consulta.
            // El verbo del texto decide aunque parser/DSM no hayan marcado op
            $keepOp = in_array($intent, ['derive_action','start_operation'], true)
                || !empty($slots['_op'])
                || preg_match('/\b(cita(?:r|mos|n|te|me|lo|la)?|citamos|convoca\w*|deriva\w*|genera\w*|reporta\w*|registra\w*|autoriza\w*|tramit\w*)\b/u', $q0);
            $intent = $keepOp
                ? ($intent === 'start_operation' ? 'start_operation' : 'derive_action')
                : 'student_field';
            $interp['resolved']['intent'] = $intent;
            $interp['resolved']['slots'] = $slots;
            $interp['requires_clarification'] = false;
        }
    }

    // ── «todos» sobre una vista recortada (slice): pide el UNIVERSO, no
    // la vista — re-ejecutar la colección original con sus filtros (§J)
    if (($slots['_nav'] ?? null) === 'all' && $ds
        && preg_match('/primeros|últimos|ultimos|slice/i', (string)($ds['last_result']['label'] ?? ''))) {
        $orig = null;
        foreach (array_reverse($ds['objects'] ?? []) as $obj)
            if (!empty($obj['filters'])) { $orig = $obj; break; }
        if ($orig && ($orig['type'] ?? '') === 'students' && !empty($orig['filters']['group'])) {
            $slots = array_intersect_key($slots, array_flip(['_inherited']));
            $slots['group'] = $orig['filters']['group'];
            $intent = 'students_in_group';
            $interp['resolved']['intent'] = $intent;
            $interp['resolved']['slots'] = $slots;
            $interp['requires_clarification'] = false;
        }
    }
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
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }

    // «repite» llegó por parser (intent repeat) sin que el fast-path lo
    // atrapara — misma reemisión de la última respuesta del asistente
    if ($intent === 'repeat') {
        $lastP = chatLastPayload($conn, $userId, $sessionId);
        $rp = trim((string)($lastP['reply'] ?? ''));
        $out = $rp !== ''
            ? ['reply'=>$rp,'cards'=>$lastP['cards'] ?? null,'actions'=>$lastP['actions'] ?? null,
               'intent'=>'repeat_op','confidence'=>1.0,'_natural'=>true,'session_id'=>$sessionId]
            : ['reply'=>'Todavía no te he dicho nada en esta conversación — ¿qué quieres saber?',
               'intent'=>'repeat_op','confidence'=>1.0,'_natural'=>true,'session_id'=>$sessionId];
        $out['_ds'] = $ds ?: null;
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
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
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
    }
    if ($interp['turn_type'] === 'cancel') {
        $out = ['reply'=>'Cancelado — no quedó registrada ninguna operación.',
                'intent'=>'cancel','confidence'=>$conf,'session_id'=>$sessionId,
                'entities'=>[], '_interpretation'=>$out['_interpretation']];
        // cancelar limpia la operación pendiente pero conserva el tema activo
        $out['_ds'] = $ds ?: null;
        if ($out['_ds']) unset($out['_ds']['entities']['_op'], $out['_ds']['pending_op']);
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
                    $out['_ds'] = chatBuildDs($interp, $out, $ds);
                    chatLog($conn,$schoolId,$userId,$text,$out,$sessionId);
                    exit(json_encode(['status'=>'ok','data'=>$out])); }
            }
            $nm = $student ? " para {$student['first_name']} {$student['last_name']}" : '';
            $out = ['reply'=>"Otra «{$pendingOp}»{$nm} — te abro el formulario.",
                    'actions'=>[chatActionChip($pendingOp,'Continuar → '.$pendingOp,$student)],
                    'intent'=>'repeat_op','confidence'=>$conf,'session_id'=>$sessionId,
                    'entities'=>['_op'=>$pendingOp],'_interpretation'=>$out['_interpretation']];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
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
    // no requiere nuevo intent ni parser.
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
        || (preg_match('/\b(?:los|las)\s+(\d+|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(primer[oa]?s?|ultim[oa]s?)\b/u', $q0)
            // con grupo/ámbito explícito = consulta nueva; sin él = transform
            // del set activo («dame los tres últimos» después de ordenar)
            && preg_match('/\b(?:de|del|en|grupo|salon)\s+[\da-z]/u', $q0));
    // position emitido por el parser sin nav → nav posicional, usando el
    // conteo del set activo para resolver «último»
    if (empty($slots['_nav']) && !empty($slots['position'])
        && $ds && !empty($ds['last_result']['items'])) {
        $nPos = count($ds['last_result']['items']);
        $p = $slots['position'];
        $slots['_nav'] = 'nth:' . ($p === 'last' ? $nPos : max(1, (int)$p));
    }
    // «el segundo de la lista (que me diste)» — deíctico posicional sobre el
    // set activo aunque el parser no haya emitido nav/position
    if (empty($slots['_nav']) && empty($slots['position'])
        && $ds && !empty($ds['last_result']['items'])
        && preg_match('/\b(primer[oa]|segund[oa]|tercer[oa]|cuart[oa]|quint[oa]|últim[oa]|ultim[oa])\b[^.!?]{0,20}\b(lista|tabla|resultados?|tanda)\b/u', $q0, $om)) {
        $ord = ['primero'=>1,'primera'=>1,'primer'=>1,'segundo'=>2,'segunda'=>2,
                'tercero'=>3,'tercera'=>3,'tercer'=>3,'cuarto'=>4,'cuarta'=>4,'quinto'=>5,'quinta'=>5];
        $w = mb_strtolower($om[1]);
        $slots['position'] = in_array($w, ['último','última','ultimo','ultima'], true) ? 'last' : ($ord[$w] ?? 1);
        $slots['_nav'] = 'nth:' . ($slots['position'] === 'last'
            ? count($ds['last_result']['items']) : (int)$slots['position']);
    }
    // ordinal PRONOMINAL: «de la primera», «del segundo», «la tercera» — el
    // ordinal ES la referencia (sin sustantivo de lista explícito). Excluye
    // ordinales que modifican un sustantivo de dominio («primera tardanza»,
    // «segundo puesto», «último día» = consulta nueva, no nav).
    if (empty($slots['_nav']) && empty($slots['position'])
        && $ds && !empty($ds['last_result']['items'])
        && preg_match('/\b(?:del|de la|de los|de las|el|la|los|las)\s+(primer[oa]s?|segund[oa]s?|tercer[oa]s?|cuart[oa]s?|quint[oa]s?|últim[oa]s?|ultim[oa]s?|penúltim[oa]s?|penultim[oa]s?)\b(?!\s*(?:tardanza|inasistencia|evasi[oó]n|evento|incidente|falta|llegada|nota|semana|d[ií]a|mes|vez|parte|mitad|puesto|lugar|grado|hora|clase|periodo|per[ií]odo|quincena|año|anio)\b)/u', $q0, $om)) {
        $ord = ['primero'=>1,'primera'=>1,'primer'=>1,'segundo'=>2,'segunda'=>2,
                'tercero'=>3,'tercera'=>3,'tercer'=>3,'cuarto'=>4,'cuarta'=>4,
                'quinto'=>5,'quinta'=>5,'primeros'=>1,'primeras'=>1];
        $w = mb_strtolower($om[1]);
        $slots['position'] = preg_match('/ltim/u', $w) ? 'last' : ($ord[$w] ?? 1);
        $slots['_nav'] = 'nth:' . ($slots['position'] === 'last'
            ? count($ds['last_result']['items']) : (int)$slots['position']);
    }

    // ── «lo mismo pero con el último» / «para el segundo de la lista» ────
    // Nav posicional sin campo propio pero con intent de estudiante heredado:
    // el nav materializa el SUJETO y se re-ejecuta la consulta previa sobre
    // él — no se responde solo el nombre del ítem.
    if (!empty($slots['_nav']) && $ds && !empty($ds['last_result']['items'])
        && preg_match('/^(nth:\d+|first)$/', (string)$slots['_nav'])
        && (empty($slots['student'])
            || in_array('student', $slots['_inherited'] ?? [], true)
            || (isset($ds['entities']['student']) && $slots['student'] === $ds['entities']['student']))
        && !$navGroupClash && !$navEventVerb
        && (in_array($intent, ['student_field','student_summary','derive_action','start_operation'], true)
            || in_array($ds['intent'] ?? '', ['student_field','student_summary','derive_action','start_operation'], true))) {
        $idx = $slots['_nav'] === 'first' ? 0 : max(0, (int)substr($slots['_nav'], 4) - 1);
        $it = $ds['last_result']['items'][$idx] ?? null;
        if ($it && !empty($it['label'])) {
            $slots['student'] = $it['label'];
            foreach (['field','module'] as $k)
                if (empty($slots[$k]) && !empty($ds['entities'][$k])) $slots[$k] = $ds['entities'][$k];
            if (empty($slots['_op']) && !empty($ds['entities']['_op'])) $slots['_op'] = $ds['entities']['_op'];
            unset($slots['_nav']);
            // el intent a re-ejecutar: el propio si ya es de sujeto (derive/
            // field), si no el heredado del turno previo («lo mismo»)
            $intent = in_array($intent, ['student_field','student_summary','derive_action','start_operation'], true)
                ? $intent : $ds['intent'];
            $interp['resolved']['intent'] = $intent;
            $interp['resolved']['slots']  = $slots;
            $interp['requires_clarification'] = false;
        }
    }

    // «el acudiente/documento del primero/último» — la posición resuelve el
    // SUJETO; la relación pide un student_field sobre él, no el ítem a secas
    if (!empty($slots['_nav']) && $ds && !empty($ds['last_result']['items'])
        && preg_match('/^(nth:\d+|first|last)$/', (string)$slots['_nav'])
        && !in_array($ds['last_result']['type'] ?? '', ['staff'], true)
        && !$navGroupClash && !$navEventVerb
        && preg_match('/\b(acudiente|tutor|tutora|responsable|representante|documento|cedula|celular|telefono|jornada|grupo|salon|nacimiento|cumpleanos|riesgo|seguimiento|faltas|edad)\b/u', $q0)) {
        $nav0 = (string)$slots['_nav'];
        $nIt = count($ds['last_result']['items']);
        $idx = $nav0 === 'first' ? 0
             : ($nav0 === 'last' ? $nIt - 1 : max(0, (int)substr($nav0, 4) - 1));
        $it = $ds['last_result']['items'][$idx] ?? null;
        if ($it && !empty($it['label'])) {
            $slots['student'] = $it['label'];
            // «el documento DEL ACUDIENTE» — el campo es del guardian, no del
            // estudiante («documento del primero» sí es del estudiante)
            if (preg_match('/\b(documento|cedula|celular|telefono|numero|whatsapp|nombre|contacto)\s+(?:de|del|de la|de su)\s+(?:su |el |la )?(?:acudiente|acudientes|tutor|tutora|responsable|representante|papa|mama|padre|madre|familiar|encargad[oa])\b/u', $q0, $mg2))
                $slots['field'] = preg_match('/documento|cedula/', $mg2[1]) ? 'documento_acudiente'
                                : (preg_match('/nombre/', $mg2[1]) ? 'nombre_acudiente' : 'celular_acudiente');
            elseif (preg_match('/\b(acudiente|tutor|tutora|responsable|representante|papa|mama|padre|madre|familiar|encargad[oa])\b/u', $q0))
                $slots['field'] = 'acudiente';
            else {
                static $relFld = ['documento'=>'documento','cedula'=>'documento','celular'=>'celular',
                    'telefono'=>'celular','jornada'=>'jornada','grupo'=>'grupo','salon'=>'grupo',
                    'nacimiento'=>'nacimiento','cumpleanos'=>'nacimiento','riesgo'=>'resumen',
                    'seguimiento'=>'resumen','faltas'=>'resumen','edad'=>'nacimiento'];
                foreach ($relFld as $w => $fl)
                    if (preg_match('/\b' . $w . '\b/u', $q0)) { $slots['field'] = $fl; break; }
                $slots['field'] = $slots['field'] ?? 'resumen';
            }
            unset($slots['_nav'], $slots['position']);
            $intent = 'student_field';
            $interp['resolved']['intent'] = $intent;
            $interp['resolved']['slots']  = $slots;
            $interp['requires_clarification'] = false;
        }
    }

    // «el motivo/la razón del primero/segundo» — la posición resuelve el
    // SUJETO; la interrogativa causal pide risk_reason sobre él, no el
    // ítem a secas (caso real: «quiénes están en riesgo» → «el motivo de
    // la primera»). Solo cuando el set es de personas — en sets de
    // eventos el label no resuelve estudiante.
    if (!empty($slots['_nav']) && $ds && !empty($ds['last_result']['items'])
        && preg_match('/^(nth:\d+|first|last)$/', (string)$slots['_nav'])
        && in_array($ds['last_result']['type'] ?? '', ['students','risk','alerts','trackings'], true)
        && preg_match('/\b(motivo|motivos|razon|razones|causa|causas|por ?que|porque|detonante)\b/u', $q0)
        && !$navGroupClash && !$navEventVerb) {
        $nav0 = (string)$slots['_nav'];
        $nIt = count($ds['last_result']['items']);
        $idx = $nav0 === 'first' ? 0 : ($nav0 === 'last' ? $nIt - 1 : max(0, (int)substr($nav0, 4) - 1));
        $it = $ds['last_result']['items'][$idx] ?? null;
        if ($it && !empty($it['label'])) {
            $slots['student'] = $it['label'];
            unset($slots['_nav'], $slots['position']);
            $intent = 'risk_reason';
            $interp['resolved']['intent'] = $intent;
            $interp['resolved']['slots']  = $slots;
            $interp['requires_clarification'] = false;
        }
    }

    if (!empty($slots['_nav']) && $ds && isset($ds['last_result']) && !$navGroupClash && !$navEventVerb) {
        // «tabla de esos datos / todos» tras un escalar (count/resumen): el
        // set guardado es de OTRA consulta — el referente real es la oferta
        // del turno («¿ver el detalle?» → list_events con los mismos filtros)
        if (in_array($slots['_nav'], ['table','all'], true)
            && !empty($ds['offer']['intent'])
            && ($ds['last_result']['_intent'] ?? null) !== ($ds['intent'] ?? null)
            && in_array($ds['offer']['intent'], NX_QUERY_INTENTS, true)
            && chatAllowed($conn, $authUser, $ds['offer']['intent'], $role)) {
            // el offer manda: sus slots son el filtro exacto de la consulta
            // original — del DS solo se rellenan huecos temporales/módulo
            // (un student o grupo heredado de turnos atrás NO debe colarse)
            $oSlots = $ds['offer']['slots'] ?? [];
            foreach (['module','from','to','days','range_label'] as $k)
                if (empty($oSlots[$k]) && !empty($ds['entities'][$k])) $oSlots[$k] = $ds['entities'][$k];
            $out = chatDispatch($conn, $authUser, $ds['offer']['intent'], $oSlots, $vars, $role);
            $out['intent'] = $ds['offer']['intent'];
            $interp['resolved']['intent'] = $ds['offer']['intent'];
            $interp['resolved']['slots'] = $oSlots;
            $out['session_id'] = $sessionId;
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
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
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        if (count($found) > 1) {
            $opts = implode(', ', array_map(
                fn($r) => trim($r['first_name'] . ' ' . $r['last_name']) . ' (' . ($r['group_name'] ?? 'sin grupo') . ')',
                array_slice($found, 0, 3)));
            $out = ['reply'=>"Hay varios estudiantes llamados {$slots['student']}: $opts. ¿A cuál te refieres?",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
        $grp = $found[0]['group_name'] ?? null;
        if (!$grp) {
            $out = ['reply'=>"{$found[0]['first_name']} {$found[0]['last_name']} no tiene grupo asignado actualmente.",
                    'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
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
    // ── open composition (§6/§14/§27): «A y B», «A y del primero B» ──
    // cada cláusula compone su propio plan; las referencias posicionales
    // («del primero», «de esos») enlazan el paso al result-set anterior.
    $plan = null;
    $clauses = nxSemSplitCompound($q0);
    $scpChatter = []; $scpPendingTargets = [];
    if (count($clauses) > 1) {
        $steps = []; $okAll = true;
        $fieldMap = ['acudiente'=>'acudiente','tutor'=>'acudiente','responsable'=>'acudiente',
            'telefono'=>'celular','celular'=>'celular','whatsapp'=>'celular','numero'=>'celular',
            'documento'=>'documento','cedula'=>'documento','grupo'=>'grupo','jornada'=>'jornada',
            'nombre'=>'nombre','edad'=>'edad','nacimiento'=>'nacimiento'];
        // cláusulas de conversación general (chiste/saludo) — se resuelven
        // inline; NO rompen la composición del resto (objetivos múltiples)
        $chatterIntents = ['greeting','greeting_time','joke','fun_fact','thanks','wellbeing',
            'wellbeing_reply','compliment','motivation','human_check','about_nexus'];
        foreach ($clauses as $ci => $clause) {
            $cN = nxNorm($clause);
            $ref = $ci > 0 ? nxSemRefOf($cN) : null;
            if ($ref) {
                $cN = trim(preg_replace('/\s{2,}/u',' ', preg_replace(
                    '/\b(del|de los|de las|de ese|de esa|de esos|de esas|de cada)\s*(primer[oa]?s?|segund[oa]?s?|tercer[oa]?s?|cuart[oa]?s?|quint[oa]?s?|ultim[oa]s?|penultim[oa]s?|estudiantes?|alumn[oa]s?)?\b/u',
                    ' ', $cN)));
            }
            $cCls = nxClassify($cN);
            $cIp = nxDialogueResolve($cCls, is_array($ctx) ? $ctx : null, $cN);
            // objetivo de conversación general → respuesta inline, la
            // cláusula de datos sigue componiendo (caso L: chiste + tabla)
            if (in_array($cIp['resolved']['intent'], $chatterIntents, true) && !$ref) {
                $sr = chatDispatch($conn, $authUser, $cIp['resolved']['intent'], [], $vars, $role);
                $scpChatter[] = $sr['reply'] ?? '';
                continue;
            }
            if ($ci > 0 && $steps) {
                foreach (['group','module','status','days','range_label','from','to'] as $fk)
                    if (empty($cIp['resolved']['slots'][$fk]))
                        foreach ($steps as $sp)
                            if (!empty($sp['filters'][$fk])) { $cIp['resolved']['slots'][$fk] = $sp['filters'][$fk]; break; }
            }
            if ($ref) {
                $fld = null;
                foreach ($fieldMap as $w => $fk) if (preg_match('/\b'.$w.'\b/u', $cN)) { $fld = $fk; break; }
                if ($fld) {
                    $steps[] = ['capability'=>'guardian.of_student','_delegate_intent'=>'student_field',
                        'entity'=>'students','op'=>'field','filters'=>['field'=>$fld,'student'=>'@ref'],
                        '_ref'=>['step'=>0]+$ref,'conf'=>0.8,'evidence'=>['ref_clause:'.$fld]];
                    continue;
                }
            }
            if ($ref) $cIp['resolved']['slots']['student'] = '@ref';
            $cPl = nxSemanticCompose($cN, $cIp['resolved']['intent'],
                (float)($cCls['confidence'] ?? 0), $cIp['resolved']['slots'], $cIp, $ds, true);
            if (!$cPl) {
                // cláusula no componible → queda PENDIENTE («te faltó lo
                // otro» la recupera); no mata las demás cláusulas
                $scpPendingTargets[] = ['intent'=>$cIp['resolved']['intent'],
                    'slots'=>$cIp['resolved']['slots'], 'text'=>$cN, 'delivered'=>false];
                $okAll = false;
                continue;
            }
            if ($ref) $cPl['_ref'] = ['step'=>0] + $ref;
            $cexec = nxCapabilityRegistry()[$cPl['capability']]['exec'] ?? null;
            if ($cexec && str_starts_with($cexec, 'intent:'))
                $cPl['_delegate_intent'] = substr($cexec, 7);
            $steps[] = $cPl;
        }
        if ($steps)
            $plan = ['capability'=>'composed','entity'=>'composed','steps'=>$steps,
                     'read_only'=>true,'_src'=>'semantic','presentation'=>'multi',
                     'conf'=>min(array_map(fn($x)=>$x['conf'] ?? 0.7, $steps)),
                     'evidence'=>['compound:' . count($steps) . ' clauses']];
    }
    // plan SCP especializado (compare con métrica) cuando la composición
    // clásica no produjo nada — mismo contrato: validate + allowed.
    // EXCEPTO rutas chat-nativas (export_data/derive_action/start_operation
    // y las consultas con handler dedicado — permissions/citations/
    // trackings/groups_list/attendance_ranking): el registro semántico no
    // tiene capability para ellas y degradaría la petición a una lista
    // genérica («exporta tardanzas» → incidents.list, «citaciones de X» →
    // incidents.list sin filas reales de citación).
    $chatNative = in_array($intent, ['export_data','derive_action','start_operation',
        'permissions','citations','trackings','groups_list','attendance_ranking',
        'pending_returns',
        // conteo/lista con slots estructurales propios (_compare_ranges,
        // justified, _my_scope): el compose lee la señal «vs» y los degrada
        // a groups.rank — su capability solo delega de vuelta al handler
        'count_events','list_events',
        // intents derivados del modelo de datos — handler dedicado, nunca
        // degradados a plan genérico por el compositor semántico
        'risk_reason','incident_excuses','exit_detail','trip_info',
        'school_calendar','staff_contact','teacher_schedule','student_consent',
        'tracking_detail','citations_by','alert_resolution','enrollment_stats',
        'reports_log','sos_detail','guardian_messages','device_detail',
        'attendance_trend'], true);
    if (!$plan && $scpPlan && !$chatNative) { $plan = $scpPlan; }
    // cuando el frame decidió (rank/filter/count/relation), el compose
    // clásico no lo pisa: el significado normalizado tiene prioridad
    if (!$plan && !$scpForced && !$chatNative)
        $plan = nxSemanticCompose($q0, $intent, (float)$conf, $slots, $interp, $ds);
    // cláusulas de conversación general pendientes sin plan de datos:
    // responderlas igual (un chiste solo no debe caer a out_of_scope)
    if (!$plan && $scpChatter) {
        $out = ['reply'=>implode("\n\n", $scpChatter), 'intent'=>'composed_chat',
                'confidence'=>$conf, 'session_id'=>$sessionId,
                'entities'=>$scpPendingTargets ? ['_pending_targets'=>$scpPendingTargets] : []];
        $out['_ds'] = chatBuildDs($interp, $out, $ds);
        $out['_interpretation'] = ['turn_type'=>$interp['turn_type'],'nlu_intent'=>$cls['intent'],
            'scp'=>['task'=>$scpFrame['task'] ?? null,'chatter'=>count($scpChatter)],
            'inherited'=>$interp['resolved']['inherited'] ?? []];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        exit(json_encode(['status'=>'ok','data'=>$out]));
    }
    if ($plan) {
        $plan['_ctx_person'] = $ds['person'] ?? null;
        // §20 — validación estructural antes de autorizar/ejecutar
        [$planOk, $planWhy] = nxPlanValidate($plan);
        if (!$planOk) {
            $out = nxPlanFailure($planWhy, $plan, $vars);
            $out['session_id'] = $sessionId; $out['confidence'] = $conf;
            $out['_interpretation'] = $out['_interpretation'] ?? [];
            $out['_ds'] = chatBuildDs($interp, $out, $ds);
            chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
            exit(json_encode(['status'=>'ok','data'=>$out]));
        }
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
        // §8/§13 — contrato post-ejecución: un resultado que no conserva
        // lo pedido NO se presenta como correcto — se bloquea honesto
        [$resOk, $resWhy] = nxResultValidate($plan, $out);
        if (!$resOk) {
            nxScpTrace('RESULT_MISMATCH', ['why'=>$resWhy,'cap'=>$plan['capability']]);
            $out = nxPlanFailure($resWhy, $plan, $vars);
        }
        $out = nxPlanResponse($out, $plan['capability'], 'plan:' . $plan['capability']);
        $out['intent'] = $plan['capability'];
        $out['confidence'] = $conf;
        $out['session_id'] = $sessionId;
        // objetivos de conversación general del turno compuesto: sus
        // respuestas acompañan al resultado de datos (caso L)
        if ($scpChatter)
            $out['reply'] = implode("\n\n", $scpChatter) . "\n\n—\n\n" . ($out['reply'] ?? '');
        $out['entities'] = array_merge($slots, $out['entities'] ?? []);
        // objetivos que quedaron sin componer → pendientes («te faltó lo
        // otro» los recupera en el siguiente turno)
        if ($scpPendingTargets)
            $out['entities']['_pending_targets'] = $scpPendingTargets;
        $out['_ds'] = chatBuildDs($interp, $out, $ds);
        if (isset($out['_interpretation']['timing_ms']))
            $out['_interpretation']['timing_ms']['dispatch'] = round($tDisp * 1000, 2);
        $out['_interpretation']['plan'] = $plan;
        if (isset($scpFrame)) $out['_interpretation']['scp'] = [
            'task'=>$scpFrame['task'], 'conf'=>$scpFrame['confidence']['frame'] ?? null];
        chatLog($conn, $schoolId, $userId, $text, $out, $sessionId);
        echo json_encode(['status'=>'ok','data'=>$out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── RBAC + políticas institucionales ──
    if (!chatAllowed($conn, $authUser, $intent, $role)) {
        $reply = nxSmalltalk('denied', $vars);
        $out = ['reply'=>$reply,'intent'=>$intent,'confidence'=>$conf,'denied'=>true,'session_id'=>$sessionId];
        if ($ds) $out['_ds'] = $ds;   // denied no altera el estado conversacional
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
    // «expórtame X en Excel» — el parser marcó formato; si el handler
    // materializó una card, las acciones de descarga viajan con el mensaje
    if (!empty($slots['_export_format']) && !empty($out['cards']) && empty($out['denied'])) {
        [$ef, $et] = chatRange($slots);
        $title = $out['cards'][0]['title'] ?? 'Exportación NEXO';
        $fmt = (string)$slots['_export_format'];
        $want = in_array($fmt, ['excel','pdf','word','csv'], true) ? [$fmt] : [];
        $acts = chatExportActions($title, $ef, $et);
        if ($want) $acts = array_values(array_filter($acts, fn($a) => $a['format'] === $want[0]));
        // el handler pudo emitirla ya («excel» explícito → auto) — sin duplicar
        $have = array_map(fn($a) => $a['format'] ?? null, $out['actions'] ?? []);
        $acts = array_values(array_filter($acts, fn($a) => !in_array($a['format'] ?? '', $have, true)));
        $out['actions'] = array_merge($out['actions'] ?? [], $acts);
    }
    $out['_ds'] = chatBuildDs($interp, $out, $ds);
    if (isset($out['_interpretation']['timing_ms']))
        $out['_interpretation']['timing_ms']['dispatch'] = round($tDisp * 1000, 2);
    // traza SCP — el frame viaja con el turno para diagnóstico por capa
    if (isset($scpFrame)) $out['_interpretation']['scp'] = [
        'task'=>$scpFrame['task'], 'domain'=>$scpFrame['domain'],
        'subject'=>['name'=>$scpFrame['subject']['name'], 'source'=>$scpFrame['subject']['source']],
        'scope'=>$scpFrame['scope'], 'time_range'=>$scpFrame['time_range'],
        'ranking'=>$scpFrame['ranking'], 'corrections'=>$scpFrame['corrections'],
        'confidence'=>$scpFrame['confidence']];

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
    // neutro: afirma el dato sin juicio («todo limpio» contradice que el
    // colegio lleve 526 inasistencias; «buena noticia» juzga un dato)
    return nxVary([
        "No hay {$what} {$where}.",
        "Sin {$what} {$where}.",
        "No registra {$what} {$where}.",
        "Cero {$what} {$where}.",
    ], $seed);
}

/** Estado conversacional persistido — leído del último payload del asistente. */
function chatLoadDs(PDO $conn, string $userId, string $sessionId): ?array {
    // Sin latch de fallo: un error transitorio (BD aún no lista al boot del
    // contenedor) NO debe desactivar la memoria para siempre — un static
    // latch envenenaría al worker php-fpm para todas sus requests.
    try {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages
            WHERE user_id=? AND session_id=? AND role='assistant' AND jsonb_exists(payload_json, '_ds')
            ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId,$sessionId]);
        $r = $st->fetchColumn();
    } catch (Throwable $e) { return null; }
    if (!$r) return null;
    $p = json_decode($r, true);
    return $p['_ds'] ?? null;
}

/**
 * Construye el _ds para el próximo turno — §3 estado conversacional real.
 * Guarda entidades activas, campo pedido, result-set y cursor, pendientes.
 */

/** capability semántica → intent conversacional equivalente (replay/verify). */
function nxCapToIntent(?string $cap): ?string {
    static $m = [
        'incidents.list' => 'list_events', 'incidents.count' => 'count_events',
        'incidents.position' => 'list_events', 'students.list' => 'students_in_group',
        'students.field' => 'student_field', 'students.position' => 'students_in_group',
        'students.count' => 'students_count', 'students.percent' => 'students_count',
        'students.top' => 'top_offenders', 'students.detail' => 'student_summary',
        'students.of_guardian' => 'students_in_group', 'guardian.of_student' => 'student_field',
        'guardians.of_group' => 'students_in_group', 'teachers.of_group' => 'teachers_list',
        'attendance.today' => 'attendance_today', 'attendance.ranking' => 'attendance_ranking',
        'frequency_table' => 'frequency_table', 'export_data' => 'export_data',
        'schedule.of_group' => 'schedule_info', 'schedule.info' => 'schedule_info',
        'permissions.active' => 'permissions', 'permissions.pending' => 'pending_returns',
        'exits.school' => 'permissions', 'trackings.active' => 'trackings',
        'citations.list' => 'citations', 'devices.status' => 'devices_status',
        'notifications.unread' => 'notifications_unread', 'messages.failed' => 'failed_messages',
        'whatsapp.status' => 'whatsapp_status', 'sos.alerts' => 'sos_alerts',
        'biometric.spam' => 'biometric_spam', 'audit.query' => 'audit_query',
        'my.activity' => 'my_activity', 'day.summary' => 'day_summary',
        'birthdays.today' => 'birthdays_today', 'staff.lookup' => 'staff_lookup',
        'risk.students' => 'risk_students', 'groups.list' => 'groups_list',
        'groups.compare' => 'attendance_ranking', 'groups.rank' => 'attendance_ranking',
        'system.incidents' => 'system_incidents',
        // intents derivados del modelo de datos
        'risk.reason' => 'risk_reason', 'incidents.excuses' => 'incident_excuses',
        'exits.detail' => 'exit_detail', 'trips.info' => 'trip_info',
        'calendar.check' => 'school_calendar', 'staff.contact' => 'staff_contact',
        'schedule.of_teacher' => 'teacher_schedule', 'students.consent' => 'student_consent',
        'trackings.detail' => 'tracking_detail', 'citations.by' => 'citations_by',
        'alerts.resolution' => 'alert_resolution', 'students.enrollment' => 'enrollment_stats',
        'reports.list' => 'reports_log', 'sos.detail' => 'sos_detail',
        'messages.to_guardian' => 'guardian_messages', 'devices.detail' => 'device_detail',
        'attendance.trend' => 'attendance_trend',
    ];
    return $cap !== null ? ($m[$cap] ?? null) : null;
}

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
    // Un turno que no aporta tema propio NO debe borrar el contexto:
    // out_of_scope / clarify / denied / smalltalk son ruido conversacional,
    // no cambio de tema — sin esto un solo fallo mata la conversación.
    $noiseIntent = in_array($interp['resolved']['intent'] ?? $out['intent'] ?? '',
        ['out_of_scope','clarify','confirm_op','cancel','repeat_op','security_probe'], true)
        || !empty($out['denied']);
    $noNewSubject = empty($out['_result_set']) && empty($merged['student'])
        && empty($merged['group']) && empty($merged['person']);
    // marcador de colectivo («hay ALGÚN estudiante», «QUÉ estudiante»,
    // «el personal»): la pregunta es sobre el conjunto institucional —
    // el referente previo no debe re-inyectarse como entidad del tema
    $collectiveQ = (bool)preg_match('/\b(alguien|algun\w*|quien\w*|que estudiante|estudiantes|alumnos|pelados|muchachos|nadie|ningun\w*|todo el|del colegio|institucion|en general|personal|planta|mejor grupo|que grupo|cuales grupos)\b/u',
        (string)($slots['_q'] ?? ''));
    if ($isCont || $noiseIntent || ($noNewSubject && !$collectiveQ)) {
        // field NO se hereda: es de la frase, no del tema («y cuántas
        // evasiones tiene» no debe arrastrar el documento del turno previo)
        foreach (['student','group','module','days','from','to','range_label','person'] as $k) {
            if (empty($merged[$k]) && !empty($prev['entities'][$k])) $merged[$k] = $prev['entities'][$k];
        }
    } elseif ($collectiveQ && !$isCont) {
        // conjunto institucional: el rango temporal sí es del turno y
        // puede completarse desde el contexto, pero student/person no
        unset($merged['student'], $merged['person']);
    }
    // ── memoria de trabajo (§29): historial de objetos conversacionales ──
    // cada result-set materializado recibe un id R<N> MONOTÓNICO (nunca se
    // recicla tras el trim — una referencia «R3» apunta siempre al mismo
    // set). Solo se conserva identidad + filtros + conteo: los ítems con
    // datos personales viven una sola vez, en last_result.
    $objects = $prev['objects'] ?? [];
    $nextRid = (int)($prev['next_rid'] ?? 1);
    if (!empty($out['_result_set'])) {
        $rs = $out['_result_set'];
        $objects[] = [
            'id'      => 'R' . $nextRid,
            'type'    => $rs['type'] ?? null,
            'label'   => $rs['label'] ?? null,
            'entity'  => $rs['entity'] ?? null,
            'filters' => $rs['_filters'] ?? [],
            'count'   => $rs['count'] ?? count($rs['items'] ?? []),
        ];
        $nextRid++;
        if (count($objects) > 6) $objects = array_slice($objects, -6);
    }
    // jerarquía contexto vs referente (§9): el goal cambia con consultas
    // nuevas; el scope es el grupo/entidad activa; historical conserva
    // los goals anteriores para «volvamos a…».
    $currentEntity = ($out['_result_set']['entity'] ?? null)
        ?? (!empty($merged['student']) ? 'students' : (!empty($merged['group']) ? 'groups' : ($prev['current']['entity'] ?? null)));
    $goal = $slots['field'] ?? $slots['goal'] ?? ($out['_plan']['capability'] ?? ($interp['resolved']['intent'] ?? null));
    // el intent de tema debe ser el que REALMENTE se despachó: los paths de
    // continuación re-ejecutan intents sin reescribir resolved.intent —
    // registrar out_of_scope aquí envenena la herencia del próximo turno
    $resolvedIntent = $interp['resolved']['intent'] ?? null;
    $outIntent = $out['intent'] ?? null;
    $isNoiseIntent = fn($i) => $i === null || in_array($i,
        ['out_of_scope','clarify','confirm_op','cancel','repeat_op','security_probe','result_nav'], true);
    // el intent de tema es el REALMENTE despachado (out.intent = capability
    // del plan ejecutado): el resolved puede discrepar cuando el LLM
    // clasificó mal pero el compose semántico eligió la capacidad correcta
    // — guardar el intent del NLU envenena la corrección del próximo turno.
    // Solo capabilities simples mapeables ganan; intents compuestos («a+b»)
    // o clásicos conservan el resolved.
    $outConv = is_string($outIntent) && !str_contains($outIntent, '+')
        ? nxCapToIntent($outIntent) : null;
    $dsIntent = !empty($slots['_nav']) || !empty($out['_result_nav'])
        ? ($prev['intent'] ?? $resolvedIntent ?? $outIntent)
        : ($outConv ?? (!$isNoiseIntent($resolvedIntent) ? $resolvedIntent
            : (!$isNoiseIntent($outIntent) ? $outIntent
                : ($resolvedIntent ?? $outIntent))));
    // el plan semántico nombra el resultado por capability («incidents.list»);
    // _ds.intent debe conservar el intent conversacional para que la herencia
    // de NX_QUERY_INTENTS siga funcionando («y llegadas tarde?» tras una lista)
    $dsIntent = nxCapToIntent($dsIntent) ?? $dsIntent;
    $ds = [
        'intent'      => $dsIntent,
        'prev_intent' => $prev['intent'] ?? null,
        // claves internas (_q, _nav, _inherited…) no son entidades del tema —
        // _op sobrevive: es la operación pendiente que continúa el próximo turno
        'entities'    => array_filter($merged, fn($k) => !str_starts_with((string)$k, '_') || $k === '_op', ARRAY_FILTER_USE_KEY),
        'goal'        => $goal,
        'current'     => [
            'entity'   => $currentEntity,
            'result'   => !empty($out['_result_set']) ? end($objects)['id'] : ($prev['current']['result'] ?? null),
            'scope'    => $merged['group'] ?? ($prev['current']['scope'] ?? null),
            'relation' => $out['_plan']['relation'] ?? ($prev['current']['relation'] ?? null),
            'goal'     => $goal,
        ],
        'previous'    => $prev['current'] ?? null,
        'objects'     => $objects,
        // _intent = quién produjo el set — un nav «tabla de esos datos» tras
        // un count NO debe reutilizar el set de una consulta anterior: si el
        // productor difiere del intent activo y hay offer, el offer manda
        'last_result' => !empty($out['_result_set'])
            ? ($out['_result_set'] + ['_intent' => $dsIntent])
            : ($prev['last_result'] ?? null),
        'cursor'      => $out['_result_set'] ? 0
                        : ($out['_result_cursor'] ?? ($prev['cursor'] ?? 0)),
        'pending_op'  => $slots['_op'] ?? ($prev['pending_op'] ?? null),
        // objetivos compuestos sin entregar («te faltó lo otro» los recupera)
        'pending_targets' => $ent['_pending_targets'] ?? ($prev['pending_targets'] ?? null),
        'next_rid'    => $nextRid,
        // oferta del turno («¿quieres ver el detalle?») — un «sí» la ejecuta.
        // Sobrevive a ruido (out_of_scope/clarify/denied); una consulta nueva
        // la reemplaza.
        'offer'       => $out['_offer'] ?? ($noiseIntent ? ($prev['offer'] ?? null) : null),
    ];
    // ── estado tipado (§4) — el ds no es solo last_intent+slots: tarea,
    // entidad, colección, resultado, relación, campo, filtros, alcance,
    // rango temporal, métrica, agregación, orden, límite y posición ──
    $lr = $ds['last_result'];
    $ds['active'] = [
        'task'        => $ds['intent'],
        'entity'      => $currentEntity,
        'collection'  => $lr['label'] ?? ($prev['active']['collection'] ?? null),
        'result'      => $ds['current']['result'],
        'relation'    => $ds['current']['relation'],
        'field'       => $merged['field'] ?? null,
        'filters'     => $lr['_filters'] ?? array_filter($merged,
                        fn($v,$k)=>in_array($k,['group','module','status','student','search'],true),ARRAY_FILTER_USE_BOTH),
        'scope'       => $ds['current']['scope'],
        'time_range'  => array_filter(['days'=>$merged['days']??null,'from'=>$merged['from']??null,
                        'to'=>$merged['to']??null,'label'=>$merged['range_label']??null]),
        'metric'      => $merged['module'] ?? null,
        'aggregation' => in_array($ds['intent'], ['count_events','group_student_count','students_count'], true) ? 'count'
                        : (in_array($ds['intent'], ['top_offenders','groups.rank'], true) ? 'rank' : null),
        'sort'        => $lr['order'] ?? null,
        'limit'       => $slots['_rank_limit'] ?? ($ent['_rank_limit'] ?? null),
        'position'    => $ds['cursor'],
    ];
    // clarificación pendiente + última corrección del usuario (§10)
    $ds['pending_clarification'] = !empty($interp['requires_clarification'])
        ? ($interp['clarify'] ?? true) : null;
    $ds['last_correction'] = $interp['resolved']['corrections'] ?? null;
    // último plan y última ejecución — trazabilidad plan↔resultado (§8)
    $ds['last_plan'] = !empty($out['_plan'])
        ? ['capability'=>$out['_plan']['capability'] ?? null,'op'=>$out['_plan']['op'] ?? null,
           'filters'=>$out['_plan']['filters'] ?? null]
        : ($prev['last_plan'] ?? null);
    $ds['last_execution'] = ['intent'=>$out['intent'] ?? null,
        'source'=>$out['_interpretation']['source'] ?? null,
        'turn'=>$ds['intent']];
    // ── lineage del result-set (§12): cada resultado sabe de dónde vino,
    // qué lo transformó y cuál es su ítem activo; el padre sobrevive en
    // objects[] aunque la vista cambie ──
    if (is_array($lr)) {
        if (!empty($out['_result_set'])) {
            $lr['rid']              = $ds['current']['result'];
            $lr['parent_result']    = $prev['current']['result'] ?? null;
            $lr['source_capability']= $lr['_capability'] ?? ($out['_plan']['capability'] ?? null);
            $lr['source_intent']    = $interp['resolved']['intent'] ?? null;
        }
        if (!empty($out['_result_nav'])) $lr['transformation'] = $out['_result_nav'];
        $lr['active_item']    = $lr['items'][$ds['cursor']]['label'] ?? null;
        $lr['visible_items']  = count($lr['items'] ?? []);
        $ds['last_result']    = $lr;
    }
    // persona referenciada (acudiente/docente) — el handler la declara.
    // El referente solo sobrevive mientras el sujeto activo no cambie: si
    // el turno ancló OTRO estudiante, el acudiente del turno previo ya no
    // aplica a «su X» (persona obsoleta ≠ persona actual).
    if (!empty($ent['_person'])) {
        $ds['person'] = $ent['_person'];
    } elseif (!empty($prev['person'])) {
        $prevStudent = $prev['entities']['student'] ?? null;
        $newStudent  = $merged['student'] ?? null;
        $sameSubject = empty($newStudent) || empty($prevStudent)
            || mb_strtolower($newStudent) === mb_strtolower($prevStudent);
        if ($sameSubject) $ds['person'] = $prev['person'];
    }
    return $ds;
}

/**
 * SCP — correcciones del usuario: actualizan el plan/contexto activo en
 * lugar de reiniciar la conversación (§REGLAS FUNDAMENTALES). Devuelve
 * null cuando la corrección no aplica (sigue el flujo normal).
 */
function chatScpCorrections(PDO $conn, array $u, array $frame, ?array $ds, array $interp,
    array $vars, string $role, string $sessionId, string $q0, float $conf): ?array {
    if ($frame['task'] !== 'correct' || empty($frame['corrections'])) return null;
    $ds = $ds ?: [];
    $ent = $ds['entities'] ?? [];
    foreach ($frame['corrections'] as $c) {
        switch ($c['kind']) {
            // «no, me refiero al de Tomás Castaño Gutiérrez» — el nombre
            // completo explícito domina: re-ejecutar la tarea previa con
            // el sujeto corregido, heredando campo/alcance/tiempo activos
            case 'replace_subject': {
                $prevIntent = $ds['intent'] ?? null;
                // «me refiero a los de 6A / a ese grupo» — el objetivo es
                // la COLECCIÓN: re-ejecutar la consulta con el grupo
                if (preg_match('/\b(\d{1,2})\s*-?\s*([a-e])\b/i', (string)($c['value'] ?? ''), $mg)
                    && in_array($prevIntent, ['students_in_group','students.list','result_nav','students_count','group_student_count'], true)) {
                    $slots = array_intersect_key($ent, array_flip(['field','module','days','from','to','range_label']));
                    $slots['group'] = strtoupper($mg[1] . '-' . $mg[2]);
                    $out = chatDispatch($conn, $u, 'students_in_group', $slots, $vars, $role);
                    if (!is_array($out)) $out = [];
                    $out += ['intent'=>'students_in_group','confidence'=>$conf,'session_id'=>$sessionId,'entities'=>$slots];
                    $out['reply'] = 'Corrijo — ' . ($out['reply'] ?? '');
                    return $out;
                }
                $newStudent = nxScpCorrectionSubject($c['value'] ?? '');
                if (!$prevIntent || !$newStudent) break;
                $slots = array_intersect_key($ent, array_flip(['field','group','module','days','from','to']));
                $slots['student'] = $newStudent;
                $out = chatDispatch($conn, $u, $prevIntent, $slots, $vars, $role);
                if (!is_array($out)) $out = [];
                $out += ['intent'=>$prevIntent, 'confidence'=>$conf, 'session_id'=>$sessionId, 'entities'=>$slots];
                return $out;
            }
            // «no, eran las faltas» — la métrica/módulo de la consulta
            // activa era otra: re-ejecutar con el módulo corregido
            case 'replace_metric': {
                $noun = mb_strtolower(trim((string)($c['value'] ?? '')));
                $module = null;
                foreach (['inasist'=>'INASISTENCIA','falt'=>'INASISTENCIA','ausen'=>'INASISTENCIA',
                          'tardanz'=>'LATE_ARRIVAL','llegad'=>'LATE_ARRIVAL','tarde'=>'LATE_ARRIVAL',
                          'evasi'=>'EVASION_INTERNA','fug'=>'EVASION_INTERNA','escap'=>'EVASION_INTERNA',
                          'incident'=>'INCIDENTE','permis'=>'PERMISO','citaci'=>'CITACION',
                          'seguim'=>'SEGUIMIENTO','casos'=>'SEGUIMIENTO'] as $p => $mod)
                    if (str_contains($noun, $p)) { $module = $mod; break; }
                if (!$module) break;
                // comparación activa → re-ejecutar el plan con la métrica nueva
                if (!empty($ent['group']) && !empty($ent['group2'])) {
                    $plan = ['capability'=>'groups.compare','entity'=>'groups','op'=>'compare',
                        'filters'=>['group'=>$ent['group'],'group2'=>$ent['group2'],'module'=>$module,
                            'days'=>$ent['days'] ?? null,'from'=>$ent['from'] ?? null,
                            'to'=>$ent['to'] ?? null,'range_label'=>$ent['range_label'] ?? null],
                        'read_only'=>true,'_src'=>'scp-correct','conf'=>0.8,
                        'evidence'=>['correction:replace_metric']];
                    [$ok] = nxPlanValidate($plan);
                    if ($ok && nxPlanAllowed($conn, $u, $plan, $role)) {
                        $out = nxPlanExecute($conn, $u, $plan, $vars);
                        if (!is_array($out)) $out = [];
                        $out += ['intent'=>'groups.compare','confidence'=>$conf,'session_id'=>$sessionId,
                                 'entities'=>array_merge($ent,['module'=>$module])];
                        $out['reply'] = 'Corrijo — ' . ($out['reply'] ?? '');
                        return $out;
                    }
                    break;
                }
                $prevIntent = $ds['intent'] ?? null;
                if (!in_array($prevIntent, ['count_events','list_events','top_offenders','late_today'], true)) break;
                $slots = array_intersect_key($ent, array_flip(['student','group','days','from','to','range_label','status','_rank_limit','_my_scope']));
                $slots['module'] = $module;
                $out = chatDispatch($conn, $u, $prevIntent, $slots, $vars, $role);
                if (!is_array($out)) $out = [];
                $out += ['intent'=>$prevIntent,'confidence'=>$conf,'session_id'=>$sessionId,'entities'=>$slots];
                $out['reply'] = 'Corrijo — ' . ($out['reply'] ?? '');
                return $out;
            }
            // «te faltó lo otro» — objetivo compuesto pendiente de entrega
            case 'pending_target': {
                foreach (($ds['pending_targets'] ?? []) as $p) {
                    if (!empty($p['delivered'])) continue;
                    $out = chatDispatch($conn, $u, $p['intent'], $p['slots'] ?? [], $vars, $role);
                    if (!is_array($out)) $out = [];
                    $out += ['intent'=>$p['intent'], 'confidence'=>$conf, 'session_id'=>$sessionId];
                    $out['reply'] = 'Faltaba esto — ' . ($out['reply'] ?? '');
                    return $out;
                }
                $done = $ds['intent'] ?? 'tu consulta';
                return ['reply'=>"No quedó nada pendiente — tu última petición quedó completa. ¿Qué más te consigo?",
                        'intent'=>'clarify', 'confidence'=>$conf, 'session_id'=>$sessionId];
            }
            // «no sería empate, sería que ninguna» — reencuadre honesto del
            // resultado de comparación activo (los datos no cambian: la
            // interpretación sí) — pasa por validate+allowed como todo plan
            case 'none_of': {
                if (empty($ent['group']) || empty($ent['group2'])) break;
                $plan = ['capability'=>'groups.compare','entity'=>'groups','op'=>'compare',
                    'filters'=>['group'=>$ent['group'],'group2'=>$ent['group2'],
                        'module'=>$ent['module'] ?? null,'days'=>$ent['days'] ?? null,
                        'from'=>$ent['from'] ?? null,'to'=>$ent['to'] ?? null,
                        'range_label'=>$ent['range_label'] ?? null],
                    'read_only'=>true,'_src'=>'scp-correct','conf'=>0.8,
                    'evidence'=>['correction:none_of']];
                [$ok] = nxPlanValidate($plan);
                if ($ok && nxPlanAllowed($conn, $u, $plan, $role)) {
                    $out = nxPlanExecute($conn, $u, $plan, $vars);
                    $rl = $ent['range_label'] ?? 'hoy';
                    if (str_contains((string)($out['reply'] ?? ''), 'Empate'))
                        $out['reply'] = "Corrijo: ninguna de las dos — {$ent['group']} y {$ent['group2']} registran cero ({$rl}).";
                    $out += ['intent'=>'groups.compare','confidence'=>$conf,'session_id'=>$sessionId,'entities'=>$ent];
                    return $out;
                }
                break;
            }
            // «solo cinco» como turno propio — recorte del ranking activo
            case 'limit': {
                $prevIntent = $ds['intent'] ?? null;
                if (!in_array($prevIntent, ['top_offenders'], true) || empty($c['value'])) break;
                $slots = array_intersect_key($ent, array_flip(['module','group','days','from','to','range_label']));
                $slots['_rank_limit'] = $c['value'];
                $out = chatDispatch($conn, $u, $prevIntent, $slots, $vars, $role);
                if (!is_array($out)) $out = [];
                $out += ['intent'=>$prevIntent,'confidence'=>$conf,'session_id'=>$sessionId];
                return $out;
            }
            // «no esos» — exclusión del set mostrado: honesto sin datos
            case 'exclude_active':
                return ['reply'=>'Entendido — descartamos esos. Dime qué criterio uso en su lugar (otro grupo, otra condición).',
                        'intent'=>'clarify','confidence'=>$conf,'session_id'=>$sessionId];
            // «de mi clase» — refinamiento de alcance sobre la tarea activa
            case 'refine_scope': {
                $prevIntent = $ds['intent'] ?? null;
                if (!$prevIntent) break;
                $slots = array_intersect_key($ent, array_flip(['field','module','student','days','from','to']));
                $slots['group'] = '*mine*';
                $out = chatDispatch($conn, $u, $prevIntent, $slots, $vars, $role);
                if (!is_array($out)) $out = [];
                $out += ['intent'=>$prevIntent,'confidence'=>$conf,'session_id'=>$sessionId];
                return $out;
            }
        }
    }
    return null;
}

/** nombre limpio desde el valor de una corrección («al de Tomás…» → «Tomás…»). */
function nxScpCorrectionSubject(string $v): ?string {
    $v = trim(preg_replace('/^(al|a la|a|el|la|los|las|de|del|estudiante|acudiente|de el|de la)\s+/ui', '', trim($v)));
    $v = trim(preg_replace('/\b(de|del)\s+(10|11|6|7|8|9)\s*-?[ab]\b.*$/ui', '', $v)); // «de 10A» no es parte del nombre
    $v = preg_replace('/[.!?]+$/u', '', $v);
    return (mb_strlen($v) >= 4 && !preg_match('/^(si|no|este|esa|eso)$/ui', $v)) ? $v : null;
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

    // «cuántos son/hay» sobre un set vacío — el cero ES la respuesta
    if ($n === 0 && $nav === 'count')
        return ['reply'=>"En esa consulta hay 0 {$lbl} — no trajo ninguno.",
                'intent'=>'result_nav', '_result_nav'=>'count'];
    // set vacío o ya consumido — respuesta honesta, nunca caer al
    // parser con un deíctico («los demás» ≠ consulta nueva)
    if ($n === 0)
        return ['reply'=>"La consulta anterior no trajo {$lbl} — no hay nada que navegar. ¿Quieres otra lista?",
                'intent'=>'result_nav', '_result_nav'=>'empty'];

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
            return ['reply'=>"El primero es {$one(0)}.", 'intent'=>'result_nav', '_result_nav'=>'first', '_result_cursor'=>0,
                    'entities'=>[($rs['type']==='students' ? 'student' : ($rs['type'] ?? 'item')) => $items[0]['label'] ?? null]];
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

    // ── transformaciones sobre el set guardado (§12-13): proyección,
    // orden y slice SIN reconsultar — el dataset ya está en memoria ──
    if (preg_match('/^proj:(name|\+document|\+phone|\+group)$/', $nav, $m)) {
        $fld = $m[1];
        if ($fld === 'name') {
            $names = array_map(fn($it)=>$it['label'], $items);
            return ['reply'=>"Solo nombres ({$n}):\n• " . implode("\n• ", $names),
                    'cards'=>!empty($rs['columns']) ? [['title'=>ucfirst($lbl),'columns'=>['#','Nombre'],
                        'rows'=>array_map(fn($i,$it)=>[$i+1,$it['label']],array_keys($items),$items)]] : null,
                    'intent'=>'result_nav','_result_nav'=>'proj'];
        }
        $key = ['+document'=>'doc','+phone'=>'phone','+group'=>'grp'][$fld];
        $colN = ['+document'=>'Documento','+phone'=>'Teléfono','+group'=>'Grupo'][$fld];
        $lines = array_map(fn($it)=>'• '.$it['label'].' — '.($it['f'][$key] ?? '—'), $items);
        return ['reply'=>"Con {$colN} ({$n}):\n" . implode("\n",$lines),
                'cards'=>[['title'=>ucfirst($lbl),'columns'=>['#','Nombre',$colN],
                    'rows'=>array_map(fn($i,$it)=>[$i+1,$it['label'],$it['f'][$key] ?? '—'],array_keys($items),$items)]],
                'intent'=>'result_nav','_result_nav'=>'proj'];
    }
    if (preg_match('/^sort:(last_name|first_name|document|group)$/', $nav, $m)) {
        $key = ['last_name'=>'ln','first_name'=>'fn','document'=>'doc','group'=>'grp'][$m[1]];
        // permutar índices — las filas del card conservan SUS columnas
        // (un set de acudientes/incidentes no tiene doc/grupo estudiantil)
        $order = array_keys($items);
        usort($order, fn($i,$j)=>strnatcasecmp(
            (string)($items[$i]['f'][$key] ?? $items[$i]['label']),
            (string)($items[$j]['f'][$key] ?? $items[$j]['label'])));
        $items2 = array_map(fn($i)=>$items[$i], $order);
        $rows2 = !empty($rs['rows'])
            ? array_map(fn($k,$i)=>[$k+1, ...array_slice($rs['rows'][$i],1)],
                        array_keys($order), $order)
            : null;
        $lbl2 = ['ln'=>'apellido','fn'=>'nombre','doc'=>'documento','grp'=>'grupo'][$key];
        $lines = array_map(fn($it)=>'• '.$it['label'].(!empty($it['sub'])?' — '.$it['sub']:''), array_slice($items2,0,12));
        return ['reply'=>"Ordenados por {$lbl2} ({$n}):\n" . implode("\n",$lines) . ($n>12?"\n…y ".($n-12)." más":''),
                'intent'=>'result_nav','_result_nav'=>'sort',
                '_result_set'=>array_merge($rs,['items'=>$items2,'order'=>$lbl2]
                    + ($rows2 !== null ? ['rows'=>$rows2] : [])),
                '_result_cursor'=>0];
    }
    if (preg_match('/^slice:(\d+):(start|end)$/', $nav, $m)) {
        $k = (int)$m[1]; $sl = $m[2]==='end' ? array_slice($items,-$k) : array_slice($items,0,$k);
        if (!$sl) return ['reply'=>"El set solo tiene {$n} {$lbl}.",'intent'=>'result_nav','_result_nav'=>'slice'];
        $lines = array_map(fn($it)=>'• '.$it['label'].(!empty($it['sub'])?' — '.$it['sub']:''), $sl);
        $which = $m[2]==='end' ? "los últimos {$k}" : "los primeros {$k}";
        // el slice es una VISTA — el set completo queda como last_result
        // («vuelve al primero», «los demás» operan sobre el total)
        return ['reply'=>"De los {$n} {$lbl}, {$which}:\n" . implode("\n",$lines),
                'intent'=>'result_nav','_result_nav'=>'slice',
                '_result_cursor'=>array_search($sl[0],$items,true) ?: 0];
    }
    if (preg_match('/^goto:(\d+)$/', $nav, $m)) { $nav = 'nth:' . $m[1]; $idx = max(0,(int)$m[1]-1); }
    // next / nth
    $idx = $idx ?? ($cur + 1);
    if ($idx >= $n)
        return ['reply'=>"No hay más {$lbl} — ya te mostré los {$n} que encontré.",
                'intent'=>'result_nav','_result_nav'=>'next','_result_cursor'=>$cur];
    return ['reply'=>$one($idx) . ($idx < $n - 1 ? ". ¿El siguiente?" : '. Era el último de la lista.'),
            'intent'=>'result_nav','_result_nav'=>'next','_result_cursor'=>$idx,
            'entities'=>[($rs['type']==='students' ? 'student' : ($rs['type'] ?? 'item')) => $items[$idx]['label'] ?? null]];
}

/** Último payload del asistente en esta sesión — seguimiento contextual («dame otro»). */
function chatLastPayload(PDO $conn, string $userId, ?string $sessionId = null): ?array {
    // payload denegado ≠ seguimiento repetible — excluirlo para que «otro»
    // no pueda re-ejecutar un intent rechazado por RBAC/política
    $notDenied = " AND COALESCE(payload_json->>'denied','false') <> 'true'";
    if ($sessionId) {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages WHERE user_id=? AND session_id=? AND role='assistant'{$notDenied} ORDER BY created_at DESC LIMIT 1");
        $st->execute([$userId,$sessionId]);
    } else {
        $st = $conn->prepare("SELECT payload_json FROM chat_messages WHERE user_id=? AND role='assistant'{$notDenied} ORDER BY created_at DESC LIMIT 1");
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
        // flujo informal → LLM #3: conversa con contexto nativo (historial
        // real) bajo persona institucional. security_probe se queda fijo;
        // out_of_scope también prueba el chat — una pregunta de cultura
        // general sin intent cubierto merece respuesta natural, no rechazo.
        if (!in_array($intent, ['security_probe'], true)) {
            $chat = function_exists('nxLlmChat')
                ? nxLlmChat($vars['_raw'] ?? '', $vars['_history'] ?? []) : null;
            if (is_string($chat) && $chat !== '')
                return ['reply'=>$chat, 'intent'=>$intent, '_llm_chat'=>true];
        }
        return ['reply' => nxSmalltalk($intent, $vars), 'intent'=>$intent];
    }
    // alias: el intent del parser no siempre coincide 1:1 con el handler
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
    $rows = [];
    if (preg_match('/^[0-9a-f-]{36}$/i', $sessionId)) {
        // sesión: ASC directo — ya viene oldest→newest
        $stmt = $conn->prepare("
            SELECT role, content, payload_json, created_at
            FROM chat_messages WHERE user_id = ? AND session_id = ?
            ORDER BY created_at ASC LIMIT 200
        ");
        $stmt->execute([$authUser['id'], $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // sin sesión: DESC para quedarnos con los MÁS recientes, luego invertir
        $stmt = $conn->prepare("
            SELECT role, content, payload_json, created_at
            FROM chat_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 60
        ");
        $stmt->execute([$authUser['id']]);
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    echo json_encode(['status'=>'ok','data'=>array_map(function($r) {
        $payload = is_array($r['payload_json'] ?? null)
            ? $r['payload_json']
            : json_decode($r['payload_json'] ?? 'null', true);
        if (!is_array($payload)) $payload = [];
        return [
            'from' => $r['role'] === 'user' ? 'user' : 'bot',
            'text' => $r['content'],
            'cards' => ($payload['cards'] ?? null),
            'actions' => ($payload['actions'] ?? null),
            'intent' => ($payload['intent'] ?? null),
            'ts' => $r['created_at'],
        ];
    }, $rows)]);
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
    try {
        $st = $conn->prepare("SELECT policy_key, enabled FROM school_chat_policies WHERE school_id=?");
        $st->execute([$authUser['school_id']]);
        $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        // Tabla ausente en despliegues antiguos → mismos defaults que
        // chatPolicyEnabled(): todo habilitado, sin romper la pantalla.
        $rows = [];
    }
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
    try {
        $up = $conn->prepare("INSERT INTO school_chat_policies (school_id,policy_key,enabled,updated_by)
            VALUES (?,?,?,?)
            ON CONFLICT (school_id,policy_key) DO UPDATE SET enabled=EXCLUDED.enabled, updated_by=EXCLUDED.updated_by, updated_at=NOW()");
        foreach ($policies as $k=>$v) {
            if (!in_array($k,$allowed,true)) continue;
            // PARAM_BOOL explícito: execute([...]) bindea false como '' y
            // PostgreSQL lo rechaza ("invalid input syntax for type boolean").
            // Esto volvía imposible APAGAR cualquier interruptor — solo
            // prendidos pasaban. Era el motivo real del 500 de coordinación.
            $up->bindValue(1, $authUser['school_id']);
            $up->bindValue(2, $k);
            $up->bindValue(3, (bool)$v, PDO::PARAM_BOOL);
            $up->bindValue(4, $authUser['id']);
            $up->execute();
        }
    } catch (Throwable $e) {
        error_log("[CHAT] save policies error: " . $e->getMessage());
        http_response_code(500);
        exit(json_encode(['status'=>'error','message'=>'No se pudieron guardar las políticas. Verifica que la base de datos esté actualizada.']));
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

function chatLog(PDO $conn, string $schoolId, string $userId, string $text, array &$out, ?string $sessionId = null): void {
    // session_id puede no existir aún en DBs sin el patch — degradar sin romper
    static $hasSession = null;
    // LLM #2 — response composer: reformula el reply verificado en español
    // natural (nunca toca datos/cards). Por referencia: se persiste y se
    // devuelve ya compuesto. Falla → reply original intacto.
    // Todo conjunto de datos se entrega como tabla: si el handler materializó
    // un _result_set (columns+rows) pero no emitió card, la UI la recibe aquí
    // — la sección de consultas vive en el chat, nunca como texto plano.
    if (empty($out['cards']) && empty($out['_result_set']['_silent'])
        && !empty($out['_result_set']['columns']) && !empty($out['_result_set']['rows'])) {
        $rs = $out['_result_set'];
        $out['cards'] = [[
            'title'   => ucfirst($rs['label'] ?? 'Resultados'),
            'columns' => $rs['columns'],
            'rows'    => $rs['rows'],
        ]];
    }
    if (function_exists('nxLlmComposeReply')) {
        try {
            // contexto de los últimos turnos para coherencia (no se persiste)
            $out['_recent'] = $sessionId ? chatRecentTurns($conn, $userId, $sessionId, 2) : [];
            $better = nxLlmComposeReply($text, $out);
            unset($out['_recent']);
            if (is_string($better) && $better !== '') {
                $out['reply_raw'] = $out['reply'] ?? null;
                $out['reply'] = $better;
            }
        } catch (Throwable $e) { /* composer nunca bloquea */ }
    }
    if ($hasSession === null) {
        try {
            $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_name='chat_messages' AND column_name='session_id'");
            $st->execute();
            $hasSession = (bool)$st->fetchColumn();
        } catch (Throwable $e) { /* transitorio: reintentar en la próxima request */ }
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
    $today = nxToday();
    $safe = function (callable $f) { try { return $f(); } catch (Throwable $e) { return null; } };
    $inc = $safe(function () use ($conn, $u, $today, $scope) {
        $st = $conn->prepare("SELECT ai.incident_type, COUNT(*) AS c,
                COUNT(*) FILTER (WHERE ai.metadata_json->>'pending_context' = 'true') AS pend
            FROM attendance_incidents ai JOIN students s ON s.student_id = ai.student_id
            WHERE ai.school_id = ? AND " . chatD('ai.detected_at') . " = ? {$scope['sql']}
            GROUP BY ai.incident_type");
        $st->execute([$u['school_id'], $today]);
        $o = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$r['incident_type']] = ['c' => (int)$r['c'], 'pend' => (int)$r['pend']];
        return $o;
    }) ?? [];
    $abs  = $inc['INASISTENCIA']['c'] ?? 0;
    $pend = $inc['INASISTENCIA']['pend'] ?? 0;
    $late = $inc['LATE_ARRIVAL']['c'] ?? 0;
    $eva  = $inc['EVASION_INTERNA']['c'] ?? 0;
    $present = (int)($safe(function () use ($conn, $u, $today, $scope) {
        $st = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
            JOIN students s ON s.student_id = be.student_id
            WHERE be.school_id = ? AND " . chatD('be.event_timestamp') . " = ? AND be.event_type LIKE 'INGRESO%' {$scope['sql']}");
        $st->execute([$u['school_id'], $today]); return $st->fetchColumn();
    }) ?? 0);
    $enrolled = (int)($safe(function () use ($conn, $u, $scope) {
        $st = $conn->prepare("SELECT COUNT(*) FROM students s WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.active = TRUE {$scope['sql']}");
        $st->execute([$u['school_id']]); return $st->fetchColumn();
    }) ?? 0);
    $perm = (int)($safe(function () use ($conn, $u, $scope) {
        $st = $conn->prepare("SELECT COUNT(*) FROM class_exit_authorizations c JOIN students s ON s.student_id = c.student_id
            WHERE c.school_id = ? AND c.status = 'ACTIVE' {$scope['sql']}");
        $st->execute([$u['school_id']]); return $st->fetchColumn();
    }) ?? 0);
    $notif = (int)($safe(function () use ($conn, $u) {
        $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $st->execute([$u['id']]); return $st->fetchColumn();
    }) ?? 0);

    $of = $enrolled ? " de {$enrolled}" : '';
    if ($present === 0 && $abs > 0) {
        // 0 ingresos + inasistencias masivas = señal de captura, no de
        // ausentismo real: decirlo es más útil que «todo dentro de lo normal»
        $reply = "Hoy no hay ingresos registrados y van {$abs} inasistencias{$of} estudiantes. "
            . "Sin marcaciones de entrada lo más probable es que los sensores no estén reportando"
            . ($pend ? " — {$pend} de esas inasistencias quedaron pendientes de verificación y no se avisó a los acudientes." : '.');
    } else {
        $reply = "Hoy van {$present} presentes{$of}, {$abs} inasistencias, {$late} llegadas tarde y {$eva} evasiones.";
    }
    if ($perm) $reply .= " Hay {$perm} " . ($perm === 1 ? 'permiso activo' : 'permisos activos') . " ahora.";
    if ($notif) $reply .= " Tienes {$notif} notificaciones sin leer.";
    $out = ['reply' => $reply,
        'cards' => [['title' => 'Hoy', 'columns' => ['Presentes', 'Inasistencias', 'Tardanzas', 'Evasiones', 'Permisos activos'],
            'rows' => [[$present . ($enrolled ? " / {$enrolled}" : ''), $abs, $late, $eva, $perm]]]],
        'actions' => $eva > 0 ? chatDerivedActions($u, null, 'evasiones hoy') : [],
        '_natural' => true];
    if ($abs > 0) $out['_offer'] = chatOffer('¿Quieres ver las inasistencias por grupo?', 'frequency_table',
        ['module' => 'INASISTENCIA', 'group_by' => 'group', 'days' => 0, 'from' => $today, 'to' => $today, 'range_label' => 'hoy']);
    return $out;
}

function chat_attendance_today(PDO $conn, array $u, array $s, array $v): array {
    return chatListIncidents($conn,$u,$s,'INASISTENCIA','inasistencias hoy');
}
function chat_late_today(PDO $conn, array $u, array $s, array $v): array {
    return chatListIncidents($conn,$u,$s,'LATE_ARRIVAL','llegadas tarde hoy');
}

function chatListIncidents(PDO $conn, array $u, array $s, string $type, string $label): array {
    $s['module'] = $type;
    return chat_list_events($conn, $u, $s, ['_q' => $label]);
}

function chatRange(array $s): array {
    return [$s['from'] ?? nxToday(), $s['to'] ?? nxToday()];
}

/** Filtro común de incidentes (módulo, estudiante, grupo, grado, excusa, alcance del rol). */
function chatIncidentFilter(PDO $conn, array $u, array $s, bool $withModule = true): array {
    $scope = chatScope($conn, $u);
    $w = ['ai.school_id = ?']; $p = [$u['school_id']];
    $student = null; $group = null;
    if ($withModule && !empty($s['module']) && $s['module'] !== 'INCIDENTE') { $w[] = 'ai.incident_type = ?'; $p[] = $s['module']; }
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if ($found && count($found) > 1 && !empty($s['group'])) {
            $gk = strtoupper(str_replace(['-', ' '], '', (string)$s['group']));
            $byG = array_values(array_filter($found, fn($r) => strtoupper(str_replace(['-', ' '], '', (string)($r['group_name'] ?? ''))) === $gk));
            if ($byG) $found = $byG;
        }
        if (!$found) return ['err' => ['reply' => "No encuentro a «{$s['student']}» entre los estudiantes de tu alcance — revisa el nombre o dime su grupo.", '_natural' => true]];
        if (count($found) > 1) return ['err' => chatAmbiguous($found)];
        $student = $found[0];
        $w[] = 'ai.student_id = ?'; $p[] = $student['student_id'];
    } elseif (!empty($s['group'])) {
        $group = chatResolveGroup($conn, $u, $s['group']);
        if (!$group) return ['err' => ['reply' => "No encuentro el grupo «{$s['group']}» en la institución.", '_natural' => true]];
        $w[] = chatIncGroupCol() . ' = ?'; $p[] = $group['group_id'];
    } elseif (!empty($s['grade'])) {
        $w[] = 'ag.grade_level = ?'; $p[] = (string)$s['grade'];
    }
    if (!empty($s['justified'])) $w[] = ltrim(chatJustifiedSql((string)$s['justified']), ' AND');
    return ['where' => implode(' AND ', $w) . ' ' . $scope['sql'], 'params' => array_merge($p, $scope['params']),
            'student' => $student, 'group' => $group,
            'from' => " FROM attendance_incidents ai JOIN students s ON s.student_id = ai.student_id AND s.deleted_at IS NULL " . chatIncGroupJoin()];
}

/** Módulos con tabla propia: la consulta de eventos se deriva a su handler. */
function chatModuleDelegate(PDO $conn, array $u, array $s, array $v, bool $count = false): ?array {
    $m = $s['module'] ?? null;
    $map = ['SEGUIMIENTO' => $count ? 'count_trackings' : 'trackings', 'PERMISO' => 'permissions',
            'SALIDA_PEDAGOGICA' => 'permissions', 'SALIDA_COLEGIO' => 'permissions',
            'CITACION' => 'citations', 'SOS' => 'sos_alerts'];
    if (!$m || !isset($map[$m])) return null;
    if (in_array($m, ['PERMISO', 'SALIDA_PEDAGOGICA', 'SALIDA_COLEGIO'], true) && (!empty($s['from']) || !empty($s['range_label'])))
        $s['status'] = $s['status'] ?? 'all';
    $fn = 'chat_' . $map[$m];
    $out = $fn($conn, $u, $s, $v);
    $out['_delegated'] = $map[$m];
    return $out;
}

/** Período anterior equivalente para comparar («hoy» → día hábil anterior). */
function chatPreviousPeriod(string $from, string $to, ?string $label): array {
    $tz = new DateTimeZone('America/Bogota');
    $f = new DateTimeImmutable($from, $tz); $t = new DateTimeImmutable($to, $tz);
    $lbl = (string)$label;
    $fmt = fn(DateTimeImmutable $d) => (int)$d->format('j') . ' de ' . NX_MONTH_NAMES[(int)$d->format('n')];
    if ($from === $to) {
        $p = $f->modify('-1 day');
        while ((int)$p->format('N') >= 6) $p = $p->modify('-1 day');
        $name = ($p->format('Y-m-d') === nxToday(1)) ? 'ayer' : 'el día hábil anterior (' . $fmt($p) . ')';
        return ['from' => $p->format('Y-m-d'), 'to' => $p->format('Y-m-d'), 'range_label' => $name];
    }
    if (str_starts_with($lbl, 'esta semana')) {
        $span = (int)$f->diff($t)->days;
        $pf = $f->modify('-7 days'); $pt = $pf->modify("+{$span} days");
        return ['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d'), 'range_label' => 'la semana pasada (mismo tramo)'];
    }
    if (str_starts_with($lbl, 'este mes')) {
        $pf = $f->modify('first day of last month');
        $pt = $pf->modify('+' . ((int)$t->format('j') - 1) . ' days');
        $end = $pf->modify('last day of this month');
        if ($pt > $end) $pt = $end;
        return ['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d'), 'range_label' => 'el mes pasado (mismo tramo)'];
    }
    $span = (int)$f->diff($t)->days + 1;
    $pt = $f->modify('-1 day'); $pf = $pt->modify('-' . ($span - 1) . ' days');
    return ['from' => $pf->format('Y-m-d'), 'to' => $pt->format('Y-m-d'), 'range_label' => "los {$span} días anteriores"];
}

/** Rango de 1–3 días cuando el usuario dijo «este mes»/«esta semana»: ofrecer la ventana útil. */
function chatShortRangeOffer(array $s, string $intent): ?array {
    $lbl = (string)($s['range_label'] ?? '');
    [$from, $to] = chatRange($s);
    $days = (int)(new DateTime($from))->diff(new DateTime($to))->days + 1;
    if ($days > 3 || (!str_starts_with($lbl, 'este mes') && !str_starts_with($lbl, 'esta semana'))) return null;
    $t = nxToday();
    $slots = array_intersect_key($s, array_flip(['module', 'student', 'group', 'grade', 'justified', 'group_by']));
    $slots += ['days' => 30, 'from' => nxToday(29), 'to' => $t, 'range_label' => 'últimos 30 días'];
    return chatOffer('¿Quieres ver los últimos 30 días?', $intent, $slots);
}

function chat_count_events(PDO $conn, array $u, array $s, array $v): array {
    if ($d = chatModuleDelegate($conn, $u, $s, $v, true)) return $d;
    $module = $s['module'] ?? null;
    if (!$module) return ['reply' => '¿De qué quieres el conteo? Por ejemplo: «¿cuántas inasistencias van hoy?» o «¿cuántas tardanzas tuvo el 8A esta semana?»', '_natural' => true];
    $f = chatIncidentFilter($conn, $u, $s);
    if (isset($f['err'])) return $f['err'];
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $who = chatWho($f['student'], $f['group']);
    $count = function (string $from, string $to) use ($conn, $f) {
        $st = $conn->prepare("SELECT COUNT(*) {$f['from']} WHERE {$f['where']} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?");
        $st->execute(array_merge($f['params'], [$from, $to]));
        return (int)$st->fetchColumn();
    };
    $ent = array_filter(['module' => $module,
        'student' => $f['student'] ? mb_strtolower($f['student']['first_name'] . ' ' . $f['student']['last_name']) : null,
        'group' => $f['group']['group_name'] ?? ($f['student']['group_name'] ?? null)]);

    // ── comparación de períodos («ayer vs hoy», «esta semana vs la pasada») ──
    $periods = [];
    if (!empty($s['_compare_ranges']) && is_array($s['_compare_ranges'])) $periods = $s['_compare_ranges'];
    elseif (!empty($s['trend'])) {
        [$from, $to] = chatRange($s);
        $periods = [['from' => $from, 'to' => $to, 'range_label' => chatRangeLabel($s)], chatPreviousPeriod($from, $to, $s['range_label'] ?? null)];
    }
    if (count($periods) >= 2) {
        $rows = []; $vals = [];
        foreach ($periods as $pr) {
            $n = $count($pr['from'], $pr['to']);
            $vals[] = ['label' => $pr['range_label'] ?? "{$pr['from']} a {$pr['to']}", 'n' => $n];
            $rows[] = [$pr['range_label'] ?? "{$pr['from']} a {$pr['to']}", $pr['from'] === $pr['to'] ? $pr['from'] : "{$pr['from']} → {$pr['to']}", $n];
        }
        // semana/mes en curso vs período anterior completo: añadir el tramo comparable
        $l0 = (string)($periods[0]['range_label'] ?? ''); $l1 = (string)($periods[1]['range_label'] ?? '');
        if ((str_starts_with($l0, 'esta semana') && str_starts_with($l1, 'la semana pasada') && !str_contains($l1, 'mismo'))
            || (str_starts_with($l0, 'este mes') && str_starts_with($l1, 'el mes pasado') && !str_contains($l1, 'mismo'))) {
            $same = chatPreviousPeriod($periods[0]['from'], $periods[0]['to'], $l0);
            $n = $count($same['from'], $same['to']);
            array_splice($vals, 1, 0, [['label' => $same['range_label'], 'n' => $n]]);
            array_splice($rows, 1, 0, [[$same['range_label'], "{$same['from']} → {$same['to']}", $n]]);
        }
        $a = $vals[0]; $b = $vals[1];
        $diff = $a['n'] - $b['n'];
        $pct = $b['n'] > 0 ? round(abs($diff) * 100 / $b['n']) : null;
        if (count($periods) === 2 && !empty($s['_compare_ranges'])) {
            $hi = $a['n'] >= $b['n'] ? $a : $b; $lo = $a['n'] >= $b['n'] ? $b : $a;
            $reply = $a['n'] === $b['n']
                ? "Mismo número de {$mlabel}{$who}: {$a['n']} {$a['label']} y {$b['n']} {$b['label']}."
                : ucfirst("{$hi['label']} hubo más {$mlabel}{$who}: {$hi['n']}, frente a {$lo['n']} {$lo['label']}.");
        } else {
            $trendTxt = $diff === 0 ? 'igual que' : ($diff > 0 ? 'más que' : 'menos que');
            $reply = ucfirst("{$a['label']} van {$a['n']} {$mlabel}{$who}, {$trendTxt} {$b['label']} ({$b['n']})")
                . ($pct !== null && $diff !== 0 ? ' — ' . ($diff > 0 ? '+' : '−') . "{$pct}%." : '.');
            if (isset($vals[2])) $reply .= " {$vals[2]['label']} cerró con {$vals[2]['n']} en total.";
        }
        return ['reply' => $reply, '_natural' => true, 'entities' => $ent,
            'cards' => [['title' => ucfirst($mlabel) . ' — comparación', 'columns' => ['Período', 'Fechas', 'Total'], 'rows' => $rows]]];
    }

    [$from, $to] = chatRange($s);
    $n = $count($from, $to);
    $rl = chatRangeLabel($s);
    $just = !empty($s['justified']) ? ($s['justified'] === 'yes' ? ' con excusa' : ' sin excusa') : '';
    $out = ['entities' => $ent, '_natural' => true];
    if ($n === 0) {
        $out['reply'] = "No hay {$mlabel}{$just} registradas{$who} {$rl}.";
        // «sin tardanzas» con cero ingresos no es buena señal: es falta de datos
        if (in_array($module, ['LATE_ARRIVAL', 'EVASION_INTERNA'], true) && $from === $to && $from === nxToday()) {
            $pres = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
                WHERE be.school_id = ? AND be.event_type LIKE 'INGRESO%' AND " . chatD('be.event_timestamp') . " = ?");
            $pres->execute([$u['school_id'], $from]);
            if ((int)$pres->fetchColumn() === 0)
                $out['reply'] .= ' Ojo: tampoco hay ingresos registrados hoy, así que no hay datos para medir llegadas.';
        }
        if ($o = chatShortRangeOffer($s, 'count_events')) $out['_offer'] = $o;
        return $out;
    }
    $out['reply'] = ucfirst(trim("{$rl}: {$n} {$mlabel}{$just}{$who}.")) ;
    if ($f['student'] && $n >= 2) {
        $out['reply'] .= ' Ya cruza el umbral de atención.';
        $out['actions'] = chatDerivedActions($u, $f['student'], $mlabel);
    }
    if (!$f['student']) $out['_offer'] = chatOffer('¿Quieres ver el detalle?', 'list_events', array_intersect_key($s, array_flip(['module', 'group', 'grade', 'justified', 'days', 'from', 'to', 'range_label'])));
    if ($o = chatShortRangeOffer($s, 'count_events')) $out['_offer'] = $o;
    return $out;
}

function chat_list_events(PDO $conn, array $u, array $s, array $v): array {
    if ($d = chatModuleDelegate($conn, $u, $s, $v)) return $d;
    $module = $s['module'] ?? null;
    if (!$module) return ['reply' => '¿Qué quieres ver? Por ejemplo: «inasistencias de hoy», «tardanzas del 8A esta semana».', '_natural' => true];
    $f = chatIncidentFilter($conn, $u, $s);
    if (isset($f['err'])) return $f['err'];
    [$from, $to] = chatRange($s);
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $rl = chatRangeLabel($s);
    $who = chatWho($f['student'], $f['group']);
    $just = !empty($s['justified']) ? ($s['justified'] === 'yes' ? ' con excusa' : ' sin excusa') : '';
    $dateW = " AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?";
    $params = array_merge($f['params'], [$from, $to]);
    $tot = $conn->prepare("SELECT COUNT(*) {$f['from']} WHERE {$f['where']}{$dateW}");
    $tot->execute($params); $total = (int)$tot->fetchColumn();
    $ent = array_filter(['module' => $module, 'range_label' => $s['range_label'] ?? null, 'days' => $s['days'] ?? null,
        'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null,
        'student' => $f['student'] ? mb_strtolower($f['student']['first_name'] . ' ' . $f['student']['last_name']) : null,
        'group' => $f['group']['group_name'] ?? null, 'justified' => $s['justified'] ?? null], fn($x) => $x !== null);
    if ($total === 0) {
        $out = ['reply' => "No hay {$mlabel}{$just} registradas{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
        if ($o = chatShortRangeOffer($s, 'list_events')) $out['_offer'] = $o;
        return $out;
    }
    $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, ag.group_name,
            " . chatTs('ai.detected_at') . " AS ts, " . chatExcuseExpr() . " AS excuse
        {$f['from']} WHERE {$f['where']}{$dateW}
        ORDER BY ai.detected_at DESC, s.last_name LIMIT 500");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $withExcuse = count(array_filter($rows, fn($r) => !empty($r['excuse'])));
    $single = $from === $to;
    $cols = $single ? ['#', 'Estudiante', 'Grupo', 'Hora', 'Excusa'] : ['#', 'Estudiante', 'Grupo', 'Fecha', 'Excusa'];
    $tableRows = array_map(fn($i, $r) => [$i + 1, $r['name'], $r['group_name'] ?? '—',
        $single ? substr((string)$r['ts'], 11, 5) : (string)$r['ts'],
        $r['excuse'] ? mb_strimwidth((string)$r['excuse'], 0, 50, '…') : 'Sin excusa'], array_keys($rows), $rows);
    $reply = ucfirst(trim("{$rl}: {$total} {$mlabel}{$just}{$who}"));
    // listas grandes: el patrón importa más que los nombres
    if ($total > 25 && !$f['student'] && !$f['group']) {
        $gq = $conn->prepare("SELECT COALESCE(ag.group_name, 'Sin grupo') g, COUNT(*) c {$f['from']} WHERE {$f['where']}{$dateW}
            GROUP BY 1 ORDER BY c DESC, g LIMIT 3");
        $gq->execute($params);
        $top = array_map(fn($r) => "{$r['g']} ({$r['c']})", $gq->fetchAll(PDO::FETCH_ASSOC));
        if ($top) $reply .= ' — los grupos con más: ' . implode(', ', $top);
    }
    $reply .= '.';
    if (empty($s['justified'])) $reply .= $withExcuse ? " {$withExcuse} tienen excusa registrada." : ' Ninguna tiene excusa registrada.';
    if ($total > count($rows)) $reply .= ' La tabla muestra las ' . count($rows) . ' más recientes.';
    $out = ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => ucfirst($mlabel) . ($just ? " ({$just})" : '') . " — {$rl}", 'columns' => $cols, 'rows' => $tableRows]],
        'entities' => $ent,
        '_result_set' => ['type' => 'events', 'label' => "{$mlabel} {$rl}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'], 'sub' => ($r['group_name'] ?? '—') . ' · ' . $r['ts']], $rows),
            'count' => $total, '_filters' => $ent]];
    if ($f['student'] && $total >= 2) $out['actions'] = chatDerivedActions($u, $f['student'], $mlabel);
    return $out;
}

function chat_student_field(PDO $conn, array $u, array $s, array $v): array {
    $found = chatResolveStudent($conn,$u,$s['student'] ?? null);
    if (!$found) {
        $sf = chatResolveStaff($conn, $u, (string)($s['student'] ?? ''), $v['_q'] ?? null);
        if (count($sf) === 1) return chatStaffProfile($conn, $u, $sf[0]);
        return ['reply'=>"¿De qué estudiante hablas? Dame nombre o apellido.",'_natural'=>true];
    }
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
    if(!$found) {
        // puede ser personal de la institución («perfil de Martha Gómez»)
        $sf = chatResolveStaff($conn, $u, (string)($s['student'] ?? ''), $v['_q'] ?? null);
        if (count($sf) === 1) return chatStaffProfile($conn, $u, $sf[0]);
        if (count($sf) > 1) return ['reply'=>'No la encuentro como estudiante; como personal hay varias: '
            . implode(' · ', array_map(fn($r)=>"{$r['first_name']} {$r['last_name']} (" . (NX_ROLE_ES[$r['role_name']] ?? $r['role_name']) . ')', $sf)) . '. ¿Cuál?', '_natural'=>true];
        return ['reply'=>"¿De qué estudiante hablas? Dame nombre o apellido — y el grupo si hay homónimos.",'_natural'=>true];
    }
    if(count($found)>1) return chatAmbiguous($found);
    $st=$found[0];
    // conteos últimos 30 días
    $cnt=$conn->prepare("SELECT incident_type, COUNT(*) FROM attendance_incidents
        WHERE student_id=? AND " . chatD('detected_at') . " >= ? GROUP BY incident_type");
    $cnt->execute([$st['student_id'], nxToday(30)]);
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
    $g = chatResolveGroup($conn, $u, $s['group'] ?? '');
    if (!$g) return ['reply' => '¿Qué grupo? Por ejemplo: «¿cómo va el 7A?».', '_natural' => true];
    if (in_array($u['role'], ['TEACHER', 'COUNSELOR'], true)) {
        $chk = $conn->prepare("SELECT 1 FROM teacher_group_access WHERE teacher_user_id = ? AND group_id = ? LIMIT 1");
        $chk->execute([$u['id'], $g['group_id']]);
        if (!$chk->fetchColumn()) return ['reply' => "El grupo {$g['group_name']} no está en tu alcance — solo puedo mostrarte los tuyos.", '_natural' => true];
    }
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $n = $conn->prepare("SELECT COUNT(*) FROM student_group_assignments sga JOIN students s ON s.student_id = sga.student_id
        WHERE sga.group_id = ? AND sga.active = TRUE AND s.deleted_at IS NULL");
    $n->execute([$g['group_id']]); $total = (int)$n->fetchColumn();
    $inc = $conn->prepare("SELECT ai.incident_type, COUNT(*) c, COUNT(DISTINCT ai.student_id) st
        FROM attendance_incidents ai " . chatIncGroupJoin() . "
        WHERE ai.school_id = ? AND " . chatIncGroupCol() . " = ? AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?
        GROUP BY ai.incident_type");
    $inc->execute([$u['school_id'], $g['group_id'], $from, $to]);
    $by = []; $abSt = 0;
    foreach ($inc->fetchAll(PDO::FETCH_ASSOC) as $r) { $by[$r['incident_type']] = (int)$r['c']; if ($r['incident_type'] === 'INASISTENCIA') $abSt = (int)$r['st']; }
    $pr = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
        JOIN student_group_assignments sga ON sga.student_id = be.student_id AND sga.active = TRUE AND sga.group_id = ?
        WHERE be.school_id = ? AND be.event_type LIKE 'INGRESO%' AND " . chatD('be.event_timestamp') . " BETWEEN ? AND ?");
    $pr->execute([$g['group_id'], $u['school_id'], $from, $to]); $present = (int)$pr->fetchColumn();
    $abs = $by['INASISTENCIA'] ?? 0; $late = $by['LATE_ARRIVAL'] ?? 0; $eva = $by['EVASION_INTERNA'] ?? 0;
    $single = $from === $to;
    if (!empty($s['_attendance_q']) && $single) {
        if ($total > 0 && $abSt >= $total) $reply = "En {$g['group_name']} no asistió nadie {$rl}: los {$total} estudiantes tienen inasistencia.";
        elseif ($present === 0 && $abSt === 0) $reply = "En {$g['group_name']} no hay ingresos ni inasistencias registradas {$rl} — todavía no hay datos de asistencia para ese grupo.";
        else {
            $reply = "En {$g['group_name']} {$rl} asistieron {$present} de {$total} y faltaron {$abSt}.";
            if ($abSt > 0 && $abSt <= 6) {
                $nm = $conn->prepare("SELECT DISTINCT s.first_name || ' ' || s.last_name FROM attendance_incidents ai
                    JOIN students s ON s.student_id = ai.student_id " . chatIncGroupJoin() . "
                    WHERE ai.school_id = ? AND ai.incident_type = 'INASISTENCIA' AND " . chatIncGroupCol() . " = ?
                      AND " . chatD('ai.detected_at') . " = ?");
                $nm->execute([$u['school_id'], $g['group_id'], $from]);
                $names = $nm->fetchAll(PDO::FETCH_COLUMN);
                if ($names) $reply .= ' Faltaron: ' . implode(', ', $names) . '.';
            }
        }
    } else {
        $reply = "{$g['group_name']} ({$total} estudiantes), {$rl}: "
            . ($single ? "{$present} presentes, " : '') . "{$abs} inasistencias, {$late} llegadas tarde y {$eva} evasiones.";
        if ($single && $present === 0 && $abs >= $total && $total > 0) $reply .= ' Todo el grupo aparece ausente — revisa si el sensor del aula está reportando.';
    }
    $cols = $single ? ['Estudiantes', 'Presentes', 'Inasistencias', 'Tardanzas', 'Evasiones'] : ['Estudiantes', 'Inasistencias', 'Tardanzas', 'Evasiones'];
    $row = $single ? [$total, $present, $abs, $late, $eva] : [$total, $abs, $late, $eva];
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "{$g['group_name']} — {$rl}", 'columns' => $cols, 'rows' => [$row]]],
        'entities' => ['group' => $g['group_name']],
        '_offer' => $abs > 0 ? chatOffer("¿Quieres ver quiénes faltaron en {$g['group_name']}?", 'list_events',
            ['module' => 'INASISTENCIA', 'group' => $g['group_name'], 'from' => $from, 'to' => $to, 'range_label' => $s['range_label'] ?? $rl]) : null];
}

function chat_risk_students(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    // filtro de grupo explícito («estudiantes de 10A pasaron el umbral») —
    // el umbral del motor de riesgo no se degrada a «todos del grupo»
    $groupFilter = '';
    if (!empty($s['group']) && ($g = chatResolveGroup($conn, $u, $s['group']))) {
        $groupFilter = ' AND sga.group_id=' . $conn->quote($g['group_id']);
    }
    $st=$conn->prepare("SELECT s.student_id, s.first_name||' '||s.last_name AS name, ag.group_name, bm.risk_level, bm.risk_score
        FROM student_behavior_metrics bm JOIN students s ON s.student_id=bm.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE bm.school_id=? AND bm.risk_level IN ('HIGH','CRITICAL') {$scope['sql']}{$groupFilter}
        ORDER BY bm.risk_score DESC LIMIT 12");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $gLabel = !empty($s['group']) ? " en {$s['group']}" : '';
    if(!$rows) return ['reply'=>"El motor de riesgo no tiene alertas activas{$gLabel} — ningún patrón supera los umbrales configurados."];
    return ['reply'=>count($rows)." estudiante(s) que superaron el umbral de alerta{$gLabel}:",
        'cards'=>[['title'=>'Riesgo activo','columns'=>['Estudiante','Grupo','Nivel','Score'],
        'rows'=>array_map(fn($r)=>[$r['name'],$r['group_name']??'—',$r['risk_level'],$r['risk_score']],$rows)]],
        '_result_set'=>['type'=>'students','label'=>"en riesgo{$gLabel}",
            'items'=>array_map(fn($r)=>['id'=>$r['student_id'],'label'=>$r['name'],'sub'=>($r['group_name']??'—')." · {$r['risk_level']}"],$rows),
            'count'=>count($rows)],
        'actions'=>chatDerivedActions($u,null,'riesgo')];
}

function chat_trackings(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $w = ['t.school_id = ?']; $p = [$u['school_id']]; $stu = null;
    $status = $s['status'] ?? 'active';
    if ($status === 'completed') $w[] = "t.status IN ('resuelto','descartado')";
    elseif ($status !== 'all') $w[] = "t.status IN ('en proceso','escalado')";
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0]; $w[] = 't.student_id = ?'; $p[] = $stu['student_id'];
        // con estudiante, el historial completo importa más que solo lo abierto
        if (!isset($s['status'])) array_pop($w);
    }
    if (!empty($s['group']) && !$stu && ($g = chatResolveGroup($conn, $u, $s['group']))) { $w[] = 'ag.group_id = ?'; $p[] = $g['group_id']; }
    $st = $conn->prepare("SELECT t.tracking_id, s.first_name || ' ' || s.last_name AS name, ag.group_name, t.dependency, t.status,
            t.origin_type, " . chatTs('t.created_at') . " AS since, " . chatTs('t.updated_at') . " AS upd,
            ua.first_name || ' ' || ua.last_name AS assigned
        FROM student_tracking t JOIN students s ON s.student_id = t.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users ua ON ua.user_id = t.assigned_to_user_id
        WHERE " . implode(' AND ', $w) . " {$scope['sql']} ORDER BY t.created_at DESC LIMIT 60");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $who = $stu ? " de {$stu['first_name']} {$stu['last_name']}" : '';
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null, 'module' => 'SEGUIMIENTO']);
    $lbl = $status === 'completed' ? 'cerrados' : ($status === 'all' || ($stu && !isset($s['status'])) ? 'registrados' : 'abiertos');
    if (!$rows) return ['reply' => "No hay seguimientos {$lbl}{$who}.", 'entities' => $ent, '_natural' => true];
    // detalle del caso: un estudiante → notas del proceso (qué se ha hecho)
    if ($stu) {
        $ids = array_column($rows, 'tracking_id');
        $nt = $conn->prepare("SELECT " . chatTs('n.created_at') . " AS ts, u2.first_name || ' ' || u2.last_name AS author, n.note_text
            FROM student_tracking_notes n LEFT JOIN users u2 ON u2.user_id = n.user_id
            WHERE n.tracking_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY n.created_at DESC LIMIT 15");
        $nt->execute($ids); $notes = $nt->fetchAll(PDO::FETCH_ASSOC);
        $c0 = $rows[0];
        $reply = "{$c0['name']} ({$c0['group_name']}) tiene " . count($rows) . ' seguimiento' . (count($rows) === 1 ? '' : 's')
            . ". El más reciente es de {$c0['dependency']}, " . ($c0['status'] ?? '—') . " desde el " . substr((string)$c0['since'], 0, 10)
            . ($c0['assigned'] ? ", a cargo de {$c0['assigned']}" : '') . '.'
            . ($notes ? ' Última nota (' . substr((string)$notes[0]['ts'], 0, 10) . '): «' . mb_strimwidth((string)$notes[0]['note_text'], 0, 140, '…') . '».'
                      : ' Aún no tiene notas registradas.');
        $cards = [['title' => "Seguimientos{$who}", 'columns' => ['Origen', 'Estado', 'Desde', 'A cargo', 'Última actualización'],
            'rows' => array_map(fn($r) => [$r['dependency'] ?? '—', $r['status'], substr((string)$r['since'], 0, 10), $r['assigned'] ?? '—', $r['upd'] ?? '—'], $rows)]];
        if ($notes) $cards[] = ['title' => 'Notas del proceso', 'columns' => ['Fecha', 'Autor', 'Nota'],
            'rows' => array_map(fn($n) => [$n['ts'], $n['author'] ?? '—', mb_strimwidth((string)$n['note_text'], 0, 160, '…')], $notes)];
        return ['reply' => $reply, 'cards' => $cards, 'entities' => $ent, '_natural' => true,
            'actions' => chatDerivedActions($u, $stu, 'seguimiento')];
    }
    $bySt = array_count_values(array_map(fn($r) => (string)$r['dependency'], $rows));
    arsort($bySt);
    $orig = implode(', ', array_map(fn($k, $c) => "{$c} de " . ($k ?: 'sin origen'), array_keys($bySt), $bySt));
    return ['reply' => count($rows) . " seguimientos {$lbl}: {$orig}.", '_natural' => true,
        'cards' => [['title' => 'Seguimientos', 'columns' => ['Estudiante', 'Grupo', 'Origen', 'Estado', 'Desde', 'A cargo'],
            'rows' => array_map(fn($r) => [$r['name'], $r['group_name'] ?? '—', $r['dependency'] ?? '—', $r['status'] ?? '—', substr((string)$r['since'], 0, 10), $r['assigned'] ?? '—'], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'trackings', 'label' => 'seguimientos',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'], 'sub' => ($r['group_name'] ?? '—') . ' · ' . ($r['status'] ?? '')], $rows),
            'count' => count($rows)]];
}

function chat_permissions(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    // historial cuando hay rango/estudiante/status=all — «activos ahora»
    // solo si no se pidió rango ni estudiante
    $hist = ($s['status'] ?? null) === 'all'
        || !empty($s['student']) || isset($s['days']) || !empty($s['from']) || !empty($s['range_label']);
    $w = ['x.school_id = ?']; $p = [$u['school_id']];
    $stu = null; $g = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance — revisa el nombre o dime su grupo.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
        $w[] = 'x.student_id = ?'; $p[] = $stu['student_id'];
    }
    [$from, $to] = chatRange($s);
    if ($hist) { $w[] = chatD('x.exit_time') . ' BETWEEN ? AND ?'; $p[] = $from; $p[] = $to; }
    elseif (($s['status'] ?? null) !== 'all') { $w[] = "x.status = 'ACTIVE'"; }
    if (($s['status'] ?? null) === 'completed') { $w[] = "x.status <> 'ACTIVE'"; }
    if (!empty($s['group']) && !$stu && ($g = chatResolveGroup($conn, $u, $s['group']))) { $w[] = 'ag.group_id = ?'; $p[] = $g['group_id']; }
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    // union: salidas de clase (con retorno) + salidas del colegio
    $from_sql = "FROM (
        SELECT c.student_id, c.school_id, c.authorization_reason AS reason, c.exit_time,
               COALESCE(c.actual_return_time, c.return_time) AS return_time, c.status, 'clase' AS kind,
               ub.first_name || ' ' || ub.last_name AS issuer
        FROM class_exit_authorizations c LEFT JOIN users ub ON ub.user_id = c.authorized_by_user_id
        UNION ALL
        SELECT se.student_id, se.school_id, se.authorization_reason, se.exit_time,
               se.actual_return_time, se.status, 'colegio' AS kind,
               ub.first_name || ' ' || ub.last_name
        FROM school_exit_authorizations se LEFT JOIN users ub ON ub.user_id = se.authorized_by_user_id
    ) x
    JOIN students st ON st.student_id = x.student_id
    LEFT JOIN student_group_assignments sga ON sga.student_id = x.student_id AND sga.active = TRUE
    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
    WHERE " . implode(' AND ', $w) . " {$scopeSql}";
    $cnt = $conn->prepare("SELECT COUNT(*) {$from_sql}"); $cnt->execute(array_merge($p, $scope['params'])); $total = (int)$cnt->fetchColumn();
    $st = $conn->prepare("SELECT x.*, st.first_name, st.last_name, ag.group_name,
            " . chatTs('x.exit_time') . " AS exit_local, " . chatTs('x.return_time') . " AS return_local
        {$from_sql} ORDER BY x.exit_time DESC LIMIT 300");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Salidas pedagógicas: una autorización por estudiante del grupo — se
    // agregan por destino+fecha para no reventar la card con N filas iguales.
    // «en curso» = ya salieron y aún no llega la hora de regreso.
    $tripStmt = $conn->prepare("SELECT destination, " . chatTs('departure_time') . " AS dep, " . chatTs('t.return_time') . " AS ret, purpose,
               metadata_json->>'group_name' AS trip_group, COUNT(*) AS n,
               COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS issuer
        FROM pedagogical_trip_authorizations t LEFT JOIN users ub ON ub.user_id = t.authorized_by_user_id
        WHERE t.school_id = ?"
        . ($hist ? " AND " . chatD('t.departure_time') . " BETWEEN ? AND ?"
                 : " AND t.departure_time <= NOW() AND (t.return_time IS NULL OR t.return_time >= NOW())")
        . ($stu ? " AND t.student_id = ?" : '')
        . " GROUP BY destination, departure_time, t.return_time, purpose, trip_group, issuer
        ORDER BY departure_time DESC LIMIT 20");
    $tp = $hist ? [$u['school_id'], $from, $to] : [$u['school_id']];
    if ($stu) $tp[] = $stu['student_id'];
    $tripStmt->execute($tp);
    $trips = $tripStmt->fetchAll(PDO::FETCH_ASSOC);

    $rl = $hist ? chatRangeLabel($s) : 'ahora';
    $who = chatWho($stu, $g);
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'module' => 'PERMISO', 'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'days' => $s['days'] ?? null]);
    if (!$total && !$trips) return ['reply' => $hist ? "No hay permisos{$who} {$rl}." : "No hay permisos activos ahora: nadie está fuera con autorización{$who}.",
        'entities' => $ent, '_natural' => true];
    $stEs = ['ACTIVE' => 'Activo', 'COMPLETED' => 'Regresó', 'EXPIRED' => 'Vencido', 'CANCELLED' => 'Cancelado'];
    $cards = [];
    if ($rows) $cards[] = ['title' => "Permisos{$who} — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Tipo', 'Motivo', 'Salida', 'Retorno', 'Estado', 'Autorizó'],
        'rows' => array_map(fn($r) => [trim($r['first_name'] . ' ' . $r['last_name']), $r['group_name'] ?? '—',
            $r['kind'] === 'colegio' ? 'Del colegio' : 'De clase', mb_strimwidth((string)($r['reason'] ?? '—'), 0, 45, '…'),
            $r['exit_local'], $r['return_local'] ?: '—', $stEs[$r['status']] ?? ($r['status'] ?? '—'), $r['issuer'] ?? '—'], $rows)];
    if ($trips) $cards[] = ['title' => 'Salidas pedagógicas', 'columns' => ['Destino', 'Grupo', 'Estudiantes', 'Salida', 'Regreso', 'Autorizó'],
        'rows' => array_map(fn($t) => [mb_strimwidth((string)($t['destination'] ?? '—'), 0, 40, '…'), $t['trip_group'] ?? '—', (int)$t['n'],
            $t['dep'], $t['ret'] ?: '—', $t['issuer']], $trips)];
    $byKind = array_count_values(array_map(fn($r) => $r['kind'], $rows));
    $head = $hist
        ? ucfirst("{$rl}: {$total} permisos{$who}") . ($rows ? ' (' . (int)($byKind['clase'] ?? 0) . ' de clase y ' . (int)($byKind['colegio'] ?? 0) . ' del colegio)' : '')
        : "{$total} " . ($total === 1 ? 'permiso activo' : 'permisos activos') . " ahora{$who}";
    if ($trips) $head .= ($total ? ' y ' : '') . count($trips) . ' salida' . (count($trips) === 1 ? '' : 's') . ' pedagógica' . (count($trips) === 1 ? '' : 's');
    $head .= '.';
    if ($total > count($rows)) $head .= ' La tabla muestra los ' . count($rows) . ' más recientes.';
    return ['reply' => $head, '_natural' => true, 'cards' => $cards, 'entities' => $ent,
        '_result_set' => ['type' => 'permissions', 'label' => "permisos{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'sub' => mb_strimwidth((string)($r['reason'] ?? '—'), 0, 40, '…') . ' · ' . $r['exit_local']], $rows),
            'count' => $total + count($trips)]];
}

function chat_citations(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $scope=chatScope($conn,$u);
    $w=['tm.school_id=?',chatD('tm.sent_at') . ' BETWEEN ? AND ?']; $p=[$u['school_id'],$from,$to];
    $stu=null;
    if (!empty($s['student'])) {
        $found=chatResolveStudent($conn,$u,$s['student']);
        if (!$found) return ['reply'=>"No encuentro a «{$s['student']}» dentro de tu alcance — revisa el nombre o dime su grupo."];
        if (count($found)>1) return chatAmbiguous($found);
        $stu=$found[0]; $w[]='tm.student_id=?'; $p[]=$stu['student_id'];
    }
    // docente/psy: solo las citaciones que ellos enviaron (scope honesto)
    if (in_array($u['role'],['TEACHER','COUNSELOR'],true)) { $w[]='tm.sender_user_id=?'; $p[]=$u['id']; }
    $sqlScoped = "SELECT " . chatTs('tm.sent_at') . " AS d, tm.message_content, tm.delivery_status,
            st2.first_name||' '||st2.last_name AS name, ag.group_name,
            g2.first_name||' '||g2.last_name AS guardian_name
        FROM twilio_messages tm
        LEFT JOIN students st2 ON st2.student_id=tm.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id=st2.student_id AND sga.active=TRUE
        LEFT JOIN academic_groups ag ON ag.group_id=sga.group_id
        LEFT JOIN guardians gd ON gd.guardian_id=tm.guardian_id
        LEFT JOIN users g2 ON g2.user_id=gd.user_id
        WHERE " . implode(' AND ', $w) . " AND tm.type_code='CITACION' "
        . preg_replace('/\bs\./','st2.',(string)$scope['sql'])
        . " ORDER BY tm.sent_at DESC LIMIT 40";
    $st=$conn->prepare($sqlScoped); $st->execute(array_merge($p,$scope['params']));
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s);
    $who = $stu ? " de {$stu['first_name']} {$stu['last_name']}" : '';
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'module' => 'CITACION', 'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'days' => $s['days'] ?? null]);
    if (!$rows) return ['reply' => "No hay citaciones{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
    $stEs = ['QUEUED' => 'En cola', 'PROCESSING' => 'Enviando', 'SENT' => 'Enviada', 'DELIVERED' => 'Entregada', 'READ' => 'Leída',
        'FAILED' => 'Falló (reintenta)', 'FAILED_PERMANENT' => 'Falló definitivo', 'UNDELIVERED' => 'No entregada', 'RECEIVED' => 'Respuesta recibida'];
    $failed = count(array_filter($rows, fn($r) => in_array(strtoupper((string)$r['delivery_status']), ['FAILED', 'FAILED_PERMANENT', 'UNDELIVERED'], true)));
    $n = count($rows);
    return ['reply' => ucfirst("{$rl}: {$n} " . ($n === 1 ? 'citación' : 'citaciones') . "{$who}") . ($failed ? ", {$failed} con envío fallido." : '.'),
        '_natural' => true,
        'cards' => [['title' => "Citaciones{$who} — {$rl}", 'columns' => ['Fecha', 'Estudiante', 'Grupo', 'Acudiente', 'Mensaje', 'Estado'],
            'rows' => array_map(fn($r) => [$r['d'], $r['name'] ?? '—', $r['group_name'] ?? '—', $r['guardian_name'] ?? '—',
                mb_strimwidth((string)($r['message_content'] ?? '—'), 0, 60, '…'), $stEs[strtoupper((string)$r['delivery_status'])] ?? ($r['delivery_status'] ?? '—')], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'citations', 'label' => "citaciones{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'] ?? '—', 'sub' => $r['d'] . ' · ' . mb_strimwidth((string)($r['message_content'] ?? ''), 0, 40, '…')], $rows),
            'count' => $n]];
}

function chat_devices_status(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT device_name, location, status, configured, last_ping FROM edge_devices WHERE school_id=? AND active=TRUE ORDER BY device_name");
    $st->execute([$u['school_id']]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows) return ['reply'=>'No hay sensores registrados todavía — se registran desde «Sensores».','_natural'=>true];
    // «por configurar» ≠ «caído»: un sensor auto-provisionado nunca ha
    // reportado — mezclarlo con nodos caídos pinta una emergencia falsa.
    $pending = array_filter($rows, fn($r) => empty($r['configured']));
    $off = array_filter($rows, fn($r) => !empty($r['configured'])
        && (!$r['last_ping'] || strtotime($r['last_ping']) < time() - 600));
    $parts = [];
    if (count($pending)) $parts[] = count($pending).' por configurar (instalación pendiente)';
    if (count($off))     $parts[] = '⚠ '.count($off).' sin reportar en los últimos 10 min';
    $reply = count($rows).' sensores registrados — '
        . ($parts ? implode(' · ', $parts) : 'todos configurados y reportando') . '.';
    return ['reply'=>$reply,'_natural'=>true,'cards'=>[['title'=>'Sensores','columns'=>['Nombre','Ubicación','Estado','Último ping'],
        'rows'=>array_map(fn($r)=>[$r['device_name'],$r['location']??'—',
            empty($r['configured']) ? 'Por configurar'
                : (($r['last_ping'] && strtotime($r['last_ping']) >= time()-600) ? 'En línea' : 'Sin reportar'),
            $r['last_ping']?(new DateTime($r['last_ping']))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i'):'—'],$rows)]]];
}

function chat_notifications(PDO $conn, array $u, array $s, array $v): array {
    $tc = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $tc->execute([$u['id']]); $total = (int)$tc->fetchColumn();
    if ($total === 0) return ['reply' => 'No tienes notificaciones sin leer.', '_natural' => true];
    // «analiza mis notificaciones y dime a cuáles dar prioridad alta,
    // media y baja» — clasificación ítem a ítem de TODA la bandeja:
    // la tabla lleva cada aviso, nunca un subconjunto truncado.
    if (!empty($s['_priority'])) {
        $st = $conn->prepare("SELECT notification_id, title, message, type, created_at
            FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY created_at ASC LIMIT 200");
        $st->execute([$u['id']]);
        $notifs = $st->fetchAll(PDO::FETCH_ASSOC);
        $prio = ['alta' => [], 'media' => [], 'baja' => []];
        $now = time();
        foreach ($notifs as $n) {
            $txt = mb_strtolower(($n['title'] ?? '') . ' ' . ($n['message'] ?? ''));
            $type = strtoupper((string)$n['type']);
            $ageH = ($now - strtotime((string)$n['created_at'])) / 3600;
            // alta: emergencia, seguridad del estudiante, escalaciones sin
            // respuesta, anomalías operativas
            $p = 'baja';
            if ($type === 'URGENT'
                || preg_match('/\b(sos|panico|emergencia|urgente|critico|evasion|salio sin permiso|no autorizada|accidente|lesion|altercado|pelea|agresion|amenaza|anomalia|concentrad\w*|sin respuesta|no responde|sin atender|escalad\w*|reincid\w*)\b/u', $txt))
                $p = 'alta';
            // media: operación que requiere gestión — tardanzas, faltas,
            // citaciones, permisos, dispositivos caídos, entregas fallidas
            elseif ($type === 'WARNING' || $type === 'ALERT'
                || preg_match('/\b(tardanza|inasistencia|falta|ausencia|citacion|permiso|riesgo|seguimiento|incidente|fallo|rechazo|devolucion|no entregado|sin reportar|offline|caid\w*|desconectad\w*|no reporta)\b/u', $txt))
                $p = 'media';
            // un aviso de recuperación/resuelto/informativo baja aunque el
            // tipo sea ALERT — no exige acción
            if (preg_match('/\b(recuperad\w*|restablecid\w*|resuelt\w*|volvio a reportar|solucionad\w*|normalizad\w*|informativ\w*|recordatorio|bienvenida|confirmacion|exito|completad\w*)\b/u', $txt)
                && $p !== 'alta')
                $p = 'baja';
            // envejecimiento: lo que lleva >3 días sin atender sube un nivel
            if ($ageH > 72 && $p === 'baja') $p = 'media';
            $prio[$p][] = $n;
        }
        $typeEs = ['ALERT'=>'Alerta','WARNING'=>'Advertencia','INFO'=>'Informativa','SUCCESS'=>'Confirmación','URGENT'=>'Urgente'];
        $rows = [];
        $order = ['alta','media','baja'];
        foreach ($order as $p) foreach ($prio[$p] as $n)
            $rows[] = [ucfirst($p), $n['title'] ?: '—',
                mb_strlen((string)$n['message']) > 90 ? mb_substr((string)$n['message'], 0, 87) . '…' : (string)$n['message'],
                $typeEs[$n['type']] ?? $n['type'], (string)$n['created_at']];
        $na = count($prio['alta']); $nm = count($prio['media']); $nb = count($prio['baja']);
        $reply = "Analicé tus {$total} notificaciones sin leer: {$na} de prioridad **alta**, {$nm} **media** y {$nb} **baja** — la tabla completa está abajo.";
        if ($na) $reply .= ' Las urgentes conviene atenderlas primero.';
        return ['reply' => $reply, '_natural' => true,
            'cards' => [['title' => 'Notificaciones por prioridad', 'columns' => ['Prioridad','Notificación','Detalle','Tipo','Recibida'], 'rows' => $rows]],
            '_result_set' => ['type' => 'notifications', 'label' => 'notificaciones', 'entity' => 'notification',
                'items' => array_map(fn($n) => ['id' => $n['notification_id'], 'label' => $n['title'] ?: '—'], $notifs),
                'count' => count($notifs)],
            'actions' => [['kind' => 'nav', 'label' => 'Ver todas', 'to' => '/notificaciones']]];
    }
    // categorías = mismo título (el patrón real del aviso) + tipo
    $st = $conn->prepare("SELECT title, type, COUNT(*) c, " . chatTs('MIN(created_at)') . " AS first, " . chatTs('MAX(created_at)') . " AS last
        FROM notifications WHERE user_id = ? AND read_at IS NULL
        GROUP BY title, type ORDER BY c DESC, MAX(created_at) DESC LIMIT 15");
    $st->execute([$u['id']]); $cats = $st->fetchAll(PDO::FETCH_ASSOC);
    $typeEs = ['ALERT' => 'Alerta', 'WARNING' => 'Advertencia', 'INFO' => 'Informativa', 'SUCCESS' => 'Confirmación', 'URGENT' => 'Urgente'];
    $top = $cats[0];
    $share = round($top['c'] * 100 / $total);
    $reply = "Tienes {$total} notificaciones sin leer en " . count($cats) . ' ' . (count($cats) === 1 ? 'categoría' : 'categorías') . '.';
    if ($top['c'] > 1) $reply .= " La principal es «{$top['title']}» con {$top['c']} ({$share}%)";
    // patrón: avisos repetidos por grupo (anomalías, nodos) → qué grupos
    $groups = [];
    if ($top['c'] > 2) {
        $gq = $conn->prepare("SELECT COALESCE(substring(message from 'Grupo ([^:]+):'), substring(message from '\(([^)]+)\)')) g, COUNT(*) c
            FROM notifications WHERE user_id = ? AND read_at IS NULL AND title = ? GROUP BY 1 ORDER BY c DESC LIMIT 30");
        $gq->execute([$u['id'], $top['title']]);
        $groups = array_values(array_filter($gq->fetchAll(PDO::FETCH_ASSOC), fn($r) => $r['g'] !== null && $r['g'] !== ''));
    }
    if ($top['c'] > 1) {
        if (count($groups) > 1) {
            $reply .= ', repartida en ' . count($groups) . ' grupos (' . implode(', ', array_slice(array_map(fn($r) => $r['g'], $groups), 0, 6))
                . (count($groups) > 6 ? '…' : '') . ')';
            $sameDay = substr((string)$top['first'], 0, 10) === substr((string)$top['last'], 0, 10);
            $reply .= $sameDay ? ', todas del ' . substr((string)$top['last'], 0, 10) . ' — es un mismo evento repetido por grupo.' : '.';
        } else $reply .= '.';
    }
    $rest = array_slice($cats, 1, 3);
    if ($rest) $reply .= ' Le siguen: ' . implode('; ', array_map(fn($c) => "«{$c['title']}» ({$c['c']})", $rest)) . '.';
    $cards = [['title' => 'Notificaciones sin leer por categoría', 'columns' => ['Categoría', 'Tipo', 'Cantidad', 'Primera', 'Última'],
        'rows' => array_map(fn($c) => [$c['title'], $typeEs[strtoupper((string)$c['type'])] ?? $c['type'], (int)$c['c'], $c['first'], $c['last']], $cats)]];
    if (count($groups) > 1)
        $cards[] = ['title' => "«{$top['title']}» por grupo", 'columns' => ['Grupo', 'Avisos'],
            'rows' => array_map(fn($r) => [$r['g'], (int)$r['c']], $groups)];
    return ['reply' => $reply, '_natural' => true, 'cards' => $cards,
        'actions' => [['kind' => 'nav', 'label' => 'Ver todas', 'to' => '/notificaciones']]];
}

function chat_audit(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $st = $conn->prepare("SELECT action_type AS event_type, COUNT(*) c FROM global_audit_logs
        WHERE school_id = ? AND " . chatD('created_at') . " BETWEEN ? AND ? GROUP BY action_type ORDER BY c DESC LIMIT 15");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s);
    if (!$rows) return ['reply' => "No hay actividad auditada {$rl}.", '_natural' => true];
    $sum = array_sum(array_column($rows, 'c'));
    return ['reply' => "Actividad auditada {$rl}: {$sum} eventos en " . count($rows) . ' tipos.', '_natural' => true,
        'cards' => [['title' => "Auditoría — {$rl}", 'columns' => ['Evento', 'Veces'], 'rows' => array_map(fn($r) => [chatAuditLabel($r['event_type']), (int)$r['c']], $rows)]]];
}

function chatAuditLabel(string $a): string {
    return ['CHAT_QUERY' => 'Consultas a Nexus', 'LOGIN' => 'Inicios de sesión', 'TERMS_ACCEPTED' => 'Aceptación de términos',
        'OPERATION' => 'Operaciones', 'CHAT_POLICY_UPDATE' => 'Cambio de políticas del asistente'][$a] ?? ucfirst(strtolower(str_replace('_', ' ', $a)));
}

function chat_students_count(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $st = $conn->prepare("SELECT COUNT(*) AS n, COUNT(DISTINCT sga.group_id) AS g,
            COUNT(*) FILTER (WHERE sga.group_id IS NULL) AS sin_grupo
        FROM students s LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.active = TRUE {$scope['sql']}");
    $st->execute(array_merge([$u['school_id']], $scope['params'])); $r = $st->fetch(PDO::FETCH_ASSOC);
    $n = (int)$r['n'];
    $where = $scope['sql'] ? 'en tus grupos' : 'matriculados en la institución';
    return ['reply' => "Hay {$n} estudiantes {$where}, en {$r['g']} grupos" . ((int)$r['sin_grupo'] ? " ({$r['sin_grupo']} sin grupo asignado)." : '.'),
        '_natural' => true, 'entities' => ['_scope_all' => true]];
}

function chat_groups_list(PDO $conn, array $u, array $s, array $v): array {
    $scope=chatScope($conn,$u);
    // «todos los grupos del colegio» = alcance INSTITUCIONAL explícito —
    // no filtrar por docente; «a mi cargo / mis grupos» (default docente)
    // sí filtra por teacher_group_access
    $allSchool = (bool)preg_match('/\b(todos? los grupos|todos? los cursos|del colegio|de la institucion|de todo el plantel|del plantel|todos los salones)\b/u', $v['_q'] ?? '');
    if (in_array($u['role'],['TEACHER','COUNSELOR'],true) && !$allSchool) {
        // «mis grupos» = los grupos ASIGNADOS al docente (§9-10), no todos
        // los que tienen algún docente — el scope es del usuario actual
        $st=$conn->prepare("SELECT ag.group_name, ag.grade_level, COUNT(sga.student_id) n FROM teacher_group_access tga
            JOIN academic_groups ag ON ag.group_id=tga.group_id
            LEFT JOIN student_group_assignments sga ON sga.group_id=ag.group_id AND sga.active=TRUE
            WHERE tga.teacher_user_id=? AND ag.school_id=? GROUP BY ag.group_id, ag.group_name, ag.grade_level ORDER BY ag.grade_level, ag.group_name");
        $st->execute([$u['id'],$u['school_id']]);
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
    $q = (string)($v['_q'] ?? '');
    $st = $conn->prepare("SELECT work_shift AS shift_name, entry_time, exit_time FROM school_schedule_config WHERE school_id = ? ORDER BY work_shift LIMIT 6");
    $st->execute([$u['school_id']]); $shifts = $st->fetchAll(PDO::FETCH_ASSOC);
    $gq = $conn->prepare("SELECT ag.group_name, ag.work_shift, COUNT(sc.schedule_id) AS blocks,
            COUNT(DISTINCT sc.subject_id) AS subjects, COUNT(DISTINCT sc.teacher_user_id) AS teachers
        FROM academic_groups ag LEFT JOIN schedules sc ON sc.group_id = ag.group_id
        WHERE ag.school_id = ? GROUP BY ag.group_id, ag.group_name, ag.work_shift, ag.grade_level
        ORDER BY CASE WHEN ag.grade_level ~ '^\d+$' THEN ag.grade_level::INT ELSE 999 END, ag.group_name");
    $gq->execute([$u['school_id']]); $groups = $gq->fetchAll(PDO::FETCH_ASSOC);
    $with = array_filter($groups, fn($g) => (int)$g['blocks'] > 0);
    $shiftTxt = $shifts ? implode('; ', array_map(fn($r) => "jornada {$r['shift_name']} de " . substr((string)$r['entry_time'], 0, 5) . ' a ' . substr((string)$r['exit_time'], 0, 5), $shifts)) : 'sin jornadas configuradas';
    $all = !empty($s['_all_groups']) || preg_match('/\b(todos (los )?grupos|cada grupo|de los grupos)\b/u', $q);
    if ($all || !$shifts) {
        if (!$with) return ['reply' => "Ningún grupo tiene horario de clases cargado todavía ({$shiftTxt}). Se carga en la configuración de horarios.", '_natural' => true];
        return ['reply' => count($with) . ' de ' . count($groups) . " grupos tienen horario cargado ({$shiftTxt}). Pídeme el de uno para verlo completo, por ejemplo: «horario del 8-A».",
            '_natural' => true,
            'cards' => [['title' => 'Horarios por grupo', 'columns' => ['Grupo', 'Jornada', 'Bloques/semana', 'Materias', 'Docentes'],
                'rows' => array_map(fn($g) => [$g['group_name'], $g['work_shift'] ?? '—', (int)$g['blocks'], (int)$g['subjects'], (int)$g['teachers']], $groups)]]];
    }
    return ['reply' => ucfirst($shiftTxt) . '. ' . count($with) . ' de ' . count($groups) . ' grupos tienen horario de clases; pídeme uno: «horario del 8-A».', '_natural' => true,
        'cards' => [['title' => 'Jornadas', 'columns' => ['Jornada', 'Entrada', 'Salida'],
            'rows' => array_map(fn($r) => [$r['shift_name'], substr((string)$r['entry_time'], 0, 5), substr((string)$r['exit_time'], 0, 5)], $shifts)]]];
}

function chat_export_data(PDO $conn, array $u, array $s, array $v): array {
    $fmt = (string)($s['_export_format'] ?? $s['export_format'] ?? '');
    $fmt = in_array($fmt, ['excel', 'pdf', 'word', 'csv'], true) ? $fmt : '';
    $hasRange = isset($s['days']) || !empty($s['from']) || !empty($s['to']);
    $module = $s['module'] ?? null;
    if (!$hasRange && $module)
        return ['reply' => '¿De qué fechas lo exporto? Por ejemplo: «de hoy», «de los últimos 15 días», «del mes pasado» o «del 1 al 15 de septiembre».',
                'intent' => 'clarify', '_natural' => true,
                'entities' => array_filter(['module' => $module, 'group' => $s['group'] ?? null, 'student' => $s['student'] ?? null, 'export_format' => $fmt ?: null])];
    $acts = function (string $title, string $from, string $to) use ($fmt) {
        $a = chatExportActions($title, $from, $to);
        if ($fmt) { $a = array_values(array_filter($a, fn($x) => $x['format'] === $fmt)); if ($a) $a[0]['auto'] = true; }
        return $a;
    };
    $fmtTxt = ['excel' => 'Excel', 'pdf' => 'PDF', 'word' => 'Word', 'csv' => 'CSV'][$fmt] ?? null;
    if (!$module) {
        if (!empty($s['group']) && ($g = chatResolveGroup($conn, $u, $s['group']))) {
            $scope = chatScope($conn, $u);
            $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, s.document_number AS doc, ag.group_name, s.grade_level, s.work_shift
                FROM students s JOIN student_group_assignments ga ON ga.student_id = s.student_id AND ga.active = TRUE
                JOIN academic_groups ag ON ag.group_id = ga.group_id
                WHERE s.school_id = ? AND ga.group_id = ? AND s.deleted_at IS NULL {$scope['sql']} ORDER BY s.last_name, s.first_name LIMIT 1000");
            $st->execute(array_merge([$u['school_id'], $g['group_id']], $scope['params']));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['reply' => "No encontré estudiantes en {$g['group_name']} para exportar.", '_natural' => true];
            $title = "Estudiantes {$g['group_name']}";
            return ['reply' => count($rows) . " estudiantes de {$g['group_name']}" . ($fmtTxt ? " — descargando en {$fmtTxt}." : ' listos para exportar.'), '_natural' => true,
                'cards' => [['title' => $title, 'columns' => ['Estudiante', 'Documento', 'Grupo', 'Grado', 'Jornada'],
                    'rows' => array_map(fn($r) => [$r['name'], $r['doc'], $r['group_name'], $r['grade_level'], $r['work_shift']], $rows)]],
                'actions' => $acts($title, nxToday(), nxToday())];
        }
        return ['reply' => '¿Qué exporto? Puedo generar reportes de inasistencias, tardanzas, evasiones o permisos con fechas, o la lista de estudiantes de un grupo.', '_natural' => true];
    }
    if (in_array($module, ['PERMISO', 'SALIDA_PEDAGOGICA', 'SALIDA_COLEGIO', 'CITACION', 'SEGUIMIENTO', 'SOS'], true)) {
        $d = chatModuleDelegate($conn, $u, $s + ['status' => 'all'], $v);
        if ($d && !empty($d['cards'])) {
            [$from, $to] = chatRange($s);
            $d['actions'] = array_merge($d['actions'] ?? [], $acts((string)$d['cards'][0]['title'], $from, $to));
            if ($fmtTxt) $d['reply'] = rtrim((string)$d['reply'], ':.') . " — descargando en {$fmtTxt}.";
        }
        return $d ?? ['reply' => 'No hay datos para exportar con esos filtros.', '_natural' => true];
    }
    $f = chatIncidentFilter($conn, $u, $s);
    if (isset($f['err'])) return $f['err'];
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, s.document_number AS doc, ag.group_name,
            " . chatTs('ai.detected_at') . " AS ts, " . chatExcuseExpr() . " AS excuse
        {$f['from']} WHERE {$f['where']} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?
        ORDER BY ai.detected_at DESC LIMIT 5000");
    $st->execute(array_merge($f['params'], [$from, $to]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $who = chatWho($f['student'], $f['group']);
    if (!$rows) return ['reply' => "No hay {$mlabel}{$who} {$rl} — nada que exportar.", '_natural' => true];
    $title = ucfirst($mlabel) . "{$who} ({$rl})";
    return ['reply' => count($rows) . " {$mlabel}{$who} {$rl}" . ($fmtTxt ? " — descargando en {$fmtTxt}." : '. Elige el formato:'), '_natural' => true,
        'cards' => [['title' => $title, 'columns' => ['Estudiante', 'Documento', 'Grupo', 'Fecha y hora', 'Excusa'],
            'rows' => array_map(fn($r) => [$r['name'], $r['doc'], $r['group_name'] ?? '—', $r['ts'], $r['excuse'] ?: 'Sin excusa'], $rows)]],
        '_result_set' => ['type' => 'events', 'label' => "{$mlabel} {$rl}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'], 'sub' => ($r['group_name'] ?? '—') . ' · ' . $r['ts']], array_slice($rows, 0, 400)),
            'count' => count($rows)],
        'actions' => $acts($title, $from, $to)];
}

/** Acciones de exportación sobre la card del mismo mensaje (spec client-side). */
function chatExportActions(string $title, string $from, string $to): array {
    $base = ['kind'=>'export','card'=>0,'title'=>$title,'from'=>$from,'to'=>$to];
    return [
        $base + ['format'=>'excel','label'=>'Descargar Excel'],
        $base + ['format'=>'pdf',  'label'=>'Descargar PDF'],
        $base + ['format'=>'word', 'label'=>'Descargar Word'],
    ];
}

/** Frecuencia de un evento por día de la semana (o por estudiante si se pide). */
function chat_frequency_table(PDO $conn, array $u, array $s, array $v): array {
    $module = $s['module'] ?? null;
    if (!$module) return ['reply' => '¿Frecuencia de qué? Por ejemplo: «tardanzas por día de la semana», «inasistencias por estudiante en 10A».', '_natural' => true];
    if ($d = chatModuleDelegate($conn, $u, $s, $v)) return $d;
    // sin rango explícito: una frecuencia necesita historia (30 días), no «hoy»
    if (empty($s['range_label']) && empty($s['from'])) $s += ['from' => nxToday(29), 'to' => nxToday(), 'range_label' => 'últimos 30 días', 'days' => 30];
    $f = chatIncidentFilter($conn, $u, $s);
    if (isset($f['err'])) return $f['err'];
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $who = chatWho($f['student'], $f['group']);
    $q = (string)($v['_q'] ?? '');
    $by = $s['group_by'] ?? null;
    if (!$by) {
        if (preg_match('/por (dias? de la semana)/u', $q)) $by = 'weekday';
        elseif (preg_match('/por (dia|fecha)\b/u', $q)) $by = 'day';
        elseif (preg_match('/por (estudiante|alumn|quien)/u', $q)) $by = 'student';
        elseif (preg_match('/por (grupo|salon|curso)/u', $q)) $by = 'group';
        elseif (preg_match('/por mes/u', $q)) $by = 'month';
        else $by = 'weekday';
    }
    $base = "{$f['from']} WHERE {$f['where']} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?";
    $params = array_merge($f['params'], [$from, $to]);
    $localTs = "(ai.detected_at AT TIME ZONE " . NX_TZ_SQL . ")";
    if ($by === 'group') {
        $sql = "SELECT COALESCE(ag.group_name, 'Sin grupo') AS k, COUNT(*) c {$base} GROUP BY 1 ORDER BY c DESC, k LIMIT 60";
        $cols = ['Grupo', 'Total']; $axis = 'por grupo';
    } elseif ($by === 'student') {
        $sql = "SELECT s.first_name || ' ' || s.last_name || ' (' || COALESCE(ag.group_name, '—') || ')' AS k, COUNT(*) c {$base}
                GROUP BY s.student_id, s.first_name, s.last_name, ag.group_name ORDER BY c DESC, s.last_name LIMIT 200";
        $cols = ['Estudiante', 'Total']; $axis = 'por estudiante';
    } elseif ($by === 'day') {
        $sql = "SELECT to_char(d::date, 'YYYY-MM-DD') AS k, COALESCE(x.c, 0) AS c
                FROM generate_series(?::date, ?::date, '1 day') d
                LEFT JOIN (SELECT {$localTs}::date AS dd, COUNT(*) c {$base} GROUP BY 1) x ON x.dd = d::date
                WHERE EXTRACT(ISODOW FROM d) < 6 OR COALESCE(x.c, 0) > 0
                ORDER BY d";
        $params = array_merge([$from, $to], $params);
        $cols = ['Fecha', 'Total']; $axis = 'por día';
    } elseif ($by === 'month') {
        $sql = "SELECT to_char({$localTs}, 'YYYY-MM') AS k, COUNT(*) c {$base} GROUP BY 1 ORDER BY 1";
        $cols = ['Mes', 'Total']; $axis = 'por mes';
    } else {
        $sql = "SELECT EXTRACT(ISODOW FROM {$localTs})::int AS k, COUNT(*) c {$base} GROUP BY 1 ORDER BY 1";
        $cols = ['Día', 'Total']; $axis = 'por día de la semana';
    }
    $st = $conn->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $dow = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    if ($by === 'weekday') $rows = array_map(fn($r) => ['k' => $dow[(int)$r['k']] ?? $r['k'], 'c' => $r['c']], $rows);
    $sum = array_sum(array_map(fn($r) => (int)$r['c'], $rows));
    if ($sum === 0) return ['reply' => "No hay {$mlabel} registradas{$who} {$rl}.", '_natural' => true];
    $top = $rows; usort($top, fn($a, $b) => (int)$b['c'] <=> (int)$a['c']);
    $reply = ucfirst("{$mlabel} {$axis}{$who} ({$rl}): {$sum} en total; el pico es {$top[0]['k']} con {$top[0]['c']}.");
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => ucfirst("{$mlabel} {$axis} — {$rl}"), 'columns' => $cols, 'rows' => array_map(fn($r) => [(string)$r['k'], (int)$r['c']], $rows)]],
        'entities' => array_filter(['module' => $module, 'group' => $f['group']['group_name'] ?? null, 'range_label' => $s['range_label'] ?? null,
            'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'days' => $s['days'] ?? null]),
        '_result_set' => ['type' => 'frequency', 'label' => "{$mlabel} {$axis}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => (string)$r['k'], 'sub' => "{$r['c']}"], $rows), 'count' => count($rows)]];
}

function chat_derive_action(PDO $conn, array $u, array $s, array $v): array {
    $student=null;
    if(!empty($s['student'])){
        $found=chatResolveStudent($conn,$u,$s['student']);
        if(!$found) return ['reply'=>"No encuentro a «{$s['student']}» dentro de tu alcance — revisa el nombre."];
        if(count($found)>1) return chatAmbiguous($found);
        $student=$found[0];
    }
    // qué acción pidió → el parser puede emitir entities.op explícita;
    // si no, el léxico determinista la deriva del texto
    $q=$v['_q']??'';
    $cmd = !empty($s['_op']) ? (string)$s['_op'] : chatOperationCmd($q);
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

/* ══ Intents de utilidad: aleatorios, staff, operaciones, conteos ══════════ */

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
/** Personal (no acudientes) por nombre: LIKE sin acentos + fonético. */
function chatResolveStaff(PDO $conn, array $u, string $name, ?string $q = null): array {
    $like = '%' . mb_strtolower(strtr($name, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'])) . '%';
    $base = "SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.work_shift, r.role_name
        FROM users u JOIN roles r ON r.role_id = u.role_id
        WHERE u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE
          AND r.role_name NOT IN ('GUARDIAN','SUPER_ADMIN','SYSTEM_WORKER')";
    $st = $conn->prepare("{$base} AND (translate(lower(u.first_name || ' ' || u.last_name),'áéíóúüñ','aeiouun') LIKE ?
            OR translate(lower(u.last_name || ' ' || u.first_name),'áéíóúüñ','aeiouun') LIKE ?) ORDER BY u.last_name LIMIT 5");
    $st->execute([$u['school_id'], $like, $like]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $want = array_values(array_filter(explode(' ', chatPhonetic($name)), fn($w) => strlen($w) >= 3));
        if (!$want) return [];
        $all = $conn->prepare($base . " LIMIT 500"); $all->execute([$u['school_id']]);
        $out = [];
        foreach ($all->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $have = explode(' ', chatPhonetic($r['first_name'] . ' ' . $r['last_name']));
            $hit = 0;
            foreach ($want as $w) foreach ($have as $h) if ($h === $w || (strlen($w) >= 5 && levenshtein($h, $w) <= 1)) { $hit++; break; }
            if ($hit === count($want)) $out[] = $r;
        }
        $rows = array_slice($out, 0, 5);
    }
    // hint de rol en la pregunta: «docente prueba» → TEACHER. Desempata
    // nombres compartidos por varios cargos («Docente Prueba» vs «Coord
    // Prueba») sin eliminar la ambigüedad cuando no hay compatibles.
    if (count($rows) > 1 && $q) {
        static $roleWords = ['docente'=>'TEACHER','profesor'=>'TEACHER','profe'=>'TEACHER','maestr'=>'TEACHER',
            'coordinador'=>'COORDINATOR','coordinadora'=>'COORDINATOR','coordinacion'=>'COORDINATOR',
            'rector'=>'RECTOR','rectora'=>'RECTOR','director'=>'RECTOR','directora'=>'RECTOR',
            'secretaria'=>'SECRETARY','secretario'=>'SECRETARY',
            'psicologo'=>'COUNSELOR','psicologa'=>'COUNSELOR','orientador'=>'COUNSELOR',
            'portero'=>'SECURITY','celador'=>'SECURITY','enfermera'=>'NURSE'];
        $qn = mb_strtolower(strtr($q, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']));
        foreach ($roleWords as $w => $role) {
            if (preg_match('/\b' . preg_quote($w, '/') . '/u', $qn)) {
                $pref = array_values(array_filter($rows, fn($r) => ($r['role_name'] ?? '') === $role));
                if ($pref) return $pref;
            }
        }
    }
    return $rows;
}

function chatStaffProfile(PDO $conn, array $u, array $p): array {
    $name = trim("{$p['first_name']} {$p['last_name']}");
    $g = $conn->prepare("SELECT string_agg(DISTINCT ag.group_name, ', ' ORDER BY ag.group_name) FROM teacher_group_access tga
        JOIN academic_groups ag ON ag.group_id = tga.group_id WHERE tga.teacher_user_id = ?");
    $g->execute([$p['user_id']]); $groups = $g->fetchColumn();
    $role = NX_ROLE_ES[$p['role_name']] ?? strtolower($p['role_name']);
    $reply = "{$name} — {$role}" . ($p['work_shift'] ? ", jornada {$p['work_shift']}" : ', sin jornada registrada')
        . ($groups ? ". Grupos: {$groups}" : '') . '.';
    $row = [$name, ucfirst($role), $p['work_shift'] ?: '—', $groups ?: '—'];
    $cols = ['Nombre', 'Cargo', 'Jornada', 'Grupos'];
    // contacto institucional solo para roles con visión global
    if (in_array($u['role'], ['RECTOR', 'COORDINATOR', 'SECRETARY'], true)) {
        $cols[] = 'Correo'; $cols[] = 'Teléfono';
        $row[] = $p['email'] ?: '—'; $row[] = $p['phone'] ?: '—';
    }
    return ['reply' => $reply, '_natural' => true, 'cards' => [['title' => "Perfil — {$name}", 'columns' => $cols, 'rows' => [$row]]],
        'entities' => ['person' => $name]];
}

/** Quién es el rector/coordinador/psicoorientador… o el perfil de una persona del personal. */
function chat_staff_lookup(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $person = $s['person'] ?? null;
    if ($person && !preg_match('/\b(coordinador|rector|psicolog|orientador|secretari|porter|docente|profesor)\w*$/u', nxNorm($person))) {
        $found = chatResolveStaff($conn, $u, $person, $v['_q'] ?? $q ?? null);
        if (count($found) === 1) return chatStaffProfile($conn, $u, $found[0]);
        if (count($found) > 1) return ['reply' => 'Encontré varias personas: ' . implode(' · ', array_map(fn($r) => "{$r['first_name']} {$r['last_name']} (" . (NX_ROLE_ES[$r['role_name']] ?? $r['role_name']) . ')', $found)) . '. ¿Cuál?', '_natural' => true];
    }
    $roleMap = ['rector' => 'RECTOR', 'directora' => 'RECTOR', 'director' => 'RECTOR', 'coordinador' => 'COORDINATOR', 'coordinadora' => 'COORDINATOR', 'coordinacion' => 'COORDINATOR',
        'psicolog' => 'COUNSELOR', 'orientador' => 'COUNSELOR', 'psicoorientador' => 'COUNSELOR', 'consejer' => 'COUNSELOR',
        'secretari' => 'SECRETARY', 'portero' => 'SECURITY', 'porteria' => 'SECURITY', 'celador' => 'SECURITY', 'auxiliar' => 'AUXILIARY', 'docente' => 'TEACHER', 'profesor' => 'TEACHER'];
    $role = $s['_staff_role'] ?? null;
    if (!$role) foreach ($roleMap as $k => $r) if (str_contains($q, $k)) { $role = $r; break; }
    $shift = $s['shift'] ?? null;
    if (!$shift && preg_match('/\b(jornada|turno)?\s*(de la )?(manana|tarde|noche)\b/u', $q, $m)) $shift = $m[3] === 'manana' ? 'mañana' : $m[3];
    $w = "u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE"; $p = [$u['school_id']];
    if ($role) { $w .= ' AND r.role_name = ?'; $p[] = $role; }
    // «qué personal trabaja en la mañana» — sin rol ni sesgo a directivo:
    // la jornada aplica a TODA la planta (docentes incluidos)
    elseif (!$shift) $w .= " AND r.role_name IN ('RECTOR','COORDINATOR','COUNSELOR','SECRETARY')";
    if ($shift) { $w .= " AND translate(lower(COALESCE(u.work_shift,'')),'ñ','n') = ?"; $p[] = $shift === 'mañana' ? 'manana' : $shift; }
    $st = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.work_shift, r.role_name FROM users u
        JOIN roles r ON r.role_id = u.role_id WHERE {$w} ORDER BY r.role_name, u.last_name LIMIT 30");
    $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => 'No encuentro personal registrado' . ($role ? ' en ' . (NX_ROLE_ES[$role] ?? strtolower($role)) : '') . ($shift ? " de la jornada de la {$shift}" : '') . '.', '_natural' => true];
    if (count($rows) === 1 && ($shift || $person)) return chatStaffProfile($conn, $u, $rows[0]);
    $bits = array_map(fn($r) => "{$r['first_name']} {$r['last_name']}" . ($r['work_shift'] ? " ({$r['work_shift']})" : ''), $rows);
    $lbl = $role ? ucfirst(NX_ROLE_ES[$role] ?? strtolower($role)) : 'Personal directivo';
    $noShift = count(array_filter($rows, fn($r) => !$r['work_shift']));
    $reply = "{$lbl}" . ($shift ? " (jornada {$shift})" : '') . ': ' . implode(', ', $bits) . '.'
        . ($noShift && !$shift ? " {$noShift} sin jornada registrada." : '');
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => $lbl, 'columns' => ['Nombre', 'Cargo', 'Jornada'],
            'rows' => array_map(fn($r) => ["{$r['first_name']} {$r['last_name']}", ucfirst(NX_ROLE_ES[$r['role_name']] ?? $r['role_name']), $r['work_shift'] ?: '—'], $rows)]],
        '_result_set' => ['type' => 'staff', 'label' => strtolower($lbl), 'count' => count($rows), '_filters' => array_filter(['role' => $role, 'shift' => $shift]),
            'items' => array_map(fn($r) => ['id' => $r['user_id'], 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => $r['work_shift'] ?: 'sin jornada',
                'shift' => $r['work_shift'], 'role' => $r['role_name']], $rows)]];
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
    $scope = chatScope($conn, $u); [$from, $to] = chatRange($s);
    $params = [$u['school_id'], $from, $to]; $extra = ''; $g = null;
    if (!empty($s['group']) && ($g = chatResolveGroup($conn, $u, $s['group']))) { $extra = ' AND sga.group_id = ?'; $params[] = $g['group_id']; }
    $st = $conn->prepare("SELECT COUNT(DISTINCT be.student_id) FROM biometric_events be
        JOIN students s ON s.student_id = be.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        WHERE be.school_id = ? AND " . chatD('be.event_timestamp') . " BETWEEN ? AND ? AND be.event_type LIKE 'INGRESO%' {$extra} {$scope['sql']}");
    $st->execute(array_merge($params, $scope['params'])); $n = (int)$st->fetchColumn();
    $rl = chatRangeLabel($s);
    $who = $g ? " en {$g['group_name']}" : ($scope['sql'] ? ' en tus grupos' : '');
    return ['reply' => $n ? ucfirst("{$rl} ingresaron {$n} estudiantes{$who}.") : ucfirst("{$rl} no hay ingresos registrados{$who}."), '_natural' => true,
        'entities' => array_filter(['group' => $g['group_name'] ?? null])];
}

/** Conteo de seguimientos — abiertos por defecto; «resueltos/cerrados» filtra. */
function chat_count_trackings(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u); $q = $v['_q'] ?? '';
    $closed = ($s['status'] ?? '') === 'completed' || preg_match('/resuelt|cerrad|terminad|solucionad/u', $q);
    $st = $conn->prepare("SELECT t.status, COUNT(*) c FROM student_tracking t JOIN students s ON s.student_id = t.student_id
        WHERE t.school_id = ? {$scope['sql']} GROUP BY t.status");
    $st->execute(array_merge([$u['school_id']], $scope['params']));
    $by = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $open = (int)($by['en proceso'] ?? 0) + (int)($by['escalado'] ?? 0);
    $done = (int)($by['resuelto'] ?? 0) + (int)($by['descartado'] ?? 0);
    $where = $scope['sql'] ? ' en tus grupos' : '';
    $res = (int)($by['resuelto'] ?? 0); $desc = (int)($by['descartado'] ?? 0); $esc = (int)($by['escalado'] ?? 0);
    $reply = $closed
        ? "Hay {$done} seguimientos cerrados{$where} ({$res} resueltos, {$desc} descartados). Abiertos: {$open}."
        : "Hay {$open} seguimientos abiertos{$where}" . ($esc ? " ({$esc} escalados)" : '') . ". Cerrados: {$done}.";
    return ['reply' => $reply, '_natural' => true, 'entities' => ['module' => 'SEGUIMIENTO'],
        '_offer' => $open > 0 ? chatOffer('¿Quieres ver los casos abiertos?', 'trackings', ['status' => 'active']) : null];
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

/* ══ Intents de capacidad real del sistema ════════════════════════════════ */

/** Ranking de estudiantes por incidentes — top N real del rango/scope. */
function chat_top_offenders(PDO $conn, array $u, array $s, array $v): array {
    $module = $s['module'] ?? null;
    if (empty($s['from']) && empty($s['range_label'])) {   // sin rango: un ranking de UN día no dice nada
        $s += ['from' => nxToday(29), 'to' => nxToday(), 'range_label' => 'últimos 30 días', 'days' => 30];
    }
    $f = chatIncidentFilter($conn, $u, array_diff_key($s, ['student' => 1]));
    if (isset($f['err'])) return $f['err'];
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $limit = max(3, min(50, (int)($s['_rank_limit'] ?? 15)));
    // delta de mejora — «qué estudiantes mejoraron su asistencia»: cuenta
    // el rango vs el período anterior equivalente por estudiante; delta
    // negativo = mejoró (menos incidentes que antes)
    if (!empty($s['_improve'])) {
        [$pf, $pt] = array_slice(array_values(chatPreviousPeriod($from, $to, $s['range_label'] ?? null)), 0, 2);
        $st = $conn->prepare("SELECT s.student_id, s.first_name || ' ' || s.last_name AS name, ag.group_name,
                COUNT(*) FILTER (WHERE " . chatD('ai.detected_at') . " BETWEEN ? AND ?) AS cur,
                COUNT(*) FILTER (WHERE " . chatD('ai.detected_at') . " BETWEEN ? AND ?) AS prev
            {$f['from']} WHERE {$f['where']} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?
            GROUP BY s.student_id, s.first_name, s.last_name, ag.group_name
            ORDER BY (COUNT(*) FILTER (WHERE " . chatD('ai.detected_at') . " BETWEEN ? AND ?)) -
                     (COUNT(*) FILTER (WHERE " . chatD('ai.detected_at') . " BETWEEN ? AND ?)) ASC, s.last_name LIMIT {$limit}");
        $st->execute(array_merge([$from, $to, $pf, $pt], $f['params'], [min($from, $pf), max($to, $pt), $from, $to, $pf, $pt]));
        $rows = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => (int)$r['prev'] > 0 || (int)$r['cur'] > 0));
        $mlabel = $module ? (NX_MODULE_LABEL[$module] ?? strtolower($module)) : 'incidentes';
        $worse = ($s['_improve'] === 'worse');
        $picked = array_filter($rows, fn($r) => $worse
            ? ((int)$r['cur'] - (int)$r['prev']) > 0
            : ((int)$r['cur'] - (int)$r['prev']) < 0);
        if ($worse) usort($picked, fn($a, $b) => ((int)$b['cur'] - (int)$b['prev']) <=> ((int)$a['cur'] - (int)$a['prev']));
        if (!$rows || !$picked) return ['reply' => ($worse
            ? "Nadie aumentó sus {$mlabel} frente al período anterior — los que tienen registro van igual o mejor."
            : "Nadie redujo sus {$mlabel} frente al período anterior — los que tienen registro van igual o peor."), '_natural' => true];
        $rows = array_slice(array_values($picked), 0, $limit);
        $best = $rows[0];
        $lbl = $worse ? "empeoraron" : "mejoraron";
        return ['reply' => count($picked) . " estudiantes " . ($worse ? 'aumentaron' : 'redujeron') . " sus {$mlabel} frente al período anterior. Caso más marcado: "
                . "{$best['name']} ({$best['group_name']}) — de {$best['prev']} a {$best['cur']}.", '_natural' => true,
            'cards' => [['title' => ucfirst($lbl) . " {$mlabel} — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Antes', 'Ahora'],
                'rows' => array_map(fn($r) => [$r['name'], $r['group_name'] ?? '—', (int)$r['prev'], (int)$r['cur']], $rows)]],
            '_result_set' => ['type' => 'students', 'label' => "{$lbl} {$mlabel}", 'count' => count($rows),
                'items' => array_map(fn($r) => ['id' => $r['student_id'], 'label' => $r['name'], 'sub' => "{$r['prev']}→{$r['cur']}"] , $rows)],
            'entities' => array_filter(['module' => $module, 'range_label' => $rl])];
    }
    $asc = ($s['_rank_dir'] ?? null) === 'asc';
    $st = $conn->prepare("SELECT s.student_id, s.first_name || ' ' || s.last_name AS name, ag.group_name, COUNT(*) c,
            " . chatTs('MAX(ai.detected_at)') . " AS last
        {$f['from']} WHERE {$f['where']} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ?
        GROUP BY s.student_id, s.first_name, s.last_name, ag.group_name ORDER BY c " . ($asc ? 'ASC' : 'DESC') . ", s.last_name LIMIT {$limit}");
    $st->execute(array_merge($f['params'], [$from, $to])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $mlabel = $module ? (NX_MODULE_LABEL[$module] ?? strtolower($module)) : 'incidentes';
    $where = chatWho(null, $f['group']);
    if (!$rows) return ['reply' => "No hay {$mlabel} registradas{$where} {$rl}.", '_natural' => true];
    $max = (int)$rows[0]['c'];
    $ties = count(array_filter($rows, fn($r) => (int)$r['c'] === $max));
    $reply = ($asc ? "Menor registro de {$mlabel}{$where} — {$rl}: " : "Top de {$mlabel}{$where} — {$rl}: ")
        . ($ties > 1
            ? "{$ties} estudiantes empatan en el primer lugar ({$max} cada uno)."
            : "{$rows[0]['name']} ({$rows[0]['group_name']}) encabeza con {$max}.")
        . ($asc ? ' Entre quienes tienen registro.' : '');
    if ($max <= 1 && $from === $to) $reply .= ' En un solo día todos tienen 1 — el ranking no distingue a nadie.';
    $rs = ['type' => 'students', 'entity' => 'students', 'label' => "más {$mlabel} {$rl}",
        'order' => 'frecuencia (desc)', 'count' => count($rows),
        'columns' => ['#', 'Estudiante', 'Grupo', 'Total', 'Último'],
        'items' => array_map(fn($r) => ['id' => $r['student_id'], 'label' => $r['name'], 'sub' => ($r['group_name'] ?? '—') . " · {$r['c']}"], $rows),
        'rows' => array_map(fn($i, $r) => [$i + 1, $r['name'], $r['group_name'] ?? '—', (int)$r['c'], $r['last']], array_keys($rows), $rows),
        '_filters' => array_filter(['module' => $module, 'group' => $s['group'] ?? null, 'range_label' => $rl]),
        '_capability' => 'ranking.events'];
    $out = ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Top de {$mlabel} — {$rl}", 'columns' => $rs['columns'], 'rows' => $rs['rows']]],
        '_result_set' => $rs, 'entities' => array_filter(['module' => $module, 'range_label' => $s['range_label'] ?? null,
            'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'days' => $s['days'] ?? null])];
    if ($o = chatShortRangeOffer($s, 'top_offenders')) $out['_offer'] = $o;
    return $out;
}

/** Permisos de salida activos que ya pasaron su hora de retorno. */
function chat_pending_returns(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, ag.group_name,
            " . chatHm('c.exit_time') . " AS t, " . chatHm('c.return_time') . " AS r, c.status
        FROM class_exit_authorizations c JOIN students s ON s.student_id = c.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE c.school_id = ? AND c.actual_return_time IS NULL
          AND ((c.status = 'ACTIVE' AND c.return_time IS NOT NULL AND c.return_time < NOW())
               OR (c.status = 'EXPIRED' AND " . chatD('c.exit_time') . " = ?))
          {$scope['sql']} ORDER BY c.return_time LIMIT 50");
    $st->execute(array_merge([$u['school_id'], nxToday()], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => 'No hay permisos vencidos sin retorno: quienes salieron con permiso ya volvieron o siguen dentro del tiempo.', '_natural' => true];
    return ['reply' => count($rows) . ' estudiante' . (count($rows) === 1 ? '' : 's') . ' con permiso vencido sin registrar retorno:', '_natural' => true,
        'cards' => [['title' => 'Permisos vencidos sin retorno', 'columns' => ['Estudiante', 'Grupo', 'Salió', 'Debía volver', 'Estado'],
            'rows' => array_map(fn($r) => [$r['name'], $r['group_name'] ?? '—', $r['t'], $r['r'] ?? '—', $r['status'] === 'EXPIRED' ? 'Vencido' : 'Activo (pasado de hora)'], $rows)]],
        'actions' => chatDerivedActions($u, null, 'permisos vencidos')];
}

/** Incidencias del sistema: anomalías operativas, nodos caídos (security_incidents). */
function chat_system_incidents(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    $st=$conn->prepare("SELECT incident_type, severity_level, description, " . chatTs('detected_at') . " AS detected_at, resolved
        FROM security_incidents
        WHERE school_id=? AND " . chatD('detected_at') . " BETWEEN ? AND ?
        ORDER BY resolved ASC, detected_at DESC LIMIT 15");
    $st->execute([$u['school_id'],$from,$to]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rl=$s['range_label']??'hoy';
    if(!$rows) return ['reply'=>"No hay incidencias del sistema {$rl}.",'_natural'=>true];
    $open = count(array_filter($rows, fn($r) => empty($r['resolved'])));
    $reply = count($rows)." incidencia(s) del sistema en {$rl}"
        . ($open ? " — {$open} pendiente(s) de resolver" : ' — todas resueltas') . '.';
    return ['reply'=>$reply,
        'cards'=>[['title'=>'Incidencias del sistema','columns'=>['Tipo','Severidad','Detalle','Detectada','Estado'],
        'rows'=>array_map(fn($r)=>[$r['incident_type'],$r['severity_level']??'—',
            mb_strimwidth((string)($r['description']??'—'),0,60,'…'),
            $r['detected_at'], $r['resolved']?'Resuelta':'Abierta'],$rows)]]];
}

/** Alertas SOS / pánico del periodo. */
function chat_sos_alerts(PDO $conn, array $u, array $s, array $v): array {
    [$from,$to]=chatRange($s);
    // Dos fuentes reales: sos_alerts (alerta emitida desde la app — caso 'sos'
    // de /operations/execute) y school_panic_events (botón de pánico que
    // apaga dispositivos). Antes solo se leía la segunda: una alerta SOS
    // emitida por un usuario era invisible para el chat.
    $st=$conn->prepare("SELECT x.*, " . chatTs('x.ts') . " AS ts_local FROM (
        SELECT sa.alert_type AS event_type,
               sa.alert_description AS detail,
               sa.emitted_at AS ts,
               'ALERTA SOS' AS src,
               CASE WHEN sa.resolved THEN 'Resuelta' ELSE 'Abierta' END AS status,
               COALESCE(u2.first_name||' '||u2.last_name,'—') AS emitter
        FROM sos_alerts sa
        LEFT JOIN users u2 ON u2.user_id = sa.emitted_by_user_id
        WHERE sa.school_id = ? AND " . chatD('sa.emitted_at') . " BETWEEN ? AND ?
        UNION ALL
        SELECT 'PANIC' AS event_type,
               CONCAT(pe.devices_deactivated, ' dispositivo(s) desactivados') AS detail,
               pe.triggered_at AS ts,
               'BOTÓN DE PÁNICO' AS src,
               '—' AS status,
               COALESCE(u3.first_name||' '||u3.last_name,'—') AS emitter
        FROM school_panic_events pe
        LEFT JOIN users u3 ON u3.user_id = pe.triggered_by_user_id
        WHERE pe.school_id = ? AND " . chatD('pe.triggered_at') . " BETWEEN ? AND ?
    ) x ORDER BY x.ts DESC LIMIT 15");
    $st->execute([$u['school_id'],$from,$to,$u['school_id'],$from,$to]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    $rl=$s['range_label']??'hoy';
    if(!$rows) return ['reply'=>"No hay alertas SOS ni activaciones del botón de pánico {$rl}.",'_natural'=>true];
    return ['reply'=>count($rows)." alerta(s) SOS ({$rl}):",
        'cards'=>[['title'=>'SOS','columns'=>['Fuente','Tipo','Detalle','Emitió','Fecha','Estado'],
        'rows'=>array_map(fn($r)=>[$r['src'],$r['event_type']??'SOS',
            mb_strimwidth((string)($r['detail']??'—'),0,45,'…'),$r['emitter']??'—',
            $r['ts_local'],$r['status']??'—'],$rows)]]];
}

/** Intentos biométricos rechazados / spam del periodo. */
function chat_biometric_spam(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $st = $conn->prepare("SELECT COALESCE(ed.device_name, be.device_id::text) AS dev, COUNT(*) c, " . chatTs('MAX(be.event_timestamp)') . " AS last
        FROM biometric_events be LEFT JOIN edge_devices ed ON ed.device_id = be.device_id
        WHERE be.school_id = ? AND " . chatD('be.event_timestamp') . " BETWEEN ? AND ?
          AND (be.event_type LIKE 'SPAM%' OR be.event_type LIKE '%RECHAZ%' OR be.event_type LIKE '%FAIL%' OR be.event_type LIKE '%DENIED%')
        GROUP BY 1 ORDER BY c DESC LIMIT 15");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s);
    if (!$rows) return ['reply' => "No hay intentos biométricos rechazados ni spam {$rl}.", '_natural' => true];
    return ['reply' => array_sum(array_column($rows, 'c')) . " intentos rechazados {$rl}; el sensor con más es {$rows[0]['dev']} ({$rows[0]['c']}).", '_natural' => true,
        'cards' => [['title' => "Biometría rechazada — {$rl}", 'columns' => ['Sensor', 'Intentos', 'Último'], 'rows' => array_map(fn($r) => [$r['dev'], (int)$r['c'], $r['last']], $rows)]]];
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
        'sub'=>'doc '.$r['document_number'],
        'group'=>$g['group_name'],
        // campos materializados — proyección/navegación sobre el set
        // sin reconsultar (paridad con el result-set del plan semántico)
        'f'=>['sid'=>$r['student_id'],'fn'=>$r['first_name'],'ln'=>$r['last_name'],
              'doc'=>$r['document_number'],'grp'=>$g['group_name']]], $rows);
    $n = count($items);
    // la tabla de abajo lleva TODAS las filas — el texto no enumera ni
    // promete «los demás»: duplicaría la card y mentiría sobre datos
    // ocultos. «los demás» sigue siendo navegable si el usuario lo pide.
    $reply = $n === 1
        ? "{$g['group_name']} tiene 1 estudiante: {$items[0]['label']} ({$items[0]['sub']})."
        : "{$g['group_name']} tiene {$n} estudiantes — la lista completa está en la tabla.";
    return ['reply'=>$reply,
            'entities'=>['group'=>$g['group_name']],
            '_result_set'=>['type'=>'students','label'=>'estudiantes','items'=>$items,'count'=>$n,
                'columns'=>['#','Estudiante','Documento','Grupo'],
                'rows'=>array_map(fn($i,$r)=>[$i+1,trim($r['first_name'].' '.$r['last_name']),$r['document_number'],$g['group_name']],
                                  array_keys($rows),$rows),
                '_filters'=>['group'=>$g['group_name']]]];
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
    // «el primero / el segundo / su doc» tras el conteo navega sobre el
    // grupo: el set de miembros se materializa aunque el reply sea número
    $ms=$conn->prepare("SELECT s.student_id, s.first_name, s.last_name, s.document_number
        FROM students s JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        WHERE s.school_id=? AND sga.group_id=? AND s.deleted_at IS NULL AND s.active=TRUE
        ORDER BY s.last_name, s.first_name LIMIT 60");
    $ms->execute([$u['school_id'],$g['group_id']]);
    $members=$ms->fetchAll(PDO::FETCH_ASSOC);
    $out=['reply'=>"{$g['group_name']} tiene *{$c} estudiante(s)* activos.",
          'entities'=>['group'=>$g['group_name']]];
    if ($members) $out['_result_set']=['type'=>'students','label'=>"estudiantes de {$g['group_name']}",
        // _silent: el set existe para navegación («el primero», «su doc»)
        // pero no materializa card — el usuario pidió un número, no la tabla
        '_silent'=>true,
        'items'=>array_map(fn($r)=>['id'=>$r['student_id'],
            'label'=>trim($r['first_name'].' '.$r['last_name']),
            'sub'=>'doc '.$r['document_number'],'group'=>$g['group_name'],
            'f'=>['sid'=>$r['student_id'],'fn'=>$r['first_name'],'ln'=>$r['last_name'],
                  'doc'=>$r['document_number'],'grp'=>$g['group_name']]],$members),
        'count'=>count($members),
        'columns'=>['#','Estudiante','Documento','Grupo'],
        'rows'=>array_map(fn($i,$r)=>[$i+1,trim($r['first_name'].' '.$r['last_name']),$r['document_number'],$g['group_name']],array_keys($members),$members),
        '_filters'=>['group'=>$g['group_name']]];
    return $out;
}

/** Cumpleaños de estudiantes — hoy o esta semana. */
function chat_birthdays_today(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $t = nxToday();
    $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, ag.group_name, s.birth_date
        FROM students s
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.birth_date IS NOT NULL {$scope['sql']}");
    $st->execute(array_merge([$u['school_id']], $scope['params']));
    $today = new DateTimeImmutable($t, new DateTimeZone('America/Bogota'));
    $hits = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = new DateTimeImmutable($r['birth_date']);
        for ($k = 0; $k <= 7; $k++) {
            $d = $today->modify("+{$k} days");
            if ($d->format('m-d') === $b->format('m-d')) { $hits[] = [$r['name'], $r['group_name'] ?? '—', (int)$d->format('j') . ' de ' . NX_MONTH_NAMES[(int)$d->format('n')], $k, (int)$d->format('Y') - (int)$b->format('Y')]; break; }
        }
    }
    if (!$hits) return ['reply' => 'No hay cumpleaños de estudiantes en los próximos 7 días.', '_natural' => true];
    usort($hits, fn($a, $b) => $a[3] <=> $b[3]);
    $todayHits = array_filter($hits, fn($h) => $h[3] === 0);
    $reply = count($hits) . ' cumpleaños en los próximos 7 días'
        . ($todayHits ? '; hoy cumple ' . implode(', ', array_map(fn($h) => "{$h[0]} ({$h[1]})", $todayHits)) . '.' : '.');
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => 'Cumpleaños próximos', 'columns' => ['Estudiante', 'Grupo', 'Fecha', 'Cumple'],
            'rows' => array_map(fn($h) => [$h[0], $h[1], $h[3] === 0 ? "Hoy ({$h[2]})" : $h[2], "{$h[4]} años"], $hits)]]];
}

/** Actividad propia del usuario en el sistema (auditoría). */
function chat_my_activity(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $st = $conn->prepare("SELECT action_type, COUNT(*) c FROM global_audit_logs
        WHERE performed_by_user_id = ? AND " . chatD('created_at') . " BETWEEN ? AND ?
        GROUP BY action_type ORDER BY c DESC LIMIT 12");
    $st->execute([$u['id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s);
    if (!$rows) return ['reply' => "No tienes actividad registrada {$rl}.", '_natural' => true];
    $bits = array_map(fn($r) => chatAuditLabel($r['action_type']) . ": {$r['c']}", array_slice($rows, 0, 4));
    return ['reply' => "Tu actividad {$rl} — " . implode(' · ', $bits) . '.', '_natural' => true,
        'cards' => [['title' => "Tu actividad — {$rl}", 'columns' => ['Acción', 'Veces'],
            'rows' => array_map(fn($r) => [chatAuditLabel($r['action_type']), (int)$r['c']], $rows)]]];
}

/** Mensajes/citaciones que fallaron en envío. */
function chat_failed_messages(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $st = $conn->prepare("SELECT tm.type_code, UPPER(COALESCE(tm.delivery_status,'')) AS ds, COUNT(*) c
        FROM twilio_messages tm
        WHERE tm.school_id = ? AND tm.direction = 'OUTBOUND'
          AND UPPER(COALESCE(tm.delivery_status,'')) IN ('FAILED','FAILED_PERMANENT','UNDELIVERED','ERROR')
          AND " . chatD('tm.sent_at') . " BETWEEN ? AND ?
        GROUP BY 1, 2 ORDER BY c DESC");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No hay mensajes de WhatsApp fallidos {$rl}.", '_natural' => true];
    $total = array_sum(array_column($rows, 'c'));
    $perm = array_sum(array_map(fn($r) => $r['ds'] === 'FAILED_PERMANENT' ? (int)$r['c'] : 0, $rows));
    $byType = [];
    foreach ($rows as $r) $byType[$r['type_code']] = ($byType[$r['type_code']] ?? 0) + (int)$r['c'];
    arsort($byType);
    $typeEs = ['INASISTENCIA' => 'inasistencias', 'CITACION' => 'citaciones', 'LATE_ARRIVAL' => 'tardanzas', 'EVASION' => 'evasiones'];
    $parts = array_map(fn($k, $c) => "{$c} de " . ($typeEs[$k] ?? strtolower($k)), array_keys($byType), $byType);
    $dt = $conn->prepare("SELECT " . chatTs('tm.sent_at') . " ts, tm.type_code, UPPER(tm.delivery_status) ds, right(tm.phone_number, 4) tail,
            st.first_name || ' ' || st.last_name AS sname
        FROM twilio_messages tm LEFT JOIN students st ON st.student_id = tm.student_id
        WHERE tm.school_id = ? AND tm.direction = 'OUTBOUND'
          AND UPPER(COALESCE(tm.delivery_status,'')) IN ('FAILED','FAILED_PERMANENT','UNDELIVERED','ERROR')
          AND " . chatD('tm.sent_at') . " BETWEEN ? AND ? ORDER BY tm.sent_at DESC LIMIT 50");
    $dt->execute([$u['school_id'], $from, $to]);
    $stEs = ['FAILED' => 'Falló (reintenta)', 'FAILED_PERMANENT' => 'Falló definitivo', 'UNDELIVERED' => 'No entregado', 'ERROR' => 'Error'];
    return ['reply' => "{$total} mensajes de WhatsApp fallaron {$rl}: " . implode(', ', $parts) . '.'
            . ($perm ? " {$perm} con falla definitiva (no se reintentan)." : ' El sistema los reintenta automáticamente.'),
        '_natural' => true,
        'cards' => [['title' => "WhatsApp fallidos — {$rl}", 'columns' => ['Fecha', 'Tipo', 'Estudiante', 'Teléfono', 'Estado'],
            'rows' => array_map(fn($r) => [$r['ts'], $typeEs[$r['type_code']] ?? strtolower((string)$r['type_code']), $r['sname'] ?? '—', '…' . $r['tail'], $stEs[$r['ds']] ?? $r['ds']],
                $dt->fetchAll(PDO::FETCH_ASSOC))]]];
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
    $module = $s['module'] ?? 'INASISTENCIA';
    $q = (string)($v['_q'] ?? '');
    $grade = $s['grade'] ?? null;
    if ($grade === null && preg_match('/\b(decim\w*|grado\s*10)\b/u', $q)) $grade = '10';
    $s2 = array_diff_key($s, ['group' => 1, 'student' => 1]) + ['module' => $module];
    if ($grade !== null && $grade !== '') $s2['grade'] = (string)$grade;
    $f = chatIncidentFilter($conn, $u, $s2);
    if (isset($f['err'])) return $f['err'];
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $mine = ($s['scope'] ?? null) === 'mine' || preg_match('/\b(mis grupos|a mi cargo|que tengo|mis salones)\b/u', $q);
    $mineSql = ''; $mineP = [];
    if ($mine && in_array($u['role'], ['TEACHER', 'COUNSELOR'], true)) {
        $mineSql = ' AND ' . chatIncGroupCol() . ' IN (SELECT tga.group_id FROM teacher_group_access tga WHERE tga.teacher_user_id = ?)';
        $mineP = [$u['id']];
    }
    $mlabel = NX_MODULE_LABEL[$module] ?? strtolower($module);
    $run = function (string $a, string $b) use ($conn, $f, $mineSql, $mineP) {
        $st = $conn->prepare("SELECT COALESCE(ag.group_name, 'Sin grupo') g, COUNT(*) c {$f['from']}
            WHERE {$f['where']}{$mineSql} AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? GROUP BY 1");
        $st->execute(array_merge($f['params'], $mineP, [$a, $b]));
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    };
    $cur = $run($from, $to);
    // grupos sin eventos también cuentan (0) — un ranking que los omite miente
    $gq = $conn->prepare("SELECT ag.group_name, COUNT(sga.student_id) n FROM academic_groups ag
        LEFT JOIN student_group_assignments sga ON sga.group_id = ag.group_id AND sga.active = TRUE
        WHERE ag.school_id = ?" . ($grade ? ' AND ag.grade_level = ?' : '') . " GROUP BY ag.group_name");
    $gq->execute($grade ? [$u['school_id'], (string)$grade] : [$u['school_id']]);
    $sizes = array_map('intval', $gq->fetchAll(PDO::FETCH_KEY_PAIR));
    if ($mine && $mineP) $sizes = array_intersect_key($sizes, $cur) ?: $sizes;
    foreach ($sizes as $gname => $_) $cur[$gname] = $cur[$gname] ?? 0;
    // ranking inverso — «mejor asistencia», «quién falta menos», «grupo que
    // menos llega tarde»: el orden ascendente responde la pregunta real
    $asc = ($s['_rank_dir'] ?? null) === 'asc';
    $asc ? asort($cur) : arsort($cur);
    $scopeLbl = ($grade ? " de grado {$grade}" : '') . ($mine ? ' a tu cargo' : '');
    if (!$cur || array_sum($cur) === 0) return ['reply' => "No hay {$mlabel} en los grupos{$scopeLbl} {$rl}.", '_natural' => true];
    $trend = !empty($s['trend']) || preg_match('/\b(aumento|subi|baj|increment|comparad|vs\.? anterior|respecto)\b/u', $q);
    $names = array_keys($cur);
    if ($trend) {
        $pp = chatPreviousPeriod($from, $to, $s['range_label'] ?? null);
        $prev = $run($pp['from'], $pp['to']);
        $rows = array_map(fn($g) => [$g, $cur[$g], $prev[$g] ?? 0, (($cur[$g] - ($prev[$g] ?? 0)) >= 0 ? '+' : '') . ($cur[$g] - ($prev[$g] ?? 0))], $names);
        $up = array_filter($rows, fn($r) => (int)$r[3] > 0);
        return ['reply' => ucfirst("{$mlabel} por grupo{$scopeLbl}: {$rl} frente a {$pp['range_label']}. ") . count($up) . ' grupos subieron; el que más tiene ahora es ' . $names[0] . " ({$cur[$names[0]]}).",
            '_natural' => true,
            'cards' => [['title' => ucfirst($mlabel) . " por grupo — {$rl} vs {$pp['range_label']}", 'columns' => ['Grupo', 'Actual', 'Anterior', 'Δ'], 'rows' => $rows]],
            '_result_set' => ['type' => 'ranking', 'label' => "comparativo {$mlabel}",
                'items' => array_map(fn($g) => ['id' => null, 'label' => $g, 'sub' => "{$cur[$g]} ahora"], $names), 'count' => count($names)]];
    }
    $rows = array_map(fn($g) => [$g, $cur[$g], $sizes[$g] ?? '—', isset($sizes[$g]) && $sizes[$g] ? round($cur[$g] * 100 / $sizes[$g]) . '%' : '—'], $names);
    $top = $names[0];
    $reply = ucfirst("{$mlabel} por grupo{$scopeLbl} {$rl}: el grupo con " . ($asc ? 'menos' : 'más') . " es {$top} ({$cur[$top]})");
    $zero = array_keys(array_filter($cur, fn($c) => $c === 0));
    $reply .= $zero ? '; sin ' . $mlabel . ': ' . implode(', ', array_slice($zero, 0, 6)) . (count($zero) > 6 ? '…' : '') . '.' : '.';
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => ucfirst($mlabel) . " por grupo — {$rl}", 'columns' => ['Grupo', 'Total', 'Estudiantes', '% del grupo'], 'rows' => $rows]],
        '_result_set' => ['type' => 'ranking', 'label' => "{$mlabel} por grupo",
            'items' => array_map(fn($g) => ['id' => null, 'label' => $g, 'sub' => "{$cur[$g]}"], $names), 'count' => count($names)],
        'entities' => array_filter(['module' => $module, 'grade' => $grade, 'range_label' => $s['range_label'] ?? null,
            'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null, 'days' => $s['days'] ?? null])];
}

/** Resumen de la conversación actual — meta-del-chat. */
function chat_session_summary(PDO $conn, array $u, array $s, array $v): array {
    $st=$conn->prepare("SELECT payload_json->>'intent' AS i FROM chat_messages
        WHERE user_id=? AND role='user' AND " . chatD('created_at') . " = '" . nxToday() . "' ORDER BY created_at DESC LIMIT 30");
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
    $bits = []; $rows = [];
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $st->execute([$u['id']]); $n = (int)$st->fetchColumn();
        if ($n) { $bits[] = "{$n} notificaciones sin leer"; $rows[] = ['Notificaciones sin leer', $n]; }
    } catch (Throwable $e) {}
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM student_tracking t WHERE t.school_id = ? AND t.status IN ('en proceso','escalado') AND t.assigned_to_user_id = ?");
        $st->execute([$u['school_id'], $u['id']]); $t2 = (int)$st->fetchColumn();
        if ($t2) { $bits[] = "{$t2} seguimientos asignados a ti"; $rows[] = ['Seguimientos a tu cargo', $t2]; }
    } catch (Throwable $e) {}
    $scope = chatScope($conn, $u);
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM class_exit_authorizations c JOIN students s ON s.student_id = c.student_id
            WHERE c.school_id = ? AND c.status = 'ACTIVE' AND c.actual_return_time IS NULL AND c.return_time < NOW() {$scope['sql']}");
        $st->execute(array_merge([$u['school_id']], $scope['params'])); $p = (int)$st->fetchColumn();
        if ($p) { $bits[] = "{$p} permisos vencidos sin retorno"; $rows[] = ['Permisos vencidos sin retorno', $p]; }
    } catch (Throwable $e) {}
    if (in_array($u['role'], ['RECTOR', 'COORDINATOR'], true)) {
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM security_incidents WHERE school_id = ? AND resolved = FALSE");
            $st->execute([$u['school_id']]); $si = (int)$st->fetchColumn();
            if ($si) { $bits[] = "{$si} incidencias del sistema abiertas"; $rows[] = ['Incidencias del sistema abiertas', $si]; }
        } catch (Throwable $e) {}
    }
    if (!$bits) return ['reply' => 'No tienes nada pendiente ahora.', '_natural' => true];
    return ['reply' => 'Pendiente ahora: ' . implode(' · ', $bits) . '.', '_natural' => true,
        'cards' => [['title' => 'Tus pendientes', 'columns' => ['Pendiente', 'Cantidad'], 'rows' => $rows]]];
}

/** ¿Respondieron los acudientes los WhatsApp de inasistencia? (estado real por incidente) */
function chat_guardian_replies(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $scope = chatScope($conn, $u);
    $w = "ai.school_id = ? AND ai.incident_type = 'INASISTENCIA' AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? {$scope['sql']}";
    $p = array_merge([$u['school_id'], $from, $to], $scope['params']);
    $gf = '';
    if (!empty($s['group']) && ($g = chatResolveGroup($conn, $u, $s['group']))) { $gf = ' AND ' . chatIncGroupCol() . ' = ?'; $p[] = $g['group_id']; }
    $notified = "EXISTS (SELECT 1 FROM twilio_messages tm WHERE tm.school_id = ai.school_id AND tm.student_id = ai.student_id
                    AND tm.type_code = 'INASISTENCIA' AND tm.direction = 'OUTBOUND'
                    AND " . chatD('tm.sent_at') . " = " . chatD('ai.detected_at') . ")";
    $st = $conn->prepare("SELECT s.first_name || ' ' || s.last_name AS name, ag.group_name,
            ai.metadata_json->>'justificada' AS j, ai.metadata_json->>'guardian_response' AS gr,
            ai.metadata_json->>'motivo' AS motivo, ai.metadata_json->>'pending_context' AS pend,
            ai.metadata_json->>'responded_at' AS resp, {$notified} AS notified
        FROM attendance_incidents ai JOIN students s ON s.student_id = ai.student_id " . chatIncGroupJoin() . "
        WHERE {$w}{$gf} ORDER BY ag.group_name, s.last_name LIMIT 2000");
    $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No hay inasistencias {$rl}, así que no hubo avisos a acudientes por ese motivo.", '_natural' => true];
    $tot = count($rows); $pend = 0; $noti = 0; $just = 0; $noAware = 0; $answered = []; $silent = [];
    foreach ($rows as $r) {
        $isPend = $r['pend'] === 'true';
        $isNoti = $r['notified'] === true || $r['notified'] === 't' || $r['notified'] === '1' || $r['notified'] === 1;
        if ($isPend) $pend++;
        if ($isNoti) $noti++;
        if ($r['j'] === 'true') { $just++; $answered[] = [$r['name'], $r['group_name'] ?? '—', 'Justificada', mb_strimwidth((string)($r['motivo'] ?? '—'), 0, 60, '…')]; }
        elseif ($r['gr'] === 'no_al_tanto') { $noAware++; $answered[] = [$r['name'], $r['group_name'] ?? '—', 'No estaba al tanto', '—']; }
        elseif ($isNoti) $silent[] = [$r['name'], $r['group_name'] ?? '—', 'Sin respuesta', '—'];
    }
    $resp = $just + $noAware;
    if ($noti === 0) {
        $reply = "De las {$tot} inasistencias {$rl} no se envió ningún WhatsApp a acudientes"
            . ($pend ? ": {$pend} quedaron pendientes de verificación por concentración anómala de ausencias (posible falla de sensores), y el sistema no avisa en esos casos." : '.');
    } else {
        $reply = "De {$tot} inasistencias {$rl}, se avisó por WhatsApp a {$noti} acudientes: {$just} justificaron, {$noAware} dijeron no estar al tanto y "
            . count($silent) . ' no han respondido.'
            . ($pend ? " {$pend} inasistencias quedaron sin aviso por estar pendientes de verificación." : '');
    }
    $cards = [];
    if ($answered) $cards[] = ['title' => "Respuestas de acudientes — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Respuesta', 'Motivo'], 'rows' => array_slice($answered, 0, 300)];
    if ($silent) $cards[] = ['title' => 'Avisados sin respuesta', 'columns' => ['Estudiante', 'Grupo', 'Estado', '—'], 'rows' => array_slice($silent, 0, 300)];
    return ['reply' => $reply, '_natural' => true, 'cards' => $cards ?: null,
        'entities' => ['module' => 'INASISTENCIA'] + array_filter(['range_label' => $s['range_label'] ?? null])];
}

/** Estado de la cola de mensajería (Twilio/WhatsApp). */
function chat_whatsapp_status(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $st = $conn->prepare("SELECT tm.direction, UPPER(COALESCE(tm.delivery_status,'SIN ESTADO')) ds, COUNT(*) c
        FROM twilio_messages tm WHERE tm.school_id = ? AND " . chatD('tm.sent_at') . " BETWEEN ? AND ?
        GROUP BY 1, 2 ORDER BY c DESC");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No hubo mensajes de WhatsApp {$rl}.", '_natural' => true];
    $out = 0; $in = 0; $g = ['ok' => 0, 'sent' => 0, 'queue' => 0, 'fail' => 0];
    foreach ($rows as $r) {
        if ($r['direction'] === 'INBOUND') { $in += (int)$r['c']; continue; }
        $out += (int)$r['c'];
        $k = in_array($r['ds'], ['DELIVERED', 'READ'], true) ? 'ok' : (in_array($r['ds'], ['SENT'], true) ? 'sent'
            : (in_array($r['ds'], ['QUEUED', 'PROCESSING', 'ACCEPTED', 'SENDING'], true) ? 'queue' : (in_array($r['ds'], ['FAILED', 'FAILED_PERMANENT', 'UNDELIVERED', 'ERROR'], true) ? 'fail' : 'sent')));
        $g[$k] += (int)$r['c'];
    }
    $stEs = ['DELIVERED' => 'Entregado', 'READ' => 'Leído', 'SENT' => 'Enviado', 'QUEUED' => 'En cola', 'PROCESSING' => 'Procesando',
        'FAILED' => 'Falló (reintenta)', 'FAILED_PERMANENT' => 'Falló definitivo', 'UNDELIVERED' => 'No entregado', 'RECEIVED' => 'Recibido'];
    $reply = ucfirst("{$rl} salieron {$out} mensajes de WhatsApp: {$g['ok']} entregados o leídos, {$g['sent']} enviados sin confirmación, {$g['queue']} en cola y {$g['fail']} fallidos.")
        . " Llegaron {$in} respuestas de acudientes.";
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "WhatsApp — {$rl}", 'columns' => ['Dirección', 'Estado', 'Mensajes'],
            'rows' => array_map(fn($r) => [$r['direction'] === 'INBOUND' ? 'Recibido' : 'Enviado', $stEs[$r['ds']] ?? ucfirst(strtolower($r['ds'])), (int)$r['c']], $rows)]],
        '_offer' => $g['fail'] > 0 ? chatOffer('¿Quieres ver los mensajes fallidos?', 'failed_messages', array_intersect_key($s, array_flip(['from', 'to', 'range_label', 'days']))) : null];
}

/* ============================================================================
 * INTENTS DERIVADOS DEL MODELO DE DATOS
 * ----------------------------------------------------------------------------
 * Cobertura tabla × interrogativa: por qué / quién / cuándo / estado / causa.
 * Cada handler responde UNA forma de pregunta concreta con datos reales —
 * si el dato no existe, lo dice en vez de degradar a una lista genérica.
 * ========================================================================== */

/** Por qué un estudiante está en riesgo — score, nivel y qué lo detonó. */
function chat_risk_reason(PDO $conn, array $u, array $s, array $v): array {
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» entre los estudiantes de tu alcance — revisa el nombre o dime su grupo.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
    }
    if (!$stu) return ['reply' => '¿De qué estudiante quieres el motivo del riesgo? Dame nombre o apellido.', '_natural' => true];
    $name = trim("{$stu['first_name']} {$stu['last_name']}");

    $m = $conn->prepare("SELECT risk_score, risk_level, late_count, absence_count, total_events,
            calculation_window_days, " . chatTs('calculated_at') . " AS calc
        FROM student_behavior_metrics WHERE school_id = ? AND student_id = ?
        ORDER BY calculated_at DESC LIMIT 1");
    $m->execute([$u['school_id'], $stu['student_id']]);
    $met = $m->fetch(PDO::FETCH_ASSOC);

    $a = $conn->prepare("SELECT alert_level, trigger_category, trigger_rule, trigger_score, status,
            " . chatTs('created_at') . " AS creada
        FROM risk_alerts WHERE school_id = ? AND student_id = ? AND status <> 'RESOLVED'
        ORDER BY created_at DESC LIMIT 5");
    $a->execute([$u['school_id'], $stu['student_id']]);
    $alerts = $a->fetchAll(PDO::FETCH_ASSOC);

    $days = max(1, (int)($met['calculation_window_days'] ?? 30) ?: 30);
    $i = $conn->prepare("SELECT incident_type, COUNT(*) c FROM attendance_incidents ai
        WHERE ai.school_id = ? AND ai.student_id = ? AND " . chatD('ai.detected_at') . " >= CURRENT_DATE - INTERVAL '{$days} days'
        GROUP BY 1 ORDER BY c DESC LIMIT 6");
    $i->execute([$u['school_id'], $stu['student_id']]);
    $brk = $i->fetchAll(PDO::FETCH_ASSOC);

    if (!$met && !$alerts) {
        return ['reply' => "{$name} no tiene mediciones de riesgo registradas — el motor aún no la evalúa o está por debajo del umbral.", '_natural' => true,
            'entities' => ['student' => mb_strtolower($name)]];
    }
    $lvl = ['CRITICAL' => 'crítico', 'HIGH' => 'alto', 'MEDIUM' => 'medio', 'LOW' => 'bajo'];
    $typ = ['INASISTENCIA' => 'inasistencias', 'LATE_ARRIVAL' => 'tardanzas', 'EVASION_INTERNA' => 'evasiones',
        'PERMISO' => 'permisos', 'SALIDA_COLEGIO' => 'salidas', 'INCIDENTE' => 'incidentes'];
    $bits = [];
    if ($met) {
        $parts = [];
        if ((int)$met['absence_count'] > 0) $parts[] = (int)$met['absence_count'] . ' inasistencias';
        if ((int)$met['late_count'] > 0)    $parts[] = (int)$met['late_count'] . ' tardanzas';
        $otros = (int)$met['total_events'] - (int)$met['absence_count'] - (int)$met['late_count'];
        if ($otros > 0) $parts[] = "{$otros} eventos más";
        $bits[] = "score " . number_format((float)$met['risk_score'], 2) . " (nivel " . ($lvl[$met['risk_level']] ?? $met['risk_level']) . ")"
            . ($parts ? ' por ' . implode(' + ', $parts) . " en {$days} días" : '');
    }
    if ($alerts) {
        $cat = array_filter(array_unique(array_map(fn($r) => $r['trigger_category'], $alerts)));
        $bits[] = "alerta" . (count($alerts) > 1 ? 's' : '') . " " . implode(', ', array_map(fn($r) => $lvl[$r['alert_level']] ?? $r['alert_level'], $alerts))
            . ($cat ? " — categoría " . implode(' y ', $cat) : '');
    }
    $cards = [];
    if ($brk) $cards[] = ['title' => "Eventos que alimentan el riesgo — últimos {$days} días", 'columns' => ['Tipo', 'Eventos'],
        'rows' => array_map(fn($r) => [$typ[$r['incident_type']] ?? $r['incident_type'], (int)$r['c']], $brk)];
    if ($alerts) $cards[] = ['title' => 'Alertas activas', 'columns' => ['Nivel', 'Categoría', 'Regla', 'Score', 'Desde'],
        'rows' => array_map(fn($r) => [$lvl[$r['alert_level']] ?? $r['alert_level'], $r['trigger_category'] ?? '—',
            mb_strimwidth((string)($r['trigger_rule'] ?? '—'), 0, 50, '…'), $r['trigger_score'] ?? '—', $r['creada']], $alerts)];
    return ['reply' => "{$name} está en riesgo porque " . implode('; ', $bits) . '.', '_natural' => true,
        'cards' => $cards, 'entities' => ['student' => mb_strtolower($name)],
        '_result_set' => ['type' => 'students', 'label' => 'motivo de riesgo',
            'items' => [['id' => $stu['student_id'], 'label' => $name, 'sub' => $met ? "score {$met['risk_score']} · " . ($lvl[$met['risk_level']] ?? $met['risk_level']) : 'sin métrica']],
            'count' => 1]];
}

/** Excusas/justificaciones registradas sobre incidentes — quién, motivo, tipo. */
function chat_incident_excuses(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $scope = chatScope($conn, $u);
    $w = ['j.school_id = ?', 'j.incident_date BETWEEN ? AND ?']; $p = [$u['school_id'], $from, $to];
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
        $w[] = 'j.student_id = ?'; $p[] = $stu['student_id'];
    }
    if (!empty($s['module'])) { $w[] = 'j.incident_type = ?'; $p[] = $s['module']; }
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    $sql = "SELECT st.first_name||' '||st.last_name AS name, ag.group_name,
            j.incident_type, j.incident_date, j.justification_type, j.reason,
            " . chatTs('j.justified_at') . " AS cuando,
            COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS por
        FROM risk_justifications j
        JOIN students st ON st.student_id = j.student_id AND st.deleted_at IS NULL
        LEFT JOIN student_group_assignments sga ON sga.student_id = st.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users ub ON ub.user_id = j.justified_by
        WHERE " . implode(' AND ', $w) . " {$scopeSql}
        ORDER BY j.incident_date DESC LIMIT 200";
    $st = $conn->prepare($sql); $st->execute(array_merge($p, $scope['params']));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s); $who = chatWho($stu, null);
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'module' => $s['module'] ?? 'INASISTENCIA', 'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null]);
    if (!$rows) return ['reply' => "No hay excusas ni justificaciones registradas{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
    $typ = ['INASISTENCIA' => 'inasistencia', 'LATE_ARRIVAL' => 'tardanza', 'EVASION_INTERNA' => 'evasión',
        'PERMISO' => 'permiso', 'SALIDA_COLEGIO' => 'salida', 'INCIDENTE' => 'incidente'];
    $n = count($rows);
    $razones = array_filter(array_unique(array_map(fn($r) => trim((string)$r['reason']), $rows)));
    $reply = ucfirst("{$rl}: {$n} " . ($n === 1 ? 'justificación' : 'justificaciones') . "{$who}.")
        . ($razones ? ' Motivos: ' . implode('; ', array_slice($razones, 0, 4)) . (count($razones) > 4 ? '…' : '') : '');
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Justificaciones{$who} — {$rl}", 'columns' => ['Fecha', 'Estudiante', 'Grupo', 'Incidente', 'Motivo', 'Tipo', 'Registró'],
            'rows' => array_map(fn($r) => [$r['incident_date'], $r['name'], $r['group_name'] ?? '—', $typ[$r['incident_type']] ?? $r['incident_type'],
                mb_strimwidth((string)($r['reason'] ?? '—'), 0, 45, '…'), $r['justification_type'] ?? '—', $r['por']], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'excuses', 'label' => "justificaciones{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'], 'sub' => $r['incident_date'] . ' · ' . mb_strimwidth((string)($r['reason'] ?? ''), 0, 40, '…')], $rows),
            'count' => $n]];
}

/** Detalle de salidas autorizadas — quién autorizó, hora, motivo, retorno. */
function chat_exit_detail(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $scope = chatScope($conn, $u);
    $w = ['x.school_id = ?', chatD('x.exit_time') . ' BETWEEN ? AND ?']; $p = [$u['school_id'], $from, $to];
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
        $w[] = 'x.student_id = ?'; $p[] = $stu['student_id'];
    }
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    $fromSql = "FROM (
        SELECT c.student_id, c.school_id, c.authorization_reason AS reason, c.exit_time,
               COALESCE(c.actual_return_time, c.return_time) AS return_time, c.actual_return_time, c.status,
               'de clase' AS kind, COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS issuer
        FROM class_exit_authorizations c LEFT JOIN users ub ON ub.user_id = c.authorized_by_user_id
        UNION ALL
        SELECT se.student_id, se.school_id, se.authorization_reason, se.exit_time,
               COALESCE(se.actual_return_time, se.expected_return_time), se.actual_return_time, se.status,
               'del colegio' AS kind, COALESCE(ub.first_name || ' ' || ub.last_name, '—')
        FROM school_exit_authorizations se LEFT JOIN users ub ON ub.user_id = se.authorized_by_user_id
    ) x
    JOIN students st ON st.student_id = x.student_id AND st.deleted_at IS NULL
    LEFT JOIN student_group_assignments sga ON sga.student_id = st.student_id AND sga.active = TRUE
    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
    WHERE " . implode(' AND ', $w) . " {$scopeSql}";
    $st = $conn->prepare("SELECT x.*, st.first_name, st.last_name, ag.group_name,
            " . chatTs('x.exit_time') . " AS exit_local, " . chatTs('x.return_time') . " AS return_local,
            " . chatTs('x.actual_return_time') . " AS actual_local
        {$fromSql} ORDER BY x.exit_time DESC LIMIT 100");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s); $who = chatWho($stu, null);
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'module' => 'PERMISO', 'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null]);
    if (!$rows) return ['reply' => "No hay salidas autorizadas{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
    $stEs = ['ACTIVE' => 'Fuera aún', 'COMPLETED' => 'Ya regresó', 'EXPIRED' => 'Venció sin regreso', 'CANCELLED' => 'Cancelada'];
    $n = count($rows); $latest = $rows[0];
    $reply = ucfirst("{$rl}: {$n} " . ($n === 1 ? 'salida autorizada' : 'salidas autorizadas') . "{$who}.");
    if ($stu && $n === 1) {
        $reply = "{$latest['first_name']} {$latest['last_name']} salió {$latest['kind']} a las {$latest['exit_local']}"
            . ($latest['reason'] ? " por «" . mb_strimwidth($latest['reason'], 0, 60, '…') . "»" : '')
            . ". Autorizó {$latest['issuer']} — " . strtolower($stEs[$latest['status']] ?? $latest['status'])
            . ($latest['actual_local'] ? " (regresó {$latest['actual_local']})" : '') . '.';
    }
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Salidas{$who} — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Tipo', 'Motivo', 'Salió', 'Regreso', 'Estado', 'Autorizó'],
            'rows' => array_map(fn($r) => ["{$r['first_name']} {$r['last_name']}", $r['group_name'] ?? '—', $r['kind'],
                mb_strimwidth((string)($r['reason'] ?? '—'), 0, 40, '…'), $r['exit_local'],
                $r['actual_local'] ?: ($r['return_local'] ?: '—'), $stEs[$r['status']] ?? $r['status'], $r['issuer']], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'exits', 'label' => "salidas{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => $r['exit_local'] . ' · ' . ($stEs[$r['status']] ?? $r['status'])], $rows),
            'count' => $n]];
}

/** Paseo pedagógico — destino, hora, quiénes van. */
function chat_trip_info(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $scope = chatScope($conn, $u);
    // «para dónde es el paseo» suele ser próximo — sin rango explícito miro
    // desde hoy-7d hasta +30d; con rango lo respeto
    $explicit = !empty($s['from']) || !empty($s['range_label']) || isset($s['days']);
    $w = ['t.school_id = ?']; $p = [$u['school_id']];
    if ($explicit) { $w[] = chatD('t.departure_time') . ' BETWEEN ? AND ?'; $p[] = $from; $p[] = $to; }
    else { $w[] = "t.departure_time >= NOW() - INTERVAL '7 days' AND t.departure_time <= NOW() + INTERVAL '30 days'"; }
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
        $w[] = 't.student_id = ?'; $p[] = $stu['student_id'];
    }
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    $st = $conn->prepare("SELECT t.destination, t.purpose,
            " . chatTs('t.departure_time') . " AS dep, " . chatTs('t.return_time') . " AS ret,
            t.metadata_json->>'group_name' AS trip_group,
            COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS issuer, COUNT(*) AS n
        FROM pedagogical_trip_authorizations t
        JOIN students st ON st.student_id = t.student_id AND st.deleted_at IS NULL
        LEFT JOIN users ub ON ub.user_id = t.authorized_by_user_id
        WHERE " . implode(' AND ', $w) . " {$scopeSql}
        GROUP BY t.destination, t.departure_time, t.return_time, t.purpose, trip_group, issuer
        ORDER BY t.departure_time ASC LIMIT 20");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = $explicit ? chatRangeLabel($s) : 'las próximas semanas';
    $who = chatWho($stu, null);
    if (!$rows) return ['reply' => "No hay salidas pedagógicas programadas{$who} para {$rl}.", '_natural' => true];
    $n = count($rows); $next = $rows[0];
    $reply = $n === 1
        ? "El paseo es para {$next['destination']} — sale {$next['dep']}" . ($next['ret'] ? " y regresa {$next['ret']}" : '')
          . ($next['trip_group'] ? ", con {$next['trip_group']} ({$next['n']} estudiantes)" : ", {$next['n']} estudiantes")
          . ($next['purpose'] ? ". Motivo: " . mb_strimwidth($next['purpose'], 0, 60, '…') : '') . '.'
        : "{$n} salidas pedagógicas {$rl} — la próxima es {$next['destination']} ({$next['dep']}).";
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => 'Salidas pedagógicas', 'columns' => ['Destino', 'Grupo', 'Estudiantes', 'Sale', 'Regresa', 'Motivo', 'Autorizó'],
            'rows' => array_map(fn($r) => [mb_strimwidth((string)($r['destination'] ?? '—'), 0, 40, '…'), $r['trip_group'] ?? '—',
                (int)$r['n'], $r['dep'], $r['ret'] ?: '—', mb_strimwidth((string)($r['purpose'] ?? '—'), 0, 35, '…'), $r['issuer']], $rows)]],
        '_result_set' => ['type' => 'trips', 'label' => 'salidas pedagógicas',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['destination'], 'sub' => ($r['trip_group'] ?? '—') . ' · ' . $r['dep']], $rows),
            'count' => $n]];
}

/** Calendario escolar — día lectivo, festivos, próximo día sin clases. */
function chat_school_calendar(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    [$from, $to] = chatRange($s);
    $specificDay = $from === $to;
    // «próximo día no lectivo / festivo» — mira adelante, no el rango pasado
    if (preg_match('/\b(proxim\w+|siguiente|cuando es el|cuando cae|hasta cuando|cuando vuelve)\b.{0,30}\b(festivo|no lectivo|feriado|puente|descanso|sin clases)\b|\b(festivos?|feriados?|puentes?)\s+(proximos?|siguientes|que vienen|del mes|este mes|de este mes|del ano)\b/u', $q)) {
        $from = nxToday(); $to = date('Y-m-d', strtotime('+90 days'));
        $specificDay = false;
    }
    if (preg_match('/\b(festivos?|feriados?|dias? no lectivos?|puentes?)\b/u', $q)
        && !preg_match('/\b(hoy|manana|ayer|clases)\b/u', $q)) {
        $specificDay = false;
        if ($from === $to) { $from = substr($from, 0, 8) . '01'; $to = date('Y-m-t', strtotime($from)); }
    }
    $st = $conn->prepare("SELECT calendar_date, is_lecture_day, COALESCE(reason,'') AS reason
        FROM school_calendar WHERE school_id = ? AND calendar_date BETWEEN ? AND ?
        ORDER BY calendar_date LIMIT 90");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $fmt = fn(string $d) => (int)date('j', strtotime($d)) . ' de ' . NX_MONTH_NAMES[(int)date('n', strtotime($d))];
    $dow = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    if ($specificDay) {
        $hit = null;
        foreach ($rows as $r) if ($r['calendar_date'] === $from) { $hit = $r; break; }
        $dayName = $dow[(int)date('w', strtotime($from))];
        if ($hit === null) {
            // sin fila = día normal: lectivo si es lunes-viernes (regla del sistema)
            $isWknd = (int)date('N', strtotime($from)) >= 6;
            return ['reply' => "El {$dayName} " . $fmt($from) . " no tiene marca en el calendario — " . ($isWknd ? 'es fin de semana, sin jornada.' : 'cuenta como día lectivo normal.'), '_natural' => true];
        }
        $lect = (bool)$hit['is_lecture_day'];
        return ['reply' => "El {$dayName} " . $fmt($from) . ($lect ? ' SÍ es día lectivo — hay jornada normal.' : ' NO es día lectivo' . ($hit['reason'] ? ": {$hit['reason']}." : '.')), '_natural' => true,
            'entities' => ['from' => $from, 'to' => $to]];
    }
    $nonLect = array_values(array_filter($rows, fn($r) => !(bool)$r['is_lecture_day']));
    if (!$rows || !$nonLect)
        return ['reply' => 'No hay días marcados como no lectivos en ese período — el calendario registra jornada normal.', '_natural' => true];
    $next = null;
    foreach ($nonLect as $r) if ($r['calendar_date'] >= nxToday()) { $next = $r; break; }
    $reply = count($nonLect) . ' día' . (count($nonLect) === 1 ? '' : 's') . ' no lectivo' . (count($nonLect) === 1 ? '' : 's') . ' en el período.'
        . ($next ? ' El próximo: ' . $dow[(int)date('w', strtotime($next['calendar_date']))] . ' ' . $fmt($next['calendar_date']) . ($next['reason'] ? " ({$next['reason']})" : '') . '.' : '');
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => 'Días no lectivos', 'columns' => ['Fecha', 'Día', 'Motivo'],
            'rows' => array_map(fn($r) => [$fmt($r['calendar_date']), ucfirst($dow[(int)date('w', strtotime($r['calendar_date']))]), $r['reason'] ?: '—'], $nonLect)]],
        '_result_set' => ['type' => 'calendar', 'label' => 'días no lectivos',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $fmt($r['calendar_date']), 'sub' => $r['reason'] ?: '—'], $nonLect),
            'count' => count($nonLect)]];
}

/** Contacto de un funcionario — correo/teléfono institucional. */
function chat_staff_contact(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $name = $s['person'] ?? null;
    // «correo de la enfermera / de coordinación» — rol, no nombre
    if (!$name) {
        $roleMap = ['rector' => 'RECTOR', 'director' => 'RECTOR', 'coordinador' => 'COORDINATOR', 'coordinacion' => 'COORDINATOR',
            'psicolog' => 'COUNSELOR', 'orientador' => 'COUNSELOR', 'psicoorientador' => 'COUNSELOR', 'psicoorientacion' => 'COUNSELOR',
            'secretari' => 'SECRETARY', 'enfermer' => 'AUXILIARY', 'porter' => 'SECURITY', 'celador' => 'SECURITY'];
        $role = null;
        foreach ($roleMap as $k => $r) if (str_contains($q, $k)) { $role = $r; break; }
        if ($role) {
            $st = $conn->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.work_shift, r.role_name
                FROM users u JOIN roles r ON r.role_id = u.role_id
                WHERE u.school_id = ? AND u.deleted_at IS NULL AND u.active = TRUE AND r.role_name = ?
                ORDER BY u.last_name LIMIT 10");
            $st->execute([$u['school_id'], $role]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return ['reply' => 'No hay personal registrado en ' . (NX_ROLE_ES[$role] ?? strtolower($role)) . '.', '_natural' => true];
            if (count($rows) === 1) return chatContactCard($rows[0]);
            return ['reply' => 'En ' . (NX_ROLE_ES[$role] ?? strtolower($role)) . ' están ' . implode(', ', array_map(fn($r) => "{$r['first_name']} {$r['last_name']}", $rows)) . '. ¿De cuál quieres el contacto?', '_natural' => true,
                '_result_set' => ['type' => 'staff', 'label' => 'personal', 'count' => count($rows),
                    'items' => array_map(fn($r) => ['id' => $r['user_id'], 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => NX_ROLE_ES[$r['role_name']] ?? $r['role_name']], $rows)]];
        }
    }
    // «correo de <nombre>» — puede venir en person o en student (el parser no
    // sabe si es docente); resolver contra personal PRIMERO
    if (!$name && !empty($s['student'])) $name = $s['student'];
    if (!$name) return ['reply' => '¿De quién quieres el contacto? Nómbralo o dime su cargo.', '_natural' => true];
    $found = chatResolveStaff($conn, $u, $name, $q ?? ($v['_q'] ?? null));
    if (!$found) return ['reply' => "No encuentro a «{$name}» entre el personal de la institución.", '_natural' => true];
    if (count($found) > 1) return ['reply' => 'Encontré varias personas: ' . implode(' · ', array_map(fn($r) => "{$r['first_name']} {$r['last_name']} (" . (NX_ROLE_ES[$r['role_name']] ?? $r['role_name']) . ')', $found)) . '. ¿Cuál?', '_natural' => true];
    return chatContactCard($found[0]);
}

function chatContactCard(array $p): array {
    $name = trim("{$p['first_name']} {$p['last_name']}");
    $role = NX_ROLE_ES[$p['role_name']] ?? strtolower($p['role_name']);
    $bits = [$role . ($p['work_shift'] ? ", jornada {$p['work_shift']}" : '')];
    if ($p['email']) $bits[] = "correo {$p['email']}";
    if ($p['phone']) $bits[] = "teléfono {$p['phone']}";
    return ['reply' => "{$name} — " . implode(' · ', $bits) . '.', '_natural' => true,
        'cards' => [['title' => "Contacto — {$name}", 'columns' => ['Nombre', 'Cargo', 'Correo', 'Teléfono'],
            'rows' => [[$name, ucfirst($role), $p['email'] ?: '—', $p['phone'] ?: '—']]]],
        'entities' => ['person' => $name]];
}

/** Horario de un docente / quién enseña una materia / qué clase ahora. */
function chat_teacher_schedule(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $dow = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    // «qué materia ve el 8A ahora» — grupo + bloque actual
    if (!empty($s['group']) && preg_match('/\b(ahora|este periodo|este bloque|en este momento|que (materia|clase|asignatura))\b/u', $q)) {
        $g = chatResolveGroup($conn, $u, $s['group']);
        if (!$g) return ['reply' => "No encuentro el grupo «{$s['group']}».", '_natural' => true];
        $day = (int)date('N'); // 1=lunes
        $st = $conn->prepare("SELECT sch.block_number, sch.start_time, sch.end_time, sub.subject_name,
                u2.first_name || ' ' || u2.last_name AS teacher, cr.classroom_name
            FROM schedules sch
            LEFT JOIN subjects sub ON sub.subject_id = sch.subject_id
            LEFT JOIN users u2 ON u2.user_id = sch.teacher_user_id
            LEFT JOIN classrooms cr ON cr.classroom_id = sch.classroom_id
            WHERE sch.group_id = ? AND sch.day_of_week = ? AND sch.start_time <= (NOW() AT TIME ZONE 'America/Bogota')::time
              AND sch.end_time >= (NOW() AT TIME ZONE 'America/Bogota')::time
            ORDER BY sch.block_number LIMIT 2");
        $st->execute([$g['group_id'], $day]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['reply' => "A esta hora {$g['group_name']} no tiene bloque programado ({$dow[$day % 7]}).", '_natural' => true];
        $r = $rows[0];
        return ['reply' => "Ahora {$g['group_name']} está en " . ($r['subject_name'] ?: 'bloque libre') . " con {$r['teacher']} (bloque {$r['block_number']}, {$r['start_time']}–{$r['end_time']}" . ($r['classroom_name'] ? ", {$r['classroom_name']}" : '') . ').', '_natural' => true];
    }
    // «quién enseña <materia> (a <grupo>)» — búsqueda por materia
    if (preg_match('/\b(quien|quienes)\s+(dicta|dictan|enseña|enseñan|imparte|imparten|da|dan|ve|ven)\s+([a-záéíóúñü]{3,})/u', $q, $mm)) {
        $sub = $mm[3];
        $g = !empty($s['group']) ? chatResolveGroup($conn, $u, $s['group']) : null;
        $w = 'sch.group_id IS NOT NULL'; $p = [];
        if ($g) { $w = 'sch.group_id = ?'; $p[] = $g['group_id']; }
        $st = $conn->prepare("SELECT DISTINCT u2.first_name || ' ' || u2.last_name AS teacher, sub.subject_name,
                string_agg(DISTINCT ag.group_name, ', ' ORDER BY ag.group_name) AS groups
            FROM schedules sch
            JOIN subjects sub ON sub.subject_id = sch.subject_id
            JOIN users u2 ON u2.user_id = sch.teacher_user_id
            JOIN academic_groups ag ON ag.group_id = sch.group_id
            WHERE {$w} AND u2.school_id = ? AND ag.school_id = ?
              AND translate(lower(sub.subject_name),'áéíóúüñ','aeiouun') LIKE translate(lower(?),'áéíóúüñ','aeiouun')
            GROUP BY teacher, sub.subject_name LIMIT 10");
        $st->execute(array_merge($p, [$u['school_id'], $u['school_id'], '%' . $sub . '%']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['reply' => "Nadie tiene «{$sub}» programada en el horario" . ($g ? " de {$g['group_name']}" : '') . '.', '_natural' => true];
        return ['reply' => implode('; ', array_map(fn($r) => "{$r['teacher']} dicta {$r['subject_name']} ({$r['groups']})", $rows)) . '.', '_natural' => true,
            'cards' => [['title' => "Docentes de {$sub}", 'columns' => ['Docente', 'Materia', 'Grupos'],
                'rows' => array_map(fn($r) => [$r['teacher'], $r['subject_name'], $r['groups']], $rows)]]];
    }
    // «horario del docente X» — grid semanal de una persona
    $name = $s['person'] ?? null;
    if (!$name && !empty($s['student']) && preg_match('/\b(docente|profesor|profe|maestr\w+)\b/u', $q)) $name = $s['student'];
    if (!$name && !empty($s['student'])) $name = $s['student'];
    if (!$name) return ['reply' => '¿De qué docente quieres el horario? Nómbralo.', '_natural' => true];
    $found = chatResolveStaff($conn, $u, $name, $q ?? ($v['_q'] ?? null));
    if (!$found) return ['reply' => "No encuentro a «{$name}» entre el personal.", '_natural' => true];
    if (count($found) > 1) return ['reply' => 'Encontré varias personas: ' . implode(' · ', array_map(fn($r) => "{$r['first_name']} {$r['last_name']} (" . (NX_ROLE_ES[$r['role_name']] ?? $r['role_name']) . ')', $found)) . '. ¿Cuál?', '_natural' => true];
    $p = $found[0];
    $st = $conn->prepare("SELECT sch.day_of_week, sch.block_number, sch.start_time, sch.end_time,
            sub.subject_name, ag.group_name, cr.classroom_name
        FROM schedules sch
        JOIN academic_groups ag ON ag.group_id = sch.group_id AND ag.school_id = ?
        LEFT JOIN subjects sub ON sub.subject_id = sch.subject_id
        LEFT JOIN classrooms cr ON cr.classroom_id = sch.classroom_id
        WHERE sch.teacher_user_id = ? ORDER BY sch.day_of_week, sch.block_number LIMIT 60");
    $st->execute([$u['school_id'], $p['user_id']]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $name = trim("{$p['first_name']} {$p['last_name']}");
    if (!$rows) return ['reply' => "{$name} no tiene bloques programados en el horario.", '_natural' => true];
    $byDay = [];
    foreach ($rows as $r) $byDay[(int)$r['day_of_week']][] = $r;
    $dowFull = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
    $reply = "{$name}: " . count($rows) . ' bloques semanales — ' . implode(', ', array_map(fn($d) => $dowFull[$d] ?? "día {$d}", array_keys($byDay))) . '.';
    $cards = [];
    foreach ($byDay as $d => $rs) {
        $cards[] = ['title' => ucfirst($dowFull[$d] ?? "día {$d}"), 'columns' => ['Bloque', 'Hora', 'Materia', 'Grupo', 'Salón'],
            'rows' => array_map(fn($r) => [$r['block_number'], substr($r['start_time'], 0, 5) . '–' . substr($r['end_time'], 0, 5),
                $r['subject_name'] ?: '—', $r['group_name'], $r['classroom_name'] ?: '—'], $rs)];
    }
    return ['reply' => $reply, '_natural' => true, 'cards' => $cards, 'entities' => ['person' => $name]];
}

/** Exención/consentimiento biométrico — quiénes están exentos y por qué. */
function chat_student_consent(PDO $conn, array $u, array $s, array $v): array {
    $scope = chatScope($conn, $u);
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
    }
    if ($stu) {
        $st = $conn->prepare("SELECT s.first_name, s.last_name, s.biometric_exempt, s.exemption_reason,
                s.consent_status, s.consent_channel, " . chatTs('s.consent_recorded_at') . " AS consent_at,
                COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS consent_by
            FROM students s LEFT JOIN users ub ON ub.user_id = s.consent_recorded_by
            WHERE s.student_id = ? AND s.school_id = ?");
        $st->execute([$stu['student_id'], $u['school_id']]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $name = trim("{$r['first_name']} {$r['last_name']}");
        $cs = ['PENDING' => 'pendiente', 'GRANTED' => 'otorgado', 'DENIED' => 'negado', 'REVOKED' => 'revocado'];
        $reply = "{$name}: " . ($r['biometric_exempt'] ? "está EXENTO de biometría" . ($r['exemption_reason'] ? " — {$r['exemption_reason']}" : ' (sin motivo registrado)')
            : 'usa biometría normal')
            . '. Consentimiento ' . ($cs[strtoupper((string)$r['consent_status'])] ?? strtolower((string)($r['consent_status'] ?: 'sin registrar')))
            . ($r['consent_at'] ? " (registrado {$r['consent_at']} por {$r['consent_by']})" : '') . '.';
        return ['reply' => $reply, '_natural' => true, 'entities' => ['student' => mb_strtolower($name)]];
    }
    $scopeSql = preg_replace('/\bs\./', 's.', (string)$scope['sql']);
    $st = $conn->prepare("SELECT s.first_name, s.last_name, s.exemption_reason, ag.group_name, s.consent_status
        FROM students s
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.biometric_exempt = TRUE {$scopeSql}
        ORDER BY ag.group_name, s.last_name LIMIT 100");
    $st->execute(array_merge([$u['school_id']], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => 'Ningún estudiante está exento de biometría — todos marcan con huella o están pendientes de enrolamiento.', '_natural' => true];
    $n = count($rows);
    return ['reply' => "{$n} estudiante" . ($n === 1 ? ' está exento' : 's están exentos') . ' de biometría.', '_natural' => true,
        'cards' => [['title' => 'Exentos de biometría', 'columns' => ['Estudiante', 'Grupo', 'Motivo', 'Consentimiento'],
            'rows' => array_map(fn($r) => ["{$r['first_name']} {$r['last_name']}", $r['group_name'] ?? '—',
                mb_strimwidth((string)($r['exemption_reason'] ?? 'sin motivo'), 0, 45, '…'), strtolower((string)($r['consent_status'] ?? '—'))], $rows)]],
        '_result_set' => ['type' => 'students', 'label' => 'exentos de biometría',
            'items' => array_map(fn($r) => ['id' => null, 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => ($r['group_name'] ?? '—') . ' · ' . mb_strimwidth((string)($r['exemption_reason'] ?? ''), 0, 30, '…')], $rows),
            'count' => $n]];
}

/** El caso/seguimiento de UN estudiante — quién lo lleva, desde cuándo, notas. */
function chat_tracking_detail(PDO $conn, array $u, array $s, array $v): array {
    if (empty($s['student'])) return ['reply' => '¿De qué estudiante quieres el seguimiento? Dame nombre o apellido.', '_natural' => true];
    $found = chatResolveStudent($conn, $u, $s['student']);
    if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
    if (count($found) > 1) return chatAmbiguous($found);
    $stu = $found[0];
    $name = trim("{$stu['first_name']} {$stu['last_name']}");
    $st = $conn->prepare("SELECT t.tracking_id, t.status, t.dependency, t.origin_type,
            " . chatTs('t.created_at') . " AS desde, " . chatTs('t.updated_at') . " AS ult_act,
            COALESCE(ub.first_name || ' ' || ub.last_name, 'sin asignar') AS encargado
        FROM student_tracking t LEFT JOIN users ub ON ub.user_id = t.assigned_to_user_id
        WHERE t.school_id = ? AND t.student_id = ? ORDER BY t.created_at DESC LIMIT 10");
    $st->execute([$u['school_id'], $stu['student_id']]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "{$name} no tiene seguimientos registrados.", '_natural' => true,
        'entities' => ['student' => mb_strtolower($name)]];
    $cur = $rows[0];
    $n = $conn->prepare("SELECT " . chatTs('n.created_at') . " AS fecha, n.note_text,
            COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS autor
        FROM student_tracking_notes n LEFT JOIN users ub ON ub.user_id = n.user_id
        WHERE n.tracking_id = ? ORDER BY n.created_at DESC LIMIT 10");
    $n->execute([$cur['tracking_id']]); $notes = $n->fetchAll(PDO::FETCH_ASSOC);
    $stEs = ['active' => 'en proceso', 'en_proceso' => 'en proceso', 'closed' => 'cerrado', 'completed' => 'cerrado', 'pending' => 'pendiente'];
    $dep = strtolower(str_replace('_', ' ', (string)$cur['dependency']));
    $reply = "El seguimiento de {$name} ({$dep}) lo lleva {$cur['encargado']}, " . ($stEs[strtolower((string)$cur['status'])] ?? $cur['status'])
        . " desde {$cur['desde']}"
        . ($notes ? '. Última nota (' . $notes[0]['fecha'] . "): «" . mb_strimwidth($notes[0]['note_text'], 0, 80, '…') . '».' : '.');
    $cards = [['title' => "Seguimientos de {$name}", 'columns' => ['Origen', 'Estado', 'Desde', 'A cargo', 'Última act.'],
        'rows' => array_map(fn($r) => [strtolower(str_replace('_', ' ', (string)$r['dependency'])), $stEs[strtolower((string)$r['status'])] ?? $r['status'],
            $r['desde'], $r['encargado'], $r['ult_act']], $rows)]];
    if ($notes) $cards[] = ['title' => 'Notas del proceso', 'columns' => ['Fecha', 'Autor', 'Nota'],
        'rows' => array_map(fn($r) => [$r['fecha'], $r['autor'], mb_strimwidth((string)$r['note_text'], 0, 60, '…')], $notes)];
    return ['reply' => $reply, '_natural' => true, 'cards' => $cards,
        'entities' => ['student' => mb_strtolower($name), 'module' => 'SEGUIMIENTO'],
        '_result_set' => ['type' => 'trackings', 'label' => "seguimiento de {$name}",
            'items' => array_map(fn($r) => ['id' => $r['tracking_id'], 'label' => $dep, 'sub' => $r['desde'] . ' · ' . $r['encargado']], $rows),
            'count' => count($rows)]];
}

/** Citaciones enviadas POR un docente — o fecha programada de una citación. */
function chat_citations_by(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $q = $v['_q'] ?? '';
    $sender = null;
    $pname = $s['person'] ?? null;
    if ($pname) {
        $f = chatResolveStaff($conn, $u, $pname, $v['_q'] ?? null);
        if (count($f) > 1) return ['reply' => 'Encontré varias personas: ' . implode(' · ', array_map(fn($r) => "{$r['first_name']} {$r['last_name']} (" . (NX_ROLE_ES[$r['role_name']] ?? $r['role_name']) . ')', $f)) . '. ¿Cuál?', '_natural' => true];
        if ($f) $sender = $f[0];
        else $s['student'] = $s['student'] ?? $pname;   // no staff — quizá es estudiante
    }
    if (!$sender && !empty($s['student']) && preg_match('/\b(cito|citamos|convoco)\b/u', $q)) {
        // «citó <nombre>» — el nombre puede ser el docente (subject), no el citado
        $f = chatResolveStaff($conn, $u, $s['student'], $v['_q'] ?? null);
        if ($f && count($f) === 1) $sender = $f[0];
    }
    $stu = null;
    if (!empty($s['student']) && !$sender) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if ($found && count($found) === 1) $stu = $found[0];
        elseif ($found) return chatAmbiguous($found);
    }
    $scope = chatScope($conn, $u);
    $scopeSql = preg_replace('/\bs\./', 'st2.', (string)$scope['sql']);
    // sin emisor ni estudiante + superlativo → ranking POR emisor:
    // «qué profesor ha citado más estudiantes», «quién ha mandado más
    // citaciones» — responde quién encabeza, no la lista plana
    if (!$sender && !$stu && preg_match('/\b(mas|mayor|top|ranking)\b/u', $q)) {
        $rk = $conn->prepare("SELECT ub.first_name || ' ' || ub.last_name AS sender,
                COUNT(DISTINCT tm.student_id) AS n_students, COUNT(*) AS n_total
            FROM twilio_messages tm
            JOIN users ub ON ub.user_id = tm.sender_user_id
            LEFT JOIN students st2 ON st2.student_id = tm.student_id
            WHERE tm.school_id = ? AND tm.type_code = 'CITACION' AND " . chatD('tm.sent_at') . " BETWEEN ? AND ? {$scopeSql}
            GROUP BY 1 ORDER BY n_students DESC, n_total DESC LIMIT 10");
        $rk->execute(array_merge([$u['school_id'], $from, $to], $scope['params']));
        $rkRows = $rk->fetchAll(PDO::FETCH_ASSOC);
        $rl = chatRangeLabel($s);
        if (!$rkRows) return ['reply' => "No hay citaciones registradas {$rl}.", '_natural' => true];
        $topR = $rkRows[0];
        return ['reply' => "{$rl}: quien más citó es {$topR['sender']} ({$topR['n_students']} estudiantes, {$topR['n_total']} mensajes).",
            '_natural' => true,
            'cards' => [['title' => "Citaciones por emisor — {$rl}", 'columns' => ['Emisor', 'Estudiantes', 'Mensajes'],
                'rows' => array_map(fn($r) => [$r['sender'], (int)$r['n_students'], (int)$r['n_total']], $rkRows)]],
            '_result_set' => ['type' => 'ranking', 'label' => 'citaciones por emisor',
                'items' => array_map(fn($r) => ['id' => null, 'label' => $r['sender'], 'sub' => "{$r['n_students']} estudiantes"], $rkRows), 'count' => count($rkRows)],
            'entities' => array_filter(['module' => 'CITACION', 'range_label' => $s['range_label'] ?? null])];
    }
    $w = ["tm.school_id = ?", "tm.type_code = 'CITACION'", chatD('tm.sent_at') . ' BETWEEN ? AND ?'];
    $p = [$u['school_id'], $from, $to];
    if ($sender) { $w[] = 'tm.sender_user_id = ?'; $p[] = $sender['user_id']; }
    if ($stu)    { $w[] = 'tm.student_id = ?';     $p[] = $stu['student_id']; }
    $st = $conn->prepare("SELECT " . chatTs('tm.sent_at') . " AS d, tm.message_content, tm.delivery_status,
            st2.first_name || ' ' || st2.last_name AS name, ag.group_name,
            COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS sender,
            g2.first_name || ' ' || g2.last_name AS guardian_name
        FROM twilio_messages tm
        LEFT JOIN students st2 ON st2.student_id = tm.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = st2.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users ub ON ub.user_id = tm.sender_user_id
        LEFT JOIN guardians gd ON gd.guardian_id = tm.guardian_id
        LEFT JOIN users g2 ON g2.user_id = gd.user_id
        WHERE " . implode(' AND ', $w) . " {$scopeSql}
        ORDER BY tm.sent_at DESC LIMIT 60");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s);
    $who = $sender ? " de {$sender['first_name']} {$sender['last_name']}" : ($stu ? " de {$stu['first_name']} {$stu['last_name']}" : '');
    $ent = array_filter(['person' => $sender ? "{$sender['first_name']} {$sender['last_name']}" : null,
        'student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'module' => 'CITACION', 'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null]);
    if (!$rows) return ['reply' => "No hay citaciones{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
    $stEs = ['QUEUED' => 'En cola', 'PROCESSING' => 'Enviando', 'SENT' => 'Enviada', 'DELIVERED' => 'Entregada', 'READ' => 'Leída',
        'FAILED' => 'Falló', 'FAILED_PERMANENT' => 'Falló definitivo', 'UNDELIVERED' => 'No entregada', 'RECEIVED' => 'Respuesta'];
    $n = count($rows);
    // fecha programada: la citación suele traer el día en el cuerpo del mensaje
    $latest = $rows[0];
    $whenQ = (bool)preg_match('/\b(cuando|que dia|a que hora|fecha|programad\w*|agendad\w*)\b/u', $q);
    $reply = ucfirst("{$rl}: {$n} " . ($n === 1 ? 'citación' : 'citaciones') . "{$who}.");
    if ($whenQ && $stu && $n === 1)
        $reply = "La citación de {$stu['first_name']} {$stu['last_name']} se envió {$latest['d']}"
            . ($latest['message_content'] ? ' — el mensaje dice: «' . mb_strimwidth($latest['message_content'], 0, 90, '…') . '»' : '') . '.';
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Citaciones{$who} — {$rl}", 'columns' => ['Fecha', 'Estudiante', 'Grupo', 'Acudiente', 'Mensaje', 'Estado', 'Envió'],
            'rows' => array_map(fn($r) => [$r['d'], $r['name'] ?? '—', $r['group_name'] ?? '—', $r['guardian_name'] ?? '—',
                mb_strimwidth((string)($r['message_content'] ?? '—'), 0, 55, '…'), $stEs[strtoupper((string)$r['delivery_status'])] ?? $r['delivery_status'], $r['sender']], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'citations', 'label' => "citaciones{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'] ?? '—', 'sub' => $r['d'] . ' · ' . mb_strimwidth((string)($r['message_content'] ?? ''), 0, 35, '…')], $rows),
            'count' => $n]];
}

/** Resolución de alertas de riesgo — quién resolvió, cuándo, cuáles siguen. */
function chat_alert_resolution(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $scope = chatScope($conn, $u);
    $q = $v['_q'] ?? '';
    $open = (bool)preg_match('/\b(sin resolver|pendientes?|abiertas?|activas?|sin atender|vigentes?)\b/u', $q);
    $w = ['a.school_id = ?', chatD('a.created_at') . ' BETWEEN ? AND ?']; $p = [$u['school_id'], $from, $to];
    $w[] = $open ? "a.status <> 'RESOLVED'" : '1=1';
    $stu = null;
    if (!empty($s['student'])) {
        $found = chatResolveStudent($conn, $u, $s['student']);
        if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
        if (count($found) > 1) return chatAmbiguous($found);
        $stu = $found[0];
        $w[] = 'a.student_id = ?'; $p[] = $stu['student_id'];
    }
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    $st = $conn->prepare("SELECT a.alert_level, a.trigger_category, a.trigger_rule, a.status,
            " . chatTs('a.created_at') . " AS creada, " . chatTs('a.resolved_at') . " AS resuelta,
            st.first_name || ' ' || st.last_name AS name, ag.group_name,
            COALESCE(rb.first_name || ' ' || rb.last_name, '—') AS resolvio,
            a.resolution_notes
        FROM risk_alerts a
        JOIN students st ON st.student_id = a.student_id AND st.deleted_at IS NULL
        LEFT JOIN student_group_assignments sga ON sga.student_id = st.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users rb ON rb.user_id = a.resolved_by
        WHERE " . implode(' AND ', $w) . " {$scopeSql}
        ORDER BY a.created_at DESC LIMIT 60");
    $st->execute(array_merge($p, $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rl = chatRangeLabel($s); $who = chatWho($stu, null);
    $ent = array_filter(['student' => $stu ? mb_strtolower("{$stu['first_name']} {$stu['last_name']}") : null,
        'range_label' => $s['range_label'] ?? null, 'from' => $s['from'] ?? null, 'to' => $s['to'] ?? null]);
    if (!$rows) return ['reply' => $open ? "No hay alertas sin resolver{$who} {$rl}." : "No hay alertas registradas{$who} {$rl}.", 'entities' => $ent, '_natural' => true];
    $stEs = ['ACTIVE' => 'activa', 'ACKNOWLEDGED' => 'vista', 'RESOLVED' => 'resuelta', 'ESCALATED' => 'escalada'];
    $lvl = ['CRITICAL' => 'crítica', 'HIGH' => 'alta', 'MEDIUM' => 'media', 'LOW' => 'baja'];
    $resolved = count(array_filter($rows, fn($r) => $r['status'] === 'RESOLVED'));
    $n = count($rows);
    $reply = $open
        ? "{$n} alerta" . ($n === 1 ? ' sigue' : 's siguen') . " sin resolver{$who} {$rl}."
        : ucfirst("{$rl}: {$n} alertas{$who} — {$resolved} resueltas, " . ($n - $resolved) . ' abiertas.');
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Alertas{$who} — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Nivel', 'Categoría', 'Creada', 'Estado', 'Resolvió', 'Cuándo'],
            'rows' => array_map(fn($r) => [$r['name'], $r['group_name'] ?? '—', $lvl[$r['alert_level']] ?? $r['alert_level'],
                $r['trigger_category'] ?? '—', $r['creada'], $stEs[$r['status']] ?? $r['status'], $r['resolvio'], $r['resuelta'] ?: '—'], $rows)]],
        'entities' => $ent,
        '_result_set' => ['type' => 'alerts', 'label' => "alertas{$who}",
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['name'], 'sub' => ($lvl[$r['alert_level']] ?? '') . ' · ' . ($stEs[$r['status']] ?? $r['status'])], $rows),
            'count' => $n]];
}

/** Movimiento de matrícula — nuevos, retirados, por grado. */
function chat_enrollment_stats(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $scope = chatScope($conn, $u);
    $scopeSql = preg_replace('/\bs\./', 's.', (string)$scope['sql']);
    $q = $v['_q'] ?? '';
    $withdrawn = (bool)preg_match('/\b(retirad\w*|se fueron|salieron|dados? de baja|desmatriculad\w*|retiros?)\b/u', $q);
    if ($withdrawn) {
        $st = $conn->prepare("SELECT s.first_name, s.last_name, ag.group_name,
                " . chatTs('s.deleted_at') . " AS retiro, " . chatTs('s.updated_at') . " AS act
            FROM students s
            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE s.school_id = ? AND s.deleted_at IS NOT NULL
              AND " . chatD('s.deleted_at') . " BETWEEN ? AND ? {$scopeSql}
            ORDER BY s.deleted_at DESC LIMIT 100");
        $st->execute(array_merge([$u['school_id'], $from, $to], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['reply' => "No hay estudiantes retirados {$rl}.", '_natural' => true];
        $n = count($rows);
        return ['reply' => ucfirst("{$rl}: {$n} estudiante" . ($n === 1 ? ' retirado' : 's retirados') . '.'), '_natural' => true,
            'cards' => [['title' => "Retiros — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Retirado'],
                'rows' => array_map(fn($r) => ["{$r['first_name']} {$r['last_name']}", $r['group_name'] ?? '—', $r['retiro'] ?: $r['act']], $rows)]],
            '_result_set' => ['type' => 'students', 'label' => 'retirados',
                'items' => array_map(fn($r) => ['id' => null, 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => $r['retiro'] ?: ''], $rows), 'count' => $n]];
    }
    $st = $conn->prepare("SELECT s.first_name, s.last_name, ag.group_name,
            " . chatTs('s.created_at') . " AS ingreso, s.grade_level
        FROM students s
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ? AND s.deleted_at IS NULL
          AND " . chatD('s.created_at') . " BETWEEN ? AND ? {$scopeSql}
        ORDER BY s.created_at DESC LIMIT 150");
    $st->execute(array_merge([$u['school_id'], $from, $to], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No ingresaron estudiantes nuevos {$rl}.", '_natural' => true];
    $n = count($rows);
    $byGrade = array_count_values(array_map(fn($r) => $r['grade_level'] ?: ($r['group_name'] ?? '?'), $rows));
    arsort($byGrade);
    $gb = implode(', ', array_map(fn($g, $c) => "{$g} ({$c})", array_keys($byGrade), $byGrade));
    return ['reply' => ucfirst("{$rl} ingresaron {$n} estudiante" . ($n === 1 ? '' : 's') . " nuevos — por grado: {$gb}."), '_natural' => true,
        'cards' => [['title' => "Ingresos — {$rl}", 'columns' => ['Estudiante', 'Grupo', 'Ingresó'],
            'rows' => array_map(fn($r) => ["{$r['first_name']} {$r['last_name']}", $r['group_name'] ?? '—', $r['ingreso']], array_slice($rows, 0, 30))]],
        '_result_set' => ['type' => 'students', 'label' => 'ingresos',
            'items' => array_map(fn($r) => ['id' => null, 'label' => "{$r['first_name']} {$r['last_name']}", 'sub' => $r['ingreso']], $rows), 'count' => $n]];
}

/** Reportes generados/descargados del sistema. */
function chat_reports_log(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $st = $conn->prepare("SELECT re.report_type, re.file_format, " . chatTs('re.generated_at') . " AS cuando,
            COALESCE(ub.first_name || ' ' || ub.last_name, '—') AS por
        FROM report_exports re LEFT JOIN users ub ON ub.user_id = re.generated_by_user_id
        WHERE re.school_id = ? AND " . chatD('re.generated_at') . " BETWEEN ? AND ?
        ORDER BY re.generated_at DESC LIMIT 50");
    $st->execute([$u['school_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No se generaron reportes {$rl}.", '_natural' => true];
    $n = count($rows);
    return ['reply' => ucfirst("{$rl} se generaron {$n} reporte" . ($n === 1 ? '' : 's') . '.'), '_natural' => true,
        'cards' => [['title' => "Reportes — {$rl}", 'columns' => ['Tipo', 'Formato', 'Generado', 'Por'],
            'rows' => array_map(fn($r) => [str_replace('_', ' ', (string)$r['report_type']), strtoupper((string)$r['file_format']), $r['cuando'], $r['por']], $rows)]],
        '_result_set' => ['type' => 'reports', 'label' => 'reportes',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['report_type'], 'sub' => $r['cuando'] . ' · ' . $r['por']], $rows), 'count' => $n]];
}

/** Detalle de alertas SOS — quién emitió, aula, estado de resolución. */
function chat_sos_detail(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $q = $v['_q'] ?? '';
    $open = (bool)preg_match('/\b(sin resolver|pendientes?|activas?|sin atender|vigentes?)\b/u', $q);
    $w = ['a.school_id = ?', chatD('a.emitted_at') . ' BETWEEN ? AND ?']; $p = [$u['school_id'], $from, $to];
    if ($open) $w[] = 'a.resolved = FALSE';
    $st = $conn->prepare("SELECT a.alert_type, a.alert_description, a.resolved,
            " . chatTs('a.emitted_at') . " AS emitida, " . chatTs('a.resolved_at') . " AS resuelta,
            COALESCE(eb.first_name || ' ' || eb.last_name, '—') AS emisor,
            COALESCE(rb.first_name || ' ' || rb.last_name, '—') AS resolvio,
            cr.classroom_name
        FROM sos_alerts a
        LEFT JOIN users eb ON eb.user_id = a.emitted_by_user_id
        LEFT JOIN users rb ON rb.user_id = a.resolved_by_user_id
        LEFT JOIN classrooms cr ON cr.classroom_id = a.classroom_id
        WHERE " . implode(' AND ', $w) . " ORDER BY a.emitted_at DESC LIMIT 40");
    $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => $open ? "No hay alertas SOS sin resolver {$rl}." : "No hay alertas SOS {$rl}.", '_natural' => true];
    $n = count($rows); $latest = $rows[0];
    $reply = ucfirst("{$rl}: {$n} " . ($n === 1 ? 'alerta SOS' : 'alertas SOS') . '.')
        . " La más reciente la emitió {$latest['emisor']}" . ($latest['classroom_name'] ? " desde {$latest['classroom_name']}" : '')
        . " ({$latest['emitida']}) — " . ($latest['resolved'] ? "resuelta por {$latest['resolvio']} ({$latest['resuelta']})" : 'aún sin resolver') . '.';
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Alertas SOS — {$rl}", 'columns' => ['Tipo', 'Emitió', 'Aula', 'Cuándo', 'Estado', 'Resolvió'],
            'rows' => array_map(fn($r) => [str_replace('_', ' ', (string)($r['alert_type'] ?? 'SOS')), $r['emisor'],
                $r['classroom_name'] ?: '—', $r['emitida'], $r['resolved'] ? 'Resuelta' : 'Sin resolver', $r['resolvio']], $rows)]],
        '_result_set' => ['type' => 'sos', 'label' => 'alertas SOS',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['emisor'], 'sub' => $r['emitida'] . ' · ' . ($r['resolved'] ? 'resuelta' : 'abierta')], $rows), 'count' => $n]];
}

/** Mensajería con el acudiente de UN estudiante — qué se envió, qué respondió. */
function chat_guardian_messages(PDO $conn, array $u, array $s, array $v): array {
    if (empty($s['student'])) return ['reply' => '¿De qué estudiante? Dame nombre o apellido y miro los mensajes a su acudiente.', '_natural' => true];
    $found = chatResolveStudent($conn, $u, $s['student']);
    if (!$found) return ['reply' => "No encuentro a «{$s['student']}» dentro de tu alcance.", '_natural' => true];
    if (count($found) > 1) return chatAmbiguous($found);
    $stu = $found[0];
    $name = trim("{$stu['first_name']} {$stu['last_name']}");
    [$from, $to] = chatRange($s);
    $rl = chatRangeLabel($s);
    $st = $conn->prepare("SELECT " . chatTs('tm.sent_at') . " AS d, " . chatTs('tm.received_at') . " AS recibida,
            tm.type_code, tm.direction, tm.delivery_status, tm.message_content,
            g2.first_name || ' ' || g2.last_name AS guardian_name
        FROM twilio_messages tm
        LEFT JOIN guardians gd ON gd.guardian_id = tm.guardian_id
        LEFT JOIN users g2 ON g2.user_id = gd.user_id
        WHERE tm.school_id = ? AND tm.student_id = ?
          AND " . chatD('tm.sent_at') . " BETWEEN ? AND ?
        ORDER BY tm.sent_at DESC LIMIT 60");
    $st->execute([$u['school_id'], $stu['student_id'], $from, $to]); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No hay mensajes con el acudiente de {$name} {$rl}.", '_natural' => true,
        'entities' => ['student' => mb_strtolower($name)]];
    $stEs = ['QUEUED' => 'En cola', 'PROCESSING' => 'Enviando', 'SENT' => 'Enviado', 'DELIVERED' => 'Entregado', 'READ' => 'Leído',
        'FAILED' => 'Falló', 'FAILED_PERMANENT' => 'Falló definitivo', 'UNDELIVERED' => 'No entregado', 'RECEIVED' => 'Recibido'];
    $out = count(array_filter($rows, fn($r) => $r['direction'] !== 'INBOUND'));
    $in  = count(array_filter($rows, fn($r) => $r['direction'] === 'INBOUND'));
    $guardian = $rows[0]['guardian_name'] ?? 'el acudiente';
    $reply = ucfirst("{$rl} se le enviaron {$out} mensajes a {$guardian} (acudiente de {$name})")
        . ($in ? " y respondió {$in}." : ' — aún no ha respondido.') ;
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "Mensajes con {$guardian} — {$rl}", 'columns' => ['Fecha', 'Tipo', 'Vía', 'Mensaje', 'Estado'],
            'rows' => array_map(fn($r) => [$r['d'], str_replace('_', ' ', (string)$r['type_code']),
                $r['direction'] === 'INBOUND' ? 'Del acudiente' : 'Del colegio',
                mb_strimwidth((string)($r['message_content'] ?? '—'), 0, 55, '…'),
                $stEs[strtoupper((string)$r['delivery_status'])] ?? $r['delivery_status']], $rows)]],
        'entities' => ['student' => mb_strtolower($name)],
        '_result_set' => ['type' => 'messages', 'label' => "mensajes a {$guardian}",
            'items' => array_map(fn($r) => (['id' => null, 'label' => $r['type_code'], 'sub' => $r['d']]), $rows), 'count' => count($rows)]];
}

/** Estado de un dispositivo concreto — último ping, configuración, aula. */
function chat_device_detail(PDO $conn, array $u, array $s, array $v): array {
    $q = $v['_q'] ?? '';
    $w = ['d.school_id = ?', 'd.active = TRUE']; $p = [$u['school_id']];
    // «el nodo del aula 4 / sensor de la entrada» — filtrar por nombre/ubicación
    if (preg_match('/\b(nodo|sensor|lector|dispositivo|huellero|punto)\s+(?:del?|de la|de)\s+([a-záéíóúñü0-9\s]{2,30}?)(?:\s+(no|esta|ultimo|tiene|sin|que|ya)|[.!? ]*$)/u', $q, $md)) {
        $w[] = "(translate(lower(d.device_name),'áéíóúüñ','aeiouun') LIKE translate(lower(?),'áéíóúüñ','aeiouun')
              OR translate(lower(COALESCE(d.location,'')),'áéíóúüñ','aeiouun') LIKE translate(lower(?),'áéíóúüñ','aeiouun'))";
        $like = '%' . trim($md[2]) . '%'; $p[] = $like; $p[] = $like;
    }
    $unconf = (bool)preg_match('/\b(sin configurar|desconfigurad\w*|no configurados?|pendientes? de configurar)\b/u', $q);
    if ($unconf) $w[] = '(d.configured = FALSE OR d.configured IS NULL)';
    $st = $conn->prepare("SELECT d.device_name, d.location, d.status, d.configured, d.app_version,
            " . chatTs('d.last_ping') . " AS ping, " . chatTs('d.last_seen_timestamp') . " AS visto,
            cr.classroom_name, ag.group_name
        FROM edge_devices d
        LEFT JOIN classrooms cr ON cr.classroom_id = d.classroom_id
        LEFT JOIN academic_groups ag ON ag.group_id = d.group_id
        WHERE " . implode(' AND ', $w) . " ORDER BY d.last_ping ASC NULLS FIRST LIMIT 40");
    $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => $unconf ? 'Todos los dispositivos están configurados.' : 'No encuentro ese dispositivo — dime el nombre del nodo o el aula.', '_natural' => true];
    $n = count($rows);
    $stEs = ['ONLINE' => 'en línea', 'OFFLINE' => 'sin conexión', 'PENDING' => 'pendiente', 'ERROR' => 'con error'];
    $reply = "{$n} dispositivo" . ($n === 1 ? '' : 's') . ($unconf ? ' sin configurar' : '') . '.';
    if ($n === 1) {
        $r = $rows[0];
        $reply = "{$r['device_name']}" . ($r['location'] ? " ({$r['location']})" : '') . ": " . ($stEs[strtoupper((string)$r['status'])] ?? strtolower((string)$r['status']))
            . ($r['ping'] ? ", último ping {$r['ping']}" : ', sin ping registrado')
            . ($r['configured'] ? '' : ' — pendiente de configuración') . '.';
    }
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => 'Dispositivos', 'columns' => ['Nombre', 'Ubicación', 'Aula', 'Estado', 'Último ping', 'Configurado'],
            'rows' => array_map(fn($r) => [$r['device_name'], $r['location'] ?: '—', $r['classroom_name'] ?: ($r['group_name'] ?: '—'),
                $stEs[strtoupper((string)$r['status'])] ?? $r['status'], $r['ping'] ?: 'nunca', $r['configured'] ? 'sí' : 'no'], $rows)]],
        '_result_set' => ['type' => 'devices', 'label' => 'dispositivos',
            'items' => array_map(fn($r) => ['id' => null, 'label' => $r['device_name'], 'sub' => ($stEs[strtoupper((string)$r['status'])] ?? '—') . ' · ' . ($r['ping'] ?: 'sin ping')], $rows), 'count' => $n]];
}

/** Tendencia de asistencia — día pico, promedio, comparación de períodos. */
function chat_attendance_trend(PDO $conn, array $u, array $s, array $v): array {
    [$from, $to] = chatRange($s);
    $scope = chatScope($conn, $u);
    $q = $v['_q'] ?? '';
    $mod = $s['module'] ?? 'INASISTENCIA';
    $typ = ['INASISTENCIA' => 'inasistencias', 'LATE_ARRIVAL' => 'tardanzas', 'EVASION_INTERNA' => 'evasiones'];
    $ml = $typ[$mod] ?? 'incidentes';
    $scopeSql = preg_replace('/\bs\./', 'st.', (string)$scope['sql']);
    $base = "FROM attendance_incidents ai JOIN students st ON st.student_id = ai.student_id AND st.deleted_at IS NULL " . chatIncGroupJoin();
    $rl = chatRangeLabel($s);
    // «qué día de la semana falta más» — agrupa por weekday en el rango;
    // un rango de UN día no permite argmax (todo empata) — se amplía al
    // mes móvil cuando la frase no trajo rango propio
    if (preg_match('/\b(que dia (de la semana )?(faltan|falta|faltaron|hubo|hay) mas|dia de la semana con mas|entre semana|que dia se falta mas)\b/u', $q)) {
        if (!isset($s['from']) || (strtotime($to) - strtotime($from)) < 14 * 86400) {
            $from = nxToday(59); $to = nxToday(); $rl = 'los últimos 60 días';
        }
        $st = $conn->prepare("SELECT EXTRACT(ISODOW FROM " . chatD('ai.detected_at') . ") AS dw, COUNT(*) c
            {$base} WHERE ai.school_id = ? AND ai.incident_type = ? AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? {$scopeSql}
            GROUP BY 1 ORDER BY c DESC LIMIT 7");
        $st->execute(array_merge([$u['school_id'], $mod, $from, $to], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['reply' => "No hay {$ml} registradas {$rl}.", '_natural' => true];
        $dow = [1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
        $top = $rows[0];
        return ['reply' => "El día con más {$ml} {$rl} es el {$dow[(int)$top['dw']]} ({$top['c']}).", '_natural' => true,
            'cards' => [['title' => "{$ml} por día de la semana — {$rl}", 'columns' => ['Día', 'Total'],
                'rows' => array_map(fn($r) => [ucfirst($dow[(int)$r['dw']] ?? $r['dw']), (int)$r['c']], $rows)]]];
    }
    // «en qué mes hubo más X» — argmax por bucket mensual. La pregunta no
    // trae rango propio: un rango corto heredado («ayer») no acota el
    // argmax — el alcance real es el año escolar
    if (preg_match('/\b(en que|que|por que)\s+mes\b|por mes|mensual|\bmes (con|de)\s+mas\b/u', $q)) {
        if (!isset($s['from']) || (strtotime($to) - strtotime($from)) < 60 * 86400) {
            $from = nxToday(364); $to = nxToday(); $rl = 'los últimos 12 meses';
        }
        $st = $conn->prepare("SELECT TO_CHAR(" . chatD('ai.detected_at') . ", 'YYYY-MM') AS ym, COUNT(*) c
            {$base} WHERE ai.school_id = ? AND ai.incident_type = ? AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? {$scopeSql}
            GROUP BY 1 ORDER BY c DESC LIMIT 14");
        $st->execute(array_merge([$u['school_id'], $mod, $from, $to], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['reply' => "No hay {$ml} registradas {$rl}.", '_natural' => true];
        $mn = fn(string $ym) => ucfirst(NX_MONTH_NAMES[(int)substr($ym, 5, 2)] ?? $ym) . ' ' . substr($ym, 0, 4);
        $top = $rows[0];
        return ['reply' => "El mes con más {$ml} {$rl} es {$mn($top['ym'])} ({$top['c']}).", '_natural' => true,
            'cards' => [['title' => "{$ml} por mes — {$rl}", 'columns' => ['Mes', 'Total'],
                'rows' => array_map(fn($r) => [$mn($r['ym']), (int)$r['c']], $rows)]],
            '_result_set' => ['type' => 'incidents', 'label' => "{$ml} por mes",
                'items' => array_map(fn($r) => ['id' => null, 'label' => $mn($r['ym']), 'sub' => "{$r['c']} {$ml}"], $rows), 'count' => count($rows)]];
    }
    // serie diaria + promedio + comparación con el período anterior
    $st = $conn->prepare("SELECT " . chatD('ai.detected_at') . " AS d, COUNT(*) c
        {$base} WHERE ai.school_id = ? AND ai.incident_type = ? AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? {$scopeSql}
        GROUP BY 1 ORDER BY c DESC LIMIT 40");
    $st->execute(array_merge([$u['school_id'], $mod, $from, $to], $scope['params'])); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['reply' => "No hay {$ml} {$rl} — nada que medir.", '_natural' => true];
    $total = array_sum(array_map(fn($r) => (int)$r['c'], $rows));
    $daysN = count($rows);
    $avg = round($total / max(1, $daysN), 1);
    $peak = $rows[0];
    $fmt = fn(string $d) => (int)date('j', strtotime($d)) . ' de ' . NX_MONTH_NAMES[(int)date('n', strtotime($d))];
    // comparar con período anterior equivalente
    [$pf, $pt, $pl] = array_values(chatPreviousPeriod($from, $to, $s['range_label'] ?? null));
    $pc = $conn->prepare("SELECT COUNT(*) {$base} WHERE ai.school_id = ? AND ai.incident_type = ? AND " . chatD('ai.detected_at') . " BETWEEN ? AND ? {$scopeSql}");
    $pc->execute(array_merge([$u['school_id'], $mod, $pf, $pt], $scope['params'])); $prev = (int)$pc->fetchColumn();
    $delta = $prev > 0 ? round(($total - $prev) / $prev * 100) : null;
    $reply = ucfirst("{$rl}: {$total} {$ml} (promedio {$avg}/día).")
        . " El día con más fue {$fmt($peak['d'])} con {$peak['c']}."
        . ($prev ? ' Frente a ' . $pl . " ({$prev}): " . ($delta !== null ? ($delta >= 0 ? '+' : '') . "{$delta}%" : 'igual') . '.' : " En {$pl} no hubo {$ml}.");
    return ['reply' => $reply, '_natural' => true,
        'cards' => [['title' => "{$ml} por día — {$rl}", 'columns' => ['Día', 'Total'],
            'rows' => array_map(fn($r) => [$fmt($r['d']), (int)$r['c']], array_slice($rows, 0, 15))]],
        '_result_set' => ['type' => 'incidents', 'label' => $ml,
            'items' => array_map(fn($r) => ['id' => null, 'label' => $fmt($r['d']), 'sub' => "{$r['c']} {$ml}"], $rows), 'count' => $daysN]];
}
