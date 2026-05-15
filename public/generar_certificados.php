<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();
$error = '';
$message = '';

function certEsc(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function certRutKey(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', $rut));
}

function certFmtFecha(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '' || str_starts_with($text, '0000-00-00')) {
        return '';
    }
    $ts = strtotime($text);
    return $ts ? date('d-m-Y', $ts) : $text;
}

function certFmtNota(mixed $value): string
{
    if (!is_numeric((string)$value)) {
        return '';
    }
    return number_format((float)$value, 2, '.', '');
}

function certRegisterQrAutoload(): void
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
        throw new RuntimeException('Bacon QR compatible no disponible. Suba al hosting vendor/bacon/bacon-qr-code version 1.0.3.');
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

function certEnsureQrClasses(): void
{
    certRegisterQrAutoload();
    $classes = [
        'BaconQrCode\\Encoder\\Encoder',
        'BaconQrCode\\Common\\ErrorCorrectionLevel',
    ];
    foreach ($classes as $class) {
        if (!class_exists($class) && !enum_exists($class)) {
            throw new RuntimeException('Clase QR no disponible: ' . $class . '. Verifique que vendor/bacon y vendor/dasprid existan en el hosting.');
        }
    }
}

function certQrMatrix(string $url): array
{
    certEnsureQrClasses();

    $oldReporting = error_reporting();
    error_reporting($oldReporting & ~E_DEPRECATED);
    try {
        $level = is_callable(['BaconQrCode\\Common\\ErrorCorrectionLevel', 'M'])
            ? \BaconQrCode\Common\ErrorCorrectionLevel::M()
            : new \BaconQrCode\Common\ErrorCorrectionLevel(\BaconQrCode\Common\ErrorCorrectionLevel::M);
        $qr = \BaconQrCode\Encoder\Encoder::encode($url, $level);
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

function certQrPngFile(string $url, string $pngPath, string &$error): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        $error = 'La extension GD de PHP no esta disponible para generar el QR PNG.';
        return false;
    }

    try {
        $rows = certQrMatrix($url);
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
        $error = 'QR PNG: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
        return false;
    }
}

function certLogoDataUri(): string
{
    $logoPath = defined('APP_LOGO') ? APP_LOGO : '';
    if ($logoPath === '') {
        return '';
    }

    $paths = [
        $logoPath,
        __DIR__ . '/' . ltrim($logoPath, '/'),
        dirname(__DIR__) . '/' . ltrim($logoPath, '/'),
        dirname(__DIR__) . str_replace('/ceo.noetica.cl', '', $logoPath),
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
        }
    }

    return '';
}

function certValidationUrl(string $token): string
{
    $base = defined('APP_BASE') ? APP_BASE : '';
    $path = rtrim($base, '/') . '/public/validar_certificado.php?token=' . rawurlencode($token);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function certHtml(array $cert, string $logoDataUri, string $qrSrc, string $validationUrl): string
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
      N° Certificado<br><strong><?= certEsc($cert['codigo_certificado']) ?></strong><br>
      Fecha emisión: <?= certEsc(certFmtFecha($cert['fecha_generacion'] ?? date('Y-m-d'))) ?>
    </div>
  </div>

  <div class="title">Habilitación CEO</div>
  <div class="intro">Se certifica que el trabajador individualizado se encuentra habilitado para el servicio indicado.</div>

  <table class="data">
    <tr><td class="label">Nombre</td><td class="value"><?= certEsc(trim((string)$cert['nombre'] . ' ' . (string)$cert['apellidos'])) ?></td></tr>
    <tr><td class="label">RUT</td><td class="value"><?= certEsc($cert['rut']) ?></td></tr>
    <tr><td class="label">Cargo</td><td class="value"><?= certEsc($cert['cargo']) ?></td></tr>
    <tr><td class="label">Empresa Contratista</td><td class="value"><?= certEsc($cert['empresa']) ?></td></tr>
    <tr><td class="label">Fecha Evaluación</td><td class="value"><?= certEsc(certFmtFecha($cert['fecha_evaluacion'])) ?></td></tr>
    <tr><td class="label">Fecha Vigencia</td><td class="value"><strong><?= certEsc(certFmtFecha($cert['fechavig_fin'])) ?></strong></td></tr>
    <tr><td class="service-label">Servicio</td><td class="service-value"><?= certEsc($cert['servicio']) ?></td></tr>
  </table>

  <div class="note">
    Este certificado acredita la habilitación CEO para el servicio señalado, conforme a los resultados registrados en CEONext y a la vigencia calculada para el proceso correspondiente.
  </div>

  <table class="validation">
    <tr>
      <td class="validation-qr">
        <?php if ($qrSrc !== ''): ?><img src="<?= certEsc($qrSrc) ?>" alt="QR validación"><?php endif; ?>
      </td>
      <td class="validation-text">
      <strong>Validación del certificado</strong><br>
      Escanee el código QR para verificar el estado del certificado en CEONext.<br>
      URL pública de validación asociada al token:<br>
      <span class="token-short"><?= certEsc(substr((string)$cert['token'], 0, 16)) ?>...</span>
      </td>
    </tr>
  </table>

  <div class="signatures">
    <div class="sign"><div class="line"></div>Centro de Excelencia Operacional</div>
    <div class="sign"><div class="line"></div>Validación CEONext</div>
  </div>

  <div class="footer">
    <div>CEONext - Centro de Excelencia Operacional</div>
    <div class="right">Token validación: <?= certEsc($cert['token']) ?></div>
  </div>
</div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

function certEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_certificados (
      id INT AUTO_INCREMENT PRIMARY KEY,
      codigo_certificado INT NOT NULL UNIQUE,
      token VARCHAR(128) NOT NULL UNIQUE,
      rut VARCHAR(20) NOT NULL,
      nombre VARCHAR(120) NOT NULL,
      apellidos VARCHAR(160) NOT NULL,
      cargo VARCHAR(160) NOT NULL,
      id_empresa INT NULL,
      empresa VARCHAR(180) NOT NULL,
      id_servicio INT NOT NULL,
      servicio VARCHAR(180) NOT NULL,
      id_proceso INT NULL,
      id_vigencia_general INT NULL,
      id_vigencia_detalle INT NULL,
      fecha_evaluacion DATE NULL,
      fechavig_ini DATE NOT NULL,
      fechavig_fin DATE NOT NULL,
      nombre_archivo VARCHAR(255) NOT NULL,
      ruta_archivo VARCHAR(500) NOT NULL,
      estado ENUM('VIGENTE','REEMPLAZADO','ANULADO') NOT NULL DEFAULT 'VIGENTE',
      generado_por INT NULL,
      fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      reemplazado_por INT NULL,
      fecha_reemplazo DATETIME NULL,
      motivo_anulacion VARCHAR(255) NULL,
      fecha_anulacion DATETIME NULL,
      INDEX idx_cert_rut (rut),
      INDEX idx_cert_servicio (id_servicio),
      INDEX idx_cert_empresa (id_empresa),
      INDEX idx_cert_estado (estado),
      INDEX idx_cert_vigencia (fechavig_fin),
      INDEX idx_cert_proceso (id_proceso)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_certificado_envio (
      id INT AUTO_INCREMENT PRIMARY KEY,
      para TEXT NOT NULL,
      cc TEXT NULL,
      asunto VARCHAR(255) NOT NULL,
      cuerpo TEXT NULL,
      enviado_por INT NULL,
      estado ENUM('ENVIADO','ERROR') NOT NULL,
      error TEXT NULL,
      fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_certificado_envio_detalle (
      id INT AUTO_INCREMENT PRIMARY KEY,
      id_envio INT NOT NULL,
      id_certificado INT NOT NULL,
      INDEX idx_envio (id_envio),
      INDEX idx_certificado (id_certificado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function certCandidateSql(array $where): string
{
    return '
        SELECT
            vd.id AS id_vigencia_detalle,
            vg.id AS id_vigencia_general,
            vd.rut,
            vd.id_servicio,
            vd.id_proceso,
            COALESCE(vg.fechavig_ini, vd.fechavig_ini) AS fechavig_ini_cert,
            COALESCE(vg.fechavig_fin, vd.fechavig_fin) AS fechavig_fin_cert,
            CASE WHEN vg.id IS NULL THEN "DETALLE" ELSE "GENERAL" END AS fuente_vigencia,
            sp.servicio,
            COALESCE(h.empresa, c.id_empresa) AS id_empresa,
            COALESCE(emp_h.nombre, emp_c.nombre, "") AS empresa,
            COALESCE(hp.nombre, c.nombre, "") AS nombre,
            COALESCE(hp.apellidos, c.apellidos, "") AS apellidos,
            COALESCE(NULLIF(TRIM(hp.cargo), ""), cc.cargo, "") AS cargo,
            DATE(rfs.fecha_calculo) AS fecha_evaluacion,
            rfs.nota_prueba,
            rfs.nota_terreno,
            rfs.nota_final,
            rfs.resultado_final
        FROM ceo_vigencia_detalle vd
        LEFT JOIN ceo_vigencia_general vg
          ON REPLACE(REPLACE(REPLACE(UPPER(vg.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(REPLACE(UPPER(vd.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci
         AND CAST(vg.id_proceso AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(vd.id_proceso AS CHAR) COLLATE utf8mb4_unicode_ci
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = vd.id_servicio
        LEFT JOIN ceo_habilitacion h
          ON CAST(h.cuadrilla AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(vd.id_proceso AS CHAR) COLLATE utf8mb4_unicode_ci
         AND h.id_servicio = vd.id_servicio
        LEFT JOIN ceo_habilitacion_participantes hp
          ON CAST(hp.id_cuadrilla AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(h.cuadrilla AS CHAR) COLLATE utf8mb4_unicode_ci
         AND REPLACE(REPLACE(REPLACE(UPPER(hp.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(REPLACE(UPPER(vd.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_contratistas c
          ON REPLACE(REPLACE(REPLACE(UPPER(c.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(REPLACE(UPPER(vd.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
        LEFT JOIN ceo_empresas emp_h ON emp_h.id = h.empresa
        LEFT JOIN ceo_empresas emp_c ON emp_c.id = c.id_empresa
        INNER JOIN ceo_resultado_final_servicio rfs
          ON REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(REPLACE(UPPER(vd.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci
         AND rfs.id_servicio = vd.id_servicio
         AND rfs.segmento = "GENERAL"
         AND rfs.id = (
            SELECT rfs2.id
            FROM ceo_resultado_final_servicio rfs2
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rfs2.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = REPLACE(REPLACE(REPLACE(UPPER(vd.rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci
              AND rfs2.id_servicio = vd.id_servicio
              AND rfs2.segmento = "GENERAL"
            ORDER BY rfs2.fecha_calculo DESC, rfs2.id DESC
            LIMIT 1
         )
        WHERE ' . implode(' AND ', $where) . '
    ';
}

function certFindCandidate(PDO $pdo, int $idVigenciaDetalle): array
{
    if ($idVigenciaDetalle <= 0) {
        throw new RuntimeException('Candidato invalido para generar certificado.');
    }

    $postWhere = [
        'vd.id = :post_id_vigencia_detalle',
        "(rfs.resultado_final = 'APROBADO' OR rfs.nota_final >= 4)",
        'CURDATE() BETWEEN DATE(COALESCE(vg.fechavig_ini, vd.fechavig_ini)) AND DATE(COALESCE(vg.fechavig_fin, vd.fechavig_fin))',
    ];
    $stmtPostCand = $pdo->prepare(certCandidateSql($postWhere) . ' LIMIT 1');
    $stmtPostCand->execute([':post_id_vigencia_detalle' => $idVigenciaDetalle]);
    $cand = $stmtPostCand->fetch(PDO::FETCH_ASSOC);
    if (!$cand) {
        throw new RuntimeException('El candidato ya no esta aprobado/vigente o no existe.');
    }

    return $cand;
}

function certGenerateFromCandidate(PDO $pdo, array $cand): array
{
    $stmtCodigo = $pdo->query('SELECT COALESCE(MAX(codigo_certificado), 0) + 1 FROM ceo_certificados');
    $codigoCertificado = (int)$stmtCodigo->fetchColumn();
    $token = bin2hex(random_bytes(32));
    $fechaGeneracion = date('Y-m-d H:i:s');
    $year = date('Y');
    $baseDir = dirname(__DIR__) . '/storage/certificados/' . $year;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No se pudo crear el directorio de certificados.');
    }

    $rutArchivo = preg_replace('/[^0-9Kk]/', '', (string)$cand['rut']);
    $nombreArchivo = 'Certificado_' . $codigoCertificado . '_' . $rutArchivo . '_S' . (int)$cand['id_servicio'] . '.pdf';
    $nombreQr = 'Certificado_' . $codigoCertificado . '_' . $rutArchivo . '_S' . (int)$cand['id_servicio'] . '_qr.png';
    $rutaRelativa = 'storage/certificados/' . $year . '/' . $nombreArchivo;
    $rutaFinal = $baseDir . DIRECTORY_SEPARATOR . $nombreArchivo;
    $rutaQr = $baseDir . DIRECTORY_SEPARATOR . $nombreQr;

    $certData = [
        'codigo_certificado' => $codigoCertificado,
        'token' => $token,
        'rut' => (string)$cand['rut'],
        'nombre' => (string)$cand['nombre'],
        'apellidos' => (string)$cand['apellidos'],
        'cargo' => (string)$cand['cargo'],
        'id_empresa' => (int)$cand['id_empresa'],
        'empresa' => (string)$cand['empresa'],
        'id_servicio' => (int)$cand['id_servicio'],
        'servicio' => (string)$cand['servicio'],
        'id_proceso' => (int)$cand['id_proceso'],
        'id_vigencia_general' => $cand['id_vigencia_general'] !== null ? (int)$cand['id_vigencia_general'] : null,
        'id_vigencia_detalle' => (int)$cand['id_vigencia_detalle'],
        'fecha_evaluacion' => $cand['fecha_evaluacion'],
        'fechavig_ini' => $cand['fechavig_ini_cert'],
        'fechavig_fin' => $cand['fechavig_fin_cert'],
        'nombre_archivo' => $nombreArchivo,
        'ruta_archivo' => $rutaRelativa,
        'fecha_generacion' => $fechaGeneracion,
    ];

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->setChroot(dirname(__DIR__));
    $dompdf = new \Dompdf\Dompdf($options);
    $validationUrl = certValidationUrl($token);
    $qrError = '';
    if (!certQrPngFile($validationUrl, $rutaQr, $qrError)) {
        throw new RuntimeException('No se pudo generar el QR del certificado. ' . $qrError);
    }
    $qrRealPath = realpath($rutaQr);
    if ($qrRealPath === false) {
        throw new RuntimeException('No se pudo resolver la ruta del QR generado para incrustarlo en el PDF.');
    }
    $dompdf->loadHtml(certHtml($certData, certLogoDataUri(), 'file://' . $qrRealPath, $validationUrl), 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $pdfData = $dompdf->output();
    if (file_put_contents($rutaFinal, $pdfData) === false) {
        throw new RuntimeException('No se pudo guardar el PDF del certificado.');
    }

    try {
        $pdo->beginTransaction();
        $stmtInsert = $pdo->prepare('
            INSERT INTO ceo_certificados
              (codigo_certificado, token, rut, nombre, apellidos, cargo, id_empresa, empresa, id_servicio, servicio, id_proceso, id_vigencia_general, id_vigencia_detalle, fecha_evaluacion, fechavig_ini, fechavig_fin, nombre_archivo, ruta_archivo, estado, generado_por, fecha_generacion)
            VALUES
              (:codigo_certificado, :token, :rut, :nombre, :apellidos, :cargo, :id_empresa, :empresa, :id_servicio, :servicio, :id_proceso, :id_vigencia_general, :id_vigencia_detalle, :fecha_evaluacion, :fechavig_ini, :fechavig_fin, :nombre_archivo, :ruta_archivo, "VIGENTE", :generado_por, :fecha_generacion)
        ');
        $stmtInsert->execute([
            ':codigo_certificado' => $certData['codigo_certificado'],
            ':token' => $certData['token'],
            ':rut' => $certData['rut'],
            ':nombre' => $certData['nombre'],
            ':apellidos' => $certData['apellidos'],
            ':cargo' => $certData['cargo'],
            ':id_empresa' => $certData['id_empresa'] ?: null,
            ':empresa' => $certData['empresa'],
            ':id_servicio' => $certData['id_servicio'],
            ':servicio' => $certData['servicio'],
            ':id_proceso' => $certData['id_proceso'] ?: null,
            ':id_vigencia_general' => $certData['id_vigencia_general'],
            ':id_vigencia_detalle' => $certData['id_vigencia_detalle'],
            ':fecha_evaluacion' => $certData['fecha_evaluacion'] ?: null,
            ':fechavig_ini' => $certData['fechavig_ini'],
            ':fechavig_fin' => $certData['fechavig_fin'],
            ':nombre_archivo' => $certData['nombre_archivo'],
            ':ruta_archivo' => $certData['ruta_archivo'],
            ':generado_por' => (int)($_SESSION['auth']['id'] ?? 0) ?: null,
            ':fecha_generacion' => $certData['fecha_generacion'],
        ]);
        $nuevoId = (int)$pdo->lastInsertId();

        $stmtReemplaza = $pdo->prepare('
            UPDATE ceo_certificados
               SET estado = "REEMPLAZADO", reemplazado_por = :nuevo_id_set, fecha_reemplazo = NOW()
             WHERE id <> :nuevo_id_where
               AND estado = "VIGENTE"
               AND id_servicio = :id_servicio
               AND REPLACE(REPLACE(REPLACE(UPPER(rut), ".", ""), "-", ""), " ", "") COLLATE utf8mb4_unicode_ci = :rut_key
        ');
        $stmtReemplaza->execute([
            ':nuevo_id_set' => $nuevoId,
            ':nuevo_id_where' => $nuevoId,
            ':id_servicio' => $certData['id_servicio'],
            ':rut_key' => certRutKey($certData['rut']),
        ]);

        $pdo->commit();
        $certData['id'] = $nuevoId;
        $certData['ruta_final'] = $rutaFinal;
        return $certData;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($rutaFinal);
        @unlink($rutaQr);
        throw $e;
    }
}

function certGenerateByVigenciaDetalle(PDO $pdo, int $idVigenciaDetalle): array
{
    return certGenerateFromCandidate($pdo, certFindCandidate($pdo, $idVigenciaDetalle));
}

function certParseEmails(string $value): array
{
    $emails = [];
    foreach (preg_split('/[;,\n\r]+/', $value) ?: [] as $email) {
        $email = trim($email);
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Correo invalido: ' . $email);
        }
        $emails[] = $email;
    }
    return array_values(array_unique($emails));
}

function certFetchForMail(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id, codigo_certificado, rut, nombre, apellidos, cargo, empresa, servicio, nombre_archivo, ruta_archivo FROM ceo_certificados WHERE id IN (' . $in . ') ORDER BY codigo_certificado');
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function certSendMail(PDO $pdo, array $ids, string $paraText, string $ccText, string $asunto, string $cuerpo): int
{
    $para = certParseEmails($paraText);
    if (!$para) {
        throw new RuntimeException('Debe indicar al menos un destinatario valido en Para.');
    }
    $cc = certParseEmails($ccText);
    $certs = certFetchForMail($pdo, $ids);
    if (!$certs) {
        throw new RuntimeException('No hay certificados validos para enviar.');
    }
    if (trim($asunto) === '') {
        throw new RuntimeException('Debe indicar un asunto para el correo.');
    }
    if (trim($cuerpo) === '') {
        throw new RuntimeException('Debe indicar un cuerpo para el correo.');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'mail.noetica.cl';
    $mail->SMTPAuth = true;
    $mail->Username = 'ceo@noetica.cl';
    $mail->Password = 'Neotica_1964$';
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);
    $mail->setFrom('ceo@noetica.cl', 'Sistema CEO');
    foreach ($para as $email) {
        $mail->addAddress($email);
    }
    foreach ($cc as $email) {
        $mail->addCC($email);
    }
    $mail->Subject = $asunto;

    $tablaHtml = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">'
        . '<thead><tr style="background:#eaf2fb;">'
        . '<th align="left">RUT</th><th align="left">Nombre y apellidos</th><th align="left">Cargo</th><th align="left">Empresa</th><th align="left">Servicio</th>'
        . '</tr></thead><tbody>';
    $tablaTexto = "\n\nCertificados adjuntos:\n";
    foreach ($certs as $cert) {
        $nombreCompleto = trim((string)$cert['nombre'] . ' ' . (string)$cert['apellidos']);
        $tablaHtml .= '<tr>'
            . '<td>' . certEsc($cert['rut']) . '</td>'
            . '<td>' . certEsc($nombreCompleto) . '</td>'
            . '<td>' . certEsc($cert['cargo']) . '</td>'
            . '<td>' . certEsc($cert['empresa']) . '</td>'
            . '<td>' . certEsc($cert['servicio']) . '</td>'
            . '</tr>';
        $tablaTexto .= '- ' . (string)$cert['rut'] . ' | ' . $nombreCompleto . ' | ' . (string)$cert['cargo'] . ' | ' . (string)$cert['empresa'] . ' | ' . (string)$cert['servicio'] . "\n";
    }
    $tablaHtml .= '</tbody></table>';

    $mail->Body = '<p>' . nl2br(certEsc($cuerpo)) . '</p>'
        . '<p><strong>Certificados adjuntos:</strong></p>'
        . $tablaHtml;
    $mail->AltBody = $cuerpo . $tablaTexto;

    foreach ($certs as $cert) {
        $path = dirname(__DIR__) . '/' . ltrim((string)$cert['ruta_archivo'], '/');
        if (!is_file($path)) {
            throw new RuntimeException('No se encontro el archivo adjunto: ' . $cert['nombre_archivo']);
        }
        $mail->addAttachment($path, (string)$cert['nombre_archivo']);
    }

    $estado = 'ENVIADO';
    $error = null;
    try {
        $mail->send();
    } catch (Throwable $e) {
        $estado = 'ERROR';
        $error = $mail->ErrorInfo ?: $e->getMessage();
    }

    $stmtEnvio = $pdo->prepare('INSERT INTO ceo_certificado_envio (para, cc, asunto, cuerpo, enviado_por, estado, error) VALUES (:para, :cc, :asunto, :cuerpo, :enviado_por, :estado, :error)');
    $stmtEnvio->execute([
        ':para' => implode('; ', $para),
        ':cc' => implode('; ', $cc),
        ':asunto' => $asunto,
        ':cuerpo' => $cuerpo,
        ':enviado_por' => (int)($_SESSION['auth']['id'] ?? 0) ?: null,
        ':estado' => $estado,
        ':error' => $error,
    ]);
    $idEnvio = (int)$pdo->lastInsertId();

    $stmtDetalle = $pdo->prepare('INSERT INTO ceo_certificado_envio_detalle (id_envio, id_certificado) VALUES (:id_envio, :id_certificado)');
    foreach ($certs as $cert) {
        $stmtDetalle->execute([':id_envio' => $idEnvio, ':id_certificado' => (int)$cert['id']]);
    }

    if ($estado === 'ERROR') {
        throw new RuntimeException('No se pudo enviar el correo. ' . $error);
    }

    return $idEnvio;
}

$fRut = trim((string)($_GET['rut'] ?? ''));
$fEmpresa = (int)($_GET['empresa'] ?? 0);
$fServicio = (int)($_GET['servicio'] ?? 0);
$fEstado = trim((string)($_GET['estado'] ?? ''));

$empresas = [];
$servicios = [];
$candidatosPendientes = [];
$certificados = [];
$mailCertificados = [];

try {
    certEnsureTables($pdo);

    $empresas = $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    $servicios = $pdo->query('SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio')->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = (string)($_POST['accion'] ?? '');
        if ($accion === 'generar_certificado') {
            $idVigenciaDetalle = (int)($_POST['id_vigencia_detalle_unico'] ?? $_POST['id_vigencia_detalle'] ?? 0);
            $certGenerado = certGenerateByVigenciaDetalle($pdo, $idVigenciaDetalle);
            $mailCertificados = certFetchForMail($pdo, [(int)$certGenerado['id']]);
            $message = 'Certificado N° ' . (int)$certGenerado['codigo_certificado'] . ' generado correctamente.';
        } elseif ($accion === 'generar_seleccionados') {
            $idsSeleccionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['id_vigencia_detalle'] ?? [])))));
            if (!$idsSeleccionados) {
                throw new RuntimeException('Debe seleccionar al menos un candidato pendiente.');
            }

            $generados = [];
            $erroresLote = [];
            foreach ($idsSeleccionados as $idVigenciaDetalle) {
                try {
                    $generados[] = certGenerateByVigenciaDetalle($pdo, $idVigenciaDetalle);
                } catch (Throwable $e) {
                    $erroresLote[] = 'ID ' . $idVigenciaDetalle . ': ' . $e->getMessage();
                }
            }

            if (!$generados) {
                throw new RuntimeException('No se genero ningun certificado. ' . implode(' | ', $erroresLote));
            }

            $idsGenerados = [];
            foreach ($generados as $certGenerado) {
                $idsGenerados[] = (int)$certGenerado['id'];
            }
            $mailCertificados = certFetchForMail($pdo, $idsGenerados);
            $message = count($generados) . ' certificado(s) generado(s) correctamente.';
            if ($erroresLote) {
                $message .= ' Con errores: ' . implode(' | ', $erroresLote);
            }
        } elseif ($accion === 'enviar_certificados_mail') {
            $idsCertificados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cert_ids'] ?? [])))));
            $idEnvio = certSendMail(
                $pdo,
                $idsCertificados,
                (string)($_POST['para'] ?? ''),
                (string)($_POST['cc'] ?? ''),
                trim((string)($_POST['asunto'] ?? 'Certificados de Habilitacion CEO')),
                trim((string)($_POST['cuerpo'] ?? ''))
            );
            $message = 'Correo de certificados enviado correctamente. Registro de envio N° ' . $idEnvio . '.';
        }
    }

    $certWhere = ['1=1'];
    $certParams = [];
    if ($fRut !== '') {
        $certWhere[] = "REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci = :cert_rut";
        $certParams[':cert_rut'] = certRutKey($fRut);
    }
    if ($fEmpresa > 0) {
        $certWhere[] = 'id_empresa = :cert_empresa';
        $certParams[':cert_empresa'] = $fEmpresa;
    }
    if ($fServicio > 0) {
        $certWhere[] = 'id_servicio = :cert_servicio';
        $certParams[':cert_servicio'] = $fServicio;
    }
    if ($fEstado !== '') {
        $certWhere[] = 'estado = :cert_estado';
        $certParams[':cert_estado'] = $fEstado;
    }

    $stmtCert = $pdo->prepare('
        SELECT *
        FROM ceo_certificados
        WHERE ' . implode(' AND ', $certWhere) . '
        ORDER BY fecha_generacion DESC, id DESC
        LIMIT 1000
    ');
    $stmtCert->execute($certParams);
    $certificados = $stmtCert->fetchAll(PDO::FETCH_ASSOC);

    $certVigentes = [];
    foreach ($pdo->query("SELECT rut, id_servicio, id_proceso, id_empresa, cargo, fechavig_fin FROM ceo_certificados WHERE estado = 'VIGENTE'")->fetchAll(PDO::FETCH_ASSOC) as $cert) {
        $key = certRutKey((string)$cert['rut']) . '|' . (int)$cert['id_servicio'] . '|' . (int)$cert['id_proceso'] . '|' . (int)$cert['id_empresa'] . '|' . strtoupper(trim((string)$cert['cargo'])) . '|' . (string)$cert['fechavig_fin'];
        $certVigentes[$key] = true;
    }

    $candWhere = [
        "(rfs.resultado_final = 'APROBADO' OR rfs.nota_final >= 4)",
        'CURDATE() BETWEEN DATE(COALESCE(vg.fechavig_ini, vd.fechavig_ini)) AND DATE(COALESCE(vg.fechavig_fin, vd.fechavig_fin))',
    ];
    $candParams = [];
    if ($fRut !== '') {
        $candWhere[] = "REPLACE(REPLACE(REPLACE(UPPER(vd.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci = :cand_rut";
        $candParams[':cand_rut'] = certRutKey($fRut);
    }
    if ($fEmpresa > 0) {
        $candWhere[] = 'COALESCE(h.empresa, c.id_empresa) = :cand_empresa';
        $candParams[':cand_empresa'] = $fEmpresa;
    }
    if ($fServicio > 0) {
        $candWhere[] = 'vd.id_servicio = :cand_servicio';
        $candParams[':cand_servicio'] = $fServicio;
    }

    $sqlCand = certCandidateSql($candWhere) . '
        ORDER BY vd.fechavig_fin DESC, vd.rut ASC, sp.servicio ASC
        LIMIT 2000
    ';
    $stmtCand = $pdo->prepare($sqlCand);
    $stmtCand->execute($candParams);
    $candidatos = $stmtCand->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidatos as $cand) {
        $key = certRutKey((string)$cand['rut']) . '|' . (int)$cand['id_servicio'] . '|' . (int)$cand['id_proceso'] . '|' . (int)$cand['id_empresa'] . '|' . strtoupper(trim((string)$cand['cargo'])) . '|' . (string)$cand['fechavig_fin_cert'];
        if (!isset($certVigentes[$key])) {
            $candidatosPendientes[] = $cand;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Generar Certificados - <?= certEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .brand-title { color:#0065a4; font-weight:600; }
    .card { border:none; box-shadow:0 2px 4px rgba(0,0,0,.05); }
    .table th { background:#eaf2fb; white-space:nowrap; }
    .table td { vertical-align:middle; }
    .table-responsive { max-height:520px; overflow:auto; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= certEsc(APP_NAME) ?></div>
        <small class="text-secondary"><?= certEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <div class="card rounded-4 mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
      <div>
        <h4 class="fw-bold text-primary mb-1"><i class="bi bi-award me-2"></i>Generar Certificados</h4>
        <div class="text-muted small">Consulta de candidatos habilitados, generación PDF y registro de certificados vigentes.</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <span class="badge text-bg-warning">Pendientes: <?= count($candidatosPendientes) ?></span>
        <span class="badge text-bg-primary">Registrados: <?= count($certificados) ?></span>
      </div>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger">Error cargando certificados: <?= certEsc($error) ?></div>
  <?php endif; ?>
  <?php if ($message !== ''): ?>
    <div class="alert alert-success"><?= certEsc($message) ?></div>
  <?php endif; ?>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">RUT</label>
          <input type="text" name="rut" class="form-control form-control-sm" value="<?= certEsc($fRut) ?>" placeholder="12345678-9">
        </div>
        <div class="col-md-3">
          <label class="form-label">Empresa</label>
          <select name="empresa" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($empresas as $emp): ?>
              <option value="<?= (int)$emp['id'] ?>" <?= $fEmpresa === (int)$emp['id'] ? 'selected' : '' ?>><?= certEsc($emp['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Servicio</label>
          <select name="servicio" class="form-select form-select-sm">
            <option value="0">Todos</option>
            <?php foreach ($servicios as $srv): ?>
              <option value="<?= (int)$srv['id'] ?>" <?= $fServicio === (int)$srv['id'] ? 'selected' : '' ?>><?= certEsc($srv['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Estado certificado</label>
          <select name="estado" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach (['VIGENTE','REEMPLAZADO','ANULADO'] as $estado): ?>
              <option value="<?= $estado ?>" <?= $fEstado === $estado ? 'selected' : '' ?>><?= $estado ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Filtrar</button>
          <a href="generar_certificados.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h5 class="text-primary mb-0"><i class="bi bi-hourglass-split me-2"></i>Candidatos pendientes de certificado</h5>
        <button type="submit" form="formPendientes" name="accion" value="generar_seleccionados" class="btn btn-success btn-sm" onclick="return confirm('¿Generar certificados para los candidatos seleccionados?');">
          <i class="bi bi-files"></i> Generar seleccionados
        </button>
      </div>
      <form method="post" id="formPendientes">
      <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover align-middle">
          <thead class="text-center">
            <tr>
              <th><input class="form-check-input" type="checkbox" id="chkTodosPendientes" title="Seleccionar todos"></th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Cargo</th>
              <th>Empresa</th>
              <th>Servicio</th>
              <th>Proceso</th>
              <th>Fuente vigencia</th>
              <th>Fecha evaluación</th>
              <th>Vigencia desde</th>
              <th>Vigencia hasta</th>
              <th>Nota final</th>
              <th>Accion</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($candidatosPendientes)): ?>
            <tr><td colspan="13" class="text-center text-muted">No hay candidatos pendientes con los filtros actuales.</td></tr>
          <?php else: ?>
            <?php foreach ($candidatosPendientes as $cand): ?>
              <tr>
                <td class="text-center"><input class="form-check-input chk-pendiente" type="checkbox" name="id_vigencia_detalle[]" value="<?= (int)$cand['id_vigencia_detalle'] ?>"></td>
                <td><?= certEsc($cand['rut']) ?></td>
                <td><?= certEsc(trim((string)$cand['nombre'] . ' ' . (string)$cand['apellidos'])) ?></td>
                <td><?= certEsc($cand['cargo']) ?></td>
                <td><?= certEsc($cand['empresa']) ?></td>
                <td><?= certEsc($cand['servicio']) ?></td>
                <td class="text-center"><?= certEsc($cand['id_proceso']) ?></td>
                <td class="text-center"><span class="badge text-bg-<?= $cand['fuente_vigencia'] === 'GENERAL' ? 'success' : 'secondary' ?>"><?= certEsc($cand['fuente_vigencia']) ?></span></td>
                <td class="text-center"><?= certEsc(certFmtFecha($cand['fecha_evaluacion'])) ?></td>
                <td class="text-center"><?= certEsc(certFmtFecha($cand['fechavig_ini_cert'])) ?></td>
                <td class="text-center"><?= certEsc(certFmtFecha($cand['fechavig_fin_cert'])) ?></td>
                <td class="text-end"><?= certEsc(certFmtNota($cand['nota_final'])) ?></td>
                <td class="text-center">
                  <button type="submit" name="accion" value="generar_certificado" class="btn btn-success btn-sm" onclick="this.form.id_vigencia_detalle_unico.value='<?= (int)$cand['id_vigencia_detalle'] ?>'; return confirm('¿Generar certificado PDF para este trabajador y servicio?');"><i class="bi bi-file-earmark-pdf"></i> Generar</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <input type="hidden" name="id_vigencia_detalle_unico" value="">
      </form>
    </div>
  </div>

  <div class="card rounded-4">
    <div class="card-body">
      <h5 class="text-primary mb-3"><i class="bi bi-file-earmark-check me-2"></i>Certificados registrados</h5>
      <div class="table-responsive">
        <table class="table table-sm table-bordered table-hover align-middle">
          <thead class="text-center">
            <tr>
              <th>Código</th>
              <th>Estado</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Cargo</th>
              <th>Empresa</th>
              <th>Servicio</th>
              <th>Proceso</th>
              <th>Vigencia hasta</th>
              <th>Fecha generación</th>
              <th>Archivo</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($certificados)): ?>
            <tr><td colspan="11" class="text-center text-muted">No hay certificados registrados con los filtros actuales.</td></tr>
          <?php else: ?>
            <?php foreach ($certificados as $cert): ?>
              <tr>
                <td class="text-center"><?= certEsc($cert['codigo_certificado']) ?></td>
                <td class="text-center"><span class="badge text-bg-<?= $cert['estado'] === 'VIGENTE' ? 'success' : ($cert['estado'] === 'REEMPLAZADO' ? 'warning' : 'secondary') ?>"><?= certEsc($cert['estado']) ?></span></td>
                <td><?= certEsc($cert['rut']) ?></td>
                <td><?= certEsc(trim((string)$cert['nombre'] . ' ' . (string)$cert['apellidos'])) ?></td>
                <td><?= certEsc($cert['cargo']) ?></td>
                <td><?= certEsc($cert['empresa']) ?></td>
                <td><?= certEsc($cert['servicio']) ?></td>
                <td class="text-center"><?= certEsc($cert['id_proceso']) ?></td>
                <td class="text-center"><?= certEsc(certFmtFecha($cert['fechavig_fin'])) ?></td>
                <td class="text-center"><?= certEsc(certFmtFecha($cert['fecha_generacion'])) ?></td>
                <td>
                  <?php if (!empty($cert['ruta_archivo'])): ?>
                    <?php
                      $pdfUrl = APP_BASE . '/' . ltrim((string)$cert['ruta_archivo'], '/');
                      $pdfVersion = strtotime((string)($cert['fecha_generacion'] ?? '')) ?: time();
                    ?>
                    <a href="<?= certEsc($pdfUrl . '?v=' . $pdfVersion) ?>" target="_blank" rel="noopener"><?= certEsc($cert['nombre_archivo']) ?></a>
                  <?php else: ?>
                    <?= certEsc($cert['nombre_archivo']) ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<div class="modal fade" id="modalEnviarCertificados" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content rounded-4">
      <form method="post">
        <input type="hidden" name="accion" value="enviar_certificados_mail">
        <?php foreach ($mailCertificados as $certMail): ?>
          <input type="hidden" name="cert_ids[]" value="<?= (int)$certMail['id'] ?>">
        <?php endforeach; ?>
        <div class="modal-header bg-primary text-white rounded-top-4">
          <h5 class="modal-title"><i class="bi bi-envelope-paper me-2"></i>Enviar certificados por correo</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (!empty($mailCertificados)): ?>
            <div class="alert alert-info small">
              Se adjuntaran <?= count($mailCertificados) ?> certificado(s) PDF generados. Puede indicar multiples correos separados por coma, punto y coma o salto de linea.
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Para</label>
              <textarea name="para" class="form-control" rows="2" required placeholder="correo1@dominio.cl; correo2@dominio.cl"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">CC</label>
              <textarea name="cc" class="form-control" rows="2" placeholder="correo.cc@dominio.cl"></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Asunto</label>
              <input type="text" name="asunto" class="form-control" value="Certificados de Habilitación CEO" required>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Cuerpo</label>
              <textarea name="cuerpo" class="form-control" rows="5" required>Estimados,

Se adjuntan certificados de habilitación CEO generados en CEONext.

Saludos.</textarea>
            </div>
            <div class="border rounded-3 p-3 bg-light">
              <div class="fw-semibold mb-2">Adjuntos</div>
              <ul class="mb-0 small">
                <?php foreach ($mailCertificados as $certMail): ?>
                  <li><?= certEsc($certMail['nombre_archivo']) ?> - <?= certEsc(trim((string)$certMail['nombre'] . ' ' . (string)$certMail['apellidos'])) ?> / <?= certEsc($certMail['servicio']) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php else: ?>
            <div class="alert alert-warning mb-0">No hay certificados preparados para enviar.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm" <?= empty($mailCertificados) ? 'disabled' : '' ?>><i class="bi bi-send"></i> Enviar correo</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('chkTodosPendientes')?.addEventListener('change', function () {
  document.querySelectorAll('.chk-pendiente').forEach((chk) => { chk.checked = this.checked; });
});

<?php if (!empty($mailCertificados)): ?>
document.addEventListener('DOMContentLoaded', function () {
  const modalEl = document.getElementById('modalEnviarCertificados');
  if (modalEl && window.bootstrap) {
    new bootstrap.Modal(modalEl).show();
  }
});
<?php endif; ?>
</script>
</body>
</html>
