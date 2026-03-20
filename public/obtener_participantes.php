<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$cuadrillas = $input['cuadrillas'] ?? [];

if (empty($cuadrillas)) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}

$pdo = db();

$in = implode(',', array_fill(0, count($cuadrillas), '?'));

$sql = "
SELECT 
    p.id_cuadrilla,
    p.rut,
    CONCAT(p.nombre, ' ', p.apellidos) AS nombre_part,
    p.cargo,
    e.tipo,
    sp.servicio,
    uo.desc_uo,
    em.nombre as empresa,
    ha.fecha
FROM ceo_habilitacion_participantes p
INNER JOIN ceo_evaluaciones_programadas e
        ON e.rut = p.rut
       AND e.cuadrilla = p.id_cuadrilla
       AND e.estado = 'PENDIENTE'
       AND e.tipo IN ('TERRENO','PRUEBA')
INNER JOIN ceo_habilitacion ha ON ha.cuadrilla = p.id_cuadrilla
INNER JOIN ceo_servicios_pruebas sp ON sp.id = ha.id_servicio
INNER JOIN ceo_empresas em ON em.id = ha.empresa
INNER JOIN ceo_uo uo ON uo.id = ha.uo
WHERE p.id_cuadrilla IN ($in)
ORDER BY p.id_cuadrilla, p.apellidos, p.nombre
";

$stmt = $pdo->prepare($sql);
$stmt->execute($cuadrillas);

echo json_encode([
    'ok'   => true,
    'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
]);
