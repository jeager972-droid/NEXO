<?php
/* ============================================================================
 * scp_regression — §CASOS OBLIGATORIOS del transcript real (A-N).
 * Regresión a nivel SEMANTIC FRAME (sin DB): valida que la capa SCP
 * normaliza el significado correctamente antes de planificar. La
 * validación end-to-end (SQL/RBAC/sesión) vive en test/scp_live.php.
 *
 * Uso: php test/scp_regression.php
 * ========================================================================== */
require __DIR__ . '/../backend/api/lib/nexus_nlu.php';
if (!getenv('NX_CLASSIFY_FIXTURE')) putenv('NX_CLASSIFY_FIXTURE=' . __DIR__ . '/fixtures/llm_intents.json');
require __DIR__ . '/../backend/api/lib/nexus_semantic.php';
require __DIR__ . '/../backend/api/lib/nexus_scp.php';

/* estado conversacional simulado — espejo del _ds real tras la tabla 10-A
 * (cursor sobre el último elemento mostrado) + contexto de conteo */
function scpDsAfterTable10A(): array {
    return [
        'intent' => 'students.list',
        'entities' => ['group' => '10-A'],
        'last_result' => ['type'=>'students','count'=>8,'items'=>[
            ['id'=>'a1','label'=>'Lucía Décima Cuarta','sub'=>'doc 8104','f'=>['fn'=>'Lucía','ln'=>'Décima Cuarta','doc'=>'8104']],
            ['id'=>'a2','label'=>'Juan Camilo Ospina García','sub'=>'doc 8108','f'=>['fn'=>'Juan Camilo','ln'=>'Ospina García','doc'=>'8108']],
        ]],
        'cursor' => 1, 'pending_targets' => [],
    ];
}
function scpDsAfterMaria(): array {
    return ['intent'=>'student_field',
        'entities'=>['student'=>'María Fernanda Castaño Muñoz','group'=>'10-A','field'=>'acudiente'],
        'last_result'=>null,'cursor'=>0,'person'=>['type'=>'guardian','name'=>'María Custodia Muñoz López'],
        'pending_targets'=>[]];
}
function scpDsAfterCount(): array {
    return ['intent'=>'count_events',
        'entities'=>['student'=>'María Fernanda Castaño Muñoz','group'=>'10-A','module'=>'EVASION_INTERNA','days'=>30],
        'last_result'=>null,'cursor'=>0,'pending_targets'=>[]];
}
function scpDsAfterRank(): array {
    return ['intent'=>'top_offenders',
        'entities'=>['module'=>'INASISTENCIA','_my_scope'=>true,'days'=>30],
        'last_result'=>['type'=>'rank','count'=>5,'items'=>[]],'cursor'=>0,'pending_targets'=>[]];
}
function scpDsAfterCompare(): array {
    return ['intent'=>'groups.compare',
        'entities'=>['group'=>'10-A','group2'=>'8-C','module'=>'EVASION_INTERNA','days'=>0,'range_label'=>'hoy'],
        'last_result'=>null,'cursor'=>0,'pending_targets'=>[]];
}
function scpDsAfterCompoundPartial(): array {
    return ['intent'=>'composed',
        'entities'=>['_pending_targets'=>[['intent'=>'students_in_group','slots'=>['group'=>'6-A'],'delivered'=>false]]],
        'last_result'=>null,'cursor'=>0,'pending_targets'=>[
            ['intent'=>'students_in_group','slots'=>['group'=>'6-A'],'delivered'=>false]]];
}

/* [id, frase, ds, chequeos] — cada chequeo es [ruta_frame, esperado] */
$CASES = [
    // A — tabla + acudiente del primero (referencia posicional ≠ grado 1)
    ['A1','pasame una tabla con los estudiantes de 10A', scpDsAfterTable10A(),
        [['task','lookup|transform'],['filters.group','10-A'],['output','table']]],
    ['A2','quien es el acudiente del primero', scpDsAfterTable10A(),
        [['task','relation'],['subject.source','reference_positional'],['subject.name','Lucía Décima Cuarta'],['field','acudiente']]],
    // B — corrección de sujeto («me refiero al de Tomás…»)
    ['B','me refiero al de Tomás Castaño Gutiérrez', scpDsAfterMaria(),
        [['task','correct'],['corrections.0.kind','replace_subject']]],
    // C — acudiente de María + su documento (anáfora sobre persona activa)
    ['C1','quien es el acudiente de María Fernanda Castaño Muñoz', null,
        [['task','relation'],['subject.name','maria fernanda castano munoz'],['subject.source','explicit']]],
    ['C2','¿y su documento?', scpDsAfterMaria(),
        [['task','relation'],['subject.source','inherited|reference_anaphora'],['field','documento']]],
    // D — datos de María → evasiones 30d → llegadas tarde (hereda sujeto+tiempo)
    ['D1','datos de María Fernanda Castaño Muñoz', null,
        [['subject.name','maria fernanda castano munoz']]],
    ['D2','¿cuántas evasiones los últimos 30 días?', scpDsAfterMaria(),
        [['task','count'],['time_range.days',30]]],
    ['D3','¿y llegadas tarde?', scpDsAfterCount(),
        [['task','count'],['subject.name','María Fernanda Castaño Muñoz']]],
    // E — ranking sobre scope docente
    ['E','top 5 estudiantes con más faltas en mis clases los últimos 30 días', null,
        [['task','rank'],['ranking.metric','absences'],['ranking.limit',5],['scope.kind','teacher'],['time_range.days',30]]],
    // F — mayúsculas + corrección de límite
    ['F','LOS QUE MÁS FALTARON Y SOLO 5', scpDsAfterRank(),
        [['task','rank'],['ranking.limit',5],['corrections.0.kind','limit']]],
    // G — métrica de UNA persona con rango propio
    ['G','cuánto ha faltado Juan Camilo Ospina García de 10A los últimos 15 días', null,
        [['task','count'],['subject.source','explicit'],['time_range.days',15],['filters.group','10-A']]],
    // H — referencia al último mostrado + documento del acudiente
    ['H1','acudiente de ese último que me diste', scpDsAfterTable10A(),
        [['task','relation'],['subject.source','reference_anaphora'],['subject.name','Juan Camilo Ospina García']]],
    ['H2','¿y el documento del acudiente?', scpDsAfterMaria(),
        [['task','relation'],['field','documento_acudiente'],['subject.source','inherited|reference_anaphora']]],
    // I — umbral pedagógico preservado (no degrada a «todos del grupo»)
    ['I','qué estudiantes de 10A han pasado mi umbral de alerta pedagógica?', null,
        [['task','filter'],['filters.group','10-A']]],
    // J — «todos» navega el set activo
    ['J','todos', scpDsAfterTable10A(),
        [['task','navigate']]],
    // K — nombre completo explícito tras «acudiente» domina herencia
    ['K','celular del acudiente Juan Camilo Ospina García', scpDsAfterMaria(),
        [['task','relation'],['subject.name','juan camilo ospina garcia'],['subject.source','explicit'],['field','celular_acudiente']]],
    // L — compuesto: conversación general + comparación de datos
    ['L','dime un chiste y dame una tabla de comparación de inasistencias de 6A vs 10A', null,
        [['targets.#','2'],['targets.0.task','general'],['targets.1.task','lookup|compare']]],
    // M — «te faltó lo otro» → objetivo pendiente, no conversación nueva
    ['M','te faltó lo otro', scpDsAfterCompoundPartial(),
        [['task','correct'],['corrections.0.kind','pending_target']]],
    // N — comparación 10A vs 8C + corrección «ninguna»
    ['N1','qué grupo tiene más evasiones internas: 10A o 8C', null,
        [['task','compare'],['filters.group','10-A'],['filters.group2','8-C']]],
    ['N2','no sería empate, sería que ninguna', scpDsAfterCompare(),
        [['task','correct'],['corrections.0.kind','none_of']]],

    /* — variaciones lingüísticas naturales (no optimizar frase exacta) — */
    ['E2','muéstrame los 5 peores en inasistencias de mis grupos este último mes', null,
        [['task','rank'],['ranking.limit',5]]],
    ['E3','cuáles son los estudiantes que más faltas tienen en mis clases', null,
        [['task','rank'],['scope.kind','teacher']]],
    ['F2','los que más faltaron y nada más cinco', scpDsAfterRank(),
        [['task','rank']]],
    ['G2','cuántas faltas ha tenido Juan Camilo Ospina García en 10A en los últimos 15 días', null,
        [['task','count'],['subject.source','explicit'],['time_range.days',15]]],
    ['H3','quién es el acudiente del último que me enseñaste', scpDsAfterTable10A(),
        [['task','relation'],['subject.source','reference_anaphora|reference_positional']]],
    ['A3','quién es la acudiente del segundo', scpDsAfterTable10A(),
        [['task','relation'],['subject.source','reference_positional'],['subject.name','Juan Camilo Ospina García']]],
    ['C3','dame el teléfono de María Fernanda Castaño Muñoz', null,
        [['subject.name','maria fernanda castano munoz']]],
    ['M2','se te olvidó lo otro', scpDsAfterCompoundPartial(),
        [['task','correct'],['corrections.0.kind','pending_target']]],
    ['N3','compara 6A contra 10A en inasistencias', null,
        [['task','compare'],['filters.group','6-A'],['filters.group2','10-A']]],
];

/* ---------- evaluación ---------- */
$pass = 0; $fail = [];
foreach ($CASES as [$id, $q, $ds, $checks]) {
    $q0 = nxNorm($q);
    $cls = nxClassify($q);
    $ctx = $ds ? ['entities'=>$ds['entities'] ?? [], 'last_intent'=>$ds['intent'] ?? null, '_ds'=>$ds] : null;
    $interp = nxDialogueResolve($cls, $ctx, $q0);
    $sl = $interp['resolved']['slots'];
    if ($interp['resolved']['inherited'] ?? null) $sl['_inherited'] = $interp['resolved']['inherited'];
    $interp['resolved']['slots'] = $sl;
    $frame = nxScpFrame($q0, $cls, $interp, $ds);
    foreach ($checks as [$path, $expect]) {
        // soporte de rutas: task, subject.source, filters.group, targets.0.task,
        // targets.#, corrections.N.kind
        $val = null;
        if ($path === 'targets.#') $val = (string)count($frame['targets'] ?? []);
        elseif (preg_match('/^corrections\.(\d+)\.kind$/', $path, $m)) $val = $frame['corrections'][$m[1]]['kind'] ?? null;
        elseif (preg_match('/^(subject|filters|ranking|scope|time_range|corrections)(?:\.(\w+))?$/', $path, $m)) {
            $base = $frame[$m[1]] ?? null;
            if ($m[1] === 'corrections') $val = $base[0]['kind'] ?? null;
            elseif ($m[1] === 'subject') $val = $base[$m[2]] ?? null;
            else $val = $base[$m[2]] ?? null;
        }
        elseif (preg_match('/^targets\.(\d+)\.task$/', $path, $m)) $val = $frame['targets'][$m[1]]['task'] ?? null;
        else $val = $frame[$path] ?? null;
        $exp = explode('|', (string)$expect);
        $ok = in_array((string)$val, $exp, true) || in_array(strtolower((string)$val), array_map('strtolower', $exp), true);
        if ($ok) $pass++; else $fail[] = [$id, $path, $val, $expect];
    }
}
$tot = 0; foreach ($CASES as $c) $tot += count($c[3]);
printf("═══ scp_regression (frame) ═══\n  %d/%d chequeos PASS\n", $pass, $tot);
if ($fail) {
    echo "  fallos:\n";
    foreach ($fail as [$id,$path,$val,$exp]) printf("    ✗ %s %s = %s (esperaba %s)\n", $id, $path, var_export($val,true), $exp);
    exit(1);
}
exit(0);
