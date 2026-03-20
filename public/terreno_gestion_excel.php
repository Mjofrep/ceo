<?php
// terreno_gestion_excel.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    exit("Acceso no autorizado");
}

$id_grupo = $_GET['id_grupo'] ?? null;
if (!$id_grupo) exit("No se seleccionó agrupación.");

$db = db();

require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ===========================================================
// CONSULTA MAESTRA
// ===========================================================
$stmt = $db->prepare("
    SELECT 
        a.id AS id_grupo,
        a.grupo,
        s.id AS id_seccion,
        s.seccion,
        s.nombre AS nombre_seccion,
        s.orden AS orden_seccion,
        t.id AS id_pregunta,
        t.pregunta,
        t.ponderacion,
        t.practico,
        t.referente,
        t.orden AS orden_pregunta
    FROM ceo_agrupacion_terreno a
    LEFT JOIN ceo_seccion_terreno s ON s.id_grupo = a.id
    LEFT JOIN ceo_preguntas_seccion_terreno t ON t.id_seccion = s.id
    WHERE a.id = ?
    ORDER BY s.orden, t.orden
");
$stmt->execute([$id_grupo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) exit("No hay datos para esta agrupación.");

$nombreGrupo = $rows[0]['grupo'];

// ===========================================================
// AGRUPAR SECCIONES
// ===========================================================
$estructura = [];

foreach ($rows as $r) {
    if (!$r['id_seccion']) continue;

    $secKey = $r['id_seccion'];

    if (!isset($estructura[$secKey])) {
        $estructura[$secKey] = [
            'nombre' => $r['nombre_seccion'],
            'preguntas' => []
        ];
    }

    if ($r['id_pregunta']) {
        $estructura[$secKey]['preguntas'][] = [
            'pregunta'     => $r['pregunta'],
            'ponderacion'  => $r['ponderacion'],
            'practico'     => $r['practico'],
            'referente'    => $r['referente']
        ];
    }
}

// ===========================================================
// CREAR EXCEL
// ===========================================================
$excel = new Spreadsheet();
$sheet = $excel->getActiveSheet();
$sheet->setTitle("Prueba Terreno");

// ===========================================================
// AGREGAR LOGO
// ===========================================================
$logoPath = __DIR__ . "/../public" . APP_LOGO; // Ruta real al logo

if (file_exists($logoPath)) {
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setName('Logo');
    $drawing->setDescription('Logo ENEL');
    $drawing->setPath($logoPath);
    $drawing->setHeight(60);
    $drawing->setCoordinates('A1');
    $drawing->setOffsetX(10);
    $drawing->setOffsetY(5);
    $drawing->setWorksheet($sheet);
}

// Comenzamos más abajo del logo
$row = 5;

// ===========================================================
// TÍTULO GENERAL
// ===========================================================
$sheet->setCellValue("A{$row}", "Prueba de Terreno - " . $nombreGrupo);
$sheet->mergeCells("A{$row}:H{$row}");
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
$row += 2;

// ===========================================================
// ENCABEZADO TIPO TABLA (EDITABLE)
// ===========================================================
$encabezado = [
    "Empresa CTTA:",
    "Fecha Habilitación:",
    "Unidad Operativa:",
    "Servicio:",
    "Evaluador ENEL:",
    "RUT Evaluador:",
    "Persona Evaluada:",
    "RUT:",
    "Cargo:"
];

foreach ($encabezado as $label) {

    // Columna A – Título
    $sheet->setCellValue("A{$row}", $label);
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);

    // Columnas B–H – Celdas vacías editables
    $sheet->getStyle("B{$row}:H{$row}")
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB("FFF2F2F2"); // gris claro

    // Bordes
    $sheet->getStyle("A{$row}:H{$row}")
        ->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $row++;
}

$row++;

// ===========================================================
// SECCIONES + PREGUNTAS
// ===========================================================
$index = 0;

foreach ($estructura as $sec):

    $isGreen = ($index % 2 === 0);
    $colorSection = $isGreen ? "FFD9EAD3" : "FFFCE4D6"; // Verde / Naranjo
    $colorRow     = $isGreen ? "FFF3F8F0" : "FFFEEFE5";

    // SECCIÓN (fondo)
    $sheet->setCellValue("A{$row}", $sec['nombre']);
    $sheet->mergeCells("A{$row}:H{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:H{$row}")
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB($colorSection);

    $sheet->getStyle("A{$row}:H{$row}")
        ->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN);

    $row++;

    // ENCABEZADO TABLA
    $headers = ["Pregunta", "SI", "NO", "NA", "Observaciones", "Ponderación", "Práctico", "Referente"];
    $col = "A";
    foreach ($headers as $h) {
        $sheet->setCellValue("{$col}{$row}", $h);
        $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("{$col}{$row}")
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB("FFE6F2FF");
        $sheet->getStyle("{$col}{$row}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $col++;
    }

    $row++;

    // PREGUNTAS
    foreach ($sec['preguntas'] as $p) {

        $sheet->setCellValue("A{$row}", $p['pregunta']);
        $sheet->setCellValue("F{$row}", $p['ponderacion'] . "%");
        $sheet->setCellValue("G{$row}", $p['practico']);
        $sheet->setCellValue("H{$row}", $p['referente']);

        // Fondo de fila
        $sheet->getStyle("A{$row}:H{$row}")
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB($colorRow);

        // Bordes fila
        $sheet->getStyle("A{$row}:H{$row}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $row++;
    }

    $row++;
    $index++;

endforeach;

// ===========================================================
// ANCHOS DE COLUMNA
// ===========================================================
$widths = [50, 6, 6, 6, 30, 12, 12, 12];
$col = "A";
foreach ($widths as $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
    $col++;
}

// ===========================================================
// DESCARGA
// ===========================================================
$filename = "Prueba_Terreno_" . $nombreGrupo . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($excel);
$writer->save('php://output');
exit;
