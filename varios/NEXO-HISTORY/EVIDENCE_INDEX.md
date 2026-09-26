# EVIDENCE INDEX — de cada hito a sus commits

Índice de trazabilidad: cada acontecimiento de la historia enlaza con el
hash del commit, la fecha, el repositorio y las áreas/archivos donde se
demuestra. Repositorios: **R1**=`NEXO-HISTORY/nexo-0.01` (0nto/NEXO),
**R2**=`nexo-0.02` (= commits 1–266 de R3), **R3**=repo actual.
Los hashes son cortos; en R3 se resuelven con `git show <hash>`.

---

## Era R1 — el puente y el bot (125 commits, 04-12 → 05-08)

| Hito | Commit | Fecha | Área / evidencia |
|---|---|---|---|
| Génesis: puente AES + landing | `0a84b1a7` | 04-12 | `api.php` (76 ln, AES-256-ECB, `auditoria_global` con hash SHA-256), `index.html`, `style.css` |
| MVP "Interoperabilidad por Excepción" + Railway | `4aef080b` | 04-12 | webhook Twilio (`$_POST['Body']`), `!inasistencia`, `!rojo`, MySQL Railway, `Dockerfile` |
| "versión 1.0" declarada | `6e5c3fb1` | 04-13 | api.php 267 ln: notificación WhatsApp real a acudientes, eventos INGRESO, avisos a coordinación |
| Hardening "certificación ciberseguridad" | `9435cf1c` | 04-18 | `.htaccess`, AES-256-GCM, clave por entorno; api.php ~996 ln |
| Bot de comandos con RBAC completo | `0387a5a6` / `d5ce2718` | 05-05 | `COMMANDS_CONFIG` formalizado (~14 comandos, 8 roles); api.php 1.486 ln |
| Migración MySQL→PostgreSQL | `5d9b14d2` | 05-07 | api.php −1.019 ln; nuevo `db.php` (PDO pgsql); `validateTwilioSignature` |
| API modular `routes/` + roles | `9f771f6a`, `4023346f` | 05-08 | `routes/{auth,students,groups,operations,misc}.php`, `check_roles.php` |
| Hardening SQL / flujo citación Twilio | `783ec75d`, `79cb2b2e` | 05-08 | `sql/2026-05-*-hardening*.sql`; respuesta de acudiente 1/2 |
| App React/Vite/PWA (línea 0nto) | `20ebe789` | 05-08 | raíz separada: `src/pages/*` (9), `AuthContext`, `ProtectedRoute`, `vercel.json`, `manifest` |
| Fusión backend+frontend (HEAD de R1) | `5b6d2820` | 05-08 | merge: árbol con `routes/`+`sql/`+API PHP **y** app Vite completa |

## Era R3 — el monorepo (604 commits, 05-12 → 09-26)

### Fundación y despliegue (05-12 → 05-17)

| Hito | Commit | Fecha | Área / evidencia |
|---|---|---|---|
| Commit fundacional del monorepo | `7c468b3` | 05-12 | `WebApp/` (+Tauri), `Logica de negocio` (gitlink), `Includes/` (ESP32), `Gestion de Proyecto/`, `ARCHITECTURE.md` (estado+objetivo, problemas R1–R5) |
| Limpieza inicial + README | `e8231a5` | 05-13 | borra espejo de backend en WebApp, scripts ESP32; README |
| Backend materializado | `71de30ef` | 05-13 | `backend/alojamiento/` (api.php v7.5, 15 routes, workers, sql 2026-05→13) + `backend/edge/` (C++ HAL, ZK9500, OLED, GPIO, SQLite) |
| Renombre a `backend/` | `817f7267` | 05-13 | `Logica de negocio` → `backend` |
| Saga Railway (~40 commits) | `633ff56b`…`5b4154c0` | 05-13→17 | Dockerfile/nginx/php-fpm/Apache/MPM/502/PORT/IPv6; resultado: nginx+fpm Unix socket |
| Servicio único Railway | `1cb9e0ef` | 05-17 | landing `/` + API `/v1/` + app `/app/` en un contenedor |
| MQTT + watchdog + worker biométrico | `0e9a52d8` | 05-17 | `mqtt_publisher.php`, `mqtt_command_worker.*`, `watchdog.h`, `worker_biometric.php` |
| CORS cross-domain | `c2fd75e5`, `b805e6e8`, `274a1abf` | 05-14/16 | whitelist dinámica, `SameSite=None`, headers `X-NEXO-TOKEN`/`X-Device-Token` |

### Landing y distribución (05-17 → 05-31)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Rediseño "singapore govtech" | `4c0db6e2` | 05-17 | `WebApp/src` |
| Landing SPA + telemetría + RLS | `4074a104` | 05-17 | landing dentro del servicio único |
| Canvas 3D global + APK | `a3fd7331`, `fb4d7070` | 05-20 | Three.js/Drei/GSAP; `src-tauri/gen/android` |
| Skills de agente IA incorporadas | `1aa55bae` | 05-20 | `.agents/skills/` (diseño UI) |
| APK real 60 MB → revert → PWA | `3247af43`, `c9ba7b9`, `d1b2d29` | 05-24 | `public/assets/downloads/nexo.apk`; InstallPage |
| Instalación PWA multiplataforma | `1c5a8ff3`, `ec9363bc`, `8e83c3ae` | 05-25 | `InstallPage`, `beforeinstallprompt` global |
| Saga CORS definitiva | `0641b382`…`454210ae` | 05-25 | headers al inicio de api.php; catch-all nginx |
| JWT HS256 fallback | `3d0a1bc6` | 05-25 | JWT cuando `JWT_PRIVATE_KEY` no es RSA PEM |

### WebApp operativa (05-31 → 06-30; fin de R2)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Módulo de auditoría real | `2e051bc1`, `18aeb985`, `a767a6aa` | 05-31 | `routes/audit_full.php`, ~50 endpoints, filtros/exportación |
| Consultas dinámicas PG | `1dcc8ee7` | 06-03 | `routes/consultations.php` |
| Citaciones Twilio robustas | `11fb4f87`, `298bbc78`, `8d5b6d5a` | 06-03/05 | normalización tel, StatusCallback, reintento por template 63016/63015 |
| Acudiente responde 1/2 → notificación interna | `b58b2b7f` | 06-05 | `notifications` reales |
| OTP WhatsApp + 2FA + alerta login | `1ad18781` | 06-05 | `send-verification`, login 2FA opcional |
| Datos de usuario fuera de localStorage | `e222b75f` | 06-06 | `userStore` en memoria |
| Seguimiento estudiantil | `de2df0de`, `3ea392cf`, `4a769695` | 06-08/09 | `routes/tracking.php`, `Seguimiento.jsx`, schema tracking |
| JWT blocklist (RLS) | `3c833b53` | 06-16 | `sql/2026-16-fix-jwt-blocklist-rls.sql` |
| Fin de R2 / limpieza repomix | `304f1ecc` | 06-30 | −84.851 ln de artefactos; `lib/twilio.php`; operations reescrito; `PanicButtonTest` |

### Transición y saneamiento (06-30 → 07-04)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Cambio de cuenta autora | `561e69c` | 06-30 | primer commit `jeager972-droid`; rama `NEXO` queda aquí |
| Retiro del legado ESP32/docs | `cac1718b` | 06-30 | `Includes/.pio` (209 archivos), `Gestion de Proyecto` |
| `alojamiento` → `api` | `990d6520` | 06-30 | rename + limpieza (76 archivos) |
| `landing/` como carpeta propia | `76cb0932` | 06-30 | sale del interior del backend |
| Guía de migración futura | `1bc3d23e` | 06-30 | `documentation/` |
| Grind de despliegue | `281…297` | 07-01/02 | ports, proxy Railway, ~30 commits de ajuste |

### Canonización de datos y hardware (07-20 → 08-02)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Consolidación/canonización de BD | `bbf68c76` | 07-20 | 146 archivos; `schema_migrations_backfill.sql`, `deploy_db.sh` |
| Compatibilidad Supabase/PgBouncer | `b5ebcd26` | 07-22 | emulated prepares |
| Alineación de roles | `90a709df` | 07-22 | elimina SUPER_RECTOR |
| SDK vendor U.are.U 5300 en edge | `edead085` | 07-27 | +53.763 ln: `sensorvendor/uareu5300/`, `UareU5300/Zk9500BiometricSensor.h`, `validation/` con licencias |
| Vitest + primeros tests PWA | `ed4f1769` | 08-02 | `vitest.config.js`, `__tests__/api/*` |

### Semana del 4 de agosto (08-04 → 08-05)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Saga PgBouncer/RLS | `73d0216f`→`a77d7a95` | 08-04 | `SET LOCAL`+`exec(BEGIN)`, `/debug/worker` temporal |
| Detección automática de ausentes | `c756a659` | 08-04 | `workers/worker_absence_detector.php`, jornada |
| Onboarding horarios + evasión | `215399cb`, `4dbe82a1`, `2a6ddf03` | 08-04 | `school_config.php`, multi-jornada, `nexo_full_migration.sql` |
| Fix de auditoría end-to-end | `4ff96758` | 08-05 | 5 correcciones de auditoría |

### V1.0, Release Candidate y flota biométrica (08-11 → 08-24)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| "WebApp V1.0" | `41089626`, `edcacbd8` | 08-11 | (+6 commits del bot "Devin" de fixes UI ese día) |
| "NEXO — Release Candidate" | `43682b30` | 08-15 | 58 archivos; `backend/api/sql/schema.sql` (55 tablas) |
| Merge con origin/main | `84a17a91` | 08-15 | unifica línea RC |
| Onboarding grupos + gestión de sensores | `906c4dd8`, `21ed2f85` | 08-17 | sensores por grupo, rollover, revocación |
| Tokens de dispositivo + heartbeat | `d1889922`, `fd4f9e34` | 08-17 | auto-provisión `config.json`, CommandWorker, `last_ping` |
| Rol a sensores / asignación a usuario | `601cad04`, `1a6251bd` | 08-18 | `edge_devices` con roles |
| RLS en edge_devices + resiliencia comandos | `7b405fdc`, `8765a884`, `6fa05edc` | 08-18 | RLS dispositivos, retry ×3, Redis best-effort |
| 8 bugs críticos del edge | `bb40f83a` | 08-20 | 24 archivos `backend/edge` |
| Motor de Riesgo Pedagógico v3.0 | `ad79306e` | 08-20 | `lib/RiskEngineV3.php`, `routes/risk.php` |
| Gran reorganización + tests | `bb0165b2` | 08-20 | WebApp→PWA, Tauri eliminado, `sql/schema.sql` raíz, `test/`, core/ |
| CI verde | `786802d1`, `f2fb2671`, `c4ef7ad7` | 08-20 | Node 22 Vitest, `.env.example`, 60 tests SQL |
| Enrolamiento de huella E2E | `8688e903`, `c228c1d4` | 08-20/21 | edge→backend→PWA polling, `has_fingerprint` |
| Justificación de inasistencia por WhatsApp | `59bc8633` | 08-21 | flujo acudiente |
| Flujo completo de evasión | `5487c6cc`, `d6d08aeb`, `836d66f5` | 08-21 | LATE_ARRIVAL/EVASION_INTERNA, baño 20 min, permisos |
| Resiliencia Redis/PgBouncer | `fcb10046`, `d4c59c6d`, `155b0c3f`, `12bb033c` | 08-24 | pool exhaustion, fallback PG workers, circuit breaker 45 min |

### Auditoría e integración (09-15 → 09-19)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Documentos de auditoría | `3e329885` | 09-15 | `auditoria/` (ANALISIS_AUDITORIA — 652 verificaciones documento↔código) |
| Integración masiva | `74dc1b8b` | 09-17 | OTA (`lib/ota.php`), `events.php`, `teacher_alerts.php`, `attendance_reconcile.php`, `notify_routing.php`, simuladores (`simulaciones/`), stack `pruebas/`, `documento_final.txt` |

### Nexus (09-20 → 09-26)

| Hito | Commit | Fecha | Área |
|---|---|---|---|
| Nacimiento: chatbot NLU cascada | `a6fd4696` | 09-20 | `backend/nlu/` (train/corpus/kb/service.py), `nlu_runtime/`, `lib/nexus_nlu.php`, `routes/chat.php`, `PWA/src/api/chat.js`, `sql/migrations/004_chat.sql` |
| Freeze de baselines NLU | `0619709a` | 09-20 | tags `nlu-baselines-frozen`, `nlu-v2b-baseline` sobre `12a7278` |
| Núcleo conversacional (rama) | `699e4b44`→`e05b759` | 09-20/21 | DSM compartido, Semantic Core, gates 16/16, RC-1 read-only |
| Merge RC conversacional | `0561ffd` | 09-21 | `nexus-conversational-core` → main |
| Deploy NLU V3.1 | `9d8db6fc` | 09-21 | `model_v3_1_targeted` en producción |
| Estado conversacional server-side | `5fd5d225`, `71b156f8` | 09-21 | referencias, navegación de result-set |
| Planner universal + memoria (baseline) | `ee12db93` | 09-22 | open composition + working memory (baseline auditado) |
| Hardening Nexus | `427319ef`, `051cdbd0`, `1cbbaf15` | 09-22 | RBAC/delegación, harness, golden 9/9, gate 21/21 |
| Modelo híbrido LLM v1.0 | `e437d4ff` | 09-23 | `lib/nexus_llm.php` — parser+composer LLM |
| Retiro del clasificador sintético | `1b36423e` | 09-23 | 89 archivos; el LLM ES el parser; fixture para suites |
| Capa conversacional LLM | `3f5559b1`, `7240a649` | 09-24 | contexto/seguridad/tablas/exportación; rescate determinista |
| Reorganización de raíz | `9b30742e` | 09-26 | `backend/`, `frontend/`, `sql/`, `test/`, `docs/`, `_cuarentena/` |
| Nexus a `backend/api/nexus/` | `35271aef` | 09-26 | componente canónico + README (HEAD) |

---

## Convenciones de verificación

- Estado de un momento: `git ls-tree -r --name-only <commit>`.
- Qué cambió: `git show <commit> --stat` / `--name-status` / diff.
- Primeras apariciones: reconstruidas por `git log --name-status --diff-filter=A`
  inverso (mapa completo en `/tmp/nexo_hist/` durante el análisis).
- Los conteos citados (125/266/604, 729 únicos, 55→78 tablas,
  15→24 archivos de rutas) se obtuvieron por `git rev-list --count`,
  `grep -c "CREATE TABLE"` sobre `schema.sql` y `ls-tree` por commit.
