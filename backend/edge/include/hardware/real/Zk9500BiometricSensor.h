#pragma once
#include "hal/IBiometricSensor.h"
#include <memory>

/**
 * =============================================================================
 * Zk9500BiometricSensor.h — Factory del sensor ZKTeco ZK9500.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Expone una función factory que crea una instancia de IBiometricSensor para
 *   el lector ZKTeco ZK9500. La implementación concreta reside en el .cpp.
 */
std::unique_ptr<IBiometricSensor> createZk9500BiometricSensor();
