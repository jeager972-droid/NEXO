#pragma once

#include <string>
#include <nlohmann/json.hpp>

class ConfigManager {
public:
    static ConfigManager& getInstance() {
        static ConfigManager instance;
        return instance;
    }

    bool loadConfig(const std::string& path = "config.json");

    std::string getString(const std::string& key, const std::string& defaultVal = "") const;
    int getInt(const std::string& key, int defaultVal = 0) const;
    bool getBool(const std::string& key, bool defaultVal = false) const;

    // Convenience accessors
    std::string getApiUrl() const { return getString("api_url", "https://nexo-production-dbe3.up.railway.app/api.php"); }
    std::string getDbPath() const { return getString("db_path", "nexo_edge.db"); }
    std::string getLogPath() const { return getString("log_path", "nexo-edge.log"); }
    std::string getLogLevel() const { return getString("log_level", "info"); }
    std::string getDeviceId() const { return getString("device_id", "NEXO-EDGE-001"); }
    std::string getDeviceToken() const { return getString("device_token", ""); }
    int getMatchThreshold() const { return getInt("sensor_match_threshold", 45); }

private:
    ConfigManager() = default;
    nlohmann::json m_config;
};
