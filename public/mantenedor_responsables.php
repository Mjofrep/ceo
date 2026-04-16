<?php
declare(strict_types=1);

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

function nombreCompletoResponsable(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string)($row['nombre'] ?? '')),
        trim((string)($row['apellidop'] ?? '')),
        trim((string)($row['apellidom'] ?? '')),
    ], static fn ($v) => $v !== '')));
}

function syncResponsableHse(PDO $pdo, int $id, array $data, int $tipoAnterior, int $tipoNuevo): void
{
    $nombreCompleto = nombreCompletoResponsable($data);

    if ($tipoNuevo === 1) {
        if ($tipoAnterior === 1) {
            $stmt = $pdo->prepare('UPDATE ceo_responsablehse SET nombre = :nombre WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':id' => $id,
                ':nombre' => $nombreCompleto,
            ]);

            if ($stmt->rowCount() === 0) {
                $stmtInsert = $pdo->prepare('INSERT INTO ceo_responsablehse (id, nombre) VALUES (:id, :nombre)');
                $stmtInsert->execute([
                    ':id' => $id,
                    ':nombre' => $nombreCompleto,
                ]);
            }

            return;
        }

        $stmtInsert = $pdo->prepare('INSERT INTO ceo_responsablehse (id, nombre) VALUES (:id, :nombre)');
        $stmtInsert->execute([
            ':id' => $id,
            ':nombre' => $nombreCompleto,
        ]);
        return;
    }

    if ($tipoAnterior === 1 && $tipoNuevo !== 1) {
        $stmtDelete = $pdo->prepare('DELETE FROM ceo_responsablehse WHERE id = :id LIMIT 1');
        $stmtDelete->execute([':id' => $id]);
    }
}

function tipoLabel(int $tipo): string
{
    return $tipo === 1 ? 'Responsable HSE' : 'Responsable Línea';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $data = [
                'correo' => trim((string)($_POST['correo'] ?? '')),
                'rut' => trim((string)($_POST['rut'] ?? '')),
                'nombre' => trim((string)($_POST['nombre'] ?? '')),
                'apellidop' => trim((string)($_POST['apellidop'] ?? '')),
                'apellidom' => trim((string)($_POST['apellidom'] ?? '')),
                'tipo' => (int)($_POST['tipo'] ?? 0),
            ];

            if ($data['rut'] === '' || $data['nombre'] === '' || !in_array($data['tipo'], [1, 2], true)) {
                throw new RuntimeException('Complete Rut, Nombre y Tipo.');
            }

            $stmtExiste = $pdo->prepare('SELECT id FROM ceo_evaluador WHERE rut = :rut LIMIT 1');
            $stmtExiste->execute([':rut' => $data['rut']]);
            if ($stmtExiste->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Ya existe un responsable con ese Rut.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                INSERT INTO ceo_evaluador (correo, rut, nombre, apellidop, apellidom, tipo)
                VALUES (:correo, :rut, :nombre, :apellidop, :apellidom, :tipo)
            ');
            $stmt->execute([
                ':correo' => $data['correo'] !== '' ? $data['correo'] : null,
                ':rut' => $data['rut'],
                ':nombre' => $data['nombre'],
                ':apellidop' => $data['apellidop'] !== '' ? $data['apellidop'] : null,
                ':apellidom' => $data['apellidom'] !== '' ? $data['apellidom'] : null,
                ':tipo' => $data['tipo'],
            ]);

            $idNuevo = (int)$pdo->lastInsertId();
            syncResponsableHse($pdo, $idNuevo, $data, 0, $data['tipo']);

            $pdo->commit();
            $msg = "<div class='alert alert-success mt-3'>Responsable registrado correctamente.</div>";
        }

        if ($action === 'update') {
            $id = (int)($_POST['id_edit'] ?? 0);
            $data = [
                'correo' => trim((string)($_POST['correo_edit'] ?? '')),
                'rut' => trim((string)($_POST['rut_edit'] ?? '')),
                'nombre' => trim((string)($_POST['nombre_edit'] ?? '')),
                'apellidop' => trim((string)($_POST['apellidop_edit'] ?? '')),
                'apellidom' => trim((string)($_POST['apellidom_edit'] ?? '')),
                'tipo' => (int)($_POST['tipo_edit'] ?? 0),
            ];

            if ($id <= 0 || $data['rut'] === '' || $data['nombre'] === '' || !in_array($data['tipo'], [1, 2], true)) {
                throw new RuntimeException('Complete Rut, Nombre y Tipo para editar.');
            }

            $stmtPrev = $pdo->prepare('SELECT id, tipo FROM ceo_evaluador WHERE id = :id LIMIT 1');
            $stmtPrev->execute([':id' => $id]);
            $previo = $stmtPrev->fetch(PDO::FETCH_ASSOC);
            if (!$previo) {
                throw new RuntimeException('No se encontró el responsable a editar.');
            }

            $stmtExiste = $pdo->prepare('SELECT id FROM ceo_evaluador WHERE rut = :rut AND id <> :id LIMIT 1');
            $stmtExiste->execute([
                ':rut' => $data['rut'],
                ':id' => $id,
            ]);
            if ($stmtExiste->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Ya existe otro responsable con ese Rut.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                UPDATE ceo_evaluador
                SET correo = :correo,
                    rut = :rut,
                    nombre = :nombre,
                    apellidop = :apellidop,
                    apellidom = :apellidom,
                    tipo = :tipo
                WHERE id = :id
                LIMIT 1
            ');
            $stmt->execute([
                ':correo' => $data['correo'] !== '' ? $data['correo'] : null,
                ':rut' => $data['rut'],
                ':nombre' => $data['nombre'],
                ':apellidop' => $data['apellidop'] !== '' ? $data['apellidop'] : null,
                ':apellidom' => $data['apellidom'] !== '' ? $data['apellidom'] : null,
                ':tipo' => $data['tipo'],
                ':id' => $id,
            ]);

            syncResponsableHse($pdo, $id, $data, (int)$previo['tipo'], $data['tipo']);

            $pdo->commit();
            $msg = "<div class='alert alert-success mt-3'>Responsable actualizado correctamente.</div>";
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id_delete'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Registro inválido para eliminar.');
            }

            $stmtPrev = $pdo->prepare('SELECT id, tipo FROM ceo_evaluador WHERE id = :id LIMIT 1');
            $stmtPrev->execute([':id' => $id]);
            $previo = $stmtPrev->fetch(PDO::FETCH_ASSOC);
            if (!$previo) {
                throw new RuntimeException('No se encontró el responsable a eliminar.');
            }

            $pdo->beginTransaction();

            if ((int)$previo['tipo'] === 1) {
                $stmtHse = $pdo->prepare('DELETE FROM ceo_responsablehse WHERE id = :id LIMIT 1');
                $stmtHse->execute([':id' => $id]);
            }

            $stmt = $pdo->prepare('DELETE FROM ceo_evaluador WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);

            $pdo->commit();
            $msg = "<div class='alert alert-info mt-3'>Responsable eliminado correctamente.</div>";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = "<div class='alert alert-danger mt-3'>" . esc($e->getMessage()) . "</div>";
    }
}

$rows = $pdo->query('
    SELECT id, correo, rut, nombre, apellidop, apellidom, tipo
    FROM ceo_evaluador
    WHERE tipo IN (1, 2)
    ORDER BY tipo ASC, nombre ASC, apellidop ASC, apellidom ASC, id ASC
')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mantenedor Responsables | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f7f9fc; font-size:0.95rem; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.card { border:none; box-shadow:0 2px 4px rgba(0,0,0,.05); }
.table-sm>tbody>tr>td, .table-sm>thead>tr>th { padding:0.45rem 0.55rem; }
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="fw-bold"><?= esc(APP_NAME) ?></div>
        <small class="text-muted"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="text-primary mb-0"><i class="bi bi-people me-2"></i>Mantenedor de Responsables</h5>
  </div>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
          <label class="form-label">Rut</label>
          <input type="text" name="rut" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Correo</label>
          <input type="email" name="correo" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Nombre</label>
          <input type="text" name="nombre" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Apellido P.</label>
          <input type="text" name="apellidop" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Apellido M.</label>
          <input type="text" name="apellidom" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <option value="1">Responsable HSE</option>
            <option value="2">Responsable Línea</option>
          </select>
        </div>
        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success"><i class="bi bi-save me-2"></i>Guardar</button>
        </div>
      </form>
      <?= $msg ?>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:70px;">ID</th>
              <th>Rut</th>
              <th>Correo</th>
              <th>Nombre</th>
              <th>Apellido P.</th>
              <th>Apellido M.</th>
              <th>Tipo</th>
              <th class="text-center" style="width:140px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="8" class="text-center text-muted">No hay responsables registrados.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $row): ?>
                <tr
                  data-id="<?= (int)$row['id'] ?>"
                  data-rut="<?= esc((string)$row['rut']) ?>"
                  data-correo="<?= esc((string)($row['correo'] ?? '')) ?>"
                  data-nombre="<?= esc((string)$row['nombre']) ?>"
                  data-apellidop="<?= esc((string)($row['apellidop'] ?? '')) ?>"
                  data-apellidom="<?= esc((string)($row['apellidom'] ?? '')) ?>"
                  data-tipo="<?= (int)$row['tipo'] ?>">
                  <td><?= (int)$row['id'] ?></td>
                  <td><?= esc((string)$row['rut']) ?></td>
                  <td><?= esc((string)($row['correo'] ?? '')) ?></td>
                  <td><?= esc((string)$row['nombre']) ?></td>
                  <td><?= esc((string)($row['apellidop'] ?? '')) ?></td>
                  <td><?= esc((string)($row['apellidom'] ?? '')) ?></td>
                  <td><?= esc(tipoLabel((int)$row['tipo'])) ?></td>
                  <td class="text-center">
                    <button type="button" class="btn btn-outline-primary btn-sm btn-edit me-1" title="Editar">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm btn-del" title="Eliminar">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id_edit" id="id_edit">
      <div class="modal-header">
        <h5 class="modal-title">Editar Responsable</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Rut</label>
            <input type="text" name="rut_edit" id="rut_edit" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Correo</label>
            <input type="email" name="correo_edit" id="correo_edit" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tipo</label>
            <select name="tipo_edit" id="tipo_edit" class="form-select" required>
              <option value="1">Responsable HSE</option>
              <option value="2">Responsable Línea</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Nombre</label>
            <input type="text" name="nombre_edit" id="nombre_edit" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Apellido P.</label>
            <input type="text" name="apellidop_edit" id="apellidop_edit" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Apellido M.</label>
            <input type="text" name="apellidom_edit" id="apellidom_edit" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id_delete" id="id_delete">
      <div class="modal-header">
        <h5 class="modal-title">Eliminar Responsable</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Esta acción eliminará físicamente el registro. Si es tipo HSE, también se eliminará de `ceo_responsablehse`.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEdit = new bootstrap.Modal(document.getElementById('modalEdit'));
  const modalDelete = new bootstrap.Modal(document.getElementById('modalDelete'));

  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', () => {
      const tr = btn.closest('tr');
      document.getElementById('id_edit').value = tr.dataset.id || '';
      document.getElementById('rut_edit').value = tr.dataset.rut || '';
      document.getElementById('correo_edit').value = tr.dataset.correo || '';
      document.getElementById('nombre_edit').value = tr.dataset.nombre || '';
      document.getElementById('apellidop_edit').value = tr.dataset.apellidop || '';
      document.getElementById('apellidom_edit').value = tr.dataset.apellidom || '';
      document.getElementById('tipo_edit').value = tr.dataset.tipo || '2';
      modalEdit.show();
    });
  });

  document.querySelectorAll('.btn-del').forEach(btn => {
    btn.addEventListener('click', () => {
      const tr = btn.closest('tr');
      document.getElementById('id_delete').value = tr.dataset.id || '';
      modalDelete.show();
    });
  });
});
</script>
</body>
</html>
