/**
 * =============================================================================
 * DevStubNotification.cpp — Implementación stub de notificación.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementación de INotification que emite logs de beep en lugar de activar
 *   hardware real. Usada en desarrollo/test.
 */

#include "hardware/dev_stub/DevStubNotification.h"
#include "utils/Logger.h"

void DevStubNotification::notifySuccess() {
    LOG_DEBUG("[STUB-NOTIFY] SUCCESS beep");
}

void DevStubNotification::notifyError() {
    LOG_DEBUG("[STUB-NOTIFY] ERROR beep");
}

void DevStubNotification::notifyWarning() {
    LOG_DEBUG("[STUB-NOTIFY] WARNING beep");
}
