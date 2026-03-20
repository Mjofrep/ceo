<?php
ini_set('display_errors','1');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

try {
    if (empty($_FILES['excel']['tmp_name'])) {
        throw new Exception('No se recibió ningún archivo.');
    }

    // =============================
    // INICIAR TRANSACCIÓN
    // =============================
    $pdo->beginTransaction();

    // =============================
    // BORRAR TODO
    // =============================
    $pdo->exec("DELETE FROM ceo_reportewf");

    // =============================
    // LEER EXCEL
    // =============================
    $tmp = $_FILES['excel']['tmp_name'];
    $spreadsheet = IOFactory::load($tmp);
    $sheet = $spreadsheet->getSheetByName('Personas (2)')
           ?? $spreadsheet->getActiveSheet();

    $data  = $sheet->rangeToArray('A2:J10000', null, true, false);
    $filas = array_filter($data, fn($r) => !empty($r[3]) && !empty($r[4]));

    $totalLeidos = 0;
    $insertados  = 0;

    // =============================
    // PREPARED INSERT
    // =============================
    $stmtInsert = $pdo->prepare("
        INSERT INTO ceo_reportewf
        (tipo, mandante, contratista, contrato, codigo,
         rut_empleado, nombres, apellidos, wf, servicio, cargo, fecha_carga)
        VALUES
        (:tipo, :mand, :contr, :cont, :cod,
         :rut, :nom, :ape, :wf, :serv, :cargo, NOW())
    ");

    foreach ($filas as $r) {
        $totalLeidos++;

        $stmtInsert->execute([
            ':tipo'  => trim((string)$r[0]),
            ':mand'  => trim((string)$r[1]),
            ':contr' => trim((string)$r[2]),
            ':cont'  => '',
            ':cod'   => '',
            ':rut'   => trim((string)$r[3]),
            ':nom'   => trim((string)$r[4]),
            ':ape'   => trim((string)$r[5]),
            ':wf'    => trim((string)$r[7]),
            ':serv'  => trim((string)$r[9]),
            ':cargo' => trim((string)$r[6])
        ]);

        $insertados++;
    }

    // =============================
    // COMMIT
    // =============================
    $pdo->commit();

    echo json_encode([
        'ok'         => true,
        'leidos'     => $totalLeidos,
        'insertados' => $insertados,
        'msg'        => "WF actualizado correctamente. Registros cargados: $insertados"
    ]);

} catch (Throwable $e) {

    // ROLLBACK si algo falla
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}

