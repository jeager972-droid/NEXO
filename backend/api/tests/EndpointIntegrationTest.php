<?php
/**
 * =============================================================================
 * tests/EndpointIntegrationTest.php — Tests de integración de endpoints API
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica que todos los endpoints principales de la API respondan
 *   correctamente: autenticación, dashboard, operations, users, audit,
 *   consultations, devices, students, tracking, behavior, metrics.
 *
 *   Requiere: backend corriendo (local o Render) y seed aplicado.
 *   Ejecutar: php tests/EndpointIntegrationTest.php
 *
 * CONFIGURACIÓN:
 *   Setear API_BASE_URL env var o usar default http://localhost:8080
 *   Credenciales del seed: admin@nexo.edu / admin123
 * =============================================================================
 */

$baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8080';
$testEmail = 'admin@nexo.edu';
$testPassword = 'admin123';

$passed = 0;
$failed = 0;
$skipped = 0;

function logPass($msg) {
    global $passed;
    echo "  \033[32mPASS\033[0m: $msg\n";
    $passed++;
}

function logFail($msg, $expected = '', $actual = '') {
    global $failed;
    echo "  \033[31mFAIL\033[0m: $msg";
    if ($expected) echo " (expected: $expected, got: $actual)";
    echo "\n";
    $failed++;
}

function logSkip($msg) {
    global $skipped;
    echo "  \033[33mSKIP\033[0m: $msg\n";
    $skipped++;
}

function httpRequest($url, $method = 'GET', $body = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($token) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);
    }

    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['code' => 0, 'body' => null, 'error' => $error];
    }

    return ['code' => $httpCode, 'body' => json_decode($response, true), 'error' => null];
}

echo "\n\033[36m╔══════════════════════════════════════════════════════╗\n";
echo "║  NEXO API Endpoint Integration Tests                 ║\n";
echo "╚══════════════════════════════════════════════════════╝\033[0m\n\n";
echo "Base URL: $baseUrl\n\n";

// =============================================================================
// 1. HEALTH CHECK
// =============================================================================
echo "\033[34m[1] Health Check\033[0m\n";
$resp = httpRequest("$baseUrl/health");
if ($resp['code'] === 200) {
    logPass("GET /health returns 200");
} elseif ($resp['code'] > 0) {
    logFail("GET /health", "200", $resp['code']);
} else {
    logSkip("GET /health - API not reachable: " . $resp['error']);
    echo "\n\033[31mAPI no disponible. Abortando tests.\033[0m\n";
    exit(1);
}

// =============================================================================
// 2. AUTHENTICATION
// =============================================================================
echo "\n\033[34m[2] Authentication\033[0m\n";

// Login correcto
$resp = httpRequest("$baseUrl/auth/login", 'POST', [
    'email' => $testEmail,
    'password' => $testPassword
]);
if ($resp['code'] === 200 && isset($resp['body']['token'])) {
    logPass("POST /auth/login (admin@nexo.edu) returns 200 + token");
    $token = $resp['body']['token'];
} else {
    logFail("POST /auth/login", "200 + token", $resp['code'] . ' ' . json_encode($resp['body']));
    $token = null;
}

// Login con credenciales incorrectas
$resp = httpRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'wrong@test.com',
    'password' => 'wrongpass'
]);
if ($resp['code'] === 401 || $resp['code'] === 400) {
    logPass("POST /auth/login (wrong creds) returns " . $resp['code']);
} else {
    logFail("POST /auth/login (wrong creds)", "401 or 400", $resp['code']);
}

// Auth/me con token válido
if ($token) {
    $resp = httpRequest("$baseUrl/auth/me", 'GET', null, $token);
    if ($resp['code'] === 200 && isset($resp['body']['user'])) {
        logPass("GET /auth/me with valid token returns 200 + user");
    } else {
        logFail("GET /auth/me with valid token", "200 + user", $resp['code'] . ' ' . json_encode($resp['body']));
    }
}

// Auth/me sin token
$resp = httpRequest("$baseUrl/auth/me", 'GET');
if ($resp['code'] === 401 || $resp['code'] === 400) {
    logPass("GET /auth/me without token returns " . $resp['code']);
} else {
    logFail("GET /auth/me without token", "401 or 400", $resp['code']);
}

// =============================================================================
// 3. DASHBOARD
// =============================================================================
echo "\n\033[34m[3] Dashboard\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/dashboard/metrics", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /dashboard/metrics returns 200");
    } else {
        logFail("GET /dashboard/metrics", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }

    $resp = httpRequest("$baseUrl/dashboard/today-summary", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /dashboard/today-summary returns 200");
    } else {
        logFail("GET /dashboard/today-summary", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Dashboard tests (no token)");
}

// =============================================================================
// 4. STUDENTS
// =============================================================================
echo "\n\033[34m[4] Students\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/students", 'GET', null, $token);
    if ($resp['code'] === 200 && isset($resp['body']['data'])) {
        logPass("GET /students returns 200 + data (count: " . count($resp['body']['data']) . ")");
    } elseif ($resp['code'] === 200) {
        logPass("GET /students returns 200");
    } else {
        logFail("GET /students", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Students tests (no token)");
}

// =============================================================================
// 5. OPERATIONS
// =============================================================================
echo "\n\033[34m[5] Operations\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/operations", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /operations returns " . $resp['code']);
    } else {
        logFail("GET /operations", "200 or 400", $resp['code']);
    }

    // Operations por grupo
    $resp = httpRequest("$baseUrl/operations?group_id=a10aaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /operations?group_id=... returns " . $resp['code']);
    } else {
        logFail("GET /operations?group_id", "200 or 400", $resp['code']);
    }
} else {
    logSkip("Operations tests (no token)");
}

// =============================================================================
// 6. USERS
// =============================================================================
echo "\n\033[34m[6] Users\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/users", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /users returns 200");
    } else {
        logFail("GET /users", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }

    $resp = httpRequest("$baseUrl/users/profile", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /users/profile returns 200");
    } else {
        logFail("GET /users/profile", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Users tests (no token)");
}

// =============================================================================
// 7. AUDIT
// =============================================================================
echo "\n\033[34m[7] Audit\033[0m\n";
if ($token) {
    $endpoints = [
        '/audit/attendance/general',
        '/audit/discipline/incidents',
        '/audit/permissions/exit',
        '/audit/security/failed-attempts',
        '/audit/sos/alerts',
        '/audit/global',
        '/audit/integrity',
    ];
    foreach ($endpoints as $ep) {
        $resp = httpRequest("$baseUrl$ep", 'GET', null, $token);
        if ($resp['code'] === 200) {
            logPass("GET $ep returns 200");
        } elseif ($resp['code'] === 403) {
            logSkip("GET $ep returns 403 (role not authorized)");
        } else {
            logFail("GET $ep", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
        }
    }
} else {
    logSkip("Audit tests (no token)");
}

// =============================================================================
// 8. CONSULTATIONS
// =============================================================================
echo "\n\033[34m[8] Consultations\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/consultations/students", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /consultations/students returns " . $resp['code']);
    } else {
        logFail("GET /consultations/students", "200 or 400", $resp['code']);
    }
} else {
    logSkip("Consultations tests (no token)");
}

// =============================================================================
// 9. DEVICES
// =============================================================================
echo "\n\033[34m[9] Devices\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/devices", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /devices returns 200");
    } else {
        logFail("GET /devices", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Devices tests (no token)");
}

// =============================================================================
// 10. TRACKING
// =============================================================================
echo "\n\033[34m[10] Tracking\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/tracking", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /tracking returns 200");
    } else {
        logFail("GET /tracking", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Tracking tests (no token)");
}

// =============================================================================
// 11. BEHAVIOR
// =============================================================================
echo "\n\033[34m[11] Behavior\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/behavior/risk", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /behavior/risk returns 200");
    } else {
        logFail("GET /behavior/risk", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Behavior tests (no token)");
}

// =============================================================================
// 12. METRICS
// =============================================================================
echo "\n\033[34m[12] Metrics\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/metrics/summary", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /metrics/summary returns " . $resp['code']);
    } else {
        logFail("GET /metrics/summary", "200 or 400", $resp['code']);
    }
} else {
    logSkip("Metrics tests (no token)");
}

// =============================================================================
// 13. GROUPS
// =============================================================================
echo "\n\033[34m[13] Groups\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/groups", 'GET', null, $token);
    if ($resp['code'] === 200) {
        logPass("GET /groups returns 200");
    } else {
        logFail("GET /groups", "200", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
} else {
    logSkip("Groups tests (no token)");
}

// =============================================================================
// 14. SECURITY - Sin token debe rechazar
// =============================================================================
echo "\n\033[34m[14] Security (sin token)\033[0m\n";
$protectedEndpoints = [
    '/dashboard/metrics',
    '/students',
    '/users',
    '/operations',
    '/devices',
    '/tracking',
    '/behavior/risk',
    '/groups',
];
foreach ($protectedEndpoints as $ep) {
    $resp = httpRequest("$baseUrl$ep", 'GET');
    if ($resp['code'] === 401 || $resp['code'] === 400) {
        logPass("GET $ep sin token returns " . $resp['code']);
    } else {
        logFail("GET $ep sin token", "401 or 400", $resp['code']);
    }
}

// =============================================================================
// 15. SECURITY PANIC
// =============================================================================
echo "\n\033[34m[15] Security Panic\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/security/panic/status", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /security/panic/status returns " . $resp['code']);
    } else {
        logFail("GET /security/panic/status", "200 or 400", $resp['code']);
    }
} else {
    logSkip("Security panic tests (no token)");
}

// =============================================================================
// 16. TELEMETRY
// =============================================================================
echo "\n\033[34m[16] Telemetry\033[0m\n";
if ($token) {
    $resp = httpRequest("$baseUrl/telemetry", 'GET', null, $token);
    if ($resp['code'] === 200 || $resp['code'] === 400) {
        logPass("GET /telemetry returns " . $resp['code']);
    } else {
        logFail("GET /telemetry", "200 or 400", $resp['code']);
    }
} else {
    logSkip("Telemetry tests (no token)");
}

// =============================================================================
// 17. LOGIN con otros usuarios del seed
// =============================================================================
echo "\n\033[34m[17] Login con otros usuarios del seed\033[0m\n";
$seedUsers = [
    ['rector@nexo.edu', 'admin123', 'RECTOR'],
    ['coordinador@nexo.edu', 'admin123', 'COORDINATOR'],
    ['docente@nexo.edu', 'admin123', 'TEACHER'],
    ['psicorientador@nexo.edu', 'admin123', 'COUNSELOR'],
    ['secretaria@nexo.edu', 'admin123', 'SECRETARY'],
    ['portero@nexo.edu', 'admin123', 'SECURITY'],
    ['auxiliar@nexo.edu', 'admin123', 'AUXILIARY'],
];
foreach ($seedUsers as [$email, $pass, $role]) {
    $resp = httpRequest("$baseUrl/auth/login", 'POST', ['email' => $email, 'password' => $pass]);
    if ($resp['code'] === 200 && isset($resp['body']['token'])) {
        logPass("Login $email ($role) returns 200 + token");
    } else {
        logFail("Login $email ($role)", "200 + token", $resp['code'] . ' ' . json_encode($resp['body'] ?? ''));
    }
}

// =============================================================================
// RESUMEN
// =============================================================================
echo "\n\033[36m╔══════════════════════════════════════════════════════╗\n";
echo "║  RESUMEN DE TESTS                                     ║\n";
echo "╠══════════════════════════════════════════════════════╣\n";
printf("║  \033[32mPASSED: %-3d\033[0m  \033[31mFAILED: %-3d\033[0m  \033[33mSKIPPED: %-3d\033[0m  ║\n", $passed, $failed, $skipped);
echo "╚══════════════════════════════════════════════════════╝\033[0m\n\n";

exit($failed > 0 ? 1 : 0);
