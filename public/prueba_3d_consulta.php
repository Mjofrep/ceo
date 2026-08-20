<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$pdo = db();
p3dcEnsureTable($pdo);

$filters = p3dcBuildFilters();
$rows = [];
$selectedRow = null;
$error = '';

try {
    $rows = p3dcFetchRows($pdo, $filters);
    if ($filters['id'] > 0) {
        $selectedRow = p3dcFetchRowById($pdo, $filters['id']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$summary = p3dcBuildSummary($rows);

function p3dcEnsureTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ceo_prueba_3d (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero_registro INT NOT NULL,
    marca_temporal DATETIME NULL,
    rut_usuario VARCHAR(20) NOT NULL,
    fecha_registro DATETIME NULL,
    intentos_epp INT NULL,
    elementos_correctos INT NULL,
    arb01_tipo_poda VARCHAR(120) NULL,
    arb01_area_poda VARCHAR(120) NULL,
    arb01_factibilidad VARCHAR(20) NULL,
    arb01_zona_segura VARCHAR(60) NULL,
    arb01_cantidad_cortes INT NULL,
    arb01_distancia_collar_mm DECIMAL(10,2) NULL,
    arb01_angulo_collar DECIMAL(10,2) NULL,
    arb02_tipo_poda VARCHAR(120) NULL,
    arb02_area_poda VARCHAR(120) NULL,
    arb02_factibilidad VARCHAR(20) NULL,
    arb02_zona_segura VARCHAR(60) NULL,
    arb02_cantidad_cortes INT NULL,
    arb02_distancia_collar_mm DECIMAL(10,2) NULL,
    arb02_angulo_collar DECIMAL(10,2) NULL,
    arb03_tipo_poda VARCHAR(120) NULL,
    arb03_area_poda VARCHAR(120) NULL,
    arb03_factibilidad VARCHAR(20) NULL,
    puntuacion_final_1 DECIMAL(10,4) NULL,
    resultado_habilitacion VARCHAR(80) NULL,
    columna_aux_1 DECIMAL(12,6) NULL,
    intentos_epp_2 INT NULL,
    porcentaje_epp DECIMAL(10,4) NULL,
    elementos_correctos_epp INT NULL,
    porcentaje_correctos_epp DECIMAL(10,4) NULL,
    puntaje_epp DECIMAL(10,4) NULL,
    feedback_epp TEXT NULL,
    tipo_poda_resumen VARCHAR(80) NULL,
    area_poda_resumen VARCHAR(80) NULL,
    factibilidad_resumen VARCHAR(80) NULL,
    feedback_pre_poda TEXT NULL,
    zona_segura_corte_resumen VARCHAR(80) NULL,
    cantidad_orden_cortes_resumen VARCHAR(80) NULL,
    cercania_collar_resumen VARCHAR(80) NULL,
    angulo_corte_resumen VARCHAR(80) NULL,
    feedback_poda TEXT NULL,
    puntuacion_final_2 DECIMAL(10,4) NULL,
    nombre_archivo VARCHAR(255) NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_carga INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_prueba_3d_numero_rut (numero_registro, rut_usuario),
    KEY idx_prueba_3d_rut (rut_usuario),
    KEY idx_prueba_3d_fecha_registro (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $ready = true;
}

function p3dcBuildFilters(): array
{
    return [
        'rut' => trim((string)($_GET['rut'] ?? '')),
        'numero' => trim((string)($_GET['numero'] ?? '')),
        'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
        'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
        'resultado' => trim((string)($_GET['resultado'] ?? '')),
        'id' => (int)($_GET['id'] ?? 0),
    ];
}

function p3dcFetchRows(PDO $pdo, array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['rut'] !== '') {
        $where[] = 'rut_usuario = :rut';
        $params[':rut'] = $filters['rut'];
    }
    if ($filters['numero'] !== '') {
        $where[] = 'numero_registro = :numero';
        $params[':numero'] = (int)$filters['numero'];
    }
    if ($filters['fecha_desde'] !== '') {
        $where[] = 'DATE(fecha_registro) >= :fecha_desde';
        $params[':fecha_desde'] = $filters['fecha_desde'];
    }
    if ($filters['fecha_hasta'] !== '') {
        $where[] = 'DATE(fecha_registro) <= :fecha_hasta';
        $params[':fecha_hasta'] = $filters['fecha_hasta'];
    }
    if ($filters['resultado'] !== '') {
        $where[] = 'resultado_habilitacion = :resultado';
        $params[':resultado'] = $filters['resultado'];
    }

    $sql = 'SELECT * FROM ceo_prueba_3d';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY COALESCE(fecha_registro, marca_temporal, fecha_carga) DESC, numero_registro DESC, id DESC';

    if ($filters['rut'] === '') {
        $sql .= ' LIMIT 200';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function p3dcFetchRowById(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM ceo_prueba_3d WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function p3dcBuildSummary(array $rows): array
{
    $ruts = [];
    $ultimaFecha = null;
    $noHabilitados = 0;

    foreach ($rows as $row) {
        $rut = trim((string)($row['rut_usuario'] ?? ''));
        if ($rut !== '') {
            $ruts[$rut] = true;
        }

        $resultado = mb_strtolower(trim((string)($row['resultado_habilitacion'] ?? '')), 'UTF-8');
        if ($resultado !== '' && str_contains($resultado, 'no habilitado')) {
            $noHabilitados++;
        }

        $fecha = trim((string)($row['fecha_registro'] ?? $row['marca_temporal'] ?? $row['fecha_carga'] ?? ''));
        if ($fecha !== '' && ($ultimaFecha === null || $fecha > $ultimaFecha)) {
            $ultimaFecha = $fecha;
        }
    }

    return [
        'total' => count($rows),
        'ruts' => count($ruts),
        'ultima_fecha' => $ultimaFecha,
        'no_habilitados' => $noHabilitados,
    ];
}

function p3dcEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function p3dcFormatDate(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('d-m-Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function p3dcBadgeClass(?string $resultado): string
{
    $texto = mb_strtolower(trim((string)$resultado), 'UTF-8');
    if ($texto === '') {
        return 'secondary';
    }
    if (str_contains($texto, 'no habilitado')) {
        return 'danger';
    }
    if (str_contains($texto, 'habilitado')) {
        return 'success';
    }
    return 'secondary';
}

function p3dcCurrentQuery(array $filters, array $overrides = []): string
{
    $query = array_merge($filters, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === 0 || $value === null) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function p3dcField(string $label, $value): void
{
    $text = trim((string)$value);
    ?>
    <div class="col-md-6 col-xl-3">
      <div class="field-card h-100">
        <div class="field-label"><?= p3dcEsc($label) ?></div>
        <div class="field-value"><?php if ($text === ''): ?><span class="text-muted">Sin dato</span><?php else: ?><?= p3dcEsc($text) ?><?php endif; ?></div>
      </div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Consulta Prueba 3D | <?= p3dcEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .card-soft { border:none; box-shadow:0 2px 8px rgba(15,23,42,.06); border-radius:1rem; }
    .table thead th { background:#eaf2fb; white-space:nowrap; }
    .summary-box { background:linear-gradient(135deg,#fff 0%,#f8fbff 100%); border:1px solid rgba(59,130,246,.10); }
    .field-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:12px 14px; }
    .field-label { font-size:.78rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px; letter-spacing:.04em; }
    .field-value { color:#0f172a; font-weight:500; word-break:break-word; }
    .section-title { color:#0d6efd; font-weight:700; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= p3dcEsc(APP_LOGO) ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= p3dcEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= p3dcEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a href="<?= p3dcEsc(app_url('/public/cargar_prueba_3d.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Cargar archivo</a>
      <a href="<?= p3dcEsc(app_url('/public/general.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
    </div>
  </div>
</header>

<main class="container-fluid px-4 pb-5">
  <div class="card card-soft mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="bi bi-view-list me-2"></i>Consulta Prueba 3D</h4>
        <div class="text-muted small">Búsqueda histórica por RUT o filtros complementarios, con detalle del registro en la misma página.</div>
      </div>
      <span class="badge text-bg-primary">Registros visibles: <?= (int)$summary['total'] ?></span>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= p3dcEsc($error) ?></div>
  <?php endif; ?>

  <div class="card card-soft mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label fw-semibold">RUT Usuario</label>
          <input type="text" name="rut" class="form-control" value="<?= p3dcEsc($filters['rut']) ?>" placeholder="12345678-9">
        </div>
        <div class="col-md-1">
          <label class="form-label fw-semibold">N</label>
          <input type="number" name="numero" class="form-control" value="<?= p3dcEsc($filters['numero']) ?>" min="1">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Fecha desde</label>
          <input type="date" name="fecha_desde" class="form-control" value="<?= p3dcEsc($filters['fecha_desde']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Fecha hasta</label>
          <input type="date" name="fecha_hasta" class="form-control" value="<?= p3dcEsc($filters['fecha_hasta']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Resultado</label>
          <select name="resultado" class="form-select">
            <option value="">Todos</option>
            <?php foreach (['❌ No Habilitado', '✔ Habilitado', 'Habilitado', 'No Habilitado'] as $resultadoOption): ?>
              <option value="<?= p3dcEsc($resultadoOption) ?>" <?= $filters['resultado'] === $resultadoOption ? 'selected' : '' ?>><?= p3dcEsc($resultadoOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Consultar</button>
          <a href="<?= p3dcEsc(app_url('/public/prueba_3d_consulta.php')) ?>" class="btn btn-outline-secondary">Limpiar</a>
        </div>
      </form>
      <?php if ($filters['rut'] === ''): ?>
        <div class="small text-muted mt-3">Sin filtro por RUT se muestran los últimos 200 registros.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-md-3">
      <div class="card card-soft summary-box h-100"><div class="card-body"><div class="small text-muted">Registros encontrados</div><div class="fs-3 fw-bold text-primary"><?= (int)$summary['total'] ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card card-soft summary-box h-100"><div class="card-body"><div class="small text-muted">RUT distintos</div><div class="fs-3 fw-bold"><?= (int)$summary['ruts'] ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card card-soft summary-box h-100"><div class="card-body"><div class="small text-muted">Última fecha</div><div class="fs-6 fw-bold"><?= p3dcEsc(p3dcFormatDate($summary['ultima_fecha'])) ?></div></div></div>
    </div>
    <div class="col-md-3">
      <div class="card card-soft summary-box h-100"><div class="card-body"><div class="small text-muted">No habilitados</div><div class="fs-3 fw-bold text-danger"><?= (int)$summary['no_habilitados'] ?></div></div></div>
    </div>
  </div>

  <div class="card card-soft mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
          <h5 class="text-primary mb-0">Listado</h5>
          <small class="text-muted">Resumen operativo de los registros Prueba 3D.</small>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>N</th>
              <th>RUT Usuario</th>
              <th>Fecha registro</th>
              <th>Resultado</th>
              <th>Puntaje final 2</th>
              <th>% EPP</th>
              <th>Tipo de poda</th>
              <th>Factibilidad</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="9" class="text-center text-muted">No hay registros para los filtros seleccionados.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <?php $query = p3dcCurrentQuery($filters, ['id' => (int)$row['id']]); ?>
                <tr <?= $selectedRow && (int)$selectedRow['id'] === (int)$row['id'] ? 'class="table-primary"' : '' ?>>
                  <td><?= (int)($row['numero_registro'] ?? 0) ?></td>
                  <td><?= p3dcEsc((string)($row['rut_usuario'] ?? '')) ?></td>
                  <td><?= p3dcEsc(p3dcFormatDate((string)($row['fecha_registro'] ?? $row['marca_temporal'] ?? ''))) ?></td>
                  <td><span class="badge text-bg-<?= p3dcBadgeClass((string)($row['resultado_habilitacion'] ?? '')) ?>"><?= p3dcEsc((string)($row['resultado_habilitacion'] ?? '')) ?></span></td>
                  <td><?= p3dcEsc((string)($row['puntuacion_final_2'] ?? '')) ?></td>
                  <td><?= p3dcEsc((string)($row['porcentaje_epp'] ?? '')) ?></td>
                  <td><?= p3dcEsc((string)($row['tipo_poda_resumen'] ?? '')) ?></td>
                  <td><?= p3dcEsc((string)($row['factibilidad_resumen'] ?? '')) ?></td>
                  <td>
                    <a class="btn btn-outline-primary btn-sm" href="?<?= p3dcEsc($query) ?>#detalle"><i class="bi bi-eye me-1"></i>Ver detalle</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card card-soft" id="detalle">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
          <h5 class="text-primary mb-0">Detalle Prueba 3D</h5>
          <small class="text-muted">Visualización tipo formulario del registro seleccionado.</small>
        </div>
        <?php if ($selectedRow): ?>
          <span class="badge text-bg-primary">ID <?= (int)$selectedRow['id'] ?></span>
        <?php endif; ?>
      </div>

      <?php if (!$selectedRow): ?>
        <div class="text-muted">Seleccione un registro en la tabla para ver su detalle aquí mismo.</div>
      <?php else: ?>
        <section class="mb-4">
          <div class="section-title mb-3">Datos Generales</div>
          <div class="row g-3">
            <?php p3dcField('N', $selectedRow['numero_registro'] ?? ''); ?>
            <?php p3dcField('RUT Usuario', $selectedRow['rut_usuario'] ?? ''); ?>
            <?php p3dcField('Marca temporal', p3dcFormatDate((string)($selectedRow['marca_temporal'] ?? ''))); ?>
            <?php p3dcField('Fecha registro', p3dcFormatDate((string)($selectedRow['fecha_registro'] ?? ''))); ?>
            <?php p3dcField('Archivo', $selectedRow['nombre_archivo'] ?? ''); ?>
            <?php p3dcField('Fecha carga', p3dcFormatDate((string)($selectedRow['fecha_carga'] ?? ''))); ?>
            <?php p3dcField('Resultado', $selectedRow['resultado_habilitacion'] ?? ''); ?>
            <?php p3dcField('Puntuación final 2', $selectedRow['puntuacion_final_2'] ?? ''); ?>
          </div>
        </section>

        <section class="mb-4">
          <div class="section-title mb-3">EPP</div>
          <div class="row g-3">
            <?php p3dcField('Intentos EPP', $selectedRow['intentos_epp'] ?? ''); ?>
            <?php p3dcField('Elementos correctos', $selectedRow['elementos_correctos'] ?? ''); ?>
            <?php p3dcField('Intentos EPP2', $selectedRow['intentos_epp_2'] ?? ''); ?>
            <?php p3dcField('% EPP', $selectedRow['porcentaje_epp'] ?? ''); ?>
            <?php p3dcField('Elementos correctos EPP', $selectedRow['elementos_correctos_epp'] ?? ''); ?>
            <?php p3dcField('% correctos EPP', $selectedRow['porcentaje_correctos_epp'] ?? ''); ?>
            <?php p3dcField('Puntaje EPP', $selectedRow['puntaje_epp'] ?? ''); ?>
            <?php p3dcField('Feedback EPP', $selectedRow['feedback_epp'] ?? ''); ?>
          </div>
        </section>

        <section class="mb-4">
          <div class="section-title mb-3">Árbol 1</div>
          <div class="row g-3">
            <?php p3dcField('Tipo poda', $selectedRow['arb01_tipo_poda'] ?? ''); ?>
            <?php p3dcField('Área poda', $selectedRow['arb01_area_poda'] ?? ''); ?>
            <?php p3dcField('Factibilidad', $selectedRow['arb01_factibilidad'] ?? ''); ?>
            <?php p3dcField('Zona segura', $selectedRow['arb01_zona_segura'] ?? ''); ?>
            <?php p3dcField('Cantidad cortes', $selectedRow['arb01_cantidad_cortes'] ?? ''); ?>
            <?php p3dcField('Distancia al collar (mm)', $selectedRow['arb01_distancia_collar_mm'] ?? ''); ?>
            <?php p3dcField('Ángulo respecto al collar', $selectedRow['arb01_angulo_collar'] ?? ''); ?>
          </div>
        </section>

        <section class="mb-4">
          <div class="section-title mb-3">Árbol 2</div>
          <div class="row g-3">
            <?php p3dcField('Tipo poda', $selectedRow['arb02_tipo_poda'] ?? ''); ?>
            <?php p3dcField('Área poda', $selectedRow['arb02_area_poda'] ?? ''); ?>
            <?php p3dcField('Factibilidad', $selectedRow['arb02_factibilidad'] ?? ''); ?>
            <?php p3dcField('Zona segura', $selectedRow['arb02_zona_segura'] ?? ''); ?>
            <?php p3dcField('Cantidad cortes', $selectedRow['arb02_cantidad_cortes'] ?? ''); ?>
            <?php p3dcField('Distancia al collar (mm)', $selectedRow['arb02_distancia_collar_mm'] ?? ''); ?>
            <?php p3dcField('Ángulo respecto al collar', $selectedRow['arb02_angulo_collar'] ?? ''); ?>
          </div>
        </section>

        <section class="mb-4">
          <div class="section-title mb-3">Árbol 3 y Resumen</div>
          <div class="row g-3">
            <?php p3dcField('Árbol 3 Tipo poda', $selectedRow['arb03_tipo_poda'] ?? ''); ?>
            <?php p3dcField('Árbol 3 Área poda', $selectedRow['arb03_area_poda'] ?? ''); ?>
            <?php p3dcField('Árbol 3 Factibilidad', $selectedRow['arb03_factibilidad'] ?? ''); ?>
            <?php p3dcField('Puntuación final', $selectedRow['puntuacion_final_1'] ?? ''); ?>
            <?php p3dcField('Tipo de poda resumen', $selectedRow['tipo_poda_resumen'] ?? ''); ?>
            <?php p3dcField('Área de poda resumen', $selectedRow['area_poda_resumen'] ?? ''); ?>
            <?php p3dcField('Factibilidad resumen', $selectedRow['factibilidad_resumen'] ?? ''); ?>
            <?php p3dcField('Feedback Pre Poda', $selectedRow['feedback_pre_poda'] ?? ''); ?>
            <?php p3dcField('Zona segura de corte', $selectedRow['zona_segura_corte_resumen'] ?? ''); ?>
            <?php p3dcField('Cantidad y orden de cortes', $selectedRow['cantidad_orden_cortes_resumen'] ?? ''); ?>
            <?php p3dcField('Cercanía al collar', $selectedRow['cercania_collar_resumen'] ?? ''); ?>
            <?php p3dcField('Ángulo de corte', $selectedRow['angulo_corte_resumen'] ?? ''); ?>
            <?php p3dcField('Feedback Poda', $selectedRow['feedback_poda'] ?? ''); ?>
            <?php p3dcField('Columna auxiliar 1', $selectedRow['columna_aux_1'] ?? ''); ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
