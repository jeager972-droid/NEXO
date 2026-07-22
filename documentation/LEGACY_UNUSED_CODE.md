# NEXO — Código legacy, no usado o candidato a refactor en `backend/api/routes`, `workers`, `sql` y `tests`

> Listado de hallazgos encontrados durante la revisión documental. **No se modificó ninguna lógica**: solo se identifican para futuras decisiones de refactor o eliminación.

---

## 1. Endpoints/funciones vacíos o sin implementar

### 1.1 `routes/misc.php` — `GET /audit/logs`

```php
if ($cleanPath === '/audit/logs' && $method === 'GET') {
    // TODO: Implementar endpoint de auditoría
    echo json_encode(['status' => 'ok', 'data' => []]);
    exit;
}
```

- **Estado**: vacío.
- **Impacto**: no devuelve datos reales; el frontend que lo consuma verá lista vacía.
- **Relación**: `routes/audit_logs.php` ya implementa `/audit/global` para RECTOR/COORDINADOR. Este `/audit/logs` parece legacy o duplicado.

### 1.2 `routes/consultations.php` — módulo `audit_logs`

```php
case 'audit_logs':
    $data = [];
    $columns = ['info' => 'Información'];
    break;
```

- **Estado**: no implementa consulta real.
- **Acción sugerida**: implementar o eliminar el case.

---

## 2. Endpoints legacy o de compatibilidad

### 2.1 `routes/users.php` — `GET /users/me/photo`

```php
if ($cleanPath === '/users/me/photo' && $method === 'GET') {
    ...
    usersJson(['status' => 'ok', 'photo_url' => $row['profile_photo_url'] ?? null]);
}
```

- **Motivo**: `GET /users/me/extended` ya retorna `profile_photo_url` junto con el resto del perfil.
- **Estado**: mantenido por compatibilidad.
- **Acción sugerida**: deprecar y redirigir a `/users/me/extended`.

### 2.2 `routes/operations.php` — `sendTwilioWhatsAppDirect` alias

```php
function sendTwilioWhatsAppDirect($to, $body) {
    return sendTwilioWhatsAppSmart($to, $body);
}
```

- **Motivo**: alias para compatibilidad hacia atrás. Existe también `sendTwilioDirect` en `lib/twilio.php`.
- **Acción sugerida**: consolidar y eliminar alias.

---

## 3. Duplicación y near-duplicación

### 3.1 Envío de WhatsApp en `lib/twilio.php` vs `worker_twilio.php`

- `lib/twilio.php` implementa `sendTwilioDirect()` con timeout de 2s y fallback a template.
- `worker_twilio.php` implementa `sendTwilioWhatsAppRequest()` con timeout de 15s y `buildTwilioPayload()`.
- **Problema**: dos implementaciones similares mantenidas en paralelo. Cambios en uno no afectan al otro.
- **Acción sugerida**: mover `buildTwilioPayload`, `sendTwilioWhatsAppRequest` y `sendTwilioWhatsAppSmart` a `lib/twilio.php` y reutilizarlos.

### 3.2 Funciones de normalización de teléfono

- `lib/twilio.php::normalizeWhatsAppPhone()` quita `whatsapp:` y devuelve `+<digits>`.
- `routes/users.php::normalizePhone()` agrega `+57` si el número tiene 10 dígitos y empieza por 3.
- **Problema**: lógica divergente para el mismo concepto.
- **Acción sugerida**: unificar en `lib/twilio.php` o crear `lib/phone.php`.

### 3.3 Funciones `usersJson` / `auditJson`

- `routes/users.php::usersJson()`
- `routes/audit_full.php::auditJson()`
- Varias rutas usan `json_encode(...)` inline sin helper.
- **Acción sugerida**: helper global `jsonResponse($data, $code)` en `_auth_middleware.php` o utilidad común.

### 3.4 `isValidUUID` duplicada

- `routes/tracking.php::isValidUUID()`
- `routes/audit_full.php::isValidUUID()`
- **Acción sugerida**: helper único en `_auth_middleware.php` o `lib/utils.php`.

---

## 4. TODOs y notas de deuda técnica

### 4.1 `routes/devices.php` — validación de token edge en ingesta

```php
// TODO: El endpoint POST /devices genera un token raw que el edge debería
// usar para autenticarse (header X-Device-Signature o similar). Actualmente
// el firmware edge no implementa esta autenticación; se asume confianza
// por cifrado de payload. Implementar validación de token_hash en el
// endpoint EDGE cuando se añada el soporte en el edge.
```

- **Impacto de seguridad**: la ingesta edge depende del cifrado AES, no del token de dispositivo.
- **Acción sugerida**: implementar validación de `token_hash` en `/edge/ingest` y endpoints edge polling/ping.

### 4.2 `routes/users.php` — dependencia implícita de `sendTwilioDirect`

```php
if (!function_exists('sendTwilioDirect')) {
    error_log('[OTP] sendTwilioDirect no está definida. Verifica que operations.php se incluya antes que users.php.');
    return ['ok' => false, 'error' => 'sendTwilioDirect no disponible. Contacta soporte.'];
}
```

- **Problema**: `users.php` depende de que `operations.php` o `lib/twilio.php` se hayan cargado antes para tener `sendTwilioDirect`.
- **Acción sugerida**: `require_once __DIR__ . '/../lib/twilio.php';` directamente en `users.php`.

### 4.3 `worker_audit.php` — secreto por defecto

```php
$secret = getenv('APP_NEXO_HMAC_SECRET') ?: 'default-secret-change-me';
```

- **Problema**: fallback a un secreto público si no se configura la variable de entorno. La cadena de auditoría podría falsificarse.
- **Acción sugerida**: fallar al arrancar el worker si falta `APP_NEXO_HMAC_SECRET`.

### 4.4 `worker_twilio.php` y `worker_biometric.php` — no usan prepared statements para `set_config` en todos los casos

- Aunque la mayoría usan `$conn->prepare("SELECT set_config(...)")`, algunos bloques inyectan el valor directamente en el string (ver `worker_biometric.php` donde usa prepared; en `worker_twilio.php` también). No se encontró inyección real, pero unificar estilo.

---

## 5. Código aparentemente no alcanzable o redundante

### 5.1 `routes/_cors_middleware.php` — doble header idéntico

```php
if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
} else {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
}
```

- **Observación**: ambas ramas ejecutan la misma sentencia. Se puede simplificar.

### 5.2 `routes/auth.php` — `action=LOGIN` legacy

```php
if ($cleanPath === '/auth/login' || (isset($input['action']) && $input['action'] === 'LOGIN')) {
```

- **Observación**: doble forma de disparar login. La segunda (`action=LOGIN`) parece legacy del frontend anterior.
- **Acción sugerida**: validar con frontend si aún se usa; de lo contrario, eliminar.

### 5.3 `routes/security_panic.php` — paso 2.5 duplicado en lógica

- El comentario `// 2.5. Cache panic event in Redis` indica una inserción intermedia. No es un bug, pero numera la secuencia de forma poco convencional.

---

## 6. Variables y funciones globales que dificultan testing

- `$conn`, `$pdo`, `$cleanPath`, `$method`, `$input` como globales en `routes/*.php`.
- `getenv()` disperso en múltiples funciones (dificulta inyección de mocks).
- `getRedisConnection()` singleton estático: útil para producción pero difícil de mockear en tests.

---

## 7. Funciones con responsabilidades mixtas (SRP)

### 7.1 `routes/operations.php`

- Contiene helpers de Twilio (`sendTwilioNow`, `enqueueTwilioJob`), log de comandos (`logUserCommand`) y un switch gigante con ~10 tipos de comando.
- **Acción sugerida**: separar handlers por comando en clases o archivos (`commands/SosCommand.php`, `commands/CitacionCommand.php`, etc.).

### 7.2 `routes/audit_full.php`

- Único archivo con ~40 endpoints de auditoría y helpers (`auditJson`, `auditError`, `isValidUUID`, `auditFilters`).
- **Acción sugerida**: dividir por dominio (`audit_attendance.php`, `audit_discipline.php`, etc.) o usar un dispatcher.

### 7.3 `routes/misc.php`

- Agrupa contacto, notificaciones, búsqueda, report preview y webhook inbound de Twilio.
- **Acción sugerida**: separar en `contact.php`, `notifications.php`, `search.php`, `reports.php`, `twilio_inbound.php`.

---

## 8. Notas sobre workers

### 8.1 `worker_audit.php`

- Usa `generateUuidV4()` local en lugar de `uuid-ossp`/`gen_random_uuid()`. No es legacy, pero es redundante con la capacidad de PostgreSQL.
- Construye `actionDetailsJson` manualmente con strings en lugar de `json_encode` de un array; esto es propenso a errores de escape.

### 8.2 `worker_biometric.php`

- Los scripts Lua embebidos (`$scriptReliablePop`, `$scriptGc`) contienen comentarios en español y lógica atómica. Son correctos pero difíciles de testear fuera de Redis.
- `DELETE_STUDENT` hace `UPDATE students SET active=FALSE,biometric_hash=NULL` pero no desactiva relaciones ni asignaciones.

### 8.3 `worker_twilio.php`

- Tiene su propia función `securityLog` que escribe a `stderr`; `api.php` tiene `securityLog` que encola en Redis. Unificar.
- El fallback a template solo soporta una variable `{{1}}`.

---

## 9. SQL y tests

### 9.1 `sql/fix_migration.sql` — parche legacy

- **Motivo**: corrige errores de orden en `nexo_full_migration.sql` original (`get_current_school_id` y `register_migration` usadas antes de definirse).
- **Estado**: parche manual; si `nexo_full_migration.sql` ya está corregido, no es necesario.
- **Acción sugerida**: marcar como obsoleto una vez confirmado que nadie usa la versión antigua de la migración.

### 9.2 `sql/archive/` — migraciones legacy archivadas

- Directorio con migraciones antiguas que usan tipos `INTEGER` en lugar de `UUID`.
- `schema_migrations_backfill.sql` las marca como `success = FALSE` con nota `ARCHIVADO`.
- **Acción sugerida**: conservar por auditoría, pero no ejecutar.

### 9.3 `tests/01_*.php`, `09_*.php`, `12_*.php` — numeración faltante

- La suite salta de `02` a `08`, luego `10`, `11`, `13`, `14`, `15`. Faltan `01`, `09` y `12`.
- **Indica**: renumeración o eliminación de tests sin renombrar los restantes.
- **Acción sugerida**: renumerar tests para secuencia continua o documentar por qué faltan.

### 9.4 Duplicación de tests autónomos

- `FullSystemAlignmentTest.php`, `PlanComplianceTest.php`, `SchemaPhpAlignmentTest.php` e `integration_test.php` verifican solapadamente:
  - Tablas PHP referenciadas existen en SQL.
  - Roles y permisos PHP existen en SQL.
  - Eliminación de SUPER_RECTOR.
  - Preservación de GUARDIAN.
- **Problema**: alto costo de mantenimiento; si cambia una regla hay que tocar 4 archivos.
- **Acción sugerida**: consolidar en un solo `SystemAlignmentTest` PHPUnit con asserts claros.

### 9.5 `tests/InstallationTest.php` — `config.php`

```php
public function testConfigFileExists(): void
{
    $this->assertFileExists(__DIR__ . '/../config.php', ...);
}
```

- **Problema**: el proyecto usa variables de entorno, no un `config.php` real. Este test probablemente falla.
- **Acción sugerida**: eliminar el test o crear `config.php` vacío/documentado.

### 9.6 `tests/PanicButtonTest.php` — MockRedis y función global condicional

```php
if (!function_exists('getRedisConnection')) {
    function getRedisConnection() { ... }
}
```

- **Problema**: define `getRedisConnection` global solo si no existe. Si se ejecuta antes de `_auth_middleware.php`, puede anular la implementación real. También redefine `securityLog`.
- **Acción sugerida**: usar clases/mock inyectado en lugar de funciones globales condicionales.

---

## 11. Edge (C++/Python)

### 11.1 `CommandWorker` en `main.cpp`

- Clase HTTP-polling V1 que se declara pero **no se arranca** en `main()`; fue reemplazada por `MqttCommandWorker`.
- **Sugerencia**: eliminar o marcar `[[deprecated]]` si se mantiene en codebase por compatibilidad.

### 11.2 `PAE` (Programa de Alimentación Escolar)

- Menú 2 en `main.cpp` loguea "PAE mode deprecated and removed" y no hace nada.
- `SqliteManager::savePAE()` es un stub que retorna `true` sin persistencia.
- **Sugerencia**: eliminar opción del menú y funciones stub si se abandonó el PAE.

### 11.3 Funciones stub en `SqliteManager`

- `checkInasistencia`, `deleteInasistencia`, `savePAE`, `setConfig`, `getConfig` no implementan lógica real.
- **Impacto**: inasistencias y configuración runtime no se persisten.

### 11.4 `RealGpioManager` no registrado

- Clase local en `RealGpioManager.cpp` no se expone a `main.cpp`; `main()` usa `DevStubNotification`.
- **Sugerencia**: usar fábrica o selección en `main()` según config (`use_real_gpio`).

### 11.5 `IHttpClient` y `CloudManager`

- `CloudManager` posee `m_httpClient` pero `curlPost` rama se usa por defecto; `IHttpClient` queda subutilizado.
- **Sugerencia**: que `CloudManager` reciba un `IHttpClient*` y `curlPost` sea la implementación por defecto.

### 11.6 `Encryption` — clave AES en texto plano

- `initialize()` lee `nexo_aes_key_b64` como cadena de 32 caracteres, no base64 decodificado a 32 bytes.
- Debilita la protección; la clave se almacena en SQLite como string legible.

### 11.7 Build, scripts y configuración

- `setup_nexo.sh` referencia `Logica de negocio/edge` (ruta legacy); en el repo actual es `backend/edge`.
- `CMakePresets.json` apunta a `cmake/arm64-pi4-toolchain.cmake` que no existe.
- `Dockerfile.edge` no instala runtime de `libspdlog` ni `libmosquitto`; el binario puede fallar al arrancar en imagen final.
- `config.example.json` GPIO pins `32/33/34` no coinciden con `RealGpioManager.cpp` (`17/27/22`).
- `RealGpioManager.cpp` define una clase local sin registro; no se usa en `main.cpp`.

## 12. Recomendación final

No se deben eliminar archivos sin antes confirmar con el frontend y con el roadmap. La mayoría de los ítems anteriores son **deuda técnica documentada** y deben abordarse en iteraciones posteriores:

1. Consolidar librerías comunes (`lib/twilio.php`, futuro `lib/utils.php`).
2. Eliminar endpoints vacíos o deprecar los legacy con respuesta 410/redirect.
3. Separar archivos grandes (`operations.php`, `audit_full.php`, `misc.php`) en módulos.
4. Implementar validación completa de `token_hash` para edge.
5. Requerir explícitamente variables críticas (`APP_NEXO_HMAC_SECRET`, claves JWT/Twilio) al arranque.
6. Eliminar/refactorizar `CommandWorker` y PAE en edge si se confirma obsolescencia.
7. Corregir `setup_nexo.sh` para apuntar a `backend/edge` y añadir `libspdlog`/`libmosquitto`.
8. Crear `cmake/arm64-pi4-toolchain.cmake` o eliminar el preset cross-compile.
9. Sincronizar pines GPIO entre `config.example.json` y `RealGpioManager.cpp`.
