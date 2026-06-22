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

function exportPruebaNormalizeText(mixed $value): string
{
    $text = (string)($value ?? '');
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $text = str_replace("\xC2\xA0", ' ', $text);

    if (function_exists('mb_convert_encoding')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

    return trim($text);
}

function exportPruebaDecodeAlternativas(mixed $value): array
{
    if (!is_string($value) || $value === '') {
        return [];
    }

    if (function_exists('mb_convert_encoding')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

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

try {
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
        $alts = exportPruebaDecodeAlternativas($p['alternativas'] ?? null);
        $preguntaLimpia = exportPruebaNormalizeText($p['pregunta'] ?? '');
        $imagenPregunta = exportPruebaNormalizeText($p['imagen'] ?? '');

        if (empty($alts)) {
            $hoja->setCellValue("A$fila", (int)($p['id'] ?? 0));
            $hoja->setCellValue("B$fila", $preguntaLimpia);
            $hoja->setCellValue("C$fila", $imagenPregunta);
            $fila++;
            continue;
        }

        foreach ($alts as $a) {
            $hoja->setCellValue("A$fila", (int)($p['id'] ?? 0));
            $hoja->setCellValue("B$fila", $preguntaLimpia);
            $hoja->setCellValue("C$fila", $imagenPregunta);
            $hoja->setCellValue("D$fila", exportPruebaNormalizeText($a['alternativa'] ?? ''));
            $hoja->setCellValue("E$fila", ($a['correcta'] ?? '') === "S" ? "✔ Correcta" : "Incorrecta");
            $hoja->setCellValue("F$fila", exportPruebaNormalizeText($a['imagen'] ?? ''));
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
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No fue posible exportar las preguntas a Excel: ' . $e->getMessage();
    exit;
}
