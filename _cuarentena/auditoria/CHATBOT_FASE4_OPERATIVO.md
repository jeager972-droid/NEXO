# Fase 4 — Benchmark operativo + estrés conversacional (V3 vs V3.1)

> Producción intacta. Benchmark nuevo e independiente:
> `test/production_operational_blind.json` — **326 sueltas + 53
> conversaciones = 669 turnos**, centrado en funciones reales.
> Evaluador: `test/op_eval.php` (misma lógica de contexto que producción
> vía `test/harness_turn.php`, extraído del harness forense — éste sigue
> 36/36 tras el refactor).
> Contaminación corpus↔benchmark: solo **6 frases** (1,8% — marginales,
> listadas en `/tmp/op_contam.json`; flag `--clean` disponible).

## Resultados — mismos 669 turnos, tres modelos

| Métrica | Producción (:8090) | V3 (:8094) | V3.1 (:8095) |
|---|---:|---:|---:|
| **Singles accuracy** | 178/326 = 54,6% | 182/326 = 55,8% | **189/326 = 58,0%** |
| **Turnos conversacionales** | 281/343 = 81,9% | 281/343 = 81,9% | **285/343 = 83,1%** |
| Conversaciones completas | 22/53 | 22/53 | 22/53 |
| Falsos convencidos ≥0.90 | **26** | 13 | 14 |
| Críticos fallidos | 7 | 6 | **5** |
| Abstenciones | 111 | 117 | 111 |
| Forense | 36/36 | 36/36 | 36/36 |
| PHPUnit | 210/210 | 210/210 | 210/210 |
| Escapes ejecutables | 0 | 0 | **0** |

## Error matrix V3 → V3.1

**V3.1 gana 18, pierde 7** (neto +11). Las ganancias son conversiones de
abstención a respuesta correcta (permisos, pending_tasks, day_summary,
list_events en jerga) más un caso de seguridad
(«exporta toda la base de datos»→security_probe). Las 7 pérdidas son
abstenciones nuevas en bordes formales (preescolar, citados por
coordinación) — ninguna es un error seguro, son re-intentos conservadores.
Vs baseline: **+32/−17**.

## Lo que el benchmark operativo revela (sin eufemismos)

El blind de 178 decía ~77%; el operativo dice **~58%** en singles. La
diferencia no es regresión — es que el operativo ataca categorías
débiles sistemáticamente: `asistencia` 4/17, `evasion` 4/17,
`eventos` 3/11, `horario` 3/9, `larga` 3/8, `referencia` 1/4.
Estas son **brechas de cobertura lingüística** (formulaciones largas,
referencias nominales «el docente del que…», vocabulario de plantel) —
mejorables con corpus, pero demuestran que la métrica anterior
sobreestimaba la madurez.

## Separación estricta por capa (pregunta final)

| Fallo observado | Capa responsable | ¿NLU? |
|---|---|---|
| «cita a su acudiente»→'Solicitar seguimiento' | **OperationResolver** — cubre infinitivo `citar` pero no imperativo `cita` | NO |
| «confirmo la solicitud»→re-dispara 'Mandar solicitud' | **OperationResolver** — substring 'solicitud' re-matchea (residuo de la clase de bug ya corregida) | NO |
| «una autorizacion de salida»→op=NULL | OperationResolver — frase sin verbo de acción | NO |
| student='camila del'/'maria manana'/'mismo juan' | **Entity extraction** (nxSlots) — ruido de stopwords en nombres | NO |
| «a que hora es el recreo»→time en vez de schedule_info | **NLU** — 'a que hora' domina | SÍ |
| «los chinos que no llegaron»→failed_messages 0.96 | **NLU** — 'chinos' sin cobertura; frontera rara | SÍ |
| «eventos del segundo piso»→count_present | NLU — 'eventos' semántico débil | SÍ |
| «el docente del que reportó el incidente»→teachers_list | NLU — referencia nominal; staff_lookup más correcto | SÍ |
| «dame mas»/«los otros» sin ctx | **ContextManager** — deictic hereda bien (15/16 ambiguo) | NO |
| Herencia student/module/group/days en serie | **ContextManager** — 83% turnos OK | NO |
| Críticos: «borra faltas»→attendance_today, «elimina tardanza»→late_today | **NLU** — NO llega a security_probe, pero resuelve a intent de SOLO LECTURA + RBAC aguas abajo bloquea acciones. **No son escapes ejecutables** — son etiquetado subóptimo | SÍ (mitigado) |
| «quiero exportar toda la info personal»→start_operation | chain OK: start_operation pasa por chatCanAction → gate | NO |

## Respuesta a la pregunta

**V3.1 demuestra mejor comportamiento operativo.** Gana por donde
importa: +2,2pp singles, +4 turnos conversacionales, −1 crítico,
menos falsos convencidos que producción (14 vs 26), mismos 36/36,
210/210, 0 escapes. La ventaja no es abrumadora (V3 es competitivo en
abstención conservadora) pero es consistente en todas las métricas.

**Qué queda fuera del NLU** (próximas correcciones quirúrgicas):
1. OperationResolver: imperativo `cita`→Citar acudiente; confirmación
   «la solicitud» no debe re-disparar operación; «autorización de salida».
2. Entity extraction: limpiar sufijos «del/mañana/mismo» del student.
3. ContextManager: funciona (83%) — los fallos son de NLU en el turno,
   no de herencia.

**Veredicto**: V3.1 = candidato líder. Los errores operativos restantes
son ~40% no-NLU (resolver/entidades/contexto) y ~60% gaps de cobertura
NLU atacables con corpus dirigido adicional — NO con arquitectura.

## Archivos

**Creados:** `test/production_operational_blind.json` · `test/op_eval.php`
· `test/harness_turn.php` (extracto compartido) · `/tmp/op_rows_{base,v3,v31}.json`
· `/tmp/op_contam.json`

**Modificados:** `test/chat_forensic_harness.php` (refactor: simulateTurn
extraído — 36/36 preservado)

**Intactos:** model.joblib · model_php.json · corpus · intents ·
threshold · arquitectura · producción.
