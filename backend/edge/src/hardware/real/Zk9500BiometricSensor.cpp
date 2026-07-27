/**
 * =============================================================================
 * Zk9500BiometricSensor.cpp — Implementación del sensor ZKTeco ZK9500.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa IBiometricSensor para el lector de huellas ZKTeco ZK9500. Usa
 *   libzkfp para inicializar el dispositivo, mantener una base de datos en RAM
 *   (cache), enrolar/identificar/eliminar usuarios. El umbral de coincidencia
 *   se lee de ConfigManager::getMatchThreshold().
 *
 * FLUJO:
 *   initialize() -> ZKFPM_Init -> ZKFPM_OpenDevice -> ZKFPM_DBInit
 *   enrollUser(userId, templateOut) -> ZKFPM_AcquireFingerprint -> ZKFPM_DBAdd
 *   searchUser(...) -> ZKFPM_AcquireFingerprint -> ZKFPM_DBIdentify
 *
 * DEPENDENCIAS:
 *   - libzkfp, libzkfptype
 *   - utils/ConfigManager.h para threshold.
 */

#include "hal/IBiometricSensor.h"
#include "utils/Logger.h"
#include "utils/ConfigManager.h"
#include "base_de_datos/sqlite_manager.h"
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
    bool m_libInitialized = false;
    std::string m_lastError;
    std::map<uint32_t, uint32_t> m_fidMap; // Mapeo DB interna
    int m_matchThreshold = 45; // Umbral dinámico configurable (0-100)
    int m_scoreDivisor = 1;    // Divisor configurable del score raw de ZKFPM_DBIdentify

public:
    ~Zk9500BiometricSensor() {
        m_hDBCache.reset();
        m_hDevice.reset();
        if (m_libInitialized) ZKFPM_Terminate();
    }

    NexoResult<void> initialize() override {
        if (ZKFPM_Init() != 0) return NexoResult<void>::fail(NexoError::SensorError, "ZKFPM_Init falló");
        m_libInitialized = true;

        if (ZKFPM_GetDeviceCount() == 0) return NexoResult<void>::fail(NexoError::SensorError, "No hay sensores conectados");

        void* rawDevice = ZKFPM_OpenDevice(0);
        if (!rawDevice) return NexoResult<void>::fail(NexoError::SensorError, "Fallo abriendo ZK9500");
        m_hDevice.reset(rawDevice);

        void* rawDB = ZKFPM_DBInit();
        if (!rawDB) return NexoResult<void>::fail(NexoError::SensorError, "Fallo inicializando DB en RAM ZK");
        m_hDBCache.reset(rawDB);

        m_matchThreshold = std::clamp(ConfigManager::getInstance().getMatchThreshold(), 0, 100);
        m_scoreDivisor = std::max(1, ConfigManager::getInstance().getInt("zk_score_divisor", 1));
        LOG_INFO("ZK9500 match threshold={} score_divisor={}", m_matchThreshold, m_scoreDivisor);

        // Cargar estudiantes persistidos en SQLite al cache del sensor
        std::vector<Estudiante> estudiantes;
        if (SqliteManager::getInstance().getAllEstudiantesConTemplate(estudiantes)) {
            for (const auto& est : estudiantes) {
                if (!est.template_huella.empty()) {
                    auto res = addTemplate(est.huella_id, est.template_huella);
                    if (!res) {
                        LOG_WARN("ZK failed to load template {} from DB: {}", est.huella_id, res.message);
                    }
                }
            }
            LOG_INFO("ZK cache loaded: {} students", estudiantes.size());
        }

        m_isReady = true;
        LOG_INFO("ZK9500 Inicializado correctamente");
        return NexoResult<void>::success();
    }

    NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) override {
        (void)userId;
        if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);

        unsigned char fpTemplate[2048];
        std::memset(fpTemplate, 0, sizeof(fpTemplate));
        unsigned int cbTemplate = 2048;

        int ret = ZKFPM_AcquireFingerprint(m_hDevice.get(), fpTemplate, cbTemplate, nullptr, nullptr);
        if (ret != 0) return NexoResult<void>::fail(NexoError::BadQuality, "Fallo lectura huella");

        templateOut.assign(fpTemplate, fpTemplate + cbTemplate);
        return NexoResult<void>::success();
    }

    NexoResult<void> addTemplate(uint32_t userId, const std::vector<uint8_t>& templateData) override {
        if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);
        if (templateData.empty()) return NexoResult<void>::fail(NexoError::InvalidParameter, "Empty template");

        unsigned int cbTemplate = static_cast<unsigned int>(std::min(templateData.size(), static_cast<size_t>(2048)));
        ZKFPM_DBDel(m_hDBCache.get(), userId); // reemplazar si existe
        int ret = ZKFPM_DBAdd(m_hDBCache.get(), userId, templateData.data(), cbTemplate);
        if (ret != 0) return NexoResult<void>::fail(NexoError::SensorError, "ZKFPM_DBAdd falló");
        m_fidMap[userId] = userId;
        return NexoResult<void>::success();
    }

    NexoResult<void> searchUser(const std::vector<uint8_t>& /*ignorado*/, uint32_t& matchedUserId, float& matchScore) override {
        if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);

        unsigned char fpTemplate[2048];
        std::memset(fpTemplate, 0, sizeof(fpTemplate));
        unsigned int cbTemplate = 2048;
        int ret = ZKFPM_AcquireFingerprint(m_hDevice.get(), fpTemplate, cbTemplate, nullptr, nullptr);

        if (ret != 0) return NexoResult<void>::fail(NexoError::BadQuality, "Fallo lectura huella");

        unsigned int fid = 0, score = 0;
        if (ZKFPM_DBIdentify(m_hDBCache.get(), fpTemplate, cbTemplate, &fid, &score) == 0) {
            float normalizedScore = static_cast<float>(score) / static_cast<float>(m_scoreDivisor);
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
        m_fidMap.erase(userId);
        return NexoResult<void>::success();
    }

    void cancelCapture() override {}

    bool isReady() const override { return m_isReady; }
    std::string getLastError() const override { return m_lastError; }
};

std::unique_ptr<IBiometricSensor> createZk9500BiometricSensor() {
    return std::make_unique<Zk9500BiometricSensor>();
}
