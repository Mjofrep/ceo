<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/formaciones_cuadrillas_inspectores_lib.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$selections = $_POST['selecciones'] ?? [];
if (!is_array($selections)) {
    $selections = [$selections];
}

if ($selections === []) {
    $cuadrillas = $_POST['cuadrillas'] ?? [];
    if (!is_array($cuadrillas)) {
        $cuadrillas = [$cuadrillas];
    }

    foreach ($cuadrillas as $cuadrilla) {
        $cuadrilla = (int)$cuadrilla;
        if ($cuadrilla <= 0) {
            continue;
        }
        $selections[] = (string)$cuadrilla;
    }
}

try {
    $pdo = db();
    $spreadsheet = fciPrepareExportWorkbook($pdo, $selections);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . fciDownloadFilename() . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        http_response_code(500);
        exit($e->getMessage());
    }

    http_response_code(500);
    exit('No fue posible generar el Excel.');
}
