#include "utils/ConfigManager.h"
#include "utils/Logger.h"
#include <fstream>

bool ConfigManager::loadConfig(const std::string& path) {
    std::ifstream file(path);
    if (!file.is_open()) {
        LOG_WARN("Config file '{}' not found, using defaults", path);
        m_config = nlohmann::json::object();
        return false;
    }
    try {
        m_config = nlohmann::json::parse(file);
        LOG_INFO("Config loaded from '{}'", path);
        return true;
    } catch (const nlohmann::json::exception& e) {
        LOG_ERROR("Config parse error: {}", e.what());
        m_config = nlohmann::json::object();
        return false;
    }
}

std::string ConfigManager::getString(const std::string& key, const std::string& defaultVal) const {
    if (m_config.contains(key) && m_config[key].is_string())
        return m_config[key].get<std::string>();
    return defaultVal;
}

int ConfigManager::getInt(const std::string& key, int defaultVal) const {
    if (m_config.contains(key) && m_config[key].is_number_integer())
        return m_config[key].get<int>();
    return defaultVal;
}

bool ConfigManager::getBool(const std::string& key, bool defaultVal) const {
    if (m_config.contains(key) && m_config[key].is_boolean())
        return m_config[key].get<bool>();
    return defaultVal;
}
