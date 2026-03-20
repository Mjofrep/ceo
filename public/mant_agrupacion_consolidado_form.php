<?php
// /public/mant_agrupacion_consolidado_form.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

/* ============================================================
   VALIDACIÓN DE SESIÓN
   ============================================================ */
if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();

/* ============================================================
   OBTENER ID (EDICIÓN)
   ============================================================ */
$id = (int)($_GET['id'] ?? 0);
$data = [];

/* ============================================================
   CARGA DE DATOS PARA EDICIÓN
   ============================================================ */
if ($id > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM ceo_agrupacion_consolidado
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/* ============================================================
   COMBOS
   ============================================================ */
$servicios = $pdo->query("
    SELECT id, servicio
    FROM ceo_servicios_pruebas
    ORDER BY servicio
")->fetchAll(PDO::FETCH_ASSOC);

$agrupaciones = $pdo->query("
    SELECT id, titulo
    FROM ceo_agrupacion
    ORDER BY titulo
")->fetchAll(PDO::FETCH_ASSOC);

$agrupacionesTerreno = $pdo->query("
    SELECT id, grupo
    FROM ceo_agrupacion_terreno
    ORDER BY grupo
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Agrupación Consolidada</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#f9fbff; font-family:"Segoe UI", Roboto, sans-serif; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,.05); }
table th, table td { vertical-align: middle; text-align:center; }
th { background:#e9f0ff; }
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

  </div>
</header>
<div class="container mt-4">
<div class="card p-4">

<h4 class="mb-4">
<?= $id > 0 ? 'Editar' : 'Agregar' ?> Agrupación Consolidada
</h4>

<form method="post" action="mant_agrupacion_consolidado_save.php">

<input type="hidden" name="id" value="<?= $id ?>">

<!-- ================= DATOS GENERALES ================= -->
<div class="row mb-3">
  <div class="col-md-6">
    <label class="form-label">Nombre</label>
    <input type="text"
           name="nombre"
           class="form-control"
           value="<?= htmlspecialchars($data['nombre'] ?? '') ?>"
           required>
  </div>

  <div class="col-md-6">
    <label class="form-label">Servicio</label>
    <select name="id_servicio" class="form-select" required>
      <option value="">— Seleccionar —</option>
      <?php foreach ($servicios as $s): ?>
        <option value="<?= $s['id'] ?>"
          <?= (($data['id_servicio'] ?? '') == $s['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($s['servicio']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- ================= TABLA TEÓRICA A–J ================= -->
<h5 class="mt-4">Componentes Teóricos (A – J)</h5>

<table class="table table-bordered">
<thead>
<tr>
<?php foreach (range('A','J') as $letra): ?>
  <th><?= $letra ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<tr>
<?php for ($i = 1; $i <= 10; $i++): ?>
<td>
  <select name="id_teo<?= $i ?>" class="form-select form-select-sm">
    <option value="">—</option>
    <?php foreach ($agrupaciones as $a): ?>
      <option value="<?= $a['id'] ?>"
        <?= (($data["id_teo$i"] ?? '') == $a['id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($a['titulo']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</td>
<?php endfor; ?>
</tr>
</tbody>
</table>

<!-- ================= TABLA TERRENO K–T ================= -->
<h5 class="mt-4">Componentes Terreno (K – T)</h5>

<table class="table table-bordered">
<thead>
<tr>
<?php foreach (range('K','T') as $letra): ?>
  <th><?= $letra ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<tr>
<?php for ($i = 1; $i <= 10; $i++): ?>
<td>
  <select name="id_ter<?= $i ?>" class="form-select form-select-sm">
    <option value="">—</option>
    <?php foreach ($agrupacionesTerreno as $t): ?>
      <option value="<?= $t['id'] ?>"
        <?= (($data["id_ter$i"] ?? '') == $t['id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($t['grupo']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</td>
<?php endfor; ?>
</tr>
</tbody>
</table>

<!-- ================= FÓRMULAS ================= -->
<h5 class="mt-4">Fórmulas de Evaluación</h5>

<div class="row">
  <div class="col-md-4">
    <label class="form-label">Fórmula Operador</label>
    <input type="text"
           name="formulao"
           class="form-control"
           placeholder="A+B+C"
           value="<?= htmlspecialchars($data['formulao'] ?? '') ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">Fórmula Supervisor</label>
    <input type="text"
           name="formulas"
           class="form-control"
           placeholder="K+L"
           value="<?= htmlspecialchars($data['formulas'] ?? '') ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">Fórmula Otros</label>
    <input type="text"
           name="formulav"
           class="form-control"
           placeholder="(T*0.4)+(P*0.6)"
           value="<?= htmlspecialchars($data['formulav'] ?? '') ?>">
  </div>
</div>
<!-- ================= PORCENTAJE DE APROBACIÓN ================= -->
<h5 class="mt-4">Porcentaje mínimo de aprobación</h5>

<div class="row">
  <div class="col-md-4">
    <label class="form-label">% Operador</label>
    <input type="number"
           name="porceno"
           class="form-control"
           min="0" max="100"
           placeholder="Ej: 80"
           value="<?= htmlspecialchars($data['porceno'] ?? '') ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">% Supervisor</label>
    <input type="number"
           name="porcens"
           class="form-control"
           min="0" max="100"
           placeholder="Ej: 70"
           value="<?= htmlspecialchars($data['porcens'] ?? '') ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">% Otros</label>
    <input type="number"
           name="porcenv"
           class="form-control"
           min="0" max="100"
           placeholder="Ej: 100"
           value="<?= htmlspecialchars($data['porcenv'] ?? '') ?>">
  </div>
</div>

<!-- ================= ESTADO / FECHA ================= -->
<div class="row mt-3">
  <div class="col-md-3">
    <label class="form-label">Estado</label>
    <select name="estado" class="form-select">
      <option value="S" <?= (($data['estado'] ?? '') === 'S') ? 'selected' : '' ?>>Sí</option>
      <option value="N" <?= (($data['estado'] ?? '') === 'N') ? 'selected' : '' ?>>No</option>
    </select>
  </div>

  <div class="col-md-3">
    <label class="form-label">Fecha Desde</label>
    <input type="date"
           name="fechadesde"
           class="form-control"
           value="<?= $data['fechadesde'] ?? '' ?>">
  </div>
</div>

<!-- ================= BOTONES ================= -->
<div class="d-flex justify-content-end gap-2 mt-4">
  <button type="submit" class="btn btn-primary">
    💾 Guardar
  </button>
  <a href="mant_agrupacion_consolidado.php" class="btn btn-secondary">
    Cancelar
  </a>
</div>

</form>

</div>
</div>

</body>
</html>
