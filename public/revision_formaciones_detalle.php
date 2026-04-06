<?php
// --------------------------------------------------------------
// revision_formaciones_detalle.php - Detalle del participante (Formaciones)
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$pdo = db();

$rut = trim((string)($_GET['rut'] ?? ''));
$programa = (int)($_GET['programa'] ?? 0);

$error = '';
$formacion = null;
$participante = null;
$intentos = [];
$programaciones = [];

if ($rut === '') {
    $error = 'RUT no especificado.';
}

if ($error === '') {
    try {
        if ($programa > 0) {
            $stmt = $pdo->prepare("
                SELECT f.id, f.cuadrilla, f.fecha, f.id_servicio,
                       fs.servicio,
                       e.nombre AS empresa,
                       u.desc_uo AS uo
                FROM ceo_formacion f
                LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
                LEFT JOIN ceo_empresas e ON e.id = f.empresa
                LEFT JOIN ceo_uo u ON u.id = f.uo
                WHERE f.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $programa]);
            $formacion = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$formacion) {
            $stmt = $pdo->prepare("
                SELECT f.id, f.cuadrilla, f.fecha, f.id_servicio,
                       fs.servicio,
                       e.nombre AS empresa,
                       u.desc_uo AS uo
                FROM ceo_formacion f
                INNER JOIN ceo_formacion_participantes p ON p.id_cuadrilla = f.cuadrilla
                LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
                LEFT JOIN ceo_empresas e ON e.id = f.empresa
                LEFT JOIN ceo_uo u ON u.id = f.uo
                WHERE p.rut = :rut
                ORDER BY f.fecha DESC, f.id DESC
                LIMIT 1
            ");
            $stmt->execute([':rut' => $rut]);
            $formacion = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($formacion && !empty($formacion['cuadrilla'])) {
            $stmt = $pdo->prepare("
                SELECT id_cuadrilla, rut, nombre, apellidos, cargo
                FROM ceo_formacion_participantes
                WHERE rut = :rut
                  AND id_cuadrilla = :cuadrilla
                LIMIT 1
            ");
            $stmt->execute([
                ':rut' => $rut,
                ':cuadrilla' => (int)$formacion['cuadrilla']
            ]);
            $participante = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$participante) {
            $stmt = $pdo->prepare("
                SELECT id_cuadrilla, rut, nombre, apellidos, cargo
                FROM ceo_formacion_participantes
                WHERE rut = :rut
                ORDER BY id_cuadrilla DESC
                LIMIT 1
            ");
            $stmt->execute([':rut' => $rut]);
            $participante = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!empty($formacion['id_servicio'])) {
            $stmt = $pdo->prepare("
                SELECT id, fecha_rendicion, hora_rendicion, puntaje_total,
                       correctas, incorrectas, ncontestadas, notafinal
                FROM ceo_resultado_formacion_intento
                WHERE rut = :rut
                  AND id_servicio = :servicio
                ORDER BY fecha_rendicion DESC, hora_rendicion DESC
            ");
            $stmt->execute([
                ':rut' => $rut,
                ':servicio' => (int)$formacion['id_servicio']
            ]);
            $intentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($formacion['cuadrilla'])) {
                $stmt = $pdo->prepare("
                    SELECT id, fecha_programacion, estado, resultado, intento
                    FROM ceo_formacion_programadas
                    WHERE rut = :rut
                      AND id_servicio = :servicio
                      AND cuadrilla = :cuadrilla
                    ORDER BY fecha_programacion DESC, intento DESC
                ");
                $stmt->execute([
                    ':rut' => $rut,
                    ':servicio' => (int)$formacion['id_servicio'],
                    ':cuadrilla' => (int)$formacion['cuadrilla']
                ]);
                $programaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        $error = 'Error al cargar detalle de formaciones.';
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $error = 'Error SQL: ' . $e->getMessage();
        }
    }
}

$nombre = '';
if ($participante) {
    $nombre = trim(($participante['nombre'] ?? '') . ' ' . ($participante['apellidos'] ?? ''));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Detalle Participante | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
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
    <a href="revision_formaciones.php?empresa=<?= (int)($_GET['empresa'] ?? 0) ?>&uo=<?= (int)($_GET['uo'] ?? 0) ?>&programa=<?= (int)($_GET['programa'] ?? 0) ?>" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php else: ?>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h5 class="text-primary mb-2"><i class="bi bi-person me-2"></i>Detalle participante</h5>
        <div><strong>RUT:</strong> <?= esc($rut) ?></div>
        <div><strong>Nombre:</strong> <?= esc($nombre) ?></div>
        <div><strong>Cargo:</strong> <?= esc($participante['cargo'] ?? '') ?></div>
        <div><strong>Servicio:</strong> <?= esc($formacion['servicio'] ?? '') ?></div>
        <div><strong>Empresa:</strong> <?= esc($formacion['empresa'] ?? '') ?></div>
        <div><strong>UO:</strong> <?= esc($formacion['uo'] ?? '') ?></div>
        <div><strong>Cuadrilla:</strong> <?= esc((string)($formacion['cuadrilla'] ?? '')) ?></div>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h6 class="text-primary mb-3"><i class="bi bi-clipboard-check me-2"></i>Programaciones</h6>
        <?php if (empty($programaciones)): ?>
          <div class="text-muted">No hay programaciones registradas.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Fecha programacion</th>
                  <th>Intento</th>
                  <th>Estado</th>
                  <th>Resultado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($programaciones as $p): ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td><?= esc((string)$p['fecha_programacion']) ?></td>
                  <td><?= (int)$p['intento'] ?></td>
                  <td><?= esc((string)$p['estado']) ?></td>
                  <td><?= esc((string)$p['resultado']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <h6 class="text-primary mb-3"><i class="bi bi-clipboard-data me-2"></i>Resultados de prueba</h6>
        <?php if (empty($intentos)): ?>
          <div class="text-muted">No hay resultados registrados.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th>Fecha</th>
                  <th>Hora</th>
                  <th>Nota</th>
                  <th>Puntaje</th>
                  <th>Correctas</th>
                  <th>Incorrectas</th>
                  <th>No contestadas</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($intentos as $i): ?>
                <tr>
                  <td><?= esc((string)$i['fecha_rendicion']) ?></td>
                  <td><?= esc((string)$i['hora_rendicion']) ?></td>
                  <td><?= esc((string)$i['notafinal']) ?></td>
                  <td><?= esc((string)$i['puntaje_total']) ?></td>
                  <td><?= esc((string)$i['correctas']) ?></td>
                  <td><?= esc((string)$i['incorrectas']) ?></td>
                  <td><?= esc((string)$i['ncontestadas']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>

</div>

</body>
</html>
