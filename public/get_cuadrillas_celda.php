<?php
// get_cuadrillas_celda.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$rolUsuario = strtolower((string) ($_SESSION['auth']['rol'] ?? ''));
$idEmpresaUser = (int) ($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');

$fecha    = $_GET['fecha']    ?? '';
$jornada  = $_GET['jornada']  ?? '';
$servicio = $_GET['servicio'] ?? '';

if (!$fecha || !$jornada || !$servicio) {
    echo json_encode(['ok' => false, 'msg' => 'Parámetros incompletos']);
    exit;
}

try {
    $pdo = db();

    $sql = "
        SELECT 
            h.cuadrilla,
            h.empresa,
            ce.nombre AS nombre_empresa,
            GROUP_CONCAT(DISTINCT ph.numero_proceso ORDER BY ph.numero_proceso SEPARATOR ', ') AS numeros_proceso,
            COUNT(DISTINCT p.id) AS total_participantes
        FROM ceo_habilitacion h
        LEFT JOIN ceo_empresas ce 
               ON h.empresa = ce.id
        LEFT JOIN ceo_habilitacion_participantes p
               ON p.id_cuadrilla = h.cuadrilla
        LEFT JOIN ceo_evaluaciones_programadas ep
               ON ep.cuadrilla = h.cuadrilla
              AND ep.id_servicio = h.id_servicio
              AND ep.rut = p.rut
              AND ep.estado <> 'ANULADA'
        LEFT JOIN ceo_proceso_habilitacion ph
               ON ph.id = ep.id_proceso_habilitacion
        WHERE h.fecha = :fecha
          AND h.jornada = :jornada
          AND h.id_servicio = :servicio
        GROUP BY h.cuadrilla, h.empresa, ce.nombre
        ORDER BY h.cuadrilla
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':fecha'    => $fecha,
        ':jornada'  => $jornada,
        ':servicio' => $servicio
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['editable'] = !$esContratista || (int) ($row['empresa'] ?? 0) === $idEmpresaUser;
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'cuadrillas' => $rows
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'Error al obtener cuadrillas: ' . $e->getMessage()
    ]);
}
