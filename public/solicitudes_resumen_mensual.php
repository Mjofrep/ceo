<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

function solEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function solMeses(): array
{
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}

function solEstadoLabel(string $estado): string
{
    return match (strtoupper(trim($estado))) {
        'I' => 'INICIAL',
        'A' => 'AUTORIZADA',
        'F' => 'FINALIZADA',
        'C' => 'CANCELADA',
        default => strtoupper(trim($estado)) ?: 'SIN ESTADO',
    };
}

function solBaseSql(): string
{
    return "
        SELECT
            q.*,
            YEAR(q.fecha_reporte) AS anio_reporte,
            MONTH(q.fecha_reporte) AS mes_reporte
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

function solFetchRows(PDO $pdo, array $filters): array
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

    $sql = 'SELECT * FROM (' . solBaseSql() . ') q WHERE ' . implode(' AND ', $where) . ' ORDER BY q.fecha_reporte DESC, q.nsolicitud DESC, q.habilitacionceo ASC, q.apellidos ASC, q.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

$habilitaciones = $pdo->query('SELECT id, desc_tipo FROM ceo_habilitaciontipo ORDER BY desc_tipo')->fetchAll(PDO::FETCH_ASSOC);
$empresas = $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$uos = $pdo->query('SELECT id, desc_uo FROM ceo_uo ORDER BY desc_uo')->fetchAll(PDO::FETCH_ASSOC);
$procesos = $pdo->query('SELECT id, desc_proceso FROM ceo_procesos ORDER BY desc_proceso')->fetchAll(PDO::FETCH_ASSOC);
$patios = $pdo->query('SELECT id, desc_patios FROM ceo_patios ORDER BY desc_patios')->fetchAll(PDO::FETCH_ASSOC);
$meses = solMeses();
$rows = [];
$error = '';

try {
    $rows = solFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el resumen de solicitudes.';
}

$solicitudesSet = [];
$kpi = ['SOLICITUDES' => 0, 'CONVOCADOS' => 0, 'ASISTIDOS' => 0, 'NO_ASISTIERON' => 0];
$habSet = [];
$porHab = [];
$porEstado = [];
$mensualConvocados = [];
$mensualAsistidos = [];
$matrix = [];
$totalesHab = [];
$totalesMes = [];
$granTotal = ['solicitudes' => 0, 'convocados' => 0, 'asistidos' => 0];

for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $matrix[$m] = [];
    $mensualConvocados[$m] = 0;
    $mensualAsistidos[$m] = 0;
    $totalesMes[$m] = ['solicitudes' => [], 'convocados' => 0, 'asistidos' => 0];
}

foreach ($rows as $r) {
    $nsol = (int)($r['nsolicitud'] ?? 0);
    $mes = (int)$r['mes_reporte'];
    $hab = trim((string)($r['habilitacionceo'] ?? 'Sin Habilitacion CEO')) ?: 'Sin Habilitacion CEO';
    $estado = solEstadoLabel((string)($r['estado'] ?? ''));
    $asistio = (int)($r['asistio'] ?? 0) === 1;
    $tieneParticipante = trim((string)($r['rut'] ?? '')) !== '';

    $solicitudesSet[$nsol] = true;
    $habSet[$hab] = true;
    $porEstado[$estado] = ($porEstado[$estado] ?? 0) + ($tieneParticipante ? 1 : 0);
    $porHab[$hab] = ($porHab[$hab] ?? 0) + ($tieneParticipante && $asistio ? 1 : 0);

    if (!isset($matrix[$mes][$hab])) {
        $matrix[$mes][$hab] = ['solicitudes' => [], 'convocados' => 0, 'asistidos' => 0];
    }
    if (!isset($totalesHab[$hab])) {
        $totalesHab[$hab] = ['solicitudes' => [], 'convocados' => 0, 'asistidos' => 0];
    }

    $matrix[$mes][$hab]['solicitudes'][$nsol] = true;
    $totalesHab[$hab]['solicitudes'][$nsol] = true;
    $totalesMes[$mes]['solicitudes'][$nsol] = true;

    if ($tieneParticipante) {
        $kpi['CONVOCADOS']++;
        $matrix[$mes][$hab]['convocados']++;
        $totalesHab[$hab]['convocados']++;
        $totalesMes[$mes]['convocados']++;
        $mensualConvocados[$mes]++;
        if ($asistio) {
            $kpi['ASISTIDOS']++;
            $matrix[$mes][$hab]['asistidos']++;
            $totalesHab[$hab]['asistidos']++;
            $totalesMes[$mes]['asistidos']++;
            $mensualAsistidos[$mes]++;
        }
    }
}

$kpi['SOLICITUDES'] = count($solicitudesSet);
$kpi['NO_ASISTIERON'] = max(0, $kpi['CONVOCADOS'] - $kpi['ASISTIDOS']);
$asistenciaPct = $kpi['CONVOCADOS'] > 0 ? round(($kpi['ASISTIDOS'] / $kpi['CONVOCADOS']) * 100, 1) : 0.0;
$habCols = array_keys($habSet);
sort($habCols, SORT_NATURAL | SORT_FLAG_CASE);
ksort($porHab);
ksort($porEstado);
$granTotal['solicitudes'] = $kpi['SOLICITUDES'];
$granTotal['convocados'] = $kpi['CONVOCADOS'];
$granTotal['asistidos'] = $kpi['ASISTIDOS'];

$qs = http_build_query($filters);
$excelUrl = 'solicitudes_resumen_mensual_excel.php?' . $qs;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen Mensual de Solicitudes - <?= solEsc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--ink:#172033;--blue:#0a5c8f;--line:#d8e5ef;--paper:#fbfdff;--mint:#20a68a;--amber:#f2b84b;--rose:#dc5b6c;}
body{background:radial-gradient(circle at top left,#e9f6ff 0,#f7fbff 30%,#f3f6f9 100%);color:var(--ink);font-family:Georgia,'Times New Roman',serif;}
.topbar{background:rgba(255,255,255,.86);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);}
.brand-title{color:var(--blue);font-weight:700;letter-spacing:.02em;}
.hero{border:1px solid var(--line);background:linear-gradient(135deg,#ffffff 0%,#eef8ff 68%,#e8fff8 100%);box-shadow:0 18px 50px rgba(19,66,101,.08);}
.hero h1{font-weight:800;color:#0b4c76;letter-spacing:-.03em;}
.card{border:1px solid var(--line);box-shadow:0 10px 30px rgba(19,66,101,.06);}
.kpi{position:relative;overflow:hidden;border-radius:18px;background:#fff;min-height:118px;}
.kpi:after{content:'';position:absolute;right:-28px;top:-28px;width:88px;height:88px;border-radius:50%;background:rgba(10,92,143,.09);}
.kpi .num{font-size:2rem;font-weight:800;color:#0b4c76;}
.table thead th{background:#eaf4fb;color:#25445c;white-space:nowrap;}
.table-sticky{max-height:540px;overflow:auto;border:1px solid var(--line);border-radius:16px;background:#fff;}
.table-sticky thead th{position:sticky;top:0;z-index:2;}
.section-title{font-weight:800;color:#0b4c76;}
.chip{display:inline-flex;align-items:center;gap:.4rem;padding:.22rem .55rem;border-radius:999px;background:#eef7ff;color:#0b4c76;font-size:.78rem;font-weight:700;}
canvas{max-height:320px;}
.chart-legend{display:grid;gap:7px;margin-top:14px;font-size:12px;}
.chart-legend-item{display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px dashed #d8e5ef;padding-bottom:5px;}
.chart-legend-item:last-child{border-bottom:0;}
.chart-legend-label{display:flex;align-items:center;gap:8px;min-width:0;}
.chart-legend-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.chart-legend-dot{width:11px;height:11px;border-radius:50%;box-shadow:0 0 0 2px #fff,0 0 0 3px rgba(23,32,51,.08);flex:0 0 auto;}
.chart-legend-value{font-weight:800;color:#17324d;white-space:nowrap;}
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= solEsc(APP_LOGO) ?>" alt="Logo" style="height:54px;">
      <div><div class="brand-title h5 mb-0"><?= solEsc(APP_NAME) ?></div><small class="text-muted"><?= solEsc(APP_SUBTITLE) ?></small></div>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= solEsc($excelUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
      <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
    </div>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <section class="hero rounded-4 p-4 mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
      <div>
        <span class="chip mb-2"><i class="bi bi-calendar3"></i> <?= (int)$anio ?> / <?= solEsc($meses[$mesDesde]) ?> - <?= solEsc($meses[$mesHasta]) ?></span>
        <h1 class="mb-1">Resumen Mensual de Solicitudes</h1>
        <p class="mb-0 text-muted">Solicitudes por mes, Habilitacion CEO y asistencia real de participantes.</p>
      </div>
      <div class="text-end text-muted small">Solicitudes y personas se cuentan por separado.</div>
    </div>
  </section>

  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= solEsc($error) ?></div><?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-1"><label class="form-label">Año</label><input type="number" name="anio" class="form-control form-control-sm" value="<?= (int)$anio ?>"></div>
        <div class="col-md-2"><label class="form-label">Mes desde</label><select name="mes_desde" class="form-select form-select-sm"><?php foreach ($meses as $n => $m): ?><option value="<?= $n ?>" <?= $mesDesde === $n ? 'selected' : '' ?>><?= solEsc($m) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Mes hasta</label><select name="mes_hasta" class="form-select form-select-sm"><?php foreach ($meses as $n => $m): ?><option value="<?= $n ?>" <?= $mesHasta === $n ? 'selected' : '' ?>><?= solEsc($m) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Habilitación CEO</label><select name="habilitacionceo" class="form-select form-select-sm"><option value="0">Todas</option><?php foreach ($habilitaciones as $h): ?><option value="<?= (int)$h['id'] ?>" <?= (int)$filters['habilitacionceo'] === (int)$h['id'] ? 'selected' : '' ?>><?= solEsc($h['desc_tipo']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Empresa</label><select name="empresa" class="form-select form-select-sm"><option value="0">Todas</option><?php foreach ($empresas as $e): ?><option value="<?= (int)$e['id'] ?>" <?= (int)$filters['empresa'] === (int)$e['id'] ? 'selected' : '' ?>><?= solEsc($e['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Estado</label><select name="estado" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (['I','A','F','C'] as $estado): ?><option value="<?= solEsc($estado) ?>" <?= $filters['estado'] === $estado ? 'selected' : '' ?>><?= solEsc(solEstadoLabel($estado)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">UO</label><select name="uo" class="form-select form-select-sm"><option value="0">Todas</option><?php foreach ($uos as $uo): ?><option value="<?= (int)$uo['id'] ?>" <?= (int)$filters['uo'] === (int)$uo['id'] ? 'selected' : '' ?>><?= solEsc($uo['desc_uo']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i></button><a href="solicitudes_resumen_mensual.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a></div>
        <div class="col-md-3"><label class="form-label">Proceso</label><select name="proceso" class="form-select form-select-sm"><option value="0">Todos</option><?php foreach ($procesos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$filters['proceso'] === (int)$p['id'] ? 'selected' : '' ?>><?= solEsc($p['desc_proceso']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Patio</label><select name="patio" class="form-select form-select-sm"><option value="0">Todos</option><?php foreach ($patios as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$filters['patio'] === (int)$p['id'] ? 'selected' : '' ?>><?= solEsc($p['desc_patios']) ?></option><?php endforeach; ?></select></div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ([['SOLICITUDES','Solicitudes','primary'],['CONVOCADOS','Convocados','info'],['ASISTIDOS','Asistidos','success'],['NO_ASISTIERON','No asistieron','danger']] as $item): ?>
      <div class="col-md"><div class="card kpi p-3"><div class="text-muted small fw-semibold"><?= solEsc($item[1]) ?></div><div class="num"><?= (int)$kpi[$item[0]] ?></div><span class="badge text-bg-<?= solEsc($item[2]) ?>">Periodo</span></div></div>
    <?php endforeach; ?>
    <div class="col-md"><div class="card kpi p-3"><div class="text-muted small fw-semibold">% asistencia</div><div class="num"><?= solEsc((string)$asistenciaPct) ?>%</div><span class="badge text-bg-warning">Asistidos/Convocados</span></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Asistidos por Habilitación CEO</h6><canvas id="chartHab"></canvas><div id="legendHab" class="chart-legend"></div></div></div></div>
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Convocados vs asistidos</h6><canvas id="chartMensual"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Distribución por estado</h6><canvas id="chartEstado"></canvas><div id="legendEstado" class="chart-legend"></div></div></div></div>
  </div>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <h5 class="section-title mb-3"><i class="bi bi-grid-3x3-gap me-2"></i>Totales por mes y Habilitación CEO</h5>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-white">
          <thead>
            <tr><th rowspan="2">Mes</th><?php foreach ($habCols as $hab): ?><th class="text-center" colspan="3"><?= solEsc($hab) ?></th><?php endforeach; ?><th class="text-center" colspan="3">Total</th></tr>
            <tr><?php foreach ($habCols as $hab): ?><th class="text-end">Solic.</th><th class="text-end">Conv.</th><th class="text-end">Asist.</th><?php endforeach; ?><th class="text-end">Solic.</th><th class="text-end">Conv.</th><th class="text-end">Asist.</th></tr>
          </thead>
          <tbody>
          <?php for ($m = $mesDesde; $m <= $mesHasta; $m++): ?>
            <tr>
              <th><?= solEsc($meses[$m]) ?></th>
              <?php foreach ($habCols as $hab): ?>
                <td class="text-end"><?= count($matrix[$m][$hab]['solicitudes'] ?? []) ?></td><td class="text-end"><?= (int)($matrix[$m][$hab]['convocados'] ?? 0) ?></td><td class="text-end"><?= (int)($matrix[$m][$hab]['asistidos'] ?? 0) ?></td>
              <?php endforeach; ?>
              <td class="text-end fw-bold"><?= count($totalesMes[$m]['solicitudes'] ?? []) ?></td><td class="text-end fw-bold"><?= (int)($totalesMes[$m]['convocados'] ?? 0) ?></td><td class="text-end fw-bold"><?= (int)($totalesMes[$m]['asistidos'] ?? 0) ?></td>
            </tr>
          <?php endfor; ?>
          <tr class="table-primary"><th>Total</th><?php foreach ($habCols as $hab): ?><th class="text-end"><?= count($totalesHab[$hab]['solicitudes'] ?? []) ?></th><th class="text-end"><?= (int)($totalesHab[$hab]['convocados'] ?? 0) ?></th><th class="text-end"><?= (int)($totalesHab[$hab]['asistidos'] ?? 0) ?></th><?php endforeach; ?><th class="text-end"><?= (int)$granTotal['solicitudes'] ?></th><th class="text-end"><?= (int)$granTotal['convocados'] ?></th><th class="text-end"><?= (int)$granTotal['asistidos'] ?></th></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body">
      <h5 class="section-title mb-3"><i class="bi bi-person-lines-fill me-2"></i>BD detalle de participantes</h5>
      <div class="table-sticky">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr><th>Fecha solicitud</th><th>N° Solicitud</th><th>Estado</th><th>Habilitación CEO</th><th>RUT</th><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Asistió</th><th>Fecha asistencia</th><th>Proceso</th><th>UO</th><th>Patio</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="13" class="text-center text-muted py-4">Sin registros para los filtros seleccionados.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr><td><?= solEsc($r['fecha_solicitud']) ?></td><td><?= solEsc($r['nsolicitud']) ?></td><td><span class="badge text-bg-secondary"><?= solEsc(solEstadoLabel((string)$r['estado'])) ?></span></td><td><?= solEsc($r['habilitacionceo']) ?></td><td><?= solEsc($r['rut']) ?></td><td><?= solEsc(trim((string)$r['nombre'] . ' ' . (string)$r['apellidos'])) ?></td><td><?= solEsc($r['empresa']) ?></td><td><?= solEsc($r['cargo']) ?></td><td><?= (int)$r['asistio'] === 1 ? 'Si' : 'No' ?></td><td><?= solEsc($r['fechaasistio']) ?></td><td><?= solEsc($r['proceso']) ?></td><td><?= solEsc($r['uo']) ?></td><td><?= solEsc($r['patio']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
const palette = ['#4472C4','#ED7D31','#A5A5A5','#70AD47','#C00000','#5B9BD5','#FFC000','#7030A0'];
const habLabels = <?= json_encode(array_keys($porHab), JSON_UNESCAPED_UNICODE) ?>;
const habData = <?= json_encode(array_values($porHab), JSON_UNESCAPED_UNICODE) ?>;
const estadoLabels = <?= json_encode(array_keys($porEstado), JSON_UNESCAPED_UNICODE) ?>;
const estadoData = <?= json_encode(array_values($porEstado), JSON_UNESCAPED_UNICODE) ?>;
const mesLabels = <?= json_encode(array_map(fn($m) => $meses[$m], range($mesDesde, $mesHasta)), JSON_UNESCAPED_UNICODE) ?>;
const mensualConvocados = <?= json_encode(array_values(array_intersect_key($mensualConvocados, array_flip(range($mesDesde, $mesHasta)))), JSON_UNESCAPED_UNICODE) ?>;
const mensualAsistidos = <?= json_encode(array_values(array_intersect_key($mensualAsistidos, array_flip(range($mesDesde, $mesHasta)))), JSON_UNESCAPED_UNICODE) ?>;
function chartPercent(value, total) { const percent = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0'; return `${value} (${percent}%)`; }
function chartLegend(containerId, labels, data, colors) { const container = document.getElementById(containerId); if (!container) return; const total = data.reduce((sum, value) => sum + Number(value || 0), 0); container.innerHTML = labels.map((label, index) => { const value = Number(data[index] || 0); const color = colors[index % colors.length]; return `<div class="chart-legend-item"><span class="chart-legend-label"><span class="chart-legend-dot" style="background:${color}"></span><span class="chart-legend-text">${label}</span></span><span class="chart-legend-value">${chartPercent(value, total)}</span></div>`; }).join(''); }
const doughnutOptions = {responsive:true,cutout:'38%',rotation:-70,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(context){const total=context.dataset.data.reduce((sum,value)=>sum+Number(value||0),0);const value=Number(context.raw||0);return `${context.label}: ${chartPercent(value,total)}`;}}}}};
new Chart(document.getElementById('chartHab'), {type:'doughnut', data:{labels:habLabels,datasets:[{data:habData,backgroundColor:palette,borderColor:'#ffffff',borderWidth:4,hoverOffset:10}]}, options:doughnutOptions});
new Chart(document.getElementById('chartMensual'), {type:'bar', data:{labels:mesLabels,datasets:[{label:'Convocados',data:mensualConvocados,backgroundColor:'#0a5c8f'},{label:'Asistidos',data:mensualAsistidos,backgroundColor:'#20a68a'}]}, options:{scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('chartEstado'), {type:'doughnut', data:{labels:estadoLabels,datasets:[{data:estadoData,backgroundColor:palette,borderColor:'#ffffff',borderWidth:4,hoverOffset:10}]}, options:doughnutOptions});
chartLegend('legendHab', habLabels, habData, palette);
chartLegend('legendEstado', estadoLabels, estadoData, palette);
</script>
</body>
</html>
