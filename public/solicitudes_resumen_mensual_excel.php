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

function solExcelMeses(): array
{
    return [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
}

function solExcelEstadoLabel(string $estado): string
{
    return match (strtoupper(trim($estado))) {
        'I' => 'INICIAL', 'A' => 'AUTORIZADA', 'F' => 'FINALIZADA', 'C' => 'CANCELADA',
        default => strtoupper(trim($estado)) ?: 'SIN ESTADO',
    };
}

function solExcelBaseSql(): string
{
    return "
        SELECT q.*, YEAR(q.fecha_reporte) AS anio_reporte, MONTH(q.fecha_reporte) AS mes_reporte
        FROM (
            SELECT
                s.id AS id_solicitud,
                s.nsolicitud,
                s.fecha AS fecha_solicitud,
                s.fechacreacion,
                s.estado,
                s.habilitacionceo AS id_habilitacionceo,
                COALESCE(ht.desc_tipo, 'Sin Habilitacion CEO') AS habilitacionceo,
                s.contratista AS id_empresa,
                COALESCE(e.nombre, '') AS empresa,
                s.proceso AS id_proceso,
                COALESCE(pr.desc_proceso, '') AS proceso,
                s.uo AS id_uo,
                COALESCE(uo.desc_uo, '') AS uo,
                s.patio AS id_patio,
                COALESCE(pa.desc_patios, '') AS patio,
                s.horainicio,
                s.horatermino,
                ps.rut,
                COALESCE(ps.nombre, '') AS nombre,
                TRIM(CONCAT(COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS apellidos,
                COALESCE(cc.cargo, '') AS cargo,
                COALESCE(ps.asistio, 0) AS asistio,
                ps.fechaasistio,
                ps.autorizado,
                ps.aprobo,
                ps.wf,
                COALESCE(s.fecha, s.fechacreacion) AS fecha_reporte
            FROM ceo_solicitudes s
            LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
            LEFT JOIN ceo_empresas e ON e.id = s.contratista
            LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
            LEFT JOIN ceo_uo uo ON uo.id = s.uo
            LEFT JOIN ceo_patios pa ON pa.id = s.patio
            LEFT JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
            LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ps.id_cargo
        ) q
        WHERE q.fecha_reporte IS NOT NULL
    ";
}

function solExcelFetchRows(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];
    $where[] = 'q.anio_reporte = :anio';
    $params[':anio'] = (int)$filters['anio'];
    $where[] = 'q.mes_reporte BETWEEN :mes_desde AND :mes_hasta';
    $params[':mes_desde'] = (int)$filters['mes_desde'];
    $params[':mes_hasta'] = (int)$filters['mes_hasta'];
    if ((int)$filters['habilitacionceo'] > 0) {
        $where[] = 'q.id_habilitacionceo = :habilitacionceo';
        $params[':habilitacionceo'] = (int)$filters['habilitacionceo'];
    }
    if ((int)$filters['empresa'] > 0) {
        $where[] = 'q.id_empresa = :empresa';
        $params[':empresa'] = (int)$filters['empresa'];
    }
    if ((int)$filters['uo'] > 0) {
        $where[] = 'q.id_uo = :uo';
        $params[':uo'] = (int)$filters['uo'];
    }
    if ((int)$filters['proceso'] > 0) {
        $where[] = 'q.id_proceso = :proceso';
        $params[':proceso'] = (int)$filters['proceso'];
    }
    if ((int)$filters['patio'] > 0) {
        $where[] = 'q.id_patio = :patio';
        $params[':patio'] = (int)$filters['patio'];
    }
    if ((string)$filters['estado'] !== '') {
        $where[] = 'q.estado = :estado';
        $params[':estado'] = (string)$filters['estado'];
    }
    $sql = 'SELECT * FROM (' . solExcelBaseSql() . ') q WHERE ' . implode(' AND ', $where) . ' ORDER BY q.fecha_reporte ASC, q.nsolicitud ASC, q.habilitacionceo ASC, q.apellidos ASC, q.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function solExcelStyleHeader($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
    ]);
}

function solExcelCell(int $col, int $row): string
{
    return solExcelColumn($col) . $row;
}

function solExcelColumn(int $col): string
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
    'habilitacionceo' => (int)($_GET['habilitacionceo'] ?? 0),
    'empresa' => (int)($_GET['empresa'] ?? 0),
    'uo' => (int)($_GET['uo'] ?? 0),
    'proceso' => (int)($_GET['proceso'] ?? 0),
    'patio' => (int)($_GET['patio'] ?? 0),
    'estado' => strtoupper(trim((string)($_GET['estado'] ?? ''))),
];
$meses = solExcelMeses();
$rows = solExcelFetchRows($pdo, $filters);

$habSet = [];
$matrix = [];
$totalesHab = [];
$totalesMes = [];
$granSolicitudes = [];
$resumenHab = [];
for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $matrix[$m] = [];
    $totalesMes[$m] = ['solicitudes' => [], 'convocados' => 0, 'asistidos' => 0];
}
foreach ($rows as $r) {
    $nsol = (int)($r['nsolicitud'] ?? 0);
    $mes = (int)$r['mes_reporte'];
    $hab = trim((string)($r['habilitacionceo'] ?? 'Sin Habilitacion CEO')) ?: 'Sin Habilitacion CEO';
    $tieneParticipante = trim((string)($r['rut'] ?? '')) !== '';
    $asistio = (int)($r['asistio'] ?? 0) === 1;
    $habSet[$hab] = true;
    $granSolicitudes[$nsol] = true;
    foreach ([&$matrix[$mes][$hab], &$totalesHab[$hab], &$resumenHab[$hab]] as &$bucket) {
        if (!isset($bucket)) {
            $bucket = ['solicitudes' => [], 'convocados' => 0, 'asistidos' => 0];
        }
        $bucket['solicitudes'][$nsol] = true;
        if ($tieneParticipante) {
            $bucket['convocados']++;
            if ($asistio) {
                $bucket['asistidos']++;
            }
        }
    }
    unset($bucket);
    $totalesMes[$mes]['solicitudes'][$nsol] = true;
    if ($tieneParticipante) {
        $totalesMes[$mes]['convocados']++;
        if ($asistio) {
            $totalesMes[$mes]['asistidos']++;
        }
    }
}
$habCols = array_keys($habSet);
sort($habCols, SORT_NATURAL | SORT_FLAG_CASE);

$spreadsheet = new Spreadsheet();
$bd = $spreadsheet->getActiveSheet();
$bd->setTitle('BD');
$headers = ['Fecha solicitud', 'Mes', 'N Solicitud', 'Estado', 'Habilitacion CEO', 'RUT', 'Nombre', 'Empresa', 'Cargo', 'Asistio', 'Fecha asistencia', 'Proceso', 'UO', 'Patio', 'WF'];
$bd->fromArray($headers, null, 'A1');
$rowNum = 2;
foreach ($rows as $r) {
    $bd->fromArray([
        $r['fecha_solicitud'], $meses[(int)$r['mes_reporte']] ?? '', $r['nsolicitud'], solExcelEstadoLabel((string)$r['estado']), $r['habilitacionceo'], $r['rut'], trim((string)$r['nombre'] . ' ' . (string)$r['apellidos']), $r['empresa'], $r['cargo'], (int)$r['asistio'] === 1 ? 'Si' : 'No', $r['fechaasistio'], $r['proceso'], $r['uo'], $r['patio'], $r['wf'],
    ], null, 'A' . $rowNum);
    $rowNum++;
}
solExcelStyleHeader($bd, 'A1:O1');
foreach (range('A', 'O') as $col) {
    $bd->getColumnDimension($col)->setAutoSize(true);
}

$tot = $spreadsheet->createSheet();
$tot->setTitle('Totales');
$tot->setCellValue('A1', 'Mes');
$tot->mergeCells('A1:A2');
$col = 2;
foreach ($habCols as $hab) {
    $tot->setCellValue(solExcelCell($col, 1), $hab);
    $tot->mergeCells(solExcelCell($col, 1) . ':' . solExcelCell($col + 2, 1));
    foreach (['Solic.', 'Conv.', 'Asist.'] as $label) {
        $tot->setCellValue(solExcelCell($col, 2), $label);
        $col++;
    }
}
$tot->setCellValue(solExcelCell($col, 1), 'Total');
$tot->mergeCells(solExcelCell($col, 1) . ':' . solExcelCell($col + 2, 1));
foreach (['Solic.', 'Conv.', 'Asist.'] as $label) {
    $tot->setCellValue(solExcelCell($col, 2), $label);
    $col++;
}
$rowNum = 3;
for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $tot->setCellValue('A' . $rowNum, $meses[$m]);
    $col = 2;
    foreach ($habCols as $hab) {
        $tot->setCellValue(solExcelCell($col++, $rowNum), count($matrix[$m][$hab]['solicitudes'] ?? []));
        $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($matrix[$m][$hab]['convocados'] ?? 0));
        $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($matrix[$m][$hab]['asistidos'] ?? 0));
    }
    $tot->setCellValue(solExcelCell($col++, $rowNum), count($totalesMes[$m]['solicitudes'] ?? []));
    $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($totalesMes[$m]['convocados'] ?? 0));
    $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($totalesMes[$m]['asistidos'] ?? 0));
    $rowNum++;
}
$tot->setCellValue('A' . $rowNum, 'TOTAL');
$col = 2;
foreach ($habCols as $hab) {
    $tot->setCellValue(solExcelCell($col++, $rowNum), count($totalesHab[$hab]['solicitudes'] ?? []));
    $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($totalesHab[$hab]['convocados'] ?? 0));
    $tot->setCellValue(solExcelCell($col++, $rowNum), (int)($totalesHab[$hab]['asistidos'] ?? 0));
}
$tot->setCellValue(solExcelCell($col++, $rowNum), count($granSolicitudes));
$tot->setCellValue(solExcelCell($col++, $rowNum), array_sum(array_column($resumenHab, 'convocados')));
$tot->setCellValue(solExcelCell($col++, $rowNum), array_sum(array_column($resumenHab, 'asistidos')));
$highestColumn = $tot->getHighestColumn();
solExcelStyleHeader($tot, 'A1:' . $highestColumn . '2');
$tot->getStyle('A' . $rowNum . ':' . $highestColumn . $rowNum)->getFont()->setBold(true);
for ($i = 1; $i < $col; $i++) {
    $tot->getColumnDimension(solExcelColumn($i))->setAutoSize(true);
}

$det = $spreadsheet->createSheet();
$det->setTitle('Detalle');
$det->fromArray(['Habilitacion CEO', 'Solicitudes', 'Convocados', 'Asistidos', 'No asistieron', '% Asistencia'], null, 'A1');
$rowNum = 2;
ksort($resumenHab);
foreach ($resumenHab as $hab => $data) {
    $convocados = (int)($data['convocados'] ?? 0);
    $asistidos = (int)($data['asistidos'] ?? 0);
    $det->fromArray([$hab, count($data['solicitudes'] ?? []), $convocados, $asistidos, max(0, $convocados - $asistidos), $convocados > 0 ? round(($asistidos / $convocados) * 100, 1) . '%' : '0%'], null, 'A' . $rowNum);
    $rowNum++;
}
solExcelStyleHeader($det, 'A1:F1');
foreach (range('A', 'F') as $col) {
    $det->getColumnDimension($col)->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);
$filename = 'resumen_solicitudes_' . $anio . '_' . $mesDesde . '_' . $mesHasta . '.xlsx';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
