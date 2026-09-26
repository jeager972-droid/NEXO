# 12. Guía de implementación frontend

**Alcance:** arquitectura visual y de producto sin código. No traslada reglas de negocio al cliente. **Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md).

## 1. Arquitectura por capas

1. **Foundations:** tokens de color, tipo, espacio, motion, breakpoints, locale y temas.
2. **Primitives:** texto, icono, botón, campo, foco, superficie.
3. **Components:** controles y contenedores genéricos `CMP-001–041`.
4. **Patterns:** NEXO `CMP-100–108`, composición con semántica de producto.
5. **Shells:** autenticación, institucional, lista+detalle, flujo guiado.
6. **Features:** auth, home, operations, notifications, cases, queries, audit, reports, enrollment, profile, PWA.
7. **Pages:** ensamblan features por ruta/rol; no contienen reglas de negocio ni estilos ad hoc.

Dependencias apuntan hacia abajo. Feature no importa otra feature; comparte patrón o servicio tipado. Pages no duplican componentes.

## 2. Ownership y límites

| Capa | Owner | Puede decidir | No puede decidir |
|---|---|---|---|
| Foundations/System | Design System + FE | tokens, API visual | reglas de negocio |
| Components | FE platform + a11y | estados/interacción | permisos/riesgo |
| Patterns | squad + Product | composición NEXO | cálculo backend |
| Features | squad dominio | orquestación/data mapping | variantes visuales arbitrarias |
| Pages/Routes | app shell | acceso, layout, deep links | repetir RBAC autoritativo solo cliente |

## 3. Contratos de estado visual

Toda consulta se mapea a unión explícita: `idle`, `loading`, `success`, `empty`, `stale`, `offline`, `forbidden`, `error`. Toda mutación: `editing`, `validating`, `submitting`, `accepted`, `queued`, `succeeded`, `failed`, `unknown`, `conflict`.

El cliente representa estado backend, no infiere éxito:

| Backend/integración | UI |
|---|---|
| 200 + datos | success/empty según contrato |
| 202/queued | En cola, no Completado |
| delivery queued/sent/delivered/failed/replied | CMP-105 equivalente |
| 401 | sesión expirada, login con retorno |
| 403 | SCR-SYS-01 sin revelar datos |
| 404 | no disponible o removido según contexto |
| 409 | SCR-SYS-03 conflicto |
| 422 | errores de campo/forma |
| 429 | espera indicada por servidor |
| 5xx/network | error recuperable; preservar entrada |
| timeout mutante | unknown; consultar por idempotencia |
| caché | stale con fecha |

## 4. Datos y negocio

- Backend es fuente de permisos, riesgo, tendencia, destinatarios efectivos, validación, auditoría y estado WhatsApp.
- Frontend puede validar forma para feedback, nunca sustituir validación del servidor.
- Modelos de vista reducen y etiquetan datos; no replican entidades DB completas.
- Datos sensibles no se precargan “por si acaso”.
- IDs opacos en URL; no PII en query params, logs o telemetría.
- Fechas conservan zona/ISO en contrato y se presentan `es-CO`.

## 5. RBAC y routing

1. Sesión carga usuario, sede, rol y permisos desde backend.
2. Menú se deriva de una matriz canónica, no de arrays duplicados.
3. Route guard mejora UX; API siempre autoriza.
4. Deep link valida sesión, permiso y existencia antes de render.
5. 403 y 404 son estados distintos internamente, pero no filtran existencia sensible.
6. Acudiente no existe como rol WebApp navegable.
7. `SUPER_RECTOR`/`EDGE_NODE` no se mezclan con shell institucional objetivo.

Resoluciones documentadas en `DEC-IA-*`: rector en Consultas (DEC-IA-02), nombre Casos Activos (DEC-IA-01), discrepancia coordinador/auditoría (DEC-IA-03), y nomenclatura Portero/Psicoorientador (DEC-IA-07/08). Quedan pendientes de implementación en frontend/backend.

## 6. Contexto persistente

`GrupoContext` conceptual contiene ID, label, alcance, fuente y fecha. Persistencia por sesión/usuario, no global entre cuentas. Antes de reutilizar, verificar permiso/asignación. Ruta puede declarar `groupId` opaco para deep link; cambio invalida queries dependientes. Formularios sucios interceptan cambio y ofrecen guardar/cancelar.

Retorno guarda ruta origen, filtros serializables, scroll key y focus target. El historial del navegador sigue funcionando; no reemplazarlo con navegación interna paralela.

## 7. Formularios

- Esquema por operación suministrado/versionado; UI agrupa ≤5 decisiones por paso.
- Validación en blur/submit, no error agresivo al primer carácter.
- Resumen previo para comunicaciones, salidas y acciones masivas.
- Borrador solo en dispositivo confiable y con caducidad; notas sensibles no van a almacenamiento inseguro.
- Idempotency key por mutación crítica/reintentable.
- Errores del servidor se mapean a campo o resumen; desconocidos incluyen referencia.

## 8. Fetch, caché y offline

- Queries cancelables y deduplicadas; respuestas tardías no sobrescriben contexto nuevo.
- Cache keys incluyen sede, rol/permiso y grupo.
- Invalidación por evento o mutación, con TTL como respaldo.
- Datos cacheados muestran fecha; decisiones críticas fuerzan revalidación.
- Background Sync solo para acciones clasificadas seguras. **No** SOS, salidas, permisos críticos, notas sensibles o mensajes masivos sin evaluación de seguridad/idempotencia.
- Cola cifra/protege lo permitido, muestra conteo y permite inspección no sensible.
- Actualización del service worker no interrumpe formulario: avisar y aplicar en punto seguro.

La configuración actual de cache/POST demuestra infraestructura N2, no garantiza que cada mutación sea segura offline; requiere auditoría por endpoint.

## 9. Feedback optimista/pesimista

**Optimista permitido:** preferencia de tema, lectura local, reordenamiento no crítico reversible.  
**Pesimista obligatorio:** SOS, autorización de salida, permiso, cierre de caso, enrolamiento, cambio de contraseña, WhatsApp y auditoría.  
**Híbrido:** notas no sensibles pueden mostrar “guardando” local, pero no entrar al timeline auditado hasta confirmación.

## 10. Responsive/PWA/native

- Un DOM semántico cuando sea razonable; CSS cambia composición, no duplica contenido.
- Clases compact/medium/wide de [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md), complementadas por pointer/hover/keyboard/safe area.
- Sidebar sigue siendo árbol rector; móvil usa panel lateral, no bottom nav.
- PWA: manifest, standalone, offline shell, página offline, instalación contextual y update seguro.
- Tauri/Capacitor mediante puertos: secure storage, deep link, biometría del dispositivo, share, notifications y lifecycle. Web fallback obligatorio.
- Pedir permisos nativos just-in-time con explicación y alternativa.

## 11. Accesibilidad en arquitectura

Primitivos encapsulan foco, nombre, errores y targets; patterns implementan APG; pages proporcionan landmarks/título/foco de ruta. CI ejecuta axe y tests de teclado; release ejecuta matriz manual de [09_ACCESSIBILITY.md](./09_ACCESSIBILITY.md). No permitir escape de estilos que quite outline.

## 12. Rendimiento

- Presupuesto inicial por ruta y lazy loading por feature, sin skeleton de pantalla completa repetido.
- LCP objetivo ≤2.5 s p75, INP ≤200 ms, CLS ≤0.1 en equipos/redes objetivo.
- Feedback local ≤100 ms; virtualizar listas grandes con alternativa accesible.
- Imágenes de perfil responsivas y limitadas; iconos tree-shaken.
- Motion solo transform/opacity y se elimina bajo presión.
- Telemetría de Web Vitals y errores sin PII, muestreada y no bloqueante.

## 13. Theming e i18n

Tokens semánticos, no colores literales en features. Tema sistema/claro/oscuro; forced colors. Copy separado de componentes y preparado para expansión 30%. Primera locale `es-CO`; no concatenar frases, pluralizar correctamente y usar `Intl`. Zona horaria institucional explícita. No traducir nombres de roles técnicos en contratos API, sí labels de UI.

## 14. Testing

| Nivel | Cobertura |
|---|---|
| Unit | reducers/mappers, estados, validación de forma |
| Component | variantes, teclado, lector, error/loading/offline |
| Contract | esquemas API y mapeo de estados |
| Integration | RBAC, contexto, cache, formularios, sync |
| E2E | FLOW P0 por rol, timeout, 403, offline, retorno |
| Visual | temas, anchos, zoom, contenido extremo |
| A11y | axe + manual según matriz |
| Resilience | red lenta, duplicados, respuesta tardía, conflicto |

Fixtures usan dataset ficticio coherente. Un test nominal no eleva a Nivel 1; verificación requiere ejecución manual end-to-end registrada.

## 15. Observabilidad UX

Eventos con `SCR/FLOW/CAP`, paso, resultado, duración, error categórico, conectividad y versión. Prohibidos PII, texto de notas/mensajes, teléfono, documento y términos de búsqueda sensibles. Correlación técnica usa request ID opaco. El personal institucional debe ser informado de forma transparente de que se recopila telemetría anónima de uso para mejorar el producto; la deshabilitación no limita sus tareas. Dashboards observan abandono, retries, unknown outcomes, sync conflicts y accesibilidad.

## 16. Secuencia de implementación

1. Tokens, temas, a11y primitives y estados.
2. Shell/RBAC/contexto/retorno.
3. Auth y estados de sistema.
4. Home y grupo persistente.
5. Operaciones + idempotencia + WhatsApp.
6. Notificaciones NEXO.
7. Casos/riesgo.
8. Consultas.
9. Auditoría/informes.
10. Enrolamiento/biometría.
11. Perfil/PWA/offline.
12. Adaptadores native tras estabilizar web contracts.

No implementar agenda, recuperación, búsqueda global o Capacitor como existente hasta backend/contrato y estado técnico aprobados.

## 17. Definition of Done

- Trazabilidad `CAP → FLOW → SCR → CMP → endpoint` completa.
- Todos los estados y permisos implementados; no solo happy path.
- Claro/oscuro, compact/wide, teclado/touch/lector/reduced motion.
- Contrato y errores probados; mutaciones críticas idempotentes.
- Cero violaciones a11y altas, Web Vitals dentro de presupuesto.
- Telemetría sin PII y soporte con request ID.
- Documentación de brechas y decisión actualizada.
- Validación funcional manual registrada antes de usar “Verificado”.

## 18. Gobierno a diez años

Semver, changelog, owners, ADR/`DEC-*`, deprecación de dos releases y pruebas de migración. Revisión trimestral de variantes, semestral de accesibilidad y anual de dependencias/compatibilidad. Nuevos componentes exigen tres usos legítimos o necesidad normativa. Las decisiones de producto permanecen en estos documentos, no ocultas en código.

## 19. Matriz de trazabilidad técnica

| Capacidad | Flujo(s) | Pantalla(s) | Componentes clave | Endpoint / ruta |
|---|---|---|---|---|
| CAP-AUTH-01 | FLOW-AUTH-01, FLOW-AUTH-02 | SCR-AUTH-01, SCR-PRO-01 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-036, CMP-037, CMP-039, CMP-040, CMP-041 | `/auth/*` |
| CAP-AUTH-02 | FLOW-AUTH-01 | SCR-AUTH-02 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008 | `/auth/verify-2fa` |
| CAP-HOME-01 | FLOW-HOME-01 | SCR-HOME-01, SCR-HOME-03 | CMP-020, CMP-021, CMP-027, CMP-100, CMP-031, CMP-039, CMP-041 | `/dashboard/stats`, `/events` |
| CAP-HOME-02 | FLOW-HOME-01 | SCR-HOME-01 | CMP-100 | contexto persistente |
| CAP-HOME-03 | — | SCR-HOME-02 | CMP-020, CMP-023, CMP-026 | agenda futura (N4) |
| CAP-OPS-01 | FLOW-OPS-01 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | `/operations/execute` |
| CAP-OPS-02 | FLOW-OPS-03, FLOW-OPS-04, FLOW-OPS-05 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | `/operations/execute` |
| CAP-OPS-03 | FLOW-OPS-07 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | `/operations/execute` |
| CAP-OPS-04 | FLOW-OPS-02, FLOW-OPS-06, FLOW-OPS-08, FLOW-OPS-09, FLOW-OPS-10, FLOW-OPS-11 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | `/operations/execute` |
| CAP-OPS-05 | FLOW-OPS-12 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-023, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | `/operations/execute` |
| CAP-MSG-01 | FLOW-MSG-01 | SCR-OPS-03, SCR-NOT-01 | CMP-105, CMP-104, CMP-021, CMP-025 | webhook status |
| CAP-MSG-02 | FLOW-MSG-02 | SCR-NOT-02 | CMP-105, CMP-104, CMP-021, CMP-025 | webhook inbound |
| CAP-CASE-01 | FLOW-CASE-01, FLOW-CASE-02, FLOW-CASE-03 | SCR-CASE-01, SCR-CASE-02, SCR-CASE-03 | CMP-001, CMP-021, CMP-024, CMP-102, CMP-103, CMP-106, CMP-036, CMP-039, CMP-041, CMP-107 | tracking, behavior/risk |
| CAP-QRY-01 | FLOW-QRY-01 | SCR-QRY-01, SCR-QRY-02 | CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-011, CMP-021, CMP-022, CMP-032, CMP-034, CMP-035, CMP-039, CMP-041 | `/consultations/query` |
| CAP-AUD-01 | FLOW-AUD-01, FLOW-AUD-02 | SCR-AUD-01, SCR-AUD-02 | CMP-004, CMP-009, CMP-021, CMP-022, CMP-024, CMP-034, CMP-035, CMP-039, CMP-041 | `/audit/*` |
| CAP-REP-01 | FLOW-REP-01 | SCR-REP-01, SCR-REP-02 | CMP-001, CMP-007, CMP-009, CMP-022, CMP-026, CMP-036, CMP-039, CMP-040, CMP-041 | `/reports/*` |
| CAP-ENR-01 | FLOW-ENR-01, FLOW-ENR-02, FLOW-ENR-03 | SCR-ENR-01, SCR-ENR-02 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-011, CMP-021, CMP-106, CMP-036, CMP-039, CMP-040, CMP-041, CMP-107 | students/groups + edge biometría |
| CAP-PRO-01 | FLOW-PRO-01 | SCR-PRO-01, SCR-PRO-02, SCR-PRO-03 | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-108, CMP-036, CMP-039, CMP-040, CMP-041 | `/users/me/*` |
| CAP-PWA-01 | FLOW-PWA-01, FLOW-PWA-02 | SCR-PRO-03, SCR-SYS-02, SCR-SYS-03 | CMP-108, CMP-039, CMP-040, CMP-041 | manifest, workbox, background sync |
| CAP-NATIVE-01 | FLOW-NATIVE-01 | SCR-PRO-03 | CMP-001, CMP-108, CMP-041 | deep links / Tauri |
| CAP-NATIVE-02 | — | N4 | — | Capacitor adapter futuro |
