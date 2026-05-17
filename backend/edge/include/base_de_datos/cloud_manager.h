#pragma once
#include <string>
#include "base_de_datos/encryption.h"

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
    bool wipeInstitution(int instId);
    int  verifyInstitution(const std::string& nombre);
    bool verifyGroup(int instId, const std::string& salon);

    void setInstitutionId(int id) { m_instId = id; }
    int  getInstitutionId() const { return m_instId; }

private:
    CloudManager();
    std::string m_apiUrl;
    int m_instId = -1;

    std::string buildAuthenticatedRequest(const std::string& jsonData, int instId);
    std::string loadApiUrl();
};
