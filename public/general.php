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

$apps = [
    [
        'title' => 'CEONext',
        'description' => 'Permisos, habilitacion, formacion y gestion operativa en una sola plataforma.',
        'url' => 'https://www.noetica.cl/ceo.noetica.cl/config/index.php',
        'tag' => 'Plataforma central',
        'accent' => '#0d6efd',
        'image' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 320 320'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0%25' stop-color='%230d6efd'/%3E%3Cstop offset='100%25' stop-color='%2381b1ff'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='320' height='320' rx='40' fill='url(%23g)'/%3E%3Ccircle cx='240' cy='88' r='62' fill='rgba(255,255,255,0.18)'/%3E%3Cpath d='M70 220c26-58 74-92 146-104' stroke='white' stroke-width='18' stroke-linecap='round' fill='none' opacity='.85'/%3E%3Cpath d='M72 226h170' stroke='white' stroke-width='14' stroke-linecap='round' opacity='.7'/%3E%3Crect x='74' y='76' width='90' height='90' rx='22' fill='rgba(255,255,255,0.22)'/%3E%3C/svg%3E",
    ],
    [
        'title' => 'Salud',
        'description' => 'Seguimiento y acceso a procesos vinculados a salud ocupacional.',
        'url' => 'https://www.noetica.cl/ceo_salud/auth/login.php',
        'tag' => 'Seguridad y cuidado',
        'accent' => '#18a36f',
        'image' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 320 320'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0%25' stop-color='%2318a36f'/%3E%3Cstop offset='100%25' stop-color='%2385e2bb'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='320' height='320' rx='40' fill='url(%23g)'/%3E%3Cpath d='M160 255s-78-45-78-108c0-28 21-49 48-49 17 0 31 8 40 21 9-13 23-21 40-21 27 0 48 21 48 49 0 63-78 108-78 108z' fill='white' opacity='.9'/%3E%3Ccircle cx='160' cy='146' r='18' fill='%2318a36f'/%3E%3C/svg%3E",
    ],
    [
        'title' => 'Forms',
        'description' => 'Formularios operativos para capturar informacion de manera estructurada.',
        'url' => 'https://www.noetica.cl/form2/index.php?path=/login',
        'tag' => 'Captura de datos',
        'accent' => '#9b5de5',
        'image' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 320 320'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0%25' stop-color='%239b5de5'/%3E%3Cstop offset='100%25' stop-color='%23d7b9ff'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='320' height='320' rx='40' fill='url(%23g)'/%3E%3Crect x='72' y='56' width='176' height='208' rx='24' fill='rgba(255,255,255,0.88)'/%3E%3Cpath d='M106 120h108M106 156h108M106 192h68' stroke='%239b5de5' stroke-width='16' stroke-linecap='round'/%3E%3Ccircle cx='214' cy='194' r='18' fill='%239b5de5'/%3E%3C/svg%3E",
    ],
    [
        'title' => 'Feedback',
        'description' => 'Comentarios, seguimiento y administracion de respuestas del equipo.',
        'url' => 'https://www.noetica.cl/feedback/admin/login.php',
        'tag' => 'Escucha activa',
        'accent' => '#f97316',
        'image' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 320 320'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0%25' stop-color='%23f97316'/%3E%3Cstop offset='100%25' stop-color='%23ffd08a'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='320' height='320' rx='40' fill='url(%23g)'/%3E%3Cpath d='M76 86h168c18 0 32 14 32 32v66c0 18-14 32-32 32h-79l-49 34v-34H76c-18 0-32-14-32-32v-66c0-18 14-32 32-32z' fill='rgba(255,255,255,0.9)'/%3E%3Cpath d='M103 126h114M103 159h85' stroke='%23f97316' stroke-width='16' stroke-linecap='round'/%3E%3C/svg%3E",
    ],
];
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

    .portal-shell {
      position: relative;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(13,110,253,0.12), transparent 28%),
        linear-gradient(135deg, rgba(255,255,255,0.98), rgba(243,247,255,0.94));
      border: 1px solid rgba(13,110,253,0.12);
      border-radius: 1.5rem;
      box-shadow: 0 24px 55px rgba(15, 23, 42, 0.08);
      padding: 2rem;
    }

    .portal-shell::before,
    .portal-shell::after {
      content: '';
      position: absolute;
      border-radius: 999px;
      pointer-events: none;
      filter: blur(12px);
      opacity: 0.7;
    }

    .portal-shell::before {
      width: 220px;
      height: 220px;
      right: -80px;
      top: -90px;
      background: rgba(13,110,253,0.12);
    }

    .portal-shell::after {
      width: 180px;
      height: 180px;
      left: -70px;
      bottom: -85px;
      background: rgba(24,163,111,0.12);
    }

    .portal-intro {
      position: relative;
      z-index: 2;
      display: grid;
      grid-template-columns: minmax(0, 0.95fr) minmax(320px, 1.05fr);
      gap: 2rem;
      align-items: center;
    }

    .portal-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.35rem 0.8rem;
      border-radius: 999px;
      background: rgba(13,110,253,0.08);
      color: #0d6efd;
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .portal-title {
      margin: 1rem 0 0.75rem;
      font-size: clamp(2rem, 4vw, 3.35rem);
      line-height: 0.98;
      font-weight: 800;
      max-width: 11ch;
    }

    .portal-copy {
      color: #475569;
      font-size: 1.02rem;
      max-width: 52ch;
      margin-bottom: 1rem;
    }

    .ceo-visual-hero {
      position: relative;
      max-width: 540px;
      margin: 1.15rem 0 1.4rem;
      padding: 0.8rem;
      border-radius: 1.35rem;
      background:
        radial-gradient(circle at 18% 18%, rgba(13,110,253,0.14), transparent 34%),
        radial-gradient(circle at 82% 26%, rgba(24,163,111,0.13), transparent 30%),
        rgba(255,255,255,0.72);
      border: 1px solid rgba(13,110,253,0.12);
      box-shadow: 0 18px 38px rgba(15,23,42,0.08);
      overflow: hidden;
    }

    .ceo-visual-hero::before {
      content: '';
      position: absolute;
      inset: 10px;
      border-radius: 1rem;
      border: 1px solid rgba(255,255,255,0.7);
      pointer-events: none;
    }

    .ceo-visual-hero svg {
      position: relative;
      z-index: 1;
      display: block;
      width: 100%;
      height: auto;
    }

    .ceo-visual-line {
      stroke-dasharray: 6 7;
      animation: ceoFlow 14s linear infinite;
    }

    .ceo-visual-pulse {
      transform-origin: center;
      animation: ceoPulse 3.8s ease-in-out infinite;
    }

    @keyframes ceoFlow {
      to { stroke-dashoffset: -120; }
    }

    @keyframes ceoPulse {
      0%, 100% { opacity: 0.72; transform: scale(1); }
      50% { opacity: 1; transform: scale(1.03); }
    }

    .portal-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .portal-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      border-radius: 999px;
      padding: 0.55rem 0.85rem;
      background: #fff;
      color: #334155;
      border: 1px solid rgba(13,110,253,0.12);
      box-shadow: 0 8px 16px rgba(15, 23, 42, 0.04);
      font-size: 0.92rem;
    }

    .app-deck-wrap {
      position: relative;
      min-height: 540px;
      display: grid;
      align-items: center;
      justify-items: center;
    }

    .deck-orbit {
      position: absolute;
      inset: 9% 11%;
      border: 1px dashed rgba(13,110,253,0.18);
      border-radius: 2rem;
      pointer-events: none;
    }

    .deck-controls {
      position: absolute;
      left: 1.25rem;
      right: 1.25rem;
      bottom: 1rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      z-index: 3;
      pointer-events: none;
    }

    .deck-nav,
    .deck-link {
      pointer-events: auto;
    }

    .deck-nav {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      border: 1px solid rgba(13,110,253,0.12);
      background: rgba(255,255,255,0.92);
      color: #0f172a;
      font-size: 1.3rem;
      box-shadow: 0 12px 24px rgba(15,23,42,0.08);
    }

    .deck-nav:hover {
      background: #fff;
      color: #0d6efd;
    }

    .deck-status {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.45rem 0.85rem;
      border-radius: 999px;
      background: rgba(15,23,42,0.78);
      color: #fff;
      font-size: 0.85rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      box-shadow: 0 10px 20px rgba(15,23,42,0.18);
    }

    .app-deck {
      position: relative;
      width: min(100%, 420px);
      height: 500px;
      touch-action: pan-y;
    }

    .app-card {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 1.1rem;
      border-radius: 1.8rem;
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(255,255,255,0.65);
      box-shadow: 0 18px 42px rgba(15, 23, 42, 0.14);
      backdrop-filter: blur(14px);
      text-decoration: none;
      color: inherit;
      transform-origin: center center;
      transition: transform 0.55s cubic-bezier(.22,1,.36,1), opacity 0.4s ease, box-shadow 0.35s ease;
      overflow: hidden;
      isolation: isolate;
    }

    .app-card::after {
      content: '';
      position: absolute;
      inset: auto -20% -30% auto;
      width: 170px;
      height: 170px;
      border-radius: 50%;
      background: color-mix(in srgb, var(--card-accent) 22%, white);
      opacity: 0.8;
      filter: blur(10px);
      z-index: -1;
    }

    .app-card:hover {
      box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
    }

    .app-card.is-active {
      cursor: pointer;
    }

    .app-card.is-passive {
      cursor: pointer;
    }

    .app-card-media {
      position: relative;
      aspect-ratio: 1 / 1;
      border-radius: 1.35rem;
      overflow: hidden;
      background: #e2e8f0;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35);
    }

    .app-card-media img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .app-card-body {
      display: grid;
      gap: 0.55rem;
      margin-top: 1rem;
    }

    .app-card-tag {
      display: inline-flex;
      align-items: center;
      width: fit-content;
      max-width: 100%;
      padding: 0.32rem 0.72rem;
      border-radius: 999px;
      background: color-mix(in srgb, var(--card-accent) 12%, white);
      color: var(--card-accent);
      font-size: 0.77rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .app-card h3 {
      margin: 0;
      font-size: 1.65rem;
      font-weight: 800;
    }

    .app-card p {
      margin: 0;
      color: #475569;
      line-height: 1.45;
    }

    .app-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-top: 1.2rem;
      font-weight: 700;
      color: #0f172a;
    }

    .app-card-arrow {
      width: 2.5rem;
      height: 2.5rem;
      display: grid;
      place-items: center;
      border-radius: 50%;
      background: color-mix(in srgb, var(--card-accent) 16%, white);
      color: var(--card-accent);
      font-size: 1.2rem;
    }

    .deck-link {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      border-radius: 999px;
      padding: 0.7rem 1rem;
      background: #fff;
      color: #0f172a;
      text-decoration: none;
      border: 1px solid rgba(13,110,253,0.12);
      box-shadow: 0 12px 24px rgba(15,23,42,0.08);
      font-weight: 700;
    }

    .deck-link:hover {
      color: #0d6efd;
    }

    .deck-dots {
      display: flex;
      gap: 0.45rem;
      justify-content: center;
      margin-top: 1.2rem;
    }

    .deck-dot {
      width: 10px;
      height: 10px;
      border: 0;
      border-radius: 999px;
      background: rgba(13,110,253,0.18);
      transition: transform 0.25s ease, background-color 0.25s ease;
      padding: 0;
    }

    .deck-dot.is-active {
      background: #0d6efd;
      transform: scale(1.35);
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

    @media (max-width: 991.98px) {
      .portal-intro {
        grid-template-columns: 1fr;
      }

      .portal-title {
        max-width: none;
      }

      .app-deck-wrap {
        min-height: 500px;
      }
    }

    @media (max-width: 767.98px) {
      main {
        padding: 1.25rem 0.75rem 2rem;
      }

      .portal-shell {
        padding: 1.2rem;
        border-radius: 1.25rem;
      }

      .portal-title {
        font-size: 2rem;
      }

      .portal-copy {
        font-size: 0.96rem;
      }

      .app-deck-wrap {
        min-height: 460px;
      }

      .app-deck {
        height: 420px;
        width: min(100%, 330px);
      }

      .deck-orbit {
        inset: 8% 4%;
      }

      .deck-controls {
        position: static;
        margin-top: 1rem;
        gap: 0.75rem;
        justify-content: center;
        flex-wrap: wrap;
      }
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
    <section class="portal-shell">
      <div class="portal-intro">
        <div>
          <span class="portal-kicker">Portal de aplicativos</span>
          <h1 class="portal-title">Acceso visual al ecosistema CEO</h1>
          <p class="portal-copy">Centro de control operacional para permisos, habilitaciones, evaluaciones y gestion documental.</p>
          <div class="ceo-visual-hero" aria-label="Resumen visual CEONext">
            <svg viewBox="0 0 620 300" role="img" aria-labelledby="ceoVisualTitle">
              <title id="ceoVisualTitle">Mapa visual de procesos CEONext</title>
              <defs>
                <linearGradient id="ceoBlue" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#0d6efd" />
                  <stop offset="1" stop-color="#6aa5ff" />
                </linearGradient>
                <linearGradient id="ceoGreen" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#18a36f" />
                  <stop offset="1" stop-color="#7dd9b0" />
                </linearGradient>
                <filter id="ceoShadow" x="-20%" y="-20%" width="140%" height="150%">
                  <feDropShadow dx="0" dy="12" stdDeviation="12" flood-color="#0f172a" flood-opacity="0.14" />
                </filter>
              </defs>

              <rect x="18" y="20" width="584" height="260" rx="34" fill="#f8fbff" opacity="0.78" />
              <path class="ceo-visual-line" d="M168 90 C230 46 374 46 454 96" fill="none" stroke="#0d6efd" stroke-width="3" opacity="0.34" />
              <path class="ceo-visual-line" d="M168 208 C250 254 378 248 454 202" fill="none" stroke="#18a36f" stroke-width="3" opacity="0.34" />
              <path class="ceo-visual-line" d="M172 150 H448" fill="none" stroke="#64748b" stroke-width="3" opacity="0.24" />

              <g filter="url(#ceoShadow)">
                <rect x="232" y="76" width="156" height="148" rx="28" fill="url(#ceoBlue)" />
                <circle class="ceo-visual-pulse" cx="310" cy="126" r="30" fill="rgba(255,255,255,0.24)" />
                <path d="M286 130h48M292 150h36M300 170h20" stroke="#fff" stroke-width="10" stroke-linecap="round" opacity="0.92" />
                <text x="310" y="205" text-anchor="middle" fill="#fff" font-size="24" font-weight="800">CEONext</text>
              </g>

              <g filter="url(#ceoShadow)">
                <rect x="54" y="58" width="132" height="68" rx="20" fill="#ffffff" />
                <circle cx="84" cy="92" r="15" fill="#dbeafe" />
                <path d="M79 92l4 5 9-12" fill="none" stroke="#0d6efd" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                <text x="108" y="88" fill="#0f172a" font-size="14" font-weight="700">Permisos</text>
                <text x="108" y="106" fill="#64748b" font-size="11">Control por rol</text>
              </g>

              <g filter="url(#ceoShadow)">
                <rect x="54" y="174" width="132" height="68" rx="20" fill="#ffffff" />
                <circle cx="84" cy="208" r="15" fill="#dcfce7" />
                <path d="M75 209h18M84 200v18" stroke="#18a36f" stroke-width="4" stroke-linecap="round" />
                <text x="108" y="204" fill="#0f172a" font-size="14" font-weight="700">Evaluaciones</text>
                <text x="108" y="222" fill="#64748b" font-size="11">Notas y estado</text>
              </g>

              <g filter="url(#ceoShadow)">
                <rect x="434" y="58" width="132" height="68" rx="20" fill="#ffffff" />
                <circle cx="464" cy="92" r="15" fill="#fef3c7" />
                <path d="M457 92h14M464 85v14" stroke="#d97706" stroke-width="4" stroke-linecap="round" />
                <text x="488" y="88" fill="#0f172a" font-size="14" font-weight="700">Habilitacion</text>
                <text x="488" y="106" fill="#64748b" font-size="11">Vigencias</text>
              </g>

              <g filter="url(#ceoShadow)">
                <rect x="434" y="174" width="132" height="68" rx="20" fill="#ffffff" />
                <circle cx="464" cy="208" r="15" fill="#e0f2fe" />
                <path d="M458 199h13l7 7v13h-20z" fill="none" stroke="#0284c7" stroke-width="3" stroke-linejoin="round" />
                <text x="488" y="204" fill="#0f172a" font-size="14" font-weight="700">Documentos</text>
                <text x="488" y="222" fill="#64748b" font-size="11">Trazabilidad</text>
              </g>
            </svg>
          </div>
          <div class="portal-meta">
            <span class="portal-pill">Permisos y habilitaciones</span>
            <span class="portal-pill">Evaluaciones y vigencias</span>
            <span class="portal-pill">Documentos y trazabilidad</span>
          </div>
        </div>

        <div class="app-deck-wrap">
          <div class="deck-orbit" aria-hidden="true"></div>
          <div class="app-deck" id="appDeck" aria-label="Aplicativos CEO">
            <?php foreach ($apps as $index => $app): ?>
              <a
                class="app-card"
                href="<?= htmlspecialchars($app['url'], ENT_QUOTES, 'UTF-8') ?>"
                data-index="<?= $index ?>"
                data-title="<?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?>"
                style="--card-accent: <?= htmlspecialchars($app['accent'], ENT_QUOTES, 'UTF-8') ?>"
                aria-label="Abrir <?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?>"
              >
                <div class="app-card-media">
                  <img src="<?= $app['image'] ?>" alt="Imagen referencial de <?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="app-card-body">
                  <span class="app-card-tag"><?= htmlspecialchars($app['tag'], ENT_QUOTES, 'UTF-8') ?></span>
                  <h3><?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <p><?= htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="app-card-footer">
                  <span>Ingresar</span>
                  <span class="app-card-arrow" aria-hidden="true">&rarr;</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="deck-controls">
            <button class="deck-nav" id="deckPrev" type="button" aria-label="Tarjeta anterior">&larr;</button>
            <div class="deck-status"><span id="deckCount">01</span> / <?= str_pad((string)count($apps), 2, '0', STR_PAD_LEFT) ?></div>
            <a class="deck-link" id="deckDirectLink" href="<?= htmlspecialchars($apps[0]['url'], ENT_QUOTES, 'UTF-8') ?>">Abrir <span id="deckCurrentTitle"><?= htmlspecialchars($apps[0]['title'], ENT_QUOTES, 'UTF-8') ?></span></a>
            <button class="deck-nav" id="deckNext" type="button" aria-label="Siguiente tarjeta">&rarr;</button>
          </div>
        </div>
      </div>

      <div class="deck-dots" id="deckDots" aria-label="Seleccion de aplicativo">
        <?php foreach ($apps as $index => $app): ?>
          <button class="deck-dot<?= $index === 0 ? ' is-active' : '' ?>" type="button" data-index="<?= $index ?>" aria-label="Ir a <?= htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8') ?>"></button>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <!-- FOOTER -->
  <footer>
  <?= APP_FOOTER ?>
</footer>

  <script>
    (() => {
      const deck = document.getElementById('appDeck');
      if (!deck) return;

      const cards = Array.from(deck.querySelectorAll('.app-card'));
      const prev = document.getElementById('deckPrev');
      const next = document.getElementById('deckNext');
      const dots = Array.from(document.querySelectorAll('.deck-dot'));
      const count = document.getElementById('deckCount');
      const currentTitle = document.getElementById('deckCurrentTitle');
      const directLink = document.getElementById('deckDirectLink');

      let active = 0;
      let autoplay = null;
      let touchStartX = 0;
      let touchDeltaX = 0;

      const total = cards.length;

      function distanceFor(index) {
        let distance = index - active;
        if (distance > total / 2) distance -= total;
        if (distance < -total / 2) distance += total;
        return distance;
      }

      function render() {
        cards.forEach((card, index) => {
          const distance = distanceFor(index);
          const abs = Math.abs(distance);
          const translateX = distance * 84;
          const translateY = abs * 18;
          const rotate = distance * 7;
          const scale = 1 - abs * 0.08;
          const opacity = abs > 2 ? 0 : 1 - abs * 0.18;
          const zIndex = total - abs;

          card.style.transform = `translate3d(${translateX}px, ${translateY}px, 0) rotate(${rotate}deg) scale(${scale})`;
          card.style.opacity = String(opacity);
          card.style.zIndex = String(zIndex);
          card.classList.toggle('is-active', distance === 0);
          card.classList.toggle('is-passive', distance !== 0);
          card.setAttribute('aria-hidden', distance === 0 ? 'false' : 'true');
          card.tabIndex = distance === 0 ? 0 : -1;
        });

        dots.forEach((dot, index) => {
          dot.classList.toggle('is-active', index === active);
        });

        const selected = cards[active];
        count.textContent = String(active + 1).padStart(2, '0');
        currentTitle.textContent = selected.dataset.title || '';
        directLink.href = selected.href;
      }

      function setActive(index) {
        active = (index + total) % total;
        render();
      }

      function startAutoplay() {
        stopAutoplay();
        autoplay = window.setInterval(() => setActive(active + 1), 4200);
      }

      function stopAutoplay() {
        if (autoplay) {
          window.clearInterval(autoplay);
          autoplay = null;
        }
      }

      prev?.addEventListener('click', () => {
        setActive(active - 1);
        startAutoplay();
      });

      next?.addEventListener('click', () => {
        setActive(active + 1);
        startAutoplay();
      });

      dots.forEach((dot) => {
        dot.addEventListener('click', () => {
          setActive(Number(dot.dataset.index || 0));
          startAutoplay();
        });
      });

      cards.forEach((card, index) => {
        card.addEventListener('click', (event) => {
          if (index !== active) {
            event.preventDefault();
            setActive(index);
            startAutoplay();
          }
        });
      });

      deck.addEventListener('touchstart', (event) => {
        touchStartX = event.changedTouches[0].clientX;
        touchDeltaX = 0;
        stopAutoplay();
      }, { passive: true });

      deck.addEventListener('touchmove', (event) => {
        touchDeltaX = event.changedTouches[0].clientX - touchStartX;
      }, { passive: true });

      deck.addEventListener('touchend', () => {
        if (Math.abs(touchDeltaX) > 40) {
          setActive(active + (touchDeltaX < 0 ? 1 : -1));
        }
        startAutoplay();
      });

      deck.addEventListener('mouseenter', stopAutoplay);
      deck.addEventListener('mouseleave', startAutoplay);
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          stopAutoplay();
        } else {
          startAutoplay();
        }
      });

      render();
      startAutoplay();
    })();
  </script>


</body>
</html>
