<?php
// ============================================================
//  models/TimeRecord.php — Modelo de Registros de Horario
// ============================================================

declare(strict_types=1);

namespace Models;

use Config\Database;
use PDO;

class TimeRecord
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── ¿Tiene turno abierto? ─────────────────────────────────
    public function getOpenShift(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM registros_horario
             WHERE usuario_id = :uid AND hora_salida IS NULL
             ORDER BY hora_entrada DESC
             LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ── Registrar ENTRADA ─────────────────────────────────────
    public function clockIn(int $userId, array $firma): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO registros_horario
                (usuario_id, hora_entrada,
                 firma_entrada_nombre, firma_entrada_apellidos, firma_entrada_dni)
             VALUES
                (:uid, NOW(),
                 :fn, :fa, :fd)
             RETURNING id'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':fn'  => trim($firma['nombre']),
            ':fa'  => trim($firma['apellidos']),
            ':fd'  => strtoupper(trim($firma['dni'])),
        ]);
        return (int) $stmt->fetchColumn();
    }

    // ── Registrar SALIDA ──────────────────────────────────────
    public function clockOut(int $recordId, int $userId, array $firma): bool
    {
        $stmt = $this->db->prepare(
            'SELECT hora_entrada FROM registros_horario
             WHERE id = :rid AND usuario_id = :uid AND hora_salida IS NULL'
        );
        $stmt->execute([':rid' => $recordId, ':uid' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $entrada = new \DateTime($row['hora_entrada']);
        $minutos = max(0, (int) floor((time() - $entrada->getTimestamp()) / 60));

        $stmt = $this->db->prepare(
            'UPDATE registros_horario
             SET hora_salida             = NOW(),
                 firma_salida_nombre    = :fn,
                 firma_salida_apellidos = :fa,
                 firma_salida_dni       = :fd,
                 minutos_trabajados     = :mins
             WHERE id = :rid AND usuario_id = :uid AND hora_salida IS NULL'
        );
        return $stmt->execute([
            ':rid'  => $recordId,
            ':uid'  => $userId,
            ':fn'   => trim($firma['nombre']),
            ':fa'   => trim($firma['apellidos']),
            ':fd'   => strtoupper(trim($firma['dni'])),
            ':mins' => $minutos,
        ]);
    }

    // ── Historial del empleado (paginado) ─────────────────────
    public function getByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, fecha, hora_entrada, hora_salida,
                    firma_entrada_nombre, firma_entrada_apellidos, firma_entrada_dni,
                    firma_salida_nombre,  firma_salida_apellidos,  firma_salida_dni,
                    minutos_trabajados
             FROM registros_horario
             WHERE usuario_id = :uid
             ORDER BY hora_entrada DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getByUserRecords(int $userId, ?int $year = null, ?int $month = null): array
    {
        $sql = 'SELECT id, fecha, hora_entrada, hora_salida,
                    firma_entrada_nombre, firma_entrada_apellidos, firma_entrada_dni,
                    firma_salida_nombre,  firma_salida_apellidos,  firma_salida_dni,
                    minutos_trabajados
             FROM registros_horario
             WHERE usuario_id = :uid';
        $params = [':uid' => $userId];

        if ($year !== null) {
            $sql .= ' AND EXTRACT(YEAR FROM fecha) = :year';
            $params[':year'] = $year;
        }
        if ($month !== null) {
            $sql .= ' AND EXTRACT(MONTH FROM fecha) = :month';
            $params[':month'] = $month;
        }

        $sql .= ' ORDER BY hora_entrada DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Total de registros del empleado ───────────────────────
    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM registros_horario WHERE usuario_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Admin: todos los registros con filtros opcionales ─────
    public function getAllRecords(
        ?int    $userId = null,
        ?int    $year   = null,
        ?int    $month  = null
    ): array {
        $sql    = 'SELECT * FROM v_registros_completos WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND usuario_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($year !== null) {
            $sql .= ' AND EXTRACT(YEAR  FROM fecha) = :year';
            $params[':year'] = $year;
        }
        if ($month !== null) {
            $sql .= ' AND EXTRACT(MONTH FROM fecha) = :month';
            $params[':month'] = $month;
        }

        $sql .= ' ORDER BY hora_entrada DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Resumen por empleado (horas del mes) ──────────────────
    public function getMonthlySummary(int $year, int $month): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                u.id,
                u.nombre,
                u.apellidos,
                u.dni_nie,
                COUNT(rh.id)                          AS total_turnos,
                SUM(rh.minutos_trabajados)             AS total_minutos,
                ROUND(SUM(rh.minutos_trabajados)/60.0, 2) AS total_horas
             FROM usuarios u
             LEFT JOIN registros_horario rh
                ON rh.usuario_id = u.id
                AND EXTRACT(YEAR  FROM rh.fecha) = :year
                AND EXTRACT(MONTH FROM rh.fecha) = :month
                AND rh.hora_salida IS NOT NULL
             WHERE u.rol = 'empleado' AND u.activo = TRUE
             GROUP BY u.id, u.nombre, u.apellidos, u.dni_nie
             ORDER BY u.apellidos, u.nombre"
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        return $stmt->fetchAll();
    }

    // ── Años disponibles en registros ────────────────────────
    public function getAvailableYears(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT EXTRACT(YEAR FROM fecha)::INTEGER AS anio
             FROM registros_horario
             ORDER BY anio DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}