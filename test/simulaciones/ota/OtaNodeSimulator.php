<?php
/**
 * =============================================================================
 * test/simulaciones/ota/OtaNodeSimulator.php — Simulador del ciclo OTA del nodo.
 * =============================================================================
 * Reproduce la máquina de estados del OtaManager (C++) contra la API REAL:
 *   check → download (part) → verify sha256+firma → staged → applying →
 *   pending_confirm → APPLIED.
 * Escenarios de fallo validados:
 *   - payload adulterado (sha256 no coincide) → FAILED, nunca se aplica
 *   - firma manipulada → FAILED
 *   - versión anterior/igual → la oferta no se genera (anti-rollback)
 *   - "apagón" a mitad de descarga → el .part persiste y la descarga reanuda
 *   - apagón durante applying → binario .bak restaurado → ROLLED_BACK
 * =============================================================================
 */

require_once __DIR__ . '/../../../backend/api/lib/ota.php';

class OtaNodeSimulator
{
    private string $apiBase;
    private string $deviceId;
    private string $deviceToken;
    private string $otaKey;
    private string $version;
    private string $state = 'idle';
    private string $workDir;

    public function __construct(string $apiBase, string $deviceId, string $deviceToken, string $otaKey, string $version = '1.0.0') {
        $this->apiBase = rtrim($apiBase, '/');
        $this->deviceId = $deviceId;
        $this->deviceToken = $deviceToken;
        $this->otaKey = $otaKey;
        $this->version = $version;
        $this->workDir = sys_get_temp_dir() . '/ota_sim_' . getmypid();
        @mkdir($this->workDir, 0755, true);
        $this->loadState(); // estado persistente → sobrevive "apagones"
    }

    private function stateFile(): string { return $this->workDir . '/ota_state.json'; }
    private function loadState(): void {
        if (is_file($this->stateFile())) {
            $s = json_decode(file_get_contents($this->stateFile()), true);
            $this->state = $s['state'] ?? 'idle';
            $this->manifest = $s['manifest'] ?? null;
        }
    }
    private function saveState(): void {
        file_put_contents($this->stateFile(), json_encode(['state' => $this->state, 'manifest' => $this->manifest]));
    }
    private ?array $manifest = null;

    private function http(string $method, string $path, ?array $body = null): array {
        $ch = curl_init($this->apiBase . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Device-Token: ' . $this->deviceToken],
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($res, true) ?: []];
    }

    private function report(string $status, string $detail): void {
        if (empty($this->manifest['update_id'])) return;
        $this->http('POST', '/devices/ota/report', [
            'device_id' => $this->deviceId,
            'update_id' => $this->manifest['update_id'],
            'status'    => $status,
            'detail'    => $detail,
        ]);
    }

    /** Un paso de la máquina — equivale a OtaManager::tick(). */
    public function tick(): string {
        switch ($this->state) {
            case 'idle': {
                [$code, $r] = $this->http('GET', "/devices/ota/check?device_id={$this->deviceId}&version={$this->version}");
                if ($code !== 200 || empty($r['update'])) return 'idle';
                $this->manifest = $r['update'];
                $this->state = 'downloading';
                $this->saveState();
                $this->report('DOWNLOADING', 'sim aceptada');
                return 'downloading';
            }
            case 'downloading': {
                $this->simulateDownload();
                $part = $this->workDir . '/payload.part';
                $sha  = is_file($part) ? hash_file('sha256', $part) : '';
                if ($sha !== ($this->manifest['sha256'] ?? '')) {
                    $this->report('FAILED', 'sha256 mismatch (payload adulterado)');
                    $this->resetToIdle();
                    return 'failed_sha';
                }
                if (!otaVerifyManifest($this->manifest, $this->otaKey)) {
                    $this->report('FAILED', 'firma inválida');
                    $this->resetToIdle();
                    return 'failed_sig';
                }
                $this->state = 'staged';
                $this->saveState();
                $this->report('STAGED', 'verificado');
                return 'staged';
            }
            case 'staged': {
                // swap simulado: part → binary.new → binary.bak
                $this->state = 'applying';
                $this->saveState();
                $this->report('APPLYING', 'swap aplicado (sim)');
                return 'applying';
            }
            case 'applying': {
                // primer boot del binario nuevo
                $this->state = 'pending_confirm';
                $this->saveState();
                return 'pending_confirm';
            }
            case 'pending_confirm': {
                $this->report('APPLIED', 'self-check ok (sim)');
                $this->version = $this->manifest['version'];
                $this->resetToIdle();
                return 'applied';
            }
        }
        return $this->state;
    }

    /** Simula descarga; el "payload" lo genera el propio simulador. */
    private function simulateDownload(): void {
        $payload = $this->expectedPayload ?? '';
        if (is_file($this->workDir . '/payload.part') && !$this->powerLost) {
            // reanudación: append restante
            $existing = filesize($this->workDir . '/payload.part');
            $rest = substr($payload, $existing);
            file_put_contents($this->workDir . '/payload.part', $rest, FILE_APPEND);
        } else {
            file_put_contents($this->workDir . '/payload.part', $payload);
        }
    }

    public bool $powerLost = false;
    private ?string $expectedPayload = null;

    /** El test inyecta el payload esperado (cuyo sha256 ya conoce el central). */
    public function setExpectedPayload(string $payload): void { $this->expectedPayload = $payload; }

    /** Simula un apagón a mitad de descarga: .part queda truncado. */
    public function powerCutMidDownload(): void {
        $part = $this->workDir . '/payload.part';
        file_put_contents($part, substr($this->expectedPayload ?? '', 0, intdiv(strlen($this->expectedPayload ?? 'x'), 2)));
        $this->powerLost = true;
        // no cambia state → sigue 'downloading'; la próxima tick reanuda
    }
    public function powerRestore(): void { $this->powerLost = false; }

    /** Simula que el binario nuevo no arranca → rollback a .bak. */
    public function rollback(): void {
        $this->report('ROLLED_BACK', 'binario nuevo falló, .bak restaurado (sim)');
        $this->resetToIdle();
    }

    private function resetToIdle(): void {
        $this->state = 'idle';
        $this->manifest = null;
        $this->saveState();
        @unlink($this->workDir . '/payload.part');
    }

    public function getVersion(): string { return $this->version; }
    public function getState(): string { return $this->state; }
}
