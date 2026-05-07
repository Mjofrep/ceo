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

$sqlResumen = "
    SELECT
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA' THEN 1 ELSE 0 END) AS anulados,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'REPROBADO' THEN 1 ELSE 0 END) AS reprobados,
        SUM(CASE WHEN ep.id IS NULL
                  OR (
                      UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                      AND UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE'))) NOT IN ('APROBADO', 'REPROBADO')
                  ) THEN 1 ELSE 0 END) AS pendientes,
        COUNT(*) AS total
    FROM ceo_formacion_participantes p
    INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
    LEFT JOIN (
        SELECT ep1.*
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
    ) ep ON ep.rut = p.rut AND ep.id_servicio = f.id_servicio AND ep.cuadrilla = p.id_cuadrilla
";

$resumenGlobal = $pdo->query($sqlResumen)->fetch(PDO::FETCH_ASSOC) ?: [
    'aprobados' => 0,
    'reprobados' => 0,
    'anulados' => 0,
    'pendientes' => 0,
    'total' => 0,
];

$sqlResumenCuadrillas = "
    SELECT
        f.id,
        f.cuadrilla,
        f.fecha,
        s.servicio,
        e.nombre AS empresa,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA' THEN 1 ELSE 0 END) AS anulados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'REPROBADO' THEN 1 ELSE 0 END) AS reprobados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND (
                      ep.id IS NULL
                      OR (
                          UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                          AND UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE'))) NOT IN ('APROBADO', 'REPROBADO')
                      )
                  ) THEN 1 ELSE 0 END) AS pendientes,
        COUNT(p.rut) AS total
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_formacion_participantes p ON p.id_cuadrilla = f.cuadrilla
    LEFT JOIN (
        SELECT ep1.*
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
    ) ep ON ep.rut = p.rut AND ep.id_servicio = f.id_servicio AND ep.cuadrilla = p.id_cuadrilla
    GROUP BY f.id, f.cuadrilla, f.fecha, s.servicio, e.nombre
    ORDER BY f.fecha DESC, f.id DESC
";

$resumenCuadrillas = $pdo->query($sqlResumenCuadrillas)->fetchAll(PDO::FETCH_ASSOC);
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

  <div class="mt-4">
    <h6 class="text-primary mb-2"><i class="bi bi-bar-chart-line me-2"></i>Resumen Global</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle bg-white">
        <thead>
          <tr>
            <th>Aprobados</th>
            <th>Reprobados</th>
            <th>Anulados</th>
            <th>Pendientes</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="text-success fw-semibold"><?= (int)$resumenGlobal['aprobados'] ?></td>
            <td class="text-danger fw-semibold"><?= (int)$resumenGlobal['reprobados'] ?></td>
            <td class="text-secondary fw-semibold"><?= (int)$resumenGlobal['anulados'] ?></td>
            <td class="text-warning fw-semibold"><?= (int)$resumenGlobal['pendientes'] ?></td>
            <td class="fw-semibold"><?= (int)$resumenGlobal['total'] ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">
    <h6 class="text-primary mb-2"><i class="bi bi-grid-3x3-gap me-2"></i>Analisis por Cuadrilla</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover align-middle bg-white">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Cuadrilla</th>
            <th>Servicio</th>
            <th>Empresa</th>
            <th>Aprobados</th>
            <th>Reprobados</th>
            <th>Anulados</th>
            <th>Pendientes</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($resumenCuadrillas)): ?>
            <tr><td colspan="9" class="text-center text-muted">Sin registros</td></tr>
          <?php else: ?>
            <?php foreach ($resumenCuadrillas as $r): ?>
              <tr class="fila-cuadrilla" data-id="<?= (int)$r['id'] ?>" data-cuadrilla="<?= (int)$r['cuadrilla'] ?>">
                <td><?= esc((string)$r['fecha']) ?></td>
                <td><?= (int)$r['cuadrilla'] ?></td>
                <td><?= esc((string)$r['servicio']) ?></td>
                <td><?= esc((string)$r['empresa']) ?></td>
                <td class="text-success fw-semibold"><?= (int)$r['aprobados'] ?></td>
                <td class="text-danger fw-semibold"><?= (int)$r['reprobados'] ?></td>
                <td class="text-secondary fw-semibold"><?= (int)$r['anulados'] ?></td>
                <td class="text-warning fw-semibold"><?= (int)$r['pendientes'] ?></td>
                <td class="fw-semibold"><?= (int)$r['total'] ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
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
