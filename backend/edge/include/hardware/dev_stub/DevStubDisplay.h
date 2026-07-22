#pragma once
#include "hal/IDisplay.h"

/**
 * =============================================================================
 * DevStubDisplay.h — Implementación stub de pantalla.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementación de IDisplay que redirige mensajes al logger en lugar de
 *   actualizar hardware real. Útil para pruebas en máquinas sin OLED.
 */
class DevStubDisplay : public IDisplay {
public:
    void showMessage(const std::string& line1, const std::string& line2 = "") override;
    void clear() override;
};
