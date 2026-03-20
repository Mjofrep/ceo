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

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }

    $rut = trim((string)($data['rut'] ?? ''));
    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    $pdo = db();

    $sql = "
        SELECT
            sr.id,
            sr.rut,
            mj.cargo,
            sr.id_servicio,
            sp.servicio,
            sp.descripcion,
            sr.otro
        FROM ceo_servicios_rut sr
        INNER JOIN ceo_servicios_pruebas sp
            ON sp.id = sr.id_servicio
        INNER JOIN ceo_cargos_habilitacion mj ON mj.id = sr.id_cargo
        WHERE sr.rut = :rut
        ORDER BY sp.servicio ASC, sr.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'rut' => $rut
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'   => true,
        'data' => $rows
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}