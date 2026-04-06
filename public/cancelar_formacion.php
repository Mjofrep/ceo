<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo "<div class='alert alert-danger m-5'>❌ Formacion no especificada.</div>";
  exit;
}

/* ===============================================================
   CABECERA: Datos generales de la solicitud
   =============================================================== */
$stmt = $pdo->prepare("
  SELECT s.*, 
         e.nombre AS empresa_nombre,
         p.desc_proceso AS proceso_nombre,
         pa.desc_patios AS patio_nombre,
         u.desc_uo AS uo_nombre,
         sv.servicio AS servicio_nombre,
         r.responsable AS resp_uo_nombre,
         h.desc_tipo AS habceo_nombre
    FROM ceo_formacion_solicitudes s
    LEFT JOIN ceo_empresas e ON e.id = s.contratista
    LEFT JOIN ceo_procesos p ON p.id = s.proceso
    LEFT JOIN ceo_patios pa ON pa.id = s.patio
    LEFT JOIN ceo_uo u ON u.id = s.uo
    LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
    LEFT JOIN ceo_responsables r ON r.id = s.responsable
    LEFT JOIN ceo_formaciontipo h ON h.id = s.habilitacionceo
   WHERE s.nsolicitud = :nsol
   LIMIT 1
");
$stmt->execute([':nsol' => $id]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sol) {
  echo "<div class='alert alert-warning m-5'>⚠️ No se encontró la solicitud N° {$id}.</div>";
  exit;
}

/* ===============================================================
   PARTICIPANTES
   =============================================================== */
$parts = $pdo->prepare("
  SELECT ps.id_solicitud, ps.rut, ps.nombre, ps.apellidop, ps.apellidom,
         (SELECT c.cargo FROM ceo_cargo_contratistas c WHERE c.id = ps.id_cargo LIMIT 1) AS cargo,
         ps.autorizado, ps.asistio, ps.aprobo, ps.observacion
    FROM ceo_formacion_participantes_solicitud ps
   WHERE ps.id_solicitud = :nsol
   ORDER BY ps.nombre
");
$parts->execute([':nsol' => $sol['nsolicitud']]);
$participantes = $parts->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Cancelar Formacion #<?= htmlspecialchars((string)($sol['nsolicitud'] ?? '-')) ?> - <?= htmlspecialchars((string)APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc; font-size:0.9rem;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600; font-size:1.1rem;}
.card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
.form-label {font-weight:500; color:#333; font-size:0.85rem;}
.form-control[readonly] {background:#f9fafb; font-size:0.85rem;}
h4, h5, h6 {font-weight:500;}
.table th, .table td {font-size:0.85rem;}
.table th {background:#eaf2fb; font-weight:600;}
.table td {color:#444;}
.table-sm>tbody>tr>td, .table-sm>thead>tr>th {padding:0.35rem 0.5rem;}
.alert-info {font-size:0.9rem;}
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="formaciones.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <h5 class="text-danger mb-3">
    <i class="bi bi-x-circle me-2"></i>
    Cancelar Formacion N° <?= htmlspecialchars((string)($sol['nsolicitud'] ?? '')) ?>
  </h5>

  <!-- ========== CABECERA ========== -->
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form class="row g-3">
        <?php
        $campos = [
          ['Fecha', $sol['fecha']],
          ['Hora Inicio', $sol['horainicio']],
          ['Hora Término', $sol['horatermino']],
          ['Empresa', $sol['empresa_nombre']],
          ['Proceso', $sol['proceso_nombre']],
          ['Patio', $sol['patio_nombre']],
          ['Unidad Operativa', $sol['uo_nombre']],
          ['Servicio', $sol['servicio_nombre']],
          ['Habilitación CEO', $sol['habceo_nombre']],
          ['Tipo Habilitación', $sol['tipohabilitacion']],
          ['Responsable UO', $sol['resp_uo_nombre']],
          ['Observación', $sol['observacion']],
        ];
        foreach ($campos as [$label, $valor]): ?>
          <div class="col-md-4">
            <label class="form-label"><?= htmlspecialchars($label) ?></label>
            <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$valor) ?>" readonly>
          </div>
        <?php endforeach; ?>
      </form>
    </div>
  </div>

  <!-- ========== PARTICIPANTES ========== -->
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <h6 class="text-primary mb-3">
        <i class="bi bi-people me-2"></i>Lista de Participantes
      </h6>
      <div class="table-responsive">
        <?php if (empty($participantes)): ?>
          <div class="alert alert-info text-center mb-0">No hay participantes registrados para esta solicitud.</div>
        <?php else: ?>
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>RUT</th>
              <th>Nombre</th>
              <th>1° Apellido</th>
              <th>2° Apellido</th>
              <th>Cargo</th>
              <th class="text-center">Autorizado</th>
              <th class="text-center">Asistió</th>
              <th class="text-center">Aprobado</th>
              <th>Observación Rechazo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($participantes as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['rut']) ?></td>
                <td><?= htmlspecialchars($p['nombre']) ?></td>
                <td><?= htmlspecialchars($p['apellidop']) ?></td>
                <td><?= htmlspecialchars($p['apellidom']) ?></td>
                <td><?= htmlspecialchars($p['cargo']) ?></td>
                <td class="text-center"><?= ($p['autorizado'] ? '✔️' : '—') ?></td>
                <td class="text-center"><?= ($p['asistio'] === '1' ? '✔️' : '—') ?></td>
                <td class="text-center"><?= ($p['aprobo'] ? '✔️' : '—') ?></td>
                <td><?= htmlspecialchars($p['observacion'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <div class="text-end mt-4">
        <button id="btnCancelar" class="btn btn-danger btn-sm px-4">
          <i class="bi bi-save me-2"></i>Cancelar Formacion
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('btnCancelar').addEventListener('click', async ()=>{
  if(!confirm('¿Desea marcar esta solicitud como Cancelada?')) return;
  const fd = new FormData();
  fd.append('id_solicitud', <?= (int)$sol['nsolicitud'] ?>);
  try{
    const resp = await fetch('cancelar_formacion_update.php',{method:'POST',body:fd});
    const data = await resp.json();
    if(data.ok){
      alert('❌ Formacion cancelada correctamente.');
      window.location.href = 'formaciones.php';
    } else {
      alert('⚠️ Error al cancelar: '+(data.error||''));
    }
  }catch(err){
    alert('❌ Error de conexión.');
  }
});
</script>
</body>
</html>
