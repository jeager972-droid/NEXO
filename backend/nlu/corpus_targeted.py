"""
corpus_targeted.py — Familias lingüísticas dirigidas (Fase 3A).

Objetivo: cerrar fronteras concretas demostradas en la auditoría sin tocar
taxonomía, threshold ni arquitectura. Solo ADITIVO — se registra en CORPUS
al importarse; eliminar el archivo revierte todo.

Fronteras atacadas:
  1. Jerga/formal de evasión sin estudiante explícito.
  2. Patrón «cuántas X hubo/hay/se registraron» por métrica.
  3. name_meaning ↔ about_me (significado del nombre vs datos del usuario).
  4. love/compliment ↔ wellbeing_reply (afecto vs pregunta de estado).
"""

from corpus import CORPUS, _augment, _expand, STUDENTS, GROUPS, DAYS


def _add(intent_id, phrases, expand=False):
    if expand:
        phrases = _expand(phrases, student=STUDENTS, group=GROUPS, days=DAYS)
    CORPUS[intent_id].extend(_augment(phrases))


# ── 1. Jerga de evasión (sin {student} — contexto de grupo/día) ─────────────
# count_events: «cuántos se volaron/escaparon…» — preguntas de conteo.
_add('count_events', [
    "cuantos se volaron hoy", "cuantos se volaron ayer", "cuantos se volaron esta semana",
    "cuantos se escaparon hoy", "cuantos se escaparon del salon hoy",
    "cuantos se fueron de pinta hoy", "cuantos se caparon la clase hoy",
    "cuantos se tajaron hoy", "cuantos se fueron de clase sin permiso",
    "cuantos abandonaron el aula hoy", "cuantos abandonaron la clase hoy",
    "cuantos no ingresaron al aula despues del recreo",
    "cuantos salieron del aula sin autorizacion", "cuantos no volvieron del recreo",
    "cuantos no regresaron del descanso", "cuantos se quedaron por fuera de clase",
    "cuantas salidas sin permiso hubo", "cuantas fugas de clase hubo hoy",
    "cuantas evasiones se registraron hoy", "cuantas evasiones hubo ayer",
    "cuantas evasiones fueron esta semana", "cuantos casos de evasion hubo",
    "cuantas veces se volaron la clase esta semana",
    "cuantas salidas del aula sin permiso se reportaron",
    "en cuantas ocasiones se escaparon del salon",
    "fue mucha la gente que se volo hoy", "se volaron muchos hoy",
    "registraron muchas evasiones esta semana",
])
_add('count_events', [
    "cuantas veces se volo {student}", "cuantas veces se escapo {student}",
    "cuantas clases se salto {student}", "cuantas veces abandono el aula {student}",
    "cuantas veces no ingreso al aula {student}",
    "cuantas veces salio sin autorizacion {student}",
    "cuantas veces no volvio del recreo {student}",
    "cuantas evasiones registro {student} esta semana",
    "cuantas evasiones hubo del {group}", "cuantas evasiones tuvo el {group}",
    "cuantos del {group} se volaron hoy", "cuantos del {group} se escaparon ayer",
], expand=True)

# list_events: «muéstrame / dame la lista de los que…»
_add('list_events', [
    "muestrame los que se volaron hoy", "dame los que se escaparon",
    "lista de los que se fueron de pinta", "los que se caparon la clase",
    "quienes se volaron hoy", "quienes se escaparon del salon",
    "quienes abandonaron el aula sin permiso",
    "quienes no ingresaron al aula despues del recreo",
    "quienes no volvieron del descanso", "quienes salieron sin autorizacion",
    "muestrame las evasiones de hoy", "lista de evasiones de ayer",
    "las evasiones que hubo esta semana", "las fugas registradas hoy",
    "los que no permanecieron en el aula", "quienes se quedaron por fuera",
])
_add('list_events', [
    "las evasiones de {student}", "las salidas sin permiso de {student}",
    "las veces que se volo {student}", "las veces que abandono el aula {student}",
    "las evasiones del {group}", "los del {group} que se volaron",
    "los del {group} que abandonaron el aula", "los del {group} de pinta",
], expand=True)


# ── 2. «Cuántas X hubo/hay/se registraron» por métrica ───────────────────────
# late_today: el submodelo tenía «quién llegó tarde» pero no «cuántas tardanzas»
_add('late_today', [
    "cuantas tardanzas hubo hoy", "cuantas tardanzas hubo ayer",
    "cuantas tardanzas se registraron hoy", "cuantas tardanzas fueron hoy",
    "cuantas tardanzas hay hoy", "cuantas tardanzas lleva el dia",
    "cuantos llegaron tarde hoy", "cuantos llegaron tarde esta mañana",
    "cuantos llegaron despues de la hora", "cuantos entraron tarde hoy",
    "cuantos quedaron con tardanza", "cuantos estudiantes llegaron tarde",
    "cuantas llegadas tarde hubo", "cuantas llegadas tarde se registraron",
    "cuantas llegadas tarde hay hoy", "cuantos ingresos tarde hubo",
    "cuantos se reportaron tarde", "cuantos quedaron marcados tarde",
    "cuantas tardanzas fueron ayer", "cuantas tardanzas hubo esta semana",
    "cuantos llegaron tarde ayer", "cuantos llegaron tarde esta semana",
    "hubo muchas tardanzas hoy", "cuantas tardanzas en total hubo",
    "el numero de tardanzas de hoy", "el total de tardanzas de ayer",
    "cuantos pasaron de la hora de entrada",
])
# attendance_today: faltas/ausentes del día
_add('attendance_today', [
    "cuantas inasistencias hubo hoy", "cuantas inasistencias hubo ayer",
    "cuantas inasistencias se registraron hoy", "cuantas faltas hubo hoy",
    "cuantas faltas hubo ayer", "cuantas faltas se registraron esta semana",
    "cuantos faltaron hoy", "cuantos faltaron ayer", "cuantos faltaron esta mañana",
    "cuantos no vinieron hoy", "cuantos no asistieron hoy",
    "cuantos estudiantes no asistieron", "cuantos ausentes hubo hoy",
    "cuantas ausencias hubo hoy", "cuantas ausencias se registraron",
    "cuantos se reportaron ausentes", "cuantos quedaron con falta",
    "cuantas inasistencias fueron esta semana", "cuantas faltas hay hoy",
    "el numero de faltas de hoy", "el total de inasistencias de ayer",
    "hubo muchas faltas hoy", "fue alta la inasistencia hoy",
    "cuantos no llegaron al colegio hoy",
])
# citations / permissions / trackings — conteos del dominio
_add('citations', [
    "cuantas citaciones se mandaron hoy", "cuantas citaciones se mandaron ayer",
    "cuantas citaciones fueron enviadas", "cuantas citaciones se enviaron esta semana",
    "cuantas citaciones hubo hoy", "cuantas citaciones hay pendientes hoy",
    "cuantas citaciones se generaron", "cuantas citas a acudientes hubo",
    "cuantas citas a padres se mandaron", "cuantas citaciones quedaron pendientes",
])
_add('permissions', [
    "cuantos permisos se generaron hoy", "cuantos permisos hubo hoy",
    "cuantos permisos hay activos hoy", "cuantos permisos estan vigentes",
    "cuantas salidas autorizadas hubo", "cuantos permisos se dieron hoy",
    "cuantos permisos activos hay ahora", "cuantos permisos quedan abiertos",
    "cuantos permisos fueron aprobados esta semana",
])
_add('count_trackings', [
    "cuantos seguimientos se abrieron hoy", "cuantos seguimientos se abrieron esta semana",
    "cuantos seguimientos hubo este mes", "cuantos casos de seguimiento se registraron",
    "cuantos seguimientos nuevos hubo", "cuantas derivaciones hubo esta semana",
    "cuantos estudiantes entraron a seguimiento",
])


# ── 3. name_meaning ↔ about_me ───────────────────────────────────────────────
_add('name_meaning', [
    "que significa mi nombre", "que quiere decir mi nombre",
    "cual es el significado de mi nombre", "que significa el nombre",
    "de donde viene mi nombre", "cual es el origen de mi nombre",
    "que significa el nombre {student}", "que quiere decir el nombre {student}",
    "cual es el significado del nombre {student}",
    "de donde viene el nombre {student}", "origen del nombre {student}",
    "que significa {student} como nombre", "que significa ser llamado {student}",
    "el significado de mi nombre", "el origen de mi nombre",
    "mi nombre que significa", "que representa mi nombre",
    "que quiere decir mi nombre en griego", "que significa mi nombre en latin",
    "etimologia de mi nombre", "la etimologia del nombre {student}",
    "significado del nombre {student}", "a que hace referencia mi nombre",
], expand=True)
_add('about_me', [
    "que sabes de mi", "que informacion tienes sobre mi",
    "que datos tienes de mi", "que recuerdas de mi",
    "que hay en mi perfil", "que dice mi perfil",
    "que informacion manejas de mi", "que sabes sobre mi cuenta",
    "mis datos en el sistema", "mi informacion guardada",
    "que tienes registrado de mi", "que aparece en mi ficha",
    "mi perfil completo", "dame mi perfil", "muestrame mi perfil",
    "que rol tengo", "cual es mi rol", "que permisos tengo yo",
    "quien soy en el sistema", "que puesto tengo",
    "mis datos personales", "mi informacion personal",
])


# ── 4. love/compliment ↔ wellbeing_reply ─────────────────────────────────────
_add('love', [
    "me caes bien", "me caes muy bien", "me agradas", "me agradas mucho",
    "me gustas", "me gusta hablar contigo", "me encanta hablar contigo",
    "me encantas", "eres adorable", "te quiero", "te quiero mucho nexus",
    "me cae bien este bot", "me cae bien el asistente", "que lindo eres",
    "eres un amor", "eres divino", "eres un sol", "me haces feliz",
    "contigo se me pasa el dia", "eres especial", "te aprecio",
    "me tienes ganado", "eres de lo mejor",
])
_add('compliment', [
    "eres chevere", "eres bacano", "eres genial", "eres increible",
    "eres lo maximo", "eres una locura de bueno", "eres muy amable",
    "eres muy util", "eres super util", "eres brillante",
    "eres inteligente", "eres rapido", "que bueno eres",
    "que util eres", "que crack eres", "que pro eres",
    "buen trabajo", "excelente trabajo", "lo hiciste perfecto",
    "me impresionas", "eres eficiente", "eres muy atento",
    "que servicial eres", "que buena onda eres",
])
_add('wellbeing_reply', [
    "como estas hoy", "como te sientes", "como te sientes hoy",
    "como va todo contigo", "todo bien por ahi", "como andas tu",
    "como va tu dia", "como va tu jornada", "como te va a ti",
    "como te encuentras", "como amaneciste", "como te ha ido",
    "que tal estas", "que tal te va", "bien por alla", "todo chevere",
    "como sigues", "como amanecio el sistema",
])
