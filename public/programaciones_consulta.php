<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';

$pdo = db();
$fuente = strtoupper(trim((string)($_GET['fuente'] ?? 'HABILITACIONES')));
if (!in_array($fuente, ['HABILITACIONES', 'FORMACIONES'], true)) {
    $fuente = 'HABILITACIONES';
}

$rut = trim((string)($_GET['rut'] ?? ''));
$fecha = trim((string)($_GET['fecha'] ?? ''));
if ($fecha !== '') {
    $fechaObj = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fecha) {
        $fecha = '';
    }
}

$rows = [];
$error = '';
$busquedaRealizada = $rut !== '' || $fecha !== '';
$esContratista = strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista';
$idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);

if ($busquedaRealizada) {
    $where = [];
    $params = [];

    if ($rut !== '') {
        $where[] = 'p.rut = :rut';
        $params[':rut'] = $rut;
    }

    if ($fecha !== '') {
        $where[] = 'p.fecha_programacion >= :fecha_desde AND p.fecha_programacion < :fecha_hasta';
        $params[':fecha_desde'] = $fecha . ' 00:00:00';
        $params[':fecha_hasta'] = (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d 00:00:00');
    }

    if ($fuente === 'HABILITACIONES') {
        if ($esContratista) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ceo_habilitacion h
                WHERE h.id_servicio = p.id_servicio
                  AND h.cuadrilla = p.cuadrilla
                  AND h.empresa = :empresa
            )';
            $params[':empresa'] = $idEmpresa;
        }

        $sql = '
            SELECT
                p.rut,
                hp.nombre,
                hp.apellidos,
                s.servicio,
                p.tipo,
                p.cuadrilla,
                p.id_proceso_habilitacion,
                p.fecha_programacion,
                p.estado,
                p.resultado
            FROM ceo_evaluaciones_programadas p
            LEFT JOIN ceo_servicios_pruebas s ON s.id = p.id_servicio
            LEFT JOIN ceo_habilitacion_participantes hp
                ON hp.rut = p.rut
               AND hp.id_cuadrilla = p.cuadrilla
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.fecha_programacion DESC, p.id DESC
        ';
    } else {
        if ($esContratista) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ceo_formacion f
                WHERE f.id_servicio = p.id_servicio
                  AND f.cuadrilla = p.cuadrilla
                  AND f.empresa = :empresa
            )';
            $params[':empresa'] = $idEmpresa;
        }

        $sql = '
            SELECT
                p.rut,
                fp.nombre,
                fp.apellidos,
                s.servicio,
                p.tipo,
                p.cuadrilla,
                NULL AS id_proceso_habilitacion,
                p.fecha_programacion,
                p.estado,
                p.resultado
            FROM ceo_formacion_programadas p
            LEFT JOIN ceo_formacion_servicios s ON s.id = p.id_servicio
            LEFT JOIN ceo_formacion_participantes fp
                ON fp.rut = p.rut
               AND fp.id_cuadrilla = p.cuadrilla
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.fecha_programacion DESC, p.id DESC
        ';
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'No fue posible cargar las programaciones.';
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $error .= ' ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consulta de Programaciones | <?= esc(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f7f9fc; }
    .topbar { background: #fff; border-bottom: 1px solid #e3e6ea; }
    .brand-title { color: #0065a4; font-weight: 600; }
    .table thead th { background: #eaf2fb; white-space: nowrap; text-align: center; }
    .table td { vertical-align: middle; }
  </style>
</head>
<body>
  <header class="topbar py-3 mb-4">
    <div class="container d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <img src="<?= APP_LOGO ?>" alt="Logo <?= esc(APP_NAME) ?>" style="height: 55px;">
        <div>
          <div class="brand-title h5 mb-0"><?= esc(APP_NAME) ?></div>
          <small class="text-secondary"><?= esc(APP_SUBTITLE) ?></small>
        </div>
      </div>
      <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
    </div>
  </header>

  <main class="container-fluid px-4 pb-4">
    <div class="card shadow-sm rounded-4 mb-4">
      <div class="card-body py-3">
        <h4 class="fw-bold text-primary mb-0"><i class="bi bi-calendar-check me-2"></i>Consulta de Programaciones</h4>
      </div>
    </div>

    <div class="card shadow-sm rounded-4 mb-4">
      <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
          <div class="col-md-3">
            <label for="fuente" class="form-label fw-bold">Fuente</label>
            <select name="fuente" id="fuente" class="form-select">
              <option value="HABILITACIONES" <?= $fuente === 'HABILITACIONES' ? 'selected' : '' ?>>Habilitaciones</option>
              <option value="FORMACIONES" <?= $fuente === 'FORMACIONES' ? 'selected' : '' ?>>Formaciones</option>
            </select>
          </div>
          <div class="col-md-3">
            <label for="rut" class="form-label fw-bold">RUT</label>
            <input type="text" name="rut" id="rut" class="form-control" value="<?= esc($rut) ?>" placeholder="Ej.: 12345678-9">
          </div>
          <div class="col-md-3">
            <label for="fecha" class="form-label fw-bold">Fecha de programación</label>
            <input type="date" name="fecha" id="fecha" class="form-control" value="<?= esc($fecha) ?>">
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Consultar</button>
            <a href="programaciones_consulta.php" class="btn btn-outline-secondary">Limpiar</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm rounded-4">
      <div class="card-body">
        <?php if ($error !== ''): ?>
          <div class="alert alert-danger mb-0"><?= esc($error) ?></div>
        <?php elseif (!$busquedaRealizada): ?>
          <div class="alert alert-info mb-0">Ingrese un RUT, una fecha de programación o ambos criterios para consultar.</div>
        <?php elseif (empty($rows)): ?>
          <div class="alert alert-warning mb-0">No se encontraron programaciones para los criterios seleccionados.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover table-sm table-bordered align-middle mb-0">
              <thead>
                <tr>
                  <th>RUT</th>
                  <th>Nombre</th>
                  <th>Apellido</th>
                  <th>Servicio</th>
                  <th>Tipo</th>
                  <th>Cuadrilla</th>
                  <th>Proceso habilitación</th>
                  <th>Fecha programación</th>
                  <th>Estado</th>
                  <th>Resultado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td><?= esc((string)$row['rut']) ?></td>
                    <td><?= esc((string)($row['nombre'] ?? '')) ?></td>
                    <td><?= esc((string)($row['apellidos'] ?? '')) ?></td>
                    <td><?= esc((string)($row['servicio'] ?? '')) ?></td>
                    <td class="text-center"><?= esc((string)$row['tipo']) ?></td>
                    <td class="text-center"><?= esc((string)$row['cuadrilla']) ?></td>
                    <td class="text-center"><?= esc((string)($row['id_proceso_habilitacion'] ?? '')) ?></td>
                    <td class="text-center"><?= esc((string)$row['fecha_programacion']) ?></td>
                    <td class="text-center"><?= esc((string)$row['estado']) ?></td>
                    <td class="text-center"><?= esc((string)$row['resultado']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
