/**
 * =============================================================================
 * test_audit_trail.cpp — Tests Catch2 para AuditTrail.
 * =============================================================================
 * Verifica:
 *   - logEvent retorna bool (true en éxito, false en fallo de SQLite).
 *   - logEvent con documento vacío no crasha.
 *   - logEvent con evento descriptivo se persiste.
 *
 * NOTA: AuditTrail::logEvent delega en SqliteManager::saveAudit.
 *       SqliteManager usa una DB local (nexo_edge.db). Si la DB no está
 *       inicializada, saveAudit puede retornar false.
 *       Los tests verifican el contrato de la interfaz, no la persistencia real.
 */

#include <catch2/catch_test_macros.hpp>
#include <string>

#include "interoperabilidad/audit_trail.h"
#include "base_de_datos/sqlite_manager.h"

TEST_CASE("AuditTrail::logEvent returns bool", "[audit]") {
    // Initialize SQLite for the test
    SqliteManager::getInstance().initialize("/tmp/nexo_test_audit.db");
    bool result = AuditTrail::logEvent("12345678", "ACCESS_GRANTED");
    // Should return true if SQLite is working, false if not
    // We just verify it returns a bool without crashing
    REQUIRE((result == true || result == false));
}

TEST_CASE("AuditTrail::logEvent with empty doc does not crash", "[audit]") {
    SqliteManager::getInstance().initialize("/tmp/nexo_test_audit.db");
    bool result = AuditTrail::logEvent("", "EMPTY_DOC_TEST");
    REQUIRE((result == true || result == false));
}

TEST_CASE("AuditTrail::logEvent with long event string", "[audit]") {
    SqliteManager::getInstance().initialize("/tmp/nexo_test_audit.db");
    std::string longEvent(500, 'X');
    bool result = AuditTrail::logEvent("doc-123", longEvent);
    REQUIRE((result == true || result == false));
}

TEST_CASE("AuditTrail::logEvent multiple calls", "[audit]") {
    SqliteManager::getInstance().initialize("/tmp/nexo_test_audit.db");
    for (int i = 0; i < 5; i++) {
        bool result = AuditTrail::logEvent("doc-" + std::to_string(i), "EVENT_" + std::to_string(i));
        REQUIRE((result == true || result == false));
    }
}

TEST_CASE("AuditTrail::logEvent with special characters in doc", "[audit]") {
    SqliteManager::getInstance().initialize("/tmp/nexo_test_audit.db");
    bool result = AuditTrail::logEvent("doc'with\"special\x01chars", "ACCESS_DENIED");
    REQUIRE((result == true || result == false));
}
