# Fase 2 — Diagnóstico de optimización NLU/dataset (sin reentrenamiento)

> Estado congelado: 35 PASS / 1 FAIL · PHPUnit 210/210 · 87 intents ·
> 473.544 ejemplos (118.386 originales ×4). Modelo intacto, threshold 0.65.
> Nada de lo aquí descrito se aplicó a producción. Fecha: 2026-09-20.

---

## 1. FAIL residual — «¿Cuántas tardanzas hubo hoy?» tras citaciones

### Causa real (no era la que parecía)

El modelo **sí elige** `late_today` (0.6323) — el problema NO es la frontera
citations↔late_today. Cadena real:

```
late_today 0.6323 → <0.65 → out_of_scope → herencia ctx → citations
                                                  (último intent consultado)
```

La herencia lo empeora: convierte una abstención honesta en una respuesta
equivocada.

### Opción A — regla contextual (medida sobre las 36 trazas)

Exigir **marcador de seguimiento** (`y|ahora|las|del|solo|…`) para heredar
el intent. Resultado simulado sobre `/tmp/forensic_traces.json`:

| Turno que hereda intent | marcador | resultado |
|---|---|---|
| G1-T2 «ahora las inasistencias» | ✓ | PASS (se conserva) |
| G1-T4 «ahora solamente las del 8A» | ✓ | PASS |
| G4-T3 «¿y del mes pasado?» | ✓ | PASS |
| G4-T4 «ahora las del grupo 8A» | ✓ | PASS |
| G5-T3 «¿cuántas tardanzas hubo hoy?» | **✗** | FAIL → pasa a out_of_scope honesto |
| G6-T2 «ahora de María» | ✓ | PASS |
| G6c-T2 «ahora del mes pasado» | ✓ | PASS |

**Impacto: +0 regresiones, convierte el FAIL en abstención correcta.**
Una pregunta autónoma («cuántas… hubo…») sin marcador deictico no debe
heredar — es pregunta nueva, no continuación.

### Opción B — frontera NLU (medida)

```
«cuántas tardanzas hubo hoy» → late_today 0.632 | count_events 0.368
«cuántas tardanzas hay hoy»  → count_events 0.845 | late_today 0.154
«cuántas citaciones hubo hoy»→ count_events 0.622 | citations  0.370
```

El patrón `cuántas {métrica} hubo/hay` reparte entre `count_events` y los
intents específicos — el corpus de `late_today` domina la forma
«quién(es) llegó(aron) tarde» pero es débil en «cuántas tardanzas».
Corregirlo exige corpus + reentrenamiento (fase posterior).

**Veredicto: aplicar A ahora** (una línea de guarda en la herencia de
intent — ya existe el regex `followupMark` de la corrección forense);
B entra en el plan de reentrenamiento. A no excluye B: con B resuelto,
el turno ni siquiera llegaría a la herencia.

---

## 2. Auditoría de diversidad real

| Métrica | Valor |
|---|---|
| Ejemplos totales | 473.544 |
| Originales | 118.386 (exactamente ×4 con `big_variants`) |
| **Duplicados tras máscara** | **316.854 (67%)** |
| Variantes idénticas tras máscara | **61%** — aporte nulo al modelo |
| Variantes con cambio real | 39% (typo 47% · shorthand 26% · drop_fillers 9% · otras 17%) |

### Diversidad sintáctica (patrones únicos de estructura por intent)

| Intent | originales | patrones únicos | ratio |
|---|---|---|---|
| student_field | 10.998 | 764 | **0,069** |
| colombia_capital | 3.240 | 246 | 0,076 |
| math_operation | 25.956 | 2.462 | 0,095 |
| list_events | 8.496 | 822 | **0,097** |
| derive_action | 6.561 | 644 | **0,098** |
| count_events | 18.351 | 2.344 | 0,128 |
| group_summary | 3.600 | 464 | 0,129 |
| group_student_count | 1.728 | 251 | 0,145 |
| student_summary | 10.296 | 1.542 | 0,150 |
| attendance_today | 2.520 | 502 | 0,199 |
| late_today | 1.755 | 384 | 0,219 |
| *(referencia)* time/date/pending_tasks | ~100 | ~60 | **0,55–0,62** |

**Los intents más voluminosos tienen la menor diversidad estructural** —
`student_field` tiene ~14 ejemplos por patrón sintáctico (permutaciones de
nombres/campos). El volumen inflado no aporta formas nuevas: enseña
**valores**, no **estructuras**. Por eso «estudiante camila rojas del
onceno» (estructura no vista) queda en 0.39.

### Conclusión de diversidad

- El corpus entrena memorización de plantillas, no generalización.
- `count_events` (18.351 originales, TTR 0.002) es el caso extremo:
  absorbe respuestas de intents vecinos por volumen relativo.
- La augmentación ×4 reporta ruido ortográfico real en solo 39% de casos.

---

## 3. Auditoría de fronteras semánticas (9 pares)

| Par | ¿Conceptos distintos? | ¿Solo difiere en slot? | ¿Respuesta distinta? | ¿Permisos distintos? | Veredicto |
|---|---|---|---|---|---|
| count_events ↔ citations | Sí (conteo de eventos vs citaciones registradas) | No | Sí (handler distinto) | No (ambos `aggregates`) | **Separados** — confusión solo por «cuántas X» |
| count_events ↔ permissions | Sí | No | Sí | No | **Separados** |
| group_summary ↔ group_student_count | Parcial (resumen vs número) | Sí (misma entidad grupo) | Sí (completo vs conteo) | No | **Dudoso** — group_student_count podría ser slot de group_summary |
| fun_fact ↔ colombia_fun_fact | No para el usuario (ambos «dato curioso») | Casi (solo tema Colombia) | Mismo formato | No | **Fusión recomendada** → `fun_fact` + slot `region`/tema |
| students_count ↔ group_student_count | No (total vs por grupo) | **Sí — solo `group`** | Sí (scope) | No | **Fusión recomendada** → `students_count` + slot `group` opcional |
| risk_students ↔ risk_config | Sí (lista vs umbrales) | No | Sí | risk_config sin policy explícita | **Separados** |
| count_trackings ↔ students_count | Sí | No | Sí | No | **Separados** |
| permissions ↔ pending_returns | Subconjunto (activos vs sin retorno) | Casi — difieren en filtro `status` | Sí | No | **Dudoso** — modelable como `permissions`+slot `estado` |
| citations ↔ late_today | Sí | No | Sí | No | **Separados** |

Jaccard de vocabulario más alto: students_count↔group_student_count 0.75,
fun_fact↔colombia_fun_fact 0.61, group_summary↔group_student_count 0.60.

---

## 4. Propuesta de taxonomía

| Intent actual | Propuesta | Justificación | Riesgo | Compatibilidad |
|---|---|---|---|---|
| `students_count` + `group_student_count` | `students_count` (+slot `group`) | Solo difieren en la entidad; mismo handler con filtro opcional | Bajo — handler ya recibe `group` | Respuesta compatible |
| `fun_fact` + `colombia_fun_fact` | `fun_fact` (+slot `topic=colombia`) | UX idéntica; el usuario no distingue | Bajo — pools de datos separables internamente | Respuesta compatible |
| `permissions` + `pending_returns` | `permissions` (+slot `estado=vencido`) | Misma tabla, filtro distinto | Medio — copy de respuesta difiere | Handler puede bifurcar por slot |
| `group_summary` + `group_student_count` | mantener separados | «cómo va el 8A» ≠ «cuántos hay» — UX distinta | — | — |
| `count_events` ↔ {citations, permissions, late_today} | mantener separados | intents con handler propio; confusión es de corpus no de taxonomía | — | — |
| `attendance_today` / `late_today` / `count_present` | evaluar `day_metrics`+slot métrica | Tres intents «del día» se solapan en follow-ups | Alto — handlers con SQL distinto | Diferidos a Fase 3 |
| `derive_action` vs `start_operation` | mantener (evaluar alias) | mismo resolvedor de operación; dividir el vocabulario ayuda | Bajo | Handler-equivalentes ya |

**Fusión recomendada:** students_count+group_student_count,
fun_fact+colombia_fun_fact.
**Fusión dudosa:** permissions+pending_returns.
**Todo lo demás permanece separado** — las confusiones son de datos, no de
diseño.

---

## 5. Blind test — `test/blind_set.json` + `test/blind_eval.php`

111 frases sueltas escritas a mano (jerga, typos, paráfrasis, ambigüedad,
adversarial, fuera de dominio) + 5 conversaciones multi-turno. Ninguna
copia plantillas del corpus. Evaluación por `nxClassify` real →
**86/111 = 77,5%** (vs 93% de la batería interna → la batería interna
sobreestima ~15pp por similitud de estilo).

Fallos por categoría: extranjero 1/4, fuera_dominio 1/3, emoción 4/6,
colombia 6/8, meta 10/12. Falsos convencidos (≥0.90 y mal): **6 casos**
documentados en `/tmp/blind_eval.json` — incluye «leyenda del dorado»→
session_summary 0.97 y «qué significa mi nombre»→about_me 0.999.

## 6. Calibración

| Banda de confianza | Precisión |
|---|---|
| ≥ 0.90 | **92,1%** (70/76) |
| 0.65 – 0.90 | **88,9%** (16/18) |
| < 0.65 | abstención — de las 17 abstenciones, el top-1 era correcto en **9 (53%)** |

Lectura: 0.65 es razonable y bien calibrado (las bandas por encima son
≥89% correctas). El costo real está justo debajo: ~la mitad de lo que se
abstiene era recuperable. **No cambiar el umbral aún** — la decisión
correcta es en la fase de reentrenamiento, midiendo si las fronteras
blandas (count_events↔específicos) se separan mejor con corpus corregido;
si no, evaluar 0.55–0.60 con ECE formal.

---

## 7. Diagnóstico final

### A. Qué está bien

- Router formal/informal casi perfecto (p_formal >0.99 en fallos).
- Arbitraje inter-dominio y umbrales coherentes (banda ≥0.65 ≥89%).
- Herencia de slots y resolución de operación ya corregidos (Fase 1).
- Abstención segura: cero escapes adversariales de permisos.

### B. Qué limita al modelo

- **Volumen ≠ diversidad:** los intents grandes son permutaciones de
  plantillas (student_field: 14 ejemplos por patrón).
- **61% de la augmentación es invisible** tras máscara — bloat de cómputo.
- **Frontera `cuántas X hubo/hay`**: reparte entre count_events y
  específicos (causa del FAIL residual y de confusiones top-20).
- **TF-IDF no ve semántica:** «capo clase»/«se la volaron»/«pelados» no
  enlazan con evasión/estudiantes → jerga colombiana real cae a bajo umbral.

### C. Qué corregir en el dataset

1. Deduplicar tras máscara (eliminar los ~317k duplicados efectivos).
2. Reemplazar permutaciones por **estructuras nuevas**: oraciones pasivas,
   orden invertido, jerga real («capó», «se voló», «pelados», «parces»).
3. Reforzar `cuántas {métrica} hubo/hay/fueron` por intent específico.
4. Equilibrar: intents con <200 originales compiten contra clases ×100.

### D. Intents a reconsiderar

- Fusionar `students_count`+`group_student_count` → slot `group`.
- Fusionar `fun_fact`+`colombia_fun_fact` → slot `topic`.
- Evaluar `permissions`+`pending_returns` → slot `estado`.
- Revisar `derive_action`↔`start_operation` (mismo resolvedor).

### E. Ejemplos que faltan

- `cuántas tardanzas/inasistencias/evasiones hubo|hay|fueron` por intent.
- Jerga de evasión: «capar clase», «volar clase», «escaparse», «fugar».
- «pelados/muchachos/chinos/parces» como estudiantes.
- Estructuras pasivas e invertidas.
- Negaciones reales («los que NO trajeron», «sin excusa»).

### F. Cambios que podrían mejorar el modelo

- Clase única `students_count`+group → libera la frontera más confusa.
- Features de char n-gram más largos para jerga (ya hay char_wb 2-5;
  evaluar 3-6) — **medir, no asumir**.
- Class weighting / submuestreo de intents gigantes.
- Threshold 0.55–0.60 solo si la recalibración lo soporta con ECE.

### G. Cambios que NO deben hacerse

- NO fusionar intents automáticamente (cada uno debe justificar su fusión
  en la tabla §4 y validarse con blind test).
- NO bajar el threshold sin reentrenar (riesgo de falsos convencidos —
  hoy hay 6 a ≥0.90).
- NO añadir embeddings/LLM — la arquitectura TF-IDF+LR está bien
  calibrada; el problema es de datos.
- NO heredar intent sin marcador de seguimiento (medida A §1).

## Estrategia de entrenamiento propuesta (etapas)

| Etapa | Acción | Validación | Reversión |
|---|---|---|---|
| 0 | Aplicar regla A (marcador en herencia) | suite forense ≥35/36 | revert chat.php |
| 1 | Deduplicar corpus tras máscara | mismo accuracy, menor tiempo | git revert corpus |
| 2 | Fusión students_count+group_student_count, fun_fact+colombia_fun_fact | blind test ≥77.5%, matriz limpia | revert corpus+intent map |
| 3 | Corpus nuevo: frontera `cuántas X hubo`, jerga, negaciones | blind test sube; FAIL residual resuelto a late_today | revert corpus |
| 4 | Reentrenar + exportar model_php.json | suite forense 36/36, PHPUnit 210/210, ECE medido | git revert modelos |
| 5 | Recalibrar threshold si procede | banda 0.55–0.65 con precisión ≥85% | revert constante |

Cada etapa produce un modelo alternativo (`model_v2.joblib`) comparado
contra el congelado — el baseline 35/36 + 77.5% blind es la barra mínima.
