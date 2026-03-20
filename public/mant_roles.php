<?php
// /public/mant_roles.php
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
   CRUD - CREATE / UPDATE / CAMBIO DE ESTADO
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $rol = trim($_POST['rol'] ?? '');
  $estado = $_POST['estado'] ?? 'A';

  // CREAR
  if ($accion === 'crear' && $rol) {
    $stmt = $pdo->prepare("INSERT INTO ceo_rol (rol, estado) VALUES (:rol, :estado)");
    $stmt->execute(['rol' => $rol, 'estado' => $estado]);
    $msg = "✅ Rol creado correctamente.";

  // EDITAR
  } elseif ($accion === 'editar' && $id > 0 && $rol) {
    $stmt = $pdo->prepare("UPDATE ceo_rol SET rol = :rol, estado = :estado WHERE id = :id");
    $stmt->execute(['rol' => $rol, 'estado' => $estado, 'id' => $id]);
    $msg = "📝 Rol actualizado.";

  // CAMBIO DE ESTADO (Desactivar o Reactivar)
  } elseif ($accion === 'toggle' && $id > 0) {
    $nuevoEstado = ($_POST['nuevo_estado'] === 'A') ? 'A' : 'I';
    $stmt = $pdo->prepare("UPDATE ceo_rol SET estado = :estado WHERE id = :id");
    $stmt->execute(['estado' => $nuevoEstado, 'id' => $id]);
    $msg = ($nuevoEstado === 'A') ? "✅ Rol reactivado." : "⚠️ Rol desactivado.";
  }
}

/* ============================================================
   CARGA DE ROLES
   ============================================================ */
$stmt = $pdo->query("SELECT * FROM ceo_rol ORDER BY id ASC");
$roles = $stmt->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenimiento de Roles</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { background-color: #f9fbff; color: #0f172a; font-family: "Segoe UI", Roboto, sans-serif; }
    .topbar { background: #fff; border-bottom: 1px solid rgba(13,110,253,0.12); box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
    .topbar .brand-title { font-weight: 700; color: #0d6efd; }
    .card { border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    table th, table td { vertical-align: middle; }
    footer { text-align:center; font-size:0.9rem; color:#6b7280; padding:1rem; margin-top:2rem; }
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
  <div class="alert alert-info text-center"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- ====================================================== -->
  <!-- Formulario de creación / edición -->
  <!-- ====================================================== -->
  <div class="card p-4 mb-4">
    <h4 class="mb-3">Agregar / Editar Rol</h4>
    <form method="post" id="frmRol" class="row g-3">
      <input type="hidden" name="id" id="id">
      <div class="col-md-6">
        <label class="form-label">Nombre Rol</label>
        <input type="text" class="form-control" name="rol" id="rol" required placeholder="Ej: Administrador, Evaluador">
      </div>
      <div class="col-md-3">
        <label class="form-label">Estado</label>
        <select name="estado" id="estado" class="form-select">
          <option value="A">Activo</option>
          <option value="I">Inactivo</option>
        </select>
      </div>
      <div class="col-md-12 text-end mt-3">
        <button type="submit" name="accion" value="crear" id="btnGuardar" class="btn btn-primary">Guardar</button>
        <button type="submit" name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">Actualizar</button>
        <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
      </div>
    </form>
  </div>

  <!-- ====================================================== -->
  <!-- Tabla de roles -->
  <!-- ====================================================== -->
  <div class="card p-4">
    <h4 class="mb-3">Roles Registrados</h4>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= htmlspecialchars($r['rol']) ?></td>
            <td>
              <?php if ($r['estado'] === 'A'): ?>
                <span class="badge bg-success">Activo</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactivo</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm btn-info btnEditar"
                      data-id="<?= $r['id'] ?>"
                      data-rol="<?= htmlspecialchars($r['rol']) ?>"
                      data-estado="<?= $r['estado'] ?>">
                Editar
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="hidden" name="nuevo_estado" value="<?= $r['estado'] === 'A' ? 'I' : 'A' ?>">
                <button name="accion" value="toggle"
                        class="btn btn-sm <?= $r['estado'] === 'A' ? 'btn-danger' : 'btn-success' ?>"
                        onclick="return confirm('¿Desea <?= $r['estado'] === 'A' ? 'desactivar' : 'reactivar' ?> este rol?')">
                  <?= $r['estado'] === 'A' ? 'Desactivar' : 'Reactivar' ?>
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
   Edición en formulario
   ============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;
    document.getElementById('id').value = d.id;
    document.getElementById('rol').value = d.rol;
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
  document.getElementById('frmRol').reset();
  document.getElementById('btnGuardar').classList.remove('d-none');
  document.getElementById('btnActualizar').classList.add('d-none');
  document.getElementById('btnCancelar').classList.add('d-none');
});
</script>

</body>
</html>
