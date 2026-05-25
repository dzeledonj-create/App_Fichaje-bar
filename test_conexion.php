<?php
// Asegúrate de que la ruta al archivo Database.php es correcta
require_once 'config/Database.php';
use Config\Database;

try {
    // Intentamos abrir la conexión
    $db = Database::getInstance();
    echo "<h3 style='color: green;'>¡Conexión exitosa a Supabase! 🎉</h3>";
    echo "<p>Tu código PHP se está comunicando perfectamente con la base de datos en la nube.</p>";
} catch (Exception $e) {
    // Si la conexión falla, capturamos el error y lo mostramos
    echo "<h3 style='color: red;'>❌ Error al conectar:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>