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

    $id         = (int)($data['id'] ?? 0);
    $rut        = trim((string)($data['rut'] ?? ''));
    $idCargo    = (int)($data['id_cargo'] ?? 0);
    $idServicio = (int)($data['id_servicio'] ?? 0);
    $otro       = isset($data['otro']) && $data['otro'] !== '' ? (int)$data['otro'] : 0;

    if ($id <= 0) {
        throw new Exception('ID inválido');
    }

    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    if ($idCargo <= 0) {
        throw new Exception('Cargo inválido');
    }

    if ($idServicio <= 0) {
        throw new Exception('Servicio inválido');
    }

    $pdo = db();

    $sqlRegistro = "
        SELECT id
        FROM ceo_servicios_rut
        WHERE id = :id
          AND rut = :rut
        LIMIT 1
    ";
    $stmtRegistro = $pdo->prepare($sqlRegistro);
    $stmtRegistro->execute([
        'id'  => $id,
        'rut' => $rut
    ]);

    if (!$stmtRegistro->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('El registro no existe o no pertenece al RUT indicado');
    }

    $stmtCargo = $pdo->prepare("SELECT id FROM ceo_cargos_habilitacion WHERE id = :id LIMIT 1");
    $stmtCargo->execute(['id' => $idCargo]);
    if (!$stmtCargo->fetchColumn()) {
        throw new Exception('El cargo indicado no existe');
    }

    $stmtServicio = $pdo->prepare("SELECT id FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1");
    $stmtServicio->execute(['id' => $idServicio]);
    if (!$stmtServicio->fetchColumn()) {
        throw new Exception('El servicio indicado no existe');
    }

    $sqlDup = "
        SELECT id
        FROM ceo_servicios_rut
        WHERE rut = :rut
          AND id_cargo = :id_cargo
          AND id_servicio = :id_servicio
          AND id <> :id
        LIMIT 1
    ";
    $stmtDup = $pdo->prepare($sqlDup);
    $stmtDup->execute([
        'rut'         => $rut,
        'id_cargo'    => $idCargo,
        'id_servicio' => $idServicio,
        'id'          => $id
    ]);

    if ($stmtDup->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('Ya existe un registro con ese cargo y servicio para este trabajador');
    }

    $sqlUpdate = "
        UPDATE ceo_servicios_rut
        SET
            id_cargo = :id_cargo,
            id_servicio = :id_servicio,
            otro = :otro
        WHERE id = :id
          AND rut = :rut
        LIMIT 1
    ";
    $stmtUpdate = $pdo->prepare($sqlUpdate);
    $stmtUpdate->execute([
        'id_cargo'    => $idCargo,
        'id_servicio' => $idServicio,
        'otro'        => $otro,
        'id'          => $id,
        'rut'         => $rut
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