<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';

$pdo = db();

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
$idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$idUsuario = (int)($_SESSION['auth']['id'] ?? 0);
function calendarioTextoPlano(?string $valor): string
{
    return trim(preg_replace('/\s+/', ' ', (string)$valor));
}

function calendarioFormatoHora(?string $hora): string
{
    $hora = trim((string)$hora);
    if ($hora === '') {
        return '';
    }

    return substr($hora, 0, 5);
}

function calendarioTipoSolicitud(?string $habilitacionCeo): array
{
    $texto = trim((string)$habilitacionCeo);
    $normalizado = mb_strtolower($texto, 'UTF-8');

    if ($texto !== '' && str_contains($normalizado, 'capacit')) {
        return [
            'tipo' => 'FORMACION',
            'tipo_label' => 'Formacion',
            'orden' => 30,
            'meta' => 'Capacitacion autorizada',
        ];
    }

    if ($texto !== '' && str_contains($normalizado, 'habilit')) {
        return [
            'tipo' => 'HABILITACION',
            'tipo_label' => 'Habilitacion',
            'orden' => 20,
            'meta' => 'Habilitacion autorizada',
        ];
    }

    return [
        'tipo' => 'PERMISO',
        'tipo_label' => 'Permiso',
        'orden' => 10,
        'meta' => 'Autorizada',
    ];
}

function calendarioFechaValida(?string $fecha): ?DateTimeImmutable
{
    $fecha = trim((string)$fecha);
    if ($fecha === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha) {
        return null;
    }

    return $dt;
}

$hoy = new DateTimeImmutable('today');
$fechaAncla = calendarioFechaValida($_GET['fecha'] ?? '') ?? $hoy;
$fechaInicio = $fechaAncla;
$fechaFin = $fechaAncla->modify('+7 days');

$diasSemana = [
    'Monday' => 'Lunes',
    'Tuesday' => 'Martes',
    'Wednesday' => 'Miercoles',
    'Thursday' => 'Jueves',
    'Friday' => 'Viernes',
    'Saturday' => 'Sabado',
    'Sunday' => 'Domingo',
];

$meses = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre',
];

$dias = [];
$cursor = $fechaInicio;
while ($cursor <= $fechaFin) {
    $clave = $cursor->format('Y-m-d');
    $dias[$clave] = [
        'fecha' => $cursor,
        'eventos' => [],
    ];
    $cursor = $cursor->modify('+1 day');
}

$resumen = [
    'PERMISO' => 0,
    'HABILITACION' => 0,
    'FORMACION' => 0,
];

$error = '';

try {
    $sqlPermisos = "
        SELECT
            s.nsolicitud,
            s.fecha,
            s.horainicio,
            s.horatermino,
            s.estado,
            COALESCE(ht.desc_tipo, '') AS habilitacion_ceo,
            COALESCE(ch.desc_charlas, '') AS capacitacion,
            ce.nombre AS empresa,
            COALESCE(u.desc_uo, '') AS uo,
            COALESCE(sv.servicio, '') AS servicio,
            COALESCE(pa.desc_patios, '') AS patio,
            CONCAT(COALESCE(us.nombres, ''), ' ', COALESCE(us.apellidos, '')) AS solicitante,
            (
                SELECT COUNT(*)
                FROM ceo_participantes_solicitud ps
                WHERE ps.id_solicitud = s.nsolicitud
                  AND ps.autorizado = 1
            ) AS participantes_autorizados
        FROM ceo_solicitudes s
        LEFT JOIN ceo_empresas ce ON ce.id = s.contratista
        LEFT JOIN ceo_uo u ON u.id = s.uo
        LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_usuarios us ON us.id = s.solicitante
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        WHERE s.estado = 'A'
          AND s.fecha BETWEEN :desde AND :hasta
    ";

    $paramsPermisos = [
        ':desde' => $fechaInicio->format('Y-m-d'),
        ':hasta' => $fechaFin->format('Y-m-d'),
    ];

    $empresaEnel = 39;
    if (!(($idRol === 1 || $idRol === 5) && $idEmpresa === $empresaEnel)) {
        if ($idRol === 3 || $idRol === 4) {
            $sqlPermisos .= " AND s.solicitante = :iduser";
            $paramsPermisos[':iduser'] = $idUsuario;
        } else {
            $sqlPermisos .= " AND (s.contratista = :empresa OR s.solicitante = :iduser)";
            $paramsPermisos[':empresa'] = $idEmpresa;
            $paramsPermisos[':iduser'] = $idUsuario;
        }
    }

    $sqlPermisos .= " ORDER BY s.fecha ASC, s.horainicio ASC, s.nsolicitud ASC";

    $stmtPermisos = $pdo->prepare($sqlPermisos);
    $stmtPermisos->execute($paramsPermisos);
    foreach ($stmtPermisos->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fechaClave = (string)($row['fecha'] ?? '');
        if (!isset($dias[$fechaClave])) {
            continue;
        }

        $horaInicio = calendarioFormatoHora($row['horainicio'] ?? '');
        $horaTermino = calendarioFormatoHora($row['horatermino'] ?? '');
        $bloque = trim($horaInicio . ($horaTermino !== '' ? ' - ' . $horaTermino : ''));
        $tipoSolicitud = calendarioTipoSolicitud($row['habilitacion_ceo'] ?? '');
        $resumen[$tipoSolicitud['tipo']]++;

        $habilitacionCeo = calendarioTextoPlano($row['habilitacion_ceo'] ?? '');
        $capacitacion = calendarioTextoPlano($row['capacitacion'] ?? '');
        $detalle = calendarioTextoPlano($row['patio'] ?? '');

        if ($tipoSolicitud['tipo'] === 'HABILITACION' && $habilitacionCeo !== '') {
            $detalle = $habilitacionCeo;
        } elseif ($tipoSolicitud['tipo'] === 'FORMACION') {
            $partesDetalle = [];
            if ($habilitacionCeo !== '') {
                $partesDetalle[] = $habilitacionCeo;
            }
            if ($capacitacion !== '') {
                $partesDetalle[] = $capacitacion;
            }
            if ($partesDetalle) {
                $detalle = implode(' / ', $partesDetalle);
            }
        }

        $dias[$fechaClave]['eventos'][] = [
            'tipo' => $tipoSolicitud['tipo'],
            'tipo_label' => $tipoSolicitud['tipo_label'],
            'orden' => $tipoSolicitud['orden'],
            'suborden' => ($horaInicio !== '' ? (int)str_replace(':', '', $horaInicio) : 9999),
            'titulo' => 'Solicitud #' . (int)$row['nsolicitud'],
            'bloque' => $bloque !== '' ? $bloque : 'Sin horario',
            'empresa' => calendarioTextoPlano($row['empresa'] ?? ''),
            'servicio' => calendarioTextoPlano($row['servicio'] ?? ''),
            'uo' => calendarioTextoPlano($row['uo'] ?? ''),
            'detalle' => $detalle,
            'responsable' => calendarioTextoPlano($row['solicitante'] ?? ''),
            'personas' => (int)($row['participantes_autorizados'] ?? 0),
            'estado' => 'AUTORIZADA',
            'href' => 'solicitud_detalle.php?id=' . urlencode((string)$row['nsolicitud']),
            'meta' => $tipoSolicitud['meta'],
        ];
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

foreach ($dias as &$dia) {
    usort($dia['eventos'], static function (array $a, array $b): int {
        if ($a['orden'] === $b['orden']) {
            if ($a['suborden'] === $b['suborden']) {
                return strcmp((string)$a['titulo'], (string)$b['titulo']);
            }
            return $a['suborden'] <=> $b['suborden'];
        }
        return $a['orden'] <=> $b['orden'];
    });
}
unset($dia);

$fechaInicioTexto = $diasSemana[$fechaInicio->format('l')] . ' ' . $fechaInicio->format('d') . ' de ' . $meses[(int)$fechaInicio->format('n')];
$fechaFinTexto = $diasSemana[$fechaFin->format('l')] . ' ' . $fechaFin->format('d') . ' de ' . $meses[(int)$fechaFin->format('n')];
$fechaAnclaTexto = $fechaAncla->format('d-m-Y');
$urlHoy = 'calendario_actividades.php?fecha=' . urlencode($hoy->format('Y-m-d'));
$urlPrev = 'calendario_actividades.php?fecha=' . urlencode($fechaAncla->modify('-7 days')->format('Y-m-d'));
$urlNext = 'calendario_actividades.php?fecha=' . urlencode($fechaAncla->modify('+7 days')->format('Y-m-d'));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Calendario de Actividades - <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --cal-bg: #f4f7fb;
    --cal-ink: #18324b;
    --cal-line: #d8e1ec;
    --cal-panel: #ffffff;
    --cal-accent: #0b69c7;
    --cal-permiso: #0f766e;
    --cal-habilitacion: #c2410c;
    --cal-formacion: #7c3aed;
}

body {
    background:
        radial-gradient(circle at top left, rgba(11,105,199,0.10), transparent 28%),
        linear-gradient(180deg, #f7fbff 0%, var(--cal-bg) 100%);
    color: var(--cal-ink);
}

.topbar {
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid rgba(24,50,75,0.08);
}

.brand-title {
    color: #0b4d8d;
    font-weight: 700;
}

.hero-card,
.summary-card,
.day-card,
.legend-card {
    border: 1px solid rgba(24,50,75,0.08);
    box-shadow: 0 12px 30px rgba(24,50,75,0.06);
}

.hero-card {
    border-radius: 24px;
    background:
        linear-gradient(135deg, rgba(255,255,255,0.96), rgba(244,249,255,0.96)),
        #fff;
    overflow: hidden;
    position: relative;
}

.hero-card::after {
    content: "";
    position: absolute;
    inset: auto -120px -120px auto;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(11,105,199,0.18) 0%, rgba(11,105,199,0) 70%);
    pointer-events: none;
}

.hero-kicker {
    text-transform: uppercase;
    letter-spacing: .14em;
    font-size: .72rem;
    color: #5d7690;
    font-weight: 700;
}

.hero-title {
    font-size: clamp(1.35rem, 1.8vw, 1.95rem);
    font-weight: 800;
    color: #12395c;
}

.summary-card {
    border-radius: 18px;
    background: var(--cal-panel);
}

.summary-card .count {
    font-size: 1.55rem;
    line-height: 1;
    font-weight: 800;
}

.legend-chip,
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    border-radius: 999px;
    padding: .5rem .8rem;
    border: 1px solid rgba(24,50,75,0.10);
    background: rgba(255,255,255,0.88);
    font-size: .79rem;
    font-weight: 600;
}

.dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.dot-permiso { background: var(--cal-permiso); }
.dot-habilitacion { background: var(--cal-habilitacion); }
.dot-formacion { background: var(--cal-formacion); }

.filter-chip input {
    margin: 0;
}

.calendar-strip {
    display: flex;
    flex-direction: column;
    gap: .85rem;
}

.day-card {
    border-radius: 18px;
    background: rgba(255,255,255,0.96);
    min-height: 0;
}

.day-card.is-today {
    border-color: rgba(11,105,199,0.35);
    box-shadow: 0 18px 40px rgba(11,105,199,0.14);
}

.day-head {
    padding: .9rem 1rem .7rem;
    border-bottom: 1px solid rgba(24,50,75,0.08);
}

.day-name {
    font-weight: 800;
    color: #103b61;
    font-size: 1rem;
}

.day-date {
    color: #58738d;
    font-size: .82rem;
}

.today-pill {
    font-size: .67rem;
    font-weight: 700;
    color: #0b69c7;
    background: rgba(11,105,199,0.10);
    border: 1px solid rgba(11,105,199,0.15);
    padding: .2rem .55rem;
    border-radius: 999px;
}

.day-body {
    padding: .85rem 1rem 1rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: .7rem;
}

.empty-state {
    border: 1px dashed rgba(24,50,75,0.16);
    border-radius: 18px;
    padding: .9rem;
    color: #71879c;
    background: rgba(244,247,251,0.92);
    text-align: center;
}

.event-item {
    border-radius: 14px;
    padding: .78rem;
    text-decoration: none;
    color: inherit;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,251,255,0.98));
    border: 1px solid rgba(24,50,75,0.08);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.event-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 26px rgba(24,50,75,0.10);
}

.event-item[data-type="PERMISO"] { border-left: 4px solid var(--cal-permiso); }
.event-item[data-type="HABILITACION"] { border-left: 4px solid var(--cal-habilitacion); }
.event-item[data-type="FORMACION"] { border-left: 4px solid var(--cal-formacion); }

.event-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    margin-bottom: .4rem;
}

.event-tag {
    font-size: .66rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
}

.event-tag.permiso { color: var(--cal-permiso); }
.event-tag.habilitacion { color: var(--cal-habilitacion); }
.event-tag.formacion { color: var(--cal-formacion); }

.event-status {
    font-size: .66rem;
    font-weight: 700;
    border-radius: 999px;
    padding: .22rem .55rem;
    background: rgba(24,50,75,0.07);
    color: #486176;
}

.event-title {
    font-weight: 800;
    color: #18324b;
    margin-bottom: .15rem;
    font-size: .92rem;
}

.event-block {
    color: #4d667d;
    font-size: .78rem;
    margin-bottom: .5rem;
}

.event-grid {
    display: grid;
    gap: .38rem;
    font-size: .76rem;
    color: #58738d;
}

.event-grid strong {
    color: #274560;
}

.legend-card {
    border-radius: 20px;
    background: rgba(255,255,255,0.92);
}

@media (max-width: 767px) {
    .hero-actions {
        width: 100%;
    }

    .hero-actions .btn,
    .hero-actions .btn-group {
        width: 100%;
    }

    .day-body {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container-fluid px-4 pb-5">
  <section class="hero-card p-4 p-lg-5 mb-4">
    <div class="row g-4 align-items-center">
      <div class="col-xl-7">
        <div class="hero-kicker mb-2">Vista consolidada</div>
        <h1 class="hero-title mb-2">Calendario de actividades planificadas</h1>
        <p class="text-secondary mb-3">
          Se muestran solicitudes <strong>autorizadas</strong> clasificadas por su Habilitacion CEO, desde la fecha base hacia los siguientes 7 dias.
        </p>
        <div class="d-flex flex-wrap gap-2">
          <span class="legend-chip"><span class="dot dot-permiso"></span>Permisos autorizados</span>
          <span class="legend-chip"><span class="dot dot-habilitacion"></span>Solicitudes de habilitacion</span>
          <span class="legend-chip"><span class="dot dot-formacion"></span>Solicitudes de formacion</span>
        </div>
      </div>
      <div class="col-xl-5">
        <div class="legend-card p-3 p-lg-4">
          <div class="small text-uppercase text-secondary fw-semibold mb-2">Rango visible</div>
          <div class="h5 mb-1 text-primary"><?= esc($fechaInicioTexto) ?> al <?= esc($fechaFinTexto) ?></div>
          <div class="text-secondary mb-3">Fecha ancla: <?= esc($fechaAnclaTexto) ?></div>
          <div class="hero-actions d-flex flex-wrap gap-2">
            <a href="<?= esc($urlPrev) ?>" class="btn btn-outline-primary btn-sm">
              <i class="bi bi-arrow-left"></i> Semana anterior
            </a>
            <a href="<?= esc($urlHoy) ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-calendar2-check"></i> Hoy
            </a>
            <a href="<?= esc($urlNext) ?>" class="btn btn-outline-primary btn-sm">
              Semana siguiente <i class="bi bi-arrow-right"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="summary-card p-3 h-100">
        <div class="small text-secondary text-uppercase fw-semibold mb-2">Permisos</div>
        <div class="count" style="color: var(--cal-permiso);"><?= esc((string)$resumen['PERMISO']) ?></div>
        <div class="text-secondary small">Solicitudes autorizadas que no corresponden a habilitacion ni capacitacion.</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="summary-card p-3 h-100">
        <div class="small text-secondary text-uppercase fw-semibold mb-2">Habilitaciones</div>
        <div class="count" style="color: var(--cal-habilitacion);"><?= esc((string)$resumen['HABILITACION']) ?></div>
        <div class="text-secondary small">Solicitudes autorizadas clasificadas como habilitacion.</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="summary-card p-3 h-100">
        <div class="small text-secondary text-uppercase fw-semibold mb-2">Formaciones</div>
        <div class="count" style="color: var(--cal-formacion);"><?= esc((string)$resumen['FORMACION']) ?></div>
        <div class="text-secondary small">Solicitudes autorizadas clasificadas como formacion o capacitacion.</div>
      </div>
    </div>
  </section>

  <section class="legend-card p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div>
        <div class="fw-semibold mb-1">Filtros rapidos</div>
        <div class="text-secondary small">Puedes ocultar o mostrar tipos de actividad sin recargar la pagina.</div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <label class="filter-chip">
          <input type="checkbox" class="form-check-input js-filter" value="PERMISO" checked>
          Permisos
        </label>
        <label class="filter-chip">
          <input type="checkbox" class="form-check-input js-filter" value="HABILITACION" checked>
          Habilitaciones
        </label>
        <label class="filter-chip">
          <input type="checkbox" class="form-check-input js-filter" value="FORMACION" checked>
          Formaciones
        </label>
      </div>
    </div>
  </section>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger mb-4">Error al cargar calendario: <?= esc($error) ?></div>
  <?php endif; ?>

  <section class="calendar-strip">
    <?php foreach ($dias as $clave => $dia): ?>
      <?php
      $fechaDia = $dia['fecha'];
      $esHoy = ($clave === $hoy->format('Y-m-d'));
      $nombreDia = $diasSemana[$fechaDia->format('l')] ?? $fechaDia->format('l');
      $textoFecha = $fechaDia->format('d') . ' ' . ($meses[(int)$fechaDia->format('n')] ?? $fechaDia->format('m'));
      ?>
      <article class="day-card<?= $esHoy ? ' is-today' : '' ?>">
        <div class="day-head">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="day-name"><?= esc($nombreDia) ?></div>
              <div class="day-date"><?= esc($textoFecha) ?></div>
            </div>
            <?php if ($esHoy): ?>
              <span class="today-pill">Hoy</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="day-body">
          <?php if (!$dia['eventos']): ?>
            <div class="empty-state">
              <i class="bi bi-calendar-x d-block fs-4 mb-2"></i>
              Sin actividades visibles para este dia.
            </div>
          <?php else: ?>
            <?php foreach ($dia['eventos'] as $evento): ?>
              <?php
              $tagClass = 'permiso';
              if ($evento['tipo'] === 'HABILITACION') {
                  $tagClass = 'habilitacion';
              } elseif ($evento['tipo'] === 'FORMACION') {
                  $tagClass = 'formacion';
              }
              ?>
              <a
                href="<?= esc($evento['href']) ?>"
                class="event-item"
                data-type="<?= esc($evento['tipo']) ?>"
              >
                <div class="event-top">
                  <span class="event-tag <?= esc($tagClass) ?>"><?= esc($evento['tipo_label']) ?></span>
                  <span class="event-status"><?= esc($evento['estado']) ?></span>
                </div>
                <div class="event-title"><?= esc($evento['titulo']) ?></div>
                <div class="event-block"><i class="bi bi-clock-history me-1"></i><?= esc($evento['bloque']) ?></div>
                <div class="event-grid">
                  <div><strong>Empresa:</strong> <?= esc($evento['empresa'] !== '' ? $evento['empresa'] : 'Sin empresa') ?></div>
                  <div><strong>Servicio:</strong> <?= esc($evento['servicio'] !== '' ? $evento['servicio'] : 'Sin servicio') ?></div>
                  <div><strong>UO:</strong> <?= esc($evento['uo'] !== '' ? $evento['uo'] : 'Sin UO') ?></div>
                  <div><strong>Responsable:</strong> <?= esc($evento['responsable'] !== '' ? $evento['responsable'] : 'Sin responsable') ?></div>
                  <div><strong>Personas:</strong> <?= esc((string)$evento['personas']) ?></div>
                  <div><strong>Detalle:</strong> <?= esc($evento['detalle'] !== '' ? $evento['detalle'] : $evento['meta']) ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
</main>

<script>
(() => {
  const checks = Array.from(document.querySelectorAll('.js-filter'));
  const cards = Array.from(document.querySelectorAll('.event-item'));

  function applyFilters() {
    const allowed = new Set(
      checks.filter((check) => check.checked).map((check) => check.value)
    );

    cards.forEach((card) => {
      const type = card.dataset.type || '';
      card.style.display = allowed.has(type) ? '' : 'none';
    });
  }

  checks.forEach((check) => {
    check.addEventListener('change', applyFilters);
  });

  applyFilters();
})();
</script>
</body>
</html>
