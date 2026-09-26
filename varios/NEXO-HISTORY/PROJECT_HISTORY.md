# PROJECT HISTORY — la historia de NEXO

*Reconstruida a partir del historial Git real de tres repositorios
(`0nto/NEXO` = R1, `jhonedisonalvarez21-lab/NEXO` = R2, absorbido por el
repo actual = R3). Metodología en `0.01_ALCANCE_Y_METODOLOGIA.md`;
evidencia por hito en `EVIDENCE_INDEX.md`.*

---

## 1. Génesis: un puente que no quería guardar nada (abril)

NEXO no nació como plataforma. Nació como un **puente**.

El primer commit accesible (`0a84b1a7`, R1, 2026-04-12, "repo") contiene
tres archivos: `api.php` (76 líneas), un `index.html` con una galaxia
decorativa y un `style.css`. El PHP se autodescribe como
*"NEXO GLOBAL API v2.0 — PRODUCTION SECURE BRIDGE"*: recibe transacciones
cifradas con AES-256 desde instituciones, comprueba que la institución
está activa, "envía" una notificación WhatsApp al acudiente y persiste en
`auditoria_global` únicamente el hash SHA-256 del documento del
estudiante. El teléfono del padre se usa y se descarta. Los comentarios
lo dicen sin rodeos: los datos sensibles no se guardan en la base global.

Ese mismo día, el commit del MVP (`4aef080b`, "Implementación de
Interoperabilidad por Excepción y Docker Railway") ya muestra la otra
mitad de la idea: el mismo `api.php` atiende el webhook de Twilio y
responde comandos de texto — `!inasistencia 11-A 7` registra la falta y
promete avisar al acudiente; `!rojo` inicia una verificación. Es decir:
desde el primer día, **la interfaz de NEXO fue WhatsApp**, y su modelo de
datos fue deliberadamente mínimo.

El contexto se adivina con cuidado: es un sistema para colegios
colombianos (los comentarios citan la Ley 1581/2012 de protección de
datos). Las instituciones conservan sus sistemas; NEXO solo transporta
excepciones — una inasistencia, una alerta — y las convierte en una
notificación a la familia y en un rastro anónimo. "Interoperabilidad por
excepción" es exactamente eso: nada fluye salvo lo excepcional.

El 13 de abril, tras unas cincuenta iteraciones en dos días (`fix
definitivo`, `ojala este sea el definitivo` — los mensajes son honestos
sobre el proceso), el autor declara *"finalizacion de la version 1.0"*.
Era la 1.0 del puente: ya enviaba WhatsApp reales por la API de Twilio,
registraba ingresos, avisaba a coordinación.

## 2. El bot crece hasta ser el sistema (15 de abril – 5 de mayo)

En los días siguientes `api.php` engorda: 267 líneas el 13, 693 el 18,
996 al cierre del 18, ~1.400 el 20. Ese crecimiento es la segunda etapa
de NEXO: el puente se convirtió en **bot operativo de la institución**.

El archivo acumuló una tabla `COMMANDS_CONFIG` con catorce comandos —
`!tarde`, `!inasistencia`, `!citacion`, `!salida`, `!permiso`,
`!excepcion`, `!SOS`, `!daño`, `!lista`, `!tardetotal`, `!dañostotal`,
`!subsana`, `!help` — cada uno con su lista de roles autorizados:
SUPER_ADMIN, RECTOR, COORDINADOR, DOCENTE, SECRETARIA, PSICORIENTADOR,
PORTERO, PADRE. El remitente se identifica por su número de teléfono en
la tabla `personal`; cada ejecución se registra con `logComando`; los
acudientes tienen su propio mini-protocolo ("NEXO PADRES", responder 1 o
2 a una citación). Había fallback WhatsApp→SMS, validación de firma de
Twilio, rate limiting y un `securityLog`.

El 18 de abril hubo además una pasada explícita de seguridad
("cambios en pro de certificacion de ciberseguridad", `9435cf1c`):
aparece `.htaccess`, AES-256 pasa de ECB a GCM y la clave sale del
código hacia variables de entorno. La paranoia del diseño original
(endpoint "discreto", 404 para disimular, sin PII en reposo) se fue
formalizando en capas.

Hay que decirlo con claridad: era un diseño funcional pero frágil — un
solo archivo PHP de mil quinientas líneas que era a la vez webhook,
router, RBAC, lógica de negocio y capa de datos. Funcionaba porque el
dominio cabía en comandos de una línea.

## 3. El fin de semana que lo cambió todo (5–8 de mayo)

Tras dos semanas de silencio, el 5 de mayo aparece un segundo autor,
**0nto** ("cambios a la API de PHP"), y en ~36 horas el proyecto da tres
saltos:

**PostgreSQL.** La noche del 7 (`5d9b14d2`, "postgre sql update") se
migra de `mysqli` a PDO/pgsql, se extrae `db.php` y `api.php` baja de
1.486 a 648 líneas. Fue el primer refactor estructural serio: el
monolito empezó a descomponerse.

**Modularización.** En la mañana del 8 aparecen `routes/` (auth,
students, groups, operations, misc), `check_roles.php`, scripts de
hardening SQL y `docker-compose.yml`. El monolito se volvió front
controller más módulos — la forma que el backend conserva hoy.

**La WebApp.** Ese mismo día se fusionó la otra línea del repo: el
"innitial commit" de 0nto (`20ebe789`) traía una aplicación
React/Vite/Tailwind **ya terminada** — PWA con manifiesto, login, rutas
protegidas y nueve páginas institucionales (Dashboard, Consultation,
Enrollment, Operation, Audit, Notifications, Reports). Llegó con
`node_modules` y `dist` comiteados: era el snapshot de un proyecto local
sin historia propia dentro de R1.

El merge `5b6d2820` ("Resolve merge conflicts") es el último commit del
repositorio: la era R1 termina exactamente en el momento en que backend
y frontend quedan juntos. Ahí NEXO dejó de ser un bot con un puente y se
convirtió en **un sistema con dos superficies**: WhatsApp para el campo
y los acudientes, una consola web para la institución.

## 4. Refundación: el monorepo (12–13 de mayo)

Cuatro días después, el proyecto reapareció en otro repositorio
(`jhonedisonalvarez21-lab/NEXO`, hoy contenido en el repo actual) con un
commit fundacional (`7c468b3`) que no hereda el historial anterior pero
sí su código — y mucho más: `WebApp/` con Tauri incluido, un directorio
`Logica de negocio` (registrado como submódulo fantasma), librerías
ESP32 de PlatformIO, una carpeta `Gestion de Proyecto` con documentos de
un proyecto embebido, y un `ARCHITECTURE.md` que es la pieza más
reveladora de toda la historia.

Ese documento muestra que el 12 de mayo NEXO ya se concebía como una
**plataforma educativa para Colombia** de tres piezas: *edge biométrico*
en cada sede (huella, asistencia, eventos), *API en la nube* que
centraliza y alerta, y *cliente institucional* para siete roles
(rector, coordinador, docente, secretaría, portería, auxiliar,
psicorientador). Y lista con honestidad los problemas heredados: el
backend existía duplicado byte a byte en dos carpetas, la ingesta en la
nube descifraba el payload y respondía 200 **sin insertar nada**
(problema "R1"), la auditoría era mutable y el edge aún era, en teoría,
Arduino/ESP32.

Al día siguiente (`71de30ef`) el submódulo fantasma se materializó como
`backend/`: `alojamiento/` con el API ya en "v7.5" (quince rutas,
workers, migraciones SQL numeradas que ya incluían políticas RLS,
`edge_devices`, cadena de auditoría y la tabla `user_commands` — los
comandos del bot hechos datos), y `edge/` con un **agente C++ con HAL**:
interfaces `IBiometricSensor`/`IDisplay`/`IHttpClient`/`INotification`,
stubs de desarrollo, drivers reales para el lector ZK9500, OLED y GPIO,
SQLite local, cifrado y `cloud_manager`. El C++ existía antes del
monorepo — su historia no sobrevivió, pero el resultado sí: el
"objetivo RPi4/C++17" del documento ya estaba parcialmente escrito.

La refundación tuvo un precio visible: ~40 commits entre el 13 y el 17
de mayo documentan una saga de despliegue en Railway — Apache contra
MPM, nginx que devolvía 502, php-fpm que no escuchaba donde el proxy
esperaba, el PORT que no se inyectaba. El resultado final fue una
arquitectura de **servicio único** (`1cb9e0ef`): un contenedor
nginx+php-fpm sirviendo la landing en `/`, la API en `/v1/` y la app en
`/app/`. No era elegante, pero redujo el despliegue a un solo punto
verificable — y buena parte de los problemas de CORS que habían
atormentado mayo venían precisamente de tener frontend y backend en
orígenes distintos.

## 5. La landing y la distribución (17–31 de mayo)

Con el despliegue domado, el foco se movió a la cara pública. El 17
llegó un rediseño completo del frontend ("singapore govtech",
`4c0db6e2`), una landing con canvas 3D (Three.js/Drei/GSAP, modelo de
nodo `nodonuevo.glb`), telemetría y — significativo — la primera
aplicación real de RLS.

La distribución merece párrafo propio porque ilustra cómo se tomaban
decisiones en este proyecto: por ensayo. El 24 de mayo se subió un
**APK real de Android de 60 MB** al repo (`3247af43`); horas después los
botones de descarga apuntaban a la PWA en Vercel, el APK se fue, y
apareció `InstallPage` — una página de instalación que detecta el
navegador y guía el `beforeinstallprompt` por plataforma. La decisión
final quedó sellada en agosto cuando Tauri se eliminó del todo
(`bb0165b2`): NEXO sería una PWA instalable, no una app nativa. Un solo
frontend para escritorio y móvil.

## 6. La WebApp se vuelve el sistema (31 de mayo – 30 de junio)

Entre finales de mayo y principios de junio la consola dejó de ser un
panel para convertirse en el lugar donde se opera la institución:

- El módulo de **auditoría** se conectó a endpoints reales y creció a
  ~50 endpoints con filtros por fecha/grupo/estudiante y exportación
  (`18aeb985` y siguientes, 05-31).
- Las consultas dejaron de ser estáticas para ejecutarse sobre
  PostgreSQL (`1dcc8ee7`, 06-03).
- **Twilio se endureció de verdad**: normalización rigurosa de
  teléfonos, `StatusCallback`, reintentos con plantilla ante errores
  63015/63016, y las respuestas 1/2 de los acudientes convirtiéndose en
  notificaciones internas (`b58b2b7f`).
- La **seguridad de cuenta** ganó una capa: OTP por WhatsApp para
  cambios sensibles, 2FA opcional, alerta de inicio de sesión
  (`1ad18781`, 06-05) y — al día siguiente — los datos de usuario
  salieron de `localStorage` a un store en memoria (`e222b75f`).
- Apareció el módulo de **seguimiento estudiantil** (`de2df0de`,
  `3ea392cf`, 06-08/09): el primer flujo institucional que no era un
  simple registro de evento, con sus propias tablas.
- JWT ganó blocklist (`3c833b53`, 06-16 — migración
  `fix-jwt-blocklist-rls`).

El 30 de junio cierra la era del segundo repositorio con un commit mal
nombrado ("some fixes", `304f1ec`) que en realidad borró 84.851 líneas
de artefactos `repomix` y extrajo `lib/twilio.php` — una limpieza, no un
fix.

## 7. Cambio de manos y saneamiento (30 de junio – 4 de julio)

Ese mismo día cambia la cuenta autora: de `jhonedisonalvarez21-lab` a
`jeager972-droid`. (El remoto actual es `jeager972-droid/NEXO`; si fue
traspaso o nueva cuenta del mismo autor no está documentado — lo que sí
está documentado es el cambio de estilo: commits más descriptivos,
borrados masivos, documentación interna.)

La primera semana fue de saneamiento: fuera `Includes/.pio` (209
archivos vendor ESP32), fuera `Gestion de Proyecto`, fuera el frontend
duplicado dentro del backend, `alojamiento/` renombrado a `api/`, la
landing a su propia carpeta, un `REPORTE_LIMPIEZA.md` y una guía de
migración. Después vino otra sesión de grind sobre el despliegue — el
log del 1 de julio contiene mensajes de frustración personal que son
parte del registro real de lo que costó estabilizar el sistema.

## 8. Canonización de datos y hardware real (20 de julio – 2 de agosto)

El 20 de julio un solo commit (`bbf68c76`, "consolidacion, canonizacion
y refactor en la base de datos", 146 archivos) ordenó la capa de datos:
migraciones consolidadas con `schema_migrations_backfill.sql`,
`deploy_db.sh` para despliegue de esquema. Al día siguiente se hizo
compatible con Supabase/PgBouncer (prepared statements emulados) y se
alinearon los roles entre frontend y backend (fuera SUPER_RECTOR).

El 27 de julio llegó el commit más grande del proyecto (`edead085`,
+53.763 líneas) con el nombre engañoso de "webapp frontend big
refactor": en realidad incorporó al edge el **SDK completo del vendor
U.are.U 5300** (DigitalPersona), un segundo driver biométrico junto al
ZK9500, con programa de validación y licencias. El nodo dejó de depender
de un solo sensor. La misma semana se sembró `vitest` y los primeros
tests del frontend, y un `UI_UX_PLAN` que gobernó el rediseño.

## 9. La semana del 4 de agosto

El día 4 de agosto es el día más denso del historial y el que mejor
explica cómo se construía NEXO. Por la mañana, una saga de debugging de
producción: tras migrar a Supabase con PgBouncer en *transaction
pooling*, el contexto RLS (`app.current_role`, `app.school_id`) se
perdía entre consultas y el dashboard del docente devolvía vacío. La
solución — tras varios intentos, un revert y endpoints `/debug/worker`
temporales — fue abrir la transacción en `requireAuth()` antes de
cualquier consulta y fijar el contexto con `SET LOCAL` dentro de ella
(`a77d7a95`). La lección quedó en la arquitectura: el aislamiento
multi-tenant depende de que el contexto viva dentro de la transacción,
no en la sesión.

Y esa misma tarde/noche, como si el bloqueo hubiera destapado el trabajo
acumulado: detector automático de ausentes (`worker_absence_detector`),
métricas de tardanza, jornada estudiantil, motor de riesgo usando
`attendance_incidents`, y el **onboarding de horarios** — la
institución define sus jornadas y el sistema sabe cuándo debería haber
estudiantes entrando (`215399cb` → multi-jornada `4dbe82a1`, consolidado
en `nexo_full_migration.sql`). Zona horaria `America/Bogota` en todas
las consultas. NEXO empezó a tener *expectativas*: ya no solo registra
eventos, sabe cuándo un evento falta.

## 10. V1.0, Release Candidate y la flota biométrica (11–24 de agosto)

El 11 de agosto, tras una semana de pulido UI (incluidos seis commits de
un agente llamado "Devin" arreglando colores), el log declara **"WebApp
V1.0"** (`edcacbd8`). Cuatro días después, **"NEXO — Release Candidate"**
(`43682b30`) aterrizó el `schema.sql` canónico de 55 tablas y se mergeó
con `origin/main`. Fue la segunda vez que el proyecto se declaró listo —
la primera había sido el puente de abril.

Lo que siguió convierte al edge en algo distinto: una **flota
gobernada**. El 17–18 de agosto apareció la gestión de sensores
biométricos desde la PWA — onboarding de grupos y sensores en un flujo
secuencial, tokens por dispositivo auto-provisionados desde
`config.json`, revocación, heartbeat con `last_ping`, asignación de
sensor a usuario, RLS sobre `edge_devices`. El 20–21 se cerró el ciclo
completo de **enrolamiento remoto de huella**: la PWA ordena, el edge
captura, el backend confirma, la interfaz muestra `has_fingerprint`
(`8688e903`). En paralelo se corrigieron ocho bugs críticos del edge,
la regla de los 20 minutos de baño, la evasión interna entre bloques, la
salida con huella obligatoria (`SALIDA_AUTORIZADA`) y la justificación
de inasistencias por WhatsApp del acudiente.

El 20 de agosto hubo además la **gran reorganización** (`bb0165b2`):
`WebApp/` → `PWA/`, Tauri eliminado, todas las migraciones SQL
consolidadas en un único `sql/schema.sql` en la raíz, `core/` para
db/redis/mqtt, y el nacimiento de `test/` — PHPUnit para SQL y API,
Vitest en la PWA, CI con los workflows ajustados hasta verde (60 tests
SQL, 0 fallos). Junto a ello llegó el **Motor de Análisis de Riesgo
Pedagógico v3.0** (`ad79306e`): señales graduadas LEVE→MUY_ALTA con
umbrales configurables y una regla de diseño explícita — la alerta
informa, la decisión la toma una persona.

El 24 de agosto fue la última gran batalla de infraestructura: el agotamiento
del pool de base de datos y la dependencia dura de Redis/Upstash se
resolvieron con **degradación elegante** — circuit breaker con cooldown
de 45 minutos, workers que ejecutan contra PostgreSQL cuando el broker
cae, reintentos en el polling de comandos. NEXO aprendió a funcionar
roto.

## 11. Auditoría contra la especificación (15–19 de septiembre)

Septiembre abrió distinto: con documentos. Apareció `auditoria/` — una
verificación ítem por ítem (652 verificaciones) entre un documento
funcional (`documento_final.txt`, incorporado en esos días) y el código
real, cada ítem con su "por qué cumple" y "qué falta". Y el commit
`74dc1b8b` ("u dont believe what i have done", 113 archivos, +13.725
líneas) integró lo que la auditoría pedía: **OTA** para los nodos,
telemetría y salud de dispositivos, un **modelo espacial** (aulas y
horarios que atan cada evento biométrico a un bloque y un salón),
conciliación de asistencia, alertas a docentes, `routes/events.php`,
workers de contingencia — y `simulaciones/`, simuladores PHP de los
subsistemas físicos (biometría, energía, térmico, OTA, M2M), junto con
`pruebas/`, un stack Docker de integración.

Esta etapa es la que separa a NEXO de un CRUD: los eventos dejaron de
ser filas y pasaron a ser posiciones en el espacio-tiempo escolar con
consecuencias (evasión, permiso vencido, retorno al aula equivocada).

## 12. Nexus: cinco días, tres arquitecturas (20–24 de septiembre)

El 20 de septiembre apareció de golpe (`a6fd4696`, "chatbot nlu cascada
+ colombia + calc") lo que faltaba: **Nexus**, la capa conversacional.
La primera versión era un clasificador TF-IDF+regresión logística
entrenado en Python (`backend/nlu/`: corpus por dominio, corpus Colombia,
`math_ner`, `service.py`, modelo joblib exportado a JSON para PHP), un
runtime empaquetado, `routes/chat.php` y `chat.js` en la PWA.

En las 48 horas siguientes, en la rama `nexus-conversational-core`, el
sistema creció hacia adentro: baselines congeladas (los tags
`nlu-baselines-frozen`/`nlu-v2b-baseline`), un **Dialogue State Manager**
compartido, un Semantic Core con reranking determinista, resolución de
referencias ("ese estudiante", "del grupo") navegando el result-set,
estado conversacional server-side, y una metodología de **gates** —
baterías de evaluación (adversariales, blind sets, continuidad) que
debían pasar 16/16, luego 21/21, antes de cada merge. El 22 llegó el
**planner universal de capacidades con memoria de trabajo**
(`ee12db93` — commit que el proyecto conserva como baseline auditado).

El 23 ocurrió el pivote decisivo: `e437d4ff` introdujo el modelo
híbrido (LLM como parser y compositor sobre el motor NEXO) y
`1b36423e` **retiró el clasificador TF-IDF** — 89 archivos, el mensaje
es literal: "el LLM es el parser". El sistema se quedó con lo mejor de
ambos: el LLM entiende el lenguaje; el motor determinista garantiza
read-only, RBAC, evaluación y rescate. El 24 se endureció (rescate
determinista módulo→intent, deep-links a operaciones, batería forense
live). El 26 el proyecto se reorganizó a su forma actual y Nexus quedó
como componente canónico en `backend/api/nexus/`.

## 13. Qué es NEXO 1.0

El historial declara "1.0" tres veces y cada una significa algo
distinto: la 1.0 del **puente** (13 de abril), la 1.0 de la **WebApp**
(11 de agosto), y el Release Candidate del **sistema** (15 de agosto,
cerrado para Nexus el 21 de septiembre). El estado consolidado al final
del historial es:

- **Edge**: agente C++20 por sede — huella (U.are.U 5300 / ZK9500),
  SQLite local, cola persistente, MQTT + polling de comandos, OTA,
  watchdog, salud reportada, GPIO/OLED.
- **Backend**: `api.php` front controller (el mismo archivo que nació
  como puente de 76 líneas, ahora router documentado), 24 archivos de
  rutas, 11 workers, `core/` con db/redis/mqtt.
- **Datos**: PostgreSQL 15, `sql/schema.sql` de 78 tablas, multi-tenant
  por `school_id` con RLS, PgBouncer, Redis opcional para colas/caché/
  rate-limit.
- **Cliente**: PWA instalable para siete roles + landing pública.
- **Canales**: WhatsApp/SMS para acudientes, citaciones con respuesta
  1/2, justificaciones, OTP.
- **Nexus**: consulta en lenguaje natural sobre todo lo anterior,
  read-only garantizado, con la evaluación por gates como metodología.
- **Operación**: auditoría inmutable, motor de riesgo proporcional,
  onboarding de jornadas/grupos/sensores, OTA y telemetría de la flota.

## 14. Lo que el historial enseña sobre cómo se construyó

- **El diseño de privacidad fue primero**: anonimizar antes de persistir
  es del día 1; RLS, tokens de dispositivo y auditoría con hash chain
  son su continuación, no una corrección tardía.
- **La interfaz siempre fue el lenguaje**: primero comandos `!` por
  WhatsApp, luego operaciones en la PWA, finalmente Nexus en lenguaje
  natural. Tres encarnaciones de la misma idea.
- **La arquitectura se ganó depurando**: los dos periodos con más
  commits (mayo: Railway; agosto: PgBouncer/RLS) son sagas de fallos en
  producción, no de diseño en papel. El `ARCHITECTURE.md` de mayo ponía
  el plan; los commits pusieron las cicatrices.
- **El proyecto se reescribió por refundación, no por migración**: cada
  transición grande (bot→web, repo→monorepo, NLU→LLM) se hizo metiendo
  lo nuevo y retirando lo viejo, con el historial como testigo de ambos
  estados.
- **Lo que no se ve**: la historia git no muestra los meses de trabajo
  embebido (ESP32, el primer edge C++, la WebApp) porque entraron como
  snapshots. Donde el historial calla, esta historia también calla.
