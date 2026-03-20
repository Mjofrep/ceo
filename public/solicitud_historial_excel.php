<?php
declare(strict_types=1);

require_once '../config/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$rut = $_GET['rut'] ?? '';
if ($rut === '') {
    die("RUT no especificado.");
}

$pdo = db();

// ==========================
//    CONSULTA BD
// ==========================
$sql = "
SELECT 
   s.fecha,
   s.horainicio,
   s.horatermino,
   s.nsolicitud,
   sv.servicio,
   u.desc_uo AS unidad_operativa,
   e.nombre AS empresa,
   p.desc_proceso AS proceso,
   h.desc_tipo AS habilitacion
FROM ceo_participantes_solicitud ps
JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
LEFT JOIN ceo_uo u ON u.id = s.uo
LEFT JOIN ceo_empresas e ON e.id = s.contratista
LEFT JOIN ceo_procesos p ON p.id = s.proceso
LEFT JOIN ceo_habilitaciontipo h ON h.id = s.habilitacionceo
WHERE ps.rut = :rut
ORDER BY s.fecha DESC, s.horainicio DESC
";

$st = $pdo->prepare($sql);
$st->execute([':rut' => $rut]);
$data = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$data) {
    die("No existen registros para este RUT.");
}

// ======================================================
//               CREAR DOCUMENTO EXCEL
// ======================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Título de columnas
$headers = [
    'A1' => 'Fecha',
    'B1' => 'Inicio',
    'C1' => 'Término',
    'D1' => 'Solicitud',
    'E1' => 'Servicio',
    'F1' => 'UO',
    'G1' => 'Empresa',
    'H1' => 'Proceso',
    'I1' => 'Habilitación'
];

// Poner encabezados
foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// Encabezado estilo
$sheet->getStyle("A1:I1")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);

// ==========================
//   POBLAR DATOS
// ==========================
$row = 2;
foreach ($data as $d) {

    $sheet->setCellValue("A$row", $d['fecha']);
    $sheet->setCellValue("B$row", $d['horainicio']);
    $sheet->setCellValue("C$row", $d['horatermino']);
    $sheet->setCellValue("D$row", $d['nsolicitud']);
    $sheet->setCellValue("E$row", $d['servicio']);
    $sheet->setCellValue("F$row", $d['unidad_operativa']);
    $sheet->setCellValue("G$row", $d['empresa']);
    $sheet->setCellValue("H$row", $d['proceso']);
    $sheet->setCellValue("I$row", $d['habilitacion']);

    $row++;
}

// Bordes para todos los datos
$sheet->getStyle("A1:I" . ($row - 1))->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
]);

// Autofit columns
foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ======================================================
//          DESCARGA COMO ARCHIVO XLSX
// ======================================================
$filename = "Historial_$rut.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

