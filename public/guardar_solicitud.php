<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

try {
    // === 1) Captura de datos ===
    $fecha         = $_POST['fecha'] ?? null;
    $hora_inicio   = $_POST['hora_inicio'] ?? null;
    $hora_termino  = $_POST['hora_termino'] ?? null;
    $empresa       = (int)($_POST['empresa'] ?? 0);
    $proceso       = (int)($_POST['proceso'] ?? 0);
    $habCeo        = (int)($_POST['habilitacion_ceo'] ?? 0);
    $tipoHab       = $_POST['tipo_habilitacion'] ?? '';
    $patio         = (int)($_POST['patio'] ?? 0);
    $uo            = (int)($_POST['uo'] ?? 0);
    $servicio      = (int)($_POST['servicio'] ?? 0);
    $observacion   = trim($_POST['observacion'] ?? '');

    // Validación mínima
    if (!$fecha || !$hora_inicio || !$hora_termino || !$patio || !$empresa || !$proceso) {
        throw new Exception('Faltan campos obligatorios');
    }

    // === 2) Calcular número correlativo ===
    $stmtMax = $pdo->query("SELECT COALESCE(MAX(nsolicitud), 0) + 1 FROM ceo_solicitudes");
    $nsolicitud = (int)$stmtMax->fetchColumn();

    // === 3) Insertar solicitud completa ===
    $stmt = $pdo->prepare("
        INSERT INTO ceo_solicitudes (
            nsolicitud, solicitante, patio, fecha, horainicio, horatermino, estado,
            contratista, tipohabilitacion, proceso, habilitacionceo,
            fechacreacion, uo, servicio, observacion
        )
        VALUES (
            :nsolicitud, :solicitante, :patio, :fecha, :horainicio, :horatermino, 'A',
            :contratista, :tipohab, :proceso, :habceo,
            NOW(), :uo, :servicio, :observacion
        )
    ");

    $stmt->execute([
        ':nsolicitud'  => $nsolicitud,
        ':solicitante' => $_SESSION['auth']['usuario'] ?? 'sistema',
        ':patio'       => $patio,
        ':fecha'       => $fecha,
        ':horainicio'  => $hora_inicio,
        ':horatermino' => $hora_termino,
        ':contratista' => $empresa,
        ':tipohab'     => $tipoHab,
        ':proceso'     => $proceso,
        ':habceo'      => $habCeo,
        ':uo'          => $uo,
        ':servicio'    => $servicio,
        ':observacion' => $observacion
    ]);

    // === 4) Actualizar calendario para bloquear el horario ===
    $sql = "
        UPDATE ceo_calendario 
        SET estado = 'OCUPADO', nsolicitud = :nsolicitud
        WHERE fecha = :fecha
          AND id_patio = :patio
          AND horainicio >= :hora_inicio 
          AND horainicio < :hora_termino
    ";
    $upd = $pdo->prepare($sql);
    $upd->execute([
        ':nsolicitud' => $nsolicitud,
        ':fecha' => $fecha,
        ':patio' => $patio,
        ':hora_inicio' => $hora_inicio,
        ':hora_termino' => $hora_termino
    ]);
    // === 5) Registrar participantes si existen datos previos ===
    $participantesFile = __DIR__ . '/tmp/participantes.json';
    if (file_exists($participantesFile)) {
        $json = file_get_contents($participantesFile);
        $rows = json_decode($json, true);

        if (is_array($rows) && count($rows) > 0) {
            $ins = $pdo->prepare("
                INSERT INTO ceo_participantes_solicitud 
                (id_solicitud, rut, nombre, apellidop, apellidom, id_cargo, asistio, observacion, autorizado)
                VALUES (:id_solicitud, :rut, :nombre, :apellidop, :apellidom, :id_cargo, '', '', 0)
            ");

            foreach ($rows as $r) {
                // Ajusta índices según el orden en el Excel
                $rut   = trim($r[0] ?? '');
                $nombre = trim($r[1] ?? '');
                $apepat = trim($r[2] ?? '');
                $apemat = trim($r[3] ?? '');
                $cargo  = (int)($r[4] ?? 0);

                if ($rut && $nombre) {
                    $ins->execute([
                        ':id_solicitud' => $nsolicitud,
                        ':rut'          => $rut,
                        ':nombre'       => $nombre,
                        ':apellidop'    => $apepat,
                        ':apellidom'    => $apemat,
                        ':id_cargo'     => $cargo
                    ]);
                }
            }
        }
    }
    echo json_encode(['ok' => true, 'nsolicitud' => $nsolicitud]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
