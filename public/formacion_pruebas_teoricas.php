<?php
// --------------------------------------------------------------
// formacion_pruebas_teoricas.php - Centro de Excelencia Operacional (CEO)
// Registro, edición y eliminación de agrupaciones teóricas
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
asegurarColumnaPorcentajeFormacionAgrupacion($pdo);
$msg = "";

function formEnsureAgrupacionOriginTable(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_agrupacion_origen (
    id INT NOT NULL AUTO_INCREMENT,
    destino ENUM('HABILITACION','FORMACION') NOT NULL,
    id_agrupacion INT NOT NULL,
    origen VARCHAR(40) NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gp_agrupacion_origen (destino, id_agrupacion)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function formSetAgrupacionOrigin(PDO $pdo, int $idAgrupacion, string $origen): void {
  if ($idAgrupacion <= 0 || trim($origen) === '') {
    return;
  }
  formEnsureAgrupacionOriginTable($pdo);
  $stmt = $pdo->prepare("INSERT INTO ceo_gp_agrupacion_origen (destino, id_agrupacion, origen) VALUES ('FORMACION', :id_agrupacion, :origen) ON DUPLICATE KEY UPDATE origen = origen");
  $stmt->execute([':id_agrupacion' => $idAgrupacion, ':origen' => $origen]);
}

formEnsureAgrupacionOriginTable($pdo);

/* ===============================================================
   ACCIONES CRUD
   =============================================================== */
$action = $_POST['action'] ?? '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
      $titulo = trim($_POST['titulo'] ?? '');
      $id_servicio = (int)($_POST['id_servicio'] ?? 0);
      $id_agrupacion_base = (int)($_POST['id_agrupacion_base'] ?? 0);
      $porcentaje = isset($_POST['porcentaje']) ? (float)$_POST['porcentaje'] : 80.0;
      if ($porcentaje <= 0 || $porcentaje > 100) {
        throw new RuntimeException('El porcentaje mínimo debe ser mayor que 0 y menor o igual que 100.');
      }
      if ($titulo !== '' && $id_servicio > 0) {
        $pdo->beginTransaction();

        $agrupacionBase = null;
        if ($id_agrupacion_base > 0) {
          $stmtBase = $pdo->prepare("SELECT id, id_servicio, tiempo, cantidad, total, porcentaje FROM ceo_formacion_agrupacion WHERE id = :id LIMIT 1");
          $stmtBase->execute([':id' => $id_agrupacion_base]);
          $agrupacionBase = $stmtBase->fetch(PDO::FETCH_ASSOC);

          if (!$agrupacionBase) {
            throw new RuntimeException('La prueba base seleccionada no existe.');
          }

          if ((int)$agrupacionBase['id_servicio'] !== $id_servicio) {
            throw new RuntimeException('La prueba base debe pertenecer al mismo servicio seleccionado.');
          }
        }

        if ($agrupacionBase) {
          $stmt = $pdo->prepare("INSERT INTO ceo_formacion_agrupacion (titulo, id_servicio, tiempo, cantidad, total, porcentaje) VALUES (:titulo, :id_servicio, :tiempo, :cantidad, :total, :porcentaje)");
          $stmt->execute([
            ':titulo' => $titulo,
            ':id_servicio' => $id_servicio,
            ':tiempo' => $agrupacionBase['tiempo'],
            ':cantidad' => $agrupacionBase['cantidad'],
            ':total' => $agrupacionBase['total'],
            ':porcentaje' => $porcentaje,
          ]);
        } else {
          $stmt = $pdo->prepare("INSERT INTO ceo_formacion_agrupacion (titulo, id_servicio, porcentaje) VALUES (:titulo, :id_servicio, :porcentaje)");
          $stmt->execute([':titulo'=>$titulo, ':id_servicio'=>$id_servicio, ':porcentaje' => $porcentaje]);
        }
        $idNuevaAgrupacion = (int)$pdo->lastInsertId();
        formSetAgrupacionOrigin($pdo, $idNuevaAgrupacion, 'FORMACION_PRUEBAS_TEORICAS');

        if ($id_agrupacion_base > 0) {
          $stmtPreguntas = $pdo->prepare("
            SELECT id, pregunta, id_servicio, imagen, estado, retropos, retroneg, areacomp, peso, tipo_pregunta, obligatoria
            FROM ceo_formacion_preguntas_servicios
            WHERE id_agrupacion = :id_agrupacion
            ORDER BY id ASC
          ");
          $stmtPreguntas->execute([':id_agrupacion' => $id_agrupacion_base]);
          $preguntasBase = $stmtPreguntas->fetchAll(PDO::FETCH_ASSOC);

          $stmtInsertPregunta = $pdo->prepare("
            INSERT INTO ceo_formacion_preguntas_servicios
              (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp, peso, tipo_pregunta, obligatoria)
            VALUES
              (:pregunta, :id_servicio, :imagen, :estado, :id_agrupacion, :retropos, :retroneg, :areacomp, :peso, :tipo_pregunta, :obligatoria)
          ");

          $stmtAlternativas = $pdo->prepare("
            SELECT alternativa, correcta, estado, imagen
            FROM ceo_formacion_alternativas_preguntas
            WHERE id_pregunta = :id_pregunta
            ORDER BY id ASC
          ");

          $stmtInsertAlternativa = $pdo->prepare("
            INSERT INTO ceo_formacion_alternativas_preguntas
              (alternativa, correcta, estado, id_pregunta, imagen)
            VALUES
              (:alternativa, :correcta, :estado, :id_pregunta, :imagen)
          ");

          foreach ($preguntasBase as $preguntaBase) {
            $stmtInsertPregunta->execute([
              ':pregunta' => $preguntaBase['pregunta'],
              ':id_servicio' => $id_servicio,
              ':imagen' => $preguntaBase['imagen'],
              ':estado' => $preguntaBase['estado'],
              ':id_agrupacion' => $idNuevaAgrupacion,
              ':retropos' => $preguntaBase['retropos'],
              ':retroneg' => $preguntaBase['retroneg'],
              ':areacomp' => $preguntaBase['areacomp'],
              ':peso' => $preguntaBase['peso'],
              ':tipo_pregunta' => $preguntaBase['tipo_pregunta'],
              ':obligatoria' => $preguntaBase['obligatoria']
            ]);

            $idNuevaPregunta = (int)$pdo->lastInsertId();

            $stmtAlternativas->execute([':id_pregunta' => (int)$preguntaBase['id']]);
            $alternativasBase = $stmtAlternativas->fetchAll(PDO::FETCH_ASSOC);

            foreach ($alternativasBase as $alternativaBase) {
              $stmtInsertAlternativa->execute([
                ':alternativa' => $alternativaBase['alternativa'],
                ':correcta' => $alternativaBase['correcta'],
                ':estado' => $alternativaBase['estado'],
                ':id_pregunta' => $idNuevaPregunta,
                ':imagen' => $alternativaBase['imagen']
              ]);
            }
          }
        }

        $pdo->commit();
        $msg = $id_agrupacion_base > 0
          ? "<div class='alert alert-success mt-3'>✅ Agrupación registrada correctamente a partir de la prueba base seleccionada.</div>"
          : "<div class='alert alert-success mt-3'>✅ Agrupación registrada correctamente.</div>";
      } else {
        $msg = "<div class='alert alert-warning mt-3'>⚠️ Complete los campos requeridos.</div>";
      }
    }

    if ($action === 'update') {
      $id = (int)($_POST['id_edit'] ?? 0);
      $titulo = trim($_POST['titulo_edit'] ?? '');
      $id_servicio = (int)($_POST['id_servicio_edit'] ?? 0);
      $porcentaje = isset($_POST['porcentaje_edit']) ? (float)$_POST['porcentaje_edit'] : 80.0;
      if ($porcentaje <= 0 || $porcentaje > 100) {
        throw new RuntimeException('El porcentaje mínimo debe ser mayor que 0 y menor o igual que 100.');
      }
      if ($id > 0 && $titulo !== '' && $id_servicio > 0) {
        $stmt = $pdo->prepare("UPDATE ceo_formacion_agrupacion SET titulo=:titulo, id_servicio=:id_servicio, porcentaje=:porcentaje WHERE id=:id");
        $stmt->execute([':titulo'=>$titulo, ':id_servicio'=>$id_servicio, ':porcentaje' => $porcentaje, ':id'=>$id]);
        $msg = "<div class='alert alert-success mt-3'>✏️ Agrupación actualizada correctamente.</div>";
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id_delete'] ?? 0);
      if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM ceo_formacion_agrupacion WHERE id=:id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $msg = "<div class='alert alert-info mt-3'>🗑️ Agrupación eliminada.</div>";
      }
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  $msg = "<div class='alert alert-danger mt-3'>❌ Error: ".htmlspecialchars($e->getMessage())."</div>";
}

/* ===============================================================
   CONSULTAS BASE
   =============================================================== */
$servicios = $pdo->query("SELECT id, servicio FROM ceo_formacion_servicios  ")->fetchAll(PDO::FETCH_ASSOC);
$agrup = $pdo->query("
  SELECT a.id, a.titulo, a.id_servicio, a.porcentaje, s.servicio
  FROM ceo_formacion_agrupacion a
  JOIN ceo_formacion_servicios s ON s.id = a.id_servicio
  LEFT JOIN ceo_gp_agrupacion_origen go ON go.destino = 'FORMACION' AND go.id_agrupacion = a.id
  LEFT JOIN (
    SELECT id_agrupacion, COUNT(*) AS total
    FROM ceo_formacion_preguntas_servicios
    GROUP BY id_agrupacion
  ) qp ON qp.id_agrupacion = a.id
  WHERE COALESCE(qp.total, 0) > 0 OR go.origen = 'FORMACION_PRUEBAS_TEORICAS'
  ORDER BY a.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

function short_clean(string $html, int $len=120): string {
  $txt = trim(strip_tags($html));
  return (mb_strlen($txt) > $len) ? mb_substr($txt,0,$len).'…' : $txt;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Pruebas Teoricas | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.ckeditor.com/4.25.1/standard/ckeditor.js"></script>
<style>
body {background:#f7f9fc; font-size:0.9rem;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600; font-size:1.1rem;}
.card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
.table-sm>tbody>tr>td, .table-sm>thead>tr>th {padding:0.35rem 0.5rem;}
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
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <h5 class="text-primary mb-3"><i class="bi bi-journal-text me-2"></i>Registro de Agrupaciones Teoricas - Formaciones</h5>

  <!-- Formulario -->
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form id="form-create" method="POST" class="row g-3">
        <input type="hidden" name="action" value="create">
        <div class="col-12">
          <label class="form-label">Título de la Agrupación</label>
          <textarea name="titulo" id="titulo" class="form-control" rows="4" required></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Servicio Asociado</label>
          <select name="id_servicio" id="id_servicio_create" class="form-select" required>
            <option value="">-- Seleccione un servicio --</option>
            <?php foreach ($servicios as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Prueba base</label>
          <select name="id_agrupacion_base" id="id_agrupacion_base" class="form-select" disabled>
            <option value="">Seleccione primero un servicio</option>
            <?php foreach ($agrup as $a): ?>
              <option value="<?= $a['id'] ?>" data-servicio="<?= $a['id_servicio'] ?>" data-template="1" data-porcentaje="<?= htmlspecialchars((string)($a['porcentaje'] ?? 80)) ?>">
                <?= htmlspecialchars($a['id'] . ' - ' . short_clean($a['titulo'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Porcentaje mínimo</label>
          <input type="number" name="porcentaje" id="porcentaje_create" class="form-control" min="1" max="100" step="0.01" value="80" required>
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success px-4"><i class="bi bi-save me-2"></i>Guardar</button>
        </div>
      </form>
      <?= $msg ?>
    </div>
  </div>

  <!-- Listado -->
  <div class="card rounded-4">
    <div class="card-body">
      <h6 class="text-primary mb-3"><i class="bi bi-table me-2"></i>Agrupaciones registradas</h6>
      <div class="table-responsive">
 <table class="table table-bordered table-sm align-middle">
  <thead class="table-light">
    <tr>
      <th style="width:80px;">ID</th>
      <th>Título</th>
      <th>Servicio</th>
      <th>% Mínimo</th>
      <th class="text-center" style="width:150px;">Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($agrup)): ?>
      <tr>
        <td colspan="5" class="text-center text-muted">No hay registros</td>
      </tr>
    <?php else: foreach ($agrup as $a): ?>
      <tr data-id="<?= $a['id'] ?>" data-titulo="<?= htmlspecialchars($a['titulo']) ?>" data-servicio="<?= $a['id_servicio'] ?>" data-porcentaje="<?= htmlspecialchars((string)($a['porcentaje'] ?? 80)) ?>">
        <td><?= $a['id'] ?></td>
        <td><?= htmlspecialchars(short_clean($a['titulo'])) ?></td>
        <td><?= htmlspecialchars($a['servicio']) ?></td>
        <td><?= number_format((float)($a['porcentaje'] ?? 80), 2, '.', '') ?>%</td>
        <td class="text-center">
          <!-- Crear preguntas -->
          <a href="formacion_pruebas_teoricas_preguntas.php?id_agrupacion=<?= $a['id'] ?>"
             class="btn btn-outline-success btn-sm me-1"
             title="Crear preguntas asociadas">
             <i class="bi bi-question-circle"></i>
          </a>
          <!-- Editar -->
          <button type="button" class="btn btn-outline-primary btn-sm btn-edit me-1" title="Editar">
            <i class="bi bi-pencil-square"></i>
          </button>

          <!-- Eliminar -->
          <button type="button" class="btn btn-outline-danger btn-sm btn-del" title="Eliminar">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

      </div>
    </div>
  </div>
</div>

<!-- Modal Edición -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="form-edit">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id_edit" id="id_edit">
        <div class="modal-header">
          <h6 class="modal-title">Editar Agrupación</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Título</label>
          <textarea name="titulo_edit" id="titulo_edit" class="form-control" rows="5" required></textarea>
          <label class="form-label mt-3">Servicio</label>
          <select name="id_servicio_edit" id="id_servicio_edit" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($servicios as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label mt-3">Porcentaje mínimo</label>
          <input type="number" name="porcentaje_edit" id="porcentaje_edit" class="form-control" min="1" max="100" step="0.01" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Borrado -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id_delete" id="id_delete">
        <div class="modal-header">
          <h6 class="modal-title text-danger">Confirmar eliminación</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>¿Desea eliminar la agrupación seleccionada?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
CKEDITOR.replace('titulo', { height: 120 });
let editorEdit = null;
let modalEdit = new bootstrap.Modal(document.getElementById('modalEdit'));
let modalDelete = new bootstrap.Modal(document.getElementById('modalDelete'));

// Actualizar textarea antes de submit (alta)
document.getElementById('form-create').addEventListener('submit', e => {
  CKEDITOR.instances.titulo.updateElement();
});


// Editar
document.querySelectorAll('.btn-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    const tr = btn.closest('tr');
    document.getElementById('id_edit').value = tr.dataset.id;
    document.getElementById('id_servicio_edit').value = tr.dataset.servicio;
    document.getElementById('porcentaje_edit').value = tr.dataset.porcentaje || '80';
    const contenido = tr.dataset.titulo;
    document.getElementById('titulo_edit').value = contenido;
    modalEdit.show();
    setTimeout(() => {
      if (!editorEdit) {
        editorEdit = CKEDITOR.replace('titulo_edit', { height: 160 });
      }
      editorEdit.setData(contenido);
    }, 200);
  });
});

// Sincronizar editor al guardar
document.getElementById('form-edit').addEventListener('submit', () => {
  if (editorEdit) editorEdit.updateElement();
});

// Borrar
document.querySelectorAll('.btn-del').forEach(btn => {
  btn.addEventListener('click', () => {
    const tr = btn.closest('tr');
    document.getElementById('id_delete').value = tr.dataset.id;
    modalDelete.show();
  });
});
</script>
<script>
// =============== PARCHE NO-INTRUSIVO (append-only) ==================
// 1) Delegación de eventos (funciona aunque la tabla se regenere)
// 2) Decodifica entidades HTML guardadas en data-* para CKEditor
// 3) Inicializa modales de forma defensiva
(function(){
  // --- util: decodificar &lt; &gt; &amp; &quot; de data-titulo ---
  function decodeHTMLEntities(str){
    if (!str) return '';
    const txt = document.createElement('textarea');
    txt.innerHTML = str;
    return txt.value;
  }

  // --- referencias a modales/elementos existentes ---
  const elModalEdit = document.getElementById('modalEdit');
  const elModalDelete = document.getElementById('modalDelete');
  // Bootstrap modal (defensivo por si el bundler no expone window.bootstrap)
  let modalEdit = (window.bootstrap && new bootstrap.Modal(elModalEdit)) || null;
  let modalDelete = (window.bootstrap && new bootstrap.Modal(elModalDelete)) || null;

  // Asegura CKEDITOR del alta vuelque datos al enviar (redundante por robustez)
  const formCreate = document.getElementById('form-create');
  const servicioCreate = document.getElementById('id_servicio_create');
  const pruebaBaseCreate = document.getElementById('id_agrupacion_base');
  const porcentajeCreate = document.getElementById('porcentaje_create');
  const pruebaBaseTemplates = pruebaBaseCreate
    ? Array.from(pruebaBaseCreate.querySelectorAll('option[data-template="1"]')).map(option => ({
        value: option.value,
        servicio: option.dataset.servicio || '',
        porcentaje: option.dataset.porcentaje || '80',
        text: option.textContent || ''
      }))
    : [];

  function syncPruebaBaseOptions() {
    if (!servicioCreate || !pruebaBaseCreate) return;

    const servicioId = servicioCreate.value;
    pruebaBaseCreate.innerHTML = '';

    if (!servicioId) {
      pruebaBaseCreate.disabled = true;
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'Seleccione primero un servicio';
      pruebaBaseCreate.appendChild(option);
      if (porcentajeCreate && (!porcentajeCreate.value || porcentajeCreate.value === '')) {
        porcentajeCreate.value = '80';
      }
      return;
    }

    pruebaBaseCreate.disabled = false;

    const opcionNinguna = document.createElement('option');
    opcionNinguna.value = '0';
    opcionNinguna.textContent = 'Ninguna';
    pruebaBaseCreate.appendChild(opcionNinguna);

    pruebaBaseTemplates
      .filter(option => option.servicio === servicioId)
      .forEach(option => {
        const item = document.createElement('option');
        item.value = option.value;
        item.dataset.porcentaje = option.porcentaje;
        item.textContent = option.text;
        pruebaBaseCreate.appendChild(item);
      });

    if (porcentajeCreate) {
      const optionSeleccionada = pruebaBaseCreate.selectedOptions[0] || null;
      porcentajeCreate.value = (optionSeleccionada && optionSeleccionada.dataset.porcentaje) || '80';
    }
  }

  if (formCreate){
    formCreate.addEventListener('submit', function(){
      if (window.CKEDITOR && CKEDITOR.instances.titulo) {
        CKEDITOR.instances.titulo.updateElement();
      }
    });
  }

  if (servicioCreate && pruebaBaseCreate) {
    servicioCreate.addEventListener('change', syncPruebaBaseOptions);
    pruebaBaseCreate.addEventListener('change', () => {
      const optionSeleccionada = pruebaBaseCreate.selectedOptions[0] || null;
      if (porcentajeCreate) {
        porcentajeCreate.value = (optionSeleccionada && optionSeleccionada.dataset.porcentaje) || '80';
      }
    });
    syncPruebaBaseOptions();
  }

  // Instancia editor de edición al mostrar el modal (1 sola vez)
  let editorEditReady = false;
  elModalEdit.addEventListener('shown.bs.modal', function(){
    if (!editorEditReady && window.CKEDITOR){
      CKEDITOR.replace('titulo_edit', { height: 160 });
      editorEditReady = true;
    }
  });

  // Sincroniza editor de edición al enviar
  const formEdit = document.getElementById('form-edit');
  if (formEdit){
    formEdit.addEventListener('submit', function(){
      if (window.CKEDITOR && CKEDITOR.instances.titulo_edit) {
        CKEDITOR.instances.titulo_edit.updateElement();
      }
    });
  }

  // --- DELEGACIÓN DE CLICS: Editar / Borrar ---
  document.addEventListener('click', function(ev){
    const btnEdit = ev.target.closest('.btn-edit');
    if (btnEdit){
      const tr = btnEdit.closest('tr');
      if (!tr) return;

      // set campos
      const id = tr.dataset.id || '';
      const idServicio = tr.dataset.servicio || '';
      const tituloRaw = decodeHTMLEntities(tr.dataset.titulo || '');

      document.getElementById('id_edit').value = id;
      document.getElementById('id_servicio_edit').value = idServicio;

      // coloca el HTML decodificado en el textarea base
      const ta = document.getElementById('titulo_edit');
      ta.value = tituloRaw;

      // si CKEditor de edición ya existe, setea data
      if (window.CKEDITOR && CKEDITOR.instances.titulo_edit){
        CKEDITOR.instances.titulo_edit.setData(tituloRaw);
      }

      // abre modal (fallback por si window.bootstrap no está)
      if (modalEdit){ modalEdit.show(); }
      else { elModalEdit.classList.add('show'); elModalEdit.style.display='block'; }
      return;
    }

    const btnDel = ev.target.closest('.btn-del');
    if (btnDel){
      const tr = btnDel.closest('tr');
      if (!tr) return;
      document.getElementById('id_delete').value = tr.dataset.id || '';
      if (modalDelete){ modalDelete.show(); }
      else { elModalDelete.classList.add('show'); elModalDelete.style.display='block'; }
      return;
    }
  });

  // --- sanity check opcional: si no existe bootstrap, avisa una sola vez ---
  if (!window.bootstrap){
    console.warn('Bootstrap global no disponible: usando fallback simple para mostrar modales.');
  }
})();



</script>


</body>
</html>
