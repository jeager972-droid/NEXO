#pragma once
#include "hal/IBiometricSensor.h"
#include "utils/NexoResult.h"
#include <string>

class DevStubBiometricSensor : public IBiometricSensor {
public:
    NexoResult<void> initialize() override;
    NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) override;
    NexoResult<void> searchUser(const std::vector<uint8_t>& templateData,
                                uint32_t& matchedUserId, float& matchScore) override;
    NexoResult<void> deleteUser(uint32_t userId) override;
    bool isReady() const override { return m_ready; }
    std::string getLastError() const override { return m_lastError; }

private:
    bool m_ready = false;
    std::string m_lastError;
};
