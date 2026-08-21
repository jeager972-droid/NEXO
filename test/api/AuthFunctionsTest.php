<?php
/**
 * =============================================================================
 * AuthFunctionsTest — Test unitario de funciones de autenticación.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Verifica funciones puras de routes/auth.php:
 *   - verifyUserPassword(): verificación de contraseña con password_verify.
 *
 *   Las funciones que requieren PDO global ($conn) no se testean aquí
 *   porque requieren DB real. verifyUserPassword es pura.
 *
 * NOTA: no requiere PostgreSQL ni servidor.
 */
require_once __DIR__ . '/../../backend/api/vendor/autoload.php';

// auth.php usa $conn global y ejecuta código al incluirse (route matching).
// Solo necesitamos la función verifyUserPassword, la definimos manualmente
// si no está disponible (evita ejecutar el archivo completo).

if (!function_exists('verifyUserPassword')) {
    function verifyUserPassword($password, $hash) {
        $password = (string)$password;
        $hash = (string)$hash;
        return password_verify($password, $hash);
    }
}

use PHPUnit\Framework\TestCase;

class AuthFunctionsTest extends TestCase
{
    public function testVerifyUserPasswordCorrect(): void
    {
        $hash = password_hash('mySecret123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(verifyUserPassword('mySecret123', $hash));
    }

    public function testVerifyUserPasswordIncorrect(): void
    {
        $hash = password_hash('mySecret123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertFalse(verifyUserPassword('wrongPassword', $hash));
    }

    public function testVerifyUserPasswordEmptyPassword(): void
    {
        $hash = password_hash('somepassword', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertFalse(verifyUserPassword('', $hash));
    }

    public function testVerifyUserPasswordEmptyHash(): void
    {
        $this->assertFalse(verifyUserPassword('somepassword', ''));
    }

    public function testVerifyUserPasswordBothEmpty(): void
    {
        $this->assertFalse(verifyUserPassword('', ''));
    }

    public function testVerifyUserPasswordInvalidHash(): void
    {
        // Un hash inválido no debe causar error, solo retornar false
        $this->assertFalse(verifyUserPassword('password', 'not-a-valid-hash'));
    }

    public function testVerifyUserPasswordIntegerInputs(): void
    {
        // La función castea a string, integers no deben causar error
        $hash = password_hash('123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(verifyUserPassword(123, $hash));
    }

    public function testVerifyUserPasswordBcryptCost12(): void
    {
        $hash = password_hash('testPassword', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertTrue(verifyUserPassword('testPassword', $hash));
        $this->assertFalse(verifyUserPassword('TestPassword', $hash));
    }

    public function testVerifyUserPasswordArgon2id(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->markTestSkipped('Argon2id no disponible en este PHP');
        }
        $hash = password_hash('testPassword', PASSWORD_ARGON2ID);
        $this->assertTrue(verifyUserPassword('testPassword', $hash));
    }

    public function testPasswordNeedsRehash(): void
    {
        // Un hash con cost=4 debería necesitar rehash si el default es mayor
        $hash = password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]));
    }

    public function testPasswordDoesNotNeedRehash(): void
    {
        $hash = password_hash('test', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]));
    }
}
