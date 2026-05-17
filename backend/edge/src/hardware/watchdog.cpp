#include "hardware/watchdog.h"
#include "utils/Logger.h"

HardwareWatchdog::HardwareWatchdog(const std::string& device) {
    m_wdt.open(device);
    m_open = m_wdt.is_open();
    if (m_open) {
        LOG_INFO("[WATCHDOG] /dev/watchdog opened. Board will reboot if main loop freezes.");
    } else {
        LOG_WARN("[WATCHDOG] Cannot open /dev/watchdog (needs root or udev rule)");
    }
}

HardwareWatchdog::~HardwareWatchdog() {
    if (m_open) disable();
}

void HardwareWatchdog::pat() {
    if (m_open) {
        m_wdt << '\n';
        m_wdt.flush();
    }
}

void HardwareWatchdog::disable() {
    if (m_open) {
        m_wdt << 'V';
        m_wdt.flush();
        m_wdt.close();
        m_open = false;
        LOG_INFO("[WATCHDOG] Disabled (graceful shutdown)");
    }
}
