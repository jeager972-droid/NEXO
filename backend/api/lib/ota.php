<?php
/**
 * =============================================================================
 * lib/ota.php — Soporte OTA M2M (Bloque D).
 * =============================================================================
 * Actualización remota de nodos por el canal M2M:
 *   - Manifiesto firmado por HMAC-SHA256 con la clave OTA por-dispositivo
 *     (compromiso de un nodo no compromete a otros).
 *   - Comparación semver con anti-rollback (min_version).
 *   - El nodo reporta cada etapa → ota_deployments audita el resultado.
 * =============================================================================
 */

/** Comparación semver: retorna -1, 0, 1 */
function otaVersionCompare(string $a, string $b): int {
    $pa = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $a)));
    $pb = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $b)));
    for ($i = 0; $i < 3; $i++) {
        $x = $pa[$i] ?? 0; $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x < $y ? -1 : 1;
    }
    return 0;
}

/** Firma del manifiesto: HMAC-SHA256("nexo-ota" | version | sha256 | url).
 *  ota_key se guarda como hex — la clave real son los 32 bytes (mismo criterio
 *  que el edge C++). */
function otaSignManifest(string $version, string $sha256, string $url, string $otaKey): string {
    $keyBin = ctype_xdigit($otaKey) ? hex2bin($otaKey) : $otaKey;
    return hash_hmac('sha256', "nexo-ota|{$version}|{$sha256}|{$url}", $keyBin);
}

function otaVerifyManifest(array $manifest, string $otaKey): bool {
    $sig = $manifest['signature'] ?? '';
    if (!$sig) return false;
    $expected = otaSignManifest(
        (string)($manifest['version'] ?? ''),
        (string)($manifest['sha256'] ?? ''),
        (string)($manifest['url'] ?? ''),
        $otaKey
    );
    return hash_equals($expected, $sig);
}
