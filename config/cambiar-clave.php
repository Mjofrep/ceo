<?php
// /public/cambiar-clave.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../src/Csrf.php';
require_once __DIR__.'/../config/db.php';

$mensaje = '';
$csrf = Csrf::token();

// Si no hay sesión, en un futuro puedes permitir token por correo. Por ahora:
if (empty($_SESSION['auth'])) {
  // Redirige a login
  header('Location: /../config/index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!Csrf::validate($_POST['csrf'] ?? null)) {
    $mensaje = 'Sesión expirada. Vuelve a intentar.';
  } else {
    $actual = (string)($_POST['actual'] ?? '');
    $nueva  = (string)($_POST['nueva'] ?? '');
    $nueva2 = (string)($_POST['nueva2'] ?? '');

    if ($nueva === '' || strlen($nueva) < 8) {
      $mensaje = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($nueva !== $nueva2) {
      $mensaje = 'La confirmación no coincide.';
    } else {
      // Verificar contraseña actual
      $sql = "SELECT clave_hash FROM ceo_usuarios WHERE id = :id LIMIT 1";
      $st  = db()->prepare($sql);
      $st->execute([':id' => $_SESSION['auth']['id']]);
      $user = $st->fetch();


      if (!$user || !password_verify($actual, $user['clave_hash'])) {
        $mensaje = 'La contraseña actual no es correcta.';
      } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $up = db()->prepare("UPDATE ceo_usuarios SET clave_hash = :h WHERE id = :id");
        $up->execute([':h'=>$hash, ':id'=>$_SESSION['auth']['id']]);
        $mensaje = 'Contraseña actualizada correctamente.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Cambiar contraseña | CEO</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <!-- Header superior -->
<header class="topbar position-sticky top-0 w-100">
  <div class="container position-relative py-2 d-flex align-items-center">
    <img class="logo position-absolute start-0" src="/ceo.noetica.cl/config/assets/logo.png" alt="Logo CEO">

    <div class="w-100 text-center">
      <div class="brand-title h1 mb-0">Centro de Excelencia Operacional</div>
      <small class="text-secondary">Gestión de habilitaciones, permisos y evaluaciones</small>
    </div>
  </div>
</header>

  <div class="container py-5">
    <a href="/ceo.noetica.cl/config/index.php" class="btn btn-link mb-3">&larr; Volver</a>

    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h5 mb-3">Cambiar contraseña</h1>

        <?php if ($mensaje): ?>
          <div class="alert alert-info"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" class="row g-3" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="col-12">
            <label class="form-label" for="actual">Contraseña actual</label>
            <input type="password" id="actual" name="actual" class="form-control" required>
            <div class="invalid-feedback">Ingresa tu contraseña actual.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="nueva">Nueva contraseña</label>
            <input type="password" id="nueva" name="nueva" class="form-control" minlength="8" required>
            <div class="form-text">Mínimo 8 caracteres (combina mayúsculas, minúsculas y números).</div>
            <div class="invalid-feedback">Ingresa una contraseña válida.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="nueva2">Confirmar nueva contraseña</label>
            <input type="password" id="nueva2" name="nueva2" class="form-control" minlength="8" required>
            <div class="invalid-feedback">Confirma tu contraseña.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary">Guardar cambios</button>
          </div>
        </form>

      </div>
    </div>
  </div>
  <script>
    // Validación simple
    (function(){
      const form = document.querySelector('form');
      form.addEventListener('submit', function(e){
        if(!form.checkValidity()){
          e.preventDefault();
          e.stopPropagation();
        }
        form.classList.add('was-validated');
      });
    })();
  </script>
</body>
</html>
