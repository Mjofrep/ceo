<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/formaciones_huka_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

try {
    $pdo = db();
    $data = fhFetchAreaSummary($pdo, fhFilters($_GET));
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible obtener el resumen por áreas.']);
}
