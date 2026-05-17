#pragma once
#include <fstream>
#include <string>

class HardwareWatchdog {
public:
    explicit HardwareWatchdog(const std::string& device = "/dev/watchdog");
    ~HardwareWatchdog();
    bool isOpen() const { return m_open; }
    void pat();       // Escribe '\n' — debe llamarse desde el bucle principal
    void disable();   // Escribe 'V' — graceful shutdown
private:
    std::ofstream m_wdt;
    bool m_open{false};
};
