<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole('ADMIN');
$auth = gpAuth();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $nombres = trim((string)($_POST['nombres'] ?? ''));
        $apellidos = trim((string)($_POST['apellidos'] ?? ''));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $clave = (string)($_POST['clave'] ?? '');
        $idRol = (int)($_POST['id_rol'] ?? 0);
        $estado = (string)($_POST['estado'] ?? 'A');
        if (!in_array($estado, ['A', 'I'], true)) {
            $estado = 'A';
        }

        try {
            if ($accion === 'crear') {
                if ($usuario === '' || $nombres === '' || $clave === '' || $idRol <= 0) {
                    throw new RuntimeException('Usuario, nombres, rol y clave son obligatorios.');
                }
                $stmt = $pdo->prepare('INSERT INTO ceo_gp_usuarios (usuario, nombres, apellidos, correo, clave_hash, id_rol, estado, creado_por) VALUES (:usuario, :nombres, :apellidos, :correo, :clave_hash, :id_rol, :estado, :creado_por)');
                $stmt->execute([
                    ':usuario' => $usuario,
                    ':nombres' => $nombres,
                    ':apellidos' => $apellidos !== '' ? $apellidos : null,
                    ':correo' => $correo !== '' ? $correo : null,
                    ':clave_hash' => password_hash($clave, PASSWORD_DEFAULT),
                    ':id_rol' => $idRol,
                    ':estado' => $estado,
                    ':creado_por' => (int)($auth['id'] ?? 0) ?: null,
                ]);
                $msg = 'Usuario creado correctamente.';
            } elseif ($accion === 'editar') {
                if ($id <= 0 || $usuario === '' || $nombres === '' || $idRol <= 0) {
                    throw new RuntimeException('Datos incompletos para actualizar.');
                }

                $claveSql = $clave !== '' ? ', clave_hash = :clave_hash' : '';
                $stmt = $pdo->prepare("UPDATE ceo_gp_usuarios
                    SET usuario = :usuario,
                        nombres = :nombres,
                        apellidos = :apellidos,
                        correo = :correo,
                        id_rol = :id_rol,
                        estado = :estado
                        {$claveSql}
                    WHERE id = :id
                    LIMIT 1");
                $params = [
                    ':usuario' => $usuario,
                    ':nombres' => $nombres,
                    ':apellidos' => $apellidos !== '' ? $apellidos : null,
                    ':correo' => $correo !== '' ? $correo : null,
                    ':id_rol' => $idRol,
                    ':estado' => $estado,
                    ':id' => $id,
                ];
                if ($clave !== '') {
                    $params[':clave_hash'] = password_hash($clave, PASSWORD_DEFAULT);
                }
                $stmt->execute($params);
                $msg = 'Usuario actualizado correctamente.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$roles = gpRoleOptions($pdo);
$usuarios = $pdo->query("SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre
    FROM ceo_gp_usuarios u
    INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
    ORDER BY u.estado ASC, u.usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Usuarios | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid rgba(13,110,253,.12);box-shadow:0 1px 6px rgba(15,23,42,.04);}
    .card{border:0;border-radius:20px;box-shadow:0 10px 28px rgba(15,23,42,.07);}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-bold h5 mb-0">Usuarios del Gestor de Preguntas</div>
      <small class="text-muted">Cuentas paralelas al acceso CEONext</small>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_home.php" class="btn btn-outline-primary btn-sm">Inicio</a>
      <a href="gp_logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= gpEsc($msg) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= gpEsc($error) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card p-4">
        <h2 class="h5 fw-bold mb-3" id="formTitle">Crear usuario</h2>
        <form method="post" id="formUsuario" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
          <input type="hidden" name="accion" id="accion" value="crear">
          <input type="hidden" name="id" id="id" value="0">

          <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="usuario" id="usuario" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nombres</label>
            <input type="text" name="nombres" id="nombres" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Apellidos</label>
            <input type="text" name="apellidos" id="apellidos" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Correo</label>
            <input type="email" name="correo" id="correo" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Rol</label>
            <select name="id_rol" id="id_rol" class="form-select" required>
              <option value="">Seleccione</option>
              <?php foreach ($roles as $rol): ?>
                <option value="<?= (int)$rol['id'] ?>"><?= gpEsc($rol['nombre']) ?> (<?= gpEsc($rol['codigo']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Estado</label>
            <select name="estado" id="estado" class="form-select">
              <option value="A">Activo</option>
              <option value="I">Inactivo</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Clave</label>
            <input type="password" name="clave" id="clave" class="form-control" placeholder="Obligatoria al crear">
            <div class="form-text">Al editar, deja vacio para mantener la clave actual.</div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Guardar</button>
            <button type="button" class="btn btn-outline-secondary" id="btnCancelar">Cancelar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card p-4">
        <h2 class="h5 fw-bold mb-3">Usuarios registrados</h2>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr><th>Usuario</th><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
              <?php if (!$usuarios): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sin usuarios registrados.</td></tr>
              <?php endif; ?>
              <?php foreach ($usuarios as $u): ?>
                <tr>
                  <td class="fw-semibold"><?= gpEsc($u['usuario']) ?></td>
                  <td><?= gpEsc(trim((string)$u['nombres'] . ' ' . (string)($u['apellidos'] ?? ''))) ?></td>
                  <td><?= gpEsc($u['correo'] ?? '') ?></td>
                  <td><span class="badge text-bg-primary"><?= gpEsc($u['rol_nombre']) ?></span></td>
                  <td><span class="badge text-bg-<?= $u['estado'] === 'A' ? 'success' : 'secondary' ?>"><?= $u['estado'] === 'A' ? 'Activo' : 'Inactivo' ?></span></td>
                  <td class="text-end">
                    <button type="button" class="btn btn-outline-primary btn-sm btnEditar" data-user='<?= gpEsc(json_encode($u, JSON_UNESCAPED_UNICODE)) ?>'>Editar</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const form = document.getElementById('formUsuario');
const title = document.getElementById('formTitle');
function resetForm() {
  form.reset();
  document.getElementById('accion').value = 'crear';
  document.getElementById('id').value = '0';
  document.getElementById('clave').placeholder = 'Obligatoria al crear';
  title.textContent = 'Crear usuario';
}
document.getElementById('btnCancelar').addEventListener('click', resetForm);
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', () => {
    const u = JSON.parse(btn.dataset.user || '{}');
    document.getElementById('accion').value = 'editar';
    document.getElementById('id').value = u.id || '0';
    document.getElementById('usuario').value = u.usuario || '';
    document.getElementById('nombres').value = u.nombres || '';
    document.getElementById('apellidos').value = u.apellidos || '';
    document.getElementById('correo').value = u.correo || '';
    document.getElementById('id_rol').value = u.id_rol || '';
    document.getElementById('estado').value = u.estado || 'A';
    document.getElementById('clave').value = '';
    document.getElementById('clave').placeholder = 'Dejar vacio para mantener';
    title.textContent = 'Editar usuario';
    window.scrollTo({top: 0, behavior: 'smooth'});
  });
});
</script>
</body>
</html>
