<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/formaciones_cuadrillas_inspectores_lib.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

$flash = $_SESSION['formaciones_cuadrillas_flash'] ?? null;
unset($_SESSION['formaciones_cuadrillas_flash']);

$mensaje = '';
$mensajeTipo = 'info';
if (is_array($flash)) {
    $mensaje = (string)($flash['message'] ?? '');
    $mensajeTipo = (string)($flash['type'] ?? 'info');
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['accion'] ?? '') === 'cargar_excel_inspectores') {
    try {
        if (empty($_FILES['excel_inspectores']['tmp_name'])) {
            throw new RuntimeException('Debe seleccionar un archivo Excel para cargar.');
        }

        $resultadoCarga = fciImportWorkbook(
            $pdo,
            (string)$_FILES['excel_inspectores']['tmp_name'],
            (string)($_FILES['excel_inspectores']['name'] ?? 'Evaluaciones de Inspectores.xlsx'),
            (int)($_SESSION['auth']['id'] ?? 0)
        );

        $_SESSION['formaciones_cuadrillas_flash'] = [
            'type' => 'success',
            'message' => 'Carga finalizada. RUT cargados o actualizados: ' . (int)$resultadoCarga['processed'] . '. Filas omitidas: ' . (int)$resultadoCarga['skipped'] . '.',
        ];
        header('Location: formaciones_cuadrillas.php');
        exit;
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $mensajeTipo = 'danger';
    }
}

$resumenImportacion = fciFetchImportSummary($pdo);

$rutFiltro = trim((string)($_GET['rut_filtro'] ?? ''));
$rutFiltroComparable = strtoupper(str_replace(['.', '-', ' '], '', $rutFiltro));

$sql = "
    SELECT
        f.id,
        f.cuadrilla,
        f.fecha,
        f.jornada,
        f.id_servicio,
        s.servicio,
        e.nombre AS empresa,
        u.desc_uo AS uo
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_uo u ON u.id = f.uo
";

if ($rutFiltroComparable !== '') {
    $sql .= "
        WHERE EXISTS (
            SELECT 1
            FROM ceo_formacion_participantes p
            WHERE p.id_cuadrilla = f.cuadrilla
              AND REPLACE(REPLACE(REPLACE(UPPER(TRIM(COALESCE(p.rut, ''))), '.', ''), '-', ''), ' ', '') = :rut_filtro
        )
    ";
}

$sql .= " ORDER BY f.fecha DESC, f.id DESC";

$stmtCuadrillas = $pdo->prepare($sql);
if ($rutFiltroComparable !== '') {
    $stmtCuadrillas->bindValue(':rut_filtro', $rutFiltroComparable, PDO::PARAM_STR);
}
$stmtCuadrillas->execute();
$cuadrillas = $stmtCuadrillas->fetchAll(PDO::FETCH_ASSOC);

$sqlResumen = "
    SELECT
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA' THEN 1 ELSE 0 END) AS anulados,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'REPROBADO' THEN 1 ELSE 0 END) AS reprobados,
        SUM(CASE WHEN ep.id IS NULL
                  OR (
                      UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                      AND UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE'))) NOT IN ('APROBADO', 'REPROBADO')
                  ) THEN 1 ELSE 0 END) AS pendientes,
        COUNT(*) AS total
    FROM ceo_formacion_participantes p
    INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
    LEFT JOIN (
        SELECT ep1.*
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
    ) ep ON ep.rut = p.rut AND ep.id_servicio = f.id_servicio AND ep.cuadrilla = p.id_cuadrilla
";

$resumenGlobal = $pdo->query($sqlResumen)->fetch(PDO::FETCH_ASSOC) ?: [
    'aprobados' => 0,
    'reprobados' => 0,
    'anulados' => 0,
    'pendientes' => 0,
    'total' => 0,
];

$sqlResumenCuadrillas = "
    SELECT
        f.id,
        f.cuadrilla,
        f.fecha,
        s.servicio,
        e.nombre AS empresa,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) = 'ANULADA' THEN 1 ELSE 0 END) AS anulados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                  AND UPPER(TRIM(COALESCE(ep.resultado, ''))) = 'REPROBADO' THEN 1 ELSE 0 END) AS reprobados,
        SUM(CASE WHEN p.rut IS NOT NULL
                  AND (
                      ep.id IS NULL
                      OR (
                          UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                          AND UPPER(TRIM(COALESCE(ep.resultado, 'PENDIENTE'))) NOT IN ('APROBADO', 'REPROBADO')
                      )
                  ) THEN 1 ELSE 0 END) AS pendientes,
        COUNT(p.rut) AS total
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_formacion_participantes p ON p.id_cuadrilla = f.cuadrilla
    LEFT JOIN (
        SELECT ep1.*
        FROM ceo_formacion_programadas ep1
        INNER JOIN (
            SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion_programadas
            GROUP BY rut, id_servicio, cuadrilla
        ) ep2 ON ep1.id = ep2.max_id
    ) ep ON ep.rut = p.rut AND ep.id_servicio = f.id_servicio AND ep.cuadrilla = p.id_cuadrilla
    GROUP BY f.id, f.cuadrilla, f.fecha, s.servicio, e.nombre
    ORDER BY f.fecha DESC, f.id DESC
";

$resumenCuadrillas = $pdo->query($sqlResumenCuadrillas)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Cuadrillas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.table thead th {background:#eaf2fb; position: sticky; top: 0; z-index: 1;}
.scroll-box {max-height: 70vh; overflow: auto; border:1px solid #dee2e6; border-radius:8px; background:#fff;}
.table-total-row th,
.table-total-row td {background:#f8fbff;}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title h5 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3 mb-3">
    <div>
      <h5 class="text-primary mb-1"><i class="bi bi-list-check me-2"></i>Cuadrillas Formaciones</h5>
      <div class="text-muted small">Doble click para ver detalle. Para exportar Ciclo 1, seleccione las cuadrillas de RDO e Inspectores.</div>
    </div>
    <div class="border rounded bg-white p-3 shadow-sm" style="min-width:min(100%, 560px);">
      <div class="fw-semibold text-primary mb-2"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Ciclo 1 - Inspectores</div>
      <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-end">
        <form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-lg-row gap-2 flex-grow-1">
          <input type="hidden" name="accion" value="cargar_excel_inspectores">
          <div class="flex-grow-1">
            <label for="excel_inspectores" class="form-label small text-muted mb-1">Cargar Excel base</label>
            <input type="file" id="excel_inspectores" name="excel_inspectores" class="form-control form-control-sm" accept=".xlsx" required>
          </div>
          <div class="d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Cargar Excel</button>
          </div>
        </form>
        <form id="form-export-inspectores" method="post" action="formaciones_cuadrillas_export_inspectores.php" class="d-flex align-items-end">
          <div id="selected-cuadrillas-inputs"></div>
          <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Exportar Ciclo 1</button>
        </form>
      </div>
      <div class="small text-muted mt-2">
        Base cargada: <strong><?= (int)($resumenImportacion['total'] ?? 0) ?></strong> RUT
        <?php if (!empty($resumenImportacion['ultima_carga'])): ?>
          | Última carga: <strong><?= esc((string)$resumenImportacion['ultima_carga']) ?></strong>
        <?php endif; ?>
        <?php if (!empty($resumenImportacion['archivo_origen'])): ?>
          | Archivo: <strong><?= esc((string)$resumenImportacion['archivo_origen']) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($mensaje !== ''): ?>
    <div class="alert alert-<?= esc($mensajeTipo) ?>"><?= esc($mensaje) ?></div>
  <?php endif; ?>

  <div class="row g-2 align-items-end mb-3">
    <div class="col-md-4 col-lg-3">
      <label for="rut_filtro" class="form-label small text-muted mb-1">Buscar persona por RUT</label>
      <form method="get" class="d-flex gap-2">
        <input type="text" id="rut_filtro" name="rut_filtro" class="form-control form-control-sm" value="<?= esc($rutFiltro) ?>" placeholder="12345678-9">
        <button type="submit" class="btn btn-outline-primary btn-sm">Filtrar</button>
        <?php if ($rutFiltro !== ''): ?>
          <a href="formaciones_cuadrillas.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <?php if ($rutFiltro !== ''): ?>
    <div class="alert alert-info py-2">
      <?= count($cuadrillas) > 0
        ? 'El RUT ' . esc($rutFiltro) . ' aparece en ' . count($cuadrillas) . ' cuadrilla(s).'
        : 'El RUT ' . esc($rutFiltro) . ' no aparece en cuadrillas de formaciones.' ?>
    </div>
  <?php endif; ?>

  <div class="scroll-box">
    <table class="table table-hover table-sm align-middle">
      <thead>
        <tr>
          <th style="width:42px;" class="text-center"><input type="checkbox" id="chk-cuadrillas-todas" class="form-check-input" title="Seleccionar todas"></th>
          <th>Fecha</th>
          <th>Cuadrilla</th>
          <th>Servicio</th>
          <th>Empresa</th>
          <th>UO</th>
          <th>Jornada</th>
        </tr>
      </thead>
        <tbody>
        <?php if (empty($cuadrillas)): ?>
          <tr><td colspan="7" class="text-center text-muted">Sin registros</td></tr>
        <?php else: ?>
          <?php foreach ($cuadrillas as $c): ?>
            <tr class="fila-cuadrilla" data-id="<?= (int)$c['id'] ?>" data-cuadrilla="<?= (int)$c['cuadrilla'] ?>">
              <td class="text-center">
                <input
                  type="checkbox"
                  class="form-check-input chk-cuadrilla-export"
                  value="<?= (int)$c['cuadrilla'] ?>"
                  data-servicio-id="<?= (int)$c['id_servicio'] ?>"
                  data-servicio="<?= esc((string)$c['servicio']) ?>"
                  aria-label="Seleccionar cuadrilla <?= (int)$c['cuadrilla'] ?>"
                >
              </td>
              <td><?= esc((string)$c['fecha']) ?></td>
              <td><?= (int)$c['cuadrilla'] ?></td>
              <td><?= esc((string)$c['servicio']) ?></td>
              <td><?= esc((string)$c['empresa']) ?></td>
              <td><?= esc((string)$c['uo']) ?></td>
              <td><?= esc((string)$c['jornada']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    <h6 class="text-primary mb-2"><i class="bi bi-bar-chart-line me-2"></i>Resumen Global</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle bg-white">
        <thead>
          <tr>
            <th>Aprobados</th>
            <th>Reprobados</th>
            <th>Anulados</th>
            <th>Pendientes</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="text-success fw-semibold"><?= (int)$resumenGlobal['aprobados'] ?></td>
            <td class="text-danger fw-semibold"><?= (int)$resumenGlobal['reprobados'] ?></td>
            <td class="text-secondary fw-semibold"><?= (int)$resumenGlobal['anulados'] ?></td>
            <td class="text-warning fw-semibold"><?= (int)$resumenGlobal['pendientes'] ?></td>
            <td class="fw-semibold"><?= (int)$resumenGlobal['total'] ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-4">
    <h6 class="text-primary mb-2"><i class="bi bi-grid-3x3-gap me-2"></i>Analisis por Cuadrilla</h6>
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-4 col-lg-3">
        <label for="filtro-analisis-cuadrilla" class="form-label small text-muted mb-1">Filtro general</label>
        <input type="text" id="filtro-analisis-cuadrilla" class="form-control form-control-sm" placeholder="Buscar en la tabla...">
      </div>
    </div>
    <div class="table-responsive">
      <table id="tabla-analisis-cuadrilla" class="table table-sm table-bordered table-hover align-middle bg-white">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Cuadrilla</th>
            <th>Servicio</th>
            <th>Empresa</th>
            <th>Aprobados</th>
            <th>Reprobados</th>
            <th>Anulados</th>
            <th>Pendientes</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($resumenCuadrillas)): ?>
            <tr><td colspan="9" class="text-center text-muted">Sin registros</td></tr>
          <?php else: ?>
            <?php foreach ($resumenCuadrillas as $r): ?>
              <tr class="fila-cuadrilla fila-analisis-cuadrilla" data-id="<?= (int)$r['id'] ?>" data-cuadrilla="<?= (int)$r['cuadrilla'] ?>">
                <td><?= esc((string)$r['fecha']) ?></td>
                <td><?= (int)$r['cuadrilla'] ?></td>
                <td><?= esc((string)$r['servicio']) ?></td>
                <td><?= esc((string)$r['empresa']) ?></td>
                <td class="text-success fw-semibold"><?= (int)$r['aprobados'] ?></td>
                <td class="text-danger fw-semibold"><?= (int)$r['reprobados'] ?></td>
                <td class="text-secondary fw-semibold"><?= (int)$r['anulados'] ?></td>
                <td class="text-warning fw-semibold"><?= (int)$r['pendientes'] ?></td>
                <td class="fw-semibold"><?= (int)$r['total'] ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($resumenCuadrillas)): ?>
          <tfoot>
            <tr class="table-total-row">
              <th colspan="4" class="text-end">Totales filtrados</th>
              <th id="total-filtrado-aprobados" class="text-success fw-semibold">0</th>
              <th id="total-filtrado-reprobados" class="text-danger fw-semibold">0</th>
              <th id="total-filtrado-anulados" class="text-secondary fw-semibold">0</th>
              <th id="total-filtrado-pendientes" class="text-warning fw-semibold">0</th>
              <th id="total-filtrado-total" class="fw-semibold">0</th>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.fila-cuadrilla').forEach(row => {
  row.style.cursor = 'pointer';
  row.addEventListener('dblclick', function (e) {
    if (e.target.closest('a, button, input')) return;
    const id = this.dataset.id;
    const cuad = this.dataset.cuadrilla;
    window.location.href = `formaciones_cuadrilla_detalle.php?id=${encodeURIComponent(id)}&cuadrilla=${encodeURIComponent(cuad)}`;
  });
});

const checkboxesCuadrillas = Array.from(document.querySelectorAll('.chk-cuadrilla-export'));
const chkTodasCuadrillas = document.getElementById('chk-cuadrillas-todas');
const formExportInspectores = document.getElementById('form-export-inspectores');
const selectedCuadrillasInputs = document.getElementById('selected-cuadrillas-inputs');

function syncMasterCheckbox() {
  if (!chkTodasCuadrillas || checkboxesCuadrillas.length === 0) return;
  const checkedCount = checkboxesCuadrillas.filter((checkbox) => checkbox.checked).length;
  chkTodasCuadrillas.checked = checkedCount > 0 && checkedCount === checkboxesCuadrillas.length;
  chkTodasCuadrillas.indeterminate = checkedCount > 0 && checkedCount < checkboxesCuadrillas.length;
}

if (chkTodasCuadrillas) {
  chkTodasCuadrillas.addEventListener('change', function () {
    checkboxesCuadrillas.forEach((checkbox) => {
      checkbox.checked = this.checked;
    });
    syncMasterCheckbox();
  });
}

checkboxesCuadrillas.forEach((checkbox) => {
  checkbox.addEventListener('change', syncMasterCheckbox);
});

if (formExportInspectores && selectedCuadrillasInputs) {
  formExportInspectores.addEventListener('submit', function (event) {
    const seleccionadas = checkboxesCuadrillas
      .filter((checkbox) => checkbox.checked);

    if (seleccionadas.length === 0) {
      event.preventDefault();
      window.alert('Seleccione al menos una cuadrilla para exportar.');
      return;
    }

    const serviciosUnicos = [];
    seleccionadas.forEach((checkbox) => {
      const idServicio = checkbox.dataset.servicioId || '';
      if (idServicio !== '' && !serviciosUnicos.includes(idServicio)) {
        serviciosUnicos.push(idServicio);
      }
    });

    if (serviciosUnicos.length === 0) {
      event.preventDefault();
      window.alert('Las cuadrillas seleccionadas no tienen un servicio válido para exportar.');
      return;
    }

    if (serviciosUnicos.length > 2) {
      event.preventDefault();
      window.alert('La exportación Ciclo 1 soporta hasta 2 servicios seleccionados por archivo.');
      return;
    }

    selectedCuadrillasInputs.innerHTML = '';
    seleccionadas.forEach((checkbox) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'selecciones[]';
      input.value = JSON.stringify({
        cuadrilla: checkbox.value,
        id_servicio: checkbox.dataset.servicioId || '',
        servicio: checkbox.dataset.servicio || ''
      });
      selectedCuadrillasInputs.appendChild(input);
    });
  });
}

const filtroAnalisis = document.getElementById('filtro-analisis-cuadrilla');
const filasAnalisis = Array.from(document.querySelectorAll('.fila-analisis-cuadrilla'));

function normalizarTexto(texto) {
  return texto
    .toString()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

function actualizarTotalesAnalisis() {
  const totalizadores = {
    aprobados: 0,
    reprobados: 0,
    anulados: 0,
    pendientes: 0,
    total: 0
  };

  filasAnalisis.forEach((fila) => {
    if (fila.style.display === 'none') return;

    const celdas = fila.querySelectorAll('td');
    totalizadores.aprobados += parseInt(celdas[4]?.textContent || '0', 10) || 0;
    totalizadores.reprobados += parseInt(celdas[5]?.textContent || '0', 10) || 0;
    totalizadores.anulados += parseInt(celdas[6]?.textContent || '0', 10) || 0;
    totalizadores.pendientes += parseInt(celdas[7]?.textContent || '0', 10) || 0;
    totalizadores.total += parseInt(celdas[8]?.textContent || '0', 10) || 0;
  });

  const aprobados = document.getElementById('total-filtrado-aprobados');
  const reprobados = document.getElementById('total-filtrado-reprobados');
  const anulados = document.getElementById('total-filtrado-anulados');
  const pendientes = document.getElementById('total-filtrado-pendientes');
  const total = document.getElementById('total-filtrado-total');

  if (aprobados) aprobados.textContent = totalizadores.aprobados;
  if (reprobados) reprobados.textContent = totalizadores.reprobados;
  if (anulados) anulados.textContent = totalizadores.anulados;
  if (pendientes) pendientes.textContent = totalizadores.pendientes;
  if (total) total.textContent = totalizadores.total;
}

if (filtroAnalisis && filasAnalisis.length > 0) {
  filtroAnalisis.addEventListener('input', function () {
    const termino = normalizarTexto(this.value);

    filasAnalisis.forEach((fila) => {
      const textoFila = normalizarTexto(fila.textContent);
      fila.style.display = textoFila.includes(termino) ? '' : 'none';
    });

    actualizarTotalesAnalisis();
  });

  actualizarTotalesAnalisis();
}
</script>

</body>
</html>
