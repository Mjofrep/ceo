<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

/* ============================================================
   1. VALIDAR ACCESO + SOLICITUD
   ============================================================ */
if (empty($_SESSION['auth']) || !in_array((int)$_SESSION['auth']['id_rol'], [4, 5], true)
) {
    header("Location: login_evaluador_terreno.php");
    exit;
}

$idEvaluaciones = $_GET['id_evaluacion'] ?? [];

if (!is_array($idEvaluaciones) || empty($idEvaluaciones)) {
    die('Debe seleccionar al menos una evaluación.');
}

$idEvaluaciones = array_map('intval', $idEvaluaciones);
$idEvaluaciones = array_values(array_filter($idEvaluaciones, fn($v) => $v > 0));

if (empty($idEvaluaciones)) {
    die('Selección inválida.');
}

$db = db();

/* ============================================================
   2. OBTENER SOLICITUD REAL
   ============================================================ */
$placeholders = implode(',', array_fill(0, count($idEvaluaciones), '?'));

$stmt = $db->prepare("
    SELECT
        A.id,
        A.cuadrilla AS nsolicitud,
        A.id_servicio AS servicio,
        DATE(A.fecha_programacion) AS fecha,
        '00:00' AS horainicio,
        '23:59' AS horatermino
    FROM ceo_evaluaciones_programadas A
    WHERE A.id IN ($placeholders)
      AND A.tipo = 'TERRENO'
      AND A.estado = 'PENDIENTE'
    ORDER BY A.id
");

$stmt->execute($idEvaluaciones);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    die('No hay evaluaciones válidas.');
}

$cuadrillas = array_values(array_unique(array_column($rows, 'nsolicitud')));
$servicios  = array_values(array_unique(array_map('intval', array_column($rows, 'servicio'))));

if (count($servicios) !== 1) {
    die('Debe seleccionar evaluaciones del mismo servicio para generar esta prueba.');
}

$sol = $rows[0];

$idServicio  = (int)$sol['servicio'];
$fechaPrueba = $sol['fecha'];
$horaInicio  = $sol['horainicio'];
$horaTermino = $sol['horatermino'];

/* ============================================================
   3. OBTENER PARTICIPANTES AUTORIZADOS
   ============================================================ */
$placeholders = implode(',', array_fill(0, count($idEvaluaciones), '?'));

$stmt = $db->prepare("
    SELECT DISTINCT
        c.rut,
        c.nombre,
        ' ' AS apellidop,
        ' ' AS apellidom,
        c.cargo,
        ep.cuadrilla
    FROM ceo_evaluaciones_programadas ep
    INNER JOIN ceo_habilitacion_participantes c
        ON c.rut COLLATE utf8mb4_unicode_ci = ep.rut COLLATE utf8mb4_unicode_ci
       AND c.id_cuadrilla = ep.cuadrilla
    WHERE ep.id IN ($placeholders)
      AND ep.tipo = 'TERRENO'
      AND ep.estado = 'PENDIENTE'
    ORDER BY c.nombre
");

$stmt->execute($idEvaluaciones);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   4. CONSULTA MAESTRA AGRUPACIÓN → SECCIÓN → PREGUNTAS
   ============================================================ */
$stmt = $db->prepare("
    SELECT 
        a.id AS id_grupo,
        a.grupo,
        s.id AS id_seccion,
        s.nombre AS nombre_seccion,
        s.orden AS orden_seccion,
        p.id AS id_pregunta,
        p.pregunta,
        p.ponderacion,
        IFNULL(p.practico,'') AS practico,
        IFNULL(p.referente,'') AS referente
    FROM ceo_agrupacion_terreno a
    LEFT JOIN ceo_seccion_terreno s ON s.id_grupo = a.id AND s.orden > 1
    LEFT JOIN ceo_preguntas_seccion_terreno p ON p.id_seccion = s.id
    WHERE a.id_servicio = ?
    ORDER BY a.id, s.orden, p.orden
");
$stmt->execute([$idServicio]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   5. ARMAR ESTRUCTURA COMPLETA
   ============================================================ */
$estructura = [];

foreach ($rows as $r) {

    $grupoID = $r['id_grupo'];
    $secID   = $r['id_seccion'];

    if (!isset($estructura[$grupoID])) {
        $estructura[$grupoID] = [
            'grupo'     => $r['grupo'],
            'secciones' => []
        ];
    }

    if (!isset($estructura[$grupoID]['secciones'][$secID])) {
        $estructura[$grupoID]['secciones'][$secID] = [
            'nombre_seccion' => $r['nombre_seccion'],
            'preguntas'      => []
        ];
    }

    if ($r['id_pregunta']) {
        $estructura[$grupoID]['secciones'][$secID]['preguntas'][] = [
            'id_pregunta' => $r['id_pregunta'],
            'pregunta'    => $r['pregunta'],
            'ponderacion' => $r['ponderacion'],
            'practico'    => $r['practico'],
            'referente'   => $r['referente']
        ];
    }
}


?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<?php $labelSolicitud = implode(', ', $cuadrillas); ?>
<title>Evaluación Terreno — Solicitud <?= $labelSolicitud ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#f7f9fc; }

/* HEADER */
.topbar {
    background:white;
    padding:12px 20px;
    border-bottom:1px solid #d6d6d6;
    display:flex;
    align-items:center;
    gap:15px;
}
.topbar img { height:60px; }
.topbar-title { font-size:1.4rem;font-weight:600;color:#0065a4; }
.topbar-sub { color:#666;font-size:0.9rem;margin-top:-6px; }

/* TABLAS DE EVALUACIÓN */
.section-green {background:#d9ead3; padding:8px; font-weight:bold;}
.section-orange {background:#fce4d6; padding:8px; font-weight:bold;}
.row-green td {background:#f3f8f0 !important;}
.row-orange td {background:#fef5ef !important;}
.section-box {margin-bottom:25px;}
.tab-pane {padding:20px;}

/* BOTONES */
.btn-top { margin-bottom:15px; }

/* Estilo de pestañas tipo "solapa" */
.nav-tabs .nav-link {
    border: 1px solid #d0d0d0;
    border-bottom: 2px solid transparent;
    margin-right: 4px;
    padding: 8px 15px;
    background: #f8f9fa;
    color: #0065a4;
    font-weight: 500;
    border-radius: 8px 8px 0 0;
}
.nav-tabs .nav-link:hover {
    background: #e2e6ea;
    color: #004b7a;
}
.nav-tabs .nav-link.active {
    background: #0065a4 !important;
    color: white !important;
    border-color: #0065a4 #0065a4 transparent #0065a4 !important;
    border-bottom: 2px solid white !important;
}

/* Hace sticky todo lo superior */
.sticky-top-tabs {
    position: sticky;
    top: 0;
    background: #f7f9fc;
    z-index: 1000;
    padding-bottom: 10px;
}

/* Evita que el scroll afecte la cabecera */
.sticky-header {
    position: sticky;
    top: 0;
    z-index: 1100;
}
</style>
</head>

<body>

<!-- ======================
        HEADER CEO
======================= -->
<header class="topbar sticky-header">
    <img src="<?= APP_LOGO ?>">
    <div>
        <div class="topbar-title"><?= APP_NAME ?></div>
        <div class="topbar-sub"><?= APP_SUBTITLE ?></div>
    </div>
</header>

<div class="container mt-4 sticky-top-tabs">

<h3 class="text-center mb-4">
    Evaluación de Prueba de Terreno — Solicitudes <?= htmlspecialchars(implode(', ', $cuadrillas)) ?>
</h3>

<div class="d-flex justify-content-between btn-top">
    <a href="evaluador_home_terreno.php" class="btn btn-secondary">← Volver</a>
    <button type="button" class="btn btn-success" id="btnGuardar">💾 Guardar Evaluación</button>
</div>

<!-- SOLAPAS -->
<ul class="nav nav-tabs">
<?php foreach ($participantes as $i => $p): ?>
    <li class="nav-item">
        <button class="nav-link <?= $i==0 ? 'active':'' ?>"
                data-bs-toggle="tab"
                data-bs-target="#alumno<?= $i ?>">
            <?= $p['nombre']." ".$p['apellidop']." ".$p['apellidom'] ?>
        </button>
    </li>
<?php endforeach; ?>
</ul>

</div>

<!-- FORMULARIO SOLO ENVUELVE LAS PREGUNTAS -->
<form id="formTerreno">
<div class="tab-content">

<?php foreach ($participantes as $i => $p): ?>

<div id="alumno<?= $i ?>" class="tab-pane fade <?= $i==0?'show active':'' ?>">

    <h5 class="mt-3">Datos del Participante</h5>

<p>
    <strong>RUT:</strong> <?= $p['rut'] ?><br>
    <strong>Nombre:</strong> <?= $p['nombre']." ".$p['apellidop']." ".$p['apellidom'] ?><br>
    <strong>Cargo:</strong> <?= $p['cargo'] ?><br>
    <strong>Fecha:</strong> <?= $fechaPrueba ?><br>
    <strong>Horario:</strong> <?= $horaInicio ?> a <?= $horaTermino ?><br>    
    <strong>Referente:</strong> <?= $_SESSION['auth']['nombre'] ?><br>
</p>


    <hr>

    <?php $colorIndex=0; ?>
    <?php foreach ($estructura as $grupo): ?>

        <h4 class="mt-4"><?= htmlspecialchars($grupo['grupo']) ?></h4>

        <?php foreach ($grupo['secciones'] as $idSec => $sec): ?>

            <div class="section-box">

                <div class="<?= ($colorIndex%2==0?'section-green':'section-orange') ?>">
                    <?= htmlspecialchars($sec['nombre_seccion']) ?>
                </div>

                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Pregunta</th>
                            <th width="5%">SI</th>
                            <th width="5%">NO</th>
                            <th width="5%">NA</th>
                            <th width="20%">Obs.</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($sec['preguntas'] as $pre): ?>
                        <?php $grp = $p['rut'].'_'.$pre['id_pregunta']; ?>

                        <tr class="<?= ($colorIndex%2==0?'row-green':'row-orange') ?>">

                            <td>
                                <?= $pre['pregunta'] ?>
                                <!-- ID SECCIÓN SIEMPRE DENTRO DEL <td> -->
                                <input type="hidden"
                                       name="resp[<?= $p['rut'] ?>][<?= $pre['id_pregunta'] ?>][id_seccion]"
                                       value="<?= $idSec ?>">
                            </td>

                            <!-- SI / NO / NA -->
                            <td><input type="checkbox" class="chk" data-group="<?= $grp ?>" data-type="si"
                                       name="resp[<?= $p['rut'] ?>][<?= $pre['id_pregunta'] ?>][si]"></td>
                            <td><input type="checkbox" class="chk" data-group="<?= $grp ?>" data-type="no"
                                       name="resp[<?= $p['rut'] ?>][<?= $pre['id_pregunta'] ?>][no]"></td>
                            <td><input type="checkbox" class="chk" data-group="<?= $grp ?>" data-type="na"
                                       name="resp[<?= $p['rut'] ?>][<?= $pre['id_pregunta'] ?>][na]"></td>

                            <!-- Obs -->
                            <td><textarea class="form-control form-control-sm"
                                          name="resp[<?= $p['rut'] ?>][<?= $pre['id_pregunta'] ?>][obs]"
                                          rows="2"></textarea></td>


                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php $colorIndex++; endforeach; ?>

    <?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</form>

<?php
$jsCuadrillas = $cuadrillas; // array de cuadrillas válidas
$jsIdsEvaluacion = $idEvaluaciones;
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Exclusividad SI/NO/NA
document.querySelectorAll('.chk').forEach(chk => {
    chk.addEventListener('change', () => {
        let group = chk.dataset.group;
        let type  = chk.dataset.type;

        if (chk.checked) {
            document.querySelectorAll(".chk[data-group='"+group+"']")
                .forEach(c => { if (c.dataset.type !== type) c.checked = false; });
        }
    });
});

// Guardado
document.getElementById('btnGuardar').addEventListener('click', () => {

    // ===============================
    // VALIDACIÓN OBS OBLIGATORIA SI = NO
    // ===============================
    let error = false;
    let primerError = null;

    document.querySelectorAll('.chk[data-type="no"]').forEach(chkNo => {

        if (!chkNo.checked) return;

        const group = chkNo.dataset.group;

        // Buscar textarea de observación del mismo grupo
        const textarea = document.querySelector(
            `textarea[name^="resp"][name*="[obs]"][name*="${group.split('_')[1]}"]`
        );

        if (!textarea || textarea.value.trim() === "") {
            error = true;
            textarea?.classList.add('is-invalid');

            if (!primerError && textarea) {
                primerError = textarea;
            }
        } else {
            textarea.classList.remove('is-invalid');
        }
    });

    if (error) {
        alert(
            "⚠️ Validación incompleta\n\n" +
            "Debes ingresar una OBSERVACIÓN en todas las preguntas marcadas como NO."
        );

        if (primerError) {
            primerError.focus();
        }
        return; // ⛔ NO guarda
    }

    // ===============================
    // ARMAR Y ENVIAR RESPUESTAS
    // ===============================
    let fd = new FormData(document.getElementById('formTerreno'));
    let respuestas = {};

    for (let [key,val] of fd.entries()) {

        let m = key.match(/resp\[(.*)\]\[(.*)\]\[(.*)\]/);

        if (m) {
            let rut    = m[1];
            let idpreg = m[2];
            let campo  = m[3];

            if (!respuestas[rut]) respuestas[rut] = {};
            if (!respuestas[rut][idpreg]) respuestas[rut][idpreg] = {};

            respuestas[rut][idpreg][campo] = val;
        }
    }

    fetch("guardar_terreno.php", {
        method: "POST",
        body: JSON.stringify({
    id_evaluacion: <?= json_encode($idEvaluaciones) ?>,
    nsolicitud: <?= json_encode($jsCuadrillas) ?>,
    id_servicio: <?= $idServicio ?>,
    id_empresa: <?= (int)$_SESSION['auth']['id_empresa'] ?>,
    respuestas: respuestas
})
    })
    .then(r => r.json())
.then(r => {
    if (r.ok) {
        alert("✔ Evaluación guardada correctamente.");
        window.location.href = "evaluador_home_terreno.php";
    } else {
        alert("❌ Error: " + r.error);
    }
});


});

</script>

</body>
</html>
