<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/gp_workflow.php';

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN']);

const GP_TEST_MAIL_AGRUPACION = 'Prueba Inspección de Hurto';
const GP_TEST_MAIL_DESTINATARIO = 'marcelo.jofre94@gmail.com';

$msg = '';
$error = '';

function gpTestMailContextByAgrupacion(PDO $pdo, string $agrupacionTitulo): ?array
{
    $sql = "SELECT
        q.destino,
        q.id_servicio,
        q.id_agrupacion,
        q.id_fuente,
        COUNT(*) AS preguntas,
        MAX(COALESCE(q.fecha_actualizacion, q.fecha_creacion)) AS ultima_fecha,
        COALESCE(MAX(f.titulo), 'Sin fuente') AS fuente,
        MAX(CASE WHEN q.destino = 'FORMACION'
            THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = q.id_servicio LIMIT 1)
            ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = q.id_servicio LIMIT 1)
        END) AS servicio,
        MAX(CASE WHEN q.destino = 'FORMACION'
            THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = q.id_agrupacion LIMIT 1)
            ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = q.id_agrupacion LIMIT 1)
        END) AS agrupacion
      FROM ceo_gp_preguntas q
      LEFT JOIN ceo_gp_fuentes f ON f.id = q.id_fuente
      WHERE (
            q.destino = 'FORMACION'
            AND EXISTS (
                SELECT 1
                FROM ceo_formacion_agrupacion fa
                WHERE fa.id = q.id_agrupacion
                  AND fa.titulo = :agrupacion_formacion
            )
        )
         OR (
            q.destino = 'HABILITACION'
            AND EXISTS (
                SELECT 1
                FROM ceo_agrupacion a
                WHERE a.id = q.id_agrupacion
                  AND a.titulo = :agrupacion_habilitacion
            )
        )
      GROUP BY q.destino, q.id_servicio, q.id_agrupacion, q.id_fuente
      ORDER BY ultima_fecha DESC, q.id_fuente DESC
      LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':agrupacion_formacion' => $agrupacionTitulo,
        ':agrupacion_habilitacion' => $agrupacionTitulo,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gpSendOperacionTestMail(string $to, array $context): void
{
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        throw new RuntimeException('PHPMailer no está disponible para enviar la prueba.');
    }

    $cfg = gpWorkflowSmtpConfig();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $cfg['port'];
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    $mail->addAddress($to);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);
    $mail->Subject = 'Prueba asignada a Operacion - ' . (string)($context['servicio'] ?? '') . ' - ' . (string)($context['agrupacion'] ?? '');
    $mail->Body = '<html><body style="font-family:Arial,sans-serif">'
        . '<h3 style="color:#0046AD;">Asignacion de prueba a Operacion</h3>'
        . '<p>Correo de prueba generado manualmente desde el Gestor de Preguntas para validar asunto y codificacion.</p>'
        . '<table cellpadding="6" cellspacing="0" style="font-size:14px">'
        . '<tr><td><b>Destinatario de prueba:</b></td><td>' . gpEsc($to) . '</td></tr>'
        . '<tr><td><b>Servicio:</b></td><td>' . gpEsc((string)($context['servicio'] ?? '')) . '</td></tr>'
        . '<tr><td><b>Agrupacion:</b></td><td>' . gpEsc((string)($context['agrupacion'] ?? '')) . '</td></tr>'
        . '<tr><td><b>Fuente o carga:</b></td><td>' . gpEsc((string)($context['fuente'] ?? '')) . '</td></tr>'
        . '<tr><td><b>Preguntas:</b></td><td>' . gpEsc((string)($context['preguntas'] ?? '0')) . '</td></tr>'
        . '<tr><td><b>Fecha de prueba:</b></td><td>' . gpEsc(date('d-m-Y H:i')) . '</td></tr>'
        . '<tr><td><b>Acceso:</b></td><td><a href="https://www.noetica.cl/ceo.noetica.cl/public/gp_login.php">Abrir Gestor de Preguntas</a></td></tr>'
        . '</table>'
        . '<hr><small>Mensaje de prueba generado automaticamente por el sistema CEO.</small>'
        . '</body></html>';
    $mail->AltBody = 'Correo de prueba Gestion de Preguntas' . PHP_EOL
        . 'Servicio: ' . (string)($context['servicio'] ?? '') . PHP_EOL
        . 'Agrupacion: ' . (string)($context['agrupacion'] ?? '') . PHP_EOL
        . 'Fuente: ' . (string)($context['fuente'] ?? '') . PHP_EOL
        . 'Preguntas: ' . (string)($context['preguntas'] ?? '0');
    $mail->send();
}

$context = gpTestMailContextByAgrupacion($pdo, GP_TEST_MAIL_AGRUPACION);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $error = 'Sesion expirada. Recarga e intenta nuevamente.';
    } elseif (!$context) {
        $error = 'No se encontraron datos para la agrupacion de prueba.';
    } else {
        try {
            gpSendOperacionTestMail(GP_TEST_MAIL_DESTINATARIO, $context);
            $msg = 'Correo de prueba enviado correctamente a ' . GP_TEST_MAIL_DESTINATARIO . '.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$csrf = Csrf::token();
$subjectPreview = $context
    ? 'Prueba asignada a Operacion - ' . (string)($context['servicio'] ?? '') . ' - ' . (string)($context['agrupacion'] ?? '')
    : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Prueba Correo Operacion | Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .card{border:0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.07)}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
  </style>
</head>
<body>
<header class="bg-white border-bottom py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
      <strong>Prueba Correo Operacion</strong>
      <div class="small text-muted">Envio controlado a destinatario tecnico sin notificar al operador real</div>
    </div>
    <div class="d-flex gap-2">
      <a href="gp_home.php" class="btn btn-outline-primary btn-sm">Inicio</a>
      <a href="gp_revision.php" class="btn btn-outline-secondary btn-sm">Revision</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <?php if ($msg !== ''): ?><div class="alert alert-success"><?= gpEsc($msg) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger"><?= gpEsc($error) ?></div><?php endif; ?>

  <div class="card p-4 mb-4">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Agrupacion fija de prueba</label>
        <input type="text" class="form-control" value="<?= gpEsc(GP_TEST_MAIL_AGRUPACION) ?>" readonly>
      </div>
      <div class="col-md-6">
        <label class="form-label">Destinatario fijo</label>
        <input type="text" class="form-control" value="<?= gpEsc(GP_TEST_MAIL_DESTINATARIO) ?>" readonly>
      </div>
    </div>
  </div>

  <?php if (!$context): ?>
    <div class="card p-5 text-center text-muted">No se encontro informacion actual para la agrupacion <?= gpEsc(GP_TEST_MAIL_AGRUPACION) ?>.</div>
  <?php else: ?>
    <div class="card p-4 mb-4">
      <h5 class="mb-3">Vista previa</h5>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Servicio</label>
          <input type="text" class="form-control" value="<?= gpEsc((string)($context['servicio'] ?? '')) ?>" readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label">Agrupacion</label>
          <input type="text" class="form-control" value="<?= gpEsc((string)($context['agrupacion'] ?? '')) ?>" readonly>
        </div>
        <div class="col-md-8">
          <label class="form-label">Fuente o carga</label>
          <input type="text" class="form-control" value="<?= gpEsc((string)($context['fuente'] ?? '')) ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">Preguntas</label>
          <input type="text" class="form-control" value="<?= gpEsc((string)($context['preguntas'] ?? '0')) ?>" readonly>
        </div>
        <div class="col-12">
          <label class="form-label">Asunto real a enviar</label>
          <input type="text" class="form-control mono" value="<?= gpEsc($subjectPreview) ?>" readonly>
        </div>
      </div>
    </div>

    <div class="card p-4">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= gpEsc($csrf) ?>">
        <div class="alert alert-warning mb-3">Esta prueba envia el correo solo a <?= gpEsc(GP_TEST_MAIL_DESTINATARIO) ?> y no agrega CC.</div>
        <button type="submit" class="btn btn-primary">Enviar correo de prueba</button>
      </form>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
