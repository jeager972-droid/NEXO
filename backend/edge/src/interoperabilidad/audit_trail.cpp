#include "interoperabilidad/audit_trail.h"
#include "base_de_datos/sqlite_manager.h"

void AuditTrail::logEvent(const std::string& studentDoc, const std::string& event) {
    SqliteManager::getInstance().saveAudit(studentDoc, event);
}
