<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
$_SESSION['LAST_ACTIVITY'] = time();
echo json_encode(['ok' => true, 'ts' => time()]);
