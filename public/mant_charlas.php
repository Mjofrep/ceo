<?php
// /public/mant_charlas.php
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
   CRUD ceo_charlas
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion       = $_POST['accion'] ?? '';
  $id           = (int)($_POST['id'] ?? 0);
  $desc_charlas = trim($_POST['desc_charlas'] ?? '');

  if ($accion === 'crear' && $desc_charlas !== '') {
    $stmt = $pdo->prepare("INSERT INTO ceo_charlas (desc_charlas) VALUES (:d)");
    $stmt->execute(['d' => $desc_charlas]);
    $msg = "✅ Charla creada correctamente.";

  } elseif ($accion === 'editar' && $id > 0) {
    $stmt = $pdo->prepare("UPDATE ceo_charlas SET desc_charlas=:d WHERE id=:id");
    $stmt->execute(['d' => $desc_charlas, 'id' => $id]);
    $msg = "📝 Charla actualizada.";

  } elseif ($accion === 'eliminar' && $id > 0) {
    $pdo->prepare("DELETE FROM ceo_charlas WHERE id=?")->execute([$id]);
    $msg = "🗑️ Charla eliminada.";
  }
}

/* ============================================================
   Cargar registros
   ============================================================ */
$lista = $pdo->query("SELECT id, desc_charlas FROM ceo_charlas ORDER BY id ASC")
             ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenedor de Charlas</title>
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
    <h4 class="mb-3">Agregar / Editar Charla</h4>

    <form method="post" id="frmCharla" class="row g-3">
      <input type="hidden" name="id" id="id">

      <div class="col-md-8">
        <label class="form-label">Descripción de la Charla</label>
        <input type="text" class="form-control" name="desc_charlas" id="desc_charlas" required>
      </div>

      <div class="col-md-12 text-end mt-3">
        <button type="submit" name="accion" value="crear" id="btnGuardar" class="btn btn-primary">
          Guardar
        </button>
        <button type="submit" name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">
          Actualizar
        </button>
        <button type="button" class="btn btn-secondary d-none" id="btnCancelar">
          Cancelar
        </button>
      </div>
    </form>
  </div>

  <!-- ============================================================
       TABLA
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
    <h4 class="mb-3">Charlas Registradas</h4>

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
          <?php foreach ($lista as $r): ?>
            <tr>
              <td><?= $r['id']; ?></td>
              <td><?= esc($r['desc_charlas']); ?></td>
              <td>
                <button class="btn btn-sm btn-info btnEditar"
                        data-id="<?= $r['id']; ?>"
                        data-text="<?= esc($r['desc_charlas']); ?>">
                  Editar
                </button>

                <form method="post" class="d-inline">
                  <input type="hidden" name="id" value="<?= $r['id']; ?>">
                  <button name="accion" value="eliminar"
                    class="btn btn-sm btn-danger"
                    onclick="return confirm('¿Eliminar charla?')">
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
/* =============== Modo edición =============== */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;

    document.getElementById('id').value = d.id;
    document.getElementById('desc_charlas').value = d.text;

    document.getElementById('btnGuardar').classList.add('d-none');
    document.getElementById('btnActualizar').classList.remove('d-none');
    document.getElementById('btnCancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});

/* =============== Cancelar edición =============== */
document.getElementById('btnCancelar').addEventListener('click', () => {
  document.getElementById('frmCharla').reset();
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
