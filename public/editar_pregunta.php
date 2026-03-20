<?php
// -------------------------------------------------------------
// editar_pregunta.php
// -------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$db = db();

// Obtener ID
$id = intval($_GET['id']);

$stmt = $db->prepare("SELECT * FROM ceo_preguntas_seccion_terreno WHERE id=?");
$stmt->execute([$id]);
$preg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$preg) {
    die("Pregunta no encontrada.");
}

// obtener id_grupo para volver
$stmt2 = $db->prepare("
    SELECT st.id_grupo 
    FROM ceo_seccion_terreno st 
    WHERE st.id=?
");
$stmt2->execute([$preg['id_seccion']]);
$id_grupo = $stmt2->fetchColumn();
?>
<!DOCTYPE html>
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

<body class="bg-light">
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
    <h3>Editar Pregunta</h3>

    <a href="terreno_gestion.php?modo=preguntas&id_seccion=<?= $preg['id_seccion'] ?>" 
       class="btn btn-secondary mb-3">← Volver</a>

    <div class="card shadow-sm">
        <div class="card-body">

            <form method="post" action="terreno_gestion_acciones.php">

                <input type="hidden" name="accion" value="editar_pregunta">
                <input type="hidden" name="id" value="<?= $preg['id'] ?>">

                <div class="mb-3">
                    <label>Pregunta</label>
                    <input type="text" name="pregunta" class="form-control" required
                           value="<?= htmlspecialchars($preg['pregunta']) ?>">
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label>Ponderación (%)</label>
                        <input type="number" name="ponderacion" class="form-control"
                               value="<?= $preg['ponderacion'] ?>">
                    </div>

                    <div class="col-md-3">
                        <label>Práctico</label>
                        <select name="practico" class="form-select">
                            <option value="SI" <?= ($preg['practico']=='SI'?'selected':'') ?>>SI</option>
                            <option value="NO" <?= ($preg['practico']=='NO'?'selected':'') ?>>NO</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label>Referente</label>
                        <input type="text" name="referente" class="form-control"
                               value="<?= htmlspecialchars($preg['referente']) ?>">
                    </div>

                    <div class="col-md-3">
                        <label>Orden</label>
                        <input type="number" name="orden" class="form-control"
                               value="<?= $preg['orden'] ?>">
                    </div>
                </div>

                <button class="btn btn-primary">Guardar Cambios</button>

            </form>

        </div>
    </div>

</div>

</body>
</html>
