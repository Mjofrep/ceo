<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

$rut = trim((string)($_GET['rut'] ?? ''));
$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$idServicio = (int)($_GET['id_servicio'] ?? 0);

$error = '';
$formacion = null;
$participante = null;
$programacion = null;
$respuestas = [];
$resumenAreas = [];

function frmPreguntaEstado(int $validacion): string
{
    return match ($validacion) {
        1 => 'BUENA',
        0 => 'MALA',
        -1 => 'NO CONTESTADA',
        default => 'SIN CLASIFICAR',
    };
}

if ($rut === '' || $cuadrilla <= 0 || $idServicio <= 0) {
    $error = 'Parámetros incompletos para consultar el detalle de preguntas.';
} else {
    try {
        $stmt = $pdo->prepare('
            SELECT f.id, f.cuadrilla, f.fecha, f.jornada, f.id_servicio, fs.servicio, e.nombre AS empresa, u.desc_uo AS uo
            FROM ceo_formacion f
            LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_uo u ON u.id = f.uo
            WHERE f.cuadrilla = :cuadrilla
              AND f.id_servicio = :id_servicio
            ORDER BY f.id DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':cuadrilla' => $cuadrilla,
            ':id_servicio' => $idServicio,
        ]);
        $formacion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare('
            SELECT rut, nombre, apellidos, cargo
            FROM ceo_formacion_participantes
            WHERE rut = :rut
              AND id_cuadrilla = :cuadrilla
            LIMIT 1
        ');
        $stmt->execute([
            ':rut' => $rut,
            ':cuadrilla' => $cuadrilla,
        ]);
        $participante = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare('
            SELECT id, fecha_programacion, fecha_resultado, estado, resultado, intento
            FROM ceo_formacion_programadas
            WHERE rut = :rut
              AND id_servicio = :id_servicio
              AND cuadrilla = :cuadrilla
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([
            ':rut' => $rut,
            ':id_servicio' => $idServicio,
            ':cuadrilla' => $cuadrilla,
        ]);
        $programacion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$formacion) {
            $error = 'No se encontró la formación solicitada.';
        } elseif (!$participante) {
            $error = 'No se encontró el participante en la cuadrilla indicada.';
        } elseif (!$programacion) {
            $error = 'No se encontró la programación de prueba para el participante.';
        } else {
            $stmt = $pdo->prepare('
                SELECT
                    rpt.id_pregunta,
                    rpt.respuesta,
                    rpt.respuesta_texto,
                    rpt.validacion,
                    rpt.fecha_rendicion,
                    rpt.hora_rendicion,
                    ps.pregunta,
                    ps.tipo_pregunta,
                    ps.peso,
                    ps.obligatoria,
                    COALESCE(ac.descripcion, \'Sin área de competencia\') AS area_competencia,
                    COALESCE(ap.alternativa, \'\') AS alternativa_marcada
                FROM ceo_resultado_formacion_pruebat rpt
                INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta
                LEFT JOIN ceo_areacompetencia_formacion ac ON ac.id = ps.areacomp AND ac.id_servicio = ps.id_servicio
                LEFT JOIN ceo_formacion_alternativas_preguntas ap ON ap.id = rpt.respuesta
                WHERE rpt.rut = :rut
                  AND rpt.proceso = :cuadrilla
                  AND rpt.intento = :intento
                  AND ps.id_servicio = :id_servicio
                ORDER BY area_competencia ASC, rpt.id_pregunta ASC
            ');
            $stmt->execute([
                ':rut' => $rut,
                ':cuadrilla' => $cuadrilla,
                ':intento' => (int)$programacion['intento'],
                ':id_servicio' => $idServicio,
            ]);
            $respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($respuestas as $respuesta) {
                $area = trim((string)($respuesta['area_competencia'] ?? '')) ?: 'Sin área de competencia';
                if (!isset($resumenAreas[$area])) {
                    $resumenAreas[$area] = [
                        'area' => $area,
                        'buenas' => 0,
                        'malas' => 0,
                        'no_contestadas' => 0,
                        'total' => 0,
                    ];
                }

                $resumenAreas[$area]['total']++;
                $validacion = (int)($respuesta['validacion'] ?? -99);
                if ($validacion === 1) {
                    $resumenAreas[$area]['buenas']++;
                } elseif ($validacion === 0) {
                    $resumenAreas[$area]['malas']++;
                } elseif ($validacion === -1) {
                    $resumenAreas[$area]['no_contestadas']++;
                }
            }

            $resumenAreas = array_values($resumenAreas);
        }
    } catch (Throwable $e) {
        $error = defined('APP_DEBUG') && APP_DEBUG
            ? 'Error SQL: ' . $e->getMessage()
            : 'No fue posible cargar el detalle de preguntas realizadas.';
    }
}

$nombreCompleto = trim((string)($participante['nombre'] ?? '') . ' ' . (string)($participante['apellidos'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Preguntas Realizadas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.card {border:none; box-shadow:0 2px 8px rgba(0,0,0,.06);}
.table thead th {background:#eaf2fb; white-space:nowrap;}
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
    <a href="formaciones_cuadrilla_detalle.php?cuadrilla=<?= (int)$cuadrilla ?>" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php else: ?>
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="text-primary mb-3"><i class="bi bi-list-check me-2"></i>Preguntas realizadas en la prueba</h5>
        <div class="row g-2">
          <div class="col-md-4"><strong>RUT:</strong> <?= esc($rut) ?></div>
          <div class="col-md-4"><strong>Persona:</strong> <?= esc($nombreCompleto) ?></div>
          <div class="col-md-4"><strong>Cargo:</strong> <?= esc((string)($participante['cargo'] ?? '')) ?></div>
          <div class="col-md-4"><strong>Cuadrilla:</strong> <?= (int)$cuadrilla ?></div>
          <div class="col-md-4"><strong>Servicio:</strong> <?= esc((string)($formacion['servicio'] ?? '')) ?></div>
          <div class="col-md-4"><strong>Intento:</strong> <?= (int)($programacion['intento'] ?? 0) ?></div>
          <div class="col-md-4"><strong>Estado:</strong> <?= esc((string)($programacion['estado'] ?? '')) ?></div>
          <div class="col-md-4"><strong>Resultado:</strong> <?= esc((string)($programacion['resultado'] ?? '')) ?></div>
          <div class="col-md-4"><strong>Fecha programación:</strong> <?= esc((string)($programacion['fecha_programacion'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h6 class="text-primary mb-3"><i class="bi bi-pie-chart me-2"></i>Resumen por área de competencia</h6>
        <?php if (empty($resumenAreas)): ?>
          <div class="text-muted">No hay preguntas registradas para este intento.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead>
                <tr>
                  <th>Área de competencia</th>
                  <th>Buenas</th>
                  <th>Malas</th>
                  <th>No contestadas</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($resumenAreas as $area): ?>
                <tr>
                  <td><?= esc((string)$area['area']) ?></td>
                  <td><?= (int)$area['buenas'] ?></td>
                  <td><?= (int)$area['malas'] ?></td>
                  <td><?= (int)$area['no_contestadas'] ?></td>
                  <td><?= (int)$area['total'] ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h6 class="text-primary mb-3"><i class="bi bi-card-list me-2"></i>Detalle de preguntas efectivamente rendidas</h6>
        <?php if (empty($respuestas)): ?>
          <div class="text-muted">No hay preguntas registradas para este intento.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ID Pregunta</th>
                  <th>Área de competencia</th>
                  <th>Pregunta</th>
                  <th>Tipo</th>
                  <th>Respuesta registrada</th>
                  <th>Resultado</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($respuestas as $idx => $respuesta): ?>
                <?php
                  $textoRespuesta = trim((string)($respuesta['alternativa_marcada'] ?? ''));
                  if ($textoRespuesta === '') {
                      $textoRespuesta = trim((string)($respuesta['respuesta_texto'] ?? ''));
                  }
                ?>
                <tr>
                  <td><?= $idx + 1 ?></td>
                  <td><?= (int)$respuesta['id_pregunta'] ?></td>
                  <td><?= esc((string)$respuesta['area_competencia']) ?></td>
                  <td><?= esc((string)$respuesta['pregunta']) ?></td>
                  <td><?= esc((string)$respuesta['tipo_pregunta']) ?></td>
                  <td><?= esc($textoRespuesta) ?></td>
                  <td><?= esc(frmPreguntaEstado((int)($respuesta['validacion'] ?? -99))) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
