#pragma once
#include "hal/IDisplay.h"

/**
 * RealOledDisplay — Display OLED SSD1306 128x64 vía I2C (/dev/i2c-1, 0x3C).
 * Implementación real para Raspberry Pi 4 con display físico conectado.
 */
class RealOledDisplay : public IDisplay {
public:
    RealOledDisplay();
    ~RealOledDisplay() override;

    void showMessage(const std::string& line1, const std::string& line2 = "") override;
    void clear() override;

private:
    int m_i2cFd = -1;
    bool m_initialized = false;

    bool sendCommand(uint8_t cmd);
    bool sendData(const uint8_t* data, size_t len);
    bool initDisplay();
    void setCursor(int col, int row);
};
