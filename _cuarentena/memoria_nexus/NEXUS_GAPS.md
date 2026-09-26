# Nexus — gaps y causas persistentes

Pregunta rectora: ¿Qué puede hacer NEXO que Nexus todavía no puede pedir, expresar o componer mediante lenguaje natural?
Estado: auditoría + correcciones aplicadas (checkpoint 427319e + posterior). No clasificar hipótesis como fallos confirmados.

| Familia | Evidencia/estado inicial | Siguiente medida |
|---|---|---|
| semantic | No verificado | Contrastar IR con planes requeridos |
| context | No verificado | Ejecutar continuidad y retornos de tema |
| entity | No verificado | Mapear esquema, identificadores y resolución |
| relation | No verificado | Contrastar joins/relaciones con registry |
| planner | No verificado | Ejecutar composición y dependencias |
| capability | No verificado | Comparar inventario real y cobertura |
| presentation | No verificado | Comprobar conservación del dataset y UI |
| data | No verificado | Verificar grounding y entorno de pruebas |
| RBAC | No verificado | Verificar autorización antes de ejecución |
| response | No verificado | Comparar respuesta contra resultado real |
| performance | Sin medidas de sesión | Medir p50/p95/p99 por capa disponible |
| OOD | No verificado | Ejecutar negativos sin forzar intent |

## Hallazgos de baseline y código

- ~~semantic/fallback: grado numérico → math_operation~~ **RESUELTO** (cobertura rerutea; «estudiantes de grado 9» ya llega a students.list por ruta Python — fallback :9 conserva 1 caso residual conocido). Propiedad explícita y modificación temporal: cubiertas por coverage overrides.
- ~~planner/dependencies~~ **RESUELTO**: validator rechaza forward/self/orphan refs, executors arbitrarios, efectos no-READ, slices/positions inválidos; `each` itera el result-set (cap 12); delegación re-corre `chatAllowed` sobre el intent declarado. Contrato: NexusPlanInvariantTest (34).
- ~~context/result-reference~~ **RESUELTO**: R-ids monotónicos (`next_rid`), objects[] sin ítems PII (identidad+filtros+count), `person` se descarta al anclar otro estudiante; nav por cursor y set persistido server-side.
- ~~presentation/paginación UI~~ **RESUELTO**: DataCard pagina 10/pág con accesibilidad completa (caption, scope, aria-live, select+botones) + test. Pendiente: sort/proyección para tipos no-estudiante (columnas fabricadas).
- ~~response/history~~ **RESUELTO**: payload_json decodificado, rama por sesión oldest→newest, últimos 200 conservados.
- ~~capability/data (nombres de tablas)~~ **RESUELTO** en harness (`$entityTables` vs sql/schema.sql). Pendiente real: LIMIT 400 trunca conteos `all` — sigue abierto (verificar si el executor cuenta antes de cortar).
- ~~evaluation~~ **RESUELTO**: consistency real (inherited ⇒ respaldo en ctx/_ref), continuity_50 exit+aserciones, blind_eval exit-code, capability_eval sin auto-compare, G7b=0.
- integration: sigue vigente — BD real/HTTP/demos no verificables en alcance local (decisión del usuario tras bind-mount denied).

## Hallazgos nuevos del segundo ciclo

- **security/esquema**: «abre la tabla de usuarios» llegaba a derive_action — RESUELTO (probe por `tabla de <infra>` / `base de datos|esquema`). Detectado SOLO al endurecer G7b — la tolerancia previa lo ocultaba.
- **entity/extracción**: residuo post-stopword producía nombres («cuantica», «nombre») — RESUELTO en ambos extractores (paridad G3 intacta). Marcadores de persona extendidos.
- **semantic/meta-tema**: «cuéntame sobre X» sin dominio forzaba student_summary — RESUELTO (foreign_culture).
- ~~modelo/corpus acudiente~~ **RESUELTO**: +16 paráfrasis en corpus + retrain (argmax 95.8→98.6%) + sinónimos relacionales en `nxFieldSynonyms` + `_ref=guardian`. Único blind vivo: «quiero que el representante del alumno se presente en coordi» (paráfrasis de operación — mismo tipo de gap de corpus).
- **semantic/rerank (preexistente, encontrado por prueba manual)**: «muéstrame los estudiantes del 6-A» → `list_events` — el léxico de list_events («muestrame/dame») robaba el turno del modelo (0.998) porque `students_in_group` carece de entrada léxica y coverage corre antes del rerank. RESUELTO con corrección post-rerank espejo + regresión en dsm_units §N.
- **entity/campo (preexistente)**: `str_contains('ti')` hacía match dentro de «institu**ti**ción» → `field=documento` espurio. RESUELTO: sinsortas ≤3 letras exigen límite de palabra.
- **blind_eval 73.6%**: diagnóstico honesto — categorías débiles: emocion 4/10, frontera_nombre 4/10, frontera_social 6/10, fuera_dominio 5/8, evasion_formal 2/4. Son gaps de cobertura NLU conocidos.
- **registro**: referencias colgantes (result_nav, students.summary, risk.alerts…) — verificar si son intencionales (delegación) o huérfanos. Pendiente de barrido.

## Reglas de seguimiento

## Reglas de seguimiento

- Cada fallo conservará caso reproducible, causa familiar, before/after y pruebas de regresión.
- No eliminar negativos, reducir población ni cambiar umbrales para ocultar fallos.
- Separar capacidades ausentes de NEXO de capacidades existentes no expuestas por Nexus.
- Un fallo preexistente que afecte al usuario sigue siendo un gap del producto.
