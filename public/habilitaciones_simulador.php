<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

if (!function_exists('simuladorHasColumn')) {
    function simuladorHasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($column));
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }
}

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
$hasEvaluacionProgramadaAgrupacion = simuladorHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion');

$cuadrillas = $pdo->query("
    SELECT
        ep.cuadrilla,
        MAX(h.fecha) AS fecha,
        sp.servicio,
        MAX(ph.numero_proceso) AS numero_proceso
    FROM ceo_evaluaciones_programadas ep
    LEFT JOIN ceo_habilitacion h ON h.cuadrilla = ep.cuadrilla
    LEFT JOIN ceo_servicios_pruebas sp ON sp.id = ep.id_servicio
    LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = ep.id_proceso_habilitacion
    WHERE ep.estado = 'PENDIENTE'
      AND ep.resultado = 'PENDIENTE'
      AND ep.tipo = 'PRUEBA'
    GROUP BY ep.cuadrilla, sp.servicio
    ORDER BY fecha DESC, ep.cuadrilla DESC
")->fetchAll(PDO::FETCH_ASSOC);

$participantes = [];
if ($cuadrilla > 0) {
    $selectAgrupacion = $hasEvaluacionProgramadaAgrupacion
        ? 'ep.id_agrupacion'
        : 'NULL AS id_agrupacion';
    $stmt = $pdo->prepare("
        SELECT
            ep.id AS id_programada,
            ep.rut,
            hp.nombre,
            hp.apellidos,
            ep.id_servicio,
            {$selectAgrupacion},
            sp.servicio,
            ph.numero_proceso
        FROM ceo_evaluaciones_programadas ep
        LEFT JOIN ceo_habilitacion_participantes hp
            ON hp.rut = ep.rut
           AND hp.id_cuadrilla = ep.cuadrilla
        LEFT JOIN ceo_servicios_pruebas sp
            ON sp.id = ep.id_servicio
        LEFT JOIN ceo_proceso_habilitacion ph
            ON ph.id = ep.id_proceso_habilitacion
        WHERE ep.cuadrilla = :cuadrilla
          AND ep.estado = 'PENDIENTE'
          AND ep.resultado = 'PENDIENTE'
          AND ep.tipo = 'PRUEBA'
        ORDER BY sp.servicio ASC, hp.apellidos ASC, hp.nombre ASC, ep.id ASC
    ");
    $stmt->execute([':cuadrilla' => $cuadrilla]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Habilitaciones - Simulador | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.brand-title { color:#0065a4; font-weight:600; }
.card { border-radius:12px; }
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
    <div class="alert alert-info mb-3">
      Este simulador usa la configuración real de Habilitación, pero no guarda respuestas ni resultados.
    </div>
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-7">
        <label class="form-label">Cuadrilla con prueba pendiente</label>
        <select name="cuadrilla" class="form-select" required>
          <option value="">Seleccione...</option>
          <?php foreach ($cuadrillas as $c): ?>
            <option value="<?= (int)$c['cuadrilla'] ?>" <?= $cuadrilla === (int)$c['cuadrilla'] ? 'selected' : '' ?>>
              <?= (int)$c['cuadrilla'] ?>
              - <?= esc((string)($c['servicio'] ?? 'Sin servicio')) ?>
              <?php if (!empty($c['numero_proceso'])): ?>
                - Proceso <?= esc((string)$c['numero_proceso']) ?>
              <?php endif; ?>
              <?php if (!empty($c['fecha'])): ?>
                (<?= esc((string)$c['fecha']) ?>)
              <?php endif; ?>
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
      <h5 class="text-primary mb-3"><i class="bi bi-person-check me-2"></i>Personas disponibles para simular</h5>
      <?php if (empty($participantes)): ?>
        <div class="text-muted">No hay participantes pendientes para esta cuadrilla.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>RUT</th>
                <th>Nombre</th>
                <th>Servicio</th>
                <th>Proceso</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($participantes as $p): ?>
                <tr>
                  <td><?= esc((string)$p['rut']) ?></td>
                  <td><?= esc(trim((string)($p['nombre'] ?? '') . ' ' . (string)($p['apellidos'] ?? ''))) ?></td>
                  <td><?= esc((string)($p['servicio'] ?? '')) ?></td>
                  <td><?= esc((string)($p['numero_proceso'] ?? '')) ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-primary"
                       href="habilitaciones_simulador_iniciar.php?id_programada=<?= (int)$p['id_programada'] ?>&rut=<?= urlencode((string)$p['rut']) ?>&id_agrupacion=<?= (int)($p['id_agrupacion'] ?? 0) ?>">
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
