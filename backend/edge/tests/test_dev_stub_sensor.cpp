/**
 * =============================================================================
 * test_dev_stub_sensor.cpp — Tests de DevStubBiometricSensor (Catch2 v3).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica el sensor stub: inicialización, enrolamiento (template dummy
 *   256 bytes 0xAA), búsqueda (1 match cada 5), eliminación, y manejo
 *   de errores cuando no está inicializado.
 *
 * DEPENDENCIAS:
 *   - Catch2 v3
 *   - hardware/dev_stub/DevStubBiometricSensor.h
 */

#include <catch2/catch_test_macros.hpp>
#include "hardware/dev_stub/DevStubBiometricSensor.h"
#include <vector>
#include <cstdint>

TEST_CASE("DevStubBiometricSensor initialization", "[sensor_stub]") {
    DevStubBiometricSensor sensor;

    REQUIRE_FALSE(sensor.isReady());

    auto result = sensor.initialize();
    REQUIRE(result.error == NexoError::None);
    REQUIRE(sensor.isReady());
    REQUIRE(sensor.getLastError().empty());
}

TEST_CASE("DevStubBiometricSensor enroll produces 256-byte template", "[sensor_stub]") {
    DevStubBiometricSensor sensor;
    sensor.initialize();

    std::vector<uint8_t> tmpl;
    auto result = sensor.enrollUser(1, tmpl);

    REQUIRE(result.error == NexoError::None);
    REQUIRE(tmpl.size() == 256);
    for (auto byte : tmpl) {
        REQUIRE(byte == 0xAA);
    }
}

TEST_CASE("DevStubBiometricSensor operations fail before init", "[sensor_stub]") {
    DevStubBiometricSensor sensor;

    SECTION("enrollUser fails") {
        std::vector<uint8_t> tmpl;
        auto result = sensor.enrollUser(1, tmpl);
        REQUIRE(result.error == NexoError::NotInitialized);
        REQUIRE_FALSE(result.message.empty());
    }

    SECTION("searchUser fails") {
        uint32_t matchedId = 0;
        float score = 0;
        std::vector<uint8_t> tmpl(256, 0xBB);
        auto result = sensor.searchUser(tmpl, matchedId, score);
        REQUIRE(result.error == NexoError::NotInitialized);
    }

    SECTION("deleteUser fails") {
        auto result = sensor.deleteUser(1);
        REQUIRE(result.error == NexoError::NotInitialized);
    }
}

TEST_CASE("DevStubBiometricSensor deleteUser succeeds after init", "[sensor_stub]") {
    DevStubBiometricSensor sensor;
    sensor.initialize();

    auto result = sensor.deleteUser(42);
    REQUIRE(result.error == NexoError::None);
}

TEST_CASE("DevStubBiometricSensor addTemplate default is no-op success", "[sensor_stub]") {
    DevStubBiometricSensor sensor;
    sensor.initialize();

    std::vector<uint8_t> tmpl(256, 0xCC);
    auto result = sensor.addTemplate(5, tmpl);
    REQUIRE(result.error == NexoError::None);
}

TEST_CASE("DevStubBiometricSensor cancelCapture is no-op", "[sensor_stub]") {
    DevStubBiometricSensor sensor;
    sensor.initialize();

    // Should not crash
    sensor.cancelCapture();
    REQUIRE(sensor.isReady());
}
