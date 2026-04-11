<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if (!in_array($rol, [1, 4, 5], true)) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$rut = trim((string)($_GET['rut'] ?? ''));
$msg = '';

$cuadrillas = $pdo->query("
    SELECT DISTINCT f.cuadrilla, f.fecha, s.servicio
    FROM ceo_formacion f
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    ORDER BY f.fecha DESC, f.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$participantes = [];
$respuestas = [];
$idServicio = 0;
$intento = 0;
$idAgrupacion = 0;

if ($cuadrilla > 0) {
    $stmt = $pdo->prepare("
        SELECT p.rut, p.nombre, p.apellidos
        FROM ceo_formacion_participantes p
        WHERE p.id_cuadrilla = :cuadrilla
        ORDER BY p.apellidos, p.nombre
    ");
    $stmt->execute([':cuadrilla' => $cuadrilla]);
    $participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cuadrilla = (int)($_POST['cuadrilla'] ?? 0);
    $rut = trim((string)($_POST['rut'] ?? ''));
    $idServicio = (int)($_POST['id_servicio'] ?? 0);
    $intento = (int)($_POST['intento'] ?? 0);
    $idAgrupacion = (int)($_POST['id_agrupacion'] ?? 0);

    $puntajes = $_POST['puntaje_manual'] ?? [];
    $observaciones = $_POST['observacion'] ?? [];

    try {
        $pdo->beginTransaction();

        foreach ($puntajes as $idPregunta => $puntaje) {
            $idPregunta = (int)$idPregunta;
            $puntajeVal = (int)$puntaje;
            $obs = trim((string)($observaciones[$idPregunta] ?? ''));

            $stmtPeso = $pdo->prepare("SELECT peso FROM ceo_formacion_preguntas_servicios WHERE id = :id");
            $stmtPeso->execute([':id' => $idPregunta]);
            $peso = (int)$stmtPeso->fetchColumn();
            if ($peso <= 0) {
                $peso = 1;
            }
            if ($puntajeVal < 0) {
                $puntajeVal = 0;
            }
            if ($puntajeVal > $peso) {
                $puntajeVal = $peso;
            }

            $stmtUpd = $pdo->prepare("
                UPDATE ceo_resultado_formacion_pruebat
                SET puntaje_manual = :puntaje,
                    revisada = 1,
                    observacion = :obs
                WHERE rut = :rut
                  AND proceso = :cuadrilla
                  AND intento = :intento
                  AND id_pregunta = :id_pregunta
            ");
            $stmtUpd->execute([
                ':puntaje' => $puntajeVal,
                ':obs' => $obs,
                ':rut' => $rut,
                ':cuadrilla' => $cuadrilla,
                ':intento' => $intento,
                ':id_pregunta' => $idPregunta
            ]);
        }

        $stmtPend = $pdo->prepare("
            SELECT COUNT(*)
            FROM ceo_resultado_formacion_pruebat rpt
            INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta
            WHERE rpt.rut = :rut
              AND rpt.proceso = :cuadrilla
              AND rpt.intento = :intento
              AND ps.tipo_pregunta = 'TEXTO_LIBRE'
              AND (rpt.revisada = 0 OR rpt.revisada IS NULL)
        ");
        $stmtPend->execute([
            ':rut' => $rut,
            ':cuadrilla' => $cuadrilla,
            ':intento' => $intento
        ]);
        $pendientes = (int)$stmtPend->fetchColumn();

        $stmtCalc = $pdo->prepare("
            SELECT
                SUM(CASE WHEN ps.tipo_pregunta <> 'TEXTO_LIBRE' AND rpt.validacion = 1 THEN COALESCE(ps.peso,1) ELSE 0 END) AS auto_obt,
                SUM(CASE WHEN ps.tipo_pregunta <> 'TEXTO_LIBRE' THEN COALESCE(ps.peso,1) ELSE 0 END) AS auto_max,
                SUM(CASE WHEN ps.tipo_pregunta = 'TEXTO_LIBRE' THEN COALESCE(ps.peso,1) ELSE 0 END) AS txt_max,
                SUM(CASE WHEN ps.tipo_pregunta = 'TEXTO_LIBRE' THEN COALESCE(rpt.puntaje_manual,0) ELSE 0 END) AS txt_obt
            FROM ceo_resultado_formacion_pruebat rpt
            INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta
            WHERE rpt.rut = :rut
              AND rpt.proceso = :cuadrilla
              AND rpt.intento = :intento
              AND ps.id_servicio = :servicio
        ");
        $stmtCalc->execute([
            ':rut' => $rut,
            ':cuadrilla' => $cuadrilla,
            ':intento' => $intento,
            ':servicio' => $idServicio
        ]);
        $calc = $stmtCalc->fetch(PDO::FETCH_ASSOC);

        $puntajeObtenido = (float)($calc['auto_obt'] ?? 0) + (float)($calc['txt_obt'] ?? 0);
        $puntajeMaximo = (float)($calc['auto_max'] ?? 0) + (float)($calc['txt_max'] ?? 0);
        $porcentaje = ($puntajeMaximo > 0) ? round(($puntajeObtenido / $puntajeMaximo) * 100, 2) : 0.0;

        $stmtPorc = $pdo->prepare("
            SELECT porcentaje
            FROM ceo_porcentaje_agrupacion
            WHERE id_agrupacion = :id_agrupacion
              AND fechadesde <= CURDATE()
              AND activo = 'S'
            ORDER BY fechadesde DESC
            LIMIT 1
        ");
        $stmtPorc->execute([':id_agrupacion' => $idAgrupacion]);
        $porcentajeMinimo = (float)$stmtPorc->fetchColumn();
        if ($porcentajeMinimo <= 0) {
            $porcentajeMinimo = 80.0;
        }

        $resultado = ($porcentaje >= $porcentajeMinimo) ? 'APROBADO' : 'REPROBADO';
        if ($pendientes > 0) {
            $resultado = 'PENDIENTE';
        }

        $notaFinal = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);

        $stmtUpdInt = $pdo->prepare("
            UPDATE ceo_resultado_formacion_intento
            SET puntaje_total = :porcentaje,
                puntaje_obtenido = :puntaje_obtenido,
                puntaje_maximo = :puntaje_maximo,
                notafinal = :nota
            WHERE id = (
                SELECT id FROM ceo_resultado_formacion_intento
                WHERE rut = :rut AND id_servicio = :servicio
                ORDER BY id DESC LIMIT 1
            )
        ");
        $stmtUpdInt->execute([
            ':porcentaje' => $porcentaje,
            ':puntaje_obtenido' => $puntajeObtenido,
            ':puntaje_maximo' => $puntajeMaximo,
            ':nota' => $notaFinal,
            ':rut' => $rut,
            ':servicio' => $idServicio
        ]);

        $stmtUpdProg = $pdo->prepare("
            UPDATE ceo_formacion_programadas
            SET resultado = :resultado,
                fecha_resultado = NOW()
            WHERE id = (
                SELECT id FROM ceo_formacion_programadas
                WHERE rut = :rut AND id_servicio = :servicio AND cuadrilla = :cuadrilla
                ORDER BY id DESC LIMIT 1
            )
        ");
        $stmtUpdProg->execute([
            ':resultado' => $resultado,
            ':rut' => $rut,
            ':servicio' => $idServicio,
            ':cuadrilla' => $cuadrilla
        ]);

        $pdo->commit();
        $msg = '✅ Corrección guardada y recalculada.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = '❌ Error: ' . $e->getMessage();
    }
}

if ($cuadrilla > 0 && $rut !== '') {
    $stmt = $pdo->prepare("
        SELECT id_servicio
        FROM ceo_formacion
        WHERE cuadrilla = :cuadrilla
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':cuadrilla' => $cuadrilla]);
    $idServicio = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, intento
        FROM ceo_formacion_programadas
        WHERE rut = :rut AND id_servicio = :servicio AND cuadrilla = :cuadrilla
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla
    ]);
    $rowProg = $stmt->fetch(PDO::FETCH_ASSOC);
    $intento = (int)($rowProg['intento'] ?? 1);

    $stmt = $pdo->prepare("
        SELECT DISTINCT ps.id_agrupacion
        FROM ceo_resultado_formacion_pruebat rpt
        INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta
        WHERE rpt.rut = :rut AND rpt.proceso = :cuadrilla AND rpt.intento = :intento
        LIMIT 1
    ");
    $stmt->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
        ':intento' => $intento
    ]);
    $idAgrupacion = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            ps.id AS id_pregunta,
            ps.pregunta,
            ps.tipo_pregunta,
            ps.peso,
            rpt.respuesta,
            rpt.respuesta_texto,
            rpt.puntaje_manual,
            rpt.revisada,
            rpt.observacion,
            ap.alternativa
        FROM ceo_resultado_formacion_pruebat rpt
        INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta
        LEFT JOIN ceo_formacion_alternativas_preguntas ap ON ap.id = rpt.respuesta
        WHERE rpt.rut = :rut
          AND rpt.proceso = :cuadrilla
          AND rpt.intento = :intento
        ORDER BY ps.id ASC
    ");
    $stmt->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
        ':intento' => $intento
    ]);
    $respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Revision Texto Libre | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.card {border-radius:12px;}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title h5 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <?php if ($msg): ?>
    <div class="alert alert-info"><?= esc($msg) ?></div>
  <?php endif; ?>

  <div class="card p-3 mb-3">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Cuadrilla</label>
        <select name="cuadrilla" class="form-select" required>
          <option value="">Seleccione...</option>
          <?php foreach ($cuadrillas as $c): ?>
            <option value="<?= (int)$c['cuadrilla'] ?>" <?= $cuadrilla === (int)$c['cuadrilla'] ? 'selected' : '' ?>>
              <?= (int)$c['cuadrilla'] ?> - <?= esc((string)$c['servicio']) ?> (<?= esc((string)$c['fecha']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Participante</label>
        <select name="rut" class="form-select">
          <option value="">Seleccione...</option>
          <?php foreach ($participantes as $p): ?>
            <?php $rutOpt = (string)$p['rut']; ?>
            <option value="<?= esc($rutOpt) ?>" <?= $rut === $rutOpt ? 'selected' : '' ?>>
              <?= esc($rutOpt) ?> - <?= esc((string)$p['nombre']) ?> <?= esc((string)$p['apellidos']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary">Cargar</button>
      </div>
    </form>
  </div>

  <?php if ($cuadrilla > 0 && $rut !== '' && !empty($respuestas)): ?>
  <form method="post" class="card p-3">
    <input type="hidden" name="cuadrilla" value="<?= (int)$cuadrilla ?>">
    <input type="hidden" name="rut" value="<?= esc($rut) ?>">
    <input type="hidden" name="id_servicio" value="<?= (int)$idServicio ?>">
    <input type="hidden" name="intento" value="<?= (int)$intento ?>">
    <input type="hidden" name="id_agrupacion" value="<?= (int)$idAgrupacion ?>">

    <h5 class="text-primary mb-3"><i class="bi bi-clipboard-check me-2"></i>Revision Texto Libre</h5>

    <?php foreach ($respuestas as $r): ?>
      <div class="border rounded p-3 mb-3">
        <div class="mb-2"><strong>Pregunta:</strong> <?= esc((string)$r['pregunta']) ?></div>
        <div class="mb-2"><strong>Tipo:</strong> <?= esc((string)$r['tipo_pregunta']) ?> | <strong>Peso:</strong> <?= (int)$r['peso'] ?></div>

        <?php if (($r['tipo_pregunta'] ?? '') === 'TEXTO_LIBRE'): ?>
          <div class="mb-2"><strong>Respuesta:</strong></div>
          <div class="border rounded p-2 bg-light mb-2"><?= nl2br(esc((string)$r['respuesta_texto'])) ?></div>
          <div class="row g-2">
            <div class="col-md-2">
              <label class="form-label">Puntaje</label>
              <input type="number" name="puntaje_manual[<?= (int)$r['id_pregunta'] ?>]" class="form-control" min="0" max="<?= (int)$r['peso'] ?>" value="<?= esc((string)($r['puntaje_manual'] ?? '')) ?>">
            </div>
            <div class="col-md-10">
              <label class="form-label">Observacion</label>
              <input type="text" name="observacion[<?= (int)$r['id_pregunta'] ?>]" class="form-control" value="<?= esc((string)($r['observacion'] ?? '')) ?>">
            </div>
          </div>
        <?php else: ?>
          <div class="mb-2"><strong>Respuesta seleccionada:</strong> <?= esc((string)($r['alternativa'] ?? '')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="text-end">
      <button class="btn btn-success">Guardar y recalcular</button>
    </div>
  </form>
  <?php elseif ($cuadrilla > 0 && $rut !== ''): ?>
    <div class="alert alert-warning">No se encontraron respuestas para esta seleccion.</div>
  <?php endif; ?>

</div>

</body>
</html>
