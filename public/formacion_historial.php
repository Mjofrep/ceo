<?php
declare(strict_types=1);
require_once '../config/db.php';

$rut = $_GET['rut'] ?? '';
if ($rut === '') {
    echo "<div class='alert alert-warning'>RUT no especificado</div>";
    exit;
}

$pdo = db();

$sql = "
SELECT 
   ps.rut,
   ps.nombre,
   ps.apellidop,
   ps.apellidom,
   s.nsolicitud,
   s.fecha,
   s.horainicio,
   s.horatermino,
   sv.servicio AS servicio,
   u.desc_uo AS unidad_operativa,
   e.nombre     AS empresa,
   p.desc_proceso, ht.desc_tipo
FROM ceo_formacion_participantes_solicitud ps
INNER JOIN ceo_formacion_solicitudes s ON s.nsolicitud = ps.id_solicitud
LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
LEFT JOIN ceo_uo u ON u.id = s.uo
LEFT JOIN ceo_empresas e ON e.id = s.contratista
LEFT JOIN ceo_formaciontipo ht ON s.habilitacionceo = ht.id
LEFT JOIN ceo_procesos p ON s.proceso = p.id
WHERE ps.rut = :rut
ORDER BY s.fecha DESC, s.horainicio DESC
";

$st = $pdo->prepare($sql);
$st->execute([':rut' => $rut]);
$data = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$data) {
    echo "<div class='alert alert-info'>No hay historial para este participante.</div>";
    exit;
}

$nombre = trim($data[0]['nombre'].' '.$data[0]['apellidop'].' '.$data[0]['apellidom']);
?>

<h6 class="mb-3 text-primary">
  <i class="bi bi-person me-2"></i><?= htmlspecialchars($nombre) ?>  
  <small class="text-muted">(<?= htmlspecialchars($rut) ?>)</small>
</h6>

<div class="table-responsive">
<table class="table table-bordered table-sm">
  <thead class="table-light">
    <tr>
      <th>Fecha</th>
      <th>Hora</th>
      <th>N° Formacion</th>
      <th>Servicio</th>
      <th>UO</th>
      <th>Empresa</th>
      <th>Proceso</th>
      <th>Habilitacion</th>
    </tr>
  </thead>
  <tbody>

<?php foreach($data as $d): ?>
  <tr>
    <td><?= htmlspecialchars($d['fecha']) ?></td>
    <td><?= htmlspecialchars(substr($d['horainicio'],0,5)) ?> - <?= htmlspecialchars(substr($d['horatermino'],0,5)) ?></td>
    <td><?= (int)$d['nsolicitud'] ?></td>
    <td><?= htmlspecialchars($d['servicio']) ?></td>
    <td><?= htmlspecialchars($d['unidad_operativa']) ?></td>
    <td><?= htmlspecialchars($d['empresa']) ?></td>
    <td><?= htmlspecialchars($d['desc_proceso']) ?></td>
    <td><?= htmlspecialchars($d['desc_tipo']) ?></td>
  </tr>
<?php endforeach; ?>

  </tbody>
</table>
</div>
