<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
if ($cuadrilla <= 0) {
    die('Cuadrilla invalida');
}

$stmt = $pdo->prepare("
    SELECT f.id_servicio
    FROM ceo_formacion f
    WHERE f.cuadrilla = :cuadrilla
    ORDER BY f.id DESC
    LIMIT 1
");
$stmt->execute([':cuadrilla' => $cuadrilla]);
$idServicio = (int)$stmt->fetchColumn();

$rows = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            p.rut,
            p.nombre,
            p.apellidos,
        ep.resultado,
        ep.fecha_inicio,
        ep.fecha_termino,
        ep.cierre_modo,
        ri.notafinal,
        ri.puntaje_total,
        ri.puntaje_obtenido,
        ri.puntaje_maximo,
        ri.correctas,
        ri.incorrectas,
        ri.ncontestadas
        FROM ceo_formacion_participantes p
        LEFT JOIN (
            SELECT ep1.*
            FROM ceo_formacion_programadas ep1
            INNER JOIN (
                SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion_programadas
                WHERE cuadrilla = :cuadrilla
                GROUP BY rut, id_servicio, cuadrilla
            ) ep2 ON ep1.id = ep2.max_id
        ) ep ON ep.rut = p.rut AND ep.id_servicio = :servicio AND ep.cuadrilla = :cuadrilla2
        LEFT JOIN (
            SELECT ri1.*
            FROM ceo_resultado_formacion_intento ri1
            INNER JOIN (
                SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion,' ',hora_rendicion)) AS max_fecha
                FROM ceo_resultado_formacion_intento
                GROUP BY rut, id_servicio
            ) ri2 ON ri1.rut = ri2.rut
                  AND ri1.id_servicio = ri2.id_servicio
                  AND CONCAT(ri1.fecha_rendicion,' ',ri1.hora_rendicion) = ri2.max_fecha
        ) ri ON ri.rut = p.rut AND ri.id_servicio = :servicio2
        WHERE p.id_cuadrilla = :cuadrilla3
        ORDER BY p.apellidos ASC, p.nombre ASC
    ");
    $stmt->execute([
        ':cuadrilla' => $cuadrilla,
        ':cuadrilla2' => $cuadrilla,
        ':cuadrilla3' => $cuadrilla,
        ':servicio' => $idServicio,
        ':servicio2' => $idServicio
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        die('Error SQL: ' . $e->getMessage());
    }
    $rows = [];
}

function estadoResultado(?string $resultado): string
{
    if (!$resultado) {
        return 'PENDIENTE';
    }
    $res = strtoupper(trim($resultado));
    if ($res === 'APROBADO' || $res === 'REPROBADO' || $res === 'PENDIENTE') {
        return $res;
    }
    return $res;
}

function formatDuracion(?string $inicio, ?string $termino): string
{
    if (!$inicio || !$termino) {
        return '';
    }
    try {
        $dtInicio = new DateTime($inicio);
        $dtTermino = new DateTime($termino);
        $diff = $dtInicio->diff($dtTermino);
        $mins = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
        return (string)$mins . ' min';
    } catch (Throwable $e) {
        return '';
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Formaciones');

$sheet->fromArray([
    ['RUT', 'Nombre', 'Apellido', 'Nota', 'Porcentaje', 'Puntaje', 'Correctas', 'Incorrectas', 'No contestadas', 'Inicio', 'Termino', 'Duracion', 'Motivo', 'Estado']
], null, 'A1');

$rowNum = 2;
foreach ($rows as $r) {
    $sheet->setCellValue("A{$rowNum}", $r['rut'] ?? '');
    $sheet->setCellValue("B{$rowNum}", $r['nombre'] ?? '');
    $sheet->setCellValue("C{$rowNum}", $r['apellidos'] ?? '');
    $sheet->setCellValue("D{$rowNum}", $r['notafinal'] ?? '');
    $sheet->setCellValue("E{$rowNum}", $r['puntaje_total'] ?? '');
    $sheet->setCellValue("F{$rowNum}", ($r['puntaje_obtenido'] ?? '') . ' / ' . ($r['puntaje_maximo'] ?? ''));
    $sheet->setCellValue("G{$rowNum}", $r['correctas'] ?? '');
    $sheet->setCellValue("H{$rowNum}", $r['incorrectas'] ?? '');
    $sheet->setCellValue("I{$rowNum}", $r['ncontestadas'] ?? '');
    $sheet->setCellValue("J{$rowNum}", $r['fecha_inicio'] ?? '');
    $sheet->setCellValue("K{$rowNum}", $r['fecha_termino'] ?? '');
    $sheet->setCellValue("L{$rowNum}", formatDuracion($r['fecha_inicio'] ?? null, $r['fecha_termino'] ?? null));
    $sheet->setCellValue("M{$rowNum}", $r['cierre_modo'] ?? '');
    $sheet->setCellValue("N{$rowNum}", estadoResultado($r['resultado'] ?? null));
    $rowNum++;
}

foreach (range('A', 'N') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'formaciones_cuadrilla_' . $cuadrilla . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
