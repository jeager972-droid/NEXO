# Nexus — gaps y causas persistentes

Pregunta rectora: ¿Qué puede hacer NEXO que Nexus todavía no puede pedir, expresar o componer mediante lenguaje natural?
Estado: auditoría en curso. No clasificar hipótesis como fallos confirmados.

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

- semantic/fallback: grado numérico puede convertirse en math_operation; propiedad explícita en student_summary; modificación temporal en birthdays_today. Baseline reproducida, corrección pendiente.
- planner/dependencies: referencias siempre step 0; validator no rechaza forward refs/ciclos; each no materializa iteración; delegación depende de metadata libre del plan. Pendiente de pruebas conductuales.
- context/result-reference: IDs R7 repetidos tras seis objetos; cursor explícito descartado cuando se devuelve result_set; person anterior puede sobrevivir a un nuevo estudiante. Pendiente de regresión.
- presentation: proyección no persiste para la transformación siguiente; sort fabrica columnas estudiantiles incluso para otros tipos; no hay paginación UI.
- response/history: payload_json no se decodifica y el historial ASC se invierte otra vez, perdiendo tarjetas/acciones y orden. Evidencia de fuente, falta test local.
- capability/data: inventario usa nombres de tablas incorrectos; LIMIT 400 puede convertir conteos/all en cifras truncadas.
- evaluation: real_conversation_v1 simula estado y su dimensión consistency se incrementa sin aserción; continuity_50 acepta cualquier palabra en varios turnos y no falla su exit code.
- integration: usuario seleccionó Solo pruebas locales tras Permission denied de bind mounts. BD real/HTTP/demos no verificables en este alcance.

## Reglas de seguimiento

- Cada fallo conservará caso reproducible, causa familiar, before/after y pruebas de regresión.
- No eliminar negativos, reducir población ni cambiar umbrales para ocultar fallos.
- Separar capacidades ausentes de NEXO de capacidades existentes no expuestas por Nexus.
- Un fallo preexistente que afecte al usuario sigue siendo un gap del producto.
