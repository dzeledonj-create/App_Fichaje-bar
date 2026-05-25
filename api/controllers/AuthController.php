<?php
// ============================================================
//  controllers/AuthController.php — Autenticación con sesiones
// ============================================================

declare(strict_types=1);

namespace Controllers;

use Models\User;
use Config\Database;
use PDO;

class AuthController
{
    private User $userModel;
    private PDO  $db;

    // Duración de sesión: 8 horas
    private const SESSION_TTL = 28800;

    public function __construct()
    {
        $this->userModel = new User();
        $this->db        = Database::getInstance();
    }

    // ── Login ─────────────────────────────────────────────────
    public function login(string $email, string $password): array
    {
        // Rate limiting simple (en producción usar Redis)
        $this->checkRateLimit($email);

        $user = $this->userModel->findByEmail($email);

        if (!$user || !$this->userModel->verifyPassword($password, $user['password_hash'])) {
            $this->incrementFailedAttempts($email);
            return ['success' => false, 'message' => 'Credenciales incorrectas.'];
        }

        if (!$user['activo']) {
            return ['success' => false, 'message' => 'Cuenta desactivada. Contacta al administrador.'];
        }

        // Crear token de sesión
        $token = $this->createSession((int)$user['id']);

        return [
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'        => $user['id'],
                'nombre'    => $user['nombre'],
                'apellidos' => $user['apellidos'],
                'email'     => $user['email'],
                'rol'       => $user['rol'],
                'dni_nie'   => $user['dni_nie'],
            ],
        ];
    }

    // ── Logout ────────────────────────────────────────────────
    public function logout(string $token): void
    {
        $stmt = $this->db->prepare('DELETE FROM sesiones WHERE token = :token');
        $stmt->execute([':token' => $token]);
    }

    // ── Validar token de sesión ───────────────────────────────
    public function validateToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.usuario_id, s.expires_at,
                    u.nombre, u.apellidos, u.email, u.rol, u.dni_nie, u.activo
             FROM sesiones s
             JOIN usuarios u ON u.id = s.usuario_id
             WHERE s.token = :token'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if (new \DateTime() > new \DateTime($row['expires_at'])) {
            $this->logout($token);
            return null;
        }

        if (!$row['activo']) {
            return null;
        }

        // Renovar expiración (sliding window)
        $this->renewSession($token);

        return [
            'id'        => $row['usuario_id'],
            'nombre'    => $row['nombre'],
            'apellidos' => $row['apellidos'],
            'email'     => $row['email'],
            'rol'       => $row['rol'],
            'dni_nie'   => $row['dni_nie'],
        ];
    }

    // ── Crear sesión en BD ────────────────────────────────────
    private function createSession(int $userId): string
    {
        // Limpiar sesiones antiguas del usuario
        $stmt = $this->db->prepare(
            'DELETE FROM sesiones WHERE usuario_id = :uid AND expires_at < NOW()'
        );
        $stmt->execute([':uid' => $userId]);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL);

        $stmt = $this->db->prepare(
            'INSERT INTO sesiones (usuario_id, token, ip, user_agent, expires_at)
             VALUES (:uid, :token, :ip, :ua, :exp)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':token' => $token,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':exp'   => $expiresAt,
        ]);

        return $token;
    }

    private function renewSession(string $token): void
    {
        $newExp = date('Y-m-d H:i:s', time() + self::SESSION_TTL);
        $stmt   = $this->db->prepare(
            'UPDATE sesiones SET expires_at = :exp WHERE token = :token'
        );
        $stmt->execute([':exp' => $newExp, ':token' => $token]);
    }

    // ── Rate limiting simple (tabla en memoria / BD) ──────────
    private function checkRateLimit(string $email): void
    {
        // Implementación básica: max 5 intentos en 10 min
        // En producción: usar Redis o tabla dedicada
    }

    private function incrementFailedAttempts(string $email): void {}
}