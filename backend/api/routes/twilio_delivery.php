<?php
global $cleanPath, $conn, $method;

// Health check endpoint para verificar que el webhook sea accesible
if ($cleanPath === '/webhooks/twilio/status' && $method === 'GET') {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Webhook endpoint is accessible']);
    exit;
}

if ($cleanPath === '/webhooks/twilio/status' && $method === 'POST') {
    // DEBUG: Log incoming webhook request
    error_log("[TWILIO_STATUS] Webhook received. SID: " . ($_POST['MessageSid'] ?? 'N/A') . " Status: " . ($_POST['MessageStatus'] ?? 'N/A'));
    error_log("[TWILIO_STATUS] Full POST data: " . json_encode($_POST));
    
    if (function_exists('verifyTwilioSignature') && !verifyTwilioSignature()) {
        error_log("[TWILIO_STATUS] Signature verification FAILED. Rejecting request.");
        securityLog('TWILIO_WEBHOOK_REJECTED', 'Firma inválida en status callback');
        http_response_code(403);
        exit;
    }
    error_log("[TWILIO_STATUS] Signature verification PASSED.");

    $messageSid = $_POST['MessageSid'] ?? '';
    $status = $_POST['MessageStatus'] ?? '';
    $errorCode = $_POST['ErrorCode'] ?? null;
    $errorMessage = $_POST['ErrorMessage'] ?? null;
    
    // Limpiar el prefijo 'whatsapp:' y asegurar formato
    $rawTo = $_POST['To'] ?? 'unknown';
    $to = preg_replace('/^whatsapp:/i', '', trim($rawTo));

    if ($messageSid && $status) {
        try {
            // BYPASS RLS: Es un proceso de sistema autenticado por HMAC, no un usuario
            $conn->query("SELECT set_config('app.current_role', 'SYSTEM_WORKER', true)");
            
            $errorJson = $errorCode ? json_encode(['code' => $errorCode, 'msg' => $errorMessage]) : '{}';
            
            $stmt = $conn->prepare("
                UPDATE twilio_messages 
                SET delivery_status = ?, 
                    metadata_json = jsonb_set(COALESCE(metadata_json, '{}'::jsonb), '{delivery_error}', ?::jsonb)
                WHERE provider_message_sid = ?
            ");
            $stmt->execute([strtoupper($status), $errorJson, $messageSid]);
            
            if ($stmt->rowCount() === 0) {
                securityLog('TWILIO_STATUS_DB_WARN', "Update affected 0 rows for SID: $messageSid");
            } else {
                securityLog('TWILIO_STATUS_UPDATE', "SID: $messageSid, Status: $status, To: $to");
            }
        } catch (Exception $e) {
            securityLog('TWILIO_STATUS_DB_ERROR', $e->getMessage());
        }
    } else {
        error_log("[TWILIO_STATUS] Missing required fields. MessageSid: $messageSid, Status: $status");
    }

    http_response_code(200);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<Response></Response>';
    exit;
}
