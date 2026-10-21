<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/ranking_errores_preguntas_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

function repEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();
$modality = (string)($_GET['modalidad'] ?? 'habilitacion');
if (!in_array($modality, ['habilitacion', 'formacion'], true)) {
    $modality = 'habilitacion';
}
$serviceId = (int)($_GET['id_servicio'] ?? 0);
$type = (string)($_GET['tipo'] ?? 'teorica');
if (!in_array($type, ['teorica', 'terreno'], true) || $modality === 'formacion') {
    $type = 'teorica';
}
$role = strtolower(trim((string)($_SESSION['auth']['rol'] ?? '')));
$companyId = $role === 'contratista' ? (int)($_SESSION['auth']['id_empresa'] ?? 0) : 0;
$services = repFetchServices($pdo, $modality);
$report = null;
$error = '';

if ($serviceId > 0) {
    try {
        $report = repBuildRanking($pdo, $modality, $serviceId, $companyId, $type);
    } catch (OutOfBoundsException|InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        $error = 'No fue posible cargar el ranking de errores.';
    }
}

$topRows = $report !== null ? array_slice($report['rows'], 0, 10) : [];
$maxErrors = max(array_column($topRows, 'incorrectas') ?: [1]);
$excelUrl = 'ranking_errores_preguntas_excel.php?modalidad=' . urlencode($modality) . '&tipo=' . urlencode($type) . '&id_servicio=' . $serviceId;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ranking de Errores | <?= repEsc(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}.topbar{background:#fff;border-bottom:1px solid #e3e6ea}.table thead th{background:#eaf2fb;white-space:nowrap}.chart-row{display:grid;grid-template-columns:minmax(170px,34%) 1fr auto;gap:.75rem;align-items:center}.chart-label{font-size:.82rem;line-height:1.2}.chart-track{height:1.5rem;background:#edf1f5;border-radius:.35rem;overflow:hidden}.chart-bar{height:100%;background:linear-gradient(90deg,#dc3545,#f08080);min-width:2px;border-radius:.35rem}.chart-value{font-size:.8rem;font-weight:700;white-space:nowrap}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4"><div class="container d-flex justify-content-between align-items-center"><div class="d-flex gap-2 align-items-center"><img src="<?= repEsc(APP_LOGO) ?>" style="height:55px" alt="Logo"><div><div class="fw-bold"><?= repEsc(APP_NAME) ?></div><small class="text-muted"><?= repEsc(APP_SUBTITLE) ?></small></div></div><a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a></div></header>

<main class="container mb-5">
  <section class="card shadow-sm mb-4"><div class="card-body"><h4 class="text-primary mb-1"><i class="bi bi-bar-chart-line me-2"></i>Ranking de preguntas con mayor error</h4><p class="text-muted small mb-3">Considera el último intento rendido por cada participante para el servicio seleccionado.</p><form method="get" class="row g-3 align-items-end"><div class="col-md-3"><label class="form-label fw-semibold">Modalidad</label><select name="modalidad" class="form-select" onchange="this.form.submit()"><option value="habilitacion" <?= $modality === 'habilitacion' ? 'selected' : '' ?>>Habilitaciones</option><option value="formacion" <?= $modality === 'formacion' ? 'selected' : '' ?>>Formaciones</option></select></div><div class="col-md-3"><label class="form-label fw-semibold">Tipo de evaluación</label><select name="tipo" class="form-select" <?= $modality === 'formacion' ? 'disabled' : '' ?>><option value="teorica" <?= $type === 'teorica' ? 'selected' : '' ?>>Teórica</option><?php if ($modality === 'habilitacion'): ?><option value="terreno" <?= $type === 'terreno' ? 'selected' : '' ?>>Terreno</option><?php endif; ?></select><?php if ($modality === 'formacion'): ?><input type="hidden" name="tipo" value="teorica"><?php endif; ?></div><div class="col-md-4"><label class="form-label fw-semibold">Servicio</label><select name="id_servicio" class="form-select" required><option value="">Seleccione...</option><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $serviceId === (int)$service['id'] ? 'selected' : '' ?>><?= repEsc((string)$service['servicio']) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Consultar</button></div></form></div></section>

  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= repEsc($error) ?></div><?php endif; ?>
  <?php if ($report !== null): ?>
    <section class="card shadow-sm mb-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4"><div><h5 class="mb-1"><?= repEsc((string)$report['servicio']) ?></h5><div class="small text-muted">Modalidad: <?= $modality === 'formacion' ? 'Formaciones' : 'Habilitaciones' ?> | Tipo: <?= $type === 'terreno' ? 'Terreno' : 'Teórica' ?> | Participantes considerados: <?= (int)$report['participantes'] ?></div></div><div class="d-flex gap-2 align-items-center"><span class="badge text-bg-danger fs-6"><?= count($report['rows']) ?> pregunta<?= count($report['rows']) === 1 ? '' : 's' ?> con errores</span><a href="<?= repEsc($excelUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a></div></div>
      <?php if (!$topRows): ?><div class="text-muted">No hay respuestas incorrectas para el servicio seleccionado.</div><?php else: ?><h6 class="mb-3">Top 10 por cantidad de respuestas incorrectas</h6><div class="vstack gap-3"><?php foreach ($topRows as $row): ?><div class="chart-row"><div class="chart-label"><span class="text-muted">#<?= (int)$row['ranking'] ?></span> <?= repEsc((string)$row['pregunta']) ?></div><div class="chart-track" title="<?= (int)$row['incorrectas'] ?> errores"><div class="chart-bar" style="width:<?= number_format(($row['incorrectas'] / $maxErrors) * 100, 2, '.', '') ?>%"></div></div><div class="chart-value"><?= (int)$row['incorrectas'] ?> | <?= number_format((float)$row['porcentaje_error'], 1, ',', '.') ?>%</div></div><?php endforeach; ?></div><?php endif; ?></div></section>

    <section class="card shadow-sm"><div class="card-body"><h5 class="text-primary mb-3">Detalle del ranking</h5><div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0"><thead><tr><th>#</th><th>ID pregunta</th><th>Área de competencia</th><th>Pregunta</th><?php if ($type === 'terreno'): ?><th>Ponderación</th><?php else: ?><th>Alternativas</th><th>Alternativa más elegida</th><?php endif; ?><th>Respondidas</th><th>Cumple<?= $type === 'terreno' ? '' : ' / Correctas' ?></th><th>No cumple<?= $type === 'terreno' ? '' : ' / Incorrectas' ?></th><th><?= $type === 'terreno' ? 'No aplica' : 'No contestadas' ?></th><th>% error</th></tr></thead><tbody><?php if (!$report['rows']): ?><tr><td colspan="<?= $type === 'terreno' ? 10 : 11 ?>" class="text-center text-muted">Sin errores para mostrar.</td></tr><?php else: ?><?php foreach ($report['rows'] as $row): ?><tr><td><?= (int)$row['ranking'] ?></td><td><?= (int)$row['id_pregunta'] ?></td><td><?= repEsc((string)$row['area_competencia']) ?></td><td><?= repEsc((string)$row['pregunta']) ?></td><?php if ($type === 'terreno'): ?><td><?= number_format((float)$row['ponderacion'], 2, ',', '.') ?></td><?php else: ?><td class="small"><?= repEsc((string)$row['alternativas']) ?></td><td><?= repEsc((string)$row['alternativa_habitual']) ?></td><?php endif; ?><td><?= (int)$row['respondidas'] ?></td><td><?= (int)$row['correctas'] ?></td><td class="table-danger fw-semibold"><?= (int)$row['incorrectas'] ?></td><td><?= (int)$row['no_contestadas'] ?></td><td class="table-danger fw-semibold"><?= number_format((float)$row['porcentaje_error'], 1, ',', '.') ?>%</td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></section>
  <?php endif; ?>
</main>
</body>
</html>
