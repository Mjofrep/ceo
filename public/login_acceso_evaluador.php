<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

$err = '';

$destinos = [
    'teorica' => '/ceo.noetica.cl/public/login_evaluador.php',
    'formacion' => '/ceo.noetica.cl/public/login_formacion_evaluador.php',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesión expirada. Recarga la página.';
    } else {
        $codigo = trim((string)($_POST['codigo'] ?? ''));
        $clave = (string)($_POST['password'] ?? '');
        $destino = (string)($_POST['destino'] ?? '');

        if ($codigo === '' || $clave === '' || !isset($destinos[$destino])) {
            $err = 'Debe ingresar código, clave y seleccionar un destino.';
        } else {
            try {
                $pdo = db();
                $stmt = $pdo->prepare("
                    SELECT
                        u.id,
                        u.codigo,
                        u.nombres,
                        u.apellidos,
                        u.correo,
                        u.clave_hash,
                        u.estado,
                        u.id_rol,
                        u.id_empresa,
                        r.rol,
                        e.nombre AS empresa
                    FROM ceo_usuarios u
                    LEFT JOIN ceo_rol r ON r.id = u.id_rol
                    LEFT JOIN ceo_empresas e ON e.id = u.id_empresa
                    WHERE u.codigo = :codigo
                    LIMIT 1
                ");
                $stmt->execute([':codigo' => $codigo]);
                $usr = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$usr || (string)($usr['estado'] ?? '') !== 'A' || !password_verify($clave, (string)($usr['clave_hash'] ?? ''))) {
                    $err = 'Código o contraseña incorrectos.';
                } elseif (!in_array((int)($usr['id_rol'] ?? 0), [4, 5], true)) {
                    $err = 'Usuario no autorizado para evaluación.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['auth'] = [
                        'id' => (int)($usr['id'] ?? 0),
                        'codigo' => (string)($usr['codigo'] ?? ''),
                        'nombre' => trim((string)($usr['nombres'] ?? '') . ' ' . (string)($usr['apellidos'] ?? '')),
                        'correo' => (string)($usr['correo'] ?? ''),
                        'rol' => (string)($usr['rol'] ?? 'Evaluador'),
                        'id_rol' => (int)($usr['id_rol'] ?? 0),
                        'id_empresa' => (int)($usr['id_empresa'] ?? 0),
                        'empresa' => (string)($usr['empresa'] ?? ''),
                    ];

                    session_write_close();
                    header('Location: ' . $destinos[$destino]);
                    exit;
                }
            } catch (Throwable $e) {
                $err = 'Error interno: ' . $e->getMessage();
            }
        }
    }
}

$csrf = Csrf::token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Acceso Evaluador | <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
main {
  min-height: calc(100vh - 180px);
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
}
html,body{height:100%;}
body{
  background: radial-gradient(1000px 800px at 10% -10%, #eef4ff 0%, #ffffff 37%), var(--brand-1);
  color: var(--text-1);
}
.topbar {
  background: linear-gradient(90deg, #f9fbff 0%, #ffffff 100%);
  padding: .5rem;
  border-bottom: 1px solid rgba(13,110,253,0.12);
  margin-bottom: 1rem;
}
.topbar .logo {
  height: 70px;
}
.login-card{
  max-width: 440px;
  background: var(--brand-2);
  border: 1px solid rgba(13,110,253,0.10);
  box-shadow: 0 10px 30px rgba(13,110,253,0.07);
  border-radius: 18px;
}
</style>
</head>
<body>
<header class="topbar text-center">
    <img src="<?= APP_LOGO ?>" class="logo">
    <h1 class="h4"><?= APP_NAME ?></h1>
    <small class="text-secondary"><?= APP_SUBTITLE ?></small>
</header>

<main>
<div class="login-card p-4 p-md-5 m-3 w-100">
  <div class="mb-4 text-center">
    <h1 class="h4 mb-1">Acceso Evaluador</h1>
    <p class="text-secondary">Ingrese su código y contraseña para continuar.</p>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="form-floating mb-3">
      <input type="text" class="form-control" id="codigo" name="codigo" placeholder="Código" required>
      <label for="codigo">Código</label>
    </div>

    <div class="form-floating mb-4">
      <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
      <label for="password">Contraseña</label>
    </div>

    <div class="d-grid gap-2">
      <button class="btn btn-primary btn-lg" type="submit" name="destino" value="teorica">Teórica Habilitación</button>
      <button class="btn btn-outline-primary btn-lg" type="submit" name="destino" value="formacion">Teórica Formación</button>
    </div>
  </form>

  <hr class="my-4">
  <div class="text-center small text-secondary">
    © <?= date('Y') ?> Centro de Excelencia Operacional — Enel
  </div>
</div>
</main>
</body>
</html>
