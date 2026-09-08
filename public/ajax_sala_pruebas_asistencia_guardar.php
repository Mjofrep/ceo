<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function salaAjaxRutComparable(string $field): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER({$field}), '.', ''), '-', ''), ' ', '')";
}

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Sesión expirada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);

$tipo = strtoupper(trim((string)($data['tipo'] ?? '')));
$id = (int)($data['id'] ?? 0);
$rut = trim((string)($data['rut'] ?? ''));
$checked = (int)($data['checked'] ?? 0);

if (!in_array($tipo, ['HABILITACION', 'FORMACION'], true) || $id <= 0 || $rut === '' || !in_array($checked, [0, 1], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    if ($tipo === 'HABILITACION') {
        $stmtCab = $pdo->prepare(' 
            SELECT cuadrilla, id_servicio
            FROM ceo_habilitacion
            WHERE id = :id
            LIMIT 1
        ');
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$cabecera) {
            throw new RuntimeException('No se encontró la cuadrilla de habilitación.');
        }

        $stmtUpd = $pdo->prepare(' 
            UPDATE ceo_evaluaciones_programadas
            SET asistio_sala = :asistio_sala
            WHERE ' . salaAjaxRutComparable('rut') . ' = ' . salaAjaxRutComparable(':rut') . '
              AND id_servicio = :id_servicio
              AND cuadrilla = :cuadrilla
              AND tipo = :tipo
        ');
        $stmtUpd->bindValue(':asistio_sala', $checked === 1 ? 1 : null, $checked === 1 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtUpd->bindValue(':rut', $rut, PDO::PARAM_STR);
        $stmtUpd->bindValue(':id_servicio', (int)$cabecera['id_servicio'], PDO::PARAM_INT);
        $stmtUpd->bindValue(':cuadrilla', (int)$cabecera['cuadrilla'], PDO::PARAM_INT);
        $stmtUpd->bindValue(':tipo', 'PRUEBA', PDO::PARAM_STR);
        $stmtUpd->execute();
    } else {
        $stmtCab = $pdo->prepare(' 
            SELECT cuadrilla, id_servicio
            FROM ceo_formacion
            WHERE id = :id
            LIMIT 1
        ');
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$cabecera) {
            throw new RuntimeException('No se encontró la cuadrilla de formación.');
        }

        $stmtUpd = $pdo->prepare(' 
            UPDATE ceo_formacion_programadas
            SET asistio_sala = :asistio_sala
            WHERE ' . salaAjaxRutComparable('rut') . ' = ' . salaAjaxRutComparable(':rut') . '
              AND id_servicio = :id_servicio
              AND cuadrilla = :cuadrilla
              AND tipo = :tipo
        ');
        $stmtUpd->bindValue(':asistio_sala', $checked === 1 ? 1 : null, $checked === 1 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtUpd->bindValue(':rut', $rut, PDO::PARAM_STR);
        $stmtUpd->bindValue(':id_servicio', (int)$cabecera['id_servicio'], PDO::PARAM_INT);
        $stmtUpd->bindValue(':cuadrilla', (int)$cabecera['cuadrilla'], PDO::PARAM_INT);
        $stmtUpd->bindValue(':tipo', 'PRUEBA', PDO::PARAM_STR);
        $stmtUpd->execute();
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
