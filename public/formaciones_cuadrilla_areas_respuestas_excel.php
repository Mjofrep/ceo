<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$idServicio = (int)($_GET['id_servicio'] ?? 0);

if ($cuadrilla <= 0 || $idServicio <= 0) {
    die('Parametros invalidos');
}

$pdo = db();
$stmtFormacion = $pdo->prepare('
    SELECT f.cuadrilla, f.jornada, fs.servicio
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
    WHERE f.cuadrilla = :cuadrilla
      AND f.id_servicio = :id_servicio
    ORDER BY f.id DESC
    LIMIT 1
');
$stmtFormacion->execute([
    ':cuadrilla' => $cuadrilla,
    ':id_servicio' => $idServicio,
]);
$formacion = $stmtFormacion->fetch(PDO::FETCH_ASSOC);

if (!$formacion) {
    die('No se encontro la formacion solicitada');
}

$stmt = $pdo->prepare('
    SELECT
        p.rut,
        COALESCE(e.nombre, \'\') AS empresa,
        p.nombre,
        p.apellidos,
        rpt.validacion,
        COALESCE(ac.descripcion, \'Sin área de competencia\') AS area_competencia,
        ps.pregunta,
        GROUP_CONCAT(
            DISTINCT CONCAT(CASE WHEN ap.correcta = \'S\' THEN \'[Correcta] \' ELSE \'\' END, COALESCE(ap.alternativa, \'\'))
            ORDER BY ap.id SEPARATOR \'\\n\'
        ) AS alternativas,
        MAX(CASE WHEN ap.correcta = \'S\' THEN ap.alternativa END) AS alternativa_correcta,
        COALESCE(ap_elegida.alternativa, \'No contestada\') AS alternativa_elegida
    FROM (
        SELECT ep1.rut, ep1.id_servicio, ep1.cuadrilla, ep1.intento
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            WHERE cuadrilla = :cuadrilla
              AND id_servicio = :id_servicio
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
        WHERE UPPER(TRIM(COALESCE(ep1.resultado, \'\'))) IN (\'APROBADO\', \'REPROBADO\')
    ) ep
    INNER JOIN ceo_formacion_participantes p
        ON p.rut = ep.rut
       AND p.id_cuadrilla = ep.cuadrilla
    INNER JOIN ceo_formacion f
        ON f.cuadrilla = ep.cuadrilla
       AND f.id_servicio = ep.id_servicio
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    INNER JOIN ceo_resultado_formacion_pruebat rpt
        ON rpt.rut = ep.rut
       AND rpt.proceso = ep.cuadrilla
       AND rpt.intento = ep.intento
    INNER JOIN ceo_formacion_preguntas_servicios ps
        ON ps.id = rpt.id_pregunta
       AND ps.id_servicio = ep.id_servicio
    LEFT JOIN ceo_areacompetencia_formacion ac
        ON ac.id = ps.areacomp
       AND ac.id_servicio = ps.id_servicio
    LEFT JOIN ceo_formacion_alternativas_preguntas ap ON ap.id_pregunta = ps.id
    LEFT JOIN ceo_formacion_alternativas_preguntas ap_elegida ON ap_elegida.id = rpt.respuesta
    WHERE ps.areacomp IS NOT NULL
      AND COALESCE(ps.tipo_pregunta, \'ALT\') <> \'TEXTO_LIBRE\'
    GROUP BY rpt.id, p.rut, e.nombre, p.nombre, p.apellidos, rpt.validacion, ac.descripcion, ps.pregunta, ap_elegida.alternativa
    ORDER BY p.apellidos ASC, p.nombre ASC, ac.descripcion ASC, rpt.id_pregunta ASC
');
$stmt->execute([
    ':cuadrilla' => $cuadrilla,
    ':id_servicio' => $idServicio,
]);
$respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Respuestas por area');

$headers = ['RUT', 'Empresa', 'Nombre', 'Apellido', 'Área de competencia', 'Pregunta', 'Alternativas', 'Correcta', 'Elegida'];
$sheet->setCellValue('A1', 'Detalle de respuestas por áreas de competencia');
$sheet->mergeCells('A1:I1');
$sheet->setCellValue('A2', 'Servicio');
$sheet->setCellValue('B2', (string)($formacion['servicio'] ?? ''));
$sheet->setCellValue('A3', 'Cuadrilla');
$sheet->setCellValue('B3', (string)$cuadrilla);
$sheet->setCellValue('A4', 'Jornada');
$sheet->setCellValue('B4', (string)($formacion['jornada'] ?? ''));
$sheet->fromArray([$headers], null, 'A6');

$sheet->getStyle('A1:A4')->getFont()->setBold(true);
$sheet->getStyle('A1:I1')->getFont()->setBold(true);
$sheet->getStyle('A6:I6')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
]);

$rowNum = 7;
foreach ($respuestas as $respuesta) {
    $sheet->fromArray([[
        $respuesta['rut'],
        $respuesta['empresa'],
        $respuesta['nombre'],
        $respuesta['apellidos'],
        $respuesta['area_competencia'],
        $respuesta['pregunta'],
        $respuesta['alternativas'],
        $respuesta['alternativa_correcta'],
        $respuesta['alternativa_elegida'],
    ]], null, 'A' . $rowNum);
    if ((int)$respuesta['validacion'] === 0) {
        $sheet->getStyle('A' . $rowNum . ':I' . $rowNum)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
        ]);
    }
    $rowNum++;
}

$lastRow = max(6, $rowNum - 1);
$sheet->getStyle('A6:I' . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
]);

foreach (['A' => 16, 'B' => 25, 'C' => 20, 'D' => 20, 'E' => 28, 'F' => 55, 'G' => 55, 'H' => 35, 'I' => 35] as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$filename = 'formaciones_cuadrilla_respuestas_areas_' . $cuadrilla . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
