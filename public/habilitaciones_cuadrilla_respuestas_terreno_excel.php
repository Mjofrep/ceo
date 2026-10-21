<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';

$areasLibrary = __DIR__ . '/habilitaciones_cuadrilla_areas_lib.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($areasLibrary, true);
}
require_once $areasLibrary;
require_once __DIR__ . '/habilitaciones_cuadrilla_areas_batch_lib.php';
require_once __DIR__ . '/habilitaciones_cuadrilla_respuestas_terreno_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    exit('No autorizado.');
}

try {
    $pdo = db();
    $headersSelected = hcaHeadersFromRequest($pdo, $_GET);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    exit($e->getMessage());
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

$multiple = count($headersSelected) > 1;
$responses = hcrtrTerrainResponses($pdo, $headersSelected);
$header = $headersSelected[0];
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Preguntas terreno');

$columns = ['RUT', 'Empresa', 'Nombre', 'Apellido', 'Área de competencia', 'Pregunta', 'Ponderación', 'Resultado'];
if ($multiple) {
    array_unshift($columns, 'Cuadrilla');
}
$lastColumn = hcaExcelColumn(count($columns));
$sheet->setCellValue('A1', 'Revisión de preguntas de terreno');
$sheet->mergeCells('A1:' . $lastColumn . '1');
$sheet->setCellValue('A2', 'Servicio');
$sheet->setCellValue('B2', (string)$header['servicio']);
$sheet->setCellValue('A3', 'Cuadrilla' . ($multiple ? 's' : ''));
$sheet->setCellValue('B3', implode(', ', array_map(static fn(array $item): string => (string)$item['cuadrilla'], $headersSelected)));
$sheet->setCellValue('A4', 'Jornada');
$sheet->setCellValue('B4', $multiple ? 'Varias' : (string)$header['jornada']);
$sheet->fromArray([$columns], null, 'A6');

$sheet->getStyle('A1:A4')->getFont()->setBold(true);
$sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
$sheet->getStyle('A6:' . $lastColumn . '6')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
]);

$rowNumber = 7;
foreach ($responses as $response) {
    $values = [
        $response['rut'],
        $response['empresa'],
        $response['nombre'],
        $response['apellidos'],
        $response['area_competencia'],
        $response['pregunta'],
        $response['ponderacion'],
        $response['resultado'],
    ];
    if ($multiple) {
        array_unshift($values, $response['cuadrilla']);
    }
    $sheet->fromArray([$values], null, 'A' . $rowNumber);
    if ((int)$response['no_cumple'] === 1) {
        $sheet->getStyle('A' . $rowNumber . ':' . $lastColumn . $rowNumber)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
        ]);
    }
    $rowNumber++;
}

$lastRow = max(6, $rowNumber - 1);
$sheet->setAutoFilter('A6:' . $lastColumn . $lastRow);
$sheet->freezePane('A7');
$sheet->getStyle('A6:' . $lastColumn . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);

$widths = [16, 25, 20, 20, 28, 65, 14, 16];
if ($multiple) {
    array_unshift($widths, 14);
}
foreach ($widths as $index => $width) {
    $sheet->getColumnDimension(hcaExcelColumn($index + 1))->setWidth($width);
}

$filename = 'habilitaciones_preguntas_terreno_' . ($multiple ? 'consolidado' : (int)$header['cuadrilla']) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
