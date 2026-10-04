<?php
/* _bugprobe.php — regresión de los bugs del transcript real por la ruta
 * determinista (sin LLM, sin DB). php test/_bugprobe.php */
define('ROLE', 'COORDINATOR');
require_once __DIR__ . '/../backend/api/nexus/nexus_nlu.php';
require_once __DIR__ . '/../backend/api/routes/chat.php';
require_once __DIR__ . '/harness_turn.php';

$CASES = [
    // [frase, ctx ds simulado, intent esperado (uno de)]
    ['quiero su acudiente', ['entities'=>['student'=>'María Fernanda Castaño Muñoz'],'last_intent'=>'student_summary','last_result'=>null], ['student_field']],
    ['dame su acudiente',  ['entities'=>['student'=>'María Fernanda Castaño Muñoz'],'last_intent'=>'student_summary','last_result'=>null], ['student_field']],
    ['dame los datos del acudiente', ['entities'=>['student'=>'María Fernanda Castaño Muñoz'],'last_intent'=>'student_summary','last_result'=>null], ['student_field']],
    ['estudiantes nacidos el 14 de febrero de 2023', null, ['birthdays_today']],
    ['nacidos en febrero', null, ['birthdays_today']],
    ['horario del 8A', null, ['schedule_info','schedule.of_group']],
    ['qué clases tiene el grupo 8A hoy', null, ['schedule_info','schedule.of_group']],
    ['horarios de todos los grupos', null, ['schedule_info']],
    ['horario del docente javier moreno', null, ['teacher_schedule']],
    ['y por grupo?', ['entities'=>['module'=>'INASISTENCIA','group_by'=>'weekday','from'=>'2026-09-01','to'=>'2026-09-30'],'last_intent'=>'frequency_table','last_result'=>['type'=>'frequency_table','count'=>3,'items'=>[]]], ['frequency_table']],
    ['entre 10B y 10C quién tiene más tardanzas', null, ['groups_compare']],
    ['qué grupo tiene más evasiones internas: 10A o 8C', null, ['groups_compare']],
    ['compara el 8A con el 9B en tardanzas', null, ['groups_compare']],
    ['octavo vs noveno en tardanzas', null, ['groups_compare']],
    ['cómo va octubre comparado con septiembre en tardanzas', null, ['count_events','attendance_trend']],
    ['octubre vs septiembre en inasistencias', null, ['count_events','attendance_trend']],
    ['este mes vs el mes pasado en tardanzas', null, ['count_events','attendance_trend']],
    ['qué docentes tiene el grupo 9C', null, ['teachers_list']],
    ['quiénes son los docentes de 10A', null, ['teachers_list']],
    ['los acudientes del 8A', null, ['out_of_scope','students_in_group','guardians.of_group']],
    ['cuántos estudiantes hay por grado', null, ['groups_list']],
    ['qué grado tiene más estudiantes', null, ['groups_list']],
    ['qué estudiantes salieron y no han regresado', null, ['pending_returns']],
    ['valentina duarte ramirez ha hecho spam al sensor los últimos 7 días', null, ['biometric_spam']],
    ['qué profesor ha citado más estudiantes esta semana', null, ['citations_by']],
    ['quién generó el último reporte exportado', null, ['reports_log']],
    ['cuántos estudiantes tienen excepción biométrica', null, ['student_consent']],
    ['ranking de grupos por evasiones internas', null, ['attendance_ranking']],
    ['los que más evaden este semestre', null, ['top_offenders']],
    ['y evasiones?', ['entities'=>['module'=>'INASISTENCIA','from'=>'2026-09-01','to'=>'2026-09-30'],'last_intent'=>'count_events','last_result'=>null], ['count_events','list_events','frequency_table']],
    ['y ayer?', ['entities'=>['module'=>'INASISTENCIA'],'last_intent'=>'count_events','last_result'=>null], ['count_events','attendance_today','list_events']],
];

$fail = 0;
foreach ($CASES as [$q, $ctx, $expect]) {
    $cls = nxClassify($q);
    $i = nxDialogueResolve($cls, $ctx, nxNorm($q));
    $r = $i['resolved'];
    $intent = $r['intent']; $slots = $r['slots'] ?? [];
    $ok = in_array($intent, $expect, true);
    if (!$ok) $fail++;
    printf("%s %-58s → %s  slots=%s\n", $ok ? '✓' : '✗',
        mb_substr($q,0,58), $intent,
        json_encode(array_intersect_key($slots, array_flip(['student','group','group2','grade','grade2','module','field','group_by','from','to','range_label','_ref','_nav','days','person','_compare_ranges'])), JSON_UNESCAPED_UNICODE));
}
echo "\n" . ($fail === 0 ? 'TODOS PASAN' : "$fail FALLAN") . "\n";
exit($fail === 0 ? 0 : 1);
