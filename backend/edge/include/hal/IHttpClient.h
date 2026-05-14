#pragma once
#include <string>
#include <map>

class IHttpClient {
public:
    virtual ~IHttpClient() = default;
    virtual bool postRequest(const std::string& url,
                             const std::string& body,
                             const std::map<std::string, std::string>& headers,
                             std::string& response) = 0;
};
