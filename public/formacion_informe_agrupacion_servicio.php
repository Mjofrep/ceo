<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/formacion_informe_agrupacion_servicio_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
fisEnsureTable($pdo);
$services = fisFetchServiceOptions($pdo);
$idServicio = (int)($_GET['id_servicio'] ?? 0);
$data = null;
$error = '';

if ($idServicio > 0) {
    try {
        $data = fisFetchReportData($pdo, $idServicio);
    } catch (Throwable $e) {
        $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el informe.';
    }
}

$excelUrl = 'formacion_informe_agrupacion_servicio_excel.php?id_servicio=' . $idServicio;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informe Agrupación por Servicio | <?= fisEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid #e3e6ea;}
    .summary-box{background:#fff;border-radius:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
    .report-group{background:#fff;border-radius:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
    .report-group .table thead th{background:#eaf2fb;white-space:nowrap;}
    .text-low-score{color:#c62828;font-weight:700;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= fisEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= fisEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="cargar_informe_agrupacion_servicios.php" class="btn btn-outline-secondary btn-sm">Cargar Excel</a>
      <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
    </div>
  </div>
</header>

<div class="container mb-5">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h4 class="text-primary mb-3"><i class="bi bi-table me-2"></i>Informe Agrupación por Servicio</h4>
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach ($services as $service): ?>
              <option value="<?= (int)$service['id'] ?>" <?= $idServicio === (int)$service['id'] ? 'selected' : '' ?>><?= (int)$service['id'] ?> - <?= fisEsc((string)$service['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Consultar</button>
          <?php if ($data !== null): ?>
            <a href="<?= fisEsc($excelUrl) ?>" class="btn btn-success"><i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= fisEsc($error) ?></div>
  <?php endif; ?>

  <?php if ($data !== null): ?>
    <div class="summary-box p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
          <h5 class="mb-1">Servicio: <?= fisEsc((string)$data['servicio']) ?></h5>
          <div class="text-muted small">Grupos cargados: <?= count($data['groups']) ?> | Registros: <?= (int)($data['summary']['TOTAL'] ?? 0) ?></div>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Pendientes</div><div class="fs-4 fw-bold"><?= (int)($data['summary']['PENDIENTE'] ?? 0) ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Aprobadas</div><div class="fs-4 fw-bold"><?= (int)($data['summary']['APROBADA'] ?? 0) ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Reprobadas</div><div class="fs-4 fw-bold"><?= (int)($data['summary']['REPROBADA'] ?? 0) ?></div></div></div>
      </div>
    </div>

    <?php if (empty($data['groups'])): ?>
      <div class="alert alert-warning">No existen datos cargados para el servicio seleccionado.</div>
    <?php endif; ?>

    <?php foreach ($data['groups'] as $group): ?>
      <div class="report-group p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h5 class="mb-0"><?= fisEsc((string)$group['group_label']) ?></h5>
          <span class="badge text-bg-light border">Cuadrilla <?= (int)$group['cuadrilla'] ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead>
              <tr>
                <th>N°</th>
                <th>RUT</th>
                <th>Nombre</th>
                <th>Apellido</th>
                <th>Cargo</th>
                <th>Prueba C-Integrada</th>
                <th>Prueba SE</th>
                <th>RDO</th>
                <th>Resultado de Habilitación</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($group['rows'] as $row): ?>
                <tr>
                  <td><?= (int)$row['orden_item'] ?></td>
                  <td><?= fisEsc((string)$row['rut']) ?></td>
                  <td><?= fisEsc((string)$row['nombre']) ?></td>
                  <td><?= fisEsc((string)$row['apellido']) ?></td>
                  <td><?= fisEsc((string)$row['cargo']) ?></td>
                  <td class="<?= (($row['prueba_c_integrada_pct'] ?? 0) * 100 < 80) ? 'text-low-score' : '' ?>">
                    <?= number_format(((float)$row['prueba_c_integrada']) * 100, 0) ?>%
                </td>

                <td class="<?= (($row['prueba_se_pct'] ?? 0)  < 80) ? 'text-low-score' : '' ?>">
                    <?= number_format(((float)$row['prueba_se']), 0) ?>%
                </td>

                <td class="<?= (($row['rdo_pct'] ?? 0) * 100 < 80) ? 'text-low-score' : '' ?>">
                    <?= number_format(((float)$row['rdo']) * 100, 0) ?>%
                </td>

                <td class="<?= (($row['resultado_habilitacion_pct'] ?? 0) * 100 < 80) ? 'text-low-score' : '' ?>">
                    <?= number_format(((float)$row['resultado_habilitacion']) * 100, 0) ?>%
                </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
