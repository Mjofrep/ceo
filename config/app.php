<?php
// /config/app.php
declare(strict_types=1);

/**
 * Configuración global del sistema CEONext
 * -----------------------------------------
 * Centraliza los datos de identidad visual y textual del sistema.
 * Estos valores se pueden usar en cualquier parte del sitio.
 */

define('APP_NAME', 'CEONext');
define('APP_SUBTITLE', 'Centro de Excelencia Operacional — Enel');
define('APP_LOGO', '/ceo.noetica.cl/config/assets/ceonext.png'); // ruta al nuevo logo
define('APP_FAVICON', '/ceo.noetica.cl/config/assets/favicon.ico'); // opcional
define('APP_FOOTER', '© ' . date('Y') . ' CEONext — Centro de Excelencia Operacional de Enel');
define('APP_DEBUG', false); // ponlo en false en producción
define('APP_BASE', '/ceo.noetica.cl');


