#pragma once
#include <string>
#include <map>

/**
 * =============================================================================
 * IHttpClient.h — Interfaz abstracta de cliente HTTP.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Abstracción para realizar POST HTTP con headers personalizados. Permite
 *   reemplazar libcurl por un stub en pruebas unitarias o entornos sin red.
 *
 * IMPLEMENTACIONES:
 *   - DevStubHttpClient : responde {"status":"ok","stub":true} sin salir.
 *   - CloudManager::curlPost (no implementa IHttpClient) para producción.
 */
class IHttpClient {
public:
    virtual ~IHttpClient() = default;
    virtual bool postRequest(const std::string& url,
                             const std::string& body,
                             const std::map<std::string, std::string>& headers,
                             std::string& response) = 0;
};
