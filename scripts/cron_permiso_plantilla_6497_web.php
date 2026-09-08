<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

if (!function_exists('cronLog')) {
    function cronLog(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] ' . $message . PHP_EOL;

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $line);
            return;
        }

        echo $line;
        if (function_exists('flush')) {
            flush();
        }
    }
}

cronLog('Ejecutando wrapper web: ' . __FILE__);

// El cron original bloquea REQUEST_METHOD para impedir ejecucion web.
// Esta copia web lo remueve antes de reutilizar exactamente la misma logica.
unset($_SERVER['REQUEST_METHOD']);

require __DIR__ . '/cron_permiso_plantilla_6497.php';
