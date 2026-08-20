<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

function anularPendientesPlanificacionHabilitacion(PDO $pdo, string $rut, int $idServicio, int $cuadrilla): void
{
    if ($rut === '' || $idServicio <= 0 || $cuadrilla <= 0) {
        return;
    }

    $stmt = $pdo->prepare('
        UPDATE ceo_evaluaciones_programadas
           SET estado = "ANULADA"
         WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), ".", ""), "-", ""), " ", "") = REPLACE(REPLACE(REPLACE(UPPER(:rut), ".", ""), "-", ""), " ", "")
           AND id_servicio = :id_servicio
           AND cuadrilla = :cuadrilla
           AND tipo IN ("PRUEBA", "TERRENO")
           AND estado = "PENDIENTE"
    ');
    $stmt->execute([
        ':rut' => $rut,
        ':id_servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla,
    ]);
}

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
    $cuadrilla = (int)($data['cuadrilla'] ?? 0);
    $idServicioPlan = (int)($data['id_servicio'] ?? 0);

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

    if ($idServicioPlan > 0 && $idServicioPlan !== $idServicio) {
        throw new Exception('El proceso seleccionado no pertenece al servicio de la planificación actual.');
    }

    $pdo->beginTransaction();
    anularPendientesPlanificacionHabilitacion($pdo, $rut, $idServicio, $cuadrilla);

    $_SESSION['proceso_habilitacion_seleccionado'][$rut][$idServicio][$idCargo] = (int)$proceso['id'];

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'proceso' => $proceso,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
