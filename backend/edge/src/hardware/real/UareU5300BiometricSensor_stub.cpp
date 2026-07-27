/**
 * =============================================================================
 * UareU5300BiometricSensor_stub.cpp — Stub cuando el SDK U.are.U no está disponible.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Provee la función factory createUareU5300BiometricSensor() para que main.cpp
 *   linke aunque el SDK DigitalPersona no esté instalado. Devuelve nullptr; main
 *   debe caer a DevStub si el sensor real no pudo crearse.
 */
#include "hardware/real/UareU5300BiometricSensor.h"
#include <memory>

std::unique_ptr<IBiometricSensor> createUareU5300BiometricSensor() {
    return nullptr;
}
