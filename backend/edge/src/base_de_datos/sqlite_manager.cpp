#include "base_de_datos/sqlite_manager.h"
#include "base_de_datos/encryption.h"
#include "utils/Logger.h"
#include <cstring>
#include <thread>
#include <chrono>
#include <openssl/rand.h>

bool SqliteManager::initialize(const std::string& dbPath) {
    int rc = sqlite3_open(dbPath.c_str(), &db);
    if (rc != SQLITE_OK) {
        LOG_CRITICAL("Cannot open DB: {} (code: {})", sqlite3_errmsg(db), rc);
        sqlite3_close(db);
        db = nullptr;

        // FIX: Si es base de datos corrupta, renombrar y recrear
        if (rc == SQLITE_CORRUPT || rc == SQLITE_NOTADB) {
            LOG_WARN("DB appears corrupt. Renaming to .bak and creating fresh DB.");
            std::string bakPath = dbPath + ".bak";
            std::rename(dbPath.c_str(), bakPath.c_str());
            rc = sqlite3_open(dbPath.c_str(), &db);
            if (rc != SQLITE_OK) {
                LOG_CRITICAL("Failed to create fresh DB: {}", sqlite3_errmsg(db));
                sqlite3_close(db);
                db = nullptr;
                return false;
            }
            LOG_INFO("Fresh DB created after corruption recovery. Sync from cloud required.");
        } else {
            return false;
        }
    }
    sqlite3_busy_timeout(db, 5000); 
    sqlite3_exec(db, "PRAGMA journal_mode=WAL;", nullptr, nullptr, nullptr);
    sqlite3_exec(db, "PRAGMA synchronous=EXTRA;", nullptr, nullptr, nullptr);
    sqlite3_exec(db, "PRAGMA temp_store=MEMORY;", nullptr, nullptr, nullptr);
    sqlite3_exec(db, "PRAGMA foreign_keys=ON;", nullptr, nullptr, nullptr);
    return createTables();
}

void SqliteManager::close() {
    if (db) { sqlite3_close(db); db = nullptr; }
}

bool SqliteManager::factoryReset() { close(); return true; }

bool SqliteManager::createTables() {
    const char* sql = R"(
        CREATE TABLE IF NOT EXISTS estudiantes (documento TEXT PRIMARY KEY, nombre TEXT NOT NULL, telefono_acudiente TEXT, nombre_acudiente TEXT, huella_id INTEGER UNIQUE, template_huella BLOB);
        CREATE TABLE IF NOT EXISTS patrones (documento TEXT PRIMARY KEY, ingresos_temprano INTEGER DEFAULT 0, ingresos_tarde INTEGER DEFAULT 0, asistencia_total INTEGER DEFAULT 0);
        -- PAE (Programa de Alimentacion Escolar) eliminado por decision de arquitectura
        CREATE TABLE IF NOT EXISTS inasistencias (documento TEXT PRIMARY KEY, fecha TEXT DEFAULT (date('now')));
        CREATE TABLE IF NOT EXISTS audit_trail (id INTEGER PRIMARY KEY AUTOINCREMENT, documento TEXT NOT NULL, event TEXT NOT NULL, fecha TEXT DEFAULT (datetime('now')), synced INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT);
        CREATE INDEX IF NOT EXISTS idx_audit_synced ON audit_trail(synced);
    )";
    return sqlite3_exec(db, sql, nullptr, nullptr, nullptr) == SQLITE_OK;
}

// Ejecuta un statement con manejo de SQLITE_LOCKED (Race Conditions)
int executeWithRetry(sqlite3_stmt* stmt) {
    int rc;
    int retries = 0;
    while ((rc = sqlite3_step(stmt)) == SQLITE_LOCKED || rc == SQLITE_BUSY) {
        if (++retries > 5) break;
        std::this_thread::sleep_for(std::chrono::milliseconds(10 * (1 << retries))); // Backoff exponencial
    }
    return rc;
}

bool SqliteManager::saveEstudiante(const Estudiante& est) {
    const char* sql = "INSERT OR REPLACE INTO estudiantes (documento, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella) VALUES (?,?,?,?,?,?);";
    sqlite3_stmt* stmt;
    // FIX: Uso de prepare_v3 con flag 0 (evita fuga de memoria con SQLITE_PREPARE_PERSISTENT)
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, est.documento.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, est.nombre.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 3, est.telefono_acudiente.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 4, est.nombre_acudiente.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_int(stmt, 5, static_cast<int>(est.huella_id));
    // FIX: IV aleatorio de 12 bytes (RAND_bytes), concatenado al ciphertext en base64
    std::string rawTemplate(est.template_huella.begin(), est.template_huella.end());
    std::vector<uint8_t> iv(12);
    if (RAND_bytes(iv.data(), static_cast<int>(iv.size())) != 1) {
        LOG_ERROR("RAND_bytes failed for IV generation");
        sqlite3_finalize(stmt);
        return false;
    }
    std::string encryptedTemplateB64 = Encryption::getInstance().encrypt(rawTemplate, iv);
    sqlite3_bind_text(stmt, 6, encryptedTemplateB64.c_str(), -1, SQLITE_TRANSIENT);
    
    bool success = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return success;
}

bool SqliteManager::getEstudianteByDocumento(const std::string& doc, Estudiante& est) {
    const char* sql = "SELECT documento, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella FROM estudiantes WHERE documento = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, doc.c_str(), -1, SQLITE_TRANSIENT);
    
    bool found = false;
    if (executeWithRetry(stmt) == SQLITE_ROW) {
        est.documento = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        est.nombre = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        const char* tel = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        est.telefono_acudiente = tel ? tel : "";
        const char* nom = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 3));
        est.nombre_acudiente = nom ? nom : "";
        est.huella_id = static_cast<uint32_t>(sqlite3_column_int(stmt, 4));
        // FIX: El base64 almacenado contiene IV(12) + ciphertext + tag(16); decrypt extrae el IV
        const char* encryptedTemplate = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 5));
        if (encryptedTemplate) {
            std::string decrypted = Encryption::getInstance().decrypt(encryptedTemplate);
            if (!decrypted.empty()) {
                est.template_huella.assign(decrypted.begin(), decrypted.end());
            }
        }
        found = true;
    }
    sqlite3_finalize(stmt);
    return found;
}

bool SqliteManager::getEstudianteByHuellaID(uint32_t huellaId, Estudiante& est) {
    const char* sql = "SELECT documento, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella FROM estudiantes WHERE huella_id = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_int(stmt, 1, static_cast<int>(huellaId));
    bool found = false;
    if (executeWithRetry(stmt) == SQLITE_ROW) {
        est.documento = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        est.nombre = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        const char* tel = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        est.telefono_acudiente = tel ? tel : "";
        const char* nom = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 3));
        est.nombre_acudiente = nom ? nom : "";
        est.huella_id = static_cast<uint32_t>(sqlite3_column_int(stmt, 4));
        // FIX: El base64 almacenado contiene IV(12) + ciphertext + tag(16); decrypt extrae el IV
        const char* encryptedTemplate = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 5));
        if (encryptedTemplate) {
            std::string decrypted = Encryption::getInstance().decrypt(encryptedTemplate);
            if (!decrypted.empty()) {
                est.template_huella.assign(decrypted.begin(), decrypted.end());
            }
        }
        found = true;
    }
    sqlite3_finalize(stmt);
    return found;
}

bool SqliteManager::deleteEstudiante(const std::string& doc) {
    const char* sql = "DELETE FROM estudiantes WHERE documento = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, doc.c_str(), -1, SQLITE_TRANSIENT);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

uint32_t SqliteManager::getNextHuellaID() {
    const char* sql = "SELECT COALESCE(MAX(huella_id), 0) + 1 FROM estudiantes;";
    sqlite3_stmt* stmt;
    uint32_t next = 1;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        if (executeWithRetry(stmt) == SQLITE_ROW) next = static_cast<uint32_t>(sqlite3_column_int(stmt, 0));
        sqlite3_finalize(stmt);
    }
    return next;
}

bool SqliteManager::updatePattern(const std::string& documento, bool temprano, bool tarde) {
    const char* sql = "INSERT INTO patrones (documento, ingresos_temprano, ingresos_tarde, asistencia_total) VALUES (?, ?, ?, 1) ON CONFLICT(documento) DO UPDATE SET asistencia_total = asistencia_total + 1, ingresos_temprano = ingresos_temprano + ?, ingresos_tarde = ingresos_tarde + ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    int temp = temprano ? 1 : 0; int tar = tarde ? 1 : 0;
    sqlite3_bind_text(stmt, 1, documento.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_int(stmt, 2, temp); sqlite3_bind_int(stmt, 3, tar); sqlite3_bind_int(stmt, 4, temp); sqlite3_bind_int(stmt, 5, tar);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

bool SqliteManager::saveAudit(const std::string& documento, const std::string& event) {
    const char* sql = "INSERT INTO audit_trail (documento, event) VALUES (?, ?);";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, documento.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, event.c_str(), -1, SQLITE_TRANSIENT);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

bool SqliteManager::getPendingAudits(std::vector<AuditRecord>& audits) {
    // strftime('%s', fecha) convierte la fecha original a Timestamp UNIX
    const char* sql = "SELECT documento, event, CAST(strftime('%s', fecha) AS INTEGER) FROM audit_trail WHERE synced = 0 ORDER BY id LIMIT 50;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    while (executeWithRetry(stmt) == SQLITE_ROW) {
        audits.push_back({
            reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0)),
            reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1)),
            sqlite3_column_int(stmt, 2)
        });
    }
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::clearAudit(const std::string& documento, const std::string& event) {
    const char* sql = "UPDATE audit_trail SET synced = 1 WHERE documento = ? AND event = ? AND synced = 0;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, documento.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, event.c_str(), -1, SQLITE_TRANSIENT);
    executeWithRetry(stmt);
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::checkInasistencia(const std::string& /*doc*/) { return false; }
bool SqliteManager::deleteInasistencia(const std::string& /*doc*/) { return true; }
bool SqliteManager::savePAE(const std::string& /*doc*/, bool /*r*/) { return true; }
bool SqliteManager::setConfig(const std::string& /*k*/, const std::string& /*v*/) { return true; }
std::string SqliteManager::getConfig(const std::string& /*k*/, const std::string& d) { return d; }
