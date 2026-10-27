<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/formaciones_huka_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/public/index.php');
    exit;
}

$pdo = db();
$filters = fhFilters($_GET);
$services = $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio')->fetchAll(PDO::FETCH_ASSOC);
$rows = [];
$error = '';
try {
    $rows = fhFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible consultar las formaciones de HUKA Spa.';
}

$serviceIds = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['id_servicio'], $rows)));
$hayPendientes = (bool)array_filter($rows, static fn (array $row): bool => fhEstado($row['resultado'] ?? null) === 'PENDIENTE');
$puedeResumenAreas = count($serviceIds) === 1 && count($rows) > 0 && !$hayPendientes;
$queryString = fhQueryString($filters);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Formaciones HUKA Spa | <?= fhEsc(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}.topbar{background:#fff;border-bottom:1px solid #e3e6ea}.table-wrap{max-height:68vh;overflow:auto;background:#fff;border:1px solid #dee2e6;border-radius:.5rem}.table thead th{position:sticky;top:0;z-index:1;background:#eaf2fb;white-space:nowrap}.table td{white-space:nowrap}.filter-card,.modal-content{box-shadow:0 2px 10px rgba(0,0,0,.06)}.area-note-good{background:#d1e7dd!important;color:#111!important;font-weight:600}.area-note-bad{background:#dc3545!important;color:#fff!important;font-weight:600}.area-note-meta{font-size:.72rem;line-height:1.2;white-space:nowrap}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4"><div class="container d-flex align-items-center justify-content-between gap-3"><div class="d-flex align-items-center gap-2"><img src="<?= fhEsc(APP_LOGO) ?>" alt="Logo" style="height:52px"><div><div class="fw-bold"><?= fhEsc(APP_NAME) ?></div><small class="text-muted">Formaciones HUKA Spa</small></div></div><a href="general.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a></div></header>
<main class="container mb-5">
  <div class="card filter-card mb-4"><div class="card-body"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h4 class="text-primary mb-0">Personas formadas HUKA Spa</h4><small class="text-muted">Registros desde el 01-01-2026</small></div><span class="badge text-bg-light border"><?= count($rows) ?> resultados</span></div>
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-3"><label class="form-label">Servicio</label><select name="servicio" class="form-select"><option value="0">Todos</option><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $filters['servicio'] === (int)$service['id'] ? 'selected' : '' ?>><?= fhEsc((string)$service['servicio']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label">RUT</label><input name="rut" value="<?= fhEsc($filters['rut']) ?>" class="form-control" placeholder="Ej: 12345678-9"></div>
      <div class="col-md-2"><label class="form-label">Desde</label><input type="date" name="fecha_desde" min="<?= FORMACIONES_HUKA_FECHA_MINIMA ?>" value="<?= fhEsc($filters['fecha_desde']) ?>" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" name="fecha_hasta" min="<?= FORMACIONES_HUKA_FECHA_MINIMA ?>" value="<?= fhEsc($filters['fecha_hasta']) ?>" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">Estado</label><select name="estado" class="form-select"><option value="TODOS" <?= $filters['estado'] === 'TODOS' ? 'selected' : '' ?>>Todos</option><option value="APROBADO" <?= $filters['estado'] === 'APROBADO' ? 'selected' : '' ?>>Aprobados</option><option value="REPROBADO" <?= $filters['estado'] === 'REPROBADO' ? 'selected' : '' ?>>Reprobados</option><option value="PENDIENTE" <?= $filters['estado'] === 'PENDIENTE' ? 'selected' : '' ?>>Pendientes</option></select></div>
      <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" title="Consultar"><i class="bi bi-search"></i> Consultar</button><a href="formaciones_huka_excel.php<?= $queryString !== '' ? '?' . fhEsc($queryString) : '' ?>" class="btn btn-success" title="Exportar Excel"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a><button type="button" id="btnResumenAreas" class="btn btn-outline-secondary" title="Ver resumen por áreas de competencia" <?= $puedeResumenAreas ? '' : 'disabled' ?>><i class="bi bi-grid-3x3-gap"></i> Resumen por áreas</button></div>
    </form>
    <?php if (!$puedeResumenAreas): ?><div class="form-text mt-2">El resumen por áreas se habilita cuando los resultados corresponden a un único servicio y no incluyen participantes pendientes.</div><?php endif; ?>
  </div></div>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= fhEsc($error) ?></div><?php endif; ?>
  <div class="table-wrap"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>RUT</th><th>Nombre</th><th>Apellido</th><th>Cuadrilla</th><th>Fecha</th><th>Servicio</th><th>Empresa</th><th>UO</th><th>Jornada</th><th>Nota</th><th>Porcentaje</th><th>Puntaje</th><th>Correctas</th><th>Incorrectas</th><th>No contestadas</th><th>Inicio</th><th>Término</th><th>Duración</th><th>Motivo</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="21" class="text-center text-muted py-4">Sin resultados para los filtros seleccionados.</td></tr><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <?php
      $estado = fhEstado($row['resultado'] ?? null);
      $pendiente = $estado === 'PENDIENTE';
      $aprobado = $estado === 'APROBADO';
      $idCertificado = (int)($row['id_certificado'] ?? 0);
      $certificadoEmitido = (int)($row['certificado_emitido'] ?? 0) === 1;
    ?>
    <tr><td><?= fhEsc($row['rut']) ?></td><td><?= fhEsc($row['nombre']) ?></td><td><?= fhEsc($row['apellidos']) ?></td><td><?= (int)$row['cuadrilla'] ?></td><td><?= fhEsc($row['fecha']) ?></td><td><?= fhEsc($row['servicio']) ?></td><td><?= fhEsc($row['empresa']) ?></td><td><?= fhEsc($row['uo']) ?></td><td><?= fhEsc($row['jornada']) ?></td><td><?= fhEsc($row['notafinal'] ?? '') ?></td><td><?= fhEsc($row['puntaje_total'] ?? '') ?></td><td><?= fhEsc(($row['puntaje_obtenido'] ?? '') . ' / ' . ($row['puntaje_maximo'] ?? '')) ?></td><td><?= fhEsc($row['correctas'] ?? '') ?></td><td><?= fhEsc($row['incorrectas'] ?? '') ?></td><td><?= fhEsc($row['ncontestadas'] ?? '') ?></td><td><?= fhEsc($row['fecha_inicio'] ?? '') ?></td><td><?= fhEsc($row['fecha_termino'] ?? '') ?></td><td><?= fhEsc(fhDuracion($row['fecha_inicio'] ?? null, $row['fecha_termino'] ?? null)) ?></td><td><?= fhEsc($row['cierre_modo'] ?? '') ?></td><td><?= fhEsc($estado) ?></td><td><div class="d-flex gap-1"><?php if (!$pendiente): ?><button type="button" class="btn btn-outline-primary btn-sm btn-area" title="Ver detalle por áreas" data-rut="<?= fhEsc($row['rut']) ?>" data-cuadrilla="<?= (int)$row['cuadrilla'] ?>" data-servicio="<?= (int)$row['id_servicio'] ?>"><i class="bi bi-pie-chart"></i></button><a class="btn btn-outline-secondary btn-sm" title="Ver preguntas realizadas" href="formaciones_cuadrilla_preguntas_detalle.php?rut=<?= rawurlencode((string)$row['rut']) ?>&cuadrilla=<?= (int)$row['cuadrilla'] ?>&id_servicio=<?= (int)$row['id_servicio'] ?>&return_to=<?= rawurlencode('formaciones_huka.php' . ($queryString !== '' ? '?' . $queryString : '')) ?>"><i class="bi bi-list-check"></i></a><?php endif; ?><?php if ($aprobado): ?><?php if ($certificadoEmitido && $idCertificado > 0): ?><a class="btn btn-outline-success btn-sm" title="Descargar certificado generado" target="_blank" rel="noopener" href="rie_certificados.php?descargar=<?= $idCertificado ?>"><i class="bi bi-download"></i></a><?php else: ?><button type="button" class="btn btn-outline-success btn-sm btn-generar-certificado" title="Generar certificado" data-id-programacion="<?= (int)$row['id_programacion'] ?>"><i class="bi bi-award"></i></button><?php endif; ?><?php endif; ?></div></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</main>
<div class="modal fade" id="modalAreas" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="modalAreasTitulo">Áreas de competencia</h5><div class="d-flex gap-2 align-items-center"><a id="btnExportarAreas" class="btn btn-success btn-sm d-none" target="_blank" rel="noopener"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div></div><div class="modal-body" id="modalAreasContenido"></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const modalAreas = new bootstrap.Modal(document.getElementById('modalAreas'));
const contenidoAreas = document.getElementById('modalAreasContenido');
const btnExportarAreas = document.getElementById('btnExportarAreas');
function escaparHtml(value) { const node = document.createElement('span'); node.textContent = value; return node.innerHTML; }
function abrirModal(titulo, exportUrl = '') { document.getElementById('modalAreasTitulo').textContent = titulo; contenidoAreas.innerHTML = '<div class="text-center text-muted py-4">Cargando...</div>'; btnExportarAreas.classList.toggle('d-none', exportUrl === ''); if (exportUrl) btnExportarAreas.href = exportUrl; modalAreas.show(); }
function mostrarDetalleAreas(url) { abrirModal('Detalle por áreas de competencia'); fetch(url).then(r => r.json()).then(data => { if (!data.ok) throw new Error(data.error || 'No fue posible cargar el resumen.'); if (!data.data.length) { contenidoAreas.innerHTML = '<div class="alert alert-info mb-0">No hay respuestas por áreas para los registros seleccionados.</div>'; return; } let html = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Área</th><th>Correctas</th><th>Incorrectas</th><th>No contestadas</th><th>Total</th><th>%</th></tr></thead><tbody>'; data.data.forEach(r => { const clase = r.reforzar ? 'table-danger' : 'table-success'; html += `<tr class="${clase}"><td>${escaparHtml(r.area)}</td><td>${r.correctas}</td><td>${r.incorrectas}</td><td>${r.ncontestadas}</td><td>${r.total}</td><td>${r.porcentaje}%</td></tr>`; }); contenidoAreas.innerHTML = html + '</tbody></table></div>'; }).catch(error => { contenidoAreas.innerHTML = `<div class="alert alert-danger mb-0">${escaparHtml(error.message)}</div>`; }); }
function mostrarResumenAreas(url, exportUrl) { abrirModal('Resumen por áreas de competencia', exportUrl); fetch(url).then(r => r.json()).then(data => { if (!data.ok) throw new Error(data.error || 'No fue posible cargar el resumen.'); if (!data.participants.length) { contenidoAreas.innerHTML = '<div class="alert alert-info mb-0">No hay participantes con respuestas por áreas.</div>'; return; } let html = '<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr><th>RUT</th><th>Empresa</th><th>Nombre / Apellido</th><th>Nota</th>'; data.areas.forEach(area => html += `<th>${escaparHtml(area.area)}</th>`); html += '</tr></thead><tbody>'; data.participants.forEach(participant => { html += `<tr><td>${escaparHtml(participant.rut)}</td><td>${escaparHtml(participant.empresa)}</td><td>${escaparHtml(participant.nombre_completo)}</td><td>${escaparHtml(participant.nota_final)}</td>`; data.areas.forEach(area => { const value = participant.areas[String(area.id_area)]; if (!value) { html += '<td></td>'; return; } html += `<td class="text-center ${value.aprobada ? 'area-note-good' : 'area-note-bad'}"><div>${escaparHtml(value.nota)}</div><div class="area-note-meta">${escaparHtml(value.porcentaje)}%</div></td>`; }); html += '</tr>'; }); contenidoAreas.innerHTML = html + '</tbody></table></div>'; }).catch(error => { contenidoAreas.innerHTML = `<div class="alert alert-danger mb-0">${escaparHtml(error.message)}</div>`; }); }
document.querySelectorAll('.btn-area').forEach(button => button.addEventListener('click', () => mostrarDetalleAreas(`ajax_formacion_area_stats.php?rut=${encodeURIComponent(button.dataset.rut)}&cuadrilla=${button.dataset.cuadrilla}&id_servicio=${button.dataset.servicio}`)));
document.querySelectorAll('.btn-generar-certificado').forEach(button => button.addEventListener('click', async () => {
  button.disabled = true;
  try {
    const body = new URLSearchParams({ accion: 'generar_huka', id_programacion: button.dataset.idProgramacion });
    const response = await fetch('rie_certificados.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const data = await response.json();
    if (!data.ok) throw new Error(data.error || 'No fue posible generar el certificado.');
    const link = document.createElement('a');
    link.className = 'btn btn-outline-success btn-sm';
    link.title = 'Descargar certificado generado';
    link.target = '_blank';
    link.rel = 'noopener';
    link.href = data.descarga;
    link.innerHTML = '<i class="bi bi-download"></i>';
    button.replaceWith(link);
  } catch (error) {
    button.disabled = false;
    alert(error.message);
  }
}));
<?php if ($puedeResumenAreas): ?>document.getElementById('btnResumenAreas').addEventListener('click', () => mostrarResumenAreas(<?= json_encode('ajax_formaciones_huka_areas.php?' . $queryString, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode('formaciones_huka_areas_excel.php?' . $queryString, JSON_UNESCAPED_UNICODE) ?>));<?php endif; ?>
</script>
</body>
</html>
