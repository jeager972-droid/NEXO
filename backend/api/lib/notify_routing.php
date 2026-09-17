<?php
/**
 * =============================================================================
 * lib/notify_routing.php — Enrutamiento configurable de notificaciones y
 * políticas de acción por institución.
 * =============================================================================
 *
 * CUBRE: V-013/041/058/066/081/388/462/406.
 *
 *   - nexoRouteUserIds(): destinatarios internos vía school_notification_routes
 *     (event_kind → target_role). Sin filas = roles por defecto del llamador.
 *   - nexoPolicyEnabled(): on/off por evento vía school_action_policies
 *     (enabled=false → la acción/disparo se omite). Sin fila = comportamiento
 *     por defecto (habilitado).
 *   - nexoAction(): acción configurada para un evento (o la por defecto).
 *
 * Incluido por api.php y workers (vía contingency_lib.php).
 * =============================================================================
 */

/** Devuelve user_ids destino para un event_kind, consultando rutas por escuela. */
function nexoRouteUserIds($conn, string $schoolId, string $eventKind, array $defaultRoles = ['COORDINATOR', 'RECTOR']): array {
    try {
        $routed = $conn->prepare("
            SELECT DISTINCT UPPER(target_role) FROM school_notification_routes
            WHERE school_id = ? AND event_kind = ? AND enabled = TRUE
        ");
        $routed->execute([$schoolId, $eventKind]);
        $roles = $routed->fetchAll(PDO::FETCH_COLUMN);
        if (empty($roles)) $roles = array_map('strtoupper', $defaultRoles);
        $ph = implode(',', array_fill(0, count($roles), '?'));
        $st = $conn->prepare("
            SELECT u.user_id FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.school_id = ? AND u.active = TRUE AND UPPER(r.role_name) IN ($ph)
        ");
        $st->execute(array_merge([$schoolId], $roles));
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

/** ¿El evento/acción está habilitado para la escuela? Default: true.
 *  OJO: pgsql puede devolver bool false para enabled=FALSE — hay que
 *  distinguir "sin fila" (fetch===false) del valor false. */
function nexoPolicyEnabled($conn, string $schoolId, string $eventType): bool {
    try {
        $st = $conn->prepare("SELECT enabled FROM school_action_policies WHERE school_id = ? AND event_type = ? LIMIT 1");
        $st->execute([$schoolId, $eventType]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row === false) return true; // sin fila → default habilitado
        return in_array($row[0], ['t', '1', 1, true], true);
    } catch (Exception $e) {
        return true;
    }
}

/** Acción configurada para un evento (o $default si no hay fila). */
function nexoAction($conn, string $schoolId, string $eventType, string $default): string {
    try {
        $st = $conn->prepare("SELECT action FROM school_action_policies WHERE school_id = ? AND event_type = ? AND enabled = TRUE LIMIT 1");
        $st->execute([$schoolId, $eventType]);
        $v = $st->fetchColumn();
        return is_string($v) && $v !== '' ? $v : $default;
    } catch (Exception $e) {
        return $default;
    }
}
