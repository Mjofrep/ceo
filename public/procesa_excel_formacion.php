<?php
// procesa_excel_formacion.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../vendor/autoload.php';
require_once '../config/functions.php';
require_once '../config/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// =========================================================
// VALIDACIÓN INICIAL
// =========================================================
if (empty($_SESSION['auth'])) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'No autorizado'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['excel']['tmp_name'])) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'No se recibió archivo Excel'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$idServicio = (int)($_POST['id_servicio'] ?? 0);
if ($idServicio <= 0) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'No se recibió el servicio a validar'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath = $_FILES['excel']['tmp_name'];

// =========================================================
// PROCESAMIENTO DEL EXCEL
// =========================================================
try {
    $pdo = db();
    $reader = IOFactory::createReaderForFile($tmpPath);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmpPath);

    // Usar hoja "Solicitud" o la activa si no existe
    $sheet = $spreadsheet->getSheetByName('Solicitud') ?? $spreadsheet->getActiveSheet();

    // Filas del formato actual
    $inicioFila = 16;
    $finFila    = (int)$sheet->getHighestRow();
    $finFila    = min($finFila, $inicioFila + 2000);

    $participantes = [];
    $errores = [];

    $emptyStreak = 0;

    for ($i = $inicioFila; $i <= $finFila; $i++) {
        $rut   = trim((string)$sheet->getCell("B{$i}")->getValue());
        $nom   = trim((string)$sheet->getCell("C{$i}")->getValue());
        $apPat = trim((string)$sheet->getCell("F{$i}")->getValue());
        $apMat = trim((string)$sheet->getCell("I{$i}")->getValue());
        $cargo = trim((string)$sheet->getCell("L{$i}")->getValue());

        // Fila vacia
        if ($rut === '' && $nom === '' && $apPat === '' && $apMat === '' && $cargo === '') {
            $emptyStreak++;
            if ($emptyStreak >= 5) {
                break;
            }
            continue;
        }

        $emptyStreak = 0;

        // Normalizar RUT
        $rut = normalizarRut($rut);

        // Validar RUT
        if (!validar_rut_backend($rut)) {
            $errores[] = "Fila {$i}: RUT inválido ({$rut})";
            continue;
        }

        // Validar estado del RUT para el servicio
        $estado = obtenerEstadoHabilitacionServicio($pdo, $rut, $idServicio);

        if (!empty($estado['bloquear'])) {
            $errores[] = "Fila {$i}: {$estado['mensaje']}";
            continue;
        }

        // Participante válido
        $participantes[] = [
            'rut'    => $rut,
            'nombre' => $nom,
            'app'    => $apPat,
            'apm'    => $apMat,
            'cargo'  => $cargo
        ];
    }

    // Si hay errores, no continuar
    if (!empty($errores)) {
        echo json_encode([
            'ok'      => false,
            'msg'     => 'Se encontraron inconsistencias en el archivo Excel',
            'errores' => $errores
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Todo correcto
    echo json_encode([
        'ok'            => true,
        'participantes' => $participantes
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'ok'  => false,
        'msg' => 'Error al procesar el archivo: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================
// FUNCIONES
// =========================================================

function normalizarRut(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

function validar_rut_backend(string $rut): bool
{
    $rut = normalizarRut($rut);

    if (strlen($rut) < 2) {
        return false;
    }

    $cuerpo = substr($rut, 0, -1);
    $dv     = substr($rut, -1);

    if (!ctype_digit($cuerpo)) {
        return false;
    }

    $suma = 0;
    $mult = 2;

    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += $mult * (int)$cuerpo[$i];
        $mult = ($mult < 7) ? $mult + 1 : 2;
    }

    $res = 11 - ($suma % 11);
    $dvEsperado = ($res === 11) ? '0' : (($res === 10) ? 'K' : (string)$res);

    return $dv === $dvEsperado;
}

/**
 * Regla semántica:
 *
 * 1) HABILITADO
 *    Si el rut + servicio tiene vigencia activa en ceo_vigencia_detalle
 *    y existe respaldo del mismo proceso en ceo_vigencia_general.
 *
 * 2) EN PROCESO
 *    Si no está habilitado, pero ya existen evaluaciones para ese
 *    rut + servicio y todavía falta aprobar una o más pruebas.
 *
 * 3) LIBRE
 *    Si no cae en ninguna de las anteriores.
 */
function obtenerEstadoHabilitacionServicio(PDO $pdo, string $rut, int $idServicio): array
{
    $rutComp = strtoupper(str_replace(['.', '-', ' '], '', $rut));

    // -----------------------------------------------------
    // 1) VALIDAR SI YA ESTÁ HABILITADO Y VIGENTE
    // -----------------------------------------------------
    $sqlHabilitado = "
        SELECT
            vd.id,
            vd.rut,
            vd.id_servicio,
            vd.id_proceso,
            vd.fechavig_ini,
            vd.fechavig_fin,
            vg.id AS id_vigencia_general
        FROM ceo_vigencia_detalle vd
        INNER JOIN ceo_vigencia_general vg
            ON REPLACE(REPLACE(REPLACE(UPPER(vg.rut), '.', ''), '-', ''), ' ', '') =
               REPLACE(REPLACE(REPLACE(UPPER(vd.rut), '.', ''), '-', ''), ' ', '')
           AND vg.id_proceso = vd.id_proceso
        WHERE REPLACE(REPLACE(REPLACE(UPPER(vd.rut), '.', ''), '-', ''), ' ', '') = :rut
          AND vd.id_servicio = :id_servicio
          AND CURDATE() BETWEEN DATE(vd.fechavig_ini) AND DATE(vd.fechavig_fin)
        ORDER BY vd.fechavig_fin DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sqlHabilitado);
    $stmt->execute([
        ':rut'         => $rutComp,
        ':id_servicio' => $idServicio
    ]);

    $habilitado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($habilitado) {
        return [
            'bloquear' => true,
            'estado'   => 'habilitado',
            'mensaje'  => "el RUT {$rut} ya se encuentra habilitado para este servicio hasta {$habilitado['fechavig_fin']}"
        ];
    }

    // -----------------------------------------------------
    // 2) VALIDAR SI EXISTE UN PROCESO ABIERTO DEL MISMO SERVICIO
    //    SOLO bloquea si hay registros pendientes / no cerrados
    // -----------------------------------------------------
    $sqlProcesoAbierto = "
        SELECT
            COUNT(*) AS total_abiertos,
            COALESCE(MAX(cuadrilla), 0) AS cuadrilla_ref
        FROM ceo_evaluaciones_programadas
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
          AND id_servicio = :id_servicio
          AND estado <> 'ANULADA'
          AND (
                estado = 'PENDIENTE'
                OR resultado IS NULL
                OR resultado = 'PENDIENTE'
          )
    ";

    $stmt = $pdo->prepare($sqlProcesoAbierto);
    $stmt->execute([
        ':rut'         => $rutComp,
        ':id_servicio' => $idServicio
    ]);

    $abierto = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalAbiertos = (int)($abierto['total_abiertos'] ?? 0);

    if ($totalAbiertos > 0) {
        $cuadrillaRef = !empty($abierto['cuadrilla_ref']) ? $abierto['cuadrilla_ref'] : 's/i';

        return [
            'bloquear' => true,
            'estado'   => 'en_proceso',
            'mensaje'  => "el RUT {$rut} ya se encuentra en proceso de habilitación para este servicio (cuadrilla/proceso {$cuadrillaRef})"
        ];
    }

    // -----------------------------------------------------
    // 3) LIBRE
    //    Si solo hay históricos REPROBADOS o procesos cerrados,
    //    se permite generar un nuevo proceso
    // -----------------------------------------------------
    return [
        'bloquear' => false,
        'estado'   => 'libre',
        'mensaje'  => ''
    ];
}
