<?php
// --------------------------------------------------------------
// pruebas_teoricas_modificar.php - CEO
// Etapa 4: Agregar nuevas alternativas dinámicamente
// --------------------------------------------------------------
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
$msg = "";

// ======== CARGAR AGRUPACIONES ========
$agrupaciones = $pdo->query("SELECT id, titulo FROM ceo_agrupacion ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ======== ACCIONES ========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'update') {
      $idPregunta = (int)$_POST['id_pregunta'];
      $texto = $_POST['pregunta_texto'] ?? '';
      $retroPos = $_POST['retropos'] ?? '';
      $retroNeg = $_POST['retroneg'] ?? '';
      $correctaAlt = $_POST['correcta_alt'] ?? '';

      $uploadDir = __DIR__ . '/../uploads/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

      // === Imagen / Video de la pregunta ===
      $imagen = trim($_POST['pregunta_imagen_actual'] ?? '');
      if (!empty($_FILES['pregunta_imagen']['name'])) {
        $name = basename($_FILES['pregunta_imagen']['name']);
        $target = $uploadDir . time() . '_' . $name;
        if (move_uploaded_file($_FILES['pregunta_imagen']['tmp_name'], $target))
          $imagen = 'uploads/' . basename($target);
      }
      if (!empty($_POST['pregunta_video'])) $imagen = trim($_POST['pregunta_video']);

      $pdo->prepare("UPDATE ceo_preguntas_servicios 
                       SET pregunta=?, imagen=?, retropos=?, retroneg=? 
                     WHERE id=?")
          ->execute([$texto, $imagen, $retroPos, $retroNeg, $idPregunta]);

      // === Actualizar alternativas existentes ===
      foreach ($_POST as $k => $v) {
        if (preg_match('/^alt_id_(\d+)$/', $k, $m)) {
          $idAlt = (int)$m[1];
          // Texto principal (solo si la alternativa es tipo texto)
$alternativaTexto = $_POST["alt_texto_$idAlt"] ?? '';

// Texto adicional opcional para IMAGEN / VIDEO
$alternativaTextoExtra = trim($_POST["alt_textoextra_$idAlt"] ?? '');

// Siempre almacenamos texto adicional si existe
$alternativa = $alternativaTexto ?: $alternativaTextoExtra;

          $imgAlt = $_POST["alt_imagen_actual_$idAlt"] ?? '';
          if (!empty($_FILES["alt_imagen_$idAlt"]['name'])) {
            $name = basename($_FILES["alt_imagen_$idAlt"]['name']);
            
            $target = $uploadDir . time() . "_alt_" . $name;
            if (move_uploaded_file($_FILES["alt_imagen_$idAlt"]['tmp_name'], $target))
              $imgAlt = 'uploads/' . basename($target);
          }
          if (!empty($_POST["alt_video_$idAlt"])) $imgAlt = trim($_POST["alt_video_$idAlt"]);
          $correcta = ($correctaAlt == $idAlt) ? 'S' : 'N';

          $pdo->prepare("UPDATE ceo_alternativas_preguntas 
                            SET alternativa=?, correcta=?, imagen=? 
                          WHERE id=?")
              ->execute([$alternativa, $correcta, $imgAlt, $idAlt]);
        }
      }

      // === NUEVAS ALTERNATIVAS ===
// === NUEVAS ALTERNATIVAS ===
if (!empty($_POST['nueva_alt_texto'])) {
  foreach ($_POST['nueva_alt_texto'] as $idx => $nuevoTexto) {

    // Texto principal
    $nuevoTexto = trim($nuevoTexto);

    // Texto extra opcional (para imagen/video)
    $nuevoTextoExtra = trim($_POST['nueva_alt_textoextra'][$idx] ?? '');

    // Usar el texto principal si existe, si no, usar el extra
    $textoFinal = $nuevoTexto !== '' ? $nuevoTexto : $nuevoTextoExtra;

    // Si no hay texto ni imagen ni video, no grabamos nada
    $nuevoVideo = trim($_POST['nueva_alt_video'][$idx] ?? '');
    $nuevaImg = '';
    if (!empty($_FILES['nueva_alt_imagen']['name'][$idx])) {
      $name = basename($_FILES['nueva_alt_imagen']['name'][$idx]);
      $target = $uploadDir . time() . "_nueva_" . $name;
      if (move_uploaded_file($_FILES['nueva_alt_imagen']['tmp_name'][$idx], $target)) {
        $nuevaImg = 'uploads/' . basename($target);
      }
    }

    // Si no hay nada, se salta
    if ($textoFinal === '' && $nuevoVideo === '' && $nuevaImg === '') {
      continue;
    }

    $imagenFinal = $nuevoVideo ?: $nuevaImg;

    // Nuevas alternativas quedan como no correctas por defecto
    $pdo->prepare("INSERT INTO ceo_alternativas_preguntas
                     (alternativa, correcta, estado, id_pregunta, imagen)
                   VALUES (?, 'N', 'S', ?, ?)")
        ->execute([$textoFinal, $idPregunta, $imagenFinal]);
  }
}


      $msg = "<div class='alert alert-success mt-3'>✅ Pregunta #{$idPregunta} actualizada correctamente.</div>";
    }

    // === Eliminar pregunta / alternativa ===
    if ($action === 'delete_pregunta') {
      $id = (int)$_POST['id_pregunta'];
      $pdo->prepare("DELETE FROM ceo_alternativas_preguntas WHERE id_pregunta=?")->execute([$id]);
      $pdo->prepare("DELETE FROM ceo_preguntas_servicios WHERE id=?")->execute([$id]);
      $msg = "<div class='alert alert-info mt-3'>🗑️ Pregunta eliminada correctamente.</div>";
    }

    if ($action === 'delete_alternativa') {
      $id = (int)$_POST['id_alternativa'];
      $pdo->prepare("DELETE FROM ceo_alternativas_preguntas WHERE id=?")->execute([$id]);
      $msg = "<div class='alert alert-info mt-3'>🗑️ Alternativa eliminada correctamente.</div>";
    }

  } catch (Throwable $e) {
    $msg = "<div class='alert alert-danger mt-3'>❌ Error: ".htmlspecialchars($e->getMessage())."</div>";
  }
}

// ======== LISTAR PREGUNTAS ========
$idSel = (int)($_GET['id_agrupacion'] ?? ($_POST['id_agrupacion'] ?? 0));
$preguntas = [];
if ($idSel > 0) {
  $stmt = $pdo->prepare("
    SELECT p.id, p.pregunta, p.imagen, p.retropos, p.retroneg,
           (SELECT JSON_ARRAYAGG(JSON_OBJECT(
              'id', a.id,
              'alternativa', a.alternativa,
              'correcta', a.correcta,
              'imagen', a.imagen
            )) FROM ceo_alternativas_preguntas a WHERE a.id_pregunta = p.id
           ) AS alternativas
      FROM ceo_preguntas_servicios p
     WHERE p.id_agrupacion = :id
     ORDER BY p.id ASC
  ");
  $stmt->execute([':id' => $idSel]);
  $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Modificar Preguntas | <?= htmlspecialchars(APP_NAME) ?></title>
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
              <option value="<?= $a['id'] ?>" <?= ($idSel === (int)$a['id'])?'selected':'' ?>>
                <?= htmlspecialchars($a['titulo']) ?>
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

  <?php if ($idSel > 0 && !empty($preguntas)): ?>
    <?php foreach ($preguntas as $p): 
        $alts = [];
        if (!empty($p['alternativas']) && is_string($p['alternativas'])) {
            $alts = json_decode($p['alternativas'], true);
            if (!is_array($alts)) {
                $alts = [];
            }
        }

    ?>
    <form method="POST" enctype="multipart/form-data" class="card rounded-4 mb-4 p-3">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id_pregunta" value="<?= $p['id'] ?>">
      <input type="hidden" name="id_agrupacion" value="<?= $idSel ?>">

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="text-primary mb-0"><i class="bi bi-question-circle me-2"></i>Pregunta #<?= $p['id'] ?></h6>
        <button type="button" class="btn btn-outline-danger btn-sm btn-delete-pregunta" data-id="<?= $p['id'] ?>"><i class="bi bi-trash"></i></button>
      </div>

      <textarea name="pregunta_texto" id="pregunta_<?= $p['id'] ?>"><?= $p['pregunta'] ?></textarea>

      <?php if ($p['imagen']): ?>
        <div class="my-2">
          <label class="form-label">Contenido actual:</label>
          <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $p['imagen'])): ?>
            <img src="../<?= htmlspecialchars($p['imagen']) ?>" class="img-fluid rounded shadow-sm" style="max-width:250px;">
          <?php else: ?>
            <a href="<?= htmlspecialchars($p['imagen']) ?>" target="_blank"><i class="bi bi-play-btn"></i> Ver video</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <input type="hidden" name="pregunta_imagen_actual" value="<?= htmlspecialchars($p['imagen']) ?>">
      <label class="form-label">Reemplazar imagen</label>
      <input type="file" name="pregunta_imagen" accept="image/*" class="form-control mb-2">
      <label class="form-label">O URL de video</label>
      <input type="url" name="pregunta_video" class="form-control mb-3" placeholder="https://...">

      <?php if (!empty($alts)): ?>
        <fieldset class="mb-3">
          <legend>Alternativas</legend>
          <div id="alt_container_<?= $p['id'] ?>">
            <?php foreach ($alts as $a): ?>
              <input type="hidden" name="alt_id_<?= $a['id'] ?>" value="<?= $a['id'] ?>">
              <div class="border rounded p-2 mb-2 <?= $a['correcta']==='S'?'alt-correcta':'' ?>">
                <div class="alt-header">
                  <div class="d-flex align-items-center gap-2">
                    <input type="radio" name="correcta_alt" value="<?= $a['id'] ?>" <?= $a['correcta']==='S'?'checked':'' ?>>
                    <small>Correcta</small>
                  </div>
                  <button type="button" class="btn btn-outline-danger btn-sm btn-del-alt" data-id="<?= $a['id'] ?>" title="Eliminar alternativa"><i class="bi bi-x-circle"></i></button>
                </div>
                <textarea name="alt_texto_<?= $a['id'] ?>" id="alt_texto_<?= $a['id'] ?>"><?= $a['alternativa'] ?></textarea>
                <?php if (!empty($a['imagen'])): ?>
                    <div class="mt-2">
                        <label class="form-label">Contenido actual:</label>
                        <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $a['imagen'])): ?>
                            <img src="../<?= htmlspecialchars($a['imagen']) ?>" 
                                 class="img-fluid rounded shadow-sm" 
                                 style="max-width:180px;">
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($a['imagen']) ?>" 
                               target="_blank">
                               <i class="bi bi-play-btn-fill me-1"></i> Ver video
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <input type="hidden" name="alt_imagen_actual_<?= $a['id'] ?>" value="<?= htmlspecialchars($a['imagen']) ?>">
                <textarea name="alt_textoextra_<?= $a['id'] ?>"
          class="form-control form-control-sm mt-2"
          rows="2"
          placeholder="(Opcional) Texto complementario para IMAGEN/VIDEO"></textarea>

              </div>
            <?php endforeach; ?>
          </div>
          <!-- Botón agregar alternativa -->
          <div class="text-end">
            <button type="button" class="btn btn-outline-success btn-sm mt-2 btn-add-alt" data-id="<?= $p['id'] ?>">
              <i class="bi bi-plus-circle me-1"></i>Agregar alternativa
            </button>
          </div>
        </fieldset>
      <?php endif; ?>

      <fieldset class="mb-3">
        <legend>Retroalimentación Correcta</legend>
        <textarea name="retropos" id="retropos_<?= $p['id'] ?>"><?= $p['retropos'] ?></textarea>
      </fieldset>
      <fieldset class="mb-3">
        <legend>Retroalimentación Incorrecta</legend>
        <textarea name="retroneg" id="retroneg_<?= $p['id'] ?>"><?= $p['retroneg'] ?></textarea>
      </fieldset>

      <div class="text-end">
        <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Guardar cambios</button>
      </div>
    </form>
		<script>
		// Desactiva advertencias de actualización de CKEditor
		if (window.CKEDITOR) {
		  CKEDITOR.disableAutoInline = true;
		  CKEDITOR.config.versionCheck = false;
		  if (CKEDITOR.plugins && CKEDITOR.plugins.addExternal) {
		    console.log("CKEditor loaded with version check disabled.");
		  }
		}
		// Suprime mensajes en consola
		window.CKEDITOR_VERSION_WARNING = false;
</script>
    <script>
      CKEDITOR.replace('pregunta_<?= $p['id'] ?>',{height:80});
      CKEDITOR.replace('retropos_<?= $p['id'] ?>',{height:70});
      CKEDITOR.replace('retroneg_<?= $p['id'] ?>',{height:70});
      <?php foreach ($alts as $a): ?>
        CKEDITOR.replace('alt_texto_<?= $a['id'] ?>',{height:60});
      <?php endforeach; ?>
    </script>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="action_modal">
        <input type="hidden" name="id_pregunta" id="id_pregunta_modal">
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
const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirm'));

// === Confirmaciones de eliminación ===
document.querySelectorAll('.btn-delete-pregunta').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.getElementById('action_modal').value='delete_pregunta';
    document.getElementById('id_pregunta_modal').value=btn.dataset.id;
    document.getElementById('textoConfirm').innerText='¿Eliminar esta pregunta y todas sus alternativas?';
    modalConfirm.show();
  });
});
document.querySelectorAll('.btn-del-alt').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.getElementById('action_modal').value='delete_alternativa';
    document.getElementById('id_alternativa_modal').value=btn.dataset.id;
    document.getElementById('textoConfirm').innerText='¿Eliminar esta alternativa?';
    modalConfirm.show();
  });
});

// === Agregar nueva alternativa dinámicamente ===
document.querySelectorAll('.btn-add-alt').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const idP = btn.dataset.id;
    const cont = document.getElementById('alt_container_'+idP);
    const idx = Date.now(); // identificador temporal
    const block = document.createElement('div');
    block.className = 'border rounded p-2 mb-2';
block.innerHTML = `
  <div class="alt-header">
    <div class="d-flex align-items-center gap-2">
      <input type="radio" name="correcta_alt" value="new_${idx}">
      <small>Correcta</small>
    </div>
  </div>

  <!-- TEXTO PRINCIPAL -->
  <textarea name="nueva_alt_texto[]" id="nueva_alt_texto_${idx}" 
            class="form-control mb-2" rows="2"
            placeholder="Texto de alternativa (si corresponde)"></textarea>

  <!-- TEXTO OPCIONAL PARA IMAGEN/VIDEO -->
  <textarea name="nueva_alt_textoextra[]" 
            class="form-control form-control-sm mb-2" rows="2"
            placeholder="(Opcional) Texto complementario para IMAGEN/VIDEO"></textarea>

  <!-- IMAGEN -->
  <label class="form-label">Imagen (opcional)</label>
  <input type="file" name="nueva_alt_imagen[]" 
         accept="image/*" class="form-control mb-2">

  <!-- VIDEO -->
  <label class="form-label">O URL de video</label>
  <input type="url" name="nueva_alt_video[]" 
         class="form-control" placeholder="https://...">
`;

    cont.appendChild(block);
    CKEDITOR.replace('nueva_alt_texto_'+idx,{height:60});
  });
});
</script>
</body>
</html>
