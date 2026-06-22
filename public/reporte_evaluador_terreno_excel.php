<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/reporte_evaluador_terreno_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$pdo = db();
$filters = retBuildFilters();
$rows = retFetchRows($pdo, $filters);

$excel = new Spreadsheet();
$sheet = $excel->getActiveSheet();
$sheet->setTitle('Eval Terreno');

$sheet->setCellValue('A1', 'Reporte Evaluaciones de Terreno por Evaluador');
$sheet->mergeCells('A1:I1');
$sheet->setCellValue('A2', 'Fecha desde');
$sheet->setCellValue('B2', $filters['fecha_desde']);
$sheet->setCellValue('C2', 'Fecha hasta');
$sheet->setCellValue('D2', $filters['fecha_hasta']);
$sheet->setCellValue('E2', 'Evaluador');
$sheet->setCellValue('F2', !empty($filters['es_admin']) ? ((int)$filters['id_evaluador'] > 0 ? (string)$filters['id_evaluador'] : 'Todos') : trim((string)($_SESSION['auth']['nombre'] ?? '')));
$sheet->setCellValue('G2', 'RUT');
$sheet->setCellValue('H2', $filters['rut'] !== '' ? $filters['rut'] : 'Todos');

$headers = ['Fecha', 'Hora', 'Evaluador', 'Cuadrilla', 'Proceso', 'Servicio', 'RUT', 'Nombre', 'Empresa'];
$rowExcel = 4;
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $rowExcel, $header);
    $col++;
}

$sheet->getStyle('A1:I1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A4:I4')->getFont()->setBold(true);
$sheet->getStyle('A4:I4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('EAF2FB');
$sheet->getStyle('A4:I4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$rowExcel = 5;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $rowExcel, (string)($row['fecha_rendicion'] ?? ''));
    $sheet->setCellValue('B' . $rowExcel, (string)($row['hora_rendicion'] ?? ''));
    $sheet->setCellValue('C' . $rowExcel, trim((string)($row['evaluador'] ?? '')));
    $sheet->setCellValue('D' . $rowExcel, (string)($row['cuadrilla'] ?? ''));
    $sheet->setCellValue('E' . $rowExcel, (string)($row['numero_proceso'] ?? ''));
    $sheet->setCellValue('F' . $rowExcel, (string)($row['servicio'] ?? ''));
    $sheet->setCellValue('G' . $rowExcel, (string)($row['rut'] ?? ''));
    $sheet->setCellValue('H' . $rowExcel, trim((string)($row['nombre'] ?? '') . ' ' . (string)($row['apellidos'] ?? '')));
    $sheet->setCellValue('I' . $rowExcel, (string)($row['empresa'] ?? ''));
    $rowExcel++;
}

$lastRow = max(4, $rowExcel - 1);
$sheet->getStyle('A4:I' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', 'I') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'reporte_evaluador_terreno_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($excel);
$writer->save('php://output');
exit;
