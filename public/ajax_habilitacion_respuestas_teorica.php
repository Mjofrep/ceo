<?php
declare(strict_types=1);

session_start();

$areasLibrary = __DIR__ . '/habilitaciones_cuadrilla_areas_lib.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($areasLibrary, true);
}
require_once $areasLibrary;
require_once __DIR__ . '/habilitaciones_cuadrilla_areas_batch_lib.php';
require_once __DIR__ . '/habilitaciones_cuadrilla_respuestas_teorica_lib.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

try {
    $pdo = db();
    $headers = hcaHeadersFromRequest($pdo, $_GET);
    echo json_encode(['ok' => true, 'data' => hcrtTheoreticalResponses($pdo, $headers)], JSON_UNESCAPED_UNICODE);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo obtener el detalle de respuestas teóricas.']);
}
