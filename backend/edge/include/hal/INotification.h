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
};
