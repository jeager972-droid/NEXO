<?php
/**
 * =============================================================================
 * routes/teacher_alerts.php — Criterios de aviso configurables por docente (F-18)
 * =============================================================================
 * Documento §4.5/§9.9: "los docentes pueden establecer criterios de aviso
 * asociados con su propia actividad — cantidad de llegadas tardías,
 * inasistencias o salidas dentro de un período definido".
 *
 * Endpoints (rol TEACHER):
 *   GET  /teacher/alert-rules          → reglas del docente autenticado
 *   POST /teacher/alert-rules          → crear regla
 *   PUT  /teacher/alert-rules/{id}     → actualizar (umbral/ventana/activa)
 *   DELETE /teacher/alert-rules/{id}   → eliminar
 *   POST /teacher/onboarding           → marcar onboarding del docente
 *        { completed: true } — el docente configuró sus reglas o decidió
 *        omitirlas explícitamente. users.onboarding_completed=TRUE.
 *
 * Las reglas las evalúa workers/worker_teacher_alerts.php.
 * =============================================================================
 */

// ─── GET /teacher/alert-rules ───
if ($cleanPath === '/teacher/alert-rules' && $method === 'GET') {
    $authUser = requireAuth(['TEACHER', 'COORDINATOR', 'RECTOR']);
    $stmt = $conn->prepare("
        SELECT r.rule_id, r.event_kind, r.threshold_count, r.window_days, r.active,
               r.group_id, ag.group_name, r.student_id,
               s.first_name || ' ' || s.last_name AS student_name,
               r.created_at
        FROM teacher_alert_rules r
        LEFT JOIN academic_groups ag ON ag.group_id = r.group_id
        LEFT JOIN students s ON s.student_id = r.student_id
        WHERE r.teacher_user_id = ? AND r.school_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$authUser['id'], $authUser['school_id']]);
    exit(json_encode(['status' => 'ok', 'rules' => $stmt->fetchAll(PDO::FETCH_ASSOC)]));
}

// ─── POST /teacher/alert-rules ───
if ($cleanPath === '/teacher/alert-rules' && $method === 'POST') {
    $authUser = requireAuth(['TEACHER']);
    $b = $input;
    $kind    = strtoupper(trim($b['event_kind'] ?? ''));
    $thresh  = (int)($b['threshold_count'] ?? 0);
    $window  = (int)($b['window_days'] ?? 7);
    $groupId = $b['group_id'] ?? null;
    $stuId   = $b['student_id'] ?? null;

    if (!in_array($kind, ['LATE','ABSENCE','EVASION','EXIT','PERMISSION_EXPIRY'], true)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'event_kind inválido']));
    }
    if ($thresh < 1 || $thresh > 60) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'threshold_count debe ser 1-60']));
    }
    if ($window < 1 || $window > 90) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'window_days debe ser 1-90']));
    }

    // Si especificó grupo, debe ser un grupo al que el docente tiene acceso
    if ($groupId) {
        $chk = $conn->prepare("
            SELECT 1 FROM teacher_group_access
            WHERE teacher_user_id = ? AND group_id = ? LIMIT 1
        ");
        $chk->execute([$authUser['id'], $groupId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            exit(json_encode(['status' => 'error', 'message' => 'No tienes acceso a ese grupo']));
        }
    }
    if ($stuId) {
        $chk = $conn->prepare("
            SELECT 1 FROM students s
            WHERE s.student_id = ? AND s.school_id = ? AND s.active = TRUE AND s.deleted_at IS NULL
        ");
        $chk->execute([$stuId, $authUser['school_id']]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Estudiante no encontrado']));
        }
    }

    $conn->prepare("
        INSERT INTO teacher_alert_rules (school_id, teacher_user_id, group_id, student_id, event_kind, threshold_count, window_days)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$authUser['school_id'], $authUser['id'], $groupId ?: null, $stuId ?: null, $kind, $thresh, $window]);

    http_response_code(201);
    exit(json_encode(['status' => 'ok', 'message' => 'Criterio de aviso creado']));
}

// ─── PUT /teacher/alert-rules/{id} ───
if (preg_match('#^/teacher/alert-rules/([0-9a-f-]{36})$#i', $cleanPath, $m) && $method === 'PUT') {
    $authUser = requireAuth(['TEACHER']);
    $ruleId = $m[1];
    $b = $input;
    $fields = []; $params = [];
    if (isset($b['threshold_count'])) {
        $t = (int)$b['threshold_count'];
        if ($t < 1 || $t > 60) { http_response_code(400); exit(json_encode(['status'=>'error','message'=>'threshold_count 1-60'])); }
        $fields[] = 'threshold_count = ?'; $params[] = $t;
    }
    if (isset($b['window_days'])) {
        $w = (int)$b['window_days'];
        if ($w < 1 || $w > 90) { http_response_code(400); exit(json_encode(['status'=>'error','message'=>'window_days 1-90'])); }
        $fields[] = 'window_days = ?'; $params[] = $w;
    }
    if (isset($b['active'])) { $fields[] = 'active = ?'; $params[] = $b['active'] ? 'TRUE' : 'FALSE'; }
    if (!$fields) { http_response_code(400); exit(json_encode(['status'=>'error','message'=>'Nada que actualizar'])); }

    $params[] = $ruleId; $params[] = $authUser['id'];
    $stmt = $conn->prepare("UPDATE teacher_alert_rules SET " . implode(', ', $fields) . ", updated_at = NOW()
                            WHERE rule_id = ? AND teacher_user_id = ?");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) { http_response_code(404); exit(json_encode(['status'=>'error','message'=>'Regla no encontrada'])); }
    exit(json_encode(['status' => 'ok']));
}

// ─── DELETE /teacher/alert-rules/{id} ───
if (preg_match('#^/teacher/alert-rules/([0-9a-f-]{36})$#i', $cleanPath, $m) && $method === 'DELETE') {
    $authUser = requireAuth(['TEACHER']);
    $stmt = $conn->prepare("DELETE FROM teacher_alert_rules WHERE rule_id = ? AND teacher_user_id = ?");
    $stmt->execute([$m[1], $authUser['id']]);
    if ($stmt->rowCount() === 0) { http_response_code(404); exit(json_encode(['status'=>'error','message'=>'Regla no encontrada'])); }
    exit(json_encode(['status' => 'ok']));
}

// ─── GET /teacher/onboarding — estado del onboarding del docente ───
if ($cleanPath === '/teacher/onboarding' && $method === 'GET') {
    $authUser = requireAuth(['TEACHER']);
    $stmt = $conn->prepare("SELECT onboarding_completed FROM users WHERE user_id = ?");
    $stmt->execute([$authUser['id']]);
    $done = (bool)$stmt->fetchColumn();
    $nRules = $conn->prepare("SELECT COUNT(*) FROM teacher_alert_rules WHERE teacher_user_id = ? AND active");
    $nRules->execute([$authUser['id']]);
    exit(json_encode([
        'status' => 'ok',
        'onboarding_completed' => $done,
        'active_rules' => (int)$nRules->fetchColumn(),
    ]));
}

// ─── POST /teacher/onboarding — completar/omitir onboarding del docente ───
if ($cleanPath === '/teacher/onboarding' && $method === 'POST') {
    $authUser = requireAuth(['TEACHER']);
    // completed=true (configuró reglas) o skipped=true (omitió explícito)
    $done = !empty($input['completed']) || !empty($input['skipped']);
    if (!$done) { http_response_code(400); exit(json_encode(['status'=>'error','message'=>'completed|skipped requerido'])); }
    $conn->prepare("UPDATE users SET onboarding_completed = TRUE WHERE user_id = ?")
        ->execute([$authUser['id']]);
    exit(json_encode(['status' => 'ok', 'onboarding_completed' => true]));
}
