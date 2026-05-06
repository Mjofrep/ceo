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
    $id = (int)($data['id'] ?? 0);

    if ($rut === '' || $id <= 0) {
        throw new Exception('Datos inválidos');
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT id, rut, id_servicio, id_cargo, numero_proceso, estado
        FROM ceo_proceso_habilitacion
        WHERE id = :id
          AND rut = :rut
          AND estado = "ABIERTO"
        LIMIT 1
    ');
    $stmt->execute([
        ':id' => $id,
        ':rut' => $rut,
    ]);
    $proceso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proceso) {
        throw new Exception('El proceso no existe, no pertenece al RUT o no está abierto.');
    }

    $idServicio = (int)$proceso['id_servicio'];
    $idCargo = (int)$proceso['id_cargo'];
    if ($idServicio <= 0 || $idCargo <= 0) {
        throw new Exception('El proceso seleccionado no tiene servicio/cargo válido.');
    }

    $_SESSION['proceso_habilitacion_seleccionado'][$rut][$idServicio][$idCargo] = (int)$proceso['id'];

    echo json_encode([
        'ok' => true,
        'proceso' => $proceso,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
