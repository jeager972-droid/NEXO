#pragma once
#include <string>
#include <thread>
#include <atomic>
#include <queue>
#include <mutex>
#include <condition_variable>
#include <mosquitto.h>

/**
 * MqttCommandWorker — V2: Suscripción persistente MQTT para comandos M2M.
 * Thread-safe: callback de red solo pushea a queue; main thread consume.
 */
class MqttCommandWorker {
public:
    MqttCommandWorker(const std::string& brokerHost, int brokerPort,
                      const std::string& deviceId,
                      const std::string& username, const std::string& password);
    ~MqttCommandWorker();

    bool start();
    void stop();
    bool isConnected() const;

    // Thread-safe: main.cpp llama esto para extraer comandos de forma segura
    bool hasPendingCommand() const;
    std::string popCommand();  // Bloquea hasta comando o timeout (100ms)

private:
    std::string m_brokerHost; int m_brokerPort;
    std::string m_deviceId, m_username, m_password, m_topic;

    struct mosquitto* m_mosq{nullptr};
    std::thread m_loopThread;
    std::atomic<bool> m_stop{false};
    std::atomic<bool> m_connected{false};

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
