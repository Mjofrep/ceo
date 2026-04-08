<?php
// --------------------------------------------------
// auth.php - Control centralizado de sesión CEO
// --------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

// ⏱️ Tiempo máximo de inactividad (en segundos)
$MAX_IDLE = 60 * 60; // 60 minutos

// ❌ No está logueado
if (empty($_SESSION['auth'])) {
    session_destroy();
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

// ⏱️ Control de inactividad
$ahora = time();

if (isset($_SESSION['LAST_ACTIVITY']) && ($ahora - $_SESSION['LAST_ACTIVITY']) > $MAX_IDLE) {
    if (function_exists('auditLog')) {
        auditLog('SESSION_TIMEOUT', 'session', null, [
            'motivo' => 'inactividad',
            'url' => $_SERVER['REQUEST_URI'] ?? ''
        ]);
    }
    session_unset();
    session_destroy();
    header('Location: /ceo.noetica.cl/config/index.php?timeout=1');
    exit;
}

// ✔️ Actualiza actividad
$_SESSION['LAST_ACTIVITY'] = $ahora;

if (function_exists('auditLog')) {
    auditLog('PAGE_ACCESS', 'page', null, [
        'path' => $_SERVER['PHP_SELF'] ?? '',
        'query_keys' => !empty($_GET) ? array_keys($_GET) : [],
        'post_keys' => !empty($_POST) ? array_keys($_POST) : [],
        'ajax' => (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') ? 'S' : 'N'
    ]);
}

// 🔒 Evita cache (Back/Forward)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
