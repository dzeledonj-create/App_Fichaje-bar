<?php
// ============================================================
//  models/User.php — Modelo de usuarios
// ============================================================

declare(strict_types=1);

namespace Models;

use Config\Database;
use PDO;
use PDOException;

class User
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $this->normalizeEmail($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios
                (nombre, apellidos, dni_nie, email, password_hash, rol)
             VALUES
                (:nombre, :apellidos, :dni_nie, :email, :password_hash, :rol)
             RETURNING id'
        );

        $stmt->execute([
            ':nombre'        => trim($data['nombre']),
            ':apellidos'     => trim($data['apellidos']),
            ':dni_nie'       => strtoupper(trim($data['dni_nie'])),
            ':email'         => $this->normalizeEmail($data['email']),
            ':password_hash' => $this->hashPassword($data['password']),
            ':rol'           => $data['rol'] ?? 'empleado',
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET activo = :activo WHERE id = :id'
        );

        return $stmt->execute([
            ':activo' => $active,
            ':id'     => $id,
        ]);
    }

    public function getAllEmployees(): array
    {
        $stmt = $this->db->query(
            'SELECT id, nombre, apellidos, dni_nie, email, rol, activo
             FROM usuarios
             ORDER BY apellidos, nombre'
        );
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuarios WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE usuarios
             SET nombre = :nombre,
                 apellidos = :apellidos,
                 dni_nie = :dni_nie,
                 rol = :rol
             WHERE id = :id'
        );

        return $stmt->execute([
            ':nombre'    => trim($data['nombre']),
            ':apellidos' => trim($data['apellidos']),
            ':dni_nie'   => strtoupper(trim($data['dni_nie'])),
            ':rol'       => $data['rol'],
            ':id'        => $id,
        ]);
    }

    private function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
