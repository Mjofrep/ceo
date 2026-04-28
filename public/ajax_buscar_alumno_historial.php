<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$tipo = trim((string)($_GET['tipo'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . mb_strtoupper($q, 'UTF-8') . '%';
$exacto = mb_strtoupper($q, 'UTF-8');
$prefijo = $exacto . '%';

try {
    if ($tipo === 'formaciones') {
        $stmt = $pdo->prepare("
            SELECT
                p.rut,
                MAX(p.nombre) AS nombre,
                MAX(p.apellidos) AS apellidos,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM ceo_formacion_programadas fp
                        WHERE fp.rut = p.rut
                          AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
                    ) THEN 1 ELSE 0
                END AS tiene_historial
            FROM ceo_formacion_participantes p
            WHERE (
                UPPER(p.rut) LIKE :like_rut
                OR UPPER(p.nombre) LIKE :like_nombre
                OR UPPER(p.apellidos) LIKE :like_apellidos
                OR UPPER(CONCAT(p.nombre, ' ', p.apellidos)) LIKE :like_nombre_completo
            )
            GROUP BY p.rut
            ORDER BY
                CASE WHEN UPPER(p.rut) = :exacto_rut THEN 0 ELSE 1 END,
                CASE WHEN UPPER(p.rut) LIKE :prefijo_rut THEN 0 ELSE 1 END,
                tiene_historial DESC,
                nombre ASC,
                apellidos ASC,
                p.rut ASC
            LIMIT 15
        ");
        $stmt->execute([
            ':like_rut' => $like,
            ':like_nombre' => $like,
            ':like_apellidos' => $like,
            ':like_nombre_completo' => $like,
            ':exacto_rut' => $exacto,
            ':prefijo_rut' => $prefijo,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($tipo === 'evaluaciones') {
        $stmt = $pdo->prepare("
            SELECT
                base.rut,
                MAX(base.nombre) AS nombre,
                MAX(base.apellidos) AS apellidos,
                MAX(base.tiene_historial) AS tiene_historial
            FROM (
                SELECT
                    c.rut,
                    c.nombre,
                    TRIM(COALESCE(c.apellidos, '')) AS apellidos,
                    1 AS tiene_historial
                FROM ceo_contratistas c

                UNION ALL

                SELECT
                    rpi.rut,
                    COALESCE(c2.nombre, '') AS nombre,
                    TRIM(COALESCE(c2.apellidos, '')) AS apellidos,
                    1 AS tiene_historial
                FROM ceo_resultado_prueba_intento rpi
                LEFT JOIN ceo_contratistas c2 ON c2.rut = rpi.rut

                UNION ALL

                SELECT
                    et.rut,
                    COALESCE(c3.nombre, '') AS nombre,
                    TRIM(COALESCE(c3.apellidos, '')) AS apellidos,
                    1 AS tiene_historial
                FROM ceo_evaluacion_terreno et
                LEFT JOIN ceo_contratistas c3 ON c3.rut = et.rut
            ) base
            WHERE (
                UPPER(base.rut) LIKE :like_rut
                OR UPPER(base.nombre) LIKE :like_nombre
                OR UPPER(base.apellidos) LIKE :like_apellidos
                OR UPPER(CONCAT(base.nombre, ' ', base.apellidos)) LIKE :like_nombre_completo
            )
            GROUP BY base.rut
            ORDER BY
                CASE WHEN UPPER(base.rut) = :exacto_rut THEN 0 ELSE 1 END,
                CASE WHEN UPPER(base.rut) LIKE :prefijo_rut THEN 0 ELSE 1 END,
                tiene_historial DESC,
                nombre ASC,
                apellidos ASC,
                base.rut ASC
            LIMIT 15
        ");
        $stmt->execute([
            ':like_rut' => $like,
            ':like_nombre' => $like,
            ':like_apellidos' => $like,
            ':like_nombre_completo' => $like,
            ':exacto_rut' => $exacto,
            ':prefijo_rut' => $prefijo,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Tipo de busqueda invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = [];
    foreach ($rows as $row) {
        $nombre = trim((string)($row['nombre'] ?? ''));
        $apellidos = trim((string)($row['apellidos'] ?? ''));
        $nombreCompleto = trim($nombre . ' ' . $apellidos);
        $tieneHistorial = ((int)($row['tiene_historial'] ?? 0) === 1);
        $items[] = [
            'rut' => (string)$row['rut'],
            'nombre' => $nombre,
            'apellido' => $apellidos,
            'label' => trim((string)$row['rut'] . ' - ' . $nombreCompleto),
            'origen' => $tipo,
            'tiene_historial' => $tieneHistorial,
            'estado' => $tieneHistorial ? 'Con historial' : 'Sin historial',
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Error al buscar alumnos',
    ], JSON_UNESCAPED_UNICODE);
}
