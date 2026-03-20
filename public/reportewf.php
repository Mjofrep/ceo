<?php
// /public/mant_reportewf.php
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
   Escape seguro
   ============================================================ */
function esc($v): string {
  if ($v === null) return '';
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   CRUD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $accion = $_POST['accion'] ?? '';
  $id     = (int)($_POST['id'] ?? 0);

  // Campos del formulario
  $data = [
    "tipo"         => trim($_POST['tipo'] ?? ''),
    "mandante"     => trim($_POST['mandante'] ?? ''),
    "contratista"  => trim($_POST['contratista'] ?? ''),
    "contrato"     => trim($_POST['contrato'] ?? ''),
    "codigo"       => trim($_POST['codigo'] ?? ''),
    "rut_empleado" => trim($_POST['rut_empleado'] ?? ''),
    "nombres"      => trim($_POST['nombres'] ?? ''),
    "apellidos"    => trim($_POST['apellidos'] ?? ''),
    "wf"           => trim($_POST['wf'] ?? ''),
    "servicio"     => trim($_POST['servicio'] ?? ''),
    "cargo"        => trim($_POST['cargo'] ?? '')
  ];

  if ($accion === 'crear') {

      $sql = "INSERT INTO ceo_reportewf 
              (tipo, mandante, contratista, contrato, codigo, rut_empleado, nombres, apellidos, wf, servicio, cargo)
              VALUES (:tipo,:mandante,:contratista,:contrato,:codigo,:rut_empleado,:nombres,:apellidos,:wf,:servicio,:cargo)";
      $pdo->prepare($sql)->execute($data);
      $msg = "✔ Registro creado correctamente.";

  } elseif ($accion === 'editar' && $id > 0) {

      $sql = "UPDATE ceo_reportewf SET
              tipo=:tipo, mandante=:mandante, contratista=:contratista, contrato=:contrato,
              codigo=:codigo, rut_empleado=:rut_empleado, nombres=:nombres, apellidos=:apellidos,
              wf=:wf, servicio=:servicio, cargo=:cargo
              WHERE id=:id";
      $data['id'] = $id;
      $pdo->prepare($sql)->execute($data);
      $msg = "✏ Registro actualizado.";

  } elseif ($accion === 'eliminar' && $id > 0) {
      $pdo->prepare("DELETE FROM ceo_reportewf WHERE id=?")->execute([$id]);
      $msg = "🗑 Registro eliminado.";
  }
}

/* ============================================================
   CARGA DATOS
   ============================================================ */
$rows = $pdo->query("SELECT * FROM ceo_reportewf ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Reporte WF</title>

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
  .card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
  table th, table td { vertical-align:middle; }

  /* DataTables estilo CEO */
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

    <div class="d-flex gap-2">
      <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
    </div>
  </div>
</header>

<main class="container">

<?php if ($msg): ?>
  <div class="alert alert-info text-center"><?= esc($msg) ?></div>
<?php endif; ?>

<!-- ============================================================
     FORMULARIO
============================================================ -->
<div class="card p-4 mb-4">
  <h4 class="mb-3">Agregar / Editar Registro WF</h4>

  <form method="post" id="frmWF" class="row g-3">

    <input type="hidden" name="id" id="id">

    <?php
      $fields = [
        "tipo" => "Tipo",
        "mandante" => "Mandante",
        "contratista" => "Contratista",
        "contrato" => "Contrato",
        "codigo" => "Código",
        "rut_empleado" => "Rut Empleado",
        "nombres" => "Nombres",
        "apellidos" => "Apellidos",
        "wf" => "WF",
        "servicio" => "Servicio",
        "cargo" => "Cargo"
      ];
    ?>

    <?php foreach ($fields as $name => $label): ?>
      <div class="col-md-4">
        <label class="form-label"><?= $label ?></label>
        <input type="text" class="form-control" name="<?= $name ?>" id="<?= $name ?>">
      </div>
    <?php endforeach; ?>

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
<div class="row mb-2">
  <div class="col-md-4 ms-auto">
    <input type="text"
           id="buscarCalendario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar en calendario...">
  </div>
</div>

<div class="card p-4">
  <h4 class="mb-3">Registros WF</h4>

  <div class="table-responsive">
    <table class="table table-striped align-middle" id="tablaWF">
      <thead class="table-primary">

        <!-- Encabezado principal -->
        <tr>
          <th>ID</th>
          <th>Tipo</th>
          <th>Mandante</th>
          <th>Contratista</th>
          <th>Contrato</th>
          <th>Código</th>
          <th>Rut</th>
          <th>Nombres</th>
          <th>Apellidos</th>
          <th>WF</th>
          <th>Servicio</th>
          <th>Cargo</th>
          <th>Acciones</th>
        </tr>

        <!-- Filtros por columna -->
        <tr class="bg-white">
          <th><input class="form-control form-control-sm" placeholder="ID"></th>
          <th><input class="form-control form-control-sm" placeholder="Tipo"></th>
          <th><input class="form-control form-control-sm" placeholder="Mandante"></th>
          <th><input class="form-control form-control-sm" placeholder="Contratista"></th>
          <th><input class="form-control form-control-sm" placeholder="Contrato"></th>
          <th><input class="form-control form-control-sm" placeholder="Código"></th>
          <th><input class="form-control form-control-sm" placeholder="RUT"></th>
          <th><input class="form-control form-control-sm" placeholder="Nombres"></th>
          <th><input class="form-control form-control-sm" placeholder="Apellidos"></th>
          <th><input class="form-control form-control-sm" placeholder="WF"></th>
          <th><input class="form-control form-control-sm" placeholder="Servicio"></th>
          <th><input class="form-control form-control-sm" placeholder="Cargo"></th>
          <th></th>
        </tr>

      </thead>

      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= esc($r['tipo']) ?></td>
          <td><?= esc($r['mandante']) ?></td>
          <td><?= esc($r['contratista']) ?></td>
          <td><?= esc($r['contrato']) ?></td>
          <td><?= esc($r['codigo']) ?></td>
          <td><?= esc($r['rut_empleado']) ?></td>
          <td><?= esc($r['nombres']) ?></td>
          <td><?= esc($r['apellidos']) ?></td>
          <td><?= esc($r['wf']) ?></td>
          <td><?= esc($r['servicio']) ?></td>
          <td><?= esc($r['cargo']) ?></td>

          <td>
            <button class="btn btn-info btn-sm btnEditar"
              data-row='<?= json_encode($r) ?>'>
              Editar
            </button>

            <form method="post" class="d-inline">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button name="accion" value="eliminar"
                class="btn btn-danger btn-sm"
                onclick="return confirm('¿Eliminar registro?')">
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

    // Inicializa DataTable
    var table = $('#tablaWF').DataTable({
        orderCellsTop: true,
        fixedHeader: true,
        pageLength: 25,
        language: {
            url: "https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json"
        },
        columnDefs: [
            { orderable: false, targets: -1 } // Columna acciones sin ordenamiento
        ]
    });

    // Filtros individuales por columna
    $('#tablaWF thead tr:eq(1) th input').on('keyup change', function () {
        let index = $(this).parent().index();
        table.column(index).search(this.value).draw();
    });
});

/* ============================================================
   Edición
============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', () => {

    const r = JSON.parse(btn.dataset.row);

    Object.keys(r).forEach(k => {
      if (document.getElementById(k)) {
        document.getElementById(k).value = r[k];
      }
    });

    document.getElementById('btnGuardar').classList.add('d-none');
    document.getElementById('btnActualizar').classList.remove('d-none');
    document.getElementById('btnCancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});

/* ============================================================
   Cancelar Edición
============================================================ */
document.getElementById('btnCancelar').addEventListener('click', () => {

  document.getElementById('frmWF').reset();
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


