#include "interoperabilidad/audit_trail.h"
#include "base_de_datos/sqlite_manager.h"

bool AuditTrail::logEvent(const std::string& studentDoc, const std::string& event) {
    // FIX (SRE-3): Propagar el estado de persistencia local.
    // Si SQLite falla (disco lleno, corrupto, RO), el acceso biométrico debe bloquearse.
    return SqliteManager::getInstance().saveAudit(studentDoc, event);
}
