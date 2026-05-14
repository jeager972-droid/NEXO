#include "hardware/dev_stub/DevStubDisplay.h"
#include "utils/Logger.h"

void DevStubDisplay::showMessage(const std::string& line1, const std::string& line2) {
    LOG_INFO("[STUB-DISPLAY] {} | {}", line1, line2);
}

void DevStubDisplay::clear() {
    LOG_DEBUG("[STUB-DISPLAY] Clear");
}
