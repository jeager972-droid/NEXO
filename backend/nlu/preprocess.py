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
    return re.sub(r'\s+', ' ', t).strip()


_STOP = {'grupo','salon','colegio','escuela','jornada','hoy','ayer','semana',
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
         'grados','grado','jornadas','turno','bano','banos','permiso','permisos',
         'faltas','falta','ausencias','ausencia','fugas','fuga','casos','emergencia',
         'emergencias','panico','sos','seguimiento','datos','informacion','ficha',
         'perfil','resumen','estado','edad','cumpleanos','contacto','telefono',
         'documento','cedula','identificacion','whatsapp','celular','numero',
         'acudiente','acudientes','responsable','familiar','papa','mama','padre','madre'}

_BOUNDARY = r'(?:\s+(?:del|de|en|grupo|salon|durante|en los|en las|hoy|ayer|esta|ultimos|en el|por|que|y)\b|$)'
_STUDENT_PATS = [
    r'(?=(?:estudiante|alumno|alumna|niño|niña)\s+([a-z]+(?:\s+[a-z]+){0,3})' + _BOUNDARY + r')',
    r'(?=\b(?:de|del|sobre|para|a|tenido|tuvo|tiene|tienen|sido|hizo|estado|estuvo|hecho)\s+([a-z]+(?:\s+[a-z]+){0,3})' + _BOUNDARY + r')',
]


def extract_entities(q: str) -> dict:
    e = {}
    m = re.search(r'ultimos? (\d+) dias?|en (?:los )?(\d+) dias?|(\d+) dias? atras', q)
    if m:
        e['days'] = int(next(g for g in m.groups() if g))
    elif re.search(r'(\d+) semanas?', q):
        e['days'] = int(re.search(r'(\d+) semanas?', q).group(1)) * 7
    elif re.search(r'\bhoy\b|este dia|dia de hoy', q):
        e['days'] = 0
    elif re.search(r'\bayer\b', q):
        e['days'] = 1
    elif re.search(r'esta semana|de la semana|en la semana', q):
        e['days'] = 7
    elif re.search(r'este mes|del mes|en el mes|ultimo mes', q):
        e['days'] = 30

    m = re.search(r'\b(?:grupo|salon|del|de|en)\s+(\d{1,2}\s?[a-z]|\d{1,2}-\d{1,2}|\d{1,2}\.\d{1,2}|prescolar|jardin|transicion|kinder)\b', q) \
        or re.search(r'\b(\d{1,2}[a-z]|\d{1,2}-\d{1,2}|\d{1,2}\.\d{1,2})\b', q) \
        or re.search(r'\b(\d{1,2}\s\d{1,2})\b', q)   # «11.2» → normalizado «11 2»
    if m:
        e['group'] = m.group(1).upper().replace(' ', '-').replace('.', '-')
    # ordinales: «octavo a», «onceavo b», «grado noveno», «11.2» ya cubierto
    _ORD = {'primero':'1','segundo':'2','tercero':'3','cuarto':'4','quinto':'5',
            'sexto':'6','septimo':'7','octavo':'8','noveno':'9','decimo':'10',
            'once':'11','onceavo':'11','undecimo':'11','onceavo':'11'}
    if 'group' not in e:
        mo = re.search(r'\b(' + '|'.join(_ORD) + r')\s*([a-j])\b', q) \
             or re.search(r'\b(?:grado|grupo|salon)\s+(' + '|'.join(_ORD) + r')\b', q)
        if mo:
            num = _ORD[mo.group(1)]
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
    for pat in _STUDENT_PATS:
        for m in re.finditer(pat, q):
            words = [w for w in m.group(1).split() if w not in _STOP and len(w) > 1]
            if words:
                cands.append(' '.join(words))
    if cands:
        e['student'] = cands[-1]

    m = re.search(r'mas de (\d+)|al menos (\d+)|(\d+) veces|(\d+) repeticiones', q)
    if m:
        e['threshold'] = int(next(g for g in m.groups() if g))
    return e


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
    if e.get('group'):
        src = e.get('_group_src') or e['group'].lower()
        masked = re.sub(r'\b' + re.escape(src) + r'\b', ' grupo_ent ', masked)
    masked = re.sub(r'\b\d+\b', ' num_ent ', masked)
    _numw = ('un|uno|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|'
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
