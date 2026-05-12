<?php
// --------------------------------------------------------------
// test_correo.php - Prueba de servicio SMTP CEONext
// --------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$resultado = null;
$debugSmtp = '';
$adjuntoInfo = null;

$para = trim((string)($_POST['para'] ?? ''));
$asunto = trim((string)($_POST['asunto'] ?? 'Prueba correo CEONext'));
$cuerpo = trim((string)($_POST['cuerpo'] ?? 'Este es un correo de prueba enviado desde CEONext.'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        $resultado = [
            'ok' => false,
            'msg' => 'Debe indicar un correo destinatario válido.',
        ];
    } else {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'mail.noetica.cl';
            $mail->SMTPAuth = true;
            $mail->Username = 'ceo@noetica.cl';
            $mail->Password = 'Neotica_1964$';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $str, int $level) use (&$debugSmtp): void {
                $debugSmtp .= '[' . $level . '] ' . $str . "\n";
            };

            $mail->setFrom('ceo@noetica.cl', 'Sistema CEO - Prueba SMTP');
            $mail->addAddress($para);
            $mail->Subject = $asunto;
            $mail->Body = '<p>' . nl2br(htmlspecialchars($cuerpo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
                . '<hr><small>Correo de prueba generado el ' . date('d-m-Y H:i:s') . ' desde CEONext.</small>';
            $mail->AltBody = $cuerpo;

            $pdfAdjunto = null;
            $basePermisos = __DIR__ . '/../storage/permisos';
            if (is_dir($basePermisos)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($basePermisos, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
                        $pdfAdjunto = $file->getPathname();
                        break;
                    }
                }
            }

            if ($pdfAdjunto !== null) {
                $mail->addAttachment($pdfAdjunto, 'Adjunto_Prueba_CEONext.pdf');
                $adjuntoInfo = [
                    'ok' => true,
                    'path' => $pdfAdjunto,
                ];
            } else {
                $adjuntoInfo = [
                    'ok' => false,
                    'path' => 'No se encontró PDF en storage/permisos.',
                ];
            }

            $mail->send();

            $resultado = [
                'ok' => true,
                'msg' => 'Correo enviado correctamente a ' . $para . '.',
            ];
        } catch (Exception $e) {
            $resultado = [
                'ok' => false,
                'msg' => 'Error PHPMailer: ' . ($mail->ErrorInfo ?? $e->getMessage()),
            ];
        } catch (Throwable $e) {
            $resultado = [
                'ok' => false,
                'msg' => 'Error general: ' . $e->getMessage(),
            ];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Prueba Correo - <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.debug-box { white-space:pre-wrap; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; max-height:420px; overflow:auto; }
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= esc(APP_LOGO) ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="h5 mb-0 text-primary fw-semibold"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary">Prueba de servicio SMTP</small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">Volver</a>
  </div>
</header>

<main class="container">
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body">
          <h5 class="fw-bold text-primary mb-3">Enviar Correo De Prueba</h5>

          <?php if ($resultado): ?>
            <div class="alert <?= $resultado['ok'] ? 'alert-success' : 'alert-danger' ?>">
              <?= esc($resultado['msg']) ?>
            </div>
          <?php endif; ?>

          <?php if ($adjuntoInfo): ?>
            <div class="alert <?= $adjuntoInfo['ok'] ? 'alert-info' : 'alert-warning' ?>">
              <div class="fw-semibold">Adjunto PDF:</div>
              <div><?= $adjuntoInfo['ok'] ? 'Se adjuntó un PDF de prueba.' : 'El correo se enviará sin adjunto.' ?></div>
              <small><?= esc($adjuntoInfo['path']) ?></small>
            </div>
          <?php endif; ?>

          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label fw-semibold">Destinatario</label>
              <input type="email" name="para" class="form-control" value="<?= esc($para) ?>" required>
            </div>

            <div>
              <label class="form-label fw-semibold">Asunto</label>
              <input type="text" name="asunto" class="form-control" value="<?= esc($asunto) ?>" required>
            </div>

            <div>
              <label class="form-label fw-semibold">Mensaje</label>
              <textarea name="cuerpo" class="form-control" rows="5" required><?= esc($cuerpo) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Enviar Prueba</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
          <h5 class="fw-bold text-primary mb-3">Parámetros SMTP Usados</h5>
          <table class="table table-sm">
            <tr><th>Host</th><td>mail.noetica.cl</td></tr>
            <tr><th>Puerto</th><td>465</td></tr>
            <tr><th>Seguridad</th><td>SMTPS / SSL</td></tr>
            <tr><th>Usuario</th><td>ceo@noetica.cl</td></tr>
            <tr><th>Remitente</th><td>ceo@noetica.cl / Sistema CEO - Prueba SMTP</td></tr>
          </table>
        </div>
      </div>

      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body">
          <h5 class="fw-bold text-primary mb-3">Diagnóstico SMTP</h5>
          <?php if ($debugSmtp !== ''): ?>
            <div class="debug-box bg-dark text-light rounded p-3"><?= esc($debugSmtp) ?></div>
          <?php else: ?>
            <div class="text-muted">El diagnóstico aparecerá después de enviar una prueba.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>
