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

$rut = trim((string)($_GET['rut'] ?? ''));
$cuadrilla = (int)($_GET['cuadrilla'] ?? 0);
$idServicio = (int)($_GET['id_servicio'] ?? 0);

if ($rut === '' || $cuadrilla <= 0 || $idServicio <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametros invalidos.']);
    exit;
}

try {
    $pdo = db();

    $stmtIntento = $pdo->prepare("
        SELECT intento
        FROM ceo_formacion_programadas
        WHERE rut = :rut
          AND id_servicio = :id_servicio
          AND cuadrilla = :cuadrilla
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtIntento->execute([
        ':rut' => $rut,
        ':id_servicio' => $idServicio,
        ':cuadrilla' => $cuadrilla
    ]);
    $intento = (int)$stmtIntento->fetchColumn();

    if ($intento <= 0) {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            ac.id AS id_area,
            ac.descripcion AS area,
            COALESCE(cfg.porcentaje, 0) AS objetivo,
            SUM(CASE WHEN rpt.validacion = 1 THEN COALESCE(ps.peso,1) ELSE 0 END) AS correctas,
            SUM(CASE WHEN rpt.validacion = 0 THEN COALESCE(ps.peso,1) ELSE 0 END) AS incorrectas,
            SUM(CASE WHEN rpt.validacion = -1 THEN COALESCE(ps.peso,1) ELSE 0 END) AS ncontestadas,
            SUM(COALESCE(ps.peso,1)) AS total_peso
        FROM ceo_resultado_formacion_pruebat rpt
        INNER JOIN ceo_formacion_preguntas_servicios ps
            ON ps.id = rpt.id_pregunta
        LEFT JOIN ceo_areacompetencias ac
            ON ac.id = ps.areacomp
           AND ac.id_servicio = ps.id_servicio
        LEFT JOIN ceo_formacion_areacompetencias_pct cfg
            ON cfg.id_servicio = ps.id_servicio
           AND cfg.id_area = ps.areacomp
        WHERE rpt.rut = :rut
          AND rpt.proceso = :cuadrilla
          AND rpt.intento = :intento
          AND ps.id_servicio = :id_servicio
          AND ps.areacomp IS NOT NULL
          AND COALESCE(ps.tipo_pregunta,'ALT') <> 'TEXTO_LIBRE'
        GROUP BY ac.id, ac.descripcion, cfg.porcentaje
        ORDER BY ac.descripcion
    ");

    $stmt->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla,
        ':intento' => $intento,
        ':id_servicio' => $idServicio
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        $total = (float)$row['total_peso'];
        $correctas = (float)$row['correctas'];
        $porcentaje = $total > 0 ? round(($correctas / $total) * 100, 2) : 0.0;
        $data[] = [
            'id_area' => (int)$row['id_area'],
            'area' => (string)$row['area'],
            'objetivo' => (float)$row['objetivo'],
            'correctas' => (float)$row['correctas'],
            'incorrectas' => (float)$row['incorrectas'],
            'ncontestadas' => (float)$row['ncontestadas'],
            'total' => $total,
            'porcentaje' => $porcentaje,
            'reforzar' => $porcentaje < 80
        ];
    }

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al obtener estadisticas.']);
}
