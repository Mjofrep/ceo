<?php
// /public/mant_porcentaje_agrupacion.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

/* ============================================================
   VALIDACIÓN DE SESIÓN
   ============================================================ */
if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$msg = '';

/* ============================================================
   CRUD - CREATE / UPDATE / DELETE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion         = $_POST['accion'] ?? '';
    $id             = (int)($_POST['id'] ?? 0);
    $id_agrupacion  = (int)($_POST['id_agrupacion'] ?? 0);
    $porcentaje     = (int)($_POST['porcentaje'] ?? 0);
    $fechadesde     = $_POST['fechadesde'] ?? '';
    $activo         = $_POST['activo'] ?? 'S';

    /* ===================== CREAR ===================== */
    if ($accion === 'crear' && $id_agrupacion > 0 && $fechadesde) {

        $stmt = $pdo->prepare("
            INSERT INTO ceo_porcentaje_agrupacion
                (id_agrupacion, porcentaje, fechadesde, activo)
            VALUES
                (:agrupacion, :porcentaje, :fecha, :activo)
        ");
        $stmt->execute([
            ':agrupacion' => $id_agrupacion,
            ':porcentaje' => $porcentaje,
            ':fecha'      => $fechadesde,
            ':activo'     => $activo
        ]);

        $msg = "✅ Porcentaje creado correctamente.";

    /* ===================== EDITAR ===================== */
    } elseif ($accion === 'editar' && $id > 0) {

        $stmt = $pdo->prepare("
            UPDATE ceo_porcentaje_agrupacion
               SET id_agrupacion = :agrupacion,
                   porcentaje    = :porcentaje,
                   fechadesde    = :fecha,
                   activo        = :activo
             WHERE id = :id
        ");
        $stmt->execute([
            ':agrupacion' => $id_agrupacion,
            ':porcentaje' => $porcentaje,
            ':fecha'      => $fechadesde,
            ':activo'     => $activo,
            ':id'         => $id
        ]);

        $msg = "📝 Porcentaje actualizado.";

    /* ===================== ELIMINAR ===================== */
    } elseif ($accion === 'eliminar' && $id > 0) {

        $stmt = $pdo->prepare("DELETE FROM ceo_porcentaje_agrupacion WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $msg = "🗑️ Porcentaje eliminado.";
    }
}

/* ============================================================
   CARGA DE DATOS
   ============================================================ */

// Agrupaciones (para combo)
$agrupaciones = $pdo->query("
    SELECT id, titulo
    FROM ceo_agrupacion
    ORDER BY titulo
")->fetchAll(PDO::FETCH_ASSOC);

// Listado porcentajes
$porcentajes = $pdo->query("
    SELECT p.*, a.titulo
    FROM ceo_porcentaje_agrupacion p
    INNER JOIN ceo_agrupacion a ON a.id = p.id_agrupacion
    ORDER BY a.titulo, p.fechadesde DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Mantenimiento Porcentaje Agrupación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
body { background:#f9fbff; font-family:"Segoe UI", Roboto, sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,.12); box-shadow:0 1px 4px rgba(0,0,0,.04); }
.brand-title { font-weight:700; color:#0d6efd; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,.05); }
table th, table td { vertical-align:middle; }
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" style="height:60px;">
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
<div class="alert alert-info text-center"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ================= FORMULARIO ================= -->
<div class="card p-4 mb-4">
<h4 class="mb-3">Agregar / Editar Porcentaje</h4>

<form method="post" id="frmPorcentaje" class="row g-3">
<input type="hidden" name="id" id="id">

<div class="col-md-6">
  <label class="form-label">Agrupación</label>
  <select name="id_agrupacion" id="id_agrupacion" class="form-select" required>
    <option value="">— Seleccionar —</option>
    <?php foreach ($agrupaciones as $a): ?>
      <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['titulo']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="col-md-3">
  <label class="form-label">Porcentaje</label>
  <input type="number" name="porcentaje" id="porcentaje" class="form-control" min="0" max="100" required>
</div>

<div class="col-md-3">
  <label class="form-label">Fecha Desde</label>
  <input type="date" name="fechadesde" id="fechadesde" class="form-control" required>
</div>

<div class="col-md-3">
  <label class="form-label">Activo</label>
  <select name="activo" id="activo" class="form-select">
    <option value="S">Sí</option>
    <option value="N">No</option>
  </select>
</div>

<div class="col-md-12 text-end mt-3">
  <button name="accion" value="crear" id="btnGuardar" class="btn btn-primary">Guardar</button>
  <button name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">Actualizar</button>
  <button type="button" id="btnCancelar" class="btn btn-secondary d-none">Cancelar</button>
</div>
</form>
</div>

<!-- ================= TABLA ================= -->
<div class="row mb-2">
  <div class="col-md-4 ms-auto">
    <input type="text" id="buscarTabla" class="form-control form-control-sm" placeholder="🔍 Buscar...">
  </div>
</div>

<div class="card p-4">
<h4 class="mb-3">Porcentajes Registrados</h4>

<div class="table-responsive">
<table class="table table-striped">
<thead class="table-primary">
<tr>
  <th>ID</th>
  <th>Agrupación</th>
  <th>Porcentaje</th>
  <th>Fecha Desde</th>
  <th>Activo</th>
  <th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($porcentajes as $p): ?>
<tr>
  <td><?= $p['id'] ?></td>
  <td><?= htmlspecialchars($p['titulo']) ?></td>
  <td><?= $p['porcentaje'] ?>%</td>
  <td><?= $p['fechadesde'] ?></td>
  <td><?= $p['activo'] ?></td>
  <td>
    <button class="btn btn-sm btn-info btnEditar"
      data-id="<?= $p['id'] ?>"
      data-agrupacion="<?= $p['id_agrupacion'] ?>"
      data-porcentaje="<?= $p['porcentaje'] ?>"
      data-fecha="<?= $p['fechadesde'] ?>"
      data-activo="<?= $p['activo'] ?>">
      Editar
    </button>

    <form method="post" class="d-inline">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">
      <button name="accion" value="eliminar"
        class="btn btn-sm btn-danger"
        onclick="return confirm('¿Eliminar este registro?')">
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

<script>
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.onclick = e => {
    const d = e.target.dataset;
    id.value = d.id;
    id_agrupacion.value = d.agrupacion;
    porcentaje.value = d.porcentaje;
    fechadesde.value = d.fecha;
    activo.value = d.activo;

    btnGuardar.classList.add('d-none');
    btnActualizar.classList.remove('d-none');
    btnCancelar.classList.remove('d-none');
    window.scrollTo({top:0,behavior:'smooth'});
  }
});

btnCancelar.onclick = () => {
  frmPorcentaje.reset();
  btnGuardar.classList.remove('d-none');
  btnActualizar.classList.add('d-none');
  btnCancelar.classList.add('d-none');
};
</script>

<script>
document.getElementById('buscarTabla').addEventListener('keyup', function () {
  const txt = this.value.toLowerCase();
  document.querySelectorAll('tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(txt) ? '' : 'none';
  });
});
</script>

</body>
</html>
