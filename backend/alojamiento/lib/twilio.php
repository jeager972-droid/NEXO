<?php
/**
 * lib/twilio.php — Helpers compartidos de Twilio para NEXO
 *
 * Utilizado por:
 *   - routes/operations.php  (envíos síncronos en requests HTTP)
 *   - worker_twilio.php       (procesamiento asíncrono de la cola)
 *
 * NUNCA incluir esto directamente desde rutas públicas.
 * Requiere: $conn (PDO) en contexto global o pasado explícitamente.
 */

// ── Normalización de teléfono ─────────────────────────────────────────────────

/**
 * Normaliza un número de teléfono al formato +XX... para WhatsApp.
 * Acepta: "+573001234567", "whatsapp:+573001234567", "573001234567"
 */
function normalizeWhatsAppPhone(string $value): string {
    $value = trim($value);
    $value = preg_replace('/^whatsapp:/i', '', $value);
    if ($value === '') return '';
    if ($value[0] !== '+') $value = '+' . $value;
    return preg_replace('/[^0-9+]/', '', $value);
}

// ── URL del webhook de status ─────────────────────────────────────────────────

function getTwilioStatusCallbackUrl(): ?string {
    $base = getenv('TWILIO_WEBHOOK_URL_BASE') ?: getenv('APP_URL') ?: '';
    if ($base === '') return null;
    return rtrim($base, '/') . '/v1/webhooks/twilio/status';
}

// ── Envío directo a la API de Twilio ─────────────────────────────────────────

/**
 * Envía un mensaje WhatsApp directamente vía API de Twilio.
 * Intenta mensaje de sesión primero; si recibe 63015/63016 (fuera de ventana 24h),
 * reintenta con template configurado en TWILIO_WHATSAPP_TEMPLATE_SID.
 *
 * @return array{ok: bool, error: ?string, sid: ?string}
 */
function sendTwilioDirect(string $to, string $body): array {
    $sid   = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $from  = getenv('TWILIO_WHATSAPP_FROM') ?: getenv('TWILIO_FROM_NUMBER');

    if (!$sid || !$token || !$from) {
        $missing = array_filter([
            !$sid   ? 'TWILIO_ACCOUNT_SID' : null,
            !$token ? 'TWILIO_AUTH_TOKEN' : null,
            !$from  ? 'TWILIO_WHATSAPP_FROM (o TWILIO_FROM_NUMBER)' : null,
        ]);
        $err = 'Missing Twilio credentials: ' . implode(', ', $missing);
        securityLog('TWILIO_CREDENTIALS_MISSING', $err);
        error_log("[TWILIO] CREDENTIALS MISSING: " . implode(', ', $missing));
        return ['ok' => false, 'error' => $err, 'sid' => null];
    }

    $toNorm   = normalizeWhatsAppPhone($to);
    $fromNorm = normalizeWhatsAppPhone($from);

    if ($toNorm === '' || $fromNorm === '') {
        return ['ok' => false, 'error' => 'Número inválido', 'sid' => null];
    }

    error_log("[TWILIO] sendTwilioDirect from={$fromNorm} to={$toNorm} sid_prefix=" . substr($sid, 0, 6));

    $url     = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $payload = [
        'From' => "whatsapp:$fromNorm",
        'To'   => "whatsapp:$toNorm",
        'Body' => $body,
    ];

    $statusCallback = getTwilioStatusCallbackUrl();
    if ($statusCallback) $payload['StatusCallback'] = $statusCallback;

    $result = _twilioHttpPost($url, $payload, $sid, $token);

    // Reintentar con template si estamos fuera de la ventana de 24h
    if (!$result['ok'] && in_array($result['twilio_code'] ?? 0, [63015, 63016])) {
        $templateSid = getenv('TWILIO_WHATSAPP_TEMPLATE_SID');
        error_log("[TWILIO] Template fallback templateSid=" . ($templateSid ?: 'NOT_SET'));

        if ($templateSid) {
            $tmplPayload = [
                'From'             => "whatsapp:$fromNorm",
                'To'               => "whatsapp:$toNorm",
                'ContentSid'       => $templateSid,
                'ContentVariables' => json_encode(['1' => $body], JSON_UNESCAPED_UNICODE),
            ];
            if ($statusCallback) $tmplPayload['StatusCallback'] = $statusCallback;

            $tmplResult = _twilioHttpPost($url, $tmplPayload, $sid, $token);
            if ($tmplResult['ok']) {
                securityLog('TWILIO_TEMPLATE_FALLBACK_OK', "SID: {$tmplResult['sid']} To: $toNorm");
                return ['ok' => true, 'error' => null, 'sid' => $tmplResult['sid']];
            }
            return ['ok' => false, 'error' => "[{$result['twilio_code']}] Template fallback falló", 'sid' => null];
        }

        return ['ok' => false, 'error' => "[{$result['twilio_code']}] Fuera de ventana de 24h. Configura TWILIO_WHATSAPP_TEMPLATE_SID en Railway.", 'sid' => null];
    }

    if ($result['ok']) {
        securityLog('TWILIO_DIRECT_OK', "SID:{$result['sid']} To:$toNorm");
        error_log("[TWILIO] SUCCESS sid={$result['sid']}");
        return ['ok' => true, 'error' => null, 'sid' => $result['sid']];
    }

    securityLog('TWILIO_DIRECT_ERROR', "To:$toNorm HTTP:{$result['http_code']} Error:{$result['error']}");
    error_log("[TWILIO] FAILED: {$result['error']}");
    return ['ok' => false, 'error' => $result['error'], 'sid' => null];
}

/**
 * Ejecuta un POST HTTP a la API de Twilio. Interno — no usar directamente.
 * @internal
 */
function _twilioHttpPost(string $url, array $payload, string $sid, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_USERPWD        => "$sid:$token",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    error_log("[TWILIO] curl httpCode={$httpCode} curl_err=" . ($curlErr ?: 'none'));

    if ($response === false || $httpCode >= 400) {
        $json = $response ? (json_decode($response, true) ?? []) : [];
        $twilioCode = $json['code'] ?? $json['error_code'] ?? $httpCode;
        $detail     = $curlErr ?: "HTTP $httpCode";
        if ($response) $detail .= " | " . substr($response, 0, 500);
        return ['ok' => false, 'error' => $detail, 'http_code' => $httpCode, 'twilio_code' => $twilioCode, 'sid' => null];
    }

    $json = json_decode($response, true) ?? [];
    return ['ok' => true, 'error' => null, 'http_code' => $httpCode, 'twilio_code' => null, 'sid' => $json['sid'] ?? null];
}

// ── Log de mensaje Twilio en DB ───────────────────────────────────────────────

/**
 * Inserta un registro de mensaje Twilio en twilio_messages (fail-safe).
 * No lanza excepciones — los errores son logeados silenciosamente.
 */
function logTwilioMessage(
    $conn, string $schoolId, string $typeCode, string $direction,
    string $phone, string $content, array $meta = [],
    ?string $studentId = null, ?string $guardianId = null,
    ?string $senderUserId = null, ?string $providerSid = null,
    ?string $deliveryStatus = null
): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO twilio_messages (
                twilio_message_id, school_id, student_id, guardian_id, sender_user_id,
                type_code, direction, phone_number, message_content, provider_message_sid,
                delivery_status, sent_at, metadata_json
            ) VALUES (
                uuid_generate_v4(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?::jsonb
            )
        ");
        $stmt->execute([
            $schoolId, $studentId, $guardianId, $senderUserId,
            $typeCode, $direction, $phone, $content,
            $providerSid, $deliveryStatus,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        securityLog('TWILIO_LOG_ERROR', $e->getMessage());
    }
}
