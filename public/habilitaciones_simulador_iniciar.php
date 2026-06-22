<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/Csrf.php';

if (!function_exists('simImagenUrl')) {
    function simImagenUrl(string $ruta): string
    {
        $ruta = trim($ruta);
        if ($ruta === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $ruta)) {
            return $ruta;
        }
        if (defined('APP_BASE') && $ruta !== '' && strncmp($ruta, APP_BASE, strlen(APP_BASE)) === 0) {
            return $ruta;
        }
        if (strncmp($ruta, '/public/uploads/', 16) === 0) {
            $ruta = substr($ruta, 7);
        }
        if (strncmp($ruta, '/uploads/', 9) === 0) {
            return (defined('APP_BASE') ? APP_BASE : '') . $ruta;
        }
        if (strncmp($ruta, 'uploads/', 8) === 0) {
            return (defined('APP_BASE') ? APP_BASE : '') . '/' . $ruta;
        }
        return (defined('APP_BASE') ? APP_BASE : '') . '/' . ltrim($ruta, '/');
    }
}

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($rol !== 1) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

function cargarSimulacionHabilitacionProgramada(PDO $pdo, int $idProgramada, string $rut): ?array
{
    if ($idProgramada <= 0 || $rut === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            ep.id,
            ep.rut,
            ep.id_servicio,
            ep.cuadrilla,
            ep.intento,
            ep.tipo,
            ep.id_proceso_habilitacion,
            ph.numero_proceso,
            hp.nombre,
            hp.apellidos,
            sp.servicio
        FROM ceo_evaluaciones_programadas ep
        LEFT JOIN ceo_proceso_habilitacion ph
            ON ph.id = ep.id_proceso_habilitacion
        LEFT JOIN ceo_habilitacion_participantes hp
            ON hp.rut = ep.rut
           AND hp.id_cuadrilla = ep.cuadrilla
        LEFT JOIN ceo_servicios_pruebas sp
            ON sp.id = ep.id_servicio
        WHERE ep.id = :id
          AND ep.rut = :rut
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $idProgramada,
        ':rut' => $rut,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$pdo = db();

$err = '';
$msg = '';
$resultadoSim = [];

$maxIdle = 2 * 60 * 60;
$elapsedSession = 0;
$remainingSession = $maxIdle;
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsedSession = max(0, time() - (int)$_SESSION['LAST_ACTIVITY']);
    $remainingSession = max(0, $maxIdle - $elapsedSession);
}

try {
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    $err = 'Error de conexion DB: ' . $e->getMessage();
}

$data = [
    'id_servicio' => 0,
    'id_agrupacion' => 0,
    'rut_alumno' => '',
    'proceso' => 0,
    'cuadrilla' => 0,
    'numero_proceso' => '',
    'servicio' => '',
    'participante' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesión expirada. Recarga la página.';
    } else {
        $data['id_servicio'] = (int)($_POST['id_servicio'] ?? 0);
        $data['id_agrupacion'] = (int)($_POST['id_agrupacion'] ?? 0);
        $data['rut_alumno'] = trim((string)($_POST['rut_alumno'] ?? ''));
        $data['proceso'] = (int)($_POST['proceso'] ?? 0);

        $respuestas = $_POST['respuestas'] ?? [];
        $respuestasTexto = $_POST['respuestas_texto'] ?? [];
        $preguntas = $_POST['preguntas'] ?? [];

        $procRow = cargarSimulacionHabilitacionProgramada($pdo, $data['proceso'], $data['rut_alumno']);
        if ($procRow) {
            if ($data['id_servicio'] <= 0) {
                $data['id_servicio'] = (int)($procRow['id_servicio'] ?? 0);
            }
            $data['cuadrilla'] = (int)($procRow['cuadrilla'] ?? 0);
            $data['numero_proceso'] = (string)($procRow['numero_proceso'] ?? '');
            $data['servicio'] = (string)($procRow['servicio'] ?? '');
            $data['participante'] = trim((string)($procRow['nombre'] ?? '') . ' ' . (string)($procRow['apellidos'] ?? ''));
        }

        if (!$preguntas) {
            $err = 'No se recibieron preguntas.';
        } elseif ($data['id_servicio'] <= 0 || $data['rut_alumno'] === '') {
            $err = 'Faltan datos obligatorios para simular la prueba.';
        } else {
            try {
                $preguntasIds = array_map('intval', $preguntas);
                $placeholders = implode(',', array_fill(0, count($preguntasIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT
                        p.id,
                        p.id_servicio,
                        'ALT' AS tipo_pregunta,
                        1 AS peso,
                        p.areacomp,
                        COALESCE(ac.descripcion, '') AS area_descripcion
                    FROM ceo_preguntas_servicios p
                    LEFT JOIN ceo_areacompetencias ac
                        ON ac.id = p.areacomp
                       AND ac.id_servicio = p.id_servicio
                    WHERE p.id IN ($placeholders)
                ");
                $stmt->execute($preguntasIds);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = [];
                foreach ($rows as $row) {
                    $map[(int)$row['id']] = [
                        'tipo' => (string)($row['tipo_pregunta'] ?? 'ALT'),
                        'id_servicio' => (int)($row['id_servicio'] ?? 0),
                        'peso' => (int)($row['peso'] ?? 1),
                        'areacomp' => (int)($row['areacomp'] ?? 0),
                        'area_descripcion' => (string)($row['area_descripcion'] ?? ''),
                    ];
                }

                $stmtCorrecta = $pdo->prepare("
                    SELECT id
                    FROM ceo_alternativas_preguntas
                    WHERE id = :id
                      AND id_pregunta = :id_pregunta
                      AND correcta = 'S'
                    LIMIT 1
                ");

                $correctas = 0;
                $incorrectas = 0;
                $ncontestadas = 0;
                $puntajeObtenido = 0.0;
                $puntajeMaximo = 0.0;
                $areaSummary = [];

                foreach ($preguntasIds as $idPregunta) {
                    $tipo = $map[$idPregunta]['tipo'] ?? 'ALT';
                    $idServicioPregunta = (int)($map[$idPregunta]['id_servicio'] ?? 0);
                    $peso = (int)($map[$idPregunta]['peso'] ?? 1);
                    $areaId = (int)($map[$idPregunta]['areacomp'] ?? 0);
                    $areaDescripcion = trim((string)($map[$idPregunta]['area_descripcion'] ?? ''));
                    $areaLabel = $areaDescripcion !== '' ? $areaDescripcion : 'Sin area de competencia';

                    $areaKey = $areaId > 0 ? ('S:' . $idServicioPregunta . ':A:' . $areaId) : 'SIN_AREA';
                    if (!isset($areaSummary[$areaKey])) {
                        $areaSummary[$areaKey] = [
                            'id_servicio' => $idServicioPregunta,
                            'area_id' => $areaId,
                            'area' => $areaLabel,
                            'preguntas' => 0,
                        ];
                    }
                    $areaSummary[$areaKey]['preguntas']++;

                    if ($tipo === 'TEXTO_LIBRE') {
                        continue;
                    }

                    $puntajeMaximo += $peso;

                    if (isset($respuestas[$idPregunta])) {
                        $idAlt = (int)$respuestas[$idPregunta];
                        $stmtCorrecta->execute([
                            ':id' => $idAlt,
                            ':id_pregunta' => $idPregunta,
                        ]);
                        $isCorrecta = $stmtCorrecta->fetchColumn();
                        if ($isCorrecta) {
                            $correctas++;
                            $puntajeObtenido += $peso;
                        } else {
                            $incorrectas++;
                        }
                    } else {
                        $ncontestadas++;
                    }
                }

                $stmtPorc = $pdo->prepare("
                    SELECT porcentaje
                    FROM ceo_porcentaje_agrupacion
                    WHERE id_agrupacion = :id_agrupacion
                      AND fechadesde <= CURDATE()
                      AND activo = 'S'
                    ORDER BY fechadesde DESC
                    LIMIT 1
                ");
                $stmtPorc->execute([':id_agrupacion' => $data['id_agrupacion']]);
                $porcentajeMinimo = (float)$stmtPorc->fetchColumn();

                if ($porcentajeMinimo <= 0) {
                    throw new RuntimeException('No existe porcentaje mínimo de aprobación vigente.');
                }

                $porcentaje = $puntajeMaximo > 0
                    ? round(($puntajeObtenido / $puntajeMaximo) * 100, 2)
                    : 0.0;

                $resultado = ($porcentaje >= $porcentajeMinimo) ? 'APROBADO' : 'REPROBADO';
                $notaFinal = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);

                $resultadoSim = [
                    'correctas' => $correctas,
                    'incorrectas' => $incorrectas,
                    'ncontestadas' => $ncontestadas,
                    'puntaje_obtenido' => $puntajeObtenido,
                    'puntaje_maximo' => $puntajeMaximo,
                    'porcentaje' => $porcentaje,
                    'porcentaje_minimo' => $porcentajeMinimo,
                    'nota' => $notaFinal,
                    'resultado' => $resultado,
                    'areas' => array_values($areaSummary),
                ];
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
    }
} else {
    $data['rut_alumno'] = trim((string)($_GET['rut'] ?? ''));
    $data['proceso'] = (int)($_GET['id_programada'] ?? 0);
    $data['id_servicio'] = (int)($_GET['id_servicio'] ?? 0);
    $data['id_agrupacion'] = (int)($_GET['id_agrupacion'] ?? 0);

    if ($data['rut_alumno'] === '' || $data['proceso'] <= 0) {
        $err = 'Parámetros incompletos.';
    } else {
        $procRow = cargarSimulacionHabilitacionProgramada($pdo, $data['proceso'], $data['rut_alumno']);
        if (!$procRow) {
            $err = 'No se encontró programación pendiente.';
        } else {
            $data['id_servicio'] = (int)($procRow['id_servicio'] ?? 0);
            $data['cuadrilla'] = (int)($procRow['cuadrilla'] ?? 0);
            $data['numero_proceso'] = (string)($procRow['numero_proceso'] ?? '');
            $data['servicio'] = (string)($procRow['servicio'] ?? '');
            $data['participante'] = trim((string)($procRow['nombre'] ?? '') . ' ' . (string)($procRow['apellidos'] ?? ''));
        }
    }
}

if ($err === '' && $data['rut_alumno'] !== '') {
    $msg = 'Simulador cargado para RUT: ' . $data['rut_alumno'] . ' / programación: ' . $data['proceso'];
}

$agrupacion = null;
$preguntas = [];
$totalPreguntas = 0;
$tiempoTotalSegundos = 0;

if ($err === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($data['id_agrupacion'] <= 0) {
        $sqlAgr = "
            SELECT id, titulo, tiempo, cantidad
            FROM ceo_agrupacion
            WHERE id_servicio = :id_servicio
            ORDER BY id ASC
            LIMIT 1
        ";
        $paramsAgr = [':id_servicio' => $data['id_servicio']];
    } else {
        $sqlAgr = "
            SELECT id, titulo, tiempo, cantidad
            FROM ceo_agrupacion
            WHERE id = :id_agrupacion
              AND id_servicio = :id_servicio
            LIMIT 1
        ";
        $paramsAgr = [
            ':id_servicio' => $data['id_servicio'],
            ':id_agrupacion' => $data['id_agrupacion'],
        ];
    }

    $stmtAgr = $pdo->prepare($sqlAgr);
    $stmtAgr->execute($paramsAgr);
    $agrupacion = $stmtAgr->fetch(PDO::FETCH_ASSOC);

    if (!$agrupacion) {
        $err = 'No se encontró agrupación.';
    } else {
        $data['id_agrupacion'] = (int)$agrupacion['id'];
        $cantidadPreguntas = (int)$agrupacion['cantidad'];
        $preguntas = [];

        $stmtAvail = $pdo->prepare("
            SELECT areacomp, COUNT(*) AS total
            FROM ceo_preguntas_servicios
            WHERE id_servicio = :id_servicio
              AND id_agrupacion = :id_agrupacion
              AND estado = 'S'
              AND areacomp IS NOT NULL
            GROUP BY areacomp
        ");
        $stmtAvail->execute([
            ':id_servicio' => $data['id_servicio'],
            ':id_agrupacion' => $data['id_agrupacion'],
        ]);
        $availableRows = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);

        $availableMap = [];
        foreach ($availableRows as $row) {
            $areaId = (int)($row['areacomp'] ?? 0);
            if ($areaId <= 0) {
                continue;
            }
            $availableMap[$areaId] = (int)($row['total'] ?? 0);
        }

        $stmtCfg = $pdo->prepare("
            SELECT id_area, porcentaje
            FROM ceo_habilitacion_areascompetencias_pct
            WHERE id_servicio = :id_servicio
        ");
        $stmtCfg->execute([':id_servicio' => $data['id_servicio']]);
        $configRows = $stmtCfg->fetchAll(PDO::FETCH_ASSOC);

        $useConfig = $cantidadPreguntas > 0 && !empty($configRows) && !empty($availableMap);

        if ($useConfig) {
            $distribution = formacionDistribuirCuotasPorArea($cantidadPreguntas, $configRows, $availableMap);
            foreach (($distribution['additional'] ?? []) as $areaId => $assigned) {
                if ((int)$assigned <= 0) {
                    continue;
                }

                $sqlArea = "
                    SELECT
                        id,
                        pregunta,
                        id_servicio,
                        imagen,
                        areacomp,
                        'ALT' AS tipo_pregunta,
                        1 AS peso
                    FROM ceo_preguntas_servicios
                    WHERE id_servicio = ?
                      AND id_agrupacion = ?
                      AND estado = 'S'
                      AND areacomp = ?
                ";
                $excludeIds = array_map(static fn($q): int => (int)$q['id'], $preguntas);
                if (!empty($excludeIds)) {
                    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                    $sqlArea .= " AND id NOT IN ($placeholders) ";
                }
                $sqlArea .= " ORDER BY RAND() LIMIT ?";

                $params = [$data['id_servicio'], $data['id_agrupacion'], (int)$areaId];
                if (!empty($excludeIds)) {
                    $params = array_merge($params, $excludeIds);
                }
                $params[] = (int)$assigned;

                $stmtArea = $pdo->prepare($sqlArea);
                $stmtArea->execute($params);
                $preguntas = array_merge($preguntas, $stmtArea->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        if (count($preguntas) < $cantidadPreguntas) {
            $faltantes = $cantidadPreguntas - count($preguntas);
            $excludeIds = array_map(static fn($q): int => (int)$q['id'], $preguntas);
            $sqlExtra = "
                SELECT
                    id,
                    pregunta,
                    id_servicio,
                    imagen,
                    areacomp,
                    'ALT' AS tipo_pregunta,
                    1 AS peso
                FROM ceo_preguntas_servicios
                WHERE id_servicio = ?
                  AND id_agrupacion = ?
                  AND estado = 'S'
            ";
            if (!empty($excludeIds)) {
                $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                $sqlExtra .= " AND id NOT IN ($placeholders) ";
            }
            $sqlExtra .= " ORDER BY RAND() LIMIT ?";

            $params = [$data['id_servicio'], $data['id_agrupacion']];
            if (!empty($excludeIds)) {
                $params = array_merge($params, $excludeIds);
            }
            $params[] = $faltantes;

            $stmtExtra = $pdo->prepare($sqlExtra);
            $stmtExtra->execute($params);
            $preguntas = array_merge($preguntas, $stmtExtra->fetchAll(PDO::FETCH_ASSOC));
        }

        $totalPreguntas = count($preguntas);

        if ($totalPreguntas === 0) {
            $err = 'No hay preguntas configuradas.';
        } else {
            $sqlAlt = "
                SELECT id, alternativa, id_pregunta, estado, imagen, correcta
                FROM ceo_alternativas_preguntas
                WHERE id_pregunta = :id_pregunta
                  AND estado = 'S'
                ORDER BY id ASC
            ";

            $stmtAlt = $pdo->prepare($sqlAlt);
            foreach ($preguntas as &$preg) {
                $stmtAlt->execute([':id_pregunta' => $preg['id']]);
                $preg['alternativas'] = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($preg);
        }
    }
}

if (!empty($agrupacion['tiempo'])) {
    $partes = array_map('intval', explode(':', (string)$agrupacion['tiempo']));
    $hh = $partes[0] ?? 0;
    $mm = $partes[1] ?? 0;
    $ss = $partes[2] ?? 0;
    $tiempoTotalSegundos = ($hh * 3600) + ($mm * 60) + $ss;
} else {
    $tiempoTotalSegundos = 45 * 60;
}

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Habilitaciones - Simulador | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
  background-color: #f5f7fb;
}
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.brand-title { color:#0065a4; font-weight:600; }
.question-card {
  border-radius: 0.75rem;
  box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075);
}
.question-title {
  font-weight: 600;
  font-size: 1.05rem;
}
.timer-badge {
  font-size: 1rem;
}
.opcion-label {
  cursor: pointer;
}
.btn-pregunta-circle {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  font-size: 0.9rem;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background-color: #adb5bd;
  color: #fff;
}
.btn-pregunta-circle.active {
  background-color: #0d6efd;
}
.btn-pregunta-circle.answered {
  background-color: #ffc107;
  color: #212529;
  font-size: 0.9rem;
  font-weight: bold;
}
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
    <a href="habilitaciones_simulador.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <?php if ($err !== ''): ?>
    <div class="alert alert-danger"><?= esc($err) ?></div>
  <?php else: ?>
    <?php if ($msg !== ''): ?>
      <div class="alert alert-info"><?= esc($msg) ?></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($err === '' && !empty($resultadoSim)): ?>
    <div class="card p-3">
      <h5 class="text-primary">Resultado Simulación</h5>
      <div class="row g-2">
        <div class="col-md-3"><strong>Correctas:</strong> <?= (int)$resultadoSim['correctas'] ?></div>
        <div class="col-md-3"><strong>Incorrectas:</strong> <?= (int)$resultadoSim['incorrectas'] ?></div>
        <div class="col-md-3"><strong>No contestadas:</strong> <?= (int)$resultadoSim['ncontestadas'] ?></div>
        <div class="col-md-3"><strong>Puntaje:</strong> <?= esc((string)$resultadoSim['puntaje_obtenido']) ?> / <?= esc((string)$resultadoSim['puntaje_maximo']) ?></div>
        <div class="col-md-3"><strong>Porcentaje:</strong> <?= esc((string)$resultadoSim['porcentaje']) ?>%</div>
        <div class="col-md-3"><strong>Mínimo aprobación:</strong> <?= esc((string)$resultadoSim['porcentaje_minimo']) ?>%</div>
        <div class="col-md-3"><strong>Nota:</strong> <?= esc((string)$resultadoSim['nota']) ?></div>
        <div class="col-md-3"><strong>Resultado:</strong> <?= esc((string)$resultadoSim['resultado']) ?></div>
      </div>
      <?php if (!empty($resultadoSim['areas'])): ?>
        <div class="mt-4">
          <h6 class="text-secondary mb-3">Preguntas efectivas por área de competencia</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Área de competencia</th>
                  <th class="text-center">Preguntas efectivas</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($resultadoSim['areas'] as $area): ?>
                  <tr>
                    <td><?= esc((string)($area['area'] ?? 'Sin area de competencia')) ?></td>
                    <td class="text-center"><?= (int)($area['preguntas'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
      <div class="mt-3">
        <a href="habilitaciones_simulador.php" class="btn btn-outline-secondary">Volver</a>
      </div>
    </div>
  <?php elseif ($err === '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

    <div class="row mb-3">
      <div class="col-lg-8">
        <h1 class="h4 mb-1">
          <?= $agrupacion ? esc((string)$agrupacion['titulo']) : 'Prueba Teórica' ?>
        </h1>
        <p class="text-muted mb-0">
          Servicio: <strong><?= esc($data['servicio'] !== '' ? $data['servicio'] : 'CEO / Evaluación Teórica') ?></strong>
        </p>
        <?php if ($data['participante'] !== ''): ?>
          <p class="text-muted mb-0">
            Participante: <strong><?= esc($data['participante']) ?></strong>
          </p>
        <?php endif; ?>
        <?php if ($data['rut_alumno'] !== ''): ?>
          <p class="text-muted mb-0">
            RUT: <strong><?= esc($data['rut_alumno']) ?></strong>
          </p>
        <?php endif; ?>
        <p class="text-muted mb-0">
          <?php if ($data['numero_proceso'] !== ''): ?>
            Proceso: <strong><?= esc($data['numero_proceso']) ?></strong>
          <?php endif; ?>
          <?php if ($data['cuadrilla'] > 0): ?>
            <?php if ($data['numero_proceso'] !== ''): ?> · <?php endif; ?>
            Cuadrilla: <strong><?= (int)$data['cuadrilla'] ?></strong>
          <?php endif; ?>
        </p>
      </div>
      <div class="col-lg-4 mt-3 mt-lg-0 text-lg-end">
        <div class="card border-0 shadow-sm d-inline-block">
          <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
            <div class="text-primary">
              <i class="bi bi-clock-history"></i>
            </div>
            <div>
              <div class="small text-muted">Tiempo restante</div>
              <div class="fw-bold" id="timer">--:--</div>
            </div>
            <div class="ms-3 small text-muted">
              La simulación se cerrará al terminar
            </div>
          </div>
        </div>
        <div class="small text-muted mt-2" id="session-timer"
             data-elapsed="<?= (int)$elapsedSession ?>"
             data-remaining="<?= (int)$remainingSession ?>">
          Sesión: -- restantes | -- transcurridos
        </div>
      </div>
    </div>

    <div class="mb-3">
      <div class="d-flex justify-content-between mb-1">
        <small><strong>Progreso de la prueba</strong></small>
        <small><span id="progreso-texto">0 / <?= (int)$totalPreguntas ?> respondidas</span></small>
      </div>
      <div class="progress" style="height: 1.1rem;">
        <div id="progreso-barra" class="progress-bar" role="progressbar"
             style="width: 0%;" aria-valuenow="0"
             aria-valuemin="0" aria-valuemax="<?= (int)$totalPreguntas ?>">
          0%
        </div>
      </div>
    </div>

    <form id="form-prueba" method="post" action="habilitaciones_simulador_iniciar.php">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="id_servicio" value="<?= (int)$data['id_servicio'] ?>">
      <input type="hidden" name="id_agrupacion" value="<?= (int)$data['id_agrupacion'] ?>">
      <input type="hidden" name="rut_alumno" value="<?= esc($data['rut_alumno']) ?>">
      <input type="hidden" name="proceso" value="<?= (int)$data['proceso'] ?>">
      <input type="hidden" id="tiempo_restante" name="tiempo_restante" value="<?= (int)$tiempoTotalSegundos ?>">
      <?php foreach ($preguntas as $preg): ?>
        <input type="hidden" name="preguntas[]" value="<?= (int)$preg['id'] ?>">
      <?php endforeach; ?>

      <div class="card mb-3">
        <div class="card-body text-center">
          <div class="d-flex flex-wrap justify-content-center gap-2" id="nav-preguntas">
            <?php for ($i = 1; $i <= $totalPreguntas; $i++): ?>
              <button type="button" class="btn-pregunta-circle pregunta-nav" data-index="<?= $i ?>" id="nav_<?= $i ?>">
                <?= $i ?>
              </button>
            <?php endfor; ?>
          </div>
          <div class="mt-2 small text-muted">Preguntas: <strong><?= (int)$totalPreguntas ?></strong></div>
        </div>
      </div>

      <?php $indice = 1; foreach ($preguntas as $preg): ?>
        <div class="card question-card mb-3 pregunta-item" id="pregunta_<?= $indice ?>" data-index="<?= $indice ?>" style="<?= $indice === 1 ? '' : 'display:none;' ?>">
          <div class="card-body">
            <h6 class="text-muted mb-1">Pregunta <?= $indice ?> de <?= (int)$totalPreguntas ?></h6>
            <p class="question-title mb-3"><?= esc((string)$preg['pregunta']) ?></p>

            <?php if (!empty($preg['imagen'])): ?>
              <div class="mb-3">
                <img src="<?= esc(simImagenUrl((string)$preg['imagen'])) ?>" alt="Imagen pregunta" class="img-fluid rounded">
              </div>
            <?php endif; ?>

            <?php if (($preg['tipo_pregunta'] ?? '') === 'TEXTO_LIBRE'): ?>
              <div class="mb-2">
                <textarea name="respuestas_texto[<?= (int)$preg['id'] ?>]" class="form-control" rows="4" maxlength="4000" placeholder="Escriba su respuesta..."></textarea>
              </div>
            <?php elseif (!empty($preg['alternativas'])): ?>
              <?php foreach ($preg['alternativas'] as $alt): ?>
                <div class="form-check mb-2">
                  <input class="form-check-input respuesta-radio" type="radio" name="respuestas[<?= (int)$preg['id'] ?>]" id="alt_<?= (int)$preg['id'] ?>_<?= (int)$alt['id'] ?>" value="<?= (int)$alt['id'] ?>" data-index="<?= $indice ?>">
                  <label class="form-check-label opcion-label" for="alt_<?= (int)$preg['id'] ?>_<?= (int)$alt['id'] ?>">
                    <?= esc((string)$alt['alternativa']) ?>
                  </label>
                  <?php if (!empty($alt['imagen'])): ?>
                    <div class="mt-1">
                      <img src="<?= esc(simImagenUrl((string)$alt['imagen'])) ?>" alt="Imagen alternativa" class="img-fluid rounded">
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="text-muted">No hay alternativas configuradas.</p>
            <?php endif; ?>
          </div>
        </div>
      <?php $indice++; endforeach; ?>

      <div class="d-flex justify-content-between mt-3">
        <button type="button" id="btnAnterior" class="btn btn-outline-secondary">← Anterior</button>
        <button type="button" id="btnSiguiente" class="btn btn-primary">Siguiente →</button>
      </div>
      <div class="d-flex justify-content-center mt-4">
        <button type="button" id="btn-finalizar" class="btn btn-danger">Finalizar simulación</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function () {
  const tiempoTotal = <?= (int)$tiempoTotalSegundos ?>;
  let tiempoRestante = tiempoTotal;

  const timerSpan = document.getElementById('timer');
  const inputTiempo = document.getElementById('tiempo_restante');
  const formPrueba = document.getElementById('form-prueba');
  const btnFinalizar = document.getElementById('btn-finalizar');

  const keepaliveUrl = '/ceo.noetica.cl/public/ajax_keepalive.php';
  const keepaliveIntervalMs = 5 * 60 * 1000;
  let keepaliveId = null;
  let envioEnCurso = false;

  function syncTiempoRestante() {
    window.ceoTiempoRestante = tiempoRestante;
  }

  function enviarFormularioFinal() {
    if (!formPrueba || envioEnCurso) {
      return;
    }

    envioEnCurso = true;
    window.pruebaFinalizada = true;
    stopKeepalive();
    formPrueba.submit();
  }

  function startKeepalive() {
    if (keepaliveId) return;
    keepaliveId = setInterval(() => {
      fetch(keepaliveUrl, { cache: 'no-store' }).catch(() => {});
    }, keepaliveIntervalMs);
  }

  function stopKeepalive() {
    if (keepaliveId) {
      clearInterval(keepaliveId);
      keepaliveId = null;
    }
  }

  function formatoTiempo(segundos) {
    const m = String(Math.floor(segundos / 60)).padStart(2, '0');
    const s = String(segundos % 60).padStart(2, '0');
    return m + ':' + s;
  }

  function tick() {
    tiempoRestante--;
    if (tiempoRestante < 0) tiempoRestante = 0;

    if (timerSpan) {
      timerSpan.textContent = formatoTiempo(tiempoRestante);
    }
    if (inputTiempo) {
      inputTiempo.value = tiempoRestante;
    }
    syncTiempoRestante();

    if (tiempoRestante <= 0) {
      enviarFormularioFinal();
      return;
    }

    setTimeout(tick, 1000);
  }

  if (formPrueba && timerSpan) {
    timerSpan.textContent = formatoTiempo(tiempoRestante);
    syncTiempoRestante();
    setTimeout(tick, 1000);
    startKeepalive();
  }

  if (btnFinalizar && formPrueba) {
    btnFinalizar.addEventListener('click', function () {
      if (confirm('¿Seguro que deseas finalizar la simulación?')) {
        enviarFormularioFinal();
      }
    });
  }

  window.addEventListener('beforeunload', stopKeepalive);

  const sessionTimer = document.getElementById('session-timer');
  if (sessionTimer) {
    let elapsed = parseInt(sessionTimer.dataset.elapsed || '0', 10);
    let remaining = parseInt(sessionTimer.dataset.remaining || '0', 10);

    function fmtSession(segundos) {
      const h = String(Math.floor(segundos / 3600)).padStart(2, '0');
      const m = String(Math.floor((segundos % 3600) / 60)).padStart(2, '0');
      const s = String(segundos % 60).padStart(2, '0');
      return h + ':' + m + ':' + s;
    }

    function updateSessionTimer() {
      sessionTimer.textContent = 'Sesión: ' + fmtSession(Math.max(remaining, 0)) + ' restantes | ' + fmtSession(Math.max(elapsed, 0)) + ' transcurridos';
      elapsed++;
      remaining--;
      setTimeout(updateSessionTimer, 1000);
    }

    updateSessionTimer();
  }

  const totalPreguntas = <?= (int)$totalPreguntas ?>;
  const radios = document.querySelectorAll('.respuesta-radio');
  const textos = document.querySelectorAll('textarea[name^="respuestas_texto"]');
  const progresoBarra = document.getElementById('progreso-barra');
  const progresoTexto = document.getElementById('progreso-texto');

  function actualizarProgreso() {
    if (!totalPreguntas || !progresoBarra || !progresoTexto) return;

    const contestadas = new Set();
    document.querySelectorAll('.btn-pregunta-circle').forEach(btn => btn.classList.remove('answered'));

    radios.forEach(radio => {
      if (radio.checked) {
        contestadas.add(radio.name);
        const idx = parseInt(radio.dataset.index || '0', 10);
        if (idx > 0) {
          const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
          if (btn) btn.classList.add('answered');
        }
      }
    });

    textos.forEach(textarea => {
      if (textarea.value.trim() !== '') {
        contestadas.add(textarea.name);
        const idx = parseInt((textarea.closest('.pregunta-item')?.dataset.index || '0'), 10);
        if (idx > 0) {
          const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
          if (btn) btn.classList.add('answered');
        }
      }
    });

    const numContestadas = contestadas.size;
    const porcentaje = totalPreguntas > 0 ? Math.round((numContestadas / totalPreguntas) * 100) : 0;

    progresoBarra.style.width = porcentaje + '%';
    progresoBarra.setAttribute('aria-valuenow', String(numContestadas));
    progresoBarra.textContent = porcentaje + '%';
    progresoTexto.textContent = numContestadas + ' / ' + totalPreguntas + ' respondidas';
  }

  radios.forEach(radio => {
    radio.addEventListener('change', actualizarProgreso);
  });

  textos.forEach(textarea => {
    textarea.addEventListener('input', actualizarProgreso);
  });

  const navBtns = document.querySelectorAll('.pregunta-nav');
  const items = document.querySelectorAll('.pregunta-item');
  const btnAnterior = document.getElementById('btnAnterior');
  const btnSiguiente = document.getElementById('btnSiguiente');
  let actual = 1;

  function mostrarPregunta(indice) {
    if (indice < 1 || indice > totalPreguntas) return;
    actual = indice;

    items.forEach(item => {
      const idx = parseInt(item.dataset.index || '0', 10);
      item.style.display = idx === actual ? '' : 'none';
    });

    navBtns.forEach(btn => {
      const idx = parseInt(btn.dataset.index || '0', 10);
      btn.classList.toggle('active', idx === actual);
    });

    if (btnAnterior) btnAnterior.disabled = actual === 1;
    if (btnSiguiente) btnSiguiente.disabled = actual === totalPreguntas;
  }

  navBtns.forEach(btn => {
    btn.addEventListener('click', function () {
      mostrarPregunta(parseInt(btn.dataset.index || '1', 10));
    });
  });

  if (btnAnterior) {
    btnAnterior.addEventListener('click', function () {
      mostrarPregunta(actual - 1);
    });
  }

  if (btnSiguiente) {
    btnSiguiente.addEventListener('click', function () {
      mostrarPregunta(actual + 1);
    });
  }

  if (totalPreguntas > 0) {
    mostrarPregunta(1);
    actualizarProgreso();
  }
})();
</script>

</body>
</html>
