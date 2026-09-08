<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/dp_lib.php';

dpModuleGuard();

$pdo = dpCeoDb();
dpEnsureTables($pdo);

$solicitudes = dpRecentSolicitudes($pdo, 200);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Historial Datos Personales | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background:#f7f9fc;">
<div class="container-fluid px-4 py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h3 class="fw-bold mb-1">Historial de consultas</h3>
      <div class="text-muted">Trazabilidad interna y acceso a informes consolidados por solicitud</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(app_url('/public/datos_personales/index.php')) ?>">Volver al modulo</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= esc(app_url('/public/general.php')) ?>">Panel</a>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Folio</th>
              <th>RUT</th>
              <th>Tipo</th>
              <th>Fecha</th>
              <th>Responsable</th>
              <th>Hallazgos</th>
              <th>Registros</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($solicitudes === []): ?>
            <tr><td colspan="8" class="text-muted">No hay consultas registradas.</td></tr>
          <?php else: ?>
            <?php foreach ($solicitudes as $row): ?>
              <tr>
                <td><?= esc($row['folio']) ?></td>
                <td><?= esc($row['rut_titular']) ?></td>
                <td><?= esc(dpTipoSolicitudLabel((string)$row['tipo_solicitud'])) ?></td>
                <td><?= esc($row['fecha_recepcion']) ?></td>
                <td><?= esc($row['usuario_nombre']) ?></td>
                <td><?= (int)($row['sistemas_con_hallazgo'] ?? 0) ?></td>
                <td><?= (int)($row['total_registros'] ?? 0) ?></td>
                <td><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="<?= esc(app_url('/public/datos_personales/detalle.php?solicitud_id=' . (int)$row['id'])) ?>">Ver informe</a><a class="btn btn-sm btn-outline-success" href="<?= esc(app_url('/public/datos_personales/export_pdf.php?solicitud_id=' . (int)$row['id'])) ?>">PDF</a></div></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>
