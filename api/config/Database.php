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
    private static ?PDO $instance = null;

    /**
     * Devuelve la instancia única de PDO (Singleton).
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            
            // 1. Obtenemos la URL de la base de datos desde Vercel (Variable de Entorno)
            $dbUrl = getenv('DATABASE_URL');
            
            if (!$dbUrl) {
                throw new \RuntimeException('Error Crítico: No se encontró la variable de entorno DATABASE_URL en Vercel.');
            }

            // 2. Parseamos la URL para extraer las credenciales dinámicamente
            $dbopts = parse_url($dbUrl);

            $host     = $dbopts["host"];
            $port     = $dbopts["port"] ?? 5432;
            $user     = $dbopts["user"];
            $password = $dbopts["pass"];
            $dbname   = ltrim($dbopts["path"], '/');
            $charset  = 'utf8';

            // 3. Construimos el DSN seguro
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=require;options=--client_encoding=%s',
                $host,
                $port,
                $dbname,
                $charset
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                // 4. Iniciamos la conexión con las credenciales extraídas
                self::$instance = new PDO($dsn, $user, $password, $options);
                
                // Mantenemos tu zona horaria para que los registros cuadren
                self::$instance->exec("SET timezone='Europe/Madrid'");
                
            } catch (PDOException $e) {
                // En producción: log ocultando la contraseña real
                error_log('[DB ERROR] Fallo al conectar con Supabase.');
                throw new \RuntimeException('Error al conectar a la base de datos.');
            }
        }

        return self::$instance;
    }

    /** No instanciable */
    private function __construct() {}
    private function __clone() {}
}