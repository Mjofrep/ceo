<?php
// /public/mant_procesos.php
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
   CRUD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $desc_proceso = trim($_POST['desc_proceso'] ?? '');

  if ($accion === 'crear' && $desc_proceso) {
    $stmt = $pdo->prepare("INSERT INTO ceo_procesos (desc_proceso) VALUES (:desc_proceso)");
    $stmt->execute(compact('desc_proceso'));
    $msg = "✅ Proceso creado correctamente.";

  } elseif ($accion === 'editar' && $id > 0 && $desc_proceso) {
    $stmt = $pdo->prepare("UPDATE ceo_procesos SET desc_proceso=:desc_proceso WHERE id=:id");
    $stmt->execute(compact('desc_proceso','id'));
    $msg = "📝 Proceso actualizado.";

  } elseif ($accion === 'eliminar' && $id > 0) {
    $pdo->prepare("DELETE FROM ceo_procesos WHERE id=?")->execute([$id]);
    $msg = "🗑️ Proceso eliminado.";
  }
}

/* ============================================================
   CARGA DE PROCESOS
   ============================================================ */
$procesos = $pdo->query("SELECT * FROM ceo_procesos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenimiento de Procesos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { background-color:#f9fbff; color:#0f172a; font-family:"Segoe UI",Roboto,sans-serif; }
    .topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.04); }
    .topbar .brand-title { font-weight:700; color:#0d6efd; }
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


  <div class="card p-4 mb-4">
    <h4 class="mb-3">Agregar / Editar Proceso</h4>
    <form method="post" id="frmProceso" class="row g-3">
      <input type="hidden" name="id" id="id">
      <div class="col-md-8">
        <label class="form-label">Descripción del Proceso</label>
        <input type="text" class="form-control" name="desc_proceso" id="desc_proceso" required placeholder="Ej: Habilitación, Evaluación, etc.">
      </div>
      <div class="col-md-4 text-end align-self-end">
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
    <h4 class="mb-3">Procesos Registrados</h4>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Descripción</th>
            <th style="width:160px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($procesos as $p): ?>
          <tr>
            <td><?= $p['id']; ?></td>
            <td><?= $p['desc_proceso']; ?></td>
            <td>
              <button class="btn btn-sm btn-info btnEditar"
                      data-id="<?= $p['id']; ?>"
                      data-desc="<?= $p['desc_proceso']; ?>">
                Editar
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= $p['id']; ?>">
                <button name="accion" value="eliminar" class="btn btn-sm btn-danger"
                        onclick="return confirm('¿Eliminar este proceso?')">
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
    document.getElementById('desc_proceso').value = d.desc;
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
  document.getElementById('frmProceso').reset();
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
