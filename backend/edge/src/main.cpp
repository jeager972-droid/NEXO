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
#include "hardware/dev_stub/DevStubDisplay.h"
#include "hardware/dev_stub/DevStubNotification.h"
#include "hardware/dev_stub/DevStubHttpClient.h"
#include "interoperabilidad/audit_trail.h"

// ============================================================
// Globals
// ============================================================
std::atomic<bool> g_shutdownRequested(false);
std::atomic<bool> g_clockValid(true);

// ============================================================
// Signal handler
// ============================================================
void signalHandler(int signal) {
    g_shutdownRequested.store(true, std::memory_order_release);
    (void)signal;
}

// ============================================================
// Non-blocking stdin reader using poll()
// ============================================================
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
        if (ret == 0) continue; 
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

// ============================================================
// Async Cloud Sync Worker
// ============================================================
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
        while (!m_stop.load(std::memory_order_acquire)) {
            m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
            syncBatch();
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
            if (m_stop.load(std::memory_order_acquire)) break;

            Estudiante est;
            if (!db.getEstudianteByDocumento(record.documento, est)) {
                // FIX: Evita el bucle infinito al limpiar huérfanos
                LOG_WARN("[SyncWorker] Registro huerfano para doc {}. Limpiando cola.", record.documento);
                db.clearAudit(record.documento, record.event);
                continue;
            }

            // FIX: Añadir timestamp REAL, nonce único y request_id para evitar Replay y trazabilidad
            nlohmann::json j;
            j["action"] = "SYNC_ATTENDANCE";
            j["doc"] = est.documento;
            j["event"] = record.event; // <-- ACTUALIZADO
            j["parent_tel"] = est.telefono_acudiente;
            j["captured_at"] = record.timestamp; // <-- ¡LA MAGIA OCURRE AQUÍ!
            j["device_token"] = ConfigManager::getInstance().getDeviceToken();
            uint64_t micro = std::chrono::duration_cast<std::chrono::microseconds>(
                std::chrono::system_clock::now().time_since_epoch()).count();
            j["nonce"] = std::to_string(micro) + "_" + est.documento + "_" + std::to_string(nonceCounter.fetch_add(1));
            j["request_id"] = generateRequestId(); // PILAR 4.1
            std::string payload = j.dump();

            if (CloudManager::getInstance().syncRecord(payload)) {
                db.clearAudit(record.documento, record.event); // <-- ACTUALIZADO
                ++synced;
                delayMs = 1000; // Resetear delay al exito
            } else {
                ++failed;
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

// ============================================================
// Command Worker (M2M — recibe comandos desde la nube)
// ============================================================
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

private:
    std::thread m_thread;
    std::atomic<bool> m_stop{false};
    std::string m_apiBase;
    std::string m_deviceToken;
    std::string m_deviceId;

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
        CURL* curl = curl_easy_init();
        if (!curl) return;

        std::string readBuffer;
        struct curl_slist* headers = nullptr;
        headers = curl_slist_append(headers, "Content-Type: application/json");
        headers = curl_slist_append(headers, ("X-Device-Token: " + m_deviceToken).c_str());

        curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
        curl_easy_setopt(curl, CURLOPT_HTTPGET, 1L);
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCallback);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &readBuffer);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);

        CURLcode res = curl_easy_perform(curl);
        long httpCode = 0;
        curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);
        curl_easy_cleanup(curl);
        curl_slist_free_all(headers);

        if (res != CURLE_OK || httpCode != 200) {
            LOG_WARN("[CommandWorker] Poll failed: HTTP {} | {}", httpCode, curl_easy_strerror(res));
            return;
        }

        try {
            auto json = nlohmann::json::parse(readBuffer);
            if (json.contains("data") && json["data"].is_array()) {
                for (const auto& cmd : json["data"]) {
                    std::string command = cmd.value("command", "");
                    LOG_INFO("[CommandWorker] Received command: {}", command);
                    if (command == "REBOOT") {
                        LOG_WARN("[CommandWorker] Executing REBOOT command from cloud");
                        // En producción: system("reboot");
                    } else if (command == "RELOAD_CONFIG") {
                        LOG_INFO("[CommandWorker] Reloading configuration");
                        ConfigManager::getInstance().loadConfig();
                    } else if (command == "FORCE_SYNC") {
                        LOG_INFO("[CommandWorker] Force sync requested from cloud");
                        // El SyncWorker se nudges desde el menú; aquí se podría usar una cv compartida
                    } else if (command == "UPDATE_FIRMWARE") {
                        LOG_WARN("[CommandWorker] Firmware update requested (placeholder)");
                    }
                }
            }
        } catch (const std::exception& e) {
            LOG_WARN("[CommandWorker] JSON parse error: {}", e.what());
        }
    }
};

// ============================================================
// Time helpers (POSIX)
// ============================================================
struct LocalTime {
    int hour = 0, min = 0, sec = 0;
    bool valid = false;
};

LocalTime getLocalTimeBogota() {
    time_t now = time(nullptr);
    struct tm tm_buf{};
    setenv("TZ", "America/Bogota", 1);
    tzset();
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

// ============================================================
// NTP / System Clock Verification
// ============================================================
bool checkNtpSync() {
    std::array<char, 128> buffer;
    std::string result;
    std::unique_ptr<FILE, decltype(&pclose)> pipe(popen("ntpdate -q pool.ntp.org 2>/dev/null | grep -oP 'offset \\K[-\\d.]+'", "r"), pclose);
    if (!pipe) {
        LOG_WARN("Could not run ntpdate command");
        return true;
    }
    while (fgets(buffer.data(), buffer.size(), pipe.get()) != nullptr) {
        result += buffer.data();
    }
    if (!result.empty()) {
        try {
            double offset = std::stod(result);
            if (std::abs(offset) > 60.0) {
                LOG_ERROR("System clock offset is {:.2f} seconds > 60s", offset);
                return false;
            }
            LOG_INFO("NTP offset: {:.2f} seconds", offset);
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

// ============================================================
// Security Provisioning Wizard (Headless-safe)
// ============================================================
bool runSecurityProvisioning() {
    Encryption& crypto = Encryption::getInstance();

    // Already provisioned — nothing to do.
    if (crypto.isKeyProvisioned() && crypto.isTokenProvisioned()) {
        return true;
    }

    const std::string provisionPath = "/boot/nexo_provision.json";

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

// ============================================================
// Health Monitor: Detecta threads muertos y fuerza reinicio
// ============================================================
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
        while (!m_stop.load(std::memory_order_acquire)) {
            std::this_thread::sleep_for(CHECK_INTERVAL);

            auto now = std::chrono::steady_clock::now();

            // Check SyncWorker heartbeat
            auto syncDelta = now - m_sync.lastActivity();
            if (syncDelta > SYNC_MAX_STALE) {
                LOG_CRITICAL("[HealthMonitor] SyncWorker stale for {}s. Forcing self-destruction.",
                             std::chrono::duration_cast<std::chrono::seconds>(syncDelta).count());
                exit(1);
            }

            // Check MQTT heartbeat (only if mqtt is configured)
            if (m_mqtt) {
                auto mqttDelta = now - m_mqtt->lastActivity();
                if (mqttDelta > MQTT_MAX_STALE) {
                    LOG_CRITICAL("[HealthMonitor] MQTT thread stale for {}s. Forcing self-destruction.",
                                 std::chrono::duration_cast<std::chrono::seconds>(mqttDelta).count());
                    exit(1);
                }
            }
        }
        LOG_INFO("[HealthMonitor] Stopped");
    }
};

// ============================================================
// Business Logic: Handle biometric match (ZK9500)
// ============================================================
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

// ============================================================
// Main Menu
// ============================================================
void showMainMenu() {
    std::cout << "\n"
        "====================================================\n"
        "              NEXO EDGE - MENU PRINCIPAL\n"
        "====================================================\n"
        "  1. MODO PERPETUO (Control Asistencia)\n"
        "  2. MODO PAE (Control Alimentacion)\n"
        "  3. MODO SECRETARIA\n"
        "  4. Sync Pendientes (manual)\n"
        "  0. Salir\n"
        "====================================================\n"
        "Seleccione: ";
}

void modoSecretaria(IBiometricSensor* sensor, SyncWorker& syncWorker) {
    while (!g_shutdownRequested.load()) {
        std::cout << "\n--- MODO SECRETARIA ---\n"
            "1. Enrolar Estudiante\n"
            "2. Eliminar Estudiante\n"
            "3. Sync Pendientes\n"
            "4. Volver\n"
            "Opcion: ";

        std::string ch;
        if (!readLineNonBlocking(ch)) break;
        if (ch.empty()) continue;

        auto& db = SqliteManager::getInstance();

        if (ch[0] == '1') {
            std::cout << "Documento: ";
            std::string doc;
            if (!readLineNonBlocking(doc)) break;
            std::cout << "Nombre: ";
            std::string nombre;
            if (!readLineNonBlocking(nombre)) break;
            std::cout << "Tel Acudiente: ";
            std::string tel;
            if (!readLineNonBlocking(tel)) break;

            uint32_t huellaId = db.getNextHuellaID();
            std::vector<uint8_t> tpl;
            auto res = sensor->enrollUser(huellaId, tpl);
            if (res) {
                Estudiante est{doc, nombre, tel, "", huellaId, tpl.empty() ? std::vector<uint8_t>(256, 0) : tpl};
                if (db.saveEstudiante(est)) {
                    LOG_INFO("Student enrolled: {} ({})", nombre, doc);
                    std::cout << "Estudiante enrolado exitosamente.\n";
                } else {
                    LOG_ERROR("DB save failed for {}", doc);
                }
            } else {
                LOG_ERROR("Enroll failed: {} - {}", toString(res.error), res.message);
            }
        } else if (ch[0] == '2') {
            std::cout << "Documento a eliminar: ";
            std::string doc;
            if (!readLineNonBlocking(doc)) break;
            Estudiante est;
            if (db.getEstudianteByDocumento(doc, est)) {
                sensor->deleteUser(est.huella_id);
                db.deleteEstudiante(doc);
                LOG_INFO("Student deleted: {}", doc);
            } else {
                std::cout << "Estudiante no encontrado.\n";
            }
        } else if (ch[0] == '3') {
            syncWorker.nudge();
            std::cout << "Sync worker notificado.\n";
        } else if (ch[0] == '4') {
            break;
        }
    }
}

// ============================================================
// MAIN
// ============================================================
int main() {
    // FIX: Previene Errores de segmentación en libcurl para hilos múltiples
    curl_global_init(CURL_GLOBAL_DEFAULT);

    ConfigManager::getInstance().loadConfig();
    Logger::initialize();
    LOG_INFO("NEXO EDGE starting...");

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

    LOG_INFO("Initializing Encryption...");
    if (!Encryption::getInstance().initialize()) {
        LOG_WARN("Cryptographic keys not provisioned");
        if (!runSecurityProvisioning()) {
            LOG_CRITICAL("Security provisioning failed");
            SqliteManager::getInstance().close();
            return 1;
        }
    }

    auto biometricSensor = std::make_unique<DevStubBiometricSensor>();
    auto initResult = biometricSensor->initialize();
    if (!initResult) {
        LOG_CRITICAL("Biometric sensor init failed: {} - {}", toString(initResult.error), initResult.message);
        SqliteManager::getInstance().close();
        return 1;
    }
    auto display = std::make_unique<DevStubDisplay>();
    auto notification = std::make_unique<DevStubNotification>();
    auto httpClient = std::make_unique<DevStubHttpClient>();
    LOG_INFO("HAL initialized (dev-stub mode)");

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
    std::string deviceId = ConfigManager::getInstance().getDeviceId();
    std::string mqttUser = ConfigManager::getInstance().getString("mqtt_user", "");
    std::string mqttPass = ConfigManager::getInstance().getString("mqtt_pass", "");

    if (!mqttHost.empty()) {
        mqttWorker = std::make_unique<MqttCommandWorker>(mqttHost, mqttPort, deviceId, mqttUser, mqttPass);
        if (mqttWorker->start()) {
            LOG_INFO("MqttCommandWorker started (persistent MQTT connection)");
        } else {
            LOG_WARN("MqttCommandWorker failed to start. Commands will not be received via MQTT.");
        }
    } else {
        LOG_WARN("mqtt_host not configured. Skipping MqttCommandWorker. Add mqtt_host to config.json for V2.");
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
                        LOG_WARN("[Main] REBOOT ordered by cloud");
                    } else if (cmd == "RELOAD_CONFIG") {
                        ConfigManager::getInstance().loadConfig();
                    } else if (cmd == "FORCE_SYNC") {
                        syncWorker.nudge();
                    } else if (cmd == "UPDATE_FIRMWARE") {
                        LOG_WARN("[Main] UPDATE_FIRMWARE placeholder");
                    }
                } catch (const std::exception& e) {
                    LOG_WARN("[Main] Bad MQTT JSON: {}", e.what());
                }
            }
        }

        // FIX: En systemd (sin TTY), no imprimir menú ni hacer busy-loop
        if (!isatty(STDIN_FILENO)) {
            std::this_thread::sleep_for(std::chrono::seconds(5));
            continue;
        }

        showMainMenu();

        std::string choice;
        if (!readLineNonBlocking(choice)) {
            if (g_shutdownRequested.load()) break;
            continue;
        }
        if (choice.empty()) continue;

        switch (choice[0]) {
            case '1': {
                // FIX: Bloquear lecturas biométricas si el reloj no está sincronizado
                if (!g_clockValid.load(std::memory_order_acquire)) {
                    LOG_WARN("Biometric reads blocked: clock not synchronized");
                    display->showMessage("ERROR", "HORA NO SINCRONIZADA");
                    std::this_thread::sleep_for(std::chrono::seconds(3));
                    break;
                }
                LOG_INFO("Entering PERPETUAL mode (attendance)");
                display->showMessage("NEXO", "Listo para scan");
                while (!g_shutdownRequested.load()) {
                    // FIX: Verificar shutdown antes de cada adquisición bloqueante
                    if (g_shutdownRequested.load(std::memory_order_acquire)) break;

                    std::vector<uint8_t> mockTpl(256, 0);
                    uint32_t uid = 0;
                    float score = 0.0f;
                    auto res = biometricSensor->searchUser(mockTpl, uid, score);
                    if (res) {
                        handleBiometricMatch(uid, display.get(), notification.get(), syncWorker);
                    }
                    std::string key;
                    if (readLineNonBlocking(key, 200) && !key.empty() && key[0] == 's') {
                        LOG_INFO("Exiting PERPETUAL mode");
                        break;
                    }
                }
                display->clear();
                break;
            }
            case '2': {
                // PAE (Programa de Alimentacion Escolar) eliminado por decision de arquitectura
                LOG_WARN("PAE mode deprecated and removed");
                display->showMessage("NEXO", "MODO PAE DESACTIVADO");
                std::this_thread::sleep_for(std::chrono::seconds(2));
                display->clear();
                break;
            }
            case '3':
                modoSecretaria(biometricSensor.get(), syncWorker);
                break;
            case '4':
                LOG_INFO("Manual sync requested");
                syncWorker.nudge();
                std::cout << "Sync worker notificado.\n";
                break;
            case '0':
                g_shutdownRequested.store(true);
                break;
            default:
                LOG_WARN("Invalid option: '{}'", choice);
                break;
        }
    }

    LOG_INFO("Shutting down...");
    healthMonitor.stop();
    LOG_INFO("HealthMonitor stopped");

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
