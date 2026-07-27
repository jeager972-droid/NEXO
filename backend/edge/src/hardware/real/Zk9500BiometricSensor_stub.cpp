/**
 * =============================================================================
 * Zk9500BiometricSensor_stub.cpp — Stub cuando libzkfp no está disponible.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Provee la función factory createZk9500BiometricSensor() para que main.cpp
 *   linkee aunque el SDK ZKTeco no esté instalado. Devuelve nullptr; main
 *   debe caer a DevStub si el sensor real no pudo crearse.
 */
#include "hardware/real/Zk9500BiometricSensor.h"
#include <memory>

std::unique_ptr<IBiometricSensor> createZk9500BiometricSensor() {
    return nullptr;
}
