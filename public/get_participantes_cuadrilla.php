<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok'=>false,'msg'=>'No autorizado']);
    exit;
}

$cuad = (int)($_GET['cuadrilla'] ?? 0);
if ($cuad <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Cuadrilla inválida']);
    exit;
}

$pdo = db();

$sql = "SELECT 
    p.rut,
    p.nombre,
    p.apellidos,
    p.cargo,
    h.uo
FROM ceo_habilitacion_participantes p
INNER JOIN ceo_habilitacion h 
        ON h.cuadrilla = p.id_cuadrilla
WHERE p.id_cuadrilla = ?
ORDER BY p.nombre, p.apellidos";

$st = $pdo->prepare($sql);
$st->execute([$cuad]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$uo = 0;
if (!empty($rows)) {
    $uo = (int)$rows[0]['uo']; // UO es única por cuadrilla
}

echo json_encode([
    'ok' => true,
    'uo' => $uo,
    'participantes' => $rows
]);

