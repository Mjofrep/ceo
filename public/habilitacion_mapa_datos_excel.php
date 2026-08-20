<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/habilitacion_datos_lib.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function habDataToolExcelSheetTitle(string $label): string
{
    $title = preg_replace('/[\\\\\/*\?:\[\]]/', ' ', $label) ?? 'Datos';
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? 'Datos');
    if ($title === '') {
        $title = 'Datos';
    }

    return mb_substr($title, 0, 31, 'UTF-8');
}

function habDataToolExcelFileName(string $table, string $label): string
{
    $base = trim($label) !== '' ? $label : $table;
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        if (is_string($converted) && $converted !== '') {
            $base = $converted;
        }
    }
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $base) ?? $table;
    $base = trim($base, '_');
    if ($base === '') {
        $base = $table;
    }

    return $base . '.xlsx';
}

if ((int)($_SESSION['auth']['id_rol'] ?? 0) !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

set_time_limit(0);

$table = trim((string)($_GET['table'] ?? ''));
$filtersRaw = (string)($_GET['filters'] ?? '');
$filters = [];
if ($filtersRaw !== '') {
    $decoded = json_decode($filtersRaw, true);
    if (is_array($decoded)) {
        $filters = $decoded;
    }
}

if ($table === '') {
    http_response_code(400);
    exit('Tabla requerida.');
}

$pdo = db();
$query = habDataToolBuildQueryContext($pdo, $table, $filters);
$columnsMap = $query['columns_map'];
$columnNames = $query['column_names'];
$whereSql = $query['where_sql'];
$params = $query['params'];
$orderSql = $query['order_sql'];
$label = (string)($query['config']['label'] ?? $table);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(habDataToolExcelSheetTitle($label));
$sheet->freezePane('A2');

$columns = habDataToolBuildColumnsPayload($columnsMap, $query['filter_values']);
$headers = array_map(static fn(array $column): string => (string)$column['label'], $columns);
$sheet->fromArray($headers, null, 'A1');

$lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
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

$sql = 'SELECT * FROM `' . $table . '`' . $whereSql . ' ORDER BY ' . $orderSql;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rowNumber = 2;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $line = [];
    foreach ($columnNames as $columnName) {
        $value = $row[$columnName] ?? '';
        $line[] = $value === null ? '' : (string)$value;
    }
    $sheet->fromArray($line, null, 'A' . $rowNumber);
    $rowNumber++;
}

if ($rowNumber > 2) {
    $sheet->getStyle("A2:{$lastColumn}" . ($rowNumber - 1))->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
}

for ($i = 1; $i <= count($headers); $i++) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . habDataToolExcelFileName($table, $label) . '"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
unset($spreadsheet);
exit;
