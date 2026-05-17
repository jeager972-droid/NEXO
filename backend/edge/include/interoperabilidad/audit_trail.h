#pragma once
#include <string>

class AuditTrail {
public:
    // FIX (SRE-3): Retorna bool para que el caller pueda detectar
    // fallos de persistencia local y bloquear el acceso.
    static bool logEvent(const std::string& studentDoc, const std::string& event);
};
