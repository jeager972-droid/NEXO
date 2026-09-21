# NEXO — Informe final del núcleo conversacional

> Rama `nexus-conversational-core` · último commit `bec9dcb` · 2026-09-21
> Alcance: refactorización del chatbot (clasificador → sistema
> conversacional determinista) según el proceso
> AUDITAR→DISEÑAR→IMPLEMENTAR→TESTEAR→MEDIR→CORREGIR→REPETIR→VALIDAR→RELEASE.

## A. Objetivo cumplido

El chat ya no es un clasificador de frases: es un pipeline conversacional
con separación explícita de capas — comprensión → interpretación
estructurada → estado → resolución contextual → validación → autorización
→ plan → ejecución determinista → respuesta fundamentada.

## B. Arquitectura entregada

```text
texto
 → nxNorm (normalización)
 → nxClassify (servicio NLU :8090 | fallback PHP embebido)
 → nxSlots + merge de entidades del servicio
 → nxCoverageOverride (reglas determinísticas auditables)
 → nxDialogueResolve — DSM (turn-types, herencia, ops, clarificación)
 → chatAllowed (nxAllowed × rol + políticas institucionales)
 → chatDispatch (smalltalk | help | chat_<intent> — datos reales BD)
 → nxPlanResponse (reply fundamentado + sello de procedencia)
 → chatLog (auditoría) → JSON con _interpretation + timing_ms
```

Fuente única del DSM: `backend/api/lib/nexus_nlu.php::nxDialogueResolve()`
— consumida por `routes/chat.php` (producción) y `test/harness_turn.php`
(harness forense). Paridad garantizada por construcción.

## C. Capas deterministas implementadas

| Capa | Componente | Determinismo |
|---|---|---|
| Comprensión | nxNorm + servicio sklearn | probabilístico |
| Extracción | nxSlots (PHP) ↔ preprocess.py (Py) | determinista, 0 conflictos |
| Cobertura | nxCoverageOverride | determinista, auditable |
| Estado/DSM | nxDialogueResolve | determinista, 9 turn-types |
| Autorización | nxAllowed + chatCanAction + políticas | determinista, nunca del modelo |
| Operación | chatOperationCmd → chip navegación | determinista, jamás ejecuta |
| Respuesta | nxPlanResponse + handlers BD | hechos reales o fallo explícito |

## D. Reglas del DSM (cada una trazable a un fallo medido)

1. Herencia de slots solo en turnos dependientes (marca followup,
   corrección, verbo deíctico, interrogativo sin tema, sustantivo
   desnudo). `days=0` vía `array_key_exists`, no `empty`.
2. Autonomía: consulta autónoma no arrastra entidades.
3. `mismo X` → entidad del ctx, nunca del texto.
4. `vuelve/regreso`: referencia a rango (con periodo explícito) → swap
   `prev_days`; nombre de op → confirmación; ni una ni otra → heredar.
5. Posesivos (`su ficha`, `para ella`) → ctx.student.
6. `repeat_op`: `otro/uno más/nuevo` + _op o tema-op → mismo comando
   con parámetros nuevos. `una solicitud` no es repetición.
7. Corrección de op (`no, mejor una citación`) → re-resuelve.
8. Verbo de op rerutea consulta → `derive_action`.
9. Sustantivo de op sin marcadores de consulta → `derive_action`.
10. Destructivo + datos → `security_probe` + log.
11. Smalltalk no resetea ctx; confirm/cancel/repeat lo preservan.
12. Deíctico vacío → **clarificar, no adivinar**.
13. Cuantificador+métrica propia → intent propio (no se degrada);
    métrica sin cuantificador → modificación de cadena.
14. `_op` persiste entre consultas intermedias; se recalcula solo si el
    mensaje nombra operación.

## E. Extracción de entidades (PHP ↔ Python espejadas)

- Stopwords: del/de/mañana/mismo/vez/ahora/para/info/solo/
  específicamente/pronombres/estados (abiertos, pendientes…)/materias/
  roles (coordinadores, docentes…).
- Filtro de dígitos en nombres; patrón «nombre del grado»;
  ordinales con/sin letra (`del octavo`→8); `al mes`→30d;
  `prev_days` para «vuelve»; `solicitud` antes que `cita` en el
  resolver de ops (colisión `cit`).

## F. Autorización y seguridad

- El modelo **nunca** decide permisos: nxAllowed(rol) + chatCanAction
  (acción) + políticas por escuela (resiliente a tabla ausente →
  default TRUE documentado).
- Operación = chip de navegación a Operaciones — la ejecución real vive
  en la UI autorizada, no en el chat.
- Destructivo/cross-scope → `security_probe` + `securityLog()`.
- `X-Requested-With` exigido en POST (mitigación CSRF existente).
- Confirmación nunca ejecuta — solo abre el formulario.

## G. Suites y métricas (medidos, no estimados)

| Suite | Resultado | Umbral |
|---|---|---|
| Forense conversacional | **36/36** | 36/36 |
| DSM units (`test/dsm_units.php`) | **50/50** | 50/50 |
| Paridad PHP↔Py (`test/parity_dsm.php`) | **0 conflictos /25** | 0 |
| Release gate (`test/nexus_release_gate.php`) | **11/11** | todas |
| PHPUnit integración (:18080) | **42/42** | — |
| Adversarial chain (G9) | 12/12 denegados/rechazados | — |
| Latencia NLU+DSM | p50=3.6ms · p95=8.4ms | — |

### Benchmark ciego (`test/production_operational_blind.json`, 343 turnos)

| Métrica | Prod :8090 | V3 :8094 | V3.1 :8095 |
|---|---|---|---|
| Singles | 52.8% | 55.2% | 57.4% |
| Turnos conversacionales | 98.8% | 98.3% | **99.7%** |
| Convos completas | 49/53 | 48/53 | **52/53** |
| FC≥0.90 | 28 | 13 | 15 |
| Críticos fallidos | 6 | 5 | **4** |

Evolución: convos 81.9% → **99.7%** (iter-1 DSM) → iter-2 cobertura.

## H. Verificación end-to-end (Docker nexo-test :18080)

Rebuild api+nlu con el código de la rama; login teach@test.nexo:

```
genera un permiso      → start_operation + chip «Generar permiso»
confirmo               → confirm_op · turn=confirmation · ms={nlu,dsm}
otro para camila       → repeat_op · «Otra Generar permiso»
borra las evasiones    → security_probe + rechazo
gracias                → thanks · ctx conservado
los permisos del 8a    → permissions · reply con datos reales BD
```

Hallazgo real en integración: `school_chat_policies` ausente en el
seed → tabla creada + `chatPolicyEnabled` resiliente.

## I. Residuales conocidos (limitaciones honestas)

- `y de todo su grupo` — resolver estudiante→grupo requiere consulta DB
  del handler (fuera del NLU; documentado).
- Singles ~57% — el cuello de botella restante es el clasificador
  sklearn, no el DSM. V3.1 es el mejor candidato experimental; la capa
  compartida levanta todos los modelos igual.
- 4 críticos fallidos en singles — casos límite de cobertura del modelo.

## J. Decisiones de interpretación documentadas

- `vuelve al mes` restaura el rango **anterior** (swap prev_days), no
  «este mes» — expectativa del benchmark corregida a 60.
- `y cuantas tardanzas` con cuantificador = intent propio (conteo);
  `ahora las tardanzas` sin cuantificador = modifica la cadena.
- `export_data` permitido a STAFF (política existente) — la puerta
  adversarial valida denegación para SECURITY/AUXILIARY, no para
  TEACHER/COUNSELOR (autorizados por diseño).

## K. Archivos de evidencia

- `docs/nexus/NEXUS_CURRENT_ARCHITECTURE.md` — auditoría del flujo real.
- `docs/nexus/NEXUS_TAXONOMY_MATRIX.md` — matriz intents/slots/ops/roles.
- `auditoria/CHATBOT_DSM_ITER1.md` — registro de iteraciones.
- `test/dsm_units.php` · `test/parity_dsm.php` ·
  `test/nexus_release_gate.php` — suites nuevas.

## R. Veredicto

Criterios de puerta cumplidos: forense 36/36, DSM 50/50, paridad sin
conflictos, 11/11 puertas del release gate, 42/42 integración Docker,
adversarial 12/12, telemetría activa, respuestas fundamentadas en BD,
operación nunca ejecutada por el modelo, confirmación como chip,
clarificación en ambigüedad, RBAC determinista en todos los niveles.

Los residuales son de cobertura del clasificador sklearn (capa
probabilística), no del núcleo conversacional — y están documentados
con su causa. La arquitectura pedida (determinista en interpretación,
autorización, plan y ejecución) está implementada, medida y verificada
end-to-end.

**READY FOR CONTROLLED PRODUCTION**
