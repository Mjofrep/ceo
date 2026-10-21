<?php
declare(strict_types=1);
session_start();

header('Content-Type: application/json');

require_once '../config/db.php';

if (empty($_SESSION['auth'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

$pdo = db();

$fecha     = $_POST['fecha'] ?? '';
$jornada   = $_POST['jornada'] ?? '';
$servicio  = (int)($_POST['servicio'] ?? 0);
$empresa   = (int)($_SESSION['auth']['id_empresa'] ?? 0);
//$empresa   = (int)($_POST['empresa'] ?? 0); 
$uo = (int)($_POST['uo'] ?? 0);
if ($uo <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Unidad Operativa obligatoria']);
    exit;
}

$gestor    = $_SESSION['auth']['id'];  // usuario conectado
$particip  = json_decode($_POST['participantes'] ?? '[]', true);
$cuadrillaExistente = (int)($_POST['cuadrilla'] ?? 0);
$esNuevaPlanificacion = ($cuadrillaExistente <= 0);
$rolUsuario = strtolower((string) ($_SESSION['auth']['rol'] ?? ''));
$esContratista = ($rolUsuario === 'contratista');

if (!$fecha || !$jornada || !$servicio || empty($particip)) {
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']);
    exit;
}

try {
    $pdo->beginTransaction();

    if ($cuadrillaExistente > 0) {
        $cuadrilla = $cuadrillaExistente;

        $stHabExistente = $pdo->prepare('
            SELECT id, empresa
            FROM ceo_habilitacion
            WHERE cuadrilla = :cuadrilla
              AND fecha = :fecha
              AND jornada = :jornada
              AND id_servicio = :servicio
            LIMIT 1
            FOR UPDATE
        ');
        $stHabExistente->execute([
            ':cuadrilla' => $cuadrilla,
            ':fecha' => $fecha,
            ':jornada' => $jornada,
            ':servicio' => $servicio,
        ]);
        $habilitacionExistente = $stHabExistente->fetch(PDO::FETCH_ASSOC);

        if (!$habilitacionExistente) {
            throw new Exception('No se encontró la planificación a modificar');
        }

        if ($esContratista && (int) $habilitacionExistente['empresa'] !== $empresa) {
            throw new Exception('No puedes modificar cuadrillas de otra empresa');
        }

        // Una vez programadas pruebas, la planificación deja de ser editable.
        $stPruebas = $pdo->prepare('
            SELECT COUNT(*)
            FROM ceo_evaluaciones_programadas
            WHERE cuadrilla = :cuadrilla
              AND id_servicio = :servicio
        ');
        $stPruebas->execute([
            ':cuadrilla' => $cuadrilla,
            ':servicio' => $servicio,
        ]);
        if ((int) $stPruebas->fetchColumn() > 0) {
            throw new Exception('No se puede modificar una cuadrilla con pruebas planificadas o ejecutadas');
        }

        $idHabilitacion = (int) $habilitacionExistente['id'];
        $stUo = $pdo->prepare('UPDATE ceo_habilitacion SET uo = :uo WHERE id = :id');
        $stUo->execute([':uo' => $uo, ':id' => $idHabilitacion]);

    } else {
        $pdo->exec("
            UPDATE ceo_secuencia_cuadrilla
            SET ultimo_numero = LAST_INSERT_ID(ultimo_numero + 1)
        ");
        $cuadrilla = (int)$pdo->lastInsertId();

        $sqlHab = "INSERT INTO ceo_habilitacion
                    (fecha, jornada, id_servicio, cuadrilla, empresa, uo, gestor)
                   VALUES (:f, :j, :s, :c, :e, :u, :g)";
        $st = $pdo->prepare($sqlHab);
        $st->execute([
            ':f' => $fecha,
            ':j' => $jornada,
            ':s' => $servicio,
            ':c' => $cuadrilla,
            ':e' => $empresa,
            ':u' => $uo,
            ':g' => $gestor
        ]);
        $stHabId = $pdo->prepare('SELECT id FROM ceo_habilitacion WHERE cuadrilla = :cuadrilla LIMIT 1');
        $stHabId->execute([':cuadrilla' => $cuadrilla]);
        $idHabilitacion = (int) $stHabId->fetchColumn();
    }

    if ($idHabilitacion <= 0) {
        throw new Exception("No se encontró la habilitación para la cuadrilla {$cuadrilla}");
    }

    $participantes = [];
    foreach ($particip as $p) {
        $rut = normalizar_rut(trim((string) ($p['rut'] ?? '')));
        $rutKey = clave_rut($rut);
        if ($rutKey === '') {
            throw new Exception('Todos los participantes deben tener RUT');
        }
        if (isset($participantes[$rutKey])) {
            throw new Exception("El RUT {$rut} está repetido en la cuadrilla");
        }

        $participantes[$rutKey] = [
            'rut' => $rut,
            'nombre' => trim((string) ($p['nombre'] ?? '')),
            'apellidos' => trim((string) ($p['app'] ?? '') . ' ' . (string) ($p['apm'] ?? '')),
            'cargo' => trim((string) ($p['cargo'] ?? '')),
        ];
    }

    $sqlClaveRut = "REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '')";
    $stExistentes = $pdo->prepare("SELECT rut FROM ceo_habilitacion_participantes WHERE id_cuadrilla = :cuadrilla");
    $stExistentes->execute([':cuadrilla' => $cuadrilla]);
    $stEliminarParticipante = $pdo->prepare("DELETE FROM ceo_habilitacion_participantes WHERE id_cuadrilla = :cuadrilla AND {$sqlClaveRut} = :rut_key");
    foreach ($stExistentes->fetchAll(PDO::FETCH_COLUMN) as $rutExistente) {
        if (!isset($participantes[clave_rut($rutExistente)])) {
            $stEliminarParticipante->execute([
                ':cuadrilla' => $cuadrilla,
                ':rut_key' => clave_rut($rutExistente),
            ]);
        }
    }

    $stExistentesBase = $pdo->prepare("SELECT rut FROM ceo_habilitacion_personas WHERE id_habilitacion = :id_habilitacion AND tipo_participacion = 'NO_EVALUA'");
    $stExistentesBase->execute([':id_habilitacion' => $idHabilitacion]);
    $stEliminarBase = $pdo->prepare("DELETE FROM ceo_habilitacion_personas WHERE id_habilitacion = :id_habilitacion AND tipo_participacion = 'NO_EVALUA' AND {$sqlClaveRut} = :rut_key");
    foreach ($stExistentesBase->fetchAll(PDO::FETCH_COLUMN) as $rutExistente) {
        if (!isset($participantes[clave_rut($rutExistente)])) {
            $stEliminarBase->execute([
                ':id_habilitacion' => $idHabilitacion,
                ':rut_key' => clave_rut($rutExistente),
            ]);
        }
    }

    $stBuscarParticipante = $pdo->prepare("SELECT 1 FROM ceo_habilitacion_participantes WHERE id_cuadrilla = :cuadrilla AND {$sqlClaveRut} = :rut_key LIMIT 1");
    $stActualizarParticipante = $pdo->prepare("UPDATE ceo_habilitacion_participantes SET rut = :rut, nombre = :nombre, apellidos = :apellidos, cargo = :cargo WHERE id_cuadrilla = :cuadrilla AND {$sqlClaveRut} = :rut_key");
    $stInsertarParticipante = $pdo->prepare('INSERT INTO ceo_habilitacion_participantes (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo) VALUES (:cuadrilla, 0, :rut, :nombre, :apellidos, :cargo)');
    $stBuscarBase = $pdo->prepare("SELECT 1 FROM ceo_habilitacion_personas WHERE id_habilitacion = :id_habilitacion AND {$sqlClaveRut} = :rut_key LIMIT 1");
    $stActualizarBase = $pdo->prepare("UPDATE ceo_habilitacion_personas SET rut = :rut, nombre = :nombre, apellidos = :apellidos, cargo = :cargo, estado = 'ACTIVO' WHERE id_habilitacion = :id_habilitacion AND {$sqlClaveRut} = :rut_key");
    $stInsertarBase = $pdo->prepare("INSERT INTO ceo_habilitacion_personas (id_habilitacion, rut, nombre, apellidos, cargo, tipo_participacion, estado) VALUES (:id_habilitacion, :rut, :nombre, :apellidos, :cargo, 'NO_EVALUA', 'ACTIVO')");

    foreach ($participantes as $rutKey => $p) {
        $paramsParticipante = [
            ':cuadrilla' => $cuadrilla,
            ':rut_key' => $rutKey,
            ':rut' => $p['rut'],
            ':nombre' => $p['nombre'],
            ':apellidos' => $p['apellidos'],
            ':cargo' => $p['cargo'],
        ];
        $stBuscarParticipante->execute([':cuadrilla' => $cuadrilla, ':rut_key' => $rutKey]);
        if ($stBuscarParticipante->fetchColumn()) {
            $stActualizarParticipante->execute($paramsParticipante);
        } else {
            unset($paramsParticipante[':rut_key']);
            $stInsertarParticipante->execute($paramsParticipante);
        }

        $paramsBase = [
            ':id_habilitacion' => $idHabilitacion,
            ':rut_key' => $rutKey,
            ':rut' => $p['rut'],
            ':nombre' => $p['nombre'],
            ':apellidos' => $p['apellidos'],
            ':cargo' => $p['cargo'],
        ];
        $stBuscarBase->execute([':id_habilitacion' => $idHabilitacion, ':rut_key' => $rutKey]);
        if ($stBuscarBase->fetchColumn()) {
            $stActualizarBase->execute($paramsBase);
        } else {
            unset($paramsBase[':rut_key']);
            $stInsertarBase->execute($paramsBase);
        }
    }


    $pdo->commit();
    if ($esNuevaPlanificacion) {
    try {
        require_once __DIR__ . '/notificar_nueva_planificacion.php';
// ===============================================
// OBTENER NOMBRE EMPRESA Y UO (ANTES DEL CORREO)
// ===============================================

        // Nombre Empresa
        $stEmp = $pdo->prepare("
            SELECT nombre 
            FROM ceo_empresas 
            WHERE id = :id
            LIMIT 1
        ");
        $stEmp->execute([':id' => $empresa]);
        $nombreEmpresa = $stEmp->fetchColumn() ?: '—';
        
        // Nombre UO
        $stUo = $pdo->prepare("
            SELECT desc_uo 
            FROM ceo_uo 
            WHERE id = :id
            LIMIT 1
        ");
        $stUo->execute([':id' => $uo]);
        $nombreUo = $stUo->fetchColumn() ?: '—';

        // Nombre Servicio
        $stser = $pdo->prepare("
            SELECT servicio 
            FROM ceo_servicios_pruebas
            WHERE id = :id
            LIMIT 1
        ");
        $stser->execute([':id' => $servicio]);
        $nombreservicio = $stser->fetchColumn() ?: '—';
        
        require_once __DIR__ . '/notificar_nueva_planificacion.php';
        
        notificarNuevaPlanificacion(
            $cuadrilla,
            $fecha,
            $jornada,
            $nombreEmpresa,
            $nombreUo,
            $nombreservicio
        );

    } catch (Throwable $t) {
        // ⚠️ No bloquea el flujo si falla el correo
        // error_log($t->getMessage());
    }
}
    echo json_encode(['ok' => true, 'cuadrilla' => $cuadrilla]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}

// ===============================================
// NORMALIZA RUT
//=================================================

function normalizar_rut($rut) {
    // Quitar puntos
    $rut = str_replace('.', '', $rut);
    $rut = strtoupper($rut);

    // Si ya tiene guion, devolver tal cual (solo asegurando formato)
    if (strpos($rut, '-') !== false) {
        return $rut;
    }

    // Insertar guion antes del último carácter
    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

function clave_rut($rut) {
    return str_replace(['.', '-', ' '], '', strtoupper(trim((string) $rut)));
}
