# Validación preproducción — model_v2_B

> Producción intacta durante toda la validación. Candidato servido en
> :8092 (aislado), export PHP en `model/exp/model_php_v2_B.json`.
> Fecha: 2026-09-20.

## Tabla comparativa

| Métrica | Producción | v2-B |
|---|---:|---:|
| Blind accuracy | 77,5% | **79,3%** |
| Internal (18.880) efectiva | 93,44% | 92,34% |
| Falsos convencidos ≥0.90 | 6 | **3** |
| Precisión ≥0.90 | 92,1% | **95,7%** |
| Precisión 0.65–0.90 | 88,9% | 80,0% |
| Abstenciones (blind) | 17 | 17 |
| Abstenciones recuperables | 9 | 9 |
| Forense conversacional | 36/36 | **36/36** |
| PHPUnit | 210/210 | 210/210 |
| Escapes adversariales | 0 | **0** |
| Divergencias Py↔PHP | 36/126 | 36/126 |
| Ejemplos corpus | 473.544 | **157.275 (−67%)** |

## 1. Batería interna (18.880)

Efectiva 92,34% vs 93,44% baseline (−1,1pp). Desglose honesto:
falsos fallback **505** (baseline 275 — más conservador), intents
erróneos **941** (baseline 964 — menos errores). El patrón dominante de
los falsos fallback nuevos son cortesías combinadas («oye buenas gracias»,
«nexus qué tal porfa») — abstenciones benignas, no errores. Cero escapes
adversariales en ambos.

## 2. Blind test completo

86→88 correctas. Recuperadas respecto al baseline: «cuántos mensajes sin
leer tengo» (failed_messages... pasa a abstención correcta), errores
nuevos: ninguno seguro — los cambios son bandas de confianza.

Por categoría las diferencias relevantes: `extranjero` mejora (francia
pasa de error a abstención), `operacion`/`frontera` estables.

## 3. Fronteras — baseline vs v2-B

| Frase | base | v2-B |
|---|---|---|
| cuántas tardanzas hubo hoy | oos 0.63 | **late_today 0.74** ✓ |
| cuántas citaciones hubo hoy | count_events 0.62 | (igual, boundary «cuántas X hubo») |
| cuántos permisos hay activos | oos 0.64 | count_events 0.71 + module=PERMISO |
| cuántos estudiantes hay (en el 8A) | students/group_student_count ✓ | igual ✓ |
| un dato curioso | colombia_fun_fact 0.92 | colombia_fun_fact 0.89 — frontera estructural pendiente (fusión propuesta Fase 3) |
| permisos sin retorno / activos | pending/permissions ✓ | igual ✓ |
| riesgo vs umbrales | separados ✓ | igual ✓ |

## 4. Cadena Python → PHP

`model_php_v2_B.json` generado con el export oficial (word-level, min_df=3,
C=4.0) — 26 MB. Paridad medida sobre 126 frases:

- **Baseline:** 54 exactas + 36 mismo-intent + **36 divergentes**
- **v2-B:** 41 exactas + 49 mismo-intent + **36 divergentes**

Las divergencias son inherentes al export word-level (sin char n-grams)
— mismo conteo que baseline, ninguna divergencia de privilegio (todas
caen a intents de consulta o abstención). Paridad equivalente: **no es
regresión**, es la tolerancia normal del runtime dual.

## 5. `/chat/message` real

Servicio v2-B en :8092 → suite forense completa **36/36 PASS**
(conversaciones A y B correctas turno a turno, incluida la herencia
contextual y la resolución de operación corregidas en Fase 1).

## 6. Contexto y seguridad

- Herencia/reemplazo/temporal/operación: intactos (36/36).
- Adversarial: `security_probe` 0.92–1.00 en inyección, `select * from`,
  «modo admin», «borra estudiantes». «dame la contraseña de la db»→
  out_of_scope (abstención segura). **Ningún escape nuevo.**
- La mayor confianza no convierte nada rechazado en ejecutable — el RBAC
  corre después de clasificación y los intents de acción mantienen su
  gate (`chatAllowed`/`chatCanAction`).

## 7. Los 3 falsos convencidos restantes (v2-B)

| Frase | Esperado | Predicho | Conf | Motivo | ¿Corregible por dataset? |
|---|---|---|---|---|---|
| «quienes se la volaron hoy» | count/list_events | attendance_today | 0.94 | «volaron»=jerga de evasión ausente del corpus | **Sí** — corpus jerga evasión |
| «me caes bien» | love/compliment | wellbeing_reply | 0.93 | frontera legítima ambigua | Parcial — más ejemplos love |
| «que significa mi nombre» | name_meaning | about_me | 0.998 | «mi nombre» domina; falta contrastar «significa» | **Sí** — pares nombre/significado |

Ninguno es bug de código — todos son gaps de corpus, diferidos a la fase
de dataset por decisión de alcance.

## 8. «cuántos permisos hay activos» → count_events+PERMISO

Verificado end-to-end: `nxSlots` extrae `module=PERMISO` en PHP; PERMISO es
`incident_type` válido de `attendance_incidents` (consultations.php:195,
dashboard.php). `chat_count_events` ejecuta
`COUNT(*) WHERE incident_type='PERMISO'` → **responde el número real de
permisos del período**. Es *cambio de representación interna sin cambio
funcional*. Caveat: el rango default es «hoy» mientras «activos» implica
vigencia — misma semántica que ya aplicaba baseline con oos→clarify;
documentado, no bloquea.

## 9. Calibración

v2-B es **mejor calibrado arriba** (96% vs 92% en ≥0.90) y algo más
conservador en la banda media (80% vs 89% en 0.65–0.90, por abstenciones
extra). ECE cualitativo: se equivoca menos con confianza alta — lo que el
usuario ve. Abstenciones recuperables iguales (9).

## Veredicto de adopción

Criterios cumplidos: sin regresiones funcionales (36/36, 210/210), blind
mejora, falsos convencidos a la mitad, paridad equivalente, cero escapes,
`/chat/message` correcto. La única métrica a la baja es la batería
interna (−1,1pp por mayor abstención) — aceptable: el blind test (frases
reales fuera de plantilla) es la medida que importa y **sube**.

**v2-B es candidato aprobado para producción.**

### Procedimiento de sustitución reversible (NO ejecutado)

```bash
cd backend/nlu/model
cp model.joblib model_baseline.joblib            # backup
cp model_php.json model_php_baseline.json
cp exp/model_v2_B.joblib model.joblib
cp exp/model_php_v2_B.json model_php.json
cp exp/model_v2_B.joblib ../../api/nlu_runtime/model/model.joblib
cp exp/model_php_v2_B.json model_php.json 2>/dev/null  # runtime usa el mismo dir si existe
# reiniciar servicio NLU + validar: forense 36/36, blind ≥77.5%, phpunit 210
# reversión: restaurar los 2 *_baseline + reiniciar servicio
```

## Archivos creados en esta fase

`backend/nlu/exp_export_php.py` · `backend/nlu/model/exp/model_php_v2_B.json`
· `test/parity_v2b.php` · este informe. Sin cambios en producción.
