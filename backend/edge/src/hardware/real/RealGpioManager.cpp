/**
 * =============================================================================
 * RealGpioManager.cpp — Implementación de notificación con GPIO real (RPi 4).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa INotification usando libgpiod para controlar LEDs y buzzer en
 *   un Raspberry Pi 4. Abre gpiochip4, configura líneas 17 (verde), 27 (rojo)
 *   y 22 (buzzer) como salidas. Emite patrones de beep/LED según el estado.
 *
 * FIX C8: Refactorizado para usar header dedicado (RealGpioManager.h).
 * FIX M9: Verifica retorno de gpiod_line_request_output.
 */

#include "hardware/real/RealGpioManager.h"
#include "utils/Logger.h"
#include <gpiod.h>
#include <thread>
#include <chrono>

RealGpioManager::RealGpioManager()
    : m_chip(nullptr), m_ledGreen(nullptr), m_ledRed(nullptr), m_buzzer(nullptr) {
    m_chip = gpiod_chip_open_by_name("gpiochip4"); // RPi 4 main chip
    if (!m_chip) {
        LOG_ERROR("[GPIO] Cannot open gpiochip4. LEDs/buzzer will not work.");
        return;
    }
    m_ledGreen = gpiod_chip_get_line(m_chip, 17);
    m_ledRed = gpiod_chip_get_line(m_chip, 27);
    m_buzzer = gpiod_chip_get_line(m_chip, 22);

    // FIX M9: Verificar éxito de gpiod_line_request_output
    if (m_ledGreen && gpiod_line_request_output(m_ledGreen, "NEXO", 0) != 0) {
        LOG_ERROR("[GPIO] Failed to request LED green line 17");
        m_ledGreen = nullptr;
    }
    if (m_ledRed && gpiod_line_request_output(m_ledRed, "NEXO", 0) != 0) {
        LOG_ERROR("[GPIO] Failed to request LED red line 27");
        m_ledRed = nullptr;
    }
    if (m_buzzer && gpiod_line_request_output(m_buzzer, "NEXO", 0) != 0) {
        LOG_ERROR("[GPIO] Failed to request buzzer line 22");
        m_buzzer = nullptr;
    }
}

RealGpioManager::~RealGpioManager() {
    if (m_chip) gpiod_chip_close(m_chip);
}

void RealGpioManager::beep(int ms) {
    if (!m_buzzer) return;
    gpiod_line_set_value(m_buzzer, 1);
    std::this_thread::sleep_for(std::chrono::milliseconds(ms));
    gpiod_line_set_value(m_buzzer, 0);
}

void RealGpioManager::notifySuccess() {
    if (m_ledGreen) gpiod_line_set_value(m_ledGreen, 1);
    beep(200);
    if (m_ledGreen) gpiod_line_set_value(m_ledGreen, 0);
}

void RealGpioManager::notifyError() {
    if (m_ledRed) gpiod_line_set_value(m_ledRed, 1);
    beep(500); beep(500);
    if (m_ledRed) gpiod_line_set_value(m_ledRed, 0);
}

void RealGpioManager::notifyWarning() {
    beep(100);
}
