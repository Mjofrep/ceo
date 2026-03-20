<?php
// /public/mant_agrupacion_consolidado_save.php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

/* ============================================================
   VALIDACIÓN DE SESIÓN
   ============================================================ */
if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();

/* ============================================================
   RECEPCIÓN DE DATOS
   ============================================================ */
$id          = (int)($_POST['id'] ?? 0);
$nombre      = trim($_POST['nombre'] ?? '');
$id_servicio = (int)($_POST['id_servicio'] ?? 0);

$formulao   = trim($_POST['formulao'] ?? '');
$formulas   = trim($_POST['formulas'] ?? '');
$formulav   = trim($_POST['formulav'] ?? '');
$estado     = $_POST['estado'] ?? 'S';
$fechadesde = $_POST['fechadesde'] ?? null;
$porceno = ($_POST['porceno'] !== '') ? (int)$_POST['porceno'] : null;
$porcens = ($_POST['porcens'] !== '') ? (int)$_POST['porcens'] : null;
$porcenv = ($_POST['porcenv'] !== '') ? (int)$_POST['porcenv'] : null;
/* ============================================================
   VALIDACIONES BÁSICAS
   ============================================================ */
if ($nombre === '' || $id_servicio <= 0) {
    // Puedes mejorar esto con mensajes por sesión
    header('Location: mant_agrupacion_consolidado.php');
    exit;
}

/* ============================================================
   ARMADO DINÁMICO DE CAMPOS
   ============================================================ */
$campos = [
    'nombre'      => $nombre,
    'id_servicio' => $id_servicio,
    'formulao'    => $formulao,
    'formulas'    => $formulas,
    'formulav'    => $formulav,
    'estado'      => $estado,
    'fechadesde'  => $fechadesde,
    'porceno'     => $porceno,
    'porcens'     => $porcens,
    'porcenv'     => $porcenv
];

/* ============================================================
   VALIDACIÓN DE PORCENTAJES DE APROBACIÓN
   ============================================================ */
foreach (
    [
        'porceno' => $porceno,
        'porcens' => $porcens,
        'porcenv' => $porcenv
    ] as $campo => $valor
) {
    if ($valor !== null && ($valor < 0 || $valor > 100)) {
        die("❌ El valor de $campo debe estar entre 0 y 100");
    }
}

// id_teo1 … id_teo10
for ($i = 1; $i <= 10; $i++) {
    $campos["id_teo$i"] = !empty($_POST["id_teo$i"])
        ? (int)$_POST["id_teo$i"]
        : null;
}

// id_ter1 … id_ter10
for ($i = 1; $i <= 10; $i++) {
    $campos["id_ter$i"] = !empty($_POST["id_ter$i"])
        ? (int)$_POST["id_ter$i"]
        : null;
}

/* ============================================================
   INSERT / UPDATE
   ============================================================ */
if ($id > 0) {

    /* ===================== UPDATE ===================== */
    $sets = [];
    foreach ($campos as $col => $val) {
        $sets[] = "$col = :$col";
    }

    $sql = "
        UPDATE ceo_agrupacion_consolidado
           SET " . implode(', ', $sets) . "
         WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $campos['id'] = $id;
    $stmt->execute($campos);

} else {

    /* ===================== INSERT ===================== */
    $columns = implode(', ', array_keys($campos));
    $params  = ':' . implode(', :', array_keys($campos));

    $sql = "
        INSERT INTO ceo_agrupacion_consolidado
            ($columns)
        VALUES
            ($params)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($campos);
}

/* ============================================================
   REDIRECCIÓN FINAL
   ============================================================ */
header('Location: mant_agrupacion_consolidado.php');
exit;
