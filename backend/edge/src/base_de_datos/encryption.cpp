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
#include <openssl/hmac.h>
#include <openssl/rand.h>
#include <openssl/bio.h>
#include <openssl/buffer.h>
#include <openssl/kdf.h>
#include <cstring>
#include <vector>
#include <sstream>
#include <iomanip>
#include <openssl/crypto.h>
#include <sys/mman.h>
#include <cerrno>
#include <fstream>
#include <sys/stat.h>
#include <unistd.h>

// Base64 helpers
static std::string base64Encode(const std::vector<uint8_t>& data) {
    BIO* bio = BIO_new(BIO_s_mem());
    BIO* b64 = BIO_new(BIO_f_base64());
    if (!bio || !b64) {
        if (bio) BIO_free(bio);
        if (b64) BIO_free(b64);
        return "";
    }
    BIO_set_flags(b64, BIO_FLAGS_BASE64_NO_NL);
    bio = BIO_push(b64, bio);
    if (BIO_write(bio, data.data(), static_cast<int>(data.size())) <= 0 ||
        BIO_flush(bio) <= 0) {
        BIO_free_all(bio);
        return "";
    }
    BUF_MEM* buf = nullptr;
    BIO_get_mem_ptr(bio, &buf);
    std::string result(buf ? buf->data : "", buf ? buf->length : 0);
    BIO_free_all(bio);
    return result;
}

static std::vector<uint8_t> base64Decode(const std::string& encoded) {
    if (encoded.empty()) return {};
    BIO* bio = BIO_new_mem_buf(encoded.data(), static_cast<int>(encoded.size()));
    BIO* b64 = BIO_new(BIO_f_base64());
    if (!bio || !b64) {
        if (bio) BIO_free(bio);
        if (b64) BIO_free(b64);
        return {};
    }
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

void Encryption::setKeyFile(const std::string& path) {
    m_keyFile = path;
}

bool Encryption::initialize() {
    auto& db = SqliteManager::getInstance();
    std::string storedKey;
    if (loadKeyFromFile(storedKey)) {
        m_aesKey.assign(storedKey.begin(), storedKey.end());
        if (mlock(m_aesKey.data(), m_aesKey.size()) != 0) {
            LOG_WARN("mlock failed for AES key (errno={})", errno);
        }
    }
    std::string storedToken = db.getConfig("nexo_api_token");
    if (!storedToken.empty()) m_apiToken = storedToken;
    return isKeyProvisioned() && isTokenProvisioned();
}

bool Encryption::saveKeyToFile(const std::string& key) {
    if (key.size() != 32) return false;
    // FIX C5: Cifrar la clave antes de guardarla en disco (binding a hardware)
    // Si el cifrado falla (ej: no es RPi), fallback a texto plano con chmod 600
    if (saveKeyToFileEncrypted(key)) {
        return true;
    }
    LOG_WARN("[C5] Hardware-bound encryption failed, falling back to plaintext key file");
    // Escritura atómica: temp -> chmod -> rename, para evitar archivo truncado por apagón.
    std::string tmpPath = m_keyFile + ".tmp";
    {
        std::ofstream ofs(tmpPath, std::ios::binary | std::ios::trunc);
        if (!ofs) {
            LOG_ERROR("Cannot open AES key file for writing: {}", tmpPath);
            return false;
        }
        ofs.write(key.data(), static_cast<std::streamsize>(key.size()));
        if (!ofs) return false;
        ofs.close();
    }
    chmod(tmpPath.c_str(), S_IRUSR | S_IWUSR);
    if (std::rename(tmpPath.c_str(), m_keyFile.c_str()) != 0) {
        LOG_ERROR("Failed to rename AES key file: {} -> {}", tmpPath, m_keyFile);
        return false;
    }
    return true;
}

bool Encryption::loadKeyFromFile(std::string& key) {
    // FIX C5: Intentar cargar cifrado primero, fallback a texto plano (compatibilidad)
    if (loadKeyFromFileEncrypted(key)) {
        return true;
    }
    // Fallback: archivo en texto plano (formato legacy o no-RPi)
    std::ifstream ifs(m_keyFile, std::ios::binary | std::ios::ate);
    if (!ifs) return false;
    auto size = static_cast<std::streamoff>(ifs.tellg());
    if (size != 32) {
        // Si el tamaño no es 32, probablemente está cifrado pero no podemos descifrar
        // (ej: SD card movida a otra RPi) — eso es esperado y seguro
        LOG_ERROR("AES key file {} has size {} (expected 32). May be encrypted for different hardware.",
                  m_keyFile, size);
        return false;
    }
    ifs.seekg(0, std::ios::beg);
    key.resize(32);
    ifs.read(key.data(), 32);
    return ifs.good();
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
    if (!saveKeyToFile(key)) {
        LOG_ERROR("Failed to persist AES key to file");
        return false;
    }
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
    if (EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1 ||
        EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr) != 1 ||
        EVP_EncryptInit_ex(ctx, nullptr, nullptr,
                           reinterpret_cast<const uint8_t*>(m_aesKey.data()), iv.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }
    if (EVP_EncryptUpdate(ctx, ciphertext.data(), &len,
                          reinterpret_cast<const uint8_t*>(plaintext.data()),
                          static_cast<int>(plaintext.size())) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }
    cipherLen = len;
    if (EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &len) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }
    cipherLen += len;
    std::vector<uint8_t> tag(16);
    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, 16, tag.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }
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

    return encrypt(plaintext, iv);
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

    if (EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1 ||
        EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr) != 1 ||
        EVP_DecryptInit_ex(ctx, nullptr, nullptr,
                           reinterpret_cast<const uint8_t*>(m_aesKey.data()), iv.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }

    if (EVP_DecryptUpdate(ctx, plaintext.data(), &len, ciphertext.data(),
                          static_cast<int>(ciphertext.size())) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }
    plainLen = len;

    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, 16, tag.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        return "";
    }

    int ret = EVP_DecryptFinal_ex(ctx, plaintext.data() + len, &len);
    EVP_CIPHER_CTX_free(ctx);

    if (ret <= 0) {
        LOG_ERROR("AES-GCM authentication failed");
        return "";
    }
    plainLen += len;
    return std::string(reinterpret_cast<char*>(plaintext.data()), static_cast<size_t>(plainLen));
}

// ============================================================================
// FIX C5: Hardware-bound encryption — cifrar clave AES en disco
// ============================================================================
// Deriva una clave de cifrado del CPU serial de la RPi (/proc/cpuinfo).
// Esto significa que si extraen la SD card y la ponen en otra RPi, no pueden
// descifrar la clave AES. No es tan seguro como un TPM, pero eleva la barra
// significativamente para ataques de "robar la SD card".
// ============================================================================

std::string Encryption::getHardwareBoundKey() {
    // Leer CPU serial y revision de /proc/cpuinfo (RPi específico)
    std::string serial, revision;
    std::ifstream cpuinfo("/proc/cpuinfo");
    if (cpuinfo) {
        std::string line;
        while (std::getline(cpuinfo, line)) {
            if (line.rfind("Serial", 0) == 0) {
                auto pos = line.find(':');
                if (pos != std::string::npos) serial = line.substr(pos + 2);
            } else if (line.rfind("Revision", 0) == 0) {
                auto pos = line.find(':');
                if (pos != std::string::npos) revision = line.substr(pos + 2);
            }
        }
    }
    if (serial.empty() && revision.empty()) {
        // No es RPi o no se pudo leer — no se puede hacer binding
        return "";
    }
    // Combinar serial + revision + salt fijo para derivar clave
    std::string hwId = serial + ":" + revision + ":NEXO_EDGE_HW_BIND_V1";
    // Derivar clave de 32 bytes con PBKDF2-SHA256
    std::vector<uint8_t> derivedKey(32);
    // Salt fijo (no necesita ser secreto, solo único para NEXO)
    const unsigned char salt[] = "NEXO_EDGE_SALT_2026";
    int rc = PKCS5_PBKDF2_HMAC(
        hwId.c_str(), static_cast<int>(hwId.size()),
        salt, sizeof(salt) - 1,  // -1 para excluir el null terminator
        10000,  // iteraciones
        EVP_sha256(),
        32, derivedKey.data());
    if (rc != 1) {
        LOG_ERROR("[C5] PBKDF2 key derivation failed");
        return "";
    }
    return std::string(reinterpret_cast<char*>(derivedKey.data()), 32);
}

bool Encryption::saveKeyToFileEncrypted(const std::string& key) {
    std::string hwKey = getHardwareBoundKey();
    if (hwKey.empty()) {
        // No es RPi o no se pudo leer CPU serial — no se puede cifrar
        return false;
    }

    // Cifrar la clave AES con la clave derivada del hardware
    std::vector<uint8_t> iv(12);
    if (RAND_bytes(iv.data(), 12) != 1) {
        LOG_ERROR("[C5] RAND_bytes failed for IV");
        return false;
    }

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) return false;

    std::vector<uint8_t> ciphertext(key.size() + 16);
    int len = 0, cipherLen = 0;
    if (EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1 ||
        EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr) != 1 ||
        EVP_EncryptInit_ex(ctx, nullptr, nullptr,
                           reinterpret_cast<const unsigned char*>(hwKey.data()), iv.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }
    if (EVP_EncryptUpdate(ctx, ciphertext.data(), &len,
                          reinterpret_cast<const unsigned char*>(key.data()),
                          static_cast<int>(key.size())) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }
    cipherLen = len;
    if (EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &len) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }
    cipherLen += len;
    std::vector<uint8_t> tag(16);
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, 16, tag.data());
    EVP_CIPHER_CTX_free(ctx);
    OPENSSL_cleanse(&hwKey[0], hwKey.size());

    // Empaquetar: magic(4) + IV(12) + ciphertext + tag(16)
    // Magic "NXE1" para distinguir de archivo en texto plano
    std::vector<uint8_t> packed;
    packed.insert(packed.end(), {'N', 'X', 'E', '1'});
    packed.insert(packed.end(), iv.begin(), iv.end());
    packed.insert(packed.end(), ciphertext.begin(), ciphertext.begin() + cipherLen);
    packed.insert(packed.end(), tag.begin(), tag.end());

    // Escritura atómica
    std::string tmpPath = m_keyFile + ".tmp";
    {
        std::ofstream ofs(tmpPath, std::ios::binary | std::ios::trunc);
        if (!ofs) return false;
        ofs.write(reinterpret_cast<const char*>(packed.data()),
                  static_cast<std::streamsize>(packed.size()));
        if (!ofs) return false;
        ofs.close();
    }
    chmod(tmpPath.c_str(), S_IRUSR | S_IWUSR);
    if (std::rename(tmpPath.c_str(), m_keyFile.c_str()) != 0) {
        LOG_ERROR("[C5] Failed to rename encrypted key file");
        return false;
    }
    LOG_INFO("[C5] AES key saved with hardware-bound encryption");
    return true;
}

bool Encryption::loadKeyFromFileEncrypted(std::string& key) {
    std::ifstream ifs(m_keyFile, std::ios::binary | std::ios::ate);
    if (!ifs) return false;
    auto size = static_cast<std::streamoff>(ifs.tellg());
    // Formato cifrado: magic(4) + IV(12) + ciphertext(32) + tag(16) = 64 bytes
    if (size != 64) return false;  // No es formato cifrado

    std::vector<uint8_t> packed(size);
    ifs.seekg(0, std::ios::beg);
    ifs.read(reinterpret_cast<char*>(packed.data()), size);
    if (!ifs.good()) return false;

    // Verificar magic
    if (packed[0] != 'N' || packed[1] != 'X' || packed[2] != 'E' || packed[3] != '1') {
        return false;  // No es formato cifrado
    }

    std::string hwKey = getHardwareBoundKey();
    if (hwKey.empty()) {
        LOG_ERROR("[C5] Cannot decrypt key file — hardware ID not available");
        return false;
    }

    // Extraer IV(12) + ciphertext(32) + tag(16)
    std::vector<uint8_t> iv(packed.begin() + 4, packed.begin() + 16);
    std::vector<uint8_t> ciphertext(packed.begin() + 16, packed.begin() + 48);
    std::vector<uint8_t> tag(packed.begin() + 48, packed.begin() + 64);

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) {
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }

    std::vector<uint8_t> plaintext(32);
    int len = 0, plainLen = 0;

    if (EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1 ||
        EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, 12, nullptr) != 1 ||
        EVP_DecryptInit_ex(ctx, nullptr, nullptr,
                           reinterpret_cast<const unsigned char*>(hwKey.data()), iv.data()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }
    if (EVP_DecryptUpdate(ctx, plaintext.data(), &len, ciphertext.data(),
                          static_cast<int>(ciphertext.size())) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        OPENSSL_cleanse(&hwKey[0], hwKey.size());
        return false;
    }
    plainLen = len;
    EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, 16, tag.data());
    int ret = EVP_DecryptFinal_ex(ctx, plaintext.data() + len, &len);
    EVP_CIPHER_CTX_free(ctx);
    OPENSSL_cleanse(&hwKey[0], hwKey.size());

    if (ret <= 0) {
        LOG_ERROR("[C5] AES-GCM authentication failed for key file — wrong hardware?");
        return false;
    }
    plainLen += len;
    key.assign(reinterpret_cast<char*>(plaintext.data()), static_cast<size_t>(plainLen));
    OPENSSL_cleanse(plaintext.data(), plaintext.size());
    LOG_INFO("[C5] AES key loaded from hardware-bound encrypted file");
    return true;
}

// ── V-243: hash con clave para campos-busqueda (determinístico) ────────────
// HMAC-SHA256 sobre "dockey|" + plaintext con la clave AES del dispositivo.
// Determinístico → sirve como PK/join sin exponer el valor real en reposo.
std::string Encryption::keyedHash(const std::string& plaintext) {
    if (!isKeyProvisioned() || plaintext.empty()) return "";
    std::string msg = "dockey|" + plaintext;
    unsigned char mac[EVP_MAX_MD_SIZE];
    unsigned int macLen = 0;
    HMAC(EVP_sha256(), m_aesKey.data(), (int)m_aesKey.size(),
         reinterpret_cast<const unsigned char*>(msg.data()), msg.size(),
         mac, &macLen);
    std::ostringstream hex;
    for (unsigned int i = 0; i < macLen; ++i)
        hex << std::hex << std::setfill('0') << std::setw(2) << (int)mac[i];
    OPENSSL_cleanse(mac, sizeof(mac));
    return hex.str();
}
