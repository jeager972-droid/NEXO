#include "hal/IBiometricSensor.h"
#include "utils/Logger.h"
#include "utils/ConfigManager.h"
#include <libzkfptype.h>
#include <libzkfp.h>
#include <vector>
#include <map>
#include <cstring>
#include <memory>
#include <thread>
#include <atomic>
#include <chrono>
#include <utility>
#include <algorithm>
#include <future>

// PILAR 3.2: Deleters RAII para handles ZKTeco
struct ZKDeviceDeleter {
    void operator()(void* h) const {
        if (h) ZKFPM_CloseDevice(h);
    }
};

struct ZKDBDeleter {
    void operator()(void* h) const {
        if (h) ZKFPM_DBFree(h);
    }
};

class Zk9500BiometricSensor : public IBiometricSensor {
private:
    std::unique_ptr<void, ZKDeviceDeleter> m_hDevice;
    std::unique_ptr<void, ZKDBDeleter> m_hDBCache;
    bool m_isReady = false;
    std::string m_lastError;
    std::map<uint32_t, uint32_t> m_fidMap; // Mapeo DB interna
    int m_matchThreshold = 45; // Umbral dinámico configurable (0-100)

public:
    ~Zk9500BiometricSensor() {
        m_hDBCache.reset();
        m_hDevice.reset();
        ZKFPM_Terminate();
    }

    NexoResult<void> initialize() override {
        if (ZKFPM_Init() != 0) return NexoResult<void>::fail(NexoError::SensorError, "ZKFPM_Init falló");
        
        if (ZKFPM_GetDeviceCount() == 0) return NexoResult<void>::fail(NexoError::SensorError, "No hay sensores conectados");
        
        void* rawDevice = ZKFPM_OpenDevice(0);
        if (!rawDevice) return NexoResult<void>::fail(NexoError::SensorError, "Fallo abriendo ZK9500");
        m_hDevice.reset(rawDevice);
        
        void* rawDB = ZKFPM_DBInit();
        if (!rawDB) return NexoResult<void>::fail(NexoError::SensorError, "Fallo inicializando DB en RAM ZK");
        m_hDBCache.reset(rawDB);
        
        m_matchThreshold = std::clamp(ConfigManager::getInstance().getMatchThreshold(), 0, 100);
        LOG_INFO("ZK9500 match threshold set to {}", m_matchThreshold);
        m_isReady = true;
        LOG_INFO("ZK9500 Inicializado correctamente");
        return NexoResult<void>::success();
    }

    NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) override {
        if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);
        
        unsigned char fpTemplate[2048];
        unsigned int cbTemplate = 2048;
        
        // Bloqueante esperando huella real (Simplificado para el ejemplo)
        int ret = ZKFPM_AcquireFingerprint(m_hDevice.get(), fpTemplate, cbTemplate, nullptr, nullptr);
        if (ret != 0) return NexoResult<void>::fail(NexoError::BadQuality, "Fallo lectura huella");
        
        ZKFPM_DBAdd(m_hDBCache.get(), userId, fpTemplate, cbTemplate);
        templateOut.assign(fpTemplate, fpTemplate + cbTemplate);
        return NexoResult<void>::success();
    }

    NexoResult<void> searchUser(const std::vector<uint8_t>& /*ignorado*/, uint32_t& matchedUserId, float& matchScore) override {
        if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);

        unsigned char fpTemplate[2048];
        unsigned int cbTemplate = 2048;
        int ret = ZKFPM_AcquireFingerprint(m_hDevice.get(), fpTemplate, cbTemplate, nullptr, nullptr);
        
        if (ret != 0) {
             return NexoResult<void>::fail(NexoError::BadQuality, "Fallo lectura huella");
        }

        unsigned int fid = 0, score = 0;
        if (ZKFPM_DBIdentify(m_hDBCache.get(), fpTemplate, cbTemplate, &fid, &score) == 0) {
            float normalizedScore = static_cast<float>(score) / 10.0f;
            normalizedScore = std::min(normalizedScore, 100.0f);
            if (static_cast<int>(normalizedScore) >= m_matchThreshold) {
                matchedUserId = fid;
                matchScore = normalizedScore;
                return NexoResult<void>::success();
            }
            LOG_WARN("Fingerprint matched but score {:.1f} below threshold {}", normalizedScore, m_matchThreshold);
            return NexoResult<void>::fail(NexoError::NoMatch, "Score insuficiente");
        }
        return NexoResult<void>::fail(NexoError::NoMatch, "Huella no registrada");
    }

    NexoResult<void> deleteUser(uint32_t userId) override {
        if (m_hDBCache) ZKFPM_DBDel(m_hDBCache.get(), userId);
        return NexoResult<void>::success();
    }

    bool isReady() const override { return m_isReady; }
    std::string getLastError() const override { return m_lastError; }
};
