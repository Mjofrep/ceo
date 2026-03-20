<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['auth'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método inválido');
    }

    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!$in) throw new Exception('JSON inválido');

    $rut      = trim((string)($in['rut'] ?? ''));
    $cuadrilla= (int)($in['cuadrilla'] ?? 0);

    if ($rut === '' || $cuadrilla <= 0) {
        throw new Exception('Parámetros incompletos');
    }

    $pdo = db();

    $sql = "
        SELECT
            ep.id,
            ep.rut,
            ep.cuadrilla,
            ep.id_servicio,
            sp.servicio,
            sp.descripcion,
            ep.tipo,
            ep.fecha_programacion,
            DATE(ep.fecha_programacion) AS fecha_dia,
            ep.estado,
            ep.resultado,
            ep.intento
        FROM ceo_evaluaciones_programadas ep
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = ep.id_servicio
        WHERE ep.rut = :rut
          AND ep.cuadrilla = :cuadrilla
          AND ep.estado = 'PENDIENTE'
          AND ep.resultado = 'PENDIENTE'
        ORDER BY ep.fecha_programacion ASC, ep.id ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':rut' => $rut,
        ':cuadrilla' => $cuadrilla
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Formateos para frontend
    foreach ($rows as &$r) {
        $tipo = strtoupper(trim((string)$r['tipo']));
        $r['tipo_label'] = ($tipo === 'PRUEBA') ? 'Teórica' : (($tipo === 'TERRENO') ? 'Terreno' : $r['tipo']);

        $fechaISO = (string)($r['fecha_dia'] ?? '');
        $r['fecha_iso'] = $fechaISO;
        $r['fecha_label'] = $fechaISO ? date('d-m-Y', strtotime($fechaISO)) : '';

        // Texto servicio para tabla (incluye descripción si existe)
        $srv = (string)($r['servicio'] ?? '');
        $desc = trim((string)($r['descripcion'] ?? ''));
        $r['servicio_txt'] = $desc !== '' ? ($srv . ' - ' . $desc) : $srv;

        // Clave para comparar/evitar duplicados en UI
        $r['ui_key'] = (string)$r['id_servicio'] . '|' . $fechaISO . '|' . $tipo;
    }
    unset($r);

    echo json_encode([
        'ok'   => true,
        'data' => $rows
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}