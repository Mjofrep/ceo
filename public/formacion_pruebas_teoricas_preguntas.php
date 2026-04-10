<?php
// --------------------------------------------------------------
// formacion_pruebas_teoricas_preguntas.php - Centro de Excelencia Operacional (CEO)
// Registro y carga de preguntas y alternativas (versión con modal resumen)
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
$id_agrupacion = (int)($_GET['id_agrupacion'] ?? 0);

// =================== VALIDAR AGRUPACION ===================
$stmt = $pdo->prepare("SELECT a.*, s.servicio 
                         FROM ceo_formacion_agrupacion a
                         JOIN ceo_formacion_servicios s ON s.id = a.id_servicio
                        WHERE a.id = :id LIMIT 1");
$stmt->execute([':id' => $id_agrupacion]);
$agrup = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$agrup) {
  echo "<div class='alert alert-danger m-5'>❌ Agrupación no encontrada.</div>";
  exit;
}

// =================== ÁREAS DE COMPETENCIA ===================
$stmtArea = $pdo->prepare("
    SELECT id, descripcion
    FROM ceo_areacompetencias
    WHERE id_servicio = :id_servicio
    ORDER BY descripcion
");
$stmtArea->execute([
    ':id_servicio' => $agrup['id_servicio']
]);
$areasCompetencia = $stmtArea->fetchAll(PDO::FETCH_ASSOC);

$msg = "";

// =================== CONTADOR Y LISTADO ===================
$contPreg = $pdo->prepare("SELECT COUNT(*) FROM ceo_formacion_preguntas_servicios WHERE id_agrupacion = :id");
$contPreg->execute([':id' => $id_agrupacion]);
$totalPreg = (int)$contPreg->fetchColumn();

$listPreg = $pdo->prepare("
  SELECT p.id, p.pregunta, p.imagen, p.retropos, p.retroneg,
         (SELECT JSON_ARRAYAGG(JSON_OBJECT(
            'id', a.id,
            'alternativa', a.alternativa,
            'correcta', a.correcta,
            'imagen', a.imagen
          )) 
          FROM ceo_formacion_alternativas_preguntas a WHERE a.id_pregunta = p.id
         ) AS alternativas
    FROM ceo_formacion_preguntas_servicios p
   WHERE p.id_agrupacion = :id
   ORDER BY p.id ASC
");
$listPreg->execute([':id' => $id_agrupacion]);
$preguntas = $listPreg->fetchAll(PDO::FETCH_ASSOC);

// =================== PROCESO DE GUARDADO ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $idAgrup = (int)($_POST['id_agrupacion'] ?? 0);
    $idServicio = (int)($agrup['id_servicio'] ?? 0);
    $textoPregunta = trim($_POST['texto_pregunta'] ?? '');
    $tipoPregunta = $_POST['tipo_pregunta'] ?? '';
    $tipoContenido = $_POST['tipo_contenido'] ?? '';
    $retroPos = trim($_POST['retro_correcta'] ?? '');
    $retroNeg = trim($_POST['retro_incorrecta'] ?? '');

    $textoPregunta = html_entity_decode(strip_tags($textoPregunta), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $retroPos = html_entity_decode(strip_tags($retroPos), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $retroNeg = html_entity_decode(strip_tags($retroNeg), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $areaComp = (int)($_POST['areacomp'] ?? 0);
    $peso = (int)($_POST['peso'] ?? 1);
    if ($peso <= 0) {
        $peso = 1;
    }
    
    if ($areaComp <= 0) {
        throw new Exception("Debe seleccionar un Área de Competencia.");
    }

    $preguntaImagen = '';
    if (!empty($_FILES['img_pregunta']['name'])) {
      $name = basename($_FILES['img_pregunta']['name']);
      $target = $uploadDir . time() . '_' . $name;
      if (move_uploaded_file($_FILES['img_pregunta']['tmp_name'], $target)) {
        $preguntaImagen = 'uploads/' . basename($target);
      }
    }
    if (!empty($_POST['video_pregunta'])) {
      $preguntaImagen = trim($_POST['video_pregunta']);
    }

    $stmt = $pdo->prepare("INSERT INTO ceo_formacion_preguntas_servicios 
      (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp, peso)
      VALUES (:pregunta, :id_servicio, :imagen, 'S', :id_agrupacion, :retropos, :retroneg, :areacomp, :peso)");
    $stmt->execute([
      ':pregunta' => $textoPregunta,
      ':id_servicio' => $idServicio,
      ':imagen' => $preguntaImagen,
      ':id_agrupacion' => $idAgrup,
      ':retropos' => $retroPos,
      ':retroneg' => $retroNeg,
      ':areacomp'       => $areaComp,
      ':peso'           => $peso
    ]);
    $idPregunta = (int)$pdo->lastInsertId();

    if ($tipoPregunta === 'VF') {
      $correcta = $_POST['correcta'] ?? '';
      $opciones = ['Verdadero', 'Falso'];
      for ($i = 1; $i <= 2; $i++) {
        $valor = $opciones[$i - 1];
        $estadoCor = ($correcta == $i) ? 'S' : 'N';
        $stmtAlt = $pdo->prepare("INSERT INTO ceo_formacion_alternativas_preguntas
          (alternativa, correcta, estado, id_pregunta, imagen)
          VALUES (:alternativa, :correcta, 'S', :id_pregunta, '')");
        $stmtAlt->execute([
          ':alternativa' => $valor,
          ':correcta' => $estadoCor,
          ':id_pregunta' => $idPregunta
        ]);
      }
    } else {
       $correcta = $_POST['correcta_alt'] ?? '';
       for ($i = 1; $i <= 8; $i++) {
           if (!isset($_POST["tipo_alt_$i"])) continue;
           $tipoAlt = $_POST["tipo_alt_$i"];
           $alternativa = '';
           $imagenAlt = '';

// Siempre capturamos texto opcional si existe (para imagen o video)
 $alternativaTextoOpc = trim($_POST["alt_textoextra_$i"] ?? '');

// Evaluar por tipo
 if ($tipoAlt === 'texto' && !empty($_POST["alt_texto_$i"])) {
     $alternativa = trim($_POST["alt_texto_$i"]);

} elseif ($tipoAlt === 'imagen') {
    if (!empty($_FILES["alt_img_$i"]['name'])) {
        $name = basename($_FILES["alt_img_$i"]['name']);
        $target = $uploadDir . time() . "_alt{$i}_" . $name;
        if (move_uploaded_file($_FILES["alt_img_$i"]['tmp_name'], $target)) {
            $imagenAlt = 'uploads/' . basename($target);
        }
    }
    // texto opcional si usuario quiere agregar explicación
     $alternativa = $alternativaTextoOpc;

} elseif ($tipoAlt === 'video') {
    if (!empty($_POST["alt_video_$i"])) {
        $imagenAlt = trim($_POST["alt_video_$i"]);
    }
    // texto opcional si usuario quiere agregar explicación
     $alternativa = $alternativaTextoOpc;
}


        $alternativa = html_entity_decode(strip_tags($alternativa), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $estadoCor = ($correcta == $i) ? 'S' : 'N';
        $stmtAlt = $pdo->prepare("INSERT INTO ceo_formacion_alternativas_preguntas
          (alternativa, correcta, estado, id_pregunta, imagen)
          VALUES (:alternativa, :correcta, 'S', :id_pregunta, :imagen)");
        $stmtAlt->execute([
          ':alternativa' => $alternativa,
          ':correcta' => $estadoCor,
          ':id_pregunta' => $idPregunta,
          ':imagen' => $imagenAlt
        ]);
      }
    }

    $msg = "<div class='alert alert-success mt-3'>✅ Pregunta registrada correctamente.</div>";
    header("Refresh:1"); // recarga para actualizar contador
  } catch (Throwable $e) {
    $msg = "<div class='alert alert-danger mt-3'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Banco de Preguntas Formaciones | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.ckeditor.com/4.22.1/standard/ckeditor.js"></script>
<style>
body {background:#f7f9fc; font-size:0.9rem;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600; font-size:1.1rem;}
.card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
.section-header {background:#0d6efd; color:#fff; font-weight:600; padding:6px 10px; font-size:0.9rem;}
.form-label {font-weight:500; font-size:0.85rem;}
fieldset {border:1px solid #dee2e6; padding:10px; border-radius:6px; background:#fff;}
legend {font-size:0.9rem; font-weight:600; color:#0d6efd;}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="formacion_pruebas_teoricas.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <!-- CABECERA -->
  <div class="card rounded-4 mb-4">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <h6 class="text-primary mb-2"><i class="bi bi-journal-text me-2"></i>Agregar al Banco de Preguntas Formaciones</h6>
        <p class="mb-1"><strong>Datos Generales:</strong> <?= htmlspecialchars($agrup['titulo']) ?></p>
        <p class="mb-1 text-muted">Servicio asociado: <?= htmlspecialchars($agrup['servicio']) ?></p>
        <p class="mb-0">
          <strong>Preguntas registradas:</strong> 
          <span class="badge bg-info text-dark"><?= $totalPreg ?></span>
          <?php if ($totalPreg > 0): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#modalVerPreguntas">
              <i class="bi bi-eye"></i> Ver preguntas
            </button>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>

  <!-- FORMULARIO -->
  <div class="card rounded-4">
    <div class="card-body">
      <?= $msg ?>
      <form id="formPregunta" method="POST" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="id_agrupacion" value="<?= $agrup['id'] ?>">

        <div class="section-header mb-2">Agregar Pregunta</div>

        <div class="row mb-3">
          <div class="col-md-4">
            <label class="form-label">Tipo de Pregunta</label>
            <select name="tipo_pregunta" id="tipo_pregunta" class="form-select" required>
              <option value="">-- Seleccione --</option>
              <option value="VF">Verdadero y Falso</option>
              <option value="ALT">Alternativas</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tipo de Contenido</label>
            <select name="tipo_contenido" id="tipo_contenido" class="form-select" required>
              <option value="">-- Seleccione --</option>
              <option value="texto">Texto</option>
              <option value="imagen">Imagen</option>
              <option value="video">Video</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Área de Competencia</label>
            <select name="areacomp" class="form-select" required>
              <option value="">-- Seleccione --</option>
              <?php foreach ($areasCompetencia as $ac): ?>
                <option value="<?= (int)$ac['id'] ?>">
                  <?= htmlspecialchars($ac['descripcion']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Peso</label>
            <input type="number" name="peso" class="form-control" min="1" max="10" value="1" required>
          </div>

        </div>

        <fieldset class="mb-3">
          <legend>Redactar texto de la pregunta</legend>
          <textarea name="texto_pregunta" id="texto_pregunta" rows="4" class="form-control"></textarea>
          <div id="extra_contenido" class="mt-3"></div>
        </fieldset>

        <fieldset id="contenedor_opciones" class="mb-3">
          <legend>Opciones de respuesta</legend>
          <div class="text-muted">Seleccione el tipo de pregunta arriba para generar las opciones.</div>
        </fieldset>

        <fieldset class="mb-3">
          <legend>Retroalimentación Correcta</legend>
          <textarea name="retro_correcta" id="retro_correcta" rows="3" class="form-control"></textarea>
        </fieldset>

        <fieldset class="mb-3">
          <legend>Retroalimentación Incorrecta</legend>
          <textarea name="retro_incorrecta" id="retro_incorrecta" rows="3" class="form-control"></textarea>
        </fieldset>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Grabar Pregunta</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL VER PREGUNTAS -->
<div class="modal fade" id="modalVerPreguntas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h6 class="modal-title"><i class="bi bi-list-check me-2"></i>Preguntas registradas (solo lectura)</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (empty($preguntas)): ?>
          <div class="alert alert-info">No hay preguntas registradas en esta agrupación.</div>
        <?php else: ?>
          <?php foreach ($preguntas as $p): 
            $alts = json_decode($p['alternativas'], true) ?? [];
          ?>
          <div class="border rounded p-3 mb-3 bg-light">
            <h6 class="fw-semibold text-primary">Pregunta #<?= $p['id'] ?></h6>
            <div><?= $p['pregunta'] ?></div>
            <?php if (!empty($p['imagen'])): ?>
              <div class="mt-2">
                <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $p['imagen'])): ?>
                  <img src="../<?= htmlspecialchars($p['imagen']) ?>" class="img-fluid rounded shadow-sm" style="max-width:300px;">
                <?php else: ?>
                  <a href="<?= htmlspecialchars($p['imagen']) ?>" target="_blank"><i class="bi bi-play-btn-fill me-1"></i> Ver video</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($alts)): ?>
              <div class="mt-3">
                <strong>Alternativas:</strong>
                <ul class="list-group list-group-flush mt-2">
                  <?php foreach ($alts as $a): ?>
                    <li class="list-group-item d-flex align-items-center">
                      <?php if ($a['correcta'] === 'S'): ?>
                        <span class="badge bg-success me-2">✔ Correcta</span>
                      <?php else: ?>
                        <span class="badge bg-secondary me-2">✖</span>
                      <?php endif; ?>
                      <div>
                        <?= $a['alternativa'] ?: '' ?>
                        <?php if (!empty($a['imagen'])): ?>
                          <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $a['imagen'])): ?>
                            <img src="../<?= htmlspecialchars($a['imagen']) ?>" class="img-fluid rounded mt-1" style="max-width:180px;">
                          <?php else: ?>
                            <a href="<?= htmlspecialchars($a['imagen']) ?>" target="_blank"><i class="bi bi-play-btn-fill me-1"></i> Ver video</a>
                          <?php endif; ?>
                        <?php endif; ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            <div class="mt-3">
              <strong class="text-success">Retroalimentación Correcta:</strong>
              <div><?= $p['retropos'] ?></div>
              <strong class="text-danger d-block mt-2">Retroalimentación Incorrecta:</strong>
              <div><?= $p['retroneg'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <div class="modal-footer">
            <a href="formacion_pruebas_teoricas_modificar.php?id_agrupacion=<?= $id_agrupacion ?>"
               class="btn btn-outline-primary">
                <i class="bi bi-pencil-square"></i> Editar preguntas
            </a>
            <a href="export_prueba_excel.php?id_agrupacion=<?= $id_agrupacion ?>" 
               class="btn btn-success">
                <i class="bi bi-file-earmark-excel"></i> Exportar a Excel
            </a>
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
</div>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
CKEDITOR.replace('texto_pregunta',{height:120});
CKEDITOR.replace('retro_correcta',{height:100});
CKEDITOR.replace('retro_incorrecta',{height:100});
const extraContenido=document.getElementById('extra_contenido');
document.getElementById('tipo_contenido').addEventListener('change',e=>{
  extraContenido.innerHTML='';
  if(e.target.value==='imagen'){
    extraContenido.innerHTML=`<label class="form-label">Seleccione imagen</label><input type="file" name="img_pregunta" accept="image/*" class="form-control">`;
  }else if(e.target.value==='video'){
    extraContenido.innerHTML=`<label class="form-label">URL del video</label><input type="url" name="video_pregunta" placeholder="https://..." class="form-control">`;
  }
});
const contOpciones=document.getElementById('contenedor_opciones');
document.getElementById('tipo_pregunta').addEventListener('change',e=>{
  contOpciones.innerHTML='';
  const tipo=e.target.value;
  if(tipo==='VF'){
    contOpciones.innerHTML=`<div class="mb-2"><input type="radio" name="correcta" value="1" required> Verdadero</div><div><input type="radio" name="correcta" value="2"> Falso</div>`;
  }
  if(tipo==='ALT'){
    contOpciones.innerHTML=`<div class="mb-2 d-flex align-items-center"><label class="form-label me-2">Cantidad de alternativas:</label><input type="number" id="num_alt" value="4" min="2" max="8" class="form-control form-control-sm" style="width:90px;"><button type="button" class="btn btn-outline-secondary btn-sm ms-2" id="btnGenAlt"><i class="bi bi-arrow-repeat"></i> Generar</button></div><div id="alt_container"></div>`;
    inicializarGeneradorAlternativas();
  }
});
function inicializarGeneradorAlternativas(){
  const btnGen=document.getElementById('btnGenAlt');
  const cont=document.getElementById('alt_container');
  btnGen.addEventListener('click',()=>{
    const n=parseInt(document.getElementById('num_alt').value)||2;
    cont.innerHTML='';
    for(let i=1;i<=n;i++){
      cont.insertAdjacentHTML('beforeend',`<div class="border rounded p-2 mb-2"><div class="row mb-2 align-items-center"><div class="col-md-3"><label class="form-label">Tipo opción ${i}</label><select id="tipo_alt_${i}" name="tipo_alt_${i}" class="form-select form-select-sm tipoAlt" data-n="${i}"><option value="texto">Texto</option><option value="imagen">Imagen</option><option value="video">Video</option></select></div><div class="col-md-1 text-center"><input type="radio" name="correcta_alt" value="${i}" title="Correcta"></div><div class="col-md-8" id="cont_alt_${i}"></div></div></div>`);
      generarCampoAlternativa(i,'texto');
    }
    document.querySelectorAll('.tipoAlt').forEach(sel=>{
      sel.addEventListener('change',e=>{
        const tipo=e.target.value;
        const num=e.target.dataset.n;
        generarCampoAlternativa(num,tipo);
      });
    });
  });
}
function generarCampoAlternativa(num,tipo){
  const cont=document.getElementById('cont_alt_'+num);
  cont.innerHTML='';

  if(tipo==='texto'){
    cont.innerHTML = `
      <textarea name="alt_texto_${num}" id="alt_texto_${num}" class="form-control" rows="2"></textarea>
    `;
    CKEDITOR.replace('alt_texto_'+num,{height:60});
  }

  else if(tipo==='imagen'){
    cont.innerHTML = `
      <div class="mb-2">
        <input type="file" name="alt_img_${num}" accept="image/*" class="form-control form-control-sm">
      </div>
      <textarea name="alt_textoextra_${num}" class="form-control form-control-sm" 
                placeholder="(Opcional) Texto complemento..." rows="2"></textarea>
    `;
  }

  else if(tipo==='video'){
    cont.innerHTML = `
      <div class="mb-2">
        <input type="url" name="alt_video_${num}" class="form-control form-control-sm" placeholder="https://...">
      </div>
      <textarea name="alt_textoextra_${num}" class="form-control form-control-sm" 
                placeholder="(Opcional) Texto complemento..." rows="2"></textarea>
    `;
  }
}

</script>
</body>
</html>
