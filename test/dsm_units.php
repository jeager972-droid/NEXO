<?php
/* test/dsm_units.php — suite unitaria del DSM (nxDialogueResolve) y del
 * resolver de operaciones (chatOperationCmd). Cubre los casos de la lista
 * pendiente: days=0, «el mismo X», «vuelve», «y de hoy», ordinales,
 * posesivos, autonomía tras operación, confirmación tras consulta,
 * cancelación con prefijo y preservación de _op.
 *
 * Uso: php test/dsm_units.php
 */
define('ROLE', 'TEACHER');
require_once __DIR__ . '/../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

$P = 0; $F = 0; $fails = [];
function chk(string $label, bool $ok, string $detail = ''): void {
    global $P, $F, $fails;
    if ($ok) { $P++; return; }
    $F++; $fails[] = "$label — $detail";
}
/* convierte texto crudo → intent+slots a través del pipeline real */
function turn(string $text, ?array &$ctx = null, ?array &$last = null): array {
    $r = simulateTurn($text, $ctx, $last);
    $tr = $r['trace'];
    $out = ['intent' => $r['intent'], 'turn' => $tr['turn_type'] ?? '',
            'slots' => $tr['9_slots_finales'] ?? [], 'op' => $r['operation'] ?? null];
    $last = ['intent' => $r['intent'], 'entities' => $out['slots']];
    return $out;
}

echo "═══ DSM units — nxDialogueResolve + chatOperationCmd ═══\n\n";

/* ── A. Resolver de operaciones ───────────────────────────────────────── */
$cases = [
    ['cita a su acudiente',                    'Citar acudiente'],
    ['ahora cita al acudiente de juan',        'Citar acudiente'],
    ['quiero citar a un acudiente',            'Citar acudiente'],
    ['una citacion para el acudiente',         'Citar acudiente'],
    ['hay que convocar al acudiente',          'Citar acudiente'],
    ['citala a citacion',                      'Citar acudiente'],
    ['quiero enviar una solicitud',            'Mandar solicitud'],
    ['quiero mandar una solicitud al docente', 'Mandar solicitud'],
    ['confirmo la solicitud',                  'Mandar solicitud'],
    ['una autorizacion de salida',             'Autorizar salida'],
    ['quiero autorizar una salida',            'Autorizar salida'],
    ['genera un permiso',                      'Generar permiso'],
    ['genera un permiso medico',               'Generar permiso'],
    ['reporta un incidente',                   'Reportar incidente'],
    ['quiero reportar un daño',                'Reportar daño'],
    ['situacion critica en el 8a',             'Situación Crítica'],
    ['emergencia en el patio',                 'Situación Crítica'],
];
foreach ($cases as [$q, $want]) {
    $got = chatOperationCmd(nxNorm($q));
    chk("opCmd " . $q, $got === $want, "op=$got ≠ $want");
}

/* ── B. days=0 se preserva (empty() no debe tratarlo como ausente) ────── */
$ctx = null;
$t = turn('muéstrame las faltas de hoy', $ctx);
chk('B1 hoy→days=0', ($t['slots']['days'] ?? -1) === 0, "days=" . var_export($t['slots']['days'] ?? null, true));
$t = turn('y ahora solo juan', $ctx);
chk('B2 hereda days=0 en modify', ($t['slots']['days'] ?? -1) === 0, "days=" . var_export($t['slots']['days'] ?? null, true));

/* ── C. Deícticos «el mismo X» ────────────────────────────────────────── */
$ctx = null;
turn('las tardanzas del 8a', $ctx);
$t = turn('ahora las evasiones del mismo grupo', $ctx);
chk('C1 mismo grupo', ($t['slots']['group'] ?? '') === '8A', "group=" . var_export($t['slots']['group'] ?? null, true));

$ctx = null;
turn('el resumen de pedro', $ctx);
$t = turn('y las faltas del mismo estudiante', $ctx);
chk('C2 mismo estudiante', ($t['slots']['student'] ?? '') === 'pedro', "student=" . var_export($t['slots']['student'] ?? null, true));

/* ── D. «vuelve» — retorno a tema y a operación ───────────────────────── */
$ctx = null;
turn('las tardanzas del mes pasado', $ctx);
turn('y las de hoy', $ctx);
$t = turn('vuelve al mes', $ctx);
chk('D1 vuelve al mes→days', ($t['slots']['days'] ?? 0) === 60, "days=" . var_export($t['slots']['days'] ?? null, true));

$ctx = null;
turn('quiero mandar una solicitud', $ctx);
turn('cuantas tardanzas hubo hoy', $ctx);   // consulta intermedia
$t = turn('vuelve a la solicitud', $ctx);
chk('D2 vuelve a la solicitud→confirm', in_array($t['intent'], ['confirm_op'], true), "intent={$t['intent']}");
chk('D3 _op sobrevivió consulta intermedia', $t['op'] === 'Mandar solicitud', "op=" . var_export($t['op'], true));

/* ── E. Autonomía: consultas independientes no heredan entidades ───────── */
$ctx = null;
turn('las faltas de juan del 8a', $ctx);
$t = turn('cuantas evasiones hubo esta semana', $ctx);
chk('E1 consulta autónoma no hereda student', empty($t['slots']['student']), "student=" . var_export($t['slots']['student'] ?? null, true));

/* ── F. Posesivos y pronombres ─────────────────────────────────────────── */
$ctx = null;
turn('info de pedro del octavo', $ctx);
chk('F0 nombre+del+grado', ($ctx['entities']['student'] ?? '') === 'pedro',
    'student=' . var_export($ctx['entities']['student'] ?? null, true));
$t = turn('sus faltas', $ctx);
chk('F1 sus faltas→student', ($t['slots']['student'] ?? '') === 'pedro', "student=" . var_export($t['slots']['student'] ?? null, true));
$t = turn('y el telefono de su papa', $ctx);
chk('F2 telefono de su papa→student', ($t['slots']['student'] ?? '') === 'pedro', "student=" . var_export($t['slots']['student'] ?? null, true));
$t = turn('para ella', $ctx);
chk('F3 para ella→student', ($t['slots']['student'] ?? '') === 'pedro', "student=" . var_export($t['slots']['student'] ?? null, true));

/* ── G. Confirmación y cancelación con prefijos ────────────────────────── */
$ctx = null;
turn('quiero citar al acudiente de juan', $ctx);
turn('cuantas tardanzas tiene', $ctx);      // consulta intermedia
$t = turn('ahora si, confirma', $ctx);
chk('G1 confirmación tras consulta', $t['intent'] === 'confirm_op' && $t['op'] === 'Citar acudiente',
    "intent={$t['intent']} op=" . var_export($t['op'], true));

$ctx = null;
turn('quiero citar al acudiente de juan', $ctx);
$t = turn('ahora cancela eso', $ctx);
chk('G2 cancelación con prefijo', $t['intent'] === 'cancel', "intent={$t['intent']}");
chk('G3 _op eliminado', empty($ctx['entities']['_op']), 'ctx._op sigue presente');

/* ── H. Repetición de operación ────────────────────────────────────────── */
$ctx = null;
turn('genera un permiso', $ctx);
$t = turn('otro para camila', $ctx);
chk('H1 otro para camila→repeat_op', $t['intent'] === 'repeat_op' && $t['op'] === 'Generar permiso',
    "intent={$t['intent']} op=" . var_export($t['op'], true));
chk('H2 student nuevo', ($t['slots']['student'] ?? '') === 'camila', "student=" . var_export($t['slots']['student'] ?? null, true));
$t = turn('genera uno nuevo', $ctx);
chk('H3 genera uno nuevo→repeat_op', $t['intent'] === 'repeat_op', "intent={$t['intent']}");
$t = turn('uno para manana', $ctx);
chk('H4 uno para manana→repeat_op', $t['intent'] === 'repeat_op', "intent={$t['intent']}");

/* ── I. Corrección de operación ────────────────────────────────────────── */
$ctx = null;
turn('quiero mandar una solicitud a un docente', $ctx);
$t = turn('no, mejor una citacion', $ctx);
chk('I1 no mejor X→corrige op', $t['op'] === 'Citar acudiente', "op=" . var_export($t['op'], true));
$t = turn('vuelve a la solicitud', $ctx);
chk('I2 vuelve a la solicitud→confirma op corregida', in_array($t['intent'], ['confirm_op'], true),
    "intent={$t['intent']}");

/* ── J. Smalltalk no destruye contexto ─────────────────────────────────── */
$ctx = null;
turn('las tardanzas del 9b del mes pasado', $ctx);
turn('gracias', $ctx);
$t = turn('ahora las evasiones del mismo grupo', $ctx);
chk('J1 ctx sobrevive gracias', ($t['slots']['group'] ?? '') === '9B', "group=" . var_export($t['slots']['group'] ?? null, true));
chk('J2 days sobrevive gracias', ($t['slots']['days'] ?? 0) === 60, "days=" . var_export($t['slots']['days'] ?? null, true));

/* ── K. Clarificación — deíctico sin datos ─────────────────────────────── */
$t = turn('cuantas hubo hoy', $ctx2);
chk('K1 cuantas hubo hoy→clarifica', $t['intent'] === 'clarify' || $t['turn'] === 'deictic',
    "intent={$t['intent']} turn={$t['turn']}");

/* ── L. Ordinales desnudos ─────────────────────────────────────────────── */
$ctx = null;
turn('los seguimientos abiertos', $ctx);
$t = turn('los del noveno', $ctx);
chk('L1 los del noveno→group=9', ($t['slots']['group'] ?? '') === '9', "group=" . var_export($t['slots']['group'] ?? null, true));

/* ── M. Extracción de estudiantes limpia ───────────────────────────────── */
$extractCases = [
    ['datos de camila del quinto',   'camila'],
    ['el permiso para el mismo juan','juan'],
    ['juan del 8a documento',        'juan'],
    ['camila del septimo',           'camila'],
    ['info de pedro del octavo',     'pedro'],
    ['de juan especificamente',      'juan'],
    ['maria manana',                 null],
    ['para ella',                    null],
    ['sobre la fisica cuantica',     null],
    ['a nombre de quien esta este alumno', null],
    ['la ficha de la muchacha sofia','sofia'],
];
foreach ($extractCases as [$q, $want]) {
    $slots = nxSlots(nxNorm($q));
    $got = $slots['student'] ?? null;
    chk("extr " . $q . "→" . var_export($want, true), $got === $want,
        "student=" . var_export($got, true));
}

/* ── N. Nómina con verbo de listado — «muestrame/dame los estudiantes del
 *      6-A» es roster, no eventos (el léxico de list_events no debe robar
 *      el turno cuando hay sustantivo de persona + grupo sin módulo) ── */
$ctx = null;
foreach ([['muéstrame los estudiantes del 6-A','students_in_group','6-A'],
          ['dame los alumnos del 8-B','students_in_group','8-B'],
          ['lista las tardanzas del 6-A','list_events','6-A'],
          ['muéstrame los eventos del 6-A','list_events','6-A']] as [$q,$want,$grp]) {
    $t = turn($q, $ctx);
    chk("roster {$q}→{$want}", $t['intent'] === $want && ($t['slots']['group'] ?? '') === $grp,
        "intent={$t['intent']} group=" . var_export($t['slots']['group'] ?? null, true));
}

/* ── O. Campo acudiente por paráfrasis relacional ──────────────────────── */
$ctx = null;
foreach ([['quién responde por ese muchacho ante el colegio','acudiente'],
          ['la persona que lo representa ante la institución','acudiente'],
          ['a nombre de quién está este alumno','acudiente']] as [$q,$wantField]) {
    $t = turn($q, $ctx);
    chk("relField {$q}→{$wantField}", ($t['slots']['field'] ?? '') === $wantField,
        "field=" . var_export($t['slots']['field'] ?? null, true) . " intent={$t['intent']}");
}

/* ── resumen ── */
echo "\n";
foreach ($fails as $f) echo "  FAIL $f\n";
printf("\n  RESULTADO: %d PASS · %d FAIL · %.1f%%\n", $P, $F, $P + $F ? $P / ($P + $F) * 100 : 0);
exit($F ? 1 : 0);
