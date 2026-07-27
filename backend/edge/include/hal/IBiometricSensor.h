#pragma once

#include <cstdint>
#include <string>
#include <vector>
#include "utils/NexoResult.h"

/**
 * =============================================================================
 * IBiometricSensor.h — Interfaz abstracta del sensor biométrico.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Define el contrato que cualquier sensor biométrico (real o stub) debe
 *   cumplir: inicialización, enrolamiento, búsqueda 1:N, eliminación de usuario
 *   y consulta de estado. Permite inyectar implementaciones DevStub o ZK9500
 *   sin modificar main.cpp.
 *
 * FLUJO:
 *   initialize() -> isReady()
 *        │
 *        ├── enrollUser(userId, templateOut) -> guardar template cifrado
 *        └── searchUser(templateData, matchedUserId, matchScore) -> identificar
 *
 * IMPLEMENTACIONES:
 *   - hardware/dev_stub/DevStubBiometricSensor : simulación sin hardware.
 *   - hardware/real/Zk9500BiometricSensor      : sensor ZKTeco ZK9500.
 */
class IBiometricSensor {
public:
    virtual ~IBiometricSensor() = default;

    virtual NexoResult<void> initialize() = 0;

    virtual NexoResult<void> enrollUser(uint32_t userId, std::vector<uint8_t>& templateOut) = 0;

    // Añade un template ya generado al cache del sensor. Permite enrolamiento atómico:
    // SQLite primero, cache después. Implementación por defecto no-op para stubs.
    virtual NexoResult<void> addTemplate(uint32_t userId, const std::vector<uint8_t>& templateData) {
        (void)userId;
        (void)templateData;
        return NexoResult<void>::success();
    }

    virtual NexoResult<void> searchUser(const std::vector<uint8_t>& templateData,
                                        uint32_t& matchedUserId,
                                        float& matchScore) = 0;

    virtual NexoResult<void> deleteUser(uint32_t userId) = 0;

    // Cancela una captura bloqueante (p. ej. dpfpdd_cancel). No-op por defecto.
    virtual void cancelCapture() {}

    virtual bool isReady() const = 0;
    virtual std::string getLastError() const = 0;
};
