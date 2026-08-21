/**
 * =============================================================================
 * main.cpp — Punto de entrada y bucle principal del nodo edge NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Inicializa toda la infraestructura del dispositivo edge (config, logger,
 *   SQLite, criptografía, sensor biométrico, display, notificaciones) y arranca
 *   los workers de sincronización y comandos. Proporciona un menú interactivo
 *   para modos de asistencia, secretaría y sync manual, además de procesar
 *   comandos recibidos por MQTT. Incluye HealthMonitor y watchdog de hardware.
 *
 * FLUJO GENERAL:
 *   main()
 *      │
 *      ├── curl_global_init / ConfigManager / Logger
 *      ├── Verificación de reloj (NTP + año >= 2024)
 *      ├── SQLite / Encryption / Security provisioning
 *      ├── HAL: sensor biométrico, display, notificaciones (stub/real)
 *      ├── SyncWorker.start()  -> cola audit_trail -> CloudManager
 *      ├── MqttCommandWorker.start() (opcional, V2)
 *      ├── HealthMonitor.start() (monitorea SyncWorker/MQTT)
 *      ├── HardwareWatchdog
 *      │
 *      └── Bucle principal: menú → modo perpetuo / secretaría / sync manual
 *              └── handleBiometricMatch() -> AuditTrail -> SQLite -> nudge sync
 *
 * DEPENDENCIAS:
 *   - SQLite, OpenSSL, libcurl, spdlog, nlohmann/json
 *   - libmosquitto (MQTT, opcional)
 *   - libgpiod + /dev/gpiochip4 (GPIO real, opcional)
 *   - /dev/i2c-1 + OLED SSD1306 (opcional)
 *   - /dev/watchdog (hardware watchdog, opcional)
 *   - libzkfp (ZKTeco ZK9500, opcional; en modo stub no se usa)
 */

#include <iostream>
#include <csignal>
#include <atomic>
#include <string>
#include <thread>
#include <chrono>
#include <mutex>
#include <condition_variable>
#include <ctime>
#include <poll.h>
#include <unistd.h>
#include <curl/curl.h>
#include <nlohmann/json.hpp>
#include <random>
#include <sstream>
#include <queue>
#include <iomanip>
#include <cstdlib>
#include <array>
#include <memory>
#include <cstdio>
#include <filesystem>
#include <fstream>

#include "utils/Logger.h"
#include "utils/ConfigManager.h"
#include "utils/NexoResult.h"
#include "base_de_datos/sqlite_manager.h"
#include "base_de_datos/encryption.h"
#include "base_de_datos/cloud_manager.h"
#include "mqtt/mqtt_command_worker.h"
#include "hardware/watchdog.h"
#include "hardware/dev_stub/DevStubBiometricSensor.h"
#include "hardware/real/Zk9500BiometricSensor.h"
#include "hardware/real/UareU5300BiometricSensor.h"
#include "hardware/dev_stub/DevStubDisplay.h"
#include "hardware/dev_stub/DevStubNotification.h"
#include "interoperabilidad/audit_trail.h"

// FIX C8: Hardware real condicional — OLED SSD1306 + GPIO LEDs/buzzer
#if defined(HAS_REAL_DISPLAY)
#include "hardware/real/RealOledDisplay.h"
#endif
#if defined(HAS_REAL_GPIO)
#include "hardware/real/RealGpioManager.h"
#endif

// =============================================================================
// Globals
// =============================================================================
// g_shutdownRequested: señal de SIGINT/SIGTERM para graceful shutdown.
// g_clockValid:        false si NTP/año indica que el reloj no es confiable;
//                      en ese caso se bloquean las lecturas biométricas.
std::atomic<bool> g_shutdownRequested(false);
std::atomic<bool> g_clockValid(true);
std::atomic<IBiometricSensor*> g_activeSensor{nullptr};

// =============================================================================
// Signal handler
// =============================================================================
// Captura SIGINT y SIGTERM para levantar g_shutdownRequested.
void signalHandler(int signal) {
    g_shutdownRequested.store(true, std::memory_order_release);
    auto* sensor = g_activeSensor.load(std::memory_order_acquire);
    if (sensor) sensor->cancelCapture();
    (void)signal;
}

// =============================================================================
// Non-blocking stdin reader using poll()
// =============================================================================
// Lee líneas desde stdin sin bloquear, de modo que el bucle principal pueda
// seguir pateando el watchdog y procesando comandos MQTT. En systemd (sin TTY)
// retorna false inmediatamente.
bool readLineNonBlocking(std::string& out, int timeoutMs = 500) {
    out.clear();
    
    // FIX: Si el proceso es un servicio de systemd, no intentar leer STDIN.
    if (!isatty(STDIN_FILENO)) {
        std::this_thread::sleep_for(std::chrono::milliseconds(timeoutMs));
        return false;
    }

    struct pollfd pfd{};
    pfd.fd = STDIN_FILENO;
    pfd.events = POLLIN;

    while (!g_shutdownRequested.load(std::memory_order_acquire)) {
        int ret = poll(&pfd, 1, timeoutMs);
        if (ret < 0) {
            if (errno == EINTR) continue;
            return false;
        }
        if (ret == 0) return false;  // FIX: timeout sin input → retornar false para que el main loop procese comandos
        if (pfd.revents & POLLIN) {
            char buf[256];
            ssize_t n = read(STDIN_FILENO, buf, sizeof(buf) - 1);
            if (n <= 0) return false;
            buf[n] = '\0';
            out.append(buf, static_cast<size_t>(n));
            auto pos = out.find('\n');
            if (pos != std::string::npos) {
                out.erase(pos);
                if (!out.empty() && out.back() == '\r') out.pop_back();
                return true;
            }
        }
    }
    return false;
}

// =============================================================================
// Async Cloud Sync Worker
// =============================================================================
// Hilo que consume audit_trail en lotes y envía cada evento cifrado al
// backend usando CloudManager. Implementa DLQ después de 5 intentos fallidos,
// backoff exponencial con jitter y nudge manual desde main/secretaría.
class SyncWorker {
public:
    void start() {
        m_thread = std::thread([this] { run(); });
    }

    void requestStop() {
        m_stop.store(true, std::memory_order_release);
        m_cv.notify_all();
    }

    void join() {
        if (m_thread.joinable()) m_thread.join();
    }

    void nudge() { m_cv.notify_all(); }

    // FIX (SRE-2): Timestamp de última actividad para HealthMonitor
    std::atomic<std::chrono::steady_clock::time_point> m_lastActivity{std::chrono::steady_clock::now()};
    std::chrono::steady_clock::time_point lastActivity() const { return m_lastActivity.load(std::memory_order_acquire); }

private:
    std::thread m_thread;
    std::atomic<bool> m_stop{false};
    std::mutex m_mtx;
    std::condition_variable m_cv;

    void run() {
        LOG_INFO("[SyncWorker] Cloud sync thread started");
        // FIX C3: Contador para purgar audit_trail una vez al día (~2880 ciclos de 30s)
        int cycleCount = 0;
        while (!m_stop.load(std::memory_order_acquire)) {
            m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
            syncBatch();
            // FIX C3: Purgar registros antiguos cada ~24h (2880 ciclos × 30s)
            if (++cycleCount >= 2880) {
                cycleCount = 0;
                auto& db = SqliteManager::getInstance();
                int purged = db.purgeOldAuditTrail(30, 90);
                if (purged > 0) {
                    db.vacuum();  // Reclamar espacio físico solo si hubo purgado
                }
            }
            std::unique_lock<std::mutex> lk(m_mtx);
            m_cv.wait_for(lk, std::chrono::seconds(30), [this] {
                return m_stop.load(std::memory_order_acquire);
            });
        }
        syncBatch();
        LOG_INFO("[SyncWorker] Cloud sync thread stopped");
    }

    // PILAR 4.1: Generador de request_id simple (hex)
    std::string generateRequestId() {
        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_int_distribution<> dis(0, 15);
        std::stringstream ss;
        for (int i = 0; i < 16; ++i) ss << std::hex << dis(gen);
        return ss.str();
    }

    void syncBatch() {
        m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
        std::vector<AuditRecord> audits; // <-- ACTUALIZADO
        auto& db = SqliteManager::getInstance();
        if (!db.getPendingAudits(audits) || audits.empty()) return;

        int synced = 0, failed = 0;
        int delayMs = 1000; // 1 segundo inicial
        std::random_device rd;
        std::mt19937 gen(rd());
        std::uniform_real_distribution<> jitter(-0.3, 0.3);
        static std::atomic<uint64_t> nonceCounter{0};

        for (auto& record : audits) { // <-- ACTUALIZADO
            m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
            if (m_stop.load(std::memory_order_acquire)) break;

            if (record.attempts >= 5) {
                LOG_WARN("[SyncWorker] Registro de auditoria doc={} id={} fallo mas de 5 veces. Marcando error (synced=-1) en DLQ.", record.documento, record.id);
                db.markAuditError(record.id);
                continue;
            }

            Estudiante est;
            if (!db.getEstudianteByDocumento(record.documento, est)) {
                // FIX: Evita el bucle infinito al limpiar huérfanos
                LOG_WARN("[SyncWorker] Registro huerfano para doc {}. Limpiando cola.", record.documento);
                db.clearAudit(record.id);
                continue;
            }

            // FIX: Añadir timestamp REAL, nonce único y request_id para evitar Replay y trazabilidad
            nlohmann::json j;
            j["action"] = "SYNC_ATTENDANCE";
            j["doc"] = est.documento;
            j["event"] = record.event; // <-- ACTUALIZADO
            j["parent_tel"] = est.telefono_acudiente;
            j["captured_at"] = record.timestamp; // <-- ¡LA MAGIA OCURRE AQUÍ!
            j["device_token"] = Encryption::getInstance().getToken();
            j["device_id"] = ConfigManager::getInstance().getDeviceId();
            uint64_t micro = std::chrono::duration_cast<std::chrono::microseconds>(
                std::chrono::system_clock::now().time_since_epoch()).count();
            j["nonce"] = std::to_string(micro) + "_" + est.documento + "_" + std::to_string(nonceCounter.fetch_add(1));
            j["request_id"] = generateRequestId(); // PILAR 4.1
            std::string payload = j.dump();

            if (CloudManager::getInstance().syncRecord(payload)) {
                db.clearAudit(record.id); // <-- ACTUALIZADO a usar ID
                ++synced;
                delayMs = 1000; // Resetear delay al exito
            } else {
                ++failed;
                db.incrementAuditAttempt(record.id);
                // PILAR 1.2: Exponential Backoff con Jitter (±30%)
                double j_val = 1.0 + jitter(gen);
                int sleepMs = static_cast<int>(delayMs * j_val);
                if (sleepMs < 500) sleepMs = 500;
                std::this_thread::sleep_for(std::chrono::milliseconds(sleepMs));
                delayMs = std::min(delayMs * 2, 60000); // Duplicar hasta max 60s
            }
        }
        if (synced > 0 || failed > 0) {
            LOG_INFO("[SyncWorker] Batch complete: synced={} failed={}", synced, failed);
        }
    }
};

// =============================================================================
// Command Worker (M2M — recibe comandos desde la nube via HTTP polling)
// =============================================================================
// (V1) Hilo que cada 30s consulta /devices/commands por HTTP. En V2 este rol
// es desempeñado por MqttCommandWorker; esta clase queda como fallback.

// FIX C7: HeartbeatWorker — envía POST /devices/ping cada 30s siempre,
// incluso cuando MQTT está habilitado. Esto asegura que el edge aparezca
// online en el dashboard de health check del RECTOR.
class HeartbeatWorker {
public:
    void start(const std::string& apiBase, const std::string& deviceToken, const std::string& deviceId) {
        m_apiBase = apiBase;
        m_deviceToken = deviceToken;
        m_deviceId = deviceId;
        m_thread = std::thread([this] { run(); });
    }

    void requestStop() { m_stop.store(true, std::memory_order_release); }
    void join() { if (m_thread.joinable()) m_thread.join(); }

    std::chrono::steady_clock::time_point lastActivity() const { return m_lastActivity.load(std::memory_order_acquire); }

private:
    std::thread m_thread;
    std::atomic<bool> m_stop{false};
    std::string m_apiBase, m_deviceToken, m_deviceId;
    std::atomic<std::chrono::steady_clock::time_point> m_lastActivity{std::chrono::steady_clock::now()};

    static size_t writeCallback(void* contents, size_t size, size_t nmemb, std::string* userp) {
        userp->append((char*)contents, size * nmemb);
        return size * nmemb;
    }

    void run() {
        LOG_INFO("[HeartbeatWorker] Started (POST /devices/ping every 30s)");
        while (!m_stop.load(std::memory_order_acquire)) {
            sendPing();
            for (int i = 0; i < 30 && !m_stop.load(std::memory_order_acquire); ++i) {
                std::this_thread::sleep_for(std::chrono::seconds(1));
            }
        }
        LOG_INFO("[HeartbeatWorker] Stopped");
    }

    void sendPing() {
        m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);

        if (m_apiBase.empty() || m_deviceId.empty() || m_deviceToken.empty()) {
            LOG_WARN("[HeartbeatWorker] Missing api_url, device_id, or device_token — skipping ping");
            return;
        }

        std::string url = m_apiBase + "/devices/ping";
        std::string body = nlohmann::json({
            {"device_id", m_deviceId},
            {"status", "online"},
            {"timestamp", std::chrono::duration_cast<std::chrono::seconds>(
                std::chrono::system_clock::now().time_since_epoch()).count()}
        }).dump();

        CURL* curl = curl_easy_init();
        if (!curl) return;

        std::string readBuffer;
        struct curl_slist* headers = nullptr;
        headers = curl_slist_append(headers, "Content-Type: application/json");
        headers = curl_slist_append(headers, ("X-Device-Token: " + m_deviceToken).c_str());
        headers = curl_slist_append(headers, "User-Agent: nexo-edge/1.0");

        curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
        curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCallback);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &readBuffer);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 5L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);

        CURLcode res = curl_easy_perform(curl);
        long httpCode = 0;
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);
        curl_easy_cleanup(curl);
        curl_slist_free_all(headers);

        if (res != CURLE_OK || (httpCode != 200 && httpCode != 202)) {
            LOG_WARN("[HeartbeatWorker] Ping failed: HTTP {} | {}", httpCode, curl_easy_strerror(res));
        } else {
            LOG_DEBUG("[HeartbeatWorker] Ping OK (HTTP {})", httpCode);
        }
    }
};

class CommandWorker {
public:
    void start(const std::string& apiBase, const std::string& deviceToken, const std::string& deviceId) {
        m_apiBase = apiBase;
        m_deviceToken = deviceToken;
        m_deviceId = deviceId;
        m_thread = std::thread([this] { run(); });
    }

    void requestStop() {
        m_stop.store(true, std::memory_order_release);
    }

    void join() {
        if (m_thread.joinable()) m_thread.join();
    }

    // V2: Cola de comandos para que el main loop los procese con acceso a sensor/display/sync
    bool hasPendingCommand() {
        std::lock_guard<std::mutex> lock(m_queueMutex);
        return !m_commandQueue.empty();
    }

    std::string popCommand() {
        std::lock_guard<std::mutex> lock(m_queueMutex);
        if (m_commandQueue.empty()) return "";
        std::string cmd = std::move(m_commandQueue.front());
        m_commandQueue.pop();
        return cmd;
    }

private:
    std::thread m_thread;
    std::atomic<bool> m_stop{false};
    std::string m_apiBase;
    std::string m_deviceToken;
    std::string m_deviceId;

    std::mutex m_queueMutex;
    std::queue<std::string> m_commandQueue;

    static size_t writeCallback(void* contents, size_t size, size_t nmemb, std::string* userp) {
        userp->append((char*)contents, size * nmemb);
        return size * nmemb;
    }

    void run() {
        LOG_INFO("[CommandWorker] Command polling thread started (30s interval)");
        while (!m_stop.load(std::memory_order_acquire)) {
            pollCommands();
            for (int i = 0; i < 30 && !m_stop.load(std::memory_order_acquire); ++i) {
                std::this_thread::sleep_for(std::chrono::seconds(1));
            }
        }
        LOG_INFO("[CommandWorker] Command polling thread stopped");
    }

    void pollCommands() {
        std::string url = m_apiBase + "/devices/commands?device_id=" + m_deviceId;
        LOG_INFO("[CommandWorker] Polling: {}", url);
        CURL* curl = curl_easy_init();
        if (!curl) return;

        std::string readBuffer;
        struct curl_slist* headers = nullptr;
        headers = curl_slist_append(headers, ("X-Device-Token: " + m_deviceToken).c_str());

        curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
        curl_easy_setopt(curl, CURLOPT_HTTPGET, 1L);
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCallback);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &readBuffer);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 10L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
        curl_easy_setopt(curl, CURLOPT_FRESH_CONNECT, 1L);
        curl_easy_setopt(curl, CURLOPT_FORBID_REUSE, 1L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
        curl_easy_setopt(curl, CURLOPT_USERAGENT, "nexo-edge/1.0");

        CURLcode res = curl_easy_perform(curl);
        long httpCode = 0;
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);
        curl_easy_cleanup(curl);
        curl_slist_free_all(headers);

        if (res != CURLE_OK || httpCode != 200) {
            LOG_WARN("[CommandWorker] Poll failed: HTTP {} | {} | URL: {} | Body: {}", httpCode, curl_easy_strerror(res), url, readBuffer);
            return;
        }

        try {
            auto json = nlohmann::json::parse(readBuffer);
            if (json.contains("data") && json["data"].is_array()) {
                for (const auto& cmd : json["data"]) {
                    std::string command = cmd.value("command", "");
                    LOG_INFO("[CommandWorker] Received command: {}", command);
                    // V2: Encolar TODOS los comandos para que el main loop los procese
                    // con acceso a biometricSensor, display, syncWorker, enrollStudentOnDevice.
                    // Los comandos simples (RELOAD_CONFIG, FORCE_SYNC) se procesan inline;
                    // los que necesitan hardware (ENROLL_REQUEST, AUTHORIZE_EXIT) van a la cola.
                    if (command == "REBOOT") {
                        // FIX A6: REBOOT real — sync graceful + system("reboot")
                        // CommandWorker no tiene acceso a syncWorker, pero el REBOOT
                        // también llega por MQTT donde sí se hace sync. Aquí solo reboot.
                        LOG_WARN("[CommandWorker] REBOOT ordered by cloud. Rebooting...");
                        sync();
                        system("reboot");
                    } else if (command == "RELOAD_CONFIG") {
                        LOG_INFO("[CommandWorker] Reloading configuration");
                        ConfigManager::getInstance().loadConfig();
                    } else if (command == "FORCE_SYNC") {
                        LOG_INFO("[CommandWorker] Force sync requested from cloud");
                        // El SyncWorker se nudges desde el menú; aquí se podría usar una cv compartida
                    } else if (command == "UPDATE_FIRMWARE") {
                        LOG_WARN("[CommandWorker] Firmware update requested (placeholder)");
                    } else {
                        // ENROLL_REQUEST, AUTHORIZE_EXIT, DELETE_STUDENT, etc.
                        // Encolar para que el main loop los procese con acceso al hardware
                        std::lock_guard<std::mutex> lock(m_queueMutex);
                        m_commandQueue.push(cmd.dump());
                        LOG_INFO("[CommandWorker] Command {} enqueued for main loop", command);
                    }
                }
            }
        } catch (const std::exception& e) {
            LOG_WARN("[CommandWorker] JSON parse error: {}", e.what());
        }
    }
};

// =============================================================================
// Time helpers (POSIX)
// =============================================================================
// Obtiene hora UTC en zona Bogotá y clasifica el ingreso en PUNTUAL, MANANA,
// TARDE, MADRUGADA o EXTRAORDINARIO para fines de reporte.
struct LocalTime {
    int hour = 0, min = 0, sec = 0;
    bool valid = false;
};

LocalTime getLocalTimeBogota() {
    time_t now = time(nullptr);
    struct tm tm_buf{};
    localtime_r(&now, &tm_buf);
    return {tm_buf.tm_hour, tm_buf.tm_min, tm_buf.tm_sec, true};
}

std::string checkLateStatus() {
    auto t = getLocalTimeBogota();
    if (!t.valid) return "ERROR_TIME";

    int totalMin = t.hour * 60 + t.min;
    if (totalMin >= 400 && totalMin <= 420) return "PUNTUAL";
    if (totalMin > 420 && totalMin <= 660) return "MANANA";
    if (totalMin > 690 && totalMin <= 960) return "TARDE";
    if (totalMin < 400) return "MADRUGADA";
    return "EXTRAORDINARIO";
}

// =============================================================================
// NTP / System Clock Verification
// =============================================================================
// Verifica sincronización NTP y que el año del sistema sea >= 2024. Si el
// reloj es inválido, g_clockValid=false y se bloquean lecturas biométricas.
// Intenta múltiples métodos: chronyc (modern), ntpdate (legacy), timedatectl.
bool checkNtpSync() {
    std::array<char, 128> buffer;
    std::string result;
    auto pclose_deleter = [](FILE* f) { if (f) pclose(f); };

    // Método 1: chronyc (systemd/chrony — estándar en Fedora/RHEL/RPi OS moderno)
    std::unique_ptr<FILE, decltype(pclose_deleter)> pipe(
        popen("chronyc tracking 2>/dev/null | grep -oP 'System time:\\s+\\K[-\\d.]+'", "r"), pclose_deleter);
    if (pipe) {
        while (fgets(buffer.data(), buffer.size(), pipe.get()) != nullptr) {
            result += buffer.data();
        }
    }
    pipe.reset();

    // Método 2: ntpdate (legacy, si chronyc no devolvió nada)
    if (result.empty()) {
        pipe.reset(popen("ntpdate -q pool.ntp.org 2>/dev/null | grep -oP 'offset \\K[-\\d.]+'", "r"));
        if (pipe) {
            while (fgets(buffer.data(), buffer.size(), pipe.get()) != nullptr) {
                result += buffer.data();
            }
        }
        pipe.reset();
    }

    // Método 3: timedatectl (systemd — verifica si NTP está sincronizado)
    if (result.empty()) {
        pipe.reset(popen("timedatectl show --property=NTPSynchronized --value 2>/dev/null", "r"));
        if (pipe) {
            while (fgets(buffer.data(), buffer.size(), pipe.get()) != nullptr) {
                result += buffer.data();
            }
        }
        pipe.reset();
        if (result.find("yes") != std::string::npos) {
            LOG_INFO("NTP synchronized (timedatectl)");
            return true;
        }
        result.clear();
    }

    if (!result.empty()) {
        try {
            double offset = std::stod(result);
            if (std::abs(offset) > 60.0) {
                LOG_WARN("Clock offset {:.2f}s. Sync recommended but not blocking.", offset);
            } else {
                LOG_INFO("NTP offset: {:.2f} seconds", offset);
            }
            return true;
        } catch (...) {
            LOG_WARN("Could not parse NTP offset");
        }
    }
    LOG_WARN("Could not determine NTP offset, falling back to year check");
    return true;
}

bool checkSystemClock() {
    time_t now = time(nullptr);
    struct tm tm_buf{};
    localtime_r(&now, &tm_buf);
    if (tm_buf.tm_year < 124) { // Año < 2024
        LOG_CRITICAL("System clock is seriously wrong (year < 2024). Please sync time.");
        return false;
    }
    return true;
}

// =============================================================================
// Security Provisioning Wizard (Headless-safe)
// =============================================================================
// Carga AES key (32 bytes) y API token desde /boot/nexo_provision.json o de
// forma interactiva (TTY). El archivo de provisionamiento se elimina tras usar.
bool runSecurityProvisioning() {
    Encryption& crypto = Encryption::getInstance();

    // Already provisioned — nothing to do.
    if (crypto.isKeyProvisioned() && crypto.isTokenProvisioned()) {
        return true;
    }

    // Auto-provision token from config.json if available
    std::string configToken = ConfigManager::getInstance().getDeviceToken();
    if (!configToken.empty() && !crypto.isTokenProvisioned()) {
        crypto.provisionToken(configToken);
        LOG_INFO("API token auto-provisioned from config.json");
    }

    const std::string provisionPath = ConfigManager::getInstance().getString("provision_file", "/boot/nexo_provision.json");

    while (!crypto.isKeyProvisioned() || !crypto.isTokenProvisioned()) {
        // FIX (SRE-3): Auto-provision from staging file injected via USB/MicroSD.
        if (std::filesystem::exists(provisionPath)) {
            try {
                std::ifstream f(provisionPath);
                if (!f.is_open()) {
                    throw std::runtime_error("Cannot open provision file");
                }
                nlohmann::json j = nlohmann::json::parse(f);
                f.close();

                std::string key   = j.value("aes_key", "");
                std::string token = j.value("api_token", "");
                std::string provisionDeviceId = j.value("device_id", "");

                if (key.length() != 32) {
                    throw std::runtime_error("Invalid AES key length in provision file");
                }
                if (token.empty()) {
                    throw std::runtime_error("Empty API token in provision file");
                }

                if (!crypto.provisionKey(key)) {
                    throw std::runtime_error("Failed to provision AES key from file");
                }
                if (!crypto.provisionToken(token)) {
                    throw std::runtime_error("Failed to provision API token from file");
                }

                // FIX C2: Auto-update device_id desde provision file si es UUID v4 válido
                if (!provisionDeviceId.empty() && ConfigManager::isValidUuidV4(provisionDeviceId)) {
                    ConfigManager::getInstance().setValue("device_id", provisionDeviceId, true);
                    LOG_INFO("device_id auto-updated from provision file: {}", provisionDeviceId);
                } else if (!provisionDeviceId.empty()) {
                    LOG_WARN("Provision file contains device_id '{}' but it is not a valid UUID v4. Ignored.", provisionDeviceId);
                }

                // Securely delete the one-time staging file
                std::filesystem::remove(provisionPath);
                LOG_INFO("Security provisioning completed from {}. File securely deleted.", provisionPath);
                return true;
            } catch (const std::exception& e) {
                LOG_ERROR("Provision file error: {}. Retrying in 10s...", e.what());
                std::this_thread::sleep_for(std::chrono::seconds(10));
            }
            continue;
        }

        // Interactive fallback: only when a TTY is present (development)
        if (isatty(STDIN_FILENO)) {
            std::cout << "\n  NEXO: CONFIGURACION DE SEGURIDAD REQUERIDA\n";
            std::cout << "Este nodo no tiene claves criptograficas configuradas.\n\n";

            if (!crypto.isKeyProvisioned()) {
                std::cout << "PASO 1: Clave AES-256-GCM (32 caracteres exactos)\n";
                std::cout << "Ingrese la clave: ";
                std::string key;
                if (!readLineNonBlocking(key, 60000) || key.length() != 32) {
                    LOG_ERROR("Invalid AES key length (need 32, got {})", key.length());
                    return false;
                }
                if (!crypto.provisionKey(key)) {
                    LOG_ERROR("Failed to provision AES key");
                    return false;
                }
            }
            if (!crypto.isTokenProvisioned()) {
                std::cout << "PASO 2: Token de Autenticacion API\n";
                std::cout << "Ingrese el token: ";
                std::string token;
                if (!readLineNonBlocking(token, 60000) || token.empty()) {
                    LOG_ERROR("Empty API token");
                    return false;
                }
                if (!crypto.provisionToken(token)) {
                    LOG_ERROR("Failed to provision API token");
                    return false;
                }
            }
            LOG_INFO("Security provisioning completed via TTY");
            return true;
        }

        // Headless systemd: no TTY and no provision file. Wait and retry
        // instead of crashing, preventing a systemd crash-loop.
        LOG_WARN("No provision file at {} and no TTY. Waiting for staging...", provisionPath);
        std::this_thread::sleep_for(std::chrono::seconds(10));
    }

    return true;
}

// =============================================================================
// Health Monitor: Detecta threads muertos y fuerza reinicio
// =============================================================================
// Supervisor interno que revisa lastActivity() de SyncWorker y MqttCommandWorker
// cada 30s. Si un worker está inactivo más de 180s/240s acumula strikes y, tras
// 5 strikes, exit(1) para que systemd reinicie el servicio.
class HealthMonitor {
public:
    HealthMonitor(SyncWorker& syncWorker, MqttCommandWorker* mqttWorker)
        : m_sync(syncWorker), m_mqtt(mqttWorker), m_stop(false) {}

    void start() {
        m_thread = std::thread([this] { run(); });
    }

    void stop() {
        m_stop.store(true, std::memory_order_release);
        if (m_thread.joinable()) m_thread.join();
    }

private:
    SyncWorker& m_sync;
    MqttCommandWorker* m_mqtt;
    std::atomic<bool> m_stop;
    std::thread m_thread;

    static constexpr auto SYNC_MAX_STALE = std::chrono::seconds(180);
    static constexpr auto MQTT_MAX_STALE = std::chrono::seconds(240);
    static constexpr auto CHECK_INTERVAL = std::chrono::seconds(30);

    void run() {
        LOG_INFO("[HealthMonitor] Started (check every 30s)");
        int syncStrikes = 0;
        int mqttStrikes = 0;

        while (!m_stop.load(std::memory_order_acquire)) {
            std::this_thread::sleep_for(CHECK_INTERVAL);

            auto now = std::chrono::steady_clock::now();

            // Check SyncWorker heartbeat
            auto syncDelta = now - m_sync.lastActivity();
            if (syncDelta > SYNC_MAX_STALE) {
                syncStrikes++;
                LOG_ERROR("[HealthMonitor] SyncWorker stale for {}s (Strike {}/5).",
                             std::chrono::duration_cast<std::chrono::seconds>(syncDelta).count(), syncStrikes);
                if (syncStrikes >= 5) {
                    LOG_CRITICAL("[HealthMonitor] SyncWorker permanently dead. Forcing self-destruction.");
                    exit(1);
                }
            } else {
                syncStrikes = 0;
            }

            // Check MQTT heartbeat (only if mqtt is configured)
            if (m_mqtt) {
                auto mqttDelta = now - m_mqtt->lastActivity();
                if (mqttDelta > MQTT_MAX_STALE) {
                    mqttStrikes++;
                    LOG_ERROR("[HealthMonitor] MQTT thread stale for {}s (Strike {}/5).",
                                 std::chrono::duration_cast<std::chrono::seconds>(mqttDelta).count(), mqttStrikes);
                    if (mqttStrikes >= 5) {
                        LOG_CRITICAL("[HealthMonitor] MQTT thread permanently dead. Forcing self-destruction.");
                        exit(1);
                    }
                } else {
                    mqttStrikes = 0;
                }
            }
        }
        LOG_INFO("[HealthMonitor] Stopped");
    }
};

// =============================================================================
// Business Logic: Handle biometric match
// =============================================================================
// Llamado cuando el sensor identifica una huella. Busca el estudiante por
// huella_id, registra el evento en AuditTrail (bloquea si falla SQLite) y,
// luego de confirmar persistencia, notifica éxito y actualiza patrones.
void handleBiometricMatch(uint32_t huellaId,
                          IDisplay* display,
                          INotification* notification,
                          SyncWorker& syncWorker) {
    auto& db = SqliteManager::getInstance();
    Estudiante est;

    if (!db.getEstudianteByHuellaID(huellaId, est)) {
        LOG_WARN("Fingerprint ID {} detected but not linked to any student", huellaId);
        notification->notifyError();
        display->showMessage("ERROR", "HUELLA NO VINCULADA");
        std::this_thread::sleep_for(std::chrono::seconds(2));
        display->clear();
        return;
    }

    std::string status = checkLateStatus();
    auto t = getLocalTimeBogota();
    char timeBuf[16];
    snprintf(timeBuf, sizeof(timeBuf), "%02d:%02d", t.hour, t.min);

    // FIX: Ocultar PII en logs
    std::string maskedDoc = est.documento;
    if (maskedDoc.length() > 4) {
        maskedDoc.replace(0, maskedDoc.length() - 4, maskedDoc.length() - 4, '*');
    }
    LOG_INFO("Match: id_{} doc={} status={} time={}", est.huella_id, maskedDoc, status, timeBuf);

    // FIX (SRE-3): Verificar persistencia local ANTES de permitir el acceso.
    // Si SQLite falla (disco lleno, SD corrupta, RO), NO se permite el ingreso
    // para evitar responsabilidad legal por pérdida de datos.
    std::string eventType = "INGRESO_" + status;
    if (!AuditTrail::logEvent(est.documento, eventType)) {
        LOG_CRITICAL("[SRE-3] SQLite persistence FAILED for doc={}. BLOCKING ACCESS.", maskedDoc);
        notification->notifyError();
        display->showMessage("ERROR", "ALMACENAMIENTO LLENO");
        std::this_thread::sleep_for(std::chrono::seconds(3));
        display->clear();
        return;
    }

    // Persistence confirmed: proceed with access
    notification->notifySuccess();
    display->showMessage(est.nombre, "Ingreso " + status + " " + std::string(timeBuf));

    bool wasAbsent = db.checkInasistencia(est.documento);
    if (wasAbsent) {
        db.deleteInasistencia(est.documento);
        LOG_INFO("[SAT] Absence alert cancelled for {}", est.documento);
    }

    bool temprano = (t.hour < 7);
    bool tarde = (t.hour >= 12);
    db.updatePattern(est.documento, temprano, tarde);

    syncWorker.nudge();

    std::this_thread::sleep_for(std::chrono::seconds(2));
    display->clear();
}

// =============================================================================
// UI Helpers — output limpio sin flood del menú
// =============================================================================

static bool g_menuShown = false;  // Controla si el menú está visible en pantalla

void showMainMenu() {
    if (g_menuShown) return;  // No reimprimir si ya está visible
    g_menuShown = true;
    std::cout << "\n"
        "╔══════════════════════════════════════════════════╗\n"
        "║         NEXO EDGE — NODO DE PRODUCCION           ║\n"
        "╠══════════════════════════════════════════════════╣\n"
        "║  1. Modo Perpetuo (Control Asistencia)          ║\n"
        "║  3. Modo Secretaria (Enrolar/Eliminar)          ║\n"
        "║  4. Sync Pendientes (manual)                    ║\n"
        "║  0. Salir                                       ║\n"
        "╚══════════════════════════════════════════════════╝\n"
        "  > Seleccione opción (o Enter para status): ";
    std::cout.flush();
}

void clearMenuLine() {
    // Limpiar la línea del prompt del menú para imprimir un evento encima
    if (g_menuShown) {
        std::cout << "\r\033[K";  // CR + borrar línea
        g_menuShown = false;
    }
}

void printEvent(const std::string& tag, const std::string& msg, const std::string& color = "") {
    clearMenuLine();
    if (color == "green")      std::cout << "\033[32m";
    else if (color == "red")   std::cout << "\033[31m";
    else if (color == "yellow") std::cout << "\033[33m";
    else if (color == "cyan")  std::cout << "\033[36m";
    std::cout << "  [" << tag << "] " << msg;
    if (!color.empty()) std::cout << "\033[0m";
    std::cout << "\n";
    std::cout.flush();
}

void reprintMenu() {
    g_menuShown = false;
    showMainMenu();
}

// =============================================================================
// Enrolamiento atómico compartido (menú local + comando remoto MQTT)
// =============================================================================
// Captura la huella vía sensor y persiste en SQLite ANTES de actualizar la
// cache del sensor. La transacción explícita permite rollback completo.
// Devuelve true solo si el estudiante queda persistido y en cache.
bool enrollStudentOnDevice(IBiometricSensor* sensor, const std::string& doc,
                           const std::string& nombre, const std::string& tel,
                           std::string& errOut) {
    auto& db = SqliteManager::getInstance();
    uint32_t huellaId = db.getNextHuellaID();
    std::vector<uint8_t> tpl;
    auto res = sensor->enrollUser(huellaId, tpl);
    if (!res) {
        errOut = res.message;
        LOG_ERROR("Enroll failed: {} - {}", toString(res.error), res.message);
        return false;
    }

    Estudiante est{doc, nombre, tel, "", huellaId, tpl.empty() ? std::vector<uint8_t>(256, 0) : tpl};
    sqlite3_exec(db.getDB(), "BEGIN;", nullptr, nullptr, nullptr);
    if (db.saveEstudiante(est)) {
        auto cacheRes = sensor->addTemplate(huellaId, est.template_huella);
        if (cacheRes) {
            sqlite3_exec(db.getDB(), "COMMIT;", nullptr, nullptr, nullptr);
            LOG_INFO("Estudiante enrolado localmente: doc={} nombre={}", doc, nombre);

            // Sincronizar con cloud (best-effort, no bloquea el enrolamiento local)
            // Usar registerStudentWithFingerprint para que el backend marque biometric_hash
            auto& cloud = CloudManager::getInstance();
            bool syncOk = cloud.registerStudentWithFingerprint(doc, nombre, tel, huellaId);
            if (!syncOk) {
                LOG_WARN("Enrolamiento local OK pero sync cloud falló. Se reintentará en próximo sync cycle.");
            } else {
                LOG_INFO("Enrolamiento sincronizado con cloud: doc={} huella_id={}", doc, huellaId);
            }
            return true;
        }
        sqlite3_exec(db.getDB(), "ROLLBACK;", nullptr, nullptr, nullptr);
        sensor->deleteUser(huellaId); // cache sanity cleanup
        errOut = cacheRes.message;
        LOG_ERROR("Sensor cache add failed for {}. SQLite rolled back: {}", doc, cacheRes.message);
        return false;
    }
    sqlite3_exec(db.getDB(), "ROLLBACK;", nullptr, nullptr, nullptr);
    sensor->deleteUser(huellaId); // rollback cache (no-op si no se añadió)
    errOut = "DB save failed";
    LOG_ERROR("DB save failed for {}. Sensor cache rolled back.", doc);
    return false;
}

void modoSecretaria(IBiometricSensor* sensor, SyncWorker& syncWorker) {
    bool secMenuShown = false;
    while (!g_shutdownRequested.load()) {
        if (!secMenuShown) {
            std::cout << "\n  ── MODO SECRETARIA ──\n"
                "  1. Enrolar Estudiante\n"
                "  2. Eliminar Estudiante\n"
                "  3. Sync Pendientes\n"
                "  4. Volver al menú principal\n"
                "  > Opción: ";
            std::cout.flush();
            secMenuShown = true;
        }

        std::string ch;
        if (!readLineNonBlocking(ch, 2000)) {
            if (g_shutdownRequested.load()) break;
            continue;  // No reimprimir el menú
        }
        if (ch.empty()) continue;
        secMenuShown = false;  // Se va a imprimir output, limpiar menú
        std::cout << "\r\033[K";

        auto& db = SqliteManager::getInstance();

        if (ch[0] == '1') {
            std::cout << "  Documento: ";
            std::cout.flush();
            std::string doc;
            if (!readLineNonBlocking(doc, 30000)) break;
            std::cout << "  Nombre: ";
            std::cout.flush();
            std::string nombre;
            if (!readLineNonBlocking(nombre, 30000)) break;
            std::cout << "  Tel Acudiente: ";
            std::cout.flush();
            std::string tel;
            if (!readLineNonBlocking(tel, 30000)) break;

            printEvent("ENROLL", "Iniciando enrolamiento para " + nombre + " (" + doc + ")", "cyan");
            printEvent("ENROLL", ">>> Coloque el dedo del alumno en el sensor <<<", "yellow");
            std::string err;
            if (enrollStudentOnDevice(sensor, doc, nombre, tel, err)) {
                printEvent("ENROLL", "✓ Estudiante enrolado exitosamente", "green");
            } else {
                printEvent("ENROLL", "✗ Error: " + err, "red");
            }
        } else if (ch[0] == '2') {
            std::cout << "  Documento a eliminar: ";
            std::cout.flush();
            std::string doc;
            if (!readLineNonBlocking(doc, 30000)) break;
            Estudiante est;
            if (db.getEstudianteByDocumento(doc, est)) {
                if (db.deleteEstudiante(doc)) {
                    sensor->deleteUser(est.huella_id);
                    LOG_INFO("Student deleted: {}", doc);
                    printEvent("DELETE", "✓ Estudiante eliminado: " + doc, "green");
                } else {
                    LOG_ERROR("DB delete failed for {}", doc);
                    printEvent("DELETE", "✗ Error de BD al eliminar", "red");
                }
            } else {
                printEvent("DELETE", "Estudiante no encontrado: " + doc, "red");
            }
        } else if (ch[0] == '3') {
            syncWorker.nudge();
            printEvent("SYNC", "Sincronización manual iniciada", "cyan");
        } else if (ch[0] == '4') {
            break;
        }
    }
}

// =============================================================================
// MAIN
// =============================================================================
// Punto de entrada: inicializa subsistemas, arranca workers, configura
// señales, instancia watchdog y entra al menú/bucle principal.
int main() {
    // FIX: Previene Errores de segmentación en libcurl para hilos múltiples
    curl_global_init(CURL_GLOBAL_DEFAULT);

    ConfigManager::getInstance().loadConfig();
    Logger::initialize();
    LOG_INFO("NEXO EDGE starting...");

    // FIX C2: Validar que device_id sea UUID v4 (lo que la API exige).
    // Si no lo es, el edge no puede sincronizar — bloquear con mensaje claro.
    std::string deviceId = ConfigManager::getInstance().getDeviceId();
    if (!ConfigManager::isValidUuidV4(deviceId)) {
        LOG_CRITICAL("[C2] device_id '{}' no es UUID v4 válido. La API rechazará todo sync con HTTP 400.",
                     deviceId.empty() ? "(empty)" : deviceId);
        LOG_CRITICAL("[C2] ACCION REQUERIDA: Registrar el dispositivo en la WebApp del RECTOR y");
        LOG_CRITICAL("[C2] copiar el device_id (UUID) y device_token al config.json o al provision file.");
        if (isatty(STDIN_FILENO)) {
            std::cout << "\n  ⚠️  ERROR: device_id no es UUID v4 válido.\n"
                      << "     Registre el dispositivo en la WebApp y copie el UUID + token.\n"
                      << "     Vea /boot/nexo_provision.json para provisionamiento automático.\n\n";
        }
        // No abortar: el edge puede seguir haciendo match local, pero sync fallará.
        // El operador verá el error en logs y display.
    }

    // FIX: Verificar sincronización de reloj antes de procesar eventos con timestamp
    if (!checkNtpSync() || !checkSystemClock()) {
        g_clockValid.store(false, std::memory_order_release);
        LOG_CRITICAL("System clock invalid. ENTERING LOCK STATE. Biometric reads disabled.");
    }

    struct sigaction sa{};
    sa.sa_handler = signalHandler;
    sigemptyset(&sa.sa_mask);
    sa.sa_flags = 0; 
    sigaction(SIGINT, &sa, nullptr);
    sigaction(SIGTERM, &sa, nullptr);

    std::cout << "\n"
        "===============================================\n"
        "    NEXO EDGE - NODO DE PRODUCCION LINUX\n"
        "===============================================\n\n";

    LOG_INFO("Initializing SQLite...");
    if (!SqliteManager::getInstance().initialize()) {
        LOG_CRITICAL("SQLite init failed");
        return 1;
    }

    // TZ se configura una sola vez antes de que cualquier hilo use localtime_r
    setenv("TZ", "America/Bogota", 1);
    tzset();

    LOG_INFO("Initializing Encryption...");
    Encryption::getInstance().setKeyFile(ConfigManager::getInstance().getString("aes_key_file", "nexo_edge.key"));
    if (!Encryption::getInstance().initialize()) {
        LOG_WARN("Cryptographic keys not provisioned");
        if (!runSecurityProvisioning()) {
            LOG_CRITICAL("Security provisioning failed");
            SqliteManager::getInstance().close();
            return 1;
        }
    }

    std::unique_ptr<IBiometricSensor> biometricSensor;
    std::string sensorType = ConfigManager::getInstance().getString("biometric_sensor", "dev_stub");
    if (sensorType == "zk9500") {
        biometricSensor = createZk9500BiometricSensor();
        if (biometricSensor) LOG_INFO("Using ZKTeco ZK9500 biometric sensor");
    } else if (sensorType == "uareu5300") {
        biometricSensor = createUareU5300BiometricSensor();
        LOG_INFO("Using DigitalPersona U.are.U 5300 biometric sensor");
    }
    if (!biometricSensor) {
        biometricSensor = std::make_unique<DevStubBiometricSensor>();
        LOG_WARN("Requested sensor '{}' unavailable, falling back to DevStub", sensorType);
    }
    g_activeSensor.store(biometricSensor.get(), std::memory_order_release);
    auto initResult = biometricSensor->initialize();
    if (!initResult) {
        LOG_CRITICAL("Biometric sensor init failed: {} - {}", toString(initResult.error), initResult.message);
        LOG_WARN("Falling back to DevStub sensor. Edge will continue without biometric hardware.");
        biometricSensor = std::make_unique<DevStubBiometricSensor>();
        g_activeSensor.store(biometricSensor.get(), std::memory_order_release);
        auto retryResult = biometricSensor->initialize();
        if (!retryResult) {
            LOG_CRITICAL("DevStub sensor also failed: {}. Edge cannot continue.", retryResult.message);
            SqliteManager::getInstance().close();
            return 1;
        }
    }
    // FIX C8: Instanciar hardware real (OLED + GPIO) cuando esté compilado.
    // Si el hardware físico no está disponible (ej: /dev/i2c-1 no existe),
    // el constructor del display real hace fallback silencioso y los
    // mensajes se ignoran. Siempre se compila DevStub como fallback final.
    std::unique_ptr<IDisplay> display;
    std::unique_ptr<INotification> notification;

#if defined(HAS_REAL_DISPLAY)
    display = std::make_unique<RealOledDisplay>();
    if (!display) { display = std::make_unique<DevStubDisplay>(); }
    LOG_INFO("Display: RealOledDisplay (SSD1306 I2C)");
#else
    display = std::make_unique<DevStubDisplay>();
    LOG_INFO("Display: DevStubDisplay (no HAS_REAL_DISPLAY)");
#endif

#if defined(HAS_REAL_GPIO)
    notification = std::make_unique<RealGpioManager>();
    if (!notification) { notification = std::make_unique<DevStubNotification>(); }
    LOG_INFO("Notification: RealGpioManager (libgpiod LEDs/buzzer)");
#else
    notification = std::make_unique<DevStubNotification>();
    LOG_INFO("Notification: DevStubNotification (no HAS_REAL_GPIO)");
#endif
    LOG_INFO("HAL initialized");

    // FIX: Si el reloj es inválido, mostrar error en OLED y bloquear lecturas biométricas
    if (!g_clockValid.load(std::memory_order_acquire)) {
        display->showMessage("ERROR", "HORA NO SINCRONIZADA");
    }

    SyncWorker syncWorker;
    syncWorker.start();
    LOG_INFO("Cloud sync worker started (background thread)");

    // V2: MqttCommandWorker — conexión persistente MQTT en vez de polling HTTP cada 30s
    std::unique_ptr<MqttCommandWorker> mqttWorker;
    std::string mqttHost = ConfigManager::getInstance().getString("mqtt_host", "");
    int mqttPort = ConfigManager::getInstance().getInt("mqtt_port", 1883);
    // deviceId ya declarado arriba (validación C2)
    std::string mqttUser = ConfigManager::getInstance().getString("mqtt_user", "");
    std::string mqttPass = ConfigManager::getInstance().getString("mqtt_pass", "");
    // FIX C4: Configuración TLS para MQTT
    std::string mqttCaCert = ConfigManager::getInstance().getString("mqtt_ca_cert", "");
    bool mqttUseTls = ConfigManager::getInstance().getBool("mqtt_use_tls", false);

    if (!mqttHost.empty()) {
        // FIX C4: Auto-habilitar TLS si el puerto es 8883
        if (mqttPort == 8883 && !mqttUseTls) {
            mqttUseTls = true;
            LOG_INFO("[MQTT] Port 8883 detected — auto-enabling TLS");
        }
        mqttWorker = std::make_unique<MqttCommandWorker>(mqttHost, mqttPort, deviceId,
                                                          mqttUser, mqttPass, mqttCaCert, mqttUseTls);
        if (mqttWorker->start()) {
            LOG_INFO("MqttCommandWorker started (persistent MQTT connection)");
        } else {
            LOG_WARN("MqttCommandWorker failed to start. Commands will not be received via MQTT.");
        }
    } else {
        LOG_WARN("mqtt_host not configured. Skipping MqttCommandWorker. Add mqtt_host to config.json for V2.");
    }

    // Fallback V1: CommandWorker — polling HTTP cada 30s cuando no hay MQTT
    std::unique_ptr<CommandWorker> commandWorker;
    if (mqttHost.empty()) {
        std::string apiBase = ConfigManager::getInstance().getString("api_url", "");
        std::string deviceToken = ConfigManager::getInstance().getDeviceToken();
        commandWorker = std::make_unique<CommandWorker>();
        commandWorker->start(apiBase, deviceToken, deviceId);
        LOG_INFO("[Main] CommandWorker started (HTTP polling fallback, 30s interval)");
    }

    // FIX C7: HeartbeatWorker — siempre activo, envía POST /devices/ping cada 30s
    // incluso cuando MQTT está habilitado. Esto asegura que el edge aparezca
    // online en el dashboard de health check del RECTOR.
    HeartbeatWorker heartbeatWorker;
    {
        std::string apiBase = ConfigManager::getInstance().getString("api_url", "");
        std::string deviceToken = ConfigManager::getInstance().getDeviceToken();
        heartbeatWorker.start(apiBase, deviceToken, deviceId);
        LOG_INFO("[Main] HeartbeatWorker started (POST /devices/ping every 30s)");
    }

    // FIX (SRE-2): HealthMonitor — detecta threads muertos (Sync/MQTT) que el
    // hardware watchdog no ve, y fuerza exit(1) para que systemd reinicie.
    HealthMonitor healthMonitor(syncWorker, mqttWorker.get());
    healthMonitor.start();
    LOG_INFO("[Main] HealthMonitor started");

    // Watchdog Real: pat() debe estar en el bucle principal. Si se atasca, la placa rebootea.
    HardwareWatchdog watchdog;
    if (!watchdog.isOpen()) {
        LOG_WARN("[Main] /dev/watchdog unavailable. Freeze reboot NOT protected.");
    }

    LOG_INFO("NEXO EDGE ready. Ctrl+C or SIGTERM for graceful shutdown.");

    // Imprimir menú una sola vez al inicio
    showMainMenu();

    while (!g_shutdownRequested.load(std::memory_order_acquire)) {
        if (watchdog.isOpen()) watchdog.pat();

        // V2: Safe MQTT command consumption — main thread only. Callback solo pushea a queue.
        if (mqttWorker && mqttWorker->hasPendingCommand()) {
            std::string rawCmd = mqttWorker->popCommand();
            if (!rawCmd.empty()) {
                try {
                    auto j = nlohmann::json::parse(rawCmd);
                    std::string cmd = j.value("command", "");
                    LOG_INFO("[Main] Executing MQTT command: {}", cmd);
                    if (cmd == "REBOOT") {
                        // FIX A6: REBOOT real — sync graceful + system("reboot")
                        LOG_WARN("[Main] REBOOT ordered by cloud. Flushing and rebooting...");
                        display->showMessage("REBOOT", "Orden cloud");
                        syncWorker.nudge();
                        std::this_thread::sleep_for(std::chrono::seconds(2));  // Dar tiempo al sync
                        sync();
                        system("reboot");
                    } else if (cmd == "RELOAD_CONFIG") {
                        ConfigManager::getInstance().loadConfig();
                    } else if (cmd == "FORCE_SYNC") {
                        syncWorker.nudge();
                    } else if (cmd == "UPDATE_FIRMWARE") {
                        LOG_WARN("[Main] UPDATE_FIRMWARE placeholder");
                    } else if (cmd == "ENROLL_REQUEST") {
                        // Enrolamiento remoto desde WebApp: captura huella en el lector
                        // y persiste localmente (templates nunca salen del edge).
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        std::string nombre = p.value("nombre", "");
                        std::string tel = p.value("tel", p.value("parent_tel", ""));
                        if (doc.empty() || nombre.empty()) {
                            LOG_WARN("[Main] ENROLL_REQUEST sin doc/nombre. Ignorado.");
                        } else {
                            LOG_INFO("[Main] Remote enrollment requested: doc={} ({})", doc, nombre);
                            std::cout << "\n[REMOTO] Enrolamiento solicitado para " << nombre
                                      << " (" << doc << "). Coloque el dedo en el lector...\n";
                            display->showMessage("ENROLAMIENTO", "Coloque dedo");
                            std::string err;
                            if (enrollStudentOnDevice(biometricSensor.get(), doc, nombre, tel, err)) {
                                display->showMessage("ENROLL OK", nombre.substr(0, 16));
                                std::cout << "[REMOTO] Estudiante enrolado exitosamente.\n";
                                AuditTrail::logEvent(doc, "ENROLL_OK");
                                syncWorker.nudge();
                            } else {
                                display->showMessage("ENROLL FAIL", err.substr(0, 16));
                                std::cout << "[REMOTO] Error de enrolamiento: " << err << "\n";
                            }
                        }
                    } else if (cmd == "AUTHORIZE_EXIT") {
                        // Salida autorizada por rectoría/coordinación desde WebApp.
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        if (doc.empty()) {
                            LOG_WARN("[Main] AUTHORIZE_EXIT sin doc. Ignorado.");
                        } else {
                            LOG_INFO("[Main] Exit authorized by cloud for doc={}", doc);
                            AuditTrail::logEvent(doc, "SALIDA_AUTORIZADA");
                            syncWorker.nudge();
                            display->showMessage("SALIDA", "AUTORIZADA");
                            std::cout << "\n[REMOTO] Salida autorizada registrada para doc " << doc << ".\n";
                        }
                    } else if (cmd == "DELETE_STUDENT") {
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        if (!doc.empty()) {
                            auto& db = SqliteManager::getInstance();
                            Estudiante est;
                            if (db.getEstudianteByDocumento(doc, est)) {
                                db.deleteEstudiante(doc);
                                biometricSensor->deleteUser(est.huella_id);
                                CloudManager::getInstance().deleteStudent(doc);
                                LOG_INFO("[Main] Student deleted by cloud command: {}", doc);
                                display->showMessage("ELIMINADO", doc.substr(0, 16));
                            } else {
                                LOG_WARN("[Main] DELETE_STUDENT para doc desconocido={}", doc);
                            }
                        }
                    }
                } catch (const std::exception& e) {
                    LOG_WARN("[Main] Bad MQTT JSON: {}", e.what());
                }
            }
        }

        // V2: Procesar comandos del CommandWorker (HTTP polling fallback)
        // Mismo procesamiento que el MQTT worker pero para comandos recibidos via polling.
        if (commandWorker && commandWorker->hasPendingCommand()) {
            std::string rawCmd = commandWorker->popCommand();
            if (!rawCmd.empty()) {
                try {
                    auto j = nlohmann::json::parse(rawCmd);
                    std::string cmd = j.value("command", "");
                    LOG_INFO("[Main] Executing HTTP-polling command: {}", cmd);
                    if (cmd == "ENROLL_REQUEST") {
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        std::string nombre = p.value("nombre", "");
                        std::string tel = p.value("tel", p.value("parent_tel", ""));
                        if (doc.empty() || nombre.empty()) {
                            LOG_WARN("[Main] ENROLL_REQUEST sin doc/nombre. Ignorado.");
                        } else {
                            LOG_INFO("[Main] Remote enrollment requested (HTTP): doc={} ({})", doc, nombre);
                            printEvent("ENROLL", "Solicitud recibida de la WebApp", "cyan");
                            printEvent("ENROLL", "Alumno: " + nombre + " (doc: " + doc + ")", "cyan");
                            printEvent("ENROLL", ">>> Coloque el dedo del alumno en el sensor <<<", "yellow");
                            printEvent("ENROLL", "    (4 capturas necesarias — levantar y poner 4 veces)", "");
                            display->showMessage("ENROLAMIENTO", "Coloque dedo");
                            std::string err;
                            if (enrollStudentOnDevice(biometricSensor.get(), doc, nombre, tel, err)) {
                                display->showMessage("ENROLL OK", nombre.substr(0, 16));
                                printEvent("ENROLL", "✓ Huella registrada exitosamente para " + nombre, "green");
                                printEvent("ENROLL", "  Sincronizando con la nube...", "cyan");
                                AuditTrail::logEvent(doc, "ENROLL_OK");
                                syncWorker.nudge();
                            } else {
                                display->showMessage("ENROLL FAIL", err.substr(0, 16));
                                printEvent("ENROLL", "✗ Error: " + err, "red");
                            }
                            reprintMenu();
                        }
                    } else if (cmd == "AUTHORIZE_EXIT") {
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        if (doc.empty()) {
                            LOG_WARN("[Main] AUTHORIZE_EXIT sin doc. Ignorado.");
                        } else {
                            LOG_INFO("[Main] Exit authorized by cloud (HTTP) for doc={}", doc);
                            AuditTrail::logEvent(doc, "SALIDA_AUTORIZADA");
                            syncWorker.nudge();
                            display->showMessage("SALIDA", "AUTORIZADA");
                            printEvent("SALIDA", "Salida autorizada para doc " + doc, "green");
                            reprintMenu();
                        }
                    } else if (cmd == "DELETE_STUDENT") {
                        auto p = j.value("payload", nlohmann::json::object());
                        std::string doc = p.value("doc", "");
                        if (!doc.empty()) {
                            auto& db = SqliteManager::getInstance();
                            Estudiante est;
                            if (db.getEstudianteByDocumento(doc, est)) {
                                db.deleteEstudiante(doc);
                                biometricSensor->deleteUser(est.huella_id);
                                CloudManager::getInstance().deleteStudent(doc);
                                LOG_INFO("[Main] Student deleted by cloud command (HTTP): {}", doc);
                                display->showMessage("ELIMINADO", doc.substr(0, 16));
                                printEvent("DELETE", "Estudiante eliminado: " + doc, "yellow");
                            } else {
                                LOG_WARN("[Main] DELETE_STUDENT para doc desconocido={}", doc);
                                printEvent("DELETE", "Doc no encontrado: " + doc, "red");
                            }
                            reprintMenu();
                        }
                    } else {
                        printEvent("CMD", "Comando desconocido: " + cmd, "yellow");
                        reprintMenu();
                    }
                } catch (const std::exception& e) {
                    LOG_WARN("[Main] Bad HTTP-polling JSON: {}", e.what());
                    printEvent("ERROR", "JSON parse error: " + std::string(e.what()), "red");
                    reprintMenu();
                }
            }
        }

        // FIX: En systemd (sin TTY), auto-ejecutar modo de escaneo biométrico para simulación
        // DISABLED: Transformado a Panel de Control Interactivo
        // if (!isatty(STDIN_FILENO)) {
        //     // Auto-Poblar SQLite con el estudiante para simulación
        //     auto& db = SqliteManager::getInstance();
        //     Estudiante est{"100000001", "Jhon Edison", "+573243607948", "Acudiente Prueba", 1, std::vector<uint8_t>(256, 0)};
        //     db.saveEstudiante(est);
        //
        //     // Bloquear lecturas biométricas si el reloj no está sincronizado
        //     if (!g_clockValid.load(std::memory_order_acquire)) {
        //         std::this_thread::sleep_for(std::chrono::seconds(15));
        //         continue;
        //     }
        //
        //     // Simular lectura biométrica periódica para pruebas automatizadas
        //     std::this_thread::sleep_for(std::chrono::seconds(15));
        //     std::vector<uint8_t> mockTpl(256, 0);
        //     uint32_t uid = 1; // ID de prueba
        //     float score = 95.0f;
        //     auto res = biometricSensor->searchUser(mockTpl, uid, score);
        //     if (res) {
        //         handleBiometricMatch(uid, display.get(), notification.get(), syncWorker);
        //     }
        //     continue;
        // }

        // FIX: No reimprimir el menú en cada iteración. El menú se imprime
        // una sola vez al inicio o después de procesar un evento. Aquí solo
        // esperamos input del usuario con un timeout largo (2s) para no spamear.
        std::string choice;
        if (!readLineNonBlocking(choice, 2000)) {
            // Timeout sin input — no hacer nada, el loop continúa y procesa
            // comandos del CommandWorker. El menú sigue visible en pantalla.
            if (g_shutdownRequested.load()) break;
            continue;
        }
        if (choice.empty()) {
            // Enter sin texto — mostrar status rápido
            clearMenuLine();
            std::cout << "  [STATUS] Edge operativo. Esperando comandos de la WebApp...\n";
            reprintMenu();
            continue;
        }

        clearMenuLine();
        g_menuShown = false;

        switch (choice[0]) {
            case '1': {
                // FIX: Bloquear lecturas biométricas si el reloj no está sincronizado
                if (!g_clockValid.load(std::memory_order_acquire)) {
                    LOG_WARN("Biometric reads blocked: clock not synchronized");
                    display->showMessage("ERROR", "HORA NO SINCRONIZADA");
                    printEvent("ERROR", "Reloj no sincronizado. Lecturas bloqueadas.", "red");
                    std::this_thread::sleep_for(std::chrono::seconds(3));
                    break;
                }
                LOG_INFO("Entering PERPETUAL mode (attendance)");
                printEvent("MODO", "Perpetuo activado — escaneo de asistencia", "cyan");
                printEvent("MODO", "Presione 's' + Enter para salir del modo", "");
                display->showMessage("NEXO", "Listo para scan");
                while (!g_shutdownRequested.load()) {
                    if (g_shutdownRequested.load(std::memory_order_acquire)) break;

                    std::vector<uint8_t> mockTpl(256, 0);
                    uint32_t uid = 0;
                    float score = 0.0f;
                    auto res = biometricSensor->searchUser(mockTpl, uid, score);
                    if (res) {
                        handleBiometricMatch(uid, display.get(), notification.get(), syncWorker);
                    }

                    std::this_thread::sleep_for(std::chrono::milliseconds(100));

                    std::string key;
                    if (readLineNonBlocking(key, 200) && !key.empty() && key[0] == 's') {
                        LOG_INFO("Exiting PERPETUAL mode");
                        printEvent("MODO", "Saliendo de modo perpetuo", "yellow");
                        break;
                    }
                }
                display->clear();
                break;
            }
            case '3':
                modoSecretaria(biometricSensor.get(), syncWorker);
                break;
            case '4':
                LOG_INFO("Manual sync requested");
                syncWorker.nudge();
                printEvent("SYNC", "Sincronización manual iniciada", "cyan");
                break;
            case '0':
                g_shutdownRequested.store(true);
                break;
            default:
                printEvent("ERROR", "Opción inválida: " + choice, "red");
                break;
        }
        reprintMenu();
    }

    LOG_INFO("Shutting down...");
    healthMonitor.stop();
    LOG_INFO("HealthMonitor stopped");

    // FIX C7: Detener HeartbeatWorker
    heartbeatWorker.requestStop();
    heartbeatWorker.join();
    LOG_INFO("HeartbeatWorker stopped");

    syncWorker.requestStop();
    syncWorker.join();
    LOG_INFO("Sync worker stopped");

    if (mqttWorker) {
        mqttWorker->stop();
        LOG_INFO("MqttCommandWorker stopped");
    }

    display->clear();
    SqliteManager::getInstance().close();
    LOG_INFO("Resources released. Goodbye.");
    
    Logger::shutdown();
    curl_global_cleanup();

    return 0;
}
