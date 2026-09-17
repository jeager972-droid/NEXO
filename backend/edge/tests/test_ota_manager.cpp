/**
 * =============================================================================
 * test_ota_manager.cpp — Validación de la máquina de estados OTA M2M.
 * =============================================================================
 * Verifica transiciones persistentes y resistencia a apagones:
 *   - boot con estado 'applying'   → 'pending_confirm'
 *   - boot con 'pending_confirm'   → tras el periodo de confirmación, idle
 *   - boot con 'downloading'/'staged' → se mantiene (reanudable en tick)
 *   - estado idle → no en progreso
 * =============================================================================
 */

#include <catch2/catch_test_macros.hpp>
#include "interoperabilidad/ota_manager.h"
#include "base_de_datos/sqlite_manager.h"
#include "utils/ConfigManager.h"

#include <cstdio>
#include <filesystem>
#include <unistd.h>
#include <ctime>


namespace {
struct OtaFixture {
    OtaFixture() {
        std::string tmp = "/tmp/nexo_ota_test_" + std::to_string(getpid()) + ".db";
        ConfigManager::getInstance().setValue("db_path", tmp);
        SqliteManager::getInstance().initialize(tmp);
    }
    ~OtaFixture() {
        SqliteManager::getInstance().close();
    }
    void set(const std::string& k, const std::string& v) {
        SqliteManager::getInstance().setConfig("ota_" + k, v);
    }
    std::string get(const std::string& k) {
        return SqliteManager::getInstance().getConfig("ota_" + k, "");
    }
};
}

TEST_CASE_METHOD(OtaFixture, "OTA: estado idle no está en progreso", "[ota]") {
    set("state", "idle");
    REQUIRE(OtaManager::getInstance().inProgress() == false);
}

TEST_CASE_METHOD(OtaFixture, "OTA: boot con applying → pending_confirm", "[ota]") {
    set("state", "applying");
    set("update_id", "11111111-1111-4111-8111-111111111111");
    set("version", "2.0.0");
    OtaManager::getInstance().onBoot();
    REQUIRE(get("state") == "pending_confirm");
    REQUIRE(OtaManager::getInstance().inProgress());
}

TEST_CASE_METHOD(OtaFixture, "OTA: boot con downloading/staged → se mantiene (reanudable)", "[ota]") {
    set("state", "downloading");
    OtaManager::getInstance().onBoot();
    REQUIRE(get("state") == "downloading"); // no pierde la descarga al reiniciar
    set("state", "staged");
    OtaManager::getInstance().onBoot();
    REQUIRE(get("state") == "staged");
}

TEST_CASE_METHOD(OtaFixture, "OTA: pending_confirm antiguo → confirma y limpia estado", "[ota]") {
    set("state", "pending_confirm");
    set("update_id", "11111111-1111-4111-8111-111111111111");
    set("version", "2.0.0");
    set("apply_start", std::to_string(time(nullptr) - 300)); // >120s
    OtaManager::getInstance().onBoot();
    REQUIRE(get("state") == "");
}

TEST_CASE_METHOD(OtaFixture, "OTA: pending_confirm reciente → sigue esperando", "[ota]") {
    set("state", "pending_confirm");
    set("apply_start", std::to_string(time(nullptr)));
    OtaManager::getInstance().onBoot();
    REQUIRE(get("state") == "pending_confirm");
}
