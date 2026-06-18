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

function scdEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scdBuildScope(): array
{
    $idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
    $idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['auth']['id'] ?? 0);
    $empresaEnel = 39;

    if (($idRol === 1 || $idRol === 5) && $idEmpresa === $empresaEnel) {
        return ['1=1', []];
    }

    if ($idRol === 3 || $idRol === 4 || $idRol === 6) {
        return ['s.solicitante = :scope_iduser', [':scope_iduser' => $idUsuario]];
    }

    return [
        '(s.contratista = :scope_empresa OR s.solicitante = :scope_iduser)',
        [
            ':scope_empresa' => $idEmpresa,
            ':scope_iduser' => $idUsuario,
        ],
    ];
}

function scdBaseSql(): string
{
    return "
        SELECT
            s.nsolicitud,
            s.fecha,
            s.tipohabilitacion,
            COALESCE(s.observacion, '') AS observacion,
            COALESCE(s.tipo_visita, '') AS tipo_visita,
            COALESCE(e.nombre, '') AS empresa,
            TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS solicitante,
            COALESCE(pa.desc_patios, '') AS patio,
            COALESCE(pr.desc_proceso, '') AS proceso,
            COALESCE(ht.desc_tipo, '') AS habilitacionceo,
            COALESCE(ch.desc_charlas, '') AS capacitacion,
            COALESCE(rd.reinduccion, '') AS motivo_reinduccion,
            COALESCE(ps.rut, '') AS rut,
            COALESCE(ps.nombre, '') AS nombre,
            COALESCE(ps.apellidop, '') AS apellidop,
            COALESCE(ps.apellidom, '') AS apellidom,
            COALESCE(cc.cargo, '') AS cargo,
            TRIM(CONCAT(COALESCE(ps.nombre, ''), ' ', COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS nombre_completo,
            COALESCE(ps.asistio, 0) AS asistio
        FROM ceo_solicitudes s
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ps.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_usuarios u ON u.id = s.solicitante
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        LEFT JOIN ceo_reinduccion rd ON rd.id = s.motivoreinduccion
    ";
}

function scdFetchRows(PDO $pdo, array $filters): array
{
    [$scopeWhere, $scopeParams] = scdBuildScope();

    $where = [$scopeWhere, 's.fecha BETWEEN :fecha_desde AND :fecha_hasta'];
    $params = $scopeParams;
    $params[':fecha_desde'] = $filters['fecha_desde'];
    $params[':fecha_hasta'] = $filters['fecha_hasta'];

    if ($filters['id'] > 0) {
        $where[] = 's.nsolicitud = :nsolicitud';
        $params[':nsolicitud'] = $filters['id'];
    }
    if ($filters['empresa'] > 0) {
        $where[] = 's.contratista = :empresa';
        $params[':empresa'] = $filters['empresa'];
    }
    if ($filters['solicitante'] > 0) {
        $where[] = 's.solicitante = :solicitante';
        $params[':solicitante'] = $filters['solicitante'];
    }
    if ($filters['patio'] > 0) {
        $where[] = 's.patio = :patio';
        $params[':patio'] = $filters['patio'];
    }
    if ($filters['proceso'] > 0) {
        $where[] = 's.proceso = :proceso';
        $params[':proceso'] = $filters['proceso'];
    }
    if ($filters['habilitacionceo'] > 0) {
        $where[] = 's.habilitacionceo = :habilitacionceo';
        $params[':habilitacionceo'] = $filters['habilitacionceo'];
    }
    if ($filters['tipohabilitacion'] !== '') {
        $where[] = 's.tipohabilitacion = :tipohabilitacion';
        $params[':tipohabilitacion'] = $filters['tipohabilitacion'];
    }
    if ($filters['charla'] > 0) {
        $where[] = 's.charla = :charla';
        $params[':charla'] = $filters['charla'];
    }
    if ($filters['motivoreinduccion'] > 0) {
        $where[] = 's.motivoreinduccion = :motivoreinduccion';
        $params[':motivoreinduccion'] = $filters['motivoreinduccion'];
    }
    if ($filters['tipo_visita'] !== '') {
        $where[] = 'COALESCE(s.tipo_visita, \'\') = :tipo_visita';
        $params[':tipo_visita'] = $filters['tipo_visita'];
    }
    if ($filters['asistio'] !== '') {
        $where[] = 'COALESCE(ps.asistio, 0) = :asistio';
        $params[':asistio'] = (int)$filters['asistio'];
    }

    $sql = scdBaseSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.fecha ASC, s.nsolicitud ASC, ps.apellidop ASC, ps.apellidom ASC, ps.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$hoy = date('Y-m-d');
$fechaDesde = trim((string)($_GET['fecha_desde'] ?? $hoy));
$fechaHasta = trim((string)($_GET['fecha_hasta'] ?? $hoy));
if ($fechaDesde === '') {
    $fechaDesde = $hoy;
}
if ($fechaHasta === '') {
    $fechaHasta = $hoy;
}
if ($fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$filters = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'id' => max(0, (int)($_GET['id'] ?? 0)),
    'empresa' => max(0, (int)($_GET['empresa'] ?? 0)),
    'solicitante' => max(0, (int)($_GET['solicitante'] ?? 0)),
    'patio' => max(0, (int)($_GET['patio'] ?? 0)),
    'proceso' => max(0, (int)($_GET['proceso'] ?? 0)),
    'habilitacionceo' => max(0, (int)($_GET['habilitacionceo'] ?? 0)),
    'tipohabilitacion' => trim((string)($_GET['tipohabilitacion'] ?? '')),
    'charla' => max(0, (int)($_GET['charla'] ?? 0)),
    'motivoreinduccion' => max(0, (int)($_GET['motivoreinduccion'] ?? 0)),
    'tipo_visita' => trim((string)($_GET['tipo_visita'] ?? '')),
    'asistio' => in_array((string)($_GET['asistio'] ?? ''), ['0', '1'], true) ? (string)$_GET['asistio'] : '',
];

$empresas = $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$solicitantes = $pdo->query("SELECT id, TRIM(CONCAT(COALESCE(nombres, ''), ' ', COALESCE(apellidos, ''))) AS nombre FROM ceo_usuarios ORDER BY nombres, apellidos")->fetchAll(PDO::FETCH_ASSOC);
$patios = $pdo->query('SELECT id, desc_patios FROM ceo_patios ORDER BY desc_patios')->fetchAll(PDO::FETCH_ASSOC);
$procesos = $pdo->query('SELECT id, desc_proceso FROM ceo_procesos ORDER BY desc_proceso')->fetchAll(PDO::FETCH_ASSOC);
$habilitaciones = $pdo->query('SELECT id, desc_tipo FROM ceo_habilitaciontipo ORDER BY desc_tipo')->fetchAll(PDO::FETCH_ASSOC);
$tiposHabilitacion = $pdo->query("SELECT DISTINCT TRIM(tipohabilitacion) AS tipohabilitacion FROM ceo_solicitudes WHERE TRIM(COALESCE(tipohabilitacion, '')) <> '' ORDER BY tipohabilitacion")->fetchAll(PDO::FETCH_ASSOC);
$charlas = $pdo->query('SELECT id, desc_charlas FROM ceo_charlas ORDER BY desc_charlas')->fetchAll(PDO::FETCH_ASSOC);
$reinducciones = $pdo->query('SELECT id, reinduccion FROM ceo_reinduccion ORDER BY reinduccion')->fetchAll(PDO::FETCH_ASSOC);
$tiposVisita = [
    'Colegio',
    'Institutos profesionales',
    'Universidades',
    'Municipios',
    'Carabineros',
    'Bomberos',
    'PDI',
    'Aduana',
];

$rows = [];
$error = '';

try {
    $rows = scdFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el informe.';
}

$excelUrl = 'solicitudes_consulta_detalle_excel.php?' . http_build_query($filters);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Consulta Detallada de Solicitudes - <?= scdEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .brand-title { color:#0065a4; font-weight:600; }
    .card { border:none; box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .table thead th { background:#eaf2fb; position:sticky; top:0; z-index:2; white-space:nowrap; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= scdEsc(APP_LOGO) ?>" alt="Logo" style="height:54px;">
      <div>
        <div class="brand-title h5 mb-0"><?= scdEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= scdEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
        <div>
          <h1 class="h4 mb-1 text-primary">Consulta Detallada de Solicitudes</h1>
          <p class="mb-0 text-muted">Detalle por participante asociado a cada solicitud del periodo consultado.</p>
        </div>
        <div class="text-muted small">Total registros: <?= count($rows) ?></div>
      </div>

      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Fecha desde</label>
          <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?= scdEsc($filters['fecha_desde']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Fecha hasta</label>
          <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?= scdEsc($filters['fecha_hasta']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">ID Solicitud</label>
          <input type="number" min="0" name="id" class="form-control form-control-sm" value="<?= $filters['id'] > 0 ? (int)$filters['id'] : '' ?>" placeholder="Todas">
        </div>
        <div class="col-md-3">
          <label class="form-label">Empresa</label>
          <select name="empresa" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($empresas as $empresa): ?>
              <option value="<?= (int)$empresa['id'] ?>" <?= $filters['empresa'] === (int)$empresa['id'] ? 'selected' : '' ?>><?= scdEsc($empresa['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Solicitante</label>
          <select name="solicitante" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($solicitantes as $solicitante): ?>
              <option value="<?= (int)$solicitante['id'] ?>" <?= $filters['solicitante'] === (int)$solicitante['id'] ? 'selected' : '' ?>><?= scdEsc($solicitante['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Patio</label>
          <select name="patio" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($patios as $patio): ?>
              <option value="<?= (int)$patio['id'] ?>" <?= $filters['patio'] === (int)$patio['id'] ? 'selected' : '' ?>><?= scdEsc($patio['desc_patios']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proceso</label>
          <select name="proceso" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($procesos as $proceso): ?>
              <option value="<?= (int)$proceso['id'] ?>" <?= $filters['proceso'] === (int)$proceso['id'] ? 'selected' : '' ?>><?= scdEsc($proceso['desc_proceso']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Habilitación CEO</label>
          <select name="habilitacionceo" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($habilitaciones as $habilitacion): ?>
              <option value="<?= (int)$habilitacion['id'] ?>" <?= $filters['habilitacionceo'] === (int)$habilitacion['id'] ? 'selected' : '' ?>><?= scdEsc($habilitacion['desc_tipo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo Habilitación</label>
          <select name="tipohabilitacion" class="form-select form-select-sm">
            <option value="">Todas</option>
            <?php foreach ($tiposHabilitacion as $tipo): ?>
              <option value="<?= scdEsc($tipo['tipohabilitacion']) ?>" <?= $filters['tipohabilitacion'] === (string)$tipo['tipohabilitacion'] ? 'selected' : '' ?>><?= scdEsc($tipo['tipohabilitacion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Capacitación</label>
          <select name="charla" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($charlas as $charla): ?>
              <option value="<?= (int)$charla['id'] ?>" <?= $filters['charla'] === (int)$charla['id'] ? 'selected' : '' ?>><?= scdEsc($charla['desc_charlas']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Motivo Reinducción</label>
          <select name="motivoreinduccion" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($reinducciones as $reinduccion): ?>
              <option value="<?= (int)$reinduccion['id'] ?>" <?= $filters['motivoreinduccion'] === (int)$reinduccion['id'] ? 'selected' : '' ?>><?= scdEsc($reinduccion['reinduccion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo de visita</label>
          <select name="tipo_visita" class="form-select form-select-sm">
            <option value="">Todas</option>
            <?php foreach ($tiposVisita as $tipoVisita): ?>
              <option value="<?= scdEsc($tipoVisita) ?>" <?= $filters['tipo_visita'] === $tipoVisita ? 'selected' : '' ?>><?= scdEsc($tipoVisita) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Asistió</label>
          <select name="asistio" class="form-select form-select-sm">
            <option value="" <?= $filters['asistio'] === '' ? 'selected' : '' ?>>Todos</option>
            <option value="1" <?= $filters['asistio'] === '1' ? 'selected' : '' ?>>Si</option>
            <option value="0" <?= $filters['asistio'] === '0' ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Consultar</button>
          <a href="<?= scdEsc($excelUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
          <a href="general.php" class="btn btn-outline-secondary btn-sm">Volver</a>
        </div>
      </form>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= scdEsc($error) ?></div>
  <?php endif; ?>

  <div class="card rounded-4">
    <div class="card-body p-3">
      <div class="table-responsive" style="max-height:600px;overflow:auto;">
        <table class="table table-bordered table-sm align-middle mb-0">
          <thead class="text-center align-middle">
            <tr>
              <th>Fecha</th>
              <th>N° Solicitud</th>
              <th>Empresa</th>
              <th>Solicitante</th>
              <th>Patio</th>
              <th>Proceso</th>
              <th>Habilitación CEO</th>
              <th>Tipo Habilitación</th>
              <th>Capacitación</th>
              <th>Motivo Reinducción</th>
              <th>Tipo de visita</th>
              <th>Observación</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Apellidos</th>
              <th>Cargo</th>
              <th>Asistió</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= scdEsc($row['fecha']) ?></td>
                <td class="text-center"><?= (int)$row['nsolicitud'] ?></td>
                <td><?= scdEsc($row['empresa']) ?></td>
                <td><?= scdEsc($row['solicitante']) ?></td>
                <td><?= scdEsc($row['patio']) ?></td>
                <td><?= scdEsc($row['proceso']) ?></td>
                <td><?= scdEsc($row['habilitacionceo']) ?></td>
                <td><?= scdEsc($row['tipohabilitacion']) ?></td>
                <td><?= scdEsc($row['capacitacion']) ?></td>
                <td><?= scdEsc($row['motivo_reinduccion']) ?></td>
                <td><?= scdEsc($row['tipo_visita']) ?></td>
                <td><?= scdEsc($row['observacion']) ?></td>
                <td><?= scdEsc($row['rut']) ?></td>
                <td><?= scdEsc($row['nombre']) ?></td>
                <td><?= scdEsc(trim($row['apellidop'] . ' ' . $row['apellidom'])) ?></td>
                <td><?= scdEsc($row['cargo']) ?></td>
                <td class="text-center"><?= (int)$row['asistio'] === 1 ? 'Si' : 'No' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
              <td colspan="17" class="text-center text-muted py-4">No se encontraron registros para los filtros seleccionados.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
