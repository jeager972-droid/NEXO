# Auditoría Estadística — Modelo y Dataset NLU de Nexus

> Fase read-only. Modelo intacto, dataset intacto.
> Fecha: 2026-09-20 · `audit_stats.py` → `model/audit_stats.json`

## 1. Resumen ejecutivo

El volumen «473.544 ejemplos» es **engañoso**: son 118.386 líneas originales
del corpus × 4 (1 original + 3 variantes `big_variants`). Y de esas variantes,
**el 61% queda idéntica tras la máscara** — el modelo jamás las distingue.
La diversidad real está en ~118k plantillas originales, no en 473k.

**La confusión observada NO es principalmente un problema de modelo.** Los
fallos reales del usuario (solicitud→citación, «pasado»→estudiante) están en
**la capa de reglas posteriores** (`chatOperationCmd`, `nxSlots`), no en la
clasificación. Donde el modelo sí falla es en intents con baja diversidad
léxica real y fronteras semánticas genuinas.

## 2. Conjunto de evaluación — honestidad metodológica

No existe un test set etiquetado a mano. Se usó la batería de `selftest.py`:
18.880 frases generadas con plantillas **distintas** a las del corpus,
entidades nuevas (estudiantes/grupos no vistos), wrappers coloquiales y
ruido ortográfico. **Limitación:** las plantillas comparten el estilo del
corpus; 1.455 frases (7,7%) son idénticas normalizadas a algún ejemplo del
corpus → el accuracy reportado es ligeramente optimista. No se contaminó
entrenamiento para fabricarlo.

**accuracy = 93,12 % · macroF1 = 0,918 · weightedF1 = 0,946**

## 3. Matriz de confusión — Top-20

REAL → PREDICHO → n → % de la clase

```
count_events          → citations              94   8%
count_events          → permissions            92   8%
group_summary         → group_student_count    83  17%
fun_fact              → colombia_fun_fact      74  37%
trackings             → out_of_scope           62  21%
student_summary       → wellbeing              59   7%
student_summary       → out_of_scope           59   7%
math_operation        → out_of_scope           59  20%
count_present         → students_count         57  19%
news_sports           → out_of_scope           56  28%
permissions           → out_of_scope           49  16%
student_summary       → colombia_president     47   6%
list_events           → out_of_scope           41   7%
student_summary       → whatsapp_status        40   5%
food_music            → sing                   33  16%
fun_fact              → out_of_scope           28  14%
student_summary       → staff_lookup           25   3%
notifications_unread  → fun_fact               25  12%
thanks                → out_of_scope           22  11%
start_operation       → out_of_scope           22   6%
```

Interpretación: las confusiones *peores* son entre clases vecinas
semánticas (count_events↔citations/permissions, group_summary↔
group_student_count, fun_fact↔colombia_fun_fact — este último par es
semánticamente equivalente para el usuario). Las fuga a `out_of_scope`
(trackings 21%, news_sports 28%, math 20%) son **fallbacks correctos
ante frases ambiguas** — no errores de seguridad.

Los pares que el usuario sospechaba (citations↔start_operation,
permisos↔autorización) **no aparecen en el top-20 de confusión del modelo**
— confirmando que el bug de «solicitud» vive en `chatOperationCmd`, no
en el clasificador.

## 4. Auditoría del dataset

| Métrica | Valor |
|---|---|
| Intents | 87 |
| Ejemplos originales | 118.386 |
| Con augmentación | 473.544 (exactamente ×4) |
| **Duplicados tras máscara (global)** | **316.854 (67%)** |
| Variantes idénticas tras máscara | **61,0%** — no aportan nada |
| Variantes con cambio real | 39,0% (typo 47%, shorthand 26%, drop_fillers 9%, otras 17%) |

Intents con **mayor volumen pero menor diversidad léxica** (TTR =
vocabulario/tokens):

| Intent | originales | TTR | vocab | len media | dup tras máscara |
|---|---|---|---|---|---|
| count_events | 18.351 | 0,002 | 218 | 7,7 | 64% |
| math_operation | 25.956 | 0,002 | 238 | 5,7 | 78% |
| student_field | 10.998 | 0,002 | 113 | 6,1 | 62% |
| student_summary | 10.296 | 0,002 | 119 | 6,6 | 61% |
| derive_action | 6.561 | 0,002 | 105 | 6,4 | 68% |
| list_events | 8.496 | 0,003 | 140 | 6,4 | 64% |
| group_summary | 3.600 | 0,004 | 74 | 5,4 | 70% |

`math_operation` tiene 25.956 «originales» pero vocab=238 — son
plantillas `{a} mas {b}` con permutaciones de números. TTR 0,002 indica
~1 token nuevo por cada 500 — saturación léxica total. El corpus
esencialmente enseña **formas**, no **diversidad**.

## 5. Qué aporta la augmentación

Transformaciones en `train.py`:
- `shorthand` — jerga: que→q, por→x, para→pa, gracias→grax…
- `typo` — una mutación por palabra: dup/drop/swap de letra
- `drop_fillers` — elimina artículos/preposiciones
- `upper` — 12% mayúsculas
- `_augment` (corpus.py) — prefijos/sufijos de cortesía (`oye`, `porfa`,
  `¿puedes?`…)
- `_expand` — sustitución de slots `{dept}`, `{prez}`, `{a}`, `{b}`…

**61% de las variantes producen el mismo texto enmascarado** — el modelo
las ve idénticas. La augmentación aporta robustez ortográfica real en el
39% restante. El corpus no infla en diversidad semántica; infla en
ruido superficial.

## 6. Solapamiento semántico (centroide TF-IDF entre intents del mismo dominio)

| sim | Par | lectura |
|---|---|---|
| 0,64 | fun_fact ↔ colombia_fun_fact | **misma respuesta para el usuario** — clases duplicadas en efecto |
| 0,54 | risk_students ↔ risk_config | «riesgo» vs «umbrales» — frontera confusa |
| 0,45 | students_count ↔ group_student_count | «cuántos hay» vs «cuántos hay en el 8A» — difieren solo por entidad |
| 0,42 | out_of_scope ↔ math_operation | contaminación léxica |
| 0,37 | count_trackings ↔ students_count | «en seguimiento» vs total |
| 0,35 | permissions ↔ pending_returns | permisos activos vs sin retorno |

El solapamiento alto entre `fun_fact`/`colombia_fun_fact` y
`students_count`/`group_student_count` indica intents que se distinguen
solo por una entidad — deberían ser un mismo intent + slot.

## 7. Conclusiones

1. **El clasificador NO es el cuello de botella principal.** 93,1% en
   batería externa; las confusiones son entre vecinos semánticos reales.
2. **Los bugs que el usuario ve viven en la capa de reglas:**
   `chatOperationCmd` (substring `cit`⊂`solicitud`), `nxSlots`
   («pasado»→student, «del mes pasado»→30 días), y la política de
   herencia de intent (solo rescata bajo umbral).
3. **El dataset tiene volumen inflado:** 67% duplicado tras máscara;
   diversidad léxica real bajísima en los intents de mayor n.
4. **Varias clases deberían fusionarse:** fun_fact+colombia_fun_fact;
   students_count+group_student_count (difieren solo por slot).
5. Las fugas a `out_of_scope` (21-28% en trackings, news_sports, math)
   son fallback correcto — el diseño rechaza antes que inventar.

## 8. Recomendaciones para la corrección (NO aplicadas aún)

1. `chatOperationCmd`: orden de checks por especificidad + `\b` boundary
   (`'solicitud'` antes que `'cit'`; o regex `\bcit(ar|acion|ar a)\b`).
2. `_STOP`: añadir `pasado`, `pasada`, `anterior`, `proximo`, `siguiente`
   como no-estudiante.
3. `nxSlots`/`extract_entities`: evaluar `mes pasado`/`semana pasada`
   antes de `del mes`/`este mes`.
4. Herencia: si la nueva frase trae slot temporal/entidad nueva pero el
   intent clasificado es de resumen genérico, considerar «mismo intent +
   nuevo slot» antes de reemplazar.
5. Dataset: reducir k o deduplicar tras máscara; fusionar intents
   equivalentes; añadir diversidad léxica real en vez de permutaciones.
6. El modelo está cerca del techo de TF-IDF+LR para este dominio —
   la ganancia futura está en **slots+contexto+reglas**, no en más
   ejemplos.
