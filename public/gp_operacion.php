<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/gp_workflow.php';

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN', 'OPERACION']);
$auth = gpAuth();
$msg = '';
$error = '';
$operationFilters = [];
if (!gpIsAdmin()) {
    $operationFilters['id_operador_asignado'] = (int)($auth['id'] ?? 0);
}

function gpOperacionAreaCatalog(PDO $pdo): array
{
    $catalog = [];

    $rowsFormacion = $pdo->query('SELECT MIN(id) AS id, id_servicio, descripcion FROM ceo_areacompetencia_formacion GROUP BY id_servicio, descripcion ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsFormacion as $row) {
        $catalog['FORMACION'][(int)$row['id_servicio']][] = [
            'id' => (int)$row['id'],
            'descripcion' => (string)$row['descripcion'],
        ];
    }

    $rowsHabilitacion = $pdo->query('SELECT id, id_servicio, descripcion FROM ceo_areacompetencias ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsHabilitacion as $row) {
        $catalog['HABILITACION'][(int)$row['id_servicio']][] = [
            'id' => (int)$row['id'],
            'descripcion' => (string)$row['descripcion'],
        ];
    }

    return $catalog;
}

function gpOperacionBuildMessage(string $action, int $moved): string
{
    if ($action === 'aprobar') {
        return $moved === 1 ? 'Pregunta visada por Operacion.' : $moved . ' preguntas visadas por Operacion.';
    }
    return $moved === 1 ? 'Pregunta observada.' : $moved . ' preguntas observadas.';
}

$selectedAgrupacion = (int)($_GET['id_agrupacion'] ?? $_POST['id_agrupacion_filtro'] ?? 0);
$selectedBucket = trim((string)($_GET['bucket'] ?? $_POST['bucket_filtro'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        try {
            $action = trim((string)($_POST['accion'] ?? ''));
            $comment = trim((string)($_POST['comentario'] ?? ''));
            $questionIds = [];

            if ($action === 'guardar_cambios') {
                $id = (int)($_POST['id_pregunta'] ?? 0);
                $pregunta = trim((string)($_POST['pregunta'] ?? ''));
                $idArea = (int)($_POST['id_area'] ?? 0);
                $alts = $_POST['alternativas'] ?? [];
                $correcta = (int)($_POST['correcta'] ?? 0);
                if ($id <= 0 || $pregunta === '' || !is_array($alts) || count($alts) < 2) {
                    throw new RuntimeException('Datos incompletos para guardar la pregunta.');
                }

                $params = [':id' => $id];
                $sqlEstado = 'SELECT estado, id_operador_asignado, destino, id_servicio FROM ceo_gp_preguntas WHERE id = :id LIMIT 1';
                $stmt = $pdo->prepare($sqlEstado);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $estado = (string)($row['estado'] ?? '');
                $idOperadorAsignado = (int)($row['id_operador_asignado'] ?? 0);
                $destinoPregunta = (string)($row['destino'] ?? '');
                $idServicioPregunta = (int)($row['id_servicio'] ?? 0);
                if ($estado !== 'OPERACION') {
                    throw new RuntimeException('Solo se pueden editar preguntas en Operacion.');
                }
                if (!gpIsAdmin() && $idOperadorAsignado > 0 && $idOperadorAsignado !== (int)($auth['id'] ?? 0)) {
                    throw new RuntimeException('No puedes editar una pregunta no asignada a tu usuario.');
                }

                if ($idArea <= 0) {
                    throw new RuntimeException('Debes seleccionar un área de competencia.');
                }
                $sqlArea = $destinoPregunta === 'FORMACION'
                    ? 'SELECT 1 FROM ceo_areacompetencia_formacion WHERE id = :id AND id_servicio = :id_servicio LIMIT 1'
                    : 'SELECT 1 FROM ceo_areacompetencias WHERE id = :id AND id_servicio = :id_servicio LIMIT 1';
                $stmtArea = $pdo->prepare($sqlArea);
                $stmtArea->execute([
                    ':id' => $idArea,
                    ':id_servicio' => $idServicioPregunta,
                ]);
                if (!$stmtArea->fetchColumn()) {
                    throw new RuntimeException('El área de competencia seleccionada no corresponde al servicio de la pregunta.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE ceo_gp_preguntas SET pregunta = :pregunta, id_area = :id_area, estado = "OPERACION", actualizado_por = :u, fecha_actualizacion = NOW() WHERE id = :id')->execute([
                    ':pregunta' => $pregunta,
                    ':id_area' => $idArea,
                    ':u' => (int)($auth['id'] ?? 0),
                    ':id' => $id,
                ]);
                foreach ($alts as $idAlt => $texto) {
                    $idAlt = (int)$idAlt;
                    $texto = trim((string)$texto);
                    if ($idAlt <= 0 || $texto === '') {
                        continue;
                    }
                    $pdo->prepare('UPDATE ceo_gp_alternativas SET alternativa = :alt, correcta = :correcta WHERE id = :id AND id_pregunta = :id_pregunta')->execute([
                        ':alt' => $texto,
                        ':correcta' => $idAlt === $correcta ? 'S' : 'N',
                        ':id' => $idAlt,
                        ':id_pregunta' => $id,
                    ]);
                }
                gpAddRevisionLog($pdo, $id, $estado, 'OPERACION', $comment !== '' ? $comment : 'Correccion aplicada en Operacion', (int)($auth['id'] ?? 0));
                $pdo->commit();
                $msg = 'Cambios guardados y pregunta mantenida en OPERACION.';
            } elseif ($action === 'aprobar' || $action === 'observar') {
                $questionIds = [(int)($_POST['id_pregunta'] ?? 0)];
            } elseif ($action === 'aprobar_seleccionadas' || $action === 'observar_seleccionadas') {
                $questionIds = $_POST['ids'] ?? [];
            } elseif ($action === 'aprobar_todo' || $action === 'observar_todo') {
                if ($selectedBucket === '') {
                    throw new RuntimeException('Debes seleccionar una carga antes de aplicar una accion por lote.');
                }
                $bucketFilters = gpWorkflowBucketFiltersFromToken($selectedBucket);
                if ($selectedAgrupacion > 0) {
                    $bucketFilters['id_agrupacion'] = $selectedAgrupacion;
                }
                $bucketFilters = array_merge($bucketFilters, $operationFilters);
                $questionIds = gpWorkflowQuestionIds($pdo, ['OPERACION'], $bucketFilters);
            } else {
                throw new RuntimeException('Accion invalida.');
            }

            if (str_contains($action, 'observar') && $comment === '') {
                throw new RuntimeException('El comentario es obligatorio para observar.');
            }

                $result = gpWorkflowTransitionQuestions(
                    $pdo,
                    $questionIds,
                    ['OPERACION'],
                    str_contains($action, 'aprobar') ? 'APROBADA_OPERACION' : 'OBSERVADA',
                    $comment,
                    (int)($auth['id'] ?? 0),
                    false
                );

            if ((int)$result['moved'] <= 0) {
                throw new RuntimeException(!empty($result['warnings']) ? implode(' ', $result['warnings']) : 'No se pudieron mover preguntas.');
            }

            $msg = gpOperacionBuildMessage(str_contains($action, 'aprobar') ? 'aprobar' : 'observar', (int)$result['moved']);
            if (!empty($result['warnings'])) {
                $msg .= ' Advertencias: ' . implode(' | ', $result['warnings']);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$areaCatalog = gpOperacionAreaCatalog($pdo);

$buckets = gpFetchWorkflowBuckets($pdo, ['OPERACION'], $operationFilters);
$agrupaciones = [];
foreach ($buckets as $bucket) {
    $idAgrupacion = (int)($bucket['id_agrupacion'] ?? 0);
    $optionId = $idAgrupacion > 0 ? $idAgrupacion : -1;
    $agrupaciones[$optionId] = [
        'id' => $optionId,
        'nombre' => $idAgrupacion > 0 ? (string)($bucket['agrupacion'] ?? 'Sin agrupacion') : 'Sin agrupacion asignada',
        'destino' => (string)($bucket['destino'] ?? ''),
        'servicio' => (string)($bucket['servicio'] ?? ''),
    ];
}
uasort($agrupaciones, static function (array $a, array $b): int {
    if ((int)$a['id'] < 0) {
        return -1;
    }
    if ((int)$b['id'] < 0) {
        return 1;
    }
    return strcmp($a['nombre'], $b['nombre']);
});

$filteredBuckets = array_values(array_filter($buckets, static function (array $bucket) use ($selectedAgrupacion): bool {
    if ($selectedAgrupacion === 0) {
        return false;
    }
    if ($selectedAgrupacion < 0) {
        return (int)($bucket['id_agrupacion'] ?? 0) === 0;
    }
    return (int)($bucket['id_agrupacion'] ?? 0) === $selectedAgrupacion;
}));

if ($selectedBucket !== '') {
    $bucketExists = false;
    foreach ($filteredBuckets as $bucket) {
        if (($bucket['bucket_token'] ?? '') === $selectedBucket) {
            $bucketExists = true;
            break;
        }
    }
    if (!$bucketExists) {
        $selectedBucket = '';
    }
}

$questions = [];
$selectedBucketRow = null;
if ($selectedBucket !== '') {
    foreach ($filteredBuckets as $bucket) {
        if (($bucket['bucket_token'] ?? '') === $selectedBucket) {
            $selectedBucketRow = $bucket;
            break;
        }
    }
    if ($selectedBucketRow) {
        $filters = gpWorkflowBucketFiltersFromToken($selectedBucket);
        $filters['id_agrupacion'] = (int)$selectedBucketRow['id_agrupacion'];
        $filters = array_merge($filters, $operationFilters);
        $questions = gpFetchWorkflowQuestions($pdo, ['OPERACION'], $filters);
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Operacion | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .card{border:0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
    .correct{background:#eaf8ef}
    .sticky-tools{position:sticky;top:16px;z-index:10}
    .assignment-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border-radius:999px;background:#fff7e6;color:#8a5300;font-size:.78rem;font-weight:600;border:1px solid rgba(138,83,0,.14)}
  </style>
</head>
<body>
<header class="bg-white border-bottom py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <strong>Visacion Operacion</strong>
      <div class="small text-muted">Visacion conceptual del lote completo con opcion de visar u observar</div>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_home.php" class="btn btn-outline-primary btn-sm">Inicio</a>
      <a href="gp_logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= gpEsc($msg) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= gpEsc($error) ?></div><?php endif; ?>

  <div class="card p-4 mb-4">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Agrupacion</label>
        <select name="id_agrupacion" class="form-select" onchange="this.form.submit()">
          <option value="">Selecciona una agrupacion</option>
          <?php foreach ($agrupaciones as $agr): ?>
            <option value="<?= (int)$agr['id'] ?>" <?= $selectedAgrupacion === (int)$agr['id'] ? 'selected' : '' ?>>
              <?= gpEsc($agr['nombre']) ?> | <?= gpEsc($agr['servicio']) ?> | <?= gpEsc($agr['destino']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Carga o fuente</label>
        <select name="bucket" class="form-select" <?= $selectedAgrupacion !== 0 ? '' : 'disabled' ?> onchange="this.form.submit()">
          <option value="">Selecciona una carga</option>
          <?php foreach ($filteredBuckets as $bucket): ?>
            <option value="<?= gpEsc($bucket['bucket_token']) ?>" <?= $selectedBucket === (string)$bucket['bucket_token'] ? 'selected' : '' ?>>
              <?= gpEsc($bucket['bucket_label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>

  <?php if ($selectedBucketRow): ?>
    <div class="card p-4 mb-4 sticky-tools">
      <div class="d-flex justify-content-between gap-3 flex-wrap">
        <div>
          <div class="fw-semibold"><?= gpEsc($selectedBucketRow['agrupacion'] ?? '') ?></div>
          <div class="small text-muted"><?= gpEsc($selectedBucketRow['servicio'] ?? '') ?> | <?= gpEsc($selectedBucketRow['fuente'] ?? '') ?></div>
          <div class="small text-muted mt-1">Pendientes en Operacion: <?= (int)count($questions) ?></div>
          <?php if (trim((string)(($selectedBucketRow['operador_nombres'] ?? '') . ' ' . ($selectedBucketRow['operador_apellidos'] ?? ''))) !== ''): ?>
            <div class="mt-2"><span class="assignment-chip">Asignada a: <?= gpEsc(trim((string)(($selectedBucketRow['operador_nombres'] ?? '') . ' ' . ($selectedBucketRow['operador_apellidos'] ?? '')))) ?><?php if (!empty($selectedBucketRow['fecha_asignacion_operacion'])): ?> | <?= gpEsc((string)$selectedBucketRow['fecha_asignacion_operacion']) ?><?php endif; ?></span></div>
          <?php endif; ?>
        </div>
        <div class="text-end small text-muted">
          <?= gpEsc((string)$selectedBucketRow['bucket_label']) ?>
        </div>
      </div>
      <form method="post" id="bulk-form" class="mt-3">
        <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
        <input type="hidden" name="id_agrupacion_filtro" value="<?= (int)$selectedAgrupacion ?>">
        <input type="hidden" name="bucket_filtro" value="<?= gpEsc($selectedBucket) ?>">
        <label class="form-label small">Comentario</label>
        <textarea name="comentario" class="form-control mb-3" rows="2" placeholder="Obligatorio solo para observar"></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success btn-sm" name="accion" value="aprobar_seleccionadas">Visar seleccionadas</button>
          <button class="btn btn-outline-success btn-sm" name="accion" value="aprobar_todo">Visar todo el lote</button>
          <button class="btn btn-outline-danger btn-sm" name="accion" value="observar_seleccionadas">Observar seleccionadas</button>
          <button class="btn btn-danger btn-sm" name="accion" value="observar_todo">Observar todo el lote</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!$agrupaciones): ?>
    <div class="card p-5 text-center text-muted">No hay preguntas pendientes de Operacion.</div>
  <?php elseif ($selectedAgrupacion === 0): ?>
    <div class="card p-5 text-center text-muted">Selecciona una agrupacion para ver sus cargas pendientes de Operacion.</div>
  <?php elseif (!$filteredBuckets): ?>
    <div class="card p-5 text-center text-muted">No hay cargas pendientes para la agrupacion seleccionada.</div>
  <?php elseif ($selectedBucket === ''): ?>
    <div class="card p-5 text-center text-muted">Selecciona una carga o fuente para visar sus preguntas.</div>
  <?php elseif (!$questions): ?>
    <div class="card p-5 text-center text-muted">No hay preguntas en Operacion dentro de la carga seleccionada.</div>
  <?php endif; ?>

  <?php foreach ($questions as $q): ?>
    <div class="card p-4 mb-3" id="pregunta-<?= (int)$q['id'] ?>">
      <div class="d-flex justify-content-between gap-3 flex-wrap">
        <div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <input type="checkbox" class="form-check-input bulk-check" form="bulk-form" name="ids[]" value="<?= (int)$q['id'] ?>">
            <span class="badge text-bg-warning">OPERACION</span>
            <span class="badge text-bg-light border"><?= gpEsc($q['destino']) ?></span>
          </div>
          <div class="small text-muted mt-1"><?= gpEsc($q['servicio']) ?> | <?= gpEsc($q['agrupacion']) ?> | <?= gpEsc($q['area_competencia'] ?: 'Sin área de competencia') ?> | <?= gpEsc($q['fuente']) ?></div>
          <?php if ((int)($q['id_operador_asignado'] ?? 0) > 0): ?>
            <div class="mt-2"><span class="assignment-chip">Asignada a: <?= gpEsc(trim((string)(($q['operador_nombres'] ?? '') . ' ' . ($q['operador_apellidos'] ?? '')))) ?></span></div>
          <?php endif; ?>
        </div>
        <div class="small text-muted">#<?= (int)$q['id'] ?></div>
      </div>
      <hr>
      <?php if ($q['logs']): ?>
        <details class="mt-3">
          <summary class="small text-muted">Comentarios anteriores</summary>
          <?php foreach ($q['logs'] as $log): ?>
            <div class="small border-top py-2"><strong><?= gpEsc($log['estado_hasta']) ?></strong> <?= gpEsc($log['fecha_creacion']) ?> <?= gpEsc($log['usuario'] ?? '') ?><br><?= gpEsc($log['comentario'] ?? '') ?></div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <form method="post" class="mt-3" action="?id_agrupacion=<?= (int)$selectedAgrupacion ?>&bucket=<?= urlencode((string)$selectedBucket) ?>#pregunta-<?= (int)$q['id'] ?>">
        <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
        <input type="hidden" name="id_pregunta" value="<?= (int)$q['id'] ?>">
        <input type="hidden" name="id_agrupacion_filtro" value="<?= (int)$selectedAgrupacion ?>">
        <input type="hidden" name="bucket_filtro" value="<?= gpEsc($selectedBucket) ?>">
        <label class="form-label">Pregunta</label>
        <textarea name="pregunta" class="form-control mb-3" rows="3" required><?= gpEsc($q['pregunta']) ?></textarea>
        <?php $areaOptions = $areaCatalog[(string)($q['destino'] ?? '')][(int)($q['id_servicio'] ?? 0)] ?? []; ?>
        <label class="form-label">Área de competencia</label>
        <select name="id_area" class="form-select mb-3" required>
          <option value="">Selecciona un área</option>
          <?php foreach ($areaOptions as $areaOption): ?>
            <option value="<?= (int)$areaOption['id'] ?>" <?= (int)($q['id_area'] ?? 0) === (int)$areaOption['id'] ? 'selected' : '' ?>><?= gpEsc((string)$areaOption['descripcion']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php foreach ($q['alternativas'] as $alt): ?>
          <div class="input-group mb-2 <?= $alt['correcta'] === 'S' ? 'correct rounded' : '' ?>">
            <div class="input-group-text"><input type="radio" name="correcta" value="<?= (int)$alt['id'] ?>" <?= $alt['correcta'] === 'S' ? 'checked' : '' ?>></div>
            <input type="text" name="alternativas[<?= (int)$alt['id'] ?>]" class="form-control" value="<?= gpEsc($alt['alternativa']) ?>" required>
          </div>
        <?php endforeach; ?>
        <label class="form-label small">Comentario</label>
        <textarea name="comentario" class="form-control mb-2" rows="2"></textarea>
        <div class="text-end d-flex gap-2 justify-content-end flex-wrap">
          <button class="btn btn-outline-primary btn-sm" name="accion" value="guardar_cambios">Guardar cambios</button>
          <button class="btn btn-outline-danger btn-sm" name="accion" value="observar">Observar</button>
          <button class="btn btn-success btn-sm" name="accion" value="aprobar">Visar</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</main>

<script>
document.querySelectorAll('.bulk-check').forEach(function (checkbox) {
  checkbox.addEventListener('change', function () {
    const anyChecked = Array.from(document.querySelectorAll('.bulk-check')).some(function (item) { return item.checked; });
    const bulkForm = document.getElementById('bulk-form');
    if (bulkForm) {
      bulkForm.classList.toggle('border', anyChecked);
    }
  });
});
</script>
</body>
</html>
