<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

function frmExcelMetricToString(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $number = (float)$value;
    if (abs($number - round($number)) < 0.00001) {
        return (string)(int)round($number);
    }

    return number_format($number, 2, '.', '');
}

function frmExcelColumn(int $col): string
{
    $letters = '';
    while ($col > 0) {
        $col--;
        $letters = chr(65 + ($col % 26)) . $letters;
        $col = intdiv($col, 26);
    }
    return $letters;
}

function frmExcelHeaderStyle($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
    ]);
}

$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$idServicio = (int)($_GET['id_servicio'] ?? 0);

if ($cuadrilla <= 0 || $idServicio <= 0) {
    die('Parametros invalidos');
}

$pdo = db();

$stmtFormacion = $pdo->prepare('
    SELECT f.cuadrilla, f.jornada, f.id_servicio, f.id_agrupacion, fs.servicio
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

$porcentajeMinimo = obtenerPorcentajeMinimoFormacionAgrupacion($pdo, (int)($formacion['id_agrupacion'] ?? 0));

$stmtAreas = $pdo->prepare('
    SELECT MIN(id) AS id_area, descripcion AS area
    FROM ceo_areacompetencia_formacion
    WHERE id_servicio = :id_servicio
    GROUP BY descripcion, id_servicio
    ORDER BY descripcion
');
$stmtAreas->execute([':id_servicio' => $idServicio]);
$areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtParticipantes = $pdo->prepare('
    SELECT
        p.rut,
        p.nombre,
        p.apellidos,
        COALESCE(e.nombre, \'\') AS empresa,
        ep.resultado,
        ri.notafinal
    FROM ceo_formacion_participantes p
    INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN (
        SELECT ep1.*
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            WHERE cuadrilla = :cuadrilla
              AND id_servicio = :id_servicio
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
    ) ep ON ep.rut = p.rut AND ep.id_servicio = :id_servicio2 AND ep.cuadrilla = :cuadrilla2
    LEFT JOIN (
        SELECT ri1.*
        FROM ceo_resultado_formacion_intento ri1
        INNER JOIN (
            SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion, \' \', hora_rendicion)) AS max_fecha
            FROM ceo_resultado_formacion_intento
            GROUP BY rut, id_servicio
        ) ri2 ON ri1.rut = ri2.rut
              AND ri1.id_servicio = ri2.id_servicio
              AND CONCAT(ri1.fecha_rendicion, \' \', ri1.hora_rendicion) = ri2.max_fecha
    ) ri ON ri.rut = p.rut AND ri.id_servicio = :id_servicio3
    WHERE p.id_cuadrilla = :cuadrilla3
      AND UPPER(TRIM(COALESCE(ep.resultado, \'\'))) IN (\'APROBADO\', \'REPROBADO\')
    ORDER BY
      CASE UPPER(TRIM(COALESCE(ep.resultado, \'\')))
        WHEN \'REPROBADO\' THEN 1
        WHEN \'APROBADO\' THEN 2
        ELSE 3
      END,
      p.apellidos ASC,
      p.nombre ASC
');
$stmtParticipantes->execute([
    ':cuadrilla' => $cuadrilla,
    ':cuadrilla2' => $cuadrilla,
    ':cuadrilla3' => $cuadrilla,
    ':id_servicio' => $idServicio,
    ':id_servicio2' => $idServicio,
    ':id_servicio3' => $idServicio,
]);
$rowsParticipantes = $stmtParticipantes->fetchAll(PDO::FETCH_ASSOC) ?: [];

$participants = [];
foreach ($rowsParticipantes as $row) {
    $participants[(string)$row['rut']] = [
        'rut' => (string)$row['rut'],
        'empresa' => (string)($row['empresa'] ?? ''),
        'nombre_completo' => trim((string)($row['nombre'] ?? '') . ' ' . (string)($row['apellidos'] ?? '')),
        'nota_final' => $row['notafinal'] !== null ? number_format((float)$row['notafinal'], 2, '.', '') : '',
        'areas' => [],
    ];
}

if ($participants) {
    $stmtStats = $pdo->prepare('
        SELECT
            ep.rut,
            MIN(ac.id) AS id_area,
            COALESCE(ac.descripcion, \'\') AS area,
            SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
            SUM(CASE WHEN rpt.validacion = 0 THEN 1 ELSE 0 END) AS incorrectas,
            SUM(CASE WHEN rpt.validacion = -1 THEN 1 ELSE 0 END) AS ncontestadas,
            COUNT(*) AS total_preguntas
        FROM (
            SELECT ep1.rut, ep1.id_servicio, ep1.cuadrilla, ep1.intento, ep1.resultado
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
        WHERE ps.areacomp IS NOT NULL
          AND COALESCE(ps.tipo_pregunta, \'ALT\') <> \'TEXTO_LIBRE\'
        GROUP BY ep.rut, ac.descripcion
        ORDER BY ac.descripcion ASC
    ');
    $stmtStats->execute([
        ':cuadrilla' => $cuadrilla,
        ':id_servicio' => $idServicio,
    ]);
    $statsRows = $stmtStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($statsRows as $row) {
        $rut = (string)$row['rut'];
        if (!isset($participants[$rut])) {
            continue;
        }

        $total = (float)$row['total_preguntas'];
        $correctas = (float)$row['correctas'];
        if ($total <= 0) {
            continue;
        }

        $porcentaje = round(($correctas / $total) * 100, 2);
        $nota = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);
        $participants[$rut]['areas'][(string)(int)$row['id_area']] = [
            'nota' => number_format($nota, 2, '.', ''),
            'aprobada' => $porcentaje >= $porcentajeMinimo,
            'porcentaje' => number_format($porcentaje, 1, '.', ''),
            'correctas' => frmExcelMetricToString($row['correctas']),
            'total' => frmExcelMetricToString($total),
        ];
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Areas Jornada');

$headers = ['RUT', 'Empresa', 'Nombre / Apellidos', 'Nota'];
foreach ($areas as $area) {
    $headers[] = (string)$area['area'];
}

$lastHeaderCol = frmExcelColumn(count($headers));
$sheet->setCellValue('A1', 'Prueba');
$sheet->setCellValue('B1', 'Formacion por areas de competencia');
$sheet->setCellValue('A2', 'Servicio');
$sheet->setCellValue('B2', (string)($formacion['servicio'] ?? ''));
$sheet->setCellValue('A3', 'Cuadrilla');
$sheet->setCellValue('B3', (string)$cuadrilla);
$sheet->setCellValue('A4', 'Jornada');
$sheet->setCellValue('B4', (string)($formacion['jornada'] ?? ''));
$sheet->fromArray([$headers], null, 'A6');

$sheet->getStyle('A1:A4')->getFont()->setBold(true);
$sheet->mergeCells('B1:' . $lastHeaderCol . '1');
frmExcelHeaderStyle($sheet, 'A6:' . $lastHeaderCol . '6');

$rowNum = 7;
foreach (array_values($participants) as $participant) {
    $sheet->setCellValue('A' . $rowNum, $participant['rut']);
    $sheet->setCellValue('B' . $rowNum, $participant['empresa']);
    $sheet->setCellValue('C' . $rowNum, $participant['nombre_completo']);
    $sheet->setCellValue('D' . $rowNum, $participant['nota_final']);

    $col = 5;
    foreach ($areas as $area) {
        $key = (string)(int)$area['id_area'];
        $areaData = $participant['areas'][$key] ?? null;
        $cell = frmExcelColumn($col) . $rowNum;
        if ($areaData) {
            $sheet->setCellValue($cell, $areaData['nota'] . "\n" . $areaData['porcentaje'] . '%');
            $sheet->getStyle($cell)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $areaData['aprobada'] ? 'D1E7DD' : 'DC3545'],
                ],
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => $areaData['aprobada'] ? '111111' : 'FFFFFF'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ]);
        }
        $col++;
    }

    $rowNum++;
}

$lastRow = max(6, $rowNum - 1);
$sheet->getStyle('A6:' . $lastHeaderCol . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);

for ($i = 1; $i <= count($headers); $i++) {
    $sheet->getColumnDimension(frmExcelColumn($i))->setAutoSize(true);
}

$filename = 'formaciones_cuadrilla_areas_' . $cuadrilla . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
