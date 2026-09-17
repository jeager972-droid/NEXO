/**
 * =============================================================================
 * sqlite_manager.cpp — Implementación de la base de datos local SQLite.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Abre/crea la base de datos local del edge, maneja corrupción renombrando a
 *   .bak, aplica pragmas WAL/synchronous/foreign_keys y crea tablas. Expone
 *   CRUD de estudiantes, patrones de asistencia, inasistencias (legacy), y
 *   audit_trail para sincronización con la nube. Los templates se cifran con
 *   Encryption usando un IV aleatorio de 12 bytes.
 *
 * SECCIONES:
 *   1. initialize/close/factoryReset/createTables
 *   2. executeWithRetry() — manejo SQLITE_BUSY/LOCKED con backoff exponencial
 *   3. Estudiantes (save, get, delete, nextHuellaID)
 *   4. Patrones de asistencia
 *   5. Auditoría pendiente (save, getPending, clear, attempts, error)
 *   6. Funciones legacy/config (stubs)
 */

#include "base_de_datos/sqlite_manager.h"
#include "base_de_datos/encryption.h"
#include "utils/Logger.h"
#include <cstring>
#include <thread>
#include <chrono>
#include <algorithm>
#include <openssl/rand.h>

// Declaraciones anticipadas — helpers de cifrado/retry definidos más abajo.
int executeWithRetry(sqlite3_stmt* stmt);
static std::string encField(const std::string& plain);
static std::string decField(const std::string& stored);
static std::string docKey(const std::string& doc);
static bool isKeyedDoc(const std::string& v);

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
        CREATE TABLE IF NOT EXISTS estudiantes (documento TEXT PRIMARY KEY, documento_enc TEXT, nombre TEXT NOT NULL, telefono_acudiente TEXT, nombre_acudiente TEXT, huella_id INTEGER UNIQUE, template_huella BLOB, school_id TEXT);
        -- TODO: evaluar si tabla patrones se usa, candidato a eliminar
        CREATE TABLE IF NOT EXISTS patrones (documento TEXT PRIMARY KEY, ingresos_temprano INTEGER DEFAULT 0, ingresos_tarde INTEGER DEFAULT 0, asistencia_total INTEGER DEFAULT 0);
        -- PAE (Programa de Alimentacion Escolar) eliminado por decision de arquitectura
        CREATE TABLE IF NOT EXISTS inasistencias (documento TEXT PRIMARY KEY, fecha TEXT DEFAULT (date('now')));
        CREATE TABLE IF NOT EXISTS audit_trail (id INTEGER PRIMARY KEY AUTOINCREMENT, documento TEXT NOT NULL, event TEXT NOT NULL, fecha TEXT DEFAULT (datetime('now')), synced INTEGER DEFAULT 0, attempts INTEGER DEFAULT 0);
        CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT);
        -- F-03: multi-huella — hasta 2 dedos por estudiante. estudiantes.huella_id
        -- se mantiene como compat (dedo 1); estudiante_huellas es la fuente normalizada.
        CREATE TABLE IF NOT EXISTS estudiante_huellas (
            documento TEXT NOT NULL,
            finger_slot INTEGER NOT NULL CHECK(finger_slot IN (1,2)),
            huella_id INTEGER NOT NULL UNIQUE,
            template_huella BLOB,
            school_id TEXT,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            PRIMARY KEY(documento, finger_slot)
        );
        CREATE INDEX IF NOT EXISTS idx_audit_synced ON audit_trail(synced);
        CREATE INDEX IF NOT EXISTS idx_estudiantes_documento ON estudiantes(documento);
        CREATE INDEX IF NOT EXISTS idx_estudiantes_school_id ON estudiantes(school_id);
        CREATE INDEX IF NOT EXISTS idx_estudiantes_huella ON estudiantes(huella_id);
        CREATE INDEX IF NOT EXISTS idx_estudiante_huellas_huella ON estudiante_huellas(huella_id);
    )";
    bool ok = sqlite3_exec(db, sql, nullptr, nullptr, nullptr) == SQLITE_OK;
    sqlite3_exec(db, "ALTER TABLE audit_trail ADD COLUMN attempts INTEGER DEFAULT 0;", nullptr, nullptr, nullptr);
    // Migración para bases de datos existentes (agrega school_id si no existe)
    migrateSchema();
    return ok;
}

void SqliteManager::migrateSchema() {
    // ALTER TABLE para bases de datos existentes que no tienen school_id
    // Si la columna ya existe, SQLite retorna error (SQLITE_ERROR) — lo ignoramos
    sqlite3_exec(db, "ALTER TABLE estudiantes ADD COLUMN school_id TEXT;", nullptr, nullptr, nullptr);
    sqlite3_exec(db, "ALTER TABLE estudiantes ADD COLUMN documento_enc TEXT;", nullptr, nullptr, nullptr);

    // V-243: migración de documentos en claro → clave seudonimizada + valor
    // cifrado. Solo corre cuando hay clave provisionada (keyedHash no vacío);
    // idempotente: los documentos ya derivados tienen 64 hex y se saltan.
    if (!Encryption::getInstance().isKeyProvisioned()) return;

    auto migrateTable = [this](const char* table, bool withEnc) {
        std::string selSql = std::string("SELECT documento") + (withEnc ? ", documento_enc" : "") +
                             " FROM " + table + ";";
        sqlite3_stmt* sel;
        if (sqlite3_prepare_v3(db, selSql.c_str(), -1, 0, &sel, nullptr) != SQLITE_OK) return;
        std::vector<std::string> plainDocs;
        while (executeWithRetry(sel) == SQLITE_ROW) {
            const char* d = reinterpret_cast<const char*>(sqlite3_column_text(sel, 0));
            if (!d) continue;
            std::string doc = d;
            bool alreadyKeyed = isKeyedDoc(doc);
            if (withEnc) {
                const char* enc = reinterpret_cast<const char*>(sqlite3_column_text(sel, 1));
                if (alreadyKeyed && enc) continue; // migrado completo
            } else if (alreadyKeyed) {
                continue;
            }
            if (!alreadyKeyed) plainDocs.push_back(doc);
        }
        sqlite3_finalize(sel);

        for (const auto& doc : plainDocs) {
            std::string key = docKey(doc);
            sqlite3_stmt* upd;
            std::string updSql = std::string("UPDATE ") + table +
                (withEnc ? " SET documento = ?, documento_enc = ? WHERE documento = ?;"
                         : " SET documento = ? WHERE documento = ?;");
            if (sqlite3_prepare_v3(db, updSql.c_str(), -1, 0, &upd, nullptr) != SQLITE_OK) continue;
            std::string encDoc = withEnc ? encField(doc) : "";
            sqlite3_bind_text(upd, 1, key.c_str(), -1, SQLITE_TRANSIENT);
            if (withEnc) {
                sqlite3_bind_text(upd, 2, encDoc.c_str(), -1, SQLITE_TRANSIENT);
                sqlite3_bind_text(upd, 3, doc.c_str(), -1, SQLITE_TRANSIENT);
            } else {
                sqlite3_bind_text(upd, 2, doc.c_str(), -1, SQLITE_TRANSIENT);
            }
            executeWithRetry(upd);
            sqlite3_finalize(upd);
        }
        if (!plainDocs.empty()) {
            LOG_INFO("[V-243] {} documento(s) seudonimizados en {}", plainDocs.size(), table);
        }
    };

    migrateTable("estudiantes", true);
    migrateTable("estudiante_huellas", false);
    migrateTable("patrones", false);
    migrateTable("inasistencias", false);
}

// ── F-11: cifrado a nivel de campo para PII en reposo ──────────────────────
// Formato: "enc:v1:" + base64(IV|ct|tag) (mismo esquema que template_huella).
// Sin clave provisionada → plaintext (degradado, igual que antes de F-11).
// decField: si el valor no lleva prefijo → se trata como plaintext legacy
// (migración gradual de bases existentes).
static std::string encField(const std::string& plain) {
    if (plain.empty()) return plain;
    auto& enc = Encryption::getInstance();
    if (!enc.isKeyProvisioned()) return plain;
    std::string c = enc.encrypt(plain);
    if (c.empty()) {
        LOG_WARN("encField: cifrado falló — guardando en claro (degradado)");
        return plain;
    }
    return "enc:v1:" + c;
}

static std::string decField(const std::string& stored) {
    static const std::string pfx = "enc:v1:";
    if (stored.compare(0, pfx.size(), pfx) != 0) return stored; // legacy plaintext
    std::string d = Encryption::getInstance().decrypt(stored.substr(pfx.size()));
    if (d.empty()) {
        LOG_WARN("decField: decrypt falló — devolviendo valor crudo (no pérdida)");
        return stored;
    }
    return d;
}

// ── V-243: clave de búsqueda seudonimizada para `documento` ────────────────
// El documento en reposo ya no es legible: todas las tablas guardan
// docKey = HMAC-SHA256(documento) (64 hex, determinístico → PK/join intactos)
// y el valor real queda en estudiantes.documento_enc (AES-GCM). Sin clave
// provisionada → plaintext (mismo degradado que encField).
static std::string docKey(const std::string& doc) {
    if (doc.empty()) return doc;
    std::string k = Encryption::getInstance().keyedHash(doc);
    return k.empty() ? doc : k;
}

// true si el valor ya es una clave derivada (64 hex), no un documento en claro
static bool isKeyedDoc(const std::string& v) {
    if (v.size() != 64) return false;
    for (char c : v) if (!isxdigit((unsigned char)c)) return false;
    return true;
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
    const char* sql = "INSERT OR REPLACE INTO estudiantes (documento, documento_enc, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella, school_id) VALUES (?,?,?,?,?,?,?,?);";
    sqlite3_stmt* stmt;
    // FIX: Uso de prepare_v3 con flag 0 (evita fuga de memoria con SQLITE_PREPARE_PERSISTENT)
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    // V-243: la PK es la clave seudonimizada (HMAC); el documento real va cifrado
    std::string key = docKey(est.documento);
    std::string encDoc = encField(est.documento);
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, encDoc.c_str(), -1, SQLITE_TRANSIENT);
    // F-11: PII cifrada en reposo (nombre/teléfonos/documento).
    std::string encNombre  = encField(est.nombre);
    std::string encTel     = encField(est.telefono_acudiente);
    std::string encNomAcud = encField(est.nombre_acudiente);
    sqlite3_bind_text(stmt, 3, encNombre.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 4, encTel.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 5, encNomAcud.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_int(stmt, 6, static_cast<int>(est.huella_id));
    // FIX: IV aleatorio de 12 bytes (RAND_bytes), concatenado al ciphertext en base64
    std::string rawTemplate(est.template_huella.begin(), est.template_huella.end());
    std::vector<uint8_t> iv(12);
    if (RAND_bytes(iv.data(), static_cast<int>(iv.size())) != 1) {
        // FIX 1: Si RAND_bytes falla, NO continuar con IV no inicializado — retornar error inmediatamente
        LOG_ERROR("RAND_bytes failed for IV generation — abortando saveEstudiante por seguridad");
        sqlite3_finalize(stmt);
        return false;
    }
    std::string encryptedTemplateB64 = Encryption::getInstance().encrypt(rawTemplate, iv);
    sqlite3_bind_text(stmt, 7, encryptedTemplateB64.c_str(), -1, SQLITE_TRANSIENT);
    // school_id: multi-tenancy (NULL si no está definido)
    if (est.school_id.empty()) {
        sqlite3_bind_null(stmt, 8);
    } else {
        sqlite3_bind_text(stmt, 8, est.school_id.c_str(), -1, SQLITE_TRANSIENT);
    }

    bool success = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return success;
}

bool SqliteManager::getEstudianteByDocumento(const std::string& doc, Estudiante& est) {
    // V-243: doc puede ser el documento real (se deriva a clave) o la clave
    // almacenada (viene de estudiante_huellas) — no se re-deriva. El OR con el
    // valor crudo cubre filas legacy en claro no migradas aún.
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc);
    const char* sql = "SELECT documento_enc, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella, school_id FROM estudiantes WHERE documento IN (?, ?) LIMIT 1;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, doc.c_str(), -1, SQLITE_TRANSIENT);
    
    bool found = false;
    if (executeWithRetry(stmt) == SQLITE_ROW) {
        // documento real = documento_enc descifrado; si está vacío (fila legacy
        // en claro o clave no provisionada) se usa el valor que resolvió.
        const char* encDocCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        est.documento = (encDocCol && *encDocCol) ? decField(encDocCol) : doc;
        const char* nombreCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        est.nombre = nombreCol ? decField(nombreCol) : "";
        const char* tel = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        est.telefono_acudiente = tel ? decField(tel) : "";
        const char* nom = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 3));
        est.nombre_acudiente = nom ? decField(nom) : "";
        est.huella_id = static_cast<uint32_t>(sqlite3_column_int(stmt, 4));
        // FIX: El base64 almacenado contiene IV(12) + ciphertext + tag(16); decrypt extrae el IV
        const char* encryptedTemplate = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 5));
        if (encryptedTemplate) {
            std::string decrypted = Encryption::getInstance().decrypt(encryptedTemplate);
            if (!decrypted.empty()) {
                est.template_huella.assign(decrypted.begin(), decrypted.end());
            }
        }
        // school_id (columna 6) — multi-tenancy
        const char* sid = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 6));
        est.school_id = sid ? sid : "";
        found = true;
    }
    sqlite3_finalize(stmt);
    return found;
}

bool SqliteManager::getEstudianteByHuellaID(uint32_t huellaId, Estudiante& est) {
    // F-03: buscar primero en estudiante_huellas (multi-dedo); si está, el
    // documento (ya seudonimizado, V-243) resuelve el estudiante sin re-derivar.
    const char* sqlNew = "SELECT documento FROM estudiante_huellas WHERE huella_id = ?;";
    sqlite3_stmt* stmtNew;
    if (sqlite3_prepare_v3(db, sqlNew, -1, 0, &stmtNew, nullptr) == SQLITE_OK) {
        sqlite3_bind_int(stmtNew, 1, static_cast<int>(huellaId));
        if (executeWithRetry(stmtNew) == SQLITE_ROW) {
            std::string doc = reinterpret_cast<const char*>(sqlite3_column_text(stmtNew, 0));
            sqlite3_finalize(stmtNew);
            return getEstudianteByDocumento(doc, est);
        }
        sqlite3_finalize(stmtNew);
    }

    const char* sql = "SELECT documento_enc, documento, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella, school_id FROM estudiantes WHERE huella_id = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_int(stmt, 1, static_cast<int>(huellaId));
    bool found = false;
    if (executeWithRetry(stmt) == SQLITE_ROW) {
        const char* encDocCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        const char* docCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        est.documento = (encDocCol && *encDocCol) ? decField(encDocCol) : (docCol ? docCol : "");
        const char* nombreCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        est.nombre = nombreCol ? decField(nombreCol) : "";
        const char* tel = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 3));
        est.telefono_acudiente = tel ? decField(tel) : "";
        const char* nom = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 4));
        est.nombre_acudiente = nom ? decField(nom) : "";
        est.huella_id = static_cast<uint32_t>(sqlite3_column_int(stmt, 5));
        // FIX: El base64 almacenado contiene IV(12) + ciphertext + tag(16); decrypt extrae el IV
        const char* encryptedTemplate = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 6));
        if (encryptedTemplate) {
            std::string decrypted = Encryption::getInstance().decrypt(encryptedTemplate);
            if (!decrypted.empty()) {
                est.template_huella.assign(decrypted.begin(), decrypted.end());
            }
        }
        // school_id (columna 7) — multi-tenancy
        const char* sid = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 7));
        est.school_id = sid ? sid : "";
        found = true;
    }
    sqlite3_finalize(stmt);
    return found;
}

bool SqliteManager::deleteEstudiante(const std::string& doc) {
    // F-03: eliminar también las huellas multi-dedo (mismo caller, misma unidad lógica)
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc); // V-243
    const char* sqlH = "DELETE FROM estudiante_huellas WHERE documento = ?;";
    sqlite3_stmt* stmtH;
    if (sqlite3_prepare_v3(db, sqlH, -1, 0, &stmtH, nullptr) == SQLITE_OK) {
        sqlite3_bind_text(stmtH, 1, key.c_str(), -1, SQLITE_TRANSIENT);
        executeWithRetry(stmtH);
        sqlite3_finalize(stmtH);
    }
    const char* sql = "DELETE FROM estudiantes WHERE documento = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

// ── F-03: multi-huella ─────────────────────────────────────────────────────

bool SqliteManager::saveHuella(const std::string& doc, int fingerSlot, uint32_t huellaId,
                               const std::vector<uint8_t>& tpl, const std::string& schoolId) {
    if (fingerSlot < 1 || fingerSlot > 2) return false;
    const char* sql = "INSERT OR REPLACE INTO estudiante_huellas (documento, finger_slot, huella_id, template_huella, school_id) VALUES (?,?,?,?,?);";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc); // V-243
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_int(stmt, 2, fingerSlot);
    sqlite3_bind_int(stmt, 3, static_cast<int>(huellaId));
    // Mismo cifrado que estudiantes.template_huella: IV aleatorio + base64
    std::string rawTemplate(tpl.begin(), tpl.end());
    std::vector<uint8_t> iv(12);
    if (RAND_bytes(iv.data(), static_cast<int>(iv.size())) != 1) {
        LOG_ERROR("RAND_bytes failed for IV generation — abortando saveHuella por seguridad");
        sqlite3_finalize(stmt);
        return false;
    }
    std::string encryptedTemplateB64 = Encryption::getInstance().encrypt(rawTemplate, iv);
    sqlite3_bind_text(stmt, 4, encryptedTemplateB64.c_str(), -1, SQLITE_TRANSIENT);
    if (schoolId.empty()) sqlite3_bind_null(stmt, 5);
    else sqlite3_bind_text(stmt, 5, schoolId.c_str(), -1, SQLITE_TRANSIENT);
    bool success = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return success;
}

int SqliteManager::getHuellaCount(const std::string& doc) {
    int count = 0;
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc); // V-243
    const char* sql = "SELECT COUNT(*) FROM estudiante_huellas WHERE documento = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
        if (executeWithRetry(stmt) == SQLITE_ROW) count = sqlite3_column_int(stmt, 0);
        sqlite3_finalize(stmt);
    }
    if (count == 0) {
        // Fallback legacy: estudiantes.huella_id poblado cuenta como dedo 1
        const char* sqlLeg = "SELECT 1 FROM estudiantes WHERE documento = ? AND huella_id IS NOT NULL AND huella_id > 0;";
        sqlite3_stmt* stmtLeg;
        if (sqlite3_prepare_v3(db, sqlLeg, -1, 0, &stmtLeg, nullptr) == SQLITE_OK) {
            sqlite3_bind_text(stmtLeg, 1, key.c_str(), -1, SQLITE_TRANSIENT);
            if (executeWithRetry(stmtLeg) == SQLITE_ROW) count = 1;
            sqlite3_finalize(stmtLeg);
        }
    }
    return count;
}

bool SqliteManager::getHuellaIdsByDocumento(const std::string& doc, std::vector<uint32_t>& idsOut) {
    idsOut.clear();
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc); // V-243
    const char* sql = "SELECT huella_id FROM estudiante_huellas WHERE documento = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
        while (executeWithRetry(stmt) == SQLITE_ROW) {
            idsOut.push_back(static_cast<uint32_t>(sqlite3_column_int(stmt, 0)));
        }
        sqlite3_finalize(stmt);
    }
    // Legacy: incluir estudiantes.huella_id si no está ya en la lista
    const char* sqlLeg = "SELECT huella_id FROM estudiantes WHERE documento = ? AND huella_id IS NOT NULL AND huella_id > 0;";
    sqlite3_stmt* stmtLeg;
    if (sqlite3_prepare_v3(db, sqlLeg, -1, 0, &stmtLeg, nullptr) == SQLITE_OK) {
        sqlite3_bind_text(stmtLeg, 1, key.c_str(), -1, SQLITE_TRANSIENT);
        if (executeWithRetry(stmtLeg) == SQLITE_ROW) {
            uint32_t legacy = static_cast<uint32_t>(sqlite3_column_int(stmtLeg, 0));
            if (std::find(idsOut.begin(), idsOut.end(), legacy) == idsOut.end()) {
                idsOut.push_back(legacy);
            }
        }
        sqlite3_finalize(stmtLeg);
    }
    return !idsOut.empty();
}

bool SqliteManager::huellaSlotExists(const std::string& doc, int fingerSlot) {
    const char* sql = "SELECT 1 FROM estudiante_huellas WHERE documento = ? AND finger_slot = ? LIMIT 1;";
    sqlite3_stmt* stmt;
    bool exists = false;
    std::string key = isKeyedDoc(doc) ? doc : docKey(doc); // V-243
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
        sqlite3_bind_int(stmt, 2, fingerSlot);
        exists = (executeWithRetry(stmt) == SQLITE_ROW);
        sqlite3_finalize(stmt);
    }
    return exists;
}

uint32_t SqliteManager::getNextHuellaID() {
    // F-03: huella_id es global — el máximo debe cubrir ambas tablas
    const char* sql = "SELECT COALESCE(MAX(h), 0) + 1 FROM (SELECT huella_id AS h FROM estudiantes UNION ALL SELECT huella_id AS h FROM estudiante_huellas);";
    sqlite3_stmt* stmt;
    uint32_t next = 1;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        if (executeWithRetry(stmt) == SQLITE_ROW) next = static_cast<uint32_t>(sqlite3_column_int(stmt, 0));
        sqlite3_finalize(stmt);
    }
    return next;
}

bool SqliteManager::updatePattern(const std::string& documento, bool temprano, bool tarde) {
    std::string key = isKeyedDoc(documento) ? documento : docKey(documento); // V-243
    const char* sql = "INSERT INTO patrones (documento, ingresos_temprano, ingresos_tarde, asistencia_total) VALUES (?, ?, ?, 1) ON CONFLICT(documento) DO UPDATE SET asistencia_total = asistencia_total + 1, ingresos_temprano = ingresos_temprano + ?, ingresos_tarde = ingresos_tarde + ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    int temp = temprano ? 1 : 0; int tar = tarde ? 1 : 0;
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_int(stmt, 2, temp); sqlite3_bind_int(stmt, 3, tar); sqlite3_bind_int(stmt, 4, temp); sqlite3_bind_int(stmt, 5, tar);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

bool SqliteManager::saveAudit(const std::string& documento, const std::string& event) {
    const char* sql = "INSERT INTO audit_trail (documento, event) VALUES (?, ?);";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    // F-11: documento del estudiante cifrado en reposo en la cola de sync.
    // (getPendingAudits lo descifra antes de resolver el estudiante).
    std::string encDoc = encField(documento);
    sqlite3_bind_text(stmt, 1, encDoc.c_str(), -1, SQLITE_TRANSIENT);
    // V-245: el tipo de evento también queda cifrado en reposo
    std::string encEvt = encField(event);
    sqlite3_bind_text(stmt, 2, encEvt.c_str(), -1, SQLITE_TRANSIENT);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

bool SqliteManager::getPendingAudits(std::vector<AuditRecord>& audits) {
    // strftime('%s', fecha) convierte la fecha original a Timestamp UNIX
    const char* sql = "SELECT id, documento, event, CAST(strftime('%s', fecha) AS INTEGER), attempts FROM audit_trail WHERE synced = 0 ORDER BY id LIMIT 50;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    while (executeWithRetry(stmt) == SQLITE_ROW) {
        const char* docCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        const char* evtCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        audits.push_back({
            sqlite3_column_int(stmt, 0),
            docCol ? decField(docCol) : "",
            evtCol ? decField(evtCol) : "",
            sqlite3_column_int(stmt, 3),
            sqlite3_column_int(stmt, 4)
        });
    }
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::clearAudit(int id) {
    const char* sql = "UPDATE audit_trail SET synced = 1 WHERE id = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_int(stmt, 1, id);
    executeWithRetry(stmt);
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::clearAudit(const std::string& documento, const std::string& event) {
    // V-243/V-245: con documento y event cifrados (IV aleatorio) no existe
    // igualdad directa en SQL — se comparan los pendientes ya descifrados.
    std::vector<AuditRecord> pend;
    if (!getPendingAudits(pend)) return false;
    bool any = false;
    for (const auto& a : pend) {
        if (a.documento == documento && a.event == event) {
            any = clearAudit(a.id) || any;
        }
    }
    return any;
}

bool SqliteManager::incrementAuditAttempt(int id) {
    const char* sql = "UPDATE audit_trail SET attempts = attempts + 1 WHERE id = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_int(stmt, 1, id);
    executeWithRetry(stmt);
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::markAuditError(int id) {
    const char* sql = "UPDATE audit_trail SET synced = -1 WHERE id = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_int(stmt, 1, id);
    executeWithRetry(stmt);
    sqlite3_finalize(stmt);
    return true;
}

bool SqliteManager::checkInasistencia(const std::string& /*doc*/) { return false; }
bool SqliteManager::deleteInasistencia(const std::string& /*doc*/) { return true; }
bool SqliteManager::savePAE(const std::string& /*doc*/, bool /*r*/) { return true; }

bool SqliteManager::setConfig(const std::string& key, const std::string& value) {
    const char* sql = "INSERT INTO config (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    sqlite3_bind_text(stmt, 2, value.c_str(), -1, SQLITE_TRANSIENT);
    bool ok = (executeWithRetry(stmt) == SQLITE_DONE);
    sqlite3_finalize(stmt);
    return ok;
}

std::string SqliteManager::getConfig(const std::string& key, const std::string& defaultVal) {
    const char* sql = "SELECT value FROM config WHERE key = ?;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return defaultVal;
    sqlite3_bind_text(stmt, 1, key.c_str(), -1, SQLITE_TRANSIENT);
    std::string result = defaultVal;
    if (executeWithRetry(stmt) == SQLITE_ROW) {
        const char* val = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        if (val) result = val;
    }
    sqlite3_finalize(stmt);
    return result;
}

bool SqliteManager::getAllEstudiantesConTemplate(std::vector<Estudiante>& estudiantes) {
    const char* sql = "SELECT documento, nombre, telefono_acudiente, nombre_acudiente, huella_id, template_huella, school_id FROM estudiantes WHERE template_huella IS NOT NULL AND length(template_huella) > 0;";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return false;
    while (executeWithRetry(stmt) == SQLITE_ROW) {
        Estudiante est;
        est.documento = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 0));
        const char* nombreCol = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 1));
        est.nombre = nombreCol ? decField(nombreCol) : "";
        const char* tel = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 2));
        est.telefono_acudiente = tel ? decField(tel) : "";
        const char* nom = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 3));
        est.nombre_acudiente = nom ? decField(nom) : "";
        est.huella_id = static_cast<uint32_t>(sqlite3_column_int(stmt, 4));
        const char* encryptedTemplate = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 5));
        if (encryptedTemplate) {
            std::string decrypted = Encryption::getInstance().decrypt(encryptedTemplate);
            if (!decrypted.empty()) {
                est.template_huella.assign(decrypted.begin(), decrypted.end());
            }
        }
        // school_id (columna 6) — multi-tenancy
        const char* sid = reinterpret_cast<const char*>(sqlite3_column_text(stmt, 6));
        est.school_id = sid ? sid : "";
        if (!est.template_huella.empty()) {
            estudiantes.push_back(std::move(est));
        }
    }
    sqlite3_finalize(stmt);
    return true;
}

// FIX C3: Purgar registros antiguos de audit_trail para evitar llenar la SD card.
// - synced=1 (enviados OK): borrar tras `daysSynced` días (default 30)
// - synced=-1 (DLQ): borrar tras `daysDlq` días (default 90, más conservador)
// Retorna el número total de filas eliminadas.
int SqliteManager::purgeOldAuditTrail(int daysSynced, int daysDlq) {
    int totalDeleted = 0;

    // Borrar registros sincronizados exitosamente
    {
        const char* sql = "DELETE FROM audit_trail WHERE synced = 1 AND fecha < datetime('now', ?);";
        sqlite3_stmt* stmt;
        if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
            std::string delta = "-" + std::to_string(daysSynced) + " days";
            sqlite3_bind_text(stmt, 1, delta.c_str(), -1, SQLITE_TRANSIENT);
            if (executeWithRetry(stmt) == SQLITE_DONE) {
                totalDeleted += sqlite3_changes(db);
            }
            sqlite3_finalize(stmt);
        }
    }

    // Borrar registros en DLQ (synced=-1) tras período más largo
    {
        const char* sql = "DELETE FROM audit_trail WHERE synced = -1 AND fecha < datetime('now', ?);";
        sqlite3_stmt* stmt;
        if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
            std::string delta = "-" + std::to_string(daysDlq) + " days";
            sqlite3_bind_text(stmt, 1, delta.c_str(), -1, SQLITE_TRANSIENT);
            if (executeWithRetry(stmt) == SQLITE_DONE) {
                totalDeleted += sqlite3_changes(db);
            }
            sqlite3_finalize(stmt);
        }
    }

    if (totalDeleted > 0) {
        LOG_INFO("[C3] Purged {} old audit_trail records (synced>{}d, dlq>{}d)",
                 totalDeleted, daysSynced, daysDlq);
    }
    return totalDeleted;
}

// F-06/F-13: métricas de cola para telemetría
int SqliteManager::getPendingAuditCount() {
    const char* sql = "SELECT COUNT(*) FROM audit_trail WHERE synced = 0;";
    sqlite3_stmt* stmt;
    int n = 0;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        if (executeWithRetry(stmt) == SQLITE_ROW) n = sqlite3_column_int(stmt, 0);
        sqlite3_finalize(stmt);
    }
    return n;
}

int SqliteManager::getDlqCount() {
    const char* sql = "SELECT COUNT(*) FROM audit_trail WHERE synced = -1;";
    sqlite3_stmt* stmt;
    int n = 0;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) == SQLITE_OK) {
        if (executeWithRetry(stmt) == SQLITE_ROW) n = sqlite3_column_int(stmt, 0);
        sqlite3_finalize(stmt);
    }
    return n;
}

// F-13: reintento de largo plazo — reactiva hasta `limit` registros de la DLQ.
int SqliteManager::requeueDlqItems(int limit) {
    const char* sql = "UPDATE audit_trail SET synced = 0, attempts = 0 WHERE id IN (SELECT id FROM audit_trail WHERE synced = -1 ORDER BY id LIMIT ?);";
    sqlite3_stmt* stmt;
    if (sqlite3_prepare_v3(db, sql, -1, 0, &stmt, nullptr) != SQLITE_OK) return 0;
    sqlite3_bind_int(stmt, 1, limit);
    int n = (executeWithRetry(stmt) == SQLITE_DONE) ? sqlite3_changes(db) : 0;
    sqlite3_finalize(stmt);
    if (n > 0) LOG_INFO("[DLQ] Requeued {} dead-lettered audit records for retry", n);
    return n;
}

// FIX C3: VACUUM para reclamar espacio físico tras purgado
bool SqliteManager::vacuum() {
    // VACUUM no puede ejecutarse dentro de transacción
    int rc = sqlite3_exec(db, "VACUUM;", nullptr, nullptr, nullptr);
    if (rc != SQLITE_OK) {
        LOG_WARN("[C3] VACUUM failed: {}", sqlite3_errmsg(db));
        return false;
    }
    LOG_INFO("[C3] VACUUM completed — disk space reclaimed");
    return true;
}
