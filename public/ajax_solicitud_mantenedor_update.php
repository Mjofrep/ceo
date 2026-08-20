<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['auth'])) {
        throw new Exception('Sesión no válida');
    }

    if ((int)($_SESSION['auth']['id_rol'] ?? 0) === 6) {
        throw new Exception('No autorizado para modificar solicitudes.');
    }

    $rol = strtolower((string)($_SESSION['auth']['rol'] ?? ''));
    if ($rol !== 'administrador') {
        throw new Exception('No autorizado para modificar solicitudes.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido');
    }

    $nsolicitud = (int)($data['nsolicitud'] ?? 0);
    $fecha = trim((string)($data['fecha'] ?? ''));
    $proceso = (int)($data['proceso'] ?? 0);
    $patio = (int)($data['patio'] ?? 0);
    $uo = (int)($data['uo'] ?? 0);
    $servicio = (int)($data['servicio'] ?? 0);
    $habilitacionCeo = (int)($data['habilitacionceo'] ?? 0);
    $tipoHabilitacion = trim((string)($data['tipohabilitacion'] ?? ''));

    if ($nsolicitud <= 0 || $fecha === '' || $proceso <= 0 || $patio <= 0 || $uo <= 0 || $servicio <= 0 || $habilitacionCeo <= 0 || $tipoHabilitacion === '') {
        throw new Exception('Datos incompletos.');
    }

    $fechaDt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$fechaDt || $fechaDt->format('Y-m-d') !== $fecha) {
        throw new Exception('Fecha inválida.');
    }

    $tiposPermitidos = ['Seguridad', 'Técnica', 'Ambos'];
    if (!in_array($tipoHabilitacion, $tiposPermitidos, true)) {
        throw new Exception('Tipo de habilitación inválido.');
    }

    $pdo = db();

    $stmtSol = $pdo->prepare('SELECT nsolicitud FROM ceo_solicitudes WHERE nsolicitud = :nsolicitud LIMIT 1');
    $stmtSol->execute([':nsolicitud' => $nsolicitud]);
    if (!$stmtSol->fetchColumn()) {
        throw new Exception('La solicitud no existe.');
    }

    $validaciones = [
        ['sql' => 'SELECT id FROM ceo_procesos WHERE id = :id LIMIT 1', 'id' => $proceso, 'msg' => 'Proceso inválido.'],
        ['sql' => 'SELECT id FROM ceo_patios WHERE id = :id LIMIT 1', 'id' => $patio, 'msg' => 'Patio inválido.'],
        ['sql' => 'SELECT id FROM ceo_uo WHERE id = :id LIMIT 1', 'id' => $uo, 'msg' => 'Unidad Operativa inválida.'],
        ['sql' => 'SELECT id FROM ceo_habilitaciontipo WHERE id = :id LIMIT 1', 'id' => $habilitacionCeo, 'msg' => 'Habilitación CEO inválida.'],
    ];

    foreach ($validaciones as $val) {
        $stmt = $pdo->prepare($val['sql']);
        $stmt->execute([':id' => $val['id']]);
        if (!$stmt->fetchColumn()) {
            throw new Exception($val['msg']);
        }
    }

    $stmtServicio = $pdo->prepare('SELECT id FROM ceo_servicios WHERE id = :id AND uo = :uo LIMIT 1');
    $stmtServicio->execute([
        ':id' => $servicio,
        ':uo' => $uo,
    ]);
    if (!$stmtServicio->fetchColumn()) {
        throw new Exception('El servicio seleccionado no pertenece a la Unidad Operativa indicada.');
    }

    $stmtUpdate = $pdo->prepare('
        UPDATE ceo_solicitudes
        SET fecha = :fecha,
            proceso = :proceso,
            patio = :patio,
            uo = :uo,
            servicio = :servicio,
            habilitacionceo = :habilitacionceo,
            tipohabilitacion = :tipohabilitacion
        WHERE nsolicitud = :nsolicitud
        LIMIT 1
    ');
    $stmtUpdate->execute([
        ':fecha' => $fecha,
        ':proceso' => $proceso,
        ':patio' => $patio,
        ':uo' => $uo,
        ':servicio' => $servicio,
        ':habilitacionceo' => $habilitacionCeo,
        ':tipohabilitacion' => $tipoHabilitacion,
        ':nsolicitud' => $nsolicitud,
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
