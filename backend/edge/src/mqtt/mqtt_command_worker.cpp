/**
 * =============================================================================
 * mqtt_command_worker.cpp — Worker MQTT para comandos M2M.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa MqttCommandWorker: inicializa libmosquitto, conecta al broker,
 *   se suscribe al tópico del dispositivo y ejecuta mosquitto_loop en un hilo.
 *   Callbacks de red (onConnect, onMessage, onDisconnect) actualizan estado y
 *   encolan comandos. popCommand() consume la cola de forma segura desde el
 *   hilo principal.
 *
 * SEGURIDAD:
 *   onMessage no accede a base de datos ni hardware; solo hace push a queue.
 */

#include "mqtt/mqtt_command_worker.h"
#include "utils/Logger.h"
#include <chrono>

MqttCommandWorker::MqttCommandWorker(const std::string& h, int p,
    const std::string& did, const std::string& u, const std::string& pw,
    const std::string& caCertPath, bool useTls)
    : m_brokerHost(h), m_brokerPort(p), m_deviceId(did), m_username(u), m_password(pw),
      m_topic("nexo/devices/" + did + "/commands"),
      m_caCertPath(caCertPath), m_useTls(useTls) {}

MqttCommandWorker::~MqttCommandWorker() { stop(); }

bool MqttCommandWorker::start() {
    mosquitto_lib_init();
    m_mosq = mosquitto_new(("nexo-edge-" + m_deviceId).c_str(), true, this);
    if (!m_mosq) { LOG_ERROR("[MQTT] mosquitto_new failed"); return false; }
    if (!m_username.empty()) mosquitto_username_pw_set(m_mosq, m_username.c_str(), m_password.c_str());

    // Configurar TLS si está habilitado (puerto 8883)
    if (m_useTls || m_brokerPort == 8883) {
        int tlsRc;
        if (!m_caCertPath.empty()) {
            // TLS con CA cert específico (mutual auth o CA propia)
            tlsRc = mosquitto_tls_set(m_mosq, m_caCertPath.c_str(), nullptr, nullptr, nullptr, nullptr);
        } else {
            // TLS usando el CA store del sistema (verificación estándar)
            tlsRc = mosquitto_tls_set(m_mosq, nullptr, "/etc/ssl/certs/", nullptr, nullptr, nullptr);
        }
        if (tlsRc != MOSQ_ERR_SUCCESS) {
            LOG_ERROR("[MQTT] tls_set failed: {}", mosquitto_strerror(tlsRc));
            mosquitto_destroy(m_mosq); m_mosq = nullptr; return false;
        }
        // Verificar peer (requiere hostname válido en el cert del broker)
        mosquitto_tls_opts_set(m_mosq, 1, nullptr, nullptr);
        LOG_INFO("[MQTT] TLS enabled (CA: {})", m_caCertPath.empty() ? "system" : m_caCertPath);
    } else {
        LOG_WARN("[MQTT] TLS disabled — conexión en texto plano. NO usar en producción.");
    }

    mosquitto_connect_callback_set(m_mosq, onConnect);
    mosquitto_message_callback_set(m_mosq, onMessage);
    mosquitto_disconnect_callback_set(m_mosq, onDisconnect);

    int rc = mosquitto_connect(m_mosq, m_brokerHost.c_str(), m_brokerPort, 60);
    if (rc != MOSQ_ERR_SUCCESS) {
        LOG_ERROR("[MQTT] connect failed: {}", mosquitto_strerror(rc));
        mosquitto_destroy(m_mosq); m_mosq = nullptr; return false;
    }
    m_loopThread = std::thread([this] { runLoop(); });
    LOG_INFO("[MQTT] Connecting to {}:{} | TLS: {} | topic: {}",
             m_brokerHost, m_brokerPort,
             (m_useTls || m_brokerPort == 8883) ? "ON" : "OFF",
             m_topic);
    return true;
}

void MqttCommandWorker::stop() {
    m_stop.store(true, std::memory_order_release);
    if (m_mosq) {
        mosquitto_disconnect(m_mosq);
        mosquitto_loop_stop(m_mosq, false);
        mosquitto_destroy(m_mosq); m_mosq = nullptr;
    }
    if (m_loopThread.joinable()) m_loopThread.join();
    mosquitto_lib_cleanup();
    LOG_INFO("[MQTT] Stopped");
}

bool MqttCommandWorker::isConnected() const { return m_connected.load(std::memory_order_acquire); }

bool MqttCommandWorker::hasPendingCommand() const {
    std::lock_guard<std::mutex> lk(m_cmdMutex);
    return !m_cmdQueue.empty();
}

std::string MqttCommandWorker::popCommand() {
    std::unique_lock<std::mutex> lk(m_cmdMutex);
    m_cmdCv.wait_for(lk, std::chrono::milliseconds(100),
        [this] { return !m_cmdQueue.empty() || m_stop.load(std::memory_order_acquire); });
    if (m_cmdQueue.empty()) return "";
    std::string cmd = std::move(m_cmdQueue.front());
    m_cmdQueue.pop();
    return cmd;
}

void MqttCommandWorker::pushCommand(const std::string& cmd) {
    std::lock_guard<std::mutex> lk(m_cmdMutex);
    m_cmdQueue.push(cmd);
    m_cmdCv.notify_one();
}

void MqttCommandWorker::runLoop() {
    while (!m_stop.load(std::memory_order_acquire) && m_mosq) {
        m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
        int rc = mosquitto_loop(m_mosq, 1000, 1);
        if (rc != MOSQ_ERR_SUCCESS && rc != MOSQ_ERR_NO_CONN) {
            LOG_WARN("[MQTT] loop error: {}. Reconnect in 5s", mosquitto_strerror(rc));
            std::this_thread::sleep_for(std::chrono::seconds(5));
            mosquitto_reconnect(m_mosq);
        }
    }
}

void MqttCommandWorker::onConnect(struct mosquitto* mosq, void* obj, int rc) {
    auto* self = static_cast<MqttCommandWorker*>(obj);
    self->m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
    if (rc == 0) {
        self->m_connected.store(true, std::memory_order_release);
        LOG_INFO("[MQTT] Connected. Subscribing to {}", self->m_topic);
        mosquitto_subscribe(mosq, nullptr, self->m_topic.c_str(), 1);
    } else {
        LOG_ERROR("[MQTT] Conn failed: {}", mosquitto_connack_string(rc));
    }
}

// CRITICAL: Runs in mosquitto's internal network thread.
// ONLY push to queue. NEVER touch DB, OLED, ConfigManager here.
void MqttCommandWorker::onMessage(struct mosquitto*, void* obj, const struct mosquitto_message* msg) {
    auto* self = static_cast<MqttCommandWorker*>(obj);
    self->m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
    if (!msg->payload || msg->payloadlen <= 0) return;
    std::string payload(static_cast<char*>(msg->payload), static_cast<size_t>(msg->payloadlen));
    LOG_INFO("[MQTT] RX {} bytes on {}", msg->payloadlen, msg->topic);
    self->pushCommand(payload);
}

void MqttCommandWorker::onDisconnect(struct mosquitto*, void* obj, int) {
    auto* self = static_cast<MqttCommandWorker*>(obj);
    self->m_lastActivity.store(std::chrono::steady_clock::now(), std::memory_order_release);
    self->m_connected.store(false, std::memory_order_release);
    LOG_WARN("[MQTT] Disconnected");
}
