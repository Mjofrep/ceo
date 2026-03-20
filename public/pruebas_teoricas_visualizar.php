<?php
// --------------------------------------------------------------
// pruebas_teoricas_visualizar.php - CEO
// Simulación de prueba (solo lectura) con progreso y resumen
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
$idAgrup = (int)($_GET['id_agrupacion'] ?? 0);

// Agrupaciones
$agrupaciones = $pdo->query("
  SELECT a.id, a.titulo, s.servicio 
    FROM ceo_agrupacion a
    JOIN ceo_servicios s ON s.id = a.id_servicio
   ORDER BY a.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$preguntas = [];
$agrup = null;

if ($idAgrup > 0) {
  $stmt = $pdo->prepare("SELECT a.titulo, s.servicio 
                           FROM ceo_agrupacion a
                           JOIN ceo_servicios s ON s.id = a.id_servicio
                          WHERE a.id = :id");
  $stmt->execute([':id' => $idAgrup]);
  $agrup = $stmt->fetch(PDO::FETCH_ASSOC);

   // Traemos preguntas + alternativas en una sola consulta
  $stmt = $pdo->prepare("
    SELECT 
      p.id              AS id_pregunta,
      p.pregunta,
      p.imagen          AS imagen_pregunta,
      a.id              AS id_alternativa,
      a.alternativa,
      a.imagen          AS imagen_alternativa
    FROM ceo_preguntas_servicios p
    LEFT JOIN ceo_alternativas_preguntas a 
           ON a.id_pregunta = p.id 
          AND a.estado = 'S'
    WHERE p.id_agrupacion = :id
    ORDER BY p.id ASC, a.id ASC
  ");
  $stmt->execute([':id' => $idAgrup]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // Armamos estructura: una entrada por pregunta, con sus alternativas como array
  $preguntas = [];
  $map = []; // para indexar por id_pregunta

  foreach ($rows as $r) {
    $pid = (int)$r['id_pregunta'];

    if (!isset($map[$pid])) {
      $map[$pid] = [
        'id'          => $pid,
        'pregunta'    => $r['pregunta'],
        'imagen'      => $r['imagen_pregunta'],
        'alternativas'=> []
      ];
      $preguntas[] = &$map[$pid]; // referencia para ir llenando alternativas
    }

    if (!empty($r['id_alternativa'])) {
      $map[$pid]['alternativas'][] = [
        'id'          => (int)$r['id_alternativa'],
        'alternativa' => $r['alternativa'],
        'imagen'      => $r['imagen_alternativa']
      ];
    }
  }

  // Liberar la referencia
  unset($map);

}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Simulación de Prueba | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{
  background:#f7f9fc;
  font-size:0.9rem;
}

.card{
  border:none;
  box-shadow:0 2px 4px rgba(0,0,0,.05);
}

/* Contenedor indicadores */
.q-indicator{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  margin-bottom:1rem;
}

/* ================================
   CÍRCULOS DE PREGUNTAS
   ================================ */

.btn-pregunta-circle{
  width:36px;
  height:36px;
  border-radius:50%;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:#adb5bd;      /* gris */
  color:#fff;
  font-weight:600;
  font-size:0.9rem;
  border:none;
}

.btn-pregunta-circle.active{
  background:#0d6efd;      /* azul */
}

.btn-pregunta-circle.answered{
  background:#ffc107;      /* amarillo */
  color:#212529;
}




/* ================================
   ALTERNATIVAS
   ================================ */

.alt-block{
  border:1px solid #dee2e6;
  border-radius:6px;
  padding:6px 10px;
  margin-bottom:6px;
  background:#fff;
}

.alt-block:hover{
  background:#f1f4f8;
}

/* Imágenes */
img.q-img{
  max-width:320px;
  border-radius:8px;
  margin:10px 0;
}

/* Área pregunta */
.question-area{
  min-height:220px;
}

/* Barra progreso */
.progress{
  position:relative;
}

.progress small{
  position:absolute;
  left:50%;
  transform:translateX(-50%);
  color:#000;
  font-weight:600;
}
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
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-3">
        <div class="col-md-8">
          <label class="form-label">Seleccione Agrupación</label>
          <select name="id_agrupacion" class="form-select" required>
            <option value="">-- Seleccione una agrupación --</option>
            <?php foreach ($agrupaciones as $a): ?>
              <option value="<?= $a['id'] ?>" <?= ($idAgrup === (int)$a['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['titulo']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 align-self-end">
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-search me-2"></i>Ver prueba</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($idAgrup === 0): ?>
    <div class="alert alert-info">Seleccione una agrupación para visualizar la prueba.</div>
  <?php elseif (empty($preguntas)): ?>
    <div class="alert alert-warning">No hay preguntas registradas para esta agrupación.</div>
  <?php else: ?>
    <div class="card rounded-4 p-3">
      <div class="mb-2">
        <h5 class="text-primary mb-1"><?= htmlspecialchars($agrup['titulo']) ?></h5>
        <small class="text-muted">Servicio: <?= htmlspecialchars($agrup['servicio']) ?></small>
      </div>

      <!-- Progreso -->
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div><strong id="lblProgreso">0/<?= count($preguntas) ?></strong> respondidas</div>
        <div class="flex-grow-1 ms-3 position-relative">
          <div class="progress" style="height:14px;">
            <div id="barProgreso" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
          </div>
        </div>
        <div class="ms-3">
          <button id="btnFinalizar" class="btn btn-outline-success btn-sm"><i class="bi bi-flag-checkered me-1"></i>Finalizar</button>
          <button id="btnReiniciar" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-arrow-counterclockwise me-1"></i>Reiniciar</button>
        </div>
      </div>

      <!-- Semáforo -->
      <div id="indicadores" class="q-indicator text-center"></div>

      <!-- Pregunta -->
      <div id="preguntaContainer" class="question-area"></div>

      <!-- Navegación -->
      <div class="d-flex justify-content-between mt-3">
        <button id="btnAnterior" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Anterior</button>
        <button id="btnSiguiente" class="btn btn-outline-primary btn-sm">Siguiente <i class="bi bi-arrow-right"></i></button>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal resumen -->
<div class="modal fade" id="modalResumen" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h6 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>Resumen de simulación</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Agrupación:</strong> <?= htmlspecialchars($agrup['titulo'] ?? '') ?></p>
        <p id="resumenConteo" class="mb-1">Respondidas: 0 / <?= count($preguntas) ?></p>
        <div class="progress" style="height:14px;">
          <div id="barResumen" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
        </div>
        <hr>
        <p class="text-muted mb-0">* Esta es una vista de simulación para creadores. No se guarda ni califica.</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button id="btnResumenReiniciar" class="btn btn-outline-secondary">Reiniciar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const preguntas = <?= json_encode($preguntas, JSON_UNESCAPED_UNICODE) ?>;
let actual = 0;

// Estado de respuestas
const estado = Array(preguntas.length).fill(false);
const respuestas = Array(preguntas.length).fill(null);

// 🕒 Configuración del temporizador
let tiempoRestante = 10 * 60; // 10 minutos en segundos
let temporizadorActivo = false;
let intervalo = null;

// === Temporizador visual ===
function iniciarTemporizador() {
  if (temporizadorActivo) return;
  temporizadorActivo = true;
  const cont = document.createElement('div');
  cont.className = 'alert alert-light d-flex justify-content-between align-items-center mb-3 border shadow-sm';
  cont.innerHTML = `
    <div><i class="bi bi-stopwatch text-primary me-2"></i>
      <strong>Tiempo restante:</strong> <span id="reloj" class="fw-bold text-success">10:00</span>
    </div>
    <div class="text-muted"><small>El simulador se bloqueará al terminar</small></div>`;
  const container = document.querySelector('.card.rounded-4.p-3');
  if (container) container.prepend(cont);

  intervalo = setInterval(() => {
    if (tiempoRestante <= 0) {
      clearInterval(intervalo);
      finalizarPorTiempo();
      return;
    }
    tiempoRestante--;
    actualizarReloj();
  }, 1000);
}

function actualizarReloj() {
  const min = Math.floor(tiempoRestante / 60);
  const seg = tiempoRestante % 60;
  const reloj = document.getElementById('reloj');
  if (!reloj) return;
  const txt = `${String(min).padStart(2,'0')}:${String(seg).padStart(2,'0')}`;
  reloj.textContent = txt;

  if (tiempoRestante <= 60) reloj.className = 'fw-bold text-danger';
  else if (tiempoRestante <= 120) reloj.className = 'fw-bold text-warning';
  else reloj.className = 'fw-bold text-success';
}

// === Función llamada al finalizar el tiempo ===
function finalizarPorTiempo() {
  const cont = document.getElementById('preguntaContainer');
  cont.innerHTML = `
    <div class="text-center py-5">
      <i class="bi bi-hourglass-split text-danger" style="font-size:3rem;"></i>
      <h5 class="text-danger mt-3">⏰ Tiempo finalizado</h5>
      <p class="text-muted">El tiempo del simulador ha terminado.</p>
    </div>`;
  document.getElementById('btnAnterior').disabled = true;
  document.getElementById('btnSiguiente').disabled = true;
  document.querySelectorAll('.form-check-input').forEach(r=>r.disabled = true);
  if (modalResumen) modalResumen.show();
}

// === Renderizado general ===
function renderIndicadores(){
  const ind = document.getElementById('indicadores');
  if(!ind) return;

  ind.innerHTML = '';

  preguntas.forEach((_, i) => {

    const circ = document.createElement('button');
    circ.type = 'button';

    // clase base
    circ.className = 'btn-pregunta-circle';

    // si está respondida → amarillo
    if (estado[i]) {
      circ.classList.add('answered');
    }

    // si es la actual → azul (PRIORIDAD VISUAL)
    if (i === actual) {
      circ.classList.add('active');
    }

    circ.textContent = i + 1;

    circ.addEventListener('click', () => {
      actual = i;
      renderPregunta();
    });

    ind.appendChild(circ);
  });
}


function renderPregunta(){
  const cont = document.getElementById('preguntaContainer');
  if(!cont || preguntas.length === 0) return;

  const p = preguntas[actual];
  const alts = p.alternativas || [];
  let html = `
    <h6 class="text-primary mb-2">Pregunta ${actual+1} de ${preguntas.length}</h6>
    <div class="mb-3">${p.pregunta ?? ''}</div>
    ${p.imagen ? renderImagen(p.imagen) : ''}
  `;

alts.forEach((a, idx) => {
    const sel = String(respuestas[actual] ?? '');
    const aid = String(a.id);
    const checked = (sel === aid) ? 'checked' : '';

    let texto = a.alternativa?.trim() || "";
    let media = a.imagen?.trim() || "";

    // Detectar si es imagen o video
    let htmlMedia = "";
    if (media !== "") {
        const ext = (media.split('.').pop() || "").toLowerCase();
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            htmlMedia = `<img src="../${media}" class="img-fluid rounded mt-2" style="max-width:150px;">`;
        } else {
            htmlMedia = `<a href="${media}" target="_blank"><i class="bi bi-play-btn-fill me-1"></i> Ver video</a>`;
        }
    }

    // Texto si existe, si no existe texto pero hay imagen/video → mostrar "(Contenido visual)"
    let htmlTexto = texto !== "" ? texto : (htmlMedia !== "" ? "<em>[Contenido visual]</em>" : "<em>[Sin información]</em>");

    html += `
      <div class="alt-block">
        <div class="form-check">
          <input class="form-check-input" type="radio"
                 name="alt_${actual}" id="alt_${actual}_${idx}"
                 value="${aid}" ${checked}>
          <label class="form-check-label" for="alt_${actual}_${idx}">
            ${htmlTexto}
          </label>
        </div>
        ${htmlMedia}
      </div>
    `;
});


  cont.innerHTML = html;
  renderIndicadores();
  updateProgress();
  document.getElementById('btnAnterior').disabled = (actual===0);
  document.getElementById('btnSiguiente').disabled = (actual===preguntas.length-1);
}

function renderImagen(img){
  const ext = (img.split('.').pop()||'').toLowerCase();
  if(['jpg','jpeg','png','gif','webp'].includes(ext))
    return `<img src="../${img}" class="q-img" alt="imagen pregunta">`;
  return `<a href="${img}" target="_blank"><i class="bi bi-play-btn me-1"></i>Ver video</a>`;
}

function renderAltImagen(img){
  const ext = (img.split('.').pop()||'').toLowerCase();
  if(['jpg','jpeg','png','gif','webp'].includes(ext))
    return `<img src="../${img}" class="img-fluid rounded mt-2" style="max-width:150px;">`;
  return `<a href="${img}" target="_blank"><i class="bi bi-play-btn"></i> Ver video</a>`;
}

// Progreso
function updateProgress(){
  const total = preguntas.length;
  const respondidas = estado.filter(v=>v).length;
  const pct = total ? Math.round((respondidas/total)*100) : 0;
  document.getElementById('lblProgreso').textContent = `${respondidas}/${total}`;
  document.getElementById('barProgreso').style.width = pct + '%';
}

// Eventos de navegación
document.addEventListener('click', e=>{
  if(e.target.id==='btnAnterior' && actual>0){ actual--; renderPregunta(); }
  if(e.target.id==='btnSiguiente' && actual<preguntas.length-1){ actual++; renderPregunta(); }
});

// Selección persistente
document.addEventListener('change', e=>{
  if(e.target.matches('.form-check-input')){
    respuestas[actual] = String(e.target.value);
    estado[actual] = true;
    renderIndicadores();
    updateProgress();
  }
});

// Finalizar / Reiniciar manual
const modalResumen = (()=> {
  const mod = document.getElementById('modalResumen');
  return mod ? new bootstrap.Modal(mod) : null;
})();

document.addEventListener('click', e=>{
  if(e.target && e.target.id==='btnFinalizar'){
    e.preventDefault();
    finalizarPorTiempo();
  }
  if(e.target && (e.target.id==='btnReiniciar' || e.target.id==='btnResumenReiniciar')){
    e.preventDefault();
    for(let i=0;i<estado.length;i++){ estado[i]=false; respuestas[i]=null; }
    actual = 0;
    tiempoRestante = 10*60;
    clearInterval(intervalo);
    temporizadorActivo = false;
    document.querySelector('.alert.alert-light')?.remove();
    renderPregunta();
    iniciarTemporizador();
    if(modalResumen) modalResumen.hide();
  }
});

// Cargar primera pregunta y temporizador
window.addEventListener('load', ()=>{
  if(preguntas.length>0){
    actual = 0;
    renderPregunta();
    iniciarTemporizador();
  }
});
</script>




<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
