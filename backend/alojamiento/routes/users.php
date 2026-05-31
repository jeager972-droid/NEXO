<?php
/**
 * routes/users.php — Endpoints para gestión de usuarios y fotos de perfil.
 */

global $cleanPath, $conn, $method, $input;
require_once __DIR__ . '/_auth_middleware.php';

if (strpos($cleanPath, '/users/') !== 0) {
    return;
}

$authUser = requireAuth();
$schoolId = $authUser['school_id'];
$userId   = $authUser['user_id'];

function usersJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// GET /users/by-role?role=COORDINADOR&same_shift=1
// Returns users of a specific role within the same school.
// If same_shift=1, filters by the requesting user's work_shift.
// ============================================================================
if ($cleanPath === '/users/by-role' && $method === 'GET') {
    try {
        $roleName = $_GET['role'] ?? '';
        $sameShift = ($_GET['same_shift'] ?? '0') === '1';

        if (!$roleName) {
            usersJson(['status' => 'error', 'message' => 'role param required'], 400);
        }

        // Fetch requester's shift
        $requesterShift = null;
        if ($sameShift) {
            $st = $conn->prepare("SELECT work_shift FROM users WHERE user_id = ?");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $requesterShift = $row['work_shift'] ?? null;
        }

        $sql = "
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_photo_url,
                   r.role_name, u.work_shift
            FROM users u
            INNER JOIN roles r ON u.role_id = r.role_id
            WHERE u.school_id = ? AND r.role_name = ? AND u.active = TRUE
        ";
        $params = [$schoolId, $roleName];

        if ($sameShift && $requesterShift) {
            $sql .= " AND u.work_shift = ?";
            $params[] = $requesterShift;
        }

        $sql .= " ORDER BY u.last_name, u.first_name";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        usersJson(['status' => 'ok', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /users/upload-photo
// Body: multipart/form-data with 'photo' file field
// ============================================================================
if ($cleanPath === '/users/upload-photo' && $method === 'POST') {
    try {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            usersJson(['status' => 'error', 'message' => 'No photo uploaded or upload error'], 400);
        }

        $file = $_FILES['photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if (!in_array($file['type'], $allowed)) {
            usersJson(['status' => 'error', 'message' => 'Only JPG, PNG, WEBP allowed'], 400);
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            usersJson(['status' => 'error', 'message' => 'Max 2MB'], 400);
        }

        $uploadDir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $dest = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            usersJson(['status' => 'error', 'message' => 'Failed to save file'], 500);
        }

        $photoUrl = '/uploads/avatars/' . $filename;

        // Delete old photo if exists
        $st = $conn->prepare("SELECT profile_photo_url FROM users WHERE user_id = ?");
        $st->execute([$userId]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if ($old && $old['profile_photo_url']) {
            $oldPath = __DIR__ . '/..' . $old['profile_photo_url'];
            if (file_exists($oldPath)) unlink($oldPath);
        }

        $stmt = $conn->prepare("UPDATE users SET profile_photo_url = ? WHERE user_id = ?");
        $stmt->execute([$photoUrl, $userId]);

        usersJson(['status' => 'ok', 'photo_url' => $photoUrl]);
    } catch (Exception $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// GET /users/me/photo
// Returns current user's photo URL
// ============================================================================
if ($cleanPath === '/users/me/photo' && $method === 'GET') {
    try {
        $stmt = $conn->prepare("SELECT profile_photo_url FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        usersJson(['status' => 'ok', 'photo_url' => $row['profile_photo_url'] ?? null]);
    } catch (Exception $e) {
        usersJson(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}
