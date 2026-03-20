<?php
// ------------------------------------------------------------------
// terreno_gestion_ver.php  (Visualización completa usando consulta maestra)
// ------------------------------------------------------------------
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

// ------------------------------------------------------
// Cargar agrupaciones (para selector)
// ------------------------------------------------------
$stmt = $db->prepare("
    SELECT a.id, a.grupo, s.servicio
    FROM ceo_agrupacion_terreno a
    LEFT JOIN ceo_servicios_pruebas s ON s.id = a.id_servicio
    ORDER BY a.grupo ASC
");
$stmt->execute();
$agrupaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$id_grupo = $_GET['id_grupo'] ?? null;

$estructura = [];
$nombreGrupo = "";

// ------------------------------------------------------
// Consulta maestra si seleccionaron un grupo
// ------------------------------------------------------
if ($id_grupo) {

    $stmt2 = $db->prepare("
        SELECT 
            a.id AS id_grupo,
            a.grupo,
            s.id AS id_seccion,
            s.seccion,
            s.nombre AS nombre_seccion,
            t.id AS id_pregunta,
            t.pregunta,
            t.ponderacion,
            t.practico,
            t.referente
        FROM ceo_agrupacion_terreno a
        LEFT JOIN ceo_seccion_terreno s ON s.id_grupo = a.id
        LEFT JOIN ceo_preguntas_seccion_terreno t ON t.id_seccion = s.id
        WHERE a.id = ?
        ORDER BY s.orden, t.orden
    ");
    $stmt2->execute([$id_grupo]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Guardar nombre del grupo
    if (!empty($rows)) {
        $nombreGrupo = $rows[0]['grupo'];
    }

    // Agrupar por sección
    foreach ($rows as $r) {

        if (!$r['id_seccion']) continue;

        $secKey = $r['id_seccion'];

        if (!isset($estructura[$secKey])) {
            $estructura[$secKey] = [
                'seccion' => $r['seccion'],
                'nombre'  => $r['nombre_seccion'],
                'preguntas' => []
            ];
        }

        if ($r['id_pregunta']) {
            $estructura[$secKey]['preguntas'][] = [
                'pregunta'     => $r['pregunta'],
                'ponderacion'  => $r['ponderacion'],
                'practico'     => $r['practico'],
                'referente'    => $r['referente']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Prueba Terreno | <?= htmlspecialchars(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc; font-size:0.9rem;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600; font-size:1.1rem;}
.card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
.table-header {background:#e6f2ff; font-weight:bold;}

/* COLORES DE SECCIONES */
.section-green {
    background: #d9ead3 !important;
    padding: 8px;
    margin-top: 20px;
    font-weight: bold;
    border-radius: 4px;
}

.section-orange {
    background: #fce4d6 !important;
    padding: 8px;
    margin-top: 20px;
    font-weight: bold;
    border-radius: 4px;
}

/* COLORES DE FILAS */
.row-green td {
    background: #f3f8f0 !important;
}

.row-orange td {
    background: #fef5ef !important;
}
</style>
</head>

<body>

<!-- TOP BAR -->
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">

    <h3 class="mb-4">Visualización de Prueba de Terreno</h3>

    <!-- Selector de Agrupación -->
    <form method="get" class="card p-3 shadow-sm mb-4">
        <div class="row">
            <div class="col-md-6">
                <label>Seleccione Agrupación</label>
                <select name="id_grupo" class="form-select" onchange="this.form.submit()">
                    <option value="">Seleccione...</option>
                    <?php foreach ($agrupaciones as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= ($id_grupo == $a['id'] ? 'selected' : '') ?>>
                            <?= htmlspecialchars($a['grupo']) ?> (<?= $a['servicio'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <?php if ($id_grupo): ?>
<a href="terreno_gestion_excel.php?id_grupo=<?= $id_grupo ?>" 
   class="btn btn-success mb-3">
    📥 Exportar a Excel
</a>

    <!-- ENCABEZADO -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">Información del Proceso (Solo visual)</div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4"><strong>Empresa CTTA:</strong> __________________</div>
                <div class="col-md-4"><strong>Fecha Habilitación:</strong> ______________</div>
                <div class="col-md-4"><strong>Unidad Operativa:</strong> _______________</div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4"><strong>Servicio:</strong> ________________________</div>
                <div class="col-md-4"><strong>Evaluador ENEL:</strong> _________________</div>
                <div class="col-md-4"><strong>RUT Evaluador:</strong> _________________</div>
            </div>

            <div class="row">
                <div class="col-md-4"><strong>Persona Evaluada:</strong> ______________</div>
                <div class="col-md-4"><strong>RUT:</strong> ___________________________</div>
                <div class="col-md-4"><strong>Cargo:</strong> _________________________</div>
            </div>
        </div>
    </div>

    <!-- NOMBRE DEL GRUPO -->
    <h4 class="mb-3">
        <span class="badge bg-secondary"><?= htmlspecialchars($nombreGrupo) ?></span>
    </h4>

    <!-- SECCIONES + PREGUNTAS -->
    <?php 
    $index = 0;
    foreach ($estructura as $sec): 
        $isGreen = ($index % 2 == 0);
    ?>
        <div class="<?= $isGreen ? 'section-green' : 'section-orange' ?>">
            <?= htmlspecialchars($sec['nombre']) ?>
        </div>

        <table class="table table-bordered shadow-sm mt-2">
            <thead class="table-header">
                <tr>
                    <th width="45%">Pregunta</th>
                    <th width="5%">SI</th>
                    <th width="5%">NO</th>
                    <th width="5%">NA</th>
                    <th width="20%">Observaciones</th>
                    <th width="10%">Ponderación</th>
                    <th width="10%">Práctico</th>
                    <th width="10%">Referente</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sec['preguntas'] as $p): ?>
                <tr class="<?= $isGreen ? 'row-green' : 'row-orange' ?>">
                    <td><?= htmlspecialchars($p['pregunta']) ?></td>
                    <td></td><td></td><td></td><td></td>
                    <td><?= $p['ponderacion'] ?>%</td>
                    <td><?= $p['practico'] ?></td>
                    <td><?= $p['referente'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php $index++; endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>

