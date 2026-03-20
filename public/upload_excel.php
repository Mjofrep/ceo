<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

// Verificar archivo subido
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo correctamente']);
    exit;
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$filename = basename($_FILES['excel_file']['name']);
$targetPath = $uploadDir . '/' . $filename;

// Mover archivo original
if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $targetPath)) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo mover el archivo a uploads/']);
    exit;
}

try {
    $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx','xls'])) {
        $reader = IOFactory::createReaderForFile($targetPath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Solicitud']); // ✅ solo hoja Solicitud
        $spreadsheet = $reader->load($targetPath);
        $sheet = $spreadsheet->getActiveSheet();

        // 🧠 Leer rango A15:L103
        $range = 'A15:L103';
        $data = $sheet->rangeToArray($range, null, true, true, true);

        // Guardar CSV reducido
        $csvFile = $uploadDir . '/ultima_solicitud.csv';
        $fp = fopen($csvFile, 'w');
        foreach ($data as $row) {
            // Cada $row es array asociativo ['A' => ..., 'B' => ..., ..., 'L' => ...]
            fputcsv($fp, array_values($row), ';');
        }
        fclose($fp);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    echo json_encode(['ok' => true, 'file' => $filename]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al procesar rango A15:L103: ' . $e->getMessage()]);
}

