<?php
// /public/solicitudes.php
// ----------------------------------------------------------
// Página de gestión de Solicitudes del CEO (versión final)
// ----------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();
$solicitudes = [];
$error = '';

/* ============================================================
   Determinar visibilidad según empresa y rol
   ============================================================ */
$idRol     = (int)($_SESSION['auth']['id_rol'] ?? 0);
$idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);

$where  = '';
$params = [];

if ($idRol === 1 || $idEmpresa === 38) {
  $where = '1=1'; // Admin o ENEL
} else {
  $where = 's.contratista = :empresa';
  $params[':empresa'] = $idEmpresa;
}

/* ============================================================
   Consulta principal de solicitudes con filtro dinámico
   ============================================================ */
try {
  $sql = "
    SELECT 
      s.id,
      s.nsolicitud,
      CONCAT(u.nombres, ' ', u.apellidos) AS solicitante,
      p.desc_patios AS patio,
      s.fecha,
      s.horainicio,
      s.horatermino,
      s.estado,
      ce.nombre AS contratista,
      cp.desc_proceso AS proceso,
      ch.desc_tipo AS habilitacionceo,
      s.tipohabilitacion,
      (
        SELECT COUNT(*) 
        FROM ceo_participantes_solicitud ps 
        WHERE ps.id_solicitud = s.nsolicitud
      ) AS cantidad_personas
    FROM ceo_solicitudes s
    LEFT JOIN ceo_usuarios u ON s.solicitante = u.id
    LEFT JOIN ceo_patios    p ON s.patio = p.id
    LEFT JOIN ceo_empresas  ce ON s.contratista = ce.id
    LEFT JOIN ceo_procesos  cp ON s.proceso = cp.id
    LEFT JOIN ceo_habilitaciontipo ch ON s.habilitacionceo = ch.id
    WHERE $where
    ORDER BY s.nsolicitud DESC LIMIT 100
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Solicitudes - <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {background:#f7f9fc;}
    .topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
    .brand-title {color:#0065a4; font-weight:600;}
    .icon-btn i {cursor:pointer; transition:transform .2s;}
    .icon-btn i:hover {transform:scale(1.15);}
    .table thead {position:sticky; top:0; z-index:2;}
    .table th {background:#eaf2fb; font-size:.85rem;}
    .card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
    #tablaSolicitudes tbody tr.selected {
      background-color: #cce5ff !important;
      color: #000;
      font-weight: 600;
    }
    #tablaSolicitudes tbody tr:hover {background:#e8f0fe; cursor:pointer;}
    .table-responsive {width: 100%; overflow-x: auto;}
    .table {width: 100%; min-width: 1200px; table-layout: auto;}
    @media (max-width: 1400px){.table{min-width: 1000px;}}
  </style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="/ceo/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container-fluid px-4">
  <div class="card rounded-4 mb-4">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div class="d-flex gap-3 align-items-center flex-wrap icon-btn">
        <i class="bi bi-plus-circle text-primary fs-4" title="Ingresar Solicitud" id="btnNuevaSolicitud"></i>
        <i class="bi bi-search text-info fs-4" title="Consultar Solicitud" id="btnConsultar"></i>
        <i class="bi bi-arrow-repeat text-warning fs-4" title="Actualizar Work Follow" id="btnActualizarWF"></i>
        <i class="bi bi-x-circle text-danger fs-4" title="Cancelar Solicitud" id="btnCancelar"></i>
        <i class="bi bi-check2-circle text-secondary fs-4" title="Finalizar Solicitud" id="btnFinalizar"></i>
        <i class="bi bi-door-closed text-dark fs-4" title="Cerrar" id="btnCerrar"></i>

        <h4 class="fw-bold text-primary mb-0 ms-2">
          <i class="bi bi-file-earmark-text me-2"></i>Gestión de Solicitudes
        </h4>
      </div>

      <div class="d-flex align-items-center gap-2">
        <a href="mapa_interactivo.php" target="_blank" class="btn btn-outline-success btn-sm" title="Abrir Mapa Interactivo">
          <i class="bi bi-map"></i>
        </a>
        <div class="input-group" style="max-width:280px;">
          <input type="text" id="buscar" class="form-control form-control-sm" placeholder="Buscar solicitud...">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
        </div>
      </div>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger">Error al cargar solicitudes: <?= esc($error) ?></div>
  <?php endif; ?>

  <div class="card rounded-4 shadow-sm">
    <div class="card-body p-3">
      <div class="table-responsive" style="max-height:350px;overflow-y:auto;">
        <table class="table table-hover table-sm align-middle" id="tablaSolicitudes">
          <thead class="text-center align-middle">
            <tr>
              <th>ID</th>
              <th>N° Solicitud</th>
              <th>Solicitante</th>
              <th>Patio</th>
              <th>Fecha</th>
              <th>Hora Inicio</th>
              <th>Hora Término</th>
              <th>Estado</th>
              <th>Contratista</th>
              <th>Proceso</th>
              <th>Habilitación CEO</th>
              <th>Tipo Habilitación</th>
              <th>Cant. Personas</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($solicitudes): foreach ($solicitudes as $row): ?>
            <tr data-id="<?= (int)$row['id'] ?>" data-nsolicitud="<?= (int)$row['nsolicitud'] ?>">
              <td><?= esc($row['id']) ?></td>
              <td><?= esc($row['nsolicitud']) ?></td>
              <td><?= esc($row['solicitante']) ?></td>
              <td><?= esc($row['patio']) ?></td>
              <td><?= esc($row['fecha']) ?></td>
              <td><?= esc($row['horainicio']) ?></td>
              <td><?= esc($row['horatermino']) ?></td>
              <td><?= esc($row['estado']) ?></td>
              <td><?= esc($row['contratista']) ?></td>
              <td><?= esc($row['proceso']) ?></td>
              <td><?= esc($row['habilitacionceo']) ?></td>
              <td><?= esc($row['tipohabilitacion']) ?></td>
              <td class="text-center"><?= esc($row['cantidad_personas']) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="13" class="text-center text-muted">No hay solicitudes registradas.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  let selectedId = null;
  let selectedNsol = null;
  const tbody = document.querySelector('#tablaSolicitudes tbody');

  // === Seleccionar fila ===
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    tbody.querySelector('.selected')?.classList.remove('selected');
    tr.classList.add('selected');
    selectedId = parseInt(tr.dataset.id, 10);
    selectedNsol = parseInt(tr.dataset.nsolicitud, 10);
  });

  // === Doble clic abre detalle ===
  tbody.addEventListener('dblclick', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    if (!tr.classList.contains('selected')) tr.click();
    abrirDetalle(selectedNsol);
  });

  // === Buscador ===
  document.getElementById('buscar').addEventListener('keyup', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaSolicitudes tbody tr').forEach(tr=>{
      tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  // === Acciones con íconos ===
  document.getElementById('btnNuevaSolicitud').onclick = () => {
    window.location.href = 'nueva_solicitud.php';
  };
  document.getElementById('btnCerrar').onclick = () => {
    window.location.href = 'general.php';
  };
  document.getElementById('btnConsultar').onclick = () => {
    if (!ensureSel()) return;
    abrirDetalle(selectedNsol);
  };
document.getElementById('btnActualizarWF').onclick = () => {
  // Abre directamente la página de actualización de Workflow
  window.location.href = 'actualiza_wf.php';
};

  document.getElementById('btnCancelar').onclick = () => {
    if (!ensureSel()) return;
    abrirCancelar(selectedNsol);
  };
  document.getElementById('btnFinalizar').onclick = () => {
    if (!ensureSel()) return;
        abrirFinaliza(selectedNsol);
  };

  // === Helpers ===
  function ensureSel(){
    if (!selectedNsol){
      alert('⚠️ Selecciona una solicitud primero.');
      return false;
    }
    return true;
  }

  function abrirDetalle(nsol){
    if (!nsol) return;
    window.location.href = 'solicitud_detalle.php?id=' + encodeURIComponent(nsol);
  }
  function abrirFinaliza(nsol){
    if (!nsol) return;
    window.location.href = 'finalizar_solicitud.php?id=' + encodeURIComponent(nsol);
  }
    function abrirCancelar(nsol){
    if (!nsol) return;
    window.location.href = 'cancelar_solicitud.php?id=' + encodeURIComponent(nsol);
  }
})();
</script>
</body>
</html>


