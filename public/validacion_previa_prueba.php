<?php
declare(strict_types=1);

function renderValidationErrorScreen(string $message, ?string $detail = null): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    $messageEsc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $detailEsc = $detail !== null ? htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : '';

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error validacion previa</title><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>';
    echo 'body{margin:0;background:#f5f7fb;font-family:"Segoe UI",Roboto,sans-serif;color:#1f2937;}';
    echo '.wrap{max-width:900px;margin:0 auto;padding:32px 20px;}';
    echo '.card{background:#fff;border:1px solid #f1c5cb;border-left:6px solid #dc3545;border-radius:16px;padding:24px;box-shadow:0 4px 12px rgba(0,0,0,.05);}';
    echo 'h1{margin:0 0 12px;font-size:1.35rem;}';
    echo '.msg{margin:0 0 12px;font-size:1rem;}';
    echo '.detail{margin-top:14px;padding:14px;background:#fff5f5;border-radius:10px;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92rem;}';
    echo '.hint{margin-top:16px;color:#6b7280;font-size:.95rem;}';
    echo '</style></head><body><main class="wrap"><section class="card">';
    echo '<h1>Error al cargar la validacion previa</h1>';
    echo '<p class="msg">' . $messageEsc . '</p>';
    if ($detailEsc !== '') {
        echo '<div class="detail">' . $detailEsc . '</div>';
    }
    echo '<p class="hint">La pagina detuvo la ejecucion antes de completar la validacion. Con este mensaje ya podremos identificar el punto exacto del problema.</p>';
    echo '</section></main></body></html>';
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    renderValidationErrorScreen(
        $e->getMessage(),
        basename($e->getFile()) . ':' . $e->getLine()
    );
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    renderValidationErrorScreen(
        (string)($error['message'] ?? 'Error fatal no identificado.'),
        basename((string)($error['file'] ?? '')) . ':' . (int)($error['line'] ?? 0)
    );
});

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

$pdo = db();

function escv($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeRut(string $rut): string
{
    return preg_replace('/[^0-9K]/', '', strtoupper($rut)) ?? '';
}

function obtenerDiagnosticoRut(PDO $pdo, string $rutNormalizado, bool $hasAgrupacionColumn): array
{
    $selectAgrupacion = $hasAgrupacionColumn ? 'ep.id_agrupacion' : 'NULL AS id_agrupacion';
    $stmt = $pdo->prepare("
        SELECT
            ep.id,
            ep.rut,
            ep.id_servicio,
            {$selectAgrupacion},
            ep.tipo,
            ep.cuadrilla,
            ep.id_proceso_habilitacion,
            ep.estado,
            ep.resultado,
            ep.fecha_programacion
        FROM ceo_evaluaciones_programadas ep
        WHERE REPLACE(REPLACE(REPLACE(UPPER(ep.rut), '.', ''), '-', ''), ' ', '') = :rut_normalizado
        ORDER BY ep.fecha_programacion DESC, ep.id DESC
        LIMIT 20
    ");
    $stmt->execute([':rut_normalizado' => $rutNormalizado]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function schemaHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache[$key];
}

function buildCheck(string $label, string $status, string $detail): array
{
    return [
        'label' => $label,
        'status' => $status,
        'detail' => $detail,
    ];
}

function statusLabel(string $status): string
{
    if ($status === 'ok') {
        return 'OK';
    }
    if ($status === 'warn') {
        return 'Advertencia';
    }
    return 'Error';
}

function cardStateFromChecks(array $checks): string
{
    $hasWarn = false;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'error') {
            return 'error';
        }
        if (($check['status'] ?? '') === 'warn') {
            $hasWarn = true;
        }
    }
    return $hasWarn ? 'warn' : 'ok';
}

function resolveAgrupacionProgramada(PDO $pdo, int $idServicio, ?int $idAgrupacionProgramada, bool $hasAgrupacionColumn): array
{
    $stmtAll = $pdo->prepare("
        SELECT id, titulo, cantidad, tiempo, total
        FROM ceo_agrupacion
        WHERE id_servicio = :id_servicio
        ORDER BY id ASC
    ");
    $stmtAll->execute([':id_servicio' => $idServicio]);
    $rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAgrupacionColumn && $idAgrupacionProgramada !== null && $idAgrupacionProgramada > 0) {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $idAgrupacionProgramada) {
                return [
                    'ok' => true,
                    'warning' => false,
                    'row' => $row,
                    'detail' => 'Agrupacion programada: #' . (int)$row['id'] . ' - ' . (string)$row['titulo'],
                ];
            }
        }

        return [
            'ok' => false,
            'warning' => false,
            'row' => null,
            'detail' => 'La agrupacion programada #' . $idAgrupacionProgramada . ' no pertenece al servicio.',
        ];
    }

    if (count($rows) === 1) {
        return [
            'ok' => true,
            'warning' => $hasAgrupacionColumn && ($idAgrupacionProgramada === null || $idAgrupacionProgramada <= 0),
            'row' => $rows[0],
            'detail' => ($hasAgrupacionColumn && ($idAgrupacionProgramada === null || $idAgrupacionProgramada <= 0))
                ? 'La programacion no trae id_agrupacion; se resolvio por servicio a #' . (int)$rows[0]['id'] . ' - ' . (string)$rows[0]['titulo']
                : 'Agrupacion resuelta por servicio: #' . (int)$rows[0]['id'] . ' - ' . (string)$rows[0]['titulo'],
        ];
    }

    if (count($rows) === 0) {
        return [
            'ok' => false,
            'warning' => false,
            'row' => null,
            'detail' => 'El servicio no tiene agrupaciones teoricas configuradas.',
        ];
    }

    $partes = [];
    foreach ($rows as $row) {
        $partes[] = '#' . (int)$row['id'] . ' ' . (string)$row['titulo'];
    }

    return [
        'ok' => false,
        'warning' => false,
        'row' => null,
        'detail' => 'El servicio tiene multiples agrupaciones y no se pudo identificar la programada: ' . implode(', ', $partes),
    ];
}

function obtenerPorcentajeAgrupacionVigente(PDO $pdo, int $idAgrupacion): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, id_agrupacion, porcentaje, fechadesde, activo
        FROM ceo_porcentaje_agrupacion
        WHERE id_agrupacion = :id_agrupacion
          AND fechadesde <= CURDATE()
          AND activo = 'S'
        ORDER BY fechadesde DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([':id_agrupacion' => $idAgrupacion]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function obtenerPreguntasAgrupacion(PDO $pdo, int $idServicio, int $idAgrupacion): array
{
    $stmt = $pdo->prepare("
        SELECT
            ps.id,
            ps.areacomp,
            COUNT(ap.id) AS alternativas,
            SUM(CASE WHEN ap.correcta = 'S' THEN 1 ELSE 0 END) AS correctas
        FROM ceo_preguntas_servicios ps
        LEFT JOIN ceo_alternativas_preguntas ap
            ON ap.id_pregunta = ps.id
           AND ap.estado = 'S'
        WHERE ps.id_servicio = :id_servicio
          AND ps.id_agrupacion = :id_agrupacion
          AND ps.estado = 'S'
        GROUP BY ps.id, ps.areacomp
        ORDER BY ps.id ASC
    ");
    $stmt->execute([
        ':id_servicio' => $idServicio,
        ':id_agrupacion' => $idAgrupacion,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats = [
        'total' => count($rows),
        'sin_alternativas' => 0,
        'sin_correcta' => 0,
        'multiples_correctas' => 0,
        'sin_area' => 0,
        'areas_uso' => [],
    ];

    foreach ($rows as $row) {
        $alternativas = (int)($row['alternativas'] ?? 0);
        $correctas = (int)($row['correctas'] ?? 0);
        $idArea = (int)($row['areacomp'] ?? 0);

        if ($alternativas <= 0) {
            $stats['sin_alternativas']++;
        }
        if ($correctas <= 0) {
            $stats['sin_correcta']++;
        } elseif ($correctas > 1) {
            $stats['multiples_correctas']++;
        }
        if ($idArea <= 0) {
            $stats['sin_area']++;
        } else {
            if (!isset($stats['areas_uso'][$idArea])) {
                $stats['areas_uso'][$idArea] = 0;
            }
            $stats['areas_uso'][$idArea]++;
        }
    }

    return $stats;
}

function obtenerConfiguracionAreas(PDO $pdo, int $idServicio): array
{
    $stmt = $pdo->prepare("
        SELECT
            cfg.id_area,
            cfg.porcentaje,
            COALESCE(ac.descripcion, CONCAT('Area #', cfg.id_area)) AS area
        FROM ceo_habilitacion_areascompetencias_pct cfg
        LEFT JOIN ceo_areacompetencias ac
            ON ac.id = cfg.id_area
           AND ac.id_servicio = cfg.id_servicio
        WHERE cfg.id_servicio = :id_servicio
        ORDER BY cfg.id_area ASC
    ");
    $stmt->execute([':id_servicio' => $idServicio]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function describirConfiguracionAreas(array $configRows, array $areasUso): array
{
    if (empty($configRows)) {
        return [
            'status' => empty($areasUso) ? 'warn' : 'error',
            'detail' => empty($areasUso)
                ? 'No hay porcentajes por area configurados; las preguntas activas tampoco usan areas.'
                : 'No hay porcentajes por area configurados para las areas usadas por la prueba.',
        ];
    }

    $map = [];
    $sumatoria = 0.0;
    foreach ($configRows as $row) {
        $idArea = (int)($row['id_area'] ?? 0);
        $map[$idArea] = (float)($row['porcentaje'] ?? 0);
        $sumatoria += (float)($row['porcentaje'] ?? 0);
    }

    $faltantes = [];
    foreach (array_keys($areasUso) as $idArea) {
        if (!array_key_exists((int)$idArea, $map)) {
            $faltantes[] = (int)$idArea;
        }
    }

    if (!empty($faltantes)) {
        return [
            'status' => 'warn',
            'detail' => 'Faltan porcentajes para las areas usadas: ' . implode(', ', $faltantes) . '. Sumatoria actual: ' . rtrim(rtrim(number_format($sumatoria, 2, '.', ''), '0'), '.') . '%.',
        ];
    }

    if (abs($sumatoria - 100.0) > 0.01) {
        return [
            'status' => 'warn',
            'detail' => 'Las areas estan configuradas, pero la sumatoria es ' . rtrim(rtrim(number_format($sumatoria, 2, '.', ''), '0'), '.') . '% y no 100%.',
        ];
    }

    return [
        'status' => 'ok',
        'detail' => 'Configuracion de areas completa. Areas configuradas: ' . count($configRows) . '. Sumatoria: 100%.',
    ];
}

function obtenerNombreParticipante(PDO $pdo, array $row): string
{
    $nombre = trim((string)($row['hp_nombre'] ?? ''));
    $apellidos = trim((string)($row['hp_apellidos'] ?? ''));
    if ($nombre !== '' || $apellidos !== '') {
        return trim($nombre . ' ' . $apellidos);
    }

    $stmt = $pdo->prepare("
        SELECT nombre, apellidos
        FROM ceo_contratistas
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') =
              REPLACE(REPLACE(REPLACE(UPPER(:rut), '.', ''), '-', ''), ' ', '')
        LIMIT 1
    ");
    $stmt->execute([':rut' => (string)$row['rut']]);
    $contratista = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($contratista) {
        return trim(((string)$contratista['nombre']) . ' ' . ((string)$contratista['apellidos']));
    }

    return (string)$row['rut'];
}

function validarFilaPrueba(PDO $pdo, array $row, bool $hasAgrupacionColumn): array
{
    $checks = [];
    $idServicio = (int)($row['id_servicio'] ?? 0);
    $cuadrilla = (int)($row['cuadrilla'] ?? 0);
    $rut = (string)($row['rut'] ?? '');
    $idProcesoHab = (int)($row['id_proceso_habilitacion'] ?? 0);
    $idAgrupacionProgramada = $hasAgrupacionColumn ? (int)($row['id_agrupacion'] ?? 0) : null;

    if ($idProcesoHab <= 0) {
        $checks[] = buildCheck('Proceso habilitacion', 'error', 'La evaluacion no tiene id_proceso_habilitacion asociado.');
    } else {
        $checks[] = buildCheck(
            'Proceso habilitacion',
            'ok',
            'Proceso habilitacion ID ' . $idProcesoHab . ' / numero ' . (string)($row['numero_proceso'] ?? '')
        );
    }

    $estado = strtoupper(trim((string)($row['estado'] ?? '')));
    if ($estado === 'PENDIENTE') {
        $checks[] = buildCheck('Programacion', 'ok', 'La prueba esta programada y pendiente de ejecucion.');
    } elseif ($estado === 'EJECUTADA') {
        $checks[] = buildCheck('Programacion', 'warn', 'La prueba ya figura como ejecutada.');
    } elseif ($estado === 'ANULADA') {
        $checks[] = buildCheck('Programacion', 'error', 'La programacion de la prueba esta anulada.');
    } else {
        $checks[] = buildCheck('Programacion', 'warn', 'Estado de programacion no reconocido: ' . $estado . '.');
    }

    if (!$hasAgrupacionColumn) {
        $checks[] = buildCheck('Modelo de agrupacion', 'warn', 'La tabla ceo_evaluaciones_programadas no tiene id_agrupacion en este ambiente. La nueva seleccion de prueba no quedaria persistida correctamente.');
    } elseif ($idAgrupacionProgramada <= 0) {
        $checks[] = buildCheck('Agrupacion programada', 'warn', 'La evaluacion no tiene id_agrupacion guardada; se intentara inferir por servicio.');
    } else {
        $checks[] = buildCheck('Agrupacion programada', 'ok', 'La evaluacion tiene id_agrupacion ' . $idAgrupacionProgramada . '.');
    }

    $agrupacion = resolveAgrupacionProgramada($pdo, $idServicio, $idAgrupacionProgramada, $hasAgrupacionColumn);
    if (!$agrupacion['ok']) {
        $checks[] = buildCheck('Agrupacion efectiva', 'error', $agrupacion['detail']);
        return [
            'checks' => $checks,
            'agrupacion' => null,
            'question_stats' => null,
            'cargo_id' => null,
            'cargo_nombre' => null,
            'regla' => null,
        ];
    }

    $checks[] = buildCheck(
        'Agrupacion efectiva',
        $agrupacion['warning'] ? 'warn' : 'ok',
        $agrupacion['detail']
    );

    $agrupacionRow = $agrupacion['row'];
    $idAgrupacion = (int)($agrupacionRow['id'] ?? 0);

    $porcentajeAgr = obtenerPorcentajeAgrupacionVigente($pdo, $idAgrupacion);
    if (!$porcentajeAgr || (float)($porcentajeAgr['porcentaje'] ?? 0) <= 0) {
        $checks[] = buildCheck('Porcentaje agrupacion', 'error', 'No existe porcentaje minimo vigente para la agrupacion #' . $idAgrupacion . '.');
    } else {
        $checks[] = buildCheck(
            'Porcentaje agrupacion',
            'ok',
            'Registro #' . (int)$porcentajeAgr['id'] . ': ' . (float)$porcentajeAgr['porcentaje'] . '% desde ' . (string)$porcentajeAgr['fechadesde'] . '.'
        );
    }

    $questionStats = obtenerPreguntasAgrupacion($pdo, $idServicio, $idAgrupacion);
    $cantidadEsperada = (int)($agrupacionRow['cantidad'] ?? 0);
    if ($questionStats['total'] <= 0) {
        $checks[] = buildCheck('Preguntas', 'error', 'No hay preguntas activas para el servicio/agrupacion.');
    } else {
        $statusPreguntas = 'ok';
        $detailPreguntas = 'Preguntas activas: ' . $questionStats['total'];
        if ($cantidadEsperada > 0) {
            $detailPreguntas .= ' / cantidad configurada: ' . $cantidadEsperada;
            if ($questionStats['total'] < $cantidadEsperada) {
                $statusPreguntas = 'warn';
                $detailPreguntas .= '. Hay menos preguntas activas que las requeridas por la agrupacion.';
            }
        }
        $checks[] = buildCheck('Preguntas', $statusPreguntas, $detailPreguntas);
    }

    if ($questionStats['sin_alternativas'] > 0 || $questionStats['sin_correcta'] > 0 || $questionStats['multiples_correctas'] > 0) {
        $partes = [];
        if ($questionStats['sin_alternativas'] > 0) {
            $partes[] = $questionStats['sin_alternativas'] . ' sin alternativas';
        }
        if ($questionStats['sin_correcta'] > 0) {
            $partes[] = $questionStats['sin_correcta'] . ' sin alternativa correcta';
        }
        if ($questionStats['multiples_correctas'] > 0) {
            $partes[] = $questionStats['multiples_correctas'] . ' con multiples correctas';
        }
        $checks[] = buildCheck('Alternativas', 'error', 'Problemas detectados: ' . implode(', ', $partes) . '.');
    } else {
        $checks[] = buildCheck('Alternativas', 'ok', 'Todas las preguntas activas tienen alternativas y una correcta valida.');
    }

    if ($questionStats['sin_area'] > 0) {
        $checks[] = buildCheck('Areas en preguntas', 'warn', 'Hay ' . $questionStats['sin_area'] . ' pregunta(s) sin area de competencia asignada.');
    } else {
        $checks[] = buildCheck('Areas en preguntas', 'ok', 'Todas las preguntas activas tienen area de competencia.');
    }

    $configAreas = obtenerConfiguracionAreas($pdo, $idServicio);
    $validacionAreas = describirConfiguracionAreas($configAreas, $questionStats['areas_uso']);
    $checks[] = buildCheck('Porcentajes por area', $validacionAreas['status'], $validacionAreas['detail']);

    $cargoId = obtenerCargoTrabajador($pdo, $rut, $idServicio, $cuadrilla);
    if ($cargoId === null || $cargoId <= 0) {
        $checks[] = buildCheck('Cargo / regla ponderacion', 'error', 'No se pudo determinar el cargo del trabajador para este servicio.');
        return [
            'checks' => $checks,
            'agrupacion' => $agrupacionRow,
            'question_stats' => $questionStats,
            'cargo_id' => null,
            'cargo_nombre' => null,
            'regla' => null,
        ];
    }

    $cargoNombre = obtenerNombreCargoPonderacion($pdo, $cargoId);
    $regla = obtenerReglaPonderacion($pdo, $idServicio, $cargoId, 'GENERAL');
    if (!$regla) {
        $checks[] = buildCheck(
            'Cargo / regla ponderacion',
            'error',
            'Cargo detectado: #' . $cargoId . ' ' . ($cargoNombre !== null ? $cargoNombre : '') . '. No existe regla de ponderacion vigente.'
        );
    } else {
        $ponderacionPrueba = (float)($regla['ponderacion_prueba'] ?? 0);
        $ponderacionTerreno = (float)($regla['ponderacion_terreno'] ?? 0);
        $statusRegla = ($ponderacionPrueba <= 0 && $ponderacionTerreno <= 0) ? 'error' : 'ok';
        $checks[] = buildCheck(
            'Cargo / regla ponderacion',
            $statusRegla,
            'Cargo detectado: #' . $cargoId . ' ' . ($cargoNombre !== null ? $cargoNombre : '')
            . '. Regla #' . (int)$regla['id']
            . ' -> prueba ' . rtrim(rtrim(number_format($ponderacionPrueba, 2, '.', ''), '0'), '.')
            . ', terreno ' . rtrim(rtrim(number_format($ponderacionTerreno, 2, '.', ''), '0'), '.')
            . '.'
        );
    }

    return [
        'checks' => $checks,
        'agrupacion' => $agrupacionRow,
        'question_stats' => $questionStats,
        'cargo_id' => $cargoId,
        'cargo_nombre' => $cargoNombre,
        'regla' => $regla,
    ];
}

$rutBusqueda = trim((string)($_GET['rut'] ?? ''));
$rutNormalizado = normalizeRut($rutBusqueda);
$buscado = ($rutNormalizado !== '');
$erroresBusqueda = [];
$resultados = [];
$diagnosticoRut = [];
$hasAgrupacionColumn = schemaHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion');

if ($buscado) {
    if ($rutNormalizado === '') {
        $erroresBusqueda[] = 'Debes indicar un RUT.';
    } else {
        $selectAgrupacion = $hasAgrupacionColumn ? ', ep.id_agrupacion' : '';
        $sql = "
            SELECT
                ep.id,
                ep.rut,
                ep.id_servicio,
                ep.tipo,
                ep.cuadrilla,
                ep.id_proceso_habilitacion,
                ep.fecha_programacion,
                ep.estado,
                ep.resultado,
                ep.intento,
                ph.numero_proceso,
                sp.servicio,
                hp.nombre AS hp_nombre,
                hp.apellidos AS hp_apellidos,
                hp.cargo AS hp_cargo
                {$selectAgrupacion}
            FROM ceo_evaluaciones_programadas ep
            LEFT JOIN ceo_proceso_habilitacion ph
                ON ph.id = ep.id_proceso_habilitacion
            LEFT JOIN ceo_servicios_pruebas sp
                ON sp.id = ep.id_servicio
            LEFT JOIN ceo_habilitacion_participantes hp
                ON hp.rut = ep.rut
               AND hp.id_cuadrilla = ep.cuadrilla
            WHERE TRIM(UPPER(ep.tipo)) = 'PRUEBA'
              AND TRIM(UPPER(ep.estado)) = 'PENDIENTE'
              AND REPLACE(REPLACE(REPLACE(UPPER(ep.rut), '.', ''), '-', ''), ' ', '') = :rut_normalizado
        ";

        $params = [':rut_normalizado' => $rutNormalizado];

        $sql .= " ORDER BY ep.fecha_programacion DESC, ep.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $analisis = validarFilaPrueba($pdo, $row, $hasAgrupacionColumn);
            $checks = $analisis['checks'];
            $cardState = cardStateFromChecks($checks);
            $resultados[] = [
                'row' => $row,
                'nombre' => obtenerNombreParticipante($pdo, $row),
                'checks' => $checks,
                'card_state' => $cardState,
                'agrupacion' => $analisis['agrupacion'],
                'question_stats' => $analisis['question_stats'],
                'cargo_id' => $analisis['cargo_id'],
                'cargo_nombre' => $analisis['cargo_nombre'],
                'regla' => $analisis['regla'],
            ];
        }

        if (empty($resultados)) {
            $erroresBusqueda[] = 'No se encontraron pruebas teoricas con los criterios indicados.';
            $diagnosticoRut = obtenerDiagnosticoRut($pdo, $rutNormalizado, $hasAgrupacionColumn);
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= escv(APP_NAME) ?> | Validacion previa prueba</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f5f7fb; font-family:"Segoe UI", Roboto, sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,.12); box-shadow:0 1px 4px rgba(0,0,0,.04); }
.brand-title { font-weight:700; color:#0d6efd; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,.05); border:none; }
.card-state-ok { border-left:6px solid #198754; }
.card-state-warn { border-left:6px solid #ffc107; }
.card-state-error { border-left:6px solid #dc3545; }
.status-pill { display:inline-block; border-radius:999px; padding:.25rem .65rem; font-size:.82rem; font-weight:600; }
.status-ok { background:#d1e7dd; color:#0f5132; }
.status-warn { background:#fff3cd; color:#664d03; }
.status-error { background:#f8d7da; color:#842029; }
.meta-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.75rem; }
.meta-item { background:#f8f9fb; border:1px solid #eef1f5; border-radius:.85rem; padding:.75rem .9rem; }
.table td, .table th { vertical-align:middle; }
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= escv(APP_LOGO) ?>" alt="Logo" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= escv(APP_NAME) ?></div>
        <small class="text-secondary"><?= escv(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">Volver</a>
  </div>
</header>

<main class="container pb-4">
  <div class="card p-4 mb-4">
    <h1 class="h4 mb-2">Validacion previa de prueba teorica</h1>
    <p class="text-secondary mb-3">Ingresa un RUT y la pagina buscara sus pruebas teoricas pendientes para validar que tengan toda la configuracion necesaria. Esta revision es solo de lectura y no modifica datos.</p>

    <?php if ($hasAgrupacionColumn): ?>
      <div class="alert alert-info mb-3">
        La validacion usara <code>ceo_evaluaciones_programadas.id_agrupacion</code> como fuente principal para identificar la prueba programada.
      </div>
    <?php else: ?>
      <div class="alert alert-warning mb-3">
        Este ambiente aun no tiene <code>id_agrupacion</code> en <code>ceo_evaluaciones_programadas</code>. La pagina intentara resolver la prueba por servicio, pero eso ya no es el modelo ideal cuando un servicio tiene mas de una prueba.
      </div>
    <?php endif; ?>

    <?php if (!empty($erroresBusqueda)): ?>
      <div class="alert alert-danger">
        <?php foreach ($erroresBusqueda as $error): ?>
          <div><?= escv($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($diagnosticoRut)): ?>
      <div class="alert alert-secondary mb-3">
        <div class="fw-semibold mb-2">Diagnostico de solo lectura para ese RUT</div>
        <div class="small text-secondary mb-2">Se muestran registros existentes en <code>ceo_evaluaciones_programadas</code> para ayudarte a comparar <code>tipo</code>, <code>estado</code> y <code>id_agrupacion</code>.</div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Servicio</th>
                <th>ID agrupacion</th>
                <th>Tipo</th>
                <th>Cuadrilla</th>
                <th>ID proc. hab.</th>
                <th>Estado</th>
                <th>Resultado</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($diagnosticoRut as $diag): ?>
                <tr>
                  <td><?= escv((string)($diag['id'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['id_servicio'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['id_agrupacion'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['tipo'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['cuadrilla'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['id_proceso_habilitacion'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['estado'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['resultado'] ?? '')) ?></td>
                  <td><?= escv((string)($diag['fecha_programacion'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <form method="get" class="row g-3">
      <div class="col-md-4">
        <label class="form-label">RUT</label>
        <input type="text" name="rut" class="form-control" placeholder="Ej: 26301948-2" value="<?= escv($rutBusqueda) ?>">
      </div>
      <div class="col-md-8 d-flex align-items-end gap-2">
        <button type="submit" class="btn btn-primary">Validar</button>
        <a href="validacion_previa_prueba.php" class="btn btn-outline-secondary">Limpiar</a>
      </div>
    </form>
  </div>

  <?php if (!empty($resultados)): ?>
    <div class="mb-3">
      <h2 class="h5 mb-0">Resultados encontrados: <?= count($resultados) ?></h2>
    </div>

    <?php foreach ($resultados as $resultado): ?>
      <?php
      $row = $resultado['row'];
      $agrupacion = $resultado['agrupacion'];
      $cardState = $resultado['card_state'];
      $cardClass = $cardState === 'ok' ? 'card-state-ok' : ($cardState === 'warn' ? 'card-state-warn' : 'card-state-error');
      ?>
      <section class="card <?= escv($cardClass) ?> p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
          <div>
            <h3 class="h5 mb-1"><?= escv($resultado['nombre']) ?></h3>
            <div class="text-secondary">
              RUT <?= escv((string)$row['rut']) ?> | Servicio <?= escv((string)$row['id_servicio']) ?> - <?= escv((string)($row['servicio'] ?? '')) ?>
            </div>
          </div>
          <span class="status-pill <?= $cardState === 'ok' ? 'status-ok' : ($cardState === 'warn' ? 'status-warn' : 'status-error') ?>">
            <?= escv(statusLabel($cardState)) ?>
          </span>
        </div>

        <div class="meta-grid mb-4">
          <div class="meta-item">
            <div class="small text-secondary">Numero proceso</div>
            <div class="fw-semibold"><?= escv((string)($row['numero_proceso'] ?? '')) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">ID proceso habilitacion</div>
            <div class="fw-semibold"><?= escv((string)($row['id_proceso_habilitacion'] ?? '')) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">Cuadrilla</div>
            <div class="fw-semibold"><?= escv((string)($row['cuadrilla'] ?? '')) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">ID agrupacion programada</div>
            <div class="fw-semibold"><?= escv((string)($row['id_agrupacion'] ?? '')) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">Programacion</div>
            <div class="fw-semibold">#<?= escv((string)$row['id']) ?> / <?= escv((string)$row['estado']) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">Resultado actual</div>
            <div class="fw-semibold"><?= escv((string)$row['resultado']) ?></div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">Agrupacion</div>
            <div class="fw-semibold">
              <?php if ($agrupacion): ?>
                #<?= escv((string)$agrupacion['id']) ?> - <?= escv((string)$agrupacion['titulo']) ?>
              <?php else: ?>
                Sin resolver
              <?php endif; ?>
            </div>
          </div>
          <div class="meta-item">
            <div class="small text-secondary">Cargo detectado</div>
            <div class="fw-semibold">
              <?php if ($resultado['cargo_id'] !== null): ?>
                #<?= escv((string)$resultado['cargo_id']) ?> <?= escv((string)($resultado['cargo_nombre'] ?? '')) ?>
              <?php else: ?>
                Sin determinar
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Validacion</th>
                <th>Estado</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($resultado['checks'] as $check): ?>
                <tr>
                  <td><?= escv((string)$check['label']) ?></td>
                  <td>
                    <span class="status-pill <?= $check['status'] === 'ok' ? 'status-ok' : ($check['status'] === 'warn' ? 'status-warn' : 'status-error') ?>">
                      <?= escv(statusLabel((string)$check['status'])) ?>
                    </span>
                  </td>
                  <td><?= escv((string)$check['detail']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
  <?php elseif ($buscado && empty($erroresBusqueda)): ?>
    <div class="alert alert-info">No se encontraron resultados para la busqueda realizada.</div>
  <?php endif; ?>
</main>

</body>
</html>
