<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
$areasLibrary = __DIR__ . '/habilitaciones_cuadrilla_areas_lib.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($areasLibrary, true);
}
require_once $areasLibrary;
require_once __DIR__ . '/habilitaciones_cuadrilla_areas_batch_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}
$esContratista = strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista';

try {
    $pdo = db();
    $headers = hcaHeadersFromRequest($pdo, $_GET);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit($e->getMessage());
} catch (Throwable $e) {
    $message = 'habilitaciones_cuadrilla_detalle: ' . $e->getMessage() . PHP_EOL;
    error_log($message);
    error_log($message, 3, __DIR__ . '/error_log');
    http_response_code(500);
    exit('No se pudo cargar la selección de cuadrillas. Intente nuevamente o contacte al administrador.');
}
$multiple = count($headers) > 1;
$selectedIds = array_map(static fn(array $header): int => (int)$header['id'], $headers);
$crewNumbers = array_map(static fn(array $header): string => (string)$header['cuadrilla'], $headers);
$service = (string)$headers[0]['servicio'];
$selectedParticipants = [];
foreach ($headers as $header) {
    foreach (hcaParticipants($pdo, $header) as $participant) {
        $participant['cuadrilla'] = (string)$header['cuadrilla'];
        $selectedParticipants[] = $participant;
    }
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle habilitación - <?= esc(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:#f7f9fc}.topbar{background:#fff;border-bottom:1px solid #e3e6ea}.area-good{background:#d1e7dd!important;color:#111!important;font-weight:600}.area-bad{background:#dc3545!important;color:#fff!important;font-weight:600}.area-note{font-size:.78rem}</style>
</head>
<body>
<header class="topbar py-3 mb-4"><div class="container d-flex justify-content-between align-items-center"><div class="d-flex gap-2 align-items-center"><img src="<?= APP_LOGO ?>" style="height:55px" alt="<?= esc(APP_NAME) ?>"><div><div class="fw-bold"><?= APP_NAME ?></div><small class="text-muted"><?= APP_SUBTITLE ?></small></div></div><a href="habilitaciones_cuadrillas.php" class="btn btn-outline-primary btn-sm">Volver</a></div></header>
<main class="container-fluid px-4">
    <section class="card shadow-sm mb-4"><div class="card-body"><h5 class="fw-bold text-primary">Selección de habilitaciones</h5><div class="row g-3 align-items-center"><div class="col-md-5"><strong>Servicio:</strong> <?= esc($service) ?></div><div class="col-md-4"><strong>Cuadrilla<?= $multiple ? 's' : '' ?>:</strong> <?= esc(implode(', ', $crewNumbers)) ?></div><div class="col-md-3"><strong>Áreas de competencia:</strong> <button type="button" class="btn btn-outline-secondary btn-sm ms-1" data-area-action data-tipo="teorica"><i class="bi bi-grid-3x3-gap"></i> Teórica</button><button type="button" class="btn btn-outline-secondary btn-sm ms-1" data-area-action data-tipo="terreno"><i class="bi bi-signpost-split"></i> Terreno</button></div></div></div></section>
    <section class="card shadow-sm mb-4"><div class="card-body"><h5 class="fw-bold text-primary">Participantes seleccionados</h5><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Cuadrilla</th><th>RUT</th><th>Nombre / Apellidos</th><th>Cargo</th></tr></thead><tbody><?php foreach ($selectedParticipants as $participant): ?><tr><td><?= esc($participant['cuadrilla']) ?></td><td><?= esc($participant['rut']) ?></td><td><?= esc(trim((string)$participant['nombre'] . ' ' . (string)$participant['apellidos'])) ?></td><td><?= esc($participant['cargo']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if (!$selectedParticipants): ?><div class="text-muted">No se encontraron participantes para las cuadrillas seleccionadas.</div><?php endif; ?></div></section>
</main>
<div class="modal fade" id="modalAreas" tabindex="-1" aria-labelledby="tituloModalAreas" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title" id="tituloModalAreas">Resumen por áreas de competencia</h5><div id="subtitulo" class="small text-muted"></div></div><div class="d-flex gap-2"><a id="excel" class="btn btn-success btn-sm" target="_blank" rel="noopener"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div></div><div class="modal-body" id="contenido">Cargando...</div></div></div></div>
    <div class="modal fade" id="modalRespuestasTeoricas" tabindex="-1" aria-labelledby="tituloModalRespuestasTeoricas" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title" id="tituloModalRespuestasTeoricas">Detalle de respuestas teóricas</h5><div id="subtituloRespuestasTeoricas" class="small text-muted"></div></div><div class="d-flex gap-2"><a id="excelRespuestasTeoricas" class="btn btn-success btn-sm" target="_blank" rel="noopener"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div></div><div class="modal-body" id="contenidoRespuestasTeoricas">Cargando...</div></div></div></div>
    <div class="modal fade" id="modalRespuestasTerreno" tabindex="-1" aria-labelledby="tituloModalRespuestasTerreno" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title" id="tituloModalRespuestasTerreno">Revisión de preguntas de terreno</h5><div id="subtituloRespuestasTerreno" class="small text-muted"></div></div><div class="d-flex gap-2"><a id="excelRespuestasTerreno" class="btn btn-success btn-sm" target="_blank" rel="noopener"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div></div><div class="modal-body" id="contenidoRespuestasTerreno">Cargando...</div></div></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>const esContratista=<?= $esContratista ? 'true' : 'false' ?>;</script>
<script>
const modal=new bootstrap.Modal('#modalAreas'),body=document.getElementById('contenido'),sub=document.getElementById('subtitulo'),excel=document.getElementById('excel'),selectedIds=<?= json_encode($selectedIds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,multiple=<?= $multiple ? 'true' : 'false' ?>,service=<?= json_encode($service, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,crews=<?= json_encode($crewNumbers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;function esc(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}function query(){return selectedIds.map(id=>'ids[]='+encodeURIComponent(id)).join('&')}function render(data){if(!data.participants?.length)return '<div class="text-muted">No hay participantes con esta evaluación rendida.</div>';let h='<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr>'+(multiple?'<th>Cuadrilla</th>':'')+'<th>RUT</th><th>Empresa</th><th>Nombre / Apellidos</th><th>Fecha de evaluación</th><th>Nota</th>';data.areas.forEach(a=>h+='<th>'+esc(a.area)+'</th>');h+='</tr></thead><tbody>';data.participants.forEach(p=>{h+='<tr>'+(multiple?'<td>'+esc(p.cuadrilla)+'</td>':'')+'<td>'+esc(p.rut)+'</td><td>'+esc(p.empresa)+'</td><td>'+esc(p.nombre_completo)+'</td><td>'+esc(p.fecha_evaluacion)+'</td><td>'+esc(p.nota_final)+'</td>';data.areas.forEach(a=>{const x=p.areas[String(a.id_area)];h+=x?'<td class="text-center '+(x.aprobada?'area-good':'area-bad')+'" title="'+esc(x.correctas)+' / '+esc(x.total)+'">'+esc(x.nota)+'</td>':'<td></td>'});h+='</tr>'});return h+'</tbody></table></div>'}document.querySelectorAll('[data-area-action]').forEach(button=>button.addEventListener('click',()=>{const type=button.dataset.tipo,params=query();sub.textContent='Cuadrilla'+(multiple?'s ':' ')+crews.join(', ')+' | '+(type==='teorica'?'Evaluación teórica':'Evaluación de terreno')+' | '+service;excel.href='habilitaciones_cuadrilla_areas_'+type+'_excel.php?'+params;body.innerHTML='Cargando...';modal.show();fetch('ajax_habilitacion_area_'+type+'_resumen.php?'+params,{cache:'no-store'}).then(response=>response.json()).then(response=>body.innerHTML=response.ok?render(response):'<div class="text-danger">'+esc(response.error||'No se pudo cargar el resumen.')+'</div>').catch(()=>body.innerHTML='<div class="text-danger">No se pudo cargar el resumen.</div>')}));
</script>
<script>
let areaSummaryType = '';
document.querySelectorAll('[data-area-action]').forEach(button => button.addEventListener('click', () => {
  areaSummaryType = button.dataset.tipo;
}, true));

function render(data) {
  if (!data.participants?.length) return '<div class="text-muted">No hay participantes con esta evaluación rendida.</div>';
  let html = '<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr>' + (multiple ? '<th>Cuadrilla</th>' : '') + '<th>RUT</th><th>Empresa</th><th>Nombre / Apellidos</th><th>Fecha de evaluación</th><th>Nota</th>';
  data.areas.forEach(area => html += '<th>' + esc(area.area) + '</th>');
  html += '</tr></thead><tbody>';
  data.participants.forEach(participant => {
    html += '<tr>' + (multiple ? '<td>' + esc(participant.cuadrilla) + '</td>' : '') + '<td>' + esc(participant.rut) + '</td><td>' + esc(participant.empresa) + '</td><td>' + esc(participant.nombre_completo) + '</td><td>' + esc(participant.fecha_evaluacion) + '</td><td>' + esc(participant.nota_final) + '</td>';
    data.areas.forEach(area => {
      const result = participant.areas[String(area.id_area)];
      if (!result || (areaSummaryType === 'terreno' && result.nota === '1,00')) {
        html += '<td></td>';
        return;
      }
      html += '<td class="text-center ' + (result.aprobada ? 'area-good' : 'area-bad') + '" title="' + esc(result.correctas) + ' / ' + esc(result.total) + '">' + esc(result.nota) + '</td>';
    });
    html += '</tr>';
  });
  return html + '</tbody></table></div>';
}
</script>
<script>
(() => {
  if (esContratista) return;

  const areaExcel = document.getElementById('excel');
  const responsesModal = new bootstrap.Modal(document.getElementById('modalRespuestasTeoricas'));
  const responsesBody = document.getElementById('contenidoRespuestasTeoricas');
  const responsesSubtitle = document.getElementById('subtituloRespuestasTeoricas');
  const responsesExcel = document.getElementById('excelRespuestasTeoricas');
  const responsesButton = document.createElement('button');

  responsesButton.type = 'button';
  responsesButton.className = 'btn btn-outline-primary btn-sm';
  responsesButton.innerHTML = '<i class="bi bi-list-check"></i> Ver respuestas';
  responsesButton.hidden = true;
  areaExcel.insertAdjacentElement('afterend', responsesButton);

  const responseQuery = () => selectedIds.map(id => 'ids[]=' + encodeURIComponent(id)).join('&');
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

  function renderResponses(rows) {
    if (!rows?.length) return '<div class="text-muted">No hay respuestas teóricas para mostrar.</div>';
    let html = '<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr>';
    if (multiple) html += '<th>Cuadrilla</th>';
    html += '<th>RUT</th><th>Empresa</th><th>Nombre</th><th>Apellido</th><th>Área de competencia</th><th>Pregunta</th><th>Alternativas</th><th>Correcta</th><th>Elegida</th></tr></thead><tbody>';
    rows.forEach(row => {
      const alternatives = escapeHtml(row.alternativas || '').replace(/\n/g, '<br>');
      html += '<tr' + (Number(row.validacion) === 0 ? ' class="table-danger"' : '') + '>';
      if (multiple) html += '<td>' + escapeHtml(row.cuadrilla) + '</td>';
      html += '<td>' + escapeHtml(row.rut) + '</td><td>' + escapeHtml(row.empresa) + '</td><td>' + escapeHtml(row.nombre) + '</td><td>' + escapeHtml(row.apellidos) + '</td><td>' + escapeHtml(row.area_competencia) + '</td><td>' + escapeHtml(row.pregunta) + '</td><td>' + alternatives + '</td><td>' + escapeHtml(row.alternativa_correcta) + '</td><td>' + escapeHtml(row.alternativa_elegida) + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  document.querySelectorAll('[data-area-action]').forEach(button => button.addEventListener('click', () => {
    responsesButton.hidden = button.dataset.tipo !== 'teorica';
  }));

  responsesButton.addEventListener('click', () => {
    const params = responseQuery();
    responsesSubtitle.textContent = 'Cuadrilla' + (multiple ? 's ' : ' ') + crews.join(', ') + ' | ' + service;
    responsesExcel.href = 'habilitaciones_cuadrilla_respuestas_teorica_excel.php?' + params;
    responsesBody.innerHTML = '<div class="text-muted">Cargando...</div>';
    responsesModal.show();

    fetch('ajax_habilitacion_respuestas_teorica.php?' + params, {cache: 'no-store'})
      .then(response => response.json())
      .then(response => {
        if (!response?.ok) {
          responsesBody.innerHTML = '<div class="text-danger">' + escapeHtml(response?.error || 'No se pudo cargar el detalle de respuestas.') + '</div>';
          return;
        }
        responsesBody.innerHTML = renderResponses(response.data || []);
      })
      .catch(() => {
        responsesBody.innerHTML = '<div class="text-danger">No se pudo cargar el detalle de respuestas.</div>';
      });
  });
})();
</script>
<script>
(() => {
  if (esContratista) return;

  const areaExcel = document.getElementById('excel');
  const terrainModal = new bootstrap.Modal(document.getElementById('modalRespuestasTerreno'));
  const terrainBody = document.getElementById('contenidoRespuestasTerreno');
  const terrainSubtitle = document.getElementById('subtituloRespuestasTerreno');
  const terrainExcel = document.getElementById('excelRespuestasTerreno');
  const terrainButton = document.createElement('button');

  terrainButton.type = 'button';
  terrainButton.className = 'btn btn-outline-primary btn-sm';
  terrainButton.innerHTML = '<i class="bi bi-list-check"></i> Revisión de preguntas';
  terrainButton.hidden = true;
  areaExcel.insertAdjacentElement('afterend', terrainButton);

  const terrainQuery = () => selectedIds.map(id => 'ids[]=' + encodeURIComponent(id)).join('&');
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));

  function renderTerrainResponses(rows) {
    if (!rows?.length) return '<div class="text-muted">No hay preguntas de terreno para mostrar.</div>';
    let html = '<div class="table-responsive"><table class="table table-bordered table-sm align-middle"><thead><tr>';
    if (multiple) html += '<th>Cuadrilla</th>';
    html += '<th>RUT</th><th>Empresa</th><th>Nombre</th><th>Apellido</th><th>Área de competencia</th><th>Pregunta</th><th>Ponderación</th><th>Resultado</th></tr></thead><tbody>';
    rows.forEach(row => {
      html += '<tr' + (Number(row.no_cumple) === 1 ? ' class="table-danger"' : '') + '>';
      if (multiple) html += '<td>' + escapeHtml(row.cuadrilla) + '</td>';
      html += '<td>' + escapeHtml(row.rut) + '</td><td>' + escapeHtml(row.empresa) + '</td><td>' + escapeHtml(row.nombre) + '</td><td>' + escapeHtml(row.apellidos) + '</td><td>' + escapeHtml(row.area_competencia) + '</td><td>' + escapeHtml(row.pregunta) + '</td><td>' + escapeHtml(row.ponderacion) + '</td><td>' + escapeHtml(row.resultado) + '</td></tr>';
    });
    return html + '</tbody></table></div>';
  }

  document.querySelectorAll('[data-area-action]').forEach(button => button.addEventListener('click', () => {
    terrainButton.hidden = button.dataset.tipo !== 'terreno';
  }));

  terrainButton.addEventListener('click', () => {
    const params = terrainQuery();
    terrainSubtitle.textContent = 'Cuadrilla' + (multiple ? 's ' : ' ') + crews.join(', ') + ' | ' + service;
    terrainExcel.href = 'habilitaciones_cuadrilla_respuestas_terreno_excel.php?' + params;
    terrainBody.innerHTML = '<div class="text-muted">Cargando...</div>';
    terrainModal.show();

    fetch('ajax_habilitacion_respuestas_terreno.php?' + params, {cache: 'no-store'})
      .then(response => response.json())
      .then(response => {
        if (!response?.ok) {
          terrainBody.innerHTML = '<div class="text-danger">' + escapeHtml(response?.error || 'No se pudo cargar el detalle de preguntas de terreno.') + '</div>';
          return;
        }
        terrainBody.innerHTML = renderTerrainResponses(response.data || []);
      })
      .catch(() => {
        terrainBody.innerHTML = '<div class="text-danger">No se pudo cargar el detalle de preguntas de terreno.</div>';
      });
  });
})();
</script>
</body>
</html>
