<?php
// --------------------------------------------------
// auth.php - Control centralizado de sesión CEO
// --------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ⏱️ Tiempo máximo de inactividad (en segundos)
$MAX_IDLE = 15 * 60; // 15 minutos

// ❌ No está logueado
if (empty($_SESSION['auth'])) {
    session_destroy();
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

// ⏱️ Control de inactividad
$ahora = time();

if (isset($_SESSION['LAST_ACTIVITY']) && ($ahora - $_SESSION['LAST_ACTIVITY']) > $MAX_IDLE) {
    session_unset();
    session_destroy();
    header('Location: /ceo.noetica.cl/config/index.php?timeout=1');
    exit;
}

// ✔️ Actualiza actividad
$_SESSION['LAST_ACTIVITY'] = $ahora;

// 🔒 Evita cache (Back/Forward)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");