#pragma once
#include <fstream>
#include <string>

/**
 * =============================================================================
 * watchdog.h — Wrapper del watchdog de hardware Linux (/dev/watchdog).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Abre /dev/watchdog y permite "patear" (pat) el perro guardián desde el
 *   bucle principal. Si el proceso se bloquea, el kernel reinicia la placa.
 *   disable() escribe 'V' para apagar el watchdog en shutdown graceful.
 *
 * USO:
 *   HardwareWatchdog wdt;
 *   while (running) { wdt.pat(); }
 *   // al salir: wdt.disable() o destruir.
 */
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
