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

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($rol !== 1) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();
$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);

$cuadrillas = $pdo->query("
    SELECT
        fp.cuadrilla,
        MAX(f.fecha) AS fecha,
        s.servicio
    FROM ceo_formacion_programadas fp
    LEFT JOIN ceo_formacion f ON f.cuadrilla = fp.cuadrilla
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    WHERE fp.estado = 'PENDIENTE'
      AND fp.resultado = 'PENDIENTE'
      AND fp.tipo = 'PRUEBA'
    GROUP BY fp.cuadrilla, s.servicio
    ORDER BY fecha DESC, fp.cuadrilla DESC
")->fetchAll(PDO::FETCH_ASSOC);

$participantes = [];
if ($cuadrilla > 0) {
    $stmt = $pdo->prepare("
        SELECT
            fp.id AS id_programada,
            fp.rut,
            p.nombre,
            p.apellidos,
            fp.id_servicio
        FROM ceo_formacion_programadas fp
        LEFT JOIN ceo_formacion_participantes p ON p.rut = fp.rut AND p.id_cuadrilla = fp.cuadrilla
        WHERE fp.cuadrilla = :cuadrilla
          AND fp.estado = 'PENDIENTE'
          AND fp.resultado = 'PENDIENTE'
          AND fp.tipo = 'PRUEBA'
        ORDER BY p.apellidos, p.nombre
    ");
    $stmt->execute([':cuadrilla' => $cuadrilla]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Simulador | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.card {border-radius:12px;}
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
  <div class="card p-3 mb-3">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Cuadrilla (pendiente)</label>
        <select name="cuadrilla" class="form-select" required>
          <option value="">Seleccione...</option>
          <?php foreach ($cuadrillas as $c): ?>
            <option value="<?= (int)$c['cuadrilla'] ?>" <?= $cuadrilla === (int)$c['cuadrilla'] ? 'selected' : '' ?>>
              <?= (int)$c['cuadrilla'] ?> - <?= esc((string)$c['servicio']) ?> (<?= esc((string)$c['fecha']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary">Cargar</button>
      </div>
    </form>
  </div>

  <?php if ($cuadrilla > 0): ?>
    <div class="card p-3">
      <h5 class="text-primary mb-3"><i class="bi bi-person-check me-2"></i>Participantes pendientes</h5>
      <?php if (empty($participantes)): ?>
        <div class="text-muted">No hay participantes pendientes.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>RUT</th>
                <th>Nombre</th>
                <th>Accion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($participantes as $p): ?>
                <tr>
                  <td><?= esc((string)$p['rut']) ?></td>
                  <td><?= esc(trim((string)($p['nombre'] ?? '') . ' ' . (string)($p['apellidos'] ?? ''))) ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-primary"
                       href="formaciones_simulador_iniciar.php?id_programada=<?= (int)$p['id_programada'] ?>&rut=<?= urlencode((string)$p['rut']) ?>">
                      Simular prueba
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
