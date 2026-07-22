/**
 * =============================================================================
 * DevStubHttpClient.cpp — Implementación stub de cliente HTTP.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementación de IHttpClient que simula una respuesta JSON exitosa sin
 *   realizar conexión de red. Permite testear lógica que depende de CloudManager.
 */

#include "hardware/dev_stub/DevStubHttpClient.h"
#include "utils/Logger.h"

bool DevStubHttpClient::postRequest(const std::string& url,
                                     const std::string& /*body*/,
                                     const std::map<std::string, std::string>& /*headers*/,
                                     std::string& response) {
    LOG_INFO("[STUB-HTTP] POST {}", url);
    response = R"({"status":"ok","stub":true})";
    return true;
}
