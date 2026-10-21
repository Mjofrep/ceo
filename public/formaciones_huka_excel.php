<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/formaciones_huka_lib.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/public/index.php');
    exit;
}

$rows = fhFetchRows(db(), fhFilters($_GET));
$sheet = (new Spreadsheet())->getActiveSheet();
$sheet->setTitle('Formaciones HUKA');
$sheet->fromArray([[
    'RUT', 'Nombre', 'Apellido', 'Cuadrilla', 'Fecha', 'Servicio', 'Empresa', 'UO', 'Jornada',
    'Nota', 'Porcentaje', 'Puntaje', 'Correctas', 'Incorrectas', 'No contestadas', 'Inicio',
    'Termino', 'Duracion', 'Motivo', 'Estado'
]], null, 'A1');

$line = 2;
foreach ($rows as $row) {
    $sheet->fromArray([[
        $row['rut'], $row['nombre'], $row['apellidos'], (int)$row['cuadrilla'], $row['fecha'],
        $row['servicio'], $row['empresa'], $row['uo'], $row['jornada'], $row['notafinal'],
        $row['puntaje_total'], ($row['puntaje_obtenido'] ?? '') . ' / ' . ($row['puntaje_maximo'] ?? ''),
        $row['correctas'], $row['incorrectas'], $row['ncontestadas'], $row['fecha_inicio'],
        $row['fecha_termino'], fhDuracion($row['fecha_inicio'] ?? null, $row['fecha_termino'] ?? null),
        $row['cierre_modo'], fhEstado($row['resultado']),
    ]], null, 'A' . $line);
    $line++;
}

foreach (range('A', 'T') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}
$sheet->freezePane('A2');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="formaciones_huka_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($sheet->getParent()))->save('php://output');
exit;
