#pragma once
#include "hal/IBiometricSensor.h"
#include <memory>
#include <string>

/**
 * =============================================================================
 * UareU5300BiometricSensor.h — Factory del sensor DigitalPersona U.are.U 5300.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Expone una función factory que crea una instancia de IBiometricSensor para
 *   el lector DigitalPersona U.are.U 5300. La implementación concreta respeta
 *   el contrato: captura FID, extrae FMD, mantiene cache en RAM para identify,
 *   soporta reconexión USB y cancelación de captura.
 */
std::unique_ptr<IBiometricSensor> createUareU5300BiometricSensor();
