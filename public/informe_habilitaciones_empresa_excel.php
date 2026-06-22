<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/informe_habilitaciones_empresa_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$empresaSolicitada = (int)($_GET['empresa'] ?? 0);
$empresaId = iheResolveEmpresaSeleccionada($_SESSION['auth'], $empresaSolicitada);
$searchTerm = trim((string)($_GET['buscar'] ?? ''));
$habilitadoFilter = iheNormalizeHabilitadoFilter((string)($_GET['habilitado'] ?? ''));
if ($empresaId <= 0) {
    http_response_code(400);
    exit('Empresa invalida.');
}

$pdo = db();
try {
    $report = iheBuildCompanyReport($pdo, $empresaId);
    $report = iheFilterReportRowsByHabilitado($report, $habilitadoFilter);
    $report = iheFilterReportRowsBySearch($report, $searchTerm);

    $spreadsheet = new Spreadsheet();
    $sheetIndex = 0;

    foreach ($report['definitions'] as $definition) {
        $sheetData = $report['sheets'][$definition['key']] ?? null;
        if ($sheetData === null) {
            continue;
        }

        $sheet = $sheetIndex === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr((string)$definition['title'], 0, 31));
        $rows = $sheetData['rows'];
        $columns = $definition['columns'];

        if (($definition['mode'] ?? '') === 'legend') {
            $sheet->setCellValue('A1', 'ESTADO DE HABILITACION');
            $sheet->mergeCells('A1:B1');
            $sheet->getStyle('A1:B1')->applyFromArray([
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $sheet->fromArray($columns, null, 'A2');
            $sheet->getStyle('A2:B2')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF2FB']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $rowNum = 3;
            foreach ($rows as $row) {
                $sheet->setCellValue("A{$rowNum}", (string)($row['ESTADO'] ?? ''));
                $sheet->setCellValue("B{$rowNum}", (string)($row['OBSERVACION'] ?? ''));
                $rowNum++;
            }

            $sheet->getStyle("A3:B" . max(3, $rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getColumnDimension('A')->setWidth(18);
            $sheet->getColumnDimension('B')->setWidth(90);
            $sheetIndex++;
            continue;
        }

        $sheet->freezePane('A2');
        $sheet->fromArray($columns, null, 'A1');

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($columns));
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
        $sheet->getRowDimension(1)->setRowHeight(32);

        $excelRow = 2;
        foreach ($rows as $row) {
            $excelColumn = 1;
            foreach ($columns as $columnName) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($excelColumn);
                $sheet->setCellValue($columnLetter . $excelRow, (string)($row[$columnName] ?? ''));
                $excelColumn++;
            }
            $excelRow++;
        }

        if ($excelRow > 2) {
            $sheet->getStyle("A2:{$lastColumn}" . ($excelRow - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        for ($i = 1; $i <= count($columns); $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheetIndex++;
    }

    $spreadsheet->setActiveSheetIndex(0);

    $filename = 'informe_habilitaciones_empresa_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$report['empresa_nombre']) . '.xlsx';

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    exit('Error al generar Excel: ' . $e->getMessage());
}
