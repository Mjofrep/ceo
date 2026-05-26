<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/gp_workflow.php';

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN', 'REVISOR']);
$auth = gpAuth();
$msg = '';
$error = '';

function gpRevisionBuildMessage(string $action, int $moved): string
{
    if ($action === 'enviar_operacion') {
        return $moved === 1 ? 'Pregunta enviada o reenviada a Operacion.' : $moved . ' preguntas enviadas o reenviadas a Operacion.';
    }
    if ($action === 'publicar') {
        return $moved === 1 ? 'Pregunta publicada.' : $moved . ' preguntas publicadas.';
    }
    return $moved === 1 ? 'Pregunta observada.' : $moved . ' preguntas observadas.';
}

function gpRevisionSelectionStateSummary(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return ['revision' => 0, 'observada' => 0, 'visada' => 0, 'other' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM ceo_gp_preguntas WHERE id IN ($placeholders) GROUP BY estado");
    $stmt->execute($ids);

    $summary = ['revision' => 0, 'observada' => 0, 'visada' => 0, 'other' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $state = (string)($row['estado'] ?? '');
        $total = (int)($row['total'] ?? 0);
        if ($state === 'REVISION') {
            $summary['revision'] += $total;
        } elseif ($state === 'OBSERVADA') {
            $summary['observada'] += $total;
        } elseif ($state === 'APROBADA_OPERACION') {
            $summary['visada'] += $total;
        } else {
            $summary['other'] += $total;
        }
    }

    return $summary;
}

function gpRevisionFetchOperationMailContext(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return ['servicio' => '', 'agrupacion' => '', 'fuente' => '', 'preguntas' => 0];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT
        COUNT(*) AS total,
        COALESCE(MAX(f.titulo), 'Sin fuente') AS fuente,
        MAX(CASE WHEN q.destino = 'FORMACION'
            THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = q.id_servicio LIMIT 1)
            ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = q.id_servicio LIMIT 1)
        END) AS servicio,
        MAX(CASE WHEN q.destino = 'FORMACION'
            THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = q.id_agrupacion LIMIT 1)
            ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = q.id_agrupacion LIMIT 1)
        END) AS agrupacion
      FROM ceo_gp_preguntas q
      LEFT JOIN ceo_gp_fuentes f ON f.id = q.id_fuente
      WHERE q.id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'servicio' => (string)($row['servicio'] ?? ''),
        'agrupacion' => (string)($row['agrupacion'] ?? ''),
        'fuente' => (string)($row['fuente'] ?? ''),
        'preguntas' => (int)($row['total'] ?? 0),
    ];
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

            if ($action === 'guardar_cambios') {
                $id = (int)($_POST['id_pregunta'] ?? 0);
                $pregunta = trim((string)($_POST['pregunta'] ?? ''));
                $alts = $_POST['alternativas'] ?? [];
                $correcta = (int)($_POST['correcta'] ?? 0);
                if ($id <= 0 || $pregunta === '' || !is_array($alts) || count($alts) < 2) {
                    throw new RuntimeException('Datos incompletos para guardar la pregunta.');
                }

                $stmt = $pdo->prepare('SELECT estado FROM ceo_gp_preguntas WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $estado = (string)$stmt->fetchColumn();
                if (!in_array($estado, ['REVISION', 'OBSERVADA'], true)) {
                    throw new RuntimeException('Solo se pueden editar preguntas en revision u observadas.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('UPDATE ceo_gp_preguntas SET pregunta = :pregunta, estado = "REVISION", actualizado_por = :u, fecha_actualizacion = NOW() WHERE id = :id')->execute([
                    ':pregunta' => $pregunta,
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
                gpAddRevisionLog($pdo, $id, $estado, 'REVISION', $comment !== '' ? $comment : 'Correccion aplicada en Revision', (int)($auth['id'] ?? 0));
                $pdo->commit();
                $msg = 'Cambios guardados y pregunta mantenida en REVISION.';
            } else {
                $questionIds = [];
                $operatorId = (int)($_POST['id_operador_asignado'] ?? 0);
                if ($action === 'enviar_operacion' || $action === 'observar') {
                    $questionIds = [(int)($_POST['id_pregunta'] ?? 0)];
                } elseif ($action === 'enviar_operacion_seleccionadas' || $action === 'observar_seleccionadas') {
                    $questionIds = $_POST['ids'] ?? [];
                } elseif ($action === 'enviar_operacion_todo' || $action === 'observar_todo' || $action === 'publicar_lote') {
                    if ($selectedBucket === '') {
                        throw new RuntimeException('Debes seleccionar una carga antes de aplicar una accion por lote.');
                    }
                    $bucketFilters = gpWorkflowBucketFiltersFromToken($selectedBucket);
                    if ($selectedAgrupacion > 0) {
                        $bucketFilters['id_agrupacion'] = $selectedAgrupacion;
                    }
                    if ($action === 'publicar_lote') {
                        $questionIds = gpWorkflowQuestionIds($pdo, ['APROBADA_OPERACION'], $bucketFilters);
                    } else {
                        $questionIds = gpWorkflowQuestionIds($pdo, str_contains($action, 'enviar_operacion') ? ['REVISION', 'OBSERVADA'] : ['REVISION', 'OBSERVADA', 'APROBADA_OPERACION'], $bucketFilters);
                    }
                } else {
                    throw new RuntimeException('Accion invalida.');
                }

                if (str_contains($action, 'observar') && $comment === '') {
                    throw new RuntimeException('El comentario es obligatorio para observar.');
                }
                if (str_contains($action, 'enviar_operacion') && $operatorId <= 0) {
                    throw new RuntimeException('Debes seleccionar el operador de destino.');
                }

                if ($action === 'publicar_lote') {
                    $result = gpPublishQuestions($pdo, $questionIds, (int)($auth['id'] ?? 0));
                    $msg = gpRevisionBuildMessage('publicar', (int)$result['published']);
                } else {
                    if ($action === 'enviar_operacion_seleccionadas') {
                        $summary = gpRevisionSelectionStateSummary($pdo, is_array($questionIds) ? $questionIds : [$questionIds]);
                        if ((($summary['revision'] ?? 0) + ($summary['observada'] ?? 0)) <= 0) {
                            throw new RuntimeException('Debes seleccionar al menos una pregunta en REVISION.');
                        }
                    }

                    if (str_contains($action, 'enviar_operacion')) {
                        $idsToCheck = array_values(array_unique(array_filter(array_map('intval', is_array($questionIds) ? $questionIds : [$questionIds]), static fn(int $id): bool => $id > 0)));
                        if (!$idsToCheck) {
                            throw new RuntimeException('Debes seleccionar al menos una pregunta.');
                        }
                        $placeholders = implode(',', array_fill(0, count($idsToCheck), '?'));
                        $stmtAgr = $pdo->prepare("SELECT COUNT(*) FROM ceo_gp_preguntas WHERE id IN ($placeholders) AND (id_agrupacion IS NULL OR id_agrupacion <= 0)");
                        $stmtAgr->execute($idsToCheck);
                        if ((int)$stmtAgr->fetchColumn() > 0) {
                            throw new RuntimeException('Debes asignar agrupacion antes de enviar a Operacion.');
                        }
                    }

                    if (str_contains($action, 'enviar_operacion')) {
                        $result = gpAssignQuestionsToOperation(
                            $pdo,
                            $questionIds,
                            $operatorId,
                            $comment !== '' ? $comment : 'Pregunta enviada a Operacion.',
                            (int)($auth['id'] ?? 0),
                            false
                        );
                    } else {
                        $result = gpWorkflowTransitionQuestions(
                            $pdo,
                            $questionIds,
                            ['REVISION', 'OBSERVADA', 'APROBADA_OPERACION'],
                            'OBSERVADA',
                            $comment,
                            (int)($auth['id'] ?? 0),
                            false
                        );
                    }

                    if ((int)$result['moved'] <= 0) {
                        throw new RuntimeException(!empty($result['warnings']) ? implode(' ', $result['warnings']) : 'No se pudieron mover preguntas.');
                    }

                    $msg = gpRevisionBuildMessage(str_contains($action, 'enviar_operacion') ? 'enviar_operacion' : 'observar', (int)$result['moved']);
                    if (!empty($result['warnings'])) {
                        $msg .= ' Advertencias: ' . implode(' | ', $result['warnings']);
                    }
                    $selectionSummary = gpRevisionSelectionStateSummary($pdo, is_array($questionIds) ? $questionIds : [$questionIds]);
                    if ($action === 'enviar_operacion_todo' && (int)($selectionSummary['observada'] ?? 0) > 0) {
                        $msg .= ' Se reenviaron tambien preguntas que ya estaban observadas.';
                    }
                    if (str_contains($action, 'enviar_operacion')) {
                        $mailContext = gpRevisionFetchOperationMailContext($pdo, is_array($questionIds) ? $questionIds : [$questionIds]);
                        $mailResult = gpSendOperacionAssignmentMail($pdo, $result['operator'] ?? [], $mailContext, (int)($auth['id'] ?? 0));
                        if (!empty($mailResult['warning'])) {
                            $msg .= ' ' . $mailResult['warning'];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$buckets = gpFetchWorkflowBuckets($pdo, ['REVISION', 'OBSERVADA', 'APROBADA_OPERACION']);
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
        $questions = gpFetchWorkflowQuestions($pdo, ['REVISION', 'OBSERVADA', 'APROBADA_OPERACION'], $filters);
    }
}

$availableOperators = [];
if ($selectedBucketRow) {
    $availableOperators = gpFetchOperacionUsers($pdo, (string)($selectedBucketRow['destino'] ?? ''), (int)($selectedBucketRow['id_servicio'] ?? 0));
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Revision | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .card{border:0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
    .correct{background:#eaf8ef}
    .sticky-tools{position:sticky;top:16px;z-index:10}
    .assignment-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border-radius:999px;background:#eef6ff;color:#0d47a1;font-size:.78rem;font-weight:600;border:1px solid rgba(13,71,161,.14)}
  </style>
</head>
<body>
<header class="bg-white border-bottom py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <strong>Revision de Preguntas</strong>
      <div class="small text-muted">Revisa, corrige, recibe observaciones y prepara preguntas visadas para publicacion</div>
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
          <div class="fw-semibold"><?= gpEsc((int)($selectedBucketRow['id_agrupacion'] ?? 0) > 0 ? ($selectedBucketRow['agrupacion'] ?? '') : 'Sin agrupacion asignada') ?></div>
          <div class="small text-muted"><?= gpEsc($selectedBucketRow['servicio'] ?? '') ?> | <?= gpEsc($selectedBucketRow['fuente'] ?? '') ?></div>
          <div class="small text-muted mt-1">En revision: <?= (int)($selectedBucketRow['total_revision'] ?? 0) ?> | Observadas: <?= (int)($selectedBucketRow['total_observada'] ?? 0) ?> | Visadas: <?= (int)($selectedBucketRow['total_visada'] ?? 0) ?> | Publicadas: <?= (int)($selectedBucketRow['total_publicada'] ?? 0) ?></div>
          <?php if (trim((string)(($selectedBucketRow['operador_nombres'] ?? '') . ' ' . ($selectedBucketRow['operador_apellidos'] ?? ''))) !== ''): ?>
            <div class="mt-2"><span class="assignment-chip">Operacion asignada: <?= gpEsc(trim((string)(($selectedBucketRow['operador_nombres'] ?? '') . ' ' . ($selectedBucketRow['operador_apellidos'] ?? '')))) ?></span></div>
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
        <div class="mb-3">
          <label class="form-label small">Operador de destino</label>
          <select name="id_operador_asignado" id="id_operador_asignado" class="form-select">
            <option value="">Selecciona un operador</option>
            <?php foreach ($availableOperators as $operator): ?>
              <?php $operatorName = trim((string)(($operator['nombres'] ?? '') . ' ' . ($operator['apellidos'] ?? ''))); ?>
              <option value="<?= (int)$operator['id'] ?>"><?= gpEsc($operatorName !== '' ? $operatorName : (string)($operator['usuario'] ?? '')) ?><?= !empty($operator['correo']) ? ' | ' . gpEsc((string)$operator['correo']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="form-label small">Comentario</label>
        <textarea name="comentario" class="form-control mb-3" rows="2" placeholder="Obligatorio solo para observar"></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-success btn-sm" name="accion" value="enviar_operacion_seleccionadas">Enviar seleccionadas a Operacion</button>
          <button class="btn btn-outline-success btn-sm" name="accion" value="enviar_operacion_todo">Enviar todo el lote a Operacion</button>
          <button class="btn btn-outline-danger btn-sm" name="accion" value="observar_seleccionadas">Observar seleccionadas</button>
          <button class="btn btn-danger btn-sm" name="accion" value="observar_todo">Observar todo el lote</button>
          <?php if ((int)($selectedBucketRow['total_visada'] ?? 0) > 0): ?>
            <button class="btn btn-primary btn-sm" name="accion" value="publicar_lote">Publicar lote</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <?php if (!$agrupaciones): ?>
    <div class="card p-5 text-center text-muted">No hay preguntas en revision u observadas.</div>
  <?php elseif ($selectedAgrupacion === 0): ?>
    <div class="card p-5 text-center text-muted">Selecciona una agrupacion para ver sus cargas pendientes.</div>
  <?php elseif (!$filteredBuckets): ?>
    <div class="card p-5 text-center text-muted">No hay cargas pendientes para la agrupacion seleccionada.</div>
  <?php elseif ($selectedBucket === ''): ?>
    <div class="card p-5 text-center text-muted">Selecciona una carga o fuente para revisar sus preguntas.</div>
  <?php elseif (!$questions): ?>
    <div class="card p-5 text-center text-muted">No hay preguntas dentro de la carga seleccionada.</div>
  <?php endif; ?>

  <?php foreach ($questions as $q): ?>
    <div class="card p-4 mb-3">
      <div class="d-flex justify-content-between gap-3 flex-wrap">
        <div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <input type="checkbox" class="form-check-input bulk-check" form="bulk-form" name="ids[]" value="<?= (int)$q['id'] ?>">
            <span class="badge text-bg-<?= $q['estado'] === 'OBSERVADA' ? 'danger' : ($q['estado'] === 'APROBADA_OPERACION' ? 'success' : 'secondary') ?>"><?= gpEsc($q['estado'] === 'APROBADA_OPERACION' ? 'VISADA' : $q['estado']) ?></span>
            <span class="badge text-bg-light border"><?= gpEsc($q['destino']) ?></span>
          </div>
          <div class="small text-muted mt-1"><?= gpEsc($q['servicio']) ?> | <?= gpEsc((string)$q['agrupacion'] !== '' ? $q['agrupacion'] : 'Sin agrupacion') ?> | <?= gpEsc($q['fuente']) ?></div>
          <?php if ((int)($q['id_operador_asignado'] ?? 0) > 0): ?>
            <div class="mt-2"><span class="assignment-chip">Asignada a Operacion: <?= gpEsc(trim((string)(($q['operador_nombres'] ?? '') . ' ' . ($q['operador_apellidos'] ?? '')))) ?><?php if (!empty($q['fecha_asignacion_operacion'])): ?> | <?= gpEsc((string)$q['fecha_asignacion_operacion']) ?><?php endif; ?></span></div>
          <?php endif; ?>
        </div>
        <div class="small text-muted">#<?= (int)$q['id'] ?></div>
      </div>
      <?php if ($q['logs']): ?>
        <?php
          $latestObservation = null;
          foreach ($q['logs'] as $logCandidate) {
              if (($logCandidate['estado_hasta'] ?? '') === 'OBSERVADA') {
                  $latestObservation = $logCandidate;
                  break;
              }
          }
        ?>
        <?php if (is_array($latestObservation)): ?>
          <div class="alert alert-warning py-2 mt-3 mb-0 small">
            <strong>Ultima observacion:</strong> <?= gpEsc((string)($latestObservation['comentario'] ?? 'Sin comentario')) ?><br>
            <span class="text-muted"><?= gpEsc((string)($latestObservation['fecha_creacion'] ?? '')) ?> <?= gpEsc((string)($latestObservation['usuario'] ?? '')) ?></span>
          </div>
        <?php endif; ?>
        <details class="mt-3">
          <summary class="small text-muted">Comentarios anteriores</summary>
          <?php foreach ($q['logs'] as $log): ?>
            <div class="small border-top py-2"><strong><?= gpEsc($log['estado_hasta']) ?></strong> <?= gpEsc($log['fecha_creacion']) ?> <?= gpEsc($log['usuario'] ?? '') ?><br><?= gpEsc($log['comentario'] ?? '') ?></div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <form method="post" class="mt-3">
        <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
        <input type="hidden" name="id_pregunta" value="<?= (int)$q['id'] ?>">
        <input type="hidden" name="id_agrupacion_filtro" value="<?= (int)$selectedAgrupacion ?>">
        <input type="hidden" name="bucket_filtro" value="<?= gpEsc($selectedBucket) ?>">
        <input type="hidden" name="id_operador_asignado" class="hidden-operador-asignado" value="">
        <label class="form-label">Pregunta</label>
        <textarea name="pregunta" class="form-control mb-3" rows="3" required><?= gpEsc($q['pregunta']) ?></textarea>
        <?php foreach ($q['alternativas'] as $alt): ?>
          <div class="input-group mb-2 <?= $alt['correcta'] === 'S' ? 'correct rounded' : '' ?>">
            <div class="input-group-text"><input type="radio" name="correcta" value="<?= (int)$alt['id'] ?>" <?= $alt['correcta'] === 'S' ? 'checked' : '' ?>></div>
            <input type="text" name="alternativas[<?= (int)$alt['id'] ?>]" class="form-control" value="<?= gpEsc($alt['alternativa']) ?>" required>
          </div>
        <?php endforeach; ?>
        <label class="form-label small">Comentario</label>
        <textarea name="comentario" class="form-control mb-2" rows="2"></textarea>
        <?php if ($q['estado'] === 'OBSERVADA'): ?>
          <div class="small text-muted mb-2">Puedes corregirla o reenviarla a Operacion con observaciones pendientes.</div>
        <?php endif; ?>
        <div class="text-end d-flex gap-2 justify-content-end flex-wrap">
          <button class="btn btn-outline-primary btn-sm" name="accion" value="guardar_cambios">Guardar cambios</button>
          <button class="btn btn-outline-danger btn-sm" name="accion" value="observar">Observar</button>
          <?php if ($q['estado'] === 'REVISION'): ?>
            <button class="btn btn-success btn-sm" name="accion" value="enviar_operacion">Enviar a Operacion</button>
          <?php elseif ($q['estado'] === 'OBSERVADA'): ?>
            <button class="btn btn-success btn-sm" name="accion" value="enviar_operacion">Reenviar a Operacion</button>
          <?php elseif ($q['estado'] === 'APROBADA_OPERACION'): ?>
            <span class="btn btn-success btn-sm disabled">Visada por Operacion</span>
          <?php endif; ?>
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

const operadorSelect = document.getElementById('id_operador_asignado');
function syncOperadorAsignado() {
  const value = operadorSelect ? operadorSelect.value : '';
  document.querySelectorAll('.hidden-operador-asignado').forEach(function (input) {
    input.value = value;
  });
}
if (operadorSelect) {
  operadorSelect.addEventListener('change', syncOperadorAsignado);
  syncOperadorAsignado();
}
</script>
</body>
</html>
