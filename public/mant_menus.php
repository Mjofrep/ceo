<?php
// /public/mant_menus.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo.noetica.cl/config/index.php');
  exit;
}

$pdo = db();
$msg = '';

/**
 * Sanitizador seguro y universal
 */
function h(mixed $v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   CRUD GENERAL
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  $tabla = $_POST['tabla'] ?? '';
  $id = (int)($_POST['id'] ?? 0);

  // MENÚS
  if ($tabla === 'menu') {
    $nombre = trim($_POST['nombre'] ?? '');
    $pagina = trim($_POST['pagina'] ?? '');
    $orden = trim($_POST['orden'] ?? '');
    $estado = $_POST['estado'] ?? 'A';

    if ($accion === 'crear' && $nombre) {
      $stmt = $pdo->prepare("INSERT INTO menu (nombre, pagina, estado, orden)
                             VALUES (:n, :p, :e, :o)");
      $stmt->execute(['n' => $nombre, 'p' => $pagina, 'e' => $estado, 'o' => $orden]);
      $msg = "✅ Menú creado correctamente.";
    } elseif ($accion === 'editar' && $id > 0) {
      $stmt = $pdo->prepare("UPDATE menu SET nombre=:n, pagina=:p, estado=:e, orden=:o WHERE id=:id");
      $stmt->execute(['n' => $nombre, 'p' => $pagina, 'e' => $estado, 'o' => $orden, 'id' => $id]);
      $msg = "📝 Menú actualizado.";
    } elseif ($accion === 'toggle' && $id > 0) {
      $nuevo = $_POST['nuevo_estado'] === 'A' ? 'A' : 'D';
      $pdo->prepare("UPDATE menu SET estado=? WHERE id=?")->execute([$nuevo, $id]);
      $msg = ($nuevo === 'A') ? "✅ Menú activado." : "⚠️ Menú desactivado.";
    }
  }

  // SUBMENÚS
  if ($tabla === 'submenu') {
    $id_menu = (int)($_POST['id_menu'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $pagina = trim($_POST['pagina'] ?? '');
    $orden = trim($_POST['orden'] ?? '');
    $estado = $_POST['estado'] ?? 'A';

    if ($accion === 'crear' && $nombre) {
      $stmt = $pdo->prepare("INSERT INTO submenu (id_menu, nombre, pagina, estado, orden)
                             VALUES (:id_menu, :n, :p, :e, :o)");
      $stmt->execute(['id_menu' => $id_menu, 'n' => $nombre, 'p' => $pagina, 'e' => $estado, 'o' => $orden]);
      $msg = "✅ Submenú creado correctamente.";
    } elseif ($accion === 'editar' && $id > 0) {
      $stmt = $pdo->prepare("UPDATE submenu
                             SET id_menu=:id_menu, nombre=:n, pagina=:p, estado=:e, orden=:o WHERE id=:id");
      $stmt->execute(['id_menu' => $id_menu, 'n' => $nombre, 'p' => $pagina, 'e' => $estado, 'o' => $orden, 'id' => $id]);
      $msg = "📝 Submenú actualizado.";
    } elseif ($accion === 'toggle' && $id > 0) {
      $nuevo = $_POST['nuevo_estado'] === 'A' ? 'A' : 'D';
      $pdo->prepare("UPDATE submenu SET estado=? WHERE id=?")->execute([$nuevo, $id]);
      $msg = ($nuevo === 'A') ? "✅ Submenú activado." : "⚠️ Submenú desactivado.";
    }
  }

  // ROL ↔ MENÚ
  if ($tabla === 'rolmenu') {
    $id_rol = (int)($_POST['id_rol'] ?? 0);
    $id_orden = trim($_POST['id_orden'] ?? '');

    if ($accion === 'asignar' && $id_rol && $id_orden) {
      $stmt = $pdo->prepare("INSERT IGNORE INTO rol_menu (id, id_orden) VALUES (:id, :id_orden)");
      $stmt->execute(['id' => $id_rol, 'id_orden' => $id_orden]);
      $msg = "✅ Permiso asignado.";
    } elseif ($accion === 'eliminar' && $id_rol && $id_orden) {
      $stmt = $pdo->prepare("DELETE FROM rol_menu WHERE id=:id AND id_orden=:ord");
      $stmt->execute(['id' => $id_rol, 'ord' => $id_orden]);
      $msg = "❌ Permiso retirado.";
    }
  }
}

/* ============================================================
   CARGA DE DATOS
   ============================================================ */
$menus = $pdo->query("SELECT id, id_modulo, nombre, estado, descripcion, pagina, orden FROM menu ORDER BY orden")->fetchAll();
$submenus = $pdo->query("SELECT s.*, m.nombre AS menu_nombre
                         FROM submenu s
                         LEFT JOIN menu m ON m.id=s.id_menu
                         ORDER BY s.id_menu, s.orden")->fetchAll();
$roles = $pdo->query("SELECT id, rol FROM ceo_rol WHERE estado='A' ORDER BY rol")->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Administración de Menús</title>
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
  <?php if ($msg): ?><div class="alert alert-info text-center"><?= h($msg) ?></div><?php endif; ?>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="tabMenu" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#men" type="button">Menús</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sub" type="button">Submenús</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#rol" type="button">Asignación Roles</button></li>
  </ul>

  <div class="tab-content">

    <!-- Menús -->
    <div class="tab-pane fade show active" id="men">
      <div class="card p-4 mb-4">
        <h5>Administrar Menús</h5>
        <form method="post" id="formMenu" class="row g-3">
          <input type="hidden" name="tabla" value="menu">
          <input type="hidden" name="id" id="menu_id">
          <div class="col-md-3"><input type="text" class="form-control" name="nombre" id="menu_nombre" placeholder="Nombre del menú" required></div>
          <div class="col-md-3"><input type="text" class="form-control" name="pagina" id="menu_pagina" placeholder="Ruta o página (opcional)"></div>
          <div class="col-md-2"><input type="text" class="form-control" name="orden" id="menu_orden" placeholder="Orden" required></div>
          <div class="col-md-2">
            <select name="estado" id="menu_estado" class="form-select">
              <option value="A">Activo</option>
              <option value="D">Inactivo</option>
            </select>
          </div>
          <div class="col-md-2 text-end">
            <button class="btn btn-primary" name="accion" value="crear" id="btnGuardarMenu">Guardar</button>
            <button class="btn btn-warning d-none" name="accion" value="editar" id="btnEditarMenu">Actualizar</button>
            <button type="button" class="btn btn-secondary d-none" id="btnCancelarMenu">Cancelar</button>
          </div>
        </form>
      </div>

      <div class="card p-4">
        <table class="table table-hover align-middle">
          <thead class="table-primary"><tr><th>Nombre</th><th>Página</th><th>Orden</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($menus as $m): ?>
            <tr>
              <td><?= $m['nombre'];?></td>
              <td><?= $m['pagina']; ?></td>
              <td><?= $m['orden']; ?></td>
              <td><?= $m['estado']==='A' ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
              <td>
                <button type="button" class="btn btn-sm btn-info btnEditMenu"
                  data-id="<?= $m['id'] ?>" data-nombre="<?= $m['nombre']; ?>"
                  data-pagina="<?= $m['pagina']; ?>" data-orden="<?= $m['orden']; ?>" data-estado="<?= $m['estado']; ?>">
                  Editar
                </button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="tabla" value="menu">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="nuevo_estado" value="<?= $m['estado']==='A'?'D':'A' ?>">
                  <button class="btn btn-sm <?= $m['estado']==='A'?'btn-danger':'btn-success' ?>" name="accion" value="toggle">
                    <?= $m['estado']==='A'?'Desactivar':'Activar' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Submenús -->
    <div class="tab-pane fade" id="sub">
      <div class="card p-4 mb-4">
        <h5>Administrar Submenús</h5>
        <form method="post" id="formSubmenu" class="row g-3">
          <input type="hidden" name="tabla" value="submenu">
          <input type="hidden" name="id" id="sub_id">
          <div class="col-md-3">
            <select name="id_menu" id="sub_id_menu" class="form-select" required>
              <option value="">Menú padre</option>
              <?php foreach ($menus as $m): ?>
                <option value="<?= $m['id'] ?>"><?= $m['nombre']; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><input type="text" class="form-control" name="nombre" id="sub_nombre" placeholder="Nombre del submenú" required></div>
          <div class="col-md-3"><input type="text" class="form-control" name="pagina" id="sub_pagina" placeholder="Ruta o página"></div>
          <div class="col-md-2"><input type="text" class="form-control" name="orden" id="sub_orden" placeholder="Orden"></div>
          <div class="col-md-1 text-end">
            <button class="btn btn-primary" name="accion" value="crear" id="btnGuardarSub">Guardar</button>
            <button class="btn btn-warning d-none" name="accion" value="editar" id="btnEditarSub">Actualizar</button>
            <button type="button" class="btn btn-secondary d-none" id="btnCancelarSub">Cancelar</button>
          </div>
        </form>
      </div>

      <div class="card p-4">
        <table class="table table-hover align-middle">
          <thead class="table-primary"><tr><th>Menú</th><th>Submenú</th><th>Página</th><th>Orden</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
          <?php foreach ($submenus as $s): ?>
            <tr>
              <td><?= $s['menu_nombre']; ?></td>
              <td><?= $s['nombre']; ?></td>
              <td><?= $s['pagina']; ?></td>
              <td><?= $s['orden']; ?></td>
              <td><?= $s['estado']==='A' ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
              <td>
                <button type="button" class="btn btn-sm btn-info btnEditSub"
                  data-id="<?= $s['id']; ?>" data-idmenu="<?= $s['id_menu']; ?>"
                  data-nombre="<?= $s['nombre']; ?>" data-pagina="<?= $s['pagina']; ?>"
                  data-orden="<?= $s['orden']; ?>" data-estado="<?= $s['estado']; ?>">
                  Editar
                </button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="tabla" value="submenu">
                  <input type="hidden" name="id" value="<?= $s['id']; ?>">
                  <input type="hidden" name="nuevo_estado" value="<?= $s['estado']==='A'?'D':'A' ?>">
                  <button class="btn btn-sm <?= $s['estado']==='A'?'btn-danger':'btn-success' ?>" name="accion" value="toggle">
                    <?= $s['estado']==='A'?'Desactivar':'Activar' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Asignación de roles -->
    <div class="tab-pane fade" id="rol">
      <div class="card p-4 mb-4">
        <h5>Asignar Permisos de Rol a Menús/Submenús</h5>
        <form method="post" class="row g-3">
          <input type="hidden" name="tabla" value="rolmenu">
          <div class="col-md-3">
            <select name="id_rol" class="form-select" required>
              <option value="">Rol</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id']; ?>"><?= $r['rol']; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <select name="id_orden" class="form-select" required>
              <option value="">Menú/Submenú (por orden)</option>
              <?php foreach ($menus as $m): ?>
                <option value="<?= $m['orden']; ?>">Menú: <?= $m['nombre']; ?></option>
              <?php endforeach; ?>
              <?php foreach ($submenus as $s): ?>
                <option value="<?= $s['orden']; ?>">Submenú: <?= $s['nombre']; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><button class="btn btn-primary" name="accion" value="asignar">Asignar</button></div>
        </form>
      </div>

      <div class="card p-4">
        <table class="table table-hover align-middle">
          <thead class="table-primary"><tr><th>Rol</th><th>ID Orden</th><th>Acción</th></tr></thead>
          <tbody>
          <?php foreach ($roles as $r): ?>
            <?php
              $asignados = $pdo->prepare("SELECT id_orden FROM rol_menu WHERE id=?");
              $asignados->execute([$r['id']]);
              foreach ($asignados->fetchAll() as $a): ?>
                <tr>
                  <td><?= $r['rol']; ?></td>
                  <td><?= $a['id_orden']; ?></td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="tabla" value="rolmenu">
                      <input type="hidden" name="id_rol" value="<?= $r['id'] ?>">
                      <input type="hidden" name="id_orden" value="<?= $a['id_orden']; ?>">
                      <button class="btn btn-sm btn-danger" name="accion" value="eliminar">Eliminar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<footer><?= APP_FOOTER ?></footer>

<script>
/* === EDICIÓN DE MENÚ === */
document.querySelectorAll('.btnEditMenu').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;
    document.getElementById('menu_id').value = d.id;
    document.getElementById('menu_nombre').value = d.nombre;
    document.getElementById('menu_pagina').value = d.pagina;
    document.getElementById('menu_orden').value = d.orden;
    document.getElementById('menu_estado').value = d.estado;
    document.getElementById('btnGuardarMenu').classList.add('d-none');
    document.getElementById('btnEditarMenu').classList.remove('d-none');
    document.getElementById('btnCancelarMenu').classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
document.getElementById('btnCancelarMenu').addEventListener('click', () => {
  document.getElementById('formMenu').reset();
  document.getElementById('btnGuardarMenu').classList.remove('d-none');
  document.getElementById('btnEditarMenu').classList.add('d-none');
  document.getElementById('btnCancelarMenu').classList.add('d-none');
});

/* === EDICIÓN DE SUBMENÚ === */
document.querySelectorAll('.btnEditSub').forEach(btn => {
  btn.addEventListener('click', e => {
    const d = e.target.dataset;
    document.getElementById('sub_id').value = d.id;
    document.getElementById('sub_id_menu').value = d.idmenu;
    document.getElementById('sub_nombre').value = d.nombre;
    document.getElementById('sub_pagina').value = d.pagina;
    document.getElementById('sub_orden').value = d.orden;
    document.getElementById('btnGuardarSub').classList.add('d-none');
    document.getElementById('btnEditarSub').classList.remove('d-none');
    document.getElementById('btnCancelarSub').classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
document.getElementById('btnCancelarSub').addEventListener('click', () => {
  document.getElementById('formSubmenu').reset();
  document.getElementById('btnGuardarSub').classList.remove('d-none');
  document.getElementById('btnEditarSub').classList.add('d-none');
  document.getElementById('btnCancelarSub').classList.add('d-none');
});
</script>

</body>
</html>


