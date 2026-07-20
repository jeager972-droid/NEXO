#pragma once
#include <string>
#include "base_de_datos/encryption.h"
#include "hal/IHttpClient.h"

/**
 * CloudManager — Linux-native cloud sync via libcurl.
 * Target: Raspberry Pi 4 → Railway (Postgres).
 * URL leída de /opt/nexo/config.json (api_url) o env NEXO_API_URL.
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
};
