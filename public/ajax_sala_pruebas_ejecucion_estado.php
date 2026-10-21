<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function salaExecutionRutComparable(string $field): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER({$field}), '.', ''), '-', ''), ' ', '')";
}

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$id = (int)($_GET['id'] ?? 0);

if (!in_array($tipo, ['HABILITACION', 'FORMACION'], true) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    if ($tipo === 'HABILITACION') {
        $stmtCab = $pdo->prepare('SELECT cuadrilla, id_servicio FROM ceo_habilitacion WHERE id = :id LIMIT 1');
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$cabecera) {
            throw new RuntimeException('No se encontró la cuadrilla de habilitación.');
        }

        $tieneAuditoria = (bool)$pdo->query("SHOW TABLES LIKE 'ceo_auditoria_prueba'")->fetchColumn();
        $auditoriaFinalizada = $tieneAuditoria
            ? " AND EXISTS (
                    SELECT 1
                    FROM ceo_auditoria_prueba ap
                    WHERE ap.id_programada = ep.id
                      AND ap.evento = 'PRUEBA_FINALIZADA'
                )"
            : '';
        $rutEp = salaExecutionRutComparable('ep.rut');
        $rutRi = salaExecutionRutComparable('ri.rut');
        $sql = "
            SELECT {$rutEp} AS rut,
                   MIN(CASE
                       WHEN UPPER(TRIM(COALESCE(ep.estado, ''))) = 'EJECUTADA'
                        AND EXISTS (
                            SELECT 1
                            FROM ceo_resultado_prueba_intento ri
                            WHERE {$rutRi} = {$rutEp}
                              AND ri.id_servicio = ep.id_servicio
                              AND ri.id_proceso_habilitacion = ep.id_proceso_habilitacion
                        ){$auditoriaFinalizada}
                       THEN 1 ELSE 0
                   END) AS ejecutada
            FROM ceo_evaluaciones_programadas ep
            WHERE ep.id_servicio = :id_servicio
              AND ep.cuadrilla = :cuadrilla
              AND ep.tipo = 'PRUEBA'
              AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
            GROUP BY {$rutEp}
        ";
    } else {
        $stmtCab = $pdo->prepare('SELECT cuadrilla, id_servicio FROM ceo_formacion WHERE id = :id LIMIT 1');
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$cabecera) {
            throw new RuntimeException('No se encontró la cuadrilla de formación.');
        }

        $rutFp = salaExecutionRutComparable('fp.rut');
        $rutRi = salaExecutionRutComparable('ri.rut');
        $sql = "
            SELECT {$rutFp} AS rut,
                   MIN(CASE
                       WHEN UPPER(TRIM(COALESCE(fp.estado, ''))) = 'EJECUTADA'
                        AND EXISTS (
                            SELECT 1
                            FROM ceo_resultado_formacion_intento ri
                            WHERE {$rutRi} = {$rutFp}
                              AND ri.id_servicio = fp.id_servicio
                              AND ri.fecha_rendicion >= DATE(fp.fecha_programacion)
                        )
                       THEN 1 ELSE 0
                   END) AS ejecutada
            FROM ceo_formacion_programadas fp
            WHERE fp.id_servicio = :id_servicio
              AND fp.cuadrilla = :cuadrilla
              AND fp.tipo = 'PRUEBA'
              AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
            GROUP BY {$rutFp}
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_servicio' => (int)$cabecera['id_servicio'],
        ':cuadrilla' => (int)$cabecera['cuadrilla'],
    ]);

    $estados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $estados[(string)$row['rut']] = (int)$row['ejecutada'];
    }

    echo json_encode(['ok' => true, 'estados' => $estados], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
