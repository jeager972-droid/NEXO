/**
 * =============================================================================
 * ota_manager.cpp — Implementación OTA M2M resistente a apagones.
 * =============================================================================
 */

#include "interoperabilidad/ota_manager.h"
#include "utils/ConfigManager.h"
#include "utils/Logger.h"
#include "base_de_datos/sqlite_manager.h"

#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/sha.h>

#include <chrono>
#include <cstdio>
#include <cstring>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <sstream>
#include <sys/stat.h>
#include <unistd.h>


namespace fs = std::filesystem;

// ── Helpers HTTP ────────────────────────────────────────────────────────────

static size_t curlWriteString(char* ptr, size_t size, size_t nmemb, void* userdata) {
    static_cast<std::string*>(userdata)->append(ptr, size * nmemb);
    return size * nmemb;
}
static size_t curlWriteFile(char* ptr, size_t size, size_t nmemb, void* userdata) {
    return fwrite(ptr, size, nmemb, static_cast<FILE*>(userdata));
}

/** GET autenticado por X-Device-Token (mismo esquema que /devices/commands). */
static bool otaHttpGet(const std::string& url, std::string& out,
                       const std::string& token) {
    CURL* c = curl_easy_init();
    if (!c) return false;
    struct curl_slist* h = nullptr;
    h = curl_slist_append(h, ("X-Device-Token: " + token).c_str());
    curl_easy_setopt(c, CURLOPT_URL, url.c_str());
    curl_easy_setopt(c, CURLOPT_HTTPHEADER, h);
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, curlWriteString);
    curl_easy_setopt(c, CURLOPT_WRITEDATA, &out);
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 30L);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYPEER, 1L);
    bool ok = curl_easy_perform(c) == CURLE_OK;
    long code = 0; curl_easy_getinfo(c, CURLINFO_RESPONSE_CODE, &code);
    if (code != 200) ok = false;
    curl_slist_free_all(h);
    curl_easy_cleanup(c);
    return ok;
}

/** Descarga con reanudación (Range) — apagón a mitad de bajada no pierde lo bajado. */
static bool otaHttpDownload(const std::string& url, const std::string& path,
                            const std::string& token) {
    CURL* c = curl_easy_init();
    if (!c) return false;
    struct stat st{};
    off_t existing = (stat(path.c_str(), &st) == 0) ? st.st_size : 0;
    FILE* f = fopen(path.c_str(), existing > 0 ? "ab" : "wb");
    if (!f) { curl_easy_cleanup(c); return false; }
    struct curl_slist* h = nullptr;
    h = curl_slist_append(h, ("X-Device-Token: " + token).c_str());
    if (existing > 0) {
        std::string range = "Range: bytes=" + std::to_string(existing) + "-";
        h = curl_slist_append(h, range.c_str());
    }
    curl_easy_setopt(c, CURLOPT_URL, url.c_str());
    curl_easy_setopt(c, CURLOPT_HTTPHEADER, h);
    curl_easy_setopt(c, CURLOPT_WRITEFUNCTION, curlWriteFile);
    curl_easy_setopt(c, CURLOPT_WRITEDATA, f);
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 300L);
    curl_easy_setopt(c, CURLOPT_FOLLOWLOCATION, 1L);
    bool ok = curl_easy_perform(c) == CURLE_OK;
    fclose(f);
    curl_slist_free_all(h);
    curl_easy_cleanup(c);
    return ok;
}

// ── Helpers cripto (OpenSSL) ────────────────────────────────────────────────

static std::string hexEncode(const unsigned char* data, size_t len) {
    std::ostringstream o;
    for (size_t i = 0; i < len; ++i) o << std::hex << std::setfill('0') << std::setw(2) << (int)data[i];
    return o.str();
}

/** SHA-256 de archivo en streaming (payloads grandes). */
static bool fileSha256(const std::string& path, std::string& outHex) {
    std::ifstream f(path, std::ios::binary);
    if (!f) return false;
    EVP_MD_CTX* ctx = EVP_MD_CTX_new();
    if (!ctx) return false;
    EVP_DigestInit_ex(ctx, EVP_sha256(), nullptr);
    char buf[65536];
    while (f) { f.read(buf, sizeof(buf)); auto n = f.gcount(); if (n > 0) EVP_DigestUpdate(ctx, buf, n); }
    unsigned char md[EVP_MAX_MD_SIZE]; unsigned int mdLen = 0;
    EVP_DigestFinal_ex(ctx, md, &mdLen);
    EVP_MD_CTX_free(ctx);
    outHex = hexEncode(md, mdLen);
    return true;
}

/** HMAC-SHA256 hex del manifiesto con la clave OTA del dispositivo. */
static std::string manifestHmac(const std::string& version, const std::string& sha,
                                const std::string& url, const std::string& otaKeyHex) {
    std::string key; key.reserve(32);
    for (size_t i = 0; i + 1 < otaKeyHex.size(); i += 2)
        key.push_back(static_cast<char>(std::stoi(otaKeyHex.substr(i, 2), nullptr, 16)));
    std::string msg = "nexo-ota|" + version + "|" + sha + "|" + url;
    unsigned char out[EVP_MAX_MD_SIZE]; unsigned int outLen = 0;
    HMAC(EVP_sha256(), key.data(), (int)key.size(),
         reinterpret_cast<const unsigned char*>(msg.data()), msg.size(), out, &outLen);
    OPENSSL_cleanse(key.data(), key.size());
    return hexEncode(out, outLen);
}

// ── JSON mínimo (evitar depender de nlohmann aquí si ya está — usar parser propio) ──
static std::string jsonStr(const std::string& json, const std::string& key) {
    std::string pat = "\"" + key + "\"";
    auto p = json.find(pat);
    if (p == std::string::npos) return "";
    p = json.find(':', p + pat.size());
    if (p == std::string::npos) return "";
    p = json.find('"', p + 1);
    if (p == std::string::npos) return "";
    auto e = json.find('"', p + 1);
    return e == std::string::npos ? "" : json.substr(p + 1, e - p - 1);
}

// ── Implementación ──────────────────────────────────────────────────────────

/** Comparación semver local (x.y.z numérico). <0 si a<b, 0 si iguales, >0 si a>b. */
static int compareVersions(const std::string& a, const std::string& b) {
    auto parse = [](const std::string& v, int out[3]) {
        out[0] = out[1] = out[2] = 0;
        sscanf(v.c_str(), "%d.%d.%d", &out[0], &out[1], &out[2]);
    };
    int va[3], vb[3];
    parse(a, va); parse(b, vb);
    for (int i = 0; i < 3; ++i) if (va[i] != vb[i]) return va[i] - vb[i];
    return 0;
}

OtaManager& OtaManager::getInstance() { static OtaManager inst; return inst; }

std::string OtaManager::getState(const std::string& k, const std::string& def) {
    return SqliteManager::getInstance().getConfig("ota_" + k, def);
}
void OtaManager::setState(const std::string& k, const std::string& v) {
    SqliteManager::getInstance().setConfig("ota_" + k, v);
}

void OtaManager::clearState() {
    for (const char* k : {"state","update_id","version","url","sha256","sig","apply_start"})
        setState(k, "");
}

bool OtaManager::inProgress() const {
    OtaManager& self = const_cast<OtaManager&>(*this);
    std::string s = self.getState("state", "idle");
    return s != "" && s != "idle";
}

// ── Arranque: resolver estado pendiente ─────────────────────────────────────
void OtaManager::onBoot() {
    std::string st = getState("state", "idle");
    if (st == "applying") {
        // Sobrevivimos el swap: el binario nuevo está corriendo. Esperar
        // ~2 min de estabilidad (confirm_on_boot cuenta ticks) → APPLIED.
        setState("state", "pending_confirm");
        setState("apply_start", std::to_string(std::chrono::system_clock::to_time_t(std::chrono::system_clock::now())));
        LOG_INFO("[OTA] Binario nuevo arrancó — estado pending_confirm");
    } else if (st == "pending_confirm") {
        long t0 = 0;
        { std::string s = getState("apply_start", "0"); if (!s.empty()) t0 = std::stol(s); }
        // Contador de arranques: si el binario nuevo reincide en reinicios sin
        // estabilizarse, se revierte al .bak localmente (anti brick-in-loop).
        int boots = 0;
        { std::string s = getState("boot_count", "0"); if (!s.empty()) boots = std::stoi(s); }
        setState("boot_count", std::to_string(boots + 1));
        if (boots + 1 > 5) {
            LOG_ERROR("[OTA] {} arranques sin confirmar — restaurando .bak", boots + 1);
            if (restoreBackup()) {
                report(getState("update_id"), "ROLLED_BACK", "boot loop detectado tras 5 arranques");
            } else {
                report(getState("update_id"), "FAILED", "boot loop y sin .bak restaurable");
            }
            clearPendingFlag();
            clearState();
            _exit(0); // el supervisor relanza el binario restaurado
        }
        if (time(nullptr) - t0 >= 120) {
            report(getState("update_id"), "APPLIED", "self-check ok tras arranque");
            LOG_INFO("[OTA] Actualización confirmada: v{}", getState("version"));
            // Persistir la versión aplicada en la config local (anti-rollback
            // local y reporte correcto en el próximo /devices/ota/check).
            ConfigManager::getInstance().setValue("app_version", getState("version"), true);
            clearPendingFlag();
            clearState();
        }
    } else if (st == "downloading" || st == "staged") {
        // Apagón durante descarga/verificación — continuar en tick()
        LOG_WARN("[OTA] Reanudando estado {} tras reinicio", st);
    }
}

// ── Ciclo periódico ─────────────────────────────────────────────────────────
void OtaManager::tick() {
    std::string st = getState("state", "idle");

    if (st == "pending_confirm") { onBoot(); return; }

    if (st == "idle") {
        std::string updateId, version, url, sha, sig;
        if (!checkForUpdate(updateId, version, url, sha, sig)) return;
        setState("update_id", updateId); setState("version", version);
        setState("url", url); setState("sha256", sha); setState("sig", sig);
        setState("state", "downloading");
        report(updateId, "DOWNLOADING", "oferta aceptada, descargando");
        st = "downloading";
    }

    if (st == "downloading") {
        std::string part = getState("url") + ".part";
        part = "/tmp/nexo_ota.part"; // archivo de trabajo fijo y limpio
        if (!downloadPayload(getState("url"), part)) {
            report(getState("update_id"), "FAILED", "descarga falló");
            LOG_ERROR("[OTA] Descarga falló para {}", getState("url"));
            clearState();
            return;
        }
        if (!verifyPayload(part, getState("sha256"), getState("version"),
                           getState("url"), getState("sig"))) {
            report(getState("update_id"), "FAILED", "verificación sha256/firma falló");
            LOG_ERROR("[OTA] Verificación falló — payload rechazado");
            fs::remove(part);
            clearState();
            return;
        }
        setState("state", "staged");
        report(getState("update_id"), "STAGED", "verificado y preparado");
        st = "staged";
    }

    if (st == "staged") {
        if (!stageAndApply("/tmp/nexo_ota.part")) {
            report(getState("update_id"), "FAILED", "no se pudo aplicar el swap");
            LOG_ERROR("[OTA] stageAndApply falló");
            clearState();
            return;
        }
        // Swap exitoso → el wrapper reinicia el proceso; el próximo onBoot
        // resuelve pending_confirm.
        setState("state", "applying");
        report(getState("update_id"), "APPLYING", "binario intercambiado, reiniciando");
        LOG_INFO("[OTA] Swap aplicado — reiniciando nodo");
        _exit(0); // el supervisor (systemd/watchdog) relanza el proceso
    }
}

// ── Consulta al central ─────────────────────────────────────────────────────
bool OtaManager::checkForUpdate(std::string& updateId, std::string& version,
                                std::string& url, std::string& sha, std::string& sig) {
    auto& cfg = ConfigManager::getInstance();
    std::string api   = cfg.getApiUrl();
    std::string devId = cfg.getString("device_id", "");
    std::string tok   = cfg.getString("device_token", "");
    std::string ver   = cfg.getString("app_version", "1.0.0");
    if (api.empty() || devId.empty() || tok.empty()) return false;

    std::string u = api + "/devices/ota/check?device_id=" + devId + "&version=" + ver;
    std::string body;
    if (!otaHttpGet(u, body, tok)) return false;
    if (body.find("\"update\":null") != std::string::npos) return false;

    updateId = jsonStr(body, "update_id");
    version  = jsonStr(body, "version");
    url      = jsonStr(body, "url");
    sha      = jsonStr(body, "sha256");
    sig      = jsonStr(body, "signature");
    if (updateId.empty() || url.empty() || sha.empty()) return false;

    // Anti-rollback LOCAL: aunque el central ya filtra por versión, el nodo
    // verifica de nuevo — una oferta manipulada o una versión ≤ a la instalada
    // se rechaza antes de descargar.
    if (compareVersions(version, ver) <= 0) {
        LOG_WARN("[OTA] Oferta v{} rechazada — no supera la instalada v{}", version, ver);
        report(updateId, "REJECTED", "version no supera la instalada (anti-rollback local)");
        return false;
    }
    return true;
}

// ── Descarga con reanudación ────────────────────────────────────────────────
bool OtaManager::downloadPayload(const std::string& url, const std::string& part) {
    std::string tok = ConfigManager::getInstance().getString("device_token", "");
    return otaHttpDownload(url, part, tok);
}

// ── Verificación bidireccional: sha256 + firma HMAC ─────────────────────────
bool OtaManager::verifyPayload(const std::string& path, const std::string& expectedSha,
                               const std::string& version, const std::string& url,
                               const std::string& sig) {
    std::string actual;
    if (!fileSha256(path, actual) || actual != expectedSha) {
        LOG_ERROR("[OTA] sha256 mismatch: {} != {}", actual, expectedSha);
        return false;
    }
    std::string otaKey = ConfigManager::getInstance().getString("ota_key", "");
    if (otaKey.empty()) { LOG_ERROR("[OTA] Sin ota_key provisionada"); return false; }
    std::string expected = manifestHmac(version, expectedSha, url, otaKey);
    if (sig.size() != expected.size() ||
        CRYPTO_memcmp(expected.data(), sig.data(), expected.size()) != 0) {
        LOG_ERROR("[OTA] Firma HMAC inválida");
        return false;
    }
    return true;
}

// ── Bandera .pending para el wrapper de arranque ────────────────────────────
std::string OtaManager::selfExePath() {
    char buf[4096];
    ssize_t n = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
    if (n <= 0) return "";
    buf[n] = '\0';
    return buf;
}

void OtaManager::touchPendingFlag() {
    std::string p = selfExePath();
    if (p.empty()) return;
    std::ofstream f(p + ".pending");
    f << "pending_confirm\n";
    f.flush();
    sync();
}

void OtaManager::clearPendingFlag() {
    std::string p = selfExePath();
    if (p.empty()) return;
    fs::remove(p + ".pending");
}

// ── Swap del binario (atómico vía rename + backup .bak) ─────────────────────
bool OtaManager::stageAndApply(const std::string& part) {
    std::string self;
    {
        char buf[4096];
        ssize_t n = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
        if (n <= 0) return false;
        buf[n] = '\0'; self = buf;
    }
    std::string bak = self + ".bak";
    std::string neu = self + ".new";

    fs::rename(part, neu);                    // staged → .new (atómico, mismo fs)
    if (fs::exists(bak)) fs::remove(bak);
    fs::rename(self, bak);                    // actual → .bak
    fs::rename(neu, self);                    // nuevo → definitivo
    chmod(self.c_str(), S_IRWXU | S_IRGRP | S_IXGRP | S_IROTH | S_IXOTH);
    touchPendingFlag();                       // el wrapper vigila este archivo
    sync();                                   // flush antes de reinicio
    return true;
}

// ── Restaurar el binario anterior (.bak → definitivo) ───────────────────────
bool OtaManager::restoreBackup() {
    char buf[4096];
    ssize_t n = readlink("/proc/self/exe", buf, sizeof(buf) - 1);
    if (n <= 0) return false;
    buf[n] = '\0';
    std::string self = buf;
    std::string bak = self + ".bak";
    if (!fs::exists(bak)) return false;
    std::error_code ec;
    fs::rename(bak, self, ec);
    if (ec) return false;
    chmod(self.c_str(), S_IRWXU | S_IRGRP | S_IXGRP | S_IROTH | S_IXOTH);
    sync();
    LOG_INFO("[OTA] Binario anterior restaurado desde .bak");
    return true;
}

// ── Reporte de estado al central ────────────────────────────────────────────
void OtaManager::report(const std::string& updateId, const std::string& status,
                        const std::string& detail) {
    if (updateId.empty()) return;
    auto& cfg = ConfigManager::getInstance();
    std::string api   = cfg.getApiUrl();
    std::string devId = cfg.getString("device_id", "");
    std::string tok   = cfg.getString("device_token", "");
    std::string body  = "{\"device_id\":\"" + devId + "\",\"update_id\":\"" + updateId +
                        "\",\"status\":\"" + status + "\",\"detail\":\"" + detail + "\"}";

    CURL* c = curl_easy_init();
    if (!c) return;
    struct curl_slist* h = nullptr;
    h = curl_slist_append(h, "Content-Type: application/json");
    h = curl_slist_append(h, ("X-Device-Token: " + tok).c_str());
    curl_easy_setopt(c, CURLOPT_URL, (api + "/devices/ota/report").c_str());
    curl_easy_setopt(c, CURLOPT_HTTPHEADER, h);
    curl_easy_setopt(c, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(c, CURLOPT_TIMEOUT, 20L);
    curl_easy_setopt(c, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_perform(c);
    curl_slist_free_all(h);
    curl_easy_cleanup(c);
}

