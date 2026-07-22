/**
 * =============================================================================
 * RealGpioManager.cpp — Implementación de notificación con GPIO real (RPi 4).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa INotification usando libgpiod para controlar LEDs y buzzer en
 *   un Raspberry Pi 4. Abre gpiochip4, configura líneas 17 (verde), 27 (rojo)
 *   y 22 (buzzer) como salidas. Emite patrones de beep/LED según el estado.
 */

#include "hal/INotification.h"
#include <gpiod.h>
#include <thread>
#include <chrono>

class RealGpioManager : public INotification {
private:
    struct gpiod_chip *chip;
    struct gpiod_line *ledGreen;
    struct gpiod_line *ledRed;
    struct gpiod_line *buzzer;

public:
    RealGpioManager() {
        chip = gpiod_chip_open_by_name("gpiochip4"); // RPi 4 main chip
        if (chip) {
            ledGreen = gpiod_chip_get_line(chip, 17);
            ledRed = gpiod_chip_get_line(chip, 27);
            buzzer = gpiod_chip_get_line(chip, 22);
            
            gpiod_line_request_output(ledGreen, "NEXO", 0);
            gpiod_line_request_output(ledRed, "NEXO", 0);
            gpiod_line_request_output(buzzer, "NEXO", 0);
        }
    }

    ~RealGpioManager() {
        if (chip) gpiod_chip_close(chip);
    }

    void beep(int ms) {
        if (!buzzer) return;
        gpiod_line_set_value(buzzer, 1);
        std::this_thread::sleep_for(std::chrono::milliseconds(ms));
        gpiod_line_set_value(buzzer, 0);
    }

    void notifySuccess() override {
        if (ledGreen) gpiod_line_set_value(ledGreen, 1);
        beep(200);
        if (ledGreen) gpiod_line_set_value(ledGreen, 0);
    }

    void notifyError() override {
        if (ledRed) gpiod_line_set_value(ledRed, 1);
        beep(500); beep(500);
        if (ledRed) gpiod_line_set_value(ledRed, 0);
    }

    void notifyWarning() override {
        beep(100);
    }
};
