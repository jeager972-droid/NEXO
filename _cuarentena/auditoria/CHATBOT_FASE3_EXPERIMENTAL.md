# Fase experimental NLU 1 — Resultados

> Modelo de producción intacto (`model.joblib` no tocado). Candidatos en
> `backend/nlu/model/exp/`. Fecha: 2026-09-20.

## ETAPA 0 — Regla contextual aplicada

`chat.php` + `chat_forensic_harness.php`: la herencia de intent bajo umbral
ahora exige marcador de seguimiento (`y|ahora|las|del|solo|esas|…`).
Una consulta autónoma sin marcador cae a abstención honesta.

**Resultado: suite forense 36/36 (antes 35/36) · PHPUnit 210/210 ·
chat_context_sim PASS.** El FAIL residual quedó eliminado:
«¿Cuántas tardanzas hubo hoy?» tras citaciones → `out_of_scope` honesto
en vez de ejecutar `citations` equivocado.

## ETAPA 1 — Candidatos de corpus

| Corpus | Estrategia | Ejemplos | Masked únicos | min/intent | max/intent |
|---|---|---|---|---|---|
| base | ×4 augmentación | 473.544 | 156.773 | 107 | 50.945 |
| **A** | dedup exacto | 251.079 | 156.773 | 107 | 50.945 |
| **B** | dedup tras máscara | 157.275 | 156.773 | 90 | 26.204 |
| **C** | equilibrado (cap=2×mediana, patrones primero) | 84.168 | 40.332 | 216 | 1.656 |

A conserva todo el masked-set pero quita duplicados literales. B conserva
un representante por forma enmascarada. C trunca gigantes — pero pierde
116k formas únicas (masked únicos cae a 40k porque el cap corta variantes).

## ETAPA 2 — Entrenamiento aislado

Mismo pipeline exacto (router + formal + informal, seed 42, C=4.0).
Modelos en `backend/nlu/model/exp/model_v2_{A,B,C}.joblib`, servidos en
8091/8092/8093 para evaluación por el camino real de producción.

| Nivel | base | A | B | C |
|---|---|---|---|---|
| router acc | ~0.997 | 0.9962 | 0.9922 | 0.9896 |
| formal acc | ~0.988 | 0.9864 | 0.9872 | **0.9933** |
| informal acc | ~0.985 | 0.9860 | 0.9748 | 0.9763 |

## ETAPA 3/4 — Comparación completa

| Métrica | Baseline | v2-A | v2-B | v2-C |
|---|---:|---:|---:|---:|
| Blind accuracy | 77,5% | 78,4% | **79,3%** | 77,5% |
| Forense | 36/36 | **36/36** | **36/36** | **28/36 ✗** |
| PHPUnit | 210/210 | 210/210 | 210/210 | 210/210 |
| Falsos convencidos ≥0.90 | 6 | **3** | **3** | **2** |
| Precisión ≥0.90 | 92,1% | 96,0% | 95,7% | **96,8%** |
| Precisión 0.65–0.90 | 88,9% | 77,8% | 80,0% | 80,0% |
| Abstenciones | 17 | 18 | 17 | 18 |
| Abstenciones recuperables | 9 | 8 | 9 | 7 |
| «cuántas tardanzas hubo hoy» | oos 0.63 | **late_today 0.82 ✓** | late_today 0.74 ✓ | count_events 0.90 ✗ |
| «cuántos permisos hay activos» | oos 0.64 | oos 0.65 | count_events 0.71 ✗* | count_events 0.70 ✗* |

*Semánticamente discutible: sí es una consulta de conteo sobre permisos;
el handler `count_events` con `module=PERMISO` respondería el número.
No es fuga de permisos — es un intent vecino razonable.

### Análisis por candidato

**A — dedup exacto.** Misma información, menos volumen (−47%). Limpia la
frontera `late_today` (0.63→0.82) y reduce falsos convencidos a la mitad.
Conservador: ningún nuevo error seguro.

**B — dedup tras máscara.** −67% de volumen, mejor blind (79.3%), misma
mejora en la frontera residual (0.74), 3 falsos convencidos. Un punto
débil nuevo: «cuántos permisos hay activos» se vuelve count_events 0.71
(confundible pero semánticamente razonable — sí pregunta un conteo).

**C — equilibrado.** Descalificado: mejor formal-acc de split (0.9933) pero
**8 regresiones forenses** — el cap destruyó cobertura de follow-ups
cortos y «cuántas tardanzas hubo hoy» se vuelve count_events 0.90 (peor
que abstenerse: error seguro). Prueba de que «equilibrar por volumen»
daña la cobertura de colas largas.

### Fronteras (deltas relevantes)

| Frontera | base | A | B | C |
|---|---|---|---|---|
| late_today en «cuántas X hubo» | 0.63 fallback | **0.82 ✓** | 0.74 ✓ | 0.90 mal |
| count_events↔citations | estable | estable | estable | estable |
| students_count↔group_student_count | separados ✓ | separados ✓ | separados ✓ | separados ✓ |
| fun_fact↔colombia_fun_fact | colombia gana siempre (0.89–1.0) | igual | igual | igual — frontera estructural, requiere decisión de taxonomía |

## Recomendación técnica (solo evidencia)

**Adoptar corpus B (dedup tras máscara) como candidato preferido:**
mejor blind accuracy, 36/36 forense, ⅓ del tamaño, falsos convencidos a
la mitad. Única salvedad: «cuántos permisos hay activos» → count_events
0.71 (aceptable semánticamente — se responde el conteo correcto).

Alternativa conservadora: **corpus A** — mismas mejoras sin ese único
cambio de comportamiento, a costa de 251k ejemplos.

**Descartado C**: mejora métricas de split pero destruye follow-ups —
evidencia de que la cola larga del corpus sí es señal, no ruido.

**Antes de producción falta:** regenerar `model_php.json` para el
candidato elegido (export word-level de train.py) y correr la batería
interna de 18.880 frases como sanity final.

## Archivos

**Creados:** `backend/nlu/exp_v2.py` · `backend/nlu/model/exp/model_v2_{A,B,C}.joblib`
· `backend/nlu/model/exp/corpus_stats.json` · `test/blind_set.json` ·
`test/blind_eval.php` · `/tmp/nlu_v2_{A,B,C}/` (servicios de evaluación)

**Modificados:** `backend/api/routes/chat.php` (guarda de marcador — Etapa 0)
· `test/chat_forensic_harness.php` (paridad + expectativa documentada)

**Intactos:** `model.joblib` · `model_php.json` · corpus·.py · intents ·
threshold 0.65 · arquitectura.
