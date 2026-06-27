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
$idServicio = (int)($data['id_servicio'] ?? 0);
$idProcesoHabilitacion = (int)($data['id_proceso_habilitacion'] ?? 0);

if ($rut === '' || $idServicio <= 0 || $idProcesoHabilitacion <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmtProg = $pdo->prepare("
        SELECT id, cuadrilla, intento
        FROM ceo_evaluaciones_programadas
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND id_proceso_habilitacion = :id_proceso_habilitacion
          AND tipo = 'PRUEBA'
        ORDER BY intento DESC, id DESC
        LIMIT 1
    ");
    $stmtProg->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':id_proceso_habilitacion' => $idProcesoHabilitacion,
    ]);
    $programacion = $stmtProg->fetch(PDO::FETCH_ASSOC);

    if (!$programacion) {
        throw new RuntimeException('No se encontró una programación de prueba para este alumno.');
    }

    $cuadrilla = (int)($programacion['cuadrilla'] ?? 0);
    $intento = (int)($programacion['intento'] ?? 0);
    if ($cuadrilla <= 0 || $intento <= 0) {
        throw new RuntimeException('No se pudo determinar el intento a reiniciar.');
    }

    $stmtDelResp = $pdo->prepare("
        DELETE FROM ceo_resultado_pruebat
        WHERE rut = :rut
          AND proceso = :cuadrilla
          AND intento = :intento
    ");
    $stmtDelResp->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
        ':intento' => $intento,
    ]);

    $stmtSelIntento = $pdo->prepare("
        SELECT id
        FROM ceo_resultado_prueba_intento
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND id_proceso_habilitacion = :id_proceso_habilitacion
        ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC
        LIMIT 1
    ");
    $stmtSelIntento->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':id_proceso_habilitacion' => $idProcesoHabilitacion,
    ]);
    $idIntento = $stmtSelIntento->fetchColumn();

    if ($idIntento !== false) {
        $stmtDelIntento = $pdo->prepare('DELETE FROM ceo_resultado_prueba_intento WHERE id = :id LIMIT 1');
        $stmtDelIntento->execute([':id' => (int)$idIntento]);
    }

    $stmtUpdProg = $pdo->prepare("
        UPDATE ceo_evaluaciones_programadas
        SET estado = 'PENDIENTE',
            resultado = 'PENDIENTE',
            fecha_resultado = NULL
        WHERE id = :id
        LIMIT 1
    ");
    $stmtUpdProg->execute([':id' => (int)$programacion['id']]);

    $stmtDelVigDet = $pdo->prepare("
        DELETE FROM ceo_vigencia_detalle
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND id_proceso = :cuadrilla
          AND id_proceso_habilitacion = :id_proceso_habilitacion
          AND tipo = 'PRUEBA'
    ");
    $stmtDelVigDet->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla,
        ':id_proceso_habilitacion' => $idProcesoHabilitacion,
    ]);

    $stmtDelVigGen = $pdo->prepare("
        DELETE FROM ceo_vigencia_general
        WHERE rut = :rut
          AND id_proceso = :cuadrilla
    ");
    $stmtDelVigGen->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
    ]);

    $stmtDelFinal = $pdo->prepare("
        DELETE FROM ceo_resultado_final_servicio
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND id_proceso = :cuadrilla
          AND (id_proceso_habilitacion = :id_proceso_habilitacion OR id_proceso_habilitacion IS NULL)
    ");
    $stmtDelFinal->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla,
        ':id_proceso_habilitacion' => $idProcesoHabilitacion,
    ]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'La prueba teórica de habilitación fue reiniciada y el alumno quedó pendiente para rendir nuevamente.',
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
