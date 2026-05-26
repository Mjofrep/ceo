<?php
declare(strict_types=1);

function gpImpClean(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function gpImpXmlText(string $xml): string
{
    $xml = preg_replace('/<t[^>]*>(.*?)<\/t>/is', '$1 ', $xml) ?? $xml;
    $xml = preg_replace('/<[^>]+>/u', ' ', $xml) ?? $xml;
    return gpImpClean($xml);
}

function gpImpCellValue(string $cellXml, array $sharedStrings): string
{
    $type = '';
    if (preg_match('/\st="([^"]+)"/i', $cellXml, $m)) {
        $type = $m[1];
    }
    if ($type === 's' && preg_match('/<v[^>]*>(.*?)<\/v>/is', $cellXml, $m)) {
        return gpImpClean($sharedStrings[(int)trim($m[1])] ?? '');
    }
    if ($type === 'inlineStr') {
        return gpImpXmlText($cellXml);
    }
    if (preg_match('/<v[^>]*>(.*?)<\/v>/is', $cellXml, $m)) {
        return gpImpClean($m[1]);
    }
    return '';
}

function gpImpExtractSheetLines(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive no esta disponible para leer XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el XLSX.');
    }

    $sharedStrings = [];
    $sharedXml = (string)$zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== '' && preg_match_all('/<si[^>]*>(.*?)<\/si>/is', $sharedXml, $matches)) {
        foreach ($matches[1] as $si) {
            $sharedStrings[] = gpImpXmlText($si);
        }
    }

    $rels = [];
    $relsXml = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml !== '' && preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i', $relsXml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $rel) {
            $rels[$rel[1]] = $rel[2];
        }
    }

    $workbookXml = (string)$zip->getFromName('xl/workbook.xml');
    if ($workbookXml === '') {
        $zip->close();
        throw new RuntimeException('El XLSX no contiene workbook.xml.');
    }

    $sheets = [];
    if (preg_match_all('/<sheet\b[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"[^>]*\/?>/i', $workbookXml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $sheet) {
            $target = $rels[$sheet[2]] ?? '';
            if ($target === '') {
                continue;
            }
            $file = str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
            $sheets[] = ['name' => html_entity_decode($sheet[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'file' => $file];
        }
    }

    $result = [];
    foreach ($sheets as $sheet) {
        $sheetXml = (string)$zip->getFromName($sheet['file']);
        if ($sheetXml === '') {
            continue;
        }
        $lines = [];
        if (preg_match_all('/<row\b[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/is', $sheetXml, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $parts = [];
                if (preg_match_all('/<c\b[^>]*>.*?<\/c>/is', $row[2], $cells)) {
                    foreach ($cells[0] as $cellXml) {
                        $value = gpImpCellValue($cellXml, $sharedStrings);
                        if ($value !== '') {
                            $parts[] = $value;
                        }
                    }
                }
                if ($parts) {
                    $lines[] = ['row' => (int)$row[1], 'text' => gpImpClean(implode(' ', $parts))];
                }
            }
        }
        $result[] = ['name' => $sheet['name'], 'lines' => $lines];
    }

    $zip->close();
    return $result;
}

function gpImpAlternativeMatch(string $line): ?array
{
    if (preg_match('/^\s*-?\s*([a-eA-E])[\)\.]\s*(.+)$/u', $line, $m)) {
        return [strtoupper($m[1]), gpImpClean($m[2])];
    }
    return null;
}

function gpImpCorrectLetter(string $line): string
{
    if (preg_match('/respuesta\s+correcta\s*:\s*([a-eA-E])/iu', $line, $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

function gpImpIsNoise(string $line): bool
{
    $low = mb_strtolower(trim($line, ': '), 'UTF-8');
    if ($low === '' || preg_match('/^pregunta\s*\d+\s*:?$/iu', $low)) {
        return true;
    }
    if (preg_match('/^nivel\s+(bajo|medio|alto)\s*:?$/iu', $low)) {
        return true;
    }
    if (preg_match('/^(\d+\.?\s*)?(conexion a tierra|conexión a tierra|encargado de trabajo|seguridad|intervenciones|temas generales|transgresiones|maniobras)$/iu', $low)) {
        return true;
    }
    return false;
}

function gpImpNormalizeQuestionLine(string $line): string
{
    return gpImpClean(preg_replace('/^nivel\s+(bajo|medio|alto)\s*:\s*/iu', '', $line) ?? $line);
}

function gpImpParseXlsxQuestions(string $path): array
{
    $records = [];
    foreach (gpImpExtractSheetLines($path) as $sheet) {
        if (mb_strtoupper($sheet['name'], 'UTF-8') === 'Q PREGUNTAS') {
            continue;
        }
        $lines = array_values($sheet['lines']);
        $start = 0;
        foreach ($lines as $i => $lineInfo) {
            $line = $lineInfo['text'];
            if (stripos($line, 'respuesta correcta') === false) {
                continue;
            }

            $chunk = array_slice($lines, $start, $i - $start);
            $start = $i + 1;
            $alts = [];
            $qParts = [];
            $seenAlt = false;

            foreach ($chunk as $chunkLineInfo) {
                $chunkLine = gpImpNormalizeQuestionLine($chunkLineInfo['text']);
                if (gpImpIsNoise($chunkLine)) {
                    continue;
                }
                $alt = gpImpAlternativeMatch($chunkLine);
                if ($alt) {
                    $seenAlt = true;
                    $alts[] = ['letra' => $alt[0], 'texto' => $alt[1], 'correcta' => false];
                } elseif (!$seenAlt) {
                    $qParts[] = $chunkLine;
                } elseif ($alts) {
                    $alts[count($alts) - 1]['texto'] = gpImpClean($alts[count($alts) - 1]['texto'] . ' ' . $chunkLine);
                }
            }

            $correct = gpImpCorrectLetter($line);
            foreach ($alts as &$alt) {
                $alt['correcta'] = $correct !== '' && $alt['letra'] === $correct;
            }
            unset($alt);

            $pregunta = gpImpClean(implode(' ', $qParts));
            if ($pregunta === '' || count($alts) < 2) {
                continue;
            }
            $records[] = [
                'pregunta' => $pregunta,
                'alternativas' => $alts,
                'referencia' => $sheet['name'] . ' / fila ' . (int)$lineInfo['row'],
            ];
        }
    }
    return $records;
}

function gpImpParsePdfQuestions(string $text): array
{
    $text = gpImpClean($text);
    $text = preg_replace('/<PARSED TEXT FOR PAGE:\s*\d+\s*\/\s*\d+>/iu', "\n", $text) ?? $text;
    $text = preg_replace('/\bPRUEBA\s+CONOCIMIENTOS\s+VA22\b|\bINTERNAL\b/u', '', $text) ?? $text;
    $text = preg_replace('/CUADRILLA\s+INSPECCIONES\s+CONTROL\s+DE\s+P[ÉE]RDIDAS/iu', '', $text) ?? $text;
    $text = preg_replace('/Nombre completo:.*|Cedula identidad:.*|Fecha:\s*____.*|Empresa contratista:.*|Cargo al que postula:.*|INSTRUCCIONES:.*Debe marcar.*$/imu', '', $text) ?? $text;
    $text = preg_replace('/\n\s*\d+\s*\n/u', "\n", $text) ?? $text;
    $text = preg_replace('/([\.\?\!])\s+(?:Pregunta\s*)?(\d{1,3})[\.)]\s+/iu', "$1\n$2. ", $text) ?? $text;
    $text = preg_replace('/\s+(\d{1,3})[\.)]\s+(?=[¿A-ZÁÉÍÓÚÑ])/u', "\n$1. ", $text) ?? $text;
    $text = preg_replace('/\n\s*Pregunta\s+(\d{1,3})\s*:?\s*/iu', "\n$1. ", $text) ?? $text;
    $text = preg_replace('/(?<![\p{L}\p{N}])[-–]?\s*([a-eA-E])[\)\.]\s+/u', "\n$1. ", $text) ?? $text;
    $text = preg_replace('/\n([a-eA-E])\.\s+/u', "\n$1. ", $text) ?? $text;
    $text = gpImpClean($text);

    preg_match_all('/(?:^|\n)\s*(?:Pregunta\s*)?(\d{1,3})[\.)]\s+/iu', $text, $matches, PREG_OFFSET_CAPTURE);
    $records = [];
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
        $start = $matches[0][$i][1];
        $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($text);
        $block = trim(substr($text, $start, $end - $start));
        $block = preg_replace('/^(?:Pregunta\s*)?\d{1,3}[\.)]\s*/iu', '', $block) ?? $block;
        if (!preg_match_all('/(?:^|\n)\s*-?\s*([a-eA-E])[\)\.]\s+/u', $block, $altMatches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $altCount = count($altMatches[0]);
        $firstAltOffset = $altMatches[0][0][1];
        $pregunta = gpImpClean(substr($block, 0, $firstAltOffset));
        $alts = [];
        for ($j = 0; $j < $altCount; $j++) {
            $altStart = $altMatches[0][$j][1] + strlen($altMatches[0][$j][0]);
            $altEnd = $j + 1 < $altCount ? $altMatches[0][$j + 1][1] : strlen($block);
            $texto = gpImpClean(substr($block, $altStart, $altEnd - $altStart));
            $texto = preg_replace('/\s+(?=\d{1,3}[\.)]\s+)/u', '', $texto) ?? $texto;
            if ($texto !== '') {
                $alts[] = ['letra' => strtoupper($altMatches[1][$j][0]), 'texto' => $texto, 'correcta' => false];
            }
        }
        if ($pregunta !== '' && count($alts) >= 2) {
            $records[] = ['pregunta' => $pregunta, 'alternativas' => $alts, 'referencia' => 'PDF pregunta ' . ($i + 1)];
        }
    }
    return $records;
}

function gpImpValidateQuestion(array $record): array
{
    $errors = [];
    if (trim((string)($record['pregunta'] ?? '')) === '') {
        $errors[] = 'pregunta vacia';
    }
    if (count($record['alternativas'] ?? []) < 2) {
        $errors[] = 'menos de 2 alternativas';
    }
    if (count($record['alternativas'] ?? []) > 6) {
        $errors[] = 'mas de 6 alternativas';
    }
    $corrects = 0;
    foreach (($record['alternativas'] ?? []) as $alt) {
        if (trim((string)($alt['texto'] ?? '')) === '') {
            $errors[] = 'alternativa vacia';
        }
        if (!empty($alt['correcta'])) {
            $corrects++;
        }
    }
    if ($corrects > 1) {
        $errors[] = 'mas de una alternativa correcta';
    }
    return $errors;
}

function gpImpInsertQuestions(PDO $pdo, int $idFuente, array $records, int $creadoPor = 0, string $origen = 'MANUAL'): array
{
    $stmtFuente = $pdo->prepare('SELECT * FROM ceo_gp_fuentes WHERE id = :id LIMIT 1');
    $stmtFuente->execute([':id' => $idFuente]);
    $fuente = $stmtFuente->fetch(PDO::FETCH_ASSOC);
    if (!$fuente) {
        throw new RuntimeException('Fuente no encontrada para importar preguntas.');
    }

    $origen = $origen === 'IA' ? 'IA' : 'MANUAL';
    $stmtPregunta = $pdo->prepare('INSERT INTO ceo_gp_preguntas (id_fuente, destino, id_servicio, id_agrupacion, id_area, pregunta, referencia, import_referencia, origen, estado, creado_por) VALUES (:id_fuente, :destino, :id_servicio, :id_agrupacion, :id_area, :pregunta, :referencia, :import_referencia, :origen, "REVISION", :creado_por)');
    $stmtAlt = $pdo->prepare('INSERT INTO ceo_gp_alternativas (id_pregunta, orden, alternativa, correcta, estado) VALUES (:id_pregunta, :orden, :alternativa, :correcta, "A")');

    $inserted = 0;
    $alternatives = 0;
    $skipped = [];
    foreach ($records as $idx => $record) {
        $errors = gpImpValidateQuestion($record);
        if ($errors) {
            $skipped[] = 'Registro ' . ($idx + 1) . ': ' . implode(', ', $errors);
            continue;
        }
        $stmtPregunta->execute([
            ':id_fuente' => $idFuente,
            ':destino' => $fuente['destino'],
            ':id_servicio' => (int)$fuente['id_servicio'],
            ':id_agrupacion' => (int)($fuente['id_agrupacion'] ?? 0) > 0 ? (int)$fuente['id_agrupacion'] : null,
            ':id_area' => (int)($fuente['id_area'] ?? 0) > 0 ? (int)$fuente['id_area'] : null,
            ':pregunta' => gpImpClean((string)$record['pregunta']),
            ':referencia' => (string)($record['referencia'] ?? ''),
            ':import_referencia' => (string)($record['referencia'] ?? ''),
            ':origen' => $origen,
            ':creado_por' => $creadoPor > 0 ? $creadoPor : null,
        ]);
        $idPregunta = (int)$pdo->lastInsertId();
        foreach ($record['alternativas'] as $orden => $alt) {
            $stmtAlt->execute([
                ':id_pregunta' => $idPregunta,
                ':orden' => $orden + 1,
                ':alternativa' => gpImpClean((string)$alt['texto']),
                ':correcta' => !empty($alt['correcta']) ? 'S' : 'N',
            ]);
            $alternatives++;
        }
        $inserted++;
    }

    return ['preguntas' => $inserted, 'alternativas' => $alternatives, 'omitidas' => $skipped];
}

function gpImpParseDocument(string $path, string $ext, string $text = ''): array
{
    $ext = strtolower($ext);
    if ($ext === 'xlsx') {
        return gpImpParseXlsxQuestions($path);
    }
    if ($ext === 'pdf') {
        return gpImpParsePdfQuestions($text);
    }
    throw new RuntimeException('Importacion de preguntas soportada solo para XLSX y PDF.');
}
