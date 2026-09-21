"""
corpus_v31_targeted.py — Gaps residuales confirmados en blind LIMPIO.

Solo familias con gap estructural demostrado (Parte B, Fase 3B):
  1. Construcción pronominal «se la voló/volaron» (evasión colombiana).
  2. Contraste «se registraron»: evento institucional vs seguimiento.
  3. name_meaning: variantes «de dónde viene/proviene/procede».
  4. fun_fact: «dato random/al azar/aleatorio» (random_* secuestra).

NO contiene frases del blind set. Estructuras nuevas, no repeticiones.
Aditivo: borrar el archivo revierte.
"""

from corpus import CORPUS, _augment, _expand, STUDENTS, GROUPS, DAYS


def _add(intent_id, phrases, expand=False):
    if expand:
        phrases = _expand(phrases, student=STUDENTS, group=GROUPS, days=DAYS)
    CORPUS[intent_id].extend(_augment(phrases))


# ── 1. «Se la voló/volaron» — pronombre insertado, jerga fuerte de evasión ──
_add('count_events', [
    "cuantas veces se la volo {student}", "cuantas veces se la volo la clase {student}",
    "cuantas veces se la volo de clase {student}",
    "cuantas veces se la volaron los del {group}",
    "cuantos se la volaron hoy", "cuantos se la volaron ayer",
    "cuantos se la volaron de clase", "cuantos se la volaron esta semana",
    "cuantos se la volaron del salon", "cuantos se la volaron de la clase",
    "cuantas clases se la volo {student} esta semana",
    "cuantas veces se la volo de la materia",
    "cuantos se la estan volando", "cuantos se la volaron del colegio",
    "cuantos se la volaron a la hora", "cuantos se la dieron",
    "cuantos se la piantaron", "cuantos se la tiraron de clase",
], expand=True)
_add('list_events', [
    "quienes se la volaron hoy", "quienes se la volaron ayer",
    "quienes se la volaron de clase", "quienes se la estan volando",
    "los que se la volaron", "los que se la volaron de la clase",
    "muestrame los que se la volaron", "dame los que se la volaron hoy",
    "quien se la volo hoy", "quien se la volo de la clase",
    "los del {group} que se la volaron", "quienes del {group} se la volaron",
], expand=True)

# ── 2. «Se registraron» — el EVENTO pesa, no el verbo ───────────────────────
_add('count_events', [
    "cuantas evasiones se registraron", "cuantas evasiones se reportaron",
    "cuantas evasiones quedaron registradas", "cuantas evasiones quedaron reportadas",
    "cuantas faltas se registraron", "cuantas faltas se reportaron",
    "cuantas inasistencias se registraron", "cuantas salidas sin permiso se registraron",
    "cuantas fugas se registraron", "cuantas evasiones fueron registradas",
    "cuantas evasiones fueron reportadas", "cuantas evasiones se anotaron",
    "cuantas evasiones quedaron en el sistema", "cuantas evasiones se contaron",
    "cuantas salidas del aula se registraron", "cuantas evasiones se documentaron",
])
_add('late_today', [
    "cuantas tardanzas se registraron", "cuantas tardanzas se reportaron",
    "cuantas tardanzas quedaron registradas", "cuantas llegadas tarde se registraron",
    "cuantas tardanzas se anotaron", "cuantas tardanzas fueron registradas",
])
_add('citations', [
    "cuantas citaciones se registraron", "cuantas citaciones se reportaron",
    "cuantas citaciones quedaron registradas", "cuantas citas se registraron",
])
# reforzar el lado contrario para que «se registraron» no domine
_add('count_trackings', [
    "cuantos seguimientos se registraron", "cuantos seguimientos se reportaron",
    "cuantos casos se registraron en seguimiento",
    "cuantos casos de seguimiento se registraron",
    "cuantas derivaciones se registraron", "cuantos seguimientos quedaron registrados",
    "cuantos seguimientos fueron registrados", "cuantos seguimientos se anotaron",
])

# ── 3. name_meaning — «viene/proviene/procede/origen» ───────────────────────
_add('name_meaning', [
    "de donde viene mi nombre", "de donde viene el nombre",
    "de donde viene el nombre {student}", "de donde proviene mi nombre",
    "de donde procede mi nombre", "de donde sale mi nombre",
    "cual es la procedencia de mi nombre", "origen de mi nombre",
    "origen del nombre {student}", "la procedencia del nombre {student}",
    "de donde salio el nombre {student}", "de donde sacaron mi nombre",
    "porque me llaman {student}", "porque me pusieron {student}",
    "que idioma es mi nombre", "de que lengua viene mi nombre",
    "mi nombre de donde es", "de donde nace el nombre {student}",
    "cual es la raiz de mi nombre", "raiz del nombre {student}",
    "mi nombre viene de donde", "mi nombre es de origen que",
], expand=True)
# reforzar el lado about_me para que «de mí» no se confunda
_add('about_me', [
    "de donde sacaste mi informacion", "de donde sacas mis datos",
    "de donde tienes mi informacion", "de donde conoces mi perfil",
    "que recuerdas de mi cuenta", "que guardas de mi",
    "que informacion guardas de mi", "que tienes anotado de mi",
])

# ── 4. fun_fact — «dato random/azar» no es estudiante aleatorio ─────────────
_add('fun_fact', [
    "dame un dato random", "dame un dato al azar", "un dato aleatorio",
    "un hecho random", "algo random interesante", "cuentame algo al azar",
    "dime algo random", "dato curioso al azar", "un dato cualquiera",
    "sorprendeme con algo random", "cuentame cualquier cosa curiosa",
    "dime un hecho al azar", "un fact random", "dato random de algo",
])
