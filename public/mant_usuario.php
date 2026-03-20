<?php
// /public/mant_usuario.php
declare(strict_types=1);
//if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

// Validación de sesión
if (empty($_SESSION['auth'])) {
  header('Location: /ceo.noetica.cl/config/index.php');
  exit;
}

$pdo = db();
$msg = '';

/* ============================================================
   CARGA DE ROLES Y EMPRESAS
   ============================================================ */
$stmtRoles = $pdo->query("SELECT id, rol FROM ceo_rol WHERE estado = 'A' ORDER BY rol");
$roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

$stmtEmp = $pdo->query("SELECT id, nombre FROM ceo_empresas ORDER BY nombre");
$empresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   CRUD - CREATE / UPDATE / CAMBIO DE ESTADO
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion   = $_POST['accion'] ?? '';
  $id       = (int)($_POST['id'] ?? 0);
  $codigo   = trim($_POST['codigo'] ?? '');
  $nombres  = trim($_POST['nombres'] ?? '');
  $correo   = trim($_POST['correo'] ?? '');
  $id_rol   = (int)($_POST['id_rol'] ?? 0);
  $id_empresa = (int)($_POST['id_empresa'] ?? 0);
  $estado   = $_POST['estado'] ?? 'A';
  $clave    = $_POST['clave'] ?? '';
  $apellidos = trim($_POST['apellidos'] ?? '');
$clavepruebas = trim($_POST['clavepruebas'] ?? '');


  // CREAR
  if ($accion === 'crear' && $codigo && $nombres) {
    $hash = $clave ? password_hash($clave, PASSWORD_DEFAULT) : null;
        $stmt = $pdo->prepare("
          INSERT INTO ceo_usuarios 
          (codigo, nombres, apellidos, correo, clave_hash, clavepruebas, estado, id_rol, id_empresa, creado_en)
          VALUES 
          (:codigo, :nombres, :apellidos, :correo, :clave, :clavepruebas, :estado, :id_rol, :id_empresa, NOW())
        ");
        
        $stmt->execute([
          'codigo' => $codigo,
          'nombres' => $nombres,
          'apellidos' => $apellidos,
          'correo' => $correo,
          'clave' => $hash,
          'clavepruebas' => $clavepruebas !== '' ? $clavepruebas : null,
          'estado' => $estado,
          'id_rol' => $id_rol,
          'id_empresa' => $id_empresa
        ]);


    $msg = "✅ Usuario creado correctamente.";

  // EDITAR
  } elseif ($accion === 'editar' && $id > 0) {
    $hashSQL = $clave ? ", clave_hash = :clave" : "";
    $clavePruebasSQL = $clavepruebas !== '' ? ", clavepruebas = :clavepruebas" : "";

        $stmt = $pdo->prepare("
            UPDATE ceo_usuarios
            SET codigo=:codigo,
                nombres=:nombres,
                apellidos=:apellidos,
                correo=:correo,
                estado=:estado,
                id_rol=:id_rol,
                id_empresa=:id_empresa
                $hashSQL
                $clavePruebasSQL
            WHERE id=:id
        ");
        
        $params = [
          'codigo' => $codigo,
          'nombres' => $nombres,
          'apellidos' => $apellidos,
          'correo' => $correo,
          'estado' => $estado,
          'id_rol' => $id_rol,
          'id_empresa' => $id_empresa,
          'id' => $id
        ];

        if ($clave) {
            $params['clave'] = password_hash($clave, PASSWORD_DEFAULT);
        }
        
        if ($clavepruebas !== '') {
            $params['clavepruebas'] = $clavepruebas;
        }
        
        $stmt->execute($params);

    $msg = "📝 Usuario actualizado.";

  // CAMBIO DE ESTADO
  } elseif ($accion === 'toggle' && $id > 0) {
    $nuevoEstado = ($_POST['nuevo_estado'] === 'A') ? 'A' : 'D';
    $stmt = $pdo->prepare("UPDATE ceo_usuarios SET estado = :estado WHERE id = :id");
    $stmt->execute(['estado' => $nuevoEstado, 'id' => $id]);
    $msg = ($nuevoEstado === 'A') ? "✅ Usuario reactivado." : "⚠️ Usuario desactivado.";
  }
}

/* ============================================================
   CARGA DE USUARIOS
   ============================================================ */
$stmt = $pdo->query("SELECT u.*, r.rol, e.nombre AS empresa
                     FROM ceo_usuarios u
                     LEFT JOIN ceo_rol r ON r.id = u.id_rol
                     LEFT JOIN ceo_empresas e ON e.id = u.id_empresa
                     ORDER BY u.id DESC");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Mantenimiento de Usuarios</title>
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

  <div class="card p-4 mb-4">
    <h4 class="mb-3">Agregar / Editar Usuario</h4>
    <form method="post" id="frmUsuario" class="row g-3">
      <input type="hidden" name="id" id="id">

      <div class="col-md-3">
        <label class="form-label">Código</label>
        <input type="text" class="form-control" name="codigo" id="codigo" required>
      </div>

      <div class="col-md-3">
        <label class="form-label">Nombre Completo</label>
        <input type="text" class="form-control" name="nombres" id="nombres" required>
      </div>

    <div class="col-md-3">
      <label class="form-label">Apellidos</label>
      <input type="text" class="form-control" name="apellidos" id="apellidos" required>
    </div>

      <div class="col-md-3">
        <label class="form-label">Correo</label>
        <input type="email" class="form-control" name="correo" id="correo">
      </div>

      <div class="col-md-3">
        <label class="form-label">Empresa</label>
        <select name="id_empresa" id="id_empresa" class="form-select" required>
          <option value="">Seleccione...</option>
          <?php foreach ($empresas as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Rol</label>
        <select name="id_rol" id="id_rol" class="form-select" required>
          <option value="">Seleccione...</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['rol']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Clave</label>
        <input type="password" class="form-control" name="clave" id="clave" placeholder="••••••••">
      </div>
        <div class="col-md-2">
          <label class="form-label">Clave Pruebas</label>
          <input type="text"
                 class="form-control"
                 name="clavepruebas"
                 id="clavepruebas"
                 placeholder="Ej: CEO-1234">
        </div>

      <div class="col-md-2">
        <label class="form-label">Estado</label>
        <select name="estado" id="estado" class="form-select">
          <option value="A">Activo</option>
          <option value="D">Desactivado</option>
        </select>
      </div>

      <div class="col-md-12 text-end mt-3">
        <button type="submit" name="accion" value="crear" id="btnGuardar" class="btn btn-primary">Guardar</button>
        <button type="submit" name="accion" value="editar" id="btnActualizar" class="btn btn-warning d-none">Actualizar</button>
        <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card p-4">
    <h4 class="mb-3">Usuarios Registrados</h4>
<div class="row mb-3">
  <div class="col-md-4 ms-auto">
    <input type="text"
           id="buscarUsuario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar usuario...">
  </div>
</div>

    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Código</th>
            <th>Nombre</th>
            <th>Apellidos</th>
            <th>Correo</th>
            <th>Rol</th>
            <th>Empresa</th>
            <th>Estado</th>
            <th>Creado en</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['codigo']) ?></td>
            <td><?= htmlspecialchars($u['nombres']) ?></td>
            <td><?= htmlspecialchars($u['apellidos']) ?></td>
            <td><?= htmlspecialchars($u['correo']) ?></td>
            <td><?= htmlspecialchars($u['rol']) ?></td>
            <td><?= htmlspecialchars($u['empresa'] ?? '') ?></td>
            <td>
              <?php if ($u['estado'] === 'A'): ?>
                <span class="badge bg-success">Activo</span>
              <?php else: ?>
                <span class="badge bg-secondary">Desactivado</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['creado_en']) ?></td>
            <td>
              <button class="btn btn-sm btn-info btnEditar"
                      data-id="<?= $u['id'] ?>"
                      data-codigo="<?= htmlspecialchars($u['codigo']) ?>"
                      data-nombres="<?= htmlspecialchars($u['nombres']) ?>"
                      data-apellidos="<?= htmlspecialchars($u['apellidos']) ?>"
                      data-correo="<?= htmlspecialchars($u['correo']) ?>"
                      data-idrol="<?= $u['id_rol'] ?>"
                      data-idempresa="<?= $u['id_empresa'] ?>"
                      data-estado="<?= $u['estado'] ?>"
                      data-clavepruebas="<?= htmlspecialchars($u['clavepruebas'] ?? '') ?>">
                Editar
              </button>
              <form method="post" class="d-inline">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <input type="hidden" name="nuevo_estado" value="<?= $u['estado'] === 'A' ? 'D' : 'A' ?>">
                <button name="accion" value="toggle"
                        class="btn btn-sm <?= $u['estado'] === 'A' ? 'btn-danger' : 'btn-success' ?>"
                        onclick="return confirm('¿Desea <?= $u['estado'] === 'A' ? 'desactivar' : 'reactivar' ?> este usuario?')">
                  <?= $u['estado'] === 'A' ? 'Desactivar' : 'Reactivar' ?>
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
    document.getElementById('codigo').value = d.codigo;
    document.getElementById('nombres').value = d.nombres;
    document.getElementById('apellidos').value = d.apellidos;
    document.getElementById('correo').value = d.correo;
    document.getElementById('id_rol').value = d.idrol;
    document.getElementById('id_empresa').value = d.idempresa;
    document.getElementById('estado').value = d.estado;
    document.getElementById('clave').value = '';
    document.getElementById('clavepruebas').value = d.clavepruebas || '';

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
  document.getElementById('frmUsuario').reset();
  document.getElementById('btnGuardar').classList.remove('d-none');
  document.getElementById('btnActualizar').classList.add('d-none');
  document.getElementById('btnCancelar').classList.add('d-none');
});
</script>
<script>
document.getElementById('frmUsuario').addEventListener('submit', function(e) {

    const codigo = document.getElementById('codigo').value.trim();
    const nombres = document.getElementById('nombres').value.trim();
    const correo = document.getElementById('correo').value.trim();
    const id_empresa = document.getElementById('id_empresa').value;
    const id_rol = document.getElementById('id_rol').value;
    const clave = document.getElementById('clave').value.trim();
    const accion = document.activeElement.value; // saber si hizo clic en CREAR o EDITAR

    let errores = [];

    if (codigo === '') errores.push("Debe ingresar el Código.");
    if (nombres === '') errores.push("Debe ingresar el Nombre Completo.");
    if (correo === '') errores.push("Debe ingresar un correo.");
    else if (!correo.match(/^[\w\.-]+@[\w\.-]+\.\w+$/))
        errores.push("El correo no tiene formato válido.");

    if (id_empresa === '') errores.push("Debe seleccionar una Empresa.");
    if (id_rol === '') errores.push("Debe seleccionar un Rol.");

    if (accion === 'crear' && clave === '')
        errores.push("Debe ingresar una clave para crear el usuario.");

    if (errores.length > 0) {
        e.preventDefault();
        alert("❌ No es posible guardar:\n\n" + errores.join("\n"));
    }
});
</script>
<script>
/* ============================================================
   Buscador general de usuarios
   ============================================================ */
document.getElementById('buscarUsuario').addEventListener('keyup', function () {
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

