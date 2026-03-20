<?php
// --------------------------------------------------------------
// terreno_gestion.php - Gestión de Agrupaciones, Secciones y Preguntas
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


// ---------------------------------------------------------------------
// Cargar servicios (para combo de agrupaciones)
// ---------------------------------------------------------------------
$sql_serv = "SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY id ASC";
$servicios = $pdo->query($sql_serv)->fetchAll(PDO::FETCH_ASSOC);

// Determinar vista (agrupaciones / secciones / preguntas)
$modo = $_GET['modo'] ?? 'agrupaciones';
$id_grupo = $_GET['id_grupo'] ?? null;
$id_seccion = $_GET['id_seccion'] ?? null;

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

.btn-action {
    width: 28px;
    height: 28px;
    padding: 0;
    font-size: 14px;
    line-height: 28px; /* para centrar el icono */
}
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
    <h3 class="mb-4">Gestión de Pruebas de Terreno</h3>

    <!-- ******************************************************** -->
    <!-- 1) CREAR AGRUPACIONES ---------------------------------- -->
    <!-- ******************************************************** -->
    <?php if ($modo === 'agrupaciones'): ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                Crear Agrupación de Terreno
            </div>
            <div class="card-body">
                <form method="post" action="terreno_gestion_acciones.php">
                    <input type="hidden" name="accion" value="crear_agrupacion">

                    <div class="row">
                        <div class="col-md-6">
                            <label>Nombre Agrupación</label>
                            <input type="text" name="grupo" class="form-control" required>
                        </div>

                        <div class="col-md-4">
                            <label>Servicio Asociado</label>
                            <select name="id_servicio" class="form-select" required>
                                <option value="">Seleccione...</option>
                                <?php foreach ($servicios as $s): ?>
                                    <option value="<?= $s['id'] ?>">
                                        <?= $s['servicio'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-success w-100">Guardar</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- LISTA DE AGRUPACIONES -------------------------------- -->
        <?php
        $agr = $pdo->query("
            SELECT a.id, a.grupo, s.servicio 
            FROM ceo_agrupacion_terreno a
            JOIN ceo_servicios_pruebas s ON s.id = a.id_servicio
            ORDER BY a.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white">Agrupaciones Registradas</div>
            <div class="card-body">
                <table class="table table-bordered table-striped">
                    <thead class="table-secondary">
                        <tr>
                            <th>ID</th>
                            <th>Grupo</th>
                            <th>Servicio</th>
                            <th class="text-center" width="150">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($agr as $a): ?>
                            <tr>
                                <td><?= $a['id'] ?></td>
                                <td><?= $a['grupo'] ?></td>
                                <td><?= $a['servicio'] ?></td>
                                <td class="text-center">

                                    <!-- Gestionar Secciones (?) -->
                                    <a href="terreno_gestion.php?modo=secciones&id_grupo=<?= $a['id'] ?>"
                                       class="btn btn-outline-success btn-action" title="Secciones">
                                        <i class="bi bi-question-lg"></i>
                                    </a>

                                    <!-- Editar -->
                                    <a href="editar_agrupacion.php?id=<?= $a['id'] ?>"
                                       class="btn btn-outline-primary btn-action" title="Editar">
                                        ✏️
                                    </a>

                                    <!-- Eliminar -->
                                    <a onclick="return confirm('¿Eliminar agrupación?')"
                                       href="terreno_gestion_acciones.php?accion=eliminar_agrupacion&id=<?= $a['id'] ?>"
                                       class="btn btn-outline-danger btn-action">
                                        🗑️
                                    </a>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </div>

    <?php endif; ?>

    <!-- ******************************************************** -->
    <!-- 2) GESTIONAR SECCIONES -------------------------------- -->
    <!-- ******************************************************** -->
    <?php if ($modo === 'secciones'): ?>
        <a href="terreno_gestion.php" class="btn btn-secondary mb-3">
            ← Volver a Agrupaciones
        </a>
        <?php
        $datosGrupo = $pdo->prepare("SELECT * FROM ceo_agrupacion_terreno WHERE id=?");
        $datosGrupo->execute([$id_grupo]);
        $g = $datosGrupo->fetch(PDO::FETCH_ASSOC);

        $secciones = $pdo->prepare("SELECT * FROM ceo_seccion_terreno WHERE id_grupo=? ORDER BY orden ASC");
        $secciones->execute([$id_grupo]);
        $secciones = $secciones->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <h4 class="mt-4">Agrupación: <?= $g['grupo'] ?></h4>

        <div class="card shadow-sm mt-3">
            <div class="card-header bg-info text-white">Crear Sección</div>
            <div class="card-body">
                <form method="post" action="terreno_gestion_acciones.php">
                    <input type="hidden" name="accion" value="crear_seccion">
                    <input type="hidden" name="id_grupo" value="<?= $id_grupo ?>">

                    <div class="row">
                        <div class="col-md-4">
                            <label>Sección</label>
                            <input type="text" name="seccion" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Nombre</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-success w-100">Agregar</button>
                        </div>
                    </div>

                </form>
            </div>
        </div>

        <!-- LISTA DE SECCIONES -->
        <div class="card shadow-sm mt-3">
            <div class="card-header bg-dark text-white">Secciones</div>
            <div class="card-body">

                <table class="table table-bordered table-striped">
                    <thead class="table-secondary">
                        <tr>
                            <th>ID</th>
                            <th>Sección</th>
                            <th>Nombre</th>
                            <th>Orden</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secciones as $s): ?>
                            <tr>
                                <td><?= $s['id'] ?></td>
                                <td><?= $s['seccion'] ?></td>
                                <td><?= $s['nombre'] ?></td>
                                <td><?= $s['orden'] ?></td>
                                <td class="text-center">

                                    <!-- Gestionar Preguntas -->
                                    <a href="terreno_gestion.php?modo=preguntas&id_seccion=<?= $s['id'] ?>"
                                       class="btn btn-outline-success btn-action">
                                        ?
                                    </a>

                                    <!-- Editar -->
                                    <a href="editar_seccion.php?id=<?= $s['id'] ?>"
                                       class="btn btn-outline-primary btn-action">✏️</a>

                                    <!-- Eliminar -->
                                    <a onclick="return confirm('Eliminar sección?');"
                                       href="terreno_gestion_acciones.php?accion=eliminar_seccion&id=<?= $s['id'] ?>"
                                       class="btn btn-outline-danger btn-action">🗑️</a>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </div>

    <?php endif; ?>

    <!-- ******************************************************** -->
    <!-- 3) GESTIONAR PREGUNTAS -------------------------------- -->
    <!-- ******************************************************** -->
    <?php if ($modo === 'preguntas'): ?>
    
        <?php
    // Recuperar id_grupo para volver correctamente a secciones
    $stmt = $pdo->prepare("SELECT id_grupo FROM ceo_seccion_terreno WHERE id=?");
    $stmt->execute([$id_seccion]);
    $id_grupo = $stmt->fetchColumn();
    ?>

        <a href="terreno_gestion.php?modo=secciones&id_grupo=<?= $id_grupo ?>" 
           class="btn btn-secondary mb-3">
           ← Volver a Secciones
        </a>

        <?php
        $datosSec = $pdo->prepare("SELECT * FROM ceo_seccion_terreno WHERE id=?");
        $datosSec->execute([$id_seccion]);
        $sec = $datosSec->fetch(PDO::FETCH_ASSOC);

        $preguntas = $pdo->prepare("SELECT * FROM ceo_preguntas_seccion_terreno WHERE id_seccion=? ORDER BY orden ASC");
        $preguntas->execute([$id_seccion]);
        $preguntas = $preguntas->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <h4 class="mt-4">Sección: <?= $sec['nombre'] ?></h4>

        <div class="card shadow-sm mt-3">
            <div class="card-header bg-warning">Agregar Pregunta</div>
            <div class="card-body">

                <form method="post" action="terreno_gestion_acciones.php">
                    <input type="hidden" name="accion" value="crear_pregunta">
                    <input type="hidden" name="id_seccion" value="<?= $id_seccion ?>">

                    <label>Pregunta</label>
                    <input type="text" name="pregunta" class="form-control mb-2" required>

                    <div class="row mb-2">
                        <div class="col-md-3">
                            <label>Ponderación (%)</label>
                            <input type="number" name="ponderacion" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label>Práctico</label>
                            <select name="practico" class="form-select">
                                <option value="SI">SI</option>
                                <option value="NO">NO</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Referente</label>
                            <input type="text" name="referente" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label>Orden</label>
                            <input type="number" name="orden" class="form-control">
                        </div>
                    </div>

                    <button class="btn btn-success">Agregar Pregunta</button>
                </form>

            </div>
        </div>

        <div class="card shadow-sm mt-3">
            <div class="card-header bg-dark text-white">Preguntas Registradas</div>
            <div class="card-body">

                <table class="table table-bordered table-striped">
                    <thead class="table-secondary">
                        <tr>
                            <th>ID</th>
                            <th>Pregunta</th>
                            <th>Ponderación</th>
                            <th>Práctico</th>
                            <th>Referente</th>
                            <th>Orden</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preguntas as $p): ?>
                            <tr>
                                <td><?= $p['id'] ?></td>
                                <td><?= $p['pregunta'] ?></td>
                                <td><?= $p['ponderacion'] ?>%</td>
                                <td><?= $p['practico'] ?></td>
                                <td><?= $p['referente'] ?></td>
                                <td><?= $p['orden'] ?></td>
                                <td class="text-center">

                                    <a href="editar_pregunta.php?id=<?= $p['id'] ?>"
                                       class="btn btn-outline-primary btn-action">✏️</a>

                                    <a onclick="return confirm('Eliminar pregunta?')"
                                       href="terreno_gestion_acciones.php?accion=eliminar_pregunta&id=<?= $p['id'] ?>"
                                       class="btn btn-outline-danger btn-action">🗑️</a>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
