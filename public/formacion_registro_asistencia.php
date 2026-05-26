<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

function fraEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fraMeses(): array
{
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}

function fraFechaExcel(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }
    try {
        return (new DateTimeImmutable($fecha))->format('m/d/y');
    } catch (Throwable $e) {
        return '';
    }
}

function fraFiltros(): array
{
    $anio = (int)($_GET['anio'] ?? date('Y'));
    if ($anio < 2000 || $anio > 2100) {
        $anio = (int)date('Y');
    }

    $mesDesde = max(1, min(12, (int)($_GET['mes_desde'] ?? 1)));
    $mesHasta = max(1, min(12, (int)($_GET['mes_hasta'] ?? date('n'))));
    if ($mesDesde > $mesHasta) {
        [$mesDesde, $mesHasta] = [$mesHasta, $mesDesde];
    }

    return [
        'anio' => $anio,
        'mes_desde' => $mesDesde,
        'mes_hasta' => $mesHasta,
        'servicio' => max(0, (int)($_GET['servicio'] ?? 0)),
    ];
}

function fraRangoFechas(array $filters): array
{
    $desde = sprintf('%04d-%02d-01', (int)$filters['anio'], (int)$filters['mes_desde']);
    $hastaBase = DateTimeImmutable::createFromFormat('!Y-n-j', (string)$filters['anio'] . '-' . (string)$filters['mes_hasta'] . '-1');
    $hasta = $hastaBase ? $hastaBase->modify('last day of this month')->format('Y-m-d') : date('Y-m-t');
    return [$desde, $hasta];
}

function fraFetchRows(PDO $pdo, array $filters): array
{
    [$desde, $hasta] = fraRangoFechas($filters);
    $where = [
        "q.estado_reporte IN ('APROBADO', 'REPROBADO')",
        'q.fecha_inicio IS NOT NULL',
        'q.fecha_termino IS NOT NULL',
        'q.fecha_reporte BETWEEN :desde AND :hasta',
    ];
    $params = [
        ':desde' => $desde,
        ':hasta' => $hasta,
    ];

    if ((int)$filters['servicio'] > 0) {
        $where[] = 'q.id_servicio = :servicio';
        $params[':servicio'] = (int)$filters['servicio'];
    }

    $sql = "
        SELECT
            q.correo,
            q.rut,
            q.nombres,
            q.apellidos,
            q.fecha_inicio,
            q.fecha_termino,
            q.nombre_formacion,
            q.descripcion,
            TIMESTAMPDIFF(MINUTE, q.fecha_inicio, q.fecha_termino) AS duracion_minutos
        FROM (
            SELECT
                f.id_servicio,
                COALESCE(c.correo, '') AS correo,
                p.rut,
                COALESCE(NULLIF(TRIM(p.nombre), ''), NULLIF(TRIM(c.nombre), ''), '') AS nombres,
                COALESCE(NULLIF(TRIM(p.apellidos), ''), NULLIF(TRIM(c.apellidos), ''), '') AS apellidos,
                ep.fecha_inicio,
                ep.fecha_termino,
                COALESCE(NULLIF(TRIM(fa.titulo), ''), NULLIF(TRIM(fs.servicio), ''), '') AS nombre_formacion,
                COALESCE(fs.descripcion, '') AS descripcion,
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
            INNER JOIN ceo_formacion f
                ON f.cuadrilla = p.id_cuadrilla
            LEFT JOIN (
                SELECT ep1.*
                FROM ceo_formacion_programadas ep1
                INNER JOIN (
                    SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                    FROM ceo_formacion_programadas
                    GROUP BY rut, id_servicio, cuadrilla
                ) ep2 ON ep1.id = ep2.max_id
            ) ep ON ep.rut = p.rut
                AND ep.id_servicio = f.id_servicio
                AND ep.cuadrilla = f.cuadrilla
            LEFT JOIN ceo_contratistas c
                ON c.rut = p.rut
            LEFT JOIN ceo_formacion_servicios fs
                ON fs.id = f.id_servicio
            LEFT JOIN ceo_formacion_agrupacion fa
                ON fa.id = ep.id_agrupacion
        ) q
        WHERE " . implode(' AND ', $where) . "
        ORDER BY q.fecha_reporte ASC, q.nombre_formacion ASC, q.apellidos ASC, q.nombres ASC, q.rut ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fraExportar(PDO $pdo, array $filters): void
{
    $template = __DIR__ . '/../docs/formato_registro_asistencia.xlsx';
    if (!is_file($template)) {
        throw new RuntimeException('No se encontro la plantilla docs/formato_registro_asistencia.xlsx.');
    }

    $rows = fraFetchRows($pdo, $filters);
    $spreadsheet = IOFactory::load($template);
    if (!$spreadsheet instanceof Spreadsheet) {
        throw new RuntimeException('No fue posible cargar la plantilla.');
    }

    $sheet = $spreadsheet->getSheetByName('Usuarios');
    if ($sheet === null) {
        throw new RuntimeException('La plantilla no contiene la hoja Usuarios.');
    }

    $maxRow = max(965, $sheet->getHighestRow());
    for ($row = 2; $row <= $maxRow; $row++) {
        for ($col = 'A'; $col <= 'O'; $col++) {
            $sheet->setCellValue($col . $row, null);
        }
    }

    $rowNum = 2;
    foreach ($rows as $r) {
        $duracion = is_numeric($r['duracion_minutos'] ?? null) ? max(0, (int)$r['duracion_minutos']) : 0;
        $horas = intdiv($duracion, 60);
        $minutos = $duracion;

        $sheet->setCellValueExplicit('A' . $rowNum, (string)($r['correo'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B' . $rowNum, (string)($r['rut'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $rowNum, (string)($r['nombres'] ?? ''));
        $sheet->setCellValue('D' . $rowNum, (string)($r['apellidos'] ?? ''));
        $sheet->setCellValueExplicit('E' . $rowNum, fraFechaExcel($r['fecha_inicio'] ?? null), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F' . $rowNum, fraFechaExcel($r['fecha_termino'] ?? null), DataType::TYPE_STRING);
        $sheet->setCellValue('G' . $rowNum, (string)($r['nombre_formacion'] ?? ''));
        $sheet->setCellValue('H' . $rowNum, (string)($r['descripcion'] ?? ''));
        $sheet->setCellValue('I' . $rowNum, $horas);
        $sheet->setCellValue('J' . $rowNum, $minutos);
        $sheet->setCellValue('K' . $rowNum, '');
        $sheet->setCellValue('L' . $rowNum, '');
        $sheet->setCellValue('M' . $rowNum, 'Online Async');
        $sheet->setCellValue('N' . $rowNum, 'Test');
        $sheet->setCellValue('O' . $rowNum, '');
        $rowNum++;
    }

    $sheet->setSelectedCell('A2');
    $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

    $filename = sprintf(
        'registro_asistencia_formacion_%04d_%02d_%02d.xlsx',
        (int)$filters['anio'],
        (int)$filters['mes_desde'],
        (int)$filters['mes_hasta']
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

$filters = fraFiltros();
$meses = fraMeses();
$servicios = [];
$error = '';

try {
    $servicios = $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC);
    if ((string)($_GET['exportar'] ?? '') === '1') {
        fraExportar($pdo, $filters);
    }
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible generar el registro de asistencia.';
}

[$desde, $hasta] = fraRangoFechas($filters);
$totalPreview = null;
if ($error === '') {
    try {
        $totalPreview = count(fraFetchRows($pdo, $filters));
    } catch (Throwable $e) {
        $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible consultar los registros del periodo.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Registro de Asistencia Formación - <?= fraEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; font-size:.92rem; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .brand-title { color:#0065a4; font-weight:700; font-size:1.1rem; }
    .hero { background:linear-gradient(135deg,#eaf4fb,#fff); border:1px solid #d8e7f2; }
    .card { border:none; box-shadow:0 2px 8px rgba(28,48,74,.07); }
    .chip { display:inline-flex; gap:.4rem; align-items:center; color:#0b5c91; background:#fff; border:1px solid #cfe3f2; border-radius:999px; padding:.25rem .7rem; font-size:.8rem; font-weight:600; }
    .form-label { font-weight:600; color:#344054; font-size:.83rem; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= fraEsc(APP_LOGO) ?>" alt="Logo" style="height:54px;">
      <div>
        <div class="brand-title mb-0"><?= fraEsc(APP_NAME) ?></div>
        <small class="text-secondary"><?= fraEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="formacion_resumen_mensual.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left"></i> Dashboard Formaciones</a>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <section class="hero rounded-4 p-4 mb-4">
    <span class="chip mb-2"><i class="bi bi-file-earmark-spreadsheet"></i> <?= fraEsc($desde) ?> / <?= fraEsc($hasta) ?></span>
    <h1 class="h3 mb-1">Registro de Asistencia de Formación</h1>
    <p class="mb-0 text-muted">Exporta los examenes ejecutados al formato corporativo de la hoja Usuarios.</p>
  </section>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= fraEsc($error) ?></div>
  <?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Año</label>
          <input type="number" name="anio" class="form-control form-control-sm" value="<?= (int)$filters['anio'] ?>" min="2000" max="2100">
        </div>
        <div class="col-md-2">
          <label class="form-label">Mes desde</label>
          <select name="mes_desde" class="form-select form-select-sm">
            <?php foreach ($meses as $n => $m): ?>
              <option value="<?= (int)$n ?>" <?= (int)$filters['mes_desde'] === (int)$n ? 'selected' : '' ?>><?= fraEsc($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mes hasta</label>
          <select name="mes_hasta" class="form-select form-select-sm">
            <?php foreach ($meses as $n => $m): ?>
              <option value="<?= (int)$n ?>" <?= (int)$filters['mes_hasta'] === (int)$n ? 'selected' : '' ?>><?= fraEsc($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Servicio</label>
          <select name="servicio" class="form-select form-select-sm">
            <option value="0">Todos</option>
            <?php foreach ($servicios as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= (int)$filters['servicio'] === (int)$s['id'] ? 'selected' : '' ?>><?= fraEsc($s['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Consultar</button>
          <button type="submit" name="exportar" value="1" class="btn btn-success btn-sm"><i class="bi bi-download"></i> Exportar Excel</button>
          <a href="formacion_registro_asistencia.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>
      </form>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <div class="text-muted small fw-semibold">Registros encontrados</div>
        <div class="display-6 fw-bold text-primary mb-0"><?= $totalPreview === null ? '-' : (int)$totalPreview ?></div>
      </div>
      <div class="text-muted">
        Se incluyen solo pruebas de formacion aprobadas o reprobadas, ejecutadas con fecha de inicio y termino registradas.
      </div>
    </div>
  </div>
</main>
</body>
</html>
