<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);

$rut = trim((string)($data['rut'] ?? ''));
$idCuadrilla = (int)($data['id_cuadrilla'] ?? 0);
$idServicio = (int)($data['id_servicio'] ?? 0);

if ($rut === '' || $idCuadrilla <= 0 || $idServicio <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmtHab = $pdo->prepare('
        SELECT id, estado
        FROM ceo_habilitacion
        WHERE cuadrilla = :cuadrilla
          AND id_servicio = :servicio
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtHab->execute([
        ':cuadrilla' => $idCuadrilla,
        ':servicio' => $idServicio,
    ]);
    $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$habilitacion) {
        throw new RuntimeException('No se encontró la habilitación asociada a la cuadrilla indicada.');
    }

    $idHabilitacion = (int)$habilitacion['id'];

    $stmtPersona = $pdo->prepare('
        SELECT id, estado
        FROM ceo_habilitacion_personas
        WHERE id_habilitacion = :id_habilitacion
          AND rut = :rut
        LIMIT 1
    ');
    $stmtPersona->execute([
        ':id_habilitacion' => $idHabilitacion,
        ':rut' => $rut,
    ]);
    $persona = $stmtPersona->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$persona) {
        throw new RuntimeException('No se encontró la persona asociada a esta habilitación.');
    }

    if (strcasecmp((string)($persona['estado'] ?? ''), 'ELIMINADO') === 0) {
        throw new RuntimeException('La persona ya se encuentra anulada en esta planificación.');
    }

    $stmtUpdPersona = $pdo->prepare('
        UPDATE ceo_habilitacion_personas
        SET estado = :estado
        WHERE id = :id
        LIMIT 1
    ');
    $stmtUpdPersona->execute([
        ':estado' => 'ELIMINADO',
        ':id' => (int)$persona['id'],
    ]);

    $stmtUpdPendientes = $pdo->prepare('
        UPDATE ceo_evaluaciones_programadas
        SET estado = :estado
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND cuadrilla = :cuadrilla
          AND estado = :estado_pendiente
    ');
    $stmtUpdPendientes->execute([
        ':estado' => 'ANULADA',
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':cuadrilla' => $idCuadrilla,
        ':estado_pendiente' => 'PENDIENTE',
    ]);

    $stmtActivas = $pdo->prepare('
        SELECT COUNT(*)
        FROM ceo_habilitacion_personas
        WHERE id_habilitacion = :id_habilitacion
          AND estado <> :estado_eliminado
    ');
    $stmtActivas->execute([
        ':id_habilitacion' => $idHabilitacion,
        ':estado_eliminado' => 'ELIMINADO',
    ]);
    $personasActivas = (int)$stmtActivas->fetchColumn();

    $cuadrillaAnulada = false;
    if ($personasActivas === 0) {
        $stmtUpdHab = $pdo->prepare('
            UPDATE ceo_habilitacion
            SET estado = :estado
            WHERE id = :id
            LIMIT 1
        ');
        $stmtUpdHab->execute([
            ':estado' => 'Anulada',
            ':id' => $idHabilitacion,
        ]);
        $cuadrillaAnulada = true;
    }

    $pdo->commit();

    $mensaje = $cuadrillaAnulada
        ? 'La persona fue anulada por no asistencia y la cuadrilla completa quedó anulada.'
        : 'La persona fue anulada por no asistencia. Solo se anularon evaluaciones pendientes.';

    echo json_encode([
        'ok' => true,
        'msg' => $mensaje,
        'cuadrilla_anulada' => $cuadrillaAnulada,
        'estado_cuadrilla' => $cuadrillaAnulada ? 'Anulada' : (string)($habilitacion['estado'] ?? 'Pendiente'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
