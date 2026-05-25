<?php
// ============================================================
//  controllers/AdminController.php — Panel de administración
// ============================================================

declare(strict_types=1);

namespace Controllers;

use Models\User;
use Models\TimeRecord;

class AdminController
{
    private User       $userModel;
    private TimeRecord $recordModel;

    public function __construct()
    {
        $this->userModel   = new User();
        $this->recordModel = new TimeRecord();
    }

    // ── Dashboard: resumen del mes actual ────────────────────
    public function getDashboard(): array
    {
        $year  = (int) date('Y');
        $month = (int) date('n');

        return [
            'empleados'       => $this->userModel->getAllEmployees(),
            'resumen_mes'     => $this->recordModel->getMonthlySummary($year, $month),
            'anios'           => $this->recordModel->getAvailableYears(),
            'mes_actual'      => $month,
            'anio_actual'     => $year,
        ];
    }

    // ── Registros con filtros ────────────────────────────────
    public function getRecords(
        ?int $userId = null,
        ?int $year   = null,
        ?int $month  = null
    ): array {
        $rows = $this->recordModel->getAllRecords($userId, $year, $month);

        return array_map(function ($r) {
            $mins = $r['minutos_trabajados'];
            return [
                'id'                    => $r['id'],
                'usuario_id'            => $r['usuario_id'],
                'usuario_nombre'        => $r['usuario_nombre'],
                'usuario_apellidos'     => $r['usuario_apellidos'],
                'usuario_dni'           => $r['usuario_dni'],
                'fecha'                 => $r['fecha']
                    ? date('d/m/Y', strtotime($r['fecha'])) : '-',
                'hora_entrada'          => $r['hora_entrada']
                    ? date('d/m/Y H:i:s', strtotime($r['hora_entrada'])) : '-',
                'hora_salida'           => $r['hora_salida']
                    ? date('d/m/Y H:i:s', strtotime($r['hora_salida'])) : 'En curso',
                'firma_entrada'         => trim(
                    ($r['firma_entrada_nombre'] ?? '') . ' ' .
                    ($r['firma_entrada_apellidos'] ?? '')
                ) . ' · ' . ($r['firma_entrada_dni'] ?? ''),
                'firma_salida'          => $r['firma_salida_nombre']
                    ? trim(
                        ($r['firma_salida_nombre'] ?? '') . ' ' .
                        ($r['firma_salida_apellidos'] ?? '')
                    ) . ' · ' . ($r['firma_salida_dni'] ?? '') : '-',
                'horas_trabajadas'      => $mins
                    ? sprintf('%dh %02dm', intdiv($mins, 60), $mins % 60) : '-',
                'estado'                => $r['estado_turno'],
                'minutos_trabajados'    => $mins,
                // Datos en crudo para PDF
                'raw_hora_entrada'      => $r['hora_entrada'],
                'raw_hora_salida'       => $r['hora_salida'],
                'firma_entrada_nombre'  => $r['firma_entrada_nombre'],
                'firma_entrada_apellidos'=> $r['firma_entrada_apellidos'],
                'firma_entrada_dni'     => $r['firma_entrada_dni'],
                'firma_salida_nombre'   => $r['firma_salida_nombre'],
                'firma_salida_apellidos'=> $r['firma_salida_apellidos'],
                'firma_salida_dni'      => $r['firma_salida_dni'],
            ];
        }, $rows);
    }

    // ── Crear empleado ───────────────────────────────────────
    public function createEmployee(array $data): array
    {
        // Validaciones básicas
        foreach (['nombre', 'apellidos', 'dni_nie', 'email', 'password'] as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                return ['success' => false, 'message' => "El campo '$field' es obligatorio."];
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Email inválido.'];
        }

        if (strlen($data['password']) < 8) {
            return ['success' => false, 'message' => 'La contraseña debe tener mínimo 8 caracteres.'];
        }

        try {
            $id = $this->userModel->create($data);
            return ['success' => true, 'message' => 'Empleado creado correctamente.', 'id' => $id];
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'unique')) {
                return ['success' => false, 'message' => 'El email o DNI/NIE ya está registrado.'];
            }
            error_log('[AdminController::createEmployee] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al crear el empleado.'];
        }
    }

    // ── Actualizar empleado ─────────────────────────────────
    public function updateEmployee(int $id, array $data): array
    {
        foreach (['nombre', 'apellidos', 'dni_nie', 'rol'] as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                return ['success' => false, 'message' => "El campo '$field' es obligatorio."];
            }
        }

        $rol = trim($data['rol']);
        if (!in_array($rol, ['empleado', 'admin'], true)) {
            return ['success' => false, 'message' => 'Rol inválido.'];
        }

        try {
            $ok = $this->userModel->update($id, [
                'nombre'    => $data['nombre'],
                'apellidos' => $data['apellidos'],
                'dni_nie'   => $data['dni_nie'],
                'rol'       => $rol,
            ]);

            return [
                'success' => $ok,
                'message' => $ok ? 'Empleado actualizado correctamente.' : 'No se pudo actualizar el empleado.',
            ];
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'unique')) {
                return ['success' => false, 'message' => 'El DNI/NIE ya está registrado en otro usuario.'];
            }
            error_log('[AdminController::updateEmployee] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al actualizar el empleado.'];
        }
    }

    // ── Activar/desactivar empleado ──────────────────────────
    public function toggleEmployee(int $id, bool $active): array
    {
        $ok = $this->userModel->setActive($id, $active);
        return [
            'success' => $ok,
            'message' => $ok
                ? ($active ? 'Empleado activado.' : 'Empleado desactivado.')
                : 'Error al actualizar.',
        ];
    }
}