<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function normalizarRutSolicitudParticipante(string $rut): string
{
    $rut = strtoupper(preg_replace('/[^0-9Kk]/', '', $rut) ?? '');
    if (strlen($rut) < 2) {
        return '';
    }

    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

function validarRutSolicitudParticipante(string $rut): bool
{
    $rut = strtoupper(preg_replace('/[^0-9Kk]/', '', $rut) ?? '');
    if (strlen($rut) < 2) {
        return false;
    }

    $dv = substr($rut, -1);
    $num = substr($rut, 0, -1);
    $suma = 0;
    $factor = 2;

    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $suma += ((int)$num[$i]) * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }

    $resto = 11 - ($suma % 11);
    $esperado = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string)$resto);
    return $dv === $esperado;
}

function rutKeySolicitudParticipante(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', $rut));
}

function resolverWfSolicitudParticipante(PDO $pdo, string $rut): array
{
    $stmt = $pdo->prepare('
        SELECT wf
        FROM ceo_reportewf
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut_empleado), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([':rut' => rutKeySolicitudParticipante($rut)]);
    $wfVal = $stmt->fetchColumn();

    if ($wfVal === false || $wfVal === null || trim((string)$wfVal) === '') {
        return ['wf' => 'No Autorizado', 'autorizado' => 0];
    }

    $numero = (float)str_replace(['%', ','], ['', '.'], trim((string)$wfVal));
    if ($numero >= 100.0) {
        return ['wf' => 'Si', 'autorizado' => 1];
    }

    return ['wf' => 'No', 'autorizado' => 0];
}

try {
    if (empty($_SESSION['auth'])) {
        throw new RuntimeException('Sesión no válida');
    }

    $rol = strtolower((string)($_SESSION['auth']['rol'] ?? ''));
    $idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
    if ($idRol === 6) {
        throw new RuntimeException('No autorizado');
    }
    if ($rol !== 'administrador' && $idRol !== 5) {
        throw new RuntimeException('No autorizado');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método no permitido');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new RuntimeException('JSON inválido');
    }

    $nsolicitud = (int)($data['nsolicitud'] ?? 0);
    $rutInput = trim((string)($data['rut'] ?? ''));
    $nombre = trim((string)($data['nombre'] ?? ''));
    $apellidop = trim((string)($data['apellidop'] ?? ''));
    $apellidom = trim((string)($data['apellidom'] ?? ''));
    $cargoNombre = trim((string)($data['cargo'] ?? ''));

    if ($nsolicitud <= 0 || $rutInput === '' || $nombre === '' || $apellidop === '' || $apellidom === '' || $cargoNombre === '') {
        throw new RuntimeException('Todos los campos son obligatorios');
    }
    if (!validarRutSolicitudParticipante($rutInput)) {
        throw new RuntimeException('RUT inválido');
    }

    $rut = normalizarRutSolicitudParticipante($rutInput);
    $rutKey = rutKeySolicitudParticipante($rut);
    $apellidos = trim($apellidop . ' ' . $apellidom);

    $pdo = db();
    $pdo->beginTransaction();

    $stmtSol = $pdo->prepare('SELECT nsolicitud, estado FROM ceo_solicitudes WHERE nsolicitud = :n LIMIT 1');
    $stmtSol->execute([':n' => $nsolicitud]);
    $sol = $stmtSol->fetch(PDO::FETCH_ASSOC);
    if (!$sol) {
        throw new RuntimeException('Solicitud no existe');
    }
    if (($sol['estado'] ?? '') === 'F') {
        throw new RuntimeException('No se puede modificar una solicitud cerrada');
    }

    $stmtCargo = $pdo->prepare('SELECT id FROM ceo_cargo_contratistas WHERE LOWER(TRIM(cargo)) = LOWER(TRIM(:cargo)) LIMIT 1');
    $stmtCargo->execute([':cargo' => $cargoNombre]);
    $idCargo = $stmtCargo->fetchColumn();
    if (!$idCargo) {
        $stmtInsCargo = $pdo->prepare('INSERT INTO ceo_cargo_contratistas (cargo, estado) VALUES (:cargo, \'A\')');
        $stmtInsCargo->execute([':cargo' => $cargoNombre]);
        $idCargo = (int)$pdo->lastInsertId();
    } else {
        $idCargo = (int)$idCargo;
    }

    $wf = resolverWfSolicitudParticipante($pdo, $rut);

    $stmtExiste = $pdo->prepare('
        SELECT 1
        FROM ceo_participantes_solicitud
        WHERE id_solicitud = :n
          AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
        LIMIT 1
    ');
    $stmtExiste->execute([':n' => $nsolicitud, ':rut' => $rutKey]);
    $existe = (bool)$stmtExiste->fetchColumn();

    if ($existe) {
        $stmtPart = $pdo->prepare('
            UPDATE ceo_participantes_solicitud
            SET rut = :rut,
                nombre = :nombre,
                apellidop = :apellidop,
                apellidom = :apellidom,
                id_cargo = :id_cargo,
                wf = :wf
            WHERE id_solicitud = :n
              AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut_key
        ');
        $stmtPart->execute([
            ':rut' => $rut,
            ':nombre' => $nombre,
            ':apellidop' => $apellidop,
            ':apellidom' => $apellidom,
            ':id_cargo' => $idCargo,
            ':wf' => $wf['wf'],
            ':n' => $nsolicitud,
            ':rut_key' => $rutKey,
        ]);
        $accion = 'actualizado';
    } else {
        $stmtPart = $pdo->prepare('
            INSERT INTO ceo_participantes_solicitud
                (id_solicitud, rut, nombre, apellidop, apellidom, id_cargo, wf, autorizado)
            VALUES
                (:n, :rut, :nombre, :apellidop, :apellidom, :id_cargo, :wf, :autorizado)
        ');
        $stmtPart->execute([
            ':n' => $nsolicitud,
            ':rut' => $rut,
            ':nombre' => $nombre,
            ':apellidop' => $apellidop,
            ':apellidom' => $apellidom,
            ':id_cargo' => $idCargo,
            ':wf' => $wf['wf'],
            ':autorizado' => $wf['autorizado'],
        ]);
        $accion = 'insertado';
    }

    $stmtHab = $pdo->prepare('SELECT id, cuadrilla FROM ceo_habilitacion WHERE nsolicitud = :n');
    $stmtHab->execute([':n' => $nsolicitud]);
    $habilitaciones = $stmtHab->fetchAll(PDO::FETCH_ASSOC);

    foreach ($habilitaciones as $hab) {
        $idHabilitacion = (int)$hab['id'];
        $cuadrilla = (int)$hab['cuadrilla'];
        if ($idHabilitacion <= 0 || $cuadrilla <= 0) {
            continue;
        }

        $stmtExisteHabPart = $pdo->prepare('
            SELECT 1
            FROM ceo_habilitacion_participantes
            WHERE id_cuadrilla = :cuadrilla
              AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut_key
            LIMIT 1
        ');
        $stmtExisteHabPart->execute([':cuadrilla' => $cuadrilla, ':rut_key' => $rutKey]);

        if ($stmtExisteHabPart->fetchColumn()) {
            $stmtUpdHabPart = $pdo->prepare('
                UPDATE ceo_habilitacion_participantes
                SET rut = :rut,
                    nombre = :nombre,
                    apellidos = :apellidos,
                    cargo = :cargo
                WHERE id_cuadrilla = :cuadrilla
                  AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut_key
            ');
            $stmtUpdHabPart->execute([
                ':rut' => $rut,
                ':nombre' => $nombre,
                ':apellidos' => $apellidos,
                ':cargo' => $cargoNombre,
                ':cuadrilla' => $cuadrilla,
                ':rut_key' => $rutKey,
            ]);
        } else {
            $stmtInsHabPart = $pdo->prepare('
                INSERT INTO ceo_habilitacion_participantes
                    (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo)
                VALUES
                    (:cuadrilla, 0, :rut, :nombre, :apellidos, :cargo)
            ');
            $stmtInsHabPart->execute([
                ':cuadrilla' => $cuadrilla,
                ':rut' => $rut,
                ':nombre' => $nombre,
                ':apellidos' => $apellidos,
                ':cargo' => $cargoNombre,
            ]);
        }

        $stmtExisteHabPersona = $pdo->prepare('
            SELECT 1
            FROM ceo_habilitacion_personas
            WHERE id_habilitacion = :id_hab
              AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut_key
            LIMIT 1
        ');
        $stmtExisteHabPersona->execute([':id_hab' => $idHabilitacion, ':rut_key' => $rutKey]);

        if ($stmtExisteHabPersona->fetchColumn()) {
            $stmtUpdHabPersona = $pdo->prepare('
                UPDATE ceo_habilitacion_personas
                SET rut = :rut,
                    nombre = :nombre,
                    apellidos = :apellidos,
                    cargo = :cargo,
                    estado = \'ACTIVO\'
                WHERE id_habilitacion = :id_hab
                  AND REPLACE(REPLACE(REPLACE(UPPER(rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut_key
            ');
            $stmtUpdHabPersona->execute([
                ':rut' => $rut,
                ':nombre' => $nombre,
                ':apellidos' => $apellidos,
                ':cargo' => $cargoNombre,
                ':id_hab' => $idHabilitacion,
                ':rut_key' => $rutKey,
            ]);
        } else {
            $stmtInsHabPersona = $pdo->prepare('
                INSERT INTO ceo_habilitacion_personas
                    (id_habilitacion, rut, nombre, apellidos, cargo, tipo_participacion, estado)
                VALUES
                    (:id_hab, :rut, :nombre, :apellidos, :cargo, \'NO_EVALUA\', \'ACTIVO\')
            ');
            $stmtInsHabPersona->execute([
                ':id_hab' => $idHabilitacion,
                ':rut' => $rut,
                ':nombre' => $nombre,
                ':apellidos' => $apellidos,
                ':cargo' => $cargoNombre,
            ]);
        }
    }

    $pdo->commit();

    echo json_encode(['ok' => true, 'accion' => $accion], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
