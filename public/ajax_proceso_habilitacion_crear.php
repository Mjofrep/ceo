<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

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
    $idServicio = (int)($data['id_servicio'] ?? 0);
    $idCargo = (int)($data['id_cargo'] ?? 0);

    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    $pdo = db();

    $where = ['sr.rut = :rut'];
    $params = [':rut' => $rut];
    if ($idServicio > 0) {
        $where[] = 'sr.id_servicio = :id_servicio';
        $params[':id_servicio'] = $idServicio;
    }
    if ($idCargo > 0) {
        $where[] = 'sr.id_cargo = :id_cargo';
        $params[':id_cargo'] = $idCargo;
    }

    $stmtAsoc = $pdo->prepare('
        SELECT
            sr.id_cargo,
            sr.id_servicio,
            ch.cargo,
            sp.servicio,
            sp.descripcion
        FROM ceo_servicios_rut sr
        INNER JOIN ceo_cargos_habilitacion ch ON ch.id = sr.id_cargo
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = sr.id_servicio
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY sp.servicio ASC, ch.cargo ASC
    ');
    $stmtAsoc->execute($params);
    $asociaciones = $stmtAsoc->fetchAll(PDO::FETCH_ASSOC);

    if (empty($asociaciones)) {
        throw new Exception('No existen servicios/cargos asociados a este trabajador.');
    }

    if (($idServicio <= 0 || $idCargo <= 0) && count($asociaciones) > 1) {
        echo json_encode([
            'ok' => true,
            'requires_selection' => true,
            'options' => array_map(static function (array $row): array {
                return [
                    'id_servicio' => (int)$row['id_servicio'],
                    'id_cargo' => (int)$row['id_cargo'],
                    'servicio' => (string)$row['servicio'],
                    'descripcion' => (string)($row['descripcion'] ?? ''),
                    'cargo' => (string)$row['cargo'],
                ];
            }, $asociaciones),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $asoc = $asociaciones[0];
    $idServicio = (int)$asoc['id_servicio'];
    $idCargo = (int)$asoc['id_cargo'];

    $abierto = obtenerProcesoHabilitacionAbierto($pdo, $rut, $idServicio, $idCargo);
    $creado = false;
    if ($abierto === null) {
        $abierto = obtenerOCrearProcesoHabilitacion($pdo, $rut, $idServicio, 'CEONEXT', $idCargo);
        $creado = true;
    }

    $_SESSION['proceso_habilitacion_seleccionado'][$rut][$idServicio][$idCargo] = (int)$abierto['id'];

    echo json_encode([
        'ok' => true,
        'created' => $creado,
        'proceso' => [
            'id' => (int)$abierto['id'],
            'numero_proceso' => (int)$abierto['numero_proceso'],
            'rut' => (string)$abierto['rut'],
            'id_servicio' => (int)$abierto['id_servicio'],
            'id_cargo' => (int)$abierto['id_cargo'],
            'estado' => (string)$abierto['estado'],
            'servicio' => (string)$asoc['servicio'],
            'descripcion' => (string)($asoc['descripcion'] ?? ''),
            'cargo' => (string)$asoc['cargo'],
            'fecha_inicio' => (string)($abierto['fecha_inicio'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
