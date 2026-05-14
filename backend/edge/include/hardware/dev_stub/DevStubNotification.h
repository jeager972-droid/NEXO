#pragma once
#include "hal/INotification.h"

class DevStubNotification : public INotification {
public:
    void notifySuccess() override;
    void notifyError() override;
    void notifyWarning() override;
};
