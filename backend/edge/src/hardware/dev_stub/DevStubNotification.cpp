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
