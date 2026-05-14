#pragma once
#include <string>

class AuditTrail {
public:
    static void logEvent(const std::string& studentDoc, const std::string& event);
};
