<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

$sql = "
    SELECT
        f.id,
        f.cuadrilla,
        f.fecha,
        f.jornada,
        s.servicio,
        e.nombre AS empresa,
        u.desc_uo AS uo
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_uo u ON u.id = f.uo
    ORDER BY f.fecha DESC, f.id DESC
";

$cuadrillas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Cuadrillas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.table thead th {background:#eaf2fb; position: sticky; top: 0; z-index: 1;}
.scroll-box {max-height: 70vh; overflow: auto; border:1px solid #dee2e6; border-radius:8px; background:#fff;}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title h5 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="text-primary mb-0"><i class="bi bi-list-check me-2"></i>Cuadrillas Formaciones</h5>
    <div class="text-muted small">Doble click para ver detalle</div>
  </div>

  <div class="scroll-box">
    <table class="table table-hover table-sm align-middle">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Cuadrilla</th>
          <th>Servicio</th>
          <th>Empresa</th>
          <th>UO</th>
          <th>Jornada</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($cuadrillas)): ?>
          <tr><td colspan="6" class="text-center text-muted">Sin registros</td></tr>
        <?php else: ?>
          <?php foreach ($cuadrillas as $c): ?>
            <tr class="fila-cuadrilla" data-id="<?= (int)$c['id'] ?>" data-cuadrilla="<?= (int)$c['cuadrilla'] ?>">
              <td><?= esc((string)$c['fecha']) ?></td>
              <td><?= (int)$c['cuadrilla'] ?></td>
              <td><?= esc((string)$c['servicio']) ?></td>
              <td><?= esc((string)$c['empresa']) ?></td>
              <td><?= esc((string)$c['uo']) ?></td>
              <td><?= esc((string)$c['jornada']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.querySelectorAll('.fila-cuadrilla').forEach(row => {
  row.style.cursor = 'pointer';
  row.addEventListener('dblclick', function (e) {
    if (e.target.closest('a, button, input')) return;
    const id = this.dataset.id;
    const cuad = this.dataset.cuadrilla;
    window.location.href = `formaciones_cuadrilla_detalle.php?id=${encodeURIComponent(id)}&cuadrilla=${encodeURIComponent(cuad)}`;
  });
});
</script>

</body>
</html>
