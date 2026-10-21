<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$areasLibrary = __DIR__ . '/habilitaciones_cuadrilla_areas_lib.php';
if (function_exists('opcache_invalidate')) { opcache_invalidate($areasLibrary, true); }
require_once $areasLibrary;
require_once __DIR__ . '/habilitaciones_cuadrilla_areas_batch_lib.php';
if (empty($_SESSION['auth'])) { http_response_code(401); exit('No autorizado.'); }
$pdo = db();
try { $headers = hcaHeadersFromRequest($pdo, $_GET); } catch (OutOfBoundsException $e) { http_response_code(404); exit($e->getMessage()); } catch (InvalidArgumentException $e) { http_response_code(400); exit($e->getMessage()); }
hcaExportSummary($headers[0], hcaCombinedSummary($pdo, $headers, 'terreno'), 'terreno', $headers);
