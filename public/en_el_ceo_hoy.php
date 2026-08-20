<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../src/Csrf.php';

$pdo = db();

if (!function_exists('echEsc')) {
    function echEsc(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function echHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column
         LIMIT 1'
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function echFormatDateSpanish(string $date): string
{
    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];
    $days = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ];

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: new DateTimeImmutable();
    $dayName = $days[(int)$dt->format('w')] ?? '';
    $monthName = $months[(int)$dt->format('n')] ?? '';

    return sprintf('%s %s de %s de %s', $dayName, $dt->format('d'), $monthName, $dt->format('Y'));
}

function echNormalizeText(string $value): string
{
    return strtoupper(trim($value));
}

function echDetectSegment(array $row): string
{
    $tipoVisita = echNormalizeText((string)($row['tipo_visita'] ?? ''));
    $charla = echNormalizeText((string)($row['charla_nombre'] ?? ''));
    $habilitacion = echNormalizeText((string)($row['habilitacion_nombre'] ?? ''));
    $proceso = echNormalizeText((string)($row['proceso_nombre'] ?? ''));
    $observacion = echNormalizeText((string)($row['observacion'] ?? ''));
    $habilitacionId = (int)($row['habilitacionceo'] ?? 0);
    $procesoId = (int)($row['proceso'] ?? 0);

    if ($procesoId === 24 || $charla === 'RDO' || $proceso === 'RDO') {
        return 'rdo';
    }

    if ($tipoVisita !== '') {
        return 'visitas';
    }

    if ($charla !== '' || ($habilitacionId === 6 && $observacion !== '')) {
        return 'capacitaciones';
    }

    if ($habilitacionId > 0 || $habilitacion !== '') {
        return 'habilitaciones';
    }

    return 'otras';
}

function echBuildActivityName(array $row, string $segment): string
{
    $tipoVisita = trim((string)($row['tipo_visita'] ?? ''));
    $charla = trim((string)($row['charla_nombre'] ?? ''));
    $habilitacion = trim((string)($row['habilitacion_nombre'] ?? ''));
    $proceso = trim((string)($row['proceso_nombre'] ?? ''));
    $servicio = trim((string)($row['servicio_nombre'] ?? ''));
    $observacion = trim((string)($row['observacion'] ?? ''));

    if ($segment === 'rdo') {
        return 'RDO';
    }

    if ($segment === 'visitas' && $tipoVisita !== '') {
        return $tipoVisita;
    }

    if ($segment === 'capacitaciones') {
        if ($charla !== '') {
            return $charla;
        }
        if ($observacion !== '') {
            return $observacion;
        }
    }

    if ($segment === 'habilitaciones' && $habilitacion !== '') {
        return $habilitacion;
    }

    foreach ([$charla, $habilitacion, $proceso, $servicio, $observacion] as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'Actividad autorizada';
}

function echSegmentLabel(string $segment): string
{
    return match ($segment) {
        'habilitaciones' => 'Habilitaciones',
        'capacitaciones' => 'Capacitaciones',
        'visitas' => 'Visitas',
        'rdo' => 'RDO',
        default => 'Otras actividades',
    };
}

function echFormatHour(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '--:--';
    }

    return substr($value, 0, 5);
}

function echEnsureResponsableTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_en_el_ceo_hoy_responsable (
        id INT NOT NULL AUTO_INCREMENT,
        fecha DATE NOT NULL,
        nsolicitud INT NOT NULL,
        responsable_nombre VARCHAR(160) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ceo_hoy_fecha_solicitud (fecha, nsolicitud),
        KEY idx_ceo_hoy_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$today = date('Y-m-d');
$hasTipoVisita = false;
$rows = [];
$error = '';
$csrfToken = Csrf::token();

try {
    echEnsureResponsableTable($pdo);
    $hasTipoVisita = echHasColumn($pdo, 'ceo_solicitudes', 'tipo_visita');
    $tipoVisitaSelect = $hasTipoVisita ? 'COALESCE(s.tipo_visita, "") AS tipo_visita' : '"" AS tipo_visita';

    $sql = "
        SELECT
            s.nsolicitud,
            s.fecha,
            s.horainicio,
            s.horatermino,
            s.habilitacionceo,
            s.proceso,
            s.observacion,
            {$tipoVisitaSelect},
            COALESCE(e.nombre, '') AS empresa,
            COALESCE(p.desc_patios, '') AS patio,
            COALESCE(ht.desc_tipo, '') AS habilitacion_nombre,
            COALESCE(ch.desc_charlas, '') AS charla_nombre,
            COALESCE(pr.desc_proceso, '') AS proceso_nombre,
            COALESCE(sv.servicio, '') AS servicio_nombre,
            TRIM(CONCAT(COALESCE(ev.nombre, ''), ' ', COALESCE(ev.apellidop, ''), ' ', COALESCE(ev.apellidom, ''))) AS responsable_linea,
            COALESCE(rh.responsable_nombre, '') AS responsable_override
        FROM ceo_solicitudes s
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_patios p ON p.id = s.patio
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
        LEFT JOIN ceo_evaluador ev ON ev.id = s.resplinea
        LEFT JOIN ceo_en_el_ceo_hoy_responsable rh ON rh.fecha = s.fecha AND rh.nsolicitud = s.nsolicitud
        WHERE s.fecha = :fecha
          AND s.estado = 'A'
        ORDER BY s.horainicio ASC, s.horatermino ASC, s.nsolicitud ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fecha' => $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('en_el_ceo_hoy.php: ' . $e->getMessage());
    $error = 'No fue posible cargar las actividades autorizadas de hoy.';
}

$activities = [];
foreach ($rows as $row) {
    $segment = echDetectSegment($row);
    $override = trim((string)($row['responsable_override'] ?? ''));
    $responsableBase = trim((string)($row['responsable_linea'] ?? ''));
    $responsable = $override !== '' ? $override : ($responsableBase !== '' ? $responsableBase : 'No aplica');
    $activities[] = [
        'nsolicitud' => (int)($row['nsolicitud'] ?? 0),
        'horario' => echFormatHour((string)($row['horainicio'] ?? '')) . ' - ' . echFormatHour((string)($row['horatermino'] ?? '')),
        'actividad' => echBuildActivityName($row, $segment),
        'segmento' => $segment,
        'segmento_label' => echSegmentLabel($segment),
        'lugar' => trim((string)($row['patio'] ?? '')) !== '' ? (string)$row['patio'] : 'Por definir',
        'responsable' => $responsable,
        'responsable_base' => $responsableBase !== '' ? $responsableBase : 'No aplica',
        'responsable_override' => $override,
        'responsable_is_override' => $override !== '',
        'empresa' => trim((string)($row['empresa'] ?? '')) !== '' ? (string)$row['empresa'] : 'Sin empresa',
    ];
}

$todayLabel = echFormatDateSpanish($today);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>En el CEO Hoy - <?= echEsc(APP_NAME) ?></title>
  <link rel="icon" href="<?= echEsc(APP_FAVICON) ?>">
  <style>
    :root {
      --blue-strong: #18304f;
      --blue-brand: #2f73bb;
      --page-bg: #eef0f5;
      --card-bg: #f6f7fb;
      --table-border: #d6dbe6;
      --text-main: #223047;
      --text-soft: #6a7484;
      --pink: #ea5d95;
      --pink-strong: #c25fc2;
      --cyan: #5fe0e4;
      --orange: #f5b000;
      --slate: #9ba6b4;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      background: var(--page-bg);
      color: var(--text-main);
    }

    .top-stripe {
      height: 10px;
      background: linear-gradient(90deg, #25539a 0 66%, #e71f93 66% 85%, #f26722 85% 100%);
    }

    .page {
      max-width: 1160px;
      margin: 0 auto;
      padding: 18px 18px 22px;
    }

    .board {
      background: var(--card-bg);
      border: 1px solid #d9dde7;
      box-shadow: 0 6px 24px rgba(24, 48, 79, 0.08);
      padding: 18px 18px 12px;
    }

    .header {
      position: relative;
      min-height: 120px;
      padding: 4px 0 10px;
    }

    .brand {
      position: absolute;
      top: 4px;
      left: 0;
      display: flex;
      align-items: center;
      gap: 10px;
      max-width: 260px;
    }

    .brand img {
      width: 86px;
      height: auto;
      object-fit: contain;
    }

    .brand-copy {
      font-size: 13px;
      line-height: 1.2;
      color: #4f5d73;
      text-transform: uppercase;
      font-weight: 700;
    }

    .hero {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding-top: 8px;
    }

    .hero-title {
      background: var(--blue-brand);
      color: #fff;
      border-radius: 16px;
      padding: 22px 56px;
      font-size: 30px;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      text-align: center;
      box-shadow: inset 0 -2px 0 rgba(0, 0, 0, 0.08);
    }

    .hero-date {
      color: #667189;
      font-size: 28px;
      text-align: center;
      text-transform: capitalize;
    }

    .lunch {
      position: absolute;
      right: 10px;
      top: 0;
      width: 210px;
      min-height: 110px;
      background: #ffc61c;
      border-radius: 48% 52% 45% 55% / 46% 45% 55% 54%;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 16px 18px;
      color: #1d2c46;
      font-weight: 700;
      font-size: 14px;
      box-shadow: 0 8px 16px rgba(245, 176, 0, 0.25);
    }

    .lunch::after {
      content: '';
      position: absolute;
      left: 38px;
      bottom: -18px;
      width: 22px;
      height: 22px;
      background: #ffc61c;
      border-radius: 50% 50% 45% 55%;
      box-shadow: 18px 10px 0 -5px #ffc61c;
      transform: rotate(25deg);
    }

    .table-shell {
      border: 1px solid var(--table-border);
      border-radius: 12px;
      overflow: hidden;
      background: #fff;
    }

    .content-layout {
      display: grid;
      grid-template-columns: 220px minmax(0, 1fr);
      gap: 16px;
      align-items: start;
    }

    .legend-panel {
      border: 1px solid var(--table-border);
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #f5f7fb 100%);
      padding: 16px 14px;
    }

    .legend-title {
      margin: 0 0 14px;
      font-size: 18px;
      font-weight: 800;
      text-align: center;
      color: var(--blue-strong);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .legend-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 10px;
      min-height: 44px;
      padding: 8px 10px;
      border-radius: 10px;
      background: rgba(24, 48, 79, 0.04);
      color: var(--text-main);
      font-size: 15px;
      font-weight: 700;
    }

    .legend-swatch {
      width: 22px;
      height: 22px;
      border-radius: 6px;
      border: 1px solid rgba(24, 48, 79, 0.15);
      flex: 0 0 22px;
    }

    .legend-swatch-habilitaciones { background: var(--pink); }
    .legend-swatch-capacitaciones { background: var(--cyan); }
    .legend-swatch-visitas { background: var(--orange); }
    .legend-swatch-rdo { background: var(--pink-strong); }
    .legend-swatch-otras { background: #c6d2e2; }

    .table-scroll {
      max-height: calc(100vh - 285px);
      overflow-y: auto;
      scroll-behavior: smooth;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    thead th {
      background: var(--blue-strong);
      color: #fff;
      font-size: 18px;
      font-weight: 800;
      text-align: left;
      padding: 16px 18px;
      text-transform: uppercase;
      position: sticky;
      top: 0;
      z-index: 2;
    }

    tbody td {
      padding: 14px 18px;
      font-size: 17px;
      border-top: 1px solid rgba(255, 255, 255, 0.55);
      vertical-align: middle;
    }

    tbody tr.segmento-habilitaciones td { background: var(--pink); }
    tbody tr.segmento-capacitaciones td { background: var(--cyan); }
    tbody tr.segmento-visitas td { background: var(--orange); }
    tbody tr.segmento-rdo td { background: var(--pink-strong); color: #fff; }
    tbody tr.segmento-otras td { background: #c6d2e2; }

    .col-horario {
      width: 15%;
      color: #005ea6;
      font-weight: 800;
      white-space: nowrap;
    }

    .segmento-rdo .col-horario { color: #d1f1ff; }

    .col-actividad {
      width: 24%;
      font-weight: 700;
    }

    .col-lugar { width: 22%; }
    .col-responsable { width: 24%; }
    .col-empresa { width: 15%; }

    .responsable-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }

    .responsable-main {
      min-width: 0;
      flex: 1 1 auto;
    }

    .responsable-badge {
      display: inline-block;
      margin-top: 6px;
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(24, 48, 79, 0.12);
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }

    .responsable-action {
      width: 34px;
      height: 34px;
      border: 1px solid rgba(24, 48, 79, 0.18);
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.65);
      color: var(--blue-strong);
      font-size: 16px;
      line-height: 1;
      cursor: pointer;
      flex: 0 0 34px;
    }

    .responsable-action:hover {
      background: rgba(255, 255, 255, 0.9);
    }

    .segmento-rdo .responsable-badge,
    .segmento-rdo .responsable-action {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border-color: rgba(255, 255, 255, 0.28);
    }

    .segment-pill {
      display: inline-block;
      margin-top: 6px;
      padding: 4px 8px;
      border-radius: 999px;
      background: rgba(24, 48, 79, 0.14);
      color: #18304f;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }

    .segmento-rdo .segment-pill {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
    }

    .empty,
    .error {
      padding: 26px 20px;
      text-align: center;
      font-size: 20px;
      background: #fff;
      color: var(--text-soft);
    }

    .error {
      color: #b42318;
      background: #fff4f4;
    }

    .footer-note {
      margin-top: 12px;
      padding: 0 8px;
      color: #798393;
      font-size: 12px;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(9, 18, 32, 0.52);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 50;
    }

    .modal-backdrop.is-open {
      display: flex;
    }

    .modal-card {
      width: min(100%, 460px);
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 24px 60px rgba(9, 18, 32, 0.22);
      overflow: hidden;
    }

    .modal-head {
      padding: 18px 22px 12px;
      border-bottom: 1px solid #e5eaf2;
    }

    .modal-head h3 {
      margin: 0;
      font-size: 20px;
      color: var(--blue-strong);
    }

    .modal-body {
      padding: 18px 22px;
    }

    .modal-help {
      margin: 0 0 12px;
      color: #617086;
      font-size: 14px;
    }

    .modal-input {
      width: 100%;
      border: 1px solid #cfd7e5;
      border-radius: 12px;
      padding: 12px 14px;
      font-size: 16px;
      outline: none;
    }

    .modal-input:focus {
      border-color: var(--blue-brand);
      box-shadow: 0 0 0 3px rgba(47, 115, 187, 0.14);
    }

    .modal-error {
      margin-top: 10px;
      color: #b42318;
      font-size: 13px;
      min-height: 18px;
    }

    .modal-foot {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 0 22px 20px;
    }

    .btn-modal {
      border: 0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
    }

    .btn-modal-secondary {
      background: #e8eef6;
      color: #28405f;
    }

    .btn-modal-danger {
      background: #fff1f1;
      color: #b42318;
    }

    .btn-modal-primary {
      background: var(--blue-brand);
      color: #fff;
    }

    @media (max-width: 980px) {
      .content-layout {
        grid-template-columns: 1fr;
      }

      .brand,
      .lunch {
        position: static;
      }

      .header {
        display: flex;
        flex-direction: column;
        gap: 16px;
        align-items: center;
      }

      .brand {
        align-self: flex-start;
      }

      .lunch {
        width: 100%;
        max-width: 320px;
        border-radius: 28px;
      }

      .hero-title {
        width: 100%;
        padding: 18px 20px;
        font-size: 24px;
      }

      .hero-date {
        font-size: 22px;
      }

      thead {
        display: none;
      }

      table,
      tbody,
      tr,
      td {
        display: block;
        width: 100%;
      }

      tbody tr {
        margin-bottom: 10px;
      }

      tbody td {
        border-top: none;
        padding: 10px 14px;
      }

      tbody td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 4px;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        opacity: 0.75;
      }

      .col-horario,
      .col-actividad,
      .col-lugar,
      .col-responsable,
      .col-empresa {
        width: 100%;
      }

      .table-scroll {
        max-height: none;
        overflow: visible;
      }

      .legend-panel {
        padding: 14px;
      }
    }

    @media print {
      body {
        background: #fff;
      }

      .page {
        max-width: none;
        padding: 0;
      }

      .board {
        border: none;
        box-shadow: none;
      }

      .table-scroll {
        max-height: none;
        overflow: visible;
      }
    }
  </style>
</head>
<body>
  <div class="top-stripe"></div>
  <div class="page">
    <section class="board">
      <header class="header">
        <div class="brand">
          <img src="<?= echEsc(APP_LOGO) ?>" alt="Logo <?= echEsc(APP_NAME) ?>">
          <div class="brand-copy">
            <div>Centro de Excelencia</div>
            <div>Operacional</div>
          </div>
        </div>

        <div class="hero">
          <div class="hero-title">En el CEO Hoy</div>
          <div class="hero-date"><?= echEsc($todayLabel) ?></div>
        </div>

        <div class="lunch">Horario de colación CEO<br>13:00 - 14:00</div>
      </header>

      <div class="content-layout">
        <aside class="legend-panel" aria-label="Referencias de colores">
          <h2 class="legend-title">Referencias</h2>
          <div class="legend-list">
            <div class="legend-item"><span class="legend-swatch legend-swatch-habilitaciones"></span><span>Habilitaciones</span></div>
            <div class="legend-item"><span class="legend-swatch legend-swatch-capacitaciones"></span><span>Capacitaciones</span></div>
            <div class="legend-item"><span class="legend-swatch legend-swatch-visitas"></span><span>Visitas</span></div>
            <div class="legend-item"><span class="legend-swatch legend-swatch-rdo"></span><span>RDO</span></div>
            <div class="legend-item"><span class="legend-swatch legend-swatch-otras"></span><span>Otras actividades</span></div>
          </div>
        </aside>

        <div class="table-shell">
          <?php if ($error !== ''): ?>
            <div class="error"><?= echEsc($error) ?></div>
          <?php elseif (empty($activities)): ?>
            <div class="empty">No hay actividades autorizadas para hoy.</div>
          <?php else: ?>
            <div class="table-scroll" id="tablaActividadesScroll">
              <table>
                <thead>
                  <tr>
                    <th>Horario</th>
                    <th>Actividad</th>
                    <th>Lugar</th>
                    <th>Responsable de línea</th>
                    <th>Empresa</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activities as $activity): ?>
                    <tr class="segmento-<?= echEsc($activity['segmento']) ?>">
                      <td class="col-horario" data-label="Horario"><?= echEsc($activity['horario']) ?></td>
                      <td class="col-actividad" data-label="Actividad">
                        <?= echEsc($activity['actividad']) ?>
                        <span class="segment-pill"><?= echEsc($activity['segmento_label']) ?></span>
                      </td>
                      <td class="col-lugar" data-label="Lugar"><?= echEsc($activity['lugar']) ?></td>
                      <td class="col-responsable" data-label="Responsable de línea">
                        <div class="responsable-wrap">
                          <div class="responsable-main">
                            <span class="responsable-text" data-nsolicitud="<?= (int)$activity['nsolicitud'] ?>" data-base-responsable="<?= echEsc($activity['responsable_base']) ?>" data-current-responsable="<?= echEsc($activity['responsable']) ?>"><?= echEsc($activity['responsable']) ?></span>
                            <?php if ($activity['responsable_is_override']): ?>
                              <div class="responsable-badge">Asignado hoy</div>
                            <?php endif; ?>
                          </div>
                          <button
                            type="button"
                            class="responsable-action js-edit-responsable"
                            data-nsolicitud="<?= (int)$activity['nsolicitud'] ?>"
                            data-current-responsable="<?= echEsc($activity['responsable_override'] !== '' ? $activity['responsable_override'] : $activity['responsable']) ?>"
                            data-base-responsable="<?= echEsc($activity['responsable_base']) ?>"
                            title="Asignar responsable del día"
                            aria-label="Asignar responsable del día"
                          >✎</button>
                        </div>
                      </td>
                      <td class="col-empresa" data-label="Empresa"><?= echEsc($activity['empresa']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer-note">
        Orden cronológico según horario autorizado del día actual. Fuente: <code>ceo_solicitudes</code>, estado <code>A</code>.
      </div>
    </section>
  </div>
  <div class="modal-backdrop" id="responsableModal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="responsableModalTitle">
      <div class="modal-head">
        <h3 id="responsableModalTitle">Asignar responsable del día</h3>
      </div>
      <div class="modal-body">
        <p class="modal-help">Escriba un nombre libre para esta actividad de hoy. Esta asignación no modifica la solicitud original.</p>
        <input type="text" id="responsableModalInput" class="modal-input" maxlength="160" placeholder="Nombre del responsable">
        <div class="modal-error" id="responsableModalError"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn-modal btn-modal-secondary" id="responsableModalCancel">Cancelar</button>
        <button type="button" class="btn-modal btn-modal-danger" id="responsableModalClear">Limpiar asignación</button>
        <button type="button" class="btn-modal btn-modal-primary" id="responsableModalSave">Guardar</button>
      </div>
    </div>
  </div>
  <script>
    (function () {
      const keepaliveUrl = '<?= echEsc(APP_BASE) ?>/public/ajax_keepalive.php';
      const keepaliveIntervalMs = 5 * 60 * 1000;
      const reloadIntervalMs = 5 * 60 * 1000;
      const responsableUrl = '<?= echEsc(APP_BASE) ?>/public/ajax_en_el_ceo_hoy_responsable.php';
      const csrfToken = '<?= echEsc($csrfToken) ?>';
      const today = '<?= echEsc($today) ?>';
      const modal = document.getElementById('responsableModal');
      const modalInput = document.getElementById('responsableModalInput');
      const modalError = document.getElementById('responsableModalError');
      const btnCancel = document.getElementById('responsableModalCancel');
      const btnClear = document.getElementById('responsableModalClear');
      const btnSave = document.getElementById('responsableModalSave');
      let currentSolicitud = 0;
      let currentBaseResponsable = 'No aplica';

      function closeModal() {
        if (!modal) {
          return;
        }
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (modalError) {
          modalError.textContent = '';
        }
        currentSolicitud = 0;
      }

      function openModal(button) {
        if (!modal || !modalInput) {
          return;
        }
        currentSolicitud = Number(button.dataset.nsolicitud || '0');
        currentBaseResponsable = button.dataset.baseResponsable || 'No aplica';
        modalInput.value = button.dataset.currentResponsable || '';
        if (modalError) {
          modalError.textContent = '';
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        window.setTimeout(function () {
          modalInput.focus();
          modalInput.select();
        }, 0);
      }

      function setResponsableInRow(nsolicitud, responsable, isOverride) {
        const text = document.querySelector('.responsable-text[data-nsolicitud="' + nsolicitud + '"]');
        const button = document.querySelector('.js-edit-responsable[data-nsolicitud="' + nsolicitud + '"]');
        if (!text || !button) {
          return;
        }

        text.textContent = responsable;
        text.dataset.currentResponsable = responsable;
        button.dataset.currentResponsable = isOverride ? responsable : '';

        const main = text.closest('.responsable-main');
        if (!main) {
          return;
        }

        const existingBadge = main.querySelector('.responsable-badge');
        if (isOverride) {
          if (!existingBadge) {
            const badge = document.createElement('div');
            badge.className = 'responsable-badge';
            badge.textContent = 'Asignado hoy';
            main.appendChild(badge);
          }
        } else if (existingBadge) {
          existingBadge.remove();
        }
      }

      async function postResponsable(action, responsableNombre) {
        const body = new URLSearchParams();
        body.set('csrf', csrfToken);
        body.set('accion', action);
        body.set('fecha', today);
        body.set('nsolicitud', String(currentSolicitud));
        if (action === 'guardar') {
          body.set('responsable_nombre', responsableNombre);
        }

        const response = await fetch(responsableUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
          cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.error || 'No fue posible guardar la asignación.');
        }
        return data;
      }

      document.querySelectorAll('.js-edit-responsable').forEach(function (button) {
        button.addEventListener('click', function () {
          openModal(button);
        });
      });

      if (btnCancel) {
        btnCancel.addEventListener('click', closeModal);
      }
      if (modal) {
        modal.addEventListener('click', function (event) {
          if (event.target === modal) {
            closeModal();
          }
        });
      }
      if (modalInput) {
        modalInput.addEventListener('keydown', function (event) {
          if (event.key === 'Escape') {
            closeModal();
          }
          if (event.key === 'Enter') {
            event.preventDefault();
            if (btnSave) {
              btnSave.click();
            }
          }
        });
      }
      if (btnSave) {
        btnSave.addEventListener('click', async function () {
          const value = modalInput ? modalInput.value.trim() : '';
          if (value === '') {
            if (modalError) {
              modalError.textContent = 'Debe ingresar un nombre o usar "Limpiar asignación".';
            }
            return;
          }

          if (modalError) {
            modalError.textContent = '';
          }
          try {
            const data = await postResponsable('guardar', value);
            setResponsableInRow(currentSolicitud, data.responsable || value, true);
            closeModal();
          } catch (error) {
            if (modalError) {
              modalError.textContent = error.message || 'No fue posible guardar la asignación.';
            }
          }
        });
      }
      if (btnClear) {
        btnClear.addEventListener('click', async function () {
          try {
            await postResponsable('limpiar', '');
            setResponsableInRow(currentSolicitud, currentBaseResponsable, false);
            closeModal();
          } catch (error) {
            if (modalError) {
              modalError.textContent = error.message || 'No fue posible limpiar la asignación.';
            }
          }
        });
      }

      window.setInterval(function () {
        fetch(keepaliveUrl, { cache: 'no-store' }).catch(function () {});
      }, keepaliveIntervalMs);

      window.setInterval(function () {
        window.location.reload();
      }, reloadIntervalMs);

      const container = document.getElementById('tablaActividadesScroll');
      if (!container || window.matchMedia('(max-width: 980px)').matches) {
        return;
      }

      const rows = Array.from(container.querySelectorAll('tbody tr'));
      if (rows.length === 0 || container.scrollHeight <= container.clientHeight) {
        return;
      }

      const maxScrollTop = Math.max(container.scrollHeight - container.clientHeight, 0);
      const positions = [];
      for (const row of rows) {
        const top = Math.min(row.offsetTop, maxScrollTop);
        if (positions.length === 0 || positions[positions.length - 1] !== top) {
          positions.push(top);
        }
      }

      if (positions.length <= 1) {
        return;
      }

      let index = 0;

      window.setInterval(function () {
        if (Math.abs(container.scrollTop - maxScrollTop) < 4) {
          index = 0;
          container.scrollTo({ top: 0, behavior: 'smooth' });
          return;
        }

        index += 1;
        if (index >= positions.length) {
          index = 0;
        }

        container.scrollTo({ top: positions[index], behavior: 'smooth' });
      }, 10000);
    }());
  </script>
</body>
</html>
