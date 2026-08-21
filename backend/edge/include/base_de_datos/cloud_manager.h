#pragma once
#include <string>
#include "base_de_datos/encryption.h"
#include "hal/IHttpClient.h"

/**
 * =============================================================================
 * cloud_manager.h — Interfaz del gestor de sincronización con la nube (CloudManager).
 * =============================================================================
 * RESPONSABILIDAD:
 *   Interfaz singleton para sincronizar registros de asistencia y ejecutar
 *   comandos cloud (registro/eliminación de estudiantes/personal, wipe,
 *   verificación de instituciones/grupos). Construye requests autenticados
 *   cifrando el JSON de payload con AES-256-GCM y enviándolo vía libcurl
 *   (o un IHttpClient inyectado para testing).
 *
 * FLUJO TÍPICO (syncRecord):
 *   syncRecord(jsonData)
 *        │
 *        ▼
 *   buildAuthenticatedRequest(jsonData, instId)
 *        │  ├─ Cifra payload con Encryption (AES-256-GCM)
 *        │  └─ Añade inst_id, token opcional, payload base64
 *        ▼
 *   curlPost / httpClientPost(m_httpClient)
 *        │  ├─ Headers: Content-Type, User-Agent, X-NEXO-TOKEN
 *        │  └─ Verifica HTTP 200 o 202
 *        ▼
 *   retorna ok/fail
 *
 * DEPENDENCIAS:
 *   - base_de_datos/encryption.h  : AES-256-GCM y token.
 *   - hal/IHttpClient.h           : abstracción HTTP para dev stubs/tests.
 *   - libcurl                     : transporte real en Linux/RPi4.
 *
 * CONFIGURACIÓN:
 *   - NEXO_API_URL (env) o /opt/nexo/config.json api_url.
 *   - m_instId seteado desde setInstitutionId().
 */
class CloudManager {
public:
    static CloudManager& getInstance() {
        static CloudManager instance;
        return instance;
    }

    bool syncRecord(const std::string& jsonData);

    bool registerStudent(const std::string& doc, const std::string& nombre, const std::string& tel,
                         const std::string& salon, const std::string& parent_doc, const std::string& parent_name);
    bool registerStudentWithFingerprint(const std::string& doc, const std::string& nombre,
                                        const std::string& tel, uint32_t huellaId);
    bool registerStaff(const std::string& doc, const std::string& nombre, const std::string& tel,
                       const std::string& rol, const std::string& jornada);
    bool deleteStudent(const std::string& doc);
    bool wipeInstitution(const std::string& instId);
    std::string verifyInstitution(const std::string& nombre);
    bool verifyGroup(const std::string& instId, const std::string& salon);

    void setInstitutionId(const std::string& id) { m_instId = id; }
    std::string getInstitutionId() const { return m_instId; }

    void setHttpClient(IHttpClient* client) { m_httpClient = client; }

private:
    CloudManager();
    std::string m_apiUrl;
    std::string m_instId = "";
    IHttpClient* m_httpClient = nullptr; // nullptr = usar libcurl real

    std::string buildAuthenticatedRequest(const std::string& jsonData, const std::string& instId);
    std::string loadApiUrl();
    std::string getIngestUrl() const;
};
