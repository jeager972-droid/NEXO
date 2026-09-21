"""
intent_semantics.py — Especificación semántica formal de cada intent.

Cada intent NO es «una lista de frases»: es un OBJETIVO semántico
    (action, source_entity, target_entity, field)
que puede expresarse de cientos de formas. El corpus se genera a partir de
estas definiciones cruzando marcos estructurales, vocabulario de conceptos
y escenarios — nunca por mutación mecánica de una sola frase.

Esquema por intent:
    goal               — qué quiere lograr el usuario, en lenguaje de dominio
    action             — retrieve | count | list | navigate | summarize |
                         operate | meta | social | reject
    source_entity      — entidad de la que parte la consulta (o None)
    target_entity      — entidad objetivo del dato (student|guardian|staff|
                         group|school|self|result_set|none)
    possible_fields    — campos consultables bajo este intent
    required_entities  — sin estas → aclaración genuina
    optional_entities  — enriquecen el query sin ser obligatorias
    context_deps       — qué puede heredar del estado de diálogo
    disambiguation     — reglas para separarlo de sus vecinos
    nearby_intents     — intents con los que comparte vocabulario (near-miss)
    negative_examples  — frases que parecen del intent pero no lo son
    concepts           — vocabularios semánticos que activan el intent
                         (las CLAVES se interpolan en los frames del corpus)
"""

# ══════════════════════════════════════════════════════════════════════════
# VOCABULARIOS DE CONCEPTOS — sinónimos contextuales reales (§13)
# Cada entrada = una FORMA DE REFERIRSE al concepto, no un token obligatorio.
# ══════════════════════════════════════════════════════════════════════════

CONCEPTS = {

# — entidades —
'C_STUDENT': [
    'el estudiante', 'el alumno', 'el niño', 'la niña', 'el muchacho',
    'la muchacha', 'el pelado', 'la pelada', 'el chino', 'la china',
    'ese estudiante', 'ese alumno', 'el de la clase', 'el del incidente',
    'el muchacho del que hablamos', 'ese niño', 'el menor',
    'el alumno ese', 'el estudiante ese', 'el que mencioné',
],
'C_GUARDIAN': [
    'el acudiente', 'su acudiente', 'el representante', 'el responsable',
    'el papá', 'la mamá', 'los papás', 'el padre', 'la madre',
    'el padre de familia', 'quien responde por él', 'quien responde por ella',
    'la persona que figura como acudiente', 'quien está registrado como responsable',
    'el familiar registrado', 'el tutor legal', 'el encargado del niño',
    'el que lo representa', 'el que responde por el estudiante',
    'la persona a cargo', 'el adulto responsable', 'quien lo cuida',
    'el contacto familiar', 'la familia del estudiante',
],
'C_STAFF': [
    'el docente', 'el profesor', 'la profesora', 'la docente',
    'el coordinador', 'la coordinadora', 'el rector', 'la rectora',
    'el psicoorientador', 'la psicoorientadora', 'el orientador',
    'la secretaria', 'el secretario', 'el portero', 'el vigilante',
    'el auxiliar', 'quien lo atiende', 'el que le da clase',
],
'C_GROUP': [
    'el {group}', 'el grupo {group}', 'el curso {group}', 'el salón {group}',
    'del {group}', 'de {group}', 'del grupo {group}', 'del curso {group}',
    'el grado {group}', 'los del {group}', 'el {group}',
],
'C_SCHOOL': [
    'el colegio', 'la institución', 'el plantel', 'la escuela',
    'el colegio entero', 'todo el plantel', 'la institución completa',
],

# — campos —
'C_DOC': [
    'el documento', 'su documento', 'el número de documento',
    'la cédula', 'su cédula', 'el documento de identidad', 'el doc',
    'la identificación', 'el número de identificación', 'la TI',
    'el papel de identidad', 'su número de identidad', 'el número de cédula',
],
'C_PHONE': [
    'el número', 'su número', 'el teléfono', 'su teléfono', 'el celular',
    'su celular', 'el whatsapp', 'su whatsapp', 'el contacto', 'su contacto',
    'el número de contacto', 'el número del acudiente',
],

# frases completas de petición de contacto (no interpolan en «X de E»)
'C_PHONE_Q': [
    'cómo lo llamo', 'a qué número lo llamo', 'dónde lo contacto',
    'cómo me comunico con él', 'cómo lo contacto', 'cómo le llamo',
    'cómo hablo con él', 'dónde lo ubico por teléfono',
],
'C_NAME': [
    'el nombre', 'su nombre', 'cómo se llama', 'el nombre completo',
    'quién es', 'el nombre del acudiente', 'cómo se llama el acudiente',
],

# — módulos de eventos —
# C_X: sustantivos («inasistencias») — interpolan en «cuántos {X}», «los {X}»
# C_X_WHO: frases-verbales («se volaron») — interpolan en «quiénes {X_WHO}»
'C_ABSENCE': [
    'inasistencias', 'faltas', 'ausencias', 'ausentes', 'faltantes',
    'días perdidos', 'inasistencias registradas', 'faltas registradas',
    'ausentismo', 'ausentismo escolar', 'el ausentismo',
],
'C_ABSENCE_WHO': [
    'no vinieron', 'faltaron', 'no entraron', 'no llegaron',
    'no se presentaron', 'no asistieron', 'están ausentes', 'se ausentaron',
    'se reportaron ausentes', 'quedaron por fuera',
],
'C_LATE': [
    'tardanzas', 'llegadas tarde', 'impuntualidad', 'tardones', 'retardos',
    'llegadas tarde', 'impuntuales', 'entradas tarde', 'llegadas después de la hora',
    'tardanzas registradas',
],
'C_LATE_WHO': [
    'llegaron tarde', 'se demoraron', 'llegaron después de la hora',
    'entraron tarde', 'se tardaron', 'llegaron atrasados',
    'llegaron después del timbre', 'entraron después de la hora',
],
'C_EVASION': [
    'evasiones', 'fugas', 'salidas no autorizadas', 'escapadas',
    'evasiones internas', 'fugas internas', 'evasiones registradas',
],
'C_EVASION_WHO': [
    'se volaron', 'se escaparon', 'se salieron', 'se fugaron',
    'abandonaron', 'se fueron sin permiso', 'se piraron', 'se largaron',
    'se fugaron del plantel', 'se escaparon del colegio',
    'se salieron del plantel', 'abandonaron el colegio',
    'se fueron sin avisar', 'se volaron de la jornada',
    'se caparon', 'se rajaron', 'se dieron a la fuga', 'se escaparon de clase',
],
'C_PERMISSION': [
    'permisos', 'excusas', 'autorizaciones', 'permisos de salida',
    'salidas autorizadas', 'excusas médicas', 'las solicitudes de salida',
    'los permisos aprobados', 'autorizaciones de salida',
],
'C_CITATION': [
    'citaciones', 'citas a acudientes', 'llamados a acudiente',
    'las citaciones registradas', 'las convocatorias a padres',
],
'C_TRACKING': [
    'seguimientos', 'casos en seguimiento', 'casos abiertos',
    'casos en convivencia', 'casos de psicoorientación', 'los casos activos',
    'los procesos abiertos', 'los seguimientos activos',
],

# — acciones —
'C_WANT_OP': [
    'quiero', 'necesito', 'me toca', 'hay que', 'tengo que', 'quisiera',
    'me gustaría', 'quiero poder', 'necesito hacer', 'debo', 'me pidieron',
    'voy a', 'ocupo', 'se necesita', 'es necesario', 'me urgen',
],
'C_RETRIEVE': [
    'dame', 'muéstrame', 'pásame', 'dime', 'indícame', 'consúltame',
    'tráeme', 'búscame', 'déjame ver', 'quiero ver', 'necesito saber',
    'me puedes decir', 'me puedes pasar', 'me colaboras con',
    'averíguame', 'encuéntrame', 'necesito', 'quiero saber', 'quisiera ver',
    'me gustaría saber', 'tienes', 'conoces', 'sabes', 'me averiguas',
],
'C_COUNT': [
    'cuántos', 'cuántas', 'cuántos hay', 'el número de', 'el total de',
    'qué cantidad de', 'cuánto fue', 'con qué número contamos', 'el conteo de',
    'a cuántos llegamos', 'qué tan grave es', 'qué tantos',
],

# — operaciones concretas (chip/deep-link) —
'C_OP_CITE': [
    'citar a', 'convocar a', 'hacer venir a', 'llamar a', 'traer a',
    'requerir la presencia de', 'solicitar la presencia de',
    'pedir que se presente', 'necesitar que venga', 'programar una cita con',
    'agendar una reunión con', 'citar a reunión a', 'mandar a llamar a',
    'que se acerque', 'que pase por coordinación', 'que asista a la cita',
    'hacer una citación a', 'citar formalmente a', 'pedir audiencia con',
    'que se presente en rectoría', 'convocar formalmente a',
],
'C_OP_PERMISSION': [
    'generar un permiso para', 'hacer un permiso a', 'autorizar la salida de',
    'sacar un permiso para', 'dar salida a', 'autorizar que salga',
    'dejar salir a', 'darle permiso a', 'aprobar la salida de',
    'firmar el permiso de', 'expedir un permiso para', 'permisar a',
    'autorizar que se vaya', 'darle salida a',
],
'C_OP_REPORT': [
    'reportar un incidente de', 'reportar lo que pasó con',
    'dejar constancia de', 'registrar lo ocurrido con', 'documentar',
    'levantar un reporte sobre', 'anotar el incidente de', 'reportar',
    'hacer un reporte sobre', 'dejar el reporte de',
],
'C_OP_TRACK': [
    'abrir un seguimiento a', 'iniciar un seguimiento de', 'empezar seguimiento a',
    'poner en seguimiento a', 'darle seguimiento a', 'crear un caso para',
    'remitir a convivencia a', 'pasar el caso de', 'derivar a psicología a',
    'escalar el caso de',
],

# — tiempo —
'C_TIME': [
    'hoy', 'ayer', 'anteayer', 'esta semana', 'la semana pasada',
    'este mes', 'el mes pasado', 'últimos {days} días', 'en {month}',
    'en la jornada de hoy', 'en la mañana', 'en la tarde', 'esta jornada',
    'durante la semana', 'en lo que va de mes', 'del día', 'del día de hoy',
    'el día de ayer', 'estos últimos días', 'recientemente', 'últimamente',
    'el otro día', 'esta mañana',
],

# — ruido/cortesía (§10 E/F) —
'C_NOISE_PRE': [
    'hola, ', 'buenas, ', 'buenos días, ', 'buenas tardes, ', 'hey, ',
    'oye, ', 'oiga, ', 'mira, ', 'disculpa, ', 'perdón, ', 'nexus, ',
    'nexo, ', 'profe, ', 'listo, ', 'vale, ', 'bueno, ', 'oiga profe, ',
    'señor, ', 'señora, ', 'disculpe, ', 'perdone, ',
],
'C_NOISE_MID': [
    'por favor, ', 'si es tan amable, ', 'si me colaboras, ',
    'si no es molestia, ', 'cuando puedas, ', 'de casualidad, ',
    'a ver si puedes, ', 'rapidito, ', 'de una vez, ',
],
'C_NOISE_POST': [
    ' por favor', ' porfa', ' por fis', ' gracias', ' muchas gracias',
    ' te lo agradezco', ' mil gracias', ' de una vez', ' rapidito',
    ' ¿me ayudas?', ' ¿sí?', ' ¿puedes?', ' si me ayudas', ' ¿vale?',
    ' gracias de antemano', ' porfa gracias',
],
'C_SCENARIO': [
    # escenarios reales que envuelven la petición (§11) — texto que rodea
    # la intención sin cambiarla: justificación, contexto, emoción
    'es que mañana tengo reunión y ', 'te cuento que ', 'resulta que ',
    'mira que ', 'fíjate que ', 'lo que pasa es que ', 'anda en una vuelta y ',
    'me están pidiendo que ', 'estoy ocupado y ', 'me urge porque ',
    'es para un informe, entonces ', 'estoy haciendo el reporte, ',
    'antes de la reunión de hoy ', 'para el boletín necesito que ',
],
'C_CORRECTION': [
    'no, espera, ', 'no, mejor ', 'perdón, quise decir ',
    'me corrijo, ', 'no no, ', 'espera espera, ', 'digamos mejor ',
],

# — marcadores conversacionales —
'C_FOLLOWUP': [
    'y ', '¿y ', 'ahora ', 'también ', 'además ', 'otra cosa, ',
    'y ya que estamos, ', 'aprovecho y ', 'por cierto, ',
],
}

# ══════════════════════════════════════════════════════════════════════════
# ESPECIFICACIÓN FORMAL POR INTENT (§5)
# ══════════════════════════════════════════════════════════════════════════

SEMANTIC_SPECS = {

'student_field': {
    'goal': 'obtener un dato puntual (campo) de un estudiante concreto',
    'action': 'retrieve', 'source_entity': 'student',
    'target_entity': 'student_or_guardian',
    'possible_fields': ['documento','telefono','grupo','grado','jornada',
        'edad','cumpleanos','acudiente','contacto','direccion','correo',
        'nombre','datos','ficha','perfil'],
    'required_entities': ['student'], 'optional_entities': ['group','field'],
    'context_deps': ['student','person','field'],
    'disambiguation': [
        'campo del ACUDIENTE (documento/teléfono de su acudiente) sigue siendo '
        'student_field — el _ref marca el target, no otro intent',
        '«quién es su acudiente» = field acudiente, no staff_lookup',
        '«citar al acudiente» NO es retrieve — es operate (derive_action)',
    ],
    'nearby_intents': ['staff_lookup','derive_action','student_summary',
                       'random_student'],
    'negative_examples': [
        'quiero citar a su acudiente', 'cuántos acudientes vinieron',
        'el documento del profesor',
    ],
    'concepts': {'target': 'C_GUARDIAN', 'field': 'C_DOC|C_PHONE|C_NAME',
                 'entity': 'C_STUDENT'},
},

'student_summary': {
    'goal': 'ficha/resumen completo de un estudiante (estado general)',
    'action': 'summarize', 'source_entity': 'student',
    'target_entity': 'student',
    'possible_fields': [],
    'required_entities': ['student'], 'optional_entities': ['group','days'],
    'context_deps': ['student','person'],
    'disambiguation': [
        'sin campo pedido → resumen; con campo → student_field',
        '«qué pasó con él» → resumen del referente, no auditoría',
    ],
    'nearby_intents': ['student_field','count_events','audit_query'],
    'negative_examples': ['el documento de X', 'cuántas faltas tiene X'],
    'concepts': {'entity': 'C_STUDENT'},
},

'derive_action': {
    'goal': 'el usuario quiere INICIAR una operación concreta sobre una '
            'entidad — el chat deriva con chip/deep-link (nunca ejecuta)',
    'action': 'operate', 'source_entity': 'student_or_generic',
    'target_entity': 'operation_target',
    'possible_fields': [],
    'required_entities': [], 'optional_entities': ['student','group','field'],
    'context_deps': ['student','_op'],
    'disambiguation': [
        '«quiero citar al acudiente» = derive_action — verbo operativo '
        '+ entidad; no confundir con student_field («quién ES el acudiente»)',
        '«permiso de salida para X» es operación, no consulta de permisos',
        '«cuántos permisos hay» es consulta (permissions/count_events)',
    ],
    'nearby_intents': ['start_operation','student_field','permissions',
                       'citations','trackings'],
    'negative_examples': [
        '¿quién es el acudiente?', 'cuántas citaciones hubo',
        'los permisos pendientes',
    ],
    'concepts': {'op': 'C_OP_CITE|C_OP_PERMISSION|C_OP_REPORT|C_OP_TRACK',
                 'target': 'C_GUARDIAN|C_STUDENT', 'want': 'C_WANT_OP'},
},

'start_operation': {
    'goal': 'solicitud explícita de abrir un flujo operativo — sin entidad '
            'o con entidad vaga («quiero hacer una citación»)',
    'action': 'operate', 'source_entity': 'none',
    'target_entity': 'operation',
    'possible_fields': [],
    'required_entities': [], 'optional_entities': ['student'],
    'context_deps': ['_op'],
    'disambiguation': [
        'misma familia semántica que derive_action — el resolver decide '
        'start vs derive por especificidad del comando y entidad presente',
    ],
    'nearby_intents': ['derive_action'],
    'negative_examples': ['cuántas citaciones hay', 'quién es el acudiente'],
    'concepts': {'op': 'C_OP_CITE|C_OP_PERMISSION|C_OP_REPORT|C_OP_TRACK',
                 'want': 'C_WANT_OP'},
},

'count_events': {
    'goal': 'cuántos eventos de un tipo ocurrieron en un periodo '
            '(métrica cuantitativa)',
    'action': 'count', 'source_entity': 'student_or_group_or_school',
    'target_entity': 'events',
    'possible_fields': ['module'],
    'required_entities': [], 'optional_entities': ['student','group',
                                                   'module','days','from'],
    'context_deps': ['student','group','module','days'],
    'disambiguation': [
        '«cuántas faltas hoy» SIN módulo explícito puede aclarar solo si '
        'no hay módulo activo en contexto',
        '«quiénes faltaron» es list_events, no count_events',
        '«cuántos presentes» es count_present (INGRESO), no count_events',
    ],
    'nearby_intents': ['list_events','count_present','attendance_today',
                       'late_today','students_count'],
    'negative_examples': ['quiénes faltaron hoy', 'el documento de X'],
    'concepts': {'module': 'C_ABSENCE|C_LATE|C_EVASION|C_PERMISSION|C_CITATION',
                 'time': 'C_TIME', 'count': 'C_COUNT'},
},

'list_events': {
    'goal': 'listar/registrar los eventos concretos (quiénes, cuáles)',
    'action': 'list', 'source_entity': 'student_or_group_or_school',
    'target_entity': 'events',
    'possible_fields': ['module'],
    'required_entities': [], 'optional_entities': ['student','group',
                                                   'module','days'],
    'context_deps': ['student','group','module','days','_result_set'],
    'disambiguation': [
        '«quiénes/cuáles/los que/muéstrame» → list; «cuántos» → count',
    ],
    'nearby_intents': ['count_events','attendance_today','top_offenders'],
    'negative_examples': ['cuántas faltas hay', 'el resumen del grupo'],
    'concepts': {'module': 'C_ABSENCE|C_LATE|C_EVASION', 'time': 'C_TIME'},
},

'count_present': {
    'goal': 'cuántos estudiantes ingresaron/asistieron (biometría INGRESO)',
    'action': 'count', 'source_entity': 'school_or_group',
    'target_entity': 'students_present',
    'possible_fields': [],
    'required_entities': [], 'optional_entities': ['group','days'],
    'context_deps': ['group','days'],
    'disambiguation': [
        '«llegaron tarde» es LATE (late_today/count_events), no presente',
        '«matriculados en total» es students_count, no presentes del día',
    ],
    'nearby_intents': ['students_count','count_events','attendance_today'],
    'negative_examples': ['cuántos llegaron tarde', 'cuántos faltaron'],
    'concepts': {'time': 'C_TIME', 'count': 'C_COUNT'},
},

'attendance_today': {
    'goal': 'pulso del día: quiénes faltan/vinieron hoy (pregunta del día)',
    'action': 'list_or_count', 'source_entity': 'school',
    'target_entity': 'attendance_day',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['group'],
    'context_deps': ['group'],
    'disambiguation': [
        'con marcador histórico (este mes/ayer) → count_events/list_events',
        '«quiénes faltan hoy» = pulso del día (asistencia), no histórico',
    ],
    'nearby_intents': ['count_present','list_events','day_summary'],
    'negative_examples': ['inasistencias del mes pasado'],
    'concepts': {'module': 'C_ABSENCE', 'time': 'C_TIME'},
},

'late_today': {
    'goal': 'tardanzas del día (pulso)', 'action': 'list_or_count',
    'source_entity': 'school_or_group', 'target_entity': 'late_day',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['group'],
    'context_deps': ['group'],
    'disambiguation': ['histórico de tardanzas → count_events con LATE'],
    'nearby_intents': ['count_events','attendance_today'],
    'negative_examples': ['tardanzas del mes'],
    'concepts': {'module': 'C_LATE', 'time': 'C_TIME'},
},

'students_in_group': {
    'goal': 'quiénes están en un grupo concreto (lista nominal)',
    'action': 'list', 'source_entity': 'group', 'target_entity': 'students',
    'possible_fields': [], 'required_entities': ['group'],
    'optional_entities': [],
    'context_deps': ['group','_result_set'],
    'disambiguation': [
        '«cuántos estudiantes hay en el X» → group_student_count, no lista',
    ],
    'nearby_intents': ['group_student_count','group_summary','groups_list'],
    'negative_examples': ['cuántos hay en el 8A', 'cómo va el 8A'],
    'concepts': {'group': 'C_GROUP'},
},

'group_student_count': {
    'goal': 'cuántos estudiantes tiene un grupo', 'action': 'count',
    'source_entity': 'group', 'target_entity': 'students',
    'possible_fields': [], 'required_entities': ['group'],
    'optional_entities': [], 'context_deps': ['group'],
    'disambiguation': ['«cuántos en total» con grupo activo → este intent'],
    'nearby_intents': ['students_count','students_in_group'],
    'negative_examples': ['quiénes son los del 8A', 'cuántos hay en el colegio'],
    'concepts': {'group': 'C_GROUP', 'count': 'C_COUNT'},
},

'students_count': {
    'goal': 'total de estudiantes del colegio/matriculados', 'action': 'count',
    'source_entity': 'school', 'target_entity': 'students',
    'possible_fields': [], 'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': ['con grupo → group_student_count; siempre colegio'],
    'nearby_intents': ['group_student_count','count_present'],
    'negative_examples': ['cuántos del 8A', 'cuántos vinieron hoy'],
    'concepts': {'school': 'C_SCHOOL', 'count': 'C_COUNT'},
},

'group_summary': {
    'goal': 'estado general de un grupo (asistencia, conducta, pulso)',
    'action': 'summarize', 'source_entity': 'group', 'target_entity': 'group',
    'possible_fields': [], 'required_entities': ['group'],
    'optional_entities': ['days'],
    'context_deps': ['group'],
    'disambiguation': ['«quiénes son» → students_in_group, no resumen'],
    'nearby_intents': ['students_in_group','group_student_count','day_summary'],
    'negative_examples': ['los estudiantes del 8A', 'cuántos hay en el 8A'],
    'concepts': {'group': 'C_GROUP'},
},

'day_summary': {
    'goal': 'resumen del día institucional (todas las métricas)',
    'action': 'summarize', 'source_entity': 'school',
    'target_entity': 'day', 'possible_fields': [],
    'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': ['métrica específica → su intent dedicado'],
    'nearby_intents': ['attendance_today','count_present','session_summary'],
    'negative_examples': ['cuántos presentes hay'],
    'concepts': {},
},

'permissions': {
    'goal': 'consultar permisos/excusas registradas (lista o estado)',
    'action': 'list', 'source_entity': 'student_or_school',
    'target_entity': 'permissions',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['student','days'],
    'context_deps': ['student','days'],
    'disambiguation': [
        '«genera un permiso» = derive_action; «permisos activos» = consulta',
        '«permiso de salida para X» = derive_action (solicitud nueva)',
    ],
    'nearby_intents': ['derive_action','count_events','pending_returns'],
    'negative_examples': ['quiero un permiso para X', 'cuántos permisos hubo'],
    'concepts': {'module': 'C_PERMISSION'},
},

'citations': {
    'goal': 'consultar citaciones registradas',
    'action': 'list', 'source_entity': 'student_or_school',
    'target_entity': 'citations',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['student','days'],
    'context_deps': ['student','days'],
    'disambiguation': ['«quiero citar a X» = derive_action, no consulta'],
    'nearby_intents': ['derive_action','permissions','trackings'],
    'negative_examples': ['citar a la mamá de X'],
    'concepts': {'module': 'C_CITATION'},
},

'trackings': {
    'goal': 'casos de seguimiento/convivencia (lista o estado)',
    'action': 'list', 'source_entity': 'student_or_school',
    'target_entity': 'trackings',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['student','days'],
    'context_deps': ['student','days'],
    'disambiguation': ['«abrir un seguimiento» = derive_action'],
    'nearby_intents': ['count_trackings','derive_action'],
    'negative_examples': ['quiero abrir un seguimiento'],
    'concepts': {'module': 'C_TRACKING'},
},

'staff_lookup': {
    'goal': 'identificar al personal (docente/coordinador) de un grupo o '
            'estudiante — datos del STAFF, no del estudiante',
    'action': 'retrieve', 'source_entity': 'student_or_group',
    'target_entity': 'staff',
    'possible_fields': ['nombre','telefono','correo'],
    'required_entities': [], 'optional_entities': ['student','group'],
    'context_deps': ['student','group'],
    'disambiguation': [
        '«quién es su docente» → staff; «quién es su acudiente» → student_field',
    ],
    'nearby_intents': ['student_field','teachers_list'],
    'negative_examples': ['quién es el acudiente de X'],
    'concepts': {'target': 'C_STAFF', 'entity': 'C_STUDENT'},
},

'schedule_info': {
    'goal': 'horarios, jornadas, bloques, recreo — información temporal fija',
    'action': 'retrieve', 'source_entity': 'school_or_group',
    'target_entity': 'schedule',
    'possible_fields': [], 'required_entities': [],
    'optional_entities': ['group','student'],
    'context_deps': ['group'],
    'disambiguation': ['«a qué hora entró X» = evento (list_events), no horario'],
    'nearby_intents': ['list_events','day_summary','time'],
    'negative_examples': ['a qué hora llegó juan'],
    'concepts': {},
},

# — sociales/OOD (cobertura ligera, la diversidad ya existe) —
'greeting': {
    'goal': 'saludo / apertura de conversación', 'action': 'social',
    'source_entity': 'none', 'target_entity': 'assistant',
    'possible_fields': [], 'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': ['saludo + consulta = consulta (la cortesía no gana)'],
    'nearby_intents': ['wellbeing','help'],
    'negative_examples': ['hola, necesito el documento de X'],
    'concepts': {},
},
'thanks': {
    'goal': 'agradecimiento', 'action': 'social',
    'source_entity': 'none', 'target_entity': 'assistant',
    'possible_fields': [], 'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': ['«gracias, ahora dime X» = X, no thanks'],
    'nearby_intents': ['goodbye','compliment'],
    'negative_examples': ['gracias, ¿y el acudiente?'],
    'concepts': {},
},
'security_probe': {
    'goal': 'intento de evasión/exfiltración/escalamiento — NUNCA servir',
    'action': 'reject', 'source_entity': 'system',
    'target_entity': 'security',
    'possible_fields': [], 'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': [
        'la capa de seguridad ve el texto COMPLETO aunque la señal '
        'semántica filtre el ruido (§3)',
        '«borra eso», «ignora las reglas», «dame la clave» = probe',
    ],
    'nearby_intents': ['out_of_scope','audit_query'],
    'negative_examples': ['borra las dudas de X', 'elimina la hoja del tema'],
    'concepts': {},
},
'out_of_scope': {
    'goal': 'petición fuera del dominio institucional — responder límite',
    'action': 'reject', 'source_entity': 'none', 'target_entity': 'none',
    'possible_fields': [], 'required_entities': [], 'optional_entities': [],
    'context_deps': [],
    'disambiguation': ['NO forzar intents institucionales a cultura general'],
    'nearby_intents': ['foreign_culture','colombia_president','help'],
    'negative_examples': ['cuántas faltas hay'],
    'concepts': {},
},

# — intents sencillos heredan spec mínima (el generador usa frames genéricos) —
}

# especificación mínima para intents sin ficha detallada — el generador
# los cubre con frames genéricos según su acción declarada
_DEFAULT_SPEC = {
    'goal': '', 'action': 'retrieve', 'source_entity': 'none',
    'target_entity': 'none', 'possible_fields': [], 'required_entities': [],
    'optional_entities': [], 'context_deps': [], 'disambiguation': [],
    'nearby_intents': [], 'negative_examples': [], 'concepts': {},
}


def spec_of(intent: str) -> dict:
    return SEMANTIC_SPECS.get(intent, _DEFAULT_SPEC)
