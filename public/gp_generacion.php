<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/gp_workflow.php';

$openaiConfig = __DIR__ . '/../config/ceonext_ai.php';
if (is_file($openaiConfig)) {
    require_once $openaiConfig;
}

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN', 'CREADOR', 'REVISOR']);
$auth = gpAuth();
$msg = '';
$error = '';
$debugFile = '';
$debugError = '';
$debugExtract = '';
$currentGenerationId = (int)($_GET['generacion'] ?? 0);

const GP_AI_MAX_SYNC_QUESTIONS = 20;
const GP_AI_CURL_TIMEOUT_SECONDS = 60;
const GP_AI_SOURCE_MAX_CHARS = 8000;
const GP_AI_GENERATION_BATCH_SIZE = 5;

function gpGeneracionNewDb(): PDO
{
    return new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function gpGeneracionLogDir(): string
{
    return dirname(__DIR__) . '/storage/gestor_preguntas/logs';
}

function gpGeneracionLogPath(): string
{
    return gpGeneracionLogDir() . '/gp_generacion_error.log';
}

function gpGeneracionLog(string $message, array $context = []): void
{
    $dir = gpGeneracionLogDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    @error_log($line . PHP_EOL, 3, gpGeneracionLogPath());
}

function gpGeneracionRegisterShutdownLog(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        gpGeneracionLog('Fatal en gp_generacion.php', [
            'type' => (int)$error['type'],
            'message' => (string)$error['message'],
            'file' => (string)$error['file'],
            'line' => (int)$error['line'],
            'accion' => (string)($_POST['accion'] ?? ''),
            'id_fuente' => (int)($_POST['id_fuente'] ?? 0),
            'cantidad' => (int)($_POST['cantidad'] ?? 0),
            'id_generacion' => (int)($_POST['id_generacion'] ?? 0),
        ]);
    });
}

function gpAiApiKey(): string
{
    $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    if ($key !== '') {
        return $key;
    }

    $localKey = defined('OPENAI_API_KEY_LOCAL')
        ? trim((string)constant('OPENAI_API_KEY_LOCAL'))
        : '';

    return $localKey !== 'TU_API_KEY_AQUI' ? $localKey : '';
}

function gpAiModel(): string
{
    $model = trim((string)(getenv('OPENAI_MODEL') ?: ''));
    if ($model === '' && defined('OPENAI_MODEL_LOCAL')) {
        $model = trim((string)constant('OPENAI_MODEL_LOCAL'));
    }

    return $model !== '' ? $model : 'gpt-4.1-mini';
}

function gpAiExtractJson(string $text): string
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

    $objectStart = strpos($text, '{');
    $objectEnd = strrpos($text, '}');
    if ($objectStart !== false && $objectEnd !== false && $objectEnd > $objectStart) {
        $candidate = substr($text, $objectStart, $objectEnd - $objectStart + 1);
        json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $candidate;
        }
    }

    $arrayStart = strpos($text, '[');
    $arrayEnd = strrpos($text, ']');
    if ($arrayStart !== false && $arrayEnd !== false && $arrayEnd > $arrayStart) {
        $candidate = substr($text, $arrayStart, $arrayEnd - $arrayStart + 1);
        json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $candidate;
        }
    }

    return $text;
}

function gpArrayIsList(array $array): bool
{
    $expected = 0;
    foreach ($array as $key => $_value) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function gpAiRepairJson(string $json): string
{
    // Reparacion conservadora para respuestas casi validas: comas finales antes de ] o }.
    return preg_replace('/,\s*([\]}])/', '$1', $json) ?? $json;
}

function gpAiDecodeQuestions(string $jsonText): array
{
    $extractedJson = gpAiExtractJson($jsonText);
    $decoded = json_decode($extractedJson, true);
    if (!is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
        $decoded = json_decode(gpAiRepairJson($extractedJson), true);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('La respuesta IA no tiene formato JSON valido.');
    }

    if ($decoded === []) {
        throw new RuntimeException('La IA devolvio una lista vacia. La fuente no contiene texto suficiente para generar preguntas sin inventar contenido.');
    }

    foreach (['preguntas', 'items', 'questions', 'data', 'resultado', 'resultados', 'borradores', 'preguntas_generadas', 'generadas', 'quiz', 'evaluacion'] as $listKey) {
        if (isset($decoded[$listKey]) && is_array($decoded[$listKey])) {
            $decoded = $decoded[$listKey];
            break;
        }
    }

    $questionKeys = ['pregunta', 'question', 'enunciado', 'texto_pregunta', 'texto'];
    $hasQuestionKey = static function (array $row) use ($questionKeys): bool {
        foreach ($questionKeys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return true;
            }
        }
        return false;
    };

    if ($hasQuestionKey($decoded)) {
        $decoded = [$decoded];
    } elseif (gpArrayIsList($decoded) === false) {
        $candidates = [];
        foreach ($decoded as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($hasQuestionKey($value)) {
                $candidates[] = $value;
                continue;
            }
            foreach ($value as $nested) {
                if (is_array($nested) && $hasQuestionKey($nested)) {
                    $candidates[] = $nested;
                }
            }
        }
        if ($candidates !== []) {
            $decoded = $candidates;
        }
    }

    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $question = trim((string)($row['pregunta'] ?? $row['question'] ?? $row['enunciado'] ?? $row['texto_pregunta'] ?? $row['texto'] ?? ''));
        $alternatives = $row['alternativas'] ?? [];
        if (!is_array($alternatives)) {
            $alternatives = $row['opciones'] ?? $row['respuestas'] ?? $row['options'] ?? [];
        }
        if (!is_array($alternatives)) {
            $alternatives = [];
        }

        $correct = -1;
        foreach (['correcta_index', 'correct_index'] as $correctKey) {
            if (isset($row[$correctKey]) && is_numeric($row[$correctKey])) {
                $correct = (int)$row[$correctKey];
                break;
            }
        }
        if ($correct < 0) {
            foreach (['indice_correcta', 'respuesta_correcta_index', 'numero_correcta'] as $correctKey) {
                if (isset($row[$correctKey]) && is_numeric($row[$correctKey])) {
                    $correct = (int)$row[$correctKey];
                    if ($correct >= 1 && $correct <= 4) {
                        $correct--;
                    }
                    break;
                }
            }
        }
        if ($correct < 0) {
            foreach (['correcta', 'respuesta_correcta'] as $correctKey) {
                if (isset($row[$correctKey]) && is_numeric($row[$correctKey])) {
                    $correct = (int)$row[$correctKey];
                    if ($correct >= 1 && $correct <= 4) {
                        $correct--;
                    }
                    break;
                }
            }
        }
        $correctRaw = trim((string)($row['respuesta_correcta'] ?? $row['correcta'] ?? $row['correct_answer'] ?? $row['answer'] ?? ''));
        if ($correct < 0 && $correctRaw !== '') {
            $letter = strtoupper(substr($correctRaw, 0, 1));
            if (in_array($letter, ['A', 'B', 'C', 'D'], true)) {
                $correct = ord($letter) - ord('A');
            }
        }
        if ($correct < 0) {
            foreach (['correcta_letra', 'letra_correcta'] as $correctKey) {
                if (isset($row[$correctKey]) && trim((string)$row[$correctKey]) !== '') {
                    $letter = strtoupper(substr(trim((string)$row[$correctKey]), 0, 1));
                    if (in_array($letter, ['A', 'B', 'C', 'D'], true)) {
                        $correct = ord($letter) - ord('A');
                    }
                    break;
                }
            }
        }
        if ($correct > 3 && $correct <= 4) {
            $correct--;
        }

        if ($alternatives === []) {
            $letterAlternatives = [];
            foreach (['A', 'B', 'C', 'D', 'a', 'b', 'c', 'd'] as $letterKey) {
                if (array_key_exists($letterKey, $row)) {
                    $letterAlternatives[] = $row[$letterKey];
                }
            }
            if (count($letterAlternatives) >= 4) {
                $alternatives = array_slice($letterAlternatives, 0, 4);
            }
        }

        if (gpArrayIsList($alternatives) === false) {
            $ordered = [];
            foreach (['A', 'B', 'C', 'D', 'a', 'b', 'c', 'd', '1', '2', '3', '4'] as $altKey) {
                if (array_key_exists($altKey, $alternatives)) {
                    $ordered[] = $alternatives[$altKey];
                }
            }
            if (count($ordered) >= 4) {
                $alternatives = array_slice($ordered, 0, 4);
            }
        }

        if ($question === '' || count($alternatives) < 4) {
            continue;
        }

        if (count($alternatives) > 4) {
            $alternatives = array_slice($alternatives, 0, 4);
        }

        $alts = [];
        foreach ($alternatives as $idx => $alt) {
            if (is_array($alt)) {
                $alts[] = trim((string)($alt['texto'] ?? $alt['alternativa'] ?? $alt['respuesta'] ?? $alt['opcion'] ?? $alt['text'] ?? $alt['label'] ?? ''));

                $isCorrect = $alt['correcta'] ?? $alt['correct'] ?? $alt['es_correcta'] ?? null;
                if ($correct < 0 && ($isCorrect === true || $isCorrect === 1 || $isCorrect === '1' || strtoupper((string)$isCorrect) === 'S' || strtoupper((string)$isCorrect) === 'SI' || strtoupper((string)$isCorrect) === 'TRUE')) {
                    $correct = (int)$idx;
                }
            } else {
                $alts[] = trim((string)$alt);
            }
        }
        if ($correct < 0 && $correctRaw !== '') {
            foreach ($alts as $idx => $altText) {
                if (strcasecmp($altText, $correctRaw) === 0) {
                    $correct = (int)$idx;
                    break;
                }
            }
        }
        if (count(array_filter($alts, static fn($v) => $v !== '')) !== 4 || $correct < 0 || $correct > 3) {
            continue;
        }

        $items[] = [
            'pregunta' => $question,
            'alternativas' => $alts,
            'correcta_index' => $correct,
            'retropos' => trim((string)($row['retro_correcta'] ?? $row['retropos'] ?? $row['retroalimentacion_correcta'] ?? '')),
            'retroneg' => trim((string)($row['retro_incorrecta'] ?? $row['retroneg'] ?? $row['retroalimentacion_incorrecta'] ?? '')),
            'referencia' => trim((string)($row['referencia'] ?? '')),
        ];
    }

    if ($items === []) {
        throw new RuntimeException('La IA respondio JSON valido, pero no se pudo reconocer la estructura de preguntas.');
    }

    return $items;
}

function gpAiOpenAiGenerate(string $apiKey, string $model, string $prompt): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extension cURL no esta disponible en PHP.');
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Responde solo con JSON valido, sin markdown ni texto adicional.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.25,
        'max_tokens' => 3200,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => GP_AI_CURL_TIMEOUT_SECONDS,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    gpGeneracionLog('Respuesta OpenAI recibida', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'prompt_chars' => mb_strlen($prompt, 'UTF-8'),
        'response_bytes' => is_string($response) ? strlen($response) : 0,
    ]);

    if ($response === false || $curlError !== '') {
                throw new RuntimeException('Error de comunicacion con OpenAI: ' . $curlError . '. Si solicitaste muchas preguntas, intenta una tanda menor.');
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        $apiError = (string)($data['error']['message'] ?? 'Error HTTP ' . $httpCode);
        throw new RuntimeException('OpenAI respondio con error: ' . $apiError);
    }

    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        throw new RuntimeException('OpenAI no devolvio contenido util.');
    }

    return $content;
}

function gpAiBuildPrompt(string $sourceText, array $context): string
{
    $sourceText = trim(mb_substr($sourceText, 0, GP_AI_SOURCE_MAX_CHARS));

    return "Eres un generador de preguntas para CEONext.\n"
        . "Debes crear preguntas SOLO usando la fuente entregada.\n"
        . "No inventes datos no presentes en la fuente.\n"
        . "Devuelve exclusivamente un objeto JSON valido, sin markdown, sin comentarios y sin explicaciones extra.\n"
        . "Formato exacto esperado: {\"preguntas\":[{\"pregunta\":\"...\",\"alternativas\":[\"...\",\"...\",\"...\",\"...\"],\"correcta_index\":0,\"retro_correcta\":\"...\",\"retro_incorrecta\":\"...\",\"referencia\":\"...\"}]}\n"
        . "Reglas obligatorias:\n"
        . "- exactamente {$context['cantidad']} preguntas si la fuente lo permite\n"
        . "- JSON estricto: no uses comas finales antes de ] o }\n"
        . "- no devuelvas un array raiz; la raiz debe ser un objeto con la clave preguntas\n"
        . "- cada pregunta debe tener exactamente 4 alternativas\n"
        . "- correcta_index debe ser 0, 1, 2 o 3\n"
        . "- solo una alternativa correcta\n"
        . "- pregunta, alternativas, retro_correcta, retro_incorrecta y referencia deben ser breves\n"
        . "- referencia debe citar brevemente el fragmento, titulo o seccion usada\n"
        . "- evita preguntas duplicadas, ambiguas o de memoria trivial si el nivel no corresponde\n"
        . "- no incluyas texto extenso; prioriza concision para responder rapido\n"
        . "Contexto CEONext:\n"
        . "Destino: {$context['destino']}\n"
        . "Servicio: {$context['servicio']}\n"
        . "Agrupacion o prueba: {$context['agrupacion']}\n"
        . "Area de competencia: {$context['area']}\n"
        . "Cantidad solicitada: {$context['cantidad']}\n"
        . "Nivel de complejidad: {$context['dificultad']}\n\n"
        . "Fuente:\n"
        . $sourceText;
}

function gpAiGenerateQuestionsBatched(string $apiKey, string $model, string $sourceText, array $context): array
{
    $items = [];
    $rawBatches = [];
    $prompts = [];

    $requested = max(1, (int)($context['cantidad'] ?? 1));
    $advancedCount = (int)ceil($requested / 2);
    $mediumCount = $requested - $advancedCount;
    $plans = [];
    if ($mediumCount > 0) {
        $plans[] = ['dificultad' => 'MEDIA', 'cantidad' => $mediumCount];
    }
    if ($advancedCount > 0) {
        $plans[] = ['dificultad' => 'AVANZADA', 'cantidad' => $advancedCount];
    }

    foreach ($plans as $plan) {
        $remaining = (int)$plan['cantidad'];
        $batchNumber = 0;
        while ($remaining > 0) {
            $batchNumber++;
            $batchCount = min(GP_AI_GENERATION_BATCH_SIZE, $remaining);
            $batchContext = $context;
            $batchContext['cantidad'] = (string)$batchCount;
            $batchContext['dificultad'] = (string)$plan['dificultad'];
            $startedAt = microtime(true);
            gpGeneracionLog('Inicio tanda OpenAI', [
                'dificultad' => $plan['dificultad'],
                'batch_number' => $batchNumber,
                'cantidad_solicitada' => $batchCount,
                'remaining_before' => $remaining,
            ]);
            $prompt = gpAiBuildPrompt($sourceText, $batchContext);
            try {
                $raw = gpAiOpenAiGenerate($apiKey, $model, $prompt);
                $decodedItems = gpAiDecodeQuestions($raw);
            } catch (Throwable $e) {
                gpGeneracionLog('Error en tanda OpenAI', [
                    'dificultad' => $plan['dificultad'],
                    'batch_number' => $batchNumber,
                    'cantidad_solicitada' => $batchCount,
                    'elapsed_sec' => round(microtime(true) - $startedAt, 3),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
            if (count($decodedItems) > $batchCount) {
                $decodedItems = array_slice($decodedItems, 0, $batchCount);
            }

            $items = array_merge($items, $decodedItems);
            $rawBatches[] = [
                'dificultad' => $plan['dificultad'],
                'cantidad_solicitada' => $batchCount,
                'raw' => $raw,
            ];
            $prompts[] = [
                'dificultad' => $plan['dificultad'],
                'cantidad_solicitada' => $batchCount,
                'prompt' => $prompt,
            ];
            gpGeneracionLog('Fin tanda OpenAI', [
                'dificultad' => $plan['dificultad'],
                'batch_number' => $batchNumber,
                'cantidad_solicitada' => $batchCount,
                'preguntas_recibidas' => count($decodedItems),
                'elapsed_sec' => round(microtime(true) - $startedAt, 3),
            ]);
            $remaining -= count($decodedItems);
            if (!$decodedItems) {
                break;
            }
        }
    }

    if (count($items) > $requested) {
        $items = array_slice($items, 0, $requested);
    }

    if (!$items) {
        throw new RuntimeException('La IA no pudo generar preguntas utiles para la fuente seleccionada.');
    }

    return [
        'items' => $items,
        'raw' => $rawBatches,
        'prompts' => $prompts,
    ];
}

function gpCatalogFor(PDO $pdo, string $destino): array
{
    if ($destino === 'FORMACION') {
        return [
            'agrupaciones' => $pdo->query('SELECT id, titulo, id_servicio FROM ceo_formacion_agrupacion ORDER BY titulo ASC')->fetchAll(PDO::FETCH_ASSOC),
            'areas' => $pdo->query('SELECT MIN(id) AS id, descripcion, id_servicio FROM ceo_areacompetencia_formacion GROUP BY descripcion, id_servicio ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    return [
        'agrupaciones' => $pdo->query('SELECT id, titulo, id_servicio FROM ceo_agrupacion ORDER BY titulo ASC')->fetchAll(PDO::FETCH_ASSOC),
        'areas' => $pdo->query('SELECT id, descripcion, id_servicio FROM ceo_areacompetencias ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function gpLookupName(PDO $pdo, string $destino, string $type, int $id): string
{
    if ($id <= 0) {
        return '';
    }
    if ($destino === 'FORMACION') {
        $sql = $type === 'servicio'
            ? 'SELECT servicio FROM ceo_formacion_servicios WHERE id = :id LIMIT 1'
            : ($type === 'agrupacion'
                ? 'SELECT titulo FROM ceo_formacion_agrupacion WHERE id = :id LIMIT 1'
                : 'SELECT descripcion FROM ceo_areacompetencia_formacion WHERE id = :id LIMIT 1');
    } else {
        $sql = $type === 'servicio'
            ? 'SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1'
            : ($type === 'agrupacion'
                ? 'SELECT titulo FROM ceo_agrupacion WHERE id = :id LIMIT 1'
                : 'SELECT descripcion FROM ceo_areacompetencias WHERE id = :id LIMIT 1');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    return (string)$stmt->fetchColumn();
}

function gpAiSaveDebug(string $raw, string $prompt, string $errorMessage): array
{
    $extracted = gpAiExtractJson($raw);
    json_decode($raw, true);
    $rawJsonError = json_last_error_msg();
    json_decode($extracted, true);
    $extractedJsonError = json_last_error_msg();
    $repaired = gpAiRepairJson($extracted);
    json_decode($repaired, true);
    $repairedJsonError = json_last_error_msg();
    $dirs = [
        dirname(__DIR__) . '/storage/gestor_preguntas/debug' => 'storage/gestor_preguntas/debug',
        dirname(__DIR__) . '/storage/gp_ai_debug' => 'storage/gp_ai_debug',
    ];

    $fileName = 'gp_ai_debug_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $payload = [
        'fecha' => date('Y-m-d H:i:s'),
        'error' => $errorMessage,
        'raw' => $raw,
        'extracted' => $extracted,
        'repaired' => $repaired,
        'json_error_raw' => $rawJsonError,
        'json_error_extracted' => $extractedJsonError,
        'json_error_repaired' => $repairedJsonError,
        'prompt' => $prompt,
    ];
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($jsonPayload === false) {
        return [
            'file' => '',
            'error' => 'No se pudo serializar el debug: ' . json_last_error_msg(),
            'extracted' => $extracted,
        ];
    }

    $errors = [];
    foreach ($dirs as $dir => $relativeDir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $lastError = error_get_last();
            $errors[] = $relativeDir . ': no se pudo crear directorio' . (!empty($lastError['message']) ? ' (' . $lastError['message'] . ')' : '');
            continue;
        }

        $path = $dir . '/' . $fileName;
        $written = @file_put_contents($path, $jsonPayload);
        if ($written !== false) {
            return [
                'file' => $relativeDir . '/' . $fileName,
                'error' => '',
                'extracted' => $extracted,
            ];
        }

        $lastError = error_get_last();
        $errors[] = $relativeDir . ': no se pudo escribir archivo' . (!empty($lastError['message']) ? ' (' . $lastError['message'] . ')' : '');
    }

    return [
        'file' => '',
        'error' => implode(' | ', $errors),
        'extracted' => $extracted,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpGeneracionRegisterShutdownLog();
    if (function_exists('set_time_limit')) {
        @set_time_limit(240);
    }
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        $idFuente = 0;
        $cantidad = 0;
        $dificultad = 'MEDIA';
        $destino = '';
        $idServicio = 0;
        $idAgrupacion = 0;
        $idArea = 0;
        $prompt = '';
        $raw = '';
        $rawBatches = [];
        $promptBatches = [];
        $model = gpAiModel();
        try {
            $accion = (string)($_POST['accion'] ?? '');
            if (!in_array($accion, ['generar', 'enviar_revision'], true)) {
                throw new RuntimeException('Accion invalida.');
            }

            if ($accion === 'enviar_revision') {
                gpGeneracionLog('Inicio envio generacion a revision', [
                    'id_generacion' => (int)($_POST['id_generacion'] ?? 0),
                ]);
                $idGeneracion = (int)($_POST['id_generacion'] ?? 0);
                if ($idGeneracion <= 0) {
                    throw new RuntimeException('Debes seleccionar una generacion valida.');
                }
                $stmtIds = $pdo->prepare('SELECT id FROM ceo_gp_preguntas WHERE id_generacion = :id_generacion AND estado = "BORRADOR" ORDER BY id ASC');
                $stmtIds->execute([':id_generacion' => $idGeneracion]);
                $ids = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if (!$ids) {
                    throw new RuntimeException('La generacion seleccionada no tiene preguntas en BORRADOR para enviar a REVISION.');
                }
                $result = gpWorkflowTransitionQuestions($pdo, $ids, ['BORRADOR'], 'REVISION', 'Generacion enviada a REVISION desde gp_generacion.php', (int)($auth['id'] ?? 0), false);
                if ((int)$result['moved'] <= 0) {
                    throw new RuntimeException(!empty($result['warnings']) ? implode(' ', $result['warnings']) : 'No se pudieron mover preguntas a REVISION.');
                }
                $currentGenerationId = $idGeneracion;
                $msg = 'Generacion enviada a REVISION: ' . (int)$result['moved'] . ' preguntas.';
                gpGeneracionLog('Fin envio generacion a revision', [
                    'id_generacion' => $idGeneracion,
                    'moved' => (int)$result['moved'],
                    'warnings' => $result['warnings'] ?? [],
                ]);
            } else {

                $idFuente = (int)($_POST['id_fuente'] ?? 0);
                $cantidad = (int)($_POST['cantidad'] ?? 0);
                $dificultad = 'MEDIA_AVANZADA';
                $idAgrupacionPost = (int)($_POST['id_agrupacion'] ?? 0);
                $idAreaPost = (int)($_POST['id_area'] ?? 0);

                if ($idFuente <= 0) {
                    throw new RuntimeException('Debes seleccionar una fuente.');
                }
                if ($cantidad < 1) {
                    throw new RuntimeException('La cantidad debe ser al menos 1 pregunta por generacion.');
                }

                $stmtFuente = $pdo->prepare('SELECT * FROM ceo_gp_fuentes WHERE id = :id AND estado = "ACTIVA" LIMIT 1');
                $stmtFuente->execute([':id' => $idFuente]);
                $fuente = $stmtFuente->fetch(PDO::FETCH_ASSOC);
                if (!$fuente) {
                    throw new RuntimeException('La fuente seleccionada no existe o no esta activa.');
                }
                if (trim((string)$fuente['texto_fuente']) === '') {
                    throw new RuntimeException('La fuente seleccionada no tiene texto utilizable para IA.');
                }

                $textoFuente = trim((string)$fuente['texto_fuente']);
                if (mb_strlen($textoFuente) < 120) {
                    throw new RuntimeException('La fuente seleccionada tiene muy poco texto para generar preguntas confiables. Agrega contenido tecnico del documento o selecciona una fuente con mas texto.');
                }

                $destino = (string)$fuente['destino'];
                $idServicio = (int)$fuente['id_servicio'];
                $idAgrupacion = (int)($fuente['id_agrupacion'] ?? 0) ?: $idAgrupacionPost;
                $idArea = (int)($fuente['id_area'] ?? 0) ?: $idAreaPost;

                if (!in_array($destino, ['HABILITACION', 'FORMACION'], true) || $idServicio <= 0) {
                    throw new RuntimeException('La fuente tiene contexto invalido.');
                }
                if ($idAgrupacion <= 0) {
                    throw new RuntimeException('Debes seleccionar agrupacion/prueba para esta generacion.');
                }
                if ($idArea <= 0) {
                    throw new RuntimeException('Debes seleccionar un area de competencia concreta para esta generacion.');
                }

                $servicioTxt = gpLookupName($pdo, $destino, 'servicio', $idServicio);
                $agrupacionTxt = gpLookupName($pdo, $destino, 'agrupacion', $idAgrupacion);
                $areaTxt = gpLookupName($pdo, $destino, 'area', $idArea);
                if ($servicioTxt === '' || $agrupacionTxt === '' || $areaTxt === '') {
                    throw new RuntimeException('No fue posible resolver servicio, agrupacion o area.');
                }

                $apiKey = gpAiApiKey();
                if ($apiKey === '') {
                    throw new RuntimeException('No existe OPENAI_API_KEY en el entorno del servidor.');
                }

                $generationContext = [
                    'destino' => $destino,
                    'servicio' => $servicioTxt,
                    'agrupacion' => $agrupacionTxt,
                    'area' => $areaTxt,
                    'cantidad' => (string)$cantidad,
                    'dificultad' => $dificultad,
                ];
                gpGeneracionLog('Inicio generacion IA', [
                    'id_fuente' => $idFuente,
                    'cantidad' => $cantidad,
                    'modelo' => $model,
                    'destino' => $destino,
                    'id_servicio' => $idServicio,
                    'id_agrupacion' => $idAgrupacion,
                    'id_area' => $idArea,
                    'source_chars' => mb_strlen($textoFuente, 'UTF-8'),
                ]);
                $batchResult = gpAiGenerateQuestionsBatched($apiKey, $model, $textoFuente, $generationContext);
                $items = $batchResult['items'];
                $rawBatches = $batchResult['raw'];
                $promptBatches = $batchResult['prompts'];
                $promptJson = json_encode($promptBatches ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $rawJson = json_encode($rawBatches ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $prompt = is_string($promptJson) ? $promptJson : '';
                $raw = is_string($rawJson) ? $rawJson : '';

                // OpenAI puede dejar la conexion MySQL inactiva el tiempo suficiente para que el servidor la cierre.
                $pdo = gpGeneracionNewDb();

                $pdo->beginTransaction();
                $stmtGen = $pdo->prepare('INSERT INTO ceo_gp_generaciones (id_fuente, destino, id_servicio, id_agrupacion, id_area, cantidad_solicitada, dificultad, modelo, prompt_text, respuesta_json, estado, creado_por) VALUES (:id_fuente, :destino, :id_servicio, :id_agrupacion, :id_area, :cantidad, :dificultad, :modelo, :prompt_text, :respuesta_json, "GENERADA", :creado_por)');
                $stmtGen->execute([
                    ':id_fuente' => $idFuente,
                    ':destino' => $destino,
                    ':id_servicio' => $idServicio,
                    ':id_agrupacion' => $idAgrupacion,
                    ':id_area' => $idArea,
                    ':cantidad' => $cantidad,
                    ':dificultad' => $dificultad,
                    ':modelo' => $model,
                    ':prompt_text' => $prompt,
                    ':respuesta_json' => json_encode(['preguntas' => $items, 'batches' => $rawBatches], JSON_UNESCAPED_UNICODE),
                    ':creado_por' => (int)($auth['id'] ?? 0) ?: null,
                ]);
                $currentGenerationId = (int)$pdo->lastInsertId();

                $stmtQuestion = $pdo->prepare('INSERT INTO ceo_gp_preguntas (id_fuente, id_generacion, destino, id_servicio, id_agrupacion, id_area, pregunta, retropos, retroneg, referencia, origen, estado, creado_por) VALUES (:id_fuente, :id_generacion, :destino, :id_servicio, :id_agrupacion, :id_area, :pregunta, :retropos, :retroneg, :referencia, "IA", "BORRADOR", :creado_por)');
                $stmtAlt = $pdo->prepare('INSERT INTO ceo_gp_alternativas (id_pregunta, orden, alternativa, correcta, estado) VALUES (:id_pregunta, :orden, :alternativa, :correcta, "A")');

                foreach ($items as $item) {
                    $stmtQuestion->execute([
                        ':id_fuente' => $idFuente,
                        ':id_generacion' => $currentGenerationId,
                        ':destino' => $destino,
                        ':id_servicio' => $idServicio,
                        ':id_agrupacion' => $idAgrupacion,
                        ':id_area' => $idArea,
                        ':pregunta' => $item['pregunta'],
                        ':retropos' => $item['retropos'],
                        ':retroneg' => $item['retroneg'],
                        ':referencia' => $item['referencia'],
                        ':creado_por' => (int)($auth['id'] ?? 0) ?: null,
                    ]);
                    $idPregunta = (int)$pdo->lastInsertId();
                    foreach ($item['alternativas'] as $idx => $alt) {
                        $stmtAlt->execute([
                            ':id_pregunta' => $idPregunta,
                            ':orden' => $idx + 1,
                            ':alternativa' => $alt,
                            ':correcta' => $idx === (int)$item['correcta_index'] ? 'S' : 'N',
                        ]);
                    }
                }

                $pdo->commit();
                $msg = 'Generacion creada en BORRADOR: ' . count($items) . ' preguntas en ' . count($rawBatches) . ' tanda(s).';
                gpGeneracionLog('Fin generacion IA', [
                    'id_fuente' => $idFuente,
                    'id_generacion' => $currentGenerationId,
                    'preguntas' => count($items),
                    'tandas' => count($rawBatches),
                ]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
            gpGeneracionLog('Excepcion en gp_generacion.php', [
                'accion' => $accion ?? '',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'id_fuente' => $idFuente,
                'cantidad' => $cantidad,
                'id_generacion' => $currentGenerationId,
            ]);
            if ($raw !== '') {
                $debugInfo = gpAiSaveDebug($raw, $prompt, $error);
                $debugFile = (string)($debugInfo['file'] ?? '');
                $debugError = (string)($debugInfo['error'] ?? '');
                $debugExtract = (string)($debugInfo['extracted'] ?? '');
                try {
                    if ($idFuente > 0) {
                        $stmtErrorGen = $pdo->prepare('INSERT INTO ceo_gp_generaciones (id_fuente, destino, id_servicio, id_agrupacion, id_area, cantidad_solicitada, dificultad, modelo, prompt_text, respuesta_json, estado, error_text, creado_por) VALUES (:id_fuente, :destino, :id_servicio, :id_agrupacion, :id_area, :cantidad, :dificultad, :modelo, :prompt_text, :respuesta_json, "ERROR", :error_text, :creado_por)');
                        $stmtErrorGen->execute([
                            ':id_fuente' => $idFuente,
                            ':destino' => in_array($destino, ['HABILITACION', 'FORMACION'], true) ? $destino : null,
                            ':id_servicio' => $idServicio > 0 ? $idServicio : null,
                            ':id_agrupacion' => $idAgrupacion > 0 ? $idAgrupacion : null,
                            ':id_area' => $idArea > 0 ? $idArea : null,
                            ':cantidad' => $cantidad > 0 ? $cantidad : 1,
                            ':dificultad' => $dificultad,
                            ':modelo' => $model,
                            ':prompt_text' => $prompt !== '' ? $prompt : null,
                            ':respuesta_json' => $raw !== '' ? $raw : null,
                            ':error_text' => $debugFile !== '' ? $error . ' | Debug: ' . $debugFile : $error,
                            ':creado_por' => (int)($auth['id'] ?? 0) ?: null,
                        ]);
                        $currentGenerationId = (int)$pdo->lastInsertId();
                    }
                } catch (Throwable $ignored) {
                    // El archivo de debug es suficiente si no se pudo registrar la generacion fallida.
                }
            }

            try {
                $pdo = gpGeneracionNewDb();
            } catch (Throwable $ignored) {
                // Si incluso la reconexion falla, el render posterior seguira devolviendo el error original.
            }
        }
    }
}

// Tras un POST largo, renderizamos siempre con una conexion fresca.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = gpGeneracionNewDb();
    } catch (Throwable $ignored) {
        // Mantener la referencia actual es mejor que ocultar el error original.
    }
}

$catalogs = [
    'HABILITACION' => gpCatalogFor($pdo, 'HABILITACION'),
    'FORMACION' => gpCatalogFor($pdo, 'FORMACION'),
];

$filterServices = $pdo->query("SELECT 'HABILITACION' AS destino, id, servicio FROM ceo_servicios_pruebas
    UNION ALL
    SELECT 'FORMACION' AS destino, id, servicio FROM ceo_formacion_servicios
    ORDER BY destino ASC, servicio ASC")->fetchAll(PDO::FETCH_ASSOC);

$filterGroups = $pdo->query("SELECT 'HABILITACION' AS destino, id, titulo, id_servicio FROM ceo_agrupacion
    UNION ALL
    SELECT 'FORMACION' AS destino, id, titulo, id_servicio FROM ceo_formacion_agrupacion
    ORDER BY destino ASC, titulo ASC")->fetchAll(PDO::FETCH_ASSOC);

$filterAreas = $pdo->query("SELECT 'HABILITACION' AS destino, id, descripcion, id_servicio FROM ceo_areacompetencias
    UNION ALL
    SELECT 'FORMACION' AS destino, MIN(id) AS id, descripcion, id_servicio FROM ceo_areacompetencia_formacion GROUP BY descripcion, id_servicio
    ORDER BY destino ASC, descripcion ASC")->fetchAll(PDO::FETCH_ASSOC);

$sources = $pdo->query("SELECT f.*,
        CASE WHEN f.destino = 'FORMACION'
             THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = f.id_servicio LIMIT 1)
             ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = f.id_servicio LIMIT 1)
        END AS servicio,
        CASE WHEN f.destino = 'FORMACION'
             THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = f.id_agrupacion LIMIT 1)
             ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = f.id_agrupacion LIMIT 1)
        END AS agrupacion,
        CASE WHEN f.destino = 'FORMACION'
             THEN (SELECT acf.descripcion FROM ceo_areacompetencia_formacion acf WHERE acf.id = f.id_area LIMIT 1)
             ELSE (SELECT ac.descripcion FROM ceo_areacompetencias ac WHERE ac.id = f.id_area LIMIT 1)
        END AS area,
        CHAR_LENGTH(f.texto_fuente) AS chars_fuente
    FROM ceo_gp_fuentes f
    WHERE f.estado = 'ACTIVA'
      AND COALESCE(f.modo_uso, 'IA') = 'IA'
      AND CHAR_LENGTH(f.texto_fuente) > 0
    ORDER BY f.id DESC
    LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$generationFilters = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'fuente' => trim((string)($_GET['fuente'] ?? '')),
    'destino' => trim((string)($_GET['destino'] ?? '')),
    'servicio_key' => trim((string)($_GET['servicio_key'] ?? '')),
    'id_servicio' => 0,
    'servicio_destino' => '',
    'estado' => trim((string)($_GET['estado'] ?? '')),
    'agrupacion_key' => trim((string)($_GET['agrupacion_key'] ?? '')),
    'id_agrupacion' => 0,
    'agrupacion_destino' => '',
    'area_key' => trim((string)($_GET['area_key'] ?? '')),
    'id_area' => 0,
    'area_destino' => '',
];

foreach (['servicio', 'agrupacion', 'area'] as $filterType) {
    $keyName = $filterType . '_key';
    if (preg_match('/^(HABILITACION|FORMACION):(\d+)$/', $generationFilters[$keyName], $matches)) {
        $generationFilters['id_' . $filterType] = (int)$matches[2];
        $generationFilters[$filterType . '_destino'] = $matches[1];
    } else {
        $generationFilters[$keyName] = '';
    }
}

$whereGen = [];
$paramsGen = [];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $generationFilters['fecha_desde'])) {
    $whereGen[] = 'g.fecha_creacion >= :fecha_desde';
    $paramsGen[':fecha_desde'] = $generationFilters['fecha_desde'] . ' 00:00:00';
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $generationFilters['fecha_hasta'])) {
    $whereGen[] = 'g.fecha_creacion < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)';
    $paramsGen[':fecha_hasta'] = $generationFilters['fecha_hasta'];
}

if ($generationFilters['fuente'] !== '') {
    $whereGen[] = 'f.titulo LIKE :fuente';
    $paramsGen[':fuente'] = '%' . $generationFilters['fuente'] . '%';
}

if (in_array($generationFilters['destino'], ['HABILITACION', 'FORMACION'], true)) {
    $whereGen[] = 'COALESCE(g.destino, f.destino) = :destino';
    $paramsGen[':destino'] = $generationFilters['destino'];
} else {
    $generationFilters['destino'] = '';
}

if ($generationFilters['id_servicio'] > 0) {
    $whereGen[] = 'COALESCE(g.destino, f.destino) = :servicio_destino';
    $whereGen[] = 'COALESCE(g.id_servicio, f.id_servicio) = :id_servicio';
    $paramsGen[':servicio_destino'] = $generationFilters['servicio_destino'];
    $paramsGen[':id_servicio'] = $generationFilters['id_servicio'];
}

if (in_array($generationFilters['estado'], ['GENERADA', 'ERROR'], true)) {
    $whereGen[] = 'g.estado = :estado';
    $paramsGen[':estado'] = $generationFilters['estado'];
} else {
    $generationFilters['estado'] = '';
}

if ($generationFilters['id_agrupacion'] > 0) {
    $whereGen[] = 'COALESCE(g.destino, f.destino) = :agrupacion_destino';
    $whereGen[] = 'g.id_agrupacion = :id_agrupacion';
    $paramsGen[':agrupacion_destino'] = $generationFilters['agrupacion_destino'];
    $paramsGen[':id_agrupacion'] = $generationFilters['id_agrupacion'];
}

if ($generationFilters['id_area'] > 0) {
    $whereGen[] = 'COALESCE(g.destino, f.destino) = :area_destino';
    $whereGen[] = 'g.id_area = :id_area';
    $paramsGen[':area_destino'] = $generationFilters['area_destino'];
    $paramsGen[':id_area'] = $generationFilters['id_area'];
}

$hasGenerationFilters = $paramsGen !== [];
$limitGen = $hasGenerationFilters ? 300 : 100;
$sqlGenerations = "SELECT g.*, f.titulo AS fuente,
        CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
             THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = COALESCE(g.id_servicio, f.id_servicio) LIMIT 1)
             ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = COALESCE(g.id_servicio, f.id_servicio) LIMIT 1)
        END AS servicio,
        CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
             THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = g.id_agrupacion LIMIT 1)
             ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = g.id_agrupacion LIMIT 1)
        END AS agrupacion,
        CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
             THEN (SELECT acf.descripcion FROM ceo_areacompetencia_formacion acf WHERE acf.id = g.id_area LIMIT 1)
             ELSE (SELECT ac.descripcion FROM ceo_areacompetencias ac WHERE ac.id = g.id_area LIMIT 1)
        END AS area
    FROM ceo_gp_generaciones g
    INNER JOIN ceo_gp_fuentes f ON f.id = g.id_fuente";

if ($whereGen) {
    $sqlGenerations .= ' WHERE ' . implode(' AND ', $whereGen);
}
$sqlGenerations .= ' ORDER BY g.id DESC LIMIT ' . $limitGen;

$stmtGenerations = $pdo->prepare($sqlGenerations);
foreach ($paramsGen as $key => $value) {
    $stmtGenerations->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtGenerations->execute();
$generations = $stmtGenerations->fetchAll(PDO::FETCH_ASSOC);

$currentGeneration = null;
$questions = [];
if ($currentGenerationId > 0) {
    $stmtGen = $pdo->prepare("SELECT g.*, f.titulo AS fuente,
            CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
                 THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = COALESCE(g.id_servicio, f.id_servicio) LIMIT 1)
                 ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = COALESCE(g.id_servicio, f.id_servicio) LIMIT 1)
            END AS servicio,
            CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
                 THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = g.id_agrupacion LIMIT 1)
                 ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = g.id_agrupacion LIMIT 1)
            END AS agrupacion,
            CASE WHEN COALESCE(g.destino, f.destino) = 'FORMACION'
                 THEN (SELECT acf.descripcion FROM ceo_areacompetencia_formacion acf WHERE acf.id = g.id_area LIMIT 1)
                 ELSE (SELECT ac.descripcion FROM ceo_areacompetencias ac WHERE ac.id = g.id_area LIMIT 1)
            END AS area
        FROM ceo_gp_generaciones g
        INNER JOIN ceo_gp_fuentes f ON f.id = g.id_fuente
        WHERE g.id = :id
        LIMIT 1");
    $stmtGen->execute([':id' => $currentGenerationId]);
    $currentGeneration = $stmtGen->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($currentGeneration) {
        $stmtQ = $pdo->prepare('SELECT * FROM ceo_gp_preguntas WHERE id_generacion = :id ORDER BY id ASC');
        $stmtQ->execute([':id' => $currentGenerationId]);
        $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
        if ($questions) {
            $ids = array_map(static fn($q) => (int)$q['id'], $questions);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtA = $pdo->prepare("SELECT * FROM ceo_gp_alternativas WHERE id_pregunta IN ($placeholders) ORDER BY id_pregunta ASC, orden ASC, id ASC");
            $stmtA->execute($ids);
            $altsByQuestion = [];
            foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $alt) {
                $altsByQuestion[(int)$alt['id_pregunta']][] = $alt;
            }
            foreach ($questions as &$question) {
                $question['alternativas'] = $altsByQuestion[(int)$question['id']] ?? [];
            }
            unset($question);
        }
    }
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Generacion IA | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;color:#0f172a;}
    .topbar{background:#fff;border-bottom:1px solid rgba(13,110,253,.12);box-shadow:0 1px 6px rgba(15,23,42,.04);}
    .card{border:0;border-radius:20px;box-shadow:0 10px 28px rgba(15,23,42,.07);}
    .source-meta{font-size:.82rem;color:#64748b;}
    .question-box{border:1px solid #dbe5f1;border-radius:16px;background:#fff;}
    .correct-alt{background:#eaf8ef;border-color:#b8e2c5;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-bold h5 mb-0">Generacion IA</div>
      <small class="text-muted">V3: crea preguntas solo como borradores del Gestor</small>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_home.php" class="btn btn-outline-primary btn-sm">Inicio</a>
      <a href="gp_fuentes.php" class="btn btn-outline-secondary btn-sm">Fuentes</a>
      <a href="gp_logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
    </div>
  </div>
</header>

<main class="container-fluid px-4 pb-5">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= gpEsc($msg) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger">
      <?= gpEsc($error) ?>
      <?php if ($debugFile !== ''): ?>
        <div class="small mt-2">Debug guardado: <a href="../<?= gpEsc($debugFile) ?>" target="_blank"><?= gpEsc($debugFile) ?></a></div>
      <?php endif; ?>
      <?php if ($debugError !== ''): ?>
        <div class="small mt-2">No se pudo guardar archivo debug: <?= gpEsc($debugError) ?></div>
      <?php endif; ?>
      <?php if ($debugExtract !== ''): ?>
        <details class="mt-2">
          <summary class="small">Ver JSON extraido para diagnostico</summary>
          <textarea class="form-control form-control-sm mt-2" rows="10" readonly><?= gpEsc(mb_substr($debugExtract, 0, 8000)) ?></textarea>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h2 class="h5 fw-bold mb-1">Nueva generacion</h2>
            <div class="text-muted small">Cantidad sugerida por solicitud: hasta <?= GP_AI_MAX_SYNC_QUESTIONS ?> preguntas. Puedes ingresar un valor mayor para pruebas manuales; la generacion mezcla dificultad media y avanzada en tandas internas.</div>
          </div>
          <div class="badge text-bg-<?= gpAiApiKey() !== '' ? 'success' : 'warning' ?>"><?= gpAiApiKey() !== '' ? 'API configurada' : 'API no configurada' ?></div>
        </div>
        <form method="post" id="formGeneracion" class="row g-3">
          <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
          <input type="hidden" name="accion" value="generar">
          <div class="col-12">
            <label class="form-label">Fuente activa</label>
            <select name="id_fuente" id="id_fuente" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($sources as $source): ?>
                <option value="<?= (int)$source['id'] ?>"
                        data-destino="<?= gpEsc($source['destino']) ?>"
                        data-servicio="<?= (int)$source['id_servicio'] ?>"
                        data-agrupacion="<?= (int)($source['id_agrupacion'] ?? 0) ?>"
                        data-area="<?= (int)($source['id_area'] ?? 0) ?>">
                  #<?= (int)$source['id'] ?> - <?= gpEsc($source['titulo']) ?> (<?= number_format((int)$source['chars_fuente'], 0, ',', '.') ?> chars)
                </option>
              <?php endforeach; ?>
            </select>
            <div id="sourceMeta" class="source-meta mt-2"></div>
          </div>
          <div class="col-md-6" id="groupWrap">
            <label class="form-label">Agrupacion / prueba</label>
            <select name="id_agrupacion" id="id_agrupacion" class="form-select">
              <option value="">Seleccione...</option>
            </select>
          </div>
          <div class="col-md-6" id="areaWrap">
            <label class="form-label">Area de competencia</label>
            <select name="id_area" id="id_area" class="form-select">
              <option value="">Seleccione...</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cantidad</label>
            <input type="number" name="cantidad" class="form-control" min="1" value="<?= GP_AI_MAX_SYNC_QUESTIONS ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nivel de complejidad</label>
            <input type="text" class="form-control" value="Mixta: media y avanzada" disabled>
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-success"><i class="bi bi-magic me-1"></i>Generar borradores</button>
          </div>
        </form>
      </div>

      <div class="card p-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
          <div>
            <h2 class="h5 fw-bold mb-1">Generaciones recientes</h2>
            <div class="text-muted small">Mostrando <?= count($generations) ?> generaciones<?= $hasGenerationFilters ? ' filtradas' : ' recientes' ?>.</div>
          </div>
          <?php if ($hasGenerationFilters): ?>
            <a href="gp_generacion.php" class="btn btn-outline-secondary btn-sm">Limpiar filtros</a>
          <?php endif; ?>
        </div>
        <form method="get" class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label small mb-1">Fecha desde</label>
            <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?= gpEsc($generationFilters['fecha_desde']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">Fecha hasta</label>
            <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?= gpEsc($generationFilters['fecha_hasta']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">Fuente / titulo</label>
            <input type="text" name="fuente" class="form-control form-control-sm" value="<?= gpEsc($generationFilters['fuente']) ?>" placeholder="Buscar por titulo de fuente...">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">Destino</label>
            <select name="destino" class="form-select form-select-sm">
              <option value="">Todos</option>
              <option value="HABILITACION" <?= $generationFilters['destino'] === 'HABILITACION' ? 'selected' : '' ?>>Habilitacion</option>
              <option value="FORMACION" <?= $generationFilters['destino'] === 'FORMACION' ? 'selected' : '' ?>>Formacion</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">Estado</label>
            <select name="estado" class="form-select form-select-sm">
              <option value="">Todos</option>
              <option value="GENERADA" <?= $generationFilters['estado'] === 'GENERADA' ? 'selected' : '' ?>>Generada</option>
              <option value="ERROR" <?= $generationFilters['estado'] === 'ERROR' ? 'selected' : '' ?>>Error</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">Servicio</label>
            <select name="servicio_key" class="form-select form-select-sm">
              <option value="">Todos</option>
              <?php foreach ($filterServices as $serviceFilter): ?>
                <?php $serviceKey = $serviceFilter['destino'] . ':' . (int)$serviceFilter['id']; ?>
                <option value="<?= gpEsc($serviceKey) ?>" <?= $generationFilters['servicio_key'] === $serviceKey ? 'selected' : '' ?>><?= gpEsc($serviceFilter['destino']) ?> - <?= gpEsc($serviceFilter['servicio']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">Agrupacion / prueba</label>
            <select name="agrupacion_key" class="form-select form-select-sm">
              <option value="">Todas</option>
              <?php foreach ($filterGroups as $groupFilter): ?>
                <?php $groupKey = $groupFilter['destino'] . ':' . (int)$groupFilter['id']; ?>
                <option value="<?= gpEsc($groupKey) ?>" <?= $generationFilters['agrupacion_key'] === $groupKey ? 'selected' : '' ?>><?= gpEsc($groupFilter['destino']) ?> - <?= gpEsc($groupFilter['titulo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">Area de competencia</label>
            <select name="area_key" class="form-select form-select-sm">
              <option value="">Todas</option>
              <?php foreach ($filterAreas as $areaFilter): ?>
                <?php $areaKey = $areaFilter['destino'] . ':' . (int)$areaFilter['id']; ?>
                <option value="<?= gpEsc($areaKey) ?>" <?= $generationFilters['area_key'] === $areaKey ? 'selected' : '' ?>><?= gpEsc($areaFilter['destino']) ?> - <?= gpEsc($areaFilter['descripcion']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>
          </div>
        </form>
        <div class="table-responsive" style="max-height:420px;overflow:auto;">
          <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>ID</th><th>Fuente</th><th>Contexto</th><th>Estado</th><th></th></tr></thead>
            <tbody>
              <?php if (!$generations): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin generaciones.</td></tr><?php endif; ?>
              <?php foreach ($generations as $generation): ?>
                <tr>
                  <td><?= (int)$generation['id'] ?></td>
                  <td><?= gpEsc($generation['fuente']) ?><div class="small text-muted"><?= gpEsc($generation['fecha_creacion']) ?></div></td>
                  <td><?= gpEsc($generation['servicio']) ?><div class="small text-muted"><?= gpEsc($generation['agrupacion']) ?> | <?= gpEsc($generation['area']) ?></div></td>
                  <td><span class="badge text-bg-primary"><?= gpEsc($generation['estado']) ?></span></td>
                  <?php $openParams = array_merge($_GET, ['generacion' => (int)$generation['id']]); ?>
                  <td class="text-end"><a href="?<?= gpEsc(http_build_query($openParams)) ?>" class="btn btn-outline-primary btn-sm">Abrir</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card p-4">
        <h2 class="h5 fw-bold mb-3">Borradores generados</h2>
        <?php if (!$currentGeneration): ?>
          <div class="text-center text-muted py-5">Selecciona una generacion o crea una nueva.</div>
        <?php else: ?>
          <?php
            $draftCount = 0;
            $reviewCount = 0;
            foreach ($questions as $questionStateRow) {
              if (($questionStateRow['estado'] ?? '') === 'BORRADOR') {
                $draftCount++;
              } elseif (($questionStateRow['estado'] ?? '') === 'REVISION') {
                $reviewCount++;
              }
            }
          ?>
          <div class="alert alert-light border">
            <strong>Generacion #<?= (int)$currentGeneration['id'] ?></strong> - <?= gpEsc($currentGeneration['fuente']) ?><br>
            <span class="small text-muted"><?= gpEsc($currentGeneration['servicio']) ?> | <?= gpEsc($currentGeneration['agrupacion']) ?> | <?= gpEsc($currentGeneration['area']) ?> | <?= gpEsc($currentGeneration['dificultad']) ?></span>
            <div class="small text-muted mt-2">Borrador: <?= $draftCount ?> | En revision: <?= $reviewCount ?></div>
          </div>
          <div class="d-flex justify-content-end mb-3">
            <?php if ($draftCount > 0): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
                <input type="hidden" name="accion" value="enviar_revision">
                <input type="hidden" name="id_generacion" value="<?= (int)$currentGeneration['id'] ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">Enviar generacion a Revision</button>
              </form>
            <?php else: ?>
              <span class="btn btn-outline-success btn-sm disabled">Generacion ya enviada a Revision</span>
            <?php endif; ?>
          </div>
          <?php if (!$questions): ?>
            <div class="text-muted">Esta generacion no tiene preguntas asociadas.</div>
          <?php endif; ?>
          <?php foreach ($questions as $idx => $question): ?>
            <div class="question-box p-3 mb-3">
              <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                <div class="fw-semibold">Pregunta <?= $idx + 1 ?></div>
                <span class="badge text-bg-secondary"><?= gpEsc($question['estado']) ?></span>
              </div>
              <p class="mb-3"><?= gpEsc($question['pregunta']) ?></p>
              <?php foreach (($question['alternativas'] ?? []) as $alt): ?>
                <div class="border rounded-3 p-2 mb-2 <?= $alt['correcta'] === 'S' ? 'correct-alt' : '' ?>">
                  <span class="badge text-bg-<?= $alt['correcta'] === 'S' ? 'success' : 'light text-dark border' ?> me-2"><?= $alt['correcta'] === 'S' ? 'Correcta' : 'Alternativa' ?></span>
                  <?= gpEsc($alt['alternativa']) ?>
                </div>
              <?php endforeach; ?>
              <?php if (trim((string)$question['retropos']) !== '' || trim((string)$question['retroneg']) !== '' || trim((string)$question['referencia']) !== ''): ?>
                <div class="small text-muted mt-3">
                  <?php if (trim((string)$question['retropos']) !== ''): ?><div><strong>Retro +:</strong> <?= gpEsc($question['retropos']) ?></div><?php endif; ?>
                  <?php if (trim((string)$question['retroneg']) !== ''): ?><div><strong>Retro -:</strong> <?= gpEsc($question['retroneg']) ?></div><?php endif; ?>
                  <?php if (trim((string)$question['referencia']) !== ''): ?><div><strong>Referencia:</strong> <?= gpEsc($question['referencia']) ?></div><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<div class="modal fade" id="modalGenerando" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-body p-4 text-center">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
        <h5 class="fw-bold mb-2">Generando borradores</h5>
        <p class="text-muted mb-0">Estamos consultando la IA y preparando las preguntas. No cierres esta ventana.</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const sources = <?= json_encode($sources, JSON_UNESCAPED_UNICODE) ?>;
const catalogs = <?= json_encode($catalogs, JSON_UNESCAPED_UNICODE) ?>;
const sourceSelect = document.getElementById('id_fuente');
const groupSelect = document.getElementById('id_agrupacion');
const areaSelect = document.getElementById('id_area');
const sourceMeta = document.getElementById('sourceMeta');
const formGeneracion = document.getElementById('formGeneracion');

function addOption(select, value, text) {
  const opt = document.createElement('option');
  opt.value = value;
  opt.textContent = text;
  select.appendChild(opt);
}

function renderContext() {
  const id = Number(sourceSelect.value || 0);
  const src = sources.find(item => Number(item.id) === id);
  groupSelect.innerHTML = '';
  areaSelect.innerHTML = '';
  addOption(groupSelect, '', 'Seleccione...');
  addOption(areaSelect, '', 'Seleccione...');
  sourceMeta.textContent = '';
  groupSelect.disabled = true;
  areaSelect.disabled = true;

  if (!src) return;

  const destino = src.destino;
  const sid = Number(src.id_servicio || 0);
  sourceMeta.textContent = `${destino} | ${src.servicio || ''} | ${src.agrupacion || 'Agrupacion por seleccionar'} | ${src.area || 'Area por seleccionar'}`;

  const cat = catalogs[destino] || {agrupaciones: [], areas: []};
  cat.agrupaciones.filter(a => Number(a.id_servicio) === sid).forEach(a => addOption(groupSelect, a.id, a.titulo));
  cat.areas.filter(a => Number(a.id_servicio) === sid).forEach(a => addOption(areaSelect, a.id, a.descripcion));

  if (Number(src.id_agrupacion || 0) > 0) {
    groupSelect.value = String(src.id_agrupacion);
    groupSelect.disabled = true;
  } else {
    groupSelect.disabled = false;
  }

  if (Number(src.id_area || 0) > 0) {
    areaSelect.value = String(src.id_area);
    areaSelect.disabled = true;
  } else {
    areaSelect.disabled = false;
  }
}

sourceSelect.addEventListener('change', renderContext);
renderContext();

if (formGeneracion) {
  formGeneracion.addEventListener('submit', () => {
    const modalEl = document.getElementById('modalGenerando');
    if (modalEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    const btn = formGeneracion.querySelector('button[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Generando...';
    }
  });
}
</script>
</body>
</html>
