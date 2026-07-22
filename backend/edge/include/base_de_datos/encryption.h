#pragma once
#include <string>
#include <vector>

/**
 * =============================================================================
 * encryption.h — Interfaz singleton de criptografía del edge.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Provee operaciones AES-256-GCM y gestión de clave/token API en un singleton.
 *   Al inicializarse, recupera la clave AES y el token API de SQLite; al
 *   provisionarlas, las persiste en la base de datos local. La clave se mantiene
 *   en memoria con mlock() cuando es posible y se limpia con OPENSSL_cleanse.
 *
 * FLUJO DE CIFRADO:
 *   plaintext + IV(12 aleatorio)
 *        │
 *        ▼
 *   AES-256-GCM -> ciphertext + tag(16)
 *        │
 *        ▼
 *   packed = IV + ciphertext + tag  -> base64Encode
 *
 * DEPENDENCIAS:
 *   - OpenSSL (EVP_aes_256_gcm, RAND_bytes, BIO base64, mlock/munlock).
 *   - base_de_datos/sqlite_manager.h (para initialize()).
 */
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
