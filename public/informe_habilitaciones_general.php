<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/informe_habilitaciones_general_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$serviceId = (int)($_GET['servicio'] ?? 0);
$empresaSolicitada = (int)($_GET['empresa'] ?? 0);
$empresaId = ihgResolveSelectedCompanyId($_SESSION['auth'], $empresaSolicitada);
$habilitado = ihgNormalizeHabilitadoFilter((string)($_GET['habilitado'] ?? ''));
$searchTerm = trim((string)($_GET['buscar'] ?? ''));
$dataset = ihgNormalizeDatasetKey((string)($_GET['dataset'] ?? 'data1'));
$previewLimit = max(50, min(1000, (int)($_GET['preview_limit'] ?? 300)));

$services = ihgFetchServices($pdo);
$companies = ihgFetchCompanies($pdo);
$rolUsuario = strtolower(trim((string)($_SESSION['auth']['rol'] ?? '')));
$esContratista = $rolUsuario === 'contratista';

$shouldLoadReport = isset($_GET['consultar']);
$report = null;
$error = '';

if ($shouldLoadReport) {
    try {
        $report = ihgBuildReport($pdo, [
            'service_id' => $serviceId,
            'empresa_id' => $empresaId,
            'habilitado' => $habilitado,
            'buscar' => $searchTerm,
            'dataset' => $dataset,
            'preview_limit' => $previewLimit,
        ]);
    } catch (Throwable $e) {
        $error = 'No fue posible cargar el informe con los filtros seleccionados. ' . $e->getMessage();
    }
}

$excelUrl = 'informe_habilitaciones_general_excel.php?consultar=1&servicio=' . $serviceId . '&empresa=' . $empresaId . '&habilitado=' . urlencode($habilitado) . '&buscar=' . urlencode($searchTerm);

function ihgPageEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ihgRenderTable(array $columns, array $rows): void
{
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-hover align-middle mb-0">
        <thead>
          <tr>
            <?php foreach ($columns as $column): ?>
              <th><?= ihgPageEsc((string)$column) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="<?= count($columns) ?>" class="text-center text-muted">No hay registros para los filtros seleccionados.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($columns as $column): ?>
                  <td><?= ihgPageEsc((string)($row[$column] ?? '')) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informe General de Habilitaciones | <?= ihgPageEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .card-stat { border:1px solid #e7ecf2; border-radius:1rem; background:#fff; }
    .table thead th { background:#eaf2fb; white-space:nowrap; }
    .section-anchor { scroll-margin-top: 100px; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= ihgPageEsc(APP_LOGO) ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= ihgPageEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= ihgPageEsc(APP_SUBTITLE) ?></small>
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
          <h5 class="text-primary fw-bold mb-1"><i class="bi bi-table me-2"></i>Informe General de Habilitaciones</h5>
          <div class="text-muted small">Vista transversal por servicio, con resumen general, áreas de competencia y checklist de terreno.</div>
        </div>
        <?php if ($report !== null): ?>
          <a class="btn btn-success btn-sm" href="<?= ihgPageEsc($excelUrl) ?>">
            <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="servicio" class="form-select">
            <option value="0">Todos</option>
            <?php foreach ($services as $service): ?>
              <option value="<?= (int)$service['id'] ?>" <?= $serviceId === (int)$service['id'] ? 'selected' : '' ?>>
                <?= ihgPageEsc((string)$service['servicio']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($esContratista): ?>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Empresa</label>
            <input type="text" class="form-control" value="<?= ihgPageEsc((string)($_SESSION['auth']['empresa'] ?? '')) ?>" readonly>
            <input type="hidden" name="empresa" value="<?= $empresaId ?>">
          </div>
        <?php else: ?>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Empresa</label>
            <select name="empresa" class="form-select">
              <option value="0">Todas</option>
              <?php foreach ($companies as $company): ?>
                <option value="<?= (int)$company['id'] ?>" <?= $empresaId === (int)$company['id'] ? 'selected' : '' ?>>
                  <?= ihgPageEsc((string)$company['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="col-md-2">
          <label class="form-label fw-semibold">Habilitado</label>
          <select name="habilitado" class="form-select">
            <option value="" <?= $habilitado === '' ? 'selected' : '' ?>>Todos</option>
            <option value="SI" <?= $habilitado === 'SI' ? 'selected' : '' ?>>SI</option>
            <option value="NO" <?= $habilitado === 'NO' ? 'selected' : '' ?>>NO</option>
            <option value="PENDIENTE" <?= $habilitado === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label fw-semibold">Filtro general</label>
          <input type="text" name="buscar" class="form-control" value="<?= ihgPageEsc($searchTerm) ?>" placeholder="RUT, nombre, cargo, empresa, servicio...">
        </div>

        <div class="col-md-2">
          <label class="form-label fw-semibold">Vista</label>
          <select name="dataset" class="form-select">
            <option value="data1" <?= $dataset === 'data1' ? 'selected' : '' ?>>data 1</option>
            <option value="data2" <?= $dataset === 'data2' ? 'selected' : '' ?>>data 2</option>
            <option value="data3" <?= $dataset === 'data3' ? 'selected' : '' ?>>data 3</option>
          </select>
        </div>

        <div class="col-md-1">
          <label class="form-label fw-semibold">Preview</label>
          <select name="preview_limit" class="form-select">
            <?php foreach ([100, 300, 500, 1000] as $option): ?>
              <option value="<?= $option ?>" <?= $previewLimit === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-1 d-flex gap-2">
          <button class="btn btn-primary w-100" type="submit" name="consultar" value="1"><i class="bi bi-search me-1"></i>Ver</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger shadow-sm mb-4"><?= ihgPageEsc($error) ?></div>
  <?php endif; ?>

  <?php if ($report !== null && !empty($report['warnings'])): ?>
    <div class="alert alert-warning shadow-sm mb-4">
      <div class="fw-semibold mb-2">Algunos servicios no pudieron cargarse:</div>
      <ul class="mb-0 ps-3">
        <?php foreach ($report['warnings'] as $warning): ?>
          <li><?= ihgPageEsc((string)$warning['service_name']) ?>: <?= ihgPageEsc((string)$warning['message']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($report === null && $error === ''): ?>
    <div class="alert alert-info shadow-sm">Seleccione filtros y presione <strong>Ver</strong> para generar el informe.</div>
  <?php elseif ($report !== null): ?>
    <div class="row g-3 mb-4">
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">Registros</div><div class="fs-3 fw-bold"><?= (int)$report['summary']['TOTAL'] ?></div></div></div>
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">Habilitados</div><div class="fs-3 fw-bold text-success"><?= (int)$report['summary']['SI'] ?></div></div></div>
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">No habilitados</div><div class="fs-3 fw-bold text-danger"><?= (int)$report['summary']['NO'] ?></div></div></div>
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">Pendientes</div><div class="fs-3 fw-bold text-warning"><?= (int)$report['summary']['PENDIENTE'] ?></div></div></div>
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">Servicios</div><div class="fs-3 fw-bold"><?= (int)$report['summary']['SERVICIOS'] ?></div></div></div>
      <div class="col-md-6 col-xl-2"><div class="card-stat p-3 h-100"><div class="small text-muted">Empresas</div><div class="fs-3 fw-bold"><?= (int)$report['summary']['EMPRESAS'] ?></div></div></div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <a class="btn <?= $dataset === 'data1' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?consultar=1&servicio=<?= $serviceId ?>&empresa=<?= $empresaId ?>&habilitado=<?= urlencode($habilitado) ?>&buscar=<?= urlencode($searchTerm) ?>&dataset=data1&preview_limit=<?= $previewLimit ?>">data 1</a>
      <a class="btn <?= $dataset === 'data2' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?consultar=1&servicio=<?= $serviceId ?>&empresa=<?= $empresaId ?>&habilitado=<?= urlencode($habilitado) ?>&buscar=<?= urlencode($searchTerm) ?>&dataset=data2&preview_limit=<?= $previewLimit ?>">data 2</a>
      <a class="btn <?= $dataset === 'data3' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm" href="?consultar=1&servicio=<?= $serviceId ?>&empresa=<?= $empresaId ?>&habilitado=<?= urlencode($habilitado) ?>&buscar=<?= urlencode($searchTerm) ?>&dataset=data3&preview_limit=<?= $previewLimit ?>">data 3</a>
    </div>

    <?php
      $activeColumns = $dataset === 'data1'
          ? $report['data1_columns']
          : ($dataset === 'data2' ? $report['data2_columns'] : $report['data3_columns']);
      $activeRows = $dataset === 'data1'
          ? $report['data1_rows']
          : ($dataset === 'data2' ? $report['data2_rows'] : $report['data3_rows']);
      $activeDescription = $dataset === 'data1'
          ? 'Resumen general por RUT y servicio.'
          : ($dataset === 'data2'
              ? 'Detalle normalizado por áreas de competencia, mostrando teórica y terreno.'
              : 'Detalle normalizado de checklist e ítems de terreno.');
    ?>
    <div class="card shadow-sm section-anchor" id="<?= ihgPageEsc($dataset) ?>">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h6 class="text-primary mb-0"><?= ihgPageEsc($dataset) ?></h6>
            <small class="text-muted"><?= ihgPageEsc($activeDescription) ?></small>
          </div>
          <span class="small text-muted">Mostrando <?= count($activeRows) ?> fila(s), limite vista previa <?= $previewLimit ?>.</span>
        </div>
        <?php ihgRenderTable($activeColumns, $activeRows); ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
