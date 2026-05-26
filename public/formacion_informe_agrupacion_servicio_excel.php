<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/formacion_informe_agrupacion_servicio_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$idServicio = (int)($_GET['id_servicio'] ?? 0);
if ($idServicio <= 0) {
    http_response_code(400);
    exit('Servicio invalido.');
}

$pdo = db();
$data = fisFetchReportData($pdo, $idServicio);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Informe');

$sheet->setCellValue('A1', 'Pendientes');
$sheet->setCellValue('B1', 'Aprobadas');
$sheet->setCellValue('C1', 'Reprobadas');
$sheet->setCellValue('A2', (int)($data['summary']['PENDIENTE'] ?? 0));
$sheet->setCellValue('B2', (int)($data['summary']['APROBADA'] ?? 0));
$sheet->setCellValue('C2', (int)($data['summary']['REPROBADA'] ?? 0));

$sheet->fromArray(['', 'N°', 'RUT', 'Nombre', 'Apellido', 'Cargo', 'Prueba C-Integrada', 'Prueba SE', 'RDO', 'Resultado de Habilitación'], null, 'A4');

$rowNum = 5;
foreach ($data['groups'] as $group) {
    foreach ($group['rows'] as $index => $row) {
        $sheet->setCellValue('A' . $rowNum, $index === 0 ? (string)$group['group_label'] : '');
        $sheet->setCellValue('B' . $rowNum, (int)$row['orden_item']);
        $sheet->setCellValue('C' . $rowNum, (string)$row['rut']);
        $sheet->setCellValue('D' . $rowNum, (string)$row['nombre']);
        $sheet->setCellValue('E' . $rowNum, (string)$row['apellido']);
        $sheet->setCellValue('F' . $rowNum, (string)$row['cargo']);
        $sheet->setCellValue('G' . $rowNum, isset($row['prueba_c_integrada']) ? ((float)$row['prueba_c_integrada']) * 100 : null);
        $sheet->setCellValue('H' . $rowNum, isset($row['prueba_se']) ? ((float)$row['prueba_se']) : null);
        $sheet->setCellValue('I' . $rowNum, isset($row['rdo']) ? ((float)$row['rdo']) * 100 : null);
        $sheet->setCellValue('J' . $rowNum, isset($row['resultado_habilitacion']) ? ((float)$row['resultado_habilitacion']) * 100 : null);
        $rowNum++;
    }
}

$sheet->getStyle('A1:C2')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);

$sheet->getStyle('A4:J4')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);

$sheet->getStyle('A5:J' . max(5, $rowNum - 1))->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);

$excelRow = 5;
foreach ($data['groups'] as $group) {
    foreach ($group['rows'] as $row) {
        foreach ([
            'G' => $row['prueba_c_integrada_pct'] ?? null,
            'H' => $row['prueba_se_pct'] ?? null,
            'I' => $row['rdo_pct'] ?? null,
            'J' => $row['resultado_habilitacion_pct'] ?? null,
        ] as $col => $value) {
            if ($value !== null && (float)$value < 80) {
                $sheet->getStyle($col . $excelRow)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                $sheet->getStyle($col . $excelRow)->getFont()->setBold(true);
            }
        }
        $excelRow++;
    }
}

foreach (range('A', 'J') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="informe_agrupacion_servicio_' . $idServicio . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
