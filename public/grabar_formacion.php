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
$empresa   = $_SESSION['auth']['id_empresa'];
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

if (!$fecha || !$jornada || !$servicio || empty($particip)) {
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']);
    exit;
}

try {
    $pdo->beginTransaction();

    /* ============================================================
        1) SI YA EXISTE CUADRILLA → ACTUALIZAR (NO GENERAR NUEVA)
    ============================================================ */
    if ($cuadrillaExistente > 0) {
        $cuadrilla = $cuadrillaExistente;

        // Borrar participantes previos
        $stDel = $pdo->prepare("DELETE FROM ceo_formacion_participantes WHERE id_cuadrilla = ?");
        $stDel->execute([$cuadrilla]);

    } else {
        /* ============================================================
            2) OBTENER NUEVO NÚMERO DE CUADRILLA
        ============================================================ */
        $pdo->exec("
            UPDATE ceo_secuencia_formacion
            SET ultimo_numero = LAST_INSERT_ID(ultimo_numero + 1)
        ");
        $cuadrilla = (int)$pdo->lastInsertId();

        /* ============================================================
            3) INSERTAR REGISTRO EN ceo_formacion
        ============================================================ */
        $sqlHab = "INSERT INTO ceo_formacion 
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
    }

// 🔑 Obtener id_formacion existente
$stHabId = $pdo->prepare("
    SELECT id
    FROM ceo_formacion
    WHERE cuadrilla = :c
    LIMIT 1
");
$stHabId->execute([':c' => $cuadrilla]);
$idFormacion = (int)$stHabId->fetchColumn();

if ($idFormacion <= 0) {
    throw new Exception("No se encontro la formacion para la cuadrilla {$cuadrilla}");
}

    /* ============================================================
        4) GUARDAR PARTICIPANTES
    ============================================================ */
    $sqlPart = "INSERT INTO ceo_formacion_participantes
                (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo)
                VALUES (:c, :r, :rut, :nom, :ape, :cargo)";

    $stp = $pdo->prepare($sqlPart);
    $sqlBase = "
    INSERT INTO ceo_formacion_personas
    (id_formacion, rut, nombre, apellidos, cargo, tipo_participacion, estado)
    VALUES
    (:id_formacion, :rut, :nom, :ape, :cargo, :tipo, 'ACTIVO')
    ON DUPLICATE KEY UPDATE
        nombre = VALUES(nombre),
        apellidos = VALUES(apellidos),
        cargo = VALUES(cargo),
        estado = 'ACTIVO'
    ";
    
    $stBase = $pdo->prepare($sqlBase);

foreach ($particip as $p) {

    $rut = normalizar_rut($p['rut']);
    $nombre = trim($p['nombre']);
    $apellidos = trim($p['app'] . ' ' . $p['apm']);

    /* ============================================================
       4.1) INSERTAR SIEMPRE EN TABLA BASE (PERMISO)
    ============================================================ */
    $stBase->execute([
    ':id_formacion' => $idFormacion,   // 🔑 USAR SIEMPRE ESTE
    ':rut'    => $rut,
    ':nom'    => $nombre,
    ':ape'    => $apellidos,
    ':cargo'  => $p['cargo'],
    ':tipo'   => 'NO_EVALUA'
]);


    /* ============================================================
       4.2) INSERTAR EN TABLA DE EVALUACIÓN (LO QUE YA EXISTÍA)
    ============================================================ */
    $stp->execute([
        ':c'     => $cuadrilla,
        ':r'     => 0,
        ':rut'   => $rut,
        ':nom'   => $nombre,
        ':ape'   => $apellidos,
        ':cargo' => $p['cargo']
    ]);
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
            FROM ceo_formacion_servicios
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

} catch (Exception $e) {
    $pdo->rollBack();
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
