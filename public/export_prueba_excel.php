<?php
// --------------------------------------------------------------
// export_prueba_excel.php - Exportar banco de preguntas a Excel
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Validar usuario
if (empty($_SESSION['auth'])) {
    die("Acceso denegado");
}

$pdo = db();
$id_agrupacion = (int)($_GET['id_agrupacion'] ?? 0);
if ($id_agrupacion <= 0) {
    die("Agrupación inválida");
}

// =================== CONSULTA DE PREGUNTAS ===================
$q = $pdo->prepare("
    SELECT p.id, p.pregunta, p.imagen, p.retropos, p.retroneg,
           (SELECT JSON_ARRAYAGG(JSON_OBJECT(
              'id', a.id,
              'alternativa', a.alternativa,
              'correcta', a.correcta,
              'imagen', a.imagen
            ))
            FROM ceo_alternativas_preguntas a
            WHERE a.id_pregunta = p.id
           ) AS alternativas
    FROM ceo_preguntas_servicios p
    WHERE p.id_agrupacion = :id
    ORDER BY p.id ASC
");
$q->execute([':id' => $id_agrupacion]);
$preguntas = $q->fetchAll(PDO::FETCH_ASSOC);

// =================== GENERAR EXCEL ===================
$excel = new Spreadsheet();
$excel->getProperties()
      ->setCreator("CEO ENEL")
      ->setTitle("Banco Preguntas Agrupación $id_agrupacion");

$hoja = $excel->getActiveSheet();
$hoja->setTitle("Preguntas");

// Encabezados
$cols = ["A"=>"ID Pregunta","B"=>"Pregunta","C"=>"Imagen Pregunta","D"=>"Alternativa",
         "E"=>"Correcta","F"=>"Imagen Alternativa"];
foreach ($cols as $col=>$titulo) {
    $hoja->setCellValue("$col"."1", $titulo);
    $hoja->getStyle("$col"."1")->getFont()->setBold(true);
}

$fila = 2;

// =================== VOLCAR DATOS ===================
foreach ($preguntas as $p) {
    $alts = json_decode($p['alternativas'], true) ?? [];

    // Limpia HTML
    $preguntaLimpia = trim(strip_tags($p['pregunta']));
    $retroPos = trim(strip_tags($p['retropos']));
    $retroNeg = trim(strip_tags($p['retroneg']));

    if (empty($alts)) {
        // Pregunta sin alternativas
        $hoja->setCellValue("A$fila", $p['id']);
        $hoja->setCellValue("B$fila", $preguntaLimpia);
        $hoja->setCellValue("C$fila", $p['imagen']);
        $fila++;
        continue;
    }

    foreach ($alts as $a) {
        $hoja->setCellValue("A$fila", $p['id']);
        $hoja->setCellValue("B$fila", $preguntaLimpia);
        $hoja->setCellValue("C$fila", $p['imagen']);
        $hoja->setCellValue("D$fila", strip_tags($a['alternativa']));
        $hoja->setCellValue("E$fila", $a['correcta'] === "S" ? "✔ Correcta" : "Incorrecta");
        $hoja->setCellValue("F$fila", $a['imagen']);
        $fila++;
    }
}

// =================== DESCARGA ===================
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=preguntas_agrupacion_$id_agrupacion.xlsx");
header('Cache-Control: max-age=0');

$writer = new Xlsx($excel);
$writer->save('php://output');
exit;
