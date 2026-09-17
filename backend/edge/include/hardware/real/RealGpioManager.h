#pragma once
#include "hal/INotification.h"

/**
 * RealGpioManager — Notificación con GPIO real (RPi 4) vía libgpiod.
 * Controla LEDs verde (GPIO17), rojo (GPIO27) y buzzer (GPIO22).
 */
class RealGpioManager : public INotification {
public:
    RealGpioManager();
    ~RealGpioManager() override;

    void notifySuccess() override;
    void notifyError() override;
    void notifyWarning() override;
    void notifyPowerState(int state) override;
    void setFan(bool on) override;

private:
    struct gpiod_chip* m_chip = nullptr;
    struct gpiod_line* m_ledGreen = nullptr;
    struct gpiod_line* m_ledRed = nullptr;
    struct gpiod_line* m_buzzer = nullptr;
    struct gpiod_line* m_fan = nullptr;

    void beep(int ms);
};
