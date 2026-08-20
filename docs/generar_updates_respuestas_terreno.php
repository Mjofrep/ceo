<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$excelPath = $root . '/docs/Respuestas Terreno1.xlsx';
$outputPath = $root . '/docs/update_respuestas_terreno_historicas.sql';

if (!is_file($excelPath)) {
    fwrite(STDERR, "No se encontró el Excel: {$excelPath}\n");
    exit(1);
}

$rows = loadFirstSheetRows($excelPath);
if ($rows === []) {
    fwrite(STDERR, "El Excel no contiene datos.\n");
    exit(1);
}

$headerMap = buildHeaderMap($rows[0]['cells'] ?? []);
$resolved = resolveRequiredHeaders($headerMap, [
    'IDRESULTADO' => ['IDRESULTADO'],
    'IDPREGUNTA' => ['IDPREGUNTA'],
    'IDSECCION' => ['IDSECCION'],
    'RUTCONTRATISTA' => ['RUTCONTRATISTA'],
    'FECHA' => ['FECHA'],
    'FECHAREAL' => ['FECHAREAL'],
    'CUMPLEREAL' => ['CUMPLEREAL'],
    'NOCUMPLEREAL' => ['NOCUMPLEREAL'],
    'NOAPLICAREAL' => ['NOAPLICAREAL'],
]);

$updates = [];
$conflicts = [];

foreach (array_slice($rows, 1) as $row) {
    $parsed = parseExcelRow($row['cells'] ?? [], $resolved, (int)($row['row_num'] ?? 0));
    if (($parsed['status'] ?? '') !== 'ok') {
        $conflicts[] = $parsed;
        continue;
    }

    $where = sprintf(
        "id_resultado = %d AND id_pregunta = %d AND id_seccion = %d AND rut_contratista = '%s' AND DATE(fecha) = '%s'",
        (int)$parsed['id_resultado'],
        (int)$parsed['id_pregunta'],
        (int)$parsed['id_seccion'],
        sqlString($parsed['rut_contratista']),
        sqlString($parsed['fecha_antigua'])
    );

    if (($parsed['action'] ?? '') === 'date_only') {
        $updates[] = sprintf(
            "-- Fila Excel %d | solo fecha\nUPDATE ceo_resultado_prueba_terreno\nSET fecha = '%s'\nWHERE %s\nLIMIT 1;\n",
            (int)$parsed['excel_row'],
            sqlString($parsed['fecha_real']),
            $where
        );
        continue;
    }

    $updates[] = sprintf(
        "-- Fila Excel %d | actualizacion completa\nUPDATE ceo_resultado_prueba_terreno\nSET fecha = '%s',\n    cumple = %s,\n    no_cumple = %s,\n    no_aplica = %s\nWHERE %s\nLIMIT 1;\n",
        (int)$parsed['excel_row'],
        sqlString($parsed['fecha_real']),
        sqlNullableInt($parsed['cumple_real']),
        sqlNullableInt($parsed['no_cumple_real']),
        sqlNullableInt($parsed['no_aplica_real']),
        $where
    );
}

$header = [];
$header[] = '-- Generado automáticamente desde docs/Respuestas Terreno1.xlsx';
$header[] = '-- Reglas aplicadas:';
$header[] = '-- 1. Match por id_resultado, id_pregunta, id_seccion, rut_contratista y fecha antigua del Excel.';
$header[] = '-- 2. Si cumple_real, no_cumple_real y no_aplica_real están vacíos: solo se actualiza fecha con Fecha Real.';
$header[] = '-- 3. Si vienen informados: se actualiza fecha y flags.';
$header[] = '-- 4. Cada UPDATE usa LIMIT 1.';
$header[] = '--';
$header[] = '-- Total filas Excel procesadas: ' . max(count($rows) - 1, 0);
$header[] = '-- Updates generados: ' . count($updates);
$header[] = '-- Conflictos omitidos por validación de Excel: ' . count($conflicts);
$header[] = '';
$header[] = 'START TRANSACTION;';
$header[] = '';

$footer = [];
$footer[] = 'COMMIT;';

if ($conflicts !== []) {
    $footer[] = '';
    $footer[] = '-- Filas omitidas por conflicto de Excel';
    foreach ($conflicts as $conflict) {
        $footer[] = sprintf('-- Fila %d: %s', (int)($conflict['excel_row'] ?? 0), (string)($conflict['reason'] ?? 'Conflicto'));
    }
}

$sql = implode("\n", $header) . implode("\n", $updates) . "\n" . implode("\n", $footer) . "\n";

if (file_put_contents($outputPath, $sql) === false) {
    fwrite(STDERR, "No fue posible escribir el archivo SQL: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'output' => $outputPath,
    'updates' => count($updates),
    'conflicts' => count($conflicts),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

function parseExcelRow(array $cells, array $headers, int $excelRow): array
{
    $idResultado = parsePositiveInt(cellByHeader($cells, $headers, 'IDRESULTADO'));
    $idPregunta = parsePositiveInt(cellByHeader($cells, $headers, 'IDPREGUNTA'));
    $idSeccion = parsePositiveInt(cellByHeader($cells, $headers, 'IDSECCION'));
    $rut = trim((string)cellByHeader($cells, $headers, 'RUTCONTRATISTA'));
    $fechaAntigua = parseDateValue(cellByHeader($cells, $headers, 'FECHA'));
    $fechaReal = parseDateValue(cellByHeader($cells, $headers, 'FECHAREAL'));
    $cumple = parseFlag(cellByHeader($cells, $headers, 'CUMPLEREAL'));
    $noCumple = parseFlag(cellByHeader($cells, $headers, 'NOCUMPLEREAL'));
    $noAplica = parseFlag(cellByHeader($cells, $headers, 'NOAPLICAREAL'));

    $row = [
        'excel_row' => $excelRow,
        'id_resultado' => $idResultado,
        'id_pregunta' => $idPregunta,
        'id_seccion' => $idSeccion,
        'rut_contratista' => $rut,
        'fecha_antigua' => $fechaAntigua,
        'fecha_real' => $fechaReal,
        'cumple_real' => $cumple['value'],
        'no_cumple_real' => $noCumple['value'],
        'no_aplica_real' => $noAplica['value'],
        'status' => 'ok',
        'reason' => '',
        'action' => '',
    ];

    if ($idResultado === null || $idPregunta === null || $idSeccion === null || $rut === '') {
        $row['status'] = 'conflict';
        $row['reason'] = 'Llave inválida.';
        return $row;
    }
    if ($fechaAntigua === null) {
        $row['status'] = 'conflict';
        $row['reason'] = 'Fecha antigua inválida.';
        return $row;
    }
    if ($fechaReal === null) {
        $row['status'] = 'conflict';
        $row['reason'] = 'Fecha Real inválida.';
        return $row;
    }
    if (!$cumple['valid'] || !$noCumple['valid'] || !$noAplica['valid']) {
        $row['status'] = 'conflict';
        $row['reason'] = 'Flags inválidos.';
        return $row;
    }

    $ones = 0;
    foreach ([$cumple['value'], $noCumple['value'], $noAplica['value']] as $flagValue) {
        if ($flagValue === 1) {
            $ones++;
        }
    }
    if ($ones > 1) {
        $row['status'] = 'conflict';
        $row['reason'] = 'Más de un flag en 1.';
        return $row;
    }

    $row['action'] = ($cumple['value'] === null && $noCumple['value'] === null && $noAplica['value'] === null) ? 'date_only' : 'full';
    return $row;
}

function sqlString(string $value): string
{
    return str_replace("'", "''", $value);
}

function sqlNullableInt(?int $value): string
{
    return $value === null ? 'NULL' : (string)$value;
}

function parsePositiveInt(mixed $value): ?int
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^-?\d+(?:\.0+)?$/', $text) !== 1) {
        return null;
    }
    $intValue = (int)round((float)$text);
    return $intValue > 0 ? $intValue : null;
}

function parseFlag(mixed $value): array
{
    $text = trim((string)$value);
    if ($text === '') {
        return ['valid' => true, 'value' => null];
    }
    $normalized = str_replace(',', '.', $text);
    if ($normalized === '0' || $normalized === '0.0') {
        return ['valid' => true, 'value' => 0];
    }
    if ($normalized === '1' || $normalized === '1.0') {
        return ['valid' => true, 'value' => 1];
    }
    return ['valid' => false, 'value' => null];
}

function parseDateValue(mixed $value): ?string
{
    $dt = parseExcelDateTimeValue($value);
    return $dt ? $dt->format('Y-m-d') : null;
}

function parseExcelDateTimeValue(mixed $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }

    $numericText = str_replace(',', '.', trim((string)$value));
    if ($numericText !== '' && is_numeric($numericText)) {
        try {
            $base = new DateTimeImmutable('1899-12-30 00:00:00');
            $seconds = (int)round(((float)$numericText) * 86400);
            return $base->modify('+' . $seconds . ' seconds');
        } catch (Throwable $e) {
            return null;
        }
    }

    $text = preg_replace('/\s+/', ' ', trim((string)$value)) ?? trim((string)$value);
    $formats = ['d/m/Y H:i:s','j/n/Y H:i:s','d/m/Y H:i','j/n/Y H:i','d-m-Y H:i:s','j-n-Y H:i:s','d-m-Y H:i','j-n-Y H:i','Y-m-d H:i:s','Y-m-d H:i','d/m/Y','j/n/Y','d-m-Y','j-n-Y','Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $text);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0);
            if (!$hasErrors) {
                return strpos($format, 'H:i') === false ? $dt->setTime(0, 0, 0) : $dt;
            }
        }
    }
    return null;
}

function loadFirstSheetRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No fue posible abrir el archivo Excel.');
    }

    try {
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedDoc = new DOMDocument();
            $sharedDoc->loadXML($sharedXml);
            foreach ($sharedDoc->getElementsByTagName('si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagName('t') as $t) {
                    $text .= $t->textContent;
                }
                $sharedStrings[] = $text;
            }
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('El archivo Excel es inválido.');
        }

        $workbookDoc = new DOMDocument();
        $workbookDoc->loadXML($workbookXml);
        $relsDoc = new DOMDocument();
        $relsDoc->loadXML($relsXml);

        $relMap = [];
        foreach ($relsDoc->getElementsByTagName('Relationship') as $rel) {
            $relMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }

        $sheetNode = $workbookDoc->getElementsByTagName('sheet')->item(0);
        if (!$sheetNode instanceof DOMElement) {
            throw new RuntimeException('El archivo Excel no contiene hojas.');
        }

        $sheetRelId = $sheetNode->getAttribute('r:id');
        $sheetPath = $relMap[$sheetRelId] ?? '';
        if ($sheetPath === '') {
            throw new RuntimeException('No fue posible ubicar la hoja del Excel.');
        }
        if (!str_starts_with($sheetPath, 'xl/')) {
            $sheetPath = 'xl/' . ltrim($sheetPath, '/');
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('No fue posible leer la hoja del Excel.');
        }

        $sheetDoc = new DOMDocument();
        $sheetDoc->loadXML($sheetXml);
        $rows = [];

        foreach ($sheetDoc->getElementsByTagName('row') as $rowNode) {
            $cells = [];
            foreach ($rowNode->getElementsByTagName('c') as $cellNode) {
                $ref = $cellNode->getAttribute('r');
                $columnLetters = preg_replace('/\d+/', '', $ref) ?? '';
                $columnIndex = excelColumnToIndex($columnLetters);
                if ($columnIndex < 0) {
                    continue;
                }

                $type = $cellNode->getAttribute('t');
                $value = '';
                if ($type === 'inlineStr') {
                    foreach ($cellNode->getElementsByTagName('t') as $textNode) {
                        $value .= $textNode->textContent;
                    }
                } else {
                    $valueNode = $cellNode->getElementsByTagName('v')->item(0);
                    if ($valueNode instanceof DOMElement) {
                        $value = $valueNode->textContent;
                    }
                    if ($type === 's' && $value !== '') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    }
                }
                $cells[$columnIndex] = $value;
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $rows[] = [
                'row_num' => (int)$rowNode->getAttribute('r'),
                'cells' => $cells,
            ];
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function excelColumnToIndex(string $letters): int
{
    $letters = strtoupper(trim($letters));
    if ($letters === '') {
        return -1;
    }
    $index = 0;
    $length = strlen($letters);
    for ($i = 0; $i < $length; $i++) {
        $char = ord($letters[$i]);
        if ($char < 65 || $char > 90) {
            return -1;
        }
        $index = ($index * 26) + ($char - 64);
    }
    return $index - 1;
}

function buildHeaderMap(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $index => $value) {
        $key = normalizeHeaderKey((string)$value);
        if ($key !== '') {
            $map[$key] = $index;
        }
    }
    return $map;
}

function normalizeHeaderKey(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = strtr($value, [
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
    ]);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtoupper($value);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? $value;
}

function resolveRequiredHeaders(array $headerMap, array $required): array
{
    $resolved = [];
    foreach ($required as $canonical => $aliases) {
        $found = null;
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $headerMap)) {
                $found = $headerMap[$alias];
                break;
            }
        }
        if ($found === null) {
            throw new RuntimeException('El archivo no contiene la columna requerida: ' . $canonical . '.');
        }
        $resolved[$canonical] = $found;
    }
    return $resolved;
}

function cellByHeader(array $row, array $headerMap, string $header): mixed
{
    $idx = $headerMap[$header] ?? null;
    return $idx === null ? null : ($row[$idx] ?? null);
}
