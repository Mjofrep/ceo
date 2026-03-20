<?php
// /public/mant_servicios.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();
$msg = '';

/* ============================================================
   Función de escape segura
   ============================================================ */
function esc(mixed $v): string {
  if ($v === null) return '';
  $s = (string)$v;
  if (!mb_check_encoding($s, 'UTF-8')) {
    $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
  }
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   Cargar combos (áreas responsables y unidades operativas)
   ============================================================ */
$areas = $pdo->query("SELECT id, responsable FROM ceo_responsables ORDER BY responsable")->fetchAll(PDO::FETCH_ASSOC);
$uos   = $pdo->query("SELECT id, desc_uo FROM ceo_uo ORDER BY desc_uo")->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   CRUD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion      = $_POST['accion'] ?? '';
  $id          = (int)($_POST['id'] ?? 0);
  $servicio    = trim($_POST['servicio'] ?? '');
  $id_arearesp = (int)($_POST['id_arearesp'] ?? 0);
  $estado      = trim($_POST['estado'] ?? '');
  $uo          = (int)($_POST['uo'] ?? 0);

  if ($accion === 'crear' && $servicio && $id_arearesp && $uo) {
    $stmt = $pdo->prepare("INSERT INTO ceo_servicios (servicio, id_arearesp, estado, uo)
                           VALUES (:servicio, :id_arearesp, :estado, :uo)");
    $stmt->execute(compact('servicio','id_arearesp','estado','uo'));
    $msg = "✅ Servicio creado correctamente.";

  } elseif ($accion === 'editar' && $id > 0 && $servicio) {
    $stmt = $pdo->prepare("UPDATE ceo_servicios 
                           SET servicio=:servicio, id_arearesp=:id_arearesp, estado=:estado, uo=:uo 
                           WHERE id=:id");
    $stmt->execute(compact('servicio','id_arearesp','estado','uo','id'));
    $msg = "📝 Servicio actualizado.";

  } elseif ($accion === 'eliminar' && $id > 0) {
    $pdo->prepare("DELETE FROM ceo_servicios WHERE id=?")->execute([$id]);
    $msg = "🗑️ Servicio eliminado.";
  }
}

/* ============================================================
   CARGA DE SERVICIOS
   ============================================================ */
$sql = "SELECT s.id, s.servicio, s.estado, 
               r.responsable AS area_responsable,
               u.desc_uo AS unidad_operativa,
               s.id_arearesp, s.uo
        FROM ceo_servicios s
        LEFT JOIN ceo_responsables r ON s.id_arearesp = r.id
        LEFT JOIN ceo_uo u ON s.uo = u.id
        ORDER BY s.id ASC";
$servicios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenimiento de Servicios</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { background-color:#f9fbff; color:#0f172a; font-family:"Segoe UI",Roboto,sans-serif; }
    .topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.04);}
    .topbar .brand-title { font-weight:700; color:#0d6efd; }
    .card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    table th, table td { vertical-align:middle; }
    footer { text-align:center; font-size:0.9rem; color:#6b7280; padding:1rem; margin-top:2rem;}
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
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container">
  <?php if ($msg): ?>
    <div class="alert alert-info text-center"><?= esc($msg) ?></div>
  <?php endif; ?>

  <div class="card p-4 mb-4">
    <h4 class="mb-3">Agregar / Editar Servicio</h4>
    <form method="post" id="frmServicio" class="row g-3">
      <input type="hidden" name="id" id="id">

      <div class="col-md-4">
        <label class="form-label">Nombre del Servicio</label>
        <input type="text" class="form-control" name="servicio" id="servicio" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Área Responsable</label>
        <select class="form-select" name="id_arearesp" id="id_arearesp" required>
          <option value="">Seleccione...</option>
          <?php foreach ($areas as $a): ?>
            <option value="<?= $a['id']; ?>"><?= $a['responsable']; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Unidad Operativa (UO)</label>
        <select class="form-select" name="uo" id="uo" required>
          <option value="">Seleccione...</option>
          <?php foreach ($uos as $u): ?>
            <option value="<?= $u['id']; ?>"><?= $u['desc_uo']; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Estado</label>
        <select class="form-select" name="estado" id="estado">
          <option value="Activo">Activo</option>
          <option value="Inactivo">Inactivo</option>
        </select>
      </div>

      <div class="col-md-12 text-end mt-3">
        <button type="submit" name="accion" value="crear" id="btnGuardar" class="btn btn-primary">Guardar</button>
        <button type="submit" name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">Actualizar</button>
        <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
      </div>
    </form>
  </div>

<div class="row mb-2">
  <div class="col-md-4 ms-auto">
    <input type="text"
           id="buscarCalendario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar en calendario...">
  </div>
</div>

  <div class="card p-4">
    <h4 class="mb-3">Servicios Registrados</h4>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Servicio</th>
            <th>Área Responsable</th>
            <th>Unidad Operativa</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($servicios as $s): ?>
          <tr>
            <td><?= $s['id']; ?></td>
            <td><?= $s['servicio']; ?></td>
            <td><?= $s['area_responsable']; ?></td>
            <td><?= $s['unidad_operativa']; ?></td>
            <td><?= $s['estado']; ?></td>
            <td>
              <button class="btn btn-sm btn-info btnEditar"
                      data-id="<?= $s['id']; ?>"
                      data-servicio="<?= $s['servicio']; ?>"
                      data-arearesp="<?= $s['id_arearesp']; ?>"
                      data-uo="<?= $s['uo']; ?>"
                      data-estado="<?= $s['estado']; ?>">
                Editar
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= $s['id']; ?>">
                <button name="accion" value="eliminar" class="btn btn-sm btn-danger"
                        onclick="return confirm('¿Eliminar este servicio?')">
                  Eliminar
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<footer><?= APP_FOOTER ?></footer>

<script>
/* ============================================================
   Edición
   ============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;
    document.getElementById('id').value = d.id;
    document.getElementById('servicio').value = d.servicio;
    document.getElementById('id_arearesp').value = d.arearesp;
    document.getElementById('uo').value = d.uo;
    document.getElementById('estado').value = d.estado;
    document.getElementById('btnGuardar').classList.add('d-none');
    document.getElementById('btnActualizar').classList.remove('d-none');
    document.getElementById('btnCancelar').classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});

/* ============================================================
   Cancelar edición
   ============================================================ */
document.getElementById('btnCancelar').addEventListener('click', () => {
  document.getElementById('frmServicio').reset();
  document.getElementById('btnGuardar').classList.remove('d-none');
  document.getElementById('btnActualizar').classList.add('d-none');
  document.getElementById('btnCancelar').classList.add('d-none');
});
</script>
<script>
/* ============================================================
   Buscador general calendario
   ============================================================ */
document.getElementById('buscarCalendario').addEventListener('keyup', function () {
  const texto = this.value.toLowerCase();
  document.querySelectorAll('table tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(texto)
      ? ''
      : 'none';
  });
});
</script>

</body>
</html>
