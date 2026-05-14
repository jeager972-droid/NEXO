#pragma once

#include <cstdint>
#include <string>
#include <vector>
#include "utils/NexoResult.h"

class IBiometricSensor {
public:
    virtual ~IBiometricSensor() = default;

    virtual NexoResult<void> initialize() = 0;

    virtual NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) = 0;

    virtual NexoResult<void> searchUser(const std::vector<uint8_t>& templateData,
                                        uint32_t& matchedUserId,
                                        float& matchScore) = 0;

    virtual NexoResult<void> deleteUser(uint32_t userId) = 0;

    virtual bool isReady() const = 0;
    virtual std::string getLastError() const = 0;
};
