#include <catch2/catch_test_macros.hpp>
#include <catch2/catch_session.hpp>
#include <openssl/evp.h>
#include <openssl/rand.h>
#include <openssl/err.h>
#include <string>
#include <vector>
#include <cstring>

// Constants from encryption.h
static const int KEY_SIZE = 32;  // 256 bits
static const int IV_SIZE = 12;   // 96 bits (recommended for GCM)
static const int TAG_SIZE = 16;  // 128 bits

// Helper to generate random bytes
void generateRandomBytes(unsigned char* buffer, size_t length) {
    if (RAND_bytes(buffer, length) != 1) {
        throw std::runtime_error("Failed to generate random bytes");
    }
}

// Helper to encode base64 (simplified for testing)
std::string base64Encode(const unsigned char* data, size_t len) {
    static const char* chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string result;
    int i = 0;
    unsigned char char_array_3[3];
    unsigned char char_array_4[4];
    size_t pos = 0;
    
    while (pos < len) {
        char_array_3[i++] = data[pos++];
        if (i == 3) {
            char_array_4[0] = (char_array_3[0] & 0xfc) >> 2;
            char_array_4[1] = ((char_array_3[0] & 0x03) << 4) + ((char_array_3[1] & 0xf0) >> 4);
            char_array_4[2] = ((char_array_3[1] & 0x0f) << 2) + ((char_array_3[2] & 0xc0) >> 6);
            char_array_4[3] = char_array_3[2] & 0x3f;
            
            for (i = 0; i < 4; i++) {
                result += chars[char_array_4[i]];
            }
            i = 0;
        }
    }
    
    if (i) {
        for (int j = i; j < 3; j++) {
            char_array_3[j] = '\0';
        }
        char_array_4[0] = (char_array_3[0] & 0xfc) >> 2;
        char_array_4[1] = ((char_array_3[0] & 0x03) << 4) + ((char_array_3[1] & 0xf0) >> 4);
        char_array_4[2] = ((char_array_3[1] & 0x0f) << 2) + ((char_array_3[2] & 0xc0) >> 6);
        char_array_4[3] = char_array_3[2] & 0x3f;
        
        for (int j = 0; j < i + 1; j++) {
            result += chars[char_array_4[j]];
        }
        while (i++ < 3) {
            result += '=';
        }
    }
    
    return result;
}

// Helper to decode base64 (simplified for testing)
std::vector<unsigned char> base64Decode(const std::string& encoded) {
    static const char* chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::vector<unsigned char> result;
    int in_len = encoded.size();
    int i = 0;
    int in = 0;
    unsigned char char_array_4[4], char_array_3[3];
    
    while (in_len-- && (encoded[in] != '=') && isalnum(encoded[in] || encoded[in] == '+' || encoded[in] == '/')) {
        char_array_4[i++] = encoded[in]; in++;
        if (i == 4) {
            for (i = 0; i < 4; i++) {
                char_array_4[i] = strchr(chars, char_array_4[i]) - chars;
            }
            char_array_3[0] = (char_array_4[0] << 2) + ((char_array_4[1] & 0x30) >> 4);
            char_array_3[1] = ((char_array_4[1] & 0xf) << 4) + ((char_array_4[2] & 0x3c) >> 2);
            char_array_3[2] = ((char_array_4[2] & 0x3) << 6) + char_array_4[3];
            
            for (i = 0; i < 3; i++) {
                result.push_back(char_array_3[i]);
            }
            i = 0;
        }
    }
    
    if (i) {
        for (int j = i; j < 4; j++) {
            char_array_4[j] = 0;
        }
        for (int j = 0; j < 4; j++) {
            char_array_4[j] = strchr(chars, char_array_4[j]) - chars;
        }
        char_array_3[0] = (char_array_4[0] << 2) + ((char_array_4[1] & 0x30) >> 4);
        char_array_3[1] = ((char_array_4[1] & 0xf) << 4) + ((char_array_4[2] & 0x3c) >> 2);
        char_array_3[2] = ((char_array_4[2] & 0x3) << 6) + char_array_4[3];
        
        for (int j = 0; j < i - 1; j++) {
            result.push_back(char_array_3[j]);
        }
    }
    
    return result;
}

// AES-256-GCM Encryption
std::string encryptAES256GCM(const std::string& plaintext, const unsigned char* key, const unsigned char* iv, unsigned char* tag) {
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) {
        throw std::runtime_error("Failed to create cipher context");
    }
    
    int len;
    int ciphertext_len;
    
    // Initialize encryption
    if (EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to initialize encryption");
    }
    
    // Set IV length
    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, IV_SIZE, nullptr) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to set IV length");
    }
    
    // Set key and IV
    if (EVP_EncryptInit_ex(ctx, nullptr, nullptr, key, iv) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to set key and IV");
    }
    
    // Provide plaintext
    std::vector<unsigned char> ciphertext(plaintext.size() + 16);
    if (EVP_EncryptUpdate(ctx, ciphertext.data(), &len, reinterpret_cast<const unsigned char*>(plaintext.c_str()), plaintext.size()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to encrypt");
    }
    ciphertext_len = len;
    
    // Finalize encryption
    if (EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &len) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to finalize encryption");
    }
    ciphertext_len += len;
    
    // Get authentication tag
    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, TAG_SIZE, tag) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to get authentication tag");
    }
    
    EVP_CIPHER_CTX_free(ctx);
    
    return std::string(ciphertext.begin(), ciphertext.begin() + ciphertext_len);
}

// AES-256-GCM Decryption
std::string decryptAES256GCM(const std::string& ciphertext, const unsigned char* key, const unsigned char* iv, const unsigned char* tag) {
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) {
        throw std::runtime_error("Failed to create cipher context");
    }
    
    int len;
    int plaintext_len;
    
    // Initialize decryption
    if (EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to initialize decryption");
    }
    
    // Set IV length
    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, IV_SIZE, nullptr) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to set IV length");
    }
    
    // Set key and IV
    if (EVP_DecryptInit_ex(ctx, nullptr, nullptr, key, iv) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to set key and IV");
    }
    
    // Set expected tag
    if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, TAG_SIZE, const_cast<unsigned char*>(tag)) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to set authentication tag");
    }
    
    // Provide ciphertext
    std::vector<unsigned char> plaintext(ciphertext.size() + 16);
    if (EVP_DecryptUpdate(ctx, plaintext.data(), &len, reinterpret_cast<const unsigned char*>(ciphertext.c_str()), ciphertext.size()) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Failed to decrypt");
    }
    plaintext_len = len;
    
    // Finalize decryption
    int ret = EVP_DecryptFinal_ex(ctx, plaintext.data() + len, &len);
    if (ret <= 0) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("Decryption failed - authentication tag mismatch");
    }
    plaintext_len += len;
    
    EVP_CIPHER_CTX_free(ctx);
    
    return std::string(plaintext.begin(), plaintext.begin() + plaintext_len);
}

TEST_CASE("AES-256-GCM Encryption and Decryption", "[crypto]") {
    unsigned char key[KEY_SIZE];
    unsigned char iv[IV_SIZE];
    unsigned char tag[TAG_SIZE];
    
    // Generate random key and IV
    generateRandomBytes(key, KEY_SIZE);
    generateRandomBytes(iv, IV_SIZE);
    
    std::string plaintext = "Hello, NEXO! This is a test message.";
    
    SECTION("Encrypt and decrypt returns original") {
        std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
        std::string decrypted = decryptAES256GCM(ciphertext, key, iv, tag);
        
        REQUIRE(decrypted == plaintext);
    }
    
    SECTION("Different IV produces different ciphertext") {
        unsigned char iv2[IV_SIZE];
        generateRandomBytes(iv2, IV_SIZE);
        unsigned char tag2[TAG_SIZE];
        
        std::string ciphertext1 = encryptAES256GCM(plaintext, key, iv, tag);
        std::string ciphertext2 = encryptAES256GCM(plaintext, key, iv2, tag2);
        
        REQUIRE(ciphertext1 != ciphertext2);
    }
    
    SECTION("Wrong key fails decryption") {
        unsigned char wrong_key[KEY_SIZE];
        generateRandomBytes(wrong_key, KEY_SIZE);
        
        std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
        
        REQUIRE_THROWS(decryptAES256GCM(ciphertext, wrong_key, iv, tag));
    }
    
    SECTION("Wrong IV fails decryption") {
        unsigned char wrong_iv[IV_SIZE];
        generateRandomBytes(wrong_iv, IV_SIZE);
        
        std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
        
        REQUIRE_THROWS(decryptAES256GCM(ciphertext, key, wrong_iv, tag));
    }
    
    SECTION("Wrong tag fails decryption") {
        unsigned char wrong_tag[TAG_SIZE];
        generateRandomBytes(wrong_tag, TAG_SIZE);
        
        std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
        
        REQUIRE_THROWS(decryptAES256GCM(ciphertext, key, iv, wrong_tag));
    }
}

TEST_CASE("AES-256-GCM Empty String", "[crypto]") {
    unsigned char key[KEY_SIZE];
    unsigned char iv[IV_SIZE];
    unsigned char tag[TAG_SIZE];
    
    generateRandomBytes(key, KEY_SIZE);
    generateRandomBytes(iv, IV_SIZE);
    
    std::string plaintext = "";
    
    std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
    std::string decrypted = decryptAES256GCM(ciphertext, key, iv, tag);
    
    REQUIRE(decrypted == plaintext);
}

TEST_CASE("AES-256-GCM Large Data", "[crypto]") {
    unsigned char key[KEY_SIZE];
    unsigned char iv[IV_SIZE];
    unsigned char tag[TAG_SIZE];
    
    generateRandomBytes(key, KEY_SIZE);
    generateRandomBytes(iv, IV_SIZE);
    
    std::string plaintext(10000, 'X'); // 10KB of data
    
    std::string ciphertext = encryptAES256GCM(plaintext, key, iv, tag);
    std::string decrypted = decryptAES256GCM(ciphertext, key, iv, tag);
    
    REQUIRE(decrypted == plaintext);
}

TEST_CASE("Random Number Generation", "[crypto]") {
    unsigned char buffer1[32];
    unsigned char buffer2[32];
    
    generateRandomBytes(buffer1, 32);
    generateRandomBytes(buffer2, 32);
    
    // Ensure two random generations are different
    REQUIRE(memcmp(buffer1, buffer2, 32) != 0);
    
    // Ensure all bytes are non-zero (statistically unlikely to be zero)
    bool all_zero = true;
    for (int i = 0; i < 32; i++) {
        if (buffer1[i] != 0) {
            all_zero = false;
            break;
        }
    }
    REQUIRE(!all_zero);
}
