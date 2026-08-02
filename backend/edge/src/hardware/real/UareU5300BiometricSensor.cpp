/**
 * =============================================================================
 * UareU5300BiometricSensor.cpp — Implementación DigitalPersona U.are.U 5300.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa IBiometricSensor para el lector U.are.U 5300 usando el SDK
 *   DigitalPersona. Mantiene cache en RAM con punteros estables (std::list),
 *   soporta reconexión USB, cancelación de captura y carga de estudiantes
 *   desde SQLite. El umbral de identificación se lee de ConfigManager.
 *
 * FLUJO:
 *   initialize() -> dpfpdd_init -> query/open -> dpfj_select_engine -> load cache
 *   enrollUser() -> capturas + dpfj_create_enrollment_fmd -> template
 *   addTemplate() -> añade FMD a cache (después de persistir en SQLite)
 *   searchUser() -> captura + dpfj_create_fmd_from_fid -> dpfj_identify
 */

#include "hardware/real/UareU5300BiometricSensor.h"
#include "base_de_datos/sqlite_manager.h"
#include "utils/ConfigManager.h"
#include "utils/Logger.h"

#include <dpfpdd.h>
#include <dpfj.h>

#include <dlfcn.h>
#include <libgen.h>

#include <list>
#include <unordered_map>
#include <vector>
#include <string>
#include <cstring>
#include <chrono>
#include <thread>
#include <algorithm>
#include <cmath>
#include <iterator>
#include <sstream>
#include <iomanip>

// Undocumented SDK helper: establece el directorio base de .dat/.lic.
extern "C" int dpfpdd_set_classifier_path(const char* path);

static bool configureUareuEnvironment() {
    Dl_info info;
    if (dladdr((void*)dpfpdd_init, &info) == 0 || !info.dli_fname) {
        return false;
    }
    char pathBuf[4096];
    strncpy(pathBuf, info.dli_fname, sizeof(pathBuf) - 1);
    pathBuf[sizeof(pathBuf) - 1] = '\0';
    const char* libDir = dirname(pathBuf);

    if (libDir && *libDir) {
        dpfpdd_set_classifier_path(libDir);
    }

    const char* plugins[] = {
        "libdpfpdd5000.so",
        "libdpfpdd_4k.so",
        "libdpfpdd7k.so",
        "libdpfpdd_ptapi.so",
        "libnex_sdk.so",
        "libdpfr6.so",
        "libdpfr7.so",
        "libtfm.so",
        nullptr
    };
    for (const char** p = plugins; *p; ++p) {
        dlopen(*p, RTLD_NOW | RTLD_GLOBAL);
    }
    return true;
}

// Entrada del cache: punteros estables a FMD
struct FmdEntry {
    uint32_t huella_id = 0;
    std::vector<uint8_t> fmd;
};

class UareU5300BiometricSensor : public IBiometricSensor {
public:
    UareU5300BiometricSensor();
    ~UareU5300BiometricSensor() override;

    NexoResult<void> initialize() override;
    NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) override;
    NexoResult<void> addTemplate(uint32_t userId, const std::vector<uint8_t>& templateData) override;
    NexoResult<void> searchUser(const std::vector<uint8_t>& /*templateData*/,
                                uint32_t& matchedUserId,
                                float& matchScore) override;
    NexoResult<void> deleteUser(uint32_t userId) override;
    void cancelCapture() override;
    bool isReady() const override { return m_isReady; }
    std::string getLastError() const override { return m_lastError; }

private:
    DPFPDD_DEV m_device = nullptr;
    bool m_isReady = false;
    bool m_libInitialized = false;
    std::string m_lastError;

    std::list<FmdEntry> m_fmdCache;
    std::unordered_map<uint32_t, std::list<FmdEntry>::iterator> m_fmdIndex;

    unsigned int m_falsePositiveRate = DPFJ_PROBABILITY_ONE / 100000; // ~0.001% FAR
    int m_matchTimeoutMs = 10000;
    int m_enrollmentMaxCaptures = 4;
    int m_reconnectAttempts = 3;
    unsigned int m_dpi = 0;

    bool openDevice();
    void closeDevice();
    bool reconnect();
    bool selectEngine(DPFPDD_DEV dev);
    bool loadCacheFromDB();

    NexoResult<std::vector<uint8_t>> captureFinger();
    bool createFmdFromFid(const std::vector<uint8_t>& fid, std::vector<uint8_t>& fmd);

    void setLastError(const std::string& msg);
    static std::string dpErrorString(int err);
};

std::unique_ptr<IBiometricSensor> createUareU5300BiometricSensor() {
    return std::make_unique<UareU5300BiometricSensor>();
}

UareU5300BiometricSensor::UareU5300BiometricSensor() = default;

UareU5300BiometricSensor::~UareU5300BiometricSensor() {
    closeDevice();
    if (m_libInitialized) {
        dpfpdd_exit();
    }
}

void UareU5300BiometricSensor::setLastError(const std::string& msg) {
    m_lastError = msg;
    LOG_ERROR("UareU5300: {}", msg);
}

std::string UareU5300BiometricSensor::dpErrorString(int err) {
    unsigned int uerr = static_cast<unsigned int>(err);
    std::ostringstream oss;
    oss << "DP error 0x" << std::hex << uerr;
    return oss.str();
}

bool UareU5300BiometricSensor::openDevice() {
    closeDevice();

    unsigned int devCnt = 1;
    std::vector<DPFPDD_DEV_INFO> devInfos(devCnt);
    for (auto& info : devInfos) info.size = sizeof(DPFPDD_DEV_INFO);

    int rc = dpfpdd_query_devices(&devCnt, devInfos.data());
    if (rc == DPFPDD_E_MORE_DATA) {
        devInfos.resize(devCnt);
        for (auto& info : devInfos) info.size = sizeof(DPFPDD_DEV_INFO);
        rc = dpfpdd_query_devices(&devCnt, devInfos.data());
    }
    if (rc != DPFPDD_SUCCESS || devCnt == 0) {
        setLastError("dpfpdd_query_devices failed: " + dpErrorString(rc));
        return false;
    }

    DPFPDD_DEV dev = nullptr;
    rc = dpfpdd_open(devInfos[0].name, &dev);
    if (rc == DPFPDD_SUCCESS) {
        m_device = dev;
        m_isReady = true;

        // Read first supported resolution, like UareUCaptureOnly does.
        unsigned int capsSize = sizeof(DPFPDD_DEV_CAPS);
        std::vector<unsigned char> capsBuf(capsSize);
        while (true) {
            DPFPDD_DEV_CAPS* pCaps = reinterpret_cast<DPFPDD_DEV_CAPS*>(capsBuf.data());
            pCaps->size = capsSize;
            int capRc = dpfpdd_get_device_capabilities(dev, pCaps);
            if (capRc == DPFPDD_SUCCESS) {
                if (pCaps->resolution_cnt > 0) {
                    m_dpi = pCaps->resolutions[0];
                }
                break;
            }
            if (capRc == DPFPDD_E_MORE_DATA && pCaps->size > capsSize) {
                capsSize = pCaps->size;
                capsBuf.resize(capsSize);
                continue;
            }
            break;
        }

        LOG_INFO("U.are.U 5300 opened: {}", devInfos[0].name);
        return true;
    }

    setLastError("dpfpdd_open failed: " + dpErrorString(rc));
    return false;
}

void UareU5300BiometricSensor::closeDevice() {
    if (m_device) {
        dpfpdd_close(m_device);
        m_device = nullptr;
    }
    m_isReady = false;
}

bool UareU5300BiometricSensor::selectEngine(DPFPDD_DEV dev) {
    int rc = dpfj_select_engine(dev, DPFJ_ENGINE_DPFJ7);
    if (rc != DPFJ_SUCCESS) {
        // Fallback ante CUALQUIER error de r7 (no implementado, licencia, etc.):
        // el sample oficial usa r6 (DPFJ_ENGINE_DPFJ) como motor principal.
        LOG_WARN("FingerJet v7 unavailable ({}), falling back to v6", dpErrorString(rc));
        rc = dpfj_select_engine(dev, DPFJ_ENGINE_DPFJ);
    }
    if (rc != DPFJ_SUCCESS) {
        setLastError("Failed to select matching engine: " + dpErrorString(rc));
        return false;
    }
    return true;
}

bool UareU5300BiometricSensor::loadCacheFromDB() {
    std::vector<Estudiante> estudiantes;
    if (!SqliteManager::getInstance().getAllEstudiantesConTemplate(estudiantes)) {
        setLastError("Failed to load students from SQLite");
        return false;
    }
    for (const auto& est : estudiantes) {
        if (!est.template_huella.empty()) {
            auto res = addTemplate(est.huella_id, est.template_huella);
            if (!res) {
                LOG_WARN("Failed to add template {} from DB to cache: {}", est.huella_id, res.message);
            }
        }
    }
    LOG_INFO("UareU cache loaded: {} enrolled students", m_fmdCache.size());
    return true;
}

NexoResult<void> UareU5300BiometricSensor::initialize() {
    if (m_libInitialized) return NexoResult<void>::success();

    configureUareuEnvironment();

    int rc = dpfpdd_init();
    if (rc != DPFPDD_SUCCESS) {
        return NexoResult<void>::fail(NexoError::SensorError, "dpfpdd_init failed: " + dpErrorString(rc));
    }
    m_libInitialized = true;

    if (!openDevice()) {
        return NexoResult<void>::fail(NexoError::SensorError, m_lastError);
    }

    if (!selectEngine(m_device)) {
        return NexoResult<void>::fail(NexoError::SensorError, m_lastError);
    }

    // Configurable false-positive rate for U.are.U: denominator of PROBABILITY_ONE.
    // e.g. 100000 => PROBABILITY_ONE/100000, 10000 => PROBABILITY_ONE/10000.
    int fpDenom = ConfigManager::getInstance().getInt("uareu_false_positive_rate", 100000);
    if (fpDenom <= 0) fpDenom = 100000;
    m_falsePositiveRate = DPFJ_PROBABILITY_ONE / static_cast<unsigned int>(fpDenom);

    m_matchTimeoutMs = ConfigManager::getInstance().getInt("uareu_capture_timeout_ms", 10000);
    m_enrollmentMaxCaptures = ConfigManager::getInstance().getInt("uareu_enrollment_captures", 4);

    if (!loadCacheFromDB()) {
        return NexoResult<void>::fail(NexoError::SensorError, m_lastError);
    }

    return NexoResult<void>::success();
}

bool UareU5300BiometricSensor::reconnect() {
    LOG_WARN("UareU USB reconnect attempt");
    for (int i = 0; i < m_reconnectAttempts; ++i) {
        std::this_thread::sleep_for(std::chrono::milliseconds(200 * (i + 1)));
        if (openDevice()) {
            LOG_INFO("UareU reconnected");
            if (!selectEngine(m_device)) {
                setLastError("UareU reconnected but engine select failed: " + m_lastError);
                return false;
            }
            return true;
        }
    }
    setLastError("UareU USB reconnect failed after retries");
    return false;
}

void UareU5300BiometricSensor::cancelCapture() {
    if (m_device) {
        dpfpdd_cancel(m_device);
    }
}

NexoResult<std::vector<uint8_t>> UareU5300BiometricSensor::captureFinger() {
    if (!m_device || !m_isReady) {
        if (!reconnect()) return NexoResult<std::vector<uint8_t>>::fail(NexoError::NotInitialized, m_lastError);
    }

    // Same pattern as UareUSample/helpers.c: wait for the reader to be READY
    // before capturing (bounded to ~2s to avoid delaying the flow).
    for (int i = 0; i < 20; ++i) {
        DPFPDD_DEV_STATUS ds{};
        ds.size = sizeof(DPFPDD_DEV_STATUS);
        int statusRc = dpfpdd_get_device_status(m_device, &ds);
        if (statusRc != DPFPDD_SUCCESS || ds.status == DPFPDD_STATUS_FAILURE) {
            m_isReady = false;
            if (reconnect()) return captureFinger();
            return NexoResult<std::vector<uint8_t>>::fail(NexoError::SensorError, m_lastError);
        }
        if (ds.status == DPFPDD_STATUS_READY || ds.status == DPFPDD_STATUS_NEED_CALIBRATION) break;
        std::this_thread::sleep_for(std::chrono::milliseconds(100));
    }

    DPFPDD_CAPTURE_PARAM capParam{};
    capParam.size = sizeof(capParam);
    capParam.image_fmt = DPFPDD_IMG_FMT_ISOIEC19794;
    capParam.image_proc = DPFPDD_IMG_PROC_DEFAULT;
    capParam.image_res = m_dpi;

    std::vector<unsigned char> imageData(512 * 1024);
    unsigned int imageSize = static_cast<unsigned int>(imageData.size());
    DPFPDD_CAPTURE_RESULT capResult{};
    capResult.size = sizeof(capResult);
    capResult.info.size = sizeof(capResult.info);

    auto t0 = std::chrono::steady_clock::now();
    int rc = dpfpdd_capture(m_device, &capParam, static_cast<unsigned int>(m_matchTimeoutMs),
                            &capResult, &imageSize, imageData.data());

    if (rc == DPFPDD_E_MORE_DATA && imageSize > 0) {
        imageData.resize(imageSize);
        rc = dpfpdd_capture(m_device, &capParam, static_cast<unsigned int>(m_matchTimeoutMs),
                            &capResult, &imageSize, imageData.data());
    }
    auto t1 = std::chrono::steady_clock::now();
    auto captureMs = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();
    LOG_DEBUG("UareU capture took {} ms", captureMs);

    if (rc == DPFPDD_E_DEVICE_FAILURE || rc == DPFPDD_E_INVALID_DEVICE) {
        m_isReady = false;
        if (reconnect()) {
            return captureFinger(); // one retry after reconnect
        }
        return NexoResult<std::vector<uint8_t>>::fail(NexoError::SensorError, m_lastError);
    }

    if (rc != DPFPDD_SUCCESS) {
        if (capResult.quality == DPFPDD_QUALITY_CANCELED) {
            return NexoResult<std::vector<uint8_t>>::fail(NexoError::SensorError, "Capture canceled");
        }
        if (capResult.quality == DPFPDD_QUALITY_TIMED_OUT) {
            return NexoResult<std::vector<uint8_t>>::fail(NexoError::Timeout, "Capture timeout");
        }
        return NexoResult<std::vector<uint8_t>>::fail(NexoError::BadQuality,
            "Capture failed: " + dpErrorString(rc) + " quality=" + std::to_string(capResult.quality));
    }

    if (!capResult.success || capResult.quality != DPFPDD_QUALITY_GOOD) {
        return NexoResult<std::vector<uint8_t>>::fail(NexoError::BadQuality,
            "Image quality not good: " + std::to_string(capResult.quality));
    }

    imageData.resize(imageSize);
    return NexoResult<std::vector<uint8_t>>::success(std::move(imageData));
}

bool UareU5300BiometricSensor::createFmdFromFid(const std::vector<uint8_t>& fid,
                                                std::vector<uint8_t>& fmd) {
    fmd.assign(MAX_FMD_SIZE, 0);
    unsigned int fmdSize = MAX_FMD_SIZE;
    int rc = dpfj_create_fmd_from_fid(DPFJ_FID_ISO_19794_4_2005,
                                      fid.data(), static_cast<unsigned int>(fid.size()),
                                      DPFJ_FMD_ISO_19794_2_2005,
                                      fmd.data(), &fmdSize);
    if (rc != DPFJ_SUCCESS) {
        setLastError("FMD extraction failed: " + dpErrorString(rc));
        return false;
    }
    fmd.resize(fmdSize);
    return true;
}

NexoResult<void> UareU5300BiometricSensor::enrollUser(uint32_t /*userId*/, std::vector<uint8_t>& templateOut) {
    if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);

    int rc = dpfj_start_enrollment(DPFJ_FMD_ISO_19794_2_2005);
    if (rc != DPFJ_SUCCESS) {
        return NexoResult<void>::fail(NexoError::SensorError, "Enrollment start failed: " + dpErrorString(rc));
    }

    bool enrollmentReady = false;
    int captures = 0;
    while (captures < m_enrollmentMaxCaptures) {
        // Retry individual capture on bad quality (finger not placed properly)
        std::vector<uint8_t> fidData;
        bool capturedOk = false;
        for (int attempt = 0; attempt < 3; ++attempt) {
            auto fidRes = captureFinger();
            if (fidRes) {
                fidData = fidRes.value.value();
                capturedOk = true;
                break;
            }
            LOG_WARN("Enroll capture {} attempt {} failed: {}", captures + 1, attempt + 1, fidRes.message);
        }
        if (!capturedOk) {
            dpfj_finish_enrollment();
            return NexoResult<void>::fail(NexoError::BadQuality,
                "Image quality not good after 3 attempts. Last: capture failed");
        }

        std::vector<uint8_t> fmd;
        if (!createFmdFromFid(fidData, fmd)) {
            dpfj_finish_enrollment();
            return NexoResult<void>::fail(NexoError::SensorError, m_lastError);
        }

        rc = dpfj_add_to_enrollment(DPFJ_FMD_ISO_19794_2_2005,
                                    fmd.data(), static_cast<unsigned int>(fmd.size()), 0);
        if (rc == DPFJ_SUCCESS) {
            enrollmentReady = true;
            break;
        } else if (rc == DPFJ_E_MORE_DATA) {
            ++captures;
            continue;
        } else {
            dpfj_finish_enrollment();
            return NexoResult<void>::fail(NexoError::SensorError,
                "Add to enrollment failed: " + dpErrorString(rc));
        }
    }

    if (!enrollmentReady) {
        dpfj_finish_enrollment();
        return NexoResult<void>::fail(NexoError::SensorError, "Enrollment did not converge");
    }

    templateOut.assign(MAX_FMD_SIZE, 0);
    unsigned int enrolledSize = MAX_FMD_SIZE;
    rc = dpfj_create_enrollment_fmd(templateOut.data(), &enrolledSize);
    dpfj_finish_enrollment();

    if (rc != DPFJ_SUCCESS) {
        return NexoResult<void>::fail(NexoError::SensorError,
            "Create enrollment FMD failed: " + dpErrorString(rc));
    }
    templateOut.resize(enrolledSize);
    return NexoResult<void>::success();
}

NexoResult<void> UareU5300BiometricSensor::addTemplate(uint32_t userId,
                                                       const std::vector<uint8_t>& templateData) {
    if (templateData.empty()) {
        return NexoResult<void>::fail(NexoError::InvalidInput, "Empty template");
    }
    // Evitar duplicados
    auto it = m_fmdIndex.find(userId);
    if (it != m_fmdIndex.end()) {
        it->second->fmd = templateData;
        return NexoResult<void>::success();
    }
    FmdEntry entry;
    entry.huella_id = userId;
    entry.fmd = templateData;
    m_fmdCache.push_back(std::move(entry));
    auto iter = std::prev(m_fmdCache.end());
    m_fmdIndex[userId] = iter;
    return NexoResult<void>::success();
}

NexoResult<void> UareU5300BiometricSensor::searchUser(const std::vector<uint8_t>& /*templateData*/,
                                                      uint32_t& matchedUserId,
                                                      float& matchScore) {
    if (!m_isReady) return NexoResult<void>::fail(NexoError::NotInitialized);
    if (m_fmdCache.empty()) return NexoResult<void>::fail(NexoError::NoMatch, "Empty cache");

    // Retry capture on bad quality (finger not placed properly)
    std::vector<uint8_t> fidData;
    bool capturedOk = false;
    for (int attempt = 0; attempt < 3; ++attempt) {
        auto fidRes = captureFinger();
        if (fidRes) {
            fidData = fidRes.value.value();
            capturedOk = true;
            break;
        }
        LOG_DEBUG("Identify capture attempt {} failed: {}", attempt + 1, fidRes.message);
    }
    if (!capturedOk) {
        return NexoResult<void>::fail(NexoError::BadQuality, "Image quality not good after 3 attempts");
    }

    std::vector<uint8_t> probeFmd;
    if (!createFmdFromFid(fidData, probeFmd)) {
        return NexoResult<void>::fail(NexoError::SensorError, m_lastError);
    }

    // Construir arrays de punteros estables desde std::list (no se invalidan por inserción)
    std::vector<unsigned char*> fmds;
    std::vector<unsigned int> fmdsSize;
    fmds.reserve(m_fmdCache.size());
    fmdsSize.reserve(m_fmdCache.size());
    for (auto& entry : m_fmdCache) {
        fmds.push_back(entry.fmd.data());
        fmdsSize.push_back(static_cast<unsigned int>(entry.fmd.size()));
    }

    auto t0 = std::chrono::steady_clock::now();
    DPFJ_CANDIDATE candidate{};
    candidate.size = sizeof(DPFJ_CANDIDATE);
    unsigned int candidateCnt = 1;
    int rc = dpfj_identify(DPFJ_FMD_ISO_19794_2_2005,
                           probeFmd.data(), static_cast<unsigned int>(probeFmd.size()), 0,
                           DPFJ_FMD_ISO_19794_2_2005,
                           static_cast<unsigned int>(m_fmdCache.size()),
                           fmds.data(), fmdsSize.data(),
                           m_falsePositiveRate, &candidateCnt, &candidate);
    auto t1 = std::chrono::steady_clock::now();
    auto identifyMs = std::chrono::duration_cast<std::chrono::milliseconds>(t1 - t0).count();
    LOG_DEBUG("UareU identify over {} entries took {} ms", m_fmdCache.size(), identifyMs);

    if (rc != DPFJ_SUCCESS || candidateCnt == 0) {
        return NexoResult<void>::fail(NexoError::NoMatch, "No matching fingerprint");
    }

    // mapear índice a huella_id
    auto it = m_fmdCache.begin();
    std::advance(it, candidate.fmd_idx);

    // Verificación 1:1 con la huella candidata para obtener score preciso
    unsigned int score = 0;
    if (dpfj_compare(DPFJ_FMD_ISO_19794_2_2005, probeFmd.data(), static_cast<unsigned int>(probeFmd.size()), 0,
                     DPFJ_FMD_ISO_19794_2_2005, it->fmd.data(), static_cast<unsigned int>(it->fmd.size()), 0,
                     &score) != DPFJ_SUCCESS) {
        return NexoResult<void>::fail(NexoError::NoMatch, "1:1 comparison failed");
    }

    if (score >= m_falsePositiveRate) {
        return NexoResult<void>::fail(NexoError::NoMatch, "Score above false-positive threshold");
    }

    matchedUserId = it->huella_id;
    double dissimilarity = static_cast<double>(score) / static_cast<double>(DPFJ_PROBABILITY_ONE);
    matchScore = static_cast<float>(std::max(0.0, 100.0 * (1.0 - dissimilarity)));

    return NexoResult<void>::success();
}

NexoResult<void> UareU5300BiometricSensor::deleteUser(uint32_t userId) {
    auto it = m_fmdIndex.find(userId);
    if (it != m_fmdIndex.end()) {
        m_fmdCache.erase(it->second);
        m_fmdIndex.erase(it);
    }
    return NexoResult<void>::success();
}
