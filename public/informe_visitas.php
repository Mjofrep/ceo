<?php
// --------------------------------------------------------------
// informe_visitas.php
// Centro de Excelencia Operacional (CEO)
// Informe de Visitas - Estilo Reporte / Excel
// --------------------------------------------------------------
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();

/* ============================================================
   Escape seguro
   ============================================================ */
function esc(mixed $v): string {
  if ($v === null) return '';
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   Fechas
   ============================================================ */
// Fechas por defecto
$hoy = date('Y-m-d');
$primerDiaMes = date('Y-m-01');

// Si no vienen por GET, usar valores por defecto
$desde = $_GET['desde'] ?? $primerDiaMes;
$hasta = $_GET['hasta'] ?? $hoy;

$error = '';

if ($desde && $hasta) {
  if ($desde === $hasta) {
    $error = 'Las fechas no pueden ser iguales.';
  } elseif ($hasta < $desde) {
    $error = 'La fecha hasta no puede ser menor que la fecha desde.';
  }
}

/* ============================================================
   Ítems
   ============================================================ */
$items = [
  'enelx' => [
    'titulo' => 'Habilitaciones ENEL X',
    'where'  => "s.proceso = 21 AND s.estado = 'F' AND s.uo = 12 AND s.habilitacionceo = 8"
  ],
  'odi' => [
    'titulo' => 'Charla ODI',
    'where'  => "s.charla = 58 AND s.estado = 'F' AND s.habilitacionceo = 6"
  ],
  'rdo' => [
    'titulo' => 'RDO',
    'where'  => "s.charla = 54 AND s.estado = 'F' AND s.habilitacionceo = 6 AND s.proceso = 24"
  ]
];

/* ============================================================
   SQL base
   ============================================================ */
$sqlResumenBase = "
  SELECT SUM(COALESCE(p.asistio,0)) total
  FROM ceo_participantes_solicitud p
  JOIN ceo_solicitudes s ON s.id = p.id_solicitud
  WHERE {{COND}} AND p.asistio = 1
    AND p.fechaasistio BETWEEN :desde AND :hasta
";

$sqlDetalleBase = "
  SELECT
    p.rut,
    CONCAT(p.nombre,' ',p.apellidop,' ',p.apellidom) nombre,
    IF(p.asistio=1,'Sí','No') asistio,
    IF(p.autorizado=1,'Sí','No') autorizado,
    p.fechaasistio,
    p.wf
  FROM ceo_participantes_solicitud p
  JOIN ceo_solicitudes s ON s.id = p.id_solicitud
  WHERE {{COND}}
    AND p.fechaasistio BETWEEN :desde AND :hasta
  ORDER BY p.fechaasistio
";
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe de Visitas</title>

<link href="/ceo/assets/bootstrap.min.css" rel="stylesheet">
<link href="/ceo/assets/bootstrap-icons.css" rel="stylesheet">

<style>
/* ===== CONTENEDOR REPORTE ===== */
.ceo-report {
  max-width: 1100px;
  margin: 20px auto;
  padding: 20px;
  border: 2px solid #000;
  background: #fff;
  font-family: Arial, Helvetica, sans-serif;
}

/* ===== TITULO ===== */
.ceo-title {
  font-size: 20px;
  font-weight: bold;
  text-transform: uppercase;
  margin-bottom: 4px;
}

.ceo-periodo {
  font-size: 13px;
  margin-bottom: 15px;
}

/* ===== FILTROS ===== */
.ceo-filtros {
  margin-bottom: 15px;
  padding-bottom: 10px;
  border-bottom: 1px solid #000;
}

/* ===== TABLA PRINCIPAL ===== */
.ceo-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.ceo-table th,
.ceo-table td {
  border: 1px solid #000;
  padding: 6px 8px;
}

.ceo-table th {
  background: #e6e6e6;
  text-transform: uppercase;
  font-size: 12px;
}

/* ===== RESUMEN ===== */
.ceo-resumen {
  cursor: pointer;
  font-weight: bold;
  background: #f2f2f2;
}

.ceo-resumen:hover {
  background: #dcdcdc;
}

/* ===== SUBTABLA ===== */
.ceo-subtable {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  margin-top: 6px;
}

.ceo-subtable th,
.ceo-subtable td {
  border: 1px solid #000;
  padding: 5px;
}

.ceo-subtable th {
  background: #f0f0f0;
  text-transform: uppercase;
  font-size: 11px;
}

/* ===== ICONO ===== */
.icon-toggle {
  font-weight: bold;
  margin-right: 6px;
}

/* VIENE de OTRA PAGINA */
body{background:#f7f9fc}
.topbar{background:#fff;border-bottom:1px solid #e3e6ea}
.brand-title{color:#0065a4;font-weight:600}
.card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05)}
.table thead{position:sticky;top:0;z-index:2}
.table th{background:#eaf2fb}
.semana-tabla th,.semana-tabla td{font-size:.8rem}
.ocupado{background:#dc3545!important;color:#fff;cursor:not-allowed}
/* === Validación visual por FONDO del campo === */
.campo-ok {
  background-color: #eaf6ff !important;   /* celeste MUY suave */
}

.campo-error {
  background-color: #fdecec !important;   /* rojo MUY suave */
}

/* === Overlay carga Excel === */
.excel-loading {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.85);
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
}

.excel-loading-content {
  text-align: center;
  padding: 20px 30px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.12);
}

/* ===== MEMBRETE CEO (HEADER REPORTE) ===== */
.ceo-membrete {
  max-width: 1100px;
  margin: 10px auto 15px auto;
  padding-bottom: 10px;
  border-bottom: 2px solid #000;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-family: Arial, Helvetica, sans-serif;
}

.ceo-membrete-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ceo-membrete-left img {
  height: 100px;        /* 👈 clave: logo más pequeño */
}

.ceo-membrete-title {
  font-weight: bold;
  font-size: 30px;
  color: #000;
}

.ceo-membrete-sub {
  font-size: 12px;
  color: #333;
}

.ceo-membrete-right a {
  font-size: 20px;
  text-decoration: none;
  color: #000;
}

.ceo-membrete-right a:hover {
  text-decoration: underline;
}

/* ===== FILTROS EN UNA SOLA LINEA ===== */
.ceo-fila-filtros {
  display: flex;
  align-items: flex-end;
  gap: 15px;
  flex-wrap: nowrap;
}

.ceo-campo {
  display: flex;
  flex-direction: column;
  font-size: 12px;
}

.ceo-campo label {
  font-weight: bold;
  margin-bottom: 2px;
}

.ceo-campo input {
  height: 28px;
  padding: 2px 6px;
  font-size: 12px;
}

.ceo-acciones {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-bottom: 2px;
}

.ceo-acciones button {
  height: 30px;
  padding: 0 12px;
  font-size: 12px;
  cursor: pointer;
}

.ceo-acciones a {
  font-size: 12px;
  text-decoration: underline;
  color: #0065a4;
}
</style>
</head>

<body class="container-fluid">
    
    
<header class="ceo-membrete">
  <div class="ceo-membrete-left">
    <img src="<?= APP_LOGO ?>" alt="Logo ENEL">
    <div class="ceo-membrete-text">
      <div class="ceo-membrete-title"><?= APP_NAME ?></div>
      <div class="ceo-membrete-sub"><?= APP_SUBTITLE ?></div>
    </div>
  </div>

  <div class="ceo-membrete-right">
    <a href="general.php">← Volver</a>
  </div>
</header>


<div class="ceo-report">
<div class="mb-3">
  <div class="ceo-title">INFORME DE VISITAS</div>
  <?php if ($desde && $hasta): ?>
    <div class="ceo-periodo">
      Periodo: <strong><?= esc($desde) ?></strong> al <strong><?= esc($hasta) ?></strong>
    </div>
  <?php endif; ?>
</div>

<form method="get" class="ceo-filtros">
  <div class="ceo-fila-filtros">
    
    <div class="ceo-campo">
      <label>Fecha desde</label>
      <input type="date" name="desde" value="<?= esc($desde) ?>" required>
    </div>

    <div class="ceo-campo">
      <label>Fecha hasta</label>
      <input type="date" name="hasta" value="<?= esc($hasta) ?>" required>
    </div>

    <div class="ceo-acciones">
      <button type="submit">Consultar</button>
      <?php if (!$error && $desde && $hasta): ?>
        <a href="/ceo.noetica.cl/public/export/informe_visitas_excel.php?desde=<?= esc($desde) ?>&hasta=<?= esc($hasta) ?>">
          Exportar Excel
        </a>
      <?php endif; ?>
    </div>

  </div>
</form>

<?php if ($error): ?>
  <div class="alert alert-danger py-1"><?= esc($error) ?></div>
<?php endif; ?>

<?php if (!$error && $desde && $hasta): ?>

<table class="table table-sm table-bordered ceo-table">
  <thead class="ceo-head">
    <tr>
      <th>Concepto</th>
      <th class="text-end">Total</th>
    </tr>
  </thead>
  <tbody>

<?php foreach ($items as $k => $item): ?>

  <?php
  $sql = str_replace('{{COND}}', $item['where'], $sqlResumenBase);
  $st = $pdo->prepare($sql);
  $st->execute(['desde'=>$desde,'hasta'=>$hasta]);
  $total = (int)$st->fetchColumn();

  $sql = str_replace('{{COND}}', $item['where'], $sqlDetalleBase);
  $st = $pdo->prepare($sql);
  $st->execute(['desde'=>$desde,'hasta'=>$hasta]);
  $detalle = $st->fetchAll();
  ?>

  <tr>
    <td class="ceo-resumen" data-target="det_<?= $k ?>">
      <i class="bi bi-chevron-right me-2 icon-toggle"></i>
      <strong><?= strtoupper(esc($item['titulo'])) ?></strong>
    </td>
    <td class="text-end"><strong><?= $total ?></strong></td>
  </tr>

  <tr id="det_<?= $k ?>" style="display:none;">
    <td colspan="2">
      <table class="table table-sm table-bordered ceo-subtable mb-0">
        <thead class="ceo-subhead">
          <tr>
            <th>RUT</th><th>Nombre</th><th>Asistió</th>
            <th>Autorizado</th><th>Fecha</th><th>WF</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$detalle): ?>
          <tr><td colspan="6" class="text-center text-muted">Sin registros</td></tr>
        <?php else: foreach ($detalle as $d): ?>
          <tr>
            <td><?= esc($d['rut']) ?></td>
            <td><?= esc($d['nombre']) ?></td>
            <td><?= esc($d['asistio']) ?></td>
            <td><?= esc($d['autorizado']) ?></td>
            <td><?= esc($d['fechaasistio']) ?></td>
            <td><?= esc($d['wf']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </td>
  </tr>

<?php endforeach; ?>

  </tbody>
</table>
<?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.ceo-resumen').forEach(cell => {
    cell.addEventListener('click', function () {

      const detalle = document.getElementById(this.dataset.target);
      const icon = this.querySelector('.icon-toggle');
      const abierto = detalle.style.display === 'table-row';

      document.querySelectorAll('[id^="det_"]').forEach(d => d.style.display = 'none');
      document.querySelectorAll('.icon-toggle').forEach(i => {
        i.classList.remove('bi-chevron-down');
        i.classList.add('bi-chevron-right');
      });

      if (!abierto) {
        detalle.style.display = 'table-row';
        icon.classList.remove('bi-chevron-right');
        icon.classList.add('bi-chevron-down');
      }
    });
  });
});
</script>

</body>
</html>