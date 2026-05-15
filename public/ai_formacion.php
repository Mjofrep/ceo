<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/public/index.php');
    exit;
}

$pdo = db();
$msg = '';
$error = '';
$currentGenerationId = (int)($_GET['generacion'] ?? 0);

function aiEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aiEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_ai_formacion_fuentes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      titulo VARCHAR(255) NOT NULL,
      id_servicio INT NULL,
      tipo_origen ENUM('MANUAL','TXT') NOT NULL DEFAULT 'MANUAL',
      nombre_archivo VARCHAR(255) NULL,
      ruta_archivo VARCHAR(500) NULL,
      texto_fuente MEDIUMTEXT NOT NULL,
      estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_fuente_servicio (id_servicio),
      INDEX idx_ai_fuente_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_ai_formacion_generaciones (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_fuente INT NOT NULL,
      id_servicio INT NOT NULL,
      id_agrupacion INT NOT NULL,
      id_area INT NOT NULL,
      cantidad_solicitada INT NOT NULL,
      dificultad VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
      modelo VARCHAR(80) NOT NULL,
      prompt_text LONGTEXT NOT NULL,
      respuesta_json LONGTEXT NOT NULL,
      estado ENUM('GENERADA','REVISADA','GUARDADA','ERROR') NOT NULL DEFAULT 'GENERADA',
      error_text TEXT NULL,
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_gen_fuente (id_fuente),
      INDEX idx_ai_gen_servicio (id_servicio),
      INDEX idx_ai_gen_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_ai_formacion_borradores (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_generacion INT NOT NULL,
      orden_item INT NOT NULL DEFAULT 0,
      pregunta TEXT NOT NULL,
      alternativas_json LONGTEXT NOT NULL,
      correcta_index INT NOT NULL DEFAULT 0,
      retropos TEXT NULL,
      retroneg TEXT NULL,
      referencia TEXT NULL,
      estado ENUM('BORRADOR','GUARDADA','DESCARTADA') NOT NULL DEFAULT 'BORRADOR',
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ai_borrador_generacion (id_generacion),
      INDEX idx_ai_borrador_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function aiApiKey(): string
{
    $key = trim((string)(getenv('OPENAI_API_KEY') ?: ''));
    return $key;
}

function aiModel(): string
{
    $model = trim((string)(getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'));
    return $model !== '' ? $model : 'gpt-4.1-mini';
}

function aiBuildPrompt(string $sourceText, array $context): string
{
    $maxChars = 12000;
    $sourceText = trim(mb_substr($sourceText, 0, $maxChars));
    return "Eres un generador de preguntas para CEONext.\n"
        . "Debes crear preguntas SOLO usando la informacion de la fuente entregada.\n"
        . "No inventes datos no presentes en la fuente.\n"
        . "Devuelve exclusivamente JSON valido, sin markdown, sin explicaciones extra.\n"
        . "Formato esperado: un array JSON de objetos con estas claves: pregunta, alternativas, correcta_index, retro_correcta, retro_incorrecta, referencia.\n"
        . "Reglas: \n"
        . "- tipo_pregunta: ALT\n"
        . "- exactamente 4 alternativas por pregunta\n"
        . "- correcta_index debe ser 0,1,2 o 3\n"
        . "- retro_correcta y retro_incorrecta deben ser breves y utiles\n"
        . "- referencia debe citar brevemente el fragmento o seccion usada\n"
        . "- evita duplicados y preguntas ambiguas\n"
        . "Contexto CEONext:\n"
        . "Servicio: {$context['servicio']}\n"
        . "Agrupacion: {$context['agrupacion']}\n"
        . "Area de competencia: {$context['area']}\n"
        . "Cantidad solicitada: {$context['cantidad']}\n"
        . "Dificultad: {$context['dificultad']}\n\n"
        . "Fuente:\n"
        . $sourceText;
}

function aiExtractJson(string $text): string
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```[a-zA-Z0-9_\-]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);
    }

    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start !== false && $end !== false && $end > $start) {
        return substr($text, $start, $end - $start + 1);
    }

    return $text;
}

function aiOpenAiGenerate(string $apiKey, string $model, string $prompt): string
{
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Responde solo con JSON valido.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.3,
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
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Error de comunicacion con OpenAI: ' . $curlError);
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

function aiDecodeQuestions(string $jsonText): array
{
    $decoded = json_decode(aiExtractJson($jsonText), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('La respuesta AI no tiene formato JSON valido.');
    }

    $items = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $question = trim((string)($row['pregunta'] ?? ''));
        $alternatives = $row['alternativas'] ?? [];
        $correct = (int)($row['correcta_index'] ?? -1);
        if ($question === '' || !is_array($alternatives) || count($alternatives) !== 4 || $correct < 0 || $correct > 3) {
            continue;
        }
        $alts = [];
        foreach ($alternatives as $alt) {
            $alts[] = trim((string)$alt);
        }
        if (count(array_filter($alts, static fn($v) => $v !== '')) !== 4) {
            continue;
        }
        $items[] = [
            'pregunta' => $question,
            'alternativas' => $alts,
            'correcta_index' => $correct,
            'retro_correcta' => trim((string)($row['retro_correcta'] ?? '')),
            'retro_incorrecta' => trim((string)($row['retro_incorrecta'] ?? '')),
            'referencia' => trim((string)($row['referencia'] ?? '')),
        ];
    }

    if ($items === []) {
        throw new RuntimeException('La respuesta AI no trajo preguntas validas en el formato esperado.');
    }

    return $items;
}

function aiStorageDir(): string
{
    return dirname(__DIR__) . '/storage/ai_formacion/' . date('Y');
}

aiEnsureTables($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'crear_fuente') {
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $idServicio = (int)($_POST['id_servicio'] ?? 0);
            $textoManual = trim((string)($_POST['texto_fuente'] ?? ''));
            $textoFinal = $textoManual;
            $tipoOrigen = 'MANUAL';
            $nombreArchivo = null;
            $rutaArchivo = null;

            if ($titulo === '') {
                throw new RuntimeException('Debes ingresar un titulo para la fuente.');
            }
            if ($idServicio <= 0) {
                throw new RuntimeException('Debes seleccionar un servicio.');
            }

            if (!empty($_FILES['archivo_fuente']['name'])) {
                $ext = strtolower(pathinfo((string)$_FILES['archivo_fuente']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'txt') {
                    throw new RuntimeException('V1 solo soporta archivos .txt o texto manual. PDF quedara para una siguiente etapa.');
                }
                $tmp = (string)($_FILES['archivo_fuente']['tmp_name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('No fue posible leer el archivo fuente.');
                }
                $contenido = (string)file_get_contents($tmp);
                if (trim($contenido) === '') {
                    throw new RuntimeException('El archivo .txt no contiene texto util.');
                }

                $dir = aiStorageDir();
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('No se pudo crear el directorio de almacenamiento AI.');
                }
                $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename((string)$_FILES['archivo_fuente']['name']));
                $savedName = date('Ymd_His') . '_' . $safeBase;
                $fullPath = $dir . '/' . $savedName;
                if (!move_uploaded_file($tmp, $fullPath)) {
                    throw new RuntimeException('No se pudo guardar el archivo fuente.');
                }

                $tipoOrigen = 'TXT';
                $nombreArchivo = (string)$_FILES['archivo_fuente']['name'];
                $rutaArchivo = 'storage/ai_formacion/' . date('Y') . '/' . $savedName;
                $textoFinal = trim($textoFinal . "\n\n" . $contenido);
            }

            if ($textoFinal === '') {
                throw new RuntimeException('Debes ingresar texto manual o cargar un archivo .txt.');
            }

            $stmt = $pdo->prepare('INSERT INTO ceo_ai_formacion_fuentes (titulo, id_servicio, tipo_origen, nombre_archivo, ruta_archivo, texto_fuente, creado_por) VALUES (:titulo, :id_servicio, :tipo_origen, :nombre_archivo, :ruta_archivo, :texto_fuente, :creado_por)');
            $stmt->execute([
                ':titulo' => $titulo,
                ':id_servicio' => $idServicio,
                ':tipo_origen' => $tipoOrigen,
                ':nombre_archivo' => $nombreArchivo,
                ':ruta_archivo' => $rutaArchivo,
                ':texto_fuente' => $textoFinal,
                ':creado_por' => (int)($_SESSION['auth']['id'] ?? 0) ?: null,
            ]);

            $msg = 'Fuente AI creada correctamente.';
        }

        if ($action === 'generar_preguntas') {
            $idFuente = (int)($_POST['id_fuente'] ?? 0);
            $idServicio = (int)($_POST['id_servicio_gen'] ?? 0);
            $idAgrupacion = (int)($_POST['id_agrupacion'] ?? 0);
            $idArea = (int)($_POST['id_area'] ?? 0);
            $cantidad = max(1, min(10, (int)($_POST['cantidad'] ?? 5)));
            $dificultad = strtoupper(trim((string)($_POST['dificultad'] ?? 'MEDIA')));

            if ($idFuente <= 0 || $idServicio <= 0 || $idAgrupacion <= 0 || $idArea <= 0) {
                throw new RuntimeException('Debes seleccionar fuente, servicio, agrupacion y area de competencia.');
            }

            $stmtFuente = $pdo->prepare('SELECT id, titulo, texto_fuente FROM ceo_ai_formacion_fuentes WHERE id = :id AND estado = "ACTIVA" LIMIT 1');
            $stmtFuente->execute([':id' => $idFuente]);
            $fuente = $stmtFuente->fetch(PDO::FETCH_ASSOC);
            if (!$fuente) {
                throw new RuntimeException('La fuente seleccionada no existe o no esta activa.');
            }

            $stmtServ = $pdo->prepare('SELECT servicio FROM ceo_formacion_servicios WHERE id = :id LIMIT 1');
            $stmtServ->execute([':id' => $idServicio]);
            $servicioTxt = (string)$stmtServ->fetchColumn();

            $stmtAgr = $pdo->prepare('SELECT titulo FROM ceo_formacion_agrupacion WHERE id = :id LIMIT 1');
            $stmtAgr->execute([':id' => $idAgrupacion]);
            $agrupacionTxt = (string)$stmtAgr->fetchColumn();

            $stmtArea = $pdo->prepare('SELECT descripcion FROM ceo_areacompetencia_formacion WHERE id = :id LIMIT 1');
            $stmtArea->execute([':id' => $idArea]);
            $areaTxt = (string)$stmtArea->fetchColumn();

            if ($servicioTxt === '' || $agrupacionTxt === '' || $areaTxt === '') {
                throw new RuntimeException('No fue posible resolver servicio, agrupacion o area de competencia.');
            }

            $apiKey = aiApiKey();
            if ($apiKey === '') {
                throw new RuntimeException('No existe OPENAI_API_KEY en el entorno del servidor.');
            }

            $prompt = aiBuildPrompt((string)$fuente['texto_fuente'], [
                'servicio' => $servicioTxt,
                'agrupacion' => $agrupacionTxt,
                'area' => $areaTxt,
                'cantidad' => (string)$cantidad,
                'dificultad' => $dificultad,
            ]);
            $model = aiModel();
            $raw = aiOpenAiGenerate($apiKey, $model, $prompt);
            $items = aiDecodeQuestions($raw);

            $pdo->beginTransaction();
            $stmtGen = $pdo->prepare('INSERT INTO ceo_ai_formacion_generaciones (id_fuente, id_servicio, id_agrupacion, id_area, cantidad_solicitada, dificultad, modelo, prompt_text, respuesta_json, estado, creado_por) VALUES (:id_fuente, :id_servicio, :id_agrupacion, :id_area, :cantidad, :dificultad, :modelo, :prompt_text, :respuesta_json, "GENERADA", :creado_por)');
            $stmtGen->execute([
                ':id_fuente' => $idFuente,
                ':id_servicio' => $idServicio,
                ':id_agrupacion' => $idAgrupacion,
                ':id_area' => $idArea,
                ':cantidad' => $cantidad,
                ':dificultad' => $dificultad,
                ':modelo' => $model,
                ':prompt_text' => $prompt,
                ':respuesta_json' => aiExtractJson($raw),
                ':creado_por' => (int)($_SESSION['auth']['id'] ?? 0) ?: null,
            ]);
            $generationId = (int)$pdo->lastInsertId();

            $stmtDraft = $pdo->prepare('INSERT INTO ceo_ai_formacion_borradores (id_generacion, orden_item, pregunta, alternativas_json, correcta_index, retropos, retroneg, referencia, estado) VALUES (:id_generacion, :orden_item, :pregunta, :alternativas_json, :correcta_index, :retropos, :retroneg, :referencia, "BORRADOR")');
            foreach ($items as $idx => $item) {
                $stmtDraft->execute([
                    ':id_generacion' => $generationId,
                    ':orden_item' => $idx + 1,
                    ':pregunta' => $item['pregunta'],
                    ':alternativas_json' => json_encode($item['alternativas'], JSON_UNESCAPED_UNICODE),
                    ':correcta_index' => $item['correcta_index'],
                    ':retropos' => $item['retro_correcta'],
                    ':retroneg' => $item['retro_incorrecta'],
                    ':referencia' => $item['referencia'],
                ]);
            }
            $pdo->commit();

            $currentGenerationId = $generationId;
            $msg = 'Preguntas AI generadas correctamente. Revisa los borradores antes de guardarlos.';
        }

        if ($action === 'guardar_borradores') {
            $generationId = (int)($_POST['id_generacion'] ?? 0);
            if ($generationId <= 0) {
                throw new RuntimeException('Generacion invalida.');
            }

            $stmtGen = $pdo->prepare('SELECT * FROM ceo_ai_formacion_generaciones WHERE id = :id LIMIT 1');
            $stmtGen->execute([':id' => $generationId]);
            $generation = $stmtGen->fetch(PDO::FETCH_ASSOC);
            if (!$generation) {
                throw new RuntimeException('La generacion seleccionada no existe.');
            }

            $selected = $_POST['guardar_item'] ?? [];
            if (!is_array($selected) || $selected === []) {
                throw new RuntimeException('Debes seleccionar al menos un borrador para guardar.');
            }

            $stmtGetDrafts = $pdo->prepare('SELECT * FROM ceo_ai_formacion_borradores WHERE id_generacion = :id_generacion AND estado = "BORRADOR" ORDER BY orden_item ASC, id ASC');
            $stmtGetDrafts->execute([':id_generacion' => $generationId]);
            $drafts = $stmtGetDrafts->fetchAll(PDO::FETCH_ASSOC);
            if ($drafts === []) {
                throw new RuntimeException('No hay borradores pendientes para esta generacion.');
            }

            $pdo->beginTransaction();
            $stmtInsertQuestion = $pdo->prepare('INSERT INTO ceo_formacion_preguntas_servicios (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp, peso, tipo_pregunta, obligatoria) VALUES (:pregunta, :id_servicio, "", "S", :id_agrupacion, :retropos, :retroneg, :areacomp, :peso, "ALT", 0)');
            $stmtInsertAlternative = $pdo->prepare('INSERT INTO ceo_formacion_alternativas_preguntas (alternativa, correcta, estado, id_pregunta, imagen) VALUES (:alternativa, :correcta, "S", :id_pregunta, "")');
            $stmtUpdateDraft = $pdo->prepare('UPDATE ceo_ai_formacion_borradores SET pregunta = :pregunta, alternativas_json = :alternativas_json, correcta_index = :correcta_index, retropos = :retropos, retroneg = :retroneg, referencia = :referencia, estado = :estado WHERE id = :id');

            $savedCount = 0;
            foreach ($drafts as $draft) {
                $idDraft = (int)$draft['id'];
                $question = trim((string)($_POST['pregunta'][$idDraft] ?? $draft['pregunta']));
                $retroPos = trim((string)($_POST['retropos'][$idDraft] ?? $draft['retropos'] ?? ''));
                $retroNeg = trim((string)($_POST['retroneg'][$idDraft] ?? $draft['retroneg'] ?? ''));
                $referencia = trim((string)($_POST['referencia'][$idDraft] ?? $draft['referencia'] ?? ''));
                $correctIndex = (int)($_POST['correcta_index'][$idDraft] ?? $draft['correcta_index']);

                $alts = [];
                for ($i = 0; $i < 4; $i++) {
                    $alts[] = trim((string)($_POST['alternativa'][$idDraft][$i] ?? ''));
                }
                if ($question === '' || $correctIndex < 0 || $correctIndex > 3 || count(array_filter($alts, static fn($v) => $v !== '')) !== 4) {
                    throw new RuntimeException('Cada borrador seleccionado debe tener pregunta, 4 alternativas completas y una correcta.');
                }

                $shouldSave = in_array((string)$idDraft, array_map('strval', $selected), true);
                $stmtUpdateDraft->execute([
                    ':pregunta' => $question,
                    ':alternativas_json' => json_encode($alts, JSON_UNESCAPED_UNICODE),
                    ':correcta_index' => $correctIndex,
                    ':retropos' => $retroPos,
                    ':retroneg' => $retroNeg,
                    ':referencia' => $referencia,
                    ':estado' => $shouldSave ? 'GUARDADA' : 'DESCARTADA',
                    ':id' => $idDraft,
                ]);

                if (!$shouldSave) {
                    continue;
                }

                $stmtInsertQuestion->execute([
                    ':pregunta' => $question,
                    ':id_servicio' => (int)$generation['id_servicio'],
                    ':id_agrupacion' => (int)$generation['id_agrupacion'],
                    ':retropos' => $retroPos,
                    ':retroneg' => $retroNeg,
                    ':areacomp' => (int)$generation['id_area'],
                    ':peso' => 1,
                ]);
                $questionId = (int)$pdo->lastInsertId();

                foreach ($alts as $idx => $alt) {
                    $stmtInsertAlternative->execute([
                        ':alternativa' => $alt,
                        ':correcta' => $idx === $correctIndex ? 'S' : 'N',
                        ':id_pregunta' => $questionId,
                    ]);
                }
                $savedCount++;
            }

            $stmtGenState = $pdo->prepare('UPDATE ceo_ai_formacion_generaciones SET estado = "GUARDADA" WHERE id = :id');
            $stmtGenState->execute([':id' => $generationId]);
            $pdo->commit();
            $currentGenerationId = $generationId;
            $msg = 'Preguntas guardadas en el banco de formacion: ' . $savedCount . '.';
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
}

$services = $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio')->fetchAll(PDO::FETCH_ASSOC);
$groupings = $pdo->query('SELECT id, titulo, id_servicio FROM ceo_formacion_agrupacion ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$areas = $pdo->query('SELECT MIN(id) AS id, descripcion, id_servicio FROM ceo_areacompetencia_formacion GROUP BY descripcion, id_servicio ORDER BY descripcion')->fetchAll(PDO::FETCH_ASSOC);
$sources = $pdo->query('SELECT f.*, s.servicio FROM ceo_ai_formacion_fuentes f LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio WHERE f.estado = "ACTIVA" ORDER BY f.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
$generations = $pdo->query('SELECT g.*, fs.servicio, a.titulo AS agrupacion, ac.descripcion AS area, f.titulo AS fuente FROM ceo_ai_formacion_generaciones g INNER JOIN ceo_ai_formacion_fuentes f ON f.id = g.id_fuente LEFT JOIN ceo_formacion_servicios fs ON fs.id = g.id_servicio LEFT JOIN ceo_formacion_agrupacion a ON a.id = g.id_agrupacion LEFT JOIN ceo_areacompetencia_formacion ac ON ac.id = g.id_area ORDER BY g.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

$currentGeneration = null;
$drafts = [];
if ($currentGenerationId > 0) {
    $stmt = $pdo->prepare('SELECT g.*, fs.servicio, a.titulo AS agrupacion, ac.descripcion AS area, f.titulo AS fuente FROM ceo_ai_formacion_generaciones g INNER JOIN ceo_ai_formacion_fuentes f ON f.id = g.id_fuente LEFT JOIN ceo_formacion_servicios fs ON fs.id = g.id_servicio LEFT JOIN ceo_formacion_agrupacion a ON a.id = g.id_agrupacion LEFT JOIN ceo_areacompetencia_formacion ac ON ac.id = g.id_area WHERE g.id = :id LIMIT 1');
    $stmt->execute([':id' => $currentGenerationId]);
    $currentGeneration = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($currentGeneration) {
        $stmtDrafts = $pdo->prepare('SELECT * FROM ceo_ai_formacion_borradores WHERE id_generacion = :id_generacion ORDER BY orden_item ASC, id ASC');
        $stmtDrafts->execute([':id_generacion' => $currentGenerationId]);
        $drafts = $stmtDrafts->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>AI Formacion - <?= aiEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .brand-title { color:#0065a4; font-weight:600; }
    .card { border:none; box-shadow:0 2px 4px rgba(0,0,0,.05); }
    .small-mono { font-family: Menlo, Monaco, Consolas, monospace; font-size:.78rem; }
    .draft-card { border:1px solid #dde4ef; border-radius:14px; background:#fff; }
    .draft-card.saved { border-color:#bfe3c0; background:#f7fff8; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= aiEsc(APP_NAME) ?></div>
        <small class="text-secondary"><?= aiEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container-fluid px-4 pb-5">
  <div class="card rounded-4 mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="bi bi-robot me-2"></i>AI Formacion V1</h4>
        <div class="text-muted small">Genera borradores de preguntas ALT desde texto manual o archivos .txt, revisa y guarda al banco de CEONext.</div>
      </div>
      <div class="text-end small">
        <div><strong>Modelo:</strong> <?= aiEsc(aiModel()) ?></div>
        <div><strong>API Key:</strong> <?= aiApiKey() !== '' ? 'Configurada' : 'No configurada' ?></div>
      </div>
    </div>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="alert alert-success"><?= aiEsc($msg) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= aiEsc($error) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card rounded-4 mb-4">
        <div class="card-body">
          <h5 class="text-primary mb-3"><i class="bi bi-file-earmark-arrow-up me-2"></i>Nueva Fuente</h5>
          <form method="post" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="crear_fuente">
            <div class="col-12">
              <label class="form-label">Titulo</label>
              <input type="text" name="titulo" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Servicio</label>
              <select name="id_servicio" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($services as $service): ?>
                  <option value="<?= (int)$service['id'] ?>"><?= aiEsc($service['servicio']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Texto fuente</label>
              <textarea name="texto_fuente" class="form-control" rows="8" placeholder="Pega aqui el contenido base para generar preguntas..."></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Archivo .txt opcional</label>
              <input type="file" name="archivo_fuente" class="form-control" accept=".txt,text/plain">
              <div class="form-text">V1 soporta texto manual y archivos .txt. PDF/DOCX quedaran para la siguiente etapa.</div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary">Crear fuente</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card rounded-4">
        <div class="card-body">
          <h5 class="text-primary mb-3"><i class="bi bi-database me-2"></i>Fuentes Registradas</h5>
          <div class="table-responsive" style="max-height:340px; overflow:auto;">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Titulo</th>
                  <th>Servicio</th>
                  <th>Origen</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($sources === []): ?>
                <tr><td colspan="4" class="text-center text-muted">Sin fuentes aun.</td></tr>
              <?php else: ?>
                <?php foreach ($sources as $source): ?>
                  <tr>
                    <td><?= (int)$source['id'] ?></td>
                    <td><?= aiEsc($source['titulo']) ?><div class="small text-muted"><?= aiEsc(mb_substr((string)$source['texto_fuente'], 0, 70)) ?><?= mb_strlen((string)$source['texto_fuente']) > 70 ? '...' : '' ?></div></td>
                    <td><?= aiEsc($source['servicio']) ?></td>
                    <td><?= aiEsc($source['tipo_origen']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card rounded-4 mb-4">
        <div class="card-body">
          <h5 class="text-primary mb-3"><i class="bi bi-stars me-2"></i>Generar Borradores</h5>
          <form method="post" class="row g-3" id="formGenerarAi">
            <input type="hidden" name="action" value="generar_preguntas">
            <div class="col-md-6">
              <label class="form-label">Fuente</label>
              <select name="id_fuente" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($sources as $source): ?>
                  <option value="<?= (int)$source['id'] ?>" data-servicio="<?= (int)($source['id_servicio'] ?? 0) ?>"><?= aiEsc($source['titulo']) ?> (#<?= (int)$source['id'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Servicio</label>
              <select name="id_servicio_gen" id="aiServicio" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($services as $service): ?>
                  <option value="<?= (int)$service['id'] ?>"><?= aiEsc($service['servicio']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Agrupacion / Prueba</label>
              <select name="id_agrupacion" id="aiAgrupacion" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($groupings as $grouping): ?>
                  <option value="<?= (int)$grouping['id'] ?>" data-servicio="<?= (int)$grouping['id_servicio'] ?>"><?= aiEsc($grouping['titulo']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Area de competencia</label>
              <select name="id_area" id="aiArea" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($areas as $area): ?>
                  <option value="<?= (int)$area['id'] ?>" data-servicio="<?= (int)$area['id_servicio'] ?>"><?= aiEsc($area['descripcion']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Cantidad</label>
              <input type="number" name="cantidad" class="form-control" min="1" max="10" value="5" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Dificultad</label>
              <select name="dificultad" class="form-select">
                <option value="BAJA">BAJA</option>
                <option value="MEDIA" selected>MEDIA</option>
                <option value="ALTA">ALTA</option>
              </select>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-success"><i class="bi bi-magic me-1"></i>Generar con AI</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card rounded-4">
        <div class="card-body">
          <h5 class="text-primary mb-3"><i class="bi bi-clock-history me-2"></i>Generaciones</h5>
          <div class="table-responsive" style="max-height:340px; overflow:auto;">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Fuente</th>
                  <th>Servicio</th>
                  <th>Area</th>
                  <th>Estado</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php if ($generations === []): ?>
                <tr><td colspan="6" class="text-center text-muted">Sin generaciones aun.</td></tr>
              <?php else: ?>
                <?php foreach ($generations as $generation): ?>
                  <tr>
                    <td><?= (int)$generation['id'] ?></td>
                    <td><?= aiEsc($generation['fuente']) ?><div class="small text-muted"><?= aiEsc($generation['agrupacion']) ?></div></td>
                    <td><?= aiEsc($generation['servicio']) ?></td>
                    <td><?= aiEsc($generation['area']) ?></td>
                    <td><span class="badge text-bg-<?= $generation['estado'] === 'GUARDADA' ? 'success' : 'primary' ?>"><?= aiEsc($generation['estado']) ?></span></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="?generacion=<?= (int)$generation['id'] ?>">Abrir</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($currentGeneration && $drafts !== []): ?>
    <div class="card rounded-4 mt-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h5 class="text-primary mb-1"><i class="bi bi-pencil-square me-2"></i>Revision de Borradores #<?= (int)$currentGeneration['id'] ?></h5>
            <div class="text-muted small">Fuente: <?= aiEsc($currentGeneration['fuente']) ?> | Servicio: <?= aiEsc($currentGeneration['servicio']) ?> | Area: <?= aiEsc($currentGeneration['area']) ?></div>
          </div>
          <div class="small-mono text-muted">Estado: <?= aiEsc($currentGeneration['estado']) ?></div>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="guardar_borradores">
          <input type="hidden" name="id_generacion" value="<?= (int)$currentGeneration['id'] ?>">

          <div class="d-grid gap-3">
          <?php foreach ($drafts as $draft): ?>
            <?php $alternatives = json_decode((string)$draft['alternativas_json'], true) ?: []; ?>
            <div class="draft-card p-3 <?= $draft['estado'] !== 'BORRADOR' ? 'saved' : '' ?>">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="guardar_item[]" value="<?= (int)$draft['id'] ?>" id="guardarItem<?= (int)$draft['id'] ?>" <?= $draft['estado'] === 'GUARDADA' ? 'checked disabled' : 'checked' ?>>
                  <label class="form-check-label fw-semibold" for="guardarItem<?= (int)$draft['id'] ?>">Borrador <?= (int)$draft['orden_item'] ?></label>
                </div>
                <span class="badge text-bg-<?= $draft['estado'] === 'GUARDADA' ? 'success' : ($draft['estado'] === 'DESCARTADA' ? 'secondary' : 'primary') ?>"><?= aiEsc($draft['estado']) ?></span>
              </div>

              <div class="mb-3">
                <label class="form-label">Pregunta</label>
                <textarea class="form-control" name="pregunta[<?= (int)$draft['id'] ?>]" rows="3" <?= $draft['estado'] !== 'BORRADOR' ? 'readonly' : '' ?>><?= aiEsc($draft['pregunta']) ?></textarea>
              </div>

              <div class="row g-3 mb-3">
                <?php for ($i = 0; $i < 4; $i++): ?>
                  <div class="col-md-6">
                    <label class="form-label">Alternativa <?= $i + 1 ?></label>
                    <div class="input-group">
                      <span class="input-group-text">
                        <input type="radio" name="correcta_index[<?= (int)$draft['id'] ?>]" value="<?= $i ?>" <?= (int)$draft['correcta_index'] === $i ? 'checked' : '' ?> <?= $draft['estado'] !== 'BORRADOR' ? 'disabled' : '' ?>>
                      </span>
                      <input type="text" class="form-control" name="alternativa[<?= (int)$draft['id'] ?>][<?= $i ?>]" value="<?= aiEsc($alternatives[$i] ?? '') ?>" <?= $draft['estado'] !== 'BORRADOR' ? 'readonly' : '' ?>>
                    </div>
                  </div>
                <?php endfor; ?>
              </div>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Retro correcta</label>
                  <textarea class="form-control" name="retropos[<?= (int)$draft['id'] ?>]" rows="2" <?= $draft['estado'] !== 'BORRADOR' ? 'readonly' : '' ?>><?= aiEsc($draft['retropos']) ?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Retro incorrecta</label>
                  <textarea class="form-control" name="retroneg[<?= (int)$draft['id'] ?>]" rows="2" <?= $draft['estado'] !== 'BORRADOR' ? 'readonly' : '' ?>><?= aiEsc($draft['retroneg']) ?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">Referencia fuente</label>
                  <input type="text" class="form-control" name="referencia[<?= (int)$draft['id'] ?>]" value="<?= aiEsc($draft['referencia']) ?>" <?= $draft['estado'] !== 'BORRADOR' ? 'readonly' : '' ?>>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          </div>

          <?php if ($currentGeneration['estado'] !== 'GUARDADA'): ?>
            <div class="text-end mt-4">
              <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Guardar seleccionadas en banco</button>
            </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const servicioSel = document.getElementById('aiServicio');
  const agrupSel = document.getElementById('aiAgrupacion');
  const areaSel = document.getElementById('aiArea');
  const fuenteSel = document.querySelector('select[name="id_fuente"]');

  function filtrarPorServicio(selectEl, servicioId) {
    if (!selectEl) return;
    Array.from(selectEl.options).forEach((opt, idx) => {
      if (idx === 0) {
        opt.hidden = false;
        return;
      }
      const match = !servicioId || opt.dataset.servicio === servicioId;
      opt.hidden = !match;
      if (!match && opt.selected) {
        selectEl.value = '';
      }
    });
  }

  servicioSel?.addEventListener('change', function () {
    filtrarPorServicio(agrupSel, this.value);
    filtrarPorServicio(areaSel, this.value);
  });

  fuenteSel?.addEventListener('change', function () {
    const servicioId = this.selectedOptions[0]?.dataset.servicio || '';
    if (servicioId && servicioSel) {
      servicioSel.value = servicioId;
      servicioSel.dispatchEvent(new Event('change'));
    }
  });
});
</script>
</body>
</html>
