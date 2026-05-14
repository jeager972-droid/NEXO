#include "hardware/dev_stub/DevStubBiometricSensor.h"
#include "utils/Logger.h"

NexoResult<void> DevStubBiometricSensor::initialize() {
    LOG_INFO("[STUB] Biometric sensor initialized (dev-stub)");
    m_ready = true;
    return NexoResult<void>::success();
}

NexoResult<void> DevStubBiometricSensor::enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) {
    if (!m_ready) return NexoResult<void>::fail(NexoError::NotInitialized, "Sensor not initialized");
    LOG_INFO("[STUB] Enroll user ID={}", userId);
    templateOut.assign(256, 0xAA);
    return NexoResult<void>::success();
}

NexoResult<void> DevStubBiometricSensor::searchUser(const std::vector<uint8_t>& /*templateData*/,
                                                     uint32_t& matchedUserId, float& matchScore) {
    if (!m_ready) return NexoResult<void>::fail(NexoError::NotInitialized, "Sensor not initialized");
    LOG_DEBUG("[STUB] Search user (simulated match ID=1)");
    matchedUserId = 1;
    matchScore = 95.0f;
    return NexoResult<void>::success();
}

NexoResult<void> DevStubBiometricSensor::deleteUser(uint32_t userId) {
    if (!m_ready) return NexoResult<void>::fail(NexoError::NotInitialized, "Sensor not initialized");
    LOG_INFO("[STUB] Delete user ID={}", userId);
    return NexoResult<void>::success();
}
