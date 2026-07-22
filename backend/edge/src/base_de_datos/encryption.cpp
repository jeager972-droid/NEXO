/**
 * =============================================================================
 * encryption.cpp — Implementación de criptografía AES-256-GCM del edge.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Provee cifrado/descifrado AES-256-GCM usando OpenSSL, codificación base64,
 *   y persistencia/lectura de clave y token API en SQLite. Usa mlock/munlock
 *   para intentar evitar que la clave sea swappeada y OPENSSL_cleanse para
 *   limpiar la memoria al destruir.
 *
 * SECCIONES:
 *   1. Base64 helpers
 *   2. Constructor/destructor y gestión segura de la clave
 *   3. initialize/provision/isKeyProvisioned
 *   4. encrypt/decrypt AES-256-GCM (IV 12 bytes + tag 16 bytes)
 */

#include "base_de_datos/encryption.h"
#include "base_de_datos/sqlite_manager.h"
#include "utils/Logger.h"
#include <openssl/evp.h>
#include <openssl/rand.h>
#include <openssl/bio.h>
#include <openssl/buffer.h>
#include <cstring>
#include <vector>
#include <openssl/crypto.h>
#include <sys/mman.h>
#include <cerrno>

// Base64 helpers
static std::string base64Encode(const std::vector<uint8_t>& data) {
    BIO* bio = BIO_new(BIO_s_mem());
    BIO* b64 = BIO_new(BIO_f_base64());
    BIO_set_flags(b64, BIO_FLAGS_BASE64_NO_NL);
    bio = BIO_push(b64, bio);
    BIO_write(bio, data.data(), static_cast<int>(data.size()));
    BIO_flush(bio);
    BUF_MEM* buf;
    BIO_get_mem_ptr(bio, &buf);
    std::string result(buf->data, buf->length);
    BIO_free_all(bio);
    return result;
}

static std::vector<uint8_t> base64Decode(const std::string& encoded) {
    BIO* bio = BIO_new_mem_buf(encoded.data(), static_cast<int>(encoded.size()));
    BIO* b64 = BIO_new(BIO_f_base64());
    BIO_set_flags(b64, BIO_FLAGS_BASE64_NO_NL);
    bio = BIO_push(b64, bio);
    std::vector<uint8_t> decoded(encoded.size());
    int len = BIO_read(bio, decoded.data(), static_cast<int>(decoded.size()));
    BIO_free_all(bio);
    if (len > 0) decoded.resize(static_cast<size_t>(len));
    else decoded.clear();
    return decoded;
}

Encryption::~Encryption() {
    if (!m_aesKey.empty()) {
        OPENSSL_cleanse(m_aesKey.data(), m_aesKey.size());
        munlock(m_aesKey.data(), m_aesKey.size());
    }
}

bool Encryption::initialize() {
    auto& db = SqliteManager::getInstance();
    std::string storedKey = db.getConfig("nexo_aes_key_b64");
    std::string storedToken = db.getConfig("nexo_api_token");
    if (!storedKey.empty()) {
        m_aesKey.assign(storedKey.begin(), storedKey.end());
        if (mlock(m_aesKey.data(), m_aesKey.size()) != 0) {
            LOG_WARN("mlock failed for AES key (errno={})", errno);
        }
    }
    if (!storedToken.empty()) m_apiToken = storedToken;
    return isKeyProvisioned() && isTokenProvisioned();
}

bool Encryption::isKeyProvisioned() const { return m_aesKey.size() == 32; }
bool Encryption::isTokenProvisioned() const { return !m_apiToken.empty(); }

bool Encryption::provisionKey(const std::string& key) {
    if (key.size() != 32) return false;
    if (!m_aesKey.empty()) {
        OPENSSL_cleanse(m_aesKey.data(), m_aesKey.size());
        munlock(m_aesKey.data(), m_aesKey.size());
    }
    m_aesKey.assign(key.begin(), key.end());
    if (mlock(m_aesKey.data(), m_aesKey.size()) != 0) {
        LOG_WARN("mlock failed for AES key (errno={})", errno);
    }
    SqliteManager::getInstance().setConfig("nexo_aes_key_b64", key);
    LOG_INFO("AES-256 key provisioned");
    return true;
}

bool Encryption::provisionToken(const std::string& token) {
    if (token.empty()) return false;
    m_apiToken = token;
    SqliteManager::getInstance().setConfig("nexo_api_token", token);
    LOG_INFO("API token provisioned");
    return true;
}

std::string Encryption::getToken() const { return m_apiToken; }

std::string Encryption::encrypt(const std::string& plaintext, const std::vector<uint8_t>& iv) {
    if (!isKeyProvisioned() || iv.size() != 12) return "";
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return "";
    std::vector<uint8_t> ciphertext(plaintext.size() + 16);
    int len = 0, cipherLen = 0;
    EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr);
    EVP_EncryptInit_ex(ctx, nullptr, nullptr,
                       reinterpret_cast<const uint8_t*>(m_aesKey.data()), iv.data());
    EVP_EncryptUpdate(ctx, ciphertext.data(), &len,
                      reinterpret_cast<const uint8_t*>(plaintext.data()),
                      static_cast<int>(plaintext.size()));
    cipherLen = len;
    EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &len);
    cipherLen += len;
    std::vector<uint8_t> tag(16);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, 16, tag.data());
    EVP_CIPHER_CTX_free(ctx);
    ciphertext.resize(static_cast<size_t>(cipherLen));
    std::vector<uint8_t> packed;
    packed.insert(packed.end(), iv.begin(), iv.end());
    packed.insert(packed.end(), ciphertext.begin(), ciphertext.end());
    packed.insert(packed.end(), tag.begin(), tag.end());
    return base64Encode(packed);
}

std::string Encryption::encrypt(const std::string& plaintext) {
    if (!isKeyProvisioned()) return "";

    // AES-256-GCM: 12-byte IV, 16-byte tag
    std::vector<uint8_t> iv(12);
    if (RAND_bytes(iv.data(), 12) != 1) return "";

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return "";

    std::vector<uint8_t> ciphertext(plaintext.size() + 16);
    int len = 0, cipherLen = 0;

    EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr);
    EVP_EncryptInit_ex(ctx, nullptr, nullptr,
                       reinterpret_cast<const uint8_t*>(m_aesKey.data()), iv.data());
    EVP_EncryptUpdate(ctx, ciphertext.data(), &len,
                      reinterpret_cast<const uint8_t*>(plaintext.data()),
                      static_cast<int>(plaintext.size()));
    cipherLen = len;
    EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &len);
    cipherLen += len;

    std::vector<uint8_t> tag(16);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, 16, tag.data());
    EVP_CIPHER_CTX_free(ctx);

    ciphertext.resize(static_cast<size_t>(cipherLen));

    // Pack: IV(12) + ciphertext + tag(16)
    std::vector<uint8_t> packed;
    packed.insert(packed.end(), iv.begin(), iv.end());
    packed.insert(packed.end(), ciphertext.begin(), ciphertext.end());
    packed.insert(packed.end(), tag.begin(), tag.end());

    return base64Encode(packed);
}

std::string Encryption::decrypt(const std::string& b64Ciphertext) {
    if (!isKeyProvisioned()) return "";

    auto packed = base64Decode(b64Ciphertext);
    if (packed.size() < 28) return ""; // 12 IV + 0 data + 16 tag minimum

    std::vector<uint8_t> iv(packed.begin(), packed.begin() + 12);
    std::vector<uint8_t> tag(packed.end() - 16, packed.end());
    std::vector<uint8_t> ciphertext(packed.begin() + 12, packed.end() - 16);

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return "";

    std::vector<uint8_t> plaintext(ciphertext.size());
    int len = 0, plainLen = 0;

    EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr);
    EVP_DecryptInit_ex(ctx, nullptr, nullptr,
                       reinterpret_cast<const uint8_t*>(m_aesKey.data()), iv.data());
    EVP_DecryptUpdate(ctx, plaintext.data(), &len, ciphertext.data(),
                      static_cast<int>(ciphertext.size()));
    plainLen = len;
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, 16, tag.data());

    int ret = EVP_DecryptFinal_ex(ctx, plaintext.data() + len, &len);
    EVP_CIPHER_CTX_free(ctx);

    if (ret <= 0) {
        LOG_ERROR("AES-GCM authentication failed");
        return "";
    }
    plainLen += len;
    return std::string(reinterpret_cast<char*>(plaintext.data()), static_cast<size_t>(plainLen));
}
