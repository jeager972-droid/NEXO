#pragma once
#include <string>
#include <vector>

class Encryption {
public:
    static Encryption& getInstance() {
        static Encryption instance;
        return instance;
    }

    bool initialize();

    // Key provisioning
    bool isKeyProvisioned() const;
    bool isTokenProvisioned() const;
    bool provisionKey(const std::string& key);
    bool provisionToken(const std::string& token);

    // AES-256-GCM
    std::string encrypt(const std::string& plaintext);
    std::string encrypt(const std::string& plaintext, const std::vector<uint8_t>& iv);
    std::string decrypt(const std::string& ciphertext);

    // Token
    std::string getToken() const;

private:
    Encryption() = default;
    ~Encryption();
    std::vector<char> m_aesKey;
    std::string m_apiToken;
};
