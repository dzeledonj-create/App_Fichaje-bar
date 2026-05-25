<?php
// ============================================================
//  controllers/TimeController.php — Lógica de fichaje de horario
// ============================================================

declare(strict_types=1);

namespace Controllers;

use Models\TimeRecord;
use Models\User;

class TimeController
{
    private TimeRecord $recordModel;
    private User $userModel;

    public function __construct()
    {
        $this->recordModel = new TimeRecord();
        $this->userModel   = new User();
    }

    public function getStatus(int $userId): array
    {
        $shift = $this->recordModel->getOpenShift($userId);

        return [
            'has_open_shift' => (bool) $shift,
            'open_shift'     => $shift ? [
                'id'             => (int) $shift['id'],
                'hora_entrada'   => $shift['hora_entrada'],
                'firma_entrada'  => trim(
                    ($shift['firma_entrada_nombre'] ?? '') . ' ' .
                    ($shift['firma_entrada_apellidos'] ?? '')
                ) . ' · ' . ($shift['firma_entrada_dni'] ?? ''),
            ] : null,
        ];
    }

    public function clockIn(int $userId, array $firma): array
    {
        if ($this->recordModel->getOpenShift($userId)) {
            return ['success' => false, 'message' => 'Ya existe un turno abierto.'];
        }

        $this->recordModel->clockIn($userId, $firma);

        return [
            'success'   => true,
            'message'   => 'Entrada registrada.',
            'timestamp' => date('d/m/Y H:i:s'),
        ];
    }

    public function clockOut(int $userId, array $firma): array
    {
        $shift = $this->recordModel->getOpenShift($userId);
        if (!$shift) {
            return ['success' => false, 'message' => 'No hay un turno abierto.'];
        }

        $ok = $this->recordModel->clockOut((int) $shift['id'], $userId, $firma);

        return [
            'success'   => $ok,
            'message'   => $ok ? 'Salida registrada.' : 'No se pudo registrar la salida.',
            'timestamp' => date('d/m/Y H:i:s'),
        ];
    }

    public function getHistory(int $userId, int $page = 1): array
    {
        $perPage     = 6;
        $total       = $this->recordModel->countByUser($userId);
        $totalPages  = max(1, (int) ceil($total / $perPage));
        $page        = min(max($page, 1), $totalPages);
        $offset      = ($page - 1) * $perPage;
        $records     = $this->recordModel->getByUser($userId, $perPage, $offset);
        $formatted   = [];

        foreach ($records as $row) {
            $formatted[] = [
                'id'               => (int) $row['id'],
                'fecha'            => $row['fecha']
                    ? date('d/m/Y', strtotime($row['fecha'])) : '-',
                'hora_entrada'     => $row['hora_entrada']
                    ? date('d/m/Y H:i:s', strtotime($row['hora_entrada'])) : '-',
                'hora_salida'      => $row['hora_salida']
                    ? date('d/m/Y H:i:s', strtotime($row['hora_salida'])) : 'En curso',
                'horas_trabajadas' => $this->formatWorkedMinutes($row['minutos_trabajados']),
                'estado'           => $row['hora_salida'] === null ? 'abierto' : 'cerrado',
                'firma_entrada'    => trim(
                    ($row['firma_entrada_nombre'] ?? '') . ' ' .
                    ($row['firma_entrada_apellidos'] ?? '')
                ) . ' · ' . ($row['firma_entrada_dni'] ?? ''),
                'firma_salida'     => $row['firma_salida_nombre']
                    ? trim(
                        ($row['firma_salida_nombre'] ?? '') . ' ' .
                        ($row['firma_salida_apellidos'] ?? '')
                    ) . ' · ' . ($row['firma_salida_dni'] ?? '')
                    : '-',
            ];
        }

        return [
            'records'      => $formatted,
            'page'         => $page,
            'total_pages'  => $totalPages,
            'total_records'=> $total,
        ];
    }

    public function getHistoryRecords(int $userId, ?int $year = null, ?int $month = null): array
    {
        $records   = $this->recordModel->getByUserRecords($userId, $year, $month);
        $formatted = [];

        foreach ($records as $row) {
            $formatted[] = [
                'fecha'            => $row['fecha']
                    ? date('d/m/Y', strtotime($row['fecha'])) : '-',
                'hora_entrada'     => $row['hora_entrada']
                    ? date('d/m/Y H:i:s', strtotime($row['hora_entrada'])) : '-',
                'hora_salida'      => $row['hora_salida']
                    ? date('d/m/Y H:i:s', strtotime($row['hora_salida'])) : 'En curso',
                'horas_trabajadas' => $this->formatWorkedMinutes($row['minutos_trabajados']),
                'minutos_trabajados' => $row['minutos_trabajados'] ?? 0,
                'estado'           => $row['hora_salida'] === null ? 'abierto' : 'cerrado',
                'firma_entrada'    => trim(
                    ($row['firma_entrada_nombre'] ?? '') . ' ' .
                    ($row['firma_entrada_apellidos'] ?? '')
                ) . ' · ' . ($row['firma_entrada_dni'] ?? ''),
                'firma_salida'     => $row['firma_salida_nombre']
                    ? trim(
                        ($row['firma_salida_nombre'] ?? '') . ' ' .
                        ($row['firma_salida_apellidos'] ?? '')
                    ) . ' · ' . ($row['firma_salida_dni'] ?? '')
                    : '-',
            ];
        }

        return $formatted;
    }

    private function formatWorkedMinutes(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) {
            return '-';
        }

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
