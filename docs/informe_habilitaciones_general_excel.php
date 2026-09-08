<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/informe_habilitaciones_general_lib.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$serviceId = (int)($_GET['servicio'] ?? 0);
$empresaSolicitada = (int)($_GET['empresa'] ?? 0);
$empresaId = ihgResolveSelectedCompanyId($_SESSION['auth'], $empresaSolicitada);
$habilitado = ihgNormalizeHabilitadoFilter((string)($_GET['habilitado'] ?? ''));
$searchTerm = trim((string)($_GET['buscar'] ?? ''));

if (!isset($_GET['consultar'])) {
    http_response_code(400);
    exit('Debe generar el informe antes de exportar.');
}

$report = ihgBuildReport($pdo, [
    'service_id' => $serviceId,
    'empresa_id' => $empresaId,
    'habilitado' => $habilitado,
    'buscar' => $searchTerm,
    'build_all_datasets' => true,
]);

$spreadsheet = new Spreadsheet();
$sheetDefinitions = [
    ['title' => 'data 1', 'columns' => $report['data1_columns'], 'rows' => $report['data1_rows']],
    ['title' => 'data 2', 'columns' => $report['data2_columns'], 'rows' => $report['data2_rows']],
    ['title' => 'data 3', 'columns' => $report['data3_columns'], 'rows' => $report['data3_rows']],
];

foreach ($sheetDefinitions as $index => $definition) {
    $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle($definition['title']);
    $columns = $definition['columns'];
    $rows = $definition['rows'];

    $sheet->freezePane('A2');
    $sheet->fromArray($columns, null, 'A1');
    $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);

    $rowNumber = 2;
    foreach ($rows as $row) {
        $columnIndex = 1;
        foreach ($columns as $columnName) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->setCellValue($columnLetter . $rowNumber, (string)($row[$columnName] ?? ''));
            $columnIndex++;
        }
        $rowNumber++;
    }

    if ($rowNumber > 2) {
        $sheet->getStyle("A2:{$lastColumn}" . ($rowNumber - 1))->applyFromArray([
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }

    for ($i = 1; $i <= count($columns); $i++) {
        $col = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

$spreadsheet->setActiveSheetIndex(0);

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="informe_habilitaciones_general.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
