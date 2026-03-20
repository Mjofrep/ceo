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

    $rut   = trim((string)($data['rut'] ?? ''));
    $items = $data['items'] ?? [];

    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    if (!is_array($items) || empty($items)) {
        throw new Exception('No se recibieron líneas para guardar');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $sqlExiste = "
        SELECT id
        FROM ceo_servicios_rut
        WHERE rut = :rut
          AND id_cargo = :id_cargo
          AND id_servicio = :id_servicio
        LIMIT 1
    ";
    $stmtExiste = $pdo->prepare($sqlExiste);

    $sqlCargo = "SELECT id FROM ceo_cargos_habilitacion WHERE id = :id LIMIT 1";
    $stmtCargo = $pdo->prepare($sqlCargo);

    $sqlServicio = "SELECT id FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1";
    $stmtServicio = $pdo->prepare($sqlServicio);

    $sqlInsert = "
        INSERT INTO ceo_servicios_rut
            (id_cargo, id_servicio, otro, rut)
        VALUES
            (:id_cargo, :id_servicio, :otro, :rut)
    ";
    $stmtInsert = $pdo->prepare($sqlInsert);

    $insertados = 0;
    $omitidos   = 0;

    foreach ($items as $item) {
        $idCargo    = (int)($item['id_cargo'] ?? 0);
        $idServicio = (int)($item['id_servicio'] ?? 0);
        $otro       = isset($item['otro']) && $item['otro'] !== '' ? (int)$item['otro'] : 0;

        if ($idCargo <= 0 || $idServicio <= 0) {
            $omitidos++;
            continue;
        }

        $stmtCargo->execute(['id' => $idCargo]);
        if (!$stmtCargo->fetchColumn()) {
            $omitidos++;
            continue;
        }

        $stmtServicio->execute(['id' => $idServicio]);
        if (!$stmtServicio->fetchColumn()) {
            $omitidos++;
            continue;
        }

        $stmtExiste->execute([
            'rut'         => $rut,
            'id_cargo'    => $idCargo,
            'id_servicio' => $idServicio
        ]);
        if ($stmtExiste->fetch(PDO::FETCH_ASSOC)) {
            $omitidos++;
            continue;
        }

        $stmtInsert->execute([
            'id_cargo'    => $idCargo,
            'id_servicio' => $idServicio,
            'otro'        => $otro,
            'rut'         => $rut
        ]);

        $insertados++;
    }

    $pdo->commit();

    echo json_encode([
        'ok'         => true,
        'insertados' => $insertados,
        'omitidos'   => $omitidos
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}