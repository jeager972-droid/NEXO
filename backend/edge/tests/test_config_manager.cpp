/**
 * =============================================================================
 * test_config_manager.cpp — Tests de ConfigManager (Catch2 v3).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica carga de config.json, defaults, acceso tipado
 *   (getString, getInt, getBool), convenience accessors y
 *   comportamiento con archivo inexistente.
 *
 * DEPENDENCIAS:
 *   - Catch2 v3
 *   - nlohmann/json
 *   - filesystem (C++17/20)
 */

#include <catch2/catch_test_macros.hpp>
#include <filesystem>
#include <fstream>
#include <string>

// Include the ConfigManager directly
#include "utils/ConfigManager.h"

static const char* TEST_CONFIG_PATH = "/tmp/nexo_test_config.json";

void writeTestConfig(const std::string& content) {
    std::ofstream ofs(TEST_CONFIG_PATH);
    ofs << content;
    ofs.close();
}

void cleanupTestConfig() {
    std::filesystem::remove(TEST_CONFIG_PATH);
}

TEST_CASE("ConfigManager loads valid JSON config", "[config]") {
    cleanupTestConfig();
    writeTestConfig(R"({
        "api_url": "https://api.nexo.edu",
        "db_path": "/tmp/nexo_edge.db",
        "log_path": "/tmp/nexo-edge.log",
        "log_level": "debug",
        "device_id": "NEXO-EDGE-TEST-001",
        "device_token": "test-token-12345",
        "sensor_match_threshold": 50,
        "mqtt_host": "mqtt.nexo.edu",
        "mqtt_port": 1883
    })");

    auto& cfg = ConfigManager::getInstance();
    REQUIRE(cfg.loadConfig(TEST_CONFIG_PATH));

    SECTION("getString returns correct values") {
        REQUIRE(cfg.getString("api_url") == "https://api.nexo.edu");
        REQUIRE(cfg.getString("db_path") == "/tmp/nexo_edge.db");
        REQUIRE(cfg.getString("device_id") == "NEXO-EDGE-TEST-001");
        REQUIRE(cfg.getString("device_token") == "test-token-12345");
    }

    SECTION("getInt returns correct values") {
        REQUIRE(cfg.getInt("sensor_match_threshold") == 50);
        REQUIRE(cfg.getInt("mqtt_port") == 1883);
    }

    SECTION("Convenience accessors") {
        REQUIRE(cfg.getApiUrl() == "https://api.nexo.edu");
        REQUIRE(cfg.getDbPath() == "/tmp/nexo_edge.db");
        REQUIRE(cfg.getLogPath() == "/tmp/nexo-edge.log");
        REQUIRE(cfg.getLogLevel() == "debug");
        REQUIRE(cfg.getDeviceId() == "NEXO-EDGE-TEST-001");
        REQUIRE(cfg.getDeviceToken() == "test-token-12345");
        REQUIRE(cfg.getMatchThreshold() == 50);
    }

    cleanupTestConfig();
}

TEST_CASE("ConfigManager returns defaults for missing keys", "[config]") {
    cleanupTestConfig();
    writeTestConfig(R"({"api_url": "https://test.com"})");

    auto& cfg = ConfigManager::getInstance();
    REQUIRE(cfg.loadConfig(TEST_CONFIG_PATH));

    SECTION("Missing keys return defaults") {
        REQUIRE(cfg.getString("nonexistent", "fallback") == "fallback");
        REQUIRE(cfg.getInt("nonexistent", 42) == 42);
        REQUIRE(cfg.getBool("nonexistent", true) == true);
    }

    SECTION("Convenience accessors return documented defaults") {
        REQUIRE(cfg.getDbPath() == "nexo_edge.db");
        REQUIRE(cfg.getLogPath() == "nexo-edge.log");
        REQUIRE(cfg.getLogLevel() == "info");
        // device_id default es "" (empty) para forzar configuración UUID v4 explícita
        REQUIRE(cfg.getDeviceId() == "");
        REQUIRE(cfg.getMatchThreshold() == 45);
    }

    cleanupTestConfig();
}

TEST_CASE("ConfigManager handles missing config file gracefully", "[config]") {
    cleanupTestConfig();

    auto& cfg = ConfigManager::getInstance();
    bool result = cfg.loadConfig("/tmp/nonexistent_config_12345.json");

    REQUIRE_FALSE(result);

    // After failed load, defaults should still work
    REQUIRE(cfg.getString("anything", "default") == "default");

    cleanupTestConfig();
}

TEST_CASE("ConfigManager getBool works correctly", "[config]") {
    cleanupTestConfig();
    writeTestConfig(R"({
        "enabled": true,
        "disabled": false
    })");

    auto& cfg = ConfigManager::getInstance();
    REQUIRE(cfg.loadConfig(TEST_CONFIG_PATH));

    REQUIRE(cfg.getBool("enabled") == true);
    REQUIRE(cfg.getBool("disabled") == false);
    REQUIRE(cfg.getBool("missing", true) == true);

    cleanupTestConfig();
}
