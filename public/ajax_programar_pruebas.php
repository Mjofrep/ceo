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

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!$data) throw new Exception('JSON inválido');

    $rut      = trim((string)($data['rut'] ?? ''));
    $cuadrilla= (int)($data['cuadrilla'] ?? 0);
    $items    = $data['items'] ?? [];

    if ($rut === '') throw new Exception('RUT requerido');
    if ($cuadrilla <= 0) throw new Exception('Cuadrilla inválida');
    if (!is_array($items) || empty($items)) throw new Exception('Sin items');

    $pdo = db();
    $pdo->beginTransaction();

    $usuario = (int)($_SESSION['auth']['id'] ?? 0);
    if ($usuario <= 0) $usuario = 1; // fallback

    // ✅ Regla pendiente real: estado+resultado
    $stmtExists = $pdo->prepare("
        SELECT 1
        FROM ceo_evaluaciones_programadas
        WHERE rut = :rut
          AND id_servicio = :id_servicio
          AND tipo = :tipo
          AND cuadrilla = :cuadrilla
          AND DATE(fecha_programacion) = :fecha
          AND estado = 'PENDIENTE'
          AND resultado = 'PENDIENTE'
        LIMIT 1
    ");

    // (Opcional) calcular intento incremental:
    $stmtNextIntento = $pdo->prepare("
        SELECT COALESCE(MAX(intento),0) + 1
        FROM ceo_evaluaciones_programadas
        WHERE rut = :rut
          AND id_servicio = :id_servicio
          AND tipo = :tipo
          AND cuadrilla = :cuadrilla
    ");

    $stmtIns = $pdo->prepare("
        INSERT INTO ceo_evaluaciones_programadas
        (rut, id_servicio, tipo, cuadrilla, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
        VALUES
        (:rut, :id_servicio, :tipo, :cuadrilla, :fecha_programacion, :usuario, 'PENDIENTE', :intento, 'PENDIENTE', NULL, 0)
    ");

    $insertados = 0;
    $omitidos   = 0;

    foreach ($items as $it) {
        $id_servicio = (int)($it['id_servicio'] ?? 0);
        $fecha       = (string)($it['fecha'] ?? '');
        $tipoSel     = strtoupper(trim((string)($it['tipo'] ?? '')));

        if ($id_servicio <= 0 || $fecha === '' || $tipoSel === '') continue;

        // Hora estándar de programación (ajustable)
        $fecha_programacion = $fecha . ' 09:00:00';

        // Expandir AMBOS -> PRUEBA + TERRENO
        $tipos = ($tipoSel === 'AMBOS') ? ['PRUEBA', 'TERRENO'] : [$tipoSel];

        foreach ($tipos as $tipo) {
            $tipo = strtoupper(trim((string)$tipo));
            if (!in_array($tipo, ['PRUEBA', 'TERRENO'], true)) continue;

            // 1) validar duplicado solo si está pendiente
            $stmtExists->execute([
                ':rut'        => $rut,
                ':id_servicio'=> $id_servicio,
                ':tipo'       => $tipo,
                ':cuadrilla'  => $cuadrilla,
                ':fecha'      => $fecha
            ]);

            if ($stmtExists->fetchColumn()) {
                $omitidos++;
                continue;
            }

            // 2) intento incremental (si no te interesa, fija en 1 y borra stmtNextIntento)
            $stmtNextIntento->execute([
                ':rut'        => $rut,
                ':id_servicio'=> $id_servicio,
                ':tipo'       => $tipo,
                ':cuadrilla'  => $cuadrilla
            ]);
            $intento = (int)$stmtNextIntento->fetchColumn();
            if ($intento <= 0) $intento = 1;

            // 3) insertar
            $stmtIns->execute([
                ':rut'               => $rut,
                ':id_servicio'       => $id_servicio,
                ':tipo'              => $tipo,
                ':cuadrilla'         => $cuadrilla,
                ':fecha_programacion'=> $fecha_programacion,
                ':usuario'           => $usuario,
                ':intento'           => $intento
            ]);

            $insertados++;
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'         => true,
        'insertados' => $insertados,
        'omitidos'   => $omitidos
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}