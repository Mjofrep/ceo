<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['auth'])) exit('Acceso denegado');

$pdo = db();
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

if (!$desde || !$hasta || $desde === $hasta || $hasta < $desde) {
  exit('Fechas inválidas');
}

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=informe_visitas_{$desde}_{$hasta}.xls");

$items = [
  'Habilitaciones ENEL X' => "s.proceso=21 AND s.estado='F' AND s.uo=12 AND s.habilitacionceo=8",
  'Charla ODI' => "s.charla=58 AND s.estado='F' AND s.habilitacionceo=6",
  'RDO' => "s.charla=54 AND s.estado='F' AND s.habilitacionceo=6 AND s.proceso=24"
];

echo "<table border='1'>";

foreach ($items as $titulo => $cond) {

  $sql = "
    SELECT
      p.rut,
      CONCAT(p.nombre,' ',p.apellidop,' ',p.apellidom) nombre,
      IF(p.asistio=1,'Sí','No') asistio,
      IF(p.autorizado=1,'Sí','No') autorizado,
      p.fechaasistio,
      p.wf
    FROM ceo_participantes_solicitud p
    JOIN ceo_solicitudes s ON s.id = p.id_solicitud
    WHERE $cond
      AND p.fechaasistio BETWEEN :d AND :h
  ";

  echo "<tr><th colspan='6'>$titulo</th></tr>";
  echo "<tr>
    <th>RUT</th><th>Nombre</th><th>Asistió</th>
    <th>Autorizado</th><th>Fecha</th><th>WF</th>
  </tr>";

  $st = $pdo->prepare($sql);
  $st->execute(['d'=>$desde,'h'=>$hasta]);

  foreach ($st as $r) {
    echo "<tr>
      <td>{$r['rut']}</td>
      <td>{$r['nombre']}</td>
      <td>{$r['asistio']}</td>
      <td>{$r['autorizado']}</td>
      <td>{$r['fechaasistio']}</td>
      <td>{$r['wf']}</td>
    </tr>";
  }

  echo "<tr><td colspan='6'></td></tr>";
}

echo "</table>";