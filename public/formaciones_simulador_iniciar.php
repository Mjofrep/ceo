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
        if (strncmp($ruta, '/public/uploads/', 15) === 0) {
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

$pdo = db();

$err = '';
$msg = '';
$resultadoSim = [];
$csrfToken = Csrf::token();

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
    'proceso' => 0
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['id_servicio'] = (int)($_POST['id_servicio'] ?? 0);
    $data['id_agrupacion'] = (int)($_POST['id_agrupacion'] ?? 0);
    $data['rut_alumno'] = trim((string)($_POST['rut_alumno'] ?? ''));
    $data['proceso'] = (int)($_POST['proceso'] ?? 0);

    $respuestas = $_POST['respuestas'] ?? [];
    $respuestasTexto = $_POST['respuestas_texto'] ?? [];
    $preguntas = $_POST['preguntas'] ?? [];

    if (!$preguntas) {
        $err = 'No se recibieron preguntas.';
    } else {
        $preguntasIds = array_map('intval', $preguntas);
        $placeholders = implode(',', array_fill(0, count($preguntasIds), '?'));
        $stmt = $pdo->prepare("SELECT id, tipo_pregunta, peso FROM ceo_formacion_preguntas_servicios WHERE id IN ($placeholders)");
        $stmt->execute($preguntasIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = [
                'tipo' => $r['tipo_pregunta'] ?? 'ALT',
                'peso' => (int)($r['peso'] ?? 1)
            ];
        }

        $stmtCorrecta = $pdo->prepare("SELECT id FROM ceo_formacion_alternativas_preguntas WHERE id = :id AND id_pregunta = :id_pregunta AND correcta = 'S' LIMIT 1");

        $correctas = 0;
        $incorrectas = 0;
        $ncontestadas = 0;
        $puntajeObtenido = 0.0;
        $puntajeMaximo = 0.0;

        foreach ($preguntasIds as $idPregunta) {
            $tipo = $map[$idPregunta]['tipo'] ?? 'ALT';
            $peso = $map[$idPregunta]['peso'] ?? 1;

            if ($tipo === 'TEXTO_LIBRE') {
                continue;
            }

            $puntajeMaximo += $peso;

            if (isset($respuestas[$idPregunta])) {
                $idAlt = (int)$respuestas[$idPregunta];
                $stmtCorrecta->execute([
                    ':id' => $idAlt,
                    ':id_pregunta' => $idPregunta
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
        $stmtPorc->execute([':id_agrupacion' => $data['id_agrupacion']]);
        $porcentajeMinimo = (float)$stmtPorc->fetchColumn();
        if ($porcentajeMinimo <= 0) {
            $porcentajeMinimo = 80.0;
        }

        $resultado = ($porcentaje >= $porcentajeMinimo) ? 'APROBADO' : 'REPROBADO';
        $notaFinal = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);

        $resultadoSim = [
            'correctas' => $correctas,
            'incorrectas' => $incorrectas,
            'ncontestadas' => $ncontestadas,
            'puntaje_obtenido' => $puntajeObtenido,
            'puntaje_maximo' => $puntajeMaximo,
            'porcentaje' => $porcentaje,
            'nota' => $notaFinal,
            'resultado' => $resultado
        ];
    }
} else {
    $data['rut_alumno'] = trim((string)($_GET['rut'] ?? ''));
    $data['proceso'] = (int)($_GET['id_programada'] ?? 0);

    if ($data['rut_alumno'] === '' || $data['proceso'] <= 0) {
        $err = 'Parametros incompletos.';
    } else {
        $stmt = $pdo->prepare("SELECT id_servicio, cuadrilla FROM ceo_formacion_programadas WHERE id = :id AND rut = :rut LIMIT 1");
        $stmt->execute([
            ':id' => $data['proceso'],
            ':rut' => $data['rut_alumno']
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $err = 'No se encontro programacion pendiente.';
        } else {
            $data['id_servicio'] = (int)$row['id_servicio'];
        }
    }
}

if ($err === '') {
    $msg = 'Simulador cargado para RUT: ' . $data['rut_alumno'] . ' / programa: ' . $data['proceso'];
}

// Agrupacion y preguntas
$agrupacion = null;
$preguntas = [];
$totalPreguntas = 0;
$tiempoTotalSegundos = 0;

if ($err === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sqlAgr = "
        SELECT id, titulo, tiempo, cantidad
        FROM ceo_formacion_agrupacion
        WHERE id_servicio = :id_servicio
        ORDER BY id ASC
        LIMIT 1
    ";
    $stmtAgr = $pdo->prepare($sqlAgr);
    $stmtAgr->execute([':id_servicio' => $data['id_servicio']]);
    $agrupacion = $stmtAgr->fetch(PDO::FETCH_ASSOC);

    if (!$agrupacion) {
        $err = 'No se encontro agrupacion.';
    } else {
        $data['id_agrupacion'] = (int)$agrupacion['id'];

        $tiempo = (string)($agrupacion['tiempo'] ?? '00:00:00');
        $partes = array_map('intval', explode(':', $tiempo));
        $hh = $partes[0] ?? 0;
        $mm = $partes[1] ?? 0;
        $ss = $partes[2] ?? 0;
        $tiempoTotalSegundos = ($hh * 3600) + ($mm * 60) + $ss;

        $cantidadPreguntas = (int)$agrupacion['cantidad'];

        $stmtOb = $pdo->prepare("
            SELECT id, pregunta, id_servicio, imagen, peso, tipo_pregunta, obligatoria
            FROM ceo_formacion_preguntas_servicios
            WHERE id_servicio = :id_servicio
              AND id_agrupacion = :id_agrupacion
              AND estado = 'S'
              AND tipo_pregunta = 'TEXTO_LIBRE'
              AND obligatoria = 1
        ");
        $stmtOb->execute([
            ':id_servicio' => $data['id_servicio'],
            ':id_agrupacion' => $data['id_agrupacion']
        ]);
        $preguntasObligatorias = $stmtOb->fetchAll(PDO::FETCH_ASSOC);

        if (count($preguntasObligatorias) > $cantidadPreguntas) {
            $err = 'La cantidad de preguntas obligatorias supera el total.';
        } else {
            $preguntas = $preguntasObligatorias;

            $stmtAvail = $pdo->prepare("
                SELECT areacomp, COUNT(*) AS total
                FROM ceo_formacion_preguntas_servicios
                WHERE id_servicio = :id_servicio
                  AND id_agrupacion = :id_agrupacion
                  AND estado = 'S'
                  AND areacomp IS NOT NULL
                GROUP BY areacomp
            ");
            $stmtAvail->execute([
                ':id_servicio' => $data['id_servicio'],
                ':id_agrupacion' => $data['id_agrupacion']
            ]);
            $availableRows = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);

            $availableMap = [];
            foreach ($availableRows as $row) {
                $availableMap[(int)$row['areacomp']] = (int)$row['total'];
            }

            $stmtCfg = $pdo->prepare("
                SELECT id_area, porcentaje
                FROM ceo_formacion_areacompetencias_pct
                WHERE id_servicio = :id_servicio
            ");
            $stmtCfg->execute([':id_servicio' => $data['id_servicio']]);
            $configRows = $stmtCfg->fetchAll(PDO::FETCH_ASSOC);

            $cantidadRest = $cantidadPreguntas - count($preguntas);
            if ($cantidadRest < 0) {
                $cantidadRest = 0;
            }

            $useConfig = $cantidadRest > 0 && !empty($configRows) && !empty($availableMap);

            if ($useConfig) {
                $areas = [];
                $sumPercent = 0.0;

                foreach ($configRows as $cfg) {
                    $areaId = (int)$cfg['id_area'];
                    $pct = (float)$cfg['porcentaje'];
                    if ($pct <= 0 || empty($availableMap[$areaId])) {
                        continue;
                    }
                    $areas[] = [
                        'area' => $areaId,
                        'pct' => $pct,
                        'available' => $availableMap[$areaId],
                        'assigned' => 0,
                        'rem' => 0.0
                    ];
                    $sumPercent += $pct;
                }

                if ($sumPercent > 0 && !empty($areas)) {
                    $assignedTotal = 0;
                    foreach ($areas as $idx => $area) {
                        $exact = ($cantidadRest * $area['pct']) / $sumPercent;
                        $base = (int)floor($exact);
                        $assign = min($base, $area['available']);
                        $areas[$idx]['assigned'] = $assign;
                        $areas[$idx]['rem'] = $exact - $base;
                        $assignedTotal += $assign;
                    }

                    $remaining = max(0, $cantidadRest - $assignedTotal);
                    while ($remaining > 0) {
                        $bestIdx = null;
                        $bestRem = -1.0;
                        foreach ($areas as $idx => $area) {
                            $cap = $area['available'] - $area['assigned'];
                            if ($cap <= 0) {
                                continue;
                            }
                            if ($area['rem'] > $bestRem) {
                                $bestRem = $area['rem'];
                                $bestIdx = $idx;
                            }
                        }
                        if ($bestIdx === null) {
                            break;
                        }
                        $areas[$bestIdx]['assigned']++;
                        $remaining--;
                    }

                    foreach ($areas as $area) {
                        if ($area['assigned'] <= 0) {
                            continue;
                        }
                        $sqlArea = "
                            SELECT id, pregunta, id_servicio, imagen, peso, tipo_pregunta, obligatoria
                            FROM ceo_formacion_preguntas_servicios
                            WHERE id_servicio = ?
                              AND id_agrupacion = ?
                              AND estado = 'S'
                              AND areacomp = ?
                        ";
                        $excludeIds = array_map(static fn($q) => (int)$q['id'], $preguntas);
                        if (!empty($excludeIds)) {
                            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                            $sqlArea .= " AND id NOT IN ($placeholders) ";
                        }
                        $sqlArea .= " ORDER BY RAND() LIMIT ?";

                        $params = [$data['id_servicio'], $data['id_agrupacion'], $area['area']];
                        if (!empty($excludeIds)) {
                            $params = array_merge($params, $excludeIds);
                        }
                        $params[] = $area['assigned'];
                        $stmtAreaQ = $pdo->prepare($sqlArea);
                        $stmtAreaQ->execute($params);
                        $preguntas = array_merge($preguntas, $stmtAreaQ->fetchAll(PDO::FETCH_ASSOC));
                    }
                }
            }

            if (count($preguntas) < $cantidadPreguntas) {
                $faltantes = $cantidadPreguntas - count($preguntas);
                $ids = array_map(static fn($q) => (int)$q['id'], $preguntas);
                $sqlExtra = "
                    SELECT id, pregunta, id_servicio, imagen, peso, tipo_pregunta, obligatoria
                    FROM ceo_formacion_preguntas_servicios
                    WHERE id_servicio = ?
                      AND id_agrupacion = ?
                      AND estado = 'S'
                ";
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sqlExtra .= " AND id NOT IN ($placeholders) ";
                }
                $sqlExtra .= " ORDER BY RAND() LIMIT ?";

                $stmtExtra = $pdo->prepare($sqlExtra);
                $params = [$data['id_servicio'], $data['id_agrupacion']];
                if (!empty($ids)) {
                    $params = array_merge($params, $ids);
                }
                $params[] = $faltantes;
                $stmtExtra->execute($params);
                $preguntas = array_merge($preguntas, $stmtExtra->fetchAll(PDO::FETCH_ASSOC));
            }

            $totalPreguntas = count($preguntas);

            if ($totalPreguntas === 0) {
                $err = 'No hay preguntas configuradas.';
            } else {
                $sqlAlt = "
                    SELECT id, alternativa, id_pregunta, estado, imagen, correcta
                    FROM ceo_formacion_alternativas_preguntas
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
}

$csrfToken = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Simulador | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body {
    background-color: #f5f7fb;
  }
  .topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
  .brand-title {color:#0065a4; font-weight:600;}
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
    <a href="formaciones_simulador.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
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
      <h5 class="text-primary">Resultado Simulacion</h5>
      <div class="row g-2">
        <div class="col-md-3"><strong>Correctas:</strong> <?= (int)$resultadoSim['correctas'] ?></div>
        <div class="col-md-3"><strong>Incorrectas:</strong> <?= (int)$resultadoSim['incorrectas'] ?></div>
        <div class="col-md-3"><strong>No contestadas:</strong> <?= (int)$resultadoSim['ncontestadas'] ?></div>
        <div class="col-md-3"><strong>Puntaje:</strong> <?= esc((string)$resultadoSim['puntaje_obtenido']) ?> / <?= esc((string)$resultadoSim['puntaje_maximo']) ?></div>
        <div class="col-md-3"><strong>Porcentaje:</strong> <?= esc((string)$resultadoSim['porcentaje']) ?>%</div>
        <div class="col-md-3"><strong>Nota:</strong> <?= esc((string)$resultadoSim['nota']) ?></div>
        <div class="col-md-3"><strong>Resultado:</strong> <?= esc((string)$resultadoSim['resultado']) ?></div>
      </div>
      <div class="mt-3">
        <a href="formaciones_simulador.php" class="btn btn-outline-secondary">Volver</a>
      </div>
    </div>
  <?php elseif ($err === '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

    <div class="row mb-3">
      <div class="col-lg-8">
        <h1 class="h4 mb-1">
          <?= $agrupacion ? esc((string)$agrupacion['titulo']) : 'Prueba Teorica' ?>
        </h1>
        <p class="text-muted mb-0">
          Servicio: <strong>CEO / Evaluacion Teorica</strong>
        </p>
        <?php if ($data['rut_alumno']): ?>
          <p class="text-muted mb-0">
            Participante: <strong><?= esc($data['rut_alumno']) ?></strong>
          </p>
        <?php endif; ?>
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
              La simulacion se cerrara al terminar
            </div>
          </div>
        </div>
        <div class="small text-muted mt-2" id="session-timer"
             data-elapsed="<?= (int)$elapsedSession ?>"
             data-remaining="<?= (int)$remainingSession ?>">
          Sesion: -- restantes | -- transcurridos
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

    <form id="form-prueba" method="post" action="formaciones_simulador_iniciar.php">
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
        <button type="button" id="btn-finalizar" class="btn btn-danger">Finalizar simulacion</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function () {
  const tiempoTotal    = <?= (int)$tiempoTotalSegundos ?>;
  let   tiempoRestante = tiempoTotal;

  const timerSpan     = document.getElementById('timer');
  const inputTiempo   = document.getElementById('tiempo_restante');
  const formPrueba    = document.getElementById('form-prueba');
  const btnFinalizar  = document.getElementById('btn-finalizar');

  const keepaliveUrl = '/ceo.noetica.cl/public/ajax_keepalive.php';
  const keepaliveIntervalMs = 5 * 60 * 1000;
  let keepaliveId = null;

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

    if (tiempoRestante <= 0) {
      if (btnFinalizar) btnFinalizar.disabled = true;
      const radios = document.querySelectorAll('.respuesta-radio');
      radios.forEach(r => r.disabled = true);

      stopKeepalive();
      if (formPrueba) formPrueba.submit();
      return;
    }

    setTimeout(tick, 1000);
  }

  if (formPrueba && timerSpan) {
    timerSpan.textContent = formatoTiempo(tiempoRestante);
    setTimeout(tick, 1000);
    startKeepalive();
  }

  if (btnFinalizar && formPrueba) {
    btnFinalizar.addEventListener('click', function () {
      if (confirm('¿Seguro que deseas finalizar la simulación?')) {
        stopKeepalive();
        formPrueba.submit();
      }
    });
  }

  window.addEventListener('beforeunload', stopKeepalive);

  const totalPreguntas = <?= (int)$totalPreguntas ?>;
  const radios         = document.querySelectorAll('.respuesta-radio');
  const textos         = document.querySelectorAll('textarea[name^="respuestas_texto"]');
  const progresoBarra  = document.getElementById('progreso-barra');
  const progresoTexto  = document.getElementById('progreso-texto');

  function actualizarProgreso() {
    if (!totalPreguntas || !progresoBarra || !progresoTexto) return;

    const contestadas = new Set();
    radios.forEach(r => {
      if (r.checked) {
        contestadas.add(r.name);
        const idx = parseInt(r.dataset.index || '0', 10);
        if (idx > 0) {
          const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
          if (btn) btn.classList.add('answered');
        }
      }
    });
    textos.forEach(t => {
      if (t.value.trim() !== '') {
        contestadas.add(t.name);
        const idx = parseInt((t.closest('.pregunta-item')?.dataset.index || '0'), 10);
        if (idx > 0) {
          const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
          if (btn) btn.classList.add('answered');
        }
      }
    });

    const numContestadas = contestadas.size;
    const porcentaje     = Math.round((numContestadas / totalPreguntas) * 100);

    progresoBarra.style.width = porcentaje + '%';
    progresoBarra.setAttribute('aria-valuenow', String(numContestadas));
    progresoBarra.textContent = porcentaje + '%';
    progresoTexto.textContent = numContestadas + ' / ' + totalPreguntas + ' respondidas';
  }

  radios.forEach(r => {
    r.addEventListener('change', () => {
      actualizarProgreso();

      const idx = parseInt(r.dataset.index || '0', 10);
      if (idx > 0) {
        const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
        if (btn) btn.classList.add('answered');
      }
    });
  });

  textos.forEach(t => {
    t.addEventListener('input', () => {
      actualizarProgreso();
      const idx = parseInt((t.closest('.pregunta-item')?.dataset.index || '0'), 10);
      if (idx > 0) {
        const btn = document.querySelector('.btn-pregunta-circle[data-index="' + idx + '"]');
        if (btn && t.value.trim() !== '') btn.classList.add('answered');
      }
    });
  });

  actualizarProgreso();

  const preguntaItems = document.querySelectorAll('.pregunta-item');
  const navButtons    = document.querySelectorAll('.pregunta-nav');
  const btnAnterior   = document.getElementById('btnAnterior');
  const btnSiguiente  = document.getElementById('btnSiguiente');

  let preguntaActual = 1;

  function mostrarPregunta(n) {
    if (n < 1 || n > totalPreguntas) return;

    preguntaItems.forEach(div => {
      const idx = parseInt(div.dataset.index || '0', 10);
      div.style.display = (idx === n) ? '' : 'none';
    });

    preguntaActual = n;
    actualizarNav();
    actualizarBotones();
  }

  function actualizarNav() {
    navButtons.forEach(btn => {
      const idx = parseInt(btn.dataset.index || '0', 10);
      btn.classList.toggle('active', idx === preguntaActual);
    });
  }

  function actualizarBotones() {
    if (btnAnterior)  btnAnterior.disabled  = (preguntaActual === 1);
    if (btnSiguiente) btnSiguiente.disabled = (preguntaActual === totalPreguntas);
  }

  if (btnAnterior) {
    btnAnterior.addEventListener('click', () => {
      mostrarPregunta(preguntaActual - 1);
    });
  }

  if (btnSiguiente) {
    btnSiguiente.addEventListener('click', () => {
      mostrarPregunta(preguntaActual + 1);
    });
  }

  navButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = parseInt(btn.dataset.index || '0', 10);
      if (idx) mostrarPregunta(idx);
    });
  });

  if (totalPreguntas > 0) {
    mostrarPregunta(1);
  }
})();
</script>

<script>
(function () {
  const el = document.getElementById('session-timer');
  if (!el) return;

  let elapsed = parseInt(el.dataset.elapsed || '0', 10);
  let remaining = parseInt(el.dataset.remaining || '0', 10);

  function fmt(seg) {
    const h = Math.floor(seg / 3600);
    const m = Math.floor((seg % 3600) / 60);
    return h + 'h ' + String(m).padStart(2, '0') + 'm';
  }

  function render() {
    el.textContent = 'Sesion: ' + fmt(remaining) + ' restantes | ' + fmt(elapsed) + ' transcurridos';
  }

  render();
  setInterval(() => {
    elapsed += 60;
    remaining = Math.max(0, remaining - 60);
    render();
  }, 60000);
})();
</script>

</body>
</html>
