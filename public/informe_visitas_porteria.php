<?php
// --------------------------------------------------------------
// informe_visitas_porteria.php
// Centro de Excelencia Operacional (CEO)
// Informe Visitas Autorizadas Portería
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
   Fecha filtro
   ============================================================ */
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$registros = [];

if (!empty($fecha)) {

  $sql = "
SELECT
      s.id                              AS solicitud,
      s.fecha                           AS fecha,
      s.horainicio                      AS hora,
      p.rut,
      CONCAT(p.nombre,' ',p.apellidop,' ',p.apellidom) AS nombre,
      e.nombre                          AS empresa,
      CONCAT(us.nombres, ' ', us.apellidos) AS solicitante,
      pr.desc_proceso                         AS proceso,
      ht.desc_tipo              AS habilitacion,
      pa.desc_patios                          AS patio
      ,s.observacion                          AS observacion
    FROM ceo_participantes_solicitud p
    INNER JOIN ceo_solicitudes s ON s.id = p.id_solicitud
    INNER JOIN ceo_usuarios us ON us.id= s.solicitante
    INNER JOIN ceo_procesos pr ON pr.id = s.proceso
    INNER JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
    INNER JOIN ceo_patios pa on pa.id = s.patio
    LEFT JOIN ceo_empresas e 
        ON e.id = s.contratista
    WHERE  s.estado = 'A'
    and   p.autorizado = 1
        AND s.fecha = :fecha
    ORDER BY empresa, nombre
  ";

  $st = $pdo->prepare($sql);
  $st->execute(['fecha' => $fecha]);
  $registros = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe Visitas Portería</title>

<link href="/ceo/assets/bootstrap.min.css" rel="stylesheet">

<style>
body{background:#f7f9fc}
.ceo-report {
  max-width: 1100px;
  margin: 20px auto;
  padding: 20px;
  border: 2px solid #000;
  background: #fff;
  font-family: Arial, Helvetica, sans-serif;
}
.ceo-title {
  font-size: 18px;
  font-weight: bold;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.ceo-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.ceo-table th,
.ceo-table td {
  border: 1px solid #000;
  padding: 5px;
}
.ceo-table th {
  background: #e6e6e6;
  text-transform: uppercase;
}
.no-print { margin-bottom: 15px; }

@media print {
  .no-print { display:none; }
  body { background:#fff; }
}

/* ===== MEMBRETE CEO ===== */
.ceo-membrete {
  max-width: 1100px;
  margin: 10px auto 15px auto;
  padding-bottom: 10px;
  border-bottom: 2px solid #000;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 15px;
  font-family: Arial, Helvetica, sans-serif;
}

.ceo-membrete-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ceo-membrete-left img {
  height: 80px;
}

.ceo-membrete-title {
  font-weight: bold;
  font-size: 22px;
  color: #000;
}

.ceo-membrete-sub {
  font-size: 12px;
  color: #333;
}

@media print {
  .no-print { display:none; }
  body { background:#fff; }
}
</style>
</head>

<body class="container-fluid">

<header class="ceo-membrete">
  <div class="ceo-membrete-left">
    <img src="<?= APP_LOGO ?>" alt="Logo ENEL">
    <div>
      <div class="ceo-membrete-title"><?= APP_NAME ?></div>
      <div class="ceo-membrete-sub"><?= APP_SUBTITLE ?></div>
    </div>
  </div>
</header>

<div class="ceo-report">

  <div class="ceo-title">
    INFORME VISITAS AUTORIZADAS PORTERÍA
  </div>

  <div style="margin-bottom:10px;">
    Fecha: <strong><?= esc($fecha) ?></strong>
  </div>

  <!-- FILTRO -->
  <form method="get" class="no-print d-flex gap-3 align-items-end mb-3">

    <div>
      <label><strong>Fecha</strong></label><br>
      <input type="date" name="fecha" value="<?= esc($fecha) ?>" required>
    </div>

    <div>
      <button type="submit" class="btn btn-primary btn-sm">
        Recuperar
      </button>

      <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">
        Imprimir
      </button>

      <a href="/ceo.noetica.cl/public/general.php" class="btn btn-light btn-sm">
        Volver
      </a>
    </div>

  </form>

  <!-- TABLA -->
  <table class="ceo-table">
    <thead>
      <tr>
        <th>Solicitud</th>
        <th>Fecha</th>
        <th>Hora</th>
        <th>RUT</th>
        <th>Nombre</th>
        <th>Empresa</th>
        <th>Solicitante</th>
        <th>Proceso</th>
        <th>Habilitación</th>
        <th>Patio</th>
        <th>Observaciones</th>
      </tr>
    </thead>
    <tbody>

    <?php if (empty($registros)): ?>
      <tr>
         <td colspan="11" class="text-center text-muted">
           Sin personas autorizadas para la fecha seleccionada.
         </td>
      </tr>
    <?php else: foreach ($registros as $r): ?>
      <tr>
        <td><?= esc($r['solicitud']) ?></td>
        <td><?= esc($r['fecha']) ?></td>
        <td><?= esc($r['hora']) ?></td>
        <td><?= esc($r['rut']) ?></td>
        <td><?= esc($r['nombre']) ?></td>
        <td><?= esc($r['empresa']) ?></td>
        <td><?= esc($r['solicitante']) ?></td>
        <td><?= esc($r['proceso']) ?></td>
        <td><?= esc($r['habilitacion']) ?></td>
        <td><?= esc($r['patio']) ?></td>
        <td><?= esc($r['observacion']) ?></td>
      </tr>
    <?php endforeach; endif; ?>

    </tbody>
  </table>

  <div style="margin-top:10px; font-size:11px;">
    Total Personas: <strong><?= count($registros) ?></strong>
  </div>

</div>
</body>
</html>
