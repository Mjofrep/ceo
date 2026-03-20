<?php
// /public/mant_agrupacion.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

// Validación de sesión
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

  $accion      = $_POST['accion'] ?? '';
  $id          = (int)($_POST['id'] ?? 0);
  $titulo      = trim($_POST['titulo'] ?? '');
  $id_servicio = (int)($_POST['id_servicio'] ?? 0);
  $tiempo      = $_POST['tiempo'] ?? null;
  $cantidad    = (int)($_POST['cantidad'] ?? 0);
  $total       = (int)($_POST['total'] ?? 0);   // ✅ NUEVO

  // CREAR
  if ($accion === 'crear' && $titulo && $id_servicio > 0) {
    $stmt = $pdo->prepare("
    INSERT INTO ceo_agrupacion (titulo, id_servicio, tiempo, cantidad, total)
    VALUES (:titulo, :servicio, :tiempo, :cantidad, :total)
    ");
    $stmt->execute([
      ':titulo'   => $titulo,
      ':servicio' => $id_servicio,
      ':tiempo'   => $tiempo,
      ':cantidad' => $cantidad,
      ':total'    => $total
    ]);

    $msg = "✅ Agrupación creada correctamente.";

  // EDITAR
  } elseif ($accion === 'editar' && $id > 0 && $titulo) {
    $stmt = $pdo->prepare("
      UPDATE ceo_agrupacion
        SET titulo = :titulo,
            id_servicio = :servicio,
            tiempo = :tiempo,
            cantidad = :cantidad,
            total = :total
       WHERE id = :id
    ");
    $stmt->execute([
      ':titulo'   => $titulo,
      ':servicio' => $id_servicio,
      ':tiempo'   => $tiempo,
      ':cantidad' => $cantidad,
      ':total'    => $total,
      ':id'       => $id
    ]);

    $msg = "📝 Agrupación actualizada.";

  // ELIMINAR
  } elseif ($accion === 'eliminar' && $id > 0) {
    $stmt = $pdo->prepare("DELETE FROM ceo_agrupacion WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $msg = "🗑️ Agrupación eliminada.";
  }
}

/* ============================================================
   CARGA DE DATOS
   ============================================================ */
$agrupaciones = $pdo->query("
  SELECT a.*, s.servicio
  FROM ceo_agrupacion a
  LEFT JOIN ceo_servicios_pruebas s ON s.id = a.id_servicio
  ORDER BY a.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$servicios = $pdo->query("
  SELECT DISTINCT id, servicio
  FROM ceo_servicios_pruebas
  ORDER BY servicio
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Mantenimiento Agrupación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
body { background:#f9fbff; font-family:"Segoe UI", Roboto, sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.brand-title { font-weight:700; color:#0d6efd; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
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
<h4 class="mb-3">Agregar / Editar Agrupación</h4>

<form method="post" id="frmAgrupacion" class="row g-3">
<input type="hidden" name="id" id="id">

<div class="col-md-6">
  <label class="form-label">Título</label>
  <input type="text" name="titulo" id="titulo" class="form-control" required>
</div>

<div class="col-md-6">
  <label class="form-label">Servicio</label>
  <select name="id_servicio" id="id_servicio" class="form-select" required>
    <option value="">— Seleccionar —</option>
    <?php foreach ($servicios as $s): ?>
      <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['servicio']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="col-md-3">
  <label class="form-label">Tiempo</label>
  <input type="time" name="tiempo" id="tiempo" class="form-control">
</div>

<div class="col-md-3">
  <label class="form-label">Cantidad Preguntas</label>
  <input type="number" name="cantidad" id="cantidad" class="form-control" min="0">
</div>

<div class="col-md-3">
  <label class="form-label">Total Preguntas</label>
  <input type="number" name="total" id="total" class="form-control" min="0">
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
    <input type="text"
           id="buscarCalendario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar en calendario...">
  </div>
</div>

<div class="card p-4">
<h4 class="mb-3">Agrupaciones Registradas</h4>

<div class="table-responsive">
<table class="table table-striped">
<thead class="table-primary">
<tr>
  <th>ID</th>
  <th>Título</th>
  <th>Servicio</th>
  <th>Tiempo</th>
  <th>Cantidad Preguntas</th>
  <th>Total Preguntas</th>
  <th>Acciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($agrupaciones as $a): ?>
<tr>
  <td><?= $a['id'] ?></td>
  <td><?= htmlspecialchars($a['titulo']) ?></td>
  <td><?= htmlspecialchars($a['servicio']) ?></td>
  <td><?= $a['tiempo'] ?></td>
  <td><?= $a['cantidad'] ?></td>
  <td><?= (int)$a['total'] ?></td>
  <td>
    <button class="btn btn-sm btn-info btnEditar"
      data-id="<?= $a['id'] ?>"
      data-titulo="<?= htmlspecialchars($a['titulo']) ?>"
      data-servicio="<?= $a['id_servicio'] ?>"
      data-tiempo="<?= $a['tiempo'] ?>"
      data-cantidad="<?= $a['cantidad'] ?>"
      data-total="<?= $a['total'] ?>">   <!-- ✅ -->
      Editar
    </button>


    <form method="post" class="d-inline">
      <input type="hidden" name="id" value="<?= $a['id'] ?>">
      <button name="accion" value="eliminar"
        class="btn btn-sm btn-danger"
        onclick="return confirm('¿Eliminar esta agrupación?')">
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
    titulo.value = d.titulo;
    id_servicio.value = d.servicio;
    tiempo.value = d.tiempo;
    cantidad.value = d.cantidad;
    total.value = d.total; 

    btnGuardar.classList.add('d-none');
    btnActualizar.classList.remove('d-none');
    btnCancelar.classList.remove('d-none');
    window.scrollTo({top:0,behavior:'smooth'});
  }
});

btnCancelar.onclick = () => {
  frmAgrupacion.reset();
  btnGuardar.classList.remove('d-none');
  btnActualizar.classList.add('d-none');
  btnCancelar.classList.add('d-none');
};
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
