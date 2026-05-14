<?php
global $cleanPath, $conn, $method;

if ($cleanPath === '/webhooks/twilio/status' && $method === 'POST') {
    if (function_exists('verifyTwilioSignature') && !verifyTwilioSignature()) {
        securityLog('TWILIO_WEBHOOK_REJECTED', 'Firma inválida en status callback');
        http_response_code(403);
        exit;
    }

    $messageSid = $_POST['MessageSid'] ?? '';
    $status = $_POST['MessageStatus'] ?? '';
    $errorCode = $_POST['ErrorCode'] ?? null;
    $errorMessage = $_POST['ErrorMessage'] ?? null;
    
    // Limpiar el prefijo 'whatsapp:' y asegurar formato
    $rawTo = $_POST['To'] ?? 'unknown';
    $to = preg_replace('/^whatsapp:/i', '', trim($rawTo));

    if ($messageSid && $status) {
        try {
            $errorJson = $errorCode ? json_encode(['code' => $errorCode, 'msg' => $errorMessage]) : '{}';
            
            $stmt = $conn->prepare("
                INSERT INTO twilio_messages (provider_message_sid, phone_number, direction, delivery_status, metadata_json, received_at)
                VALUES (?, ?, 'OUTBOUND', ?, jsonb_build_object('delivery_error', ?::jsonb), NOW())
                ON CONFLICT (provider_message_sid) DO UPDATE SET 
                    delivery_status = EXCLUDED.delivery_status,
                    metadata_json = jsonb_set(
                        COALESCE(twilio_messages.metadata_json, '{}'::jsonb), 
                        '{delivery_error}', 
                        EXCLUDED.metadata_json->'delivery_error'
                    )
            ");
            $stmt->execute([$messageSid, $to, strtoupper($status), $errorJson]);
            
            securityLog('TWILIO_STATUS_UPDATE', "SID: $messageSid, Status: $status, To: $to");
        } catch (Exception $e) {
            securityLog('TWILIO_STATUS_DB_ERROR', $e->getMessage());
        }
    }

    http_response_code(200);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<Response></Response>';
    exit;
}
