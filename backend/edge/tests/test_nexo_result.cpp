/**
 * =============================================================================
 * test_nexo_result.cpp — Tests de NexoResult<T> y NexoResult<void> (Catch2 v3).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica la monada NexoResult: success/fail, operador bool,
 *   acceso al valor opcional, toString de NexoError, y casos edge.
 *
 * DEPENDENCIAS:
 *   - Catch2 v3
 */

#include <catch2/catch_test_macros.hpp>
#include "utils/NexoResult.h"

TEST_CASE("NexoResult<void> success", "[nexo_result]") {
    auto result = NexoResult<void>::success();

    REQUIRE(result.error == NexoError::None);
    REQUIRE(result.message.empty());
    REQUIRE(static_cast<bool>(result) == true);
}

TEST_CASE("NexoResult<void> fail with error and message", "[nexo_result]") {
    auto result = NexoResult<void>::fail(NexoError::SensorError, "Sensor not found");

    REQUIRE(result.error == NexoError::SensorError);
    REQUIRE(result.message == "Sensor not found");
    REQUIRE(static_cast<bool>(result) == false);
}

TEST_CASE("NexoResult<void> fail with default message", "[nexo_result]") {
    auto result = NexoResult<void>::fail(NexoError::Timeout);

    REQUIRE(result.error == NexoError::Timeout);
    REQUIRE(result.message.empty());
    REQUIRE(static_cast<bool>(result) == false);
}

TEST_CASE("NexoResult<T> success with value", "[nexo_result]") {
    auto result = NexoResult<int>::success(42);

    REQUIRE(result.error == NexoError::None);
    REQUIRE(result.value.has_value());
    REQUIRE(result.value.value() == 42);
    REQUIRE(static_cast<bool>(result) == true);
}

TEST_CASE("NexoResult<T> fail has no value", "[nexo_result]") {
    auto result = NexoResult<std::string>::fail(NexoError::DatabaseError, "DB locked");

    REQUIRE(result.error == NexoError::DatabaseError);
    REQUIRE(result.message == "DB locked");
    REQUIRE_FALSE(result.value.has_value());
    REQUIRE(static_cast<bool>(result) == false);
}

TEST_CASE("NexoResult<T> with complex type", "[nexo_result]") {
    auto result = NexoResult<std::vector<uint8_t>>::success({0xAA, 0xBB, 0xCC});

    REQUIRE(result.error == NexoError::None);
    REQUIRE(result.value.has_value());
    auto& val = result.value.value();
    REQUIRE(val.size() == 3);
    REQUIRE(val[0] == 0xAA);
    REQUIRE(val[1] == 0xBB);
    REQUIRE(val[2] == 0xCC);
}

TEST_CASE("toString covers all NexoError values", "[nexo_result]") {
    REQUIRE(toString(NexoError::None) == "None");
    REQUIRE(toString(NexoError::NotInitialized) == "NotInitialized");
    REQUIRE(toString(NexoError::SensorError) == "SensorError");
    REQUIRE(toString(NexoError::BadQuality) == "BadQuality");
    REQUIRE(toString(NexoError::NoMatch) == "NoMatch");
    REQUIRE(toString(NexoError::Timeout) == "Timeout");
    REQUIRE(toString(NexoError::DatabaseError) == "DatabaseError");
    REQUIRE(toString(NexoError::NetworkError) == "NetworkError");
    REQUIRE(toString(NexoError::CryptoError) == "CryptoError");
    REQUIRE(toString(NexoError::InvalidInput) == "InvalidInput");
    REQUIRE(toString(NexoError::PermissionDenied) == "PermissionDenied");
    REQUIRE(toString(NexoError::Unknown) == "Unknown");
}
