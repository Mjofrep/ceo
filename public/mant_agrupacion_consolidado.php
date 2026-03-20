<?php
declare(strict_types=1);
session_start();

require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();

/* ================= LISTADO ================= */
$rows = $pdo->query("
    SELECT c.id, c.nombre, s.servicio, c.estado
    FROM ceo_agrupacion_consolidado c
    LEFT JOIN ceo_servicios_pruebas s ON s.id = c.id_servicio
    ORDER BY c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Agrupación Consolidada</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
<div class="container mt-4">

  <div class="d-flex justify-content-between mb-3">
    <div>
      <a href="mant_agrupacion_consolidado_form.php"
         class="btn btn-primary">➕ Agregar</a>
    </div>

  </div>

  <div class="card p-3">
    <h5 class="mb-3">Agrupaciones Consolidadas</h5>

    <table class="table table-striped">
      <thead class="table-primary">
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Servicio</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td><?= htmlspecialchars($r['servicio']) ?></td>
          <td><?= $r['estado'] ?></td>
          <td>
            <a href="mant_agrupacion_consolidado_form.php?id=<?= $r['id'] ?>"
               class="btn btn-sm btn-outline-info">
               ✏️ Editar
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
