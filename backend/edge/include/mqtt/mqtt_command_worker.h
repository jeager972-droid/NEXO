#pragma once
#include <string>
#include <thread>
#include <atomic>
#include <queue>
#include <mutex>
#include <condition_variable>
#include <mosquitto.h>

/**
 * =============================================================================
 * mqtt_command_worker.h — Worker MQTT para recepción de comandos cloud (M2M).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Mantiene una conexión persistente a un broker MQTT usando mosquitto.
 *   Se suscribe a `nexo/devices/{deviceId}/commands`. Los callbacks de red
 *   (onMessage) solo encolan el payload; el hilo principal consume la cola
 *   de forma segura y ejecuta comandos (REBOOT, RELOAD_CONFIG, FORCE_SYNC,
 *   UPDATE_FIRMWARE). Expone lastActivity() para HealthMonitor.
 *
 * FLUJO:
 *   start() -> mosquitto_connect -> runLoop() -> mosquitto_loop()
 *        │
 *        ├── onConnect -> subscribe al tópico
 *        ├── onMessage -> pushCommand() (cola protegida)
 *        └── onDisconnect -> m_connected = false
 *
 * DEPENDENCIAS:
 *   - libmosquitto
 *   - HealthMonitor lee lastActivity() periódicamente.
 */
class MqttCommandWorker {
public:
    // caCertPath/useTls habilitan TLS (puerto 8883)
    MqttCommandWorker(const std::string& brokerHost, int brokerPort,
                      const std::string& deviceId,
                      const std::string& username, const std::string& password,
                      const std::string& caCertPath = "",
                      bool useTls = false);
    ~MqttCommandWorker();

    bool start();
    void stop();
    bool isConnected() const;

    // Timestamp de última actividad para HealthMonitor
    std::chrono::steady_clock::time_point lastActivity() const { return m_lastActivity.load(std::memory_order_acquire); }

    // Thread-safe: main.cpp llama esto para extraer comandos de forma segura
    bool hasPendingCommand() const;
    std::string popCommand();  // Bloquea hasta comando o timeout (100ms)

private:
    std::string m_brokerHost; int m_brokerPort;
    std::string m_deviceId, m_username, m_password, m_topic;
    std::string m_caCertPath;  // Path al CA cert para TLS
    bool m_useTls;             // Habilitar TLS (puerto 8883)

    struct mosquitto* m_mosq{nullptr};
    std::thread m_loopThread;
    std::atomic<bool> m_stop{false};
    std::atomic<bool> m_connected{false};

    // Última actividad observable para HealthMonitor
    std::atomic<std::chrono::steady_clock::time_point> m_lastActivity{std::chrono::steady_clock::now()};

    // Producer-Consumer queue (protegida por mutex)
    std::queue<std::string> m_cmdQueue;
    mutable std::mutex m_cmdMutex;
    std::condition_variable m_cmdCv;

    static void onConnect(struct mosquitto* mosq, void* obj, int rc);
    static void onMessage(struct mosquitto* mosq, void* obj,
                          const struct mosquitto_message* msg);
    static void onDisconnect(struct mosquitto* mosq, void* obj, int rc);

    void runLoop();
    void pushCommand(const std::string& cmd);
};
