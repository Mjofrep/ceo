<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/gp_importadores.php';
require_once __DIR__ . '/gp_ai_pdf.php';

$openaiConfig = __DIR__ . '/../config/ceonext_ai.php';
if (is_file($openaiConfig)) {
    require_once $openaiConfig;
}

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN']);

$error = '';
$result = null;
$defaultPrompt = "Usa el flujo por tandas del sistema para extraer preguntas existentes del PDF.\n"
    . "No inventes contenido. No agregues preguntas nuevas.\n"
    . "Si no hay respuesta correcta explicita, usa correcta_index null.\n"
    . "Respeta el rango pedido en cada tanda y devuelve solo JSON valido.";

function gpTestFormatJson(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) ? $json : '';
}

function gpTestOpenAiPdfBatches(string $apiKey, string $model, string $fileId, string $instruction): array
{
    $records = [];
    $batches = [];

    for ($from = 1; $from <= GP_AI_PDF_MAX_QUESTIONS_SCAN; $from += GP_AI_PDF_BATCH_SIZE) {
        $to = $from + GP_AI_PDF_BATCH_SIZE - 1;
        $started = microtime(true);
        $batch = gpAiPdfRequestRangeDetailed($apiKey, $model, $fileId, [
            'instruction' => $instruction,
        ], $from, $to);
        $elapsed = microtime(true) - $started;

        $batchRecords = $batch['records'] ?? [];
        $records = array_merge($records, $batchRecords);
        $batches[] = [
            'desde' => $from,
            'hasta' => $to,
            'http_code' => $batch['http_code'] ?? null,
            'status' => $batch['status'] ?? '',
            'incomplete_reason' => $batch['incomplete_reason'] ?? '',
            'preguntas_detectadas' => count($batchRecords),
            'respuesta_chars' => mb_strlen((string)($batch['text'] ?? ''), 'UTF-8'),
            'tiempo_seg' => round($elapsed, 3),
            'respuesta_texto' => (string)($batch['text'] ?? ''),
            'respuesta_cruda' => (string)($batch['raw'] ?? ''),
        ];

        if (($batch['status'] ?? '') === 'incomplete') {
            break;
        }
        if (!$batchRecords) {
            break;
        }
    }

    return [
        'records' => $records,
        'batches' => $batches,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    try {
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            throw new RuntimeException('Sesion expirada. Recarga e intenta nuevamente.');
        }

        $apiKey = gpAiPdfApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('No existe OPENAI_API_KEY_LOCAL en config/ceonext_ai.php.');
        }

        $instruction = trim((string)($_POST['instruction'] ?? ''));
        if ($instruction === '') {
            throw new RuntimeException('Debes ingresar una instruccion para la IA.');
        }

        $model = trim((string)($_POST['model'] ?? ''));
        if ($model === '') {
            $model = gpAiPdfModel();
        }
        $maxTokens = max(200, min(12000, (int)($_POST['max_tokens'] ?? GP_AI_PDF_BATCH_MAX_OUTPUT_TOKENS)));

        $file = $_FILES['pdf'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Debes seleccionar un PDF valido.');
        }
        $originalName = (string)($file['name'] ?? 'documento.pdf');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            throw new RuntimeException('Esta prueba acepta solo archivos PDF.');
        }
        if ((int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
            throw new RuntimeException('El PDF supera el maximo permitido de 15 MB.');
        }
        if (!is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('Carga de archivo invalida.');
        }

        $dir = dirname(__DIR__) . '/storage/gestor_preguntas/test_openai';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de prueba.');
        }
        $safeBase = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'documento';
        $localPath = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.pdf';
        if (!move_uploaded_file((string)$file['tmp_name'], $localPath)) {
            throw new RuntimeException('No se pudo guardar temporalmente el PDF.');
        }

        $started = microtime(true);
        $uploadStart = microtime(true);
        $fileId = gpAiPdfUploadFile($apiKey, $localPath);
        $uploadSeconds = microtime(true) - $uploadStart;

        $responseStart = microtime(true);
        $openAiResponse = gpTestOpenAiPdfBatches($apiKey, $model, $fileId, $instruction);
        $responseSeconds = microtime(true) - $responseStart;

        $questionCount = count($openAiResponse['records'] ?? []);
        $jsonDetected = [
            'preguntas' => array_map(static function (array $row): array {
                $alternativas = [];
                foreach (($row['alternativas'] ?? []) as $alt) {
                    $alternativas[] = (string)($alt['texto'] ?? '');
                }

                return [
                    'pregunta' => (string)($row['pregunta'] ?? ''),
                    'alternativas' => $alternativas,
                    'correcta_index' => null,
                    'referencia' => (string)($row['referencia'] ?? ''),
                ];
            }, $openAiResponse['records'] ?? []),
        ];

        $hasIncompleteBatch = false;
        foreach (($openAiResponse['batches'] ?? []) as $batchRow) {
            if (($batchRow['status'] ?? '') === 'incomplete') {
                $hasIncompleteBatch = true;
                break;
            }
        }

        $result = [
            'diagnostico' => [
                'archivo' => $originalName,
                'bytes' => (int)$file['size'],
                'modelo' => $model,
                'max_tokens_formulario' => $maxTokens,
                'file_id' => $fileId,
                'tiempo_subida_seg' => round($uploadSeconds, 3),
                'tiempo_respuesta_seg' => round($responseSeconds, 3),
                'tiempo_total_seg' => round(microtime(true) - $started, 3),
                'tandas_procesadas' => count($openAiResponse['batches'] ?? []),
                'tamano_tanda' => GP_AI_PDF_BATCH_SIZE,
                'max_tokens_por_tanda' => GP_AI_PDF_BATCH_MAX_OUTPUT_TOKENS,
                'preguntas_detectadas' => $questionCount,
                'respuesta_incompleta' => $hasIncompleteBatch ? 'SI' : 'NO',
            ],
            'respuesta_texto' => gpTestFormatJson($jsonDetected),
            'json_detectado' => $jsonDetected,
            'respuesta_cruda' => gpTestFormatJson($openAiResponse['batches'] ?? []),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$csrf = Csrf::token();
$postedInstruction = (string)($_POST['instruction'] ?? $defaultPrompt);
$postedModel = (string)($_POST['model'] ?? gpAiPdfModel());
$postedMaxTokens = (int)($_POST['max_tokens'] ?? 2500);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Prueba OpenAI PDF | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid rgba(13,110,253,.12);box-shadow:0 1px 6px rgba(15,23,42,.04);}
    .card{border:0;border-radius:20px;box-shadow:0 10px 28px rgba(15,23,42,.07);}
    pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;border-radius:14px;padding:16px;max-height:520px;overflow:auto;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-bold h5 mb-0">Prueba OpenAI PDF</div>
      <small class="text-muted">Diagnostico aislado: sube PDF, envia instruccion y muestra respuesta</small>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_fuentes.php" class="btn btn-outline-primary btn-sm">Fuentes</a>
      <a href="gp_home.php" class="btn btn-outline-secondary btn-sm">Inicio</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= gpEsc($error) ?></div><?php endif; ?>

  <div class="card p-4 mb-4">
    <h1 class="h5 fw-bold mb-3">Nueva prueba</h1>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
      <div class="col-md-6">
        <label class="form-label">Archivo PDF</label>
        <input type="file" name="pdf" class="form-control" accept=".pdf,application/pdf" required>
        <div class="form-text">No inserta preguntas ni modifica el Gestor. Maximo 15 MB.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Modelo</label>
        <input type="text" name="model" class="form-control" value="<?= gpEsc($postedModel) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Max tokens</label>
        <input type="number" name="max_tokens" class="form-control" min="200" max="12000" value="<?= (int)$postedMaxTokens ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Instruccion para la IA</label>
        <textarea name="instruction" class="form-control" rows="9" required><?= gpEsc($postedInstruction) ?></textarea>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary"><i class="bi bi-robot me-1"></i>Probar con OpenAI</button>
      </div>
    </form>
  </div>

  <?php if (is_array($result)): ?>
    <div class="card p-4 mb-4">
      <h2 class="h5 fw-bold mb-3">Resultado tecnico</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <tbody>
          <?php foreach ($result['diagnostico'] as $key => $value): ?>
            <tr><th style="width:220px;"><?= gpEsc($key) ?></th><td><?= gpEsc(is_scalar($value) || $value === null ? (string)($value ?? '') : gpTestFormatJson($value)) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card p-4 mb-4">
      <h2 class="h5 fw-bold mb-3">Respuesta OpenAI</h2>
      <pre><?= gpEsc((string)$result['respuesta_texto']) ?></pre>
    </div>

    <div class="card p-4 mb-4">
      <h2 class="h5 fw-bold mb-3">JSON Detectado</h2>
      <pre><?= gpEsc(gpTestFormatJson($result['json_detectado'])) ?></pre>
    </div>

    <div class="card p-4">
      <h2 class="h5 fw-bold mb-3">Respuesta Cruda</h2>
      <pre><?= gpEsc((string)$result['respuesta_cruda']) ?></pre>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
