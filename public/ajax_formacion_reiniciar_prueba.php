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

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($rol !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Solo administradores pueden reiniciar pruebas']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);

$rut = trim((string)($data['rut'] ?? ''));
$cuadrilla = (int)($data['cuadrilla'] ?? 0);
$idServicio = (int)($data['id_servicio'] ?? 0);

if ($rut === '' || $cuadrilla <= 0 || $idServicio <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmtProg = $pdo->prepare("
        SELECT id, intento, estado, resultado
        FROM ceo_formacion_programadas
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND cuadrilla = :cuadrilla
          AND tipo = 'PRUEBA'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtProg->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla,
    ]);
    $programacion = $stmtProg->fetch(PDO::FETCH_ASSOC);

    if (!$programacion) {
        throw new RuntimeException('No se encontró una programación de prueba para este alumno.');
    }

    $intento = (int)($programacion['intento'] ?? 0);
    if ($intento <= 0) {
        throw new RuntimeException('No se pudo determinar el intento a reiniciar.');
    }

    $stmtDelResp = $pdo->prepare("
        DELETE FROM ceo_resultado_formacion_pruebat
        WHERE rut = :rut
          AND proceso = :cuadrilla
          AND intento = :intento
    ");
    $stmtDelResp->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
        ':intento' => $intento,
    ]);

    $stmtDelIntento = $pdo->prepare("
        DELETE FROM ceo_resultado_formacion_intento
        WHERE rut = :rut
          AND id_servicio = :servicio
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtDelIntento->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
    ]);

    $stmtUpdProg = $pdo->prepare("
        UPDATE ceo_formacion_programadas
        SET estado = 'PENDIENTE',
            resultado = 'PENDIENTE',
            fecha_resultado = NULL,
            fecha_inicio = NULL,
            fecha_termino = NULL,
            cierre_modo = NULL
        WHERE id = :id
        LIMIT 1
    ");
    $stmtUpdProg->execute([
        ':id' => (int)$programacion['id'],
    ]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'La prueba fue eliminada y el alumno quedó pendiente para rendir nuevamente.',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage(),
    ]);
}
