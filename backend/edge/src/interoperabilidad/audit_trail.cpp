/**
 * =============================================================================
 * audit_trail.cpp — Implementación del registro de auditoría local.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementa AuditTrail::logEvent() delegando en SqliteManager::saveAudit.
 *   Devuelve el resultado de la persistencia para que el caller (main.cpp)
 *   pueda bloquear el acceso si la base local falla.
 */

#include "interoperabilidad/audit_trail.h"
#include "base_de_datos/sqlite_manager.h"

bool AuditTrail::logEvent(const std::string& studentDoc, const std::string& event) {
    // Propagar el estado de persistencia local.
    // Si SQLite falla (disco lleno, corrupto, RO), el acceso biométrico debe bloquearse.
    return SqliteManager::getInstance().saveAudit(studentDoc, event);
}
