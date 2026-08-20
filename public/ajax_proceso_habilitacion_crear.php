<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

function resolverCargoCuadrilla(PDO $pdo, string $rut, int $cuadrilla): ?array
{
    if ($cuadrilla <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT cargo
        FROM ceo_habilitacion_participantes
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), ".", ""), "-", ""), " ", "") = REPLACE(REPLACE(REPLACE(UPPER(:rut), ".", ""), "-", ""), " ", "")
          AND id_cuadrilla = :cuadrilla
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
    ]);

    $cargo = strtoupper(trim((string)($stmt->fetchColumn() ?: '')));
    if ($cargo === '') {
        return null;
    }

    $map = [
        'SUPERVISOR' => 1,
        'OPERADOR' => 2,
        'AYUDANTE' => 3,
    ];

    if (!isset($map[$cargo])) {
        throw new Exception('El cargo de la cuadrilla debe ser Ayudante, Operador o Supervisor.');
    }

    return [
        'id_cargo' => $map[$cargo],
        'cargo' => ucfirst(strtolower($cargo)),
    ];
}

function cerrarProcesosAbiertosPrevios(PDO $pdo, string $rut, int $idServicio, int $idCargo): void
{
    if ($rut === '' || $idServicio <= 0 || $idCargo <= 0) {
        throw new Exception('No fue posible identificar el servicio/cargo del proceso a regenerar.');
    }

    $stmt = $pdo->prepare('
        UPDATE ceo_proceso_habilitacion
           SET estado = "CERRADO",
               fecha_cierre = COALESCE(fecha_cierre, NOW())
         WHERE rut = :rut
           AND id_servicio = :id_servicio
           AND id_cargo = :id_cargo
           AND estado = "ABIERTO"
    ');
    $stmt->execute([
        ':rut' => $rut,
        ':id_servicio' => $idServicio,
        ':id_cargo' => $idCargo,
    ]);
}

function crearProcesoNuevoForzado(PDO $pdo, string $rut, int $idServicio, int $idCargo, string $origen = 'CEONEXT'): array
{
    cerrarProcesosAbiertosPrevios($pdo, $rut, $idServicio, $idCargo);
    return obtenerOCrearProcesoHabilitacion($pdo, $rut, $idServicio, $origen, $idCargo);
}

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
    $idServicio = (int)($data['id_servicio'] ?? 0);
    $idCargo = (int)($data['id_cargo'] ?? 0);
    $cuadrilla = (int)($data['cuadrilla'] ?? 0);

    if ($rut === '') {
        throw new Exception('El RUT es obligatorio');
    }

    $pdo = db();

    $cargoCuadrilla = resolverCargoCuadrilla($pdo, $rut, $cuadrilla);
    if ($cargoCuadrilla !== null && $idServicio > 0) {
        $idCargo = (int)$cargoCuadrilla['id_cargo'];

        $stmtServicio = $pdo->prepare('
            SELECT servicio, descripcion
            FROM ceo_servicios_pruebas
            WHERE id = :id
            LIMIT 1
        ');
        $stmtServicio->execute([':id' => $idServicio]);
        $servicioRow = $stmtServicio->fetch(PDO::FETCH_ASSOC);
        if (!$servicioRow) {
            throw new Exception('El servicio indicado no existe.');
        }

        $pdo->beginTransaction();
        anularPendientesPlanificacionHabilitacion($pdo, $rut, $idServicio, $cuadrilla);
        $abierto = crearProcesoNuevoForzado($pdo, $rut, $idServicio, $idCargo, 'CEONEXT');
        $pdo->commit();
        $creado = true;

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
                'servicio' => (string)$servicioRow['servicio'],
                'descripcion' => (string)($servicioRow['descripcion'] ?? ''),
                'cargo' => (string)$cargoCuadrilla['cargo'],
                'fecha_inicio' => (string)($abierto['fecha_inicio'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

    $pdo->beginTransaction();
    anularPendientesPlanificacionHabilitacion($pdo, $rut, $idServicio, $cuadrilla);
    $abierto = crearProcesoNuevoForzado($pdo, $rut, $idServicio, $idCargo, 'CEONEXT');
    $pdo->commit();
    $creado = true;

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
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
