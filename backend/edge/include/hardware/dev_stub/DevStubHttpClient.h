#pragma once
#include "hal/IHttpClient.h"

class DevStubHttpClient : public IHttpClient {
public:
    bool postRequest(const std::string& url,
                     const std::string& body,
                     const std::map<std::string, std::string>& headers,
                     std::string& response) override;
};
