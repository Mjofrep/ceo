<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/dp_lib.php';

dpModuleGuard();

$pdo = dpCeoDb();
dpEnsureTables($pdo);

$solicitudId = (int)($_GET['solicitud_id'] ?? 0);
$solicitud = $solicitudId > 0 ? dpSolicitudWithConsulta($pdo, $solicitudId) : null;

if ($solicitud === null || (int)($solicitud['consulta_id'] ?? 0) <= 0) {
    http_response_code(404);
    echo 'Solicitud no encontrada.';
    exit;
}

$consultaId = (int)$solicitud['consulta_id'];
$detalle = dpConsultaDecodedRows($pdo, $consultaId);
$summary = json_decode((string)($solicitud['resumen_json'] ?? '{}'), true);
if (!is_array($summary)) {
    $summary = [];
}

$sistemasSensibles = array_values(array_filter($detalle, static fn(array $row): bool => (int)($row['contiene_datos_sensibles'] ?? 0) === 1));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Detalle Solicitud | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; color:#0f172a; }
    .sheet { background:#fff; border:1px solid #e5eaf1; border-radius:18px; }
    .meta-label { font-size:.78rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .meta-value { font-weight:600; }
    .system-card { border:1px solid #e7edf5; border-radius:16px; background:#fff; }
    .sample-box { max-height:260px; overflow:auto; background:#0f172a; color:#e2e8f0; border-radius:12px; padding:12px; font-size:.84rem; }
    .report-frame { background:#fff; border-radius:18px; }
    @media print {
      .no-print { display:none !important; }
      body { background:#fff; }
      .report-frame { box-shadow:none !important; }
    }
  </style>
</head>
<body>
<div class="container-fluid px-4 py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
    <div>
      <h3 class="fw-bold mb-1">Respuesta consolidada</h3>
      <div class="text-muted">Solicitud <?= esc((string)$solicitud['folio']) ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(app_url('/public/datos_personales/index.php')) ?>">Volver al modulo</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= esc(app_url('/public/datos_personales/historial.php')) ?>">Historial</a>
      <a class="btn btn-success btn-sm" href="<?= esc(app_url('/public/datos_personales/export_pdf.php?solicitud_id=' . (int)$solicitud['id'])) ?>">Exportar PDF</a>
      <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Imprimir</button>
    </div>
  </div>

  <div class="report-frame shadow-sm">
    <?= dpRenderFormalDocument($solicitud, $summary, $detalle, true, false) ?>
  </div>
</div>
</body>
</html>
