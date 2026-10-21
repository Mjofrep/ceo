<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
if (empty($_SESSION['auth'])) { header('Location: ' . app_url('/public/index.php')); exit; }
$pdo = db();
$sql = 'SELECT h.id, h.id_servicio, h.cuadrilla, h.fecha, h.jornada, h.estado, e.nombre AS empresa, u.desc_uo AS uo, sp.servicio, h.nsolicitud
        FROM ceo_habilitacion h INNER JOIN ceo_empresas e ON e.id = h.empresa
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio LEFT JOIN ceo_uo u ON u.id = h.uo
        WHERE UPPER(TRIM(COALESCE(h.estado, \'\'))) = \'CERRADO\'
          AND (
              EXISTS (
                  SELECT 1
                  FROM ceo_resultado_pruebat rpt
                  INNER JOIN ceo_preguntas_servicios ps
                    ON ps.id = rpt.id_pregunta
                   AND ps.id_servicio = h.id_servicio
                  WHERE rpt.proceso = h.cuadrilla
                    AND ps.areacomp IS NOT NULL
              )
              OR EXISTS (
                  SELECT 1
                  FROM ceo_seccion_resultado_terreno srt
                  INNER JOIN ceo_resultado_prueba_terreno rptt ON rptt.id_resultado = srt.id
                  INNER JOIN ceo_seccion_terreno st ON st.id = rptt.id_seccion AND st.orden > 1
                  WHERE srt.id_servicio = h.id_servicio
                    AND srt.nsolicitud = h.cuadrilla
              )
          )';
$params = [];
if (strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista') { $sql .= ' AND h.empresa = :empresa'; $params[':empresa'] = (int)($_SESSION['auth']['id_empresa'] ?? 0); }
$sql .= ' ORDER BY h.fecha DESC, h.cuadrilla DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resumen de habilitaciones - <?= esc(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>body{background:#f7f9fc}.topbar{background:#fff;border-bottom:1px solid #e3e6ea}.table-hover tbody tr{cursor:pointer}</style>
</head>
<body>
<header class="topbar py-3 mb-4"><div class="container d-flex justify-content-between align-items-center"><div class="d-flex gap-2 align-items-center"><img src="<?= APP_LOGO ?>" style="height:55px" alt="<?= esc(APP_NAME) ?>"><div><div class="fw-bold"><?= APP_NAME ?></div><small class="text-muted"><?= APP_SUBTITLE ?></small></div></div><a href="<?= APP_BASE ?>/public/general.php" class="btn btn-outline-primary btn-sm">Volver</a></div></header>
<main class="container-fluid px-4">
    <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h5 class="fw-bold text-primary mb-0"><i class="bi bi-clipboard-data me-2"></i>Resumen de habilitaciones por cuadrilla</h5><input id="filtro" class="form-control form-control-sm" style="max-width:360px" placeholder="Buscar cuadrilla, empresa, UO o servicio" aria-label="Buscar cuadrillas"></div>
        <form id="seleccionCuadrillas" action="habilitaciones_cuadrilla_detalle.php" method="get">
            <div class="d-flex justify-content-between align-items-center mb-3"><small class="text-muted">Seleccione una o más cuadrillas del mismo servicio.</small><button id="verSeleccionadas" type="submit" class="btn btn-primary btn-sm" disabled><i class="bi bi-people me-1"></i>Ver cuadrillas seleccionadas</button></div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle" id="tabla"><thead class="text-center"><tr><th scope="col"><span class="visually-hidden">Seleccionar</span></th><th scope="col">Cuadrilla</th><th scope="col">Empresa</th><th scope="col">UO</th><th scope="col">Servicio</th><th scope="col">Fecha</th><th scope="col">Jornada</th><th scope="col">Solicitud</th><th scope="col">Estado</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr data-id="<?= (int)$row['id'] ?>"><td class="text-center"><input class="form-check-input seleccion-cuadrilla" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" data-servicio-id="<?= (int)$row['id_servicio'] ?>" aria-label="Seleccionar cuadrilla C<?= (int)$row['cuadrilla'] ?> del servicio <?= esc($row['servicio']) ?>"></td><td>C<?= (int)$row['cuadrilla'] ?></td><td><?= esc($row['empresa']) ?></td><td><?= esc($row['uo']) ?></td><td><?= esc($row['servicio']) ?></td><td><?= esc($row['fecha']) ?></td><td><?= esc($row['jornada']) ?></td><td><?= esc($row['nsolicitud']) ?></td><td><?= esc($row['estado']) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </form>
    </div></div>
</main>
<script>
const filtro=document.getElementById('filtro'),tabla=document.getElementById('tabla'),form=document.getElementById('seleccionCuadrillas'),verSeleccionadas=document.getElementById('verSeleccionadas'),checks=[...document.querySelectorAll('.seleccion-cuadrilla')];
function actualizarSeleccion(){const seleccionadas=checks.filter(check=>check.checked),servicio=seleccionadas[0]?.dataset.servicioId||'';checks.forEach(check=>check.disabled=Boolean(servicio)&&!check.checked&&check.dataset.servicioId!==servicio);verSeleccionadas.disabled=seleccionadas.length===0}
filtro.addEventListener('input',()=>{const texto=filtro.value.toLowerCase();tabla.querySelectorAll('tbody tr').forEach(fila=>fila.hidden=!fila.textContent.toLowerCase().includes(texto))});
checks.forEach(check=>check.addEventListener('change',actualizarSeleccion));
form.addEventListener('submit',event=>{if(!checks.some(check=>check.checked)){event.preventDefault();alert('Seleccione al menos una cuadrilla.')}});
tabla.querySelectorAll('tbody tr').forEach(fila=>fila.addEventListener('dblclick',event=>{if(event.target.closest('input, button, a, label'))return;location.href='habilitaciones_cuadrilla_detalle.php?id='+fila.dataset.id}));
</script>
</body>
</html>
