#pragma once
#include "hal/IDisplay.h"

class DevStubDisplay : public IDisplay {
public:
    void showMessage(const std::string& line1, const std::string& line2 = "") override;
    void clear() override;
};
