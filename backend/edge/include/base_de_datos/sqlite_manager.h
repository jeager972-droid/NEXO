#pragma once
#include <string>
#include <vector>
#include <cstdint>
#include <sqlite3.h>

/**
 * =============================================================================
 * sqlite_manager.h — Interfaz singleton de la base de datos local SQLite.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Mantiene el almacenamiento local del dispositivo edge: estudiantes,
 *   patrones de asistencia, inasistencias (legacy), audit_trail para envíos
 *   pendientes y tabla config (clave/valor). Todos los templates de huella
 *   se cifran con Encryption antes de guardarse y se descifran al leerse.
 *
 * TABLAS:
 *   - estudiantes: documento (PK), nombre, tel/nombre acudiente, huella_id,
 *     template_huella (base64 cifrado).
 *   - patrones: resumen de ingresos tempranos/tardes por estudiante.
 *   - inasistencias: legacy (no implementado en cpp).
 *   - audit_trail: eventos locales pendientes de sincronizar con la nube.
 *   - config: pares clave/valor (AES key, token, etc.).
 *
 * DEPENDENCIAS:
 *   - sqlite3
 *   - base_de_datos/encryption.h (cifrado de templates)
 *   - openssl/rand.h (IV aleatorio para templates)
 */
struct Estudiante {
    std::string documento;
    std::string nombre;
    std::string telefono_acudiente;
    std::string nombre_acudiente;
    uint32_t huella_id = 0;
    std::vector<uint8_t> template_huella;
};

struct AuditRecord {
    int id;
    std::string documento;
    std::string event;
    int timestamp;
    int attempts;
};

class SqliteManager {
public:
    static SqliteManager& getInstance() {
        static SqliteManager instance;
        return instance;
    }

    bool initialize(const std::string& dbPath = "nexo_edge.db");
    void close();
    bool factoryReset();

    // Students
    bool saveEstudiante(const Estudiante& est);
    bool getEstudianteByDocumento(const std::string& doc, Estudiante& est);
    bool getEstudianteByHuellaID(uint32_t huellaId, Estudiante& est);
    bool deleteEstudiante(const std::string& doc);
    uint32_t getNextHuellaID();

    // Attendance patterns (UPSERT)
    bool updatePattern(const std::string& documento, bool temprano, bool tarde);
    bool getPatternSummary(const std::string& documento, int& temprano, int& tarde, int& total);

    // PAE
    bool savePAE(const std::string& documento, bool recibido);

    // Absence tracking
    bool checkInasistencia(const std::string& documento);
    bool deleteInasistencia(const std::string& documento);

    // Audit trail
    bool saveAudit(const std::string& documento, const std::string& event);
    bool getPendingAudits(std::vector<AuditRecord>& audits);
    bool clearAudit(int id);
    bool clearAudit(const std::string& documento, const std::string& event); // Keep for compatibility
    bool incrementAuditAttempt(int id);
    bool markAuditError(int id);

    // Config KV store
    bool setConfig(const std::string& key, const std::string& value);
    std::string getConfig(const std::string& key, const std::string& defaultVal = "");

    sqlite3* getDB() { return db; }

private:
    SqliteManager() = default;
    ~SqliteManager() { close(); }
    sqlite3* db = nullptr;
    bool createTables();
};
