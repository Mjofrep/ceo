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
    $value = trim($value);
    $value = strtr($value, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ñ' => 'N',
    ]);
    return strtoupper($value);
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
        'validacion_contrato' => 'Validación de Contrato',
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

function echNormalizeDisplayCompany(string $value): string
{
    return echNormalizeText($value) === 'ENEL X' ? 'E COMERCIAL' : $value;
}

function echNormalizeDisplayPlace(string $value): string
{
    $trimmed = trim($value);
    if (stripos($trimmed, 'Casa Enel X') === 0) {
        return 'Casa E Comercial' . substr($trimmed, strlen('Casa Enel X'));
    }

    return $value;
}

function echAdjustActivitySegment(string $activity, string $segment): string
{
    return echNormalizeText($activity) === 'VALIDACION DE CONTRATO' ? 'validacion_contrato' : $segment;
}

$today = date('Y-m-d');
$hasTipoVisita = false;
$rows = [];
$error = '';

try {
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
            ps.rut AS participante_rut,
            COALESCE(ps.nombre, '') AS participante_nombre,
            COALESCE(ps.apellidop, '') AS participante_apellidop,
            COALESCE(ps.apellidom, '') AS participante_apellidom,
            COALESCE(ps.cargo, '') AS participante_cargo
        FROM ceo_solicitudes s
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_patios p ON p.id = s.patio
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        WHERE s.fecha = :fecha
          AND s.estado = 'A'
        ORDER BY s.horainicio ASC, s.horatermino ASC, s.nsolicitud ASC, ps.apellidop ASC, ps.apellidom ASC, ps.nombre ASC
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
    $participanteNombre = trim((string)($row['participante_nombre'] ?? ''));
    $participanteApellidoP = trim((string)($row['participante_apellidop'] ?? ''));
    $participanteApellidoM = trim((string)($row['participante_apellidom'] ?? ''));
    $participante = trim($participanteNombre . ' ' . $participanteApellidoP . ' ' . $participanteApellidoM);
    $participanteCargo = trim((string)($row['participante_cargo'] ?? ''));
    $activityName = echBuildActivityName($row, $segment);
    $segment = echAdjustActivitySegment($activityName, $segment);
    $activities[] = [
        'nsolicitud' => (int)($row['nsolicitud'] ?? 0),
        'horario' => echFormatHour((string)($row['horainicio'] ?? '')) . ' - ' . echFormatHour((string)($row['horatermino'] ?? '')),
        'actividad' => $activityName,
        'segmento' => $segment,
        'segmento_label' => echSegmentLabel($segment),
        'lugar' => trim((string)($row['patio'] ?? '')) !== '' ? echNormalizeDisplayPlace((string)$row['patio']) : 'Por definir',
        'participante' => $participante !== '' ? $participante : 'Sin nombre',
        'cargo' => $participanteCargo !== '' ? $participanteCargo : 'Sin cargo',
        'empresa' => trim((string)($row['empresa'] ?? '')) !== '' ? echNormalizeDisplayCompany((string)$row['empresa']) : 'Sin empresa',
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
      --blue-chile: #0039a6;
      --red-chile: #d52b1e;
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
      position: relative;
      overflow-x: hidden;
    }

    .fiestas-frame {
      position: fixed;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }

    .fiestas-frame::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: url('<?= echEsc(APP_BASE) ?>/docs/imagen.png');
      background-repeat: no-repeat;
      background-position: center;
      background-size: cover;
      opacity: 0.2;
    }

    .fiesta-item {
      position: absolute;
      opacity: 0.98;
      z-index: 1;
    }

    .fiesta-item svg {
      display: block;
      width: 100%;
      height: auto;
      filter: drop-shadow(0 6px 12px rgba(24, 48, 79, 0.16));
    }

    .top-stripe {
      height: 10px;
      background: linear-gradient(90deg, #25539a 0 66%, #e71f93 66% 85%, #f26722 85% 100%);
    }

    .page {
      max-width: 1160px;
      margin: 0 auto;
      padding: 18px 18px 22px;
      position: relative;
      z-index: 1;
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
      flex-direction: column;
      align-items: flex-start;
      gap: 10px;
      max-width: 280px;
    }

    .brand-main {
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

    .flag-wave-wrap {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px 8px 10px;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.96) 0%, rgba(242, 246, 255, 0.95) 100%);
      border: 1px solid rgba(0, 57, 166, 0.2);
      border-radius: 999px;
      box-shadow: 0 10px 24px rgba(24, 48, 79, 0.12);
    }

    .flag-wave {
      position: relative;
      width: 112px;
      height: 64px;
      transform-origin: left center;
      animation: flag-wave 2.8s ease-in-out infinite;
      filter: drop-shadow(0 10px 14px rgba(24, 48, 79, 0.22));
    }

    .flag-wave::before {
      content: '';
      position: absolute;
      left: -7px;
      top: -2px;
      width: 5px;
      height: 72px;
      border-radius: 999px;
      background: linear-gradient(180deg, #c8d0da 0%, #8995a3 100%);
      box-shadow: 0 0 0 1px rgba(24, 48, 79, 0.08);
    }

    .flag-wave svg {
      display: block;
      width: 100%;
      height: 100%;
    }

    .flag-wave-label {
      color: var(--blue-strong);
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.9px;
      text-transform: uppercase;
      white-space: nowrap;
    }

    @keyframes flag-wave {
      0% { transform: perspective(220px) rotateY(0deg) rotate(-1deg); }
      25% { transform: perspective(220px) rotateY(-12deg) rotate(1deg) translateY(-1px); }
      50% { transform: perspective(220px) rotateY(6deg) rotate(-1deg) translateY(1px); }
      75% { transform: perspective(220px) rotateY(-10deg) rotate(1deg); }
      100% { transform: perspective(220px) rotateY(0deg) rotate(-1deg); }
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

    .feedback-qr {
      margin-top: 18px;
      padding-top: 16px;
      border-top: 1px solid rgba(24, 48, 79, 0.12);
      text-align: center;
    }

    .feedback-qr-title {
      margin: 0 0 10px;
      font-size: 16px;
      font-weight: 800;
      color: var(--blue-strong);
    }

    .feedback-qr-caption {
      margin: 10px 0 0;
      font-size: 14px;
      font-weight: 700;
      color: var(--blue-strong);
      text-align: center;
    }

    .feedback-qr img {
      display: block;
      width: 100%;
      max-width: 150px;
      height: auto;
      margin: 0 auto;
      border-radius: 10px;
      background: #fff;
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
    .legend-swatch-validacion-contrato { background: #f7ef9f; }
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
    tbody tr.segmento-validacion_contrato td { background: #f7ef9f; }
    tbody tr.segmento-otras td { background: #c6d2e2; }

    .col-horario {
      width: 15%;
      color: #005ea6;
      font-weight: 800;
      white-space: nowrap;
    }

    .segmento-rdo .col-horario { color: #d1f1ff; }

    .col-actividad {
      width: 20%;
      font-weight: 700;
    }

    .col-lugar { width: 15%; }
    .col-participante { width: 20%; font-weight: 700; }
    .col-cargo { width: 16%; }
    .col-empresa { width: 14%; }

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
      .fiestas-frame::before {
        background-size: contain;
        opacity: 0.16;
      }

      .fiesta-top-left { top: 8px; left: 8px; }
      .fiesta-top-right { top: 8px; right: 8px; }
      .fiesta-bottom-left { bottom: 8px; left: 8px; }
      .fiesta-bottom-right { bottom: 8px; right: 8px; }

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
        position: static;
        width: 100%;
        align-items: center;
      }

      .brand-main {
        max-width: none;
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

      .flag-wave-wrap {
        align-self: center;
      }

      .flag-wave {
        width: 96px;
        height: 56px;
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
      .col-participante,
      .col-cargo,
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
      .fiestas-frame {
        display: none;
      }

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
  <div class="fiestas-frame" aria-hidden="true">
  </div>
  <div class="top-stripe"></div>
  <div class="page">
    <section class="board">
      <header class="header">
        <div class="brand">
          <div class="brand-main">
            <img src="<?= echEsc(APP_LOGO) ?>" alt="Logo <?= echEsc(APP_NAME) ?>">
            <div class="brand-copy">
              <div>Centro de Excelencia</div>
              <div>Operacional</div>
            </div>
          </div>
          <div class="flag-wave-wrap" aria-label="Bandera de Chile decorativa">
            <div class="flag-wave">
              <svg viewBox="0 0 82 48" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
                <defs>
                  <clipPath id="flagWaveClip">
                    <path d="M3 4C16 0 27 9 40 5s25 4 39 0v34c-13 4-26-5-39-1s-24-5-37-1V4z"/>
                  </clipPath>
                </defs>
                <g clip-path="url(#flagWaveClip)">
                  <rect x="0" y="0" width="82" height="24" fill="#ffffff"/>
                  <rect x="0" y="24" width="82" height="24" fill="#d52b1e"/>
                  <rect x="0" y="0" width="28" height="24" fill="#0039a6"/>
                  <polygon points="14,5 16.4,11.2 23,11.4 17.8,15.5 19.7,21.8 14,18.2 8.3,21.8 10.2,15.5 5,11.4 11.6,11.2" fill="#ffffff"/>
                </g>
                <path d="M3 4C16 0 27 9 40 5s25 4 39 0" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.4"/>
                <path d="M3 38c13-4 24 5 37 1s26 5 39 1" fill="none" stroke="rgba(24,48,79,0.18)" stroke-width="1.2"/>
              </svg>
            </div>
            <span class="flag-wave-label">Fiestas Patrias</span>
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
            <div class="legend-item"><span class="legend-swatch legend-swatch-validacion-contrato"></span><span>Validación de Contrato</span></div>
            <div class="legend-item"><span class="legend-swatch legend-swatch-otras"></span><span>Otras actividades</span></div>
          </div>
          <div class="feedback-qr">
            <h3 class="feedback-qr-title">Tu Feedback</h3>
            <div class="feedback-qr-caption">Aquí</div>
            <img src="<?= echEsc(APP_BASE) ?>/docs/QR_feedback.jpg" alt="QR Tú Feedback">
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
                    <th>Participante</th>
                    <th>Cargo</th>
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
                      <td class="col-participante" data-label="Participante"><?= echEsc($activity['participante']) ?></td>
                      <td class="col-cargo" data-label="Cargo"><?= echEsc($activity['cargo']) ?></td>
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
        Orden cronológico según horario autorizado del día actual. Fuente: <code>ceo_solicitudes</code> y <code>ceo_participantes_solicitud</code>, solicitudes en estado <code>A</code>.
      </div>
    </section>
  </div>
  <script>
    (function () {
      const keepaliveUrl = '<?= echEsc(APP_BASE) ?>/public/ajax_keepalive.php';
      const keepaliveIntervalMs = 5 * 60 * 1000;
      const reloadIntervalMs = 5 * 60 * 1000;

      window.setInterval(function () {
        fetch(keepaliveUrl, { cache: 'no-store' }).catch(function () {});
      }, keepaliveIntervalMs);

      window.setInterval(function () {
        window.location.reload();
      }, reloadIntervalMs);

      const container = document.getElementById('tablaActividadesScroll');
      if (!container || window.matchMedia('(max-width: 980px)').matches) return;

      const rows = Array.from(container.querySelectorAll('tbody tr'));
      if (rows.length === 0 || container.scrollHeight <= container.clientHeight) return;

      const maxScrollTop = Math.max(container.scrollHeight - container.clientHeight, 0);
      const positions = [];
      for (const row of rows) {
        const top = Math.min(row.offsetTop, maxScrollTop);
        if (positions.length === 0 || positions[positions.length - 1] !== top) positions.push(top);
      }
      if (positions.length <= 1) return;

      let index = 0;
      window.setInterval(function () {
        if (Math.abs(container.scrollTop - maxScrollTop) < 4) {
          index = 0;
          container.scrollTo({ top: 0, behavior: 'smooth' });
          return;
        }
        index = (index + 1) % positions.length;
        container.scrollTo({ top: positions[index], behavior: 'smooth' });
      }, 10000);
    }());
  </script>
</body>
</html>
