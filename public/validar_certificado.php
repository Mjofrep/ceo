<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

$token = trim((string)($_GET['token'] ?? ''));
$cert = null;
$error = '';

function vcEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vcFmtFecha(mixed $value): string
{
    $text = trim((string)$value);
    if ($text === '' || str_starts_with($text, '0000-00-00')) {
        return '';
    }
    $ts = strtotime($text);
    return $ts ? date('d-m-Y', $ts) : $text;
}

if ($token === '') {
    $error = 'Token de validacion no informado.';
} else {
    try {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT codigo_certificado, rut, nombre, apellidos, cargo, empresa, servicio, fechavig_fin, estado, fecha_generacion
            FROM ceo_certificados
            WHERE token = :token
            LIMIT 1
        ');
        $stmt->execute([':token' => $token]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$cert) {
            $error = 'Certificado no encontrado.';
        }
    } catch (Throwable $e) {
        $error = 'No fue posible validar el certificado.';
    }
}

$estado = $cert['estado'] ?? '';
$badge = $estado === 'VIGENTE' ? 'success' : ($estado === 'REEMPLAZADO' ? 'warning' : 'secondary');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Validar Certificado - <?= vcEsc(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f4f7fb; }
    .topbar { background:#fff; border-bottom:1px solid #e4e8ef; }
    .wrap { max-width:760px; }
    .card { border:0; box-shadow:0 10px 30px rgba(15, 23, 42, .08); }
    .brand { color:#0b67a3; font-weight:700; }
    .label { color:#64748b; font-size:.85rem; }
    .value { color:#0f172a; font-weight:600; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-5">
  <div class="container wrap d-flex align-items-center gap-3">
    <img src="<?= vcEsc(APP_LOGO) ?>" alt="Logo" style="height:52px;">
    <div>
      <div class="brand"><?= vcEsc(APP_NAME) ?></div>
      <div class="text-muted small">Validacion publica de certificado</div>
    </div>
  </div>
</header>

<main class="container wrap mb-5">
  <div class="card rounded-4">
    <div class="card-body p-4 p-md-5">
      <h1 class="h4 text-primary fw-bold mb-4">Validacion de Certificado CEO</h1>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger mb-0"><?= vcEsc($error) ?></div>
      <?php else: ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
          <div>
            <div class="label">N° Certificado</div>
            <div class="fs-4 fw-bold"><?= vcEsc($cert['codigo_certificado']) ?></div>
          </div>
          <span class="badge text-bg-<?= vcEsc($badge) ?> fs-6 px-3 py-2"><?= vcEsc($estado) ?></span>
        </div>

        <div class="row g-3">
          <div class="col-md-6"><div class="label">RUT</div><div class="value"><?= vcEsc($cert['rut']) ?></div></div>
          <div class="col-md-6"><div class="label">Trabajador</div><div class="value"><?= vcEsc(trim((string)$cert['nombre'] . ' ' . (string)$cert['apellidos'])) ?></div></div>
          <div class="col-md-6"><div class="label">Cargo</div><div class="value"><?= vcEsc($cert['cargo']) ?></div></div>
          <div class="col-md-6"><div class="label">Empresa</div><div class="value"><?= vcEsc($cert['empresa']) ?></div></div>
          <div class="col-12"><div class="label">Servicio</div><div class="value"><?= vcEsc($cert['servicio']) ?></div></div>
          <div class="col-md-6"><div class="label">Vigencia hasta</div><div class="value"><?= vcEsc(vcFmtFecha($cert['fechavig_fin'])) ?></div></div>
          <div class="col-md-6"><div class="label">Fecha generacion</div><div class="value"><?= vcEsc(vcFmtFecha($cert['fecha_generacion'])) ?></div></div>
        </div>

        <?php if ($estado !== 'VIGENTE'): ?>
          <div class="alert alert-warning mt-4 mb-0">Este certificado existe, pero no se encuentra vigente.</div>
        <?php else: ?>
          <div class="alert alert-success mt-4 mb-0">Certificado vigente y validado por CEONext.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
