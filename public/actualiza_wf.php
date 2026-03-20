<?php
ini_set('display_errors','1');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo.noetica.cl/public/index.php');
  exit;
}

$pdo = db();
$registros = [];
$msg = '';

try {
  $st = $pdo->query("SELECT id,tipo, mandante, contratista, rut_empleado, nombres, apellidos, wf, servicio, cargo 
                     FROM ceo_reportewf ORDER BY contratista DESC");
  $registros = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $msg = '❌ Error al leer datos actuales: '.$e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Actualización Workflow (AJAX) - <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f7f9fc;font-size:0.9rem;}
.topbar{background:#fff;border-bottom:1px solid #e3e6ea;}
.brand-title{color:#0065a4;font-weight:600;font-size:1.1rem;}
.card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05);}
.table th{background:#eaf2fb;}
.table td,.table th{font-size:0.85rem;}
.alert{font-size:0.9rem;}
.progress{height:0.6rem;}
th.sticky-col {position: sticky; left: 0; background: #eaf2fb;}
td.sticky-col {position: sticky; left: 0; background: #fff;}
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
    <a href="solicitudes.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <h5 class="text-primary mb-3">
    <i class="bi bi-arrow-repeat me-2"></i>Actualización Workflow (WF)
  </h5>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <div class="row g-3 align-items-center">
        <div class="col-md-5">
          <label class="form-label">Seleccionar archivo Excel</label>
          <input type="file" id="excelFile" accept=".xlsx,.xls" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
          <button id="btnUpload" class="btn btn-success btn-sm mt-4 px-4">
            <i class="bi bi-upload me-2"></i>Actualizar desde Excel
          </button>
        </div>
      </div>
      <div id="msgArea" class="mt-3"></div>
      <div class="progress mt-2" style="display:none;">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%"></div>
      </div>
    </div>
  </div>

  <div class="card rounded-4 shadow-sm">
    <div class="card-body">
      <h6 class="text-secondary mb-3"><i class="bi bi-table me-2"></i>Registros Actuales en ceo_reportewf</h6>
      <input type="text" id="buscar" class="form-control form-control-sm mb-2" placeholder="Buscar...">
      <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
        <table class="table table-sm table-hover align-middle" id="tablaWF">
          <thead>
            <tr>
              <th class="sticky-col text-center">#</th>
              <th>ID</th>
              <th>Tipo</th>
              <th>Mandante</th>
              <th>Contratista</th>
              <th>Rut</th>
              <th>Nombres</th>
              <th>Apellidos</th>
              <th>WF</th>
              <th>Servicio</th>
              <th>Cargo</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($registros): 
              $i = 1;
              foreach($registros as $r): ?>
              <tr>
                <td class="sticky-col text-center"><?= $i++ ?></td>
                <td><?= htmlspecialchars($r['id']) ?></td>
                <td><?= htmlspecialchars($r['tipo']) ?></td>
                <td><?= htmlspecialchars($r['mandante']) ?></td>
                <td><?= htmlspecialchars($r['contratista']) ?></td>
                <td><?= htmlspecialchars($r['rut_empleado']) ?></td>
                <td><?= htmlspecialchars($r['nombres']) ?></td>
                <td><?= htmlspecialchars($r['apellidos']) ?></td>
                <td><?= htmlspecialchars($r['wf']) ?></td>
                <td><?= htmlspecialchars($r['servicio']) ?></td>
                <td><?= htmlspecialchars($r['cargo']) ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="10" class="text-center text-muted">Sin registros cargados</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
// === Buscar ===
document.getElementById('buscar').addEventListener('keyup', function(){
  const q = this.value.toLowerCase();
  document.querySelectorAll('#tablaWF tbody tr').forEach(tr=>{
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});

// === Subir Excel ===
document.getElementById('btnUpload').addEventListener('click', async ()=>{
  const file = document.getElementById('excelFile').files[0];
  const msgArea = document.getElementById('msgArea');
  const progress = document.querySelector('.progress');
  const bar = document.querySelector('.progress-bar');

  if(!file){ alert('Selecciona un archivo Excel primero.'); return; }

  if(!confirm('⚠️ Se eliminarán los registros actuales y se cargará nueva información. ¿Continuar?')) return;

  const fd = new FormData();
  fd.append('excel', file);

  msgArea.innerHTML = '<div class="alert alert-info">Procesando archivo... Espere.</div>';
  progress.style.display = 'block';
  bar.style.width = '30%';

  try {
    const resp = await fetch('actualiza_wf_process.php', {method:'POST', body:fd});
    bar.style.width = '80%';
    const data = await resp.json();
    if(data.ok){
      msgArea.innerHTML = `<div class="alert alert-success">✅ ${data.msg}</div>`;
      bar.style.width = '100%';
      setTimeout(()=>location.reload(), 2000);
    } else {
      msgArea.innerHTML = `<div class="alert alert-danger">⚠️ ${data.error}</div>`;
      bar.style.width = '0%';
    }
  } catch (err) {
    msgArea.innerHTML = '<div class="alert alert-danger">❌ Error de conexión.</div>';
    bar.style.width = '0%';
  }
});
</script>
</body>
</html>

