<?php
// --------------------------------------------------------------
// pruebas_teoricas.php - Centro de Excelencia Operacional (CEO)
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
$msg = "";

/* ===============================================================
   ACCIONES CRUD
   =============================================================== */
$action = $_POST['action'] ?? '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
      $titulo = trim($_POST['titulo'] ?? '');
      $id_servicio = (int)($_POST['id_servicio'] ?? 0);
      if ($titulo !== '' && $id_servicio > 0) {
        $stmt = $pdo->prepare("INSERT INTO ceo_agrupacion (titulo, id_servicio) VALUES (:titulo, :id_servicio)");
        $stmt->execute([':titulo'=>$titulo, ':id_servicio'=>$id_servicio]);
        $msg = "<div class='alert alert-success mt-3'>✅ Agrupación registrada correctamente.</div>";
      } else {
        $msg = "<div class='alert alert-warning mt-3'>⚠️ Complete los campos requeridos.</div>";
      }
    }

    if ($action === 'update') {
      $id = (int)($_POST['id_edit'] ?? 0);
      $titulo = trim($_POST['titulo_edit'] ?? '');
      $id_servicio = (int)($_POST['id_servicio_edit'] ?? 0);
      if ($id > 0 && $titulo !== '' && $id_servicio > 0) {
        $stmt = $pdo->prepare("UPDATE ceo_agrupacion SET titulo=:titulo, id_servicio=:id_servicio WHERE id=:id");
        $stmt->execute([':titulo'=>$titulo, ':id_servicio'=>$id_servicio, ':id'=>$id]);
        $msg = "<div class='alert alert-success mt-3'>✏️ Agrupación actualizada correctamente.</div>";
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id_delete'] ?? 0);
      if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM ceo_agrupacion WHERE id=:id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $msg = "<div class='alert alert-info mt-3'>🗑️ Agrupación eliminada.</div>";
      }
    }
  }
} catch (Throwable $e) {
  $msg = "<div class='alert alert-danger mt-3'>❌ Error: ".htmlspecialchars($e->getMessage())."</div>";
}

/* ===============================================================
   CONSULTAS BASE
   =============================================================== */
$servicios = $pdo->query("SELECT id, servicio FROM ceo_servicios_pruebas  ")->fetchAll(PDO::FETCH_ASSOC);
$agrup = $pdo->query("
  SELECT a.id, a.titulo, a.id_servicio, s.servicio
  FROM ceo_agrupacion a
  JOIN ceo_servicios_pruebas s ON s.id = a.id_servicio
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
<title>Pruebas Teóricas - Agrupaciones | <?= htmlspecialchars(APP_NAME) ?></title>
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
  <h5 class="text-primary mb-3"><i class="bi bi-journal-text me-2"></i>Registro de Agrupaciones Teóricas</h5>

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
          <select name="id_servicio" class="form-select" required>
            <option value="">-- Seleccione un servicio --</option>
            <?php foreach ($servicios as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
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
      <th class="text-center" style="width:150px;">Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($agrup)): ?>
      <tr>
        <td colspan="4" class="text-center text-muted">No hay registros</td>
      </tr>
    <?php else: foreach ($agrup as $a): ?>
      <tr data-id="<?= $a['id'] ?>" data-titulo="<?= htmlspecialchars($a['titulo']) ?>" data-servicio="<?= $a['id_servicio'] ?>">
        <td><?= $a['id'] ?></td>
        <td><?= htmlspecialchars(short_clean($a['titulo'])) ?></td>
        <td><?= htmlspecialchars($a['servicio']) ?></td>
        <td class="text-center">
          <!-- Crear preguntas -->
          <a href="pruebas_teoricas_preguntas.php?id_agrupacion=<?= $a['id'] ?>"
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
  if (formCreate){
    formCreate.addEventListener('submit', function(){
      if (window.CKEDITOR && CKEDITOR.instances.titulo) {
        CKEDITOR.instances.titulo.updateElement();
      }
    });
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

