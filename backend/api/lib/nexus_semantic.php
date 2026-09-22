<?php
/**
 * =============================================================================
 * lib/nexus_semantic.php — Capa semántica de Nexus (IR + planner + presentación)
 * =============================================================================
 *
 * Sustituye el modelo «frase → intent → plantilla» por composición:
 *
 *   mensaje → señales → plan IR verificable → validación → RBAC → SQL real
 *           → presentación (scalar|list|table|summary|comparison|detail|count)
 *           → result-set completo navegable → nuevo contexto.
 *
 * El plan es una estructura:
 *   capability, entity, op, relation, target, filters{group,group2,status,
 *   module,days,from,to,search,student,grade}, sort, position, cardinality,
 *   projection, presentation, conf, evidence.
 *
 * REGLAS DURAS:
 *   - Solo lectura: ninguna función aquí escribe en BD (readonly_guard).
 *   - Toda consulta lleva school_id ligado + scope docente (chatScope).
 *   - El composer es determinista; sin evidencia estructural devuelve null y
 *     el pipeline de intents existente decide.
 *   - Orden canónico de estudiantes: last_name ASC, first_name ASC
 *     (índice idx_students_school_last_first). Incidentes: detected_at.
 *   - Cardinalidad `all` / `presentation=table` entregan TODAS las filas en
 *     tarjeta (nunca «5 y pide otro» cuando pidieron la lista completa).
 */

/* ============================================================================
 * 1. REGISTRO DE CAPACIDADES (nexus_capability_registry)
 * --------------------------------------------------------------------------
 * Cada entrada describe una capacidad REAL del ecosistema NEXO — no un intent.
 * `exec`: nombre de ejecutor en este archivo | 'intent:<intent>' = lo ejecuta
 * el pipeline clásico | 'ui' = capacidad mutativa: el chat solo navega.
 * ========================================================================== */
function nxCapabilityRegistry(): array {
    static $r = null;
    if ($r !== null) return $r;
    $S = ['RECTOR','COORDINATOR','SECRETARY','TEACHER','COUNSELOR'];          // STAFF
    $G = ['RECTOR','COORDINATOR','SECRETARY'];                              // GLOBAL
    $r = [
    /* ---- estudiantes ---- */
    'students.list' => [
        'name'=>'Listar estudiantes','description'=>'Nómina/roster con filtros, orden y cardinalidad',
        'user_goal'=>'ver qué estudiantes cumplen una condición','action_type'=>'list',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['name','document','group','shift','birthdate','status','exempt'],
        'filters'=>['group','grade','search','status','module','range'],
        'sorting'=>['name','document','group'],'aggregation'=>'count',
        'pagination'=>'result_set','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['group','student','last_result'],
        'required_parameters'=>[],
        'related'=>['students.count','students.position','guardians.of_group','students.percent'],
        'nearby'=>['groups.list','students.in_group','attendance.absent_list'],
        'endpoints'=>['GET /students','POST /consultations/query(group_students|all_students)'],
        'service'=>'nexus_semantic','query'=>'students×sga×ag',
        'response_shape'=>'text+card+result_set','presentation'=>['list','table','count'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'students','intent_equiv'=>'students_in_group',
    ],
    'students.count' => [
        'name'=>'Conteo de estudiantes','description'=>'Cuántos estudiantes (global/filtrado)',
        'user_goal'=>'saber el total','action_type'=>'count',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['count'],'filters'=>['group','grade','status','search'],
        'sorting'=>[],'aggregation'=>'count','pagination'=>null,'time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['group'],'required_parameters'=>[],
        'related'=>['students.list','students.percent'],'nearby'=>['groups.count'],
        'endpoints'=>['GET /dashboard/stats'],'service'=>'nexus_semantic',
        'query'=>'COUNT students×scope','response_shape'=>'scalar',
        'presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,'exec'=>'students',
        'intent_equiv'=>'students_count|group_student_count',
    ],
    'students.position' => [
        'name'=>'Posición en la nómina','description'=>'primero/último/n-ésimo del orden canónico',
        'user_goal'=>'identificar un lugar del listado','action_type'=>'first|last|nth',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['name','document','group'],'filters'=>['group','grade','status','module','range'],
        'sorting'=>['name','time'],'aggregation'=>null,'pagination'=>'result_set',
        'time_scope'=>'range','required_context'=>[],'optional_context'=>['last_result','group'],
        'required_parameters'=>[],'related'=>['students.list','result_nav'],
        'nearby'=>['students.count'],'endpoints'=>['(derivable de students list)'],
        'service'=>'nexus_semantic','query'=>'students ORDER BY last_name,first_name OFFSET n',
        'response_shape'=>'scalar+result_set','presentation'=>['scalar'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'students','intent_equiv'=>'students_in_group',
    ],
    'students.percent' => [
        'name'=>'Porcentaje de estudiantes','description'=>'numerador filtrado ÷ universo (grupo/colegio)',
        'user_goal'=>'saber qué fracción cumple la condición','action_type'=>'percent',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['percent','n','total'],'filters'=>['group','status','module','range'],
        'sorting'=>[],'aggregation'=>'ratio','pagination'=>null,'time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['group'],'required_parameters'=>[],
        'related'=>['students.count','attendance.absent_count'],'nearby'=>['groups.compare'],
        'endpoints'=>['(derivable)'],'service'=>'nexus_semantic',
        'query'=>'COUNT(filtered)/COUNT(universe)','response_shape'=>'scalar',
        'presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,'exec'=>'students',
    ],
    'students.detail' => [
        'name'=>'Ficha de estudiante','description'=>'documento, grupo, acudiente, estado',
        'user_goal'=>'consultar un estudiante concreto','action_type'=>'detail',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['name','document','group','shift','birthdate','status','guardian'],
        'filters'=>['student'],'sorting'=>[],'aggregation'=>null,'pagination'=>null,
        'time_scope'=>null,'required_context'=>['student'],'optional_context'=>['person'],
        'required_parameters'=>['student'],
        'related'=>['students.field','guardian.of_student'],'nearby'=>['students.summary'],
        'endpoints'=>['GET /students','POST /consultation/search'],'service'=>'chat intent',
        'query'=>'chatResolveStudent','response_shape'=>'text',
        'presentation'=>['detail'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:student_field','intent_equiv'=>'student_field|student_summary',
    ],
    'students.field' => [
        'name'=>'Campo de estudiante','description'=>'documento/celular/grupo/jornada/nacimiento/estado',
        'user_goal'=>'obtener un dato puntual','action_type'=>'field',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['document','phone','group','shift','birthdate','status'],
        'filters'=>['student'],'sorting'=>[],'aggregation'=>null,'pagination'=>null,
        'time_scope'=>null,'required_context'=>['student'],'optional_context'=>['person'],
        'required_parameters'=>['student','field'],
        'related'=>['students.detail','guardian.of_student'],'nearby'=>['guardians.field'],
        'endpoints'=>['(derivable)'],'service'=>'chat intent','query'=>'chat_student_field',
        'response_shape'=>'text','presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:student_field',
    ],
    'students.of_guardian' => [
        'name'=>'Estudiantes del acudiente','description'=>'inversa guardian→students',
        'user_goal'=>'saber de quién es acudiente / qué estudiantes cuida','action_type'=>'list',
        'source_entity'=>'guardians','target_entity'=>'students',
        'fields'=>['name','document','group'],'filters'=>['guardian_ctx'],
        'sorting'=>['name'],'aggregation'=>'count','pagination'=>'result_set',
        'time_scope'=>null,'required_context'=>['person:guardian|student'],'optional_context'=>[],
        'required_parameters'=>[],
        'related'=>['guardians.of_group','guardian.of_student','students.list'],
        'nearby'=>['guardians.of_group'],'endpoints'=>['(derivable de guardian_student_relationships)'],
        'service'=>'nexus_semantic','query'=>'gsr→students','response_shape'=>'text+result_set',
        'presentation'=>['list','table'],'rbac'=>$S,'read_only'=>true,'exec'=>'students_of_guardian',
    ],
    /* ---- acudientes ---- */
    'guardian.of_student' => [
        'name'=>'Acudiente de un estudiante','description'=>'guardian via relación primary',
        'user_goal'=>'saber quién responde por el estudiante','action_type'=>'detail',
        'source_entity'=>'students','target_entity'=>'guardians',
        'fields'=>['name','phone','document','relationship'],
        'filters'=>['student'],'sorting'=>[],'aggregation'=>null,'pagination'=>null,
        'time_scope'=>null,'required_context'=>['student'],'optional_context'=>['person'],
        'required_parameters'=>['student'],
        'related'=>['students.of_guardian','guardians.field'],'nearby'=>['students.field'],
        'endpoints'=>['(derivable)'],'service'=>'chat intent','query'=>'chat_student_field(acudiente)',
        'response_shape'=>'text','presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:student_field',
    ],
    'guardians.of_group' => [
        'name'=>'Acudientes de un grupo','description'=>'un acudiente por estudiante del grupo',
        'user_goal'=>'contactos responsables del grupo','action_type'=>'list',
        'source_entity'=>'academic_groups','target_entity'=>'guardians',
        'fields'=>['student','guardian','phone','document','relationship'],
        'filters'=>['group'],'sorting'=>['student'],'aggregation'=>'count',
        'pagination'=>'result_set','time_scope'=>null,
        'required_context'=>[],'optional_context'=>['group'],'required_parameters'=>['group'],
        'related'=>['students.list','guardian.of_student'],'nearby'=>['teachers.of_group'],
        'endpoints'=>['POST /consultations/query(all_guardians)'],'service'=>'nexus_semantic',
        'query'=>'gsr×guardians×users×students×sga×ag','response_shape'=>'text+card+result_set',
        'presentation'=>['list','table'],'rbac'=>$S,'read_only'=>true,'exec'=>'guardians_of_group',
    ],
    /* ---- docentes ---- */
    'teachers.of_group' => [
        'name'=>'Docentes de un grupo','description'=>'teacher_group_access + materias (schedules)',
        'user_goal'=>'saber quién enseña al grupo','action_type'=>'list',
        'source_entity'=>'academic_groups','target_entity'=>'users(TEACHER)',
        'fields'=>['name','subjects','email'],'filters'=>['group'],
        'sorting'=>['name'],'aggregation'=>'count','pagination'=>'result_set',
        'time_scope'=>null,'required_context'=>[],'optional_context'=>['group'],
        'required_parameters'=>['group'],
        'related'=>['teachers.list','schedule.of_group'],'nearby'=>['groups.list'],
        'endpoints'=>['GET /groups?teacher_only','GET /school/teachers','GET /school/schedules'],
        'service'=>'nexus_semantic','query'=>'tga×users×schedules','response_shape'=>'text+card+result_set',
        'presentation'=>['list','table'],'rbac'=>$S,'read_only'=>true,'exec'=>'teachers_of_group',
    ],
    'teachers.list' => [
        'name'=>'Docentes del colegio','description'=>'planta docente + grupos',
        'user_goal'=>'ver quiénes enseñan','action_type'=>'list',
        'source_entity'=>'users(TEACHER)','target_entity'=>null,
        'fields'=>['name','email','groups'],'filters'=>['shift'],
        'sorting'=>['name'],'aggregation'=>'count','pagination'=>'card',
        'time_scope'=>null,'required_context'=>[],'optional_context'=>[],
        'required_parameters'=>[],'related'=>['teachers.of_group'],'nearby'=>['staff.list'],
        'endpoints'=>['GET /school/teachers'],'service'=>'chat intent','query'=>'chat_teachers_list',
        'response_shape'=>'card','presentation'=>['table'],'rbac'=>['RECTOR','COORDINATOR','SECRETARY','COUNSELOR'],
        'read_only'=>true,'exec'=>'intent:teachers_list',
    ],
    /* ---- grupos ---- */
    'groups.list' => [
        'name'=>'Grupos del colegio','description'=>'grupos con conteo de estudiantes',
        'user_goal'=>'ver la estructura académica','action_type'=>'list',
        'source_entity'=>'academic_groups','target_entity'=>null,
        'fields'=>['group','grade','students'],'filters'=>['shift'],
        'sorting'=>['name'],'aggregation'=>'count','pagination'=>'card',
        'time_scope'=>null,'required_context'=>[],'optional_context'=>[],
        'required_parameters'=>[],'related'=>['groups.compare','students.list'],
        'nearby'=>['teachers.list'],'endpoints'=>['GET /groups'],
        'service'=>'chat intent','query'=>'chat_groups_list','response_shape'=>'card',
        'presentation'=>['table'],'rbac'=>$S,'read_only'=>true,'exec'=>'intent:groups_list',
    ],
    'groups.compare' => [
        'name'=>'Comparar grupos','description'=>'misma métrica en dos grupos o argmax global',
        'user_goal'=>'saber qué grupo tiene más/menos de algo','action_type'=>'compare',
        'source_entity'=>'academic_groups','target_entity'=>null,
        'fields'=>['group','count','metric'],'filters'=>['group','group2','module','range'],
        'sorting'=>[],'aggregation'=>'count','pagination'=>null,'time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['module','range'],'required_parameters'=>[],
        'related'=>['groups.rank','attendance.ranking'],'nearby'=>['students.count'],
        'endpoints'=>['(derivable de attendance_incidents GROUP BY group)'],
        'service'=>'nexus_semantic','query'=>'ai GROUP BY group','response_shape'=>'text+card',
        'presentation'=>['comparison'],'rbac'=>$S,'read_only'=>true,'exec'=>'groups_compare',
    ],
    'groups.rank' => [
        'name'=>'Ranking de grupos','description'=>'grupo con más/menos incidentes del tipo',
        'user_goal'=>'encontrar el grupo extremo','action_type'=>'rank',
        'source_entity'=>'academic_groups','target_entity'=>null,
        'fields'=>['group','count'],'filters'=>['module','range'],
        'sorting'=>['count_desc'],'aggregation'=>'count','pagination'=>null,'time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['module','range'],'required_parameters'=>[],
        'related'=>['groups.compare','attendance.ranking'],'nearby'=>['students.top'],
        'endpoints'=>['(derivable)'],'service'=>'nexus_semantic','query'=>'ai GROUP BY group ORDER BY n',
        'response_shape'=>'text+card','presentation'=>['comparison','table'],
        'rbac'=>['RECTOR','COORDINATOR','COUNSELOR','SECRETARY','TEACHER'],
        'read_only'=>true,'exec'=>'groups_rank','intent_equiv'=>'attendance_ranking',
    ],

    /* ---- asistencia / incidentes ---- */
    'attendance.today' => ['name'=>'Asistencia de hoy','description'=>'presentes/ausentes/tarde del día',
        'user_goal'=>'cómo va la jornada','action_type'=>'summary|count|list',
        'source_entity'=>'biometric_events+attendance_incidents','target_entity'=>null,
        'fields'=>['present','absent','late','permissions'],'filters'=>['group','range'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['group'],'required_parameters'=>[],
        'related'=>['students.list','incidents.list'],'nearby'=>['day.summary'],
        'endpoints'=>['GET /dashboard/stats','POST /consultations/query(absences|late_arrivals)'],
        'service'=>'chat intent','query'=>'chat_attendance_today|chat_late_today|chat_count_present',
        'response_shape'=>'text+card','presentation'=>['summary','list','scalar'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'intent:attendance_today|late_today|count_present'],
    'incidents.list' => ['name'=>'Lista de incidentes','description'=>'eventos de asistencia por tipo×grupo×estudiante×rango',
        'user_goal'=>'ver los eventos ocurridos','action_type'=>'list',
        'source_entity'=>'attendance_incidents','target_entity'=>null,
        'fields'=>['student','group','type','detected_at'],'filters'=>['module','group','student','range'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'result_set','time_scope'=>'range',
        'required_context'=>['module|status'],'optional_context'=>['group','student','range'],
        'required_parameters'=>[],'related'=>['incidents.count','incidents.position','students.list'],
        'nearby'=>['attendance.today','students.top'],
        'endpoints'=>['POST /consultations/query(absences|late_arrivals|incidents|evasions)','GET /dashboard/events'],
        'service'=>'nexus_semantic|chat intent','query'=>'attendance_incidents×students×sga×ag',
        'response_shape'=>'card+result_set','presentation'=>['list','table','scalar'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'incidents|intent:list_events','intent_equiv'=>'list_events'],
    'incidents.count' => ['name'=>'Conteo de incidentes','description'=>'cuántos eventos del tipo en el rango',
        'user_goal'=>'saber la magnitud','action_type'=>'count',
        'source_entity'=>'attendance_incidents','target_entity'=>null,
        'fields'=>['count'],'filters'=>['module','group','student','range'],
        'sorting'=>[],'aggregation'=>'count','pagination'=>null,'time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['module','group','range'],'required_parameters'=>[],
        'related'=>['incidents.list','students.count'],'nearby'=>['students.percent'],
        'endpoints'=>['POST /consultations/query'],'service'=>'chat intent','query'=>'chat_count_events',
        'response_shape'=>'scalar','presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:count_events'],
    'incidents.position' => ['name'=>'Posición temporal del evento','description'=>'primera/última/n-ésima tardanza, ingreso, falta',
        'user_goal'=>'identificar un evento concreto de la serie','action_type'=>'first|last|nth',
        'source_entity'=>'attendance_incidents','target_entity'=>null,
        'fields'=>['student','group','type','detected_at'],'filters'=>['module','group','student','range'],
        'sorting'=>['time'],'aggregation'=>null,'pagination'=>'result_set','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['last_result','module','group'],'required_parameters'=>[],
        'related'=>['incidents.list','students.position'],'nearby'=>['attendance.today'],
        'endpoints'=>['(derivable de incidents list)'],'service'=>'nexus_semantic',
        'query'=>'ai ORDER BY detected_at','response_shape'=>'scalar+result_set',
        'presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,'exec'=>'incidents'],
    'students.top' => ['name'=>'Reincidencia','description'=>'estudiantes con más eventos del tipo',
        'user_goal'=>'encontrar a los que más faltan/llegan tarde','action_type'=>'rank',
        'source_entity'=>'attendance_incidents','target_entity'=>'students',
        'fields'=>['student','group','count'],'filters'=>['module','group','range'],
        'sorting'=>['count_desc'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['module','group','range'],'required_parameters'=>[],
        'related'=>['groups.rank','incidents.count'],'nearby'=>['students.list'],
        'endpoints'=>['(derivable GROUP BY student)'],'service'=>'chat intent','query'=>'chat_top_offenders',
        'response_shape'=>'card','presentation'=>['table'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:top_offenders'],
    /* ---- permisos / salidas ---- */
    'permissions.active' => ['name'=>'Permisos activos','description'=>'salidas de clase autorizadas vigentes',
        'user_goal'=>'ver quién está fuera con permiso','action_type'=>'list',
        'source_entity'=>'class_exit_authorizations','target_entity'=>null,
        'fields'=>['student','group','exit_time','expected_return'],'filters'=>['range'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'now',
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['permissions.pending','exits.list'],'nearby'=>['incidents.list'],
        'endpoints'=>['POST /consultations/query(active_permissions|issued_permissions)'],
        'service'=>'chat intent','query'=>'chat_permissions','response_shape'=>'card',
        'presentation'=>['table'],'rbac'=>$S,'read_only'=>true,'exec'=>'intent:permissions'],
    'permissions.pending' => ['name'=>'Retornos pendientes','description'=>'autorizados que no han vuelto',
        'user_goal'=>'ver quién se pasó del tiempo','action_type'=>'list',
        'source_entity'=>'class_exit_authorizations','target_entity'=>null,
        'fields'=>['student','group','expected_return'],'filters'=>[],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'now',
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['permissions.active'],'nearby'=>['incidents.list'],
        'endpoints'=>['GET /audit/permissions/pending-returns'],'service'=>'chat intent',
        'query'=>'chat_pending_returns','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'intent:pending_returns'],
    'exits.school' => ['name'=>'Salidas del colegio','description'=>'autorizaciones de salida anticipada',
        'user_goal'=>'ver retiros autorizados','action_type'=>'list',
        'source_entity'=>'school_exit_authorizations','target_entity'=>null,
        'fields'=>['student','exit_time','reason'],'filters'=>['range'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['range'],'required_parameters'=>[],
        'related'=>['permissions.active'],'nearby'=>['incidents.list'],
        'endpoints'=>['GET /audit/permissions/school-exits','POST /consultations/query(school_exits)'],
        'service'=>'chat intent','query'=>'chat_list_events(SALIDA_COLEGIO)','response_shape'=>'card',
        'presentation'=>['table'],'rbac'=>$S,'read_only'=>true,'exec'=>'intent:list_events'],
    /* ---- horario ---- */
    'schedule.of_group' => ['name'=>'Horario del grupo','description'=>'grid día×bloque×materia×docente×salón',
        'user_goal'=>'ver qué clases tiene el grupo','action_type'=>'list',
        'source_entity'=>'schedules','target_entity'=>null,
        'fields'=>['day','block','time','subject','teacher','classroom'],
        'filters'=>['group','day'],'sorting'=>['day','block'],'aggregation'=>'count',
        'pagination'=>'card','time_scope'=>null,'required_context'=>[],
        'optional_context'=>['group'],'required_parameters'=>['group'],
        'related'=>['teachers.of_group','schedule.info'],'nearby'=>['groups.list'],
        'endpoints'=>['GET /school/schedules?group_id='],'service'=>'nexus_semantic',
        'query'=>'schedules×subjects×users×classrooms','response_shape'=>'card',
        'presentation'=>['table'],'rbac'=>$S,'read_only'=>true,'exec'=>'schedule_of_group'],
    'schedule.info' => ['name'=>'Jornadas del colegio','description'=>'school_schedule_config + bloques',
        'user_goal'=>'horarios de entrada/salida por jornada','action_type'=>'detail',
        'source_entity'=>'school_schedule_config','target_entity'=>null,
        'fields'=>['shift','entry','exit','recess','blocks'],'filters'=>[],
        'sorting'=>[],'aggregation'=>null,'pagination'=>'card','time_scope'=>null,
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['schedule.of_group'],'nearby'=>['groups.list'],
        'endpoints'=>['GET /school/config','GET /school/time-blocks'],'service'=>'chat intent',
        'query'=>'chat_schedule_info','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>$S,'read_only'=>true,'exec'=>'intent:schedule_info'],
    /* ---- riesgo / seguimiento ---- */
    'risk.students' => ['name'=>'Estudiantes en riesgo','description'=>'risk_level HIGH/CRITICAL del motor',
        'user_goal'=>'priorizar casos','action_type'=>'list',
        'source_entity'=>'student_behavior_metrics','target_entity'=>null,
        'fields'=>['student','group','risk_level','score'],'filters'=>['level'],
        'sorting'=>['score_desc'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'snapshot',
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['trackings.active','risk.alerts'],'nearby'=>['students.top'],
        'endpoints'=>['GET /behavior/risk','GET /risk/alerts'],'service'=>'chat intent',
        'query'=>'chat_risk_students','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>['RECTOR','COORDINATOR','COUNSELOR','TEACHER'],'read_only'=>true,'exec'=>'intent:risk_students'],
    'trackings.active' => ['name'=>'Seguimientos en proceso','description'=>'casos abiertos de acompañamiento',
        'user_goal'=>'ver qué casos siguen vivos','action_type'=>'list|count',
        'source_entity'=>'student_tracking','target_entity'=>null,
        'fields'=>['student','group','status','dependency','updated_at'],'filters'=>['status'],
        'sorting'=>['updated'],'aggregation'=>'count','pagination'=>'card','time_scope'=>null,
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['risk.students'],'nearby'=>['citations.list'],
        'endpoints'=>['GET /tracking/active','POST /consultations/query(student_tracking_*)'],
        'service'=>'chat intent','query'=>'chat_trackings|chat_count_trackings','response_shape'=>'card',
        'presentation'=>['table','scalar'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:trackings|count_trackings'],
    'citations.list' => ['name'=>'Citaciones a acudientes','description'=>'conteo de citaciones del rango',
        'user_goal'=>'cuántas citaciones se hicieron','action_type'=>'count',
        'source_entity'=>'internal_messages','target_entity'=>null,
        'fields'=>['count'],'filters'=>['range'],'sorting'=>[],'aggregation'=>'count',
        'pagination'=>null,'time_scope'=>'range','required_context'=>[],'optional_context'=>['range'],
        'required_parameters'=>[],'related'=>['messages.failed'],'nearby'=>['trackings.active'],
        'endpoints'=>['GET /audit/messaging/citations'],'service'=>'chat intent','query'=>'chat_citations',
        'response_shape'=>'scalar','presentation'=>['scalar'],'rbac'=>$S,'read_only'=>true,'exec'=>'intent:citations'],
    /* ---- dispositivos / notificaciones / mensajería ---- */
    'devices.status' => ['name'=>'Estado de dispositivos','description'=>'edge devices activos + último ping',
        'user_goal'=>'saber si los nodos responden','action_type'=>'list',
        'source_entity'=>'edge_devices','target_entity'=>null,
        'fields'=>['device','group','last_ping','active'],'filters'=>['status'],
        'sorting'=>['name'],'aggregation'=>'count','pagination'=>'card','time_scope'=>null,
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['biometric.spam'],'nearby'=>['sos.alerts'],
        'endpoints'=>['GET /devices','GET /devices/by-role'],'service'=>'chat intent',
        'query'=>'chat_devices_status','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>['RECTOR','COORDINATOR'],'read_only'=>true,'exec'=>'intent:devices_status'],
    'notifications.unread' => ['name'=>'Notificaciones sin leer','description'=>'avisos del usuario',
        'user_goal'=>'ver pendientes','action_type'=>'list',
        'source_entity'=>'notifications','target_entity'=>null,
        'fields'=>['title','message','created_at'],'filters'=>['unread'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>null,
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['messages.failed'],'nearby'=>['trackings.active'],
        'endpoints'=>['GET /notifications'],'service'=>'chat intent','query'=>'chat_notifications',
        'response_shape'=>'card','presentation'=>['list'],'rbac'=>'ALL','read_only'=>true,
        'exec'=>'intent:notifications_unread'],
    'messages.failed' => ['name'=>'Mensajes fallidos','description'=>'envíos WhatsApp con error',
        'user_goal'=>'saber qué mensajes no llegaron','action_type'=>'count',
        'source_entity'=>'internal_messages+twilio_messages','target_entity'=>null,
        'fields'=>['count'],'filters'=>['range'],'sorting'=>[],'aggregation'=>'count',
        'pagination'=>null,'time_scope'=>'range','required_context'=>[],'optional_context'=>['range'],
        'required_parameters'=>[],'related'=>['whatsapp.status'],'nearby'=>['notifications.unread'],
        'endpoints'=>['GET /audit/messaging/failed'],'service'=>'chat intent','query'=>'chat_failed_messages',
        'response_shape'=>'scalar','presentation'=>['scalar'],'rbac'=>['RECTOR','COORDINATOR','SECRETARY'],
        'read_only'=>true,'exec'=>'intent:failed_messages'],
    'whatsapp.status' => ['name'=>'Estado de mensajería','description'=>'notificaciones WhatsApp de hoy',
        'user_goal'=>'saber si el canal responde','action_type'=>'count',
        'source_entity'=>'notifications+twilio_messages','target_entity'=>null,
        'fields'=>['count'],'filters'=>['range'],'sorting'=>[],'aggregation'=>'count',
        'pagination'=>null,'time_scope'=>'range','required_context'=>[],'optional_context'=>[],
        'required_parameters'=>[],'related'=>['messages.failed'],'nearby'=>['notifications.unread'],
        'endpoints'=>['GET /audit/messaging/whatsapp-sent'],'service'=>'chat intent',
        'query'=>'chat_whatsapp_status','response_shape'=>'scalar','presentation'=>['scalar'],
        'rbac'=>['RECTOR','COORDINATOR','SECRETARY'],'read_only'=>true,'exec'=>'intent:whatsapp_status'],
    /* ---- seguridad / auditoría ---- */
    'sos.alerts' => ['name'=>'Alertas SOS','description'=>'pánico institucional del rango',
        'user_goal'=>'ver emergencias','action_type'=>'list',
        'source_entity'=>'school_panic_events','target_entity'=>null,
        'fields'=>['event','triggered_at','by'],'filters'=>['range'],
        'sorting'=>['time'],'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['range'],'required_parameters'=>[],
        'related'=>['biometric.spam'],'nearby'=>['devices.status'],
        'endpoints'=>['GET /audit/sos/alerts'],'service'=>'chat intent','query'=>'chat_sos_alerts',
        'response_shape'=>'card','presentation'=>['table'],'rbac'=>['RECTOR','COORDINATOR','SECURITY'],
        'read_only'=>true,'exec'=>'intent:sos_alerts'],
    'biometric.spam' => ['name'=>'Spam biométrico','description'=>'rechazos/fallos por dispositivo',
        'user_goal'=>'detectar abuso del sensor','action_type'=>'list',
        'source_entity'=>'biometric_events','target_entity'=>null,
        'fields'=>['device','count'],'filters'=>['range'],'sorting'=>['count_desc'],
        'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['range'],'required_parameters'=>[],
        'related'=>['devices.status'],'nearby'=>['sos.alerts'],
        'endpoints'=>['GET /audit/discipline/biometric-spam'],'service'=>'chat intent',
        'query'=>'chat_biometric_spam','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>['RECTOR','COORDINATOR','SECURITY'],'read_only'=>true,'exec'=>'intent:biometric_spam'],
    'audit.query' => ['name'=>'Auditoría','description'=>'actividad del sistema agrupada por tipo',
        'user_goal'=>'saber quién hizo qué','action_type'=>'summary',
        'source_entity'=>'global_audit_logs','target_entity'=>null,
        'fields'=>['action_type','count'],'filters'=>['range'],'sorting'=>['count_desc'],
        'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>['range'],'required_parameters'=>[],
        'related'=>['my.activity'],'nearby'=>['notifications.unread'],
        'endpoints'=>['GET /audit/global'],'service'=>'chat intent','query'=>'chat_audit',
        'response_shape'=>'card','presentation'=>['table'],'rbac'=>['RECTOR'],'read_only'=>true,
        'exec'=>'intent:audit_query'],
    'my.activity' => ['name'=>'Mi actividad','description'=>'acciones propias del usuario',
        'user_goal'=>'qué he consultado','action_type'=>'summary',
        'source_entity'=>'global_audit_logs','target_entity'=>null,
        'fields'=>['action_type','count'],'filters'=>['range'],'sorting'=>['count_desc'],
        'aggregation'=>'count','pagination'=>'card','time_scope'=>'range',
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['audit.query'],'nearby'=>['session.summary'],
        'endpoints'=>['GET /audit/teacher/system-activity'],'service'=>'chat intent',
        'query'=>'chat_my_activity','response_shape'=>'card','presentation'=>['table'],
        'rbac'=>'ALL','read_only'=>true,'exec'=>'intent:my_activity'],
    /* ---- meta / utilidad ---- */
    'day.summary' => ['name'=>'Resumen del día','description'=>'KPIs de la jornada actual',
        'user_goal'=>'cómo va el colegio hoy','action_type'=>'summary',
        'source_entity'=>'attendance_incidents+biometric_events+permissions+notifications',
        'target_entity'=>null,'fields'=>['late','absent','evasion','present','permissions','notifications'],
        'filters'=>[],'sorting'=>[],'aggregation'=>'count','pagination'=>'card','time_scope'=>'today',
        'required_context'=>[],'optional_context'=>[],'required_parameters'=>[],
        'related'=>['attendance.today'],'nearby'=>['session.summary'],
        'endpoints'=>['GET /dashboard/stats'],'service'=>'chat intent','query'=>'chat_day_summary',
        'response_shape'=>'text+card','presentation'=>['summary'],'rbac'=>$S,'read_only'=>true,
        'exec'=>'intent:day_summary'],
    'birthdays.today' => ['name'=>'Cumpleaños','description'=>'estudiantes que cumplen en 7 días',
        'user_goal'=>'felicitar a tiempo','action_type'=>'list',
        'source_entity'=>'students','target_entity'=>null,
        'fields'=>['student','group','birth_date'],'filters'=>[],'sorting'=>['date'],
        'aggregation'=>null,'pagination'=>'card','time_scope'=>'7d','required_context'=>[],
        'optional_context'=>[],'required_parameters'=>[],'related'=>['students.list'],
        'nearby'=>['groups.list'],'endpoints'=>['(derivable de students.birth_date)'],
        'service'=>'chat intent','query'=>'chat_birthdays_today','response_shape'=>'card',
        'presentation'=>['table'],'rbac'=>'ALL','read_only'=>true,'exec'=>'intent:birthdays_today'],
    'staff.lookup' => ['name'=>'Personal directivo','description'=>'rector/coordinador/psicoorientador',
        'user_goal'=>'saber quién es quién','action_type'=>'list',
        'source_entity'=>'users+roles','target_entity'=>null,
        'fields'=>['name','role'],'filters'=>['role'],'sorting'=>['role'],
        'aggregation'=>null,'pagination'=>null,'time_scope'=>null,'required_context'=>[],
        'optional_context'=>[],'required_parameters'=>[],'related'=>['teachers.list'],
        'nearby'=>['about.me'],'endpoints'=>['GET /users/by-role','POST /consultations/query(staff*)'],
        'service'=>'chat intent','query'=>'chat_staff_lookup','response_shape'=>'text',
        'presentation'=>['list'],'rbac'=>'ALL','read_only'=>true,'exec'=>'intent:staff_lookup'],
    'operations.derive' => ['name'=>'Operaciones (navegación)','description'=>'permiso/citación/seguimiento/etc. — el chat NO ejecuta, abre el flujo autorizado',
        'user_goal'=>'hacer algo operativo','action_type'=>'navigate',
        'source_entity'=>'user_commands','target_entity'=>null,
        'fields'=>['cmd','student'],'filters'=>['student','op'],
        'sorting'=>[],'aggregation'=>null,'pagination'=>null,'time_scope'=>null,
        'required_context'=>[],'optional_context'=>['student','_op'],'required_parameters'=>[],
        'related'=>['operations.start'],'nearby'=>['citations.list','trackings.active'],
        'endpoints'=>['POST /chat/action → /operacion?cmd=…','POST /operations/execute (UI, no chat)'],
        'service'=>'chat intent','query'=>'chat_derive_action|chat_start_operation',
        'response_shape'=>'chip_nav','presentation'=>['action'],'rbac'=>'chatCanAction',
        'read_only'=>true,'exec'=>'intent:derive_action|start_operation'],
];
    return $r;
}


/* ============================================================================
 * 2. SEÑALES LÉXICAS (sobre texto normalizado nxNorm: minúsculas, sin tildes)
 * --------------------------------------------------------------------------
 * Devuelve la evidencia estructural del enunciado. Ninguna detección aquí
 * consulta BD — es pura superficie lingüística.
 * ========================================================================== */
function nxSemGroups(string $q0): array {
    // hasta dos grupos explícitos: «6-A y 6-B», «8a vs 9c», «sexto a».
    preg_match_all('/\b(\d{1,2})\s*[-\s]?\s*([a-e])\b/u', $q0, $m);
    $out = [];
    foreach (array_map(null, $m[1], $m[2]) as [$d,$l]) {
        $g = $d . '-' . strtoupper($l);
        if (!in_array($g, $out, true)) $out[] = $g;
    }
    if (preg_match_all('/\b(primer[oa]|segund[oa]|tercer[oa]|cuart[oa]|quint[oa]|sext[oa]|septim[oa]|octav[oa]|noven[oa]|decim[oa]|once|undecim[oa]?)\s*([a-e])\b/u', $q0, $m2)) {
        $ord = ['prime'=>1,'segun'=>2,'terce'=>3,'cuart'=>4,'quint'=>5,'sext'=>6,'septi'=>7,'octav'=>8,'noven'=>9,'decim'=>10,'once'=>11,'undec'=>11];
        foreach (array_map(null, $m2[1], $m2[2]) as [$o,$l]) {
            foreach ($ord as $k=>$n) if (str_starts_with($o,$k)) { $g=$n.'-'.strtoupper($l); if (!in_array($g,$out,true)) $out[]=$g; break; }
        }
    }
    return $out;
}

function nxSemSignals(string $q0, array $slots, ?array $ds): array {
    $sig = ['entity'=>null,'op'=>null,'relation'=>null,'target'=>null,'filters'=>[],
            'sort'=>null,'position'=>null,'cardinality'=>null,'projection'=>null,
            'presentation'=>null,'evidence'=>[]];
    $e =& $sig['evidence'];

    // ── entidad por sustantivo ────────────────────────────────────────────
    if (preg_match('/\b(acudientes|padres( de familia)?|papas|mamas|responsables|representantes|tutores|familiares|cuidadores)\b/u', $q0)) { $sig['entity']='guardians'; $e[]='noun:guardians'; }
    elseif (preg_match('/\b(docentes|profesores?|maestr[oa]s|profes|planta docente|docencia)\b/u', $q0)) { $sig['entity']='teachers'; $e[]='noun:teachers'; }
    elseif (preg_match('/\b(estudiantes|alumn[oa]s|muchach[oa]s|pelad[oa]s|nin[oa]s|chic[oa]s|matriculad[oa]s|menores|estudiantil|nomina|nominas|relacion de estudiantes|listado de estudiantes|roster|planta estudiantil)\b/u', $q0)
        || preg_match('/\b(la|el|una)\s+(nomina|relacion|listado|matricula)\s+(del|de|completa)\b/u', $q0)) { $sig['entity']='students'; $e[]='noun:students'; }
    elseif (preg_match('/\b(grupos|cursos|secciones|salones de clase|grados)\b/u', $q0)
        || preg_match('/\b(que|cual|cuales) grupo\b/u', $q0)) { $sig['entity']='groups'; $e[]='noun:groups'; }
    elseif (preg_match('/\b(incidentes?|eventos|registros|novedades|reportes de|tardanzas?|inasistencias?|evasiones?|ausencias?|faltas?|retardos?|ingresos?|salidas anticipadas?)\b/u', $q0)) { $sig['entity']='incidents'; $e[]='noun:incidents'; }
    elseif (preg_match('/\b(horarios?|timetable|bloques de clase)\b/u', $q0)) { $sig['entity']='schedules'; $e[]='noun:schedules'; }
    elseif (preg_match('/\b(materias|asignaturas|clases)\b/u', $q0) && preg_match('/\b(grupo|salon|curso|\d)\b/u', $q0)) { $sig['entity']='schedules'; $e[]='noun:subjects_of_group'; }
    elseif (preg_match('/\b(sensores|dispositivos|nodos|lectores|terminales biometricos|marcadores)\b/u', $q0)) { $sig['entity']='devices'; $e[]='noun:devices'; }
    elseif (preg_match('/\b(seguimientos|acompanamientos|casos abiertos)\b/u', $q0)) { $sig['entity']='trackings'; $e[]='noun:trackings'; }
    elseif (preg_match('/\b(notificaciones|avisos)\b/u', $q0)) { $sig['entity']='notifications'; $e[]='noun:notifications'; }
    elseif (preg_match('/\b(permisos|autorizaciones|salidas autorizadas)\b/u', $q0)) { $sig['entity']='permissions'; $e[]='noun:permissions'; }

    // entidad implícita por colectivo: «los del 6-A», «los que faltaron», «las del curso»
    if (!$sig['entity'] && preg_match('/\b(los|las)\s+(del|de|en|que|de la|de los)\b/u', $q0)
        && (!empty($slots['group']) || !empty($slots['module']))) {
        $sig['entity']='students'; $e[]='collective:los_del';
    }

    // ── filtro de estado sobre estudiantes ────────────────────────────────
    if (preg_match('/\b(que |quienes )?(faltaron|falto|ausentes|ausente|no vinieron|no vino|no asistieron|no asistio|no se presentaron|no se presento|se ausentaron|se ausento|no entraron|no entro|no llegaron|sin venir|sin asistir|faltan|faltaba)\b/u', $q0)) {
        $sig['filters']['status']='absent'; $sig['filters']['module']='INASISTENCIA'; $e[]='status:absent';
    } elseif (preg_match('/\b(presentes|asistieron|vinieron|ingresaron|entradas|puntuales|que si vinieron|que si llegaron|si asistieron|en clase|en el salon|en el colegio)\b/u', $q0)) {
        $sig['filters']['status']='present'; $e[]='status:present';
    }
    if (preg_match('/\b(llegaron tarde|llego tarde|tarde\b.{0,12}\b(hoy|ayer|dia|manana)|tardanzas|impuntuales|con tardanza|con retardo|retardos|tardeones)\b/u', $q0)) {
        $sig['filters']['status']='late'; $sig['filters']['module']='LATE_ARRIVAL'; $e[]='status:late';
    }
    if (preg_match('/\b(con permiso|autorizados|autorizadas|con autorizacion|salieron autorizados|permiso vigente|permiso activo)\b/u', $q0)) {
        $sig['filters']['status']='permission'; $e[]='status:permission';
    }
    if (preg_match('/\b(en riesgo|riesgosos|de riesgo|vulnerables|alto riesgo|riesgo alto|criticos)\b/u', $q0)) {
        $sig['filters']['status']='risk'; $e[]='status:risk';
    }
    if (preg_match('/\b(en seguimiento|con seguimiento|derivados|bajo seguimiento|en observacion)\b/u', $q0)) {
        $sig['filters']['status']='tracking'; $e[]='status:tracking';
    }
    if (preg_match('/\b(exentos|exentas|sin huella|eximidos|dispensados)\b/u', $q0)) {
        $sig['filters']['status']='exempt'; $e[]='status:exempt';
    }
    if (preg_match('/\b(sin grupo|sin asignar|no asignados|huerfanos|sin salon)\b/u', $q0)) {
        $sig['filters']['status']='no_group'; $e[]='status:no_group';
    }
    if (preg_match('/\b(evadieron|evadio|caparon|capo|se volaron|se volo|escaparon|se salieron|fugas?)\b/u', $q0)
        && empty($sig['filters']['status'])) {
        $sig['filters']['status']='evasion'; $sig['filters']['module']='EVASION_INTERNA'; $e[]='status:evasion';
    }
    // módulo ya extraído por nxSlots → estado equivalente
    if (empty($sig['filters']['status']) && !empty($slots['module'])) {
        $m = ['INASISTENCIA'=>'absent','INASISTENCIA_JUSTIFICADA'=>'absent','INASISTENCIA_NO_JUSTIFICADA'=>'absent',
              'LATE_ARRIVAL'=>'late','EVASION_INTERNA'=>'evasion','PERMISO'=>'permission'];
        if (isset($m[$slots['module']])) { $sig['filters']['status']=$m[$slots['module']]; $e[]='status:from_module'; }
    }

    // ── operación ─────────────────────────────────────────────────────────
    if (preg_match('/\b(que porcentaje|porcentaje|por ciento|en porcentaje|que parte|que fraccion)\b/u', $q0)) { $sig['op']='percent'; $e[]='op:percent'; }
    elseif (preg_match('/\b(compar\w+|versus|\bvs\b|diferencia entre|contra\b|cual grupo tiene mas|que grupo tiene mas|cual tiene mas|quien tiene mas|cual grupo tiene menos|que grupo tiene menos|cual tiene menos|quien tiene menos|de los dos|cual de los dos)\b/u', $q0)) { $sig['op']='compare'; $e[]='op:compare'; }
    elseif (preg_match('/\b(cuantos|cuantas|cuento|numero de|total de|en total|cuantos son|cuantas son|cuantos hay|cuantas hay)\b/u', $q0)) { $sig['op']='count'; $e[]='op:count'; }
    elseif (preg_match('/\b(resumen|resumido|balance|panorama|estado general|como va|como esta|como vamos|reporte general|situacion general|vista general)\b/u', $q0)) { $sig['op']='summary'; $e[]='op:summary'; }
    elseif (preg_match('/\b(ficha|detalle|detallado|detallada|perfil|expediente|todo sobre|toda la info|datos completos|info completa|informacion completa)\b/u', $q0)) { $sig['op']='detail'; $e[]='op:detail'; }

    // ── posición en colección ─────────────────────────────────────────────
    $pos = null;
    // «los cinco primeros / las tres últimas» = slice top-N, NO posición
    if (preg_match('/\b(?:los|las)\s+(\d{1,2}|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+(primer[oa]s|ultim[oa]s|primeras)\b/u', $q0, $msl)) {
        $numw = ['dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10];
        $n3 = ctype_digit($msl[1]) ? (int)$msl[1] : ($numw[$msl[1]] ?? 5);
        $sig['slice'] = ['n'=>$n3,'from'=>in_array($msl[2],['ultimo','ultima','ultimos','ultimas'],true)?'end':'start'];
        $sig['op'] = 'slice'; $e[]="slice:{$n3}:{$sig['slice']['from']}";
    }
    // «el primero en llegar / quién llegó primero» — orden temporal de ingresos
    if (preg_match('/\b(lleg(o|aron) (primero|antes|mas temprano|primero en)|primero(s)? (en|a) llegar|primero en entrar|antes de que|el primero en llegar|quien llego primero|quien entro primero)\b/u', $q0)) {
        $sig['filters']['status']='present'; $sig['sort']='time_asc'; $pos=1; $e[]='pos:first_arrival';
    }
    elseif (preg_match('/\b(primer[oa]?s?|de primero|aparece de primero|esta de primero|encabez\w*|primer lugar|ocupa el primer|quien va primero|quien esta primero|abre la lista|encabeza)\b/u', $q0)) $pos = 1;
    elseif (preg_match('/\bsegund[oa]s?\b/u', $q0)) $pos = 2;
    elseif (preg_match('/\b(tercer[oa]?s?|tercer lugar)\b/u', $q0)) $pos = 3;
    elseif (preg_match('/\bcuart[oa]s?\b/u', $q0)) $pos = 4;
    elseif (preg_match('/\bquint[oa]s?\b/u', $q0)) $pos = 5;
    elseif (preg_match('/\b(ultim[oa]s?|de ultimo|al final|final de la lista|ultimo lugar|ultima posicion|cierra la lista|de la cola|el ultimo)\b/u', $q0)) $pos = 'last';
    elseif (preg_match('/\bantepenultim\w*\b/u', $q0)) $pos = 'last-2';
    elseif (preg_match('/\bpenultim\w*\b/u', $q0)) $pos = 'last-1';
    elseif (preg_match('/\b(?:numero|posicion|puesto|lugar)\s*(\d{1,3})\b/u', $q0, $mm)) $pos = (int)$mm[1];
    elseif (preg_match('/\b(?:el|la)\s+(\d{1,2})\s+de(?:l| la lista| la tabla| la nomina| los| las)\b/u', $q0, $mm)) $pos = (int)$mm[1];
    elseif (preg_match('/\b(sexto|septimo|octavo|noveno|decimo)\b/u', $q0, $mm)
        && preg_match('/\b(lista|tabla|nomina|grupo|de los|de las|del salon|del curso)\b/u', $q0)) {
        $pos = ['sexto'=>6,'septimo'=>7,'octavo'=>8,'noveno'=>9,'decimo'=>10][$mm[1]];
    }
    // guardias: «primer dia», «primera semana», «grado primero», «sexto grado» son temporales/grado
    if ($pos !== null && preg_match('/\b(primer|primera|segunda|tercera|sexto|septimo|octavo|noveno|decimo)\s+(dia|semana|mes|ano|grado|clase|periodo|bloque)\b/u', $q0)) $pos = null;
    if (isset($sig['slice'])) $pos = null; // el slice manda
    if ($pos !== null && preg_match('/\bgrado\s+(primero|segundo|tercero|cuarto|quinto|sexto|septimo|octavo|noveno|decimo)\b/u', $q0)) $pos = null;
    if ($pos !== null) { $sig['position']=$pos; $sig['op']=$sig['op'] ?? 'position'; $e[]='pos:'.$pos; }

    // ── cardinalidad total ────────────────────────────────────────────────
    if (preg_match('/\b(todos|todas|completo|completa|entero|entera|integro|integra|la nomina completa|listado completo|tabla completa|relacion completa|todo el grupo|el grupo completo|toda la lista|la lista completa|sin faltar|todos y cada uno|sin excepcion|full|en totalidad|completica|completitos|el listado entero|la relacion entera|todos los|todas las)\b/u', $q0)) {
        $sig['cardinality']='all'; $e[]='card:all';
    }

    // ── presentación ──────────────────────────────────────────────────────
    if (preg_match('/\b(tabla|en tabla|formato tabla|como tabla|en una tabla|en columnas|tabulad\w*|cuadro|en cuadro|tipo tabla|ponl\w* en tabla|en forma de tabla|tabular)\b/u', $q0)) { $sig['presentation']='table'; $e[]='pres:table'; }
    elseif (preg_match('/\b(en lista|listado|como lista|uno por uno|enumeralos|enumeralos|enumerados|enumeradas|en fila|uno a uno|de a uno)\b/u', $q0)) { $sig['presentation']='list'; $e[]='pres:list'; }
    elseif ($sig['op']==='summary') { $sig['presentation']='summary'; }
    elseif ($sig['op']==='detail') { $sig['presentation']='detail'; }

    // ── proyección de campos ──────────────────────────────────────────────
    if (preg_match('/\b(solo|solamente|unicamente|nada mas)\b.{0,24}\b(nombres?|nombre)\b/u', $q0)
        || preg_match('/\b(los nombres|sus nombres|que se llaman|como se llaman|como se llama cada uno|quienes son)\b/u', $q0)) {
        $sig['projection']=['name']; $e[]='proj:name';
    } elseif (preg_match('/\b(documentos?|cedulas?|numeros? de documento|identificacion|tarjetas? de identidad|con documento)\b/u', $q0)) {
        $sig['projection']=['name','document']; $e[]='proj:doc';
    } elseif (preg_match('/\b(telefonos?|celulares?|whatsapp|contactos?|numeros? de telefono|numeros? de celular)\b/u', $q0)) {
        $sig['projection']=['name','phone']; $e[]='proj:phone';
    }

    // ── orden explícito ───────────────────────────────────────────────────
    if (preg_match('/\b(por documento|por cedula|por numero de documento)\b/u', $q0)) { $sig['sort']='document'; $e[]='sort:doc'; }
    elseif (preg_match('/\b(por grado|por grupo|por salon|por curso)\b/u', $q0)) { $sig['sort']='group'; $e[]='sort:group'; }
    elseif (preg_match('/\b(por fecha|por hora|mas recientes|los recientes|ultimos en llegar|mas antiguos|cronologic\w*|por orden de llegada|de llegada)\b/u', $q0)) { $sig['sort']='time_desc'; $e[]='sort:time'; }
    elseif (preg_match('/\b(ordenad\w*|por nombre|alfabetic\w*|por apellido|de la a a la z|por orden alfabetico|alfabetico)\b/u', $q0)) { $sig['sort']='name'; $e[]='sort:name'; }

    // ── relaciones ────────────────────────────────────────────────────────
    if (preg_match('/\bde quien (es|son) (este|ese|el|su|dicho) (acudiente|padre|responsable|familiar)\b/u', $q0)
        || preg_match('/\b(que|cuales) estudiantes? (tiene|tiene a cargo|cuida|representa) (este|ese|el|su|un|dicho) acudiente\b/u', $q0)
        || preg_match('/\bestudiantes? (del|de este|de ese|de su|de dicho) acudiente\b/u', $q0)
        || preg_match('/\bhijos? (de|del) (este|ese|el|su|dicho) acudiente\b/u', $q0)
        || preg_match('/\ba quien(es)? (acude|responde por|cuida|representa) (este|ese|el) (acudiente|padre|responsable)\b/u', $q0)) {
        $sig['relation']='students_of_guardian'; $e[]='rel:students_of_guardian';
    }
    if ($sig['entity']==='guardians' && !empty($slots['group'])) { $sig['relation']='guardians_of_group'; $e[]='rel:guardians_of_group'; }
    elseif (preg_match('/\bacudientes? (del|de los|de las) (grupo|salon|curso)\b/u', $q0) && !empty($slots['group'])) { $sig['relation']='guardians_of_group'; $e[]='rel:guardians_of_group'; }
    if ($sig['entity']==='teachers' && !empty($slots['group'])) { $sig['relation']='teachers_of_group'; $e[]='rel:teachers_of_group'; }
    elseif (preg_match('/\b(quien (ensena|dicta|da clase|le da clase|les ensena)|quienes (ensenan|dictan|dan clase)) .{0,24}\b(grupo|salon|curso|\d)/u', $q0) && !empty($slots['group'])) {
        $sig['entity']='teachers'; $sig['relation']='teachers_of_group'; $e[]='rel:teachers_of_group';
    }
    if ($sig['entity']==='schedules' && !empty($slots['group'])) { $sig['relation']='schedule_of_group'; $e[]='rel:schedule_of_group'; }
    elseif (preg_match('/\b(que (clases|materias|asignaturas) (tiene|ve|recibe|dicta)|que se ve en|que dictan en|horario del|horario de)\b/u', $q0) && !empty($slots['group'])) {
        $sig['entity']='schedules'; $sig['relation']='schedule_of_group'; $e[]='rel:schedule_of_group';
    }
    // estudiante → acudiente ya lo cubre student_field; acudiente→estudiante vía ctx.

    // ── tiempo (slots ya calculan days/from/to) ───────────────────────────
    foreach (['group','student','module','days','from','to','range_label','field'] as $k)
        if (isset($slots[$k]) && $slots[$k] !== '' && $slots[$k] !== null) $sig['filters'][$k]=$slots[$k];
    $gs = nxSemGroups($q0);
    if (!empty($slots['group'])) {
        $cg = strtoupper(str_replace([' ','.'],'-',(string)$slots['group']));
        if (preg_match('/^(\d{1,2})([A-Z])$/', $cg, $mc)) $cg = $mc[1].'-'.$mc[2];
        if (!in_array($cg, $gs, true)) $gs[] = $cg;
    }
    if ($gs) { $sig['filters']['group']=$gs[0]; if (isset($gs[1])) $sig['filters']['group2']=$gs[1]; }
    if (preg_match('/\b(mañana|manana|jornada manana|de la manana|por la manana)\b/u', $q0)) $sig['filters']['shift']='mañana';
    elseif (preg_match('/\b(tarde|jornada tarde|de la tarde|por la tarde)\b/u', $q0)
        && !isset($sig['filters']['status']) ) $sig['filters']['shift']='tarde';
    elseif (preg_match('/\b(jornada unica|unica jornada)\b/u', $q0)) $sig['filters']['shift']='única';
    if (preg_match('/\bgrado\s+(\d{1,2})\b/u', $q0, $mm) && empty($sig['filters']['group'])) $sig['filters']['grade']=$mm[1];

    // ── operaciones bajo demanda ──────────────────────────────────────────
    if (!$sig['op'] && $sig['entity']) {
        if (preg_match('/\b(quienes|cuales|lista|listado|listar|muestra|muestrame|dame|dime|ver|hay|nomina|relacion|roster|integrantes|conforman|componen|pertenecen|los de|las de|los que|las que|muestreme|pasame|ensename|enseneme|regalame|tirame|sueltame)\b/u', $q0))
            { $sig['op']='list'; $e[]='op:list'; }
    }
    return $sig;
}


/* ============================================================================
 * 3. COMPOSITOR — señales + contexto → plan IR verificable (o null)
 * --------------------------------------------------------------------------
 * Devuelve null cuando el enunciado no aporta estructura suficiente: el
 * pipeline de intents existente sigue siendo la vía normal para el resto
 * del dominio. Solo compone cuando la evidencia léxica obliga a una
 * interpretación que los intents no expresan (posición, cardinalidad,
 * presentación, relación inversa, filtro compuesto).
 * ========================================================================== */
function nxSemanticCompose(string $q0, string $intent, float $conf, array $slots, array $interp, ?array $ds): ?array {
    // ── guardias: no tocar operaciones, seguridad, confirmaciones ni nav ──
    static $never = ['security_probe','start_operation','derive_action','confirm_op',
        'cancel','repeat_op','clarify','result_nav','deictic'];
    if (in_array($intent, $never, true)) return null;
    // «compara 6-A con 7-B» puede caer en math_operation por «6-A» — dos
    // grupos explícitos + verbo de comparación = evidencia estructural real
    if ($intent === 'math_operation'
        && !(preg_match('/\b(compar\w*|versus|\bvs\b|diferencia entre|contra\b)\b/u', $q0)
             && count(nxSemGroups($q0)) >= 2)) return null;
    if (in_array($interp['turn_type'] ?? '', ['confirmation','cancel','op_repeat'], true)) return null;
    if (!empty($slots['_op']) || !empty($slots['_repeat'])) return null;
    // _nav no bloquea: si navegó, el flujo ya salió antes; si está aquí, nav fue omitida
    // field lookup ya cubierto por student_field — salvo que el enunciado
    // pida una RELACIÓN inversa («de quién es este acudiente»)
    // field lookup con persona concreta ya cubierto por student_field;
    // sin estudiante («nómina del 6-A por documento») el compositor decide
    if (($slots['field'] ?? null) && in_array($intent,['student_field','guardian_field'],true)
        && !empty($slots['student'])) {
        $probe = nxSemSignals($q0, $slots, $ds);
        if (($probe['relation'] ?? null) !== 'students_of_guardian') return null;
    }
    if (preg_match('/\b(al azar|aleatorio|random|cualquiera|uno cualquiera|una cualquiera|azar)\b/u', $q0)) return null; // random_student

    $sig = nxSemSignals($q0, $slots, $ds);
    $ent  = $sig['entity'];
    $rel  = $sig['relation'];
    $f    = $sig['filters'];

    // entidad por contexto: «y sus documentos», «los de ese grupo»…
    if (!$ent && $ds) {
        $prevType = $ds['last_result']['type'] ?? null;
        if ($prevType && preg_match('/\b(los|las|sus|esos|esas|estos|estas|del mismo|de la misma)\b|\b\w*(me|nos)(los|las)\b/u', $q0)
            && in_array($prevType, ['students','incidents','guardians'], true)) {
            $ent = ['students'=>'students','incidents'=>'incidents','guardians'=>'guardians'][$prevType];
            $sig['evidence'][]='entity:from_ctx';
            // mismo conjunto, otra vista: hereda los filtros del result-set
            foreach (($ds['last_result']['_filters'] ?? []) as $k=>$v)
                if (!isset($sig['filters'][$k]) || $sig['filters'][$k]==='' || $sig['filters'][$k]===null)
                    $sig['filters'][$k] = $v;
        }
    }
    if (!$ent && $rel === 'students_of_guardian') $ent = 'students';
    if (!$ent && !empty($f['status']) && ($slots['group'] ?? $f['group'] ?? null)) { $ent='students'; $sig['evidence'][]='entity:status+group'; }
    // posición + grupo explícito → la nómina del grupo («el primero de 6-A»)
    if (!$ent && $sig['position']!==null && !empty($f['group'])) { $ent='students'; $sig['evidence'][]='entity:pos+group'; }
    // porcentaje + estado → estudiantes («qué % del colegio llegó tarde»)
    if (!$ent && $sig['op']==='percent' && !empty($f['status'])) { $ent='students'; $sig['evidence'][]='entity:pct+status'; }
    // posición + estado → estudiantes («quién llegó primero», «el último en faltar»)
    // — va ANTES del ctx: el estado textual es evidencia más fuerte que la
    // entidad del set anterior
    if (!$ent && $sig['position']!==null && !empty($f['status'])) { $ent='students'; $sig['evidence'][]='entity:pos+status'; }
    // posición sobre result-set contextual («el primero» sin _nav por grupo distinto)
    if (!$ent && $sig['position']!==null && !empty($ds['last_result']['type'])) {
        $ent = ['students'=>'students','incidents'=>'incidents','guardians'=>'guardians','teachers'=>'teachers'][$ds['last_result']['type']] ?? null;
        if ($ent) $sig['evidence'][]='entity:pos+ctx';
    }
    // «la relación completa del 9B» sin sustantivo — el roster por defecto
    if (!$ent && ($sig['cardinality']==='all' || $sig['presentation']==='table') && !empty($f['group']) && empty($f['module'])) {
        $ent='students'; $sig['evidence'][]='entity:card+group';
    }
    // comparación/ranking de grupos no necesita sustantivo «grupos»:
    // «compara 6-A con 6-B», «cuál tiene más tardanzas»
    if (!$ent && ($sig['op']==='compare' || !empty($f['group2']))) { $ent='groups'; $sig['evidence'][]='entity:compare'; }
    // slice + grupo → nómina del grupo («los cinco primeros de 6-A»)
    if (!$ent && isset($sig['slice']) && (!empty($f['group']) || !empty($ds['last_result']['_filters']))) { $ent='students'; $sig['evidence'][]='entity:slice'; }
    // módulo + estructura (conteo/tabla/todos/posición) → serie de incidentes
    if (!$ent && !empty($f['module']) && ($sig['op']==='count' || $sig['cardinality']==='all'
        || $sig['presentation']==='table' || $sig['position']!==null || isset($sig['slice']))) {
        $ent='incidents'; $sig['evidence'][]='entity:module+struct';
    }
    if (!$ent && !$rel) return null;

    // posición/slice define colección nueva: module/days heredados del ctx
    // NO aplican salvo que el texto actual los repita («el primero DE LOS QUE
    // FALTARON» sí expresa estado; «los cinco primeros» no hereda módulo)
    if (($sig['position']!==null || isset($sig['slice'])) && !empty($interp['resolved']['inherited'])) {
        $inh = $interp['resolved']['inherited'];
        if (in_array('module',$inh,true) && empty($sig['filters']['status']))
            unset($f['module']);
        if (in_array('days',$inh,true) && !preg_match('/\b(hoy|ayer|semana|mes|ano|ultimos|pasado)\b/u',$q0))
            unset($f['days'],$f['from'],$f['to'],$f['range_label']);
        $sig['filters'] = $f;
    }

    $plan = ['capability'=>null,'entity'=>$ent,'op'=>$sig['op'] ?? 'list','relation'=>$rel,
             'target'=>$sig['target'],'filters'=>$f,'sort'=>$sig['sort'],
             'position'=>$sig['position'],'cardinality'=>$sig['cardinality'],
             'projection'=>$sig['projection'],'presentation'=>$sig['presentation'],
             'conf'=>0.0,'evidence'=>$sig['evidence'],'_src'=>'semantic'];
    $score = 0.0;

    // ── reglas de composición (más específicas primero) ───────────────────
    if ($rel === 'students_of_guardian') {
        $plan['capability']='students.of_guardian'; $plan['op']='list'; $score=0.85;
    } elseif ($rel === 'guardians_of_group') {
        $plan['capability']='guardians.of_group'; $plan['entity']='guardians'; $score=0.9;
    } elseif ($rel === 'teachers_of_group') {
        $plan['capability']='teachers.of_group'; $plan['entity']='teachers'; $score=0.9;
    } elseif ($rel === 'schedule_of_group') {
        $plan['capability']='schedule.of_group'; $plan['entity']='schedules'; $score=0.9;
    } elseif ($sig['op']==='compare' || !empty($f['group2'])) {
        $plan['entity']='groups';
        $plan['capability']= !empty($f['group2']) ? 'groups.compare' : 'groups.rank';
        $plan['op']= !empty($f['group2']) ? 'compare' : 'rank'; $score=0.8;
    } elseif ($ent === 'students') {
        if (!$sig['op'] && (!empty($f['group']) || !empty($f['grade']) || !empty($f['search']) || !empty($f['status'])))
            { $sig['op']='list'; $plan['op']='list'; $sig['evidence'][]='op:implicit_list'; }
        if (isset($sig['slice'])) { $plan['capability']='students.list'; $plan['op']='list'; $plan['slice']=$sig['slice']; $score=0.85; }
        elseif ($sig['op']==='percent') { $plan['capability']='students.percent'; $plan['op']='percent'; $score=0.85; }
        elseif ($sig['position']!==null) { $plan['capability']='students.position'; $plan['op']='position'; $score=0.85; }
        elseif (!empty($f['status'])) { $plan['capability']='students.list'; $plan['op']='list'; $score=0.8; }
        elseif ($sig['cardinality']==='all' || $sig['presentation']==='table' || $sig['sort'] || $sig['projection']) {
            $plan['capability']='students.list'; $plan['op']='list'; $score=0.8;
        }
        elseif ($sig['op']==='count') { $plan['capability']='students.count'; $plan['op']='count'; $score=0.75; }
        elseif ($sig['op']==='list' && (!empty($f['group']) || !empty($f['grade']) || !empty($f['search']))) {
            $plan['capability']='students.list'; $plan['op']='list'; $score=0.7;
        }
        elseif ($sig['op']==='summary') return null; // resumen de grupo → handler existente
    } elseif ($ent === 'incidents' || (!empty($f['module']) && !$ent)) {
        if (!$ent) $plan['entity']='incidents';
        // posición/presentación/tablas/conteo-rango sobre eventos — el resto lo hace list_events
        if ($sig['position']!==null) { $plan['capability']='incidents.position'; $plan['entity']='incidents'; $plan['op']='position'; $score=0.8; }
        elseif ($sig['op']==='count' && (!empty($f['module']) && (isset($f['days']) || !empty($f['from']))))
            { $plan['capability']='incidents.list'; $plan['entity']='incidents'; $plan['op']='count'; $score=0.75; }
        elseif ($sig['cardinality']==='all' || $sig['presentation']==='table') { $plan['capability']='incidents.list'; $plan['entity']='incidents'; $plan['op']='list'; $score=0.72; }
        else return null;
    } elseif ($ent === 'guardians' && $sig['op']==='count') {
        $plan['capability']='guardians.of_group'; $plan['entity']='guardians'; $plan['op']='count'; $score=0.75;
    } elseif ($ent === 'groups' && ($sig['op']==='compare' || !empty($f['group2']))) {
        $plan['capability']= !empty($f['group2']) ? 'groups.compare' : 'groups.rank';
        $plan['op']= !empty($f['group2']) ? 'compare' : 'rank'; $score=0.75;
    } else {
        return null; // entidades delegadas (devices, trackings, …) → intents
    }

    // ── filtro obligatorio por capacidad ──────────────────────────────────
    $cap = nxCapabilityRegistry()[$plan['capability']] ?? null;
    if (!$cap) return null;
    foreach ($cap['required_parameters'] ?? [] as $req)
        if (empty($f[$req])) return null; // falta parámetro → aclaración del pipeline clásico

    // ── score final ───────────────────────────────────────────────────────
    if (!empty($f['group'])) $score += 0.05;
    if (!empty($f['status'])) $score += 0.05;
    if ($sig['presentation'] || $sig['cardinality']==='all' || $sig['position']!==null) $score += 0.05;
    if ($conf < 0.65) $score += 0.05; // NLU inseguro → la estructura manda
    if ($conf >= 0.9 && $sig['op']==='list' && !$sig['position'] && !$sig['presentation'] && $sig['cardinality']!=='all' && !$rel) {
        // intent muy seguro y sin señales nuevas → conservar pipeline
        if (in_array($intent, ['students_in_group','group_student_count','students_count','teachers_list','groups_list'], true)) return null;
    }
    $plan['conf'] = min(0.99, $score);
    return $plan['conf'] >= 0.62 ? $plan : null;
}


/* ============================================================================
 * 4. VALIDACIÓN + RBAC — §33: RBAC se aplica DESPUÉS de comprender.
 * ========================================================================== */
function nxPlanAllowed(PDO $conn, array $u, array $plan, string $role): bool {
    $cap = nxCapabilityRegistry()[$plan['capability']] ?? null;
    if (!$cap) return false;
    $roles = $cap['rbac'];
    if ($roles === 'ALL') return chatPolicyEnabled($conn, $u['school_id'], 'chat.smalltalk.enabled') !== false || true;
    if ($roles === 'chatCanAction') return true; // ops navegan; la UI autoriza
    if (!in_array($role, (array)$roles, true)) return false;
    // políticas institucionales por capacidad → mismas llaves del mapa de intents
    if (in_array($role, ['TEACHER','COUNSELOR'], true)) {
        $intentKey = ['students.list'=>'chat.teacher.student_fields','students.position'=>'chat.teacher.student_fields',
            'students.count'=>'chat.teacher.aggregates','students.percent'=>'chat.teacher.aggregates',
            'students.of_guardian'=>'chat.teacher.student_fields','guardians.of_group'=>'chat.teacher.student_fields',
            'teachers.of_group'=>'chat.teacher.aggregates','schedule.of_group'=>'chat.teacher.aggregates',
            'incidents.list'=>'chat.teacher.aggregates','incidents.position'=>'chat.teacher.aggregates',
            'groups.compare'=>'chat.teacher.aggregates','groups.rank'=>'chat.teacher.aggregates'];
        $k = $intentKey[$plan['capability']] ?? null;
        if ($k && !chatPolicyEnabled($conn, $u['school_id'], $k)) return false;
    }
    return true;
}

/* ============================================================================
 * 5. PLANNER → SQL real. Toda consulta parametrizada + school_id + scope.
 * ========================================================================== */
function nxPlanExecute(PDO $conn, array $u, array $plan, array $vars): array {
    try {
        switch ($plan['capability']) {
            case 'students.list': case 'students.count': case 'students.position':
            case 'students.percent': case 'students.in_group':
                return nxExecStudents($conn, $u, $plan, $vars);
            case 'guardians.of_group':   return nxExecGuardiansOfGroup($conn, $u, $plan, $vars);
            case 'students.of_guardian': return nxExecStudentsOfGuardian($conn, $u, $plan, $vars);
            case 'teachers.of_group':    return nxExecTeachersOfGroup($conn, $u, $plan, $vars);
            case 'schedule.of_group':    return nxExecGroupSchedule($conn, $u, $plan, $vars);
            case 'incidents.list': case 'incidents.position':
                return nxExecIncidents($conn, $u, $plan, $vars);
            case 'groups.compare':       return nxExecGroupsCompare($conn, $u, $plan, $vars);
            case 'groups.rank':          return nxExecGroupsRank($conn, $u, $plan, $vars);
        }
    } catch (Throwable $e) {
        securityLog('NX_PLAN_ERROR', ($plan['capability'] ?? '?') . ': ' . $e->getMessage());
    }
    return ['reply'=>'No pude resolver esa consulta con los datos actuales — dime si la replanteo.',
            'intent'=>$plan['capability'],'_plan'=>$plan];
}

/** helpers compartidos ---------------------------------------------------- */
function nxSemRange(array $f): array {
    // [from, to] en 'Y-m-d' — igual criterio que chatRange
    if (!empty($f['from']) && !empty($f['to'])) return [$f['from'], $f['to']];
    $d = isset($f['days']) ? (int)$f['days'] : 0;
    $to = gmdate('Y-m-d');
    $from = gmdate('Y-m-d', strtotime('-' . max(0,$d) . ' days'));
    return [$from, $to];
}

function nxSemGroupId(PDO $conn, array $u, ?string $g): ?array {
    if (!$g) return null;
    $row = chatResolveGroup($conn, $u, $g);
    return $row ?: null;
}

/** students — el ejecutor central del espacio semántico. */
function nxExecStudents(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    // filtros estructurales (sin grupo, exentos, riesgo, seguimiento, permiso
    // vigente) no son de fecha — un rango heredado del contexto NO aplica
    if (in_array($f['status'] ?? '', ['no_group','exempt','risk','tracking','permission','active','inactive'], true))
        unset($f['days'], $f['from'], $f['to'], $f['range_label']);
    $scope = chatScope($conn, $u);
    $w = ['s.school_id = :sid', 's.deleted_at IS NULL'];
    $p = [':sid' => $u['school_id']];
    $join = " LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
              LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id ";

    // ── filtros ──
    if (!empty($f['group'])) {
        $g = nxSemGroupId($conn, $u, $f['group']);
        if (!$g && preg_match('/^\d{1,2}$/', (string)$f['group'])) {
            // «los del sexto» → grado, no grupo específico
            $w[] = 'ag.grade_level = :grade'; $p[':grade'] = (string)$f['group'];
            $plan['_group_name'] = 'grado ' . $f['group'];
        } elseif (!$g) {
            return ['reply'=>"No existe el grupo «{$f['group']}» en {$u['school_name']}. ¿Es otro nombre?",
                    'intent'=>$plan['capability'],'_plan'=>$plan];
        } else {
            $w[] = 'ag.group_id = :gid'; $p[':gid'] = $g['group_id'];
            $plan['_group_name'] = $g['group_name'];
        }
    } elseif (!empty($f['grade'])) {
        $w[] = 'ag.grade_level = :grade'; $p[':grade'] = (string)$f['grade'];
    }
    if (!empty($f['search'])) {
        $p[':q'] = '%' . mb_strtolower($f['search']) . '%';
        $w[] = "(translate(lower(s.first_name||' '||s.last_name),'áéíóú','aeiou') LIKE :q OR translate(lower(s.last_name||' '||s.first_name),'áéíóú','aeiou') LIKE :q OR s.document_number = :doc)";
        $p[':doc'] = $f['search'];
    }
    // estado asistencial → subconsulta
    [$from,$to] = nxSemRange($f);
    $st = $f['status'] ?? null;
    if ($st === 'absent') {
        $w[] = "s.student_id IN (SELECT ai.student_id FROM attendance_incidents ai
                WHERE ai.school_id = :sid2 AND ai.incident_type IN ('INASISTENCIA','UNAUTHORIZED_ABSENCE')
                  AND ai.detected_at >= :from::date AND ai.detected_at < (:to::date + INTERVAL '1 day'))";
        $p[':sid2']=$u['school_id']; $p[':from']=$from; $p[':to']=$to;
    } elseif ($st === 'present') {
        $w[] = "s.student_id IN (SELECT be.student_id FROM biometric_events be
                WHERE be.school_id = :sid2 AND be.event_type LIKE 'INGRESO%'
                  AND be.event_timestamp >= :from::date AND be.event_timestamp < (:to::date + INTERVAL '1 day'))";
        $p[':sid2']=$u['school_id']; $p[':from']=$from; $p[':to']=$to;
    } elseif ($st === 'late' || $st === 'evasion') {
        $typ = $st==='late' ? 'LATE_ARRIVAL' : 'EVASION_INTERNA';
        $w[] = "s.student_id IN (SELECT ai.student_id FROM attendance_incidents ai
                WHERE ai.school_id = :sid2 AND ai.incident_type = :typ
                  AND ai.detected_at >= :from::date AND ai.detected_at < (:to::date + INTERVAL '1 day'))";
        $p[':sid2']=$u['school_id']; $p[':from']=$from; $p[':to']=$to; $p[':typ']=$typ;
    } elseif ($st === 'permission') {
        $w[] = "s.student_id IN (SELECT c.student_id FROM class_exit_authorizations c
                WHERE c.school_id = :sid2 AND c.status = 'ACTIVE')";
        $p[':sid2']=$u['school_id'];
    } elseif ($st === 'risk') {
        $w[] = "s.student_id IN (SELECT m.student_id FROM student_behavior_metrics m
                WHERE m.school_id = :sid2 AND m.risk_level IN ('HIGH','CRITICAL'))";
        $p[':sid2']=$u['school_id'];
    } elseif ($st === 'tracking') {
        $w[] = "s.student_id IN (SELECT t.student_id FROM student_tracking t
                WHERE t.school_id = :sid2 AND t.status = 'en proceso')";
        $p[':sid2']=$u['school_id'];
    } elseif ($st === 'exempt') {
        $w[] = 's.biometric_exempt = TRUE';
    } elseif ($st === 'no_group') {
        $w[] = 'sga.assignment_id IS NULL';
    }
    // filtro por tipo de incidente libre («estudiantes con tardanzas» ya llega como status=late)
    if (!empty($f['module']) && !$st && in_array($f['module'], ['PERMISO','CITACION','SOS','DAÑO'], true)) {
        $w[] = "s.student_id IN (SELECT ai.student_id FROM attendance_incidents ai
                WHERE ai.school_id = :sid2 AND ai.incident_type = :typ
                  AND ai.detected_at >= :from::date AND ai.detected_at < (:to::date + INTERVAL '1 day'))";
        $p[':sid2']=$u['school_id']; $p[':from']=$from; $p[':to']=$to; $p[':typ']=$f['module'];
    }
    if (!empty($f['shift'])) { $w[] = 's.work_shift = :shift'; $p[':shift'] = $f['shift']; }

    // ── orden canónico (documentado — nunca orden incidental) ──
    $order = match ($plan['sort']) {
        'document' => 's.document_number ASC, s.last_name, s.first_name',
        'group'    => 'ag.group_name ASC, s.last_name, s.first_name',
        default    => 's.last_name ASC, s.first_name ASC',
    };

    $sql = "SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.document_number,
                   s.birth_date, s.work_shift, s.biometric_exempt, s.active, ag.group_name, ag.grade_level
            FROM students s {$join}
            WHERE " . implode(' AND ', $w) . " {$scope['sql']}
            ORDER BY {$order} LIMIT 400";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($p, $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // «quién llegó primero/antes» — reorden temporal por primer INGRESO
    if ($plan['sort']==='time_asc' && ($f['status'] ?? null)==='present' && $rows) {
        [$af,$at] = nxSemRange($f);
        $q = "SELECT be.student_id, MIN(be.event_timestamp) AS first_ts
              FROM biometric_events be JOIN students s ON s.student_id=be.student_id AND s.deleted_at IS NULL
              WHERE be.school_id=? AND be.event_type LIKE 'INGRESO%'
                AND be.event_timestamp >= ?::date AND be.event_timestamp < (?::date + INTERVAL '1 day')
              GROUP BY be.student_id ORDER BY first_ts ASC LIMIT 400";
        $stq = $conn->prepare($q);
        $stq->execute([$u['school_id'],$af,$at]);
        $arr = $stq->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = array_values(array_filter($rows, fn($r)=>isset($arr[$r['student_id']])));
        usort($rows, fn($a,$b)=>strcmp($arr[$a['student_id']],$arr[$b['student_id']]));
    }
    $n = count($rows);
    $gl = $plan['_group_name'] ?? ($f['group'] ?? ($f['grade'] ? 'grado '.$f['grade'] : 'el colegio'));
    $stLbl = ['absent'=>'que faltaron','present'=>'presentes','late'=>'con tardanza','evasion'=>'con evasión',
              'permission'=>'con permiso','risk'=>'en riesgo','tracking'=>'en seguimiento','exempt'=>'exentos',
              'no_group'=>'sin grupo'][$st] ?? null;
    $where = trim(($stLbl ? " {$stLbl}" : '') . ($gl !== 'el colegio' ? " en {$gl}" : ' en el colegio')) ?: 'en el colegio';
    $rl = $f['range_label'] ?? (isset($f['days']) && (int)$f['days']>0 ? 'en el rango' : ($st && $st!=='permission' && $st!=='risk' && $st!=='tracking' && $st!=='exempt' && $st!=='no_group' ? 'hoy' : ''));
    $rl = $rl ? ' ' . ltrim($rl) : '';

    // ── slice: «los cinco primeros / las tres últimas» ──
    if (!empty($plan['slice'])) {
        $n5 = $plan['slice']['n']; $fromEnd = $plan['slice']['from']==='end';
        $sl = $fromEnd ? array_slice($rows, -$n5) : array_slice($rows, 0, $n5);
        $cnt = count($sl);
        $lbl = $fromEnd ? "los últimos {$n5}" : "los primeros {$n5}";
        $items = array_map(fn($r)=>['id'=>$r['student_id'],'label'=>trim($r['first_name'].' '.$r['last_name']),
            'sub'=>'doc '.$r['document_number'].($r['group_name']?' · '.$r['group_name']:'')], $sl);
        $rs2 = ['type'=>'students','label'=>"estudiantes {$lbl}",'entity'=>'students',
            'order'=>'apellido, nombre','count'=>$cnt,'items'=>$items,
            '_capability'=>$plan['capability'],'_filters'=>$f,
            'columns'=>['#','Estudiante','Documento','Grupo'],
            'rows'=>array_map(fn($i,$r)=>[$i+1,trim($r['first_name'].' '.$r['last_name']),$r['document_number'],$r['group_name']??'—'],array_keys($sl),$sl)];
        if (!$sl) return ['reply'=>"No hay estudiantes {$where}{$rl}.",'intent'=>'students.list','_result_set'=>$rs2,'_plan'=>$plan];
        $reply = ucfirst($lbl)." {$where}{$rl} — {$cnt} de {$n}:
" . implode("
", array_map(fn($it)=>'• '.$it['label'].' — '.$it['sub'],$items));
        return ['reply'=>$reply,'intent'=>'students.list','_result_set'=>$rs2,'_plan'=>$plan,
                'entities'=>array_filter(['group'=>$plan['_group_name']??null])];
    }

    // ── operaciones ──
    if ($plan['op']==='count')
        return ['reply'=> nxVary(["Hay {$n} estudiantes {$where}{$rl}.","Son {$n} estudiantes {$where}{$rl}.","El conteo da {$n} estudiantes {$where}{$rl}."], $u['id'].$where),
                'intent'=>'students.count','entities'=>array_filter(['group'=>$plan['_group_name']??null,'status'=>$st]),
                '_plan'=>$plan];
    if ($plan['op']==='percent') {
        $den = $n;
        if (!empty($f['group']) || !empty($f['grade'])) {
            $d = $conn->prepare("SELECT COUNT(*) FROM students s
                JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
                JOIN academic_groups ag ON ag.group_id=sga.group_id
                WHERE s.school_id=? AND s.deleted_at IS NULL AND " . (!empty($f['group']) ? 'ag.group_id=?' : 'ag.grade_level=?') . " {$scope['sql']}");
            $d->execute(array_merge([$u['school_id'], !empty($f['group']) ? $g['group_id'] : (string)$f['grade']], $scope['params']));
            $den = max(1,(int)$d->fetchColumn());
        } else {
            $d = $conn->prepare("SELECT COUNT(*) FROM students s WHERE s.school_id=? AND s.deleted_at IS NULL {$scope['sql']}");
            $d->execute(array_merge([$u['school_id']], $scope['params']));
            $den = max(1,(int)$d->fetchColumn());
        }
        $pct = round($n * 100 / $den, 1);
        return ['reply'=>"{$pct}% — {$n} de {$den} estudiantes {$where}{$rl}.",
                'intent'=>'students.percent','entities'=>array_filter(['group'=>$plan['_group_name']??null,'status'=>$st]),
                '_plan'=>$plan];
    }

    // result-set completo (todas las filas — objeto de primera clase)
    $rs = [
        'type'=>'students','label'=>'estudiantes' . ($stLbl ? " {$stLbl}" : ''),
        'entity'=>'students','order'=>'apellido, nombre (A→Z)','count'=>$n,
        '_capability'=>$plan['capability'],'_filters'=>$f,
        'items'=>array_map(fn($r)=>[
            'id'=>$r['student_id'],
            'label'=>trim($r['first_name'].' '.$r['last_name']),
            'sub'=>'doc '.$r['document_number'] . ($r['group_name'] ? ' · '.$r['group_name'] : ''),
            'group'=>$r['group_name'],
        ], $rows),
        'columns'=>['#','Estudiante','Documento','Grupo'],
        'rows'=>array_map(fn($i,$r)=>[$i+1, trim($r['first_name'].' '.$r['last_name']), $r['document_number'], $r['group_name'] ?? '—'],
                          array_keys($rows), $rows),
    ];
    // proyección
    if ($plan['projection']) {
        if ($plan['projection']===['name']) { $rs['columns']=['#','Estudiante','Grupo'];
            $rs['rows']=array_map(fn($i,$r)=>[$i+1, trim($r['first_name'].' '.$r['last_name']), $r['group_name'] ?? '—'], array_keys($rows), $rows); }
        elseif (in_array('document',$plan['projection'],true)) { $rs['columns']=['#','Estudiante','Documento','Grupo']; }
    }

    if ($plan['op']==='position' || $plan['position']!==null) {
        $pos = $plan['position'];
        $idx = is_int($pos) ? $pos - 1
             : ($pos === 'last' ? $n - 1
             : (is_string($pos) && str_starts_with($pos,'last-') ? $n - 1 - (int)substr($pos,5) : null));
        if ($n === 0)
            return ['reply'=>"No hay estudiantes {$where}{$rl} — la lista está vacía.",
                    'intent'=>'students.position','_result_set'=>$rs,'_plan'=>$plan];
        if ($idx === null || $idx < 0 || $idx >= $n)
            return ['reply'=>"{$where}: solo hay {$n} estudiantes — no existe la posición pedida.",
                    'intent'=>'students.position','_result_set'=>$rs,'_plan'=>$plan];
        $it = $rs['items'][$idx];
        $ord = ['1'=>'primero','2'=>'segundo','3'=>'tercero','4'=>'cuarto','5'=>'quinto'];
        $ordTxt = is_int($pos) ? ($ord[$pos] ?? "el número {$pos}") : ($pos==='last' ? 'último' : "puesto " . ($idx+1));
        $ordRule = $plan['sort']==='time_asc' ? 'por hora de llegada'
                 : ($plan['sort']==='document' ? 'por documento'
                 : ($plan['sort']==='group' ? 'por grupo' : 'alfabético por apellido'));
        return ['reply'=>"El {$ordTxt} de {$gl} ({$ordRule}) es *{$it['label']}* — {$it['sub']}. Posición " . ($idx+1) . " de {$n}.",
                'intent'=>'students.position','entities'=>array_filter(['group'=>$plan['_group_name']??null,'student'=>$it['label']]),
                '_result_set'=>$rs,'_result_cursor'=>$idx,'_plan'=>$plan,
                '_person'=>['type'=>'student','id'=>$it['id'],'name'=>$it['label']]];
    }

    // ── presentación ──
    $pres = $plan['presentation'];
    $all = $plan['cardinality']==='all' || $pres==='table';
    if ($n === 0)
        return ['reply'=>nxVaryClean('estudiantes', "{$where}{$rl}", $u['id'].$where),
                'intent'=>'students.list','_result_set'=>$rs,'_plan'=>$plan];
    if ($pres==='table' || $all) {
        return ['reply'=>"Tabla completa: {$n} estudiantes {$where}{$rl} (orden alfabético por apellido).",
                'cards'=>[['title'=>"Estudiantes {$where}","columns"=>$rs['columns'],'rows'=>$rs['rows']]],
                'intent'=>'students.list','entities'=>array_filter(['group'=>$plan['_group_name']??null,'status'=>$st]),
                '_result_set'=>$rs,'_plan'=>$plan];
    }
    $show = array_slice($rs['items'], 0, 12);
    $nameOnly = ($plan['projection'] ?? null) === ['name'];
    $lines = array_map(fn($it)=>'• '.$it['label'].(!$nameOnly && !empty($it['sub'])?' — '.$it['sub']:''), $show);
    $reply = "Estudiantes {$where}{$rl} ({$n}):
" . implode("
", $lines) . ($n > 12 ? "

…y " . ($n-12) . " más — pídeme «todos», «la tabla» o una posición." : '');
    return ['reply'=>$reply,'intent'=>'students.list',
            'entities'=>array_filter(['group'=>$plan['_group_name']??null,'status'=>$st]),
            '_result_set'=>$rs,'_plan'=>$plan];
}


/** acudientes del grupo — relación group→guardians (uno por estudiante). */
function nxExecGuardiansOfGroup(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    $g = nxSemGroupId($conn, $u, $f['group'] ?? '');
    if (!$g) return ['reply'=>"¿De qué grupo? Dime algo como «acudientes del 6-A».",
                     'intent'=>'guardians.of_group','_plan'=>$plan];
    $scope = chatScope($conn, $u);
    $stmt = $conn->prepare("
        SELECT s.first_name||' '||s.last_name AS sname, s.document_number AS sdoc,
               u2.first_name||' '||u2.last_name AS gname, g.whatsapp_phone, u2.document_number AS gdoc,
               r.relationship_type
        FROM guardian_student_relationships r
        JOIN guardians g ON g.guardian_id = r.guardian_id
        JOIN users u2 ON u2.user_id = g.user_id
        JOIN students s ON s.student_id = r.student_id AND s.deleted_at IS NULL
        JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ? AND ag.group_id = ? {$scope['sql']}
        ORDER BY s.last_name, s.first_name LIMIT 400");
    $stmt->execute(array_merge([$u['school_id'], $g['group_id']], $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    $rs = ['type'=>'guardians','label'=>'acudientes','entity'=>'guardians','order'=>'estudiante (apellido)',
        'count'=>$n,'columns'=>['#','Estudiante','Acudiente','WhatsApp','Documento'],
        'items'=>array_map(fn($r)=>['id'=>null,'label'=>$r['gname'],'sub'=>'de '.$r['sname'].($r['whatsapp_phone']?' · '.$r['whatsapp_phone']:'')],$rows),
        'rows'=>array_map(fn($i,$r)=>[$i+1,$r['sname'],$r['gname'],$r['whatsapp_phone']?:'—',$r['gdoc']?:'—'],array_keys($rows),$rows)];
    if (($plan['op'] ?? '') === 'count')
        return ['reply'=>"{$g['group_name']} tiene {$n} acudientes registrados (uno por estudiante).",
                'intent'=>'guardians.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
    if ($n === 0) return ['reply'=>"{$g['group_name']} no tiene acudientes registrados aún.",
                          'intent'=>'guardians.of_group','_result_set'=>$rs,'_plan'=>$plan];
    if (($plan['presentation'] ?? null)==='table' || $plan['cardinality']==='all')
        return ['reply'=>"Acudientes de {$g['group_name']} ({$n}):",
                'cards'=>[['title'=>"Acudientes — {$g['group_name']}",'columns'=>$rs['columns'],'rows'=>$rs['rows']]],
                'intent'=>'guardians.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
    $show = array_slice($rs['items'],0,12);
    $reply = "Acudientes de {$g['group_name']} ({$n}):
" . implode("
", array_map(fn($it)=>'• '.$it['label'].' — '.$it['sub'],$show))
        . ($n>12 ? "

…y " . ($n-12) . " más — «todos» o «en tabla»." : '');
    return ['reply'=>$reply,'intent'=>'guardians.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
}

/** estudiantes del acudiente — relación inversa guardian→students. */
function nxExecStudentsOfGuardian(PDO $conn, array $u, array $plan, array $vars): array {
    $scope = chatScope($conn, $u);
    // fuente: persona activa (guardian) o estudiante activo → su acudiente
    $gid = null; $gname = null;
    $dsPerson = $plan['_ctx_person'] ?? null;
    if (($dsPerson['type'] ?? null) === 'guardian' && !empty($dsPerson['gid'])) {
        $gid = $dsPerson['gid']; $gname = $dsPerson['name'] ?? null;
    }
    if (!$gid && !empty($plan['filters']['student'])) {
        $found = chatResolveStudent($conn, $u, $plan['filters']['student']);
        if ($found && count($found) === 1) {
            $st = $conn->prepare("SELECT g.guardian_id, u2.first_name||' '||u2.last_name AS gname
                FROM guardian_student_relationships r JOIN guardians g ON g.guardian_id=r.guardian_id
                JOIN users u2 ON u2.user_id=g.user_id
                WHERE r.student_id=? ORDER BY r.primary_guardian DESC LIMIT 1");
            $st->execute([$found[0]['student_id']]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) { $gid=$r['guardian_id']; $gname=$r['gname']; }
        }
    }
    if (!$gid)
        return ['reply'=>"¿De qué acudiente hablas? Dime el estudiante o el nombre del acudiente.",
                'intent'=>'clarify','_plan'=>$plan];
    $stmt = $conn->prepare("
        SELECT s.student_id, s.first_name, s.last_name, s.document_number, ag.group_name,
               r.relationship_type
        FROM guardian_student_relationships r
        JOIN students s ON s.student_id = r.student_id AND s.deleted_at IS NULL
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ? AND r.guardian_id = ? {$scope['sql']}
        ORDER BY s.last_name, s.first_name");
    $stmt->execute(array_merge([$u['school_id'], $gid], $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    $who = $gname ? " de {$gname}" : '';
    if ($n === 0) return ['reply'=>"No encuentro estudiantes asociados a ese acudiente{$who}.",
                          'intent'=>'students.of_guardian','_plan'=>$plan];
    $items = array_map(fn($r)=>['id'=>$r['student_id'],
        'label'=>trim($r['first_name'].' '.$r['last_name']),
        'sub'=>'doc '.$r['document_number'].($r['group_name']?' · '.$r['group_name']:'')], $rows);
    $rs = ['type'=>'students','label'=>'estudiantes del acudiente','entity'=>'students',
           'order'=>'apellido','count'=>$n,'items'=>$items,
           'columns'=>['#','Estudiante','Documento','Grupo'],
           'rows'=>array_map(fn($i,$r)=>[$i+1,trim($r['first_name'].' '.$r['last_name']),$r['document_number'],$r['group_name']??'—'],array_keys($rows),$rows)];
    $reply = $n === 1
        ? "El acudiente{$who} responde por *{$items[0]['label']}* — {$items[0]['sub']}."
        : "El acudiente{$who} responde por {$n} estudiantes:
" . implode("
", array_map(fn($it)=>'• '.$it['label'].' — '.$it['sub'],$items));
    return ['reply'=>$reply,'intent'=>'students.of_guardian','_result_set'=>$rs,'_plan'=>$plan];
}

/** docentes del grupo — teacher_group_access + materias vía schedules. */
function nxExecTeachersOfGroup(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    $g = nxSemGroupId($conn, $u, $f['group'] ?? '');
    if (!$g) return ['reply'=>"¿De qué grupo? Dime algo como «docentes del 6-A».",
                     'intent'=>'teachers.of_group','_plan'=>$plan];
    // alcance: docente/psicoorientador solo ve docentes de SUS grupos
    if (in_array($u['role'], ['TEACHER','COUNSELOR'], true)) {
        $ck = $conn->prepare("SELECT 1 FROM teacher_group_access WHERE teacher_user_id=? AND group_id=? LIMIT 1");
        $ck->execute([$u['id'], $g['group_id']]);
        if (!$ck->fetchColumn())
            return ['reply'=>"No tienes asignado el grupo {$g['group_name']} — no puedo mostrar su planta docente.",
                    'intent'=>'teachers.of_group','denied'=>true,'_plan'=>$plan];
    }
    $stmt = $conn->prepare("
        SELECT DISTINCT u2.first_name||' '||u2.last_name AS name, u2.email, u2.user_id,
            (SELECT string_agg(DISTINCT sub.subject_name, ', ')
               FROM schedules sch JOIN subjects sub ON sub.subject_id=sch.subject_id
              WHERE sch.group_id = ? AND sch.teacher_user_id = u2.user_id) AS subjects
        FROM teacher_group_access tga
        JOIN users u2 ON u2.user_id = tga.teacher_user_id
        WHERE tga.group_id = ? AND u2.deleted_at IS NULL
        ORDER BY name");
    $stmt->execute([$g['group_id'], $g['group_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    $rs = ['type'=>'teachers','label'=>'docentes','entity'=>'teachers','order'=>'nombre',
        'count'=>$n,'columns'=>['#','Docente','Materias','Correo'],
        'items'=>array_map(fn($r)=>['id'=>$r['user_id'],'label'=>$r['name'],'sub'=>$r['subjects'] ?: $r['email']],$rows),
        'rows'=>array_map(fn($i,$r)=>[$i+1,$r['name'],$r['subjects']?:'—',$r['email']?:'—'],array_keys($rows),$rows)];
    if ($n === 0) return ['reply'=>"{$g['group_name']} no tiene docentes asignados todavía.",
                          'intent'=>'teachers.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
    if (($plan['presentation'] ?? null)==='table' || $plan['cardinality']==='all')
        return ['reply'=>"Planta docente de {$g['group_name']} ({$n}):",
                'cards'=>[['title'=>"Docentes — {$g['group_name']}",'columns'=>$rs['columns'],'rows'=>$rs['rows']]],
                'intent'=>'teachers.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
    return ['reply'=>"Docentes de {$g['group_name']} ({$n}):
" . implode("
", array_map(fn($r)=>'• '.$r['name'].($r['subjects']?' — '.$r['subjects']:''),$rows)),
            'intent'=>'teachers.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
}

/** horario del grupo — schedules×subjects×users×classrooms. */
function nxExecGroupSchedule(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    $g = nxSemGroupId($conn, $u, $f['group'] ?? '');
    if (!$g) return ['reply'=>"¿De qué grupo es el horario? Dime algo como «horario del 6-A».",
                     'intent'=>'schedule.of_group','_plan'=>$plan];
    $dayFilter = '';
    $p = [$u['school_id'], $g['group_id']];
    if (preg_match('/\b(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b/u', $vars['_q'] ?? '', $mm)) {
        $days = ['lunes'=>1,'martes'=>2,'miercoles'=>3,'jueves'=>4,'viernes'=>5,'sabado'=>6,'domingo'=>7];
        $dayFilter = ' AND sch.day_of_week = ?'; $p[] = $days[$mm[1]];
    }
    $stmt = $conn->prepare("
        SELECT sch.day_of_week, sch.block_number, sch.start_time, sch.end_time,
               sub.subject_name, u2.first_name||' '||u2.last_name AS teacher, cr.classroom_name
        FROM schedules sch
        JOIN academic_groups ag ON ag.group_id = sch.group_id
        LEFT JOIN subjects sub ON sub.subject_id = sch.subject_id
        LEFT JOIN users u2 ON u2.user_id = sch.teacher_user_id
        LEFT JOIN classrooms cr ON cr.classroom_id = sch.classroom_id
        WHERE ag.school_id = ? AND sch.group_id = ? {$dayFilter}
        ORDER BY sch.day_of_week, sch.block_number");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dow = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
    $rs = ['type'=>'schedule','label'=>'bloques de horario','entity'=>'schedules','order'=>'día,bloque',
        'count'=>count($rows),'columns'=>['Día','Bloque','Horario','Materia','Docente','Salón'],
        'items'=>array_map(fn($r)=>['id'=>null,'label'=>($dow[$r['day_of_week']]??$r['day_of_week']).' '.substr($r['start_time'],0,5).'-'.substr($r['end_time'],0,5),
            'sub'=>($r['subject_name']?:'—').($r['teacher']?' · '.$r['teacher']:'')],$rows),
        'rows'=>array_map(fn($r)=>[$dow[$r['day_of_week']]??$r['day_of_week'],$r['block_number'],
            substr($r['start_time'],0,5).'-'.substr($r['end_time'],0,5),
            $r['subject_name']?:'—',$r['teacher']?:'—',$r['classroom_name']?:'—'],$rows)];
    if (!$rows) {
        // fallback: hora de entrada/salida del grupo (daily_schedule_config de hoy)
        $d = $conn->prepare("SELECT expected_entry_time, expected_exit_time, has_classes
            FROM daily_schedule_config WHERE school_id=? AND group_id=? AND config_date=CURRENT_DATE");
        $d->execute([$u['school_id'],$g['group_id']]);
        $dc = $d->fetch(PDO::FETCH_ASSOC);
        if ($dc) return ['reply'=>"{$g['group_name']} no tiene grid de materias cargada. Hoy: entrada "
            . substr($dc['expected_entry_time'],0,5) . ", salida " . substr($dc['expected_exit_time'],0,5) . ".",
            'intent'=>'schedule.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
        return ['reply'=>"{$g['group_name']} no tiene horario cargado todavía — revisa el onboarding de grupos.",
                'intent'=>'schedule.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
    }
    return ['reply'=>"Horario de {$g['group_name']} (" . count($rows) . " bloques):",
            'cards'=>[['title'=>"Horario — {$g['group_name']}",'columns'=>$rs['columns'],'rows'=>$rs['rows']]],
            'intent'=>'schedule.of_group','entities'=>['group'=>$g['group_name']],'_result_set'=>$rs,'_plan'=>$plan];
}


/** incidentes — posición temporal / tabla completa sobre eventos. */
function nxExecIncidents(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    $scope = chatScope($conn, $u);
    $w = ['ai.school_id = :sid'];
    $p = [':sid' => $u['school_id']];
    [$from,$to] = nxSemRange($f);
    $w[] = "ai.detected_at >= :from::date AND ai.detected_at < (:to::date + INTERVAL '1 day')";
    $p[':from']=$from; $p[':to']=$to;
    // «INCIDENTE» genérico = todos los tipos reales — no filtra
    if (!empty($f['module']) && $f['module']!=='INCIDENTE') { $w[]='ai.incident_type = :typ'; $p[':typ']=$f['module']; }
    if (!empty($f['group'])) {
        $g = nxSemGroupId($conn, $u, $f['group']);
        if (!$g) return ['reply'=>"No existe el grupo «{$f['group']}».",'intent'=>'incidents.list','_plan'=>$plan];
        $w[]='ag.group_id = :gid'; $p[':gid']=$g['group_id'];
        $plan['_group_name']=$g['group_name'];
    }
    if (!empty($f['student'])) {
        $st = chatResolveStudent($conn, $u, $f['student']);
        if ($st && count($st)===1) { $w[]='ai.student_id = :stuid'; $p[':stuid']=$st[0]['student_id']; }
    }
    $sql = "SELECT ai.incident_id, ai.incident_type, ai.detected_at,
                   s.first_name||' '||s.last_name AS sname, s.document_number, ag.group_name
            FROM attendance_incidents ai
            JOIN students s ON s.student_id = ai.student_id AND s.deleted_at IS NULL
            LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
            LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
            WHERE " . implode(' AND ', $w) . " {$scope['sql']}
            ORDER BY ai.detected_at ASC LIMIT 400";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($p, $scope['params']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    $typLbl = NX_MODULE_LABEL[$f['module'] ?? ''] ?? 'eventos';
    $gl = $plan['_group_name'] ?? ($f['group'] ?? 'el colegio');
    $rs = ['type'=>'incidents','label'=>$typLbl,'entity'=>'incidents','order'=>'fecha (antiguo→reciente)',
        'count'=>$n,'columns'=>['#','Estudiante','Grupo','Tipo','Fecha'],
        'items'=>array_map(fn($r)=>['id'=>$r['incident_id'],'label'=>$r['sname'],
            'sub'=>$typLbl.' · '.substr($r['detected_at'],0,16)],$rows),
        'rows'=>array_map(fn($i,$r)=>[$i+1,$r['sname'],$r['group_name']?:'—',$typLbl,substr($r['detected_at'],0,16)],array_keys($rows),$rows)];
    if ($plan['op']==='count')
        return ['reply'=>nxVary(["Se registran {$n} {$typLbl} en el rango.","Hay {$n} {$typLbl} en el rango.","El conteo da {$n} {$typLbl}."], $u['id'].$typLbl),
                'intent'=>'incidents.count','entities'=>array_filter(['group'=>$plan['_group_name']??null,'module'=>$f['module']??null]),
                '_result_set'=>$rs,'_plan'=>$plan];
    if ($plan['op']==='position' || $plan['position']!==null) {
        $pos = $plan['position'];
        // «primero» = más temprano del rango; «último» = más reciente
        $idx = is_int($pos) ? $pos-1
             : ($pos==='last' ? $n-1
             : (is_string($pos) && str_starts_with($pos,'last-') ? $n-1-(int)substr($pos,5) : null));
        if ($n===0) return ['reply'=>"No hay {$typLbl} en ese rango.",'intent'=>'incidents.position','_result_set'=>$rs,'_plan'=>$plan];
        if ($idx===null || $idx<0 || $idx>=$n)
            return ['reply'=>"Solo hay {$n} {$typLbl} en el rango — esa posición no existe.",
                    'intent'=>'incidents.position','_result_set'=>$rs,'_plan'=>$plan];
        $it=$rs['items'][$idx];
        return ['reply'=>ucfirst($typLbl)." · posición ".($idx+1)." de {$n}: *{$it['label']}* — {$it['sub']}.",
                'intent'=>'incidents.position','_result_set'=>$rs,'_result_cursor'=>$idx,'_plan'=>$plan];
    }
    if ($n===0) return ['reply'=>nxVaryClean($typLbl,'en el rango',$u['id'].$typLbl),
                        'intent'=>'incidents.list','_result_set'=>$rs,'_plan'=>$plan];
    if (($plan['presentation'] ?? null)==='table' || $plan['cardinality']==='all')
        return ['reply'=>"{$typLbl} del rango ({$n}) — tabla completa:",
                'cards'=>[['title'=>ucfirst($typLbl),"columns"=>$rs['columns'],'rows'=>$rs['rows']]],
                'intent'=>'incidents.list','entities'=>array_filter(['group'=>$plan['_group_name']??null,'module'=>$f['module']??null]),
                '_result_set'=>$rs,'_plan'=>$plan];
    $show = array_slice($rs['items'],0,12);
    return ['reply'=>ucfirst($typLbl)." ({$n}):
" . implode("
", array_map(fn($it)=>'• '.$it['label'].' — '.$it['sub'],$show))
            . ($n>12 ? "

…y ".($n-12)." más — «todos» o «en tabla»." : ''),
            'intent'=>'incidents.list','entities'=>array_filter(['group'=>$plan['_group_name']??null,'module'=>$f['module']??null]),
            '_result_set'=>$rs,'_plan'=>$plan];
}

/** comparación de dos grupos sobre la misma métrica. */
function nxExecGroupsCompare(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    $g1 = nxSemGroupId($conn, $u, $f['group'] ?? '');
    $g2 = nxSemGroupId($conn, $u, $f['group2'] ?? '');
    if (!$g1 || !$g2)
        return ['reply'=>"¿Qué dos grupos comparo? Dime algo como «compara 6-A con 6-B».",
                'intent'=>'groups.compare','_plan'=>$plan];
    [$from,$to] = nxSemRange($f);
    $mod = $f['module'] ?? null;
    $modSql = $mod ? ' AND ai.incident_type = ?' : '';
    $q = "SELECT ag.group_name, COUNT(DISTINCT ai.incident_id) AS n
          FROM attendance_incidents ai
          JOIN students s ON s.student_id=ai.student_id AND s.deleted_at IS NULL
          JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
          JOIN academic_groups ag ON ag.group_id=sga.group_id
          WHERE ai.school_id=? AND ag.group_id IN (?,?)
            AND ai.detected_at >= ?::date AND ai.detected_at < (?::date + INTERVAL '1 day') {$modSql}
          GROUP BY ag.group_name";
    $st = $conn->prepare($q);
    $st->execute(array_filter([$u['school_id'],$g1['group_id'],$g2['group_id'],$from,$to,$mod], fn($v)=>$v!==null));
    $cnt = array_column($st->fetchAll(PDO::FETCH_ASSOC),'n','group_name');
    $n1 = (int)($cnt[$g1['group_name']] ?? 0); $n2 = (int)($cnt[$g2['group_name']] ?? 0);
    $sz = $conn->prepare("SELECT COUNT(*) FROM student_group_assignments WHERE group_id=? AND active=TRUE");
    $sz->execute([$g1['group_id']]); $s1=(int)$sz->fetchColumn();
    $sz->execute([$g2['group_id']]); $s2=(int)$sz->fetchColumn();
    $lbl = $mod ? (NX_MODULE_LABEL[$mod] ?? $mod) : 'incidentes';
    $rl  = $f['range_label'] ?? (isset($f['days'])&&$f['days']>0 ? 'en el rango' : 'hoy');
    $rl  = $rl ? ' '.ltrim($rl) : '';
    $dif = abs($n1-$n2);
    $win = $n1===$n2 ? 'Empate total' : ($n1>$n2 ? "{$g1['group_name']} tiene más (+{$dif})" : "{$g2['group_name']} tiene más (+{$dif})");
    return ['reply'=>"Comparación {$lbl}{$rl}:
• {$g1['group_name']}: {$n1} (de {$s1} estudiantes)
• {$g2['group_name']}: {$n2} (de {$s2} estudiantes)
→ {$win}.",
            'cards'=>[['title'=>"{$lbl} — comparación",'columns'=>['Grupo','Eventos','Estudiantes','%'],
                'rows'=>[[$g1['group_name'],$n1,$s1,$s1?round($n1*100/$s1,1).'%':'—'],
                         [$g2['group_name'],$n2,$s2,$s2?round($n2*100/$s2,1).'%':'—']]]],
            'intent'=>'groups.compare','entities'=>['group'=>$g1['group_name'],'group2'=>$g2['group_name'],'module'=>$mod],
            '_plan'=>$plan];
}

/** ranking de grupos — argmax/min de incidentes por grupo en el rango. */
function nxExecGroupsRank(PDO $conn, array $u, array $plan, array $vars): array {
    $f = $plan['filters'];
    [$from,$to] = nxSemRange($f);
    $mod = $f['module'] ?? null;
    $modSql = $mod ? ' AND ai.incident_type = ?' : '';
    $dir = preg_match('/\b(menos|menor|mas bajo|mas baja|mas limpio|mas limpia|mejor)\b/u', $vars['_q'] ?? '') ? 'ASC' : 'DESC';
    $st = $conn->prepare("SELECT ag.group_name, COUNT(DISTINCT ai.incident_id) AS n
        FROM attendance_incidents ai
        JOIN students s ON s.student_id=ai.student_id AND s.deleted_at IS NULL
        JOIN student_group_assignments sga ON sga.student_id=s.student_id AND sga.active=TRUE
        JOIN academic_groups ag ON ag.group_id=sga.group_id
        WHERE ai.school_id=? AND ai.detected_at >= ?::date AND ai.detected_at < (?::date + INTERVAL '1 day') {$modSql}
        GROUP BY ag.group_name ORDER BY n {$dir} LIMIT 12");
    $st->execute(array_filter([$u['school_id'],$from,$to,$mod], fn($v)=>$v!==null));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $lbl = $mod ? (NX_MODULE_LABEL[$mod] ?? $mod) : 'incidentes';
    $rl  = $f['range_label'] ?? (isset($f['days'])&&$f['days']>0 ? 'en el rango' : 'hoy');
    $rl  = $rl ? ' '.ltrim($rl) : '';
    if (!$rows) return ['reply'=>nxVaryClean($lbl,$rl,$u['id'].$lbl),'intent'=>'groups.rank','_plan'=>$plan];
    $top = $rows[0];
    return ['reply'=>"El grupo con " . ($dir==='DESC'?'más':'menos') . " {$lbl}{$rl} es *{$top['group_name']}* ({$top['n']}).",
            'cards'=>[['title'=>"Ranking — {$lbl}",'columns'=>['#','Grupo','Eventos'],
                'rows'=>array_map(fn($i,$r)=>[$i+1,$r['group_name'],$r['n']],array_keys($rows),$rows)]],
            'intent'=>'groups.rank','entities'=>['module'=>$mod],'_plan'=>$plan];
}
