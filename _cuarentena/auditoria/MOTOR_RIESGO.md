# Motor de Análisis de Riesgo Pedagógico — Diseño y Configuración

> Referencia: `backend/api/lib/RiskEngineV3.php`, `sql/schema.sql` (tablas `risk_*`),
> `backend/api/routes/risk.php`. Auditoría relacionada: §riesgos del documento
> funcional (`documento_final.txt`).

## Qué hace

El motor convierte eventos conductuales en señales pedagógicas **informativas y
proporcionales** — no diagnósticos ni alarmas automáticas. Ninguna alerta se
emite sin evidencia acumulada y configurable, y la decisión final siempre es
humana (Coordinación).

## Ontología de niveles (fija, no editable)

| Nivel            | Disparo por defecto                                | Efecto |
|------------------|---------------------------------------------------|--------|
| SIN_IMPORTANCIA  | —                                                 | No alimenta el cálculo |
| LEVE             | 4 reincidencias en 7 días                          | Observación |
| MODERADA         | 3 reincidencias en 10 días                         | Alerta a coordinación |
| ALTA             | 2 reincidencias en 15 días                         | Requiere revisión humana |
| MUY_ALTA         | 1 ocurrencia                                       | Alerta inmediata (single-occurrence, protegido) |

## Máquina de estados de escalamiento

`OBSERVACION → ALERTA_PEDAGOGICA → SEGUIMIENTO → INTERVENCION_PRIORITARIA →
ATENCION_INMEDIATA`, con resolución `resuelta`/`descartada` por acción humana.
Las alertas son **pedagógicas e informativas**: describen patrones observados,
no emiten juicios.

## Qué configura la institución (versionado por `risk_policies`)

Mediante `POST /risk/policy` (RECTOR/COORDINATOR, con `change_reason`
obligatorio ≥10 chars):

- **Mapeo evento → nivel** (`risk_event_level_mapping`): qué incidente
  institucional cuenta como LEVE, MODERADA, etc.
- **Umbrales de activación** (`risk_rules.activation_threshold`,
  `min_threshold`, `max_threshold`) dentro de rangos protegidos.
- **Vida media** del decaimiento exponencial (`half_life_days`) — los eventos
  pierden peso con el tiempo.
- **Cooldowns** entre alertas del mismo tipo.
- **Reglas de combinación** (`risk_combination_rules`): correlación entre
  categorías (p.ej. ausencias + tardanzas + incidentes disciplinarios).
- **Textos de notificación** institucionales.

Guardar una política marca `schools.risk_config_completed = TRUE`
(cierre del onboarding gate — corregido en Bloque B/C: antes ninguna acción
podía completar ese requisito y la escuela quedaba bloqueada en 428).

## Lo protegido (no editable por la institución)

- Definición de los 5 niveles y su semántica.
- `MUY_ALTA` siempre es single-occurrence.
- Pisos/techos de umbrales (`min_threshold`/`max_threshold`).
- Deduplicación y filtros de contexto obligatorios.
- Auditoría de cada versión de política (`risk_audit_log`) y justificaciones
  (`risk_justifications`).
- Lenguaje no diagnóstico de las alertas.

## Datos de entrada

- `attendance_incidents` (INASISTENCIA, LATE, EVASION, …) — los incidentes
  `pending_context` de `ANOMALIA_OPERATIVA` (F-05) **no** alimentan el motor
  hasta triaje humano, evitando que fallas operativas inflen riesgo individual.
- `security_incidents`.
- Calendario escolar (`school_calendar`) para filtros de contexto.

## Salidas

- `risk_active_snapshot`: nivel actual por estudiante/categoría.
- `risk_alerts`: alertas con ciclo de vida `abierta/en_seguimiento/resuelta/
  descartada` gestionado por Coordinación vía API.
- Endpoints: `GET /risk/policy`, `GET /risk/policy/history`,
  `POST /risk/policy`, `GET /risk/event-types`, `GET /behavior/risk`.

## Configurabilidad verificada

- Los umbrales NO están hard-coded: viven en `risk_rules` por `policy_id`
  versionada por escuela.
- `ANOMALY_MIN_ABSENCES` / `ANOMALY_GROUP_FRACTION` (anomalías operativas F-05)
  son variables de entorno con defaults documentados.
- Las rutas de notificación de respuestas de acudientes y escalaciones son
  configurables por escuela en `school_notification_routes` (Bloque C).
