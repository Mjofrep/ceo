<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado.']);
    exit;
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
    $stmt = $pdo->prepare('
        SELECT
            p.rut,
            COALESCE(e.nombre, \'\') AS empresa,
            p.nombre,
            p.apellidos,
            rpt.validacion,
            COALESCE(ac.descripcion, \'Sin área de competencia\') AS area_competencia,
            ps.pregunta,
            GROUP_CONCAT(
                DISTINCT CONCAT(CASE WHEN ap.correcta = \'S\' THEN \'[Correcta] \' ELSE \'\' END, COALESCE(ap.alternativa, \'\'))
                ORDER BY ap.id SEPARATOR \'\\n\'
            ) AS alternativas,
            MAX(CASE WHEN ap.correcta = \'S\' THEN ap.alternativa END) AS alternativa_correcta,
            COALESCE(ap_elegida.alternativa, \'No contestada\') AS alternativa_elegida
        FROM (
            SELECT ep1.rut, ep1.id_servicio, ep1.cuadrilla, ep1.intento
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
        INNER JOIN ceo_formacion_participantes p
            ON p.rut = ep.rut
           AND p.id_cuadrilla = ep.cuadrilla
        INNER JOIN ceo_formacion f
            ON f.cuadrilla = ep.cuadrilla
           AND f.id_servicio = ep.id_servicio
        LEFT JOIN ceo_empresas e ON e.id = f.empresa
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
        LEFT JOIN ceo_formacion_alternativas_preguntas ap ON ap.id_pregunta = ps.id
        LEFT JOIN ceo_formacion_alternativas_preguntas ap_elegida ON ap_elegida.id = rpt.respuesta
        WHERE ps.areacomp IS NOT NULL
          AND COALESCE(ps.tipo_pregunta, \'ALT\') <> \'TEXTO_LIBRE\'
        GROUP BY rpt.id, p.rut, e.nombre, p.nombre, p.apellidos, rpt.validacion, ac.descripcion, ps.pregunta, ap_elegida.alternativa
        ORDER BY p.apellidos ASC, p.nombre ASC, ac.descripcion ASC, rpt.id_pregunta ASC
    ');
    $stmt->execute([
        ':cuadrilla' => $cuadrilla,
        ':id_servicio' => $idServicio,
    ]);

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al obtener el detalle de respuestas.']);
}
