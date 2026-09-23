<?php
/**
 * nexus_capability_eval_v1 — eval de CAPACIDADES, no de intents.
 *
 * Mide si el enunciado produce el plan semántico correcto:
 *   capability · entity · relation · op · filters{group,status,module,range}
 *   · position · cardinality · presentation · projection · contexto.
 *
 * Cubre composiciones nunca entrenadas: lista+posición, grupo+conteo,
 * relación inversa, presentación=tabla, ordenamiento, porcentaje, comparación.
 *
 * Uso: php test/nexus_capability_eval_v1.php [--json] [--verbose]
 */
// el repo usa backend/api/lib/; en el contenedor de pruebas está plano en lib/
foreach (['nexus_nlu.php','nexus_semantic.php'] as $lib) {
    foreach ([__DIR__.'/../backend/api/lib/'.$lib, __DIR__.'/../lib/'.$lib] as $p)
        if (is_file($p)) { require $p; break; }
if (!getenv('NX_CLASSIFY_FIXTURE')) putenv('NX_CLASSIFY_FIXTURE=' . __DIR__ . '/fixtures/llm_intents.json');
}

$JSON = in_array('--json', $argv ?? []);
$VERBOSE = in_array('--verbose', $argv ?? []);

/* ── helpers de evaluación ───────────────────────────────────────────── */
function evTurn(string $text, ?array $ds = null): array {
    $q0 = nxNorm($text);
    $cls = nxClassify($text);
    $ctx = $ds ? ['entities'=>$ds['entities'] ?? [],'last_intent'=>$ds['intent'] ?? null,'_ds'=>$ds] : null;
    $interp = nxDialogueResolve($cls, $ctx, $q0);
    $slots = $interp['resolved']['slots'];
    $plan = nxSemanticCompose($q0, $interp['resolved']['intent'],
        (float)($cls['confidence'] ?? 0), $slots, $interp, $ds);
    return ['q0'=>$q0,'cls'=>$cls,'interp'=>$interp,'slots'=>$slots,'plan'=>$plan];
}
function dsStudents(string $group='6-A', int $n=5): array {
    $items = []; for ($i=1;$i<=$n;$i++) $items[]=['id'=>"id$i",'label'=>"Est$i",'sub'=>"doc 80$i"];
    return ['intent'=>'students.list','entities'=>['group'=>$group],
        'last_result'=>['type'=>'students','label'=>'estudiantes','count'=>$n,'items'=>$items,
            '_filters'=>['group'=>$group],'_capability'=>'students.list'],
        'cursor'=>0];
}

/* ── casos: utterance → aserciones sobre el plan ───────────────────────
 * chk: campos del plan que DEBEN cumplirse; null → el plan debe ser null
 * (delegar al pipeline de intents) o intent esperado.                        */
$CASES = [
// ── students.list / posición / cardinalidad / presentación ─────────────
['muéstrame los estudiantes de 10-A', ['capability'=>'students.list','filters.group'=>'10-A']],
['qué estudiantes hay en el 7B',      ['capability'=>'students.list','filters.group'=>'7-B']],
['pásame la tabla completa de estudiantes de 10-A', ['capability'=>'students.list','presentation'=>'table','filters.group'=>'10-A']],
['muéstrame todos los estudiantes de 10-A', ['capability'=>'students.list','cardinality'=>'all','filters.group'=>'10-A']],
['quiero la relación completa del 9B', ['capability'=>'students.list','cardinality'=>'all','filters.group'=>'9-B']],
['estudiantes de 8-C ordenados por nombre', ['capability'=>'students.list','sort'=>'name','filters.group'=>'8-C']],
['nómina del 6-A por documento',      ['capability'=>'students.list','sort'=>'document','filters.group'=>'6-A']],
['dame solo los nombres de los del 10-A', ['capability'=>'students.list','projection'=>['name'],'filters.group'=>'10-A']],
['los documentos de los del 6-A',     ['capability'=>'students.list','filters.group'=>'6-A']],
['estudiantes de grado 9',            ['capability'=>'students.list','filters.grade'=>'9']],
['los del 6-A',                       ['capability'=>'students.list','filters.group'=>'6-A']],
['los que están en 10-B',             ['capability'=>'students.list','filters.group'=>'10-B']], // colectivo los que + grupo
['estudiantes sin grupo asignado',    ['capability'=>'students.list','filters.status'=>'no_group']],
['estudiantes exentos del sensor',    ['capability'=>'students.list','filters.status'=>'exempt']],
// posiciones
['cuál es el primero de 10-A',        ['capability'=>'students.position','position'=>1,'filters.group'=>'10-A']],
['quién está primero en 9-B',         ['capability'=>'students.position','position'=>1,'filters.group'=>'9-B']],
['el último de la lista del 8-C',     ['capability'=>'students.position','position'=>'last','filters.group'=>'8-C']],
['dime quién es el tercero del 6-A',  ['capability'=>'students.position','position'=>3,'filters.group'=>'6-A']],
['el segundo de 10-A',                ['capability'=>'students.position','position'=>2,'filters.group'=>'10-A']],
['los cinco primeros de 6-A',         ['capability'=>'students.list','slice'=>['n'=>5,'from'=>'start'],'filters.group'=>'6-A']],
['las tres últimas del 7-B',          ['capability'=>'students.list','slice'=>['n'=>3,'from'=>'end'],'filters.group'=>'7-B']],
['quién llegó primero hoy',           ['capability'=>'students.position','filters.status'=>'present']],
['el primero en entrar hoy',          ['capability'=>'students.position','filters.status'=>'present']],
// conteos / agregaciones
['cuántos estudiantes hay en 10-A',   ['capability'=>'students.count','filters.group'=>'10-A']],
['cuántos son los de sexto',          ['capability'=>'students.count']], // grado como filtro
['qué porcentaje de 10-A faltó hoy',  ['capability'=>'students.percent','filters.group'=>'10-A','filters.status'=>'absent']],
['qué porcentaje del colegio llegó tarde hoy', ['capability'=>'students.percent','filters.status'=>'late']],
// filtros compuestos — §14
['los del 10-A que faltaron hoy',     ['capability'=>'students.list','filters.group'=>'10-A','filters.status'=>'absent']],
['estudiantes de 8-B que llegaron tarde', ['capability'=>'students.list','filters.group'=>'8-B','filters.status'=>'late']],
['estudiantes del 6-A con permiso',   ['capability'=>'students.list','filters.group'=>'6-A','filters.status'=>'permission']],
['alumnas de 9-C en seguimiento',     ['capability'=>'students.list','filters.group'=>'9-C','filters.status'=>'tracking']],
['quiénes del 10-A están en riesgo',  ['capability'=>'students.list','filters.group'=>'10-A','filters.status'=>'risk']],
// ── relaciones ────────────────────────────────────────────────────────
['quiénes son los acudientes de 10-A', ['capability'=>'guardians.of_group','filters.group'=>'10-A']],
['los padres de familia del 7-B',      ['capability'=>'guardians.of_group','filters.group'=>'7-B']],
['acudientes del 6-A en tabla',        ['capability'=>'guardians.of_group','presentation'=>'table','filters.group'=>'6-A']],
['cuántos acudientes tiene el 9-A',    ['capability'=>'guardians.of_group','op'=>'count','filters.group'=>'9-A']],
['docentes del 6-A',                   ['capability'=>'teachers.of_group','filters.group'=>'6-A']],
['quién le da clase al 8-B',           ['capability'=>'teachers.of_group','filters.group'=>'8-B']],
['qué profesores tiene el 10-A',       ['capability'=>'teachers.of_group','filters.group'=>'10-A']],
['horario del 6-A',                    ['capability'=>'schedule.of_group','filters.group'=>'6-A']],
['qué materias ve el 7-B',             ['capability'=>'schedule.of_group','filters.group'=>'7-B']],
// ── comparación / ranking ─────────────────────────────────────────────
['compara 6-A con 7-B',                ['capability'=>'groups.compare','filters.group'=>'6-A','filters.group2'=>'7-B']],
['compárame el 8-A contra el 8-C',     ['capability'=>'groups.compare','filters.group'=>'8-A','filters.group2'=>'8-C']],
['cuál grupo tiene más tardanzas hoy', ['capability'=>'groups.rank']],
['qué grupo tiene más inasistencias esta semana', ['capability'=>'groups.rank','filters.module'=>'INASISTENCIA']],
['cuál grupo tiene menos evasiones este mes',     ['capability'=>'groups.rank','filters.module'=>'EVASION_INTERNA']],
// ── incidentes ────────────────────────────────────────────────────────
['el primer incidente de la semana',   ['capability'=>'incidents.position','position'=>1]],
['la primera tardanza de hoy',         ['capability'=>'incidents.position','position'=>1,'filters.module'=>'LATE_ARRIVAL']],
['cuántas tardanzas hubo esta semana', ['capability'=>'incidents.list','op'=>'count','filters.module'=>'LATE_ARRIVAL']],
['todas las evasiones de esta semana', ['capability'=>'incidents.list','cardinality'=>'all','filters.module'=>'EVASION_INTERNA']],
['la tabla de inasistencias del mes',  ['capability'=>'incidents.list','presentation'=>'table','filters.module'=>'INASISTENCIA']],
// ── delegación al pipeline clásico (plan debe ser null) ─────────────────
['hola',                               null],
['gracias',                            null],
['cuántos faltaron hoy',               ['capability'=>'incidents.list','op'=>'count','filters.module'=>'INASISTENCIA']], // compone: count+module
['muéstrame las evasiones de ayer',    ['capability'=>'incidents.list','filters.module'=>'EVASION_INTERNA']], // module+range → derivación §15
['un estudiante al azar',              null], // random_student
['genera un permiso para Ana',         null], // operación — nunca plan
['cita al acudiente de Luis',          null], // operación
['qué dispositivos hay activos',       null], // devices_status handler
['quién eres',                         null],
['buenas tardes por favor muéstrame todos los estudiantes de 10-A muchas gracias',
    ['capability'=>'students.list','cardinality'=>'all','filters.group'=>'10-A']],
];

/* ── casos con contexto (ds simulado) ─────────────────────────────────── */
$CTX_CASES = [
// [utterance, ds, checks]
['el primero',  dsStudents(), 'nav'],           // nav nth:1 sobre el set
['el segundo',  dsStudents(), 'nav'],
['el último',   dsStudents(), 'nav'],
['los demás',   dsStudents(), 'nav'],
['cuántos son', dsStudents(), 'nav'],
['ponmelos en una tabla', dsStudents(), 'nav'], // nav table
// posición sobre grupo DISTINTO → composer (consulta nueva)
['el primero del 7-B', dsStudents('6-A'), 'plan', ['capability'=>'students.position','filters.group'=>'7-B']],
// entidad desde contexto + presentación
['y los del 7-B', dsStudents('6-A'), 'plan', ['capability'=>'students.list','filters.group'=>'7-B']],
// relación inversa con persona activa
['de quién es este acudiente', dsStudents()+['person'=>['type'=>'guardian','gid'=>'g1','name'=>'Acudiente X']],
    'plan', ['capability'=>'students.of_guardian']],
['qué estudiantes tiene este acudiente', dsStudents()+['person'=>['type'=>'guardian','gid'=>'g1']],
    'plan', ['capability'=>'students.of_guardian']],
];

/* ── runner ───────────────────────────────────────────────────────────── */
$dim = ['capability'=>['ok'=>0,'n'=>0],'filters'=>['ok'=>0,'n'=>0],'op'=>['ok'=>0,'n'=>0],
        'position'=>['ok'=>0,'n'=>0],'presentation'=>['ok'=>0,'n'=>0],'context'=>['ok'=>0,'n'=>0],
        'delegation'=>['ok'=>0,'n'=>0]];
$fail = [];

function chk(array &$dim, string $k, bool $ok, string $tag, array &$fail): void {
    $dim[$k]['n']++; if ($ok) $dim[$k]['ok']++; else $fail[] = $tag;
}

foreach ($CASES as [$text, $expect]) {
    $r = evTurn($text);
    $p = $r['plan'];
    if ($expect === null) {
        chk($dim,'delegation', $p === null, "«{$text}» → plan inesperado " . json_encode($p['capability'] ?? null), $fail);
        continue;
    }
    if ($p === null) {
        // delegación válida: el intent resuelto ES la vía del registro
        $reg = nxCapabilityRegistry()[$expect['capability']] ?? [];
        $equiv = array_map('trim', explode('|', $reg['intent_equiv'] ?? ''));
        $resolved = $r['interp']['resolved']['intent'] ?? '';
        chk($dim,'capability', $resolved && in_array($resolved,$equiv,true),
            "«{$text}» → null y resuelto=$resolved (esperaba {$expect['capability']} o {$reg['intent_equiv']})", $fail);
        continue;
    }
    chk($dim,'capability', ($p['capability'] ?? null) === $expect['capability'], "«{$text}» cap={$p['capability']}≠{$expect['capability']}", $fail);
    foreach ($expect as $k=>$v) {
        if ($k==='capability') continue;
        $cur = $p;
        foreach (explode('.',$k) as $seg) $cur = $cur[$seg] ?? null;
        $cat = str_starts_with($k,'filters') ? 'filters'
             : (in_array($k,['position','slice'],true) ? 'position'
             : (in_array($k,['presentation','cardinality','projection'],true) ? 'presentation' : 'op'));
        chk($dim,$cat, $cur === $v, "«{$text}» {$k}: " . json_encode($cur) . "≠" . json_encode($v), $fail);
    }
}

foreach ($CTX_CASES as $c) {
    [$text,$ds,$kind] = $c; $expect = $c[3] ?? null;
    $r = evTurn($text, $ds);
    if ($kind === 'nav')
        chk($dim,'context', !empty($r['slots']['_nav']), "«{$text}» sin _nav", $fail);
    else {
        $p = $r['plan'];
        if ($p === null) { chk($dim,'context',false,"«{$text}» → null",$fail); continue; }
        // sin expectativa de capability no hay chequeo posible — un caso
        // 'plan' sin 'capability' es un hueco del test, no un pass tácito
        chk($dim,'context', !empty($expect['capability']) && ($p['capability'] ?? null) === $expect['capability'],
            "«{$text}» cap=" . json_encode($p['capability'] ?? null)
            . " esperaba " . json_encode($expect['capability'] ?? '(sin expectativa)'), $fail);
        foreach (($expect ?? []) as $k=>$v) {
            if ($k==='capability') continue;
            $cur = $p; foreach (explode('.',$k) as $seg) $cur = $cur[$seg] ?? null;
            chk($dim,'context', $cur === $v, "«{$text}» {$k}: " . json_encode($cur), $fail);
        }
    }
}

/* ── reporte ──────────────────────────────────────────────────────────── */
if ($JSON) { echo json_encode(['dimensions'=>$dim,'failures'=>$fail], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); exit; }
echo "═══ nexus_capability_eval_v1 ═══\n\n";
$tot=0;$okT=0;
foreach ($dim as $k=>$d) {
    $pct = $d['n'] ? round($d['ok']*100/$d['n'],1) : 0;
    $tot+=$d['n']; $okT+=$d['ok'];
    printf("  %-14s %3d/%3d  (%.1f%%)\n", $k, $d['ok'], $d['n'], $pct);
}
printf("\n  TOTAL          %3d/%3d  (%.1f%%)\n", $okT, $tot, $tot?round($okT*100/$tot,1):0);
if ($fail && ($VERBOSE || !$JSON)) {
    echo "\n── fallos ──\n";
    foreach (array_slice($fail,0,40) as $f) echo "  ✗ $f\n";
    if (count($fail)>40) echo "  … y " . (count($fail)-40) . " más\n";
}
exit($fail ? 1 : 0);
