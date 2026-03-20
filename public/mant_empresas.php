<?php
// /public/mant_empresas.php
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
   Validación RUT (servidor)
   ============================================================ */
function validarRut(string $rut): bool {
  $rut = preg_replace('/[^0-9kK]/', '', $rut);
  if (strlen($rut) < 2) return false;

  $dv = strtoupper(substr($rut, -1));
  $num = substr($rut, 0, -1);
  $suma = 0; $factor = 2;

  for ($i = strlen($num) - 1; $i >= 0; $i--) {
    $suma += $num[$i] * $factor;
    $factor = ($factor < 7) ? $factor + 1 : 2;
  }
  $resto = 11 - ($suma % 11);
  $dvEsperado = ($resto == 11) ? '0' : (($resto == 10) ? 'K' : (string)$resto);
  return $dv === $dvEsperado;
}

/* ============================================================
   CRUD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  $rut = trim($_POST['rut'] ?? '');
  $nombre = trim($_POST['nombre'] ?? '');
  $correo = trim($_POST['correo'] ?? '');
  $direccion = trim($_POST['direccion'] ?? '');
  $telefono = trim($_POST['telefono'] ?? '');
  $contacto = trim($_POST['contacto'] ?? '');


    if ($accion === 'crear' && $rut && $nombre) {
      $stmt = $pdo->prepare("INSERT INTO ceo_empresas (rut,nombre,correo,direccion,telefono,contacto)
                            VALUES (:rut,:nombre,:correo,:direccion,:telefono,:contacto)");
      $stmt->execute(compact('rut','nombre','correo','direccion','telefono','contacto'));
      $msg = "✅ Empresa creada correctamente.";

    } elseif ($accion === 'editar' && $id > 0) {
      $stmt = $pdo->prepare("UPDATE ceo_empresas
                             SET rut=:rut, nombre=:nombre, correo=:correo, direccion=:direccion,
                                 telefono=:telefono, contacto=:contacto
                             WHERE id=:id");
      $stmt->execute(compact('rut','nombre','correo','direccion','telefono','contacto','id'));
      $msg = "📝 Empresa actualizada.";

    } elseif ($accion === 'eliminar' && $id > 0) {
      $pdo->prepare("DELETE FROM ceo_empresas WHERE id=?")->execute([$id]);
      $msg = "🗑️ Empresa eliminada.";
    }
}

/* ============================================================
   CARGA DE EMPRESAS
   ============================================================ */
$empresas = $pdo->query("SELECT * FROM ceo_empresas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenimiento de Empresas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    body { background-color:#f9fbff; color:#0f172a; font-family:"Segoe UI",Roboto,sans-serif; }
    .topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.04);}
    .topbar .brand-title { font-weight:700; color:#0d6efd; }
    .card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05);}
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
    <h4 class="mb-3">Agregar / Editar Empresa</h4>
    <form method="post" id="frmEmpresa" class="row g-3" >
      <input type="hidden" name="id" id="id">
      <div class="col-md-3">
        <label class="form-label">RUT</label>
        <input type="text" class="form-control" name="rut" id="rut" required placeholder="12.345.678-9">
      </div>
      <div class="col-md-5">
        <label class="form-label">Nombre / Razón Social</label>
        <input type="text" class="form-control" name="nombre" id="nombre" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Correo</label>
        <input type="email" class="form-control" name="correo" id="correo" placeholder="empresa@dominio.cl">
      </div>
      <div class="col-md-4">
        <label class="form-label">Dirección</label>
        <input type="text" class="form-control" name="direccion" id="direccion">
      </div>
      <div class="col-md-2">
        <label class="form-label">Teléfono</label>
        <input type="text" class="form-control" name="telefono" id="telefono">
      </div>
      <div class="col-md-4">
        <label class="form-label">Contacto Principal</label>
        <input type="text" class="form-control" name="contacto" id="contacto">
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
    <h4 class="mb-3">Empresas Registradas</h4>
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>RUT</th>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Dirección</th>
            <th>Teléfono</th>
            <th>Contacto</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($empresas as $e): ?>
          <tr>
            <td><?= $e['id']; ?></td>
            <td><?= $e['rut']; ?></td>
            <td><?= $e['nombre']; ?></td>
            <td><?= $e['correo']; ?></td>
            <td><?= $e['direccion']; ?></td>
            <td><?= $e['telefono']; ?></td>
            <td><?= $e['contacto']; ?></td>
            <td>
              <button class="btn btn-sm btn-info btnEditar"
                      data-id="<?= $e['id']; ?>"
                      data-rut="<?= $e['rut']; ?>"
                      data-nombre="<?= $e['nombre']; ?>"
                      data-correo="<?= $e['correo']; ?>"
                      data-direccion="<?= $e['direccion']; ?>"
                      data-telefono="<?= $e['telefono']; ?>"
                      data-contacto="<?= $e['contacto']; ?>">
                Editar
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= $e['id']; ?>">
                <button name="accion" value="eliminar" class="btn btn-sm btn-danger"
                        onclick="return confirm('¿Eliminar esta empresa?')">
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
   Validación RUT cliente
   ============================================================ */
function validarRutForm() {
  const rut = document.getElementById('rut').value.trim();
  if (!validarRut(rut)) {
    alert("El RUT ingresado no es válido.");
    return false;
  }
  return true;
}

function validarRut(rut) {
  rut = rut.replace(/[.\-]/g, '').toUpperCase();
  if (!/^[0-9]+[0-9K]$/.test(rut)) return false;
  let cuerpo = rut.slice(0, -1);
  let dv = rut.slice(-1);
  let suma = 0, factor = 2;
  for (let i = cuerpo.length - 1; i >= 0; i--) {
    suma += parseInt(cuerpo[i]) * factor;
    factor = factor === 7 ? 2 : factor + 1;
  }
  const resto = 11 - (suma % 11);
  const dvEsperado = resto === 11 ? "0" : resto === 10 ? "K" : resto.toString();
  return dv === dvEsperado;
}

/* ============================================================
   Edición
   ============================================================ */
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;
    document.getElementById('id').value = d.id;
    document.getElementById('rut').value = d.rut;
    document.getElementById('nombre').value = d.nombre;
    document.getElementById('correo').value = d.correo;
    document.getElementById('direccion').value = d.direccion;
    document.getElementById('telefono').value = d.telefono;
    document.getElementById('contacto').value = d.contacto;
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
  document.getElementById('frmEmpresa').reset();
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
