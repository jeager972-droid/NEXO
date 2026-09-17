<?php
/**
 * =============================================================================
 * simulaciones/biometria/FingerprintSimulator.php — Simulador del sensor
 * biométrico multi-dedo (F-03).
 * =============================================================================
 *
 * PROPÓSITO (regla transversal hardware/físico)
 * --------------------------------------------
 * Modela el comportamiento del lector de huellas y su store de templates
 * SIN hardware real: enrolamiento por slot (1|2), identificación 1:N por
 * cualquier dedo, re-enrolamiento, revocación y cola offline (eventos
 * pendientes de sincronizar al central).
 *
 * Espeja la semántica implementada en:
 *   - edge: estudiante_huellas(documento, finger_slot, huella_id)
 *   - central: student_fingerprints(student_id, finger_slot, edge_huella_id)
 *
 * USO: test/api/MultiFingerprintTest.php lo instancia en memoria para
 * validar enrolamiento, identificación bidireccional, duplicados, revocación
 * y sincronización offline. Para marcha blanca, los mismos escenarios pueden
 * ejecutarse contra el DevStub C++ (hardware/dev_stub) + nexo-tests.
 * =============================================================================
 */

class FingerprintSimulator
{
    /** @var array doc => [slot => huella_id] */
    private array $store = [];
    /** @var array huella_id => doc */
    private array $byHuella = [];
    /** @var array huella_id => template (simulado) */
    private array $templates = [];
    /** @var array eventos pendientes de sync (offline) */
    private array $pendingSync = [];
    private int $nextHuella = 1;
    /** @var bool simula el central inalcanzable (nodo offline) */
    private bool $centralOnline = true;

    // ── Control del entorno simulado ──
    public function setCentralOnline(bool $online): void { $this->centralOnline = $online; }
    public function pendingSyncCount(): int { return count($this->pendingSync); }

    /**
     * Enrola un dedo: asigna huella_id global, guarda template por slot.
     * @return array{ok:bool, huella_id?:int, error?:string}
     */
    public function enroll(string $doc, int $fingerSlot, string $template = ''): array {
        if ($fingerSlot < 1 || $fingerSlot > 2) {
            return ['ok' => false, 'error' => 'finger_slot inválido'];
        }
        // Re-enrolar el mismo slot: liberar el huella_id anterior
        if (isset($this->store[$doc][$fingerSlot])) {
            $old = $this->store[$doc][$fingerSlot];
            unset($this->byHuella[$old], $this->templates[$old]);
        }
        $huellaId = $this->nextHuella++;
        $this->store[$doc][$fingerSlot] = $huellaId;
        $this->byHuella[$huellaId] = $doc;
        $this->templates[$huellaId] = $template !== '' ? $template : "tpl_{$doc}_{$fingerSlot}";

        if ($this->centralOnline) {
            $this->syncEnroll($doc, $fingerSlot, $huellaId);
        } else {
            $this->pendingSync[] = ['action' => 'ENROLL', 'doc' => $doc, 'slot' => $fingerSlot, 'huella_id' => $huellaId];
        }
        return ['ok' => true, 'huella_id' => $huellaId];
    }

    /**
     * Identificación 1:N: un dedo cualquiera resuelve al estudiante.
     * @return array{matched:bool, doc?:string, slot?:int}
     */
    public function identify(int $huellaId): array {
        if (!isset($this->byHuella[$huellaId])) return ['matched' => false];
        $doc = $this->byHuella[$huellaId];
        $slot = array_search($huellaId, $this->store[$doc], true);
        return ['matched' => true, 'doc' => $doc, 'slot' => $slot === false ? null : (int)$slot];
    }

    /** Huellas de un estudiante: [slot => huella_id]. */
    public function fingersOf(string $doc): array { return $this->store[$doc] ?? []; }
    public function fingerCount(string $doc): int { return count($this->store[$doc] ?? []); }

    /** Revocación: elimina todos los dedos del estudiante. */
    public function revoke(string $doc): bool {
        if (!isset($this->store[$doc])) return false;
        foreach ($this->store[$doc] as $hid) {
            unset($this->byHuella[$hid], $this->templates[$hid]);
        }
        unset($this->store[$doc]);
        if (!$this->centralOnline) {
            $this->pendingSync[] = ['action' => 'REVOKE', 'doc' => $doc];
        }
        return true;
    }

    /** Vacía la cola offline "sincronizando" al central (simulación de reconexión). */
    public function flushSyncQueue(): int {
        $this->centralOnline = true;
        $n = count($this->pendingSync);
        $this->pendingSync = [];
        return $n;
    }

    private function syncEnroll(string $doc, int $slot, int $huellaId): void {
        // En el sistema real: registerStudentWithFingerprint → enroll-confirm /
        // ingest AES → student_fingerprints upsert. Aquí es no-op porque el
        // store ya refleja el estado.
    }
}
