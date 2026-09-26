#pragma once

#include <cstdint>
#include <string>
#include <functional>
#include <nlohmann/json.hpp>

/**
 * =============================================================================
 * node_monitor.h — Monitoreo físico del nodo edge.
 * =============================================================================
 * RESPONSABILIDAD:
 *   PowerMonitor      — lee el estado eléctrico vía sysfs (/sys/class/power_supply)
 *                       o un archivo simulado; detecta MAINS→BATTERY (POWER_BACKUP),
 *                       BATTERY→MAINS (POWER_RESTORED), LOW_BATTERY y CRITICAL
 *                       (shutdown ordenado). Rutas inyectables → testable sin UPS.
 *   CellularManager   — lee estado del módem M2M: operstate del interfaz
 *                       (/sys/class/net/<if>/operstate), registro y calidad de
 *                       señal parseando salida de mmcli/qmicli (función pura).
 *   NodeTelemetry     — agrega clock_drift_s, disk_free_mb (statvfs),
 *                       pending_events, dlq_count, cpu_temp_c, power_state,
 *                       signal_pct → JSON para /devices/ping.
 *
 * SIMULACIÓN INTEGRADA: todas las rutas de sysfs se inyectan por constructor —
 * los tests crean archivos fixture en /tmp y ejercen transiciones reales del
 * estado físico sin hardware.
 * =============================================================================
 */

// ── Monitor de energía / UPS ──────────────────────────────────────────────

enum class PowerState { MAINS, BATTERY, LOW_BATTERY, CRITICAL, UNKNOWN };

const char* powerStateToString(PowerState s);

class PowerMonitor {
public:
    // sysfsDir: carpeta tipo /sys/class/power_supply/<ups> con 'status' y 'capacity'.
    // Si está vacío o no existe → estado UNKNOWN (sin UPS detectable).
    explicit PowerMonitor(std::string sysfsDir = "")
        : m_sysfsDir(std::move(sysfsDir)) {}

    // Lee el estado actual (parsea archivos; nunca lanza).
    PowerState readState();
    // Capacidad de batería 0..100 (-1 si desconocida).
    int readCapacity();
    // true si debe iniciar shutdown ordenado (CRITICAL o batería ≤ minPct en BATTERY).
    bool shouldShutdown(int minPct = 8);

    // Detecta transición desde la última lectura; devuelve el nuevo estado si cambió.
    bool pollTransition(PowerState& newState);
    PowerState lastState() const { return m_last; }

private:
    std::string m_sysfsDir;
    PowerState m_last = PowerState::UNKNOWN;
    std::string readFile(const std::string& name);
};

// ── Monitor de apertura del gabinete (tamper) ──────────────────────────────
// Lee el estado del microswitch del gabinete vía un archivo inyectable —
// en hardware real es /sys/class/gpio/gpio<N>/value o un GPIO de libgpiod;
// en tests/simulación es un archivo fixture con "0" (cerrado) / "1" (abierto).
class TamperMonitor {
public:
    // valuePath: archivo cuyo contenido "1" indica gabinete abierto.
    // Vacío o inexistente → no concluyente (sin sensor, nunca alerta).
    explicit TamperMonitor(std::string valuePath = "")
        : m_valuePath(std::move(valuePath)) {}

    // true si el gabinete está abierto ahora.
    bool isOpen();
    // Detecta flanco cerrado→abierto; true solo en la transición (dispara una vez).
    bool pollOpen();

private:
    std::string m_valuePath;
    bool m_lastOpen = false;
};

// ── Gestor de conectividad celular M2M ─────────────────────────────────────

struct CellularStatus {
    bool interfaceUp = false;     // operstate == up
    bool registered  = false;     // red registrada (mmcli)
    int  signalPct   = -1;        // 0..100
    std::string carrier;
    std::string tech;             // LTE/UMTS/GSM si reportado
};

class CellularManager {
public:
    // ifName: interfaz wwan (p. ej. "wwan0"); sysNetDir inyectable para tests.
    explicit CellularManager(std::string ifName = "", std::string sysNetDir = "/sys/class/net")
        : m_ifName(std::move(ifName)), m_sysNetDir(std::move(sysNetDir)) {}

    CellularStatus readStatus();
    // Parser puro de salida `mmcli -m 0` (inyectable en tests).
    static void parseMmcliOutput(const std::string& output, CellularStatus& st);
    // Watchdog: ¿el enlace está caído o sin señal?
    static bool isDown(const CellularStatus& st) { return !st.interfaceUp || (!st.registered && st.signalPct < 0); }

private:
    std::string m_ifName;
    std::string m_sysNetDir;
    std::string readOperstate();
};

// ── Recolector de telemetría del nodo ────────────────────────────────────────

struct NodeMetrics {
    int    clock_drift_s = 0;     // |local - servidor| segundos (set externo)
    int    disk_free_mb  = -1;    // statvfs del directorio de la BD
    int    pending_events = 0;    // audit_trail synced=0
    int    dlq_count      = 0;    // audit_trail synced=-1
    int    cpu_temp_c     = -1;   // thermal_zone0 (sin ventilador)
    bool   tamper_open    = false; // apertura física del gabinete
    PowerState    power_state = PowerState::UNKNOWN;
    CellularStatus cell;
};

class NodeTelemetry {
public:
    NodeTelemetry(std::string dbDir = ".", std::string thermalPath = "")
        : m_dbDir(std::move(dbDir)), m_thermalPath(std::move(thermalPath)) {}

    int diskFreeMb();
    int cpuTempC();
    // Ensambla el JSON de telemetría para /devices/ping.
    nlohmann::json toJson(const NodeMetrics& m);

private:
    std::string m_dbDir;
    std::string m_thermalPath;
};
