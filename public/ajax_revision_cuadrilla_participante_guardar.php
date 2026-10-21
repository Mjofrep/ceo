<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function revisionParticipanteJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function revisionParticipanteRutKey(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

if (empty($_SESSION['auth'])) {
    revisionParticipanteJson(['ok' => false, 'msg' => 'No autorizado.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    revisionParticipanteJson(['ok' => false, 'msg' => 'Método no permitido.'], 405);
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    revisionParticipanteJson(['ok' => false, 'msg' => 'Datos inválidos.'], 400);
}

$accion = (string)($data['accion'] ?? '');
$programa = (int)($data['programa'] ?? 0);
$rut = trim((string)($data['rut'] ?? ''));
$nombre = preg_replace('/\s+/', ' ', trim((string)($data['nombre'] ?? ''))) ?? '';
$apellidos = preg_replace('/\s+/', ' ', trim((string)($data['apellidos'] ?? ''))) ?? '';
$idCargo = (int)($data['id_cargo'] ?? 0);

if (!in_array($accion, ['agregar', 'editar'], true) || $programa <= 0 || $rut === '' || $nombre === '' || $apellidos === '' || $idCargo <= 0) {
    revisionParticipanteJson(['ok' => false, 'msg' => 'Completa RUT, nombre, apellidos y cargo.'], 400);
}

try {
    $pdo = db();
    $stmtPlan = $pdo->prepare('SELECT id, cuadrilla, empresa, uo, id_servicio FROM ceo_habilitacion WHERE id = :id LIMIT 1');
    $stmtPlan->execute([':id' => $programa]);
    $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        throw new RuntimeException('La cuadrilla no existe.');
    }

    if (
        strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista'
        && (int)$plan['empresa'] !== (int)($_SESSION['auth']['id_empresa'] ?? 0)
    ) {
        throw new RuntimeException('No puedes modificar cuadrillas de otra empresa.');
    }

    $stmtCargo = $pdo->prepare('SELECT id, cargo FROM ceo_cargo_contratistas WHERE id = :id LIMIT 1');
    $stmtCargo->execute([':id' => $idCargo]);
    $cargo = $stmtCargo->fetch(PDO::FETCH_ASSOC);
    if (!$cargo) {
        throw new RuntimeException('El cargo seleccionado no existe.');
    }

    $rutKey = revisionParticipanteRutKey($rut);
    if ($rutKey === '') {
        throw new RuntimeException('RUT inválido.');
    }

    $pdo->beginTransaction();

    $rutSql = "REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '')";
    $stmtParticipante = $pdo->prepare("SELECT id, cargo FROM ceo_habilitacion_participantes WHERE id_cuadrilla = :cuadrilla AND {$rutSql} = :rut LIMIT 1 FOR UPDATE");
    $stmtParticipante->execute([
        ':cuadrilla' => (int)$plan['cuadrilla'],
        ':rut' => $rutKey,
    ]);
    $participante = $stmtParticipante->fetch(PDO::FETCH_ASSOC);

    if ($accion === 'agregar' && $participante) {
        throw new RuntimeException('La persona ya está registrada en esta cuadrilla.');
    }
    if ($accion === 'editar' && !$participante) {
        throw new RuntimeException('La persona no está registrada en esta cuadrilla.');
    }

    if ($accion === 'editar' && trim((string)$participante['cargo']) !== trim((string)$cargo['cargo'])) {
        $stmtProgramaciones = $pdo->prepare("SELECT 1 FROM ceo_evaluaciones_programadas WHERE {$rutSql} = :rut AND id_servicio = :servicio AND cuadrilla = :cuadrilla LIMIT 1");
        $stmtProgramaciones->execute([
            ':rut' => $rutKey,
            ':servicio' => (int)$plan['id_servicio'],
            ':cuadrilla' => (int)$plan['cuadrilla'],
        ]);
        if ($stmtProgramaciones->fetchColumn()) {
            throw new RuntimeException('No se puede cambiar el cargo porque existen evaluaciones asociadas.');
        }
    }

    if ($accion === 'agregar') {
        $stmtPersona = $pdo->prepare("SELECT 1 FROM ceo_contratistas WHERE {$rutSql} = :rut LIMIT 1");
        $stmtPersona->execute([':rut' => $rutKey]);
        if (!$stmtPersona->fetchColumn()) {
            $stmtNuevaPersona = $pdo->prepare('INSERT INTO ceo_contratistas (rut, nombre, apellidos, correo, telefono, id_cargo, fecha_ingreso, id_empresa, uo) VALUES (:rut, :nombre, :apellidos, NULL, NULL, :id_cargo, CURDATE(), :empresa, :uo)');
            $stmtNuevaPersona->execute([
                ':rut' => $rut,
                ':nombre' => $nombre,
                ':apellidos' => $apellidos,
                ':id_cargo' => (int)$cargo['id'],
                ':empresa' => (int)$plan['empresa'],
                ':uo' => (int)$plan['uo'],
            ]);
        }

        $stmtGuardarParticipante = $pdo->prepare('INSERT INTO ceo_habilitacion_participantes (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo) VALUES (:cuadrilla, 0, :rut, :nombre, :apellidos, :cargo)');
        $stmtGuardarParticipante->execute([
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut' => $rut,
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => (string)$cargo['cargo'],
        ]);
    } else {
        $stmtGuardarParticipante = $pdo->prepare("UPDATE ceo_habilitacion_participantes SET nombre = :nombre, apellidos = :apellidos, cargo = :cargo WHERE id_cuadrilla = :cuadrilla AND {$rutSql} = :rut");
        $stmtGuardarParticipante->execute([
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => (string)$cargo['cargo'],
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut' => $rutKey,
        ]);
    }

    $stmtPersonaCuadrilla = $pdo->prepare("SELECT 1 FROM ceo_habilitacion_personas WHERE id_habilitacion = :habilitacion AND {$rutSql} = :rut LIMIT 1");
    $stmtPersonaCuadrilla->execute([
        ':habilitacion' => (int)$plan['id'],
        ':rut' => $rutKey,
    ]);
    if ($stmtPersonaCuadrilla->fetchColumn()) {
        $stmtGuardarPersonaCuadrilla = $pdo->prepare("UPDATE ceo_habilitacion_personas SET nombre = :nombre, apellidos = :apellidos, cargo = :cargo, estado = 'ACTIVO' WHERE id_habilitacion = :habilitacion AND {$rutSql} = :rut");
        $stmtGuardarPersonaCuadrilla->execute([
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => (string)$cargo['cargo'],
            ':habilitacion' => (int)$plan['id'],
            ':rut' => $rutKey,
        ]);
    } else {
        $stmtGuardarPersonaCuadrilla = $pdo->prepare("INSERT INTO ceo_habilitacion_personas (id_habilitacion, rut, nombre, apellidos, cargo, tipo_participacion, estado) VALUES (:habilitacion, :rut, :nombre, :apellidos, :cargo, 'NO_EVALUA', 'ACTIVO')");
        $stmtGuardarPersonaCuadrilla->execute([
            ':habilitacion' => (int)$plan['id'],
            ':rut' => $rut,
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => (string)$cargo['cargo'],
        ]);
    }

    $pdo->commit();
    revisionParticipanteJson(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    revisionParticipanteJson(['ok' => false, 'msg' => $e->getMessage()], 400);
}
