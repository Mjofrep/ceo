<?php
// /public/mant_servicios_pruebas.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$msg = "";

/* ============================================================
   Escape seguro
============================================================ */
function esc($v): string {
    if ($v === null) return '';
    return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
}

/* ============================================================
   CRUD
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $accion = $_POST['accion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $servicio    = trim($_POST['servicio'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($accion === 'crear' && $servicio) {
        $sql = "INSERT INTO ceo_servicios_pruebas (servicio, descripcion)
                VALUES (:servicio, :descripcion)";
        $pdo->prepare($sql)->execute(compact('servicio','descripcion'));
        $msg = "✔ Servicio ingresado correctamente.";

    } elseif ($accion === 'editar' && $id > 0) {
        $sql = "UPDATE ceo_servicios_pruebas
                SET servicio=:servicio, descripcion=:descripcion
                WHERE id=:id";
        $pdo->prepare($sql)->execute(compact('servicio','descripcion','id'));
        $msg = "✏ Registro actualizado.";

    } elseif ($accion === 'eliminar' && $id > 0) {
        $pdo->prepare("DELETE FROM ceo_servicios_pruebas WHERE id=?")->execute([$id]);
        $msg = "🗑 Registro eliminado.";
    }
}

/* ============================================================
   CARGA DATOS
============================================================ */
$rows = $pdo->query("SELECT * FROM ceo_servicios_pruebas ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Servicios / Pruebas</title>

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- JQuery + DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<style>
body { background:#f9fbff; font-family:"Segoe UI",Roboto,sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.05);}
.topbar .brand-title { font-weight:700; color:#0d6efd; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05);}
table th, table td { vertical-align:middle; }

/* Estética DataTables */
table.dataTable thead .sorting,
table.dataTable thead .sorting_asc,
table.dataTable thead .sorting_desc {
    background-image: none !important;
}
.dataTables_filter input {
    border-radius: 0.5rem !important;
    padding: .4rem .6rem !important;
}
.dataTables_length select {
    border-radius: 0.5rem;
}
.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: .3rem .7rem;
    margin: 2px;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #0d6efd !important;
    color: white !important;
    border-radius: 0.4rem;
    border: 1px solid #0d6efd;
}
</style>

</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:60px;">
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
     FORMULARIO PRINCIPAL
============================================================ -->
<div class="card p-4 mb-4">
  <h4 class="mb-3">Agregar / Editar Servicio o Prueba</h4>

  <form method="post" id="frmSP" class="row g-3">

    <input type="hidden" name="id" id="id">

    <div class="col-md-4">
      <label class="form-label">Servicio</label>
      <input type="text" class="form-control" name="servicio" id="servicio" required>
    </div>

    <div class="col-md-8">
      <label class="form-label">Descripción</label>
      <input type="text" class="form-control" name="descripcion" id="descripcion">
    </div>

    <div class="col-12 text-end mt-3">
      <button type="submit" name="accion" value="crear" class="btn btn-primary" id="btnGuardar">Guardar</button>
      <button type="submit" name="accion" value="editar" class="btn btn-warning d-none" id="btnActualizar">Actualizar</button>
      <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
    </div>
  </form>
</div>

<!-- ============================================================
     TABLA
============================================================ -->
<div class="card p-4">
  <h4 class="mb-3">Servicios / Pruebas</h4>

  <div class="table-responsive">
    <table class="table table-striped align-middle" id="tablaSP">

      <thead class="table-primary">

        <tr>
            <th>ID</th>
            <th>Servicio</th>
            <th>Descripción</th>
            <th>Acciones</th>
        </tr>

        <tr class="bg-white">
            <th><input class="form-control form-control-sm" placeholder="ID"></th>
            <th><input class="form-control form-control-sm" placeholder="Servicio"></th>
            <th><input class="form-control form-control-sm" placeholder="Descripción"></th>
            <th></th>
        </tr>

      </thead>

      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= esc($r['servicio']) ?></td>
          <td><?= esc($r['descripcion']) ?></td>

          <td>
            <button class="btn btn-info btn-sm btnEditar"
                    data-row='<?= json_encode($r) ?>'>
              Editar
            </button>

            <form method="post" class="d-inline">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button name="accion" value="eliminar" class="btn btn-danger btn-sm"
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

<footer class="text-center mt-4 mb-4 text-secondary"><?= APP_FOOTER ?></footer>

<script>
/* ============================================================
   Inicializar DATATABLES
============================================================ */
$(document).ready(function(){

    var table = $('#tablaSP').DataTable({
        orderCellsTop: true,
        fixedHeader: true,
        pageLength: 25,
        language: { url: "https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" },
        columnDefs: [
            { orderable:false, targets: -1 }
        ]
    });

    // Filtros por columna
    $('#tablaSP thead tr:eq(1) th input').on('keyup change', function(){
        let i = $(this).parent().index();
        table.column(i).search(this.value).draw();
    });
});

/* ============================================================
   EDICIÓN
============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
    btn.addEventListener('click', () => {

        const r = JSON.parse(btn.dataset.row);

        document.getElementById('id').value = r.id;
        document.getElementById('servicio').value = r.servicio;
        document.getElementById('descripcion').value = r.descripcion;

        document.getElementById('btnGuardar').classList.add('d-none');
        document.getElementById('btnActualizar').classList.remove('d-none');
        document.getElementById('btnCancelar').classList.remove('d-none');

        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});

/* ============================================================
   CANCELAR
============================================================ */
document.getElementById('btnCancelar').addEventListener('click', () => {

    document.getElementById('frmSP').reset();
    document.getElementById('btnGuardar').classList.remove('d-none');
    document.getElementById('btnActualizar').classList.add('d-none');
    document.getElementById('btnCancelar').classList.add('d-none');
});
</script>

</body>
</html>
