<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();
$msg = '';

function buildRedirectUrl(int $idAgrupacion, int $idPregunta = 0, string $msg = '', string $type = 'success'): string
{
  $params = ['id_agrupacion' => $idAgrupacion];
  if ($idPregunta > 0) {
    $params['id_pregunta'] = $idPregunta;
  }
  if ($msg !== '') {
    $params['msg'] = $msg;
    $params['type'] = $type;
  }
  return 'formacion_pruebas_teoricas_modificar.php?' . http_build_query($params);
}

function redirectBack(int $idAgrupacion, int $idPregunta = 0, string $msg = '', string $type = 'success'): void
{
  header('Location: ' . buildRedirectUrl($idAgrupacion, $idPregunta, $msg, $type));
  exit;
}

function decodeAlternativas(?string $json): array
{
  if (!$json) {
    return [];
  }
  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

$agrupaciones = $pdo->query("SELECT id, titulo FROM ceo_formacion_agrupacion ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['msg'])) {
  $type = (string)($_GET['type'] ?? 'success');
  $class = match ($type) {
    'info' => 'alert-info',
    'danger' => 'alert-danger',
    default => 'alert-success',
  };
  $msg = "<div class='alert {$class} mt-3'>" . htmlspecialchars((string)$_GET['msg']) . "</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $idSelPost = (int)($_POST['id_agrupacion'] ?? 0);

  try {
    if ($action === 'update') {
      $idPregunta = (int)($_POST['id_pregunta'] ?? 0);
      $texto = $_POST['pregunta_texto'] ?? '';
      $retroPos = $_POST['retropos'] ?? '';
      $retroNeg = $_POST['retroneg'] ?? '';
      $peso = (int)($_POST['peso_' . $idPregunta] ?? 1);
      if ($peso <= 0) {
        $peso = 1;
      }
      $tipoPregunta = (string)($_POST['tipo_pregunta_' . $idPregunta] ?? 'ALT');
      $obligatoria = isset($_POST['obligatoria_' . $idPregunta]) ? 1 : 0;
      $correctaAlt = (string)($_POST['correcta_alt'] ?? '');

      $uploadDir = __DIR__ . '/../uploads/';
      if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
      }

      $imagen = trim((string)($_POST['pregunta_imagen_actual'] ?? ''));
      if (!empty($_FILES['pregunta_imagen']['name'])) {
        $name = basename((string)$_FILES['pregunta_imagen']['name']);
        $target = $uploadDir . time() . '_' . $name;
        if (move_uploaded_file($_FILES['pregunta_imagen']['tmp_name'], $target)) {
          $imagen = 'uploads/' . basename($target);
        }
      }
      if (!empty($_POST['pregunta_video'])) {
        $imagen = trim((string)$_POST['pregunta_video']);
      }

      $texto = html_entity_decode(strip_tags((string)$texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $retroPos = html_entity_decode(strip_tags((string)$retroPos), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $retroNeg = html_entity_decode(strip_tags((string)$retroNeg), ENT_QUOTES | ENT_HTML5, 'UTF-8');

      $pdo->prepare("UPDATE ceo_formacion_preguntas_servicios
                       SET pregunta=?, imagen=?, retropos=?, retroneg=?, peso=?, tipo_pregunta=?, obligatoria=?
                     WHERE id=?")
          ->execute([$texto, $imagen, $retroPos, $retroNeg, $peso, $tipoPregunta, $obligatoria, $idPregunta]);

      foreach ($_POST as $k => $v) {
        if (preg_match('/^alt_id_(\d+)$/', (string)$k, $m)) {
          $idAlt = (int)$m[1];
          $alternativaTexto = $_POST["alt_texto_$idAlt"] ?? '';
          $alternativaTextoExtra = trim((string)($_POST["alt_textoextra_$idAlt"] ?? ''));
          $alternativa = $alternativaTexto ?: $alternativaTextoExtra;

          $imgAlt = (string)($_POST["alt_imagen_actual_$idAlt"] ?? '');
          if (!empty($_FILES["alt_imagen_$idAlt"]['name'])) {
            $name = basename((string)$_FILES["alt_imagen_$idAlt"]['name']);
            $target = $uploadDir . time() . "_alt_" . $name;
            if (move_uploaded_file($_FILES["alt_imagen_$idAlt"]['tmp_name'], $target)) {
              $imgAlt = 'uploads/' . basename($target);
            }
          }
          if (!empty($_POST["alt_video_$idAlt"])) {
            $imgAlt = trim((string)$_POST["alt_video_$idAlt"]);
          }
          $correcta = ($correctaAlt === (string)$idAlt) ? 'S' : 'N';

          $alternativa = html_entity_decode(strip_tags((string)$alternativa), ENT_QUOTES | ENT_HTML5, 'UTF-8');

          $pdo->prepare("UPDATE ceo_formacion_alternativas_preguntas
                            SET alternativa=?, correcta=?, imagen=?
                          WHERE id=?")
              ->execute([$alternativa, $correcta, $imgAlt, $idAlt]);
        }
      }

      if (!empty($_POST['nueva_alt_texto'])) {
        foreach ($_POST['nueva_alt_texto'] as $idx => $nuevoTexto) {
          $nuevoTexto = trim((string)$nuevoTexto);
          $nuevoTextoExtra = trim((string)($_POST['nueva_alt_textoextra'][$idx] ?? ''));
          $textoFinal = $nuevoTexto !== '' ? $nuevoTexto : $nuevoTextoExtra;
          $nuevoVideo = trim((string)($_POST['nueva_alt_video'][$idx] ?? ''));
          $nuevaImg = '';
          if (!empty($_FILES['nueva_alt_imagen']['name'][$idx])) {
            $name = basename((string)$_FILES['nueva_alt_imagen']['name'][$idx]);
            $target = $uploadDir . time() . "_nueva_" . $name;
            if (move_uploaded_file($_FILES['nueva_alt_imagen']['tmp_name'][$idx], $target)) {
              $nuevaImg = 'uploads/' . basename($target);
            }
          }

          if ($textoFinal === '' && $nuevoVideo === '' && $nuevaImg === '') {
            continue;
          }

          $textoFinal = html_entity_decode(strip_tags((string)$textoFinal), ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $imagenFinal = $nuevoVideo ?: $nuevaImg;

          $pdo->prepare("INSERT INTO ceo_formacion_alternativas_preguntas
                           (alternativa, correcta, estado, id_pregunta, imagen)
                         VALUES (?, 'N', 'S', ?, ?)")
              ->execute([$textoFinal, $idPregunta, $imagenFinal]);
        }
      }

      redirectBack($idSelPost, $idPregunta, "Pregunta #{$idPregunta} actualizada correctamente.");
    }

    if ($action === 'delete_pregunta') {
      $idPregunta = (int)($_POST['id_pregunta'] ?? 0);

      $stmtNext = $pdo->prepare("SELECT id FROM ceo_formacion_preguntas_servicios WHERE id_agrupacion = :id_agrupacion AND id > :id ORDER BY id ASC LIMIT 1");
      $stmtNext->execute([':id_agrupacion' => $idSelPost, ':id' => $idPregunta]);
      $nextId = (int)($stmtNext->fetchColumn() ?: 0);

      if ($nextId <= 0) {
        $stmtPrev = $pdo->prepare("SELECT id FROM ceo_formacion_preguntas_servicios WHERE id_agrupacion = :id_agrupacion AND id < :id ORDER BY id DESC LIMIT 1");
        $stmtPrev->execute([':id_agrupacion' => $idSelPost, ':id' => $idPregunta]);
        $nextId = (int)($stmtPrev->fetchColumn() ?: 0);
      }

      $pdo->prepare("DELETE FROM ceo_formacion_alternativas_preguntas WHERE id_pregunta=?")->execute([$idPregunta]);
      $pdo->prepare("DELETE FROM ceo_formacion_preguntas_servicios WHERE id=?")->execute([$idPregunta]);
      redirectBack($idSelPost, $nextId, 'Pregunta eliminada correctamente.', 'info');
    }

    if ($action === 'delete_alternativa') {
      $idAlternativa = (int)($_POST['id_alternativa'] ?? 0);
      $idPregunta = (int)($_POST['id_pregunta'] ?? 0);
      $pdo->prepare("DELETE FROM ceo_formacion_alternativas_preguntas WHERE id=?")->execute([$idAlternativa]);
      redirectBack($idSelPost, $idPregunta, 'Alternativa eliminada correctamente.', 'info');
    }
  } catch (Throwable $e) {
    $msg = "<div class='alert alert-danger mt-3'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
  }
}

$idSel = (int)($_GET['id_agrupacion'] ?? ($_POST['id_agrupacion'] ?? 0));
$idPreguntaSel = (int)($_GET['id_pregunta'] ?? 0);
$preguntasNav = [];
$preguntaActiva = null;
$alternativasActivas = [];
$indiceActual = 0;

if ($idSel > 0) {
  $stmtNav = $pdo->prepare("SELECT id FROM ceo_formacion_preguntas_servicios WHERE id_agrupacion = :id ORDER BY id ASC");
  $stmtNav->execute([':id' => $idSel]);
  $preguntasNav = $stmtNav->fetchAll(PDO::FETCH_ASSOC);

  if (!$idPreguntaSel && !empty($preguntasNav)) {
    $idPreguntaSel = (int)$preguntasNav[0]['id'];
  }

  foreach ($preguntasNav as $idx => $preguntaNav) {
    if ((int)$preguntaNav['id'] === $idPreguntaSel) {
      $indiceActual = $idx + 1;
      break;
    }
  }

  if ($idPreguntaSel > 0 && $indiceActual === 0 && !empty($preguntasNav)) {
    $idPreguntaSel = (int)$preguntasNav[0]['id'];
    $indiceActual = 1;
  }

  if ($idPreguntaSel > 0) {
    $stmtPregunta = $pdo->prepare("
      SELECT p.id, p.pregunta, p.imagen, p.retropos, p.retroneg, p.peso, p.tipo_pregunta, p.obligatoria,
             (SELECT JSON_ARRAYAGG(JSON_OBJECT(
               'id', a.id,
               'alternativa', a.alternativa,
               'correcta', a.correcta,
               'imagen', a.imagen
             )) FROM ceo_formacion_alternativas_preguntas a WHERE a.id_pregunta = p.id) AS alternativas
      FROM ceo_formacion_preguntas_servicios p
      WHERE p.id_agrupacion = :id_agrupacion
        AND p.id = :id_pregunta
      LIMIT 1
    ");
    $stmtPregunta->execute([
      ':id_agrupacion' => $idSel,
      ':id_pregunta' => $idPreguntaSel,
    ]);
    $preguntaActiva = $stmtPregunta->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($preguntaActiva) {
      $alternativasActivas = decodeAlternativas($preguntaActiva['alternativas'] ?? null);
    }
  }
}

$totalPreguntas = count($preguntasNav);
$indicePrevio = $indiceActual > 1 ? $indiceActual - 1 : 0;
$indiceSiguiente = ($indiceActual > 0 && $indiceActual < $totalPreguntas) ? $indiceActual + 1 : 0;
$idPreguntaPrevia = $indicePrevio > 0 ? (int)$preguntasNav[$indicePrevio - 1]['id'] : 0;
$idPreguntaSiguiente = $indiceSiguiente > 0 ? (int)$preguntasNav[$indiceSiguiente - 1]['id'] : 0;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Modificar Preguntas Formaciones | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.ckeditor.com/4.22.1/standard/ckeditor.js"></script>
<style>
body{background:#f7f9fc;font-size:0.9rem;}
.card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05);}
.alt-correcta{background:#d1e7dd;}
.form-label{font-weight:500;font-size:0.85rem;}
.alt-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;}
.btn-pregunta-circle{width:38px;height:38px;border-radius:50%;font-size:.9rem;padding:0;display:inline-flex;align-items:center;justify-content:center;border:none;background-color:#adb5bd;color:#fff;text-decoration:none;}
.btn-pregunta-circle.active{background-color:#0d6efd;}
</style>
</head>
<body>
<header class="topbar py-3 mb-4 bg-white border-bottom">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:50px;">
      <div>
        <strong><?= APP_NAME ?></strong><br>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <?= $msg ?>
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Seleccione Agrupación</label>
          <select name="id_agrupacion" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($agrupaciones as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ($idSel === (int)$a['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$a['titulo']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 align-self-end">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search me-2"></i>Ver preguntas</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($idSel > 0 && !empty($preguntasNav)): ?>
    <div class="card rounded-4 mb-4">
      <div class="card-body text-center">
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
          <?php foreach ($preguntasNav as $idx => $preguntaNav): ?>
            <?php $num = $idx + 1; ?>
            <a href="<?= htmlspecialchars(buildRedirectUrl($idSel, (int)$preguntaNav['id'])) ?>"
               class="btn-pregunta-circle <?= ((int)$preguntaNav['id'] === $idPreguntaSel) ? 'active' : '' ?>"
               title="Pregunta <?= $num ?>">
              <?= $num ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-center gap-2">
          <a href="<?= $idPreguntaPrevia > 0 ? htmlspecialchars(buildRedirectUrl($idSel, $idPreguntaPrevia)) : '#' ?>"
             class="btn btn-outline-secondary btn-sm <?= $idPreguntaPrevia <= 0 ? 'disabled' : '' ?>">Anterior</a>
          <a href="<?= $idPreguntaSiguiente > 0 ? htmlspecialchars(buildRedirectUrl($idSel, $idPreguntaSiguiente)) : '#' ?>"
             class="btn btn-outline-secondary btn-sm <?= $idPreguntaSiguiente <= 0 ? 'disabled' : '' ?>">Siguiente</a>
        </div>
        <div class="mt-2 small text-muted">
          Pregunta <strong><?= (int)$indiceActual ?></strong> de <strong><?= (int)$totalPreguntas ?></strong>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($preguntaActiva): ?>
    <form method="POST" enctype="multipart/form-data" class="card rounded-4 mb-4 p-3">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id_pregunta" value="<?= (int)$preguntaActiva['id'] ?>">
      <input type="hidden" name="id_agrupacion" value="<?= (int)$idSel ?>">

      <div class="d-flex justify-content-between align-items-center mb-2">
        <div>
          <h6 class="text-primary mb-0"><i class="bi bi-question-circle me-2"></i>Pregunta <?= (int)$indiceActual ?> de <?= (int)$totalPreguntas ?></h6>
          <small class="text-muted">ID interno: <?= (int)$preguntaActiva['id'] ?></small>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm btn-delete-pregunta" data-id="<?= (int)$preguntaActiva['id'] ?>"><i class="bi bi-trash"></i></button>
      </div>

      <textarea name="pregunta_texto" id="pregunta_<?= (int)$preguntaActiva['id'] ?>"><?= $preguntaActiva['pregunta'] ?></textarea>

      <div class="row g-3 mt-2">
        <div class="col-md-4">
          <label class="form-label">Tipo de Pregunta</label>
          <select name="tipo_pregunta_<?= (int)$preguntaActiva['id'] ?>" class="form-select form-select-sm">
            <option value="ALT" <?= ($preguntaActiva['tipo_pregunta'] ?? '') === 'ALT' ? 'selected' : '' ?>>Alternativas</option>
            <option value="VF" <?= ($preguntaActiva['tipo_pregunta'] ?? '') === 'VF' ? 'selected' : '' ?>>Verdadero/Falso</option>
            <option value="TEXTO_LIBRE" <?= ($preguntaActiva['tipo_pregunta'] ?? '') === 'TEXTO_LIBRE' ? 'selected' : '' ?>>Texto libre</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Obligatoria</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="obligatoria_<?= (int)$preguntaActiva['id'] ?>" id="obligatoria_<?= (int)$preguntaActiva['id'] ?>" value="1" <?= !empty($preguntaActiva['obligatoria']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="obligatoria_<?= (int)$preguntaActiva['id'] ?>">Si</label>
          </div>
        </div>
      </div>

      <?php if (!empty($preguntaActiva['imagen'])): ?>
        <div class="my-2">
          <label class="form-label">Contenido actual:</label>
          <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', (string)$preguntaActiva['imagen'])): ?>
            <img src="../<?= htmlspecialchars((string)$preguntaActiva['imagen']) ?>" class="img-fluid rounded shadow-sm" style="max-width:250px;">
          <?php else: ?>
            <a href="<?= htmlspecialchars((string)$preguntaActiva['imagen']) ?>" target="_blank"><i class="bi bi-play-btn"></i> Ver video</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <input type="hidden" name="pregunta_imagen_actual" value="<?= htmlspecialchars((string)($preguntaActiva['imagen'] ?? '')) ?>">
      <label class="form-label">Reemplazar imagen</label>
      <input type="file" name="pregunta_imagen" accept="image/*" class="form-control mb-2">
      <label class="form-label">O URL de video</label>
      <input type="url" name="pregunta_video" class="form-control mb-3" placeholder="https://...">

      <?php if (($preguntaActiva['tipo_pregunta'] ?? '') !== 'TEXTO_LIBRE'): ?>
        <fieldset class="mb-3">
          <legend>Alternativas</legend>
          <div id="alt_container_<?= (int)$preguntaActiva['id'] ?>">
            <?php foreach ($alternativasActivas as $a): ?>
              <input type="hidden" name="alt_id_<?= (int)$a['id'] ?>" value="<?= (int)$a['id'] ?>">
              <div class="border rounded p-2 mb-2 <?= ($a['correcta'] ?? '') === 'S' ? 'alt-correcta' : '' ?>">
                <div class="alt-header">
                  <div class="d-flex align-items-center gap-2">
                    <input type="radio" name="correcta_alt" value="<?= (int)$a['id'] ?>" <?= ($a['correcta'] ?? '') === 'S' ? 'checked' : '' ?>>
                    <small>Correcta</small>
                  </div>
                  <button type="button" class="btn btn-outline-danger btn-sm btn-del-alt" data-id="<?= (int)$a['id'] ?>" title="Eliminar alternativa"><i class="bi bi-x-circle"></i></button>
                </div>
                <textarea name="alt_texto_<?= (int)$a['id'] ?>" id="alt_texto_<?= (int)$a['id'] ?>"><?= htmlspecialchars((string)($a['alternativa'] ?? '')) ?></textarea>
                <?php if (!empty($a['imagen'])): ?>
                  <div class="mt-2">
                    <label class="form-label">Contenido actual:</label>
                    <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', (string)$a['imagen'])): ?>
                      <img src="../<?= htmlspecialchars((string)$a['imagen']) ?>" class="img-fluid rounded shadow-sm" style="max-width:180px;">
                    <?php else: ?>
                      <a href="<?= htmlspecialchars((string)$a['imagen']) ?>" target="_blank"><i class="bi bi-play-btn-fill me-1"></i> Ver video</a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <input type="hidden" name="alt_imagen_actual_<?= (int)$a['id'] ?>" value="<?= htmlspecialchars((string)($a['imagen'] ?? '')) ?>">
                <textarea name="alt_textoextra_<?= (int)$a['id'] ?>" class="form-control form-control-sm mt-2" rows="2" placeholder="(Opcional) Texto complementario para IMAGEN/VIDEO"></textarea>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="text-end">
            <button type="button" class="btn btn-outline-success btn-sm mt-2 btn-add-alt" data-id="<?= (int)$preguntaActiva['id'] ?>">
              <i class="bi bi-plus-circle me-1"></i>Agregar alternativa
            </button>
          </div>
        </fieldset>
      <?php endif; ?>

      <fieldset class="mb-3">
        <legend>Retroalimentación Correcta</legend>
        <textarea name="retropos" id="retropos_<?= (int)$preguntaActiva['id'] ?>"><?= htmlspecialchars((string)($preguntaActiva['retropos'] ?? '')) ?></textarea>
      </fieldset>

      <fieldset class="mb-3">
        <legend>Retroalimentación Incorrecta</legend>
        <textarea name="retroneg" id="retroneg_<?= (int)$preguntaActiva['id'] ?>"><?= htmlspecialchars((string)($preguntaActiva['retroneg'] ?? '')) ?></textarea>
        <div class="mt-2" style="max-width:120px;">
          <label class="form-label">Peso</label>
          <input type="number" name="peso_<?= (int)$preguntaActiva['id'] ?>" class="form-control" min="1" max="10" value="<?= (int)($preguntaActiva['peso'] ?? 1) ?>" required>
        </div>
      </fieldset>

      <div class="text-end">
        <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Guardar cambios</button>
      </div>
    </form>
  <?php elseif ($idSel > 0): ?>
    <div class="alert alert-warning">La agrupación seleccionada no tiene preguntas configuradas.</div>
  <?php endif; ?>
</div>

<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="action_modal">
        <input type="hidden" name="id_agrupacion" value="<?= (int)$idSel ?>">
        <input type="hidden" name="id_pregunta" id="id_pregunta_modal" value="<?= (int)$idPreguntaSel ?>">
        <input type="hidden" name="id_alternativa" id="id_alternativa_modal">
        <div class="modal-header bg-danger text-white">
          <h6 class="modal-title">Confirmar eliminación</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p id="textoConfirm"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
if (window.CKEDITOR) {
  CKEDITOR.disableAutoInline = true;
  CKEDITOR.config.versionCheck = false;
}
window.CKEDITOR_VERSION_WARNING = false;

const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirm'));
const preguntaIdActual = <?= (int)$idPreguntaSel ?>;

document.querySelectorAll('.btn-delete-pregunta').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('action_modal').value = 'delete_pregunta';
    document.getElementById('id_pregunta_modal').value = btn.dataset.id;
    document.getElementById('id_alternativa_modal').value = '';
    document.getElementById('textoConfirm').innerText = '¿Eliminar esta pregunta y todas sus alternativas?';
    modalConfirm.show();
  });
});

document.querySelectorAll('.btn-del-alt').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('action_modal').value = 'delete_alternativa';
    document.getElementById('id_pregunta_modal').value = preguntaIdActual;
    document.getElementById('id_alternativa_modal').value = btn.dataset.id;
    document.getElementById('textoConfirm').innerText = '¿Eliminar esta alternativa?';
    modalConfirm.show();
  });
});

document.querySelectorAll('.btn-add-alt').forEach(btn => {
  btn.addEventListener('click', () => {
    const idP = btn.dataset.id;
    const cont = document.getElementById('alt_container_' + idP);
    if (!cont) return;
    const idx = Date.now();
    const block = document.createElement('div');
    block.className = 'border rounded p-2 mb-2';
    block.innerHTML = `
      <div class="alt-header">
        <div class="d-flex align-items-center gap-2">
          <input type="radio" name="correcta_alt" value="new_${idx}">
          <small>Correcta</small>
        </div>
      </div>
      <textarea name="nueva_alt_texto[]" id="nueva_alt_texto_${idx}" class="form-control mb-2" rows="2" placeholder="Texto de alternativa (si corresponde)"></textarea>
      <textarea name="nueva_alt_textoextra[]" class="form-control form-control-sm mb-2" rows="2" placeholder="(Opcional) Texto complementario para IMAGEN/VIDEO"></textarea>
      <label class="form-label">Imagen (opcional)</label>
      <input type="file" name="nueva_alt_imagen[]" accept="image/*" class="form-control mb-2">
      <label class="form-label">O URL de video</label>
      <input type="url" name="nueva_alt_video[]" class="form-control" placeholder="https://...">
    `;
    cont.appendChild(block);
    if (window.CKEDITOR) {
      CKEDITOR.replace('nueva_alt_texto_' + idx, {height: 60});
    }
  });
});

if (window.CKEDITOR && preguntaIdActual > 0) {
  CKEDITOR.replace('pregunta_' + preguntaIdActual, {height: 80});
  CKEDITOR.replace('retropos_' + preguntaIdActual, {height: 70});
  CKEDITOR.replace('retroneg_' + preguntaIdActual, {height: 70});
  document.querySelectorAll('textarea[id^="alt_texto_"]').forEach(el => {
    CKEDITOR.replace(el.id, {height: 60});
  });
}
</script>
</body>
</html>
