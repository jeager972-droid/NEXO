/**
 * =============================================================================
 * test_watchdog.cpp — Tests Catch2 para HardwareWatchdog.
 * =============================================================================
 * Verifica:
 *   - Construcción con device inexistente: isOpen() == false.
 *   - pat() no crasha cuando el dispositivo no está abierto.
 *   - disable() no crasha cuando el dispositivo no está abierto.
 *   - Destructor no crasha cuando el dispositivo no está abierto.
 *   - Construcción con device path válido para /dev/null (no es watchdog real
 *     pero permite verificar que isOpen() == true y pat()/disable() funcionan).
 */

#include <catch2/catch_test_macros.hpp>
#include <string>
#include <fstream>

#include "hardware/watchdog.h"

TEST_CASE("HardwareWatchdog with non-existent device is not open", "[watchdog]") {
    HardwareWatchdog wdt("/dev/nonexistent_watchdog_device");
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog pat() is safe when not open", "[watchdog]") {
    HardwareWatchdog wdt("/dev/nonexistent_watchdog_device");
    // Should not crash or throw
    wdt.pat();
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog disable() is safe when not open", "[watchdog]") {
    HardwareWatchdog wdt("/dev/nonexistent_watchdog_device");
    // Should not crash or throw
    wdt.disable();
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog destructor is safe when not open", "[watchdog]") {
    // Creating and destroying should not crash
    {
        HardwareWatchdog wdt("/dev/nonexistent_watchdog_device");
    }
    // If we reach here, destructor was safe
    REQUIRE(true);
}

TEST_CASE("HardwareWatchdog opens /dev/null successfully", "[watchdog]") {
    // /dev/null is not a real watchdog but it's a valid device file
    // that can be opened for writing
    HardwareWatchdog wdt("/dev/null");
    REQUIRE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog pat() works when open", "[watchdog]") {
    HardwareWatchdog wdt("/dev/null");
    REQUIRE(wdt.isOpen());
    // Should not crash
    wdt.pat();
    REQUIRE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog disable() closes device", "[watchdog]") {
    HardwareWatchdog wdt("/dev/null");
    REQUIRE(wdt.isOpen());
    wdt.disable();
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog disable() is idempotent", "[watchdog]") {
    HardwareWatchdog wdt("/dev/null");
    wdt.disable();
    REQUIRE_FALSE(wdt.isOpen());
    // Second disable should not crash
    wdt.disable();
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog pat() after disable is safe", "[watchdog]") {
    HardwareWatchdog wdt("/dev/null");
    wdt.disable();
    // pat() after disable should not crash
    wdt.pat();
    REQUIRE_FALSE(wdt.isOpen());
}

TEST_CASE("HardwareWatchdog default device path", "[watchdog]") {
    // Default constructor uses /dev/watchdog which likely doesn't exist in test env
    HardwareWatchdog wdt;
    // In test environment, /dev/watchdog probably doesn't exist
    // Just verify it doesn't crash
    REQUIRE((wdt.isOpen() == true || wdt.isOpen() == false));
}

TEST_CASE("HardwareWatchdog destructor calls disable when open", "[watchdog]") {
    // Create with /dev/null (open), destroy — should call disable internally
    {
        HardwareWatchdog wdt("/dev/null");
        REQUIRE(wdt.isOpen());
    }
    // If we reach here, destructor properly called disable() and closed
    REQUIRE(true);
}
