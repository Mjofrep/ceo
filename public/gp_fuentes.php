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
gpRequireRole(['ADMIN', 'CREADOR', 'REVISOR']);
$auth = gpAuth();
$msg = '';
$error = '';

function gpFuenteStorageDir(): string
{
    return dirname(__DIR__) . '/storage/gestor_preguntas/' . date('Y');
}

function gpFuenteStorageRel(string $fileName): string
{
    return 'storage/gestor_preguntas/' . date('Y') . '/' . $fileName;
}

function gpFuenteNewDb(): PDO
{
    return new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function gpEnsureAgrupacionOriginTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_agrupacion_origen (
      id INT NOT NULL AUTO_INCREMENT,
      destino ENUM('HABILITACION','FORMACION') NOT NULL,
      id_agrupacion INT NOT NULL,
      origen VARCHAR(40) NOT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_gp_agrupacion_origen (destino, id_agrupacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function gpSetAgrupacionOrigin(PDO $pdo, string $destino, int $idAgrupacion, string $origen): void
{
    if ($idAgrupacion <= 0 || !in_array($destino, ['HABILITACION', 'FORMACION'], true) || trim($origen) === '') {
        return;
    }
    gpEnsureAgrupacionOriginTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO ceo_gp_agrupacion_origen (destino, id_agrupacion, origen) VALUES (:destino, :id_agrupacion, :origen) ON DUPLICATE KEY UPDATE origen = origen');
    $stmt->execute([
        ':destino' => $destino,
        ':id_agrupacion' => $idAgrupacion,
        ':origen' => $origen,
    ]);
}

function gpFuenteLogDir(): string
{
    return dirname(__DIR__) . '/storage/gestor_preguntas/logs';
}

function gpFuenteLogPath(): string
{
    return gpFuenteLogDir() . '/gp_fuentes_error.log';
}

function gpFuenteLog(string $message, array $context = []): void
{
    $dir = gpFuenteLogDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @error_log($line . PHP_EOL, 3, gpFuenteLogPath());
}

function gpFuenteRegisterShutdownLog(): void
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
        gpFuenteLog('Fatal en gp_fuentes.php', [
            'type' => (int)$error['type'],
            'message' => (string)$error['message'],
            'file' => (string)$error['file'],
            'line' => (int)$error['line'],
            'modo_uso' => (string)($_POST['modo_uso'] ?? ''),
        ]);
    });
}

function gpCleanExtractedText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function gpXmlText(string $xml): string
{
    $xml = preg_replace('/<w:tab\/>/i', ' ', $xml) ?? $xml;
    $xml = preg_replace('/<w:br\/>/i', "\n", $xml) ?? $xml;
    $xml = preg_replace('/<\/?[^>]+>/', ' ', $xml) ?? $xml;
    return gpCleanExtractedText($xml);
}

function gpExtractTxtCsv(string $path): string
{
    $text = (string)file_get_contents($path);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
        if (is_string($converted)) {
            $text = $converted;
        }
    }
    return gpCleanExtractedText($text);
}

function gpExtractDocx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['', 'ZipArchive no esta disponible para leer DOCX.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['', 'No se pudo abrir el DOCX.'];
    }

    $parts = [];
    foreach (['word/document.xml', 'word/header1.xml', 'word/footer1.xml'] as $entry) {
        $xml = $zip->getFromName($entry);
        if (is_string($xml) && $xml !== '') {
            $parts[] = gpXmlText($xml);
        }
    }
    $zip->close();

    return [gpCleanExtractedText(implode("\n\n", array_filter($parts))), ''];
}

function gpExtractXlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return ['', 'ZipArchive no esta disponible para leer XLSX.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['', 'No se pudo abrir el XLSX.'];
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        if (preg_match_all('/<si[^>]*>(.*?)<\/si>/is', $sharedXml, $matches)) {
            foreach ($matches[1] as $si) {
                $sharedStrings[] = gpXmlText($si);
            }
        }
    }

    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            continue;
        }
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || $xml === '') {
            continue;
        }
        if (preg_match_all('/<c[^>]*(?:t="([^"]+)")?[^>]*>(.*?)<\/c>/is', $xml, $cells, PREG_SET_ORDER)) {
            foreach ($cells as $cell) {
                $type = $cell[1] ?? '';
                $body = $cell[2] ?? '';
                if ($type === 's' && preg_match('/<v[^>]*>(.*?)<\/v>/is', $body, $m)) {
                    $idx = (int)trim($m[1]);
                    if (isset($sharedStrings[$idx])) {
                        $parts[] = $sharedStrings[$idx];
                    }
                } elseif ($type === 'inlineStr') {
                    $parts[] = gpXmlText($body);
                } elseif (preg_match('/<v[^>]*>(.*?)<\/v>/is', $body, $m)) {
                    $parts[] = trim($m[1]);
                }
            }
        }
    }
    $zip->close();

    return [gpCleanExtractedText(implode("\n", array_filter($parts))), ''];
}

function gpPdfUnescapeText(string $text): string
{
    $text = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", "\n", ' '], $text);
    $text = preg_replace_callback('/\\\\([0-7]{1,3})/', static function ($m) {
        return chr(octdec($m[1]));
    }, $text) ?? $text;
    return $text;
}

function gpPdfExtractTextOperators(string $content): array
{
    $parts = [];

    if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)\s*Tj/s', $content, $matches)) {
        foreach ($matches[0] as $token) {
            if (preg_match('/^\((.*)\)\s*Tj/s', $token, $m)) {
                $parts[] = gpPdfUnescapeText($m[1]);
            }
        }
    }

    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $matches)) {
        foreach ($matches[1] as $chunk) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $chunk, $texts)) {
                foreach ($texts[0] as $token) {
                    $parts[] = gpPdfUnescapeText(substr($token, 1, -1));
                }
            }
        }
    }

    return $parts;
}

function gpExtractPdfBasic(string $path): array
{
    $raw = (string)file_get_contents($path);
    $contents = [$raw];

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
        foreach ($streams[1] as $stream) {
            foreach (['gzuncompress', 'gzdecode', 'gzinflate'] as $decoder) {
                $decoded = @$decoder($stream);
                if (is_string($decoded) && $decoded !== '') {
                    $contents[] = $decoded;
                    break;
                }
            }
        }
    }

    $parts = [];
    foreach ($contents as $content) {
        $parts = array_merge($parts, gpPdfExtractTextOperators($content));
    }

    $text = implode("\n", $parts);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
        if (is_string($converted)) {
            $text = $converted;
        }
    }
    $text = gpCleanExtractedText($text);
    $error = $text === '' ? 'PDF guardado, pero sin texto embebido extraible. OCR no disponible.' : '';
    return [$text, $error];
}

function gpPdfTextQuality(string $text): int
{
    $score = 0;
    $chars = mb_strlen(trim($text), 'UTF-8');
    if ($chars > 1000) {
        $score += 2;
    } elseif ($chars > 250) {
        $score += 1;
    }
    if (preg_match_all('/(?:^|\R)\s*\d{1,3}[\.)]\s+/u', $text, $m) && count($m[0]) >= 3) {
        $score += 2;
    }
    if (preg_match_all('/(?:^|\R|\s)[a-eA-E][\.)]\s+/u', $text, $m) && count($m[0]) >= 8) {
        $score += 2;
    }
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    $shortLines = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line, 'UTF-8') <= 3) {
            $shortLines++;
        }
    }
    if (count($lines) > 0 && ($shortLines / max(1, count($lines))) > 0.45) {
        $score -= 3;
    }
    return $score;
}

function gpExtractPdfWithPdftotext(string $path): array
{
    $binary = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($binary === '') {
        return ['', 'pdftotext no esta disponible en el servidor.'];
    }

    $cmd = escapeshellcmd($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>&1';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return ['', 'pdftotext no pudo extraer texto del PDF.'];
    }

    $text = gpCleanExtractedText($output);
    return [$text, ''];
}

function gpExtractTextFromDocument(string $path, string $ext): array
{
    $ext = strtolower($ext);
    try {
        if (in_array($ext, ['txt', 'csv'], true)) {
            return [gpExtractTxtCsv($path), ''];
        }
        if ($ext === 'docx') {
            return gpExtractDocx($path);
        }
        if ($ext === 'xlsx') {
            return gpExtractXlsx($path);
        }
        if ($ext === 'pdf') {
            [$pdftotext, $pdfToolError] = gpExtractPdfWithPdftotext($path);
            [$basicText, $basicError] = gpExtractPdfBasic($path);

            $text = gpPdfTextQuality($pdftotext) >= gpPdfTextQuality($basicText) ? $pdftotext : $basicText;
            $errorParts = [];
            if ($pdfToolError !== '') {
                $errorParts[] = $pdfToolError;
            }
            if ($basicError !== '') {
                $errorParts[] = $basicError;
            }
            if ($text === '') {
                $errorParts[] = 'No fue posible extraer texto utilizable del PDF.';
            } elseif (gpPdfTextQuality($text) < 2) {
                $errorParts[] = 'El texto extraido del PDF tiene baja calidad para detectar preguntas.';
            }
            return [$text, implode(' ', array_unique($errorParts))];
        }
    } catch (Throwable $e) {
        return ['', $e->getMessage()];
    }

    return ['', 'Tipo de archivo no soportado.'];
}

function gpUploadedDocuments(?array $fileInfo): array
{
    if (!$fileInfo || empty($fileInfo['name'])) {
        return [];
    }

    $names = is_array($fileInfo['name']) ? $fileInfo['name'] : [$fileInfo['name']];
    $tmpNames = is_array($fileInfo['tmp_name'] ?? null) ? $fileInfo['tmp_name'] : [$fileInfo['tmp_name'] ?? ''];
    $types = is_array($fileInfo['type'] ?? null) ? $fileInfo['type'] : [$fileInfo['type'] ?? ''];
    $sizes = is_array($fileInfo['size'] ?? null) ? $fileInfo['size'] : [$fileInfo['size'] ?? 0];
    $errors = is_array($fileInfo['error'] ?? null) ? $fileInfo['error'] : [$fileInfo['error'] ?? UPLOAD_ERR_NO_FILE];

    $uploads = [];
    foreach ($names as $i => $name) {
        if ((int)($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || trim((string)$name) === '') {
            continue;
        }
        $uploads[] = [
            'name' => (string)$name,
            'tmp_name' => (string)($tmpNames[$i] ?? ''),
            'type' => (string)($types[$i] ?? ''),
            'size' => (int)($sizes[$i] ?? 0),
            'error' => (int)($errors[$i] ?? UPLOAD_ERR_OK),
        ];
    }

    return $uploads;
}

function gpFetchCatalog(PDO $pdo, string $destino): array
{
    if ($destino === 'FORMACION') {
        return [
            'servicios' => $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC),
            'agrupaciones' => $pdo->query('SELECT id, titulo, id_servicio FROM ceo_formacion_agrupacion ORDER BY titulo ASC')->fetchAll(PDO::FETCH_ASSOC),
            'areas' => $pdo->query('SELECT MIN(id) AS id, descripcion, id_servicio FROM ceo_areacompetencia_formacion GROUP BY descripcion, id_servicio ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    return [
        'servicios' => $pdo->query('SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC),
        'agrupaciones' => $pdo->query('SELECT id, titulo, id_servicio FROM ceo_agrupacion ORDER BY titulo ASC')->fetchAll(PDO::FETCH_ASSOC),
        'areas' => $pdo->query('SELECT id, descripcion, id_servicio FROM ceo_areacompetencias ORDER BY descripcion ASC')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function gpResolveFuenteAgrupacion(PDO $pdo, string $destino, int $idServicio, string $titulo): array
{
    $titulo = trim($titulo);
    if ($titulo === '' || $idServicio <= 0 || !in_array($destino, ['HABILITACION', 'FORMACION'], true)) {
        throw new RuntimeException('No se pudo resolver la agrupacion automatica para la fuente.');
    }

    $table = $destino === 'FORMACION' ? 'ceo_formacion_agrupacion' : 'ceo_agrupacion';
    $stmt = $pdo->prepare("SELECT id, titulo FROM {$table} WHERE id_servicio = :id_servicio AND titulo = :titulo LIMIT 1");
    $stmt->execute([
        ':id_servicio' => $idServicio,
        ':titulo' => $titulo,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return [
            'id' => (int)$row['id'],
            'titulo' => (string)$row['titulo'],
            'created' => false,
        ];
    }

    $stmtInsert = $pdo->prepare("INSERT INTO {$table} (titulo, id_servicio) VALUES (:titulo, :id_servicio)");
    $stmtInsert->execute([
        ':titulo' => $titulo,
        ':id_servicio' => $idServicio,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'titulo' => $titulo,
        'created' => true,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gpFuenteRegisterShutdownLog();
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        try {
            if ($accion === 'crear') {
                $titulo = trim((string)($_POST['titulo'] ?? ''));
                $destino = (string)($_POST['destino'] ?? '');
                $idServicio = (int)($_POST['id_servicio'] ?? 0);
                $idAgrupacion = (int)($_POST['id_agrupacion'] ?? 0);
                $idArea = (int)($_POST['id_area'] ?? 0);
                $modoUso = (string)($_POST['modo_uso'] ?? 'IA');
                $textoManual = gpCleanExtractedText((string)($_POST['texto_fuente'] ?? ''));

                if ($titulo === '' || !in_array($destino, ['HABILITACION', 'FORMACION'], true) || $idServicio <= 0) {
                    throw new RuntimeException('Titulo, destino y servicio son obligatorios.');
                }
                if (!in_array($modoUso, ['IA', 'IMPORTAR_PREGUNTAS', 'EXTRAER_PREGUNTAS_IA'], true)) {
                    throw new RuntimeException('Modo de uso invalido.');
                }

                $allowed = ['txt', 'csv', 'xlsx', 'docx', 'pptx', 'pdf'];
                $uploads = gpUploadedDocuments($_FILES['documento'] ?? null);
                if (!$uploads && $textoManual === '') {
                    throw new RuntimeException('Debes ingresar texto manual o cargar un documento.');
                }
                if ($modoUso === 'IMPORTAR_PREGUNTAS' && !$uploads) {
                    throw new RuntimeException('Para importar preguntas existentes debes cargar al menos un documento PDF o XLSX.');
                }
                if ($modoUso === 'EXTRAER_PREGUNTAS_IA' && !$uploads) {
                    throw new RuntimeException('Para extraer preguntas con IA debes cargar al menos un PDF.');
                }

                $documents = [];
                $extractedTexts = [];
                $tipoOrigen = 'MANUAL';
                $agrupacionAutoMsg = '';

                if ($uploads) {
                    $dir = gpFuenteStorageDir();
                    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                        throw new RuntimeException('No se pudo crear el directorio de almacenamiento.');
                    }

                    $tipoOrigen = count($uploads) > 1 ? 'MIXTO' : strtoupper(pathinfo($uploads[0]['name'], PATHINFO_EXTENSION));

                    foreach ($uploads as $upload) {
                        if ($upload['error'] !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('Uno de los documentos no se pudo cargar correctamente.');
                        }
                        if (!is_uploaded_file($upload['tmp_name'])) {
                            throw new RuntimeException('Carga de documento invalida.');
                        }

                        $original = $upload['name'];
                        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowed, true)) {
                            throw new RuntimeException('Tipo de archivo no permitido. Usa TXT, CSV, XLSX, DOCX, PPTX o PDF.');
                        }
                        if ($modoUso === 'IMPORTAR_PREGUNTAS' && !in_array($ext, ['pdf', 'xlsx'], true)) {
                            throw new RuntimeException('La importacion de preguntas existentes soporta solo PDF o XLSX.');
                        }
                        if ($modoUso === 'EXTRAER_PREGUNTAS_IA' && $ext !== 'pdf') {
                            throw new RuntimeException('La extraccion de preguntas con IA soporta solo documentos PDF.');
                        }
                        if ($upload['size'] > 15 * 1024 * 1024) {
                            throw new RuntimeException('El archivo ' . $original . ' supera el maximo permitido de 15 MB.');
                        }

                        $safeBase = preg_replace('/[^A-Za-z0-9_.-]+/', '_', pathinfo($original, PATHINFO_FILENAME)) ?: 'documento';
                        $savedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
                        $target = $dir . '/' . $savedName;
                        if (!move_uploaded_file($upload['tmp_name'], $target)) {
                            throw new RuntimeException('No se pudo guardar el documento.');
                        }

                        [$extractedText, $extractError] = gpExtractTextFromDocument($target, $ext);
                        if ($extractedText !== '') {
                            $extractedTexts[] = $extractedText;
                        }
                        $documents[] = [
                            'nombre_original' => $original,
                            'ruta_archivo' => gpFuenteStorageRel($savedName),
                            'mime_type' => $upload['type'],
                            'extension' => $ext,
                            'tamano_bytes' => $upload['size'],
                            'texto_extraido' => $extractedText,
                            'estado' => $extractedText !== '' ? 'ACTIVO' : 'SIN_TEXTO',
                            'error_text' => $extractError,
                        ];
                    }
                }

                $textoFuente = gpCleanExtractedText(trim($textoManual . "\n\n" . implode("\n\n", $extractedTexts)));

                if ($idAgrupacion <= 0 && in_array($modoUso, ['IA', 'IMPORTAR_PREGUNTAS', 'EXTRAER_PREGUNTAS_IA'], true)) {
                    $agrupacionAuto = gpResolveFuenteAgrupacion($pdo, $destino, $idServicio, $titulo);
                    $idAgrupacion = (int)$agrupacionAuto['id'];
                    gpSetAgrupacionOrigin($pdo, $destino, $idAgrupacion, 'GESTOR_PREGUNTAS');
                    $agrupacionAutoMsg = !empty($agrupacionAuto['created'])
                        ? 'Agrupacion creada automaticamente: ' . (string)$agrupacionAuto['titulo'] . '.'
                        : 'Agrupacion reutilizada automaticamente: ' . (string)$agrupacionAuto['titulo'] . '.';
                }

                $pdo->beginTransaction();
                $stmtFuente = $pdo->prepare('INSERT INTO ceo_gp_fuentes (titulo, destino, id_servicio, id_agrupacion, id_area, tipo_origen, modo_uso, parser_tipo, import_estado, texto_fuente, estado, creado_por) VALUES (:titulo, :destino, :id_servicio, :id_agrupacion, :id_area, :tipo_origen, :modo_uso, :parser_tipo, :import_estado, :texto_fuente, "ACTIVA", :creado_por)');
                $stmtFuente->execute([
                    ':titulo' => $titulo,
                    ':destino' => $destino,
                    ':id_servicio' => $idServicio,
                    ':id_agrupacion' => $idAgrupacion > 0 ? $idAgrupacion : null,
                    ':id_area' => $idArea > 0 ? $idArea : null,
                    ':tipo_origen' => $tipoOrigen,
                    ':modo_uso' => $modoUso,
                    ':parser_tipo' => $modoUso === 'IMPORTAR_PREGUNTAS' ? 'AUTO' : ($modoUso === 'EXTRAER_PREGUNTAS_IA' ? 'OPENAI_PDF' : null),
                    ':import_estado' => in_array($modoUso, ['IMPORTAR_PREGUNTAS', 'EXTRAER_PREGUNTAS_IA'], true) ? 'PENDIENTE' : null,
                    ':texto_fuente' => $textoFuente,
                    ':creado_por' => (int)($auth['id'] ?? 0) ?: null,
                ]);
                $idFuente = (int)$pdo->lastInsertId();

                if ($documents) {
                    $stmtDoc = $pdo->prepare('INSERT INTO ceo_gp_documentos (id_fuente, nombre_original, ruta_archivo, mime_type, extension, tamano_bytes, texto_extraido, estado, error_text) VALUES (:id_fuente, :nombre_original, :ruta_archivo, :mime_type, :extension, :tamano_bytes, :texto_extraido, :estado, :error_text)');
                    foreach ($documents as $document) {
                        $stmtDoc->execute([
                            ':id_fuente' => $idFuente,
                            ':nombre_original' => $document['nombre_original'],
                            ':ruta_archivo' => $document['ruta_archivo'],
                            ':mime_type' => $document['mime_type'] !== '' ? $document['mime_type'] : null,
                            ':extension' => $document['extension'],
                            ':tamano_bytes' => $document['tamano_bytes'],
                            ':texto_extraido' => $document['texto_extraido'] !== '' ? $document['texto_extraido'] : null,
                            ':estado' => $document['estado'],
                            ':error_text' => $document['error_text'] !== '' ? $document['error_text'] : null,
                        ]);
                    }
                }

                if ($modoUso === 'EXTRAER_PREGUNTAS_IA') {
                    $pdo->commit();
                    // No mantener transacciones abiertas mientras esperamos respuesta de OpenAI.
                    $pdo = gpFuenteNewDb();
                }

                if ($modoUso === 'IMPORTAR_PREGUNTAS') {
                    $records = [];
                    $importErrors = [];
                    foreach ($documents as $document) {
                        $docPath = dirname(__DIR__) . '/' . $document['ruta_archivo'];
                        $textForImport = strtolower((string)$document['extension']) === 'pdf' && $textoManual !== ''
                            ? $textoManual
                            : (string)$document['texto_extraido'];
                        try {
                            $records = array_merge($records, gpImpParseDocument($docPath, $document['extension'], $textForImport));
                        } catch (Throwable $parseError) {
                            $importErrors[] = (string)$document['nombre_original'] . ': ' . $parseError->getMessage();
                        }
                    }

                    if (!$records) {
                        $resumen = trim('No se detectaron preguntas importables. Si el documento contiene preguntas, pegue el texto en "Texto manual opcional" y vuelva a cargarlo. ' . implode(' ', $importErrors) . ' ' . $agrupacionAutoMsg);
                        $stmtImp = $pdo->prepare('UPDATE ceo_gp_fuentes SET import_estado = "ERROR", import_resumen = :resumen WHERE id = :id');
                        $stmtImp->execute([':resumen' => trim($resumen), ':id' => $idFuente]);
                    } else {
                        $result = gpImpInsertQuestions($pdo, $idFuente, $records, (int)($auth['id'] ?? 0));
                        $resumen = 'Preguntas importadas a REVISION: ' . (int)$result['preguntas'] . '. Alternativas: ' . (int)$result['alternativas'] . '.';
                        if (!empty($result['omitidas'])) {
                            $resumen .= ' Omitidas: ' . count($result['omitidas']) . ' (' . implode(' | ', array_slice($result['omitidas'], 0, 5)) . ')';
                        }
                        if ($importErrors) {
                            $resumen .= ' Advertencias: ' . implode(' | ', $importErrors);
                        }
                        if ($agrupacionAutoMsg !== '') {
                            $resumen .= ' ' . $agrupacionAutoMsg;
                        }
                        $importEstado = (int)$result['preguntas'] > 0 ? 'IMPORTADO' : 'ERROR';
                        $stmtImp = $pdo->prepare('UPDATE ceo_gp_fuentes SET import_estado = :estado, import_resumen = :resumen WHERE id = :id');
                        $stmtImp->execute([':estado' => $importEstado, ':resumen' => $resumen, ':id' => $idFuente]);
                    }
                } elseif ($modoUso === 'EXTRAER_PREGUNTAS_IA') {
                    gpFuenteLog('Inicio extraccion IA PDF', [
                        'titulo' => $titulo,
                        'destino' => $destino,
                        'id_servicio' => $idServicio,
                        'documentos' => count($documents),
                        'modelo' => gpAiPdfModel(),
                    ]);
                    $apiKey = gpAiPdfApiKey();
                    if ($apiKey === '') {
                        throw new RuntimeException('No existe OPENAI_API_KEY_LOCAL en config/ceonext_ai.php.');
                    }

                    $records = [];
                    $importErrors = [];
                    foreach ($documents as $document) {
                        $docPath = dirname(__DIR__) . '/' . $document['ruta_archivo'];
                        try {
                            gpFuenteLog('Enviando PDF a OpenAI', [
                                'documento' => (string)$document['nombre_original'],
                                'bytes' => (int)$document['tamano_bytes'],
                            ]);
                            $extractResult = gpAiExtractQuestionsFromPdfDetailed($apiKey, gpAiPdfModel(), $docPath, [
                                'destino' => $destino,
                                'servicio' => (string)$idServicio,
                                'agrupacion' => $idAgrupacion > 0 ? (string)$idAgrupacion : '',
                            ]);
                            $records = array_merge($records, $extractResult['records'] ?? []);
                            foreach (($extractResult['warnings'] ?? []) as $warning) {
                                $importErrors[] = (string)$document['nombre_original'] . ': ' . (string)$warning;
                            }
                        } catch (Throwable $aiError) {
                            gpFuenteLog('Error extraccion IA PDF', [
                                'documento' => (string)$document['nombre_original'],
                                'error' => $aiError->getMessage(),
                            ]);
                            $importErrors[] = (string)$document['nombre_original'] . ': ' . $aiError->getMessage();
                        }
                    }

                    $pdo = gpFuenteNewDb();

                    if (!$records) {
                        $resumen = trim('OpenAI no pudo extraer preguntas importables desde el PDF. ' . implode(' ', $importErrors) . ' ' . $agrupacionAutoMsg);
                        $stmtImp = $pdo->prepare('UPDATE ceo_gp_fuentes SET import_estado = "ERROR", import_resumen = :resumen WHERE id = :id');
                        $stmtImp->execute([':resumen' => trim($resumen), ':id' => $idFuente]);
                    } else {
                        $result = gpImpInsertQuestions($pdo, $idFuente, $records, (int)($auth['id'] ?? 0), 'IA');
                        $resumen = 'Preguntas extraidas con IA a REVISION: ' . (int)$result['preguntas'] . '. Alternativas: ' . (int)$result['alternativas'] . '.';
                        if (!empty($result['omitidas'])) {
                            $resumen .= ' Omitidas: ' . count($result['omitidas']) . ' (' . implode(' | ', array_slice($result['omitidas'], 0, 5)) . ')';
                        }
                        if ($importErrors) {
                            $resumen .= ' Advertencias: ' . implode(' | ', $importErrors);
                        }
                        if ($agrupacionAutoMsg !== '') {
                            $resumen .= ' ' . $agrupacionAutoMsg;
                        }
                        $importEstado = (int)$result['preguntas'] > 0 ? 'IMPORTADO' : 'ERROR';
                        $stmtImp = $pdo->prepare('UPDATE ceo_gp_fuentes SET import_estado = :estado, import_resumen = :resumen WHERE id = :id');
                        $stmtImp->execute([':estado' => $importEstado, ':resumen' => $resumen, ':id' => $idFuente]);
                    }
                    gpFuenteLog('Fin extraccion IA PDF', ['resumen' => $resumen ?? '']);
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                if (in_array($modoUso, ['IMPORTAR_PREGUNTAS', 'EXTRAER_PREGUNTAS_IA'], true)) {
                    $msg = $resumen ?? 'Preguntas importadas correctamente a REVISION.';
                } else {
                    $msg = $textoFuente !== ''
                    ? 'Fuente guardada correctamente. Texto disponible para IA: ' . number_format(mb_strlen($textoFuente), 0, ',', '.') . ' caracteres.'
                    : 'Fuente guardada, pero sin texto utilizable para IA. OCR no disponible.';
                    if ($agrupacionAutoMsg !== '') {
                        $msg .= ' ' . $agrupacionAutoMsg;
                    }
                }
            } elseif ($accion === 'anular') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('Fuente invalida.');
                }
                $stmt = $pdo->prepare('UPDATE ceo_gp_fuentes SET estado = "ANULADA" WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $msg = 'Fuente anulada correctamente.';
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackError) {
                    gpFuenteLog('Error al revertir transaccion', ['error' => $rollbackError->getMessage()]);
                }
            }
            gpFuenteLog('Excepcion en gp_fuentes.php', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'modo_uso' => (string)($_POST['modo_uso'] ?? ''),
            ]);
            $error = $e->getMessage();
            try {
                $pdo = gpFuenteNewDb();
            } catch (Throwable $reconnectError) {
                gpFuenteLog('Error al reconectar BD', ['error' => $reconnectError->getMessage()]);
            }
        }
    }
}

try {
    $pdo->query('SELECT 1');
} catch (Throwable $dbError) {
    gpFuenteLog('Reconectando BD antes de renderizar', ['error' => $dbError->getMessage()]);
    $pdo = gpFuenteNewDb();
}

$catalogHab = gpFetchCatalog($pdo, 'HABILITACION');
$catalogFor = gpFetchCatalog($pdo, 'FORMACION');
$sources = $pdo->query("SELECT f.*,
        CASE WHEN f.destino = 'FORMACION'
             THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = f.id_servicio LIMIT 1)
             ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = f.id_servicio LIMIT 1)
        END AS servicio,
        CASE WHEN f.destino = 'FORMACION'
             THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = f.id_agrupacion LIMIT 1)
             ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = f.id_agrupacion LIMIT 1)
        END AS agrupacion,
        d.documentos_resumen,
        CHAR_LENGTH(f.texto_fuente) AS chars_fuente
    FROM ceo_gp_fuentes f
    LEFT JOIN (
        SELECT id_fuente, GROUP_CONCAT(CONCAT(nombre_original, '||', extension, '||', estado, '||', COALESCE(error_text, '')) SEPARATOR '##DOC##') AS documentos_resumen
        FROM ceo_gp_documentos
        GROUP BY id_fuente
    ) d ON d.id_fuente = f.id
    ORDER BY f.id DESC
    LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Fuentes | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;}
    .topbar{background:#fff;border-bottom:1px solid rgba(13,110,253,.12);box-shadow:0 1px 6px rgba(15,23,42,.04);}
    .card{border:0;border-radius:20px;box-shadow:0 10px 28px rgba(15,23,42,.07);}
    .text-preview{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-bold h5 mb-0">Fuentes y documentos</div>
      <small class="text-muted">Documentos para generar preguntas con IA, sin OCR</small>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_home.php" class="btn btn-outline-primary btn-sm">Inicio</a>
      <a href="gp_logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= gpEsc($msg) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= gpEsc($error) ?></div><?php endif; ?>

  <div class="card p-4 mb-4">
    <h2 class="h5 fw-bold mb-3">Nueva fuente</h2>
    <form method="post" enctype="multipart/form-data" id="formFuente">
      <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
      <input type="hidden" name="accion" value="crear">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Titulo</label>
          <input type="text" name="titulo" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Destino</label>
          <select name="destino" id="destino" class="form-select" required>
            <option value="HABILITACION">Habilitacion</option>
            <option value="FORMACION">Formacion</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Servicio</label>
          <select name="id_servicio" id="id_servicio" class="form-select" required></select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Agrupacion / tematica</label>
          <select name="id_agrupacion" id="id_agrupacion" class="form-select">
            <option value="">Sin asociar</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Area de competencia</label>
          <select name="id_area" id="id_area" class="form-select">
            <option value="">Todas</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Uso del documento</label>
          <select name="modo_uso" id="modo_uso" class="form-select" required>
            <option value="IA">Generar preguntas con IA</option>
            <option value="IMPORTAR_PREGUNTAS">Importar preguntas existentes para revision</option>
            <option value="EXTRAER_PREGUNTAS_IA">Extraer preguntas existentes con IA desde PDF</option>
          </select>
          <div class="form-text">Importar usa reglas locales. Extraer con IA lee el PDF directo y crea preguntas en REVISION.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Documento</label>
          <input type="file" name="documento[]" class="form-control" accept=".txt,.csv,.xlsx,.docx,.pptx,.pdf" multiple>
          <div class="form-text">Puedes seleccionar varios documentos. Permitidos: TXT, CSV, XLSX, DOCX, PPTX, PDF. Maximo 15 MB por archivo.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Texto manual opcional</label>
          <textarea name="texto_fuente" class="form-control" rows="7" placeholder="Puedes pegar texto base o complementario aqui..."></textarea>
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-3" id="btnGuardarFuente"><i class="bi bi-save me-1"></i><span>Guardar fuente</span></button>
    </form>
  </div>

  <div class="card p-4">
    <h2 class="h5 fw-bold mb-3">Fuentes registradas</h2>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr><th>ID</th><th>Titulo</th><th>Destino</th><th>Servicio</th><th>Documento</th><th>Uso</th><th>Texto IA</th><th>Estado</th><th class="text-end">Acciones</th></tr>
        </thead>
        <tbody>
          <?php if (!$sources): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin fuentes registradas.</td></tr><?php endif; ?>
          <?php foreach ($sources as $src): ?>
            <?php
              $docsResumen = trim((string)($src['documentos_resumen'] ?? ''));
              $docs = $docsResumen !== '' ? explode('##DOC##', $docsResumen) : [];
            ?>
            <tr>
              <td><?= (int)$src['id'] ?></td>
              <td><div class="fw-semibold"><?= gpEsc($src['titulo']) ?></div><div class="small text-muted"><?= gpEsc($src['agrupacion'] ?? '') ?></div></td>
              <td><?= gpEsc($src['destino']) ?></td>
              <td><?= gpEsc($src['servicio'] ?? '') ?></td>
              <td class="text-preview">
                <?php if ($docs): ?>
                  <?php foreach ($docs as $docInfo): ?>
                    <?php
                      [$docName, $docExt, $docState, $docError] = array_pad(explode('||', $docInfo, 4), 4, '');
                      $docBadge = $docState === 'ACTIVO' ? 'success' : ($docState === 'SIN_TEXTO' ? 'warning text-dark' : ($docState === 'ERROR' ? 'danger' : 'secondary'));
                    ?>
                    <div class="mb-1">
                      <span class="badge text-bg-light border"><?= gpEsc(strtoupper($docExt)) ?></span>
                      <?= gpEsc($docName) ?><br>
                      <span class="badge text-bg-<?= gpEsc($docBadge) ?>"><?= gpEsc($docState) ?></span>
                      <?php if ($docError !== ''): ?><span class="small text-muted"><?= gpEsc($docError) ?></span><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-muted">Texto manual</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $modoBadge = ($src['modo_uso'] ?? 'IA') === 'IMPORTAR_PREGUNTAS' ? 'info' : (($src['modo_uso'] ?? 'IA') === 'EXTRAER_PREGUNTAS_IA' ? 'warning text-dark' : 'primary'); ?>
                <span class="badge text-bg-<?= gpEsc($modoBadge) ?>"><?= gpEsc($src['modo_uso'] ?? 'IA') ?></span>
                <?php if (!empty($src['import_estado'])): ?><div class="small text-muted mt-1"><?= gpEsc($src['import_estado']) ?></div><?php endif; ?>
                <?php if (!empty($src['import_resumen'])): ?><div class="small text-muted mt-1"><?= gpEsc($src['import_resumen']) ?></div><?php endif; ?>
              </td>
              <td><?= number_format((int)($src['chars_fuente'] ?? 0), 0, ',', '.') ?> caracteres</td>
              <td><span class="badge text-bg-<?= $src['estado'] === 'ACTIVA' ? 'success' : 'secondary' ?>"><?= gpEsc($src['estado']) ?></span></td>
              <td class="text-end">
                <?php if ($src['estado'] === 'ACTIVA'): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('¿Anular esta fuente?');">
                    <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
                    <input type="hidden" name="accion" value="anular">
                    <input type="hidden" name="id" value="<?= (int)$src['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Anular</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<div class="modal fade" id="modalProcesandoFuente" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-body p-4 text-center">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true"></div>
        <h5 class="fw-bold mb-2">Estamos trabajando</h5>
        <p class="text-muted mb-0" id="mensajeProcesandoFuente">Estamos procesando la fuente. No cierres esta ventana.</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const catalogs = <?= json_encode(['HABILITACION' => $catalogHab, 'FORMACION' => $catalogFor], JSON_UNESCAPED_UNICODE) ?>;
const destino = document.getElementById('destino');
const servicio = document.getElementById('id_servicio');
const agrupacion = document.getElementById('id_agrupacion');
const area = document.getElementById('id_area');
const modoUso = document.getElementById('modo_uso');
const formFuente = document.getElementById('formFuente');
const btnGuardarFuente = document.getElementById('btnGuardarFuente');
const mensajeProcesandoFuente = document.getElementById('mensajeProcesandoFuente');

function option(value, label) {
  const opt = document.createElement('option');
  opt.value = value;
  opt.textContent = label;
  return opt;
}

function renderServicios() {
  const data = catalogs[destino.value] || {servicios: [], agrupaciones: [], areas: []};
  servicio.innerHTML = '';
  data.servicios.forEach(s => servicio.appendChild(option(s.id, s.servicio)));
  renderDependientes();
}

function renderDependientes() {
  const data = catalogs[destino.value] || {agrupaciones: [], areas: []};
  const sid = Number(servicio.value || 0);
  agrupacion.innerHTML = '';
  agrupacion.appendChild(option('', 'Sin asociar'));
  data.agrupaciones.filter(a => Number(a.id_servicio) === sid).forEach(a => agrupacion.appendChild(option(a.id, a.titulo)));

  area.innerHTML = '';
  area.appendChild(option('', 'Todas'));
  data.areas.filter(a => Number(a.id_servicio) === sid).forEach(a => area.appendChild(option(a.id, a.descripcion)));
}

destino.addEventListener('change', renderServicios);
servicio.addEventListener('change', renderDependientes);
renderServicios();

if (formFuente) {
  formFuente.addEventListener('submit', function () {
    if (!formFuente.reportValidity()) {
      return;
    }

    const modo = modoUso ? modoUso.value : 'IA';
    let mensaje = 'Estamos procesando la fuente. No cierres esta ventana.';
    if (modo === 'IA') {
      mensaje = 'Preparando fuente para generacion con IA...';
    } else if (modo === 'IMPORTAR_PREGUNTAS') {
      mensaje = 'Analizando documento e importando preguntas...';
    } else if (modo === 'EXTRAER_PREGUNTAS_IA') {
      mensaje = 'Subiendo PDF y extrayendo preguntas con IA...';
    }

    if (mensajeProcesandoFuente) {
      mensajeProcesandoFuente.textContent = mensaje;
    }
    if (btnGuardarFuente) {
      btnGuardarFuente.disabled = true;
      btnGuardarFuente.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><span>Procesando...</span>';
    }
    const modalEl = document.getElementById('modalProcesandoFuente');
    if (modalEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  });
}
</script>
</body>
</html>
