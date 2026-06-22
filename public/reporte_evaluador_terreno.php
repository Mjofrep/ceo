<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/reporte_evaluador_terreno_lib.php';

$pdo = db();
$filters = retBuildFilters();
$evaluadores = retFetchEvaluadores($pdo);
$rows = [];
$error = '';

try {
    $rows = retFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$exportUrl = 'reporte_evaluador_terreno_excel.php?' . http_build_query($filters);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte Evaluador Terreno | <?= retEsc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.brand-title { color:#0065a4; font-weight:600; }
.card { border:none; box-shadow:0 2px 4px rgba(0,0,0,.05); }
.table th { background:#eaf2fb; white-space:nowrap; }
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= retEsc(APP_NAME) ?></div>
        <small class="text-secondary"><?= retEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <div class="card rounded-4 mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="bi bi-clipboard-data me-2"></i>Reporte Evaluaciones de Terreno por Evaluador</h4>
        <div class="text-muted small">Consulta por rango de fechas, evaluador y RUT. Incluye cuadrilla, proceso, servicio y empresa.</div>
      </div>
      <div>
        <span class="badge text-bg-primary">Registros: <?= count($rows) ?></span>
      </div>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= retEsc($error) ?></div>
  <?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Fecha desde</label>
          <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?= retEsc($filters['fecha_desde']) ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Fecha hasta</label>
          <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?= retEsc($filters['fecha_hasta']) ?>" required>
        </div>
        <?php if (!empty($filters['es_admin'])): ?>
          <div class="col-md-4">
            <label class="form-label">Evaluador</label>
            <select name="id_evaluador" class="form-select form-select-sm">
              <option value="0">Todos</option>
              <?php foreach ($evaluadores as $evaluador): ?>
                <option value="<?= (int)$evaluador['id'] ?>" <?= (int)$filters['id_evaluador'] === (int)$evaluador['id'] ? 'selected' : '' ?>>
                  <?= retEsc($evaluador['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="col-md-2">
          <label class="form-label">RUT</label>
          <input type="text" name="rut" class="form-control form-control-sm" value="<?= retEsc($filters['rut']) ?>" placeholder="12345678-9">
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Consultar</button>
          <a href="<?= retEsc($exportUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover align-middle">
          <thead class="text-center">
            <tr>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Evaluador</th>
              <th>Cuadrilla</th>
              <th>Proceso</th>
              <th>Servicio</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Empresa</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="text-center text-muted">No hay registros para los filtros seleccionados.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="text-center"><?= retEsc((string)$row['fecha_rendicion']) ?></td>
                  <td class="text-center"><?= retEsc((string)$row['hora_rendicion']) ?></td>
                  <td><?= retEsc(trim((string)($row['evaluador'] ?? ''))) ?></td>
                  <td class="text-center"><?= retEsc((string)($row['cuadrilla'] ?? '')) ?></td>
                  <td class="text-center"><?= retEsc((string)($row['numero_proceso'] ?? '')) ?></td>
                  <td><?= retEsc((string)($row['servicio'] ?? '')) ?></td>
                  <td><?= retEsc((string)($row['rut'] ?? '')) ?></td>
                  <td><?= retEsc(trim((string)($row['nombre'] ?? '') . ' ' . (string)($row['apellidos'] ?? ''))) ?></td>
                  <td><?= retEsc((string)($row['empresa'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
