<?php
// --------------------------------------------------------------
// revision_cuadrilla_detalle.php - Detalle del Trabajador (CEO)
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$pdo = db();

$rut = $_GET['rut'] ?? '';
$prog = $_GET['programa'] ?? '';
// ============================================================
// CONSULTA DATOS DEL TRABAJADOR
// ============================================================

$trabajador = null;
$wfRegistros = [];
$agrupaciones = [];

if ($rut) {
/*    $sql = "SELECT 
            a.id,
            a.rut,
            a.nombre,
            a.apellidos,
            b.cargo,
            c.nombre AS empresa,
            d.desc_uo AS uo,
            sp.id AS id_servicio,
            sp.descripcion AS servicio_descripcion
        FROM ceo_contratistas a
        INNER JOIN ceo_cargo_contratistas b ON a.id_cargo = b.id
        INNER JOIN ceo_empresas c ON a.id_empresa = c.id
        INNER JOIN ceo_uo d ON a.uo = d.id
LEFT JOIN ceo_resultado_prueba_intento rpi 
       ON rpi.rut COLLATE utf8mb4_unicode_ci = a.rut COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = rpi.id_servicio
            WHERE a.rut = :rut
            LIMIT 1"; */

        $sql = "SELECT a.id, a.cuadrilla, b.rut, b.nombre, b.apellidos, d.cargo ,e.nombre as empresa, f.desc_uo as uo, a.id_servicio, g.servicio as servicio_descripcion
        FROM ceo_habilitacion a
        INNER JOIN ceo_habilitacion_participantes b ON a.cuadrilla = b.id_cuadrilla 
        LEFT JOIN ceo_contratistas c ON b.rut COLLATE utf8mb4_unicode_ci = c.rut COLLATE utf8mb4_unicode_ci
        INNER JOIN ceo_servicios_rut z ON z.rut = b.rut
        INNER JOIN ceo_cargos_habilitacion d ON z.id_cargo = d.id
        INNER JOIN ceo_empresas e ON e.id = c.id_empresa
        INNER JOIN ceo_uo f ON a.uo = f.id
        INNER JOIN ceo_servicios_pruebas g ON a.id_servicio = g.id
        INNER JOIN ceo_reportewf h ON h.rut_empleado = b.rut
        where a.id = :programa
        and b.rut = :rut;";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'programa' => $prog,
            'rut' => $rut
        ]);
        $trabajador = $stmt->fetch(PDO::FETCH_ASSOC);

        // ============================================================
        // CONSULTA WF DEL TRABAJADOR
        // ============================================================
        $sqlWF = "
            SELECT
                contratista,
                tipo,
                wf,
                servicio,
                cargo,
                fecha_carga
            FROM ceo_reportewf
            WHERE rut_empleado = :rut
            ORDER BY fecha_carga DESC
        ";
        
        $stmtWF = $pdo->prepare($sqlWF);
        $stmtWF->execute(['rut' => $rut]);
        $wfRegistros = $stmtWF->fetchAll(PDO::FETCH_ASSOC);

    
    // Obtener agrupaciones dinámicas para el servicio
        if (!empty($trabajador['id_servicio'])) {
            $sqlAgr = "SELECT id, titulo 
                       FROM ceo_agrupacion 
                       WHERE id_servicio = :srv
                       ORDER BY id ASC";
            
            $stmtAgr = $pdo->prepare($sqlAgr);
            $stmtAgr->execute(['srv' => $trabajador['id_servicio']]);
            $agrupaciones = $stmtAgr->fetchAll(PDO::FETCH_ASSOC);
        }
}

$intentos = [];
$historial = [];
$evaluacionesTerreno = [];
$estadoHab = null;
$vigenciaGeneral = null;
$vigenciaDetalle = [];

if ($rut && !empty($trabajador['id_servicio'])) {

    $sqlPruebas = "
        SELECT
            rpi.id,
            rpi.rut,
            rpi.fecha_rendicion,
            rpi.puntaje_total,
            rpi.notafinal
        FROM ceo_resultado_prueba_intento rpi
        WHERE rpi.rut = :rut
          AND rpi.id_servicio = :servicio
        ORDER BY rpi.fecha_rendicion DESC
    ";

    $stmtPr = $pdo->prepare($sqlPruebas);
    $stmtPr->execute([
        'rut'      => $rut,
        'servicio' => $trabajador['id_servicio']
    ]);

    $intentos = $stmtPr->fetchAll(PDO::FETCH_ASSOC);

    $sqlHist = "
        SELECT
            rpi.fecha_rendicion AS fecha,
            'Teórica'           AS tipo,
            rpi.notafinal       AS resultado,
            sp.servicio         AS servicio
        FROM ceo_resultado_prueba_intento rpi
        INNER JOIN ceo_servicios_pruebas sp 
                ON rpi.id_servicio = sp.id
        WHERE rpi.rut = :rut_teorica

        UNION ALL

        SELECT
            et.fecha_evaluacion AS fecha,
            'Terreno'           AS tipo,
            et.resultado        AS resultado,
            sp2.servicio        AS servicio
        FROM ceo_evaluacion_terreno et
        INNER JOIN ceo_servicios_pruebas sp2 
                ON et.id_servicio = sp2.id
        WHERE et.rut = :rut_terreno

        ORDER BY fecha DESC
    ";

    $stmtH = $pdo->prepare($sqlHist);
    $stmtH->execute([
        'rut_teorica' => $rut,
        'rut_terreno' => $rut
    ]);

    $historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    $sqlTerr = "
        SELECT DISTINCT
            et.id,
            et.codigo_evaluacion,
            et.rut,
            et.cargo,
            et.fecha_evaluacion,
            b.notafinal AS resultado
        FROM ceo_evaluacion_terreno et
            INNER JOIN ceo_resultado_terreno_intento b ON b.rut = et.rut
        WHERE et.rut = :rut
          AND et.id_servicio = :servicio
        ORDER BY et.fecha_evaluacion DESC
    ";

    $stmtT = $pdo->prepare($sqlTerr);
    $stmtT->execute([
        'rut'      => $rut,
        'servicio' => $trabajador['id_servicio']
    ]);

    $evaluacionesTerreno = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // CONSULTA ESTADO HABILITACIÓN (UNA SOLA FILA)
    // ============================================================
    $sqlEstadoHab = "
SELECT
        rfs.rut,
        cc.cargo,
        rfs.fecha_calculo AS fecha_rendicion,
        rfs.nota_terreno AS Terreno,
        rfs.nota_prueba AS Teorica,
        rfs.nota_final AS resultado,
        CASE
            WHEN UPPER(rfs.resultado_final) = 'APROBADO' THEN 'SI'
            ELSE 'NO'
        END AS habilitado
    FROM ceo_resultado_final_servicio rfs
    LEFT JOIN ceo_cargos_habilitacion cc ON cc.id = rfs.cargo
    WHERE rfs.rut = :rut
      AND rfs.id_servicio = :servicio
      AND rfs.segmento = 'GENERAL'
    ORDER BY rfs.fecha_calculo DESC, rfs.id DESC
    LIMIT 1
    ";

    $stmtEH = $pdo->prepare($sqlEstadoHab);
    $stmtEH->execute([
      'rut'      => $rut,
      'servicio' => (int)$trabajador['id_servicio']
    ]);
    $estadoHab = $stmtEH->fetch(PDO::FETCH_ASSOC);

    // ============================================================
    // CONSULTA VIGENCIA GENERAL Y DETALLE POR RUT + PROCESO
    // ============================================================
    $sqlVigGen = "
        SELECT
            vg.id,
            vg.rut,
            vg.fechavig_ini,
            vg.fechavig_fin,
            vg.id_proceso
        FROM ceo_vigencia_general vg
        WHERE vg.rut = :rut
          AND vg.id_proceso = :proceso
        ORDER BY vg.fechavig_fin DESC, vg.id DESC
        LIMIT 1
    ";
    $stmtVG = $pdo->prepare($sqlVigGen);
    $stmtVG->execute([
        'rut'     => $rut,
        'proceso' => $prog
    ]);
    $vigenciaGeneral = $stmtVG->fetch(PDO::FETCH_ASSOC);

    $sqlVigDet = "
        SELECT
            vd.id,
            vd.rut,
            vd.id_servicio,
            vd.fechavig_ini,
            vd.fechavig_fin,
            vd.id_proceso,
            vd.tipo,
            sp.servicio,
            sp.descripcion,
            CASE
                WHEN UPPER(TRIM(vd.tipo)) = 'PRUEBA' THEN (
                    SELECT rpi.notafinal
                    FROM ceo_resultado_prueba_intento rpi
                    WHERE rpi.rut = vd.rut
                      AND rpi.id_servicio = vd.id_servicio
                    ORDER BY rpi.fecha_rendicion DESC, rpi.id DESC
                    LIMIT 1
                )
                WHEN UPPER(TRIM(vd.tipo)) = 'TERRENO' THEN (
                    SELECT rti.notafinal
                    FROM ceo_resultado_terreno_intento rti
                    WHERE rti.rut = vd.rut
                      AND rti.id_servicio = vd.id_servicio
                    ORDER BY rti.id DESC
                    LIMIT 1
                )
                ELSE NULL
            END AS nota
        FROM ceo_vigencia_detalle vd
        INNER JOIN ceo_servicios_pruebas sp
            ON sp.id = vd.id_servicio
        WHERE vd.rut = :rut
          AND vd.id_proceso = :proceso
        ORDER BY sp.servicio ASC, vd.id DESC
    ";
    $stmtVD = $pdo->prepare($sqlVigDet);
    $stmtVD->execute([
        'rut'     => $rut,
        'proceso' => $prog
    ]);
    $vigenciaDetalle = $stmtVD->fetchAll(PDO::FETCH_ASSOC);
}

$serviciosPrueba = $pdo->query("
    SELECT id, servicio, descripcion
    FROM ceo_servicios_pruebas
    ORDER BY servicio
")->fetchAll(PDO::FETCH_ASSOC);

$cargos = $pdo->query("
    SELECT id, cargo
    FROM ceo_cargos_habilitacion
    ORDER BY cargo
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Detalle Evaluaciones - <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}

.box-label {
    font-weight: 700;
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.data-box {
    border:1px solid #d0d7de;
    padding:6px 10px;
    border-radius:6px;
    background:white;
    font-size:0.9rem;
}

.scroll-box {
    max-height:260px;
    overflow:auto;
    border:1px solid #dee2e6;
    border-radius:6px;
    background:white;
}

.table thead {
    position:sticky; 
    top:0;
    background:#eaf2fb; 
    z-index:2;
}

.section-title {
    font-weight:bold;
    font-size:1rem;
    margin-bottom:10px;
    color:#0065a4;
}

.badge-soft-success {
    background:#d1e7dd;
    color:#0f5132;
    border:1px solid #badbcc;
}

.badge-soft-danger {
    background:#f8d7da;
    color:#842029;
    border:1px solid #f5c2c7;
}

.badge-soft-secondary {
    background:#e2e3e5;
    color:#41464b;
    border:1px solid #d3d6d8;
}
</style>

</head>

<body>

<!-- ============================================================
     HEADER
============================================================ -->
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>

<a href="revision_cuadrillas.php?empresa=<?= $_GET['empresa'] ?? '' ?>&uo=<?= $_GET['uo'] ?? '' ?>&programa=<?= $_GET['programa'] ?? '' ?>" 
   class="btn btn-outline-primary btn-sm">
   ← Volver
</a>

  </div>
</header>


<div class="container-fluid px-4">

    <!-- ============================================================
         CARD PRINCIPAL
    ============================================================ -->
<div class="card rounded-4 shadow-sm mb-4">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">

        <h4 class="fw-bold text-primary mb-0">
            <i class="bi bi-person-vcard me-2"></i>
            Detalle de Evaluaciones
        </h4>

        <!-- Botón Servicios por Habilitar -->
        <button class="btn btn-outline-primary rounded-3 fw-semibold"
                data-bs-toggle="modal"
                data-bs-target="#modalHabilitaciones">
            <i class="bi bi-shield-exclamation me-1"></i>
            Servicios y Cargo por Habilitar
        </button>

    </div>
</div>


    <!-- ============================================================
         DATOS DEL TRABAJADOR
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">

            <h5 class="fw-bold text-primary mb-3">Información del Trabajador</h5>

            <div class="row g-3">

                <div class="col-md-3">
                    <div class="box-label">RUT</div>
                    <div class="data-box"><?= esc($rut) ?></div>
                </div>

 <div class="col-md-3">
    <div class="box-label">Nombre</div>
    <div class="data-box"><?= esc($trabajador['nombre'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Apellido</div>
    <div class="data-box"><?= esc($trabajador['apellidos'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Cargo</div>
    <div class="data-box"><?= esc($trabajador['cargo'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Empresa</div>
    <div class="data-box"><?= esc($trabajador['empresa'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Unidad Operativa</div>
    <div class="data-box"><?= esc($trabajador['uo'] ?? '') ?></div>
</div>

<div class="col-md-6">
    <div class="box-label">Servicio</div>
    <div class="data-box"><?= esc($trabajador['servicio_descripcion'] ?? '') ?></div>
</div>


            </div>

        </div>
    </div>


<!-- ============================================================
     INFORMACIÓN WF
============================================================ -->
<div class="card shadow-sm rounded-4 mb-4">
    <div class="card-body">

        <div class="section-title">
            <i class="bi bi-diagram-3 me-2"></i>Información WF
        </div>

        <div class="scroll-box">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr class="text-center">
                        <th>Contratista</th>
                        <th>Tipo</th>
                        <th>WF</th>
                        <th>Servicio</th>
                        <th>Cargo</th>
                        <th>Fecha Carga</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($wfRegistros)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            Sin información WF registrada
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($wfRegistros as $wf): ?>
                        <tr class="text-center">
                            <td><?= esc($wf['contratista']) ?></td>
                            <td><?= esc($wf['tipo']) ?></td>
                            <td><?= esc($wf['wf']) ?></td>
                            <td><?= esc($wf['servicio']) ?></td>
                            <td><?= esc($wf['cargo']) ?></td>
                            <td><?= esc(date('d-m-Y H:i', strtotime($wf['fecha_carga']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>
</div>


    <!-- ============================================================
         EVALUACIÓN DE TERRENO
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <div class="section-title"><i class="bi bi-tools me-2"></i>Evaluación de Terreno</div>

            <div class="scroll-box">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr class="text-center">
                            <th>RUT</th>
                            <th>Cargo</th>
                            <th>Fecha</th>

                            <th>Nota</th>
                        </tr>
                    </thead>
<tbody>
<?php if (empty($evaluacionesTerreno)): ?>
    <tr>
        <td colspan="<?= count($agrupaciones) + 4 ?>" class="text-center text-muted">
            Sin evaluaciones de terreno registradas
        </td>
    </tr>
<?php else: ?>

<?php foreach ($evaluacionesTerreno as $et): ?>

<?php
    // Obtener estado por agrupación
    $stmtDet = $pdo->prepare("
        SELECT
            d.codigo_area,
            MAX(CASE WHEN d.resultado_item = 'No alcanzó' THEN 1 ELSE 0 END) AS falla
        FROM ceo_evaluacion_terreno_detalle d
        WHERE d.id_evaluacion_terreno = :id
        GROUP BY d.codigo_area
    ");
    $stmtDet->execute(['id' => $et['id']]);
    $estados = $stmtDet->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<tr class="text-center">
    <td><?= esc($et['rut']) ?></td>
    <td><?= esc($et['cargo']) ?></td>
    <td><?= esc(date('d-m-Y', strtotime($et['fecha_evaluacion']))) ?></td>


    <td><?= esc($et['resultado']) ?></td>
</tr>

<?php endforeach; ?>
<?php endif; ?>
</tbody>

                </table>
            </div>

        </div>
    </div>




    <!-- ============================================================
         EVALUACIÓN PRUEBA TEÓRICA
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">

            <div class="section-title"><i class="bi bi-journal-check me-2"></i>Evaluación Prueba</div>

            <div class="scroll-box">
                <table class="table table-sm table-bordered">
                    <thead>
                    <tr class="text-center">
                        <th>RUT</th>
                        <th>Fecha Prueba</th>

                    
                        <th>Nota</th>
                    </tr>
                    </thead>

<tbody>
<?php if (empty($intentos)): ?>
    <tr>
        <td colspan="<?= count($agrupaciones) + 3 ?>" class="text-center text-muted">
            Sin evaluaciones registradas para este servicio
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($intentos as $int): ?>
        <tr class="text-center">
            <td><?= esc($int['rut']) ?></td>
            <td><?= esc(date('d-m-Y', strtotime($int['fecha_rendicion']))) ?></td>


            <td>
                <?= esc($int['notafinal']) ?>

            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>

                </table>
            </div>

        </div>
    </div>




    <!-- ============================================================
         HISTORIAL DE EVALUACIONES
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-5">
        <div class="card-body">

            <div class="section-title"><i class="bi bi-clock-history me-2"></i>Estado Habilitaciones</div>

            <div class="scroll-box">
                <table class="table table-sm table-bordered">
 <thead>
  <tr class="text-center">
      <th>Rut</th>
      <th>Fecha</th>
      <th>Cargo</th>
      <th>Terreno</th>
      <th>Teórica</th>
      <th>Resultado</th>
      <th>Habilitado</th>
  </tr>
</thead>

<tbody>
<?php if (empty($estadoHab)): ?>
  <tr>
      <td colspan="6" class="text-center text-muted">
          Sin información para calcular habilitación
      </td>
  </tr>
<?php else: ?>
  <?php
    $hab = strtoupper(trim((string)$estadoHab['habilitado'])) === 'SI';
  ?>
  <tr class="text-center">
      <td><?= esc($estadoHab['rut']) ?></td>
      <td><?= esc(date('d-m-Y', strtotime($estadoHab['fecha_rendicion']))) ?></td>
      <td><?= esc($estadoHab['cargo']) ?></td>
      <td><?= esc($estadoHab['Terreno']) ?></td>
      <td><?= esc($estadoHab['Teorica']) ?></td>
      <td><strong><?= esc($estadoHab['resultado']) ?></strong></td>
      <td>
        <?php if ($hab): ?>
          <span class="badge bg-success">SI</span>
        <?php else: ?>
          <span class="badge bg-danger">NO</span>
        <?php endif; ?>
      </td>
  </tr>
<?php endif; ?>
</tbody>

                </table>
            </div>

        </div>
    </div>

    <!-- ============================================================
         VIGENCIA GENERAL Y DETALLE
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-5">
        <div class="card-body">

            <div class="section-title">
                <i class="bi bi-calendar-range me-2"></i>Vigencia General y Detalle
            </div>

            <?php
                $hoy = date('Y-m-d');
                $vigenciaActiva = false;

                if (!empty($vigenciaGeneral['fechavig_ini']) && !empty($vigenciaGeneral['fechavig_fin'])) {
                    $vigenciaActiva = ($hoy >= $vigenciaGeneral['fechavig_ini'] && $hoy <= $vigenciaGeneral['fechavig_fin']);
                }
            ?>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="box-label">RUT</div>
                    <div class="data-box"><?= esc($rut) ?></div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">Proceso</div>
                    <div class="data-box"><?= esc((string)$prog) ?></div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">Vigencia Inicio</div>
                    <div class="data-box">
                        <?= !empty($vigenciaGeneral['fechavig_ini']) ? esc(date('d-m-Y', strtotime($vigenciaGeneral['fechavig_ini']))) : 'Sin registro' ?>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">Vigencia Fin</div>
                    <div class="data-box">
                        <?= !empty($vigenciaGeneral['fechavig_fin']) ? esc(date('d-m-Y', strtotime($vigenciaGeneral['fechavig_fin']))) : 'Sin registro' ?>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="box-label">Estado Vigencia General</div>
                    <div class="data-box">
                        <?php if (empty($vigenciaGeneral)): ?>
                            <span class="badge badge-soft-secondary">Sin vigencia general registrada</span>
                        <?php elseif ($vigenciaActiva): ?>
                            <span class="badge badge-soft-success">Vigente</span>
                        <?php else: ?>
                            <span class="badge badge-soft-danger">Vencida</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="scroll-box">
                <table class="table table-sm table-bordered align-middle">
                    <thead>
                        <tr class="text-center">
                            <th>Servicio</th>
                            <th>Tipo</th>
                            <th>Vigencia Inicio</th>
                            <th>Vigencia Fin</th>
                            <th>Nota</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($vigenciaDetalle)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                Sin detalle de vigencia asociado al proceso
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vigenciaDetalle as $vd): ?>
                            <?php
                                $detalleVigente = false;
                                if (!empty($vd['fechavig_ini']) && !empty($vd['fechavig_fin'])) {
                                    $detalleVigente = ($hoy >= $vd['fechavig_ini'] && $hoy <= $vd['fechavig_fin']);
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc($vd['servicio'] ?? '') ?></strong>
                                    <?php if (!empty($vd['descripcion'])): ?>
                                        <div class="small text-muted"><?= esc($vd['descripcion']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <?= esc($vd['tipo'] ?? '-') ?>
                                </td>

                                <td class="text-center">
                                    <?= !empty($vd['fechavig_ini']) ? esc(date('d-m-Y', strtotime($vd['fechavig_ini']))) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?= !empty($vd['fechavig_fin']) ? esc(date('d-m-Y', strtotime($vd['fechavig_fin']))) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?= ($vd['nota'] !== null && $vd['nota'] !== '') ? esc((string)$vd['nota']) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?php if ($detalleVigente): ?>
                                        <span class="badge badge-soft-success">Vigente</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-danger">Vencida</span>
                                    <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- =========================================
     INICIO MODAL SERVICIOS POR HABILITAR
========================================= -->
<div class="modal fade" id="modalHabilitaciones" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">

      <div class="modal-header bg-primary text-white rounded-top-4">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-calendar2-plus me-2"></i>
          Asignar Servicios de habilitación del Trabajador según cargo
        </h5>

        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Datos base ocultos -->
        <input type="hidden" id="mp_rut" value="<?= esc($rut) ?>">
        <input type="hidden" id="mp_cuadrilla" value="<?= (int)($trabajador['cuadrilla'] ?? 0) ?>">
        <input type="hidden" id="mp_edit_id" value="">

        <div class="alert alert-info rounded-3 mb-3">
          Selecciona el <strong>cargo</strong> del trabajador, agrega los <strong>servicios</strong> que debe aprobar y luego presiona <strong>Grabar</strong>.
        </div>

        <!-- Cabecera de ingreso -->
        <div class="row g-2 align-items-end mb-3">

          <div class="col-md-4">
            <label class="form-label fw-semibold">Cargo</label>
            <select id="mp_cargo" class="form-select">
              <option value="">-- Seleccionar --</option>
              <?php foreach ($cargos as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)($trabajador['id_cargo'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                  <?= esc($c['cargo']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Servicio</label>
            <select id="mp_servicio" class="form-select">
              <option value="">-- Seleccionar --</option>
              <?php foreach ($serviciosPrueba as $s): ?>
                <option value="<?= (int)$s['id'] ?>">
                  <?= esc($s['servicio']) ?><?= $s['descripcion'] ? ' - '.esc($s['descripcion']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Otro</label>
            <input type="text" id="mp_otro" class="form-control" maxlength="255" placeholder="Opcional">
          </div>

          <div class="col-12 d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary rounded-3" id="mp_btnAgregar">
              <i class="bi bi-plus-circle me-1"></i> Agregar
            </button>

            <button type="button" class="btn btn-secondary rounded-3 ms-auto" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Cerrar
            </button>
          </div>
        </div>

        <!-- Tabla líneas -->
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
              <tr class="text-center">
                <th style="width:180px;">Cargo</th>
                <th class="text-start">Servicio</th>
                <th class="text-start">Otro</th>
                <th style="width:150px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="mp_body">
              <tr>
                <td colspan="4" class="text-center text-muted">Sin líneas agregadas</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div id="mp_msg" class="mt-2"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-primary rounded-3" id="mp_btnGrabar">
          <i class="bi bi-save me-1"></i> Grabar servicios
        </button>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  const body = document.getElementById('mp_body');
  const msg  = document.getElementById('mp_msg');

  const selCargo    = document.getElementById('mp_cargo');
  const selServicio = document.getElementById('mp_servicio');
  const inpOtro     = document.getElementById('mp_otro');
  const inpEditId   = document.getElementById('mp_edit_id');

  const rut       = document.getElementById('mp_rut')?.value || '';
  const cuadrilla = parseInt(document.getElementById('mp_cuadrilla')?.value || '0', 10);

  const btnAgregar = document.getElementById('mp_btnAgregar');
  const btnGrabar  = document.getElementById('mp_btnGrabar');

  const lines = [];     // nuevas filas no guardadas
  const persisted = []; // filas ya guardadas en BD

  function setMsg(html, kind='info'){
    msg.innerHTML = `<div class="alert alert-${kind} rounded-3 py-2 mb-0">${html}</div>`;
  }

  function clearForm(keepCargo = true){
    if (!keepCargo) selCargo.value = '';
    selServicio.value = '';
    inpOtro.value = '';
    inpEditId.value = '';
    btnAgregar.innerHTML = `<i class="bi bi-plus-circle me-1"></i> Agregar`;
  }

  function render() {
    if (!persisted.length && !lines.length) {
      body.innerHTML = `<tr><td colspan="4" class="text-center text-muted">Sin líneas agregadas</td></tr>`;
      return;
    }

    let html = '';

    persisted.forEach((p, idx) => {
      html += `
        <tr class="text-center table-warning">
          <td>${escapeHtml(p.cargo_txt)}</td>
          <td class="text-start">
            ${escapeHtml(p.servicio_txt)}
            <span class="badge bg-warning text-dark ms-2">Guardado</span>
          </td>
          <td class="text-start">${escapeHtml(p.otro || '')}</td>
          <td>
            <button type="button" class="btn btn-sm btn-outline-primary me-1" data-edit-persisted="${idx}">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-persisted="${idx}">
              <i class="bi bi-trash"></i>
            </button>
          </td>
        </tr>
      `;
    });

    html += lines.map((l, idx) => `
      <tr class="text-center">
        <td>${escapeHtml(l.cargo_txt)}</td>
        <td class="text-start">${escapeHtml(l.servicio_txt)}</td>
        <td class="text-start">${escapeHtml(l.otro || '')}</td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-primary me-1" data-edit-new="${idx}">
            <i class="bi bi-pencil"></i>
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-del="${idx}">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>
    `).join('');

    body.innerHTML = html;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
  }

  function currentDuplicateExists(idCargo, idServicio, excludeNewIdx = null, excludePersistedId = null) {
    const inNew = lines.some((x, idx) =>
      idx !== excludeNewIdx &&
      Number(x.id_cargo) === Number(idCargo) &&
      Number(x.id_servicio) === Number(idServicio)
    );

    const inPersisted = persisted.some(x =>
      Number(x.id) !== Number(excludePersistedId) &&
      Number(x.id_cargo) === Number(idCargo) &&
      Number(x.id_servicio) === Number(idServicio)
    );

    return inNew || inPersisted;
  }

  body.addEventListener('click', async (e) => {
    const btnDelNew = e.target.closest('button[data-del]');
    if (btnDelNew) {
      const idx = parseInt(btnDelNew.getAttribute('data-del'), 10);
      lines.splice(idx, 1);
      render();
      return;
    }

    const btnEditNew = e.target.closest('button[data-edit-new]');
    if (btnEditNew) {
      const idx = parseInt(btnEditNew.getAttribute('data-edit-new'), 10);
      const row = lines[idx];
      if (!row) return;

      selCargo.value = row.id_cargo;
      selServicio.value = row.id_servicio;
      inpOtro.value = row.otro || '';
      inpEditId.value = `new:${idx}`;
      btnAgregar.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Actualizar`;
      return;
    }

    const btnEditPersisted = e.target.closest('button[data-edit-persisted]');
    if (btnEditPersisted) {
      const idx = parseInt(btnEditPersisted.getAttribute('data-edit-persisted'), 10);
      const row = persisted[idx];
      if (!row) return;

      selCargo.value = row.id_cargo;
      selServicio.value = row.id_servicio;
      inpOtro.value = row.otro || '';
      inpEditId.value = `db:${row.id}`;
      btnAgregar.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Actualizar`;
      return;
    }

    const btnDeletePersisted = e.target.closest('button[data-delete-persisted]');
    if (btnDeletePersisted) {
      const idx = parseInt(btnDeletePersisted.getAttribute('data-delete-persisted'), 10);
      const row = persisted[idx];
      if (!row) return;

      if (!confirm(`¿Eliminar el servicio "${row.servicio_txt}" asociado al trabajador?`)) {
        return;
      }

      try {
        const res = await fetch('ajax_servicios_rut_eliminar.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          credentials: 'same-origin',
          body: JSON.stringify({
            id: row.id,
            rut
          })
        });

        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo eliminar');

        persisted.splice(idx, 1);
        setMsg('✅ Servicio eliminado correctamente.', 'success');
        clearForm(true);
        render();
      } catch (err) {
        setMsg('❌ ' + (err.message || 'Error al eliminar'), 'danger');
      }
    }
  });

  btnAgregar?.addEventListener('click', async () => {
    const id_cargo = parseInt(selCargo.value || '0', 10);
    const cargo_txt = selCargo.options[selCargo.selectedIndex]?.text || '';
    const id_servicio = parseInt(selServicio.value || '0', 10);
    const servicio_txt = selServicio.options[selServicio.selectedIndex]?.text || '';
    const otro = (inpOtro.value || '').trim();
    const editId = inpEditId.value || '';

    if (!id_cargo) return setMsg('Selecciona un cargo.', 'warning');
    if (!id_servicio) return setMsg('Selecciona un servicio.', 'warning');

    if (editId.startsWith('new:')) {
      const idx = parseInt(editId.split(':')[1], 10);
      if (currentDuplicateExists(id_cargo, id_servicio, idx, null)) {
        return setMsg('Ese cargo y servicio ya fue agregado.', 'warning');
      }

      lines[idx] = { id_cargo, cargo_txt, id_servicio, servicio_txt, otro };
      setMsg('✅ Línea actualizada en la grilla.', 'success');
      clearForm(true);
      render();
      return;
    }

    if (editId.startsWith('db:')) {
      const id = parseInt(editId.split(':')[1], 10);
      if (currentDuplicateExists(id_cargo, id_servicio, null, id)) {
        return setMsg('Ese cargo y servicio ya existe asociado al trabajador.', 'warning');
      }

      try {
        const res = await fetch('ajax_servicios_rut_actualizar.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          credentials: 'same-origin',
          body: JSON.stringify({
            id,
            rut,
            id_cargo,
            id_servicio,
            otro
          })
        });

        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo actualizar');

        const pos = persisted.findIndex(x => Number(x.id) === Number(id));
        if (pos >= 0) {
          persisted[pos] = {
            ...persisted[pos],
            id_cargo,
            cargo_txt,
            id_servicio,
            servicio_txt,
            otro
          };
        }

        setMsg('✅ Registro actualizado correctamente.', 'success');
        clearForm(true);
        render();
      } catch (err) {
        setMsg('❌ ' + (err.message || 'Error al actualizar'), 'danger');
      }
      return;
    }

    if (currentDuplicateExists(id_cargo, id_servicio, null, null)) {
      return setMsg('Ese cargo y servicio ya fue agregado.', 'warning');
    }

    lines.push({ id_cargo, cargo_txt, id_servicio, servicio_txt, otro });
    msg.innerHTML = '';
    clearForm(true);
    render();
  });

  btnGrabar?.addEventListener('click', async () => {
    if (!rut) return setMsg('No se detectó RUT.', 'danger');
    if (!lines.length) return setMsg('Agrega al menos una línea.', 'warning');

    btnGrabar.disabled = true;
    setMsg('Grabando...', 'info');

    try {
      const res = await fetch('ajax_servicios_rut_guardar.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
          rut,
          cuadrilla,
          items: lines
        })
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Error al grabar');

      setMsg(`✅ Servicios guardados. Insertados: <strong>${json.insertados ?? 0}</strong>. Omitidos: <strong>${json.omitidos ?? 0}</strong>.`, 'success');
      setTimeout(() => location.reload(), 700);

    } catch (err) {
      setMsg('❌ ' + (err.message || 'Error'), 'danger');
    } finally {
      btnGrabar.disabled = false;
    }
  });

  const modalEl = document.getElementById('modalHabilitaciones');

  async function loadServiciosRut(){
    persisted.length = 0;

    try{
      const res = await fetch('ajax_servicios_rut_listar.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ rut, cuadrilla })
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Error cargando servicios');

      (json.data || []).forEach(r => {
        persisted.push({
          id: parseInt(r.id, 10),
          id_cargo: parseInt(r.id_cargo, 10),
          cargo_txt: r.cargo || '',
          id_servicio: parseInt(r.id_servicio, 10),
          servicio_txt: r.servicio || '',
          otro: r.otro || ''
        });
      });

      msg.innerHTML = '';
      clearForm(true);
      render();

    } catch(err){
      setMsg('⚠ No se pudieron cargar los servicios del trabajador: ' + (err.message || 'Error'), 'warning');
    }
  }
  
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', () => {
      loadServiciosRut();
    });
  }
  
  render();
})();
</script>

<!-- =========================================
    FIN MODAL SERVICIOS POR HABILITAR
========================================= -->
</body>
</html>