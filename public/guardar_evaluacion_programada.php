<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../config/functions.php';

function guardarHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache[$key];
}

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

if ($rut === '' || $servicio <= 0 || $cuadrilla <= 0 || !in_array($tipo, ['PRUEBA','TERRENO'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

try {
    $pdo = db();
    $userId = (int)($_SESSION['auth']['id'] ?? 0);
    $idAgrupacion = (int)($data['id_agrupacion'] ?? 0);
    $hasEvaluacionProgramadaAgrupacion = guardarHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion');

    // Buscar SI YA EXISTE un registro para esta combinación,
    // sin importar si está PENDIENTE o ANULADA.
    $stmtExiste = $pdo->prepare("
        SELECT id, estado, resultado
        FROM ceo_evaluaciones_programadas
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

    if ($tipo === 'PRUEBA') {
        if ($checked && $idAgrupacion <= 0) {
            throw new Exception('Debe seleccionar la prueba a aplicar antes de programarla.');
        }

        if ($checked && $idAgrupacion > 0 && !$hasEvaluacionProgramadaAgrupacion) {
            throw new Exception('Falta la columna id_agrupacion en ceo_evaluaciones_programadas para guardar la prueba seleccionada.');
        }

        if ($idAgrupacion > 0) {
            $stmtAgrupacion = $pdo->prepare("
                SELECT id
                FROM ceo_agrupacion
                WHERE id = :id
                  AND id_servicio = :id_servicio
                LIMIT 1
            ");
            $stmtAgrupacion->execute([
                ':id' => $idAgrupacion,
                ':id_servicio' => $servicio
            ]);

            if (!$stmtAgrupacion->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('La prueba seleccionada no pertenece al servicio indicado.');
            }
        }
    } else {
        $idAgrupacion = 0;
    }

    if ($checked) {
        $idCargo = obtenerCargoTrabajador($pdo, $rut, $servicio, $cuadrilla);
        if ($idCargo === null) {
            throw new Exception('No se pudo determinar el cargo del trabajador para este servicio.');
        }

        $procesoHab = resolverProcesoHabilitacionParaProgramacion($pdo, $rut, $servicio, $idCargo);
        if ($procesoHab === null) {
            throw new Exception('Debe generar o seleccionar un proceso abierto desde el detalle del trabajador antes de programar la evaluación.');
        }
        $idProcesoHab = (int)$procesoHab['id'];

        // Si ya existe, reactivar en vez de insertar
        if ($registro) {
            if ($hasEvaluacionProgramadaAgrupacion) {
                $stmtUpd = $pdo->prepare("
                    UPDATE ceo_evaluaciones_programadas
                    SET estado = 'PENDIENTE',
                        resultado = 'PENDIENTE',
                        fecha_resultado = NULL,
                        usuario_programa = :usuario,
                        fecha_programacion = NOW(),
                        id_proceso_habilitacion = :id_proceso_habilitacion,
                        id_agrupacion = :id_agrupacion,
                        cobrado = 0
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtUpd->execute([
                    ':usuario' => ($userId > 0 ? $userId : 1),
                    ':id_proceso_habilitacion' => $idProcesoHab,
                    ':id_agrupacion' => $tipo === 'PRUEBA' && $idAgrupacion > 0 ? $idAgrupacion : null,
                    ':id' => (int)$registro['id']
                ]);
            } else {
                $stmtUpd = $pdo->prepare("
                    UPDATE ceo_evaluaciones_programadas
                    SET estado = 'PENDIENTE',
                        resultado = 'PENDIENTE',
                        fecha_resultado = NULL,
                        usuario_programa = :usuario,
                        fecha_programacion = NOW(),
                        id_proceso_habilitacion = :id_proceso_habilitacion,
                        cobrado = 0
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmtUpd->execute([
                    ':usuario' => ($userId > 0 ? $userId : 1),
                    ':id_proceso_habilitacion' => $idProcesoHab,
                    ':id' => (int)$registro['id']
                ]);
            }

            echo json_encode(['ok' => true, 'msg' => 'Programación reactivada']);
            exit;
        }

        // Si no existe, insertar nuevo
        if ($hasEvaluacionProgramadaAgrupacion) {
            $stmtIns = $pdo->prepare("
                INSERT INTO ceo_evaluaciones_programadas
                    (rut, id_servicio, id_agrupacion, tipo, cuadrilla, id_proceso_habilitacion, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
                VALUES
                    (:rut, :servicio, :id_agrupacion, :tipo, :cuadrilla, :id_proceso_habilitacion, NOW(), :usuario, 'PENDIENTE', 1, 'PENDIENTE', NULL, 0)
            ");
            $stmtIns->execute([
                ':rut' => $rut,
                ':servicio' => $servicio,
                ':id_agrupacion' => $tipo === 'PRUEBA' && $idAgrupacion > 0 ? $idAgrupacion : null,
                ':tipo' => $tipo,
                ':cuadrilla' => $cuadrilla,
                ':id_proceso_habilitacion' => $idProcesoHab,
                ':usuario' => ($userId > 0 ? $userId : 1)
            ]);
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO ceo_evaluaciones_programadas
                    (rut, id_servicio, tipo, cuadrilla, id_proceso_habilitacion, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
                VALUES
                    (:rut, :servicio, :tipo, :cuadrilla, :id_proceso_habilitacion, NOW(), :usuario, 'PENDIENTE', 1, 'PENDIENTE', NULL, 0)
            ");
            $stmtIns->execute([
                ':rut' => $rut,
                ':servicio' => $servicio,
                ':tipo' => $tipo,
                ':cuadrilla' => $cuadrilla,
                ':id_proceso_habilitacion' => $idProcesoHab,
                ':usuario' => ($userId > 0 ? $userId : 1)
            ]);
        }

        echo json_encode(['ok' => true, 'msg' => 'Programación creada']);
        exit;
    }

    // Desmarcar: si existe, dejar ANULADA
    if ($registro) {
        $stmtUpd = $pdo->prepare("
            UPDATE ceo_evaluaciones_programadas
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
