#include "base_de_datos/cloud_manager.h"
#include "utils/Logger.h"
#include <curl/curl.h>
#include <string>
#include <nlohmann/json.hpp>
#include <fstream>
#include <cstdlib>

CloudManager::CloudManager() : m_apiUrl(loadApiUrl()) {}

std::string CloudManager::loadApiUrl() {
    const char* envUrl = std::getenv("NEXO_API_URL");
    if (envUrl && std::strlen(envUrl) > 0) return envUrl;
    std::ifstream f("/opt/nexo/config.json");
    if (f.good()) {
        try {
            nlohmann::json j;
            f >> j;
            if (j.contains("api_url") && j["api_url"].is_string()) return j["api_url"];
        } catch (...) { LOG_WARN("Failed to parse /opt/nexo/config.json"); }
    }
    LOG_ERROR("NEXO_API_URL not set and config.json missing. Edge cannot sync.");
    return "";
}

static size_t WriteCallback(void* contents, size_t size, size_t nmemb, void* userp) {
    static_cast<std::string*>(userp)->append(static_cast<char*>(contents), size * nmemb);
    return size * nmemb;
}

static bool curlPost(const std::string& url, const std::string& postData,
                     const std::string& authToken, std::string& response) {
    CURL* curl = curl_easy_init();
    if (!curl) { LOG_ERROR("curl_easy_init failed"); return false; }

    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    headers = curl_slist_append(headers, "User-Agent: NEXO-Edge-RPi4/2.0");
    headers = curl_slist_append(headers, "Accept: application/json");
    if (!authToken.empty())
        headers = curl_slist_append(headers, ("X-NEXO-TOKEN: " + authToken).c_str());

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, postData.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);

    CURLcode res = curl_easy_perform(curl);
    long httpCode = 0;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (res != CURLE_OK) {
        LOG_ERROR("curl failed: {}", curl_easy_strerror(res));
        return false;
    }
    // Accept 200 OK and 202 Accepted (backend returns 202 for edge ingest)
    if (httpCode != 200 && httpCode != 202) {
        LOG_ERROR("Cloud sync HTTP {}: {}", httpCode, response.substr(0, 200));
        return false;
    }
    return true;
}

static bool httpClientPost(IHttpClient* client, const std::string& url,
                          const std::string& postData, const std::string& authToken,
                          std::string& response) {
    if (!client) return false;

    std::map<std::string, std::string> headers;
    headers["Content-Type"] = "application/json";
    headers["User-Agent"] = "NEXO-Edge-RPi4/2.0";
    headers["Accept"] = "application/json";
    if (!authToken.empty()) {
        headers["X-NEXO-TOKEN"] = authToken;
    }

    return client->postRequest(url, postData, headers, response);
}

// FIX: Safe JSON serialization using nlohmann
static std::string jsonObj(std::initializer_list<std::pair<std::string, std::string>> kvs) {
    nlohmann::json j;
    for (auto& [k, v] : kvs) {
        j[k] = v;
    }
    return j.dump();
}

std::string CloudManager::buildAuthenticatedRequest(const std::string& jsonData, int instId) {
    Encryption& crypto = Encryption::getInstance();
    if (!crypto.isKeyProvisioned()) { LOG_ERROR("AES key not provisioned"); return ""; }
    std::string encrypted = crypto.encrypt(jsonData);
    if (encrypted.empty()) { LOG_ERROR("Encryption failed"); return ""; }

    nlohmann::json body;
    body["inst_id"] = instId;
    body["payload"] = encrypted;
    
    std::string token = crypto.getToken();
    if (crypto.isTokenProvisioned()) {
        body["token"] = token;
    }
    
    return body.dump();
}

bool CloudManager::syncRecord(const std::string& jsonData) {
    std::string body = buildAuthenticatedRequest(jsonData, m_instId);
    if (body.empty()) return false;
    std::string response;
    bool ok;

    // Use IHttpClient stub if available (dev mode), otherwise use real libcurl
    if (m_httpClient) {
        ok = httpClientPost(m_httpClient, m_apiUrl, body, Encryption::getInstance().getToken(), response);
    } else {
        ok = curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), response);
    }

    if (ok) LOG_DEBUG("Cloud sync OK"); else LOG_ERROR("Cloud sync failed");
    return ok;
}

bool CloudManager::deleteStudent(const std::string& doc) {
    std::string json = jsonObj({{"action","DELETE_STUDENT"},{"doc",doc}});
    std::string body = buildAuthenticatedRequest(json, m_instId);
    if (body.empty()) return false;
    std::string resp;
    return curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp)
        && resp.find("\"status\":\"ok\"") != std::string::npos;
}

bool CloudManager::registerStaff(const std::string& doc, const std::string& nombre,
                                  const std::string& tel, const std::string& rol,
                                  const std::string& jornada) {
    std::string json = jsonObj({{"action","REGISTER_STAFF"},{"doc",doc},
        {"nombre",nombre},{"tel",tel},{"rol",rol},{"jornada",jornada}});
    std::string body = buildAuthenticatedRequest(json, m_instId);
    if (body.empty()) return false;
    std::string resp;
    return curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp)
        && resp.find("\"status\":\"ok\"") != std::string::npos;
}

bool CloudManager::wipeInstitution(int instId) {
    std::string json = jsonObj({{"action","WIPE_INSTITUTION"}});
    std::string body = buildAuthenticatedRequest(json, instId);
    if (body.empty()) return false;
    std::string resp;
    return curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp)
        && resp.find("\"status\":\"ok\"") != std::string::npos;
}

int CloudManager::verifyInstitution(const std::string& nombre) {
    std::string json = jsonObj({{"action","VERIFY_INSTITUTION"},{"nombre",nombre}});
    std::string body = buildAuthenticatedRequest(json, 0);
    if (body.empty()) return -1;
    std::string resp;
    if (!curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp)) return -1;
    auto pos = resp.find("\"inst_id\":");
    if (pos == std::string::npos) return -1;
    return std::atoi(resp.c_str() + pos + 10);
}

bool CloudManager::verifyGroup(int instId, const std::string& salon) {
    std::string json = jsonObj({{"action","VERIFY_GROUP"},{"salon",salon}});
    std::string body = buildAuthenticatedRequest(json, instId);
    if (body.empty()) return false;
    std::string resp;
    return curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp)
        && resp.find("\"status\":\"ok\"") != std::string::npos;
}

bool CloudManager::registerStudent(const std::string& doc, const std::string& nombre,
                                    const std::string& tel, const std::string& salon,
                                    const std::string& parent_doc, const std::string& parent_name) {
    std::string json = jsonObj({{"action","REGISTER_STUDENT"},{"doc",doc},
        {"nombre",nombre},{"parent_tel",tel},{"parent_doc",parent_doc},
        {"parent_name",parent_name},{"salon",salon}});
    std::string body = buildAuthenticatedRequest(json, m_instId);
    if (body.empty()) return false;
    std::string resp;
    bool ok = curlPost(m_apiUrl, body, Encryption::getInstance().getToken(), resp);
    if (ok && resp.find("\"status\":\"ok\"") != std::string::npos) return true;
    if (!resp.empty()) LOG_WARN("Server response not OK: {}", resp);
    return false;
}
