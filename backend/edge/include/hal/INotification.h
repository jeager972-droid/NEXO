#pragma once

/**
 * =============================================================================
 * INotification.h — Interfaz abstracta de notificación sonora/luminosa.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Contrato para emitir señales de éxito, error o advertencia. Permite
 *   implementaciones stub o reales con GPIO/buzzer/LEDs (RealGpioManager).
 */
class INotification {
public:
    virtual ~INotification() = default;
    virtual void notifySuccess() = 0;
    virtual void notifyError() = 0;
    virtual void notifyWarning() = 0;

    // V-515: patrón luminoso/sonoro para el estado energético del nodo.
    // state: 0=MAINS, 1=BATTERY, 2=LOW_BATTERY, 3=CRITICAL (orden de PowerState).
    virtual void notifyPowerState(int /*state*/) {}

    // V-310: control de ventilación activa (GPIO fan). No-op en stubs.
    virtual void setFan(bool /*on*/) {}
};
