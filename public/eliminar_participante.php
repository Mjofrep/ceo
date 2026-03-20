<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';

header('Content-Type: application/json');

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$rut = $data['rut'] ?? '';
$idCuadrilla = (int)($data['id_cuadrilla'] ?? 0);

if (!$rut || $idCuadrilla <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos']);
    exit;
}

try {
    $pdo = db();

    $sql = "
        DELETE FROM ceo_habilitacion_participantes
        WHERE rut = :rut
          AND id_cuadrilla = :cuadrilla
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':rut'       => $rut,
        ':cuadrilla'=> $idCuadrilla
    ]);

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al eliminar'
    ]);
}
