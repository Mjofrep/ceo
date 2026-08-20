<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

$pdo = db();
$message = '';
$error = '';
$generated = [];

function rieEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rieFilePart(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'SinDato';
    }

    $value = strtr($value, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
    ]);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value;
    $value = trim($value, '_');

    return $value !== '' ? $value : 'SinDato';
}

function rieFmtFecha(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return date('d-m-Y');
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y', $ts) : $value;
}

function rieFmtRut(string $rut): string
{
    $clean = strtoupper(preg_replace('/[^0-9Kk]/', '', trim($rut)) ?? '');
    if ($clean === '' || strlen($clean) < 2) {
        return $rut;
    }

    $dv = substr($clean, -1);
    $body = substr($clean, 0, -1);
    if ($body === '' || !ctype_digit($body)) {
        return $rut;
    }

    return number_format((int)$body, 0, '', '.') . '-' . $dv;
}

function rieFechaEmisionBase(): string
{
    return '2026-07-14';
}

function rieFechaVigenciaHasta(): string
{
    return '2029-07-14';
}

function rieValidationUrl(string $token): string
{
    $base = defined('APP_BASE') ? APP_BASE : '';
    $path = rtrim($base, '/') . '/public/validar_rie_certificado.php?token=' . rawurlencode($token);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function rieLogoDataUri(): string
{
    $paths = [
        dirname(__DIR__) . '/config/assets/logo.png',
        dirname(__DIR__) . '/config/assets/ceonext.png',
    ];

    $logoPath = defined('APP_LOGO') ? (string)APP_LOGO : '';
    if ($logoPath !== '') {
        $paths[] = $logoPath;
        $paths[] = __DIR__ . '/' . ltrim($logoPath, '/');
        $paths[] = dirname(__DIR__) . '/' . ltrim($logoPath, '/');
    }

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    return '';
}

function rieRegisterQrAutoload(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $baconBase = dirname(__DIR__) . '/vendor/bacon/bacon-qr-code/src/';
    if (is_dir($baconBase . 'BaconQrCode')) {
        $baconBase .= 'BaconQrCode/';
    }

    if (!is_file($baconBase . 'Encoder/Encoder.php')) {
        throw new RuntimeException('Bacon QR compatible no disponible.');
    }

    $prefixes = [
        'BaconQrCode\\' => $baconBase,
        'DASPRiD\\Enum\\' => dirname(__DIR__) . '/vendor/dasprid/enum/src/',
    ];

    spl_autoload_register(static function (string $class) use ($prefixes): void {
        foreach ($prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }, true, true);
}

function rieQrMatrix(string $text): array
{
    rieRegisterQrAutoload();

    $oldReporting = error_reporting();
    error_reporting($oldReporting & ~E_DEPRECATED);
    try {
        $level = is_callable(['BaconQrCode\\Common\\ErrorCorrectionLevel', 'M'])
            ? \BaconQrCode\Common\ErrorCorrectionLevel::M()
            : new \BaconQrCode\Common\ErrorCorrectionLevel(\BaconQrCode\Common\ErrorCorrectionLevel::M);
        $qr = \BaconQrCode\Encoder\Encoder::encode($text, $level);
        $matrix = $qr->getMatrix();
        $rows = [];
        foreach ($matrix->getArray() as $row) {
            $rows[] = $row instanceof Traversable ? iterator_to_array($row) : (array)$row;
        }
        return $rows;
    } finally {
        error_reporting($oldReporting);
    }
}

function rieQrPngFile(string $text, string $pngPath, string &$error): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        $error = 'La extension GD de PHP no esta disponible para generar el QR PNG.';
        return false;
    }

    try {
        $rows = rieQrMatrix($text);
        $quiet = 4;
        $module = 6;
        $matrixSize = count($rows);
        if ($matrixSize <= 0) {
            $error = 'La matriz QR esta vacia.';
            return false;
        }

        $size = ($matrixSize + ($quiet * 2)) * $module;
        $image = imagecreatetruecolor($size, $size);
        if (!$image) {
            $error = 'No se pudo crear la imagen QR.';
            return false;
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size, $size, $white);

        for ($y = 0; $y < $matrixSize; $y++) {
            for ($x = 0; $x < $matrixSize; $x++) {
                if (empty($rows[$y][$x])) {
                    continue;
                }
                $x1 = ($x + $quiet) * $module;
                $y1 = ($y + $quiet) * $module;
                imagefilledrectangle($image, $x1, $y1, $x1 + $module - 1, $y1 + $module - 1, $black);
            }
        }

        $dir = dirname($pngPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($image);
            $error = 'No se pudo crear el directorio para el QR.';
            return false;
        }

        $ok = imagepng($image, $pngPath, 6);
        imagedestroy($image);

        if (!$ok || !is_file($pngPath) || filesize($pngPath) <= 0) {
            $error = 'No se pudo guardar el archivo PNG del QR.';
            return false;
        }

        return true;
    } catch (Throwable $e) {
        $error = 'QR PNG: ' . $e->getMessage();
        return false;
    }
}

function rieCertificateNumber(array $row): int
{
    return (int)($row['id'] ?? 0);
}

function rieFmtCertificateNumber(int $number): string
{
    return str_pad((string)max(0, $number), 4, '0', STR_PAD_LEFT);
}

function riePdfData(array $row): array
{
    $token = trim((string)($row['token_qr'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('El certificado RIE no tiene token de validacion.');
    }

    return [
        'codigo_certificado' => rieFmtCertificateNumber(rieCertificateNumber($row)),
        'nombre' => trim((string)$row['nombres'] . ' ' . (string)$row['apellidos']),
        'rut' => rieFmtRut((string)$row['rut']),
        'cargo' => trim((string)($row['cargo'] ?? '')),
        'fecha_evaluacion' => rieFechaEmisionBase(),
        'fechavig_fin' => rieFechaVigenciaHasta(),
        'servicio' => trim((string)($row['servicio'] ?? '')) !== ''
            ? trim((string)$row['servicio'])
            : 'Responsable Instalación Eléctrica (RIE)',
        'qr_text' => substr($token, 0, 16) . '...',
        'qr_url' => rieValidationUrl($token),
    ];
}

function rieHtml(array $cert, string $logoDataUri, string $qrSrc): string
{
    ob_start();
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
@page { margin: 22px 42px 24px; }
body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#1f2d3d; font-size:12px; }
.sheet { border:1px solid #d6dce3; padding:20px 28px 14px; }
.top { display: table; width:100%; margin-bottom: 10px; }
.top-left { display: table-cell; width:50%; vertical-align: top; }
.top-right { display: table-cell; width:50%; vertical-align: top; text-align:right; color:#334e68; font-size:12px; line-height:1.5; }
.logo { height:56px; }
.title { text-align:center; color:#0b67a3; font-size:28px; font-weight:bold; margin:40px 0 8px; }
.intro { text-align:center; color:#3f4f5f; font-size:13px; margin-bottom:30px; }
.data { width:86%; margin:0 auto; border-collapse:collapse; }
.data td { padding:8px 6px; vertical-align:bottom; }
.label { width:33%; color:#334e68; font-weight:bold; }
.value { width:67%; border-bottom:1px solid #9aa8b5; color:#111827; font-size:13px; }
.service-label { color:#334e68; font-weight:bold; padding-top:18px !important; }
.service-value { border:1px solid #9aa8b5; background:#f7fbff; padding:10px 12px !important; font-size:14px; font-weight:bold; color:#0b3f66; }
.note { width:86%; margin:28px auto 0; color:#4b5563; line-height:1.45; text-align:justify; }
.validation { width:86%; margin:24px auto 0; border:1px solid #cbd5e1; background:#f8fafc; border-collapse:collapse; table-layout:fixed; }
.validation td { padding:8px 10px; vertical-align:middle; }
.validation-qr { width:132px; text-align:center; }
.validation-qr img { width:112px; height:112px; }
.validation-text { color:#334155; font-size:11px; line-height:1.45; }
.token-short { color:#0b67a3; font-size:9px; }
.signatures { width:86%; margin:34px auto 0; display:table; }
.sign { display:table-cell; width:50%; text-align:center; color:#334e68; font-size:12px; }
.line { border-top:1px solid #6b7280; width:72%; margin:0 auto 8px; }
.footer { margin-top:20px; display: table; width:100%; color:#6b7280; font-size:9px; }
.footer div { display: table-cell; vertical-align: bottom; }
.footer .right { text-align:right; }
</style>
</head>
<body>
<div class="sheet">
  <div class="top">
    <div class="top-left">
      <?php if ($logoDataUri !== ''): ?><img class="logo" src="<?= $logoDataUri ?>" alt="Logo"><?php endif; ?>
    </div>
    <div class="top-right">
      N° Certificado<br><strong><?= rieEsc((string)$cert['codigo_certificado']) ?></strong><br>
      Fecha emisión: <?= rieEsc(rieFmtFecha((string)$cert['fecha_evaluacion'])) ?>
    </div>
  </div>

  <div class="title">Habilitación CEO</div>
  <div class="intro">Se certifica que el trabajador individualizado se encuentra habilitado para el servicio indicado.</div>

  <table class="data">
    <tr><td class="label">Nombre</td><td class="value"><?= rieEsc((string)$cert['nombre']) ?></td></tr>
    <tr><td class="label">RUT</td><td class="value"><?= rieEsc((string)$cert['rut']) ?></td></tr>
    <tr><td class="label">Cargo</td><td class="value"><?= rieEsc((string)$cert['cargo']) ?></td></tr>
    <tr><td class="label">Fecha Evaluación</td><td class="value"><?= rieEsc(rieFmtFecha((string)$cert['fecha_evaluacion'])) ?></td></tr>
    <tr><td class="label">Fecha Vigencia</td><td class="value"><strong><?= rieEsc(rieFmtFecha((string)$cert['fechavig_fin'])) ?></strong></td></tr>
    <tr><td class="service-label">Servicio</td><td class="service-value"><?= rieEsc((string)$cert['servicio']) ?></td></tr>
  </table>

  <div class="note">
    Este certificado acredita la habilitación CEO para el servicio señalado, conforme a los resultados registrados en CEONext y a la vigencia calculada para el proceso correspondiente.
  </div>

  <table class="validation">
    <tr>
      <td class="validation-qr">
        <?php if ($qrSrc !== ''): ?><img src="<?= rieEsc($qrSrc) ?>" alt="QR validación"><?php endif; ?>
      </td>
      <td class="validation-text">
      <strong>Validación del certificado</strong><br>
      Escanee el código QR para consultar este certificado RIE en CEONext.<br>
      Identificador de consulta:<br>
      <span class="token-short"><?= rieEsc((string)$cert['qr_text']) ?></span>
      </td>
    </tr>
  </table>

  <div class="signatures">
    <div class="sign"><div class="line"></div>Centro de Excelencia Operacional</div>
    <div class="sign"><div class="line"></div>Validación CEONext</div>
  </div>

  <div class="footer">
    <div>CEONext - Centro de Excelencia Operacional</div>
    <div class="right">Consulta QR: <?= rieEsc((string)$cert['qr_text']) ?></div>
  </div>
</div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function rieEnsureTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_rie_aprobados (
      id INT AUTO_INCREMENT PRIMARY KEY,
      rut VARCHAR(20) NOT NULL,
      nombres VARCHAR(120) NOT NULL,
      apellidos VARCHAR(160) NOT NULL,
      cargo VARCHAR(160) NOT NULL DEFAULT '',
      empresa VARCHAR(180) NOT NULL,
      servicio VARCHAR(180) NOT NULL DEFAULT '',
      emitido TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_rie_rut (rut)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE 'cargo'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD COLUMN cargo VARCHAR(160) NOT NULL DEFAULT '' AFTER apellidos");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE 'emitido'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD COLUMN emitido TINYINT(1) NOT NULL DEFAULT 0 AFTER empresa");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE 'servicio'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD COLUMN servicio VARCHAR(180) NOT NULL DEFAULT '' AFTER empresa");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE 'token_qr'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD COLUMN token_qr VARCHAR(128) NULL DEFAULT NULL AFTER emitido");
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD UNIQUE KEY uq_rie_token_qr (token_qr)");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE 'qr_actualizado'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE ceo_rie_aprobados ADD COLUMN qr_actualizado TINYINT(1) NOT NULL DEFAULT 0 AFTER token_qr");
    }
}

function rieEnsureToken(PDO $pdo, array $row): array
{
    $token = trim((string)($row['token_qr'] ?? ''));
    if ($token !== '') {
        return $row;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('UPDATE ceo_rie_aprobados SET token_qr = :token WHERE id = :id');
    $stmt->execute([
        ':token' => $token,
        ':id' => (int)$row['id'],
    ]);
    $row['token_qr'] = $token;

    return $row;
}

function rieFetchRow(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, rut, nombres, apellidos, cargo, empresa, servicio, emitido, token_qr, qr_actualizado FROM ceo_rie_aprobados WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('No se encontró el registro RIE solicitado.');
    }
    return $row;
}

function rieStorageDir(): string
{
    $dir = dirname(__DIR__) . '/storage/certificados_rie/' . date('Y');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio de certificados RIE.');
    }
    return $dir;
}

function riePdfFilename(array $row): string
{
    $rut = preg_replace('/[^0-9Kk]/', '', (string)$row['rut']);
    return 'Certificado_RIE_' . $rut . '_' . rieFilePart((string)$row['apellidos']) . '_' . rieFilePart((string)$row['nombres']) . '.pdf';
}

function riePdfPath(array $row): string
{
    return rieStorageDir() . DIRECTORY_SEPARATOR . riePdfFilename($row);
}

function riePdfExists(array $row): bool
{
    return is_file(riePdfPath($row));
}

function rieNeedsQrRefresh(array $row): bool
{
    return (int)($row['qr_actualizado'] ?? 0) !== 1;
}

function rieMarkQrUpdated(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE ceo_rie_aprobados SET qr_actualizado = 1 WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function rieMarkIssued(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE ceo_rie_aprobados SET emitido = 1 WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function rieStreamPdfFile(array $row): void
{
    $path = riePdfPath($row);
    if (!is_file($path)) {
        throw new RuntimeException('El PDF RIE solicitado no existe.');
    }

    $size = filesize($path);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . riePdfFilename($row) . '"');
    if ($size !== false) {
        header('Content-Length: ' . (string)$size);
    }
    readfile($path);
    exit;
}

function rieGeneratePdf(array $row, bool $stream = false): string
{
    $dir = rieStorageDir();
    $path = $dir . DIRECTORY_SEPARATOR . riePdfFilename($row);
    $qrPath = $dir . DIRECTORY_SEPARATOR . 'QR_RIE_' . preg_replace('/[^0-9Kk]/', '', (string)$row['rut']) . '.png';
    $cert = riePdfData($row);

    $qrError = '';
    if (!rieQrPngFile((string)$cert['qr_url'], $qrPath, $qrError)) {
        throw new RuntimeException('No se pudo generar el QR del certificado RIE. ' . $qrError);
    }
    $qrRealPath = realpath($qrPath);
    if ($qrRealPath === false) {
        throw new RuntimeException('No se pudo resolver la ruta del QR RIE.');
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->setChroot(dirname(__DIR__));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(rieHtml($cert, rieLogoDataUri(), 'file://' . $qrRealPath), 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();

    if (file_put_contents($path, $pdf) === false) {
        throw new RuntimeException('No se pudo guardar el PDF RIE.');
    }

    if ($stream) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . riePdfFilename($row) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    return $path;
}

rieEnsureTable($pdo);

try {
    if (isset($_GET['descargar'])) {
        $row = rieFetchRow($pdo, (int)$_GET['descargar']);
        $row = rieEnsureToken($pdo, $row);
        $emitido = (int)($row['emitido'] ?? 0) === 1;
        if (riePdfExists($row) && !rieNeedsQrRefresh($row)) {
            rieStreamPdfFile($row);
        }
        if ($emitido) {
            rieGeneratePdf($row);
            rieMarkIssued($pdo, (int)$row['id']);
            rieMarkQrUpdated($pdo, (int)$row['id']);
            rieStreamPdfFile($row);
        }
        throw new RuntimeException('El certificado aun no ha sido generado. Use el boton Generar primero.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'generar_uno') {
            $row = rieFetchRow($pdo, (int)($_POST['id'] ?? 0));
            $row = rieEnsureToken($pdo, $row);
            $pdfExists = riePdfExists($row);
            $emitido = (int)($row['emitido'] ?? 0) === 1;

            if ($emitido && $pdfExists && !rieNeedsQrRefresh($row)) {
                $message = 'El certificado ya fue emitido para ' . trim((string)$row['nombres'] . ' ' . (string)$row['apellidos']) . ' y no se regeneró.';
            } else {
                $path = rieGeneratePdf($row);
                rieMarkIssued($pdo, (int)$row['id']);
                rieMarkQrUpdated($pdo, (int)$row['id']);
                $row['emitido'] = 1;
                $row['qr_actualizado'] = 1;
                $generated[] = [
                    'row' => $row,
                    'path' => $path,
                ];
                $message = $emitido
                    ? 'Se regeneró el certificado RIE para ' . trim((string)$row['nombres'] . ' ' . (string)$row['apellidos']) . '.'
                    : 'Certificado generado correctamente para ' . trim((string)$row['nombres'] . ' ' . (string)$row['apellidos']) . '.';
            }
        } elseif ($accion === 'generar_todos') {
            $rows = $pdo->query('SELECT id, rut, nombres, apellidos, cargo, empresa, servicio, emitido, token_qr, qr_actualizado FROM ceo_rie_aprobados ORDER BY apellidos, nombres, rut')->fetchAll(PDO::FETCH_ASSOC);
            $skipped = 0;
            foreach ($rows as $row) {
                $row = rieEnsureToken($pdo, $row);
                $pdfExists = riePdfExists($row);
                $emitido = (int)($row['emitido'] ?? 0) === 1;
                if ($emitido && $pdfExists && !rieNeedsQrRefresh($row)) {
                    $skipped++;
                    continue;
                }

                $path = rieGeneratePdf($row);
                rieMarkIssued($pdo, (int)$row['id']);
                rieMarkQrUpdated($pdo, (int)$row['id']);
                $row['emitido'] = 1;
                $row['qr_actualizado'] = 1;
                $generated[] = [
                    'row' => $row,
                    'path' => $path,
                ];
            }
            if ($generated === [] && $skipped > 0) {
                $message = 'Todos los certificados ya estaban emitidos y con PDF disponible. No se regeneró ninguno.';
            } else {
                $message = count($generated) . ' certificado(s) RIE generado(s) correctamente.';
                if ($skipped > 0) {
                    $message .= ' ' . $skipped . ' ya emitido(s) se omitieron.';
                }
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$rows = $pdo->query('SELECT id, rut, nombres, apellidos, cargo, empresa, servicio, emitido, token_qr, qr_actualizado FROM ceo_rie_aprobados ORDER BY apellidos, nombres, rut')->fetchAll(PDO::FETCH_ASSOC);
$storageYear = date('Y');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Certificados RIE - <?= rieEsc(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f6f8fb; }
    .topbar { background:#fff; border-bottom:1px solid #e2e8f0; }
    .card { border:0; box-shadow:0 10px 30px rgba(15, 23, 42, .06); }
    .table td, .table th { vertical-align:middle; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div>
      <div class="h5 mb-1 text-primary">Certificados RIE</div>
      <div class="text-muted small">Listado acotado de personas aprobadas para RIE.</div>
    </div>
    <div class="text-muted small">El servicio del certificado se toma desde <code>ceo_rie_aprobados.servicio</code>.</div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= rieEsc($message) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= rieEsc($error) ?></div>
  <?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body p-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
        <div>
          <h1 class="h5 mb-1">Aprobados RIE</h1>
          <div class="text-muted small">Tabla origen: <code>ceo_rie_aprobados</code>. PDFs guardados en <code>storage/certificados_rie/<?= rieEsc($storageYear) ?></code>.</div>
        </div>
        <form method="post" class="m-0">
          <input type="hidden" name="accion" value="generar_todos">
          <button type="submit" class="btn btn-success" <?= empty($rows) ? 'disabled' : '' ?> onclick="return confirm('¿Generar certificados PDF para todo el listado RIE?');">
            <i class="bi bi-file-earmark-pdf"></i> Generar todos
          </button>
        </form>
      </div>

      <?php if (empty($rows)): ?>
        <div class="alert alert-warning mb-0">
          No hay registros en <code>ceo_rie_aprobados</code>. Carga las 9 personas con las columnas <code>rut</code>, <code>nombres</code>, <code>apellidos</code>, <code>cargo</code>, <code>empresa</code> y <code>servicio</code>.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped align-middle mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>RUT</th>
                <th>Nombres</th>
                <th>Apellidos</th>
                <th>Empresa</th>
                <th>Servicio</th>
                <th>Cargo</th>
                <th>Emitido</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $index => $row): ?>
                <?php $pdfExists = riePdfExists($row); ?>
                <?php $needsQrRefresh = rieNeedsQrRefresh($row); ?>
                <tr>
                  <td><?= $index + 1 ?></td>
                  <td><?= rieEsc(rieFmtRut((string)$row['rut'])) ?></td>
                  <td><?= rieEsc($row['nombres']) ?></td>
                  <td><?= rieEsc($row['apellidos']) ?></td>
                  <td><?= rieEsc($row['empresa']) ?></td>
                  <td><?= rieEsc(trim((string)($row['servicio'] ?? '')) !== '' ? (string)$row['servicio'] : 'Responsable Instalación Eléctrica (RIE)') ?></td>
                  <td><?= rieEsc((string)$row['cargo']) ?></td>
                  <td>
                    <?php if ((int)($row['emitido'] ?? 0) === 1): ?>
                      <span class="badge text-bg-success">Si</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">No</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="d-inline-flex gap-2">
                      <form method="post" class="m-0">
                        <input type="hidden" name="accion" value="generar_uno">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm" <?= ((int)($row['emitido'] ?? 0) === 1 && $pdfExists && !$needsQrRefresh) ? 'disabled' : '' ?>>
                          <i class="bi bi-file-earmark-pdf"></i> Generar
                        </button>
                      </form>
                      <a class="btn btn-outline-primary btn-sm <?= (!$pdfExists && (int)($row['emitido'] ?? 0) !== 1) ? 'disabled' : '' ?>" href="<?= ($pdfExists || (int)($row['emitido'] ?? 0) === 1) ? 'rie_certificados.php?descargar=' . (int)$row['id'] : '#' ?>" target="_blank" rel="noopener" <?= (!$pdfExists && (int)($row['emitido'] ?? 0) !== 1) ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                        <i class="bi bi-download"></i> Descargar
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($generated)): ?>
    <div class="card rounded-4">
      <div class="card-body p-4">
        <h2 class="h6 text-primary mb-3">Archivos generados en esta ejecución</h2>
        <div class="list-group">
          <?php foreach ($generated as $item): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="rie_certificados.php?descargar=<?= (int)$item['row']['id'] ?>" target="_blank" rel="noopener">
              <span><?= rieEsc(trim((string)$item['row']['nombres'] . ' ' . (string)$item['row']['apellidos'])) ?></span>
              <span class="text-muted small"><?= rieEsc(basename((string)$item['path'])) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
