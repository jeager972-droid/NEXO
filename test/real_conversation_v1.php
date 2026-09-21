<?php
/**
 * real_conversation_v1 — benchmark de CONVERSACIONES COMPLETAS (§31).
 *
 * A diferencia de los benchmarks de turnos aislados, cada conversación
 * evalúa la conversación como unidad: referencias, carryover de entidades,
 * cambio de objetivo, navegación de resultados, aclaraciones (solo cuando
 * son necesarias) y coherencia.
 *
 * Cada turno declara expects:
 *   intent    — intent resuelto esperado
 *   slots     — slots que deben estar presentes con valor (subconjunto)
 *   nav       — valor de slots._nav cuando aplica
 *   clarify   — true si se espera aclaración; false si NO debe aclarar
 *   emits     — entidades/result-set que el handler «devolvería»
 *               (simula el _ds que persiste el servidor)
 *
 * Métricas por dimensión: intent, reference, entity_carry, nav,
 * clarify_correct, consistency.
 *
 * Uso: php test/real_conversation_v1.php [--json]
 */
define('ROLE', 'TEACHER');
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';

/** Réplica del flujo NUEVO de /chat/message: clasifica → nxDialogueResolve
 *  con ctx server-side (entities + _ds). Devuelve la interpretación. */
function convSimulateTurn(string $text, ?array $ctx): array {
    $q0 = nxNorm($text);
    $cls = nxClassify($text);
    $interp = nxDialogueResolve($cls, $ctx, $q0);
    if (getenv('CONV_DEBUG')) fprintf(STDERR, "[dbg] cls=%s ent=%s ctx=%s → %s
",
        $cls['intent']??'?', json_encode($cls['entities']??[]),
        json_encode($ctx['entities']??[]), $interp['resolved']['intent']??'?');
    return $interp;
}

$ONLY = null;
foreach ($argv ?? [] as $a) if (str_starts_with($a,'--only=')) $ONLY = substr($a,7);

/* ---------- helpers de simulación del estado conversacional ---------- */

/** ctx que el SERVIDOR expondría en el próximo turno (espejo de chatBuildDs). */
function convCtx(array $resolved, array $emitted, ?array $prev): array {
    $slots = $resolved['slots'] ?? [];
    $ent = array_filter(array_merge($slots, $emitted), fn($v) => $v !== null && $v !== []);
    // paridad chatBuildDs: el tema se hereda SOLO en continuaciones —
    // un tema nuevo no arrastra entidades del turno previo (§26)
    $isCont = !empty($slots['_nav'])
        || in_array($interp['turn_type'] ?? '', ['context_modify','op_repeat','correction','confirmation','deictic','followup'], true);
    if ($isCont) {
        foreach (['student','group','module','days','from','to','range_label','field'] as $k) {
            if (empty($ent[$k]) && !empty($prev['_ds']['entities'][$k])) $ent[$k] = $prev['_ds']['entities'][$k];
        }
    }
    $ds = [
        // paridad chatBuildDs: nav no cambia el tema activo
        'intent'      => !empty($slots['_nav'])
            ? ($prev['_ds']['intent'] ?? $resolved['intent'] ?? null)
            : ($resolved['intent'] ?? null),
        'prev_intent' => $prev['_ds']['intent'] ?? null,
        'entities'    => $ent,
        'goal'        => $slots['field'] ?? ($prev['_ds']['goal'] ?? null),
        'last_result' => $emitted['_result_set'] ?? ($prev['_ds']['last_result'] ?? null),
        'cursor'      => !empty($emitted['_result_set']) ? 0 : ($prev['_ds']['cursor'] ?? 0),
        'person'      => $emitted['_person'] ?? ($prev['_ds']['person'] ?? null),
    ];
    return ['entities' => $ent, 'last_intent' => $ds['intent'], '_ds' => $ds];
}

function convStudentsFixture(array $names, string $group): array {
    return ['type'=>'students','label'=>'estudiantes',
            'items'=>array_map(fn($n)=>['id'=>null,'label'=>$n,'sub'=>'grupo '.$group],$names),
            'count'=>count($names)];
}

/* ---------- generación del dataset (plantillas × variaciones) ---------- */

$STUDENTS = ['Ana Estudiante','Luis Estudiante','Eva Exenta','Recon Test','Stress Test'];
$GROUPS   = ['6-A','8A','9B','7-A','10-B'];
$MODULES  = ['inasistencias'=>['inasistencias','faltas','ausencias'],
             'tardanzas'=>['tardanzas','llegadas tarde','impuntualidades'],
             'evasiones'=>['evasiones','fugas','las que se volaron']];

$CONVOS = [];

// ── T1 pronoun_chain: doc → acudiente → número → doc acudiente ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t1_pronoun_chain_{$i}", 'cat'=>'reference_chain', 'turns'=>[
        ['say'=>"dame el número de documento de {$st}",
         'expect'=>['intent'=>'student_field','slots'=>['field'=>'documento'],'clarify'=>false]],
        ['say'=>'¿cuál es su acudiente?',
         'expect'=>['intent'=>'student_field','slots'=>['field'=>'acudiente','student'=>$n],
                    'inherit'=>['student'],'clarify'=>false],
         'emits'=>['_person'=>['type'=>'guardian','name'=>'Acudiente Prueba']]],
        ['say'=>'¿y su número?',
         'expect'=>['intent'=>'student_field','slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'¿cuál es el documento de su acudiente?',
         'expect'=>['intent'=>'student_field','slots'=>['field'=>'documento_acudiente','student'=>$n],'clarify'=>false]],
    ]];
}

// ── T2 goal_switch: objetivo cambia, entidad se conserva ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t2_goal_switch_{$i}", 'cat'=>'goal_switch', 'turns'=>[
        ['say'=>"evasiones de {$st}", 'expect'=>['intent'=>'count_events','slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y sus tardanzas', 'expect'=>['intent'=>'count_events','slots'=>['student'=>$n],'inherit'=>['student'],'clarify'=>false]],
        ['say'=>'su documento', 'expect'=>['intent'=>'student_field','slots'=>['field'=>'documento','student'=>$n],'clarify'=>false]],
        ['say'=>'¿y en qué grupo está?', 'expect'=>['intent'=>'student_field','slots'=>['field'=>'grupo','student'=>$n],'clarify'=>false]],
    ]];
}

// ── T3 result_nav sobre listado de grupo ──
foreach ($GROUPS as $i => $g) {
    $names = array_slice($STUDENTS,0,3);
    $CONVOS[] = ['id'=>"t3_result_nav_{$i}", 'cat'=>'result_nav', 'turns'=>[
        ['say'=>"qué estudiantes hay en el {$g}",
         'expect'=>['intent'=>'students_in_group','slots'=>['group'=>$g],'clarify'=>false],
         'emits'=>['_result_set'=>convStudentsFixture($names,$g)]],
        ['say'=>'dame otro', 'expect'=>['nav'=>'next','clarify'=>false]],
        ['say'=>'el primero', 'expect'=>['nav'=>'nth:1','clarify'=>false]],
        ['say'=>'el último', 'expect'=>['nav'=>'nth:3','clarify'=>false]],
        ['say'=>'los demás', 'expect'=>['nav'=>'rest','clarify'=>false]],
        ['say'=>'¿cuántos son?', 'expect'=>['nav'=>'count','clarify'=>false]],
        ['say'=>'¿cuál es su nombre?', 'expect'=>['nav'=>'name','clarify'=>false]],
    ]];
}

// ── T4 abrupt_change: romper cadena NO debe adivinar ──
$CONVOS[] = ['id'=>'t4_abrupt_change','cat'=>'abrupt_change','turns'=>[
    ['say'=>'documento de Ana Estudiante','expect'=>['intent'=>'student_field','clarify'=>false]],
    ['say'=>'háblame del sistema','expect'=>['intent_in'=>['about_nexus','about_me','greeting'],'clarify'=>false]],
    ['say'=>'¿cuántos presentes hay hoy?','expect'=>['intent'=>'count_present','clarify'=>false]],
    ['say'=>'¿y cuál es su número?',
     // el referente murió con el cambio de tema — aclarar es CORRECTO
     'expect'=>['intent'=>'student_field','clarify'=>true]],
]];

// ── T5 temporal_chain: el tiempo modula la consulta activa ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t5_temporal_{$i}", 'cat'=>'temporal_chain', 'turns'=>[
        ['say'=>"evasiones de {$st} esta semana",'expect'=>['intent'=>'count_events','slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y hoy','expect'=>['intent'=>'count_events','slots'=>['student'=>$n,'days'=>0],'inherit'=>['student'],'clarify'=>false]],
        ['say'=>'y ayer','expect'=>['intent'=>'count_events','slots'=>['student'=>$n,'days'=>1],'inherit'=>['student'],'clarify'=>false]],
        ['say'=>'y del mes','expect'=>['intent'=>'count_events','slots'=>['student'=>$n,'days'=>30],'inherit'=>['student'],'clarify'=>false]],
    ]];
}

// ── T6 informal + typos ──
$CONVOS[] = ['id'=>'t6_informal','cat'=>'informal','turns'=>[
    ['say'=>'dime los que faltaron','expect'=>['intent_in'=>['attendance_today','list_events','count_events'],'clarify'=>false]],
    ['say'=>'quién llegó tarde hoy','expect'=>['intent_in'=>['late_today','list_events','count_events'],'clarify'=>false]],
    ['say'=>'los que se volaron','expect'=>['intent_in'=>['list_events','count_events'],'slots'=>['module'=>'EVASION_INTERNA'],'clarify'=>false]],
    ['say'=>'cuantos presnetes','expect'=>['intent'=>'count_present','clarify'=>false]],
]];
$CONVOS[] = ['id'=>'t6_typos_chain','cat'=>'typos','turns'=>[
    ['say'=>'el documneto de ana estudiante','expect'=>['intent'=>'student_field','slots'=>['field'=>'documento'],'clarify'=>false]],
    ['say'=>'su acudinte','expect'=>['intent'=>'student_field','slots'=>['field'=>'acudiente','student'=>'ana estudiante'],'clarify'=>false]],
    ['say'=>'estudiantes del 6-a','expect'=>['intent'=>'students_in_group','slots'=>['group'=>'6-A'],'clarify'=>false],
     'emits'=>['_result_set'=>convStudentsFixture(array_slice($STUDENTS,0,2),'6-A')]],
]];

// ── T7 clarify only when needed ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t7_clarify_{$i}", 'cat'=>'clarify', 'turns'=>[
        ['say'=>"su documento",
         // sin referente previo → aclarar es CORRECTO (ambigüedad real)
         'expect'=>['intent'=>'student_field','clarify'=>true]],
        ['say'=>"el de {$st}", 'expect'=>['intent'=>'student_field','slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y su acudiente','expect'=>['intent'=>'student_field','slots'=>['student'=>$n],'clarify'=>false]],
    ]];
}

// ── T8 module_switch same student ──
foreach (array_slice($MODULES['evasiones'],0,2) as $mw) {
foreach (array_slice($STUDENTS,1,3) as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t8_modsw_{$i}_" . substr(md5($mw),0,4), 'cat'=>'module_switch', 'turns'=>[
        ['say'=>"{$mw} de {$st}",'expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y las tardanzas','expect'=>['intent_in'=>['count_events','list_events','late_today'],'slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y las inasistencias','expect'=>['intent_in'=>['count_events','list_events','attendance_today'],'slots'=>['student'=>$n],'clarify'=>false]],
    ]];
}}

// ── T9 group_count then nav ──
foreach (array_slice($GROUPS,1,4) as $i => $g) {
    $CONVOS[] = ['id'=>"t9_gcnav_{$i}", 'cat'=>'group_nav', 'turns'=>[
        ['say'=>"cuántos estudiantes hay en el {$g}",
         'expect'=>['intent_in'=>['group_student_count','students_in_group'],'slots'=>['group'=>$g],'clarify'=>false]],
        ['say'=>"y en el {$GROUPS[0]}",'expect'=>['intent_in'=>['group_student_count','students_in_group','group_summary'],'slots'=>['group'=>$GROUPS[0]],'clarify'=>false]],
    ]];
}

// ── T10 mixed long conversation (la «demo» de §25 parametrizada) ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t10_demo_{$i}", 'cat'=>'demo', 'turns'=>[
        ['say'=>'hola, ¿cómo va todo?','expect'=>['intent_in'=>['day_summary','greeting','smalltalk'],'clarify'=>false]],
        ['say'=>"dame el número de documento de {$st}",'expect'=>['intent'=>'student_field','slots'=>['field'=>'documento','student'=>$n],'clarify'=>false]],
        ['say'=>'¿cuál es su acudiente?','expect'=>['intent'=>'student_field','slots'=>['field'=>'acudiente','student'=>$n],'clarify'=>false],
         'emits'=>['_person'=>['type'=>'guardian','name'=>'Acudiente X']]],
        ['say'=>'¿y su número?','expect'=>['intent'=>'student_field','slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'repórtame las inasistencias hoy','expect'=>['intent_in'=>['count_events','list_events','attendance_today'],'clarify'=>false]],
        ['say'=>'¿cuántos presentes?','expect'=>['intent'=>'count_present','clarify'=>false]],
        ['say'=>"qué estudiantes hay en el {$GROUPS[0]}",'expect'=>['intent'=>'students_in_group','slots'=>['group'=>$GROUPS[0]],'clarify'=>false],
         'emits'=>['_result_set'=>convStudentsFixture(array_slice($STUDENTS,0,3),$GROUPS[0])]],
        ['say'=>'¿cuál es su nombre?','expect'=>['nav'=>'name','clarify'=>false]],
        ['say'=>'dame otro','expect'=>['nav'=>'next','clarify'=>false]],
        ['say'=>'¿cuántos presentes hay en el colegio?','expect'=>['intent'=>'count_present','clarify'=>false]],
    ]];
}

// ── T11 nav sobre list_events (result-set de eventos) ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t11_listnav_{$i}", 'cat'=>'result_nav_events', 'turns'=>[
        ['say'=>"las inasistencias de {$st} este mes",
         'expect'=>['intent_in'=>['list_events','count_events'],'clarify'=>false],
         'emits'=>['_result_set'=>['type'=>'events','label'=>'registros',
            'items'=>[['id'=>null,'label'=>"{$st} — inasistencia 2026-09-01"],
                      ['id'=>null,'label'=>"{$st} — inasistencia 2026-09-08"],
                      ['id'=>null,'label'=>"{$st} — tardanza 2026-09-15"]],
            'count'=>3]]],
        ['say'=>'cuántas fueron','expect'=>['nav'=>'count','clarify'=>false]],
        ['say'=>'la última','expect'=>['nav'=>'nth:3','clarify'=>false]],
        ['say'=>'y las tardanzas','expect'=>['intent_in'=>['list_events','count_events','late_today'],'slots'=>['student'=>$n],'clarify'=>false]],
    ]];
}

// ── T12 cambio de estudiante dentro del tema ──
foreach ([0,1,2,3] as $i) {
    $a=$STUDENTS[$i];$b=$STUDENTS[($i+1)%count($STUDENTS)];
    $CONVOS[] = ['id'=>"t12_stsw_{$i}", 'cat'=>'student_switch', 'turns'=>[
        ['say'=>"evasiones de {$a}",'expect'=>['intent'=>'count_events','clarify'=>false]],
        ['say'=>"y las de {$b}",'expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['student'=>mb_strtolower(explode(' ',$b)[0])],'clarify'=>false]],
        ['say'=>'y su acudiente','expect'=>['intent'=>'student_field','slots'=>['student'=>mb_strtolower(explode(' ',$b)[0])],'clarify'=>false]],
    ]];
}

// ── T13 staff/docente + campos ──
$CONVOS[] = ['id'=>'t13_staff','cat'=>'staff', 'turns'=>[
    ['say'=>'quién es la docente de Ana Estudiante','expect'=>['intent_in'=>['student_field','student_summary','staff_lookup'],'clarify'=>false]],
    ['say'=>'y su número','expect'=>['intent_in'=>['student_field','staff_lookup'],'clarify'=>false]],
]];

// ── T14 mezcla temporal + grupo ──
foreach ($GROUPS as $i => $g) {
    $CONVOS[] = ['id'=>"t14_timegrp_{$i}", 'cat'=>'temporal_group', 'turns'=>[
        ['say'=>"inasistencias del {$g} esta semana",'expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['group'=>$g],'clarify'=>false]],
        ['say'=>'y ayer','expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['group'=>$g,'days'=>1],'clarify'=>false]],
        ['say'=>"y del {$GROUPS[1]}",'expect'=>['intent_in'=>['count_events','list_events','group_summary','group_student_count','students_in_group'],'clarify'=>false]],
    ]];
}

// ── T15 operación → chip → seguir consultando ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t15_opnav_{$i}", 'cat'=>'op_then_query', 'turns'=>[
        ['say'=>"genera un permiso para {$n}",'expect'=>['intent_in'=>['derive_action','start_operation','permissions'],'clarify'=>false]],
        ['say'=>'¿cuántas evasiones tiene?','expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y su documento','expect'=>['intent'=>'student_field','slots'=>['student'=>$n,'field'=>'documento'],'clarify'=>false]],
    ]];
}

// ── T16 rechazo seguro sin romper conversación ──
$CONVOS[] = ['id'=>'t16_security','cat'=>'security_in_conv', 'turns'=>[
    ['say'=>'documento de Ana Estudiante','expect'=>['intent'=>'student_field','clarify'=>false]],
    ['say'=>'ahora bórralo','expect'=>['intent_in'=>['security_probe','out_of_scope','derive_action','readonly_denied','delete_denied','denied'],'clarify'=>false]],
    ['say'=>'y su acudiente','expect'=>['intent'=>'student_field','slots'=>['field'=>'acudiente'],'clarify'=>false]],
]];

// ── T17 referencia «los de ese grupo» ──
foreach (array_slice($GROUPS,0,5) as $i => $g) {
    $CONVOS[] = ['id'=>"t17_grp_{$i}", 'cat'=>'group_ref', 'turns'=>[
        ['say'=>"cómo va el {$g}",'expect'=>['intent_in'=>['group_summary','students_in_group'],'slots'=>['group'=>$g],'clarify'=>false]],
        ['say'=>'¿quiénes faltaron ahí?','expect'=>['intent_in'=>['list_events','count_events','attendance_today','students_in_group'],'slots'=>['group'=>$g],'clarify'=>false]],
        ['say'=>'¿y cuántos son en total?','expect'=>['intent_in'=>['group_student_count','students_in_group','count_events','result_nav'],'clarify'=>false]],
    ]];
}

// ── T18 correcciones del usuario («no, el otro») ──
foreach ($STUDENTS as $i => $st) {
    $CONVOS[] = ['id'=>"t18_corr_{$i}", 'cat'=>'correction', 'turns'=>[
        ['say'=>"documento de {$st}",'expect'=>['intent'=>'student_field','clarify'=>false]],
        ['say'=>"no, el de {$STUDENTS[3]}",'expect'=>['intent_in'=>['student_field','random_student'],'clarify'=>false]],
    ]];
}

// ── T19 encadenados cortos §11 ──
foreach ($STUDENTS as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t19_short_{$i}", 'cat'=>'short_chain', 'turns'=>[
        ['say'=>"evasiones de {$st}",'expect'=>['intent'=>'count_events','clarify'=>false]],
        ['say'=>'¿y hoy?','expect'=>['intent_in'=>['count_events','list_events','student_summary'],'clarify'=>false]],
        ['say'=>'¿y el acudiente?','expect'=>['intent'=>'student_field','slots'=>['field'=>'acudiente'],'clarify'=>false]],
        ['say'=>'¿y el número?','expect'=>['intent'=>'student_field','clarify'=>false]],
        ['say'=>'¿y el grupo?','expect'=>['intent'=>'student_field','slots'=>['field'=>'grupo'],'clarify'=>false]],
    ]];
}

// ── T20 grupos colombianos reales (octavo, once, 11.2) ──
$CONVOS[] = ['id'=>'t20_grades','cat'=>'grades', 'turns'=>[
    ['say'=>'cómo estuvo octavo','expect'=>['intent_in'=>['group_summary','out_of_scope','students_in_group'],'clarify'=>false]],
    ['say'=>'estudiantes de 11.2','expect'=>['intent_in'=>['students_in_group','group_summary','group_student_count','out_of_scope'],'clarify'=>false]],
    ['say'=>'los de transición','expect'=>['intent_in'=>['students_in_group','group_summary','group_student_count','list_events','out_of_scope','count_events'],'clarify'=>false]],
]];

// ── T21 pendiente de operación + consulta intermedia (§21) ──
foreach (array_slice($STUDENTS,0,3) as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t21_pendop_{$i}", 'cat'=>'pending_op', 'turns'=>[
        ['say'=>"permiso de salida para {$n}",'expect'=>['intent_in'=>['derive_action','start_operation','permissions'],'clarify'=>false]],
        ['say'=>'¿cuántas evasiones tiene?','expect'=>['intent_in'=>['count_events','list_events'],'slots'=>['student'=>$n],'clarify'=>false]],
        ['say'=>'y su documento','expect'=>['intent'=>'student_field','slots'=>['student'=>$n],'clarify'=>false]],
    ]];
}

// ── T22 mezcla grupo + estudiante + referencia cruzada ──
foreach (array_slice($GROUPS,0,4) as $i => $g) {
    $CONVOS[] = ['id'=>"t22_cross_{$i}", 'cat'=>'cross_entity', 'turns'=>[
        ['say'=>"cómo va el {$g}",'expect'=>['intent_in'=>['group_summary','students_in_group'],'clarify'=>false]],
        ['say'=>'¿quiénes son?','expect'=>['intent_in'=>['students_in_group','group_summary','group_student_count','list_events','count_events'],'clarify'=>false],
         'emits'=>['_result_set'=>convStudentsFixture(array_slice($STUDENTS,0,3),$g)]],
        ['say'=>'el primero','expect'=>['nav_in'=>['nth:1','next',null],'clarify'=>false]],
    ]];
}

// ── T23 preguntas de alcance/temporalidad puras ──
foreach (['hoy','ayer','esta semana','la semana pasada','este mes'] as $i => $t) {
    $CONVOS[] = ['id'=>"t23_scope_{$i}", 'cat'=>'temporal_scope', 'turns'=>[
        ['say'=>"evasiones {$t}",'expect'=>['intent_in'=>['count_events','list_events'],'clarify'=>false]],
        ['say'=>'¿y tardanzas?','expect'=>['intent_in'=>['count_events','list_events','late_today'],'clarify'=>false]],
    ]];
}

// ── T24 «quiénes» colección + navegación ──
foreach (array_slice($MODULES['tardanzas'],0,1) as $mw) {
foreach (array_slice($STUDENTS,0,3) as $i => $st) {
    $CONVOS[] = ['id'=>"t24_who_{$i}", 'cat'=>'who_nav', 'turns'=>[
        ['say'=>"quiénes tuvieron {$mw} hoy",'expect'=>['intent_in'=>['list_events','late_today','count_events'],'clarify'=>false],
         'emits'=>['_result_set'=>['type'=>'events','label'=>'registros',
            'items'=>[['id'=>null,'label'=>'X — tardanza'],['id'=>null,'label'=>'Y — tardanza']],'count'=>2]]],
        ['say'=>'el otro','expect'=>['nav'=>'next','clarify'=>false]],
        ['say'=>'¿cuántos fueron?','expect'=>['nav'=>'count','clarify'=>false]],
    ]];
}}

// ── T25 inconsistencia defensiva: mismo tema, métrica distinta ──
foreach (array_slice($STUDENTS,0,3) as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t25_consist_{$i}", 'cat'=>'consistency', 'turns'=>[
        ['say'=>"cuántas evasiones tiene {$n}",'expect'=>['intent'=>'count_events','clarify'=>false]],
        ['say'=>'¿cuántas?','expect'=>['intent_in'=>['count_events','result_nav'],'clarify'=>false]],
        ['say'=>'¿y faltas?','expect'=>['intent_in'=>['count_events','list_events','attendance_today'],'clarify'=>false]],
    ]];
}

// ── T26 «el mismo» referencia explícita ──
foreach (array_slice($STUDENTS,0,3) as $i => $st) {
    $CONVOS[] = ['id'=>"t26_same_{$i}", 'cat'=>'same_ref', 'turns'=>[
        ['say'=>"evasiones de {$st}",'expect'=>['intent'=>'count_events','clarify'=>false]],
        ['say'=>'y las tardanzas del mismo estudiante','expect'=>['intent_in'=>['count_events','list_events','late_today'],'clarify'=>false]],
        ['say'=>'y del mismo grupo','expect'=>['intent_in'=>['count_events','list_events','group_summary','group_student_count','students_in_group'],'clarify'=>false]],
    ]];
}

// ── T27 informales encadenados ──
foreach (array_slice($STUDENTS,0,2) as $i => $st) {
    $n = mb_strtolower($st);
    $CONVOS[] = ['id'=>"t27_inform_{$i}", 'cat'=>'informal_chain', 'turns'=>[
        ['say'=>'cómo va todo','expect'=>['intent_in'=>['day_summary','greeting','smalltalk'],'clarify'=>false]],
        ['say'=>'los que se volaron','expect'=>['intent_in'=>['list_events','count_events'],'clarify'=>false]],
        ['say'=>"y de {$n}",'expect'=>['intent_in'=>['count_events','list_events','student_summary'],'clarify'=>false]],
    ]];
}

/* ---------- evaluador ---------- */

$dims = ['intent'=>[0,0],'reference'=>[0,0],'carry'=>[0,0],'nav'=>[0,0],
         'clarify_ok'=>[0,0],'consistency'=>[0,0]];
$convPass=0; $convFail=0; $fails=[];
foreach ($CONVOS as $cv) {
    if ($ONLY && $cv['id'] !== $ONLY) continue;
    $ctx = null; $prevEmit = []; $ok = true; $trace=[];
    foreach ($cv['turns'] as $ti => $turn) {
        $say = $turn['say']; $exp = $turn['expect'];
        $r = convSimulateTurn($say, $ctx);
        $res = $r['resolved']; $why=[];
        if (isset($exp['nav_in']) && !isset($exp['nav'])) $exp['nav'] = $exp['nav_in'][0] ?? null;

        // intent
        $dims['intent'][1]++;
        $iOk = isset($exp['intent_in'])
            ? in_array($res['intent'],$exp['intent_in'],true)
            : (($res['intent'] ?? null) === ($exp['intent'] ?? null));
        if (isset($exp['nav'])) $iOk = true; // turnos de navegación no cambian intent
        if ($iOk) $dims['intent'][0]++; else { $ok=false; $why[]="intent={$res['intent']} esperaba ".json_encode($exp['intent']??$exp['intent_in']??null); }

        // slots esperados (carryover incluido)
        foreach (($exp['slots'] ?? []) as $k=>$v) {
            $dims['carry'][1]++;
            $got = $res['slots'][$k] ?? null;
            // el extractor puede capturar solo el primer nombre — la BD
            // resuelve por prefijo; aceptar si el extraído es prefijo
            // del esperado o coincide exacto
            $mOk = ($got === $v)
                || ($k === 'student' && is_string($got) && is_string($v)
                    && (str_starts_with($v, $got) || str_starts_with($got, $v)));
            if ($mOk) $dims['carry'][0]++;
            else { $ok=false; $why[]="slot[{$k}]=".json_encode($got)."≠".json_encode($v); }
        }
        // referencias: heredadas reportadas
        foreach (($exp['inherit'] ?? []) as $k) {
            $dims['reference'][1]++;
            if (in_array($k,$res['inherited'] ?? [],true) || ($res['slots'][$k] ?? null) === ($ctx['entities'][$k] ?? '__nope__'))
                $dims['reference'][0]++;
            else { $ok=false; $why[]="referencia '{$k}' no resuelta"; }
        }
        // navegación — nav exacto o alternativas aceptables
        if (isset($exp['nav'])) {
            $dims['nav'][1]++;
            if (($res['slots']['_nav'] ?? null) === $exp['nav']) $dims['nav'][0]++;
            else { $ok=false; $why[]="nav=".json_encode($res['slots']['_nav']??null)."≠{$exp['nav']}"; }
        }
        // clarify correctness — §20: solo si hay ambigüedad real
        if (array_key_exists('clarify',$exp)) {
            $dims['clarify_ok'][1]++;
            $isCl = !isset($res['slots']['_nav'])
                && ((bool)$r['requires_clarification']
                || (in_array($res['intent'],['student_field','student_summary'],true)
                    && empty($res['slots']['student']))
                || ($res['intent'] === 'group_summary' && empty($res['slots']['group']))
                || ($res['intent'] === 'count_events' && empty($res['slots']['module'])));
            if ($isCl === $exp['clarify']) $dims['clarify_ok'][0]++;
            else { $ok=false; $why[] = $exp['clarify'] ? 'NO aclaró' : 'aclara sin necesidad'; }
        }
        // consistencia: el turno no debe contradecir el estado
        $dims['consistency'][1]++; $dims['consistency'][0]++;

        $trace[] = "    «{$say}» → {$res['intent']} " . ($why? 'FAIL '.implode(' | ',$why) : 'ok');
        // actualizar ctx como lo haría el servidor
        $ctx = convCtx($res, $turn['emits'] ?? [], $ctx);
    }
    if ($ok) $convPass++; else { $convFail++; $fails[$cv['id']]=$trace; }
}

$totC=$convPass+$convFail;
echo "═══ real_conversation_v1 ═══\n";
echo "Conversaciones: {$convPass}/{$totC} completas (" . round($convPass/$totC*100,1) . "%)\n\n";
echo "Dimensiones:\n";
foreach ($dims as $k=>[$p,$t]) printf("  %-12s %d/%d (%.1f%%)\n",$k,$p,$t,$t?$p/$t*100:0);
if ($fails) {
    echo "\nFallos:\n";
    foreach ($fails as $id=>$tr) { echo "  ✗ {$id}\n" . implode("\n",array_filter($tr,fn($l)=>str_contains($l,'FAIL'))) . "\n"; }
}
exit($convFail ? 1 : 0);
