#pragma once
/**
 * =============================================================================
 * ota_manager.h — Actualización OTA del nodo por canal M2M.
 * =============================================================================
 * Flujo resistente a apagones (estado persistido en SQLite `config`):
 *
 *   idle ──check──▶ downloading ──sha256+firma──▶ staged ──swap──▶ applying
 *      ▲                                                              │
 *      │                                         (reboot / corte de   │
 *      │                                          energía reinicia    │
 *      │                                          en cualquier etapa) │
 *      └── confirmado ◀── boot self-check ◀── pending_confirm ◀────────┘
 *
 *   - Verificación bidireccional: firma HMAC-SHA256 del manifiesto con la
 *     clave OTA por-dispositivo + SHA-256 del payload + anti-rollback de
 *     versión. Si cualquiera falla → estado idle, se reporta FAILED.
 *   - Apagón a mitad de descarga: el .part persiste; al volver, se reanuda
 *     con Content-Range.
 *   - Apagón a mitad de swap: el estado "applying" persistido + copia `.bak`
 *     del binario actual permiten restaurar en el siguiente arranque.
 *   - Fallo de arranque del binario nuevo: el script wrapper de arranque
 *     restaura .bak si la bandera `<bin>.pending` sigue presente tras N
 *     intentos.
 * =============================================================================
 */

#include <string>


class OtaManager {
public:
    static OtaManager& getInstance();

    /** Llamar al arranque: resuelve estado pendiente (confirm/rollback). */
    void onBoot();

    /** Llamar periódicamente (p.ej. cada 30 min): check + avance de estado. */
    void tick();

    /** true si el nodo está en medio de una actualización. */
    bool inProgress() const;

private:
    OtaManager() = default;

    std::string getState(const std::string& key, const std::string& def = "");
    void        setState(const std::string& key, const std::string& value);

    bool checkForUpdate(std::string& updateId, std::string& version,
                        std::string& url, std::string& sha256, std::string& sig);
    bool downloadPayload(const std::string& url, const std::string& partPath);
    bool verifyPayload(const std::string& path, const std::string& expectedSha,
                       const std::string& version, const std::string& url,
                       const std::string& sig);
    bool stageAndApply(const std::string& partPath);
    bool restoreBackup();
    void report(const std::string& updateId, const std::string& status,
                const std::string& detail);
    void clearState();

    // Bandera `<bin>.pending`: la crea el swap y la borra la confirmación.
    // El wrapper de arranque la usa para restaurar .bak si el nuevo binario
    // muere antes de arrancar (crash antes de onBoot).
    static std::string selfExePath();
    static void touchPendingFlag();
    static void clearPendingFlag();
};

