<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/dp_lib.php';

dpModuleGuard();

$pdo = dpCeoDb();
dpEnsureTables($pdo);

$tiposSolicitud = ['ACCESO', 'RECTIFICACION', 'ELIMINACION', 'BLOQUEO'];
$form = [
    'rut' => trim((string)($_POST['rut'] ?? '')),
    'tipo_solicitud' => trim((string)($_POST['tipo_solicitud'] ?? 'ACCESO')),
    'detalle_solicitud' => trim((string)($_POST['detalle_solicitud'] ?? '')),
    'motivo_interno' => trim((string)($_POST['motivo_interno'] ?? 'Solicitud externa de datos personales')),
];

$result = null;
$error = '';
$detailUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = dpCreateSolicitudAndSearch($form);
        $detailUrl = app_url('/public/datos_personales/detalle.php?solicitud_id=' . (int)$result['solicitud_id']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$recientes = dpRecentSolicitudes($pdo, 12);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Datos Personales | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e6ebf2; }
    .metric { border:1px solid #e7edf5; border-radius:14px; background:#fff; }
    .metric .label { font-size:.82rem; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .metric .value { font-size:1.5rem; font-weight:700; color:#0f172a; }
    .sample-box { max-height:320px; overflow:auto; background:#0f172a; color:#e2e8f0; border-radius:12px; padding:12px; font-size:.84rem; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-3 align-items-center">
      <img src="<?= esc(APP_LOGO) ?>" alt="Logo" style="height:52px;">
      <div>
        <div class="fw-bold">Modulo Datos Personales</div>
        <small class="text-muted">Consulta consolidada por RUT con trazabilidad interna</small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= esc(app_url('/public/general.php')) ?>">Volver al panel</a>
      <a class="btn btn-primary btn-sm" href="<?= esc(app_url('/public/datos_personales/historial.php')) ?>">Historial</a>
    </div>
  </div>
</header>

<main class="container-fluid px-4 pb-4">
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <strong>Consulta registrada.</strong>
        Folio <strong><?= esc($result['folio']) ?></strong> para RUT <strong><?= esc($form['rut']) ?></strong>.
      </div>
      <div class="d-flex gap-2 align-items-center flex-wrap">
        <div class="small text-muted">Solicitud #<?= (int)$result['solicitud_id'] ?> / Consulta #<?= (int)$result['consulta_id'] ?></div>
        <a class="btn btn-sm btn-success" href="<?= esc($detailUrl) ?>">Ver respuesta formal</a>
        <a class="btn btn-sm btn-outline-success" href="<?= esc(app_url('/public/datos_personales/export_pdf.php?solicitud_id=' . (int)$result['solicitud_id'])) ?>">PDF</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="fw-bold text-primary mb-3"><i class="bi bi-shield-lock me-2"></i>Nueva solicitud</h5>
          <form method="post" autocomplete="off">
            <div class="mb-3">
              <label class="form-label">RUT titular</label>
              <input type="text" name="rut" class="form-control" value="<?= esc($form['rut']) ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Tipo de solicitud</label>
              <select name="tipo_solicitud" class="form-select">
                <?php foreach ($tiposSolicitud as $tipo): ?>
                  <option value="<?= esc($tipo) ?>" <?= $form['tipo_solicitud'] === $tipo ? 'selected' : '' ?>><?= esc($tipo) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Detalle solicitud externa</label>
              <textarea name="detalle_solicitud" class="form-control" rows="4" placeholder="Ejemplo: acceso a datos de acreditacion, finalidad y conservacion."><?= esc($form['detalle_solicitud']) ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Motivo interno</label>
              <input type="text" name="motivo_interno" class="form-control" value="<?= esc($form['motivo_interno']) ?>">
            </div>
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-2"></i>Buscar y registrar</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-xl-8">
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <div class="metric p-3">
            <div class="label">Solicitudes recientes</div>
            <div class="value"><?= count($recientes) ?></div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="metric p-3">
            <div class="label">Bases consultadas</div>
            <div class="value">4</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="metric p-3">
            <div class="label">Conservacion</div>
            <div class="value">10 anos</div>
          </div>
        </div>
      </div>

      <?php if ($result === null): ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <h5 class="fw-bold mb-3">Alcance del modulo</h5>
            <ul class="mb-0">
              <li>Busca por RUT en CEONext, Salud, Forms y Feedback.</li>
              <li>Registra internamente cuando llego la solicitud, quien consulto y que se encontro.</li>
              <li>Genera una respuesta formal consolidada por solicitud.</li>
            </ul>
          </div>
        </div>
      <?php else: ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <h5 class="fw-bold mb-0">Resumen de respuesta</h5>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-primary btn-sm" href="<?= esc($detailUrl) ?>">Abrir respuesta formal</a>
                <a class="btn btn-outline-success btn-sm" href="<?= esc(app_url('/public/datos_personales/export_pdf.php?solicitud_id=' . (int)$result['solicitud_id'])) ?>">Exportar PDF</a>
              </div>
            </div>
            <div class="alert alert-primary mb-3"><?= esc(dpSummaryNarrative($result['summary'])) ?></div>
            <div class="row g-3">
              <div class="col-md-4"><div class="metric p-3"><div class="label">Sistemas consultados</div><div class="value"><?= (int)$result['summary']['sistemas_consultados'] ?></div></div></div>
              <div class="col-md-4"><div class="metric p-3"><div class="label">Sistemas con hallazgos</div><div class="value"><?= (int)$result['summary']['sistemas_con_hallazgo'] ?></div></div></div>
              <div class="col-md-4"><div class="metric p-3"><div class="label">Registros muestreados</div><div class="value"><?= (int)$result['summary']['total_registros'] ?></div></div></div>
            </div>
          </div>
        </div>

        <?php foreach ($result['results'] as $system): ?>
          <?php $status = dpSystemStatus($system); ?>
          <div class="card shadow-sm mb-3">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                  <h5 class="fw-bold mb-1"><?= esc($system['sistema_nombre']) ?></h5>
                  <div class="text-muted small"><?= esc($system['base_datos']) ?> · <?= esc($system['fuente']) ?></div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                  <?php if ($system['contiene_datos_sensibles']): ?><span class="badge text-bg-danger">Datos sensibles</span><?php endif; ?>
                  <span class="badge <?= esc($status['class']) ?>"><?= esc($status['label']) ?></span>
                </div>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-4"><strong>Datos encontrados</strong><div><?= esc(dpSystemCategoriesText($system)) ?></div></div>
                <div class="col-md-4"><strong>Concepto en el sistema</strong><div><?= esc(dpSystemConceptText($system)) ?></div></div>
                <div class="col-md-4"><strong>Fechas registradas</strong><div><?= esc(dpSystemDatesText($system)) ?></div></div>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-5"><strong>Finalidad</strong><div><?= esc($system['finalidad']) ?></div></div>
                <div class="col-md-4"><strong>Conservacion</strong><div><?= esc($system['conservacion']) ?></div></div>
                <div class="col-md-3"><strong>Total</strong><div><?= (int)$system['total_registros'] ?></div></div>
              </div>
              <?php if (trim((string)$system['observaciones']) !== ''): ?>
                <div class="alert alert-light border mb-3"><strong>Observacion:</strong> <?= esc($system['observaciones']) ?></div>
              <?php endif; ?>
              <details>
                <summary class="fw-semibold">Ver respaldo tecnico</summary>
                <div class="sample-box mt-3"><pre class="mb-0"><?= esc(json_encode($system['muestra'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
              </details>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Solicitudes recientes</h5>
            <a href="<?= esc(app_url('/public/datos_personales/historial.php')) ?>" class="btn btn-outline-primary btn-sm">Ver historial completo</a>
          </div>
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
              <?php if ($recientes === []): ?>
                <tr><td colspan="8" class="text-muted">No hay solicitudes registradas aun.</td></tr>
              <?php else: ?>
                <?php foreach ($recientes as $row): ?>
                  <tr>
                    <td><?= esc($row['folio']) ?></td>
                    <td><?= esc($row['rut_titular']) ?></td>
                    <td><?= esc($row['tipo_solicitud']) ?></td>
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
  </div>
</main>
</body>
</html>
