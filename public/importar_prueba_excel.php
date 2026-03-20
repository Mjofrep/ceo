<?php
// --------------------------------------------------------------
// importar_prueba_excel.php
// Importa preguntas + alternativas desde Excel
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (empty($_SESSION['auth'])) {
    die("Acceso denegado.");
}

$pdo = db();
$id_agrupacion = (int)($_POST['id_agrupacion'] ?? 0);

if ($id_agrupacion <= 0) {
    exit("Agrupación inválida.");
}

if (empty($_FILES['archivo']['tmp_name'])) {
    exit("No se subió archivo.");
}

$archivo = $_FILES['archivo']['tmp_name'];

// ===== LEER EL EXCEL =====
$spreadsheet = IOFactory::load($archivo);
$hoja = $spreadsheet->getActiveSheet();

$rows = $hoja->toArray(null, true, true, true);

// Estructura esperada:
$COL_IDPREG = "A";
$COL_PREGUNTA = "B";
$COL_IMG_PREG = "C";
$COL_ALT = "D";
$COL_CORRECTA = "E";
$COL_IMG_ALT = "F";

$currentPreguntaID = null;
$preguntaDBid = null;

// Recorrido
foreach ($rows as $i => $r) {
    if ($i == 1) continue; // Saltar encabezado

    $idPreguntaArchivo = trim($r[$COL_IDPREG] ?? '');
    $textoPregunta = trim($r[$COL_PREGUNTA] ?? '');
    $imgPregunta = trim($r[$COL_IMG_PREG] ?? '');
    $alternativa = trim($r[$COL_ALT] ?? '');
    $correcta = trim($r[$COL_CORRECTA] ?? '');
    $imgAlt = trim($r[$COL_IMG_ALT] ?? '');

    if ($textoPregunta === '') continue;

    // =========================
    // NUEVA PREGUNTA
    // =========================
    if ($idPreguntaArchivo !== $currentPreguntaID) {
        $currentPreguntaID = $idPreguntaArchivo;

        // Revisar si ya existe
        $chk = $pdo->prepare(
            "SELECT id FROM ceo_preguntas_servicios
             WHERE pregunta = :p AND id_agrupacion = :g LIMIT 1"
        );
        $chk->execute([
            ':p' => $textoPregunta,
            ':g' => $id_agrupacion
        ]);

        if ($row = $chk->fetch()) {
            // Ya existe → reutilizar ID
            $preguntaDBid = (int)$row['id'];
        } else {
            // Insertar pregunta
            $insQ = $pdo->prepare("
                INSERT INTO ceo_preguntas_servicios
                (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg)
                VALUES (:p, (SELECT id_servicio FROM ceo_agrupacion WHERE id=:g),
                        :img, 'S', :g, '', '')
            ");
            $insQ->execute([
                ':p' => $textoPregunta,
                ':img' => $imgPregunta,
                ':g' => $id_agrupacion
            ]);
            $preguntaDBid = (int)$pdo->lastInsertId();
        }
    }

    // =========================
    // INSERTAR ALTERNATIVA
    // =========================
    if ($alternativa !== '') {

        $insA = $pdo->prepare("
            INSERT INTO ceo_alternativas_preguntas
            (alternativa, correcta, estado, id_pregunta, imagen)
            VALUES (:a, :c, 'S', :p, :img)
        ");
        $insA->execute([
            ':a' => $alternativa,
            ':c' => ($correcta === '✔ Correcta') ? 'S' : 'N',
            ':p' => $preguntaDBid,
            ':img' => $imgAlt
        ]);
    }
}

// =========================
// RETURN
// =========================
header("Location: pruebas_teoricas.php?msg=import_ok");
exit;

