<?php
declare(strict_types=1);

const GP_AI_PDF_CURL_TIMEOUT_SECONDS = 55;
const GP_AI_PDF_UPLOAD_TIMEOUT_SECONDS = 60;
const GP_AI_PDF_MAX_OUTPUT_TOKENS = 12000;
const GP_AI_PDF_BATCH_SIZE = 5;
const GP_AI_PDF_MAX_QUESTIONS_SCAN = 120;
const GP_AI_PDF_BATCH_MAX_OUTPUT_TOKENS = 3500;

function gpAiPdfApiKey(): string
{
    $localKey = defined('OPENAI_API_KEY_LOCAL')
        ? trim((string)constant('OPENAI_API_KEY_LOCAL'))
        : '';
    if ($localKey !== '' && $localKey !== 'TU_API_KEY_AQUI') {
        return $localKey;
    }

    $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    return $key;
}

function gpAiPdfModel(): string
{
    if (defined('OPENAI_PDF_MODEL_LOCAL')) {
        $model = trim((string)constant('OPENAI_PDF_MODEL_LOCAL'));
        if ($model !== '') {
            return $model;
        }
    }

    if (defined('OPENAI_MODEL_LOCAL')) {
        $model = trim((string)constant('OPENAI_MODEL_LOCAL'));
        if ($model !== '') {
            return $model;
        }
    }

    $model = trim((string)(getenv('OPENAI_MODEL') ?: ''));
    return $model !== '' ? $model : 'gpt-4.1-mini';
}

function gpAiPdfExtractJson(string $text): string
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```[a-zA-Z0-9_\-]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);
    }

    json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $text;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $candidate = substr($text, $start, $end - $start + 1);
        json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $candidate;
        }
    }

    throw new RuntimeException('OpenAI no devolvio JSON valido para la extraccion del PDF.');
}

function gpAiPdfResponseText(array $data): string
{
    $outputText = trim((string)($data['output_text'] ?? ''));
    if ($outputText !== '') {
        return $outputText;
    }

    $parts = [];
    foreach (($data['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            $text = (string)($content['text'] ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }

    return trim(implode("\n", $parts));
}

function gpAiPdfPrompt(array $context): string
{
    $destino = (string)($context['destino'] ?? '');
    $servicio = (string)($context['servicio'] ?? '');
    $agrupacion = (string)($context['agrupacion'] ?? '');

    return "Extrae preguntas y alternativas existentes del PDF para CEONext.\n"
        . "Contexto: destino={$destino}; servicio={$servicio}; agrupacion={$agrupacion}.\n"
        . "Reglas obligatorias:\n"
        . "- No generes preguntas nuevas.\n"
        . "- No inventes alternativas ni respuestas correctas.\n"
        . "- No cambies el sentido del texto; solo limpia saltos de linea o espacios obvios.\n"
        . "- Ignora encabezados, pies de pagina, instrucciones, campos de nombre/RUT/fecha y numeracion de paginas.\n"
        . "- Cada registro debe tener una pregunta y entre 2 y 6 alternativas.\n"
        . "- Si el PDF no indica respuesta correcta, correcta_index debe ser null.\n"
        . "- Si indica respuesta correcta, correcta_index debe ser el indice base cero de la alternativa correcta.\n"
        . "- Devuelve solo JSON valido, sin markdown ni explicaciones.\n"
        . "Formato exacto: {\"preguntas\":[{\"pregunta\":\"...\",\"alternativas\":[\"...\",\"...\"],\"correcta_index\":null,\"referencia\":\"pagina o seccion si existe\"}]}.";
}

function gpAiPdfRangePrompt(array $context, int $from, int $to): string
{
    $destino = (string)($context['destino'] ?? '');
    $servicio = (string)($context['servicio'] ?? '');
    $agrupacion = (string)($context['agrupacion'] ?? '');
    $instruction = trim((string)($context['instruction'] ?? ''));

    $prompt = "Extrae preguntas y alternativas existentes del PDF para CEONext.\n"
        . "Contexto: destino={$destino}; servicio={$servicio}; agrupacion={$agrupacion}.\n"
        . "Extrae SOLO las preguntas numeradas desde {$from} hasta {$to}.\n"
        . "Si el PDF no contiene preguntas dentro de ese rango, devuelve {\"preguntas\":[]}.\n"
        . "Reglas obligatorias:\n"
        . "- No generes preguntas nuevas.\n"
        . "- No extraigas preguntas fuera del rango {$from}-{$to}.\n"
        . "- No inventes alternativas ni respuestas correctas.\n"
        . "- No cambies el sentido del texto; solo limpia saltos de linea o espacios obvios.\n"
        . "- Ignora encabezados, pies de pagina, instrucciones, campos de nombre/RUT/fecha y numeracion de paginas.\n"
        . "- Cada registro debe tener una pregunta y entre 2 y 6 alternativas.\n"
        . "- Si el PDF no indica respuesta correcta, correcta_index debe ser null.\n"
        . "- Si indica respuesta correcta, correcta_index debe ser el indice base cero de la alternativa correcta.\n"
        . "- Devuelve solo JSON valido, sin markdown ni explicaciones.\n"
        . "Formato exacto: {\"preguntas\":[{\"pregunta\":\"...\",\"alternativas\":[\"...\",\"...\"],\"correcta_index\":null,\"referencia\":\"pagina o seccion si existe\"}]}.";

    if ($instruction !== '') {
        $prompt .= "\nInstruccion adicional del operador:\n" . $instruction;
    }

    return $prompt;
}

function gpAiPdfNormalizeQuestions(array $payload, string $fallbackReference): array
{
    $rows = $payload['preguntas'] ?? $payload['questions'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    $records = [];
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $pregunta = gpImpClean((string)($row['pregunta'] ?? $row['question'] ?? ''));
        $altsRaw = $row['alternativas'] ?? $row['alternatives'] ?? $row['opciones'] ?? [];
        if (!is_array($altsRaw)) {
            continue;
        }

        $correctRaw = $row['correcta_index'] ?? $row['correct_index'] ?? $row['respuesta_correcta_index'] ?? null;
        $correct = is_numeric($correctRaw) ? (int)$correctRaw : null;
        $alternativas = [];
        foreach ($altsRaw as $altIdx => $alt) {
            $text = is_array($alt)
                ? gpImpClean((string)($alt['texto'] ?? $alt['alternativa'] ?? $alt['text'] ?? ''))
                : gpImpClean((string)$alt);
            if ($text === '') {
                continue;
            }
            $alternativas[] = [
                'letra' => chr(65 + count($alternativas)),
                'texto' => $text,
                'correcta' => $correct !== null && $correct === $altIdx,
            ];
        }

        $referencia = gpImpClean((string)($row['referencia'] ?? $row['reference'] ?? ''));
        if ($referencia === '') {
            $referencia = $fallbackReference . ' / IA pregunta ' . ($idx + 1);
        }

        $records[] = [
            'pregunta' => $pregunta,
            'alternativas' => $alternativas,
            'referencia' => $referencia,
        ];
    }

    return $records;
}

function gpAiPdfUploadFileWithPurpose(string $apiKey, string $pdfPath, string $purpose): string
{
    $ch = curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => [
            'purpose' => $purpose,
            'file' => new CURLFile($pdfPath, 'application/pdf', basename($pdfPath)),
        ],
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => GP_AI_PDF_UPLOAD_TIMEOUT_SECONDS,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Error subiendo PDF a OpenAI: ' . $curlError);
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException('OpenAI devolvio una respuesta no interpretable al subir el PDF.');
    }
    if ($httpCode >= 400) {
        $apiError = (string)($data['error']['message'] ?? 'Error HTTP ' . $httpCode);
        throw new RuntimeException($apiError);
    }

    $fileId = trim((string)($data['id'] ?? ''));
    if ($fileId === '') {
        throw new RuntimeException('OpenAI no devolvio file_id al subir el PDF.');
    }

    return $fileId;
}

function gpAiPdfUploadFile(string $apiKey, string $pdfPath): string
{
    foreach (['user_data', 'assistants'] as $purpose) {
        try {
            $fileId = gpAiPdfUploadFileWithPurpose($apiKey, $pdfPath, $purpose);
            if (function_exists('gpFuenteLog')) {
                gpFuenteLog('PDF subido a OpenAI Files', ['file_id' => $fileId, 'purpose' => $purpose]);
            }
            return $fileId;
        } catch (Throwable $e) {
            if (function_exists('gpFuenteLog')) {
                gpFuenteLog('Fallo subida PDF a OpenAI Files', ['purpose' => $purpose, 'error' => $e->getMessage()]);
            }
            if ($purpose === 'assistants') {
                throw new RuntimeException('No se pudo subir el PDF a OpenAI Files: ' . $e->getMessage());
            }
        }
    }

    throw new RuntimeException('No se pudo subir el PDF a OpenAI Files.');
}

function gpAiPdfRequestRangeDetailed(string $apiKey, string $model, string $fileId, array $context, int $from, int $to): array
{
    $payload = [
        'model' => $model,
        'input' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => gpAiPdfRangePrompt($context, $from, $to)],
                ['type' => 'input_file', 'file_id' => $fileId],
            ],
        ]],
        'temperature' => 0,
        'max_output_tokens' => GP_AI_PDF_BATCH_MAX_OUTPUT_TOKENS,
        'text' => ['format' => ['type' => 'json_object']],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => GP_AI_PDF_CURL_TIMEOUT_SECONDS,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Error de comunicacion con OpenAI en preguntas ' . $from . '-' . $to . ': ' . $curlError);
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        throw new RuntimeException('OpenAI devolvio una respuesta no interpretable en preguntas ' . $from . '-' . $to . '.');
    }
    if ($httpCode >= 400) {
        $apiError = (string)($data['error']['message'] ?? 'Error HTTP ' . $httpCode);
        throw new RuntimeException('OpenAI respondio con error en preguntas ' . $from . '-' . $to . ': ' . $apiError);
    }

    $text = gpAiPdfResponseText($data);
    if ($text === '') {
        throw new RuntimeException('OpenAI no devolvio contenido util en preguntas ' . $from . '-' . $to . '.');
    }

    $json = gpAiPdfExtractJson($text);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        throw new RuntimeException('OpenAI devolvio JSON invalido en preguntas ' . $from . '-' . $to . '.');
    }

    return [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'status' => (string)($data['status'] ?? ''),
        'incomplete_reason' => (string)($data['incomplete_details']['reason'] ?? ''),
        'text' => $text,
        'raw' => is_string($response) ? $response : '',
        'decoded' => $data,
        'records' => gpAiPdfNormalizeQuestions($payload, 'PDF preguntas ' . $from . '-' . $to),
    ];
}

function gpAiPdfRequestRange(string $apiKey, string $model, string $fileId, array $context, int $from, int $to): array
{
    $result = gpAiPdfRequestRangeDetailed($apiKey, $model, $fileId, $context, $from, $to);
    if ($result['status'] === 'incomplete') {
        $reason = $result['incomplete_reason'] !== '' ? $result['incomplete_reason'] : 'sin detalle';
        throw new RuntimeException('OpenAI dejo incompleta la tanda ' . $from . '-' . $to . ': ' . $reason);
    }

    return $result['records'];
}

function gpAiExtractQuestionsFromPdf(string $apiKey, string $model, string $pdfPath, array $context = []): array
{
    $result = gpAiExtractQuestionsFromPdfDetailed($apiKey, $model, $pdfPath, $context);
    return $result['records'];
}

function gpAiExtractQuestionsFromPdfDetailed(string $apiKey, string $model, string $pdfPath, array $context = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL no esta disponible en PHP.');
    }
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        throw new RuntimeException('No se pudo leer el PDF para enviarlo a OpenAI.');
    }

    $fileId = gpAiPdfUploadFile($apiKey, $pdfPath);

    $records = [];
    $warnings = [];
    $batches = [];
    $stoppedEarly = false;
    for ($from = 1; $from <= GP_AI_PDF_MAX_QUESTIONS_SCAN; $from += GP_AI_PDF_BATCH_SIZE) {
        $to = $from + GP_AI_PDF_BATCH_SIZE - 1;
        $startedAt = microtime(true);
        $batch = gpAiPdfRequestRangeDetailed($apiKey, $model, $fileId, $context, $from, $to);
        $batchRecords = $batch['records'] ?? [];
        $elapsed = microtime(true) - $startedAt;
        $batches[] = [
            'desde' => $from,
            'hasta' => $to,
            'http_code' => $batch['http_code'] ?? null,
            'status' => $batch['status'] ?? '',
            'incomplete_reason' => $batch['incomplete_reason'] ?? '',
            'preguntas' => count($batchRecords),
            'tiempo_seg' => round($elapsed, 3),
        ];
        if (function_exists('gpFuenteLog')) {
            gpFuenteLog('Tanda OpenAI PDF procesada', [
                'file_id' => $fileId,
                'desde' => $from,
                'hasta' => $to,
                'http_code' => $batch['http_code'] ?? null,
                'status' => $batch['status'] ?? '',
                'incomplete_reason' => $batch['incomplete_reason'] ?? '',
                'preguntas' => count($batchRecords),
                'tiempo_seg' => round($elapsed, 3),
            ]);
        }

        $records = array_merge($records, $batchRecords);

        if (($batch['status'] ?? '') === 'incomplete') {
            $reason = trim((string)($batch['incomplete_reason'] ?? ''));
            $warnings[] = 'Tanda ' . $from . '-' . $to . ' incompleta' . ($reason !== '' ? ' (' . $reason . ')' : '') . '.';
            $stoppedEarly = true;
            break;
        }
        if (!$batchRecords) {
            break;
        }
    }

    return [
        'file_id' => $fileId,
        'records' => $records,
        'warnings' => $warnings,
        'batches' => $batches,
        'stopped_early' => $stoppedEarly,
    ];
}
