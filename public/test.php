<?php
require_once __DIR__.'/../config/db.php';
try {
    $pdo = db();
    echo "✅ Conexión OK";
    echo password_hash('Inicio**', PASSWORD_DEFAULT);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
