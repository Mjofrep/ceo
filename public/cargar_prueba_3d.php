<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

const PRUEBA_3D_SESSION_KEY = 'prueba_3d_analysis';

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$pdo = db();
p3dEnsureTable($pdo);

$mensaje = '';
$mensajeTipo = 'info';
$analysis = $_SESSION[PRUEBA_3D_SESSION_KEY] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'analizar') {
        try {
            if (empty($_FILES['excel']['tmp_name'])) {
                throw new RuntimeException('Debe seleccionar el archivo Excel de Prueba 3D.');
            }

            $analysis = p3dAnalyzeWorkbook(
                $pdo,
                (string)$_FILES['excel']['tmp_name'],
                (string)($_FILES['excel']['name'] ?? 'Prueba 3D.xlsx')
            );
            $_SESSION[PRUEBA_3D_SESSION_KEY] = $analysis;
            $mensaje = 'Análisis completado. Revise el resumen antes de importar.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            unset($_SESSION[PRUEBA_3D_SESSION_KEY]);
            $analysis = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'importar') {
        try {
            if (!is_array($analysis) || empty($analysis['rows_to_insert'])) {
                throw new RuntimeException('No hay registros nuevos disponibles para importar.');
            }

            $resultado = p3dImportRows(
                $pdo,
                $analysis['rows_to_insert'],
                (string)($analysis['file_name'] ?? 'Prueba 3D.xlsx'),
                (int)($_SESSION['auth']['id'] ?? 0)
            );

            unset($_SESSION[PRUEBA_3D_SESSION_KEY]);
            $analysis = null;
            $mensaje = sprintf(
                'Carga finalizada. Filas insertadas: %d. Omitidas por duplicidad en BD: %d.',
                $resultado['insertados'],
                $resultado['omitidos_bd']
            );
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'limpiar') {
        unset($_SESSION[PRUEBA_3D_SESSION_KEY]);
        $analysis = null;
        $mensaje = 'Análisis descartado.';
        $mensajeTipo = 'secondary';
    }
}

function p3dEnsureTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ceo_prueba_3d (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    numero_registro INT NOT NULL,
    marca_temporal DATETIME NULL,
    rut_usuario VARCHAR(20) NOT NULL,
    fecha_registro DATETIME NULL,
    intentos_epp INT NULL,
    elementos_correctos INT NULL,
    arb01_tipo_poda VARCHAR(120) NULL,
    arb01_area_poda VARCHAR(120) NULL,
    arb01_factibilidad VARCHAR(20) NULL,
    arb01_zona_segura VARCHAR(60) NULL,
    arb01_cantidad_cortes INT NULL,
    arb01_distancia_collar_mm DECIMAL(10,2) NULL,
    arb01_angulo_collar DECIMAL(10,2) NULL,
    arb02_tipo_poda VARCHAR(120) NULL,
    arb02_area_poda VARCHAR(120) NULL,
    arb02_factibilidad VARCHAR(20) NULL,
    arb02_zona_segura VARCHAR(60) NULL,
    arb02_cantidad_cortes INT NULL,
    arb02_distancia_collar_mm DECIMAL(10,2) NULL,
    arb02_angulo_collar DECIMAL(10,2) NULL,
    arb03_tipo_poda VARCHAR(120) NULL,
    arb03_area_poda VARCHAR(120) NULL,
    arb03_factibilidad VARCHAR(20) NULL,
    puntuacion_final_1 DECIMAL(10,4) NULL,
    resultado_habilitacion VARCHAR(80) NULL,
    columna_aux_1 DECIMAL(12,6) NULL,
    intentos_epp_2 INT NULL,
    porcentaje_epp DECIMAL(10,4) NULL,
    elementos_correctos_epp INT NULL,
    porcentaje_correctos_epp DECIMAL(10,4) NULL,
    puntaje_epp DECIMAL(10,4) NULL,
    feedback_epp TEXT NULL,
    tipo_poda_resumen VARCHAR(80) NULL,
    area_poda_resumen VARCHAR(80) NULL,
    factibilidad_resumen VARCHAR(80) NULL,
    feedback_pre_poda TEXT NULL,
    zona_segura_corte_resumen VARCHAR(80) NULL,
    cantidad_orden_cortes_resumen VARCHAR(80) NULL,
    cercania_collar_resumen VARCHAR(80) NULL,
    angulo_corte_resumen VARCHAR(80) NULL,
    feedback_poda TEXT NULL,
    puntuacion_final_2 DECIMAL(10,4) NULL,
    nombre_archivo VARCHAR(255) NULL,
    fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_usuario_carga INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_prueba_3d_numero_rut (numero_registro, rut_usuario),
    KEY idx_prueba_3d_rut (rut_usuario),
    KEY idx_prueba_3d_fecha_registro (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $ready = true;
}

function p3dExpectedHeaders(): array
{
    return [
        'A' => 'N',
        'B' => 'Marca temporal',
        'C' => 'RUT Usuario',
        'D' => 'Fecha de registro',
        'E' => 'Intentos EPP',
        'F' => 'Elementos correctos',
        'G' => 'Arb01 Tipo poda',
        'H' => 'Arb01 Área poda',
        'I' => 'Arb01 Factibilidad',
        'J' => 'Arb01 Zona segura',
        'K' => 'Arb01 cantidad cortes',
        'L' => 'Arb01 Distancia al collar (mm)',
        'M' => 'Arb01 Ang. respecto al collar',
        'N' => 'Arb02 Tipo poda',
        'O' => 'Arb02 Area poda',
        'P' => 'Arb02 Factibilidad',
        'Q' => 'Arb02 Zona segura',
        'R' => 'Arb02 cantidad cortes',
        'S' => 'Arb02 Distancia al collar (mm)',
        'T' => 'Arb02 Ang. respecto al collar',
        'U' => 'Arb03 Tipo poda',
        'V' => 'Arb03 Área poda',
        'W' => 'Arb03 Factibilidad',
        'X' => 'Puntuación final',
        'Y' => 'Columna 25',
        'Z' => 'Columna 1',
        'AA' => 'Intentos EPP2',
        'AB' => '% EPP',
        'AC' => 'Elementos correctos EPP',
        'AD' => '% correctos EPP',
        'AE' => 'Puntaje EPP (%)',
        'AF' => 'Feedback EPP',
        'AG' => 'Tipo de poda',
        'AH' => 'Area de poda',
        'AI' => 'Factibilidad',
        'AJ' => 'Feedback Pre Poda',
        'AK' => 'Zona segura de corte',
        'AL' => 'Cantidad y orden de cortes',
        'AM' => 'Cercania al collar',
        'AN' => 'Angulo de corte',
        'AO' => 'Feedback Poda',
        'AP' => 'Puntuación final2',
    ];
}

function p3dAnalyzeWorkbook(PDO $pdo, string $tmpPath, string $fileName): array
{
    $loadedSheet = p3dLoadWorksheetRows($tmpPath, $fileName);
    $sheetName = $loadedSheet['sheet_name'];
    $rows = $loadedSheet['rows'];

    if (empty($rows[1])) {
        throw new RuntimeException('El archivo no contiene encabezados en la fila 1.');
    }

    p3dValidateHeaders($rows[1]);

    $vistosArchivo = [];
    $keysCandidatas = [];
    $rowsCandidatas = [];
    $rowsToInsert = [];
    $previewRows = [];
    $errores = [];
    $totalFilas = 0;
    $filasVacias = 0;
    $filasSinRut = 0;
    $filasValidas = 0;
    $duplicadasArchivo = 0;
    $existentesBd = 0;

    foreach ($rows as $rowNumber => $row) {
        if ($rowNumber === 1) {
            continue;
        }

        $totalFilas++;
        if (p3dRowIsEmpty($row)) {
            $filasVacias++;
            continue;
        }

        if (trim((string)($row['C'] ?? '')) === '') {
            $filasSinRut++;
            continue;
        }

        try {
            $parsed = p3dMapRow($row, $rowNumber);
        } catch (Throwable $e) {
            $errores[] = 'Fila ' . $rowNumber . ': ' . $e->getMessage();
            continue;
        }

        $filasValidas++;
        $key = $parsed['numero_registro'] . '|' . $parsed['rut_usuario'];
        if (isset($vistosArchivo[$key])) {
            $duplicadasArchivo++;
            continue;
        }
        $vistosArchivo[$key] = true;

        $keysCandidatas[] = [
            'numero_registro' => $parsed['numero_registro'],
            'rut_usuario' => $parsed['rut_usuario'],
        ];
        $rowsCandidatas[] = $parsed;
    }

    $existingMap = p3dFetchExistingKeys($pdo, $keysCandidatas);
    foreach ($rowsCandidatas as $parsed) {
        $key = $parsed['numero_registro'] . '|' . $parsed['rut_usuario'];
        if (isset($existingMap[$key])) {
            $existentesBd++;
            continue;
        }

        $rowsToInsert[] = $parsed;
        if (count($previewRows) < 50) {
            $previewRows[] = $parsed;
        }
    }

    return [
        'created_at' => date('Y-m-d H:i:s'),
        'file_name' => $fileName,
        'sheet_name' => $sheetName,
        'total_rows' => $totalFilas,
        'empty_rows' => $filasVacias,
        'rows_without_rut' => $filasSinRut,
        'valid_rows' => $filasValidas,
        'duplicate_rows_file' => $duplicadasArchivo,
        'existing_rows_db' => $existentesBd,
        'new_rows' => count($rowsToInsert),
        'error_rows' => count($errores),
        'errors' => $errores,
        'preview_rows' => $previewRows,
        'rows_to_insert' => $rowsToInsert,
    ];
}

function p3dLoadWorksheetRows(string $tmpPath, string $fileName): array
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension !== 'xlsx') {
        throw new RuntimeException('Solo se admite formato .xlsx para esta carga histórica.');
    }

    return p3dLoadXlsxRows($tmpPath);
}

function p3dLoadXlsxRows(string $tmpPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión ZipArchive no está disponible en PHP.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new RuntimeException('No fue posible abrir el archivo .xlsx.');
    }

    try {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('El archivo .xlsx no tiene la estructura esperada.');
        }

        $sharedStrings = p3dReadSharedStrings($zip);
        [$sheetName, $sheetPath] = p3dResolveActiveSheet($workbookXml, $relsXml);
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('No fue posible leer la hoja activa del archivo .xlsx.');
        }

        return [
            'sheet_name' => $sheetName,
            'rows' => p3dParseSheetXml($sheetXml, $sharedStrings),
        ];
    } finally {
        $zip->close();
    }
}

function p3dReadSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    foreach ($xpath->query('//a:si') as $si) {
        $parts = [];
        foreach ($xpath->query('.//a:t', $si) as $textNode) {
            $parts[] = $textNode->textContent;
        }
        $strings[] = implode('', $parts);
    }

    return $strings;
}

function p3dResolveActiveSheet(string $workbookXml, string $relsXml): array
{
    $workbook = new DOMDocument();
    $workbook->loadXML($workbookXml);
    $rels = new DOMDocument();
    $rels->loadXML($relsXml);

    $workbookXpath = new DOMXPath($workbook);
    $workbookXpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $relsXpath = new DOMXPath($rels);
    $relsXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $sheetNode = $workbookXpath->query('/a:workbook/a:sheets/a:sheet')->item(0);
    if (!$sheetNode instanceof DOMElement) {
        throw new RuntimeException('No se encontró ninguna hoja en el archivo .xlsx.');
    }

    $sheetName = (string)$sheetNode->getAttribute('name');
    $relationshipId = (string)$sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    if ($relationshipId === '') {
        throw new RuntimeException('No se pudo resolver la hoja activa del archivo .xlsx.');
    }

    $target = '';
    foreach ($relsXpath->query('/rel:Relationships/rel:Relationship') as $relNode) {
        if ($relNode instanceof DOMElement && $relNode->getAttribute('Id') === $relationshipId) {
            $target = $relNode->getAttribute('Target');
            break;
        }
    }

    if ($target === '') {
        throw new RuntimeException('No se encontró la relación de la hoja activa en el archivo .xlsx.');
    }

    $target = ltrim($target, '/');
    if (strpos($target, 'xl/') !== 0) {
        $target = 'xl/' . ltrim($target, './');
    }

    return [$sheetName, $target];
}

function p3dParseSheetXml(string $sheetXml, array $sharedStrings): array
{
    $reader = new XMLReader();
    if (!$reader->XML($sheetXml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('No fue posible leer el XML de la hoja Excel.');
    }

    $rows = [];
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
            continue;
        }

        $rowNumber = (int)$reader->getAttribute('r');
        $rowXml = $reader->readOuterXML();
        if ($rowXml === '') {
            continue;
        }

        $rowDoc = new DOMDocument();
        $rowDoc->loadXML($rowXml);
        $xpath = new DOMXPath($rowDoc);
        $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $mappedRow = array_fill_keys(p3dExpectedColumns(), null);
        foreach ($xpath->query('/a:row/a:c') as $cellNode) {
            if (!$cellNode instanceof DOMElement) {
                continue;
            }

            $reference = (string)$cellNode->getAttribute('r');
            $column = preg_replace('/\d+/', '', $reference) ?? '';
            if ($column === '' || !array_key_exists($column, $mappedRow)) {
                continue;
            }

            $mappedRow[$column] = p3dExtractCellValue($xpath, $cellNode, $sharedStrings);
        }

        $rows[$rowNumber] = $mappedRow;
    }

    $reader->close();
    ksort($rows);
    return $rows;
}

function p3dExtractCellValue(DOMXPath $xpath, DOMElement $cellNode, array $sharedStrings)
{
    $type = $cellNode->getAttribute('t');
    if ($type === 'inlineStr') {
        $parts = [];
        foreach ($xpath->query('.//a:is//a:t', $cellNode) as $textNode) {
            $parts[] = $textNode->textContent;
        }
        return implode('', $parts);
    }

    $valueNode = $xpath->query('./a:v', $cellNode)->item(0);
    if (!$valueNode) {
        return null;
    }

    $raw = $valueNode->textContent;
    if ($type === 's') {
        $index = (int)$raw;
        return $sharedStrings[$index] ?? $raw;
    }

    return $raw;
}

function p3dExpectedColumns(): array
{
    return array_keys(p3dExpectedHeaders());
}

function p3dFetchExistingKeys(PDO $pdo, array $keys): array
{
    if (empty($keys)) {
        return [];
    }

    $map = [];
    foreach (array_chunk($keys, 200) as $chunk) {
        $where = [];
        $params = [];
        foreach ($chunk as $index => $key) {
            $where[] = '(numero_registro = :numero_' . $index . ' AND rut_usuario = :rut_' . $index . ')';
            $params[':numero_' . $index] = $key['numero_registro'];
            $params[':rut_' . $index] = $key['rut_usuario'];
        }

        $sql = 'SELECT numero_registro, rut_usuario FROM ceo_prueba_3d WHERE ' . implode(' OR ', $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['numero_registro'] . '|' . $row['rut_usuario']] = true;
        }
    }

    return $map;
}

function p3dValidateHeaders(array $headerRow): void
{
    $expected = p3dExpectedHeaders();
    foreach ($expected as $column => $label) {
        $actual = trim((string)($headerRow[$column] ?? ''));
        if (p3dNormalizeHeader($actual) !== p3dNormalizeHeader($label)) {
            throw new RuntimeException(
                sprintf('Encabezado inválido en columna %s. Esperado: "%s". Recibido: "%s".', $column, $label, $actual)
            );
        }
    }
}

function p3dNormalizeHeader(string $value): string
{
    $value = trim($value);
    $map = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_strtolower($value, 'UTF-8');
}

function p3dRowIsEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') {
            return false;
        }
    }

    return true;
}

function p3dMapRow(array $row, int $rowNumber): array
{
    $numero = p3dToInt($row['A'] ?? null);
    $rut = p3dNormalizeRut((string)($row['C'] ?? ''));

    if ($numero === null || $numero <= 0) {
        throw new RuntimeException('N inválido o vacío.');
    }
    if ($rut === '') {
        throw new RuntimeException('RUT Usuario vacío.');
    }

    return [
        'fila_excel' => $rowNumber,
        'numero_registro' => $numero,
        'marca_temporal' => p3dToDateTime($row['B'] ?? null),
        'rut_usuario' => $rut,
        'fecha_registro' => p3dToDateTime($row['D'] ?? null),
        'intentos_epp' => p3dToInt($row['E'] ?? null),
        'elementos_correctos' => p3dToInt($row['F'] ?? null),
        'arb01_tipo_poda' => p3dToNullableString($row['G'] ?? null),
        'arb01_area_poda' => p3dToNullableString($row['H'] ?? null),
        'arb01_factibilidad' => p3dToNullableString($row['I'] ?? null),
        'arb01_zona_segura' => p3dToNullableString($row['J'] ?? null),
        'arb01_cantidad_cortes' => p3dToInt($row['K'] ?? null),
        'arb01_distancia_collar_mm' => p3dToDecimal($row['L'] ?? null, 2),
        'arb01_angulo_collar' => p3dToDecimal($row['M'] ?? null, 2),
        'arb02_tipo_poda' => p3dToNullableString($row['N'] ?? null),
        'arb02_area_poda' => p3dToNullableString($row['O'] ?? null),
        'arb02_factibilidad' => p3dToNullableString($row['P'] ?? null),
        'arb02_zona_segura' => p3dToNullableString($row['Q'] ?? null),
        'arb02_cantidad_cortes' => p3dToInt($row['R'] ?? null),
        'arb02_distancia_collar_mm' => p3dToDecimal($row['S'] ?? null, 2),
        'arb02_angulo_collar' => p3dToDecimal($row['T'] ?? null, 2),
        'arb03_tipo_poda' => p3dToNullableString($row['U'] ?? null),
        'arb03_area_poda' => p3dToNullableString($row['V'] ?? null),
        'arb03_factibilidad' => p3dToNullableString($row['W'] ?? null),
        'puntuacion_final_1' => p3dToDecimal($row['X'] ?? null, 4),
        'resultado_habilitacion' => p3dToNullableString($row['Y'] ?? null),
        'columna_aux_1' => p3dToDecimal($row['Z'] ?? null, 6),
        'intentos_epp_2' => p3dToInt($row['AA'] ?? null),
        'porcentaje_epp' => p3dToDecimal($row['AB'] ?? null, 4),
        'elementos_correctos_epp' => p3dToInt($row['AC'] ?? null),
        'porcentaje_correctos_epp' => p3dToDecimal($row['AD'] ?? null, 4),
        'puntaje_epp' => p3dToDecimal($row['AE'] ?? null, 4),
        'feedback_epp' => p3dToNullableString($row['AF'] ?? null),
        'tipo_poda_resumen' => p3dToNullableString($row['AG'] ?? null),
        'area_poda_resumen' => p3dToNullableString($row['AH'] ?? null),
        'factibilidad_resumen' => p3dToNullableString($row['AI'] ?? null),
        'feedback_pre_poda' => p3dToNullableString($row['AJ'] ?? null),
        'zona_segura_corte_resumen' => p3dToNullableString($row['AK'] ?? null),
        'cantidad_orden_cortes_resumen' => p3dToNullableString($row['AL'] ?? null),
        'cercania_collar_resumen' => p3dToNullableString($row['AM'] ?? null),
        'angulo_corte_resumen' => p3dToNullableString($row['AN'] ?? null),
        'feedback_poda' => p3dToNullableString($row['AO'] ?? null),
        'puntuacion_final_2' => p3dToDecimal($row['AP'] ?? null, 4),
    ];
}

function p3dNormalizeRut(string $value): string
{
    return trim($value);
}

function p3dToNullableString($value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function p3dToInt($value): ?int
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new RuntimeException('Valor numérico inválido: ' . $value);
    }

    return (int)round((float)$value);
}

function p3dToDecimal($value, int $scale): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new RuntimeException('Valor decimal inválido: ' . $value);
    }

    return number_format((float)$value, $scale, '.', '');
}

function p3dToDateTime($value): ?string
{
    if ($value === null) {
        return null;
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    if (is_numeric($text)) {
        try {
            return ExcelDate::excelToDateTimeObject((float)$text)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            throw new RuntimeException('Fecha Excel inválida: ' . $text, 0, $e);
        }
    }

    $timestamp = strtotime($text);
    if ($timestamp === false) {
        throw new RuntimeException('Fecha inválida: ' . $text);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function p3dImportRows(PDO $pdo, array $rowsToInsert, string $fileName, int $userId): array
{
    $stmtExiste = $pdo->prepare('SELECT 1 FROM ceo_prueba_3d WHERE numero_registro = :numero AND rut_usuario = :rut LIMIT 1');
    $stmtInsert = $pdo->prepare(
        'INSERT INTO ceo_prueba_3d (
            numero_registro, marca_temporal, rut_usuario, fecha_registro, intentos_epp, elementos_correctos,
            arb01_tipo_poda, arb01_area_poda, arb01_factibilidad, arb01_zona_segura, arb01_cantidad_cortes,
            arb01_distancia_collar_mm, arb01_angulo_collar, arb02_tipo_poda, arb02_area_poda, arb02_factibilidad,
            arb02_zona_segura, arb02_cantidad_cortes, arb02_distancia_collar_mm, arb02_angulo_collar,
            arb03_tipo_poda, arb03_area_poda, arb03_factibilidad, puntuacion_final_1, resultado_habilitacion,
            columna_aux_1, intentos_epp_2, porcentaje_epp, elementos_correctos_epp, porcentaje_correctos_epp,
            puntaje_epp, feedback_epp, tipo_poda_resumen, area_poda_resumen, factibilidad_resumen,
            feedback_pre_poda, zona_segura_corte_resumen, cantidad_orden_cortes_resumen, cercania_collar_resumen,
            angulo_corte_resumen, feedback_poda, puntuacion_final_2, nombre_archivo, id_usuario_carga
        ) VALUES (
            :numero_registro, :marca_temporal, :rut_usuario, :fecha_registro, :intentos_epp, :elementos_correctos,
            :arb01_tipo_poda, :arb01_area_poda, :arb01_factibilidad, :arb01_zona_segura, :arb01_cantidad_cortes,
            :arb01_distancia_collar_mm, :arb01_angulo_collar, :arb02_tipo_poda, :arb02_area_poda, :arb02_factibilidad,
            :arb02_zona_segura, :arb02_cantidad_cortes, :arb02_distancia_collar_mm, :arb02_angulo_collar,
            :arb03_tipo_poda, :arb03_area_poda, :arb03_factibilidad, :puntuacion_final_1, :resultado_habilitacion,
            :columna_aux_1, :intentos_epp_2, :porcentaje_epp, :elementos_correctos_epp, :porcentaje_correctos_epp,
            :puntaje_epp, :feedback_epp, :tipo_poda_resumen, :area_poda_resumen, :factibilidad_resumen,
            :feedback_pre_poda, :zona_segura_corte_resumen, :cantidad_orden_cortes_resumen, :cercania_collar_resumen,
            :angulo_corte_resumen, :feedback_poda, :puntuacion_final_2, :nombre_archivo, :id_usuario_carga
        )'
    );

    $insertados = 0;
    $omitidosBd = 0;

    $pdo->beginTransaction();
    try {
        foreach ($rowsToInsert as $row) {
            $stmtExiste->execute([
                ':numero' => $row['numero_registro'],
                ':rut' => $row['rut_usuario'],
            ]);
            if ($stmtExiste->fetchColumn()) {
                $omitidosBd++;
                continue;
            }

            $params = $row;
            unset($params['fila_excel']);
            $params['nombre_archivo'] = $fileName;
            $params['id_usuario_carga'] = $userId > 0 ? $userId : null;

            try {
                $stmtInsert->execute($params);
                $insertados++;
            } catch (PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23000') {
                    $omitidosBd++;
                    continue;
                }

                throw $e;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'insertados' => $insertados,
        'omitidos_bd' => $omitidosBd,
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Carga Prueba 3D | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid #e3e6ea;}
    .summary-box{background:#fff;border-radius:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05);}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= esc(APP_LOGO) ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= esc(APP_NAME) ?></div>
        <small class="text-muted"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="<?= esc(app_url('/public/prueba_3d_consulta.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h4 class="text-primary mb-2"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Histórica Prueba 3D</h4>
      <p class="text-muted mb-0">Importa antecedentes de <strong>Prueba 3D</strong> desde una sola hoja Excel. La carga es histórica: si la clave <strong>N + RUT Usuario</strong> ya existe, el registro se omite.</p>
    </div>
  </div>

  <?php if ($mensaje !== ''): ?>
    <div class="alert alert-<?= esc($mensajeTipo) ?>"><?= esc($mensaje) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="accion" value="analizar">
        <div class="col-md-7">
          <label class="form-label fw-semibold">Archivo Excel</label>
          <input type="file" name="excel" class="form-control" accept=".xlsx" required>
        </div>
        <div class="col-md-5 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Analizar</button>
        </div>
      </form>

      <?php if (is_array($analysis)): ?>
        <form method="post" class="mt-3 d-inline">
          <input type="hidden" name="accion" value="importar">
          <button class="btn btn-success" type="submit" <?= (int)($analysis['new_rows'] ?? 0) === 0 ? 'disabled' : '' ?>><i class="bi bi-upload me-1"></i>Importar registros nuevos</button>
        </form>
        <form method="post" class="mt-3 d-inline ms-2">
          <input type="hidden" name="accion" value="limpiar">
          <button class="btn btn-outline-secondary" type="submit">Limpiar análisis</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (is_array($analysis)): ?>
    <div class="summary-box p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
          <h5 class="mb-1">Resumen del análisis</h5>
          <div class="text-muted small">Generado el <?= esc((string)$analysis['created_at']) ?></div>
          <div class="text-muted small">Archivo: <strong><?= esc((string)$analysis['file_name']) ?></strong> | Hoja: <strong><?= esc((string)$analysis['sheet_name']) ?></strong></div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Filas leídas</div><div class="fs-4 fw-bold"><?= (int)$analysis['total_rows'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Filas válidas</div><div class="fs-4 fw-bold"><?= (int)$analysis['valid_rows'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Duplicadas en archivo</div><div class="fs-4 fw-bold"><?= (int)$analysis['duplicate_rows_file'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Ya existentes en BD</div><div class="fs-4 fw-bold"><?= (int)$analysis['existing_rows_db'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Filas vacías</div><div class="fs-4 fw-bold"><?= (int)$analysis['empty_rows'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Sin RUT Usuario</div><div class="fs-4 fw-bold"><?= (int)($analysis['rows_without_rut'] ?? 0) ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Errores</div><div class="fs-4 fw-bold"><?= (int)$analysis['error_rows'] ?></div></div></div>
        <div class="col-md-6"><div class="border rounded p-3 bg-light"><div class="small text-muted">Registros nuevos a insertar</div><div class="fs-4 fw-bold text-success"><?= (int)$analysis['new_rows'] ?></div></div></div>
      </div>

      <?php if (!empty($analysis['errors'])): ?>
        <div class="alert alert-warning">
          <div class="fw-semibold mb-2">Filas con observaciones</div>
          <ul class="mb-0">
            <?php foreach (array_slice($analysis['errors'], 0, 20) as $error): ?>
              <li><?= esc((string)$error) ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($analysis['errors']) > 20): ?>
            <div class="small mt-2">Se muestran 20 observaciones de <?= count($analysis['errors']) ?>.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Fila</th>
              <th>N</th>
              <th>RUT Usuario</th>
              <th>Marca temporal</th>
              <th>Fecha registro</th>
              <th>Resultado</th>
              <th>Puntaje final 2</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($analysis['preview_rows'])): ?>
              <tr><td colspan="7" class="text-center text-muted">No hay registros nuevos para mostrar.</td></tr>
            <?php else: ?>
              <?php foreach ($analysis['preview_rows'] as $row): ?>
                <tr>
                  <td><?= (int)($row['fila_excel'] ?? 0) ?></td>
                  <td><?= (int)($row['numero_registro'] ?? 0) ?></td>
                  <td><?= esc((string)($row['rut_usuario'] ?? '')) ?></td>
                  <td><?= esc((string)($row['marca_temporal'] ?? '')) ?></td>
                  <td><?= esc((string)($row['fecha_registro'] ?? '')) ?></td>
                  <td><?= esc((string)($row['resultado_habilitacion'] ?? '')) ?></td>
                  <td><?= esc((string)($row['puntuacion_final_2'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
