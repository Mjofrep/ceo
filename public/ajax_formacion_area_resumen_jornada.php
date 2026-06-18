<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
}

function frmEstadoResumen(?string $resultado): string
{
    $estado = strtoupper(trim((string)($resultado ?? '')));
    return in_array($estado, ['APROBADO', 'REPROBADO'], true) ? $estado : 'PENDIENTE';
}

function frmMetricToString(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $number = (float)$value;
    if (abs($number - round($number)) < 0.00001) {
        return (string)(int)round($number);
    }

    return number_format($number, 2, '.', '');
}

$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$idServicio = (int)($_GET['id_servicio'] ?? 0);

if ($cuadrilla <= 0 || $idServicio <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros invalidos.']);
    exit;
}

try {
    $pdo = db();

    $stmtFormacion = $pdo->prepare('
        SELECT f.cuadrilla, f.jornada, f.id_servicio, f.id_agrupacion, fs.servicio, e.nombre AS empresa
        FROM ceo_formacion f
        LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
        LEFT JOIN ceo_empresas e ON e.id = f.empresa
        WHERE f.cuadrilla = :cuadrilla
          AND f.id_servicio = :id_servicio
        ORDER BY f.id DESC
        LIMIT 1
    ');
    $stmtFormacion->execute([
        ':cuadrilla' => $cuadrilla,
        ':id_servicio' => $idServicio,
    ]);
    $formacion = $stmtFormacion->fetch(PDO::FETCH_ASSOC);

    if (!$formacion) {
        echo json_encode(['ok' => false, 'error' => 'No se encontro la formacion solicitada.']);
        exit;
    }

    $porcentajeMinimo = obtenerPorcentajeMinimoFormacionAgrupacion($pdo, (int)($formacion['id_agrupacion'] ?? 0));

    $stmtAreas = $pdo->prepare('
        SELECT MIN(id) AS id_area, descripcion AS area
        FROM ceo_areacompetencia_formacion
        WHERE id_servicio = :id_servicio
        GROUP BY descripcion, id_servicio
        ORDER BY descripcion
    ');
    $stmtAreas->execute([':id_servicio' => $idServicio]);
    $areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtParticipantes = $pdo->prepare('
        SELECT
            p.rut,
            p.nombre,
            p.apellidos,
            COALESCE(e.nombre, \'\') AS empresa,
            ep.resultado,
            ri.notafinal
        FROM ceo_formacion_participantes p
        INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
        LEFT JOIN ceo_empresas e ON e.id = f.empresa
        LEFT JOIN (
            SELECT ep1.*
            FROM ceo_formacion_programadas ep1
            INNER JOIN (
                SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion_programadas
                WHERE cuadrilla = :cuadrilla
                  AND id_servicio = :id_servicio
                GROUP BY rut, id_servicio, cuadrilla
            ) ep2 ON ep1.id = ep2.max_id
        ) ep ON ep.rut = p.rut AND ep.id_servicio = :id_servicio2 AND ep.cuadrilla = :cuadrilla2
        LEFT JOIN (
            SELECT ri1.*
            FROM ceo_resultado_formacion_intento ri1
            INNER JOIN (
                SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion, \' \', hora_rendicion)) AS max_fecha
                FROM ceo_resultado_formacion_intento
                GROUP BY rut, id_servicio
            ) ri2 ON ri1.rut = ri2.rut
                  AND ri1.id_servicio = ri2.id_servicio
                  AND CONCAT(ri1.fecha_rendicion, \' \', ri1.hora_rendicion) = ri2.max_fecha
        ) ri ON ri.rut = p.rut AND ri.id_servicio = :id_servicio3
        WHERE p.id_cuadrilla = :cuadrilla3
          AND UPPER(TRIM(COALESCE(ep.resultado, \'\'))) IN (\'APROBADO\', \'REPROBADO\')
        ORDER BY
          CASE UPPER(TRIM(COALESCE(ep.resultado, \'\')))
            WHEN \'REPROBADO\' THEN 1
            WHEN \'APROBADO\' THEN 2
            ELSE 3
          END,
          p.apellidos ASC,
          p.nombre ASC
    ');
    $stmtParticipantes->execute([
        ':cuadrilla' => $cuadrilla,
        ':cuadrilla2' => $cuadrilla,
        ':cuadrilla3' => $cuadrilla,
        ':id_servicio' => $idServicio,
        ':id_servicio2' => $idServicio,
        ':id_servicio3' => $idServicio,
    ]);
    $rowsParticipantes = $stmtParticipantes->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rowsParticipantes) {
        echo json_encode([
            'ok' => true,
            'areas' => $areas,
            'participants' => [],
            'meta' => [
                'cuadrilla' => $cuadrilla,
                'jornada' => (string)($formacion['jornada'] ?? ''),
                'servicio' => (string)($formacion['servicio'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $participants = [];
    foreach ($rowsParticipantes as $row) {
        $participants[(string)$row['rut']] = [
            'rut' => (string)$row['rut'],
            'empresa' => (string)($row['empresa'] ?? ''),
            'nombre_completo' => trim((string)($row['nombre'] ?? '') . ' ' . (string)($row['apellidos'] ?? '')),
            'nota_final' => $row['notafinal'] !== null ? number_format((float)$row['notafinal'], 2, '.', '') : '',
            'estado' => frmEstadoResumen($row['resultado'] ?? null),
            'areas' => [],
        ];
    }

    $stmtStats = $pdo->prepare('
        SELECT
            ep.rut,
            MIN(ac.id) AS id_area,
            COALESCE(ac.descripcion, \'\') AS area,
            SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
            SUM(CASE WHEN rpt.validacion = 0 THEN 1 ELSE 0 END) AS incorrectas,
            SUM(CASE WHEN rpt.validacion = -1 THEN 1 ELSE 0 END) AS ncontestadas,
            COUNT(*) AS total_preguntas
        FROM (
            SELECT ep1.rut, ep1.id_servicio, ep1.cuadrilla, ep1.intento, ep1.resultado
            FROM ceo_formacion_programadas ep1
            INNER JOIN (
                SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion_programadas
                WHERE cuadrilla = :cuadrilla
                  AND id_servicio = :id_servicio
                GROUP BY rut, id_servicio, cuadrilla
            ) ep2 ON ep1.id = ep2.max_id
            WHERE UPPER(TRIM(COALESCE(ep1.resultado, \'\'))) IN (\'APROBADO\', \'REPROBADO\')
        ) ep
        INNER JOIN ceo_resultado_formacion_pruebat rpt
            ON rpt.rut = ep.rut
           AND rpt.proceso = ep.cuadrilla
           AND rpt.intento = ep.intento
        INNER JOIN ceo_formacion_preguntas_servicios ps
            ON ps.id = rpt.id_pregunta
           AND ps.id_servicio = ep.id_servicio
        LEFT JOIN ceo_areacompetencia_formacion ac
            ON ac.id = ps.areacomp
           AND ac.id_servicio = ps.id_servicio
        WHERE ps.areacomp IS NOT NULL
          AND COALESCE(ps.tipo_pregunta, \'ALT\') <> \'TEXTO_LIBRE\'
        GROUP BY ep.rut, ac.descripcion
        ORDER BY ac.descripcion ASC
    ');
    $stmtStats->execute([
        ':cuadrilla' => $cuadrilla,
        ':id_servicio' => $idServicio,
    ]);
    $statsRows = $stmtStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($statsRows as $row) {
        $rut = (string)$row['rut'];
        if (!isset($participants[$rut])) {
            continue;
        }

        $total = (float)$row['total_preguntas'];
        $correctas = (float)$row['correctas'];
        if ($total <= 0) {
            continue;
        }

        $incorrectas = (float)$row['incorrectas'];
        $ncontestadas = (float)$row['ncontestadas'];
        $porcentaje = round(($correctas / $total) * 100, 2);
        $nota = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);
        $idArea = (string)(int)$row['id_area'];

        $participants[$rut]['areas'][$idArea] = [
            'area' => (string)$row['area'],
            'correctas' => frmMetricToString($row['correctas']),
            'incorrectas' => frmMetricToString($row['incorrectas']),
            'ncontestadas' => frmMetricToString($row['ncontestadas']),
            'total' => frmMetricToString($total),
            'porcentaje' => number_format($porcentaje, 1, '.', ''),
            'porcentaje_correctas' => number_format(($correctas / $total) * 100, 1, '.', ''),
            'porcentaje_incorrectas' => number_format(($incorrectas / $total) * 100, 1, '.', ''),
            'porcentaje_ncontestadas' => number_format(($ncontestadas / $total) * 100, 1, '.', ''),
            'nota' => number_format($nota, 2, '.', ''),
            'aprobada' => $porcentaje >= $porcentajeMinimo,
        ];
    }

    echo json_encode([
        'ok' => true,
        'areas' => array_map(static function (array $area): array {
            return [
                'id_area' => (int)$area['id_area'],
                'area' => (string)$area['area'],
            ];
        }, $areas),
        'participants' => array_values($participants),
        'meta' => [
            'cuadrilla' => $cuadrilla,
            'jornada' => (string)($formacion['jornada'] ?? ''),
            'servicio' => (string)($formacion['servicio'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al obtener el resumen por areas.']);
}
