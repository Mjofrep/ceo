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
$rolUsuario = (int)($_SESSION['auth']['id_rol'] ?? 0);
$mostrarEstadoDetalle = ($rolUsuario === 1);
$mostrarRealizoPruebaDetalle = ($rolUsuario === 5);
$mostrarColumnaPruebaDetalle = ($mostrarEstadoDetalle || $mostrarRealizoPruebaDetalle);

function frmEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function frmMeses(): array
{
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}

function frmSelectedIds(string $key): array
{
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) {
        $raw = $raw === '' ? [] : [$raw];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function frmBaseSql(): string
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
                COALESCE(c.id_empresa, f.empresa) AS id_empresa,
                COALESCE(ec.nombre, e.nombre) AS empresa,
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
            LEFT JOIN ceo_contratistas c ON c.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_empresas ec ON ec.id = c.id_empresa
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

function frmFetchRows(PDO $pdo, array $filters): array
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
    if (!empty($filters['empresa'])) {
        $placeholders = [];
        foreach ($filters['empresa'] as $index => $empresaId) {
            $placeholder = ':empresa_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int)$empresaId;
        }
        $where[] = 'q.id_empresa IN (' . implode(', ', $placeholders) . ')';
    }
    if ((int)$filters['uo'] > 0) {
        $where[] = 'q.id_uo = :uo';
        $params[':uo'] = (int)$filters['uo'];
    }
    if ((string)$filters['estado'] !== '') {
        $where[] = 'q.estado_reporte = :estado';
        $params[':estado'] = (string)$filters['estado'];
    }

    $sql = 'SELECT * FROM (' . frmBaseSql() . ') q WHERE ' . implode(' AND ', $where) . ' ORDER BY q.fecha_reporte DESC, q.servicio ASC, q.empresa ASC, q.apellidos ASC, q.nombre ASC';
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
    'servicio' => (int)($_GET['servicio'] ?? 0),
    'empresa' => frmSelectedIds('empresa'),
    'uo' => (int)($_GET['uo'] ?? 0),
    'estado' => strtoupper(trim((string)($_GET['estado'] ?? ''))),
];

$servicios = $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio')->fetchAll(PDO::FETCH_ASSOC);
$empresas = $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$uos = $pdo->query('SELECT id, desc_uo FROM ceo_uo ORDER BY desc_uo')->fetchAll(PDO::FETCH_ASSOC);
$meses = frmMeses();
$rows = [];
$error = '';

try {
    $rows = frmFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el resumen de formaciones.';
}

$estados = ['APROBADO', 'REPROBADO', 'PENDIENTE', 'ANULADA'];
$kpi = ['TOTAL' => count($rows), 'APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0, 'ANULADA' => 0, 'OTROS' => 0];
$servicioSet = [];
$matrixEstados = [];
$porServicio = [];
$porEstado = [];
$mensualTotal = [];
$estadosTotales = ['APROBADO' => 'Aprob.', 'REPROBADO' => 'Reprob.', 'PENDIENTE' => 'Pend.'];
$totalesServicioEstado = [];
$totalesMesEstado = [];
$granTotalEstado = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];

for ($m = $mesDesde; $m <= $mesHasta; $m++) {
    $matrixEstados[$m] = [];
    $mensualTotal[$m] = 0;
    $totalesMesEstado[$m] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
}

foreach ($rows as $r) {
    $estado = strtoupper(trim((string)($r['estado_reporte'] ?? 'PENDIENTE')));
    if (isset($kpi[$estado])) {
        $kpi[$estado]++;
    } else {
        $kpi['OTROS']++;
    }
    $servicio = trim((string)($r['servicio'] ?? 'Sin servicio')) ?: 'Sin servicio';
    $mes = (int)$r['mes_reporte'];
    $servicioSet[$servicio] = true;
    $porServicio[$servicio] = ($porServicio[$servicio] ?? 0) + 1;
    $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;
    $mensualTotal[$mes] = ($mensualTotal[$mes] ?? 0) + 1;

    if ($estado === 'ANULADA') {
        continue;
    }
    $estadoTabla = in_array($estado, ['APROBADO', 'REPROBADO'], true) ? $estado : 'PENDIENTE';
    if (!isset($matrixEstados[$mes][$servicio])) {
        $matrixEstados[$mes][$servicio] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
    }
    if (!isset($totalesServicioEstado[$servicio])) {
        $totalesServicioEstado[$servicio] = ['APROBADO' => 0, 'REPROBADO' => 0, 'PENDIENTE' => 0];
    }
    $matrixEstados[$mes][$servicio][$estadoTabla]++;
    $totalesServicioEstado[$servicio][$estadoTabla]++;
    $totalesMesEstado[$mes][$estadoTabla]++;
    $granTotalEstado[$estadoTabla]++;
}

$servicioCols = array_keys($servicioSet);
sort($servicioCols, SORT_NATURAL | SORT_FLAG_CASE);
ksort($porServicio);
ksort($porEstado);

$qs = http_build_query($filters);
$excelUrl = 'formacion_resumen_mensual_excel.php?' . $qs;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen Mensual de Formaciones - <?= frmEsc(APP_NAME) ?></title>
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
      <img src="<?= frmEsc(APP_LOGO) ?>" alt="Logo" style="height:54px;">
      <div><div class="brand-title h5 mb-0"><?= frmEsc(APP_NAME) ?></div><small class="text-muted"><?= frmEsc(APP_SUBTITLE) ?></small></div>
    </div>
    <div class="d-flex gap-2">
      <a href="https://www.noetica.cl/ceo.noetica.cl/public/formacion_registro_asistencia.php" class="btn btn-outline-success btn-sm"><i class="bi bi-clipboard-check"></i> Registro asistencia</a>
      <a href="<?= frmEsc($excelUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar Excel</a>
      <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
    </div>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <section class="hero rounded-4 p-4 mb-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
      <div>
        <span class="chip mb-2"><i class="bi bi-calendar3"></i> <?= (int)$anio ?> / <?= frmEsc($meses[$mesDesde]) ?> - <?= frmEsc($meses[$mesHasta]) ?></span>
        <h1 class="mb-1">Resumen Mensual de Formaciones</h1>
        <p class="mb-0 text-muted">Participaciones por mes, tematica, estado y detalle de alumnos.</p>
      </div>
      <div class="text-end text-muted small">Cada participacion cuenta individualmente.</div>
    </div>
  </section>

  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= frmEsc($error) ?></div><?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-1"><label class="form-label">Año</label><input type="number" name="anio" class="form-control form-control-sm" value="<?= (int)$anio ?>"></div>
        <div class="col-md-2"><label class="form-label">Mes desde</label><select name="mes_desde" class="form-select form-select-sm"><?php foreach ($meses as $n => $m): ?><option value="<?= $n ?>" <?= $mesDesde === $n ? 'selected' : '' ?>><?= frmEsc($m) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Mes hasta</label><select name="mes_hasta" class="form-select form-select-sm"><?php foreach ($meses as $n => $m): ?><option value="<?= $n ?>" <?= $mesHasta === $n ? 'selected' : '' ?>><?= frmEsc($m) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Servicio</label><select name="servicio" class="form-select form-select-sm"><option value="0">Todos</option><?php foreach ($servicios as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$filters['servicio'] === (int)$s['id'] ? 'selected' : '' ?>><?= frmEsc($s['servicio']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Empresa</label><select name="empresa[]" class="form-select form-select-sm" multiple size="5"><?php foreach ($empresas as $e): ?><option value="<?= (int)$e['id'] ?>" <?= in_array((int)$e['id'], $filters['empresa'], true) ? 'selected' : '' ?>><?= frmEsc($e['nombre']) ?></option><?php endforeach; ?></select><div class="form-text">Puedes seleccionar varias con Ctrl o Cmd.</div></div>
        <div class="col-md-1"><label class="form-label">Estado</label><select name="estado" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($estados as $estado): ?><option value="<?= frmEsc($estado) ?>" <?= $filters['estado'] === $estado ? 'selected' : '' ?>><?= frmEsc($estado) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">UO</label><select name="uo" class="form-select form-select-sm"><option value="0">Todas</option><?php foreach ($uos as $uo): ?><option value="<?= (int)$uo['id'] ?>" <?= (int)$filters['uo'] === (int)$uo['id'] ? 'selected' : '' ?>><?= frmEsc($uo['desc_uo']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i></button><a href="formacion_resumen_mensual.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a></div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ([['TOTAL','Participaciones','primary'],['APROBADO','Aprobados','success'],['REPROBADO','Reprobados','danger'],['PENDIENTE','Pendientes','warning'],['ANULADA','Anuladas','secondary']] as $item): ?>
      <div class="col-md"><div class="card kpi p-3"><div class="text-muted small fw-semibold"><?= frmEsc($item[1]) ?></div><div class="num"><?= (int)$kpi[$item[0]] ?></div><span class="badge text-bg-<?= frmEsc($item[2]) ?>">Periodo</span></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Acumulado por servicio</h6><canvas id="chartServicio"></canvas><div id="legendServicio" class="chart-legend"></div></div></div></div>
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Participaciones mensuales</h6><canvas id="chartMensual"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card rounded-4 h-100"><div class="card-body"><h6 class="section-title">Distribucion por estado</h6><canvas id="chartEstado"></canvas><div id="legendEstado" class="chart-legend"></div></div></div></div>
  </div>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <h5 class="section-title mb-3"><i class="bi bi-grid-3x3-gap me-2"></i>Totales por mes y tematica</h5>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle bg-white">
          <thead>
            <tr>
              <th rowspan="2">Mes</th>
              <?php foreach ($servicioCols as $srv): ?><th class="text-center" colspan="3"><?= frmEsc($srv) ?></th><?php endforeach; ?>
              <th class="text-center" colspan="3">Total</th>
            </tr>
            <tr>
              <?php foreach ($servicioCols as $srv): ?>
                <?php foreach ($estadosTotales as $label): ?><th class="text-end"><?= frmEsc($label) ?></th><?php endforeach; ?>
              <?php endforeach; ?>
              <?php foreach ($estadosTotales as $label): ?><th class="text-end"><?= frmEsc($label) ?></th><?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php for ($m = $mesDesde; $m <= $mesHasta; $m++): ?>
            <tr>
              <th><?= frmEsc($meses[$m]) ?></th>
              <?php foreach ($servicioCols as $srv): ?>
                <?php foreach ($estadosTotales as $estadoKey => $label): ?><td class="text-end"><?= (int)($matrixEstados[$m][$srv][$estadoKey] ?? 0) ?></td><?php endforeach; ?>
              <?php endforeach; ?>
              <?php foreach ($estadosTotales as $estadoKey => $label): ?><td class="text-end fw-bold"><?= (int)($totalesMesEstado[$m][$estadoKey] ?? 0) ?></td><?php endforeach; ?>
            </tr>
          <?php endfor; ?>
          <tr class="table-primary">
            <th>Total</th>
            <?php foreach ($servicioCols as $srv): ?>
              <?php foreach ($estadosTotales as $estadoKey => $label): ?><th class="text-end"><?= (int)($totalesServicioEstado[$srv][$estadoKey] ?? 0) ?></th><?php endforeach; ?>
            <?php endforeach; ?>
            <?php foreach ($estadosTotales as $estadoKey => $label): ?><th class="text-end"><?= (int)($granTotalEstado[$estadoKey] ?? 0) ?></th><?php endforeach; ?>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body">
      <h5 class="section-title mb-3"><i class="bi bi-person-lines-fill me-2"></i>BD detalle</h5>
      <div class="table-sticky">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead><tr><th>Fecha reporte</th><?php if ($mostrarEstadoDetalle): ?><th>Estado</th><?php elseif ($mostrarRealizoPruebaDetalle): ?><th>Realizó prueba</th><?php endif; ?><th>RUT</th><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Servicio</th><th>Cuadrilla</th><th>Fecha programacion</th><th>Fecha rendicion</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?><tr><td colspan="<?= $mostrarColumnaPruebaDetalle ? 10 : 9 ?>" class="text-center text-muted py-4">Sin registros para los filtros seleccionados.</td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= frmEsc($r['fecha_reporte']) ?></td>
              <?php if ($mostrarEstadoDetalle): ?><td><span class="badge text-bg-<?= $r['estado_reporte'] === 'APROBADO' ? 'success' : ($r['estado_reporte'] === 'REPROBADO' ? 'danger' : ($r['estado_reporte'] === 'ANULADA' ? 'secondary' : 'warning')) ?>"><?= frmEsc($r['estado_reporte']) ?></span></td><?php endif; ?>
              <?php if ($mostrarRealizoPruebaDetalle): ?><td><?= in_array((string)$r['estado_reporte'], ['APROBADO', 'REPROBADO'], true) ? 'Si' : 'No' ?></td><?php endif; ?>
              <td><?= frmEsc($r['rut']) ?></td><td><?= frmEsc(trim((string)$r['nombre'] . ' ' . (string)$r['apellidos'])) ?></td><td><?= frmEsc($r['empresa']) ?></td><td><?= frmEsc($r['cargo']) ?></td><td><?= frmEsc($r['servicio']) ?></td><td><?= frmEsc($r['cuadrilla']) ?></td><td><?= frmEsc($r['fecha_programacion']) ?></td><td><?= frmEsc($r['fecha_resultado']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
const palette = ['#4472C4','#ED7D31','#A5A5A5','#70AD47','#C00000','#5B9BD5','#FFC000','#7030A0'];
const servicioLabels = <?= json_encode(array_keys($porServicio), JSON_UNESCAPED_UNICODE) ?>;
const servicioData = <?= json_encode(array_values($porServicio), JSON_UNESCAPED_UNICODE) ?>;
const estadoLabels = <?= json_encode(array_keys($porEstado), JSON_UNESCAPED_UNICODE) ?>;
const estadoData = <?= json_encode(array_values($porEstado), JSON_UNESCAPED_UNICODE) ?>;
const mesLabels = <?= json_encode(array_map(fn($m) => $meses[$m], range($mesDesde, $mesHasta)), JSON_UNESCAPED_UNICODE) ?>;
const mesData = <?= json_encode(array_values(array_intersect_key($mensualTotal, array_flip(range($mesDesde, $mesHasta)))), JSON_UNESCAPED_UNICODE) ?>;

function chartPercent(value, total) {
  const percent = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
  return `${value} (${percent}%)`;
}

function chartLegend(containerId, labels, data, colors) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const total = data.reduce((sum, value) => sum + Number(value || 0), 0);
  container.innerHTML = labels.map((label, index) => {
    const value = Number(data[index] || 0);
    const color = colors[index % colors.length];
    return `<div class="chart-legend-item">
      <span class="chart-legend-label"><span class="chart-legend-dot" style="background:${color}"></span><span class="chart-legend-text">${label}</span></span>
      <span class="chart-legend-value">${chartPercent(value, total)}</span>
    </div>`;
  }).join('');
}

const doughnutExcelOptions = {
  responsive: true,
  cutout: '38%',
  rotation: -70,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: function(context) {
          const total = context.dataset.data.reduce((sum, value) => sum + Number(value || 0), 0);
          const value = Number(context.raw || 0);
          return `${context.label}: ${chartPercent(value, total)}`;
        }
      }
    }
  }
};

new Chart(document.getElementById('chartServicio'), {type:'doughnut', data:{labels:servicioLabels,datasets:[{data:servicioData,backgroundColor:palette,borderColor:'#ffffff',borderWidth:4,hoverOffset:10}]}, options:doughnutExcelOptions});
new Chart(document.getElementById('chartMensual'), {type:'bar', data:{labels:mesLabels,datasets:[{label:'Participaciones',data:mesData,backgroundColor:'#0a5c8f'}]}, options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('chartEstado'), {type:'doughnut', data:{labels:estadoLabels,datasets:[{data:estadoData,backgroundColor:palette,borderColor:'#ffffff',borderWidth:4,hoverOffset:10}]}, options:doughnutExcelOptions});
chartLegend('legendServicio', servicioLabels, servicioData, palette);
chartLegend('legendEstado', estadoLabels, estadoData, palette);
</script>
</body>
</html>
