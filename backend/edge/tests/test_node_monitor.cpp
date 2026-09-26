/**
 * =============================================================================
 * test_node_monitor.cpp — Tests Catch2 del monitoreo físico del nodo.
 * =============================================================================
 * PowerMonitor: transiciones MAINS↔BATTERY, LOW_BATTERY, CRITICAL,
 *   shutdown ordenado — con archivos fixture de sysfs (simulación integrada).
 * CellularManager: parser puro de mmcli + operstate por archivo.
 * NodeTelemetry: disk_free_mb real, cpu_temp_c por fixture, JSON.
 * DLQ: reintento de largo plazo sobre audit_trail real (SQLite).
 * =============================================================================
 */

#include <catch2/catch_test_macros.hpp>
#include <cstdio>
#include <fstream>
#include <filesystem>
#include <string>

#include "hardware/node_monitor.h"
#include "base_de_datos/sqlite_manager.h"
#include "base_de_datos/encryption.h"

namespace fs = std::filesystem;

// ── Helpers de fixture sysfs ──
static void writeFile(const std::string& path, const std::string& content) {
    fs::create_directories(fs::path(path).parent_path());
    std::ofstream f(path);
    f << content;
}

static void rmTree(const std::string& path) {
    std::error_code ec;
    fs::remove_all(path, ec);
}

// ──────────────────────────── PowerMonitor ────────────────────────────

TEST_CASE("PowerMonitor detecta transición MAINS→BATTERY→MAINS", "[power]") {
    std::string dir = "/tmp/nexo_pm_test/ups";
    rmTree("/tmp/nexo_pm_test");
    writeFile(dir + "/status", "Charging\n");
    writeFile(dir + "/capacity", "85\n");

    PowerMonitor pm(dir);
    REQUIRE(pm.readState() == PowerState::MAINS);

    PowerState st;
    pm.pollTransition(st); // primer poll: baseline
    REQUIRE(pm.lastState() == PowerState::MAINS);

    // Corte de energía: UPS pasa a Discharging
    writeFile(dir + "/status", "Discharging\n");
    writeFile(dir + "/capacity", "60\n");
    REQUIRE(pm.pollTransition(st));
    REQUIRE(st == PowerState::BATTERY);

    // Regresa la energía
    writeFile(dir + "/status", "Charging\n");
    REQUIRE(pm.pollTransition(st));
    REQUIRE(st == PowerState::MAINS);

    rmTree("/tmp/nexo_pm_test");
}

TEST_CASE("PowerMonitor LOW_BATTERY y CRITICAL disparan shutdown ordenado", "[power]") {
    std::string dir = "/tmp/nexo_pm_test2/ups";
    rmTree("/tmp/nexo_pm_test2");
    writeFile(dir + "/status", "Discharging\n");
    writeFile(dir + "/capacity", "24\n");

    PowerMonitor pm(dir);
    REQUIRE(pm.readState() == PowerState::LOW_BATTERY);
    REQUIRE_FALSE(pm.shouldShutdown(8)); // 24% > 8% — todavía no

    writeFile(dir + "/capacity", "7\n");
    REQUIRE(pm.readState() == PowerState::CRITICAL);
    REQUIRE(pm.shouldShutdown(8));

    rmTree("/tmp/nexo_pm_test2");
}

TEST_CASE("PowerMonitor sin UPS → UNKNOWN (no alerta)", "[power]") {
    PowerMonitor pm("/tmp/nexo_pm_nonexistent");
    REQUIRE(pm.readState() == PowerState::UNKNOWN);
    REQUIRE_FALSE(pm.shouldShutdown());
    PowerState st;
    REQUIRE_FALSE(pm.pollTransition(st)); // UNKNOWN no cuenta como transición
}

TEST_CASE("PowerMonitor batería sin lectura de capacidad → BATTERY", "[power]") {
    std::string dir = "/tmp/nexo_pm_test3/ups";
    rmTree("/tmp/nexo_pm_test3");
    writeFile(dir + "/status", "Discharging\n");
    // sin 'capacity'
    PowerMonitor pm(dir);
    REQUIRE(pm.readState() == PowerState::BATTERY);
    REQUIRE(pm.readCapacity() == -1);
    REQUIRE_FALSE(pm.shouldShutdown(8)); // sin capacidad no se decide shutdown
    rmTree("/tmp/nexo_pm_test3");
}

// ──────────────────────────── TamperMonitor ────────────────────────────

TEST_CASE("TamperMonitor: flanco cerrado→abierto dispara una sola vez", "[tamper]") {
    rmTree("/tmp/nexo_tamper_test");
    std::string path = "/tmp/nexo_tamper_test/tamper/value";
    writeFile(path, "0\n");
    TamperMonitor tm(path);
    REQUIRE_FALSE(tm.isOpen());
    REQUIRE_FALSE(tm.pollOpen());          // primer poll, cerrado

    writeFile(path, "1\n");                // apertura física
    REQUIRE(tm.isOpen());
    REQUIRE(tm.pollOpen());                // flanco → evento
    REQUIRE_FALSE(tm.pollOpen());          // sigue abierto pero sin re-disparo

    writeFile(path, "0\n");                // cierra
    REQUIRE_FALSE(tm.pollOpen());
    writeFile(path, "1\n");                // nueva apertura
    REQUIRE(tm.pollOpen());                // nuevo flanco → nuevo evento
    rmTree("/tmp/nexo_tamper_test");
}

TEST_CASE("TamperMonitor: sin sensor → nunca alerta", "[tamper]") {
    TamperMonitor tm("/tmp/nexo_tamper_nonexistent/value");
    REQUIRE_FALSE(tm.isOpen());
    REQUIRE_FALSE(tm.pollOpen());
    TamperMonitor tm2("");                 // GPIO no configurado
    REQUIRE_FALSE(tm2.isOpen());
}

// ──────────────────────────── CellularManager ────────────────────────────

TEST_CASE("CellularManager parsea salida mmcli real", "[cellular]") {
    CellularStatus st;
    std::string mmcli = R"(
  -----------------------------
  Status   |         state: 'registered'
           |  signal quality: '72' (recent)
           |  operator name: 'Claro'
           |   access tech: 'lte'
    )";
    CellularManager::parseMmcliOutput(mmcli, st);
    REQUIRE(st.registered);
    REQUIRE(st.signalPct == 72);
    REQUIRE(st.carrier == "Claro");
    REQUIRE(st.tech == "lte");
}

TEST_CASE("CellularManager detecta interfaz caída por operstate", "[cellular]") {
    rmTree("/tmp/nexo_cell_test");
    writeFile("/tmp/nexo_cell_test/wwan0/operstate", "up\n");
    CellularManager cm("wwan0", "/tmp/nexo_cell_test");
    auto stUp = cm.readStatus();
    REQUIRE(stUp.interfaceUp);
    REQUIRE_FALSE(stUp.interfaceUp == false);

    writeFile("/tmp/nexo_cell_test/wwan0/operstate", "down\n");
    auto st = cm.readStatus();
    REQUIRE_FALSE(st.interfaceUp);
    REQUIRE(CellularManager::isDown(st));
    rmTree("/tmp/nexo_cell_test");
}

TEST_CASE("CellularManager sin interfaz → no interfaceUp", "[cellular]") {
    CellularManager cm("", "/tmp/nonexistent");
    REQUIRE_FALSE(cm.readStatus().interfaceUp);
}

// ──────────────────────────── NodeTelemetry ────────────────────────────

TEST_CASE("NodeTelemetry: disk_free_mb real y cpu_temp por fixture", "[telemetry]") {
    writeFile("/tmp/nexo_thermal_test/temp", "52300\n"); // 52.3°C
    NodeTelemetry nt("/tmp", "/tmp/nexo_thermal_test/temp");
    REQUIRE(nt.diskFreeMb() > 0);
    REQUIRE(nt.cpuTempC() == 52);
    rmTree("/tmp/nexo_thermal_test");
}

TEST_CASE("NodeTelemetry: sin sensor térmico → -1 (no dato, no alerta)", "[telemetry]") {
    NodeTelemetry nt("/tmp", "/tmp/nonexistent/temp");
    REQUIRE(nt.cpuTempC() == -1);
}

TEST_CASE("NodeTelemetry: JSON incluye todos los campos del contrato", "[telemetry]") {
    NodeTelemetry nt("/tmp", "");
    NodeMetrics m;
    m.clock_drift_s = 12;
    m.disk_free_mb = 4096;
    m.pending_events = 3;
    m.dlq_count = 1;
    m.cpu_temp_c = 55;
    m.power_state = PowerState::BATTERY;
    m.cell.interfaceUp = true;
    m.cell.signalPct = 68;
    m.tamper_open = true;

    auto j = nt.toJson(m);
    REQUIRE(j["clock_drift_s"] == 12);
    REQUIRE(j["disk_free_mb"] == 4096);
    REQUIRE(j["pending_events"] == 3);
    REQUIRE(j["dlq_count"] == 1);
    REQUIRE(j["cpu_temp_c"] == 55);
    REQUIRE(j["power_state"] == "BATTERY");
    REQUIRE(j["cell"]["signal_pct"] == 68);
    REQUIRE(j["cell"]["interface_up"] == true);
    REQUIRE(j["tamper_open"] == true);
}

// ──────────────────────────── Cifrado de campos ────────────────────────────

TEST_CASE("F-11: PII de estudiantes cifrada en reposo, descifrada al leer", "[crypto_fields]") {
    std::string dbPath = "/tmp/nexo_test_f11.db";
    std::string keyPath = "/tmp/nexo_test_f11.key";
    std::remove(dbPath.c_str());
    std::remove(keyPath.c_str());

    auto& enc = Encryption::getInstance();
    enc.setKeyFile(keyPath);
    std::string key(32, 'K');
    REQUIRE(enc.provisionKey(key));
    REQUIRE(enc.isKeyProvisioned());

    auto& db = SqliteManager::getInstance();
    db.close();
    db.initialize(dbPath);

    Estudiante est;
    est.documento = "DOCPII";
    est.nombre = "María José Pérez";
    est.telefono_acudiente = "3001234567";
    est.nombre_acudiente = "Ana Pérez";
    est.huella_id = 0;
    REQUIRE(db.saveEstudiante(est));

    // En reposo: nombre NO es plaintext (prefijo enc:v1:). La columna
    // documento almacena la clave HMAC (64 hex), no el número real; el valor
    // real va en documento_enc cifrado.
    {
        sqlite3_stmt* st;
        sqlite3_prepare_v2(db.getDB(), "SELECT nombre, telefono_acudiente, documento, documento_enc FROM estudiantes", -1, &st, nullptr);
        REQUIRE(sqlite3_step(st) == SQLITE_ROW);
        std::string nombreRaw = reinterpret_cast<const char*>(sqlite3_column_text(st, 0));
        std::string telRaw = reinterpret_cast<const char*>(sqlite3_column_text(st, 1));
        std::string docRaw = reinterpret_cast<const char*>(sqlite3_column_text(st, 2));
        const char* docEncP = reinterpret_cast<const char*>(sqlite3_column_text(st, 3));
        std::string docEnc = docEncP ? docEncP : "";
        sqlite3_finalize(st);
        REQUIRE(nombreRaw.rfind("enc:v1:", 0) == 0);
        REQUIRE(telRaw.rfind("enc:v1:", 0) == 0);
        REQUIRE(nombreRaw.find("María") == std::string::npos);
        // El documento en reposo no es legible ni indexable en claro
        REQUIRE(docRaw != "DOCPII");
        REQUIRE(docRaw.size() == 64);
        REQUIRE(!docEnc.empty());
        REQUIRE(docEnc.rfind("enc:v1:", 0) == 0);
        REQUIRE(docEnc.find("DOCPII") == std::string::npos);
    }

    // Al leer: descifrado transparente
    Estudiante got;
    REQUIRE(db.getEstudianteByDocumento("DOCPII", got));
    REQUIRE(got.nombre == "María José Pérez");
    REQUIRE(got.telefono_acudiente == "3001234567");
    REQUIRE(got.nombre_acudiente == "Ana Pérez");

    // audit_trail.documento y event también cifrados en reposo
    REQUIRE(db.saveAudit("DOCPII", "INGRESO"));
    {
        sqlite3_stmt* st;
        sqlite3_prepare_v2(db.getDB(), "SELECT documento, event FROM audit_trail ORDER BY id DESC LIMIT 1", -1, &st, nullptr);
        REQUIRE(sqlite3_step(st) == SQLITE_ROW);
        std::string docRaw = reinterpret_cast<const char*>(sqlite3_column_text(st, 0));
        std::string evtRaw = reinterpret_cast<const char*>(sqlite3_column_text(st, 1));
        sqlite3_finalize(st);
        REQUIRE(docRaw.rfind("enc:v1:", 0) == 0);
        REQUIRE(docRaw.find("DOCPII") == std::string::npos);
        REQUIRE(evtRaw.rfind("enc:v1:", 0) == 0);
        REQUIRE(evtRaw != "INGRESO");
    }
    // Y descifrado al leer la cola pendiente
    std::vector<AuditRecord> audits;
    REQUIRE(db.getPendingAudits(audits));
    bool found = false;
    for (auto& a : audits) if (a.documento == "DOCPII") found = true;
    REQUIRE(found);

    std::remove(dbPath.c_str());
    std::remove(keyPath.c_str());
}

TEST_CASE("F-11: filas legacy en claro siguen leyéndose (migración gradual)", "[crypto_fields]") {
    std::string dbPath = "/tmp/nexo_test_f11_legacy.db";
    std::remove(dbPath.c_str());
    auto& db = SqliteManager::getInstance();
    db.close();
    db.initialize(dbPath);

    // Insertar directamente en claro (simula fila legacy sin cifrar)
    sqlite3_exec(db.getDB(),
        "INSERT INTO estudiantes (documento, nombre, telefono_acudiente) VALUES ('DOCLEG','Nombre Claro','3001112222');",
        nullptr, nullptr, nullptr);
    Estudiante got;
    REQUIRE(db.getEstudianteByDocumento("DOCLEG", got));
    REQUIRE(got.nombre == "Nombre Claro");
    REQUIRE(got.telefono_acudiente == "3001112222");

    std::remove(dbPath.c_str());
}

// ──────────────────────────── DLQ ────────────────────────────

TEST_CASE("DLQ: registros fallidos van a DLQ y se reintentan", "[dlq]") {
    std::string dbPath = "/tmp/nexo_test_dlq.db";
    std::remove(dbPath.c_str());
    auto& db = SqliteManager::getInstance();
    db.close();
    db.initialize(dbPath);

    REQUIRE(db.saveAudit("DOC9", "INGRESO"));
    REQUIRE(db.saveAudit("DOC9", "SALIDA"));
    REQUIRE(db.getPendingAuditCount() == 2);
    REQUIRE(db.getDlqCount() == 0);

    std::vector<AuditRecord> audits;
    REQUIRE(db.getPendingAudits(audits));
    REQUIRE(audits.size() == 2);

    // Simular 5 fallos → DLQ (synced=-1)
    for (auto& a : audits) {
        for (int i = 0; i < 5; ++i) db.incrementAuditAttempt(a.id);
        db.markAuditError(a.id);
    }
    REQUIRE(db.getPendingAuditCount() == 0);
    REQUIRE(db.getDlqCount() == 2);

    // Reintento de largo plazo: vuelven a la cola con attempts=0
    REQUIRE(db.requeueDlqItems(20) == 2);
    REQUIRE(db.getDlqCount() == 0);
    REQUIRE(db.getPendingAuditCount() == 2);
    audits.clear();
    REQUIRE(db.getPendingAudits(audits));
    for (auto& a : audits) REQUIRE(a.attempts == 0);

    // Sync OK → limpiar
    for (auto& a : audits) db.clearAudit(a.id);
    REQUIRE(db.getPendingAuditCount() == 0);

    std::remove(dbPath.c_str());
}
