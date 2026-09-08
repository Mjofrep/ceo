<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

$pdo = db();

$fechaConsulta = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
$fechaObj = DateTimeImmutable::createFromFormat('Y-m-d', $fechaConsulta);
if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fechaConsulta) {
    $fechaConsulta = date('Y-m-d');
}

$whereEmpresaHab = '';
$whereEmpresaForm = '';
$params = [
    ':fecha_hab' => $fechaConsulta,
    ':fecha_form' => $fechaConsulta,
];

if (strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista') {
    $idEmpresaAuth = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    if ($idEmpresaAuth > 0) {
        $whereEmpresaHab = ' AND h.empresa = :empresa_auth_hab ';
        $whereEmpresaForm = ' AND f.empresa = :empresa_auth_form ';
        $params[':empresa_auth_hab'] = $idEmpresaAuth;
        $params[':empresa_auth_form'] = $idEmpresaAuth;
    }
}

$rows = [];
$error = '';

$sql = "
    SELECT
        'HABILITACION' AS tipo_origen,
        h.id AS id_origen,
        h.cuadrilla,
        e.nombre AS empresa,
        u.desc_uo AS uo,
        sp.servicio,
        h.fecha,
        h.nsolicitud
    FROM ceo_habilitacion h
    LEFT JOIN ceo_empresas e ON e.id = h.empresa
    LEFT JOIN ceo_uo u ON u.id = h.uo
    LEFT JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
    WHERE h.fecha = :fecha_hab
      AND EXISTS (
          SELECT 1
          FROM ceo_evaluaciones_programadas ep
          WHERE ep.id_servicio = h.id_servicio
            AND ep.cuadrilla = h.cuadrilla
            AND ep.tipo = 'PRUEBA'
      )
      {$whereEmpresaHab}

    UNION ALL

    SELECT
        'FORMACION' AS tipo_origen,
        f.id AS id_origen,
        f.cuadrilla,
        e.nombre AS empresa,
        u.desc_uo AS uo,
        fs.servicio,
        f.fecha,
        f.nsolicitud
    FROM ceo_formacion f
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_uo u ON u.id = f.uo
    LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
    WHERE f.fecha = :fecha_form
      AND EXISTS (
          SELECT 1
          FROM ceo_formacion_programadas fp
          WHERE fp.id_servicio = f.id_servicio
            AND fp.cuadrilla = f.cuadrilla
            AND fp.tipo = 'PRUEBA'
      )
      {$whereEmpresaForm}

    ORDER BY fecha ASC, tipo_origen ASC, empresa ASC, servicio ASC, cuadrilla ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Error al cargar cuadrillas de sala de pruebas.';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $error = 'Error SQL: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Sala de Pruebas - Cuadrillas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.table thead {position:sticky; top:0; z-index:2;}
.table th {background:#eaf2fb; white-space:nowrap; text-align:center;}
.table td {vertical-align:middle;}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="habilitaciones.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container-fluid px-4">
    <div class="card rounded-4 shadow-sm mb-4">
        <div class="card-body py-3">
            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-door-open me-2"></i>Sala de Pruebas - Cuadrillas</h4>
        </div>
    </div>

    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <form class="row g-3" method="GET">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= esc($fechaConsulta) ?>" required>
                </div>
                <div class="col-12 text-end mt-2">
                    <button class="btn btn-success"><i class="bi bi-search"></i> Recuperar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm rounded-4">
        <div class="card-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= esc($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($rows)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>Cuadrilla</th>
                                <th>Tipo</th>
                                <th>Empresa</th>
                                <th>UO</th>
                                <th>Servicio</th>
                                <th>Fecha</th>
                                <th>N° Solicitud</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr
                                class="fila-cuadrilla"
                                data-tipo="<?= esc((string)$row['tipo_origen']) ?>"
                                data-id="<?= (int)$row['id_origen'] ?>"
                                style="cursor:pointer;"
                            >
                                <td class="text-center"><?= esc((string)$row['cuadrilla']) ?></td>
                                <td><?= esc((string)$row['tipo_origen']) ?></td>
                                <td><?= esc((string)($row['empresa'] ?? '')) ?></td>
                                <td><?= esc((string)($row['uo'] ?? '')) ?></td>
                                <td><?= esc((string)($row['servicio'] ?? '')) ?></td>
                                <td class="text-center"><?= esc((string)($row['fecha'] ?? '')) ?></td>
                                <td class="text-center"><?= esc((string)($row['nsolicitud'] ?? '')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center mb-0">No hay cuadrillas con pruebas teóricas para la fecha seleccionada.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.fila-cuadrilla').forEach((fila) => {
    fila.addEventListener('click', function () {
        const tipo = this.dataset.tipo || '';
        const id = this.dataset.id || '';
        const fecha = <?= json_encode($fechaConsulta, JSON_UNESCAPED_UNICODE) ?>;

        if (!tipo || !id) {
            return;
        }

        window.location.href = 'sala_pruebas_cuadrilla_detalle.php?tipo=' + encodeURIComponent(tipo) + '&id=' + encodeURIComponent(id) + '&fecha=' + encodeURIComponent(fecha);
    });
});
</script>
</body>
</html>
