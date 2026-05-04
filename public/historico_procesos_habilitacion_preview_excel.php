<?php
declare(strict_types=1);

session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/historico_procesos_habilitacion_lib.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();
$idServicio = (int)($_GET['id_servicio'] ?? 0);
$rut = trim((string)($_GET['rut'] ?? ''));

$data = historicoSimularProcesos($pdo, $idServicio, $rut);
$rows = $data['rows'];
$summary = $data['summary'];

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=preview_procesos_historicos.xls');
header('Pragma: no-cache');
header('Expires: 0');

echo "<html><head><meta charset='utf-8'></head><body>";
echo "<h3>Vista previa procesos historicos de habilitacion</h3>";
echo "<table border='1'>";
echo "<tr><th>Servicios incluidos</th><td>" . htmlspecialchars((string)($summary['servicios_texto'] ?: 'Sin información'), ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>Total RUT</th><td>" . (int)$summary['total_ruts'] . "</td></tr>";
echo "<tr><th>Intentos teoricos</th><td>" . (int)$summary['teoricas'] . "</td></tr>";
echo "<tr><th>Intentos terreno</th><td>" . (int)$summary['terrenos'] . "</td></tr>";
echo "<tr><th>Procesos sugeridos</th><td>" . (int)$summary['procesos'] . "</td></tr>";
echo "<tr><th>Procesos cerrados</th><td>" . (int)$summary['cerrados'] . "</td></tr>";
echo "<tr><th>Procesos abiertos</th><td>" . (int)$summary['abiertos'] . "</td></tr>";
echo "<tr><th>Procesos vencidos</th><td>" . (int)$summary['vencidos'] . "</td></tr>";
echo "</table><br>";

echo "<table border='1'>";
echo "<tr>";
foreach ([
    'Servicio',
    'RUT',
    'Nombre',
    'Cargo evaluacion',
    'Cargo proceso',
    'Origen cargo',
    'Proceso real',
    'Estado real',
    'Origen proceso',
    'Proceso sugerido',
    'Estado sugerido',
    'Tipo evaluacion',
    'Intento proceso',
    'Fecha evaluacion',
    'Resultado',
    'Puntaje',
    'Nota final',
    'Fecha base',
    'Vigente hasta',
    'Origen',
    'ID registro',
    'Observacion',
] as $heading) {
    echo '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
}
echo "</tr>";

foreach ($rows as $row) {
    echo "<tr>";
    echo '<td>' . htmlspecialchars((string)$row['servicio'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['rut'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['cargo_evaluacion'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['cargo_proceso'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['cargo_origen'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . ($row['proceso_real'] !== null ? (int)$row['proceso_real'] : '') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['estado_proceso_real'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['origen_proceso'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . (int)$row['proceso'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['estado_proceso'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['tipo'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . (int)$row['intento_proceso'] . '</td>';
    echo '<td>' . htmlspecialchars(historicoFmtDateTime($row['fecha_hora']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['resultado'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($row['puntaje'] !== null ? number_format((float)$row['puntaje'], 2, ',', '.') : '', ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($row['nota_final'] !== null ? number_format((float)$row['nota_final'], 2, ',', '.') : '', ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(historicoFmtDate($row['fecha_base']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(historicoFmtDate($row['vigente_hasta']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['origen'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . (int)$row['id_registro'] . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['observacion'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo "</tr>";
}

echo "</table></body></html>";
