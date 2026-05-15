<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
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

$pdo = db();

function frmExcelMeses(): array
{
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}

function frmExcelBaseSql(): string
{
    return "
        SELECT
            q.*,
            YEAR(q.fecha_reporte) AS anio_reporte,
            MONTH(q.fecha_reporte) AS mes_reporte
        FROM (
            SELECT
                f.id AS id_formacion,
                f.cuadrilla,
                f.fecha AS fecha_formacion,
                f.jornada,
                f.id_servicio,
                fs.servicio,
                f.empresa AS id_empresa,
                e.nombre AS empresa,
                f.uo AS id_uo,
                uo.desc_uo AS uo,
                p.rut,
                COALESCE(p.nombre, '') AS nombre,
                COALESCE(p.apellidos, '') AS apellidos,
                COALESCE(p.cargo, '') AS cargo,
                ep.id AS id_programacion,
                ep.estado AS estado_programacion,
                ep.resultado,
                ep.fecha_programacion,
                ep.fecha_resultado,
                ep.fecha_inicio,
                ep.fecha_termino,
                ep.cierre_modo,
                CASE
                    WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA' THEN 'ANULADA'
                    ELSE UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE')))
                END AS estado_reporte,
                CASE
                    WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA'
                        THEN COALESCE(DATE(ep.fecha_programacion), f.fecha)
                    WHEN UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE'))) IN ('APROBADO', 'REPROBADO')
                        THEN COALESCE(
                            (
                                SELECT ri.fecha_rendicion
                                FROM ceo_resultado_formacion_intento ri
                                WHERE ri.rut = p.rut
                                  AND ri.id_servicio = f.id_servicio
                                  AND (
                                    ep.fecha_resultado IS NULL
                                    OR TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) <= ep.fecha_resultado
                                  )
                                ORDER BY TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) DESC, ri.id DESC
                                LIMIT 1
                            ),
                            DATE(ep.fecha_resultado),
                            DATE(ep.fecha_termino),
                            DATE(ep.fecha_programacion),
                            f.fecha
                        )
                    ELSE COALESCE(DATE(ep.fecha_programacion), f.fecha)
                END AS fecha_reporte
            FROM ceo_formacion_participantes p
            INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
            LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_uo uo ON uo.id = f.uo
            LEFT JOIN (
                SELECT ep1.*
                FROM ceo_formacion_programadas ep1
                INNER JOIN (
                    SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                    FROM ceo_formacion_programadas
                    GROUP BY rut, id_servicio, cuadrilla
                ) ep2 ON ep1.id = ep2.max_id
            ) ep ON ep.rut = p.rut AND ep.id_servicio = f.id_servicio AND ep.cuadrilla = f.cuadrilla
        ) q
        WHERE q.fecha_reporte IS NOT NULL
    ";
}

function frmExcelFetchRows(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];
    $where[] = 'q.anio_reporte = :anio';
    $params[':anio'] = (int)$filters['anio'];
    $where[] = 'q.mes_reporte BETWEEN :mes_desde AND :mes_hasta';
    $params[':mes_desde'] = (int)$filters['mes_desde'];
    $params[':mes_hasta'] = (int)$filters['mes_hasta'];
    if ((int)$filters['servicio'] > 0) {
        $where[] = 'q.id_servicio = :servicio';
        $params[':servicio'] = (int)$filters['servicio'];
    }
    if ((int)$filters['empresa'] > 0) {
        $where[] = 'q.id_empresa = :empresa';
        $params[':empresa'] = (int)$filters['empresa'];
    }
    if ((int)$filters['uo'] > 0) {
        $where[] = 'q.id_uo = :uo';
        $params[':uo'] = (int)$filters['uo'];
    }
    if ((string)$filters['estado'] !== '') {
        $where[] = 'q.estado_reporte = :estado';
        $params[':estado'] = (string)$filters['estado'];
    }
    $sql = 'SELECT * FROM (' . frmExcelBaseSql() . ') q WHERE ' . implode(' AND ', $where) . ' ORDER BY q.fecha_reporte ASC, q.servicio ASC, q.empresa ASC, q.apellidos ASC, q.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function frmExcelStyleHeader($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
    ]);
}

function frmExcelCell(int $col, int $row): string
{
    return frmExcelColumn($col) . $row;
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

$anio = (int)($_GET['anio'] ?? date('Y'));
$mesDesde = max(1, min(12, (int)($_GET['mes_desde'] ?? 1)));
$mesHasta = max(1, min(12, (int)($_GET['mes_hasta'] ?? date('n'))));
if ($mesDesde > $mesHasta) {
    [$mesDesde, $mesHasta] = [$mesHasta, $mesDesde];
}
$filters = [
    'anio' => $anio,
    'mes_desde' => $mesDesde,
    'mes_hasta' => $mesHasta,
    'servicio' => (int)($_GET['servicio'] ?? 0),
    'empresa' => (int)($_GET['empresa'] ?? 0),
    'uo' => (int)($_GET['uo'] ?? 0),
    'estado' => strtoupper(trim((string)($_GET['estado'] ?? ''))),
];
$meses = frmExcelMeses();
$rows = frmExcelFetchRows($pdo, $filters);

$servicios = [];
$matrixEstados = [];
$estadosTotales = ['APROBADO' => 'Aprob.', 'REPROBADO' => 'Reprob.', 'PENDIENTE' => 'Pend.'];
$totalesServicioEstado = [];
$totalesMesEstado = [];
$granTotalEstado = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $matrixEstados[$m] = [];
    $totalesMesEstado[$m] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
}
foreach ($rows as $r) {
    $srv = trim((string)($r['servicio'] ?? 'Sin servicio')) ?: 'Sin servicio';
    $mes = (int)$r['mes_reporte'];
    $estado = strtoupper(trim((string)($r['estado_reporte'] ?? 'PENDIENTE')));
    $servicios[$srv] = true;
    if ($estado === 'ANULADA') {
        continue;
    }
    $estadoTabla = in_array($estado, ['APROBADO', 'REPROBADO'], true) ? $estado : 'PENDIENTE';
    if (!isset($matrixEstados[$mes][$srv])) {
        $matrixEstados[$mes][$srv] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
    }
    if (!isset($totalesServicioEstado[$srv])) {
        $totalesServicioEstado[$srv] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
    }
    $matrixEstados[$mes][$srv][$estadoTabla]++;
    $totalesServicioEstado[$srv][$estadoTabla]++;
    $totalesMesEstado[$mes][$estadoTabla]++;
    $granTotalEstado[$estadoTabla]++;
}
$servicioCols = array_keys($servicios);
sort($servicioCols, SORT_NATURAL | SORT_FLAG_CASE);

$spreadsheet = new Spreadsheet();

$bd = $spreadsheet->getActiveSheet();
$bd->setTitle('BD');
$headers = ['Fecha reporte', 'Mes', 'Estado', 'RUT', 'Nombre', 'Apellidos', 'Empresa', 'Cargo', 'Servicio', 'Cuadrilla', 'UO', 'Jornada', 'Fecha formacion', 'Fecha programacion', 'Fecha resultado'];
$bd->fromArray($headers, null, 'A1');
$rowNum = 2;
foreach ($rows as $r) {
    $bd->fromArray([
        $r['fecha_reporte'], $meses[(int)$r['mes_reporte']] ?? '', $r['estado_reporte'], $r['rut'], $r['nombre'], $r['apellidos'],
        $r['empresa'], $r['cargo'], $r['servicio'], $r['cuadrilla'], $r['uo'], $r['jornada'],
        $r['fecha_formacion'], $r['fecha_programacion'], $r['fecha_resultado'],
    ], null, 'A' . $rowNum);
    $rowNum++;
}
frmExcelStyleHeader($bd, 'A1:O1');
foreach (range('A', 'O') as $col) {
    $bd->getColumnDimension($col)->setAutoSize(true);
}

$tot = $spreadsheet->createSheet();
$tot->setTitle('Totales');
$tot->setCellValue('A1', 'Mes');
$tot->mergeCells('A1:A2');
$col = 2;
foreach ($servicioCols as $srv) {
    $tot->setCellValue(frmExcelCell($col, 1), $srv);
    $tot->mergeCells(frmExcelCell($col, 1) . ':' . frmExcelCell($col + 2, 1));
    foreach ($estadosTotales as $label) {
        $tot->setCellValue(frmExcelCell($col, 2), $label);
        $col++;
    }
}
$tot->setCellValue(frmExcelCell($col, 1), 'Total');
$tot->mergeCells(frmExcelCell($col, 1) . ':' . frmExcelCell($col + 2, 1));
foreach ($estadosTotales as $label) {
    $tot->setCellValue(frmExcelCell($col, 2), $label);
    $col++;
}
$rowNum = 2;
$rowNum = 3;
for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $tot->setCellValue('A' . $rowNum, $meses[$m]);
    $col = 2;
    foreach ($servicioCols as $srv) {
        foreach ($estadosTotales as $estadoKey => $label) {
            $tot->setCellValue(frmExcelCell($col, $rowNum), (int)($matrixEstados[$m][$srv][$estadoKey] ?? 0));
            $col++;
        }
    }
    foreach ($estadosTotales as $estadoKey => $label) {
        $tot->setCellValue(frmExcelCell($col, $rowNum), (int)($totalesMesEstado[$m][$estadoKey] ?? 0));
        $col++;
    }
    $rowNum++;
}
$tot->setCellValue('A' . $rowNum, 'TOTAL');
$col = 2;
foreach ($servicioCols as $srv) {
    foreach ($estadosTotales as $estadoKey => $label) {
        $tot->setCellValue(frmExcelCell($col, $rowNum), (int)($totalesServicioEstado[$srv][$estadoKey] ?? 0));
        $col++;
    }
}
foreach ($estadosTotales as $estadoKey => $label) {
    $tot->setCellValue(frmExcelCell($col, $rowNum), (int)($granTotalEstado[$estadoKey] ?? 0));
    $col++;
}
$highestColumn = $tot->getHighestColumn();
frmExcelStyleHeader($tot, 'A1:' . $highestColumn . '2');
$tot->getStyle('A' . $rowNum . ':' . $tot->getHighestColumn() . $rowNum)->getFont()->setBold(true);
for ($i = 1; $i < $col; $i++) {
    $tot->getColumnDimension(frmExcelColumn($i))->setAutoSize(true);
}

$det = $spreadsheet->createSheet();
$det->setTitle('Detalle');
$det->fromArray(['RUT', 'Nombre completo', 'Empresa', 'Cargo', 'Servicio', 'Estado', 'Fecha reporte', 'Cuadrilla'], null, 'A1');
$rowNum = 2;
foreach ($rows as $r) {
    $det->fromArray([
        $r['rut'], trim((string)$r['nombre'] . ' ' . (string)$r['apellidos']), $r['empresa'], $r['cargo'], $r['servicio'],
        $r['estado_reporte'], $r['fecha_reporte'], $r['cuadrilla'],
    ], null, 'A' . $rowNum);
    $rowNum++;
}
frmExcelStyleHeader($det, 'A1:H1');
foreach (range('A', 'H') as $col) {
    $det->getColumnDimension($col)->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);
$filename = 'resumen_formaciones_' . $anio . '_' . $mesDesde . '_' . $mesHasta . '.xlsx';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
