<?php
// /public/mant_bloqueo_horas.php
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
   CRUD ceo_bloqueo_horas
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion       = $_POST['accion'] ?? '';
  $id           = (int)($_POST['id'] ?? 0);
  $motivo       = trim($_POST['motivo'] ?? '');
  $fecha_inicio = $_POST['fecha_inicio'] ?? '';
  $fecha_fin    = $_POST['fecha_fin'] ?? '';
  $estado       = $_POST['estado'] ?? 'A';

  if ($accion === 'crear' && $motivo && $fecha_inicio && $fecha_fin) {

    $stmt = $pdo->prepare(
      "INSERT INTO ceo_bloqueo_horas (motivo, fecha_inicio, fecha_fin, estado)
       VALUES (:motivo, :fi, :ff, :estado)"
    );
    $stmt->execute([
      'motivo' => $motivo,
      'fi' => $fecha_inicio,
      'ff' => $fecha_fin,
      'estado' => $estado
    ]);

    $msg = "✅ Bloqueo creado correctamente.";

  } elseif ($accion === 'editar' && $id > 0) {

    $stmt = $pdo->prepare(
      "UPDATE ceo_bloqueo_horas
       SET motivo=:motivo, fecha_inicio=:fi, fecha_fin=:ff, estado=:estado
       WHERE id=:id"
    );
    $stmt->execute([
      'motivo' => $motivo,
      'fi' => $fecha_inicio,
      'ff' => $fecha_fin,
      'estado' => $estado,
      'id' => $id
    ]);

    $msg = "📝 Bloqueo actualizado.";

  } elseif ($accion === 'eliminar' && $id > 0) {

    $pdo->prepare("DELETE FROM ceo_bloqueo_horas WHERE id=?")->execute([$id]);
    $msg = "🗑️ Bloqueo eliminado.";
  }
}

/* ============================================================
   Cargar registros
   ============================================================ */
$lista = $pdo->query(
  "SELECT id, motivo, fecha_inicio, fecha_fin, estado
   FROM ceo_bloqueo_horas
   ORDER BY fecha_inicio ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Bloqueos de Agenda</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    body { background:#f9fbff; color:#0f172a; font-family:"Segoe UI", Roboto, sans-serif; }
    .topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12);
              box-shadow:0 1px 4px rgba(0,0,0,0.04); }
    .brand-title { font-weight:700; color:#0d6efd; }
    .card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    table th, table td { vertical-align:middle; }
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
    <div class="alert alert-info text-center"><?= esc($msg) ?></div>
  <?php endif; ?>

  <!-- ============================================================
       FORMULARIO AGREGAR / EDITAR
       ============================================================ -->
  <div class="card p-4 mb-4">
    <h4 class="mb-3">Agregar / Editar Bloqueo de Agenda</h4>

    <form method="post" id="frmBloqueo" class="row g-3">
      <input type="hidden" name="id" id="id">

      <div class="col-md-6">
        <label class="form-label">Motivo</label>
        <input type="text" class="form-control" name="motivo" id="motivo" required>
      </div>

      <div class="col-md-3">
        <label class="form-label">Fecha Inicio</label>
        <input type="datetime-local" class="form-control" name="fecha_inicio" id="fecha_inicio" required>
      </div>

      <div class="col-md-3">
        <label class="form-label">Fecha Fin</label>
        <input type="datetime-local" class="form-control" name="fecha_fin" id="fecha_fin" required>
      </div>

      <div class="col-md-3">
        <label class="form-label">Estado</label>
        <select class="form-select" name="estado" id="estado">
          <option value="A">Activo</option>
          <option value="I">Inactivo</option>
        </select>
      </div>

      <div class="col-md-12 text-end mt-3">
        <button type="submit" name="accion" value="crear" id="btnGuardar" class="btn btn-primary">Guardar</button>
        <button type="submit" name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">Actualizar</button>
        <button type="button" id="btnCancelar" class="btn btn-secondary d-none">Cancelar</button>
      </div>
    </form>
  </div>

  <!-- ============================================================
       TABLA DE REGISTROS
       ============================================================ -->
<div class="row mb-2">
  <div class="col-md-4 ms-auto">
    <input type="text"
           id="buscarCalendario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar en calendario...">
  </div>
</div>
       
  <div class="card p-4">
    <h4 class="mb-3">Bloqueos Registrados</h4>

    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Motivo</th>
            <th>Inicio</th>
            <th>Fin</th>
            <th>Estado</th>
            <th style="width:160px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lista as $r): ?>
            <tr>
              <td><?= $r['id']; ?></td>
              <td><?= esc($r['motivo']); ?></td>
              <td><?= esc($r['fecha_inicio']); ?></td>
              <td><?= esc($r['fecha_fin']); ?></td>
              <td><?= $r['estado'] === 'A' ? 'Activo' : 'Inactivo'; ?></td>
              <td>

                <button class="btn btn-sm btn-info btnEditar"
                        data-id="<?= $r['id']; ?>"
                        data-motivo="<?= esc($r['motivo']); ?>"
                        data-fi="<?= $r['fecha_inicio']; ?>"
                        data-ff="<?= $r['fecha_fin']; ?>"
                        data-estado="<?= $r['estado']; ?>">
                  Editar
                </button>

                <form method="post" class="d-inline">
                  <input type="hidden" name="id" value="<?= $r['id']; ?>">
                  <button name="accion" value="eliminar"
                    class="btn btn-sm btn-danger"
                    onclick="return confirm('¿Eliminar bloqueo?')">
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
   Modo edición
   ============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;

    document.getElementById('id').value = d.id;
    document.getElementById('motivo').value = d.motivo;
    document.getElementById('fecha_inicio').value = d.fi.replace(' ', 'T');
    document.getElementById('fecha_fin').value = d.ff.replace(' ', 'T');
    document.getElementById('estado').value = d.estado;

    document.getElementById('btnGuardar').classList.add('d-none');
    document.getElementById('btnActualizar').classList.remove('d-none');
    document.getElementById('btnCancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior:'smooth' });
  });
});

/* ============================================================
   Cancelar edición
   ============================================================ */
document.getElementById('btnCancelar').addEventListener('click', () => {
  document.getElementById('frmBloqueo').reset();
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
