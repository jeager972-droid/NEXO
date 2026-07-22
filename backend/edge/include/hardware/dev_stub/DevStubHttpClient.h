#pragma once
#include "hal/IHttpClient.h"

/**
 * =============================================================================
 * DevStubHttpClient.h — Implementación stub de cliente HTTP.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Implementación de IHttpClient que simula una respuesta HTTP exitosa sin
 *   realizar conexión de red. Permite testear CloudManager offline.
 */
class DevStubHttpClient : public IHttpClient {
public:
    bool postRequest(const std::string& url,
                     const std::string& body,
                     const std::map<std::string, std::string>& headers,
                     std::string& response) override;
};
