<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

$token = trim((string)($_GET['token'] ?? ''));
$cert = null;
$error = '';
$servicio = 'RIE';

function vrieEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vrieFmtFecha(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }

    $ts = strtotime($value);
    return $ts ? date('d-m-Y', $ts) : $value;
}

if ($token === '') {
    $error = 'Token de validacion no informado.';
} else {
    try {
        $pdo = db();
        try {
            $stmt = $pdo->prepare('SELECT nombres, apellidos, rut, servicio, fecha_emision, fecha_vigencia FROM ceo_rie_aprobados WHERE token_qr = :token LIMIT 1');
            $stmt->execute([':token' => $token]);
        } catch (PDOException) {
            // Keep existing RIE QR validations available until the certificate migration runs.
            $stmt = $pdo->prepare('SELECT nombres, apellidos, rut, servicio FROM ceo_rie_aprobados WHERE token_qr = :token LIMIT 1');
            $stmt->execute([':token' => $token]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            $error = 'Certificado no encontrado.';
        } else {
            $servicio = trim((string)($row['servicio'] ?? '')) ?: $servicio;
            $cert = [
                'nombre' => trim((string)$row['nombres'] . ' ' . (string)$row['apellidos']),
                'rut' => (string)$row['rut'],
                'fecha_emision' => (string)($row['fecha_emision'] ?? '') ?: '2026-07-14',
                'fecha_vigencia' => (string)($row['fecha_vigencia'] ?? '') ?: '2029-07-14',
            ];
        }
    } catch (Throwable $e) {
        $error = 'No fue posible consultar el certificado.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consulta Certificado <?= vrieEsc($servicio) ?> - <?= vrieEsc(APP_NAME) ?></title>
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
    <img src="<?= vrieEsc(APP_LOGO) ?>" alt="Logo" style="height:52px;">
    <div>
      <div class="brand"><?= vrieEsc(APP_NAME) ?></div>
      <div class="text-muted small">Consulta publica de certificado</div>
    </div>
  </div>
</header>

<main class="container wrap mb-5">
  <div class="card rounded-4">
    <div class="card-body p-4 p-md-5">
      <h1 class="h4 text-primary fw-bold mb-4">Consulta de Certificado <?= vrieEsc($servicio) ?></h1>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger mb-0"><?= vrieEsc($error) ?></div>
      <?php else: ?>
        <div class="row g-3">
          <div class="col-12"><div class="label">Persona</div><div class="value"><?= vrieEsc($cert['nombre']) ?></div></div>
          <div class="col-md-6"><div class="label">RUT</div><div class="value"><?= vrieEsc($cert['rut']) ?></div></div>
          <div class="col-md-6"><div class="label">Fecha emisión</div><div class="value"><?= vrieEsc(vrieFmtFecha($cert['fecha_emision'])) ?></div></div>
          <div class="col-md-6"><div class="label">Fecha vigencia</div><div class="value"><?= vrieEsc(vrieFmtFecha($cert['fecha_vigencia'])) ?></div></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
