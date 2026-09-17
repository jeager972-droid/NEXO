<?php
/**
 * =============================================================================
 * simulaciones/biometria/ManualPresenceSimulator.php — Simulador del flujo de
 * presencia manual (F-02).
 * =============================================================================
 *
 * PROPÓSITO (regla transversal hardware/físico)
 * --------------------------------------------
 * La captura biométrica es un proceso físico que puede fallar (huella gastada,
 * suciedad en el sensor, condición médica). Este simulador modela esos estados
 * del mundo real para validar el flujo de contingencia SIN hardware:
 *
 *   - Presencia biométrica normal (evento INGRESO_% automático).
 *   - Fallo de captura → registro manual del actor (INGRESO_MANUAL).
 *   - Estudiante exento de biometría (biometric_exempt=TRUE).
 *   - Intento duplicado (segundo INGRESO_MANUAL el mismo día → conflicto).
 *
 * USO: los escenarios devuelven estados + expectativas consumidos por
 * test/api/ManualPresenceTest.php. Para marcha blanca con BD real, los helpers
 * generateManualEvent()/generateExemptStudent() producen filas insertables.
 * =============================================================================
 */

class ManualPresenceSimulator
{
    /** Evento biométrico normal (como lo emitiría el edge real). */
    public static function biometricEvent(string $studentId, string $type = 'INGRESO_MAÑANA'): array {
        return [
            'student_id' => $studentId,
            'event_type' => $type,
            'event_result' => 'PROCESSED',
            'source' => 'biometric',
        ];
    }

    /** Evento de presencia manual (como lo inserta ctRegisterManualPresence). */
    public static function manualEvent(string $studentId, string $actorId, string $reason): array {
        return [
            'student_id' => $studentId,
            'event_type' => 'INGRESO_MANUAL',
            'event_result' => 'PROCESSED',
            'source' => 'manual',
            'registered_by' => $actorId,
            'reason' => $reason,
        ];
    }

    /** ¿Un event_type cuenta como presencia? (contrato LIKE 'INGRESO_%'). */
    public static function countsAsPresence(string $eventType): bool {
        return strncmp($eventType, 'INGRESO_', 8) === 0;
    }

    // ──────────────────────────── Escenarios ────────────────────────────

    /** Normal: huella registrada + evento biométrico → presente. */
    public static function scenarioNormal(): array {
        return [
            'student' => ['student_id' => 'stu-1', 'biometric_exempt' => false, 'has_fingerprint' => true],
            'events'  => [self::biometricEvent('stu-1')],
            'expect'  => ['present' => true, 'absence_incident' => false],
        ];
    }

    /** Fallo de captura → el actor registra manualmente → presente igual. */
    public static function scenarioManualFallback(): array {
        return [
            'student' => ['student_id' => 'stu-1', 'biometric_exempt' => false, 'has_fingerprint' => true],
            'events'  => [self::manualEvent('stu-1', 'user-9', 'sensor no captura huella')],
            'expect'  => ['present' => true, 'absence_incident' => false],
        ];
    }

    /** Exento sin ningún evento → NO genera ausencia (la falta de huella no implica ausencia). */
    public static function scenarioExemptNoEvent(): array {
        return [
            'student' => ['student_id' => 'stu-2', 'biometric_exempt' => true, 'has_fingerprint' => false],
            'events'  => [],
            'expect'  => ['present' => false, 'absence_incident' => false],
        ];
    }

    /** Duplicado: segundo registro manual el mismo día → conflicto 409. */
    public static function scenarioDuplicateManual(): array {
        return [
            'student' => ['student_id' => 'stu-1', 'biometric_exempt' => false],
            'events'  => [self::manualEvent('stu-1', 'user-9', 'sensor caído')],
            'attempt' => self::manualEvent('stu-1', 'user-10', 'segundo intento'),
            'expect'  => ['http' => 409],
        ];
    }

    /** Exento registrado manualmente → presente y sin ausencia. */
    public static function scenarioExemptWithManual(): array {
        return [
            'student' => ['student_id' => 'stu-2', 'biometric_exempt' => true, 'has_fingerprint' => false],
            'events'  => [self::manualEvent('stu-2', 'user-9', 'exento biométrico')],
            'expect'  => ['present' => true, 'absence_incident' => false],
        ];
    }
}
