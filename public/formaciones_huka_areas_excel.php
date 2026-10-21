<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/formaciones_huka_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/public/index.php');
    exit;
}

function fhaExcelColumn(int $column): string
{
    $letters = '';
    while ($column > 0) {
        $column--;
        $letters = chr(65 + ($column % 26)) . $letters;
        $column = intdiv($column, 26);
    }
    return $letters;
}

$summary = fhFetchAreaSummary(db(), fhFilters($_GET));
$areas = $summary['areas'];
$participants = $summary['participants'];
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Áreas HUKA');

$headers = ['RUT', 'Empresa', 'Nombre / Apellido', 'Nota'];
foreach ($areas as $area) {
    $headers[] = (string)$area['area'];
}
$sheet->fromArray([$headers], null, 'A1');
$lastColumn = fhaExcelColumn(count($headers));
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF2FB']],
]);

$line = 2;
foreach ($participants as $participant) {
    $values = [$participant['rut'], $participant['empresa'], $participant['nombre_completo'], $participant['nota_final']];
    foreach ($areas as $area) {
        $areaData = $participant['areas'][(string)(int)$area['id_area']] ?? null;
        $values[] = $areaData ? $areaData['nota'] . PHP_EOL . $areaData['porcentaje'] . '%' : '';
    }
    $sheet->fromArray([$values], null, 'A' . $line);
    foreach ($areas as $index => $area) {
        $areaData = $participant['areas'][(string)(int)$area['id_area']] ?? null;
        if ($areaData) {
            $column = fhaExcelColumn($index + 5);
            $sheet->getStyle($column . $line)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($areaData['aprobada'] ? 'D1E7DD' : 'F8D7DA');
        }
    }
    $line++;
}

foreach (range(1, count($headers)) as $column) {
    $sheet->getColumnDimension(fhaExcelColumn($column))->setAutoSize(true);
}
$sheet->getStyle('A1:' . $lastColumn . max(1, $line - 1))->getAlignment()->setWrapText(true);
$sheet->freezePane('A2');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="formaciones_huka_areas_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
