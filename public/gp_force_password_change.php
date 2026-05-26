<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);

$auth = gpAuth();
if (!$auth || !gpHasPendingPasswordChange() || (int)($_SESSION['gp_force_password_change_user_id'] ?? 0) !== (int)($auth['id'] ?? 0)) {
    header('Location: ' . GP_LOGIN_PATH);
    exit;
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        try {
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if ($newPassword === '' || $confirmPassword === '') {
                throw new RuntimeException('Debes completar ambos campos de clave.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('La confirmacion de la clave no coincide.');
            }

            gpUpdateUserPassword($pdo, (int)$auth['id'], $newPassword);
            unset($_SESSION['gp_force_password_change'], $_SESSION['gp_force_password_change_user_id']);
            $msg = 'Clave actualizada correctamente. Seras redirigido al inicio.';
            header('Refresh: 1; url=' . GP_HOME_PATH);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cambio Obligatorio de Clave | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{min-height:100vh;background:radial-gradient(circle at top left,#e8f1ff 0,#f8fbff 42%,#ffffff 100%);display:flex;align-items:center;justify-content:center;color:#0f172a;}
    .auth-card{width:min(520px,92vw);border:1px solid rgba(13,110,253,.12);box-shadow:0 24px 70px rgba(15,23,42,.10);border-radius:24px;background:#fff;}
  </style>
</head>
<body>
  <main class="auth-card p-4 p-md-5">
    <div class="mb-4 text-center">
      <h1 class="h4 fw-bold mb-2">Cambio Obligatorio de Clave</h1>
      <p class="text-muted mb-0">Por seguridad, debes cambiar la clave inicial antes de continuar.</p>
    </div>

    <?php if ($msg !== ''): ?>
      <div class="alert alert-success py-2"><?= gpEsc($msg) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger py-2"><?= gpEsc($error) ?></div>
    <?php endif; ?>

    <div class="alert alert-info small">
      La nueva clave debe tener al menos 10 caracteres, incluir mayusculas, minusculas, numeros y signos especiales.
    </div>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
      <div class="mb-3">
        <label for="new_password" class="form-label">Nueva clave</label>
        <input type="password" name="new_password" id="new_password" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirmar nueva clave</label>
        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 py-2">Actualizar clave</button>
    </form>
  </main>
</body>
</html>
