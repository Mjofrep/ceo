<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../src/Csrf.php';

/* ===========================================================
   FUNCION DEBUG (si no existe)
   =========================================================== */
if (!function_exists('debug')) {
    function debug($label, $data) {
        if (!defined('APP_DEBUG') || APP_DEBUG !== true) return;
        echo "<pre style='background:#111;color:#0f0;padding:8px;border-radius:6px;
                    margin:10px 0;font-size:14px;'>";
        echo "<strong>$label</strong>\n";
        print_r($data);
        echo "</pre>";
    }
}

$pdo = db();
$err  = '';
$msg  = '';

$data = [
    'id_servicio'   => 0,
    'id_agrupacion' => 0,
    'rut_alumno'    => '',
    'nsolicitud'    => 0,
    'proceso'       => 0
];

/* ===========================================================
   1) RECIBIR PARAMETROS
   =========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesión expirada. Recarga la página.';
    } else {
        $data['id_servicio']   = (int)($_POST['id_servicio'] ?? 0);
        $data['id_agrupacion'] = (int)($_POST['id_agrupacion'] ?? 0);
        $data['rut_alumno']    = trim($_POST['rut_alumno'] ?? '');
        $data['nsolicitud']    = (int)($_POST['nsolicitud'] ?? 0);
        $data['proceso']       = (int)($_POST['proceso'] ?? 0);

        debug("POST DATA", $data);

        $respuestas = $_POST['respuestas'] ?? [];
        debug("RESPUESTAS RECIBIDAS", $respuestas);

        $preguntas = $_POST['preguntas'] ?? [];
        debug("PREGUNTAS RENDIDAS", $preguntas);

        if (!$preguntas) {
            throw new Exception('No se recibieron las preguntas rendidas.');
        }

        if ($data['id_servicio'] <= 0 || $data['rut_alumno'] === '') {
            $err = "Faltan datos obligatorios para guardar respuestas.";
        } else {
            try {
                $pdo->beginTransaction();

                // =====================================================
                // RESOLVER CUADRILLA REAL DESDE EL PROCESO PROGRAMADO
                // =====================================================
                $sqlProc = "
                    SELECT cuadrilla, intento, tipo, id_servicio
                    FROM ceo_evaluaciones_programadas
                    WHERE id = :id_programada
                      AND rut = :rut
                    LIMIT 1
                ";

                $stmtProc = $pdo->prepare($sqlProc);
                $stmtProc->execute([
                    ':id_programada' => $data['proceso'],
                    ':rut'           => $data['rut_alumno']
                ]);

                $procRow = $stmtProc->fetch(PDO::FETCH_ASSOC);

                if (!$procRow) {
                    throw new Exception('No se pudo determinar la cuadrilla del proceso.');
                }

                $cuadrilla     = (int)$procRow['cuadrilla'];
                $intentoActual = (int)$procRow['intento'];

                debug('CUADRILLA RESUELTA', $cuadrilla);
                debug('PROCESO PROGRAMADO', $procRow);

                // =====================================================
                // INSERTAR RESPUESTAS TEORICAS
                // =====================================================
                $sqlInsert = "
                    INSERT INTO ceo_resultado_pruebat
                    (
                        rut,
                        id_pregunta,
                        respuesta,
                        fecha_rendicion,
                        Hora_rendicion,
                        proceso,
                        intento,
                        validacion
                    )
                    VALUES
                    (
                        :rut,
                        :id_pregunta,
                        :id_alternativa,
                        CURDATE(),
                        CURTIME(),
                        :proceso,
                        :intento,
                        :validacion
                    )
                ";

                $stmtIns = $pdo->prepare($sqlInsert);

                $sqlVal = "
                    SELECT correcta
                    FROM ceo_alternativas_preguntas
                    WHERE id = :id_alternativa
                      AND id_pregunta = :id_pregunta
                    LIMIT 1
                ";
                $stmtVal = $pdo->prepare($sqlVal);

                foreach ($preguntas as $idPregunta) {

                    $idPregunta = (int)$idPregunta;

                    if (isset($respuestas[$idPregunta])) {
                        $idAlternativa = (int)$respuestas[$idPregunta];

                        $stmtVal->execute([
                            ':id_alternativa' => $idAlternativa,
                            ':id_pregunta'    => $idPregunta
                        ]);

                        $correcta   = $stmtVal->fetchColumn();
                        $validacion = ($correcta === 'S') ? 1 : 0;
                    } else {
                        $idAlternativa = 0;
                        $validacion    = -1;
                    }

                    $paramsInsert = [
                        ':rut'            => $data['rut_alumno'],
                        ':id_pregunta'    => $idPregunta,
                        ':id_alternativa' => $idAlternativa,
                        ':validacion'     => $validacion,
                        ':proceso'        => $cuadrilla,
                        ':intento'        => $intentoActual
                    ];

                    debug('INSERT RESPUESTA + VALIDACION', $paramsInsert);

                    $stmtIns->execute($paramsInsert);
                }

                // =====================================================
                // OBTENER PORCENTAJE MINIMO DE APROBACION
                // =====================================================
                $sqlPorc = "
                    SELECT porcentaje
                    FROM ceo_porcentaje_agrupacion
                    WHERE id_agrupacion = :id_agrupacion
                      AND fechadesde <= CURDATE()
                      AND activo = 'S'
                    ORDER BY fechadesde DESC
                    LIMIT 1
                ";

                $stmtPorc = $pdo->prepare($sqlPorc);
                $stmtPorc->execute([
                    ':id_agrupacion' => $data['id_agrupacion']
                ]);

                $porcentajeMinimo = (float)$stmtPorc->fetchColumn();

                if ($porcentajeMinimo <= 0) {
                    throw new Exception('No existe porcentaje mínimo de aprobación vigente.');
                }

                // =====================================================
                // RESUMEN DEL INTENTO ACTUAL
                // =====================================================
                $sqlCount = "
                    SELECT
                        SUM(CASE WHEN rpt.validacion = 1  THEN 1 ELSE 0 END) AS correctas,
                        SUM(CASE WHEN rpt.validacion = 0  THEN 1 ELSE 0 END) AS incorrectas,
                        SUM(CASE WHEN rpt.validacion = -1 THEN 1 ELSE 0 END) AS ncontestadas,
                        COUNT(*) AS total
                    FROM ceo_resultado_pruebat rpt
                    INNER JOIN ceo_preguntas_servicios ps
                        ON ps.id = rpt.id_pregunta
                    WHERE rpt.rut = :rut
                      AND rpt.proceso = :proceso
                      AND rpt.intento = :intento
                      AND ps.id_servicio = :id_servicio
                ";

                $stmtCount = $pdo->prepare($sqlCount);
                $stmtCount->execute([
                    ':rut'         => $data['rut_alumno'],
                    ':proceso'     => $cuadrilla,
                    ':intento'     => $intentoActual,
                    ':id_servicio' => $data['id_servicio']
                ]);

                $cnt = $stmtCount->fetch(PDO::FETCH_ASSOC);

                $correctas    = (int)$cnt['correctas'];
                $incorrectas  = (int)$cnt['incorrectas'];
                $ncontestadas = (int)$cnt['ncontestadas'];
                $total        = (int)$cnt['total'];

                $porcentajeObtenido = ($total > 0)
                    ? round(($correctas / $total) * 100, 2)
                    : 0.0;

                $resultado = ($porcentajeObtenido >= $porcentajeMinimo)
                    ? 'APROBADO'
                    : 'REPROBADO';

                $notaFinal = calcularNotaFinalDesdePorcentaje($porcentajeObtenido, $porcentajeMinimo);

                debug('RESUMEN RESULTADO PRUEBA', [
                    'rut'           => $data['rut_alumno'],
                    'proceso'       => $cuadrilla,
                    'id_servicio'   => $data['id_servicio'],
                    'correctas'     => $correctas,
                    'incorrectas'   => $incorrectas,
                    'ncontestadas'  => $ncontestadas,
                    'total'         => $total,
                    'porcentaje'    => $porcentajeObtenido,
                    'nota'          => $notaFinal,
                    'resultado'     => $resultado
                ]);

                // =====================================================
                // GUARDAR INTENTO TEORICO
                // =====================================================
                $evaluadorId = $_SESSION['auth']['id'] ?? null;

                $sqlIntento = "
                    INSERT INTO ceo_resultado_prueba_intento
                    (
                        rut,
                        id_servicio,
                        id_evaluador,
                        fecha_rendicion,
                        hora_rendicion,
                        puntaje_total,
                        correctas,
                        incorrectas,
                        ncontestadas,
                        noaplica,
                        notafinal
                    )
                    VALUES
                    (
                        :rut,
                        :id_servicio,
                        :id_evaluador,
                        CURDATE(),
                        CURTIME(),
                        :puntaje,
                        :correctas,
                        :incorrectas,
                        :ncontestadas,
                        0,
                        :nota
                    )
                ";

                $stmtIntento = $pdo->prepare($sqlIntento);
                $stmtIntento->execute([
                    ':rut'          => $data['rut_alumno'],
                    ':id_servicio'  => $data['id_servicio'],
                    ':id_evaluador' => $evaluadorId,
                    ':puntaje'      => $porcentajeObtenido,
                    ':correctas'    => $correctas,
                    ':incorrectas'  => $incorrectas,
                    ':ncontestadas' => $ncontestadas,
                    ':nota'         => $notaFinal
                ]);

                // =====================================================
                // ACTUALIZAR EVALUACION PROGRAMADA
                // =====================================================
                $sqlUpdProc = "
                    UPDATE ceo_evaluaciones_programadas
                    SET estado = 'EJECUTADA',
                        resultado = :resultado
                    WHERE id = :id
                ";

                $stmtUpd = $pdo->prepare($sqlUpdProc);
                $stmtUpd->execute([
                    ':resultado' => $resultado,
                    ':id'        => $data['proceso']
                ]);

                // =====================================================
                // BLOQUEO POR VIGENCIA GENERAL ACTIVA
                // =====================================================
                if (!existeVigenciaGeneralActiva($pdo, $data['rut_alumno'], $cuadrilla)) {

                    // =====================================================
                    // INSERTAR VIGENCIA DETALLE SI APRUEBA
                    // =====================================================
                    if ($resultado === 'APROBADO') {

                        $sqlVigencia = "
                            INSERT INTO ceo_vigencia_detalle
                            (
                                rut,
                                id_servicio,
                                fechavig_ini,
                                fechavig_fin,
                                id_proceso,
                                tipo
                            )
                            VALUES
                            (
                                :rut,
                                :id_servicio,
                                CURDATE(),
                                DATE_ADD(CURDATE(), INTERVAL 3 YEAR),
                                :id_proceso,
                                :tipo
                            )
                        ";

                        $stmtVig = $pdo->prepare($sqlVigencia);
                        $stmtVig->execute([
                            ':rut'         => $data['rut_alumno'],
                            ':id_servicio' => $data['id_servicio'],
                            ':id_proceso'  => $cuadrilla,
                            ':tipo'        => $procRow['tipo']
                        ]);

                        recalcularVigenciaGeneral($pdo, $data['rut_alumno'], $cuadrilla);

                        debug('VIGENCIA REGISTRADA', [
                            'rut'      => $data['rut_alumno'],
                            'servicio' => $data['id_servicio'],
                            'proceso'  => $cuadrilla,
                            'tipo'     => $procRow['tipo']
                        ]);
                    }

                } else {
                    debug('BLOQUEO', 'Vigencia general activa: no se inserta vigencia_detalle ni se recalcula vigencia_general.');
                }

                // =====================================================
                // RECALCULAR RESULTADO FINAL DEL SERVICIO
                // =====================================================
                if (function_exists('recalcularResultadoServicio') && function_exists('guardarResultadoFinalServicio')) {
                    $resultadoFinalServicio = recalcularResultadoServicio(
                        $pdo,
                        $data['rut_alumno'],
                        $data['id_servicio'],
                        $cuadrilla,
                        'GENERAL',
                        80.0
                    );

                    guardarResultadoFinalServicio($pdo, $resultadoFinalServicio);

                    debug('RESULTADO FINAL SERVICIO', $resultadoFinalServicio);
                }

                $pdo->commit();
                header('Location: evaluador_home.php?ok=1');
                exit;

            } catch (Throwable $e) {
                $pdo->rollBack();
                $err = "Error al guardar: " . $e->getMessage();
            }
        }
    }

} else {

    // GET parameters
    $data['id_servicio']   = (int)($_GET['id_servicio'] ?? 0);
    $data['rut_alumno']    = trim($_GET['rut_alumno'] ?? '');
    $data['id_agrupacion'] = (int)($_GET['id_agrupacion'] ?? 0);
    $data['nsolicitud']    = (int)($_GET['nsolicitud'] ?? 0);
    $data['proceso']       = (int)($_GET['id_programada'] ?? 0);

    debug("GET DATA", $data);

    if ($data['id_servicio'] <= 0) {
        $err = "No se indicó servicio.";
    }
}

/* ===========================================================
   2) CONSULTA AGRUPACION
   =========================================================== */
$agrupacion = null;
$preguntas  = [];
$totalPreguntas = 0;

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
            ':id_servicio'   => $data['id_servicio'],
            ':id_agrupacion' => $data['id_agrupacion']
        ];
    }

    debug("SQL AGRUPACION", $sqlAgr);
    debug("PARAMS AGRUPACION", $paramsAgr);

    $stmtAgr = $pdo->prepare($sqlAgr);
    $stmtAgr->execute($paramsAgr);
    $agrupacion = $stmtAgr->fetch(PDO::FETCH_ASSOC);

    debug("RESULT AGRUPACION", $agrupacion);

    if (!$agrupacion) {
        $err = "No se encontró agrupación.";
    } else {

        $data['id_agrupacion'] = (int)$agrupacion['id'];

        /* ===========================================================
           3) CONSULTA PREGUNTAS
           =========================================================== */
        $cantidadPreguntas = (int)$agrupacion['cantidad'];

        $sqlP = "
            SELECT id, pregunta, id_servicio, imagen
            FROM ceo_preguntas_servicios
            WHERE id_servicio = :id_servicio
              AND id_agrupacion = :id_agrupacion
              AND estado = 'S'
            ORDER BY RAND()
            LIMIT :cantidad
        ";

        $stmtP = $pdo->prepare($sqlP);
        $stmtP->bindValue(':id_servicio', $data['id_servicio'], PDO::PARAM_INT);
        $stmtP->bindValue(':id_agrupacion', $data['id_agrupacion'], PDO::PARAM_INT);
        $stmtP->bindValue(':cantidad', $cantidadPreguntas, PDO::PARAM_INT);
        $stmtP->execute();

        $preguntas = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        $totalPreguntas = count($preguntas);

        if ($totalPreguntas === 0) {
            $err = "No hay preguntas configuradas.";
        } else {

            /* ===========================================================
               4) CONSULTA ALTERNATIVAS POR PREGUNTA
               =========================================================== */
            $sqlAlt = "
                SELECT id, alternativa, id_pregunta, estado, imagen, correcta
                FROM ceo_alternativas_preguntas
                WHERE id_pregunta = :id_pregunta
                  AND estado = 'S'
                ORDER BY id ASC
            ";

            $stmtA = $pdo->prepare($sqlAlt);

            foreach ($preguntas as &$preg) {
                $paramsAlt = [':id_pregunta' => $preg['id']];

                debug("SQL ALTERNATIVAS", $sqlAlt);
                debug("PARAMS ALTERNATIVA", $paramsAlt);

                $stmtA->execute($paramsAlt);
                $preg['alternativas'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);

                debug("RESULT ALTERNATIVAS", $preg['alternativas']);
            }
            unset($preg);
        }
    }
}

$tiempoTotalSegundos = 0;

if (!empty($agrupacion['tiempo'])) {
    [$h, $m, $s] = array_map('intval', explode(':', $agrupacion['tiempo']));
    $tiempoTotalSegundos = ($h * 3600) + ($m * 60) + $s;
} else {
    $tiempoTotalSegundos = 45 * 60;
}

$csrfToken = Csrf::token();
?>

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Inicio Prueba Teórica - Evaluador</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f5f7fb;
        }
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
            content: "✓";
            font-size: 0.9rem;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container py-4">

    <div class="row mb-3">
        <div class="col-lg-8">
            <h1 class="h4 mb-1">
                <?= $agrupacion ? htmlspecialchars($agrupacion['titulo']) : 'Prueba Teórica' ?>
            </h1>
            <p class="text-muted mb-0">
                Servicio: <strong>CEO / Evaluación Teórica</strong>
            </p>
            <?php if ($data['rut_alumno']): ?>
                <p class="text-muted mb-0">
                    Participante: <strong><?= htmlspecialchars($data['rut_alumno']) ?></strong>
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
                        <div class="fw-bold" id="timer">45:00</div>
                    </div>
                    <div class="ms-3 small text-muted">
                        La prueba se bloqueará al terminar
                    </div>
                </div>
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

    <?php if ($err !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>

    <?php elseif ($msg !== '' && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
        <a href="evaluador_home.php" class="btn btn-primary mt-3">Volver al panel del evaluador</a>

    <?php elseif ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

        <form id="form-prueba" method="post" action="evaluador_iniciar_prueba.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="id_servicio" value="<?= (int)$data['id_servicio'] ?>">
            <input type="hidden" name="id_agrupacion" value="<?= (int)$data['id_agrupacion'] ?>">
            <input type="hidden" name="rut_alumno" value="<?= htmlspecialchars($data['rut_alumno'] ?? '') ?>">
            <input type="hidden" name="nsolicitud" value="<?= (int)$data['nsolicitud'] ?>">
            <input type="hidden" id="tiempo_restante" name="tiempo_restante" value="<?= (int)$tiempoTotalSegundos ?>">
            <input type="hidden" name="proceso" value="<?= (int)$data['proceso'] ?>">
            <?php foreach ($preguntas as $preg): ?>
                <input type="hidden" name="preguntas[]" value="<?= (int)$preg['id'] ?>">
            <?php endforeach; ?>

            <div class="card mb-3">
                <div class="card-body text-center">
                    <div class="d-flex flex-wrap justify-content-center gap-2" id="nav-preguntas">
                        <?php for ($i = 1; $i <= $totalPreguntas; $i++): ?>
                            <button type="button"
                                    class="btn-pregunta-circle pregunta-nav"
                                    data-index="<?= $i ?>"
                                    id="nav_<?= $i ?>">
                                <?= $i ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <div class="mt-2 small text-muted">
                        Preguntas: <strong><?= (int)$totalPreguntas ?></strong>
                    </div>
                </div>
            </div>

            <?php
            $indice = 1;
            foreach ($preguntas as $preg):
            ?>
                <div class="card question-card mb-3 pregunta-item"
                     id="pregunta_<?= $indice ?>"
                     data-index="<?= $indice ?>"
                     style="<?= $indice === 1 ? '' : 'display:none;' ?>">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">
                            Pregunta <?= $indice ?> de <?= (int)$totalPreguntas ?>
                        </h6>
                        <p class="question-title mb-3">
                            <?= htmlspecialchars($preg['pregunta']) ?>
                        </p>

                        <?php if (!empty($preg['imagen'])): ?>
                            <div class="mb-3">
                                <img src="<?= htmlspecialchars($preg['imagen']) ?>"
                                     alt="Imagen pregunta"
                                     class="img-fluid rounded">
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($preg['alternativas'])): ?>
                            <?php foreach ($preg['alternativas'] as $alt): ?>
                                <div class="form-check mb-2">
                                    <input
                                        class="form-check-input respuesta-radio"
                                        type="radio"
                                        name="respuestas[<?= (int)$preg['id'] ?>]"
                                        id="alt_<?= (int)$preg['id'] ?>_<?= (int)$alt['id'] ?>"
                                        value="<?= (int)$alt['id'] ?>"
                                        data-index="<?= $indice ?>"
                                    >
                                    <label class="form-check-label opcion-label"
                                           for="alt_<?= (int)$preg['id'] ?>_<?= (int)$alt['id'] ?>">
                                        <?= htmlspecialchars($alt['alternativa']) ?>
                                    </label>
                                    <?php if (!empty($alt['imagen'])): ?>
                                        <div class="mt-1">
                                            <img src="<?= htmlspecialchars($alt['imagen']) ?>"
                                                 alt="Imagen alternativa"
                                                 class="img-fluid rounded">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No hay alternativas configuradas para esta pregunta.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php
                $indice++;
            endforeach;
            ?>

            <table>
                <tr>
                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" id="btnAnterior" class="btn btn-outline-secondary">
                            ← Anterior
                        </button>
                        <button type="button" id="btnSiguiente" class="btn btn-primary">
                            Siguiente →
                        </button>
                    </div>
                </tr>
                <tr></tr>
                <tr></tr>
                <tr>
                    <div class="d-flex justify-content-center mt-4">
                        <button type="button" id="btn-finalizar" class="btn btn-danger">
                            Finalizar prueba
                        </button>
                    </div>
                </tr>
            </table>
        </form>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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

    window.ceoStopKeepalive = stopKeepalive;

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
            if (confirm('¿Seguro que deseas finalizar la prueba? Una vez enviada no podrás modificar las respuestas.')) {
                stopKeepalive();
                formPrueba.submit();
            }
        });
    }

    window.addEventListener('beforeunload', stopKeepalive);

    const totalPreguntas = <?= (int)$totalPreguntas ?>;
    const radios         = document.querySelectorAll('.respuesta-radio');
    const progresoBarra  = document.getElementById('progreso-barra');
    const progresoTexto  = document.getElementById('progreso-texto');

    function actualizarProgreso() {
        if (!totalPreguntas || !progresoBarra || !progresoTexto) return;

        const contestadas = new Set();
        radios.forEach(r => {
            if (r.checked) contestadas.add(r.name);
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
(function() {
    window.addEventListener('load', function () {
        history.pushState({ examen: true }, "", location.href);
    });

    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.examen) {
            history.pushState(e.state, "", location.href);

            alert(
                "⚠ ATENCIÓN\n\n" +
                "No puedes usar los botones Atrás/Adelante del navegador durante la prueba.\n" +
                "Utiliza sólo los botones Anterior, Siguiente o Finalizar prueba."
            );
        }
    });
})();
</script>

<script>
document.addEventListener("keydown", function (e) {
    const key = e.key.toLowerCase();

    if (key === "f5" || (e.ctrlKey && key === "r")) {
        e.preventDefault();
        alert("⚠ No puedes refrescar la página durante la prueba.");
    }
});
</script>

<script>
history.pushState(null, "", location.href);

window.addEventListener("popstate", function () {
    history.pushState(null, "", location.href);

    alert(
        "⚠ ATENCIÓN\n\n" +
        "No puedes usar el botón ATRÁS del navegador durante la prueba.\n" +
        "Debes continuar con los botones Anterior, Siguiente o Finalizar."
    );
});

let pruebaFinalizada = false;

function finalizarPruebaPorSalida() {
    if (pruebaFinalizada) return;
    pruebaFinalizada = true;

    const form = document.getElementById("form-prueba");

    if (window.ceoStopKeepalive) {
        window.ceoStopKeepalive();
    }

    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "finalizado_por_salida";
    input.value = "1";
    form.appendChild(input);

    form.submit();
}

window.addEventListener("beforeunload", function (e) {
    if (typeof tiempoRestante !== "undefined" && tiempoRestante > 0 && !pruebaFinalizada) {
        if (window.ceoStopKeepalive) {
            window.ceoStopKeepalive();
        }
        e.preventDefault();
        e.returnValue = '';
    }
});

document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden" && !pruebaFinalizada && tiempoRestante > 0) {
        finalizarPruebaPorSalida();
    }
});

window.addEventListener("blur", function () {
    if (!pruebaFinalizada && tiempoRestante > 0) {
        setTimeout(() => {
            if (document.visibilityState === "hidden") {
                finalizarPruebaPorSalida();
            }
        }, 150);
    }
});
</script>

</body>
</html>
