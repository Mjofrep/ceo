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

$mensaje = '';
$mensajeTipo = 'info';
$analysis = $_SESSION[fisSessionKey()] ?? null;
$idServicio = (int)($_POST['id_servicio'] ?? ($analysis['id_servicio'] ?? 0));
$services = fisFetchServiceOptions($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'analizar') {
        try {
            if ($idServicio <= 0) {
                throw new RuntimeException('Debe seleccionar un servicio.');
            }
            if (empty($_FILES['excel']['tmp_name'])) {
                throw new RuntimeException('Debe seleccionar el archivo Informe agrupacion por servicios.xlsx.');
            }

            $blocks = fisParseWorkbookReport((string)$_FILES['excel']['tmp_name']);
            if (!$blocks) {
                throw new RuntimeException('No se detectaron grupos o filas validas en Hoja1.');
            }
            $resolvedBlocks = fisResolveBlocksToCuadrillas($pdo, $idServicio, $blocks);
            $analysis = [
                'created_at' => date('Y-m-d H:i:s'),
                'id_servicio' => $idServicio,
                'file_name' => (string)($_FILES['excel']['name'] ?? 'Informe agrupacion por servicios.xlsx'),
                'blocks' => $resolvedBlocks,
            ];
            $_SESSION[fisSessionKey()] = $analysis;
            $mensaje = 'Análisis completado. Revise los grupos antes de importar.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            unset($_SESSION[fisSessionKey()]);
            $analysis = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'importar') {
        try {
            if (!is_array($analysis) || empty($analysis['blocks'])) {
                throw new RuntimeException('No hay un análisis válido disponible para importar.');
            }
            $result = fisImportBlocks(
                $pdo,
                (int)$analysis['id_servicio'],
                (string)($analysis['file_name'] ?? 'Informe agrupacion por servicios.xlsx'),
                (int)($_SESSION['auth']['id'] ?? 0),
                $analysis['blocks']
            );
            unset($_SESSION[fisSessionKey()]);
            $analysis = null;
            $mensaje = 'Carga finalizada. Filas importadas o actualizadas: ' . (int)$result['imported'] . '.';
            if (!empty($result['skipped_groups'])) {
                $mensaje .= ' Grupos omitidos: ' . count($result['skipped_groups']) . '.';
            }
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'limpiar') {
        unset($_SESSION[fisSessionKey()]);
        $analysis = null;
        $mensaje = 'Análisis descartado.';
        $mensajeTipo = 'secondary';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Carga Informe Agrupación por Servicios | <?= fisEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid #e3e6ea;}
    .summary-box{background:#fff;border-radius:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
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
    <a href="/ceo.noetica.cl/public/formacion_informe_agrupacion_servicio.php" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h4 class="text-primary mb-2"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Informe Agrupación por Servicios</h4>
      <p class="text-muted mb-0">Cargador del Excel externo para registrar <strong>Prueba C-Integrada</strong>, <strong>RDO</strong> y <strong>Resultado de Habilitación</strong> por servicio y cuadrilla.</p>
    </div>
  </div>

  <?php if ($mensaje !== ''): ?>
    <div class="alert alert-<?= fisEsc($mensajeTipo) ?>"><?= fisEsc($mensaje) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="accion" value="analizar">
        <div class="col-md-5">
          <label class="form-label fw-semibold">Archivo Excel</label>
          <input type="file" name="excel" class="form-control" accept=".xlsx" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach ($services as $service): ?>
              <option value="<?= (int)$service['id'] ?>" <?= $idServicio === (int)$service['id'] ? 'selected' : '' ?>><?= (int)$service['id'] ?> - <?= fisEsc((string)$service['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Analizar</button>
        </div>
      </form>
      <?php if ($analysis): ?>
        <form method="post" class="mt-3 d-inline">
          <input type="hidden" name="accion" value="importar">
          <button class="btn btn-success" type="submit"><i class="bi bi-upload me-1"></i>Importar</button>
        </form>
        <form method="post" class="mt-3 d-inline ms-2">
          <input type="hidden" name="accion" value="limpiar">
          <button class="btn btn-outline-secondary" type="submit">Limpiar análisis</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (is_array($analysis)): ?>
    <div class="summary-box p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
          <h5 class="mb-1">Resumen del análisis</h5>
          <div class="text-muted small">Generado el <?= fisEsc((string)$analysis['created_at']) ?></div>
          <div class="text-muted small">Archivo: <strong><?= fisEsc((string)$analysis['file_name']) ?></strong> | Servicio ID <?= (int)$analysis['id_servicio'] ?></div>
        </div>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Grupos detectados</div><div class="fs-4 fw-bold"><?= count($analysis['blocks']) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Grupos resueltos</div><div class="fs-4 fw-bold"><?= count(array_filter($analysis['blocks'], static fn(array $b): bool => ($b['status'] ?? '') === 'RESUELTO')) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Grupos ambiguos</div><div class="fs-4 fw-bold"><?= count(array_filter($analysis['blocks'], static fn(array $b): bool => ($b['status'] ?? '') === 'AMBIGUO')) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Sin cuadrilla</div><div class="fs-4 fw-bold"><?= count(array_filter($analysis['blocks'], static fn(array $b): bool => ($b['status'] ?? '') === 'SIN_CUADRILLA')) ?></div></div></div>
      </div>
      <?php foreach ($analysis['blocks'] as $block): ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
              <div class="fw-semibold"><?= fisEsc((string)$block['group_label']) ?></div>
              <span class="badge text-bg-<?= ($block['status'] ?? '') === 'RESUELTO' ? 'success' : (($block['status'] ?? '') === 'AMBIGUO' ? 'warning text-dark' : 'danger') ?>"><?= fisEsc((string)$block['status']) ?></span>
            </div>
            <div class="small text-muted mb-2"><?= fisEsc((string)($block['message'] ?? '')) ?><?php if (!empty($block['cuadrilla'])): ?> Cuadrilla: <strong><?= (int)$block['cuadrilla'] ?></strong><?php endif; ?></div>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>N°</th><th>RUT</th><th>Nombre</th><th>Apellido</th><th>Cargo</th><th>Prueba C-Integrada</th><th>RDO</th><th>Resultado de Habilitación</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (($block['rows'] ?? []) as $row): ?>
                    <tr>
                      <td><?= (int)($row['orden_item'] ?? 0) ?></td>
                      <td><?= fisEsc((string)($row['rut'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['nombre'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['apellido'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['cargo'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['prueba_c_integrada'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['rdo'] ?? '')) ?></td>
                      <td><?= fisEsc((string)($row['resultado_habilitacion_raw'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
