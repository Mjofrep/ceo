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

function scdxBuildScope(): array
{
    $idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
    $idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['auth']['id'] ?? 0);
    $empresaEnel = 39;

    if (($idRol === 1 || $idRol === 5) && $idEmpresa === $empresaEnel) {
        return ['1=1', []];
    }

    if ($idRol === 3 || $idRol === 4 || $idRol === 6) {
        return ['s.solicitante = :scope_iduser', [':scope_iduser' => $idUsuario]];
    }

    return [
        '(s.contratista = :scope_empresa OR s.solicitante = :scope_iduser)',
        [
            ':scope_empresa' => $idEmpresa,
            ':scope_iduser' => $idUsuario,
        ],
    ];
}

function scdxBaseSql(): string
{
    return "
        SELECT
            s.nsolicitud,
            s.fecha,
            s.tipohabilitacion,
            COALESCE(s.observacion, '') AS observacion,
            COALESCE(s.tipo_visita, '') AS tipo_visita,
            COALESCE(e.nombre, '') AS empresa,
            TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS solicitante,
            COALESCE(pa.desc_patios, '') AS patio,
            COALESCE(pr.desc_proceso, '') AS proceso,
            COALESCE(ht.desc_tipo, '') AS habilitacionceo,
            COALESCE(ch.desc_charlas, '') AS capacitacion,
            COALESCE(rd.reinduccion, '') AS motivo_reinduccion,
            COALESCE(ps.rut, '') AS rut,
            COALESCE(ps.nombre, '') AS nombre,
            COALESCE(ps.apellidop, '') AS apellidop,
            COALESCE(ps.apellidom, '') AS apellidom,
            COALESCE(cc.cargo, '') AS cargo,
            COALESCE(NULLIF(TRIM(CAST(ps.asistio AS CHAR)), ''), '0') AS asistio
        FROM ceo_solicitudes s
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ps.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_usuarios u ON u.id = s.solicitante
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        LEFT JOIN ceo_reinduccion rd ON rd.id = s.motivoreinduccion
    ";
}

function scdxFetchRows(PDO $pdo, array $filters): array
{
    [$scopeWhere, $scopeParams] = scdxBuildScope();

    $where = [$scopeWhere, 's.fecha BETWEEN :fecha_desde AND :fecha_hasta'];
    $params = $scopeParams;
    $params[':fecha_desde'] = $filters['fecha_desde'];
    $params[':fecha_hasta'] = $filters['fecha_hasta'];

    if ($filters['id'] > 0) {
        $where[] = 's.nsolicitud = :nsolicitud';
        $params[':nsolicitud'] = $filters['id'];
    }
    if ($filters['empresa'] > 0) {
        $where[] = 's.contratista = :empresa';
        $params[':empresa'] = $filters['empresa'];
    }
    if ($filters['solicitante'] > 0) {
        $where[] = 's.solicitante = :solicitante';
        $params[':solicitante'] = $filters['solicitante'];
    }
    if ($filters['patio'] > 0) {
        $where[] = 's.patio = :patio';
        $params[':patio'] = $filters['patio'];
    }
    if ($filters['proceso'] > 0) {
        $where[] = 's.proceso = :proceso';
        $params[':proceso'] = $filters['proceso'];
    }
    if ($filters['habilitacionceo'] > 0) {
        $where[] = 's.habilitacionceo = :habilitacionceo';
        $params[':habilitacionceo'] = $filters['habilitacionceo'];
    }
    if ($filters['tipohabilitacion'] !== '') {
        $where[] = 's.tipohabilitacion = :tipohabilitacion';
        $params[':tipohabilitacion'] = $filters['tipohabilitacion'];
    }
    if ($filters['charla'] > 0) {
        $where[] = 's.charla = :charla';
        $params[':charla'] = $filters['charla'];
    }
    if ($filters['motivoreinduccion'] > 0) {
        $where[] = 's.motivoreinduccion = :motivoreinduccion';
        $params[':motivoreinduccion'] = $filters['motivoreinduccion'];
    }
    if ($filters['tipo_visita'] !== '') {
        $where[] = 'COALESCE(s.tipo_visita, \'\') = :tipo_visita';
        $params[':tipo_visita'] = $filters['tipo_visita'];
    }
    if ($filters['asistio'] !== '') {
        $where[] = "COALESCE(NULLIF(TRIM(CAST(ps.asistio AS CHAR)), ''), '0') = :asistio";
        $params[':asistio'] = $filters['asistio'];
    }

    $sql = scdxBaseSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.fecha ASC, s.nsolicitud ASC, ps.apellidop ASC, ps.apellidom ASC, ps.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdxHeaderStyle($sheet, string $range): void
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D7E2']]],
    ]);
}

$hoy = date('Y-m-d');
$fechaDesde = trim((string)($_GET['fecha_desde'] ?? $hoy));
$fechaHasta = trim((string)($_GET['fecha_hasta'] ?? $hoy));
if ($fechaDesde === '') {
    $fechaDesde = $hoy;
}
if ($fechaHasta === '') {
    $fechaHasta = $hoy;
}
if ($fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$filters = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'id' => max(0, (int)($_GET['id'] ?? 0)),
    'empresa' => max(0, (int)($_GET['empresa'] ?? 0)),
    'solicitante' => max(0, (int)($_GET['solicitante'] ?? 0)),
    'patio' => max(0, (int)($_GET['patio'] ?? 0)),
    'proceso' => max(0, (int)($_GET['proceso'] ?? 0)),
    'habilitacionceo' => max(0, (int)($_GET['habilitacionceo'] ?? 0)),
    'tipohabilitacion' => trim((string)($_GET['tipohabilitacion'] ?? '')),
    'charla' => max(0, (int)($_GET['charla'] ?? 0)),
    'motivoreinduccion' => max(0, (int)($_GET['motivoreinduccion'] ?? 0)),
    'tipo_visita' => trim((string)($_GET['tipo_visita'] ?? '')),
    'asistio' => in_array((string)($_GET['asistio'] ?? ''), ['0', '1'], true) ? (string)$_GET['asistio'] : '',
];

$rows = scdxFetchRows($pdo, $filters);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Detalle Solicitudes');
$headers = ['Fecha', 'N° Solicitud', 'Empresa', 'Solicitante', 'Patio', 'Proceso', 'Habilitación CEO', 'Tipo Habilitación', 'Capacitación', 'Motivo Reinducción', 'Tipo de visita', 'Observación', 'RUT', 'Nombre', 'Apellidos', 'Cargo', 'Asistió'];
$sheet->fromArray($headers, null, 'A1');
scdxHeaderStyle($sheet, 'A1:Q1');

$rowNum = 2;
foreach ($rows as $row) {
    $sheet->fromArray([
        $row['fecha'],
        $row['nsolicitud'],
        $row['empresa'],
        $row['solicitante'],
        $row['patio'],
        $row['proceso'],
        $row['habilitacionceo'],
        $row['tipohabilitacion'],
        $row['capacitacion'],
        $row['motivo_reinduccion'],
        $row['tipo_visita'],
        $row['observacion'],
        $row['rut'],
        $row['nombre'],
        trim((string)$row['apellidop'] . ' ' . (string)$row['apellidom']),
        $row['cargo'],
        (int)$row['asistio'] === 1 ? 'Si' : 'No',
    ], null, 'A' . $rowNum);
    $rowNum++;
}

$lastRow = max(1, $rowNum - 1);
$sheet->getStyle('A1:Q' . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D5DDE5']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);

foreach (range('A', 'Q') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'solicitudes_consulta_detalle_' . $filters['fecha_desde'] . '_' . $filters['fecha_hasta'] . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
