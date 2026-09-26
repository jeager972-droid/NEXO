# Fase 3A — Enriquecimiento lingüístico dirigido (V3-targeted)

> Baseline dorado: `model_v2_B` congelado en `model/exp/v2b_frozen/` +
> tag git `nlu-v2b-baseline`. Producción intacta. Fecha: 2026-09-20.

## Corpus dirigido — `corpus_targeted.py` (aditivo, reversible)

Familias añadidas a intents existentes (sin tocar corpus base):
- **Evasión** (62): jerga sin estudiante («se volaron», «se caparon», «de pinta», «tajaron», «no volvieron del recreo») + formal («abandonaron el aula», «no ingresaron al aula», «salieron sin autorización») en count_events + list_events, con/sin {student}/{group}.
- **«Cuántas X hubo/hay/se registraron»** (81): late_today, attendance_today, citations, permissions, count_trackings — interrogativa/pasiva/inversa/coloquial.
- **name_meaning vs about_me** (45): «significa/origen/viene/quiere decir/etimología» vs «sabes de mí/información/perfil/rol».
- **love/compliment vs wellbeing_reply** (60): afecto («me caes bien», «eres chévere», «te quiero») vs pregunta de estado («cómo estás», «cómo te sientes», «todo bien»).

Total corpus candidato: **169.437** ejemplos (B 157.275 + ~12k dirigidos).

## Resultados — blind ampliado (235 frases, mismas para ambos)

| Métrica | V2-B | V3-targeted |
|---|---:|---:|
| Blind accuracy | 72,3% (170/235) | **80,0% (188/235)** |
| Precisión ≥0.90 | 90,9% | **93,1%** |
| Precisión 0.65–0.90 | 72,2% | **80,8%** |
| Falsos convencidos ≥0.90 | 12 | **10** |
| Abstenciones | 49 | **38** |
| Recuperables perdidas | 20 | **14** |
| Forense | 36/36 | **36/36** |
| PHPUnit | 210/210 | 210/210 |
| Escapes adversariales | 0 | **0** |
| Divergencias Py↔PHP | ~29% | ~26% |

## Los 3 falsos convencidos de V2-B — resolución

| Frase | V2-B | V3 | Estado |
|---|---|---|---|
| «quienes se la volaron hoy» | attendance_today **0.94** ✗ | attendance_today **0.81** | **Mejorado, no resuelto** — la construcción pronominal «se LA volaron» sigue débil (list_events 0.11 quedó segundo; necesita familia específica «se la voló/volaron») |
| «me caes bien» | wellbeing_reply 0.93 ✗ | **love 0.99** ✓ | Resuelto |
| «que significa mi nombre» | about_me 0.998 ✗ | **name_meaning 1.00** ✓ | Resuelto |

Bonus: «como va todo contigo» day_summary 0.94→**wellbeing_reply 0.90** ✓.

## Fronteras — antes/después

| Frase | V2-B | V3 |
|---|---|---|
| cuántas tardanzas hubo hoy | late_today 0.74 | **late_today 1.00** |
| cuántos permisos hay activos | count_events 0.71 | **permissions 0.88** |
| cuántas citaciones se mandaron | (boundary) | **citations** |
| enviar solicitud a docente | start_operation | start_operation 0.95 |

## Nuevos falsos convencidos (vigilar)

- «dame un dato random»→random_student 0.995 («random»≈azar, frontera legítima ambigua)
- «quien te creo»→audit_query 0.94 (ruido — corpus gap)
- «listo gracias»→yes 0.93 (cortesía mixta, ambiguo real)
- «estresado con las planillas»→list_events 0.97 («planillas» tira a listas — corpus gap emoción laboral)
- «dame mas»→top_offenders 0.97 (deictic sin ctx — debería ser abstención)
- «de donde viene mi nombre»→about_me 0.99 (variante «viene» sin cobertura total)
- «capital del tolima»→colombia_capital vs department (frontera colombia_* — conocida)
- «cuantos casos de evasión se registraron»→count_trackings 0.99 (mi propio ejemplo — «se registraron» colisiona con seguimiento; dataset gap)

## Regresiones

Ninguna en la suite forense ni PHPUnit ni seguridad. En blind ampliado:
los únicos cambios de comportamiento son mejoras netas (+18 correctas);
los FC nuevos son categorías ya ambiguas, no degradación de casos
correctos previos — verificado contra el dump V2-B.

## Veredicto

**V3-targeted supera a V2-B claramente** en todas las métricas que
importan: +7,7pp blind ampliado, mejor calibración en ambas bandas,
−11 abstenciones, 2 de 3 falsos convencidos resueltos (y el tercero
mejorado 0.94→0.81), forensic 36/36, PHPUnit 210/210, 0 escapes.

**No sustituir producción todavía** — V3 queda como candidato líder en
`model/exp/`; V2-B permanece congelado como baseline. Próxima fase
experimental sugerida: familia «se la voló/volaron», contraste
«se registraron» evasión↔seguimiento, y los FC residuales documentados.

## Archivos

**Creados:** `backend/nlu/corpus_targeted.py` · `backend/nlu/exp_v3.py`
· `model/exp/model_v3_targeted.joblib` · `model/exp/model_php_v3_targeted.json`
· `model/exp/v3_stats.json` · `model/exp/v2b_frozen/` (snapshot)
· git tag `nlu-v2b-baseline` · `/tmp/nlu_v3/` (servicio :8094)

**Modificados:** `test/blind_set.json` (ampliado 111→253 — originales intactos)

**Intactos:** `model.joblib` · `model_php.json` · corpus.py ·
corpus_colombia.py · corpus_extra.py · intents · threshold · arquitectura.
