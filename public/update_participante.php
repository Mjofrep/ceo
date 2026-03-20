<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$pdo = db();

$rut    = $_POST['rut']   ?? '';
$nsol   = (int)($_POST['nsol'] ?? 0);
$campo  = $_POST['campo'] ?? '';
$valorRaw = $_POST['valor'] ?? '';

// Campos permitidos
$permitidos = ['autorizado', 'asistio', 'aprobo', 'observacion'];

if (!$rut || !$nsol || !in_array($campo, $permitidos, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
    exit;
}

// Normalizar valor según tipo de campo
switch ($campo) {
    case 'autorizado':
    case 'asistio':
        // Estos siguen siendo 0 / 1
        $valor = ($valorRaw == '1') ? 1 : 0;
        break;

    case 'aprobo':
        // Guardamos tal cual (ej: SI, NO, N/A), sin tocarlo mucho
        $valor = trim((string)$valorRaw);
        break;

    case 'observacion':
        // Texto libre
        $valor = trim((string)$valorRaw);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Campo no permitido']);
        exit;
}

try {

    // =========================
    // CASO ESPECIAL: ASISTIO
    // =========================
    if ($campo === 'asistio') {

        if ($valor === 1) {
            // Marcar asistencia + fecha/hora
            $sql = "
                UPDATE ceo_participantes_solicitud
                   SET asistio = 1,
                       fechaasistio = NOW()
                 WHERE id_solicitud = :nsol
                   AND rut = :rut
                 LIMIT 1
            ";
        } else {
            // Desmarcar asistencia + limpiar fecha
            $sql = "
                UPDATE ceo_participantes_solicitud
                   SET asistio = 0,
                       fechaasistio = NULL
                 WHERE id_solicitud = :nsol
                   AND rut = :rut
                 LIMIT 1
            ";
        }

        $st = $pdo->prepare($sql);
        $st->execute([
            ':nsol' => $nsol,
            ':rut'  => $rut
        ]);

    } else {

        // =========================
        // RESTO DE CAMPOS (igual que antes)
        // =========================
        $sql = "
            UPDATE ceo_participantes_solicitud
               SET $campo = :valor
             WHERE id_solicitud = :nsol
               AND rut = :rut
             LIMIT 1
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':valor' => $valor,
            ':nsol'  => $nsol,
            ':rut'   => $rut
        ]);
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => $e->getMessage()
    ]);
}
