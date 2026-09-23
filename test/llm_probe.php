<?php
/* test/llm_probe.php — sonda de lenguaje natural REAL sobre nxClassify.
 *
 * Frases escritas como las habla/escribe personal de un colegio colombiano
 * (no copiadas del corpus sintético). Sirve para comparar:
 *   · clasificador solo      : php test/llm_probe.php
 *   · con parser LLM activo  : NLU_LLM_KEY=gsk_... NLU_LLM_MODE=primary php test/llm_probe.php
 *   · servicio python local  : NEXO_NLU_URL=http://127.0.0.1:8096 php test/llm_probe.php
 *   · solo fallback PHP      : NEXO_NLU_URL=http://127.0.0.1:9 php test/llm_probe.php
 *
 * El chequeo ($expect) es aproximado — intenta el intent "correcto" pero lo
 * que importa es la columna impresa: intent/conf/fuente por frase.
 */
define('ROLE', 'RECTOR');
require_once __DIR__ . '/../backend/api/lib/nexus_nlu.php';

$PHRASES = [
    /* [frase natural, intent esperado aproximado] */
    ['necesito saber cuántos estudiantes hay en el colegio',            'students_count'],
    ['me puedes decir quiénes faltaron hoy',                            'attendance_today'],
    ['cuántos alumnos tiene el grado sexto',                            'group_student_count'],
    ['quién es el acudiente del niño que llegó tarde',                  'student_field'],
    ['muéstrame el reporte de asistencia de esta semana',               'list_events'],
    ['cuál es el grupo con más problemas de convivencia',               'attendance_ranking'],
    ['necesito contactar a los padres de los estudiantes de 8B',        'students_in_group'],
    ['cuántas novedades se han presentado este mes',                    'count_events'],
    ['hay algún estudiante que no haya marcado entrada hoy',            'attendance_today'],
    ['compara la asistencia del lunes con la del viernes',              'attendance_ranking'],
    ['quiénes son los que más se saltan clase',                         'top_offenders'],
    ['pásame el número de la mamá de la niña del 7A',                   'student_field'],
    ['qué docentes tienen más tardanzas esta semana',                   'top_offenders'],
    ['cuántos permisos hay pendientes por aprobar',                     'permissions'],
    ['el horario del décimo B por favor',                               'schedule_info'],
    ['qué estudiantes están en observación',                            'risk_students'],
    ['avísame si algún niño se voló ayer',                              'count_events'],
    ['dame la lista completa de los del once A',                        'students_in_group'],
    ['buenas tardes, cómo vas',                                         'greeting_time'],
    ['olvídalo, ya no necesito nada',                                   'goodbye'],
];

$llm = function_exists('nxLlmCfg') ? nxLlmCfg() : ['mode'=>'off','model'=>'-','key'=>''];
printf("═══ llm_probe — NLU_LLM_MODE=%s model=%s key=%s ═══\n\n",
    $llm['mode'], $llm['model'], $llm['key'] !== '' ? 'SET' : 'EMPTY');

$tot=0; $hit=0; $oos=0; $srcs=[];
// con LLM activo, espaciar llamadas: free tier ~8K tokens/min (~10 req/min)
$llmOn = ($llm['key'] ?? '') !== '' && ($llm['mode'] ?? 'off') !== 'off';
foreach ($PHRASES as [$q,$expect]) {
    if ($llmOn) usleep(6500000);
    $r = nxClassify($q);
    $tot++;
    $intent = $r['intent'] ?? '?';
    $ok = $intent === $expect;
    if ($ok) $hit++;
    if ($intent === 'out_of_scope') $oos++;
    $srcs[$r['source'] ?? '?'] = ($srcs[$r['source'] ?? '?'] ?? 0) + 1;
    printf("%s %-62s → %-22s conf=%-5s src=%s\n",
        $ok ? '✓' : ' ', mb_strimwidth($q,0,62), $intent,
        $r['confidence'] ?? 0, $r['source'] ?? '?');
}
printf("\n═══ %d/%d intent esperado | %d out_of_scope | fuentes: %s ═══\n",
    $hit, $tot, $oos, json_encode($srcs, JSON_UNESCAPED_UNICODE));
