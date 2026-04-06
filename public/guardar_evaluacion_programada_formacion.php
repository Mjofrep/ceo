<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$data = json_decode((string)file_get_contents("php://input"), true);

$rut       = trim((string)($data['rut'] ?? ''));
$servicio  = (int)($data['servicio'] ?? 0);
$cuadrilla = (int)($data['cuadrilla'] ?? 0);
$tipo      = (string)($data['tipo'] ?? '');
$checked   = (int)($data['checked'] ?? 0);

if ($rut === '' || $servicio <= 0 || $cuadrilla <= 0 || $tipo !== 'PRUEBA') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

try {
    $pdo = db();
    $userId = (int)($_SESSION['auth']['id'] ?? 0);

    // Buscar SI YA EXISTE un registro para esta combinación,
    // sin importar si está PENDIENTE o ANULADA.
    $stmtExiste = $pdo->prepare("
        SELECT id, estado, resultado
        FROM ceo_formacion_programadas
        WHERE rut = :rut
          AND id_servicio = :servicio
          AND tipo = :tipo
          AND cuadrilla = :cuadrilla
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtExiste->execute([
        ':rut' => $rut,
        ':servicio' => $servicio,
        ':tipo' => $tipo,
        ':cuadrilla' => $cuadrilla
    ]);

    $registro = $stmtExiste->fetch(PDO::FETCH_ASSOC);

    if ($checked) {

        // Si ya existe, reactivar en vez de insertar
        if ($registro) {
            $stmtUpd = $pdo->prepare("
            UPDATE ceo_formacion_programadas
                SET estado = 'PENDIENTE',
                    resultado = 'PENDIENTE',
                    fecha_resultado = NULL,
                    usuario_programa = :usuario,
                    fecha_programacion = NOW(),
                    cobrado = 0
                WHERE id = :id
                LIMIT 1
            ");
            $stmtUpd->execute([
                ':usuario' => ($userId > 0 ? $userId : 1),
                ':id' => (int)$registro['id']
            ]);

            echo json_encode(['ok' => true, 'msg' => 'Programación reactivada']);
            exit;
        }

        // Si no existe, insertar nuevo
        $stmtIns = $pdo->prepare("
            INSERT INTO ceo_formacion_programadas
                (rut, id_servicio, tipo, cuadrilla, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
            VALUES
                (:rut, :servicio, :tipo, :cuadrilla, NOW(), :usuario, 'PENDIENTE', 1, 'PENDIENTE', NULL, 0)
        ");
        $stmtIns->execute([
            ':rut' => $rut,
            ':servicio' => $servicio,
            ':tipo' => $tipo,
            ':cuadrilla' => $cuadrilla,
            ':usuario' => ($userId > 0 ? $userId : 1)
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Programación creada']);
        exit;
    }

    // Desmarcar: si existe, dejar ANULADA
    if ($registro) {
        $stmtUpd = $pdo->prepare("
            UPDATE ceo_formacion_programadas
            SET estado = 'ANULADA'
            WHERE id = :id
            LIMIT 1
        ");
        $stmtUpd->execute([
            ':id' => (int)$registro['id']
        ]);
    }

    echo json_encode(['ok' => true, 'msg' => 'Programación anulada']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Error SQL: ' . $e->getMessage()]);
}
