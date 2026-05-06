<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['auth'])) {
        throw new Exception('Sesión no válida');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }

    $rut = preg_replace('/\s+/', '', trim((string)($data['rut'] ?? '')));
    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT
            ph.id,
            ph.numero_proceso,
            ph.rut,
            ph.id_servicio,
            ph.id_cargo,
            ph.estado,
            ph.fecha_inicio,
            ph.fecha_cierre,
            sp.servicio,
            sp.descripcion,
            ch.cargo
        FROM ceo_proceso_habilitacion ph
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = ph.id_servicio
        LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = ph.id_cargo
        WHERE ph.rut = :rut
          AND ph.estado = "ABIERTO"
        ORDER BY ph.numero_proceso DESC, ph.id DESC
    ');
    $stmt->execute([':rut' => $rut]);

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
