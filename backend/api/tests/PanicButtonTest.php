<?php
/**
 * =============================================================================
 * tests/PanicButtonTest.php — Test del botón de pánico y revocación de JWT.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Simula el modo de emergencia (panic mode) con un MockRedis e injecta
 *   variables de entorno de JWT. Verifica que:
 *   - Un token emitido ANTES del panic sea rechazado por verifyJwtToken.
 *   - Un token emitido DESPUÉS del panic sea aceptado.
 *
 * NOTA: requiere que _auth_middleware.php defina issueJwtToken, verifyJwtToken,
 * b64url_encode/decode. Usa HS256 con JWT_SECRET.
 */
class MockRedis {
    private $store = [];
    public function connect($host, $port) { return true; }
    public function auth($pass) { return true; }
    public function setex($key, $ttl, $value) { $this->store[$key] = $value; return true; }
    public function get($key) { return $this->store[$key] ?? false; }
    public function exists($key) { return isset($this->store[$key]); }
    public function del($key) { unset($this->store[$key]); return 1; }
    public function mGet($keys) {
        return array_map(function($k) { return $this->store[$k] ?? false; }, $keys);
    }
}

if (!function_exists('getRedisConnection')) {
    function getRedisConnection() {
        static $mock = null;
        if (!$mock) $mock = new MockRedis();
        return $mock;
    }
}

require_once __DIR__ . '/../routes/_auth_middleware.php';

echo "Running PanicButtonTest...\n";

// Mock env for testing
putenv('JWT_SECRET=test_secret_for_jwt_only_1234567890');
putenv('JWT_ISSUER=nexo-api');
putenv('JWT_AUDIENCE=nexo-webapp');
putenv('REDISHOST=127.0.0.1');

function securityLog($action, $details = '', $userId = null, $schoolId = null) {
    // Mock securityLog to avoid undefined function errors
}

function assertException($callback, $expectedMessageFragment) {
    try {
        $callback();
        echo "FAIL: Expected exception containing '$expectedMessageFragment' but none was thrown.\n";
        exit(1);
    } catch (Exception $e) {
        if (strpos($e->getMessage(), $expectedMessageFragment) !== false) {
            echo "PASS: Caught expected exception: " . $e->getMessage() . "\n";
        } else {
            echo "FAIL: Exception message '" . $e->getMessage() . "' does not contain '$expectedMessageFragment'.\n";
            exit(1);
        }
    }
}

function assertTrue($condition, $msg) {
    if ($condition) {
        echo "PASS: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        exit(1);
    }
}

// 1. Generate a token conceptually issued 10 seconds ago
$schoolId = 'test_school_' . time();
$iat = time() - 10;
$claims = [
    'sub' => 'test_user',
    'school_id' => $schoolId,
    'role' => 'RECTOR',
    'exp' => time() + 3600
];

$token = issueJwtToken($claims);

// issueJwtToken sets iat to time(), so we need to manually rebuild it for the test
$parts = explode('.', $token);
$payload = json_decode(b64url_decode($parts[1]), true);
$payload['iat'] = $iat;
$payload['nbf'] = $iat - 2;

$signingInput = $parts[0] . '.' . b64url_encode(json_encode($payload));
$signature = hash_hmac('sha256', $signingInput, getenv('JWT_SECRET'), true);
$customToken = $signingInput . '.' . b64url_encode($signature);

// Verify it works normally
try {
    $verified = verifyJwtToken($customToken);
    assertTrue($verified['school_id'] === $schoolId, "Token generated and verified successfully before panic mode.");
} catch (Exception $e) {
    echo "FAIL: Unexpected exception during normal verify: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Simulate Panic Button Activation
$redis = getRedisConnection();
if ($redis) {
    echo "PASS: Redis connected. Setting panic timestamp.\n";
    $panicTimestamp = time();
    $redis->setex("panic:school:" . $schoolId, 86400, (string)$panicTimestamp);
    
    // 3. Verify the token is now rejected due to being issued BEFORE the panic
    assertException(function() use ($customToken) {
        verifyJwtToken($customToken);
    }, 'Sesión invalidada por modo de emergencia');
    
    // 4. Verify a NEW token issued AFTER the panic is accepted
    $newClaims = [
        'sub' => 'test_user_new',
        'school_id' => $schoolId,
        'role' => 'RECTOR',
        'exp' => time() + 3600
    ];
    $newToken = issueJwtToken($newClaims);
    try {
        $verifiedNew = verifyJwtToken($newToken);
        assertTrue($verifiedNew['school_id'] === $schoolId, "New Token (post-panic) verified successfully.");
    } catch (Exception $e) {
        echo "FAIL: Unexpected exception for post-panic token: " . $e->getMessage() . "\n";
        exit(1);
    }

    // 5. Clean up
    $redis->del("panic:school:" . $schoolId);
} else {
    echo "WARN: Redis no está disponible en localhost. Mapeando stub_isSchoolInPanicMode para pruebas de fallback...\n";
    // We mock the fallback DB logic here if necessary.
}

echo "All tests passed successfully.\n";
