<?php
/**
 * =============================================================================
 * test/simulaciones/nodo/NodeSimulator.php — Simulador de salud de nodos edge.
 * =============================================================================
 *
 * PROPÓSITO (regla transversal hardware/firmware/físico)
 * ------------------------------------------------------
 * Simula los estados físicos de un nodo edge —heartbeat vivo, caída prolongada,
 * flapping (corta intermitente), cobertura parcial, dispositivo sin asignar—
 * para validar la lógica de contingencia SIN hardware real:
 *
 *   - Gate de detectores (F-04): un grupo con todos sus nodos caídos no genera
 *     ausencias/evasiones falsas; se marca SIN_DATOS_NODO.
 *   - Monitor de salud (worker_device_health): transiciones offline↔online
 *     crean y resuelven incidentes NODO_OFFLINE.
 *
 * USO
 * ---
 *   Los escenarios devuelven filas con el MISMO formato que
 *   ctFetchDeviceRows() (device_id, group_id, group_name, active,
 *   last_ping_ts), por lo que alimentan directamente las funciones puras de
 *   backend/api/workers/contingency_lib.php.
 *
 *   Para una validación end-to-end con BD real (marcha blanca), los métodos
 *   applyToDb() insertan dispositivos/estados simulados sobre edge_devices.
 * =============================================================================
 */

class NodeSimulator
{
    /**
     * Crea una fila de dispositivo simulada (mismo shape que ctFetchDeviceRows).
     * @param int|null $lastPingTs epoch del último ping (null = nunca reportó)
     */
    public static function device(
        string $deviceId,
        ?string $groupId = null,
        ?int $lastPingTs = null,
        bool $active = true,
        string $name = 'SimNode',
        ?string $groupName = null
    ): array {
        return [
            'device_id'    => $deviceId,
            'device_name'  => $name,
            'group_id'     => $groupId,
            'group_name'   => $groupName ?? ($groupId ? "Grupo-{$groupId}" : null),
            'location'     => 'simulacion',
            'active'       => $active,
            'last_ping_ts' => $lastPingTs,
        ];
    }

    /** Nodo que reporta hace $ageSeconds segundos. */
    public static function aliveDevice(string $id, ?string $groupId, int $now, int $ageSeconds = 30): array {
        return self::device($id, $groupId, $now - $ageSeconds, true);
    }

    /** Nodo caído: ping más viejo que el umbral o nulo. */
    public static function deadDevice(string $id, ?string $groupId, int $now, int $offlineSec): array {
        return self::device($id, $groupId, $now - $offlineSec - 60, true);
    }

    /** Nodo que nunca reportó (last_ping NULL). */
    public static function neverReportedDevice(string $id, ?string $groupId): array {
        return self::device($id, $groupId, null, true);
    }

    // ──────────────────────────── Escenarios ────────────────────────────

    /**
     * Apagón simple: un grupo servido por un solo nodo caído.
     * expect.offlineGroups = [g1], went_offline = [dev1]
     */
    public static function scenarioSingleNodeOutage(int $now, int $offlineSec): array {
        $g = 'grp-1';
        return [
            'devices' => [self::deadDevice('dev-1', $g, $now, $offlineSec)],
            'open_incidents' => [],
            'expect' => ['offlineGroups' => [$g], 'went_offline' => ['dev-1'], 'came_online' => []],
        ];
    }

    /**
     * Recuperación: nodo con incidente abierto vuelve a reportar.
     */
    public static function scenarioRecovery(int $now, int $offlineSec): array {
        $g = 'grp-1';
        return [
            'devices' => [self::aliveDevice('dev-1', $g, $now)],
            'open_incidents' => ['dev-1' => true],
            'expect' => ['offlineGroups' => [], 'went_offline' => [], 'came_online' => ['dev-1']],
        ];
    }

    /**
     * Flapping: nodo que cae, vuelve y recae (secuencia de 3 ticks).
     * Cada tick: [devices, open_incidents, expect]
     */
    public static function scenarioFlapping(int $now, int $offlineSec): array {
        $g = 'grp-1';
        return [
            'ticks' => [
                [ // t0: nodo cae → went_offline
                    'devices' => [self::deadDevice('dev-1', $g, $now, $offlineSec)],
                    'open_incidents' => [],
                    'expect' => ['went_offline' => ['dev-1'], 'came_online' => []],
                ],
                [ // t1: sigue caído con incidente abierto → sin transición nueva
                    'devices' => [self::deadDevice('dev-1', $g, $now, $offlineSec)],
                    'open_incidents' => ['dev-1' => true],
                    'expect' => ['went_offline' => [], 'came_online' => []],
                ],
                [ // t2: recupera → came_online
                    'devices' => [self::aliveDevice('dev-1', $g, $now)],
                    'open_incidents' => ['dev-1' => true],
                    'expect' => ['went_offline' => [], 'came_online' => ['dev-1']],
                ],
                [ // t3: recae sin incidente → went_offline otra vez
                    'devices' => [self::deadDevice('dev-1', $g, $now, $offlineSec)],
                    'open_incidents' => [],
                    'expect' => ['went_offline' => ['dev-1'], 'came_online' => []],
                ],
            ],
        ];
    }

    /**
     * Cobertura parcial: grupo con 2 nodos, solo uno caído → NO se suprime.
     */
    public static function scenarioPartialCoverage(int $now, int $offlineSec): array {
        $g = 'grp-1';
        return [
            'devices' => [
                self::deadDevice('dev-1', $g, $now, $offlineSec),
                self::aliveDevice('dev-2', $g, $now),
            ],
            'open_incidents' => [],
            'expect' => ['offlineGroups' => [], 'went_offline' => ['dev-1'], 'came_online' => []],
        ];
    }

    /**
     * Sin mapeo: dispositivos sin group_id no generan gate sobre ningún grupo.
     */
    public static function scenarioUnmappedDevices(int $now, int $offlineSec): array {
        return [
            'devices' => [
                self::deadDevice('dev-1', null, $now, $offlineSec),
                self::neverReportedDevice('dev-2', null),
            ],
            'open_incidents' => [],
            // siguen generando NODO_OFFLINE (salud del nodo), pero ningún grupo queda gated
            'expect' => ['offlineGroups' => [], 'went_offline' => ['dev-1', 'dev-2'], 'came_online' => []],
        ];
    }

    /**
     * Dispositivo inactivo: no cuenta para cobertura ni para incidentes.
     */
    public static function scenarioInactiveDevice(int $now, int $offlineSec): array {
        $g = 'grp-1';
        return [
            'devices' => [
                self::device('dev-1', $g, null, false), // inactivo, nunca reportó
            ],
            'open_incidents' => [],
            'expect' => ['offlineGroups' => [], 'went_offline' => [], 'came_online' => []],
        ];
    }

    /**
     * Multi-grupo: una escuela con 3 grupos, 1 caído, 1 vivo, 1 sin nodo.
     */
    public static function scenarioMixedSchool(int $now, int $offlineSec): array {
        return [
            'devices' => [
                self::deadDevice('dev-a', 'grp-down', $now, $offlineSec),
                self::aliveDevice('dev-b', 'grp-up', $now),
                // grp-none: sin dispositivo asignado
            ],
            'open_incidents' => [],
            'expect' => [
                'offlineGroups' => ['grp-down'],
                'went_offline' => ['dev-a'],
                'came_online' => [],
            ],
        ];
    }

    // ─────────────────── Integración con BD real (marcha blanca) ───────────────────

    /**
     * Inserta un dispositivo simulado en edge_devices (requiere BD real y RLS
     * context ya configurado por el caller). Para pruebas de integración.
     */
    public static function applyToDb(PDO $conn, string $schoolId, array $simDevice): string {
        $stmt = $conn->prepare("
            INSERT INTO edge_devices (device_id, school_id, device_name, group_id, active, configured, last_ping, location)
            VALUES (uuid_generate_v4(), ?, ?, ?, ?, TRUE,
                    CASE WHEN ?::bigint IS NULL THEN NULL ELSE to_timestamp(?::bigint) END, ?)
            RETURNING device_id
        ");
        $stmt->execute([
            $schoolId,
            $simDevice['device_name'],
            $simDevice['group_id'],
            $simDevice['active'] ? 'true' : 'false',
            $simDevice['last_ping_ts'],
            $simDevice['last_ping_ts'],
            $simDevice['location'],
        ]);
        return (string)$stmt->fetchColumn();
    }

    /** Simula que un dispositivo real reporta ping (last_ping = NOW). */
    public static function simulatePing(PDO $conn, string $deviceId): void {
        $conn->prepare("UPDATE edge_devices SET last_ping = NOW() WHERE device_id = ?")->execute([$deviceId]);
    }

    /** Simula silencio del nodo (last_ping retrocedido $secondsAgo). */
    public static function simulateSilence(PDO $conn, string $deviceId, int $secondsAgo): void {
        $conn->prepare("UPDATE edge_devices SET last_ping = NOW() - (?::int * INTERVAL '1 second') WHERE device_id = ?")
             ->execute([$secondsAgo, $deviceId]);
    }
}
