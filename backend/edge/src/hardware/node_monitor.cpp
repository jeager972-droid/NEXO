/**
 * =============================================================================
 * node_monitor.cpp — Implementación del monitoreo físico del nodo edge.
 * =============================================================================
 * PowerMonitor (UPS): lee el power_supply del UPS HAT por sysfs. Fuentes
 * soportadas: cualquier driver que exponga status+capacity (PiSugar, UPS HAT
 * EP0114 vía fuel gauge I2C, o systemd-logind). Si la ruta no existe → UNKNOWN.
 *
 * CellularManager (M2M): lee operstate del interfaz wwan y parsea mmcli.
 * El parser es puro para test con fixtures reales de mmcli.
 *
 * NodeTelemetry: agrega métricas de reloj, disco, cola, DLQ,
 * temperatura del SoC y estado eléctrico/celular para el ping.
 * =============================================================================
 */

#include "hardware/node_monitor.h"
#include "utils/Logger.h"
#include <fstream>
#include <sstream>
#include <sys/statvfs.h>
#include <cctype>
#include <algorithm>

const char* powerStateToString(PowerState s) {
    switch (s) {
        case PowerState::MAINS:        return "MAINS";
        case PowerState::BATTERY:      return "BATTERY";
        case PowerState::LOW_BATTERY:  return "LOW_BATTERY";
        case PowerState::CRITICAL:     return "CRITICAL";
        default:                       return "UNKNOWN";
    }
}

// ──────────────────────────── PowerMonitor ────────────────────────────

std::string PowerMonitor::readFile(const std::string& name) {
    if (m_sysfsDir.empty()) return "";
    std::ifstream f(m_sysfsDir + "/" + name);
    if (!f.is_open()) return "";
    std::string v;
    std::getline(f, v);
    // trim
    v.erase(std::find_if(v.rbegin(), v.rend(), [](unsigned char c){ return !std::isspace(c); }).base(), v.end());
    return v;
}

int PowerMonitor::readCapacity() {
    std::string cap = readFile("capacity");
    if (cap.empty()) return -1;
    try { return std::stoi(cap); } catch (...) { return -1; }
}

PowerState PowerMonitor::readState() {
    std::string status = readFile("status"); // Charging | Discharging | Full | Not charging
    if (status.empty()) return PowerState::UNKNOWN;
    int cap = readCapacity();

    std::string s = status;
    std::transform(s.begin(), s.end(), s.begin(), [](unsigned char c){ return std::tolower(c); });
    bool onBattery = (s.find("discharg") != std::string::npos);

    if (!onBattery) return PowerState::MAINS;
    if (cap >= 0 && cap <= 8)  return PowerState::CRITICAL;
    if (cap >= 0 && cap <= 25) return PowerState::LOW_BATTERY;
    return PowerState::BATTERY;
}

bool PowerMonitor::shouldShutdown(int minPct) {
    PowerState st = readState();
    if (st == PowerState::CRITICAL) return true;
    if (st == PowerState::BATTERY || st == PowerState::LOW_BATTERY) {
        int cap = readCapacity();
        if (cap >= 0 && cap <= minPct) return true;
    }
    return false;
}

bool PowerMonitor::pollTransition(PowerState& newState) {
    newState = readState();
    if (newState == PowerState::UNKNOWN) return false; // no concluyente
    if (newState != m_last && m_last != PowerState::UNKNOWN) {
        m_last = newState;
        return true;
    }
    m_last = newState;
    return false;
}

// ──────────────────────────── TamperMonitor ────────────────────────────

bool TamperMonitor::isOpen() {
    if (m_valuePath.empty()) return false;
    std::ifstream f(m_valuePath);
    if (!f.is_open()) return false;
    std::string v;
    std::getline(f, v);
    v.erase(std::find_if(v.rbegin(), v.rend(), [](unsigned char c){ return !std::isspace(c); }).base(), v.end());
    return v == "1" || v == "open" || v == "OPEN";
}

bool TamperMonitor::pollOpen() {
    bool now = isOpen();
    bool edge = now && !m_lastOpen;
    m_lastOpen = now;
    return edge;
}

// ──────────────────────────── CellularManager ────────────────────────────

std::string CellularManager::readOperstate() {
    if (m_ifName.empty()) return "";
    std::ifstream f(m_sysNetDir + "/" + m_ifName + "/operstate");
    if (!f.is_open()) return "";
    std::string v;
    std::getline(f, v);
    v.erase(std::find_if(v.rbegin(), v.rend(), [](unsigned char c){ return !std::isspace(c); }).base(), v.end());
    return v;
}

CellularStatus CellularManager::readStatus() {
    CellularStatus st;
    std::string op = readOperstate();
    st.interfaceUp = (op == "up");
    // Sin mmcli disponible en el dispositivo → solo interfaceUp se conoce;
    // signalPct/registered quedan en su valor por defecto (-1/false).
    return st;
}

void CellularManager::parseMmcliOutput(const std::string& output, CellularStatus& st) {
    std::istringstream in(output);
    std::string line;
    while (std::getline(in, line)) {
        // "  |   status: | state: 'registered'" o "  |          state: 'registered'"
        if (line.find("'registered'") != std::string::npos || line.find("'connected'") != std::string::npos) {
            st.registered = true;
        }
        // "  | signal quality: | value: '72'" o "signal quality: '72' (recent)"
        if (line.find("signal quality") != std::string::npos) {
            size_t q1 = line.find('\'', line.find("signal quality"));
            if (q1 != std::string::npos) {
                size_t d0 = line.find_first_of("0123456789", q1);
                if (d0 != std::string::npos) {
                    size_t d1 = line.find_first_not_of("0123456789", d0);
                    try { st.signalPct = std::stoi(line.substr(d0, d1 - d0)); } catch (...) {}
                }
            }
        }
        if (line.find("operator name:") != std::string::npos) {
            size_t p1 = line.find('\'');
            size_t p2 = line.find('\'', p1 + 1);
            if (p1 != std::string::npos && p2 != std::string::npos) {
                st.carrier = line.substr(p1 + 1, p2 - p1 - 1);
            }
        }
        if (line.find("access tech:") != std::string::npos) {
            size_t p1 = line.find('\'');
            size_t p2 = line.find('\'', p1 + 1);
            if (p1 != std::string::npos && p2 != std::string::npos) {
                st.tech = line.substr(p1 + 1, p2 - p1 - 1);
            }
        }
    }
}

// ──────────────────────────── NodeTelemetry ────────────────────────────

int NodeTelemetry::diskFreeMb() {
    struct statvfs st{};
    if (statvfs(m_dbDir.c_str(), &st) != 0) return -1;
    return static_cast<int>((st.f_bavail * static_cast<uint64_t>(st.f_frsize)) / (1024 * 1024));
}

int NodeTelemetry::cpuTempC() {
    if (m_thermalPath.empty()) return -1;
    std::ifstream f(m_thermalPath);
    if (!f.is_open()) return -1;
    long milli = 0;
    f >> milli;
    if (milli <= 0) return -1;
    return static_cast<int>(milli / 1000); // millicelsius → celsius
}

nlohmann::json NodeTelemetry::toJson(const NodeMetrics& m) {
    nlohmann::json t;
    t["clock_drift_s"]  = m.clock_drift_s;
    t["disk_free_mb"]   = m.disk_free_mb;
    t["pending_events"] = m.pending_events;
    t["dlq_count"]      = m.dlq_count;
    if (m.cpu_temp_c >= 0) t["cpu_temp_c"] = m.cpu_temp_c;
    if (m.tamper_open) t["tamper_open"] = true; // solo se reporta cuando abierto
    t["power_state"]    = powerStateToString(m.power_state);
    t["cell"] = {
        {"interface_up", m.cell.interfaceUp},
        {"registered",   m.cell.registered},
        {"signal_pct",   m.cell.signalPct},
        {"carrier",      m.cell.carrier},
        {"tech",         m.cell.tech},
    };
    return t;
}
