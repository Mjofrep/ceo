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

$summaryTotal = (int)($data['summary']['TOTAL'] ?? 0);
$summaryPendiente = (int)($data['summary']['PENDIENTE'] ?? 0);
$summaryAprobada = (int)($data['summary']['APROBADA'] ?? 0);
$summaryReprobada = (int)($data['summary']['REPROBADA'] ?? 0);

$pieRadius = 44;
$pieCircumference = 2 * M_PI * $pieRadius;
$piePendienteLen = $summaryTotal > 0 ? ($summaryPendiente / $summaryTotal) * $pieCircumference : 0.0;
$pieAprobadaLen = $summaryTotal > 0 ? ($summaryAprobada / $summaryTotal) * $pieCircumference : 0.0;
$pieReprobadaLen = max(0.0, $pieCircumference - $piePendienteLen - $pieAprobadaLen);
$piePendienteOffset = 0.0;
$pieAprobadaOffset = -$piePendienteLen;
$pieReprobadaOffset = -($piePendienteLen + $pieAprobadaLen);
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
    .summary-stat{height:100%;}
    .pie-card{background:#f9fbfe;border:1px solid #e6ebf2;border-radius:1rem;padding:1rem;height:100%;}
    .pie-chart{width:180px;height:180px;position:relative;flex:0 0 auto;}
    .pie-chart svg{display:block;width:100%;height:100%;overflow:visible;}
    .pie-chart-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1;text-align:center;}
    .pie-chart-center strong{font-size:1.8rem;line-height:1;}
    .legend-dot{display:inline-block;width:12px;height:12px;border-radius:999px;flex:0 0 auto;}
    .legend-pendiente{background:#f7e7a9;}
    .legend-aprobada{background:#bfe7c6;}
    .legend-reprobada{background:#f4c2c2;}
    .capture-target{position:relative;}
    .capture-button{width:30px;height:30px;padding:0;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;}
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
    <div class="summary-box p-4 mb-4 capture-target" id="serviceSummaryCapture">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
          <h5 class="mb-1">Servicio: <?= fisEsc((string)$data['servicio']) ?></h5>
          <div class="text-muted small">Grupos cargados: <?= count($data['groups']) ?> | Registros: <?= (int)($data['summary']['TOTAL'] ?? 0) ?></div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm capture-button" id="downloadSummaryImage" title="Descargar resumen como imagen" aria-label="Descargar resumen como imagen">
          <i class="bi bi-image"></i>
        </button>
      </div>
      <div class="row g-3 align-items-stretch">
        <div class="col-md-6 col-xl-2"><div class="border rounded p-3 bg-light summary-stat"><div class="small text-muted">Pendientes</div><div class="fs-4 fw-bold"><?= $summaryPendiente ?></div></div></div>
        <div class="col-md-6 col-xl-2"><div class="border rounded p-3 bg-light summary-stat"><div class="small text-muted">Aprobadas</div><div class="fs-4 fw-bold"><?= $summaryAprobada ?></div></div></div>
        <div class="col-md-6 col-xl-2"><div class="border rounded p-3 bg-light summary-stat"><div class="small text-muted">Reprobadas</div><div class="fs-4 fw-bold"><?= $summaryReprobada ?></div></div></div>
        <div class="col-12 col-xl-6">
          <div class="pie-card d-flex flex-column flex-md-row align-items-center justify-content-center gap-4">
            <div class="border rounded p-3 bg-white summary-stat text-center" style="min-width:120px;">
              <div class="small text-muted">Total</div>
              <div class="fs-2 fw-bold"><?= $summaryTotal ?></div>
            </div>
            <div class="pie-chart">
              <svg viewBox="0 0 120 120" aria-hidden="true">
                <circle cx="60" cy="60" r="<?= fisEsc((string)$pieRadius) ?>" fill="none" stroke="#eef2f7" stroke-width="24"></circle>
                <?php if ($summaryTotal > 0 && $piePendienteLen > 0): ?>
                  <circle cx="60" cy="60" r="<?= fisEsc((string)$pieRadius) ?>" fill="none" stroke="#f7e7a9" stroke-width="24" stroke-linecap="butt" stroke-dasharray="<?= fisEsc(number_format($piePendienteLen, 4, '.', '')) ?> <?= fisEsc(number_format($pieCircumference, 4, '.', '')) ?>" stroke-dashoffset="<?= fisEsc(number_format($piePendienteOffset, 4, '.', '')) ?>" transform="rotate(-90 60 60)"></circle>
                <?php endif; ?>
                <?php if ($summaryTotal > 0 && $pieAprobadaLen > 0): ?>
                  <circle cx="60" cy="60" r="<?= fisEsc((string)$pieRadius) ?>" fill="none" stroke="#bfe7c6" stroke-width="24" stroke-linecap="butt" stroke-dasharray="<?= fisEsc(number_format($pieAprobadaLen, 4, '.', '')) ?> <?= fisEsc(number_format($pieCircumference, 4, '.', '')) ?>" stroke-dashoffset="<?= fisEsc(number_format($pieAprobadaOffset, 4, '.', '')) ?>" transform="rotate(-90 60 60)"></circle>
                <?php endif; ?>
                <?php if ($summaryTotal > 0 && $pieReprobadaLen > 0): ?>
                  <circle cx="60" cy="60" r="<?= fisEsc((string)$pieRadius) ?>" fill="none" stroke="#f4c2c2" stroke-width="24" stroke-linecap="butt" stroke-dasharray="<?= fisEsc(number_format($pieReprobadaLen, 4, '.', '')) ?> <?= fisEsc(number_format($pieCircumference, 4, '.', '')) ?>" stroke-dashoffset="<?= fisEsc(number_format($pieReprobadaOffset, 4, '.', '')) ?>" transform="rotate(-90 60 60)"></circle>
                <?php endif; ?>
                <circle cx="60" cy="60" r="30" fill="#ffffff"></circle>
              </svg>
              <div class="pie-chart-center">
                <strong><?= $summaryTotal ?></strong>
                <span class="small text-muted">Total</span>
              </div>
            </div>
            <div class="w-100">
              <div class="fw-semibold mb-2">Distribución</div>
              <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-bottom border-light-subtle">
                <div class="d-flex align-items-center gap-2"><span class="legend-dot legend-pendiente"></span><span>Pendientes</span></div>
                <strong><?= $summaryPendiente ?></strong>
              </div>
              <div class="d-flex align-items-center justify-content-between gap-3 py-1 border-bottom border-light-subtle">
                <div class="d-flex align-items-center gap-2"><span class="legend-dot legend-aprobada"></span><span>Aprobadas</span></div>
                <strong><?= $summaryAprobada ?></strong>
              </div>
              <div class="d-flex align-items-center justify-content-between gap-3 py-1">
                <div class="d-flex align-items-center gap-2"><span class="legend-dot legend-reprobada"></span><span>Reprobadas</span></div>
                <strong><?= $summaryReprobada ?></strong>
              </div>
            </div>
          </div>
        </div>
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
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
  (() => {
    const button = document.getElementById('downloadSummaryImage');
    const target = document.getElementById('serviceSummaryCapture');
    if (!button || !target || typeof html2canvas === 'undefined') {
      return;
    }

    button.addEventListener('click', async () => {
      const originalIcon = button.innerHTML;
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

      try {
        const canvas = await html2canvas(target, {
          backgroundColor: '#f7f9fc',
          scale: 2,
          useCORS: true,
        });

        const link = document.createElement('a');
        const serviceName = <?= json_encode((string)($data['servicio'] ?? 'servicio'), JSON_UNESCAPED_UNICODE) ?>
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || 'servicio';
        link.download = `resumen-${serviceName}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
      } catch (error) {
        window.alert('No fue posible generar la imagen del resumen.');
      } finally {
        button.disabled = false;
        button.innerHTML = originalIcon;
      }
    });
  })();
</script>
</body>
</html>
