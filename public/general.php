<?php
// /ceo/public/general.php


declare(strict_types=1);
//if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/auth.php';


$usuario = $_SESSION['auth']['nombre'] ?? 'Invitado';
$rol     = $_SESSION['auth']['rol'] ?? 'Sin rol';
$idRol   = (int)($_SESSION['auth']['id_rol'] ?? 0);

/* ============================================================
   CONEXIÓN A BASE DE DATOS (usa PDO desde /config/db.php)
   ============================================================ */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__.'/../config/app.php';
$pdo = db();

/* ============================================================
   FUNCIÓN SEGURA PARA basename()
   ============================================================ */
function safeBasename(?string $path): string {
    return $path ? basename($path) : '';
}

/* ============================================================
   CARGA DE PERMISOS DEL ROL
   ============================================================ */
$permitidos = [];
try {
  $stmt = $pdo->prepare("SELECT id_orden FROM rol_menu WHERE id = :idrol");
  $stmt->execute(['idrol' => $idRol]);
  $permitidos = array_column($stmt->fetchAll(), 'id_orden');
  // Normalizamos todos los valores a string
  $permitidos = array_map('strval', $permitidos);
} catch (Throwable $e) {
  $permitidos = []; // Si hay error, continúa sin permisos
}

/* ============================================================
   CARGA DE MENÚS Y SUBMENÚS
   ============================================================ */
$sqlMenu = "SELECT id, nombre, pagina, estado, orden 
            FROM menu 
            WHERE estado = 'A' 
            ORDER BY orden";
$stmtMenu = $pdo->query($sqlMenu);
$menus = [];

foreach ($stmtMenu as $menu) {
    $stmtSub = $pdo->prepare("
        SELECT nombre, pagina, estado, orden 
        FROM submenu 
        WHERE id_menu = :id AND estado = 'A' 
        ORDER BY orden
    ");
    $stmtSub->execute(['id' => $menu['id']]);
    $menu['submenus'] = $stmtSub->fetchAll();
    $menus[] = $menu;
}

/* ============================================================
   DETECCIÓN DE PÁGINA ACTUAL
   ============================================================ */
$currentPage = safeBasename($_SERVER['SCRIPT_NAME']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Panel General | <?= APP_NAME ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    :root {
      --azul-suave: rgba(13,110,253,0.1);
      --azul-borde: rgba(13,110,253,0.3);
      --azul-fondo: rgba(13,110,253,0.25);
    }

    body {
      background-color: #f9fbff;
      color: #0f172a;
      min-height: 100vh;
      font-family: "Segoe UI", Roboto, sans-serif;
    }

    /* --- Header principal --- */
    .topbar {
      background: linear-gradient(90deg, #f9fbff 0%, #ffffff 100%);
      border-bottom: 1px solid rgba(13,110,253,0.12);
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
      backdrop-filter: saturate(160%) blur(6px);
      position: sticky;
      top: 0;
      z-index: 1030;
    }

    .topbar .container {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0.5rem 1rem;
    }

    .topbar .logo {
      position: absolute;
      left: 1rem;
      height: 55px;
      width: auto;
      object-fit: contain;
      filter: drop-shadow(0 1px 1px rgba(0,0,0,0.08));
    }

    .brand-title {
      font-weight: 700;
      color: #0f172a;
      text-shadow: 0 1px 0 rgba(255,255,255,0.6);
    }

    /* --- Menú de navegación --- */
    .navbar-ceo {
      background: rgba(13, 110, 253, 0.15);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid var(--azul-borde);
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }

    .navbar-ceo .nav-link {
      color: #0f172a;
      font-weight: 500;
    }
    .navbar-ceo .nav-link:hover,
    .navbar-ceo .nav-link:focus {
      color: #0d6efd;
      background-color: rgba(13,110,253,0.08);
      border-radius: 0.5rem;
    }

    .navbar-ceo .nav-link.active {
      color: #0d6efd !important;
      font-weight: 600;
      text-decoration: underline;
    }

    .navbar-ceo .dropdown-menu {
      border-radius: 0.5rem;
      border: 1px solid var(--azul-borde);
      background-color: #ffffffcc;
      backdrop-filter: blur(6px);
    }

    .navbar-ceo .dropdown-item:hover {
      background-color: rgba(13,110,253,0.08);
    }

    .navbar-ceo .dropdown-item.active {
      color: #0d6efd !important;
      font-weight: 600;
      background-color: rgba(13,110,253,0.1);
    }

    /* Opciones deshabilitadas visualmente */
    .nav-link.disabled, .dropdown-item.disabled {
      color: #9ca3af !important;
      pointer-events: none;
      opacity: 0.7;
    }

    /* --- Contenido principal --- */
    main {
      padding: 2rem 1rem;
    }
    .card {
      border: 1px solid rgba(13,110,253,0.15);
      border-radius: 1rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    /* --- Footer --- */
    footer {
      text-align: center;
      font-size: 0.9rem;
      color: #6b7280;
      padding: 1rem;
      border-top: 1px solid rgba(13,110,253,0.1);
      margin-top: 2rem;
    }

    .topbar img.logo {
      height: 70px;
      width: auto;
      justify-self: start;
    }
    /* Asegura que los dropdowns del menú estén sobre cualquier elemento */
.navbar-ceo .dropdown-menu {
  z-index: 2000 !important;
}

/* En caso de que haya tarjetas o contenedores con z-index alto */
.card, main, .login-card {
  position: relative;
  z-index: 1;
}

/* Evita que contenedores oculten los menús */
.navbar-ceo {
  position: relative;
  z-index: 1050;
}

  </style>
</head>
<body>

  <!-- HEADER SUPERIOR -->
  <header class="topbar">
    <div class="container">
    <img class="logo" src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>">
<div class="text-center">
  <div class="brand-title h1 mb-0"><?= APP_NAME ?></div>
  <small class="text-secondary"><?= APP_SUBTITLE ?></small>
</div>
    </div>
  </header>

  <!-- MENÚ DINÁMICO -->
  <nav class="navbar navbar-expand-lg navbar-ceo">
    <div class="container">
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCeo" aria-controls="navbarCeo" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarCeo">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">

          <?php foreach ($menus as $menu): ?>
            <?php
              $paginaMenu = (string)($menu['pagina'] ?? '');
              $isMenuActive = ($paginaMenu !== '' && safeBasename($paginaMenu) === $currentPage);

              $hasActiveSub = false;
              foreach ($menu['submenus'] as $s) {
                $paginaSub = (string)($s['pagina'] ?? '');
                if ($paginaSub !== '' && safeBasename($paginaSub) === $currentPage) {
                  $hasActiveSub = true;
                }
              }

              $menuPermitido = in_array((string)$menu['orden'], $permitidos, true);
            ?>

            <?php if (!empty($menu['pagina'])): ?>
              <li class="nav-item">
                <a class="nav-link <?= $isMenuActive ? 'active' : '' ?> <?= !$menuPermitido ? 'disabled' : '' ?>"
                   href="<?= $menuPermitido ? htmlspecialchars($menu['pagina']) : '#' ?>">
                   <?= htmlspecialchars($menu['nombre']) ?>
                </a>
              </li>
            <?php else: ?>
              <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle <?= $hasActiveSub ? 'active' : '' ?> <?= !$menuPermitido ? 'disabled' : '' ?>"
                   href="#" id="menu<?= $menu['id'] ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                  <?= htmlspecialchars($menu['nombre']) ?>
                </a>
                <ul class="dropdown-menu" aria-labelledby="menu<?= $menu['id'] ?>">
                  <?php foreach ($menu['submenus'] as $sub): ?>
                    <?php
                      $paginaSub = (string)($sub['pagina'] ?? '');
                      $isActive = ($paginaSub !== '' && safeBasename($paginaSub) === $currentPage);
                      $subPermitido = in_array((string)$sub['orden'], $permitidos, true);
                    ?>
                    <?php if (!empty($sub['pagina'])): ?>
                      <li><a class="dropdown-item <?= $isActive ? 'active' : '' ?> <?= !$subPermitido ? 'disabled' : '' ?>"
                             href="<?= $subPermitido ? htmlspecialchars($sub['pagina']) : '#' ?>">
                             <?= htmlspecialchars($sub['nombre']) ?>
                      </a></li>
                    <?php else: ?>
                      <li><h6 class="dropdown-header"><?= htmlspecialchars($sub['nombre']) ?></h6></li>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endif; ?>
          <?php endforeach; ?>

        </ul>

        <div class="d-flex align-items-center gap-3">
          <span class="text-secondary small"><?= htmlspecialchars($usuario) ?> (<?= htmlspecialchars($rol) ?>)</span>
          <a href="/ceo.noetica.cl/config/index.php" class="btn btn-sm btn-outline-danger">Salir</a>
        </div>
      </div>
    </div>
  </nav>

  <!-- CONTENIDO PRINCIPAL -->
  <main class="container mt-4">
    <div class="card p-4">
      <h2 class="h5 mb-3">Bienvenido al sistema</h2>
      <p>Esta versión incluye control de acceso por <strong>rol</strong> y comparación exacta de permisos.</p>
      <ul>
        <li>Menú y submenú cargados dinámicamente desde MySQL.</li>
        <li>Gestión de Permisos, para todas las condiciones y espacios.</li>
        <li>Manejo de perfiles.</li>
        <li>desarrollo de evaluaciones incorporado</li>
        <li>gestión de Documentos y más....</li>
      </ul>
    </div>
  </main>

  <!-- FOOTER -->
  <footer>
  <?= APP_FOOTER ?>
</footer>


</body>
</html>

