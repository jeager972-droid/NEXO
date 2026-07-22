#pragma once
#include <string>

/**
 * =============================================================================
 * audit_trail.h — Wrapper del registro de auditoría local.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Facade estática para registrar eventos biométricos en la base de datos
 *   SQLite. Devuelve bool para que el caller pueda detectar fallos de
 *   persistencia local y bloquear el acceso (SRE-3).
 */
class AuditTrail {
public:
    // FIX (SRE-3): Retorna bool para que el caller pueda detectar
    // fallos de persistencia local y bloquear el acceso.
    static bool logEvent(const std::string& studentDoc, const std::string& event);
};
