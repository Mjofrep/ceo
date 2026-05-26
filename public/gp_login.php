<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM ceo_gp_usuarios')->fetchColumn();

if (gpAuth()) {
    header('Location: ' . (gpHasPendingPasswordChange() ? GP_FORCE_PASSWORD_CHANGE_PATH : GP_HOME_PATH));
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $clave = (string)($_POST['password'] ?? '');
        if ($usuario === '' || $clave === '') {
            $err = 'Debes ingresar usuario y clave.';
        } else {
            $res = gpLogin($pdo, $usuario, $clave);
            if ($res['ok']) {
                header('Location: ' . GP_HOME_PATH);
                exit;
            }
            if (!empty($res['force_password_change'])) {
                header('Location: ' . GP_FORCE_PASSWORD_CHANGE_PATH);
                exit;
            }
            $err = (string)$res['msg'];
        }
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Gestor de Preguntas | Acceso</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{min-height:100vh;background:radial-gradient(circle at top left,#e8f1ff 0,#f8fbff 42%,#ffffff 100%);display:flex;align-items:center;justify-content:center;color:#0f172a;}
    .login-card{width:min(440px,92vw);border:1px solid rgba(13,110,253,.12);box-shadow:0 24px 70px rgba(15,23,42,.10);border-radius:24px;background:#fff;}
    .brand-mark{width:76px;height:76px;border-radius:22px;background:linear-gradient(135deg,#0d6efd,#18a36f);display:grid;place-items:center;color:#fff;font-size:2rem;box-shadow:0 14px 32px rgba(13,110,253,.25);}
  </style>
</head>
<body>
  <main class="login-card p-4 p-md-5">
    <div class="text-center mb-4">
      <div class="brand-mark mx-auto mb-3"><i class="bi bi-ui-checks-grid"></i></div>
      <h1 class="h4 fw-bold mb-1">Gestor de Preguntas</h1>
      <div class="text-muted small">Acceso independiente a CEONext</div>
    </div>

    <?php if ($err !== ''): ?>
      <div class="alert alert-danger py-2"><?= gpEsc($err) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
      <div class="form-floating mb-3">
        <input type="text" name="usuario" id="usuario" class="form-control" placeholder="usuario" required autofocus>
        <label for="usuario">Usuario</label>
      </div>
      <div class="form-floating mb-3">
        <input type="password" name="password" id="password" class="form-control" placeholder="clave" required>
        <label for="password">Clave</label>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2">Ingresar</button>
    </form>
    <?php if ($userCount === 0): ?>
      <div class="alert alert-warning mt-4 mb-0 small">
        No existen usuarios del Gestor de Preguntas. Crea el primer administrador desde
        <a href="gp_setup.php" class="alert-link">setup inicial</a>.
      </div>
    <?php endif; ?>
    <div class="text-center mt-4 small text-muted"><?= gpEsc(APP_NAME) ?> - <?= gpEsc(APP_SUBTITLE) ?></div>
  </main>
</body>
</html>
