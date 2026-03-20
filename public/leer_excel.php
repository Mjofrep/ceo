<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json; charset=utf-8');
//require_once '/ceo/vendor/autoload.php'; // Usa PhpSpreadsheet
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    // Validar archivo
    if (empty($_FILES['excel']['tmp_name'])) {
        throw new Exception('No se recibió ningún archivo Excel.');
    }

    $tmpPath = $_FILES['excel']['tmp_name'];

    // Cargar Excel
    $spreadsheet = IOFactory::load($tmpPath);

    // Intentar leer hoja "Solicitud", o usar la activa si no existe
    $sheet = $spreadsheet->getSheetByName('Solicitud') ?? $spreadsheet->getActiveSheet();

    // Leer rango B15:L103
    $data = $sheet->rangeToArray('B15:L103', null, true, false);

    // Eliminar filas completamente vacías
    $rows = array_filter($data, function ($row) {
        return array_filter($row, fn($c) => trim((string)$c) !== '');
    });

    if (empty($rows)) {
        throw new Exception('El archivo Excel no contiene datos válidos en el rango B15:L103.');
    }

    // Responder con los datos leídos
    echo json_encode([
        'ok'   => true,
        'rows' => array_values($rows),
        'mensaje' => 'Archivo leído correctamente.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

