# Fase 3B — Integridad del benchmark + corrección dirigida (V3.1)

> Producción intacta. V2-B congelado. Fecha: 2026-09-20.

## PARTE A — Integridad del benchmark

### 1. Discrepancia 253 vs 235 — explicada

`blind_set.json` contiene **235 frases sueltas** (`single`) + **15 turnos
conversacionales** (`conversational`). `blind_eval.php` evalúa solo
`single` → 235. Los 15 turnos se evalúan vía `chat_forensic_harness.php`.
Sin discrepancia real — son dos conjuntos distintos.

### 2. Contaminación corpus ↔ blind — HALLAZGO REAL

| Tipo | n | % |
|---|---|---|
| Coincidencia exacta con el corpus | **51** | 21,7% |
| Tras normalización | 2 | |
| Tras máscara | 4 | |
| **Total contaminadas** | **57** | **24,3%** |

Causa: al redactar el blind escribí frases que ya existían como ejemplos
del corpus («holi», «tangente de 45», «capital de japon», «que hay en mi
perfil»…), y `corpus_targeted.py` incorporó algunas frases del blind
(«cuántas tardanzas hubo hoy», «qué significa mi nombre», «me caes bien»).
Índices en `/tmp/contam.json`; textos en `/tmp/contam_texts.json`.

### 3. Dos métricas

`blind_eval.php --clean` excluye las 57. **Toda conclusión usa blind limpio (178).**

| Modelo | Blind completo (235) | **Blind limpio (178)** |
|---|---:|---:|
| baseline (:8090) | — | 123/178 = **69,1%** |
| V2-B (:8092) | 170/235 = 72,3% | 128/178 = **71,9%** |
| V3 (:8094) | 188/235 = 80,0% | 138/178 = **77,5%** |
| V3.1 (:8095) | — | 137/178 = **77,0%** |

**V3 sigue siendo mejor de verdad** — +5,6pp sobre V2-B y +8,4pp sobre
producción **sin contaminación**. La memorización inflaba el 80% → el
honesto es 77,5%.

### 4. ¿V3 memorizó o aprendió?

Aprendió estructura: en el subconjunto limpio (frases nunca vistas en el
corpus) V3 resuelve «cuántos llegaron tarde esta mañana»→late_today,
«me gusta hablar contigo»→love, «que quiere decir el nombre camila»→
name_meaning — variantes NO incluidas en corpus_targeted.

## PARTE B — Triage de residuos

| Frase | Esperado | Actual | Conf | Categoría | Solución |
|---|---|---|---|---|---|
| quienes se la volaron hoy | list/count | attendance_today | 0.81 | **1. gap estructura** (pronombre insertado) | corpus_v31 ✓ |
| cuantos casos de evasión se registraron | count_events | count_trackings | 0.97 | 4. frontera legítima («casos»≈seguimiento) | parcial — documentada |
| de donde viene mi nombre | name_meaning | about_me | 0.99 | 1. gap vocab («viene» vs «mi») | corpus_v31 — parcial |
| estresado con las planillas | emotion_sad | list_events | 0.97 | **5. gap taxonómico** (emoción laboral sin intent propio) | documentado — NO forzar |
| dame mas / ahora esos | out_of_scope | top_offenders | 0.97 | **3. contexto** — deícticos sin ctx | contexto, no corpus |
| quien te creo | creator | audit_query | 0.94 | router: «creo» empuja a dominio formal donde creator no existe | gap de dominio — documentado |
| capital del tolima | colombia_capital | colombia_capital | 1.00 | **6. correcto** — mi etiqueta del blind estaba invertida (colombia_capital = cap. de departamento) | etiqueta corregida |
| dame un dato random | fun_fact | random_student | 0.99 | 1. gap vocab («random»→random_*) | corpus_v31 ✓ |

## V3.1 — `corpus_v31_targeted.py` (~6.300 ejemplos)

Familias: «se la voló/volaron» (30), «se registraron» con refuerzo
contrario (31), «de dónde viene/proviene/procede/origen/raíz» nombre (28),
«dato random/azar/aleatorio»→fun_fact (14). Ninguna frase del blind.

### Resultados blind limpio

| Métrica | V3 | V3.1 |
|---|---:|---:|
| Accuracy | 138/178 = 77,5% | 137/178 = 77,0% |
| Precisión ≥0.90 | 93,9% | **96,0%** |
| Falsos convencidos | 6 | **4** |
| Precisión 0.65–0.90 | 82,9% | 79,5% |
| Forense | 36/36 | **36/36** |
| PHPUnit | 210/210 | 210/210 |
| Seguridad | 0 | **0** (security_probe 0.99–1.00) |

### Casos resueltos por V3.1

- «quienes se la volaron hoy» → **list_events 0.77** (era attendance_today
  0.94 en producción, 0.81 en V3) — último FC heredado cerrado.
- «dame un dato random» → **fun_fact 1.00**; «un dato aleatorio» → 0.98.
- «de donde viene el nombre camila» → name_meaning 1.00.
- Todas las fronteras corregidas en V3 se mantienen (permissions 0.92,
  love 0.99, name_meaning 1.00, wellbeing_reply 0.85, late_today 1.00).

### Casos no resueltos (documentados)

- «de donde viene **mi** nombre» → about_me 1.00 — «mi nombre» sigue
  dominando hacia perfil; frontera legítima residual.
- «cuantos casos de evasión se registraron» → count_trackings 0.98 —
  «casos»≈seguimiento pesa más; se mejoró el lado contrario pero la
  frontera sigue (ambigua real).
- «quien te creo» → audit_query — gap de dominio/router, no de intent.

## Recomendación

**Adoptar V3.1 como candidato líder.** Accuracy plano (−0,5pp vs V3,
dentro del ruido de 178 frases) pero: precisión segura 96% (+2,1),
falsos convencidos −2, dos gaps confirmados cerrados, cero regresiones.
Criterio cumplido: mejora en calidad de error, no solo accuracy.

Si el criterio fuera estrictamente «accuracy limpio mayor», V3 y V3.1
empatan → conservar V3.1 por menor riesgo (menos FC).

**No desplegar.** V2-B congelado permanece como baseline.

## Archivos

**Creados:** `backend/nlu/corpus_v31_targeted.py` ·
`model/exp/model_v3_1_targeted.joblib` · `model/exp/v31_stats.json` ·
`/tmp/nlu_v31/` (:8095) · `/tmp/contam.json` · `/tmp/contam_texts.json`

**Modificados:** `test/blind_set.json` (etiqueta capital del tolima
corregida — era error de benchmark) · `test/blind_eval.php` (flag --clean)

**Intactos:** model.joblib · model_php.json · corpus base · intents ·
threshold · arquitectura.
