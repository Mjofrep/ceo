<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/app.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$rut = trim($_GET['rut'] ?? '');
if ($rut === '') exit('RUT requerido');

$stmt = $pdo->prepare("
    SELECT *
    FROM vw_ceo_historial_evaluaciones_persona
    WHERE rut = :rut
    ORDER BY fecha_hora DESC
");
$stmt->execute([':rut' => $rut]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/vnd.ms-excel');
header("Content-Disposition: attachment; filename=historial_evaluaciones_$rut.xls");

echo "<table border='1'>";
echo "<tr>
<th>Tipo</th><th>Servicio</th><th>Fecha</th><th>Resultado</th>
<th>Nota</th><th>Empresa</th><th>Cargo</th>
<th>Evaluador</th><th>UO</th><th>Región</th>
</tr>";

foreach ($rows as $r) {
    echo "<tr>
    <td>{$r['tipo_evaluacion']}</td>
    <td>{$r['servicio']}</td>
    <td>{$r['fecha_hora']}</td>
    <td>{$r['resultado']}</td>
    <td>{$r['notafinal']}</td>
    <td>{$r['empresa']}</td>
    <td>{$r['cargo']}</td>
    <td>{$r['evaluador']}</td>
    <td>{$r['uo']}</td>
    <td>{$r['region']}</td>
    </tr>";
}
echo "</table>";
