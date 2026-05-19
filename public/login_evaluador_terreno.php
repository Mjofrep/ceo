<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/../src/Csrf.php';
require_once __DIR__.'/../config/db.php';

function debug_point(string $msg): void {
    error_log('[DEBUG LOGIN EVALUADOR] ' . $msg);
}

$err = '';

/* ============================================================
   Cargar lista de evaluadores de terreno
   ============================================================ */
$listaEvaluadores = [];

try {
    $pdo = db();

    $sql = "SELECT id, rut, CONCAT(nombre, ' ', paterno, ' ', materno) AS nombre
            FROM ceo_evaluadores
            WHERE clave IS NOT NULL AND clave <> ''
            ORDER BY nombre, paterno, materno";

    $stmt = $pdo->query($sql);
    $listaEvaluadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $err = "Error al cargar evaluadores: " . $e->getMessage();
}

/* ============================================================
   Procesar Login
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_point('POST recibido');

        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            debug_point('CSRF inválido');
            $err = 'Sesión expirada. Recarga la página.';
        } else {
            debug_point('CSRF válido');


        $evaluadorSeleccionado = intval($_POST['usuario'] ?? 0);
        $clave = trim($_POST['password'] ?? '');

        if ($evaluadorSeleccionado === 0 || $clave === '') {
            $err = 'Debe seleccionar evaluador y digitar la clave.';
        } else {
        debug_point('Evaluador ID: ' . $evaluadorSeleccionado);
        debug_point('Clave ingresada: ' . ($clave !== '' ? '[OK]' : '[VACÍA]'));

            try {
                $pdo = db();

                /* ============================================================
                   1) VALIDAR LOGIN CONTRA ceo_evaluadores
                   ============================================================ */
                $sql = "
                    SELECT 
                        id,
                        rut,
                        nombre,
                        paterno,
                        materno,
                        correo,
                        clave
                    FROM ceo_evaluadores
                    WHERE id = :id
                    AND clave = :clave
                    LIMIT 1
                ";

                $stmt = $pdo->prepare($sql);
                debug_point('Ejecutando consulta de login');

                $stmt->execute([
                    'id'    => $evaluadorSeleccionado,
                    'clave' => $clave
                ]);

                $usr = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$usr) {
                debug_point('Login fallido: usuario no encontrado');
                $err = "Evaluador o clave incorrecta.";
            } else {
                debug_point('Login exitoso para evaluador ID ' . $usr['id']);
                    $nombreCompleto = trim(($usr['nombre'] ?? '') . ' ' . ($usr['paterno'] ?? '') . ' ' . ($usr['materno'] ?? ''));
                    $_SESSION['nombre_referente'] = $nombreCompleto;
                    /* ============================================================
                       LOGIN EXITOSO → Guardar sesión del evaluador
                       ============================================================ */
                    $_SESSION['auth'] = [
                        'id'          => $usr['id'],
                        'rut'         => $usr['rut'] ?? '',
                        'nombre'      => $nombreCompleto,
                        'correo'      => $usr['correo'] ?? '',
                        'rol'         => 'Evaluador Terreno',
                        'id_rol'      => 4,
                        'id_empresa'  => 0,
                        'empresa'     => '',
                        'codigo'      => $usr['rut'] ?? ''
                    ];

                    // Redirección al HOME DEL EVALUADOR
                    debug_point('Preparando redirección');
                    
                    if (headers_sent($file, $line)) {
                        debug_point("HEADERS YA ENVIADOS en $file línea $line");
                        die("❌ Headers enviados en $file línea $line");
                    }
                    
                    debug_point('Headers OK, redirigiendo');
                    header('Location: /ceo.noetica.cl/public/evaluador_home_terreno.php');
                    exit;


                }

            } catch (Throwable $e) {
                $err = "Error interno: " . $e->getMessage();
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
<title>Ingreso Evaluador Terreno | <?= APP_NAME ?></title>
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
  --muted:   #6b7280;
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
  max-width: 420px;
  background: var.var(--brand-2);
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
    <h1 class="h4 mb-1">Ingreso Evaluador</h1>
    <p class="text-secondary">Acceso exclusivo</p>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>

    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <!-- ============================================================
         SELECT DE EVALUADORES DE TERRENO
         ============================================================ -->
    <div class="form-floating mb-3">
        <select class="form-select" id="usuario" name="usuario" required>
            <option value="">Seleccione Evaluador...</option>

            <?php foreach ($listaEvaluadores as $ev): ?>
                <option value="<?= $ev['id'] ?>">
                    <?= htmlspecialchars($ev['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="usuario">Seleccione Evaluador</label>
    </div>

    <div class="form-floating mb-3">
      <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
      <label for="password">Clave</label>
    </div>

    <button class="btn btn-primary w-100 btn-lg" type="submit">Ingresar</button>

  </form>

  <hr class="my-4">
  <div class="text-center small text-secondary">
    © <?= date('Y') ?> Centro de Excelencia Operacional — Enel
  </div>

</div>
</main>

</body>
</html>
