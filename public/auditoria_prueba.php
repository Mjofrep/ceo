<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

$pdo = db();

$filtros = [
    'flujo' => trim((string)($_GET['flujo'] ?? '')),
    'evaluador' => trim((string)($_GET['evaluador'] ?? '')),
    'rut' => trim((string)($_GET['rut'] ?? '')),
    'evento' => trim((string)($_GET['evento'] ?? '')),
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
];

$eventosDisponibles = [
    'ACCESO_PLATAFORMA_EVALUADOR',
    'ACCESO_PLATAFORMA_FORMACION_EVALUADOR',
    'EVALUADO_CARGADO',
    'FORMACION_EVALUADO_CARGADO',
    'PRUEBA_ABIERTA',
    'PRUEBA_INICIADA_EFECTIVA',
    'PRUEBA_FINALIZADA',
    'FORMACION_PRUEBA_ACTIVADA',
];

$flujosDisponibles = [
    '' => 'Todos',
    'habilitacion' => 'Habilitación',
    'formacion' => 'Formación',
];

$eventosPorFlujo = [
    'habilitacion' => [
        'ACCESO_PLATAFORMA_EVALUADOR',
        'EVALUADO_CARGADO',
        'PRUEBA_ABIERTA',
        'PRUEBA_INICIADA_EFECTIVA',
        'PRUEBA_FINALIZADA',
    ],
    'formacion' => [
        'ACCESO_PLATAFORMA_FORMACION_EVALUADOR',
        'FORMACION_EVALUADO_CARGADO',
        'FORMACION_PRUEBA_ACTIVADA',
    ],
];

$errores = [];
$rows = [];
$resumen = [
    'total' => 0,
    'evaluadores' => 0,
    'evaluados' => 0,
    'servicios' => 0,
];

function audPruebaTablaExiste(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'ceo_auditoria_prueba'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function audPruebaNormalizarRut(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

function audPruebaFecha(?string $valor): string
{
    $texto = trim((string)$valor);
    if ($texto === '' || str_starts_with($texto, '0000-00-00')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($texto))->format('d-m-Y H:i:s');
    } catch (Throwable $e) {
        return $texto;
    }
}

function audPruebaBadgeClass(string $evento): string
{
    return match ($evento) {
        'ACCESO_PLATAFORMA_EVALUADOR' => 'bg-primary-subtle text-primary-emphasis',
        'EVALUADO_CARGADO' => 'bg-info-subtle text-info-emphasis',
        'PRUEBA_ABIERTA' => 'bg-warning-subtle text-warning-emphasis',
        'PRUEBA_INICIADA_EFECTIVA' => 'bg-secondary-subtle text-secondary-emphasis',
        'PRUEBA_FINALIZADA' => 'bg-success-subtle text-success-emphasis',
        default => 'bg-light text-dark',
    };
}

function audPruebaDetalleTexto(mixed $detalle): string
{
    if ($detalle === null) {
        return '';
    }

    if (is_array($detalle)) {
        $json = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $json !== false ? $json : '';
    }

    $texto = trim((string)$detalle);
    if ($texto === '' || $texto === '{}') {
        return '';
    }

    $decoded = json_decode($texto, true);
    if (is_array($decoded)) {
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $json !== false ? $json : $texto;
    }

    return $texto;
}

if (!audPruebaTablaExiste($pdo)) {
    $errores[] = 'La tabla ceo_auditoria_prueba no existe aun en esta base de datos.';
} else {
    $sql = "
        SELECT
            ap.id,
            ap.created_at,
            ap.evento,
            ap.usuario_id,
            ap.usuario_codigo,
            ap.usuario_nombre,
            ap.usuario_rol,
            ap.rut_evaluado,
            ap.id_servicio,
            ap.servicio,
            ap.id_programada,
            ap.id_agrupacion,
            ap.cuadrilla,
            ap.id_proceso_habilitacion,
            ap.intento,
            ap.ip,
            ap.metodo,
            ap.url,
            ap.detalle
        FROM ceo_auditoria_prueba ap
        WHERE 1 = 1
    ";

    $params = [];

    if ($filtros['evaluador'] !== '') {
        $sql .= " AND (ap.usuario_nombre LIKE :evaluador OR ap.usuario_codigo LIKE :evaluador)";
        $params[':evaluador'] = '%' . $filtros['evaluador'] . '%';
    }

    if ($filtros['flujo'] !== '' && isset($eventosPorFlujo[$filtros['flujo']])) {
        $eventosFlujo = $eventosPorFlujo[$filtros['flujo']];
        if (!empty($eventosFlujo)) {
            $placeholdersEventos = [];
            foreach ($eventosFlujo as $idx => $eventoFlujo) {
                $placeholder = ':flujo_evento_' . $idx;
                $placeholdersEventos[] = $placeholder;
                $params[$placeholder] = $eventoFlujo;
            }
            $sql .= ' AND ap.evento IN (' . implode(', ', $placeholdersEventos) . ')';
        }
    }

    if ($filtros['rut'] !== '') {
        $sql .= " AND REPLACE(REPLACE(REPLACE(UPPER(ap.rut_evaluado), '.', ''), '-', ''), ' ', '') = :rut";
        $params[':rut'] = audPruebaNormalizarRut($filtros['rut']);
    }

    if ($filtros['evento'] !== '' && in_array($filtros['evento'], $eventosDisponibles, true)) {
        $sql .= " AND ap.evento = :evento";
        $params[':evento'] = $filtros['evento'];
    }

    if ($filtros['fecha_desde'] !== '') {
        $sql .= " AND ap.created_at >= :fecha_desde";
        $params[':fecha_desde'] = $filtros['fecha_desde'] . ' 00:00:00';
    }

    if ($filtros['fecha_hasta'] !== '') {
        $sql .= " AND ap.created_at <= :fecha_hasta";
        $params[':fecha_hasta'] = $filtros['fecha_hasta'] . ' 23:59:59';
    }

    $sql .= ' ORDER BY ap.created_at DESC, ap.id DESC LIMIT 500';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $evaluadores = [];
        $evaluados = [];
        $servicios = [];
        foreach ($rows as $row) {
            $evaluadorKey = trim((string)($row['usuario_codigo'] ?? '')) . '|' . trim((string)($row['usuario_nombre'] ?? ''));
            if ($evaluadorKey !== '|') {
                $evaluadores[$evaluadorKey] = true;
            }

            $rut = trim((string)($row['rut_evaluado'] ?? ''));
            if ($rut !== '') {
                $evaluados[$rut] = true;
            }

            $servicioKey = (string)($row['id_servicio'] ?? '') . '|' . trim((string)($row['servicio'] ?? ''));
            if ($servicioKey !== '|') {
                $servicios[$servicioKey] = true;
            }
        }

        $resumen['total'] = count($rows);
        $resumen['evaluadores'] = count($evaluadores);
        $resumen['evaluados'] = count($evaluados);
        $resumen['servicios'] = count($servicios);
    } catch (Throwable $e) {
        $errores[] = 'No fue posible consultar la auditoria de pruebas.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Auditoria de Pruebas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
    background: radial-gradient(circle at top right, rgba(37, 99, 235, 0.10), transparent 30%), linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
    color: #0f172a;
}
.topbar {
    background: rgba(255,255,255,0.88);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}
.shell {
    max-width: 1440px;
}
.card-soft {
    background: rgba(255,255,255,0.92);
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 22px;
    box-shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
}
.summary-card {
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid rgba(37, 99, 235, 0.10);
    padding: 1rem 1.1rem;
    height: 100%;
}
.summary-label {
    font-size: .82rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.summary-value {
    font-size: 1.8rem;
    font-weight: 700;
}
.table td, .table th {
    vertical-align: middle;
}
.detail-box {
    max-width: 420px;
    max-height: 180px;
    overflow: auto;
    white-space: pre-wrap;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 12px;
    padding: .75rem;
    font-size: .78rem;
}
.muted-mini {
    font-size: .82rem;
    color: #64748b;
}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="shell container-fluid d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= esc(APP_LOGO) ?>" alt="Logo" style="height:60px;">
      <div>
        <div class="h4 mb-0"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="<?= esc(app_url('/public/general.php')) ?>" class="btn btn-outline-primary btn-sm">Volver</a>
  </div>
</header>

<main class="shell container-fluid pb-4">
  <section class="card-soft p-4 mb-4">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
      <div>
        <h1 class="h4 mb-1">Auditoria de Pruebas Teoricas</h1>
        <p class="text-secondary mb-0">Consulta ordenada del acceso a plataforma, carga de evaluado, apertura, inicio efectivo y finalizacion de pruebas.</p>
      </div>
      <div class="muted-mini">Se muestran hasta 500 registros por consulta.</div>
    </div>

    <?php if (!empty($errores)): ?>
      <div class="alert alert-danger mb-3">
        <?php foreach ($errores as $error): ?>
          <div><?= esc($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" class="row g-3">
      <div class="col-md-2">
        <label class="form-label">Flujo</label>
        <select name="flujo" class="form-select">
          <?php foreach ($flujosDisponibles as $valorFlujo => $etiquetaFlujo): ?>
            <option value="<?= esc($valorFlujo) ?>" <?= $filtros['flujo'] === $valorFlujo ? 'selected' : '' ?>><?= esc($etiquetaFlujo) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Evaluador</label>
        <input type="text" name="evaluador" class="form-control" value="<?= esc($filtros['evaluador']) ?>" placeholder="Nombre o codigo">
      </div>
      <div class="col-md-2">
        <label class="form-label">RUT evaluado</label>
        <input type="text" name="rut" class="form-control" value="<?= esc($filtros['rut']) ?>" placeholder="12.345.678-9">
      </div>
      <div class="col-md-3">
        <label class="form-label">Evento</label>
        <select name="evento" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($eventosDisponibles as $evento): ?>
            <option value="<?= esc($evento) ?>" <?= $filtros['evento'] === $evento ? 'selected' : '' ?>><?= esc($evento) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Fecha desde</label>
        <input type="date" name="fecha_desde" class="form-control" value="<?= esc($filtros['fecha_desde']) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Fecha hasta</label>
        <input type="date" name="fecha_hasta" class="form-control" value="<?= esc($filtros['fecha_hasta']) ?>">
      </div>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Buscar</button>
        <a href="auditoria_prueba.php" class="btn btn-outline-secondary">Limpiar</a>
      </div>
    </form>
  </section>

  <section class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="summary-label">Eventos</div>
        <div class="summary-value"><?= esc((string)$resumen['total']) ?></div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="summary-label">Evaluadores</div>
        <div class="summary-value"><?= esc((string)$resumen['evaluadores']) ?></div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="summary-label">RUT evaluados</div>
        <div class="summary-value"><?= esc((string)$resumen['evaluados']) ?></div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="summary-label">Servicios</div>
        <div class="summary-value"><?= esc((string)$resumen['servicios']) ?></div>
      </div>
    </div>
  </section>

  <section class="card-soft p-3 p-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-3 flex-wrap">
      <h2 class="h5 mb-0">Resultados</h2>
      <div class="muted-mini">Ordenados desde el evento mas reciente.</div>
    </div>

    <?php if (empty($rows) && empty($errores)): ?>
      <div class="alert alert-info mb-0">No se encontraron registros con los filtros indicados.</div>
    <?php elseif (!empty($rows)): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Fecha</th>
              <th>Evaluador</th>
              <th>RUT evaluado</th>
              <th>Servicio</th>
              <th>Evento</th>
              <th>Proceso</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <div><?= esc(audPruebaFecha((string)($row['created_at'] ?? ''))) ?></div>
                  <div class="muted-mini">ID <?= esc((string)($row['id'] ?? '')) ?></div>
                </td>
                <td>
                  <div class="fw-semibold"><?= esc((string)($row['usuario_nombre'] ?? '')) ?></div>
                  <div class="muted-mini">
                    <?= esc((string)($row['usuario_codigo'] ?? '')) ?>
                    <?php if (trim((string)($row['usuario_rol'] ?? '')) !== ''): ?>
                      | <?= esc((string)($row['usuario_rol'] ?? '')) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="fw-semibold"><?= esc((string)($row['rut_evaluado'] ?? '')) ?></div>
                  <div class="muted-mini">IP <?= esc((string)($row['ip'] ?? '')) ?></div>
                </td>
                <td>
                  <div><?= esc((string)($row['servicio'] !== '' ? $row['servicio'] : ('Servicio #' . (string)($row['id_servicio'] ?? '')))) ?></div>
                  <div class="muted-mini">
                    Serv. <?= esc((string)($row['id_servicio'] ?? '')) ?>
                    <?php if ((int)($row['id_agrupacion'] ?? 0) > 0): ?>
                      | Agrup. <?= esc((string)($row['id_agrupacion'] ?? '')) ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <span class="badge rounded-pill <?= esc(audPruebaBadgeClass((string)($row['evento'] ?? ''))) ?>">
                    <?= esc((string)($row['evento'] ?? '')) ?>
                  </span>
                </td>
                <td>
                  <div>Prog. <?= esc((string)($row['id_programada'] ?? '')) ?></div>
                  <div class="muted-mini">Proc. hab. <?= esc((string)($row['id_proceso_habilitacion'] ?? '')) ?></div>
                  <div class="muted-mini">Cuadrilla <?= esc((string)($row['cuadrilla'] ?? '')) ?> | Intento <?= esc((string)($row['intento'] ?? '')) ?></div>
                </td>
                <td>
                  <?php $detalleTexto = audPruebaDetalleTexto($row['detalle'] ?? ''); ?>
                  <?php if ($detalleTexto !== ''): ?>
                    <div class="detail-box"><?= esc($detalleTexto) ?></div>
                  <?php else: ?>
                    <span class="text-secondary">Sin detalle</span>
                  <?php endif; ?>
                  <div class="muted-mini mt-1"><?= esc((string)($row['metodo'] ?? '')) ?> | <?= esc((string)($row['url'] ?? '')) ?></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

</body>
</html>
