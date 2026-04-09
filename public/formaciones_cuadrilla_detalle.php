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

$id = (int)($_GET['id'] ?? 0);
$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);

$formacion = null;
$participantes = [];
$error = '';

if ($id <= 0 && $cuadrilla <= 0) {
    $error = 'Cuadrilla no especificada.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT f.id, f.cuadrilla, f.fecha, f.jornada, f.id_servicio,
                   s.servicio, e.nombre AS empresa, u.desc_uo AS uo
            FROM ceo_formacion f
            LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_uo u ON u.id = f.uo
            WHERE f.id = :id OR f.cuadrilla = :cuadrilla
            ORDER BY f.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
            ':cuadrilla' => $cuadrilla
        ]);
        $formacion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$formacion) {
            $error = 'No se encontro la cuadrilla.';
        } else {
            $stmt = $pdo->prepare("
                SELECT
                    p.rut,
                    p.nombre,
                    p.apellidos,
                    p.cargo,
                    ep.resultado,
                    ri.notafinal,
                    ri.puntaje_total,
                    ri.correctas,
                    ri.incorrectas,
                    ri.ncontestadas
                FROM ceo_formacion_participantes p
                LEFT JOIN (
                    SELECT ep1.*
                    FROM ceo_formacion_programadas ep1
                    INNER JOIN (
                        SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                        FROM ceo_formacion_programadas
                        WHERE cuadrilla = :cuadrilla
                        GROUP BY rut, id_servicio, cuadrilla
                    ) ep2 ON ep1.id = ep2.max_id
                ) ep ON ep.rut = p.rut AND ep.id_servicio = :servicio AND ep.cuadrilla = :cuadrilla2
                LEFT JOIN (
                    SELECT ri1.*
                    FROM ceo_resultado_formacion_intento ri1
                    INNER JOIN (
                        SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion,' ',hora_rendicion)) AS max_fecha
                        FROM ceo_resultado_formacion_intento
                        GROUP BY rut, id_servicio
                    ) ri2 ON ri1.rut = ri2.rut
                          AND ri1.id_servicio = ri2.id_servicio
                          AND CONCAT(ri1.fecha_rendicion,' ',ri1.hora_rendicion) = ri2.max_fecha
                ) ri ON ri.rut = p.rut AND ri.id_servicio = :servicio2
                WHERE p.id_cuadrilla = :cuadrilla3
                ORDER BY p.apellidos ASC, p.nombre ASC
            ");
            $stmt->execute([
                ':cuadrilla' => (int)$formacion['cuadrilla'],
                ':cuadrilla2' => (int)$formacion['cuadrilla'],
                ':cuadrilla3' => (int)$formacion['cuadrilla'],
                ':servicio' => (int)$formacion['id_servicio'],
                ':servicio2' => (int)$formacion['id_servicio']
            ]);
            $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $error = 'Error al cargar el detalle de la cuadrilla.';
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $error = 'Error SQL: ' . $e->getMessage();
        }
    }
}

function estadoResultado(?string $resultado): string
{
    if (!$resultado) {
        return 'PENDIENTE';
    }
    $res = strtoupper(trim($resultado));
    if ($res === 'APROBADO' || $res === 'REPROBADO' || $res === 'PENDIENTE') {
        return $res;
    }
    return $res;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Detalle Cuadrilla | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.table thead th {background:#eaf2fb; position: sticky; top: 0; z-index: 1;}
.scroll-box {max-height: 70vh; overflow: auto; border:1px solid #dee2e6; border-radius:8px; background:#fff;}
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
    <div class="d-flex gap-2">
      <a href="formaciones_cuadrillas.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
      <?php if ($formacion): ?>
        <a href="formaciones_cuadrilla_excel.php?cuadrilla=<?= (int)$formacion['cuadrilla'] ?>" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel"></i> Exportar
        </a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="container mb-5">
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php else: ?>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3"><strong>Cuadrilla:</strong> <?= (int)$formacion['cuadrilla'] ?></div>
          <div class="col-md-3"><strong>Fecha:</strong> <?= esc((string)$formacion['fecha']) ?></div>
          <div class="col-md-3"><strong>Servicio:</strong> <?= esc((string)$formacion['servicio']) ?></div>
          <div class="col-md-3"><strong>Empresa:</strong> <?= esc((string)$formacion['empresa']) ?></div>
          <div class="col-md-3"><strong>UO:</strong> <?= esc((string)$formacion['uo']) ?></div>
          <div class="col-md-3"><strong>Jornada:</strong> <?= esc((string)$formacion['jornada']) ?></div>
        </div>
      </div>
    </div>

    <div class="scroll-box">
      <table class="table table-hover table-sm align-middle">
        <thead>
          <tr>
            <th>RUT</th>
            <th>Nombre</th>
            <th>Apellido</th>
            <th>Nota</th>
            <th>Porcentaje</th>
            <th>Correctas</th>
            <th>Incorrectas</th>
            <th>No contestadas</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($participantes)): ?>
          <tr><td colspan="6" class="text-center text-muted">Sin participantes</td></tr>
        <?php else: ?>
          <?php foreach ($participantes as $p): ?>
            <?php
              $estado = estadoResultado($p['resultado'] ?? null);
              $nota = $p['notafinal'] !== null ? (string)$p['notafinal'] : '';
              $porcentaje = $p['puntaje_total'] !== null ? (string)$p['puntaje_total'] : '';
            ?>
            <tr>
              <td><?= esc((string)$p['rut']) ?></td>
              <td><?= esc((string)$p['nombre']) ?></td>
              <td><?= esc((string)$p['apellidos']) ?></td>
              <td><?= esc($nota) ?></td>
              <td><?= esc($porcentaje) ?></td>
              <td><?= esc((string)($p['correctas'] ?? '')) ?></td>
              <td><?= esc((string)($p['incorrectas'] ?? '')) ?></td>
              <td><?= esc((string)($p['ncontestadas'] ?? '')) ?></td>
              <td><?= esc($estado) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
