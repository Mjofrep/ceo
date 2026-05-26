<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);
$auth = gpRequireAuth();

$stats = [
    'usuarios' => (int)$pdo->query('SELECT COUNT(*) FROM ceo_gp_usuarios')->fetchColumn(),
    'fuentes' => 0,
    'preguntas' => 0,
    'publicadas' => 0,
];

foreach (['ceo_gp_fuentes' => 'fuentes', 'ceo_gp_preguntas' => 'preguntas'] as $table => $key) {
    try {
        $stats[$key] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable $e) {
        $stats[$key] = 0;
    }
}

try {
    $stats['publicadas'] = (int)$pdo->query("SELECT COUNT(*) FROM ceo_gp_preguntas WHERE estado = 'PUBLICADA'")->fetchColumn();
} catch (Throwable $e) {
    $stats['publicadas'] = 0;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Gestor de Preguntas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc;color:#0f172a;}
    .topbar{background:#fff;border-bottom:1px solid rgba(13,110,253,.12);box-shadow:0 1px 6px rgba(15,23,42,.04);}
    .hero{background:linear-gradient(135deg,#0d6efd,#18a36f);border-radius:28px;color:#fff;box-shadow:0 20px 48px rgba(13,110,253,.22);}
    .card-link{border:0;border-radius:22px;box-shadow:0 10px 30px rgba(15,23,42,.07);transition:transform .15s ease, box-shadow .15s ease;}
    .card-link:hover{transform:translateY(-3px);box-shadow:0 16px 38px rgba(15,23,42,.11);}
    .icon-box{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;background:#eef5ff;color:#0d6efd;font-size:1.35rem;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= gpEsc(APP_LOGO) ?>" alt="Logo" style="height:58px;">
      <div>
        <div class="fw-bold h5 mb-0">Gestor de Preguntas</div>
        <small class="text-muted">Banco de preguntas para habilitacion y formacion</small>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-light border"><?= gpEsc($auth['nombre']) ?> - <?= gpEsc($auth['rol_nombre']) ?></span>
      <a href="gp_logout.php" class="btn btn-outline-secondary btn-sm">Salir</a>
    </div>
  </div>
</header>

<main class="container pb-5">
  <section class="hero p-4 p-md-5 mb-4">
    <div class="row align-items-center g-4">
      <div class="col-lg-8">
        <div class="badge text-bg-light text-primary mb-3">V1 Base Operativa</div>
        <h1 class="display-6 fw-bold mb-2">Gestiona preguntas antes de publicarlas oficialmente</h1>
        <p class="lead mb-0 opacity-75">Carga fuentes, genera borradores, revisa con Operacion y publica a bancos de habilitacion o formacion.</p>
      </div>
      <div class="col-lg-4">
        <div class="row g-2">
          <div class="col-6"><div class="bg-white bg-opacity-10 rounded-4 p-3"><div class="fs-3 fw-bold"><?= (int)$stats['usuarios'] ?></div><div class="small">Usuarios</div></div></div>
          <div class="col-6"><div class="bg-white bg-opacity-10 rounded-4 p-3"><div class="fs-3 fw-bold"><?= (int)$stats['fuentes'] ?></div><div class="small">Fuentes</div></div></div>
          <div class="col-6"><div class="bg-white bg-opacity-10 rounded-4 p-3"><div class="fs-3 fw-bold"><?= (int)$stats['preguntas'] ?></div><div class="small">Preguntas</div></div></div>
          <div class="col-6"><div class="bg-white bg-opacity-10 rounded-4 p-3"><div class="fs-3 fw-bold"><?= (int)$stats['publicadas'] ?></div><div class="small">Publicadas</div></div></div>
        </div>
      </div>
    </div>
  </section>

  <div class="row g-4">
    <?php if (gpIsAdmin()): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card card-link text-decoration-none text-dark h-100" href="gp_usuarios.php">
        <div class="card-body p-4">
          <div class="icon-box mb-3"><i class="bi bi-people"></i></div>
          <h2 class="h5 fw-bold">Usuarios y roles</h2>
          <p class="text-muted mb-0">Administra cuentas paralelas del Gestor de Preguntas.</p>
        </div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (!gpHasRole('OPERACION')): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card card-link text-decoration-none text-dark h-100" href="gp_fuentes.php">
        <div class="card-body p-4">
          <div class="icon-box mb-3"><i class="bi bi-file-earmark-richtext"></i></div>
          <h2 class="h5 fw-bold">Fuentes y documentos</h2>
          <p class="text-muted mb-0">Carga texto, TXT, PDF, DOCX, XLSX y CSV para preparar contenido IA.</p>
        </div>
      </a>
    </div>
    <div class="col-md-6 col-xl-4">
      <a class="card card-link text-decoration-none text-dark h-100" href="gp_generacion.php">
        <div class="card-body p-4">
          <div class="icon-box mb-3"><i class="bi bi-stars"></i></div>
          <h2 class="h5 fw-bold">Generacion IA</h2>
          <p class="text-muted mb-0">Genera borradores desde fuentes cargadas, sin publicar al banco oficial.</p>
        </div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (gpHasRole(['ADMIN', 'REVISOR', 'CREADOR'])): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card card-link text-decoration-none text-dark h-100" href="gp_revision.php">
        <div class="card-body p-4">
          <div class="icon-box mb-3"><i class="bi bi-search"></i></div>
          <h2 class="h5 fw-bold">Revision y Correccion</h2>
          <p class="text-muted mb-0">Revisa, corrige, recibe observaciones y prepara preguntas visadas para publicacion.</p>
        </div>
      </a>
    </div>
    <?php endif; ?>
    <?php if (gpHasRole(['ADMIN', 'OPERACION'])): ?>
    <div class="col-md-6 col-xl-4">
      <a class="card card-link text-decoration-none text-dark h-100" href="gp_operacion.php">
        <div class="card-body p-4">
          <div class="icon-box mb-3"><i class="bi bi-shield-check"></i></div>
          <h2 class="h5 fw-bold">Visacion Operacion</h2>
          <p class="text-muted mb-0">Visa u observa lotes completos desde el punto de vista conceptual.</p>
        </div>
      </a>
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
