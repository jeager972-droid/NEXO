#pragma once
#include "hal/INotification.h"

/**
 * =============================================================================
 * DevStubNotification.h — Implementación stub de notificación.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementación de INotification que emite logs de beep en lugar de activar
 *   GPIO, buzzer o LEDs reales. Usada en modo desarrollo.
 */
class DevStubNotification : public INotification {
public:
    void notifySuccess() override;
    void notifyError() override;
    void notifyWarning() override;
};
