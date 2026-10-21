<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ranking_errores_preguntas_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$modality = (string)($_GET['modalidad'] ?? 'habilitacion');
$serviceId = (int)($_GET['id_servicio'] ?? 0);
$type = (string)($_GET['tipo'] ?? 'teorica');
if (!in_array($modality, ['habilitacion', 'formacion'], true) || !in_array($type, ['teorica', 'terreno'], true) || $serviceId <= 0) {
    http_response_code(400);
    exit('Parámetros de consulta inválidos.');
}
if ($modality === 'formacion') {
    $type = 'teorica';
}

$role = strtolower(trim((string)($_SESSION['auth']['rol'] ?? '')));
$companyId = $role === 'contratista' ? (int)($_SESSION['auth']['id_empresa'] ?? 0) : 0;

try {
    $report = repBuildRanking(db(), $modality, $serviceId, $companyId, $type);
} catch (Throwable $e) {
    http_response_code(500);
    exit('No fue posible generar el ranking de errores.');
}

function repExcelConfigureSheet($sheet, string $title, string $modality, string $type, string $service, array $headers): void
{
    $lastColumn = chr(64 + count($headers));
    $sheet->setTitle($title);
    $sheet->setCellValue('A1', 'Ranking de preguntas con mayor error');
    $sheet->mergeCells('A1:' . $lastColumn . '1');
    $sheet->setCellValue('A2', 'Modalidad');
    $sheet->setCellValue('B2', $modality);
    $sheet->setCellValue('A3', 'Servicio');
    $sheet->setCellValue('B3', $service);
    $sheet->setCellValue('A4', 'Tipo');
    $sheet->setCellValue('B4', $type);
    $sheet->fromArray([$headers], null, 'A6');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2:A4')->getFont()->setBold(true);
    $sheet->getStyle('A6:' . $lastColumn . '6')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
    ]);
    $sheet->freezePane('A7');
}

function repExcelFinishSheet($sheet, int $lastRow, string $lastColumn, array $widths): void
{
    $sheet->setAutoFilter('A6:' . $lastColumn . max(6, $lastRow));
    $sheet->getStyle('A6:' . $lastColumn . max(6, $lastRow))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    foreach ($widths as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }
}

$modalityLabel = $modality === 'formacion' ? 'Formaciones' : 'Habilitaciones';
$typeLabel = $type === 'terreno' ? 'Terreno' : 'Teórica';
$isTerrain = $type === 'terreno';
$topRows = array_slice($report['rows'], 0, 10);
$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summaryHeaders = ['#', 'ID pregunta', 'Área de competencia', 'Pregunta', 'Incorrectas', '% error'];
repExcelConfigureSheet($summary, 'Ranking errores', $modalityLabel, $typeLabel, (string)$report['servicio'], $summaryHeaders);
$summary->setCellValue('A5', 'Top 10 por cantidad de respuestas incorrectas');
$summary->getStyle('A5')->getFont()->setBold(true);

$rowNumber = 7;
foreach ($topRows as $row) {
    $summary->fromArray([[
        $row['ranking'],
        $row['id_pregunta'],
        $row['area_competencia'],
        $row['pregunta'],
        $row['incorrectas'],
        $row['porcentaje_error'] / 100,
    ]], null, 'A' . $rowNumber);
    $summary->getStyle('E' . $rowNumber . ':F' . $rowNumber)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
    ]);
    $summary->getStyle('F' . $rowNumber)->getNumberFormat()->setFormatCode('0.0%');
    $rowNumber++;
}
$topLastRow = max(6, $rowNumber - 1);
$summary->getStyle('A6:F' . $topLastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);

$detailTitleRow = $topLastRow + 3;
$detailHeaderRow = $detailTitleRow + 1;
$summary->setCellValue('A' . $detailTitleRow, 'Detalle completo del ranking');
$summary->getStyle('A' . $detailTitleRow)->getFont()->setBold(true);
$detailHeaders = $isTerrain
    ? ['#', 'ID pregunta', 'Área de competencia', 'Pregunta', 'Ponderación', 'Respondidas', 'Cumple', 'No cumple', 'No aplica', '% error']
    : ['#', 'ID pregunta', 'Área de competencia', 'Pregunta', 'Alternativas', 'Alternativa más elegida', 'Respondidas', 'Correctas', 'Incorrectas', 'No contestadas', '% error'];
$detailLastColumn = $isTerrain ? 'J' : 'K';
$summary->fromArray([$detailHeaders], null, 'A' . $detailHeaderRow);
$summary->getStyle('A' . $detailHeaderRow . ':' . $detailLastColumn . $detailHeaderRow)->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
]);

$rowNumber = $detailHeaderRow + 1;
foreach ($report['rows'] as $row) {
    $values = $isTerrain
        ? [$row['ranking'], $row['id_pregunta'], $row['area_competencia'], $row['pregunta'], $row['ponderacion'], $row['respondidas'], $row['correctas'], $row['incorrectas'], $row['no_aplica'], $row['porcentaje_error'] / 100]
        : [$row['ranking'], $row['id_pregunta'], $row['area_competencia'], $row['pregunta'], $row['alternativas'], $row['alternativa_habitual'], $row['respondidas'], $row['correctas'], $row['incorrectas'], $row['no_contestadas'], $row['porcentaje_error'] / 100];
    $summary->fromArray([$values], null, 'A' . $rowNumber);
    $errorColumns = $isTerrain ? 'H' . $rowNumber . ':J' . $rowNumber : 'I' . $rowNumber . ':K' . $rowNumber;
    $summary->getStyle($errorColumns)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
    ]);
    $summary->getStyle(($isTerrain ? 'J' : 'K') . $rowNumber)->getNumberFormat()->setFormatCode('0.0%');
    $rowNumber++;
}
$detailLastRow = max($detailHeaderRow, $rowNumber - 1);
$summary->setAutoFilter('A' . $detailHeaderRow . ':' . $detailLastColumn . $detailLastRow);
$summary->getStyle('A' . $detailHeaderRow . ':' . $detailLastColumn . $detailLastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);
foreach ($isTerrain
    ? ['A' => 8, 'B' => 14, 'C' => 28, 'D' => 65, 'E' => 14, 'F' => 14, 'G' => 12, 'H' => 14, 'I' => 14, 'J' => 12]
    : ['A' => 8, 'B' => 14, 'C' => 28, 'D' => 65, 'E' => 70, 'F' => 35, 'G' => 14, 'H' => 12, 'I' => 14, 'J' => 17, 'K' => 12] as $column => $width) {
    $summary->getColumnDimension($column)->setWidth($width);
}

$filename = 'ranking_errores_' . $modality . '_' . $type . '_' . $serviceId . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
