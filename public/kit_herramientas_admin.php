<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$usuario = trim((string)($_SESSION['auth']['nombre'] ?? 'Administrador'));

$tools = [
    [
        'title' => 'Historico Prueba Teorica',
        'route' => app_url('/public/cargar_historico_prueba_teorica.php'),
        'description' => 'Analisis e importacion historica de resultados teoricos.',
        'accent' => '#2563eb',
        'icon' => 'bi-file-earmark-bar-graph',
    ],
    [
        'title' => 'Historico Terreno',
        'route' => app_url('/public/cargar_historico_terreno.php'),
        'description' => 'Carga historica de terreno con normalizacion y asociacion de procesos.',
        'accent' => '#0f766e',
        'icon' => 'bi-map',
    ],
    [
        'title' => 'Update Respuestas Terreno',
        'route' => app_url('/public/herramienta_terreno_update.php'),
        'description' => 'Corrige respuestas historicas de terreno desde Excel con preview y procesamiento por segmentos.',
        'accent' => '#1d4ed8',
        'icon' => 'bi-arrow-repeat',
    ],
    [
        'title' => 'Integrar Persona',
        'route' => app_url('/public/integrar_persona_planificacion.php'),
        'description' => 'Integra o anula pruebas de formacion y habilitacion sobre planificaciones existentes.',
        'accent' => '#7c3aed',
        'icon' => 'bi-person-plus',
    ],
    [
        'title' => 'Requerimientos',
        'route' => 'http://localhost:8888/track/public/list.php',
        'description' => 'Seguimiento de requerimientos operativos fuera del flujo principal de CEONext.',
        'accent' => '#ea580c',
        'icon' => 'bi-kanban',
    ],
    [
        'title' => 'Access Excel',
        'route' => 'http://localhost:8888/access_excel_php/index.php',
        'description' => 'Utilidad auxiliar para consultas y procesos vinculados a archivos Access y Excel.',
        'accent' => '#15803d',
        'icon' => 'bi-filetype-xlsx',
    ],
    [
        'title' => 'Recuperacion Respaldo BD',
        'route' => 'https://www.noetica.cl/respbd_ceonext/index.php',
        'description' => 'Herramienta que permite seleccionar archivo SQL y cargar su contenido a base de datos. CUIDADO: herramienta sensible de carga a base de datos.',
        'accent' => '#b91c1c',
        'icon' => 'bi-database-fill-up',
    ],
    [
        'title' => 'Recuperacion Respaldo BD Local',
        'route' => 'http://localhost:8888/respbd_ceonext/index.php',
        'description' => 'Herramienta local para seleccionar archivo SQL y cargar su contenido a la base de datos en MAMP. CUIDADO: herramienta sensible de carga a base de datos.',
        'accent' => '#991b1b',
        'icon' => 'bi-database-fill-up',
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Kit de Herramientas | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    min-height: 100vh;
    background:
        radial-gradient(circle at top right, rgba(37, 99, 235, 0.10), transparent 30%),
        linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
    color: #0f172a;
}

.shell {
    max-width: 1200px;
}

.hero {
    background: rgba(255,255,255,0.88);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 28px;
    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
}

.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .8rem;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.10);
    color: #1d4ed8;
    font-size: .9rem;
    font-weight: 600;
}

.tool-card {
    position: relative;
    overflow: hidden;
    border: 0;
    border-radius: 24px;
    background: rgba(255,255,255,0.92);
    box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
    transition: transform .18s ease, box-shadow .18s ease;
    height: 100%;
}

.tool-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
}

.tool-card::after {
    content: '';
    position: absolute;
    inset: auto -20% -35% auto;
    width: 150px;
    height: 150px;
    border-radius: 999px;
    background: var(--card-accent);
    opacity: .10;
}

.tool-icon {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    background: color-mix(in srgb, var(--card-accent) 14%, white);
    color: var(--card-accent);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--card-accent) 16%, white);
}

.route-box {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .83rem;
    word-break: break-all;
    padding: .85rem 1rem;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px solid rgba(148, 163, 184, 0.22);
    color: #334155;
}

.btn-tool {
    background: var(--card-accent);
    border-color: var(--card-accent);
}

.btn-tool:hover,
.btn-tool:focus {
    background: color-mix(in srgb, var(--card-accent) 88%, black);
    border-color: color-mix(in srgb, var(--card-accent) 88%, black);
}
</style>
</head>
<body>
<div class="container py-4 py-lg-5 shell">
    <section class="hero p-4 p-lg-5 mb-4 mb-lg-5">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
            <div>
                <span class="hero-badge mb-3">
                    <i class="bi bi-shield-lock"></i>
                    Solo Administrador CEONext
                </span>
                <h1 class="display-6 fw-semibold mb-3">Kit de Herramientas</h1>
                <p class="text-secondary mb-2">Herramientas auxiliares fuera de la explotacion normal de CEONext, disponibles para apoyar carga, ajuste y consulta operativa.</p>
                <p class="mb-0 small text-muted">Sesión actual: <strong><?= esc($usuario) ?></strong></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= esc(app_url('/public/general.php')) ?>">Volver al Panel</a>
            </div>
        </div>
    </section>

    <section class="row g-4">
        <?php foreach ($tools as $tool): ?>
            <div class="col-md-6 col-xl-4">
                <article class="tool-card p-4" style="--card-accent: <?= esc($tool['accent']) ?>;">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-4">
                        <div class="tool-icon">
                            <i class="bi <?= esc($tool['icon']) ?>"></i>
                        </div>
                        <span class="badge rounded-pill text-bg-light border">Herramienta</span>
                    </div>

                    <h2 class="h4 mb-2"><?= esc($tool['title']) ?></h2>
                    <p class="text-secondary mb-3"><?= esc($tool['description']) ?></p>

                    <div class="small text-uppercase text-muted fw-semibold mb-2">Ruta</div>
                    <div class="route-box mb-4"><?= esc($tool['route']) ?></div>

                    <div class="d-flex gap-2">
                        <a
                            class="btn btn-tool text-white"
                            href="<?= esc($tool['route']) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i class="bi bi-box-arrow-up-right me-1"></i>
                            Abrir
                        </a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </section>
</div>
</body>
</html>
