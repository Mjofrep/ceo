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
        return;
    }
}

if (!function_exists('formacionImagenUrl')) {
    function formacionImagenUrl(string $ruta): string
    {
        $ruta = trim($ruta);
        if ($ruta === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $ruta)) {
            return $ruta;
        }
        if (defined('APP_BASE') && $ruta !== '' && formacionStartsWith($ruta, APP_BASE)) {
            return $ruta;
        }
        if (formacionStartsWith($ruta, '/public/uploads/')) {
            $ruta = substr($ruta, 7);
        }
        if (formacionStartsWith($ruta, '/uploads/')) {
            return (defined('APP_BASE') ? APP_BASE : '') . $ruta;
        }
        if (formacionStartsWith($ruta, 'uploads/')) {
            return (defined('APP_BASE') ? APP_BASE : '') . '/' . $ruta;
        }
        return (defined('APP_BASE') ? APP_BASE : '') . '/' . ltrim($ruta, '/');
    }
}

if (!function_exists('formacionStartsWith')) {
    function formacionStartsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
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
        $cierreModo            = strtoupper(trim((string)($_POST['cierre_modo'] ?? '')));
        if (!in_array($cierreModo, ['MANUAL', 'TIEMPO', 'SALIDA'], true)) {
            $cierreModo = 'MANUAL';
        }


        $respuestas = $_POST['respuestas'] ?? [];

        $preguntas = $_POST['preguntas'] ?? [];

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
                    FROM ceo_formacion_programadas
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


                // =====================================================
                // INSERTAR RESPUESTAS TEORICAS
                // =====================================================
                $sqlInsert = "
                    INSERT INTO ceo_resultado_formacion_pruebat
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
                    FROM ceo_formacion_alternativas_preguntas
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
                    FROM ceo_resultado_formacion_pruebat rpt
                    INNER JOIN ceo_formacion_preguntas_servicios ps
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


                // =====================================================
                // GUARDAR INTENTO TEORICO
                // =====================================================
                $evaluadorId = $_SESSION['auth']['id'] ?? null;

                $sqlIntento = "
                    INSERT INTO ceo_resultado_formacion_intento
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
                    UPDATE ceo_formacion_programadas
                    SET estado = 'EJECUTADA',
                        resultado = :resultado,
                        fecha_resultado = NOW(),
                        fecha_termino = NOW(),
                        cierre_modo = :cierre_modo
                    WHERE id = :id
                ";

                $stmtUpd = $pdo->prepare($sqlUpdProc);
                $stmtUpd->execute([
                    ':resultado' => $resultado,
                    ':cierre_modo' => $cierreModo,
                    ':id'        => $data['proceso']
                ]);

                // Formaciones: no generan vigencia ni resultado final por terreno

                $pdo->commit();
                header('Location: formacion_evaluador_home.php?ok=1');
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


    if ($data['id_servicio'] <= 0) {
        $err = "No se indicó servicio.";
    }

    if ($err === '' && $data['proceso'] > 0) {
        $stmtInicio = $pdo->prepare("
            UPDATE ceo_formacion_programadas
            SET fecha_inicio = NOW()
            WHERE id = :id
              AND fecha_inicio IS NULL
        ");
        $stmtInicio->execute([':id' => $data['proceso']]);
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
            FROM ceo_formacion_agrupacion
            WHERE id_servicio = :id_servicio
            ORDER BY id ASC
            LIMIT 1
        ";

        $paramsAgr = [':id_servicio' => $data['id_servicio']];
    } else {
        $sqlAgr = "
            SELECT id, titulo, tiempo, cantidad
            FROM ceo_formacion_agrupacion
            WHERE id = :id_agrupacion
              AND id_servicio = :id_servicio
            LIMIT 1
        ";

        $paramsAgr = [
            ':id_servicio'   => $data['id_servicio'],
            ':id_agrupacion' => $data['id_agrupacion']
        ];
    }


    $stmtAgr = $pdo->prepare($sqlAgr);
    $stmtAgr->execute($paramsAgr);
    $agrupacion = $stmtAgr->fetch(PDO::FETCH_ASSOC);


    if (!$agrupacion) {
        $err = "No se encontró agrupación.";
    } else {

        $data['id_agrupacion'] = (int)$agrupacion['id'];

        /* ===========================================================
           3) CONSULTA PREGUNTAS
           =========================================================== */
        $cantidadPreguntas = (int)$agrupacion['cantidad'];

        // Intentar seleccionar preguntas segun porcentajes por area de competencia
        $preguntas = [];

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

        $useConfig = !empty($configRows) && !empty($availableMap);

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
                    $exact = ($cantidadPreguntas * $area['pct']) / $sumPercent;
                    $base = (int)floor($exact);
                    $assign = min($base, $area['available']);
                    $areas[$idx]['assigned'] = $assign;
                    $areas[$idx]['rem'] = $exact - $base;
                    $assignedTotal += $assign;
                }

                $remaining = max(0, $cantidadPreguntas - $assignedTotal);
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
                    $stmtAreaQ = $pdo->prepare("
                        SELECT id, pregunta, id_servicio, imagen
                        FROM ceo_formacion_preguntas_servicios
                        WHERE id_servicio = :id_servicio
                          AND id_agrupacion = :id_agrupacion
                          AND estado = 'S'
                          AND areacomp = :areacomp
                        ORDER BY RAND()
                        LIMIT :cantidad
                    ");
                    $stmtAreaQ->bindValue(':id_servicio', $data['id_servicio'], PDO::PARAM_INT);
                    $stmtAreaQ->bindValue(':id_agrupacion', $data['id_agrupacion'], PDO::PARAM_INT);
                    $stmtAreaQ->bindValue(':areacomp', $area['area'], PDO::PARAM_INT);
                    $stmtAreaQ->bindValue(':cantidad', $area['assigned'], PDO::PARAM_INT);
                    $stmtAreaQ->execute();
                    $preguntas = array_merge($preguntas, $stmtAreaQ->fetchAll(PDO::FETCH_ASSOC));
                }
            }
        }

        if (count($preguntas) < $cantidadPreguntas) {
            $faltantes = $cantidadPreguntas - count($preguntas);
            $ids = array_map(static fn($q) => (int)$q['id'], $preguntas);
            $sqlExtra = "
                SELECT id, pregunta, id_servicio, imagen
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
            $stmtExtra->execute();
            $preguntas = array_merge($preguntas, $stmtExtra->fetchAll(PDO::FETCH_ASSOC));
        }
        $totalPreguntas = count($preguntas);

        if ($totalPreguntas === 0) {
            $err = "No hay preguntas configuradas.";
        } else {

            /* ===========================================================
               4) CONSULTA ALTERNATIVAS POR PREGUNTA
               =========================================================== */
            $sqlAlt = "
                SELECT id, alternativa, id_pregunta, estado, imagen, correcta
                FROM ceo_formacion_alternativas_preguntas
                WHERE id_pregunta = :id_pregunta
                  AND estado = 'S'
                ORDER BY id ASC
            ";

            $stmtA = $pdo->prepare($sqlAlt);

            foreach ($preguntas as &$preg) {
                $paramsAlt = [':id_pregunta' => $preg['id']];


                $stmtA->execute($paramsAlt);
                $preg['alternativas'] = $stmtA->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Formaciones - Inicio Prueba Teórica - Evaluador</title>
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
        <a href="formacion_evaluador_home.php" class="btn btn-primary mt-3">Volver al panel del evaluador</a>

    <?php elseif ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

        <form id="form-prueba" method="post" action="formacion_iniciar_prueba.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="id_servicio" value="<?= (int)$data['id_servicio'] ?>">
            <input type="hidden" name="id_agrupacion" value="<?= (int)$data['id_agrupacion'] ?>">
            <input type="hidden" name="rut_alumno" value="<?= htmlspecialchars($data['rut_alumno'] ?? '') ?>">
            <input type="hidden" name="nsolicitud" value="<?= (int)$data['nsolicitud'] ?>">
            <input type="hidden" id="tiempo_restante" name="tiempo_restante" value="<?= (int)$tiempoTotalSegundos ?>">
            <input type="hidden" name="proceso" value="<?= (int)$data['proceso'] ?>">
            <input type="hidden" id="cierre_modo" name="cierre_modo" value="MANUAL">
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
                                <img src="<?= htmlspecialchars(formacionImagenUrl((string)$preg['imagen'])) ?>"
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
                                            <img src="<?= htmlspecialchars(formacionImagenUrl((string)$alt['imagen'])) ?>"
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
    const inputCierre   = document.getElementById('cierre_modo');

    function setCierreModo(modo) {
        if (inputCierre) {
            inputCierre.value = modo;
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

            setCierreModo('TIEMPO');
            if (formPrueba) formPrueba.submit();
            return;
        }

        setTimeout(tick, 1000);
    }

    if (formPrueba && timerSpan) {
        timerSpan.textContent = formatoTiempo(tiempoRestante);
        setTimeout(tick, 1000);
    }

    if (btnFinalizar && formPrueba) {
        btnFinalizar.addEventListener('click', function () {
            if (confirm('¿Seguro que deseas finalizar la prueba? Una vez enviada no podrás modificar las respuestas.')) {
                setCierreModo('MANUAL');
                formPrueba.submit();
            }
        });
    }

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
    const key = 'formacion_back_attempts';

    window.addEventListener('load', function () {
        if (sessionStorage.getItem(key) === null) {
            sessionStorage.setItem(key, '0');
        }
        history.pushState({ examen: true }, "", location.href);
    });

    window.addEventListener('popstate', function (e) {
        const current = parseInt(sessionStorage.getItem(key) || '0', 10);
        const next = current + 1;
        sessionStorage.setItem(key, String(next));

        if (next <= 3) {
            history.pushState({ examen: true }, "", location.href);
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
let pruebaFinalizada = false;

function finalizarPruebaPorSalida() {
    if (pruebaFinalizada) return;
    pruebaFinalizada = true;

    const form = document.getElementById("form-prueba");
    const cierreModo = document.getElementById("cierre_modo");
    if (cierreModo) {
        cierreModo.value = 'SALIDA';
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
