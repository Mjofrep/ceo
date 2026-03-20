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

    $id  = (int)($data['id'] ?? 0);
    $rut = trim((string)($data['rut'] ?? ''));

    if ($id <= 0) {
        throw new Exception('ID inválido');
    }

    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    $pdo = db();

    $sqlExiste = "
        SELECT id
        FROM ceo_servicios_rut
        WHERE id = :id
          AND rut = :rut
        LIMIT 1
    ";
    $stmtExiste = $pdo->prepare($sqlExiste);
    $stmtExiste->execute([
        'id'  => $id,
        'rut' => $rut
    ]);

    if (!$stmtExiste->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('El registro no existe o no pertenece al RUT indicado');
    }

    $sqlDelete = "
        DELETE FROM ceo_servicios_rut
        WHERE id = :id
          AND rut = :rut
        LIMIT 1
    ";
    $stmtDelete = $pdo->prepare($sqlDelete);
    $stmtDelete->execute([
        'id'  => $id,
        'rut' => $rut
    ]);

    echo json_encode([
        'ok' => true
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}