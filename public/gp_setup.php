<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM ceo_gp_usuarios')->fetchColumn();
if ($userCount > 0) {
    header('Location: ' . GP_LOGIN_PATH);
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $nombres = trim((string)($_POST['nombres'] ?? ''));
        $apellidos = trim((string)($_POST['apellidos'] ?? ''));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $clave = (string)($_POST['clave'] ?? '');
        $clave2 = (string)($_POST['clave2'] ?? '');

        try {
            if ($usuario === '' || $nombres === '' || $clave === '') {
                throw new RuntimeException('Usuario, nombres y clave son obligatorios.');
            }
            if ($clave !== $clave2) {
                throw new RuntimeException('Las claves no coinciden.');
            }
            if (strlen($clave) < 8) {
                throw new RuntimeException('La clave debe tener al menos 8 caracteres.');
            }

            $stmtRole = $pdo->prepare("SELECT id FROM ceo_gp_roles WHERE codigo = 'ADMIN' LIMIT 1");
            $stmtRole->execute();
            $idRol = (int)$stmtRole->fetchColumn();
            if ($idRol <= 0) {
                throw new RuntimeException('No se pudo resolver el rol administrador.');
            }

            $stmt = $pdo->prepare('INSERT INTO ceo_gp_usuarios (usuario, nombres, apellidos, correo, clave_hash, id_rol, estado) VALUES (:usuario, :nombres, :apellidos, :correo, :clave_hash, :id_rol, "A")');
            $stmt->execute([
                ':usuario' => $usuario,
                ':nombres' => $nombres,
                ':apellidos' => $apellidos !== '' ? $apellidos : null,
                ':correo' => $correo !== '' ? $correo : null,
                ':clave_hash' => password_hash($clave, PASSWORD_DEFAULT),
                ':id_rol' => $idRol,
            ]);

            gpLogin($pdo, $usuario, $clave);
            header('Location: ' . GP_HOME_PATH);
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Setup | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{min-height:100vh;background:#f7f9fc;display:flex;align-items:center;justify-content:center;}
    .setup-card{width:min(520px,94vw);border:0;border-radius:22px;box-shadow:0 18px 50px rgba(15,23,42,.10);background:#fff;}
  </style>
</head>
<body>
  <main class="setup-card p-4 p-md-5">
    <h1 class="h4 fw-bold mb-2">Setup inicial</h1>
    <p class="text-muted">Crea el primer usuario administrador del Gestor de Preguntas.</p>
    <?php if ($err !== ''): ?><div class="alert alert-danger"><?= gpEsc($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Usuario</label>
          <input type="text" name="usuario" class="form-control" required autofocus>
        </div>
        <div class="col-md-6">
          <label class="form-label">Correo</label>
          <input type="email" name="correo" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Nombres</label>
          <input type="text" name="nombres" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Apellidos</label>
          <input type="text" name="apellidos" class="form-control">
        </div>
        <div class="col-md-6">
          <label class="form-label">Clave</label>
          <input type="password" name="clave" class="form-control" required minlength="8">
        </div>
        <div class="col-md-6">
          <label class="form-label">Repetir clave</label>
          <input type="password" name="clave2" class="form-control" required minlength="8">
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 mt-4">Crear administrador</button>
    </form>
  </main>
</body>
</html>
