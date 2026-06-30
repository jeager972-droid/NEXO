<?php
/**
 * mqtt_publisher.php — Helper para publicar comandos a dispositivos EDGE via MQTT.
 * Usado por routes/devices.php en lugar de Redis para V2 (Pub/Sub).
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

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
