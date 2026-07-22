<?php
/**
 * =============================================================================
 * redis.php — Conexión unificada a Redis con soporte Upstash TLS (rediss://).
 * =============================================================================
 *
 * RESPONSABILIDAD DEL ARCHIVO
 * ----------------------------
 * Expone getRedisConnection(): una única función para conectar workers,
 * middleware y endpoints a Redis. Soporta:
 *   - REDIS_URL (rediss://, redis://, tls://) con prioridad absoluta.
 *   - REDISHOST / REDISPORT / REDIS_PASSWORD / REDIS_USER / REDIS_DB.
 *   - Conexiones TLS a Upstash usando el prefijo tls:// y contexto SSL.
 *   - Fallback a Redis local en 127.0.0.1:6379.
 *
 * Si no puede conectar, retorna null (fail-open para la API). Los workers
 * deben verificar el valor de retorno y salir si es null.
 */

if (!function_exists('getRedisConnection')) {

    /**
     * Resuelve la configuración Redis desde REDIS_URL o variables sueltas.
     *
     * @return array{
     *     tls: bool,
     *     host: string,
     *     port: int,
     *     user: string,
     *     pass: string,
     *     db: int|null,
     *     timeout: float,
     *     read_timeout: float
     * }
     */
    function _nexoResolveRedisConfig(): array {
        $url = getenv('REDIS_URL');
        if ($url !== false && trim($url) !== '') {
            $parts = parse_url($url);
            if (!$parts || !isset($parts['host'])) {
                throw new RedisException('REDIS_URL is malformed: ' . $url);
            }

            $scheme = strtolower($parts['scheme'] ?? 'rediss');
            $tls = in_array($scheme, ['rediss', 'tls'], true);

            $host = $parts['host'];
            $port = (int)($parts['port'] ?? 6379);
            $user = isset($parts['user']) ? urldecode($parts['user']) : '';
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

            $db = null;
            if (!empty($parts['path'])) {
                $pathDb = ltrim($parts['path'], '/');
                if ($pathDb !== '' && is_numeric($pathDb)) {
                    $db = (int)$pathDb;
                }
            }
            parse_str($parts['query'] ?? '', $query);
            if (isset($query['db']) && is_numeric($query['db'])) {
                $db = (int)$query['db'];
            }

            // Si REDIS_URL no especifica DB, se permite sobrescribir con REDIS_DB.
            if ($db === null) {
                $dbEnv = getenv('REDIS_DB');
                if ($dbEnv !== false && $dbEnv !== '' && is_numeric($dbEnv)) {
                    $db = (int)$dbEnv;
                }
            }

            return [
                'tls'          => $tls,
                'host'         => $host,
                'port'         => $port,
                'user'         => $user,
                'pass'         => $pass,
                'db'           => $db,
                'timeout'      => (float)(getenv('REDIS_CONNECT_TIMEOUT') ?: 0.5),
                'read_timeout' => (float)(getenv('REDIS_READ_TIMEOUT') ?: 0.0),
            ];
        }

        // Fallback a variables individuales (Redis local / variables sueltas).
        $host = getenv('REDISHOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDISPORT') ?: 6379);
        $pass = getenv('REDIS_PASSWORD') ?: '';
        $user = getenv('REDIS_USER') ?: '';

        $tls = false;
        if (preg_match('/^(rediss|tls):\/\//i', $host)) {
            $tls = true;
            $parsed = parse_url($host);
            if ($parsed) {
                $host = $parsed['host'] ?? $host;
                $port = (int)($parsed['port'] ?? $port);
            }
        }
        if (getenv('REDIS_TLS')) {
            $tlsEnv = strtolower(trim(getenv('REDIS_TLS')));
            $tls = ($tlsEnv !== '' && $tlsEnv !== 'false' && $tlsEnv !== '0' && $tlsEnv !== 'no' && $tlsEnv !== 'off');
        }

        $dbEnv = getenv('REDIS_DB');
        $db = ($dbEnv !== false && $dbEnv !== '' && is_numeric($dbEnv)) ? (int)$dbEnv : null;

        return [
            'tls'          => $tls,
            'host'         => $host,
            'port'         => $port,
            'user'         => $user,
            'pass'         => $pass,
            'db'           => $db,
            'timeout'      => (float)(getenv('REDIS_CONNECT_TIMEOUT') ?: 0.5),
            'read_timeout' => (float)(getenv('REDIS_READ_TIMEOUT') ?: 0.0),
        ];
    }

    /**
     * Retorna una conexión singleton a Redis (o null si no está disponible).
     *
     * Soporta Upstash TLS cuando REDIS_URL comienza con rediss:// o cuando
     * REDISHOST empieza con tls:// / rediss:// / REDIS_TLS=true.
     *
     * @return Redis|null Instancia conectada, o null en caso de fallo.
     */
    function getRedisConnection(): ?Redis {
        static $redis = null;
        static $attempted = false;

        if ($redis !== null) {
            return $redis;
        }
        if ($attempted) {
            return null;
        }
        $attempted = true;

        try {
            if (!class_exists('Redis')) {
                return null;
            }

            $config = _nexoResolveRedisConfig();

            $redisInstance = new Redis();
            $context = [];

            if ($config['tls']) {
                $verifyPeer = getenv('REDIS_SSL_VERIFY_PEER');
                $verify = true;
                if ($verifyPeer !== false) {
                    $verify = filter_var($verifyPeer, FILTER_VALIDATE_BOOLEAN);
                }

                $context['stream'] = [
                    'verify_peer'      => $verify,
                    'verify_peer_name' => $verify,
                    'peer_name'        => $config['host'],
                    'SNI_enabled'      => true,
                ];
            }

            $host = $config['tls'] ? 'tls://' . $config['host'] : $config['host'];

            $connected = $redisInstance->connect(
                $host,
                $config['port'],
                $config['timeout'],
                null,
                0,
                $config['read_timeout'],
                $context
            );

            if (!$connected) {
                throw new RedisException("Could not connect to Redis at {$host}:{$config['port']}");
            }

            if ($config['pass'] !== '') {
                if ($config['user'] !== '' && $config['user'] !== 'default') {
                    $redisInstance->auth(['user' => $config['user'], 'pass' => $config['pass']]);
                } else {
                    $redisInstance->auth($config['pass']);
                }
            }

            // Seleccionar DB solo si es distinta de 0 (default).
            if (isset($config['db']) && $config['db'] !== 0) {
                $redisInstance->select((int)$config['db']);
            }

            $redis = $redisInstance;
            return $redis;
        } catch (Throwable $e) {
            $redis = null;
            error_log('Redis connection error: ' . $e->getMessage());
            return null;
        }
    }
}
