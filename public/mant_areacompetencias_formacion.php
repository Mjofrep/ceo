<?php
// ============================================================
// /public/mant_areacompetencias_formacion.php
// CRUD Areas de Competencia - Formacion
// ============================================================
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/public/index.php');
    exit;
}

$pdo = db();
$msg = '';

/* ============================================================
   CARGA SERVICIOS DE FORMACION
   ============================================================ */
$stmtSrv = $pdo->query('
    SELECT id, servicio
    FROM ceo_formacion_servicios
    ORDER BY servicio
');
$servicios = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   PORCENTAJES FORMACION (por servicio/area)
   ============================================================ */
$stmtPct = $pdo->query('
    SELECT id_servicio, id_area, porcentaje
    FROM ceo_formacion_areacompetencias_pct
');
$pctRows = $stmtPct->fetchAll(PDO::FETCH_ASSOC);
$pctMap = [];
foreach ($pctRows as $row) {
    $key = (int)$row['id_servicio'] . ':' . (int)$row['id_area'];
    $pctMap[$key] = (float)$row['porcentaje'];
}

/* ============================================================
   CRUD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $id = trim((string)($_POST['id'] ?? ''));
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));
    $idServicio = (int)($_POST['id_servicio'] ?? 0);
    $porcentaje = trim((string)($_POST['porcentaje'] ?? ''));

    if ($accion === 'guardar_pct') {
        $idArea = (int)($_POST['id_area'] ?? 0);
        $idServicioPct = (int)($_POST['id_servicio_pct'] ?? 0);
        $pct = (float)str_replace(',', '.', $porcentaje);

        if ($idArea <= 0 || $idServicioPct <= 0) {
            $msg = '❌ Debes seleccionar un area y servicio validos.';
        } elseif ($pct < 0 || $pct > 100) {
            $msg = '❌ El porcentaje debe estar entre 0 y 100.';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO ceo_formacion_areacompetencias_pct (id_servicio, id_area, porcentaje)
                VALUES (:id_servicio, :id_area, :porcentaje)
                ON DUPLICATE KEY UPDATE porcentaje = VALUES(porcentaje)
            ');
            $stmt->execute([
                ':id_servicio' => $idServicioPct,
                ':id_area' => $idArea,
                ':porcentaje' => $pct,
            ]);
            $msg = '✅ Porcentaje de formacion actualizado.';
        }
    } elseif ($accion === 'crear') {
        if ($id === '' || $descripcion === '' || $idServicio <= 0) {
            $msg = '❌ Todos los campos son obligatorios.';
        } else {
            try {
                $stmt = $pdo->prepare('
                    INSERT INTO ceo_areacompetencia_formacion (id, descripcion, id_servicio)
                    VALUES (:id, :descripcion, :id_servicio)
                ');
                $stmt->execute([
                    ':id' => $id,
                    ':descripcion' => $descripcion,
                    ':id_servicio' => $idServicio,
                ]);
                $msg = '✅ Area de competencia de formacion creada correctamente.';
            } catch (Throwable $e) {
                $msg = '❌ Error: posible ID duplicado.';
            }
        }
    } elseif ($accion === 'editar' && $id !== '') {
        if ($descripcion === '' || $idServicio <= 0) {
            $msg = '❌ Debes completar Descripcion y Servicio.';
        } else {
            $stmt = $pdo->prepare('
                UPDATE ceo_areacompetencia_formacion
                   SET descripcion = :descripcion,
                       id_servicio = :id_servicio
                 WHERE id = :id
            ');
            $stmt->execute([
                ':descripcion' => $descripcion,
                ':id_servicio' => $idServicio,
                ':id' => $id,
            ]);
            $msg = '📝 Area de competencia de formacion actualizada.';
        }
    }
}

/* ============================================================
   LISTADO
   ============================================================ */
$stmt = $pdo->query('
    SELECT ac.id, ac.descripcion, ac.id_servicio, fs.servicio, COUNT(fp.id) AS total_preguntas
    FROM ceo_areacompetencia_formacion ac
    INNER JOIN ceo_formacion_servicios fs ON fs.id = ac.id_servicio
    LEFT JOIN ceo_formacion_preguntas_servicios fp
        ON fp.areacomp = ac.id
       AND fp.id_servicio = ac.id_servicio
       AND fp.estado = "S"
    GROUP BY ac.id, ac.descripcion, ac.id_servicio, fs.servicio
    ORDER BY fs.servicio, ac.id
');
$areas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Areas de Competencia Formacion</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#f9fbff; font-family:Segoe UI, Roboto, sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,.12); box-shadow:0 1px 4px rgba(0,0,0,.04); }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,.05); }
table th, table td { vertical-align: middle; }
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="text-primary fw-bold"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container">

<?php if ($msg): ?>
<div class="alert alert-info text-center"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
<?php endif; ?>

<!-- FORMULARIO -->
<div class="card p-4 mb-4">
  <h4 class="mb-3">Agregar / Editar Area de Competencia Formacion</h4>

  <form method="post" id="frmArea" class="row g-3">
    <input type="hidden" name="accion" id="accion" value="crear">

    <div class="col-md-2">
      <label class="form-label">ID</label>
      <input type="text" name="id" id="id" class="form-control" required>
      <small class="text-muted">No autoincremental</small>
    </div>

    <div class="col-md-6">
      <label class="form-label">Descripcion</label>
      <input type="text" name="descripcion" id="descripcion" class="form-control" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">Servicio Formacion</label>
      <select name="id_servicio" id="id_servicio" class="form-select" required>
        <option value="">Seleccione...</option>
        <?php foreach ($servicios as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars((string)$s['servicio'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-12 text-end mt-3">
      <button type="submit" class="btn btn-primary" id="btnGuardar">Guardar</button>
      <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
    </div>
  </form>
</div>

<!-- LISTADO -->
<div class="card p-4">
  <h4 class="mb-3">Areas de Competencia de Formacion Registradas</h4>

  <div class="row mb-3">
    <div class="col-md-4 ms-auto">
      <input type="text"
             id="buscarArea"
             class="form-control form-control-sm"
             placeholder="🔍 Buscar area...">
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle" id="tablaAreas">
      <thead class="table-primary">
        <tr>
          <th>ID</th>
          <th>Descripcion</th>
          <th>Servicio Formacion</th>
          <th>Total Preguntas</th>
          <th>Pct Formacion</th>
          <th>Accion</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($areas as $a): ?>
        <tr>
          <td><?= htmlspecialchars((string)$a['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$a['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$a['servicio'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td><?= (int)($a['total_preguntas'] ?? 0) ?></td>
          <td>
            <?php $pctKey = (int)$a['id_servicio'] . ':' . (int)$a['id']; ?>
            <form method="post" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="accion" value="guardar_pct">
              <input type="hidden" name="id_area" value="<?= (int)$a['id'] ?>">
              <input type="hidden" name="id_servicio_pct" value="<?= (int)$a['id_servicio'] ?>">
              <input type="number" step="0.01" min="0" max="100" name="porcentaje"
                     class="form-control form-control-sm" style="max-width:110px"
                     value="<?= isset($pctMap[$pctKey]) ? htmlspecialchars((string)$pctMap[$pctKey], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>"
                     placeholder="%">
              <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
            </form>
          </td>
          <td>
            <button class="btn btn-sm btn-info btnEditar"
              data-id="<?= htmlspecialchars((string)$a['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-descripcion="<?= htmlspecialchars((string)$a['descripcion'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-idservicio="<?= (int)$a['id_servicio'] ?>">
              Editar
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ==============================
// EDITAR
// ==============================
document.querySelectorAll('.btnEditar').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('id').value = btn.dataset.id;
    document.getElementById('descripcion').value = btn.dataset.descripcion;
    document.getElementById('id_servicio').value = btn.dataset.idservicio;

    document.getElementById('id').readOnly = true;
    document.getElementById('accion').value = 'editar';
    document.getElementById('btnGuardar').textContent = 'Actualizar';
    document.getElementById('btnCancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});

// ==============================
// CANCELAR
// ==============================
document.getElementById('btnCancelar').addEventListener('click', () => {
  document.getElementById('frmArea').reset();
  document.getElementById('id').readOnly = false;
  document.getElementById('accion').value = 'crear';
  document.getElementById('btnGuardar').textContent = 'Guardar';
  document.getElementById('btnCancelar').classList.add('d-none');
});

// ==============================
// BUSCADOR GENERAL
// ==============================
document.getElementById('buscarArea').addEventListener('keyup', function () {
  const texto = this.value.toLowerCase();
  document.querySelectorAll('#tablaAreas tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(texto) ? '' : 'none';
  });
});
</script>

</body>
</html>
