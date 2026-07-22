<?php
/**
 * =============================================================================
 * mqtt_publisher.php — Publicador de comandos a dispositivos EDGE vía MQTT.
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone publishDeviceCommand(), que publica un mensaje JSON en el tópico
 * `nexo/devices/{deviceId}/commands` usando php-mqtt/client. Si no puede
 * conectarse, retorna false para que el caller (routes/devices.php) haga
 * fallback a Redis o a otro mecanismo.
 *
 * FLUJO
 * -----
 *   publishDeviceCommand(deviceId, payload)
 *        │
 *        ▼
 *   Conectar a MQTT_HOST:MQTT_PORT (auth opcional)
 *        │
 *        ▼
 *   publish("nexo/devices/{deviceId}/commands", json(payload), QoS 1)
 *        │
 *        ▼
 *   disconnect()
 *
 * DEPENDENCIAS
 * ------------
 * Utiliza:
 *   - vendor/autoload.php y php-mqtt/client.
 *   - Variables de entorno: MQTT_HOST, MQTT_PORT, MQTT_USER, MQTT_PASS.
 *
 * Es utilizado por:
 *   - routes/devices.php : envío de comandos a edge devices (MQTT preferido, Redis fallback).
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

/**
 * Publica un comando JSON en el tópico MQTT del dispositivo indicado.
 *
 * @param string $deviceId UUID del edge device destino.
 * @param array $payload Comando a publicar (se codifica a JSON).
 * @return bool True si se publicó exitosamente; false en caso contrario.
 */
function publishDeviceCommand(string $deviceId, array $payload): bool {
    $host = getenv('MQTT_HOST') ?: 'localhost';
    $port = (int)(getenv('MQTT_PORT') ?: 1883);
    $user = getenv('MQTT_USER') ?: '';
    $pass = getenv('MQTT_PASS') ?: '';
    $clientId = 'nexo-cloud-' . getmypid();

    try {
        $settings = (new ConnectionSettings())
            ->setConnectTimeout(3)
            ->setKeepAliveInterval(10);

        if ($user) {
            $settings = $settings->setUsername($user)->setPassword($pass);
        }

        $mqtt = new MqttClient($host, $port, $clientId);
        $mqtt->connect($settings);
        $mqtt->publish("nexo/devices/{$deviceId}/commands", json_encode($payload, JSON_UNESCAPED_UNICODE), 1, false);
        $mqtt->disconnect();
        return true;
    } catch (Exception $e) {
        error_log("MQTT publish failed: " . $e->getMessage());
        return false;
    }
}
