<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/app.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();

$rolUsuario    = strtolower($_SESSION['auth']['rol'] ?? '');
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');

$rut = trim($_GET['rut'] ?? '');
$rows = [];

if ($rut !== '') {

    /* 🔐 Seguridad: contratista solo ve lo propio */
    if ($esContratista) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM ceo_contratistas
            WHERE rut = :rut AND id_empresa = :empresa
        ");
        $stmt->execute([
            ':rut'     => $rut,
            ':empresa' => $idEmpresaUser
        ]);
        if (!$stmt->fetch()) {
            die('No autorizado para ver este RUT.');
        }
    }

 $stmt = $pdo->prepare("
    SELECT 
        tipo_evaluacion,
        servicio,
        fecha as fecha_hora,

        CASE 
            WHEN tipo_evaluacion COLLATE utf8mb4_general_ci = 'TEORICA' THEN resultado
            WHEN tipo_evaluacion COLLATE utf8mb4_general_ci = 'PRACTICA' THEN 
                CASE 
                    WHEN resultado >= 70 THEN 'APROBADO'
                    ELSE 'REPROBADO'
                END
        END AS resultado_mostrado,

        CASE 
            WHEN tipo_evaluacion COLLATE utf8mb4_general_ci = 'TEORICA' THEN notafinal
            WHEN tipo_evaluacion COLLATE utf8mb4_general_ci = 'PRACTICA' THEN resultado
        END AS nota_mostrada,

        empresa,
        cargo,
        evaluador,
        uo,
        region
    FROM vw_ceo_historial_evaluaciones_persona
    WHERE rut = :rut
    ORDER BY fecha_hora DESC
");
    $stmt->execute([':rut' => $rut]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Historial Evaluaciones | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.table thead th { background:#eaf2fb; }
.search-feedback { font-size:.82rem; color:#dc3545; margin-top:.35rem; display:none; }
.search-results-box { display:none; }
.search-results-box .table td,
.search-results-box .table th { vertical-align:middle; }
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;">
      <div>
        <div class="fw-bold"><?= APP_NAME ?></div>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="https://www.noetica.cl/ceo.noetica.cl/public/general.php"
       class="btn btn-outline-secondary btn-sm">
       ← Volver
    </a>
  </div>
</header>

<div class="container-fluid px-4">

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex justify-content-between align-items-center">
    <h5 class="fw-bold text-primary mb-0">
      <i class="bi bi-person-lines-fill me-2"></i>Historial de Evaluaciones por Persona
    </h5>

    <?php if ($rut): ?>
    <a href="historial_evaluaciones_persona_excel.php?rut=<?= urlencode($rut) ?>"
       class="btn btn-success btn-sm">
       <i class="bi bi-file-earmark-excel"></i> Exportar Excel
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" id="formBusquedaHistorialEvaluaciones" autocomplete="off">
      <div class="col-md-5">
        <input type="hidden" name="rut" id="rutSeleccionadoEvaluaciones" value="<?= esc($rut) ?>">
        <input type="text" id="buscadorAlumnoEvaluaciones" value="<?= esc($rut) ?>" class="form-control" placeholder="Buscar alumno por RUT, nombre o apellido" required>
        <div id="feedbackAlumnoEvaluaciones" class="search-feedback">Seleccione un alumno de la lista.</div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-outline-primary" type="button" id="btnBuscarAlumnoEvaluaciones">
          <i class="bi bi-search"></i> Buscar coincidencias
        </button>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-journal-text"></i> Ver historial
        </button>
      </div>
    </form>
    <div id="resultadosAlumnoEvaluacionesBox" class="search-results-box mt-3"></div>
  </div>
</div>

<?php if ($rut): ?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <?php if (empty($rows)): ?>
        <div class="text-muted">No hay historial de evaluaciones para este RUT.</div>
      <?php else: ?>
        <table class="table table-sm table-hover align-middle">
          <thead class="text-center">
            <tr>
              <th>Tipo</th>
              <th>Servicio</th>
              <th>Fecha</th>
              <th>Resultado</th>
              <th>Nota</th>
              <th>Empresa</th>
              <th>Cargo</th>
              <th>Evaluador</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= esc($r['tipo_evaluacion']) ?></td>
              <td><?= esc($r['servicio']) ?></td>
              <td><?= esc($r['fecha_hora']) ?></td>
              <td><?= esc($r['resultado_mostrado']) ?></td>
              <td><?= esc($r['nota_mostrada']) ?></td>
              <td><?= esc($r['empresa']) ?></td>
              <td><?= esc($r['cargo']) ?></td>
              <td><?= esc($r['evaluador']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div>
<script>
(() => {
  const form = document.getElementById('formBusquedaHistorialEvaluaciones');
  const input = document.getElementById('buscadorAlumnoEvaluaciones');
  const hidden = document.getElementById('rutSeleccionadoEvaluaciones');
  const btnBuscar = document.getElementById('btnBuscarAlumnoEvaluaciones');
  const resultsBox = document.getElementById('resultadosAlumnoEvaluacionesBox');
  const feedback = document.getElementById('feedbackAlumnoEvaluaciones');
  let selectedRut = hidden.value.trim();

  function hideResults() {
    resultsBox.style.display = 'none';
    resultsBox.innerHTML = '';
  }

  function hideFeedback() {
    feedback.style.display = 'none';
  }

  function showFeedback(message) {
    feedback.textContent = message;
    feedback.style.display = 'block';
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderItems(items) {
    if (!items.length) {
      resultsBox.innerHTML = '<div class="alert alert-light border mb-0">No se encontraron alumnos.</div>';
      resultsBox.style.display = 'block';
      return;
    }

    let html = '';
    html += '<div class="small text-muted mb-2">Se encontraron ' + items.length + ' resultado(s).</div>';
    html += '<div class="table-responsive">';
    html += '<table class="table table-sm table-hover align-middle mb-0">';
    html += '<thead class="table-light"><tr>';
    html += '<th>RUT</th><th>Nombre</th><th>Apellido</th><th>Estado</th><th></th>';
    html += '</tr></thead><tbody>';
    items.forEach((item) => {
      const estadoClass = item.tiene_historial ? 'success' : 'secondary';
      html += '<tr>';
      html += '<td>' + escapeHtml(item.rut) + '</td>';
      html += '<td>' + escapeHtml(item.nombre || '') + '</td>';
      html += '<td>' + escapeHtml(item.apellido || '') + '</td>';
      html += '<td><span class="badge text-bg-' + estadoClass + '">' + escapeHtml(item.estado) + '</span></td>';
      html += '<td class="text-end"><button type="button" class="btn btn-primary btn-sm btn-select-resultado" data-rut="' + escapeHtml(item.rut) + '" data-label="' + escapeHtml(item.label) + '">Seleccionar</button></td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    resultsBox.innerHTML = html;
    resultsBox.style.display = 'block';

    resultsBox.querySelectorAll('.btn-select-resultado').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedRut = btn.dataset.rut || '';
        hidden.value = selectedRut;
        input.value = btn.dataset.label || selectedRut;
        hideFeedback();
        form.requestSubmit();
      });
    });
  }

  async function search() {
    const q = input.value.trim();
    if (q.length < 2) {
      hideResults();
      showFeedback('Ingrese al menos 2 caracteres para buscar.');
      return;
    }

    hideFeedback();
    resultsBox.innerHTML = '<div class="text-muted">Buscando alumnos...</div>';
    resultsBox.style.display = 'block';

    try {
      const resp = await fetch(`ajax_buscar_alumno_historial.php?tipo=evaluaciones&q=${encodeURIComponent(q)}`);
      const data = await resp.json();
      if (!data.ok) {
        hideResults();
        showFeedback('No se pudieron cargar las coincidencias.');
        return;
      }
      renderItems(data.items || []);
    } catch (err) {
      hideResults();
      showFeedback('No se pudieron cargar las coincidencias.');
    }
  }

  input.addEventListener('input', () => {
    selectedRut = '';
    hidden.value = '';
    hideFeedback();
    hideResults();
  });

  btnBuscar.addEventListener('click', search);

  form.addEventListener('submit', (e) => {
    const q = input.value.trim();
    if (!q) {
      e.preventDefault();
      showFeedback('Ingrese un RUT, nombre o apellido.');
      return;
    }
    if (!selectedRut || !hidden.value || hidden.value !== selectedRut) {
      e.preventDefault();
      showFeedback('Seleccione un alumno de la lista.');
    }
  });
})();
</script>
</body>
</html>
