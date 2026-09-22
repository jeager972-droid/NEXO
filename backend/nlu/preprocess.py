"""
preprocess.py — Normalización + extracción de entidades + enmascarado.

Compartido entre train.py y service.py para que el modelo vea en
entrenamiento EXACTAMENTE lo mismo que verá en producción:

  texto → normalize → extract_entities (NER/slots) → mask_entities

El enmascarado reemplaza los spans de entidades por tokens fijos
(<EST>, <GRP>, <NUM>) — así el clasificador aprende la ESTRUCTURA de la frase
("cuantas evasiones tuvo <EST> del <GRP>") y no memoriza nombres concretos.
"""

import re
import unicodedata

# Regiones colombianas (departamentos) — se enmascaran como 'region_ent' y NO
# son estudiantes: "capital de Antioquia" es cultura general, no dato escolar.
_DEPTS = sorted([
    'colombia','bogota','medellin','cali','barranquilla','cartagena',
    'bucaramanga','pereira','manizales','armenia','ibague','neiva',
    'villavicencio','villavo','cucuta','pasto','santa marta','valledupar',
    'monteria','sincelejo','riohacha','yopal','arauca','mocoa','florencia',
    'leticia','quibdo','inirida','mitu','puerto carreno','tunja','popayan',
    'san andres','guatavita','villa de leyva','mompox','palenque',
    'san jose del guaviare','amazonas','antioquia','atlantico','bolivar','boyaca','caldas',
    'caqueta','casanare','cauca','cesar','choco','cordoba','cundinamarca',
    'guainia','guaviare','huila','la guajira','guajira','magdalena','meta',
    'narino','norte de santander','putumayo','quindio','risaralda',
    'san andres y providencia','san andres','santander','sucre','tolima',
    'valle del cauca','valle','vaupes','vichada',
], key=len, reverse=True)

# Países/regiones extranjeras → 'extranjero_ent' (dispara speech patrio)
_FOREIGN = sorted([
    'francia','japon','alemania','italia','espana','estados unidos','mexico',
    'argentina','brasil','chile','peru','venezuela','ecuador','bolivia',
    'paraguay','uruguay','inglaterra','portugal','canada','china','rusia',
    'corea','india','australia','holanda','suiza','belgica','suecia','noruega',
    'dinamarca','finlandia','irlanda','polonia','turquia','israel','egipto',
    'arabia saudita','emiratos','qatar','japon','grecia','roma','cartago',
    'europa','asia','africa','oceania','antartida','artico','latinoamerica',
    'everest','nilo','sahara','himalaya','vaticano','marte','venus','luna',
    'jupiter','saturno','galaxia','universo',
], key=len, reverse=True)


def find_regions(q: str):
    """Departamentos/países presentes en el texto (coincidencia más larga)."""
    found = []
    for name in _DEPTS + _FOREIGN:
        if re.search(r'\b' + re.escape(name) + r'\b', q):
            found.append(name)
    return found


def normalize(t: str) -> str:
    t = t.lower().strip()
    t = unicodedata.normalize('NFD', t)
    t = ''.join(c for c in t if unicodedata.category(c) != 'Mn')
    t = re.sub(r'[¿?¡!.,;:\(\)"\'«»]', ' ', t)
    _fix = {'presnetes':'presentes','presntes':'presentes','precentes':'presentes',
        'asistensia':'asistencia','asisitencia':'asistencia','inasitencia':'inasistencia',
        'inasistensia':'inasistencia','inasistencais':'inasistencias','estudaintes':'estudiantes',
        'alumons':'alumnos','tardansas':'tardanzas','evacione':'evasion','evasioness':'evasiones',
        'permisso':'permiso','documneto':'documento','docuemnto':'documento','ceduala':'cedula',
        'acudinte':'acudiente','acudietne':'acudiente','citasion':'citacion','segumiento':'seguimiento'}
    t = ' ' + t + ' '
    for _k, _v in _fix.items():
        t = t.replace(' ' + _k + ' ', ' ' + _v + ' ')
    return re.sub(r'\s+', ' ', t).strip()


_STOP = {'filosofia','literatura','politica','geografia','historia','quimica',
    'biologia','astronomia','religion','matematicas','espanol','aleman','etica',
    'fisica','sena','resultado','resultados','partido','clima','tiempo',
    'temperatura','pronostico','chiste','chistes','reporto','reportaste','reportamos','aplican','aplica','excepto','salvo','grupo','salon','colegio','escuela','jornada','hoy','ayer','semana',
         'mes','ano','dias','dia','el','la','los','las','un','una','este','esta',
         'esto','eso','mi','tu','su','mis','tus','sus','que','cual','cuales',
         'cuanto','cuanta','cuantos','cuantas','dime','dame','muestrame',
         'ver','hay','tiene','tienen','tenido','tuvo','fue','son','ser','nexus','nexo',
         'favor','porfa','porfavor','acudiente','acudientes','papa','mama',
         'padre','madre','documento','cedula','celular','telefono','whatsapp',
         'numero','contacto','datos','informacion','edad','nacimiento',
         'permiso','permisos','seguimiento','citacion','caso','incidente',
         'estudiantes','estudiante','alumnos','alumnas','alumno','alumna',
         'ninos','ninas','docentes','docente','profesores','profesor','profesora',
         # pronombres y cortesía — nunca son nombres de estudiante
         'ti','mi','vos','usted','ustedes','ellos','ellas','nosotros','gracias',
         'muchas','muchisimas','mil','bendiciones','amable','senor','senora',
         'quien','quienes','responde','cargo','figura','registrada','registrado',
         'aparece','responsable','responsabilidad','volvamos','vuelve','volver',
         'porfavor','porfa','favor','puedes','puedo','por','si','ok','vale','dale',
         # vocabulario del dominio — sustantivos del sistema, no personas
         'sensores','sensor','dispositivos','dispositivo','nodos','nodo',
         'institucion','sistema','app','aplicacion','huella','lectores','lector',
         'biometricos','biometrico','horario','horarios','riesgo','alertas','alerta',
         'seguimientos','auditoria','notificaciones','registros','historial',
         'reporte','reportes','excel','inasistencias','evasiones','tardanzas',
         'citaciones','mensajes','padres','incidentes','danos','salidas','entradas',
         'ingresos','ausentes','presentes','matriculados','grupos','salones',
         # cultura general — nunca son estudiantes (previenen fuga a formal)
         'arepas','arepa','pizza','pasta','gripe','dolor','vida','valores',
         'bitcoin','criptomonedas','elefante','fotosintesis','ingles','frances',
         'netflix','anime','fortnite','trucos','champions','horoscopo',
         'receta','recetas','medicina','medicinas','remedio','remedios',
         'bolsa','series','peliculas','musica','cancion','zodiacal',
         'vitaminas','diabetes','acciones','inversiones','dolar','euro',
         'noticias','lluvia','cuento','poesia','poema','libro','futbol',
         'programacion','python','javascript','codigo','idioma','idiomas',
         'capital','capitales','presidente','presidentes','departamento',
         'departamentos','region','regiones','municipio','municipios',
         'cordillera','oceano','oceanos','continente','continentes','planeta',
         'formacion','formaciones','consejeria','coordinacion','salida',
         'papas','banio','banos','ultimos','ultimo','timbre','cancha','tienda',
         'cobija','fuga','pinta','pintas','puente','libro','materia','clase',
         'clases','leccion','lecciones','recreo','descanso','alonso',
         'primero','segundo','tercero','cuarto','quinto','sexto','septimo',
         'octavo','noveno','decimo','once','onceavo','undecimo',
         'aleatorio','aleatoria','cualquiera','azar','random',
         'un','uno','una','dos','tres','cuatro','cinco','seis','siete','ocho',
         'nueve','diez','doce','trece','catorce','quince','veinte','treinta',
         'cuarenta','cincuenta','sesenta','setenta','ochenta','noventa','cien',
         'ciento','mil','millon','partido','resultado','noticias','mitad',
         'doble','triple','porciento','porcentaje','raiz','seno','coseno',
         'tangente','logaritmo','factorial','regla','area','volumen','base',
         'altura','lado','radio','catetos','hipotenusa','pitagoras','grado',
         'llover','llueve','llovio','nevando','truena','graniza','soleado',
         'nublado','lluvioso','caluroso','fresco','templado',
         'tarde','temprano','presente','justificado','puntual','ausente',
         'dorado','leyenda','leyendas','mito','mitos','leyendario',
         # referencias temporales/posicionales — nunca nombres de estudiante
         'pasado','pasada','pasados','pasadas','anterior','anteriores',
         'proximo','proxima','proximos','proximas','siguiente','siguientes',
         'actual','actuales','reciente','recientes','vigente','venidero',
         'venidera','entrante','corriente',
         # conectores/demostrativos/temporales sueltos — paridad PHP
         'del','de','manana','mismo','misma','mismos','mismas',
         'ese','esa','esos','esas','otro','otra','propio','propia',
         'aquel','aquella','aquellos','aquellas','tambien',
         'aula','aulas','veces','vez',
         'ella','ellos','ellas','usted','ustedes',
         'ahora','ahorita','y','e','ni','o','u','pero','sino','ademas',
         'luego','entonces','asi','aun','ya','muy','mas','menos','tan',
         'tanto','cada','todo','toda','todos','todas','varios','varias',
         'algunos','algunas','ningun','ninguna','cualquier','apenas',
         'info','para','con','sobre','hacia','segun','entre','sin','ante',
         'bajo','desde','hasta','tras','via','pro','suyo',
         'suya','tuyo','tuya','nuestro','nuestra','propio','propia','solicitud','solicitudes',
         'solo','solamente','unicamente','especificamente','concretamente',
         'abierto','abierta','abiertos','cerrado','cerrada','pendiente','pendientes',
         'activo','activa','activos','vigente','vigentes','anterior','anteriores',
         'reciente','recientes','nuevo','nueva',
         'matematicas','ingles','espanol','ciencias','sociales','fisica','quimica',
         'biologia','historia','geografia','arte','musica','religion','etica',
         'informatica','lectura','escritura','coordinador','coordinadores',
         'docente','docentes','profesor','profesores','maestro','maestros',
         'personal','rector','rectores','secretaria','secretarias','directivo',
         'exactamente','precisamente','respectivamente','personalmente',
         'excusa','medica','medico','durante','tiempo','sistemas','mejora','seguridad','conducta','nino','nina','academico','academica','transferida','transferido','natacion','autorizada','autorizado','autorizados','autorizadas','bimestre','preescolar','en','falto','jornada','estado','grupo','estudiante','estudiantes','alumno','alumnos','proceso','procesos','area','nivel','registrada','registrado','registrados','entrada','entradas','salida','salidas','anticipada','anticipado','temprana','temprano','tardia','tardio','alerta','alertas','tarea','tareas','caso','casos','incidencia','incidencias','evento','eventos','fuga','fugas','lector','lectores','piso','pisos','recreo','descanso','observacion','presente','presentes','ausente','ausentes','vinieron','llego','llegaron','entro','entraron','presento','presentaron','regreso','regresaron','acumulada','acumuladas','acumulado','acumulados','marcada','marcado','marcados','marcaron','resuelto','resueltos','resuelta','resueltas','bloque','bloques','materia','materias','asignatura','asignaturas','area','areas','completado','autorizo','autorizaron','faltaron','impuntual','impuntuales','registrar','registren','detectada','detectadas','detectado','detectados','detectaron','reportada','reportadas','reportado','reportados','reportaron','llamado','llamada','llamar','llamen','citado','citada','convocar','convocado','convocada','reunion','reuniones','peticion','peticiones','padres','padre','madre','mama','papa','abuela','abuelo','tia','tio','hermano','hermana','amigo','amiga','vecino','vecina','nadie','alguien','alguno','alguna','algunos','algunas','ninguno','ninguna','ningunos','ningunas','cualquiera','quienquiera','cuyo','cuya','lejos','cerca','arriba','abajo','dentro','fuera','encima','debajo','delante','detras','alrededor','junto','juntos','juntas','aparte','incluso','volaron','volar','escaparon','escapar','caparon','capar','volaron','voló','volo',
         'existimos','vivimos','nacimos','estamos','somos','fueron','somos',
         'siento','sientes','siente','tengo','tienes','quiero','quieres',
         'puedo','puedes','pueden','haces','hago','hacen','estoy','andan',
         'voy','vas','van','digo','dices','dicen','era','eran','sera','seran',
         'fui','fueron','hubo','habia','habran','hay','eres','sois','ser',
         'grados','grado','jornadas','turno','bano','banos','permiso','permisos',
         'faltas','falta','ausencias','ausencia','fugas','fuga','casos','emergencia',
         'emergencias','panico','sos','seguimiento','datos','informacion','ficha',
         'perfil','resumen','estado','edad','cumpleanos','contacto','telefono',
         'documento','cedula','identificacion','whatsapp','celular','numero',
         'acudiente','acudientes','responsable','familiar','papa','mama','padre','madre',
         # colectivos genéricos — «chicos del 7-B» es el grupo, no una persona
         'chico','chicos','chica','chicas','muchacho','muchachos','muchacha','muchachas',
         'pelado','pelados','pelada','peladas','menor','menores','chino','chinos','china','chinas',
         # imperativos/verbos de petición — «necesito los matriculados» no es persona
         'tardanza','inasistencia','evasion','ausencia','falta','permiso',
         'citacion','familia','familiar','pariente','parientes',
         # adjetivos de estado del estudiante — «alumnos exentos» no es persona
         'exento','exentos','exenta','exentas','eximido','eximidos','dispensado',
         'dispensados','presente','presentes','ausente','ausentes','tarde','puntual',
         'impuntual','impuntuales','asignado','asignada','asignados','asignadas',
         'huerfano','huerfanos','libre','libres','enrolado','enrolados','activo',
         'activa','activos','inactivo','inactiva','retirado','retirada','graduado',
         'graduada','nuevo','nueva','antiguo','antigua','bajo','alto','media','medio',
         'critico','critica','vulnerable','vulnerables','derivado','derivados',
         'observado','observados','citado','citados','faltado','faltados',
         'ensename','dime','dame','muestrame','muéstrame','necesito','quiero','queria',
         'pasame','pásame','ver','mira','mirame','buscame','buscáme','listame','contame',
         'cuentame','decime','traeme','ponme','sacame','enseñame','liste','muéstrese',
         # copulativos y sustantivos de colección/presentación — nunca personas
         'es','sea','sean','fuese','estando','siendo',
         'lista','listas','fila','filas','columna','columnas','tabla','tablas',
         'nomina','nominas','nombre','nombres','listado','listados','posicion','posiciones',
         'se','me','te','nos','lo','le','les','coordi','rectoria',
         'ahi','alli','aca','alla',
         'puesto','puestos','lugar','lugares','ranking','top','completo',
         'completa','completos','completas','ordenado','ordenada','ordenados',
         'ordenadas','orden','alfabeticamente','alfabetico','alfabetica',
         'entero','entera','integro','integra','porcentaje','porcentajes'}

_BOUNDARY = r'(?:\s+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y)\b|$)'
_STUDENT_PATS = [
    # marcador de persona explícito — «la niña camila», «el muchacho juan»:
    # el nombre sigue al sustantivo, no al conector (paridad PHP)
    r'(?=(?:estudiante|alumno|alumna|niño|niña|muchacho|muchacha|pelado|pelada|chico|chica|menor)\s+([a-z]+(?:\s+[a-z]+){0,3})' + _BOUNDARY + r')',
    r'(?=\b(?:de|del|sobre|para|(?<![-\d])a|solo|solamente|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho|falto|faltaron|llego|entro|salio|capo|volo|evadio|evadieron|caparon|volaron|volado|capado)\s+([a-z]+(?:\s+[a-z]+){0,3})' + _BOUNDARY + r')',
    # «camila del septimo», «juan del 8a» — nombre + conector + grado
    r'\b([a-z]{2,}(?:\s+[a-z]+){0,2})\s+(?:del|de)\s+(?:el |la )?(?:primero|segundo|tercero|cuarto|quinto|sexto|septimo|octavo|noveno|decimo|once|undecimo|jardin|kinder|transicion|prescolar|\d)',
]


def extract_entities(q: str) -> dict:
    e = {}
    # Períodos pasados específicos PRIMERO — «del mes pasado» no es «del mes»
    if re.search(r'mes pasado|mes anterior', q):
        e['days'] = 60
    elif re.search(r'semana pasada|semana anterior', q):
        e['days'] = 14
    elif re.search(r'ano pasado|ano anterior', q):
        e['days'] = 365
    elif (m := re.search(r'ultimos? (\d+) dias?|en (?:los )?(\d+) dias?|(\d+) dias? atras', q)):
        e['days'] = int(next(g for g in m.groups() if g))
    elif re.search(r'(\d+) semanas?', q):
        e['days'] = int(re.search(r'(\d+) semanas?', q).group(1)) * 7
    elif re.search(r'\bhoy\b|este dia|dia de hoy', q):
        e['days'] = 0
    elif re.search(r'\bayer\b', q):
        e['days'] = 1
    elif re.search(r'esta semana|de la semana|en la semana', q):
        e['days'] = 7
    elif re.search(r'este mes|del mes|en el mes|ultimo mes|al mes|de este mes', q):
        e['days'] = 30

    m = re.search(r'\b(?:grupo|salon|del|de|en|al|el)\s+(\d{1,2}\s?[a-z]|\d{1,2}-\d{1,2}|\d{1,2}-[a-z]|\d{1,2}\.\d{1,2}|prescolar|jardin|transicion|kinder)\b', q) \
        or re.search(r'\b(\d{1,2}[a-z]|\d{1,2}-\d{1,2}|\d{1,2}-[a-z]|\d{1,2}\.\d{1,2})\b', q) \
        or re.search(r'\b(\d{1,2}\s\d{1,2})\b', q)   # «11.2» → normalizado «11 2»
    if m:
        e['group'] = m.group(1).upper().replace(' ', '-').replace('.', '-')
    # ordinales: «octavo a», «onceavo b», «grado noveno», «11.2» ya cubierto
    _ORD = {'primero':'1','primer':'1','segundo':'2','tercero':'3','tercer':'3','cuarto':'4','quinto':'5',
            'sexto':'6','septimo':'7','octavo':'8','noveno':'9','decimo':'10',
            'once':'11','onceavo':'11','undecimo':'11','onceavo':'11'}
    if 'group' not in e:
        _ORDL = ('primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|'
                 'quinto|quinta|sexto|sexta|septimo|septima|octavo|octava|'
                 'noveno|novena|decimo|decima|once|undecimo')  # «primera» = fem, no primer+A
        mo = re.search(r'\b(' + _ORDL + r')\s*([a-j])(?![a-z])', q) \
             or re.search(r'\b(?:grado|grupo|salon)\s+(' + '|'.join(_ORD) + r')\b', q) \
             or re.search(r'\b(?:del|de|los|las)\s+(primero|primera|segundo|segunda|tercero|tercera|cuarto|cuarta|quinto|quinta|primer|tercer)\b(?!\s+(?:de|del|en|a|por|para)\b)', q) \
             or re.search(r'\b(?:del|de|los|las)\s+(sexto|septimo|octavo|noveno|decimo|once|undecimo|sexta|septima|octava|novena|decima)\b', q) \
             or re.search(r'\b(?:en|el|al)\s+(sexto|septimo|octavo|noveno|decimo|once|undecimo)\b(?!\s+(?:de|del|en|a|por|para|lugar|puesto|posicion|dia|mes|semana|ano)\b)', q)
        if mo:
            _base = re.sub(r'a$','o',mo.group(1))  # primera→primero
            num = _ORD.get(_base, _ORD.get(mo.group(1)))
            letter = mo.group(2).upper() if mo.lastindex >= 2 and mo.group(2) else ''
            e['group'] = num + letter
            e['_group_src'] = mo.group(0)   # para enmascarar la forma ordinal
    # períodos nombrados que no son "días"
    if 'days' not in e:
        if re.search(r'mes pasado', q):
            e['days'] = 60  # el handler lo usa como rango amplio
        elif re.search(r'semana pasada|semana anterior', q):
            e['days'] = 14
        elif re.search(r'este ano|del ano|en el ano', q):
            e['days'] = 365

    cands = []
    _LEAD = {'el','la','los','las','un','una','del','de','al',
             'mismo','misma','mismos','mismas','estudiante','estudiantes',
             'alumno','alumna','alumnos','alumnas','nino','nina','niño','niña',
             'muchacho','muchacha','pelado','pelada','chico','chica','menor'}
    for pat in _STUDENT_PATS:
        for m in re.finditer(pat, q):
            raw = m.group(1).split()
            # saltar artículos/marcadores iniciales («el mismo juan»); el
            # primer término restante debe ser el nombre — si es stopword
            # («sobre LA física cuántica») el residuo no es persona (paridad PHP)
            lead = list(raw)
            while lead and lead[0] in _LEAD:
                lead.pop(0)
            if not lead or lead[0] in _STOP:
                continue
            words = [w for w in raw if w not in _STOP and len(w) > 1 and not re.search(r'\d', w)]
            if words:
                cands.append(' '.join(words))
    if cands:
        e['student'] = cands[-1]

    m = re.search(r'mas de (\d+)|al menos (\d+)|(\d+) veces|(\d+) repeticiones', q)
    if m:
        e['threshold'] = int(next(g for g in m.groups() if g))
    return e


# Nombres de rol → tokens semánticos. El clasificador aprende la RELACIÓN
# («acudiente_ent de estudiante_ent») no el vocabulario — así «el papá»,
# «el responsable», «quien figura como acudiente» convergen a la misma
# estructura y la generalización no depende de la palabra exacta (§13).
_ROLE_MASK = [
    ('acudiente_ent', [
        'padre de familia', 'padres de familia', 'quien responde por el',
        'quien responde por ella', 'quien lo representa', 'quien la representa',
        'persona a cargo', 'adulto responsable', 'tutor legal', 'tutor',
        'familiar registrado', 'contacto familiar', 'encargado del niño',
        'encargada del niño', 'encargado del estudiante', 'acudientes',
        'acudiente', 'representante', 'responsable', 'papas', 'papa',
        'mamas', 'mama', 'padre', 'madre', 'abuelo', 'abuela', 'tio', 'tia',
        'hermano mayor', 'hermana mayor',
    ]),
    ('personal_ent', [
        'psicoorientadora', 'psicoorientador', 'orientadora', 'orientador',
        'coordinadora', 'coordinador', 'rectora', 'rector', 'docentes',
        'docente', 'profesora', 'profesor', 'profe', 'secretaria',
        'secretario', 'portera', 'portero', 'vigilante', 'auxiliar',
        'personal', 'maestra', 'maestro',
    ]),
    ('colegio_ent', [
        'institucion educativa', 'institucion', 'colegio', 'plantel',
        'escuela', 'sede',
    ]),
]

# Referentes deícticos de estudiante → mismo token que un nombre propio.
# «ese alumno», «el muchacho», «la niña del caso» = estudiante_ent —
# el modelo ve el MISMO rol semántico con y sin nombre.
_DEICTIC_STUDENT = (
    r'\b(?:ese|esa|este|esta|el|la|los|las|un|una|otro|otra|mismo|misma|'
    r'del|de la|de los|de las|de un|de una|al)\s+'
    r'(?:estudiante|alumno|alumna|niño|niña|muchacho|muchacha|pelado|pelada|'
    r'chino|china|menor|estudiante ese|estudiante esa)\b')


def mask_entities(q: str, e: dict = None) -> str:
    """Reemplaza entidades por tokens fijos — el modelo aprende estructura."""
    if e is None:
        e = extract_entities(q)
    masked = q
    # regiones primero (un departamento/país NUNCA es estudiante)
    for r in e.get('_regions', []):
        token = 'extranjero_ent' if r in _FOREIGN else 'region_ent'
        masked = re.sub(r'\b' + re.escape(r) + r'\b', ' ' + token + ' ', masked)
    if e.get('student'):
        masked = masked.replace(e['student'], ' estudiante_ent ')
    # deícticos de estudiante → mismo token que el nombre
    masked = re.sub(_DEICTIC_STUDENT, ' estudiante_ent ', masked)
    # nombres de rol → token semántico (§13 sinónimos contextuales)
    for token, words in _ROLE_MASK:
        for w in sorted(words, key=len, reverse=True):
            masked = re.sub(r'\b' + re.escape(w) + r'\b', ' ' + token + ' ',
                            masked)
    if e.get('group'):
        src = e.get('_group_src') or e['group'].lower()
        masked = re.sub(r'\b' + re.escape(src) + r'\b', ' grupo_ent ', masked)
    masked = re.sub(r'\b\d+\b', ' num_ent ', masked)
    _numw = ('uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|'
             'once|doce|trece|catorce|quince|veinte|treinta|cuarenta|cincuenta|'
             'sesenta|setenta|ochenta|noventa|cien|ciento|mil|millon|millones')
    masked = re.sub(r'\b(' + _numw + r')\b', ' num_ent ', masked)
    return re.sub(r'\s+', ' ', masked).strip()


def preprocess(text: str):
    """Pipeline completo: devuelve (texto_enmascarado, entidades)."""
    q = normalize(text)
    e = extract_entities(q)
    regions = find_regions(q)
    if regions:
        e['_regions'] = regions
        e['topic'] = regions[0]
        # una región capturada como "student" es falso positivo → se retira
        if e.get('student') and any(r.startswith(e['student']) or e['student'].startswith(r)
                                    or e['student'] in r or r in e['student']
                                    for r in regions):
            del e['student']
    return mask_entities(q, e), e
