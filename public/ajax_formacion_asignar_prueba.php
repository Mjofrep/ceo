<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);

$cuadrilla = (int)($input['cuadrilla'] ?? 0);
$idAgrupacion = (int)($input['id_agrupacion'] ?? 0);

if ($cuadrilla <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Cuadrilla inválida']);
    exit;
}

try {
    $pdo = db();

    $stmtFormacion = $pdo->prepare('
        SELECT cuadrilla, id_servicio, estado
        FROM ceo_formacion
        WHERE cuadrilla = :cuadrilla
        LIMIT 1
    ');
    $stmtFormacion->execute([':cuadrilla' => $cuadrilla]);
    $formacion = $stmtFormacion->fetch(PDO::FETCH_ASSOC);

    if (!$formacion) {
        throw new RuntimeException('No se encontró la cuadrilla seleccionada.');
    }

    if (strtolower((string)$formacion['estado']) === 'cerrado') {
        throw new RuntimeException('La cuadrilla está cerrada y no puede cambiar su prueba.');
    }

    $idServicio = (int)$formacion['id_servicio'];

    if ($idAgrupacion > 0) {
        $stmtAgrupacion = $pdo->prepare('
            SELECT id
            FROM ceo_formacion_agrupacion
            WHERE id = :id
              AND id_servicio = :id_servicio
            LIMIT 1
        ');
        $stmtAgrupacion->execute([
            ':id' => $idAgrupacion,
            ':id_servicio' => $idServicio
        ]);

        if (!$stmtAgrupacion->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('La prueba seleccionada no pertenece al servicio de esta cuadrilla.');
        }
    }

    $pdo->beginTransaction();

    $stmtUpdateFormacion = $pdo->prepare('
        UPDATE ceo_formacion
        SET id_agrupacion = :id_agrupacion
        WHERE cuadrilla = :cuadrilla
    ');
    $stmtUpdateFormacion->execute([
        ':id_agrupacion' => $idAgrupacion > 0 ? $idAgrupacion : null,
        ':cuadrilla' => $cuadrilla
    ]);

    $stmtUpdateProgramadas = $pdo->prepare('
        UPDATE ceo_formacion_programadas
        SET id_agrupacion = :id_agrupacion
        WHERE cuadrilla = :cuadrilla
          AND estado IN (\'PENDIENTE\', \'ANULADA\')
    ');
    $stmtUpdateProgramadas->execute([
        ':id_agrupacion' => $idAgrupacion > 0 ? $idAgrupacion : null,
        ':cuadrilla' => $cuadrilla
    ]);

    $pdo->commit();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
