<?php
// ============================================================
//  config/Database.php — Conexión PDO a PostgreSQL (Supabase)
// ============================================================

declare(strict_types=1);

namespace Config;

use PDO;
use PDOException;

class Database
{
    // ── Parámetros de conexión a Supabase ────────────────────
    private static string $host     = 'aws-0-eu-west-3.pooler.supabase.com';
    private static int    $port     = 5432;
    private static string $dbname   = 'postgres';
    private static string $user     = 'postgres.ynklgmrrmlielizcrviv';
    
    // ⚠️ IMPORTANTE: Reemplaza esto por la contraseña de tu base de datos de Supabase
    private static string $password = 'ZeledonMONDRAGON'; 
    
    private static string $charset  = 'utf8';
    // ─────────────────────────────────────────────────────────

    private static ?PDO $instance = null;

    /**
     * Devuelve la instancia única de PDO (Singleton).
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // Nota: Se ha añadido sslmode=require para conexiones seguras en la nube
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=require;options=--client_encoding=%s',
                self::$host,
                self::$port,
                self::$dbname,
                self::$charset
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, self::$user, self::$password, $options);
                // Mantenemos la zona horaria para que los registros cuadren con la hora local
                self::$instance->exec("SET timezone='Europe/Madrid'");
            } catch (PDOException $e) {
                // En producción: log y mensaje genérico
                error_log('[DB ERROR] ' . $e->getMessage());
                throw new \RuntimeException('Detalle del error: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /** No instanciable */
    private function __construct() {}
    private function __clone() {}
}