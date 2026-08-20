<?php
declare(strict_types=1);

ini_set('memory_limit', '768M');
ini_set('max_execution_time', '0');
@set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

const TERRENO_UPDATE_SESSION_KEY = 'terreno_update_historico_preview';
const TERRENO_UPDATE_SAMPLE_LIMIT = 60;
const TERRENO_UPDATE_CHUNK_SIZE = 800;
const TERRENO_UPDATE_PAYLOAD_VERSION = 2;

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$pdo = db();
$mensaje = '';
$mensajeTipo = 'info';
$resultadoAplicacion = null;
$analisis = terrenoUpdateLoadSessionState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'procesar_segmento') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!is_array($analisis) || ($analisis['status'] ?? '') !== 'processing') {
                throw new RuntimeException('No hay un análisis pendiente para continuar.');
            }
            $analisis = terrenoUpdateProcessChunk($pdo, $analisis, TERRENO_UPDATE_CHUNK_SIZE);
            terrenoUpdateSaveSessionState($analisis);
            echo json_encode([
                'ok' => true,
                'done' => ($analisis['status'] ?? '') === 'ready',
                'processed_rows' => (int)($analisis['processed_rows'] ?? 0),
                'total_rows' => (int)($analisis['total_rows'] ?? 0),
                'percent' => terrenoUpdatePercent((int)($analisis['processed_rows'] ?? 0), (int)($analisis['total_rows'] ?? 0)),
                'summary' => $analisis['summary'] ?? [],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($accion === 'analizar') {
        try {
            terrenoUpdateClearCurrentState($analisis);
            $analisis = terrenoUpdateInitAnalysisFromUpload($_FILES['excel'] ?? null);
            terrenoUpdateSaveSessionState($analisis);
            $mensaje = 'Archivo cargado. El análisis se procesará por segmentos.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            terrenoUpdateClearCurrentState($analisis);
            $analisis = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'aplicar') {
        try {
            if (!is_array($analisis) || ($analisis['status'] ?? '') !== 'ready') {
                throw new RuntimeException('No hay un preview listo para aplicar.');
            }
            $resultadoAplicacion = terrenoUpdateApplyChanges($pdo, $analisis);
            terrenoUpdateClearCurrentState($analisis);
            $analisis = null;
            $mensaje = sprintf(
                'Actualización finalizada. Completas: %d. Solo fecha: %d. Omitidas por cambios de estado: %d.',
                (int)$resultadoAplicacion['updated_full'],
                (int)$resultadoAplicacion['updated_date_only'],
                (int)$resultadoAplicacion['skipped_state_changed']
            );
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'limpiar') {
        terrenoUpdateClearCurrentState($analisis);
        $analisis = null;
        $mensaje = 'Preview descartado.';
        $mensajeTipo = 'secondary';
    }
}

function terrenoUpdateInitAnalysisFromUpload(?array $file): array
{
    if (!is_array($file) || empty($file['tmp_name'])) {
        throw new RuntimeException('Debe seleccionar un archivo Excel .xlsx.');
    }

    $originalName = (string)($file['name'] ?? '');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('El archivo debe estar en formato .xlsx.');
    }

    $jobDir = terrenoUpdateCreateJobDir();
    $xlsxPath = $jobDir . '/source.xlsx';
    if (!move_uploaded_file((string)$file['tmp_name'], $xlsxPath)) {
        throw new RuntimeException('No fue posible guardar temporalmente el archivo Excel.');
    }

    $extractDir = $jobDir . '/xlsx';
    if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
        throw new RuntimeException('No fue posible preparar el directorio temporal del análisis.');
    }

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException('No fue posible abrir el archivo Excel.');
    }
    try {
        if (!$zip->extractTo($extractDir)) {
            throw new RuntimeException('No fue posible extraer el archivo Excel.');
        }
    } finally {
        $zip->close();
    }

    $sheetRelativePath = terrenoUpdateResolveFirstSheetRelativePath($extractDir);
    $sheetPath = $extractDir . '/' . $sheetRelativePath;
    if (!is_file($sheetPath)) {
        throw new RuntimeException('No fue posible localizar la primera hoja del Excel.');
    }

    $sharedStrings = terrenoUpdateLoadSharedStrings($extractDir . '/xl/sharedStrings.xml');
    file_put_contents($jobDir . '/shared_strings.bin', serialize($sharedStrings));

    [$headerMap, $totalRows] = terrenoUpdateInspectSheet($sheetPath, $sharedStrings);
    $requiredHeaders = [
        'IDRESULTADO' => ['IDRESULTADO'],
        'IDPREGUNTA' => ['IDPREGUNTA'],
        'IDSECCION' => ['IDSECCION'],
        'RUTCONTRATISTA' => ['RUTCONTRATISTA'],
        'FECHA' => ['FECHA'],
        'FECHAREAL' => ['FECHAREAL'],
        'CUMPLEREAL' => ['CUMPLEREAL'],
        'NOCUMPLEREAL' => ['NOCUMPLEREAL'],
        'NOAPLICAREAL' => ['NOAPLICAREAL'],
    ];
    $resolvedHeaders = terrenoUpdateResolveRequiredHeaders($headerMap, $requiredHeaders);

    return [
        'version' => TERRENO_UPDATE_PAYLOAD_VERSION,
        'status' => 'processing',
        'created_at' => date('Y-m-d H:i:s'),
        'source_name' => $originalName,
        'job_dir' => $jobDir,
        'sheet_path' => $sheetPath,
        'shared_strings_path' => $jobDir . '/shared_strings.bin',
        'processed_rows' => 0,
        'total_rows' => $totalRows,
        'header_map' => $resolvedHeaders,
        'required_headers' => array_keys($requiredHeaders),
        'rows' => [],
        'excel_key_map' => [],
        'summary' => terrenoUpdateEmptySummary($totalRows),
        'samples' => terrenoUpdateEmptySamples(),
    ];
}

function terrenoUpdateProcessChunk(PDO $pdo, array $state, int $chunkSize): array
{
    $sharedStrings = terrenoUpdateReadSerialized((string)$state['shared_strings_path']);
    if (!is_array($sharedStrings)) {
        throw new RuntimeException('No fue posible recuperar las cadenas del Excel.');
    }

    $chunkRows = terrenoUpdateReadSheetChunk(
        (string)$state['sheet_path'],
        $sharedStrings,
        (int)$state['processed_rows'],
        $chunkSize
    );

    if ($chunkRows === []) {
        $state['status'] = 'ready';
        return $state;
    }

    $pendingIndexes = [];
    $idResultados = [];

    foreach ($chunkRows as $chunkRow) {
        $parsed = terrenoUpdateParseExcelRow(
            $chunkRow['cells'],
            $state['header_map'],
            (int)$chunkRow['row_num']
        );
        $rowIndex = count($state['rows']);
        $state['rows'][] = $parsed;

        if ($parsed['status'] !== 'pending') {
            continue;
        }

        $excelKey = terrenoUpdateBuildMatchKey(
            (int)$parsed['id_resultado'],
            (int)$parsed['id_pregunta'],
            (int)$parsed['id_seccion'],
            (string)$parsed['rut_contratista'],
            (string)$parsed['fecha_antigua']
        );

        if (isset($state['excel_key_map'][$excelKey])) {
            $state['rows'][$rowIndex]['status'] = 'conflict';
            $state['rows'][$rowIndex]['status_label'] = 'Conflicto';
            $state['rows'][$rowIndex]['reason'] = 'La llave de match se repite en el Excel.';

            $previousIndex = (int)$state['excel_key_map'][$excelKey];
            $state['rows'][$previousIndex]['status'] = 'conflict';
            $state['rows'][$previousIndex]['status_label'] = 'Conflicto';
            $state['rows'][$previousIndex]['reason'] = 'La llave de match se repite en el Excel.';
            continue;
        }

        $state['excel_key_map'][$excelKey] = $rowIndex;
        $pendingIndexes[] = $rowIndex;
        $idResultados[(int)$parsed['id_resultado']] = true;
    }

    $dbRowsByKey = terrenoUpdateLoadDbRowsByMatchKey($pdo, array_keys($idResultados));
    foreach ($pendingIndexes as $rowIndex) {
        $row = $state['rows'][$rowIndex];
        if (($row['status'] ?? '') !== 'pending') {
            continue;
        }

        $key = terrenoUpdateBuildMatchKey(
            (int)$row['id_resultado'],
            (int)$row['id_pregunta'],
            (int)$row['id_seccion'],
            (string)$row['rut_contratista'],
            (string)$row['fecha_antigua']
        );
        $matches = $dbRowsByKey[$key] ?? [];
        if (count($matches) === 0) {
            $state['rows'][$rowIndex]['status'] = 'no_match';
            $state['rows'][$rowIndex]['status_label'] = 'Sin match';
            $state['rows'][$rowIndex]['reason'] = 'No existe un registro en BD con la llave definida.';
        } elseif (count($matches) > 1) {
            $state['rows'][$rowIndex]['status'] = 'conflict';
            $state['rows'][$rowIndex]['status_label'] = 'Conflicto';
            $state['rows'][$rowIndex]['reason'] = 'La llave definida devuelve más de un registro en BD.';
        } else {
            $state['rows'][$rowIndex]['db_before'] = $matches[0];
            $state['rows'][$rowIndex]['status'] = $row['action'] === 'date_only' ? 'ready_date_only' : 'ready_full';
            $state['rows'][$rowIndex]['status_label'] = $row['action'] === 'date_only' ? 'Solo fecha' : 'Actualización completa';
            $state['rows'][$rowIndex]['reason'] = $row['action'] === 'date_only'
                ? 'Los flags reales vienen vacíos; solo se actualizará la fecha.'
                : 'La fila tiene match único y actualizará fecha y flags.';
        }
    }

    $state['processed_rows'] = min((int)$state['processed_rows'] + count($chunkRows), (int)$state['total_rows']);
    $state['summary'] = terrenoUpdateSummarizeRows($state['rows'], (int)$state['total_rows']);
    $state['samples'] = terrenoUpdateBuildSamples($state['rows']);
    if ((int)$state['processed_rows'] >= (int)$state['total_rows']) {
        $state['status'] = 'ready';
    }

    return $state;
}

function terrenoUpdateApplyChanges(PDO $pdo, array $analysis): array
{
    $rows = array_values(array_filter(
        $analysis['rows'] ?? [],
        static fn(array $row): bool => in_array((string)($row['status'] ?? ''), ['ready_full', 'ready_date_only'], true)
    ));

    $result = [
        'updated_full' => 0,
        'updated_date_only' => 0,
        'skipped_state_changed' => 0,
        'skipped_total' => 0,
    ];

    if ($rows === []) {
        return $result;
    }

    $stmtMatch = $pdo->prepare('SELECT COUNT(*) FROM ceo_resultado_prueba_terreno WHERE id_resultado = :id_resultado AND id_pregunta = :id_pregunta AND id_seccion = :id_seccion AND rut_contratista = :rut_contratista AND DATE(fecha) = :fecha_antigua');
    $stmtUpdateDate = $pdo->prepare('UPDATE ceo_resultado_prueba_terreno SET fecha = :fecha_real WHERE id_resultado = :id_resultado AND id_pregunta = :id_pregunta AND id_seccion = :id_seccion AND rut_contratista = :rut_contratista AND DATE(fecha) = :fecha_antigua LIMIT 1');
    $stmtUpdateFull = $pdo->prepare('UPDATE ceo_resultado_prueba_terreno SET fecha = :fecha_real, cumple = :cumple, no_cumple = :no_cumple, no_aplica = :no_aplica WHERE id_resultado = :id_resultado AND id_pregunta = :id_pregunta AND id_seccion = :id_seccion AND rut_contratista = :rut_contratista AND DATE(fecha) = :fecha_antigua LIMIT 1');

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $params = [
                ':id_resultado' => (int)$row['id_resultado'],
                ':id_pregunta' => (int)$row['id_pregunta'],
                ':id_seccion' => (int)$row['id_seccion'],
                ':rut_contratista' => (string)$row['rut_contratista'],
                ':fecha_antigua' => (string)$row['fecha_antigua'],
            ];

            $stmtMatch->execute($params);
            if ((int)$stmtMatch->fetchColumn() !== 1) {
                $result['skipped_state_changed']++;
                $result['skipped_total']++;
                continue;
            }

            if (($row['status'] ?? '') === 'ready_date_only') {
                $stmtUpdateDate->execute($params + [':fecha_real' => (string)$row['fecha_real']]);
                $result['updated_date_only']++;
            } else {
                $stmtUpdateFull->execute($params + [
                    ':fecha_real' => (string)$row['fecha_real'],
                    ':cumple' => $row['cumple_real'],
                    ':no_cumple' => $row['no_cumple_real'],
                    ':no_aplica' => $row['no_aplica_real'],
                ]);
                $result['updated_full']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

function terrenoUpdateLoadDbRowsByMatchKey(PDO $pdo, array $idResultados): array
{
    if ($idResultados === []) {
        return [];
    }

    $map = [];
    foreach (array_chunk(array_map('intval', $idResultados), 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare('SELECT id_resultado, id_pregunta, id_seccion, rut_contratista, DATE(fecha) AS fecha, cumple, no_cumple, no_aplica FROM ceo_resultado_prueba_terreno WHERE id_resultado IN (' . $placeholders . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dbRow) {
            $key = terrenoUpdateBuildMatchKey((int)$dbRow['id_resultado'], (int)$dbRow['id_pregunta'], (int)$dbRow['id_seccion'], (string)$dbRow['rut_contratista'], (string)$dbRow['fecha']);
            $map[$key][] = [
                'cumple' => $dbRow['cumple'],
                'no_cumple' => $dbRow['no_cumple'],
                'no_aplica' => $dbRow['no_aplica'],
                'fecha' => $dbRow['fecha'],
            ];
        }
    }
    return $map;
}

function terrenoUpdateParseExcelRow(array $cells, array $headers, int $excelRow): array
{
    $idResultado = terrenoUpdateParsePositiveInt(terrenoUpdateCellByHeader($cells, $headers, 'IDRESULTADO'));
    $idPregunta = terrenoUpdateParsePositiveInt(terrenoUpdateCellByHeader($cells, $headers, 'IDPREGUNTA'));
    $idSeccion = terrenoUpdateParsePositiveInt(terrenoUpdateCellByHeader($cells, $headers, 'IDSECCION'));
    $rut = trim((string)terrenoUpdateCellByHeader($cells, $headers, 'RUTCONTRATISTA'));
    $fechaAntigua = terrenoUpdateParseDateValue(terrenoUpdateCellByHeader($cells, $headers, 'FECHA'));
    $fechaReal = terrenoUpdateParseDateValue(terrenoUpdateCellByHeader($cells, $headers, 'FECHAREAL'));
    $cumple = terrenoUpdateParseFlag(terrenoUpdateCellByHeader($cells, $headers, 'CUMPLEREAL'));
    $noCumple = terrenoUpdateParseFlag(terrenoUpdateCellByHeader($cells, $headers, 'NOCUMPLEREAL'));
    $noAplica = terrenoUpdateParseFlag(terrenoUpdateCellByHeader($cells, $headers, 'NOAPLICAREAL'));

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
        'status' => 'pending',
        'status_label' => 'Pendiente',
        'reason' => '',
        'action' => '',
        'db_before' => null,
    ];

    if ($idResultado === null || $idPregunta === null || $idSeccion === null || $rut === '') {
        $row['status'] = 'conflict';
        $row['status_label'] = 'Conflicto';
        $row['reason'] = 'La fila no tiene una llave válida para hacer match.';
        return $row;
    }
    if ($fechaAntigua === null) {
        $row['status'] = 'conflict';
        $row['status_label'] = 'Conflicto';
        $row['reason'] = 'La fecha antigua no es válida.';
        return $row;
    }
    if ($fechaReal === null) {
        $row['status'] = 'conflict';
        $row['status_label'] = 'Conflicto';
        $row['reason'] = 'La Fecha Real no es válida.';
        return $row;
    }
    if (!$cumple['valid'] || !$noCumple['valid'] || !$noAplica['valid']) {
        $row['status'] = 'conflict';
        $row['status_label'] = 'Conflicto';
        $row['reason'] = 'Los flags reales deben venir como 0, 1 o vacío.';
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
        $row['status_label'] = 'Conflicto';
        $row['reason'] = 'Más de un flag real viene marcado con 1.';
        return $row;
    }

    $row['action'] = ($cumple['value'] === null && $noCumple['value'] === null && $noAplica['value'] === null) ? 'date_only' : 'full';
    return $row;
}

function terrenoUpdateInspectSheet(string $sheetPath, array $sharedStrings): array
{
    $reader = new XMLReader();
    if (!$reader->open($sheetPath)) {
        throw new RuntimeException('No fue posible abrir la hoja del Excel.');
    }

    $headerMap = null;
    $totalRows = 0;
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $outerXml = $reader->readOuterXML();
            if ($outerXml === '') {
                continue;
            }
            $row = terrenoUpdateParseSheetRowXml($outerXml, $sharedStrings);
            if ($row === null) {
                continue;
            }
            if ($headerMap === null) {
                $headerMap = terrenoUpdateBuildHeaderMap($row['cells']);
                continue;
            }
            $totalRows++;
        }
    } finally {
        $reader->close();
    }

    if ($headerMap === null) {
        throw new RuntimeException('El archivo Excel no contiene encabezados válidos.');
    }

    return [$headerMap, $totalRows];
}

function terrenoUpdateReadSheetChunk(string $sheetPath, array $sharedStrings, int $alreadyProcessed, int $limit): array
{
    $reader = new XMLReader();
    if (!$reader->open($sheetPath)) {
        throw new RuntimeException('No fue posible abrir la hoja del Excel.');
    }

    $rows = [];
    $dataIndex = 0;
    $headerRead = false;
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $outerXml = $reader->readOuterXML();
            if ($outerXml === '') {
                continue;
            }
            $row = terrenoUpdateParseSheetRowXml($outerXml, $sharedStrings);
            if ($row === null) {
                continue;
            }
            if (!$headerRead) {
                $headerRead = true;
                continue;
            }
            if ($dataIndex < $alreadyProcessed) {
                $dataIndex++;
                continue;
            }
            $rows[] = $row;
            $dataIndex++;
            if (count($rows) >= $limit) {
                break;
            }
        }
    } finally {
        $reader->close();
    }

    return $rows;
}

function terrenoUpdateParseSheetRowXml(string $rowXml, array $sharedStrings): ?array
{
    $row = @simplexml_load_string($rowXml);
    if ($row === false) {
        return null;
    }

    $attrs = $row->attributes();
    $rowNum = isset($attrs['r']) ? (int)$attrs['r'] : 0;
    $cells = [];

    foreach ($row->c as $cell) {
        $cellAttrs = $cell->attributes();
        $ref = (string)($cellAttrs['r'] ?? '');
        $type = (string)($cellAttrs['t'] ?? '');
        $columnLetters = preg_replace('/\d+/', '', $ref) ?? '';
        $columnIndex = terrenoUpdateExcelColumnToIndex($columnLetters);
        if ($columnIndex < 0) {
            continue;
        }

        $value = '';
        if ($type === 'inlineStr') {
            $value = (string)($cell->is->t ?? '');
        } else {
            $value = (string)($cell->v ?? '');
            if ($type === 's' && $value !== '') {
                $value = (string)($sharedStrings[(int)$value] ?? '');
            }
        }
        $cells[$columnIndex] = $value;
    }

    if ($cells === []) {
        return null;
    }

    ksort($cells);
    return ['row_num' => $rowNum, 'cells' => $cells];
}

function terrenoUpdateResolveFirstSheetRelativePath(string $extractDir): string
{
    $workbookPath = $extractDir . '/xl/workbook.xml';
    $relsPath = $extractDir . '/xl/_rels/workbook.xml.rels';
    if (!is_file($workbookPath) || !is_file($relsPath)) {
        throw new RuntimeException('El archivo Excel es inválido.');
    }

    $workbook = new DOMDocument();
    $workbook->load($workbookPath);
    $rels = new DOMDocument();
    $rels->load($relsPath);

    $relMap = [];
    foreach ($rels->getElementsByTagName('Relationship') as $rel) {
        $relMap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
    }

    $sheet = $workbook->getElementsByTagName('sheet')->item(0);
    if (!$sheet instanceof DOMElement) {
        throw new RuntimeException('El archivo Excel no contiene hojas.');
    }

    $relId = $sheet->getAttribute('r:id');
    $target = $relMap[$relId] ?? '';
    if ($target === '') {
        throw new RuntimeException('No fue posible resolver la hoja principal del Excel.');
    }

    return 'xl/' . ltrim(str_replace('xl/', '', $target), '/');
}

function terrenoUpdateLoadSharedStrings(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $doc = new DOMDocument();
    $doc->load($path);
    $items = [];
    foreach ($doc->getElementsByTagName('si') as $si) {
        $text = '';
        foreach ($si->getElementsByTagName('t') as $t) {
            $text .= $t->textContent;
        }
        $items[] = $text;
    }
    return $items;
}

function terrenoUpdateBuildHeaderMap(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $index => $value) {
        $key = terrenoUpdateNormalizeHeaderKey((string)$value);
        if ($key !== '') {
            $map[$key] = $index;
        }
    }
    return $map;
}

function terrenoUpdateResolveRequiredHeaders(array $headerMap, array $required): array
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

function terrenoUpdateCellByHeader(array $row, array $headerMap, string $header): mixed
{
    $idx = $headerMap[$header] ?? null;
    return $idx === null ? null : ($row[$idx] ?? null);
}

function terrenoUpdateNormalizeHeaderKey(string $value): string
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

function terrenoUpdateExcelColumnToIndex(string $letters): int
{
    $letters = strtoupper(trim($letters));
    if ($letters === '') {
        return -1;
    }
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $char = ord($letters[$i]);
        if ($char < 65 || $char > 90) {
            return -1;
        }
        $index = ($index * 26) + ($char - 64);
    }
    return $index - 1;
}

function terrenoUpdateParsePositiveInt(mixed $value): ?int
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

function terrenoUpdateParseFlag(mixed $value): array
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

function terrenoUpdateParseDateValue(mixed $value): ?string
{
    $dt = terrenoUpdateParseExcelDateTimeValue($value);
    return $dt ? $dt->format('Y-m-d') : null;
}

function terrenoUpdateParseExcelDateTimeValue(mixed $value): ?DateTimeImmutable
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

function terrenoUpdateBuildMatchKey(int $idResultado, int $idPregunta, int $idSeccion, string $rut, string $fecha): string
{
    return implode('|', [$idResultado, $idPregunta, $idSeccion, $rut, $fecha]);
}

function terrenoUpdateEmptySummary(int $totalRows): array
{
    return [
        'total_rows' => $totalRows,
        'ready_full' => 0,
        'ready_date_only' => 0,
        'ready_total' => 0,
        'conflict_total' => 0,
        'no_match_total' => 0,
        'db_duplicate_total' => 0,
        'excel_duplicate_total' => 0,
        'invalid_total' => 0,
    ];
}

function terrenoUpdateEmptySamples(): array
{
    return [
        'ready_full' => [],
        'ready_date_only' => [],
        'no_match' => [],
        'conflict' => [],
    ];
}

function terrenoUpdateSummarizeRows(array $rows, int $totalRows): array
{
    $summary = terrenoUpdateEmptySummary($totalRows);
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        if ($status === 'ready_full') {
            $summary['ready_full']++;
            $summary['ready_total']++;
        } elseif ($status === 'ready_date_only') {
            $summary['ready_date_only']++;
            $summary['ready_total']++;
        } elseif ($status === 'no_match') {
            $summary['no_match_total']++;
        } elseif ($status === 'conflict') {
            $summary['conflict_total']++;
            $reason = (string)($row['reason'] ?? '');
            if (str_contains($reason, 'repite en el Excel')) {
                $summary['excel_duplicate_total']++;
            } elseif (str_contains($reason, 'más de un registro en BD')) {
                $summary['db_duplicate_total']++;
            } else {
                $summary['invalid_total']++;
            }
        }
    }
    return $summary;
}

function terrenoUpdateBuildSamples(array $rows): array
{
    $samples = terrenoUpdateEmptySamples();
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        if ($status === 'ready_full') {
            terrenoUpdateCollectSample($samples['ready_full'], $row, 'Actualización completa');
        } elseif ($status === 'ready_date_only') {
            terrenoUpdateCollectSample($samples['ready_date_only'], $row, 'Solo fecha');
        } elseif ($status === 'no_match') {
            terrenoUpdateCollectSample($samples['no_match'], $row, 'Sin match');
        } elseif ($status === 'conflict') {
            terrenoUpdateCollectSample($samples['conflict'], $row, 'Conflicto');
        }
    }
    return $samples;
}

function terrenoUpdateCollectSample(array &$bucket, array $row, string $label): void
{
    if (count($bucket) >= TERRENO_UPDATE_SAMPLE_LIMIT) {
        return;
    }
    $bucket[] = [
        'excel_row' => $row['excel_row'] ?? '',
        'id_resultado' => $row['id_resultado'] ?? '',
        'id_pregunta' => $row['id_pregunta'] ?? '',
        'id_seccion' => $row['id_seccion'] ?? '',
        'rut_contratista' => $row['rut_contratista'] ?? '',
        'fecha_antigua' => $row['fecha_antigua'] ?? '',
        'fecha_real' => $row['fecha_real'] ?? '',
        'cumple_real' => terrenoUpdateDisplayNullableFlag($row['cumple_real'] ?? null),
        'no_cumple_real' => terrenoUpdateDisplayNullableFlag($row['no_cumple_real'] ?? null),
        'no_aplica_real' => terrenoUpdateDisplayNullableFlag($row['no_aplica_real'] ?? null),
        'estado' => $label,
        'motivo' => $row['reason'] ?? '',
    ];
}

function terrenoUpdateDisplayNullableFlag(mixed $value): string
{
    return $value === null ? '' : (string)$value;
}

function terrenoUpdatePercent(int $processed, int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    return (int)max(0, min(100, round(($processed / $total) * 100)));
}

function terrenoUpdateCreateJobDir(): string
{
    $baseDir = sys_get_temp_dir() . '/ceo_terreno_update_jobs';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No fue posible crear el directorio temporal base.');
    }
    $jobDir = $baseDir . '/job_' . session_id() . '_' . bin2hex(random_bytes(8));
    if (!mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException('No fue posible crear el directorio temporal del análisis.');
    }
    return $jobDir;
}

function terrenoUpdateStateFile(): string
{
    $baseDir = sys_get_temp_dir() . '/ceo_terreno_update_state';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No fue posible crear el directorio del estado temporal.');
    }
    return $baseDir . '/state_' . session_id() . '.bin';
}

function terrenoUpdateSaveSessionState(array $state): void
{
    $path = terrenoUpdateStateFile();
    if (file_put_contents($path, serialize($state)) === false) {
        throw new RuntimeException('No fue posible guardar el estado del preview.');
    }
    $_SESSION[TERRENO_UPDATE_SESSION_KEY] = ['state_file' => $path];
}

function terrenoUpdateLoadSessionState(): ?array
{
    $sessionState = $_SESSION[TERRENO_UPDATE_SESSION_KEY] ?? null;
    if (!is_array($sessionState) || empty($sessionState['state_file'])) {
        return null;
    }
    $path = (string)$sessionState['state_file'];
    if (!is_file($path)) {
        unset($_SESSION[TERRENO_UPDATE_SESSION_KEY]);
        return null;
    }
    $data = terrenoUpdateReadSerialized($path);
    if (!is_array($data) || ($data['version'] ?? null) !== TERRENO_UPDATE_PAYLOAD_VERSION) {
        unset($_SESSION[TERRENO_UPDATE_SESSION_KEY]);
        return null;
    }
    return $data;
}

function terrenoUpdateReadSerialized(string $path): mixed
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    return unserialize($raw, ['allowed_classes' => false]);
}

function terrenoUpdateClearCurrentState(?array $state): void
{
    $stateFile = $_SESSION[TERRENO_UPDATE_SESSION_KEY]['state_file'] ?? null;
    if (is_string($stateFile) && is_file($stateFile)) {
        @unlink($stateFile);
    }
    unset($_SESSION[TERRENO_UPDATE_SESSION_KEY]);

    $jobDir = is_array($state) ? (string)($state['job_dir'] ?? '') : '';
    if ($jobDir !== '' && is_dir($jobDir)) {
        terrenoUpdateDeleteDir($jobDir);
    }
}

function terrenoUpdateDeleteDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            terrenoUpdateDeleteDir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Herramienta Terreno Update | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { min-height: 100vh; background: radial-gradient(circle at top right, rgba(37, 99, 235, .10), transparent 30%), linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%); color: #0f172a; }
.shell { max-width: 1480px; }
.hero, .card-soft { background: rgba(255,255,255,.92); border: 1px solid rgba(148,163,184,.18); border-radius: 26px; box-shadow: 0 16px 40px rgba(15,23,42,.08); }
.stat-card { background: #fff; border: 1px solid rgba(148,163,184,.16); border-radius: 20px; }
.tiny-note { font-size: .78rem; color: #64748b; }
.table-zone { overflow-x: auto; }
</style>
</head>
<body>
<div class="container-fluid shell py-4">
    <section class="hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
            <div>
                <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-3"><i class="bi bi-arrow-repeat me-1"></i> Correccion Historica Terreno</span>
                <h1 class="display-6 fw-semibold mb-3">Herramienta Terreno Update</h1>
                <p class="text-secondary mb-2">Carga por segmentos para actualizar respuestas históricas de terreno sin timeout.</p>
                <p class="small text-muted mb-0">Match: <code>id_resultado</code>, <code>id_pregunta</code>, <code>id_seccion</code>, <code>rut_contratista</code> y <code>fecha</code> antigua.</p>
            </div>
            <div><a class="btn btn-outline-secondary" href="<?= esc(app_url('/public/kit_herramientas_admin.php')) ?>">Volver al Kit</a></div>
        </div>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?= esc($mensajeTipo) ?> mb-4"><?= esc($mensaje) ?></div>
    <?php endif; ?>

    <div class="card-soft p-4 p-lg-5 mb-4">
        <h2 class="h4 mb-3">Subir Excel</h2>
        <p class="tiny-note mb-4">Si los tres campos reales vienen vacíos, solo se actualizará <code>fecha</code> con <code>Fecha Real</code>.</p>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="accion" value="analizar">
            <div class="col-lg-8">
                <label class="form-label">Archivo Excel</label>
                <input type="file" name="excel" class="form-control" accept=".xlsx" required>
            </div>
            <div class="col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Iniciar Analisis</button>
            </div>
        </form>
    </div>

    <?php if (is_array($analisis) && ($analisis['status'] ?? '') === 'processing'): ?>
        <div class="card-soft p-4 p-lg-5 mb-4" id="processingCard">
            <h2 class="h4 mb-2">Procesando Preview</h2>
            <div class="tiny-note mb-3">Archivo: <?= esc((string)$analisis['source_name']) ?></div>
            <div class="progress mb-3" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="processingBar" style="width: <?= terrenoUpdatePercent((int)$analisis['processed_rows'], (int)$analisis['total_rows']) ?>%"></div>
            </div>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <div><strong id="processingText"><?= (int)$analisis['processed_rows'] ?></strong> de <strong id="processingTotal"><?= (int)$analisis['total_rows'] ?></strong> filas procesadas.</div>
                <div class="tiny-note" id="processingSummary">El navegador irá actualizando el avance automáticamente.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (is_array($analisis) && ($analisis['status'] ?? '') === 'ready'): ?>
        <?php $summary = $analisis['summary'] ?? []; ?>
        <div class="card-soft p-4 p-lg-5 mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <h2 class="h4 mb-1">Preview</h2>
                    <div class="tiny-note">Archivo: <?= esc((string)($analisis['source_name'] ?? '')) ?> | Generado: <?= esc((string)($analisis['created_at'] ?? '')) ?></div>
                </div>
                <div class="d-flex gap-2">
                    <form method="post"><input type="hidden" name="accion" value="aplicar"><button type="submit" class="btn btn-success" <?= ((int)($summary['ready_total'] ?? 0) === 0) ? 'disabled' : '' ?>><i class="bi bi-check2-circle me-1"></i>Confirmar Actualizacion</button></form>
                    <form method="post"><input type="hidden" name="accion" value="limpiar"><button type="submit" class="btn btn-outline-secondary">Descartar Preview</button></form>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Filas leidas</div><div class="fs-4 fw-semibold"><?= (int)($summary['total_rows'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Actualizacion completa</div><div class="fs-4 fw-semibold text-success"><?= (int)($summary['ready_full'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Solo fecha</div><div class="fs-4 fw-semibold text-primary"><?= (int)($summary['ready_date_only'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Sin match</div><div class="fs-4 fw-semibold text-warning"><?= (int)($summary['no_match_total'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Conflictos</div><div class="fs-4 fw-semibold text-danger"><?= (int)($summary['conflict_total'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Duplicadas en Excel</div><div class="fs-4 fw-semibold"><?= (int)($summary['excel_duplicate_total'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Duplicadas en BD</div><div class="fs-4 fw-semibold"><?= (int)($summary['db_duplicate_total'] ?? 0) ?></div></div></div>
                <div class="col-md-6 col-xl-3"><div class="stat-card p-3"><div class="tiny-note">Listas para aplicar</div><div class="fs-4 fw-semibold"><?= (int)($summary['ready_total'] ?? 0) ?></div></div></div>
            </div>

            <?php foreach (['ready_full' => 'Actualizacion completa', 'ready_date_only' => 'Solo fecha', 'no_match' => 'Sin match', 'conflict' => 'Conflictos'] as $bucketKey => $title): ?>
                <?php $sampleRows = $analisis['samples'][$bucketKey] ?? []; ?>
                <section class="mb-4">
                    <h3 class="h5 mb-2"><?= esc($title) ?></h3>
                    <?php if ($sampleRows === []): ?>
                        <div class="tiny-note">Sin filas en esta categoria.</div>
                    <?php else: ?>
                        <div class="table-zone">
                            <table class="table table-sm table-bordered align-middle bg-white mb-0">
                                <thead class="table-light"><tr><th>Fila Excel</th><th>id_resultado</th><th>id_pregunta</th><th>id_seccion</th><th>RUT</th><th>Fecha antigua</th><th>Fecha real</th><th>cumple_real</th><th>no_cumple_real</th><th>no_aplica_real</th><th>Motivo</th></tr></thead>
                                <tbody>
                                <?php foreach ($sampleRows as $sample): ?>
                                    <tr>
                                        <td><?= esc((string)$sample['excel_row']) ?></td>
                                        <td><?= esc((string)$sample['id_resultado']) ?></td>
                                        <td><?= esc((string)$sample['id_pregunta']) ?></td>
                                        <td><?= esc((string)$sample['id_seccion']) ?></td>
                                        <td><?= esc((string)$sample['rut_contratista']) ?></td>
                                        <td><?= esc((string)$sample['fecha_antigua']) ?></td>
                                        <td><?= esc((string)$sample['fecha_real']) ?></td>
                                        <td><?= esc((string)$sample['cumple_real']) ?></td>
                                        <td><?= esc((string)$sample['no_cumple_real']) ?></td>
                                        <td><?= esc((string)$sample['no_aplica_real']) ?></td>
                                        <td><?= esc((string)$sample['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($resultadoAplicacion)): ?>
        <div class="card-soft p-4 p-lg-5">
            <h2 class="h4 mb-3">Resultado de la Actualizacion</h2>
            <div class="row g-3">
                <div class="col-md-4"><div class="stat-card p-3"><div class="tiny-note">Actualizacion completa</div><div class="fs-4 fw-semibold text-success"><?= (int)$resultadoAplicacion['updated_full'] ?></div></div></div>
                <div class="col-md-4"><div class="stat-card p-3"><div class="tiny-note">Solo fecha</div><div class="fs-4 fw-semibold text-primary"><?= (int)$resultadoAplicacion['updated_date_only'] ?></div></div></div>
                <div class="col-md-4"><div class="stat-card p-3"><div class="tiny-note">Omitidas por cambio de estado</div><div class="fs-4 fw-semibold text-warning"><?= (int)$resultadoAplicacion['skipped_state_changed'] ?></div></div></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (is_array($analisis) && ($analisis['status'] ?? '') === 'processing'): ?>
<script>
let running = false;
async function processChunk() {
    if (running) return;
    running = true;
    try {
        const formData = new FormData();
        formData.append('accion', 'procesar_segmento');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (!result.ok) throw new Error(result.error || 'No fue posible continuar el análisis.');
        document.getElementById('processingBar').style.width = `${result.percent}%`;
        document.getElementById('processingText').textContent = String(result.processed_rows || 0);
        document.getElementById('processingTotal').textContent = String(result.total_rows || 0);
        const s = result.summary || {};
        document.getElementById('processingSummary').textContent = `Listas: ${s.ready_total || 0} | Sin match: ${s.no_match_total || 0} | Conflictos: ${s.conflict_total || 0}`;
        if (result.done) {
            window.location.reload();
            return;
        }
        running = false;
        setTimeout(processChunk, 120);
    } catch (error) {
        running = false;
        document.getElementById('processingSummary').textContent = error.message || 'Error inesperado durante el análisis.';
    }
}
processChunk();
</script>
<?php endif; ?>
</body>
</html>
