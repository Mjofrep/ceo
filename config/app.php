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
define('APP_BASE', '/ceo.noetica.cl');
define('APP_LOGO', APP_BASE . '/config/assets/ceonext.png'); // ruta al nuevo logo
define('APP_FAVICON', APP_BASE . '/config/assets/favicon.ico'); // opcional
define('APP_FOOTER', '© ' . date('Y') . ' CEONext — Centro de Excelencia Operacional de Enel');
define('APP_DEBUG', false); // ponlo en false en producción

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = rtrim(APP_BASE, '/');
        $path = trim($path);
        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('app_abs_url')) {
    function app_abs_url(string $path = ''): string
    {
        $relative = app_url($path);
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return $relative;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $relative;
    }
}

if (!function_exists('app_path_to_filesystem')) {
    function app_path_to_filesystem(string $path): string
    {
        $normalized = parse_url($path, PHP_URL_PATH);
        $normalized = is_string($normalized) ? $normalized : $path;
        $normalized = '/' . ltrim($normalized, '/');

        if (strncmp($normalized, APP_BASE . '/', strlen(APP_BASE) + 1) === 0 || $normalized === APP_BASE) {
            $normalized = substr($normalized, strlen(APP_BASE));
        }

        return dirname(__DIR__) . '/' . ltrim($normalized, '/');
    }
}
