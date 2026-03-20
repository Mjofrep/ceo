<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

function debug_log(string $msg): void {
    file_put_contents(__DIR__ . '/_debug_envio.log', date('Y-m-d H:i:s') . " | " . $msg . "\n", FILE_APPEND);
}

debug_log("=== ENTRA enviar_orden_mail.php ===");

if (empty($_SESSION['auth'])) {
    debug_log("NO AUTORIZADO");
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

// ------------------------
// RECIBIR POST
// ------------------------
$para       = trim((string)($_POST['para'] ?? ''));
$cc         = trim((string)($_POST['cc'] ?? ''));
$asunto     = trim((string)($_POST['asunto'] ?? ''));
$cuerpo     = (string)($_POST['cuerpo'] ?? '');
$htmlExtra  = (string)($_POST['html_participantes'] ?? '');
$cuadrillas = trim((string)($_POST['cuadrillas'] ?? ''));

debug_log("POST para={$para}");
debug_log("POST cc={$cc}");
debug_log("POST asunto={$asunto}");
debug_log("POST cuadrillas={$cuadrillas}");
debug_log("LEN cuerpo=" . strlen($cuerpo));
debug_log("LEN html_participantes=" . strlen($htmlExtra));

if ($para === '' || $asunto === '') {
    debug_log("FALTAN DATOS (para/asunto)");
    echo json_encode(['ok' => false, 'msg' => 'Debe indicar destinatario y asunto']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $cuadrillas)));
if (empty($ids)) {
    debug_log("NO SE RECIBIERON CUADRILLAS");
    echo json_encode(['ok' => false, 'msg' => 'No se recibieron cuadrillas']);
    exit;
}

// Si por alguna razón htmlExtra viene vacío, lo dejamos explícito para que lo veas en el correo.
if (trim($htmlExtra) === '') {
    debug_log("html_participantes VIENE VACIO -> se inserta aviso en HTML");
    $htmlExtra = "<div style='padding:10px;border:1px solid #f0ad4e;background:#fff3cd'>
        <b>AVISO:</b> No se recibió tabla de participantes (html_participantes vacío).
    </div>";
}

// ------------------------
// ARMAR BODY HTML (IMPORTANTE)
// ------------------------
$bodyTexto = nl2br(htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8'));

$bodyHtml = "
<!doctype html>
<html>
<head>
  <meta charset='UTF-8'>
</head>
<body style='font-family: Arial, Helvetica, sans-serif; font-size:14px; color:#111'>
  <div>{$bodyTexto}</div>
  <hr style='margin:18px 0; border:0; border-top:1px solid #ddd'>
  {$htmlExtra}
</body>
</html>
";

debug_log("LEN bodyHtml=" . strlen($bodyHtml));
debug_log("BODY PREVIEW (first 300): " . substr(preg_replace('/\s+/', ' ', $bodyHtml), 0, 300));

// ------------------------
// ENVIAR CORREO
// ------------------------
try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'mail.noetica.cl';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'ceo@noetica.cl';
    $mail->Password   = 'Neotica_1964$';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    $mail->setFrom('ceo@noetica.cl', 'Sistema CEO');
    $mail->addAddress($para);

    if ($cc !== '') {
        foreach (explode(',', $cc) as $c) {
            $c = trim($c);
            if ($c !== '') $mail->addCC($c);
        }
    }

// ------------------------
// ADJUNTAR PERMISOS AUTOMÁTICOS (PDF)
// ------------------------
$pdo = db();

// Obtener permisos vigentes por cuadrilla
$in = implode(',', array_fill(0, count($ids), '?'));

$sqlPermisos = "
SELECT
    d.nombre_archivo,
    d.ruta_archivo,
    h.cuadrilla
FROM ceo_permiso_documento d
INNER JOIN ceo_habilitacion h ON h.id = d.id_habilitacion
WHERE d.estado = 'VIGENTE'
  AND d.tipo = 'PERMISO'
  AND h.cuadrilla IN ($in)
";

$stPerm = $pdo->prepare($sqlPermisos);
$stPerm->execute($ids);
$permisos = $stPerm->fetchAll(PDO::FETCH_ASSOC);

debug_log("Permisos encontrados: " . count($permisos));

if (count($permisos) < count($ids)) {
    debug_log("ERROR: faltan permisos para una o más cuadrillas");
    echo json_encode([
        'ok' => false,
        'msg' => 'Una o más cuadrillas no tienen permiso vigente. No se puede generar la orden.'
    ]);
    exit;
}

// ------------------------
// ADJUNTOS (múltiples)
// ------------------------
if (!empty($_FILES['adjuntos']['name'][0])) {

    foreach ($_FILES['adjuntos']['tmp_name'] as $i => $tmp) {

        if (!is_uploaded_file($tmp)) {
            debug_log("Adjunto {$i} NO es upload válido");
            continue;
        }

        $nombre = $_FILES['adjuntos']['name'][$i];
        $size   = $_FILES['adjuntos']['size'][$i];

        // Seguridad básica (opcional, pero recomendada)
        if ($size > 10 * 1024 * 1024) { // 10 MB
            debug_log("Adjunto {$nombre} excede tamaño permitido");
            continue;
        }

        debug_log("Adjuntando archivo: {$nombre} ({$size} bytes)");

        $mail->addAttachment($tmp, $nombre);
    }
} else {
    debug_log("No se recibieron adjuntos");
}

    $mail->Subject = $asunto;

    // ✅ Body HTML real
    $mail->Body = $bodyHtml;

    // ✅ AltBody (texto plano) solo como fallback, NO debe llevar la tabla
    $mail->AltBody = trim($cuerpo);

    debug_log("ANTES DE SEND");
    // Adjuntar permisos automáticos
    foreach ($permisos as $p) {
        if (is_file($p['ruta_archivo'])) {
    
            $nombreAdjunto = 'Permiso_Cuadrilla_' . $p['cuadrilla'] . '.pdf';
    
            debug_log("Adjuntando permiso automático: {$nombreAdjunto}");
    
            $mail->addAttachment(
                $p['ruta_archivo'],
                $nombreAdjunto
            );
        } else {
            debug_log("⚠️ Archivo no encontrado: {$p['ruta_archivo']}");
        }
    }

    $mail->send();
    debug_log("MAIL ENVIADO OK");

    // ------------------------
    // CERRAR CUADRILLAS
    // ------------------------
    $pdo = db();
    $in = implode(',', array_fill(0, count($ids), '?'));

    $sql = "UPDATE ceo_habilitacion SET estado = 'Cerrado' WHERE cuadrilla IN ($in)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);

    debug_log("UPDATE OK rowCount=" . $stmt->rowCount());

    echo json_encode(['ok' => true]);
    exit;

} catch (Throwable $e) {
    debug_log("ERROR: " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'No fue posible enviar el correo. Revise logs.']);
    exit;
}
