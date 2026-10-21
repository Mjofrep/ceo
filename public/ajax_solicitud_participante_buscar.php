<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function buscarRutKeySolicitudParticipante(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

try {
    if (empty($_SESSION['auth'])) {
        throw new RuntimeException('Sesión no válida');
    }

    $role = strtolower((string)($_SESSION['auth']['rol'] ?? ''));
    $roleId = (int)($_SESSION['auth']['id_rol'] ?? 0);
    if ($role !== 'administrador' && $roleId !== 5) {
        throw new RuntimeException('No autorizado');
    }

    $rutKey = buscarRutKeySolicitudParticipante((string)($_GET['rut'] ?? ''));
    if ($rutKey === '') {
        echo json_encode(['ok' => true, 'found' => false]);
        exit;
    }

    $pdo = db();
    $stmtSolicitud = $pdo->prepare('
        SELECT
            ps.rut,
            ps.nombre,
            ps.apellidop,
            ps.apellidom,
            COALESCE(cc.cargo, \'\') AS cargo
        FROM ceo_participantes_solicitud ps
        INNER JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ps.id_cargo
        WHERE REPLACE(REPLACE(REPLACE(UPPER(ps.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
        ORDER BY s.fecha DESC, s.nsolicitud DESC
        LIMIT 1
    ');
    $stmtSolicitud->execute([':rut' => $rutKey]);
    $person = $stmtSolicitud->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($person) {
        echo json_encode([
            'ok' => true,
            'found' => true,
            'source' => 'Permisos anteriores',
            'person' => $person,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtContractor = $pdo->prepare('
        SELECT
            c.rut,
            c.nombre,
            c.apellidos AS apellidop,
            \'\' AS apellidom,
            COALESCE(cc.cargo, \'\') AS cargo
        FROM ceo_contratistas c
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
        WHERE REPLACE(REPLACE(REPLACE(UPPER(c.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
        LIMIT 1
    ');
    $stmtContractor->execute([':rut' => $rutKey]);
    $person = $stmtContractor->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'ok' => true,
        'found' => $person !== null,
        'source' => $person ? 'Contratistas' : '',
        'person' => $person,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
