#pragma once
#include <string>

/**
 * =============================================================================
 * IDisplay.h — Interfaz abstracta de pantalla/visor del edge.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Contrato mínimo para mostrar mensajes de dos líneas y limpiar la pantalla.
 *   Implementado por DevStubDisplay (logs) y RealOledDisplay (SSD1306 I2C).
 */
class IDisplay {
public:
    virtual ~IDisplay() = default;
    virtual void showMessage(const std::string& line1, const std::string& line2 = "") = 0;
    virtual void clear() = 0;
};
