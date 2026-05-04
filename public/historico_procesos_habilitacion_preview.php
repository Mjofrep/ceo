<?php
declare(strict_types=1);

session_start();

require_once '../config/db.php';
require_once '../config/app.php';
require_once '../config/functions.php';
require_once __DIR__ . '/historico_procesos_habilitacion_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$servicios = $pdo->query('SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC);
$idServicio = (int)($_GET['id_servicio'] ?? 0);
$rut = trim((string)($_GET['rut'] ?? ''));

$data = historicoSimularProcesos($pdo, $idServicio, $rut);
$rows = $data['rows'];
$summary = $data['summary'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Vista previa procesos históricos - <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {background:#f5f7fb; color:#1b2b3a;}
    .topbar {background:#fff; border-bottom:1px solid #dfe7f1;}
    .brand-title {color:#0065a4; font-weight:700;}
    .hero {background:linear-gradient(135deg,#073b63,#0b6fa4); color:#fff; border-radius:24px; overflow:hidden; position:relative;}
    .hero:after {content:""; position:absolute; inset:auto -80px -140px auto; width:340px; height:340px; background:rgba(255,255,255,.12); border-radius:50%;}
    .metric {background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18); border-radius:18px; padding:14px 16px; backdrop-filter:blur(8px);}
    .metric strong {display:block; font-size:1.45rem; line-height:1;}
    .card {border:none; box-shadow:0 10px 30px rgba(20,50,80,.08); border-radius:20px;}
    .table-responsive {max-height:620px; overflow:auto;}
    .table {min-width:1500px;}
    .table thead {position:sticky; top:0; z-index:2;}
    .table th {background:#eaf2fb; color:#16435f; font-size:.78rem; text-transform:uppercase; letter-spacing:.03em;}
    .badge-soft {border-radius:999px; padding:.35rem .65rem; font-weight:700;}
    .estado-CERRADO {background:#d1f4df; color:#116333;}
    .estado-ABIERTO {background:#fff3cd; color:#8a6300;}
    .estado-VENCIDO {background:#f8d7da; color:#842029;}
    .tipo-TEORICA {background:#dbeafe; color:#1d4ed8;}
    .tipo-TERRENO {background:#dcfce7; color:#166534;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= esc(APP_NAME) ?>" style="height:58px;">
      <div>
        <div class="brand-title h4 mb-0"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary">Simulación de procesos históricos de habilitación</small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container-fluid px-4 pb-4">
  <section class="hero p-4 mb-4">
    <div class="position-relative" style="z-index:1;">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
          <h1 class="h3 mb-2">Vista previa de asociación a procesos</h1>
          <p class="mb-0 opacity-75">Esta pantalla no modifica datos. Ordena intentos teóricos y terreno por RUT/servicio y sugiere procesos según ventana de 3 años.</p>
          <div class="mt-2 small opacity-75"><strong>Servicios incluidos:</strong> <?= esc($summary['servicios_texto'] ?: 'Sin información') ?></div>
        </div>
        <a class="btn btn-light btn-sm" href="historico_procesos_habilitacion_preview_excel.php?id_servicio=<?= (int)$idServicio ?>&rut=<?= urlencode($rut) ?>">
          <i class="bi bi-file-earmark-excel me-1"></i> Exportar Excel
        </a>
      </div>
      <div class="row g-3">
        <div class="col-6 col-md-2"><div class="metric"><small>RUT</small><strong><?= (int)$summary['total_ruts'] ?></strong></div></div>
        <div class="col-6 col-md-2"><div class="metric"><small>Teóricas</small><strong><?= (int)$summary['teoricas'] ?></strong></div></div>
        <div class="col-6 col-md-2"><div class="metric"><small>Terreno</small><strong><?= (int)$summary['terrenos'] ?></strong></div></div>
        <div class="col-6 col-md-2"><div class="metric"><small>Procesos</small><strong><?= (int)$summary['procesos'] ?></strong></div></div>
        <div class="col-6 col-md-2"><div class="metric"><small>Cerrados</small><strong><?= (int)$summary['cerrados'] ?></strong></div></div>
        <div class="col-6 col-md-2"><div class="metric"><small>Vencidos</small><strong><?= (int)$summary['vencidos'] ?></strong></div></div>
      </div>
    </div>
  </section>

  <section class="card mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select">
            <option value="0">Todos los servicios</option>
            <?php foreach ($servicios as $srv): ?>
              <option value="<?= (int)$srv['id'] ?>" <?= ((int)$srv['id'] === $idServicio) ? 'selected' : '' ?>><?= esc($srv['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">RUT</label>
          <input type="text" name="rut" class="form-control" value="<?= esc($rut) ?>" placeholder="Opcional">
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-search me-1"></i> Analizar</button>
          <a class="btn btn-outline-secondary" href="historico_procesos_habilitacion_preview.php">Limpiar</a>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-body p-3">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="text-center">
            <tr>
              <th>Servicio</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Cargo evaluación</th>
              <th>Cargo proceso</th>
              <th>Origen cargo</th>
              <th>Proceso real</th>
              <th>Estado real</th>
              <th>Origen proceso</th>
              <th>Proceso sugerido</th>
              <th>Estado sugerido</th>
              <th>Tipo</th>
              <th>Intento proceso</th>
              <th>Fecha evaluación</th>
              <th>Resultado</th>
              <th>Puntaje</th>
              <th>Nota final</th>
              <th>Fecha base</th>
              <th>Vigente hasta</th>
              <th>Origen</th>
              <th>ID registro</th>
              <th>Observación</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach ($rows as $row): ?>
            <tr>
              <td><?= esc($row['servicio']) ?></td>
              <td><?= esc($row['rut']) ?></td>
              <td><?= esc($row['nombre']) ?></td>
              <td><?= esc($row['cargo_evaluacion']) ?></td>
              <td><?= esc($row['cargo_proceso']) ?></td>
              <td><?= esc($row['cargo_origen']) ?></td>
              <td class="text-center fw-bold"><?= $row['proceso_real'] !== null ? (int)$row['proceso_real'] : '' ?></td>
              <td class="text-center"><?= $row['estado_proceso_real'] !== '' ? '<span class="badge-soft estado-' . esc($row['estado_proceso_real']) . '">' . esc($row['estado_proceso_real']) . '</span>' : '' ?></td>
              <td><?= esc($row['origen_proceso']) ?></td>
              <td class="text-center <?= ($row['proceso_real'] !== null && (int)$row['proceso_real'] !== (int)$row['proceso']) ? 'table-warning fw-bold' : 'fw-bold' ?>"><?= (int)$row['proceso'] ?></td>
              <td class="text-center"><span class="badge-soft estado-<?= esc($row['estado_proceso']) ?>"><?= esc($row['estado_proceso']) ?></span></td>
              <td class="text-center"><span class="badge-soft tipo-<?= esc($row['tipo']) ?>"><?= esc($row['tipo']) ?></span></td>
              <td class="text-center"><?= (int)$row['intento_proceso'] ?></td>
              <td><?= esc(historicoFmtDateTime($row['fecha_hora'])) ?></td>
              <td><?= esc($row['resultado']) ?></td>
              <td class="text-end"><?= esc($row['puntaje'] !== null ? number_format((float)$row['puntaje'], 2, ',', '.') : '') ?></td>
              <td class="text-end"><?= esc($row['nota_final'] !== null ? number_format((float)$row['nota_final'], 2, ',', '.') : '') ?></td>
              <td><?= esc(historicoFmtDate($row['fecha_base'])) ?></td>
              <td><?= esc(historicoFmtDate($row['vigente_hasta'])) ?></td>
              <td><?= esc($row['origen']) ?></td>
              <td class="text-center"><?= (int)$row['id_registro'] ?></td>
              <td><?= esc($row['observacion']) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="22" class="text-center text-muted py-4">No hay evaluaciones para los filtros seleccionados.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
</body>
</html>
