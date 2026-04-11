<?php
// /public/index.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/../src/Csrf.php';
require_once __DIR__.'/../src/Auth.php';
require_once __DIR__.'/../config/db.php'; // conexión PDO
require_once __DIR__.'/../config/functions.php';

$err = '';
$timeoutMsg = '';
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $timeoutMsg = 'Sesion expirada por inactividad. Vuelve a iniciar sesion.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Validación CSRF
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesión expirada. Por favor, recarga e intenta nuevamente.';
        if (function_exists('auditLog')) {
            auditLog('LOGIN_FAIL', 'auth', null, [
                'motivo' => 'csrf'
            ], [
                'codigo' => trim((string)($_POST['usuario'] ?? ''))
            ]);
        }
    } else {
        // 2) Sanitización básica
        $codigo = trim((string)($_POST['usuario'] ?? ''));
        $clave  = (string)($_POST['password'] ?? '');
        if ($codigo === '' || $clave === '') {
            $err = 'Debes ingresar usuario y contraseña.';
            if (function_exists('auditLog')) {
                auditLog('LOGIN_FAIL', 'auth', null, [
                    'motivo' => 'campos_vacios'
                ], [
                    'codigo' => $codigo
                ]);
            }
        } else {
            // 3) Validación de credenciales (usa Auth)
            $res = Auth::login($codigo, $clave);
            if ($res['ok']) {
                try {
                    // Buscar información completa del usuario y su rol
             $pdo = db();
            $stmt = $pdo->prepare("
                SELECT 
                    u.id,
                    u.nombres,
                    u.apellidos,
                    u.correo,
                    r.rol,
                    r.id AS id_rol,
                    e.id   AS id_empresa,
                    e.nombre AS empresa
                FROM ceo_usuarios u
                LEFT JOIN ceo_rol r ON r.id = u.id_rol
                LEFT JOIN ceo_empresas e ON e.id = u.id_empresa
                WHERE u.codigo = :codigo
                LIMIT 1
            ");
            $stmt->execute(['codigo' => $codigo]);
            $usr = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usr) {
                $_SESSION['auth'] = [
                    'nombre'      => trim(($usr['nombres'] ?? '') . ' ' . ($usr['apellidos'] ?? '')),
                    'correo'      => $usr['correo'] ?? '',
                    'rol'         => $usr['rol'] ?? 'Sin rol',
                    'id_rol'      => (int)($usr['id_rol'] ?? 0),
                    'id_empresa'  => (int)($usr['id_empresa'] ?? 0),
                    'empresa'     => $usr['empresa'] ?? '',
                    'id'          => $usr['id'] ?? 0
                ];
            } else {
                $err = 'No se pudo obtener el rol del usuario.';
            }

                    // Redirige solo si la sesión fue correctamente creada
                    if (!empty($_SESSION['auth']['id_rol'])) {
                        if (function_exists('auditLog')) {
                            auditLog('LOGIN_OK', 'auth', null, [
                                'rol' => $_SESSION['auth']['rol'] ?? ''
                            ]);
                        }
                        header('Location: /ceo.noetica.cl/public/general.php');
                        exit;
                    }

                } catch (Throwable $e) {
                    $err = 'Error interno al cargar información del usuario.';
                    if (function_exists('auditLog')) {
                        auditLog('LOGIN_FAIL', 'auth', null, [
                            'motivo' => 'exception'
                        ], [
                            'codigo' => $codigo
                        ]);
                    }
                }
            } else {
                $err = $res['msg'];
                if (function_exists('auditLog')) {
                    auditLog('LOGIN_FAIL', 'auth', null, [
                        'motivo' => 'credenciales'
                    ], [
                        'codigo' => $codigo
                    ]);
                }
            }
        }
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Acceso | <?= APP_NAME ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    main {
  min-height: calc(100vh - 180px); /* compensa header y footer */
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  }
    :root{
      --brand-1: #f6f9fc;
      --brand-2: #ffffff;
      --accent:  #0d6efd;
      --text-1:  #0f172a;
      --muted:   #6b7280;
    }
    html,body{height:100%;}
    body{
      background: radial-gradient(1000px 800px at 10% -10%, #eef4ff 0%, #ffffff 37%), var(--brand-1);
      color: var(--text-1);
    }
    .topbar .container {
      display: grid;
      grid-template-columns: auto 1fr;
      align-items: center;
      position: relative;
    }
    .topbar {
      background: linear-gradient(90deg, #f9fbff 0%, #ffffff 100%);
      border-bottom: 1px solid rgba(13,110,253,0.12);
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
      backdrop-filter: saturate(160%) blur(6px);
      margin-bottom: 1rem;
    }
    .topbar .container {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.5rem 1rem;
    }
    .topbar .logo {
    height: 65px;
    width: auto;
    object-fit: contain;
    filter: drop-shadow(0 1px 1px rgba(0,0,0,0.08));
    }
    .logo-wrapper {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding-left: 1rem;
}
    .brand-title {
      font-weight: 700;
      color: #0f172a;
      text-shadow: 0 1px 0 rgba(255,255,255,0.6);
    }
    .topbar small { color: #6b7280; }
    .login-card{
      max-width: 420px;
      background: var(--brand-2);
      border: 1px solid rgba(13,110,253,0.10);
      box-shadow: 0 10px 30px rgba(13,110,253,0.07),
                  0 3px 12px rgba(2,6,23,.05);
      border-radius: 18px;
      margin-top: 0.5rem;
    }
    .form-floating > label { color: var(--muted); }
    .btn-primary{ box-shadow: 0 6px 16px rgba(13,110,253,0.25); }
    .muted-link{ color: var(--muted); text-decoration: none; }
    .muted-link:hover{ text-decoration: underline; }
    .topbar img.logo {
      height: 70px;
      width: auto;
      justify-self: start;
    }
  </style>
</head>
<body>

<header class="topbar position-sticky top-0 w-100">
  <div class="container d-flex align-items-center justify-content-center py-2 position-relative">
    <div class="logo-wrapper me-auto">
      <img class="logo" src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>">
    </div>
    <div class="text-center flex-grow-1">
      <div class="brand-title h1 mb-0"><?= APP_NAME ?></div>
      <small class="text-secondary"><?= APP_SUBTITLE ?></small>
    </div>
  </div>
</header>



  <!-- Contenido -->
  <main class="d-flex align-items-center justify-content-center" style="min-height: calc(100% - 64px);">
    <div class="login-card p-4 p-md-5 m-3 w-100">
      <div class="mb-4 text-center">
        <h1 class="h4 mb-1">Bienvenido</h1>
        <p class="text-secondary mb-0">Accede para gestionar habilitaciones, permisos y pruebas.</p>
      </div>

<?php if ($timeoutMsg): ?>
  <div class="alert alert-warning text-center"><?= htmlspecialchars($timeoutMsg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
      <div class="alert alert-danger" role="alert">
        <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-floating mb-3">
          <input type="text" class="form-control" id="usuario" name="usuario" placeholder="usuario@empresa.cl" required>
          <label for="usuario">Usuario</label>
          <div class="invalid-feedback">Ingresa tu usuario.</div>
        </div>

        <div class="form-floating mb-3 position-relative">
          <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
          <label for="password">Contraseña</label>
          <button type="button" class="btn btn-sm btn-outline-secondary position-absolute top-50 end-0 translate-middle-y me-2" id="togglePwd" aria-label="Mostrar/Ocultar contraseña">👁️</button>
          <div class="invalid-feedback">Ingresa tu contraseña.</div>
        </div>

        <div class="d-grid gap-2">
          <button class="btn btn-primary btn-lg" type="submit">Aceptar</button>
<a class="btn btn-outline-secondary disabled"
   id="btnCambiarClave"
   role="button"
   aria-disabled="true">
   Cambiar contraseña
</a>

        </div>

<script>
  const inputUsuario = document.getElementById('usuario');
  const btnCambiar = document.getElementById('btnCambiarClave');

  const CAMBIAR_CLAVE_URL = '/ceo.noetica.cl/config/cambiar-clave.php';

  inputUsuario.addEventListener('input', function () {
    if (this.value.trim() === '') {
      btnCambiar.classList.add('disabled');
      btnCambiar.setAttribute('aria-disabled', 'true');
    } else {
      btnCambiar.classList.remove('disabled');
      btnCambiar.removeAttribute('aria-disabled');
    }
  });

  btnCambiar.addEventListener('click', function (e) {
    // ✅ SIEMPRE
    e.preventDefault();

    if (btnCambiar.classList.contains('disabled')) {
      return;
    }

    window.location.href = CAMBIAR_CLAVE_URL;
  });
</script>



      </form>

      <hr class="my-4">
      <div class="text-center small text-secondary">
        © <?= date('Y'); ?> Centro de Excelencia Operacional — Enel
      </div>
    </div>
  </main>

  <script>
    // Validación Bootstrap y UX
    (function(){
      const form = document.querySelector('form');
      form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
          e.preventDefault();
          e.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);

      // Toggle de contraseña
      document.getElementById('togglePwd').addEventListener('click', function(){
        const pwd = document.getElementById('password');
        const isPass = pwd.type === 'password';
        pwd.type = isPass ? 'text' : 'password';
        this.textContent = isPass ? '🙈' : '👁️';
      });
    })();
  </script>
</body>
</html>
