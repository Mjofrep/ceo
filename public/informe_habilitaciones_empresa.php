<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/informe_habilitaciones_empresa_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$rolUsuario = strtolower(trim((string)($_SESSION['auth']['rol'] ?? '')));
$esContratista = $rolUsuario === 'contratista';

$empresaSolicitada = (int)($_GET['empresa'] ?? 0);
$empresaId = iheResolveEmpresaSeleccionada($_SESSION['auth'], $empresaSolicitada);
$sheetKey = trim((string)($_GET['sheet'] ?? 'cyr'));
$searchTerm = trim((string)($_GET['buscar'] ?? ''));
$habilitadoFilter = iheNormalizeHabilitadoFilter((string)($_GET['habilitado'] ?? ''));

$report = [
    'empresa_id' => 0,
    'empresa_nombre' => '',
    'companies' => iheFetchEmpresas($pdo),
    'definitions' => iheGetSheetDefinitions(),
    'sheets' => [],
    'contractors_count' => 0,
];

if ($empresaId > 0) {
    $report = iheBuildCompanyReport($pdo, $empresaId);
    $report = iheFilterReportRowsByHabilitado($report, $habilitadoFilter);
    $report = iheFilterReportRowsBySearch($report, $searchTerm);
}

$selectedDefinition = iheGetSelectedDefinition($sheetKey);
$selectedSheet = $report['sheets'][$selectedDefinition['key']] ?? null;
if ($selectedSheet === null && !empty($report['sheets'])) {
    $selectedSheet = reset($report['sheets']);
    $selectedDefinition = $selectedSheet['definition'];
}

function ihePageEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informe de Habilitaciones por Empresa | <?= ihePageEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .table thead th { background:#eaf2fb; white-space:nowrap; }
    .sheet-pill { min-width: 180px; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= ihePageEsc(APP_LOGO) ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= ihePageEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= ihePageEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container-fluid px-4 pb-5">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
          <h5 class="text-primary fw-bold mb-1"><i class="bi bi-building me-2"></i>Informe de Habilitaciones por Empresa</h5>
          <div class="text-muted small">Consulta por servicio para administradores y contratistas, con exportación Excel multihoja.</div>
        </div>
        <?php if ($empresaId > 0): ?>
          <a
            class="btn btn-success btn-sm"
            href="informe_habilitaciones_empresa_excel.php?empresa=<?= (int)$empresaId ?>&habilitado=<?= urlencode($habilitadoFilter) ?>&buscar=<?= urlencode($searchTerm) ?>"
          >
            <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <?php if ($esContratista): ?>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Empresa</label>
            <input
              type="text"
              class="form-control"
              value="<?= ihePageEsc((string)$report['empresa_nombre']) ?>"
              readonly
            >
          </div>
        <?php else: ?>
          <div class="col-md-2">
            <label class="form-label fw-semibold">Empresa</label>
            <select name="empresa" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($report['companies'] as $company): ?>
                <option value="<?= (int)$company['id'] ?>" <?= $empresaId === (int)$company['id'] ? 'selected' : '' ?>>
                  <?= ihePageEsc((string)$company['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-2">
          <label class="form-label fw-semibold">Hoja / Servicio</label>
          <select name="sheet" class="form-select">
            <?php foreach ($report['definitions'] as $definition): ?>
              <option value="<?= ihePageEsc((string)$definition['key']) ?>" <?= $selectedDefinition['key'] === $definition['key'] ? 'selected' : '' ?>>
                <?= ihePageEsc((string)$definition['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label fw-semibold">Habilitado</label>
          <select name="habilitado" class="form-select">
            <option value="" <?= $habilitadoFilter === '' ? 'selected' : '' ?>>Todos</option>
            <option value="SI" <?= $habilitadoFilter === 'SI' ? 'selected' : '' ?>>SI</option>
            <option value="NO" <?= $habilitadoFilter === 'NO' ? 'selected' : '' ?>>NO</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Filtro general</label>
          <input
            type="text"
            name="buscar"
            class="form-control"
            value="<?= ihePageEsc($searchTerm) ?>"
            placeholder="Buscar por RUT, nombre, cargo, estado..."
          >
        </div>

        <div class="col-md-2 d-flex gap-2">
          <?php if ($esContratista): ?>
            <input type="hidden" name="empresa" value="<?= (int)$empresaId ?>">
          <?php endif; ?>
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Consultar</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($empresaId > 0 && $selectedSheet !== null): ?>
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="small text-muted mb-2">Empresa seleccionada</div>
            <div class="h5 mb-3"><?= ihePageEsc((string)$report['empresa_nombre']) ?></div>
            <div class="small text-muted">Trabajadores asociados</div>
            <div class="fs-3 fw-bold"><?= (int)$report['contractors_count'] ?></div>
            <div class="small text-muted mt-3">Registros en hoja actual</div>
            <div class="fs-4 fw-bold text-primary"><?= count($selectedSheet['rows']) ?></div>
            <?php if ($searchTerm !== ''): ?>
              <div class="small text-muted mt-3">Filtro aplicado</div>
              <div class="fw-semibold"><?= ihePageEsc($searchTerm) ?></div>
            <?php endif; ?>
            <?php if ($habilitadoFilter !== ''): ?>
              <div class="small text-muted mt-3">Habilitado</div>
              <div class="fw-semibold"><?= ihePageEsc($habilitadoFilter) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="text-primary mb-3">Hojas disponibles</h6>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($report['definitions'] as $definition): ?>
                <?php $sheetRows = $report['sheets'][$definition['key']]['rows'] ?? []; ?>
                <a
                  class="btn <?= $selectedDefinition['key'] === $definition['key'] ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm sheet-pill text-start"
                  href="?empresa=<?= (int)$empresaId ?>&sheet=<?= urlencode((string)$definition['key']) ?>&habilitado=<?= urlencode($habilitadoFilter) ?>&buscar=<?= urlencode($searchTerm) ?>"
                >
                  <?= ihePageEsc((string)$definition['title']) ?>
                  <span class="badge text-bg-light ms-2"><?= count($sheetRows) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h6 class="text-primary mb-0"><?= ihePageEsc((string)$selectedDefinition['title']) ?></h6>
            <small class="text-muted">Vista previa de la misma estructura usada en la exportación.</small>
          </div>
          <span class="small text-muted">Columnas: <?= count($selectedDefinition['columns']) ?></span>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered table-hover align-middle">
            <thead>
              <tr>
                <?php foreach ($selectedDefinition['columns'] as $column): ?>
                  <th><?= ihePageEsc((string)$column) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($selectedSheet['rows'])): ?>
                <tr>
                  <td colspan="<?= count($selectedDefinition['columns']) ?>" class="text-center text-muted">
                    <?= $searchTerm !== '' ? 'No hay registros para esta hoja, empresa y filtro aplicado.' : 'No hay registros para esta hoja y empresa.' ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($selectedSheet['rows'] as $row): ?>
                  <tr>
                    <?php foreach ($selectedDefinition['columns'] as $column): ?>
                      <td><?= ihePageEsc((string)($row[$column] ?? '')) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php elseif ($empresaId <= 0): ?>
    <div class="alert alert-info shadow-sm">Seleccione una empresa para generar la consulta.</div>
  <?php endif; ?>
</div>
</body>
</html>
