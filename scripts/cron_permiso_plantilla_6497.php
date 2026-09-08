<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('America/Santiago');

const CRON_TEMPLATE_NSOLICITUD = 6497;
const CRON_TEMPLATE_PARTICIPANT_COUNT = 13;

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Este script solo se puede ejecutar por CLI.');
}

if (!function_exists('cronLog')) {
    function cronLog(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fwrite(STDOUT, '[' . $timestamp . '] ' . $message . PHP_EOL);
    }
}

if (!function_exists('agregarCorreosMailerUnicos')) {
    function agregarCorreosMailerUnicos(PHPMailer $mail, array $correos, string $modo, array &$registrados): void
    {
        foreach (normalizarCorreosEmpresa($correos) as $correo) {
            $key = mb_strtolower(trim($correo), 'UTF-8');
            if ($key === '' || isset($registrados[$key])) {
                continue;
            }

            if ($modo === 'to') {
                $mail->addAddress($correo);
            } else {
                $mail->addCC($correo);
            }
            $registrados[$key] = true;
        }
    }
}

function cronDb(): PDO
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=8889;dbname=noeticac_ceo;charset=utf8mb4',
            'root',
            'root',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    configurarModoSqlLegacy($pdo);

    return $pdo;
}

function configurarModoSqlLegacy(PDO $pdo): void
{
    try {
        $pdo->exec(
            "SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_IN_DATE', ''), 'NO_ZERO_DATE', '')"
        );
    } catch (Throwable $e) {
        // El esquema actual usa fechas cero en varios flujos legacy; si no se puede ajustar,
        // se sigue con el modo actual y la insercion fallara con un mensaje explicito.
    }
}

function obtenerPlantilla(PDO $pdo, int $nsolicitud): array
{
    $sql = "
        SELECT s.*,
               e.nombre AS empresa,
               e.correo AS empresa_correo,
               u.desc_uo AS unidad_op,
               sv.servicio AS servicio_nombre,
               pa.desc_patios AS patio_nombre,
               h.desc_tipo AS habilitacion_nombre,
               ch.desc_charlas AS charla_nombre,
               rd.reinduccion AS motivoreinduccion_nombre,
               rh.nombre AS resp_hse_nombre,
               ev.nombre AS eval_nombre,
               ev.apellidop AS eval_ap,
               ev.apellidom AS eval_am
          FROM ceo_solicitudes s
          LEFT JOIN ceo_empresas e ON e.id = s.contratista
          LEFT JOIN ceo_uo u ON u.id = s.uo
          LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
          LEFT JOIN ceo_patios pa ON pa.id = s.patio
          LEFT JOIN ceo_habilitaciontipo h ON h.id = s.habilitacionceo
          LEFT JOIN ceo_charlas ch ON ch.id = s.charla
          LEFT JOIN ceo_reinduccion rd ON rd.id = s.motivoreinduccion
          LEFT JOIN ceo_responsablehse rh ON rh.id = s.resphse
          LEFT JOIN ceo_evaluador ev ON ev.id = s.resplinea
         WHERE s.nsolicitud = :n
         LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':n' => $nsolicitud]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('No se encontro la solicitud plantilla ' . $nsolicitud . '.');
    }

    $row['resp_linea_nombre'] = trim((string)($row['eval_nombre'] ?? '') . ' ' . (string)($row['eval_ap'] ?? '') . ' ' . (string)($row['eval_am'] ?? ''));

    return $row;
}

function obtenerParticipantesPlantilla(PDO $pdo, int $nsolicitud): array
{
    $sql = "
        SELECT ps.id_solicitud, ps.rut, ps.nombre, ps.apellidop, ps.apellidom,
               ps.id_cargo, ps.asistio, ps.observacion, ps.autorizado, ps.aprobo,
               ps.wf, ps.fechaasistio,
               c.cargo,
               em.nombre AS empresa
          FROM ceo_participantes_solicitud ps
          LEFT JOIN ceo_cargo_contratistas c ON c.id = ps.id_cargo
          LEFT JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
          LEFT JOIN ceo_empresas em ON em.id = s.contratista
         WHERE ps.id_solicitud = :n
         ORDER BY ps.apellidop, ps.apellidom, ps.nombre, ps.rut
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':n' => $nsolicitud]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 0) {
        throw new RuntimeException(
            'La plantilla ' . $nsolicitud . ' no tiene participantes para copiar.'
        );
    }

    return $rows;
}

function existeDuplicadoHoy(PDO $pdo, array $template, array $participantes, string $fechaObjetivo): ?int
{
    $sql = "
        SELECT nsolicitud
          FROM ceo_solicitudes
         WHERE fecha = :fecha
           AND patio = :patio
           AND contratista = :contratista
           AND proceso = :proceso
           AND habilitacionceo = :habilitacionceo
           AND resphse = :resphse
           AND resplinea = :resplinea
           AND uo = :uo
           AND servicio = :servicio
           AND responsable = :responsable
           AND tipohabilitacion <=> :tipohabilitacion
           AND observacion <=> :observacion
           AND charla = :charla
           AND motivoreinduccion = :motivoreinduccion
           AND numerohallazgo <=> :numerohallazgo
           AND tipo_visita <=> :tipo_visita
           AND horainicio = :horainicio
           AND horatermino = :horatermino
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':fecha' => $fechaObjetivo,
        ':patio' => $template['patio'],
        ':contratista' => $template['contratista'],
        ':proceso' => $template['proceso'],
        ':habilitacionceo' => $template['habilitacionceo'],
        ':resphse' => $template['resphse'],
        ':resplinea' => $template['resplinea'],
        ':uo' => $template['uo'],
        ':servicio' => $template['servicio'],
        ':responsable' => $template['responsable'],
        ':tipohabilitacion' => $template['tipohabilitacion'],
        ':observacion' => $template['observacion'],
        ':charla' => $template['charla'],
        ':motivoreinduccion' => $template['motivoreinduccion'],
        ':numerohallazgo' => $template['numerohallazgo'],
        ':tipo_visita' => $template['tipo_visita'],
        ':horainicio' => $template['horainicio'],
        ':horatermino' => $template['horatermino'],
    ]);

    $rutsPlantilla = array_map(
        static fn(array $row): string => trim((string)$row['rut']),
        $participantes
    );
    sort($rutsPlantilla);

    $stPart = $pdo->prepare('SELECT rut FROM ceo_participantes_solicitud WHERE id_solicitud = :n');
    while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
        $nsolicitud = (int)$row['nsolicitud'];
        $stPart->execute([':n' => $nsolicitud]);
        $ruts = array_map(
            static fn(array $part): string => trim((string)$part['rut']),
            $stPart->fetchAll(PDO::FETCH_ASSOC)
        );
        sort($ruts);

        if ($ruts === $rutsPlantilla) {
            return $nsolicitud;
        }
    }

    return null;
}

function siguienteNumeroSolicitud(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COALESCE(MAX(nsolicitud), 0) + 1 FROM ceo_solicitudes')->fetchColumn();
}

function insertarSolicitud(PDO $pdo, int $nsolicitud, array $template, string $fechaObjetivo): void
{
    $sql = "
        INSERT INTO ceo_solicitudes (
            nsolicitud, solicitante, patio, fecha, estado, contratista, tipohabilitacion,
            resphse, resplinea, proceso, habilitacionceo, fechacreacion, fechacancelacion,
            uo, servicio, responsable, observacion, charla, fechaaprueba, usuarioaprueba,
            fechafinaliza, usuariofinaliza, motivoreinduccion, numerohallazgo, tipo_visita,
            horainicio, horatermino
        ) VALUES (
            :nsolicitud, :solicitante, :patio, :fecha, 'A', :contratista, :tipohabilitacion,
            :resphse, :resplinea, :proceso, :habilitacionceo, :fechacreacion, '0000-00-00',
            :uo, :servicio, :responsable, :observacion, :charla, '0000-00-00', 0,
            '0000-00-00', 0, :motivoreinduccion, :numerohallazgo, :tipo_visita,
            :horainicio, :horatermino
        )
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':nsolicitud' => $nsolicitud,
        ':solicitante' => $template['solicitante'],
        ':patio' => $template['patio'],
        ':fecha' => $fechaObjetivo,
        ':contratista' => $template['contratista'],
        ':tipohabilitacion' => $template['tipohabilitacion'],
        ':resphse' => $template['resphse'],
        ':resplinea' => $template['resplinea'],
        ':proceso' => $template['proceso'],
        ':habilitacionceo' => $template['habilitacionceo'],
        ':fechacreacion' => date('Y-m-d'),
        ':uo' => $template['uo'],
        ':servicio' => $template['servicio'],
        ':responsable' => $template['responsable'],
        ':observacion' => $template['observacion'],
        ':charla' => $template['charla'],
        ':motivoreinduccion' => $template['motivoreinduccion'],
        ':numerohallazgo' => $template['numerohallazgo'],
        ':tipo_visita' => $template['tipo_visita'],
        ':horainicio' => $template['horainicio'],
        ':horatermino' => $template['horatermino'],
    ]);
}

function insertarParticipantes(PDO $pdo, int $nsolicitud, array $participantes): void
{
    $sql = "
        INSERT INTO ceo_participantes_solicitud (
            id_solicitud, rut, nombre, apellidom, apellidop, id_cargo,
            asistio, observacion, autorizado, aprobo, wf, fechaasistio
        ) VALUES (
            :id_solicitud, :rut, :nombre, :apellidom, :apellidop, :id_cargo,
            '', '', :autorizado, NULL, :wf, NULL
        )
    ";

    $st = $pdo->prepare($sql);
    foreach ($participantes as $participante) {
        $st->execute([
            ':id_solicitud' => $nsolicitud,
            ':rut' => $participante['rut'],
            ':nombre' => $participante['nombre'],
            ':apellidom' => $participante['apellidom'],
            ':apellidop' => $participante['apellidop'],
            ':id_cargo' => $participante['id_cargo'],
            ':autorizado' => $participante['autorizado'],
            ':wf' => $participante['wf'],
        ]);
    }
}

function actualizarCalendario(PDO $pdo, int $nsolicitud, array $template, string $fechaObjetivo): int
{
    $sql = "
        UPDATE ceo_calendario
           SET estado = 'OCUPADO',
               nsolicitud = :nsolicitud
         WHERE id_patio = :patio
           AND fecha = :fecha
           AND horainicio BETWEEN :horainicio AND :horatermino
           AND estado = 'DISPONIBLE'
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
        ':nsolicitud' => $nsolicitud,
        ':patio' => $template['patio'],
        ':fecha' => $fechaObjetivo,
        ':horainicio' => $template['horainicio'],
        ':horatermino' => $template['horatermino'],
    ]);

    return $st->rowCount();
}

function obtenerLogoBase64(): string
{
    if (!defined('APP_LOGO') || APP_LOGO === '') {
        return '';
    }

    $logoPath = app_path_to_filesystem(APP_LOGO);
    if (!is_file($logoPath)) {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath));
}

function construirHtmlPermiso(int $nsolicitud, array $solicitud, array $participantes): string
{
    $logoBase64 = obtenerLogoBase64();
    $fecha = (string)($solicitud['fecha'] ?? '');
    $hini = substr((string)($solicitud['horainicio'] ?? ''), 0, 5);
    $hfin = substr((string)($solicitud['horatermino'] ?? ''), 0, 5);
    $empresa = (string)($solicitud['empresa'] ?? '');
    $uo = (string)($solicitud['unidad_op'] ?? '');
    $servicio = (string)($solicitud['servicio_nombre'] ?? '');
    $patio = (string)($solicitud['patio_nombre'] ?? '');
    $habMotivo = (string)($solicitud['habilitacion_nombre'] ?? '');
    $charla = (string)($solicitud['charla_nombre'] ?? '');
    $motivoReinduccion = (string)($solicitud['motivoreinduccion_nombre'] ?? '');
    $tipoVisita = (string)($solicitud['tipo_visita'] ?? '');
    $respHSE = (string)($solicitud['resp_hse_nombre'] ?? '');
    $respLinea = trim((string)($solicitud['resp_linea_nombre'] ?? ''));
    $cantidadPersonas = count(array_filter($participantes, static fn(array $p): bool => (int)($p['autorizado'] ?? 0) === 1));

    ob_start();
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 18px 18px 18px 18px; }
body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #333; }
.header { background: #e9edf2; padding:10px; display:flex; align-items:center; justify-content:space-between; }
.header h2 { margin:0; font-weight:600; color:#3a4b6a; }
.small { color:#6c757d; font-size: 10px; }
.tbl { width:100%; border-collapse:collapse; margin-top:8px; }
.tbl th, .tbl td { border:1px solid #bfc6d4; padding:6px 8px; }
.tbl th { background:#f0f3f8; text-align:left; }
.meta td { border:none; padding:3px 6px; }
.box { border:none solid #bfc6d4; padding:8px; }
</style>
</head>
<body>

<div class="header">
  <div>
    <h2>Autorizada la Solicitud N° <?= htmlspecialchars((string)$nsolicitud, ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="small">[Cada persona tiene que hacer su Autodiagnóstico en https://enel.autodiagnostico.cl/ para ingresar al CEO]</div>
  </div>
  <div>
    <?php if ($logoBase64 !== ''): ?>
      <img src="<?= $logoBase64 ?>" alt="Logo" style="height:40px;">
    <?php endif; ?>
  </div>
</div>

<table class="tbl meta">
  <tr>
    <td><b>Fecha</b> <span class="box"><?= htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Inicio</b> <span class="box"><?= htmlspecialchars($hini, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Término</b> <span class="box"><?= htmlspecialchars($hfin, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Contratista</b> <span class="box"><?= htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Capacitación</b> <span class="box"><?= htmlspecialchars($charla, ENT_QUOTES, 'UTF-8') ?></span></td>
  </tr>
  <tr>
    <td colspan="2"><b>Responsable HSE</b> <span class="box"><?= htmlspecialchars($respHSE, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td colspan="2"><b>Unidad Operativa</b> <span class="box"><?= htmlspecialchars($uo, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Cant. Personas</b> <span class="box"><?= $cantidadPersonas ?></span></td>
  </tr>
  <tr>
    <td colspan="2"><b>Responsable Línea</b> <span class="box"><?= htmlspecialchars($respLinea, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td colspan="2"><b>Servicio</b> <span class="box"><?= htmlspecialchars($servicio, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td><b>Patio</b> <span class="box"><?= htmlspecialchars($patio, ENT_QUOTES, 'UTF-8') ?></span></td>
  </tr>
  <tr>
    <td><b>Habilitación/Motivo</b> <span class="box"><?= htmlspecialchars($habMotivo !== '' ? $habMotivo : 'Habilitación', ENT_QUOTES, 'UTF-8') ?></span></td>
    <td colspan="2"><b>Motivo Reinducción</b> <span class="box"><?= htmlspecialchars($motivoReinduccion, ENT_QUOTES, 'UTF-8') ?></span></td>
    <td colspan="2"><b>Tipo de visita</b> <span class="box"><?= htmlspecialchars($tipoVisita, ENT_QUOTES, 'UTF-8') ?></span></td>
  </tr>
</table>

<table class="tbl" style="margin-top:10px;">
  <thead>
    <tr>
      <th style="width:18%;">RUT</th>
      <th style="width:35%;">Nombre</th>
      <th style="width:18%;">Cargo</th>
      <th style="width:14%;">Wise Follow</th>
      <th style="width:15%;">Firma</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($participantes as $participante): ?>
      <?php if ((int)($participante['autorizado'] ?? 0) !== 1) { continue; } ?>
      <tr>
        <td><?= htmlspecialchars(trim((string)$participante['rut']), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars(trim((string)$participante['nombre'] . ' ' . (string)$participante['apellidop'] . ' ' . (string)$participante['apellidom']), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)($participante['cargo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td>SI</td>
        <td>&nbsp;</td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
<?php
    return (string)ob_get_clean();
}

function generarPdfPermiso(int $nsolicitud, array $solicitud, array $participantes): array
{
    $html = construirHtmlPermiso($nsolicitud, $solicitud, $participantes);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $baseDir = __DIR__ . '/../storage/permisos/' . date('Y');
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No se pudo crear el directorio de permisos: ' . $baseDir);
    }

    $nombreArchivo = 'Permiso_Solicitud_' . $nsolicitud . '.pdf';
    $rutaFinal = $baseDir . DIRECTORY_SEPARATOR . $nombreArchivo;
    file_put_contents($rutaFinal, $dompdf->output());

    return [
        'nombre_archivo' => $nombreArchivo,
        'ruta_archivo' => $rutaFinal,
    ];
}

function guardarDocumentoPermiso(PDO $pdo, int $nsolicitud, array $template, array $pdfInfo): void
{
    $st = $pdo->prepare("SELECT id FROM ceo_habilitacion WHERE nsolicitud = :n LIMIT 1");
    $st->execute([':n' => $nsolicitud]);
    $idHabilitacion = $st->fetchColumn();

    $sql = "
        INSERT INTO ceo_permiso_documento (
            nsolicitud, id_habilitacion, nombre_archivo, ruta_archivo, tipo,
            version, creado_por, fecha_creacion, estado
        ) VALUES (
            :nsolicitud, :id_habilitacion, :nombre_archivo, :ruta_archivo, 'PERMISO',
            1, :creado_por, NOW(), 'VIGENTE'
        )
    ";

    $docCreadoPor = isset($template['solicitante']) ? (int)$template['solicitante'] : 0;
    $ins = $pdo->prepare($sql);
    $ins->execute([
        ':nsolicitud' => $nsolicitud,
        ':id_habilitacion' => $idHabilitacion !== false ? (int)$idHabilitacion : null,
        ':nombre_archivo' => $pdfInfo['nombre_archivo'],
        ':ruta_archivo' => $pdfInfo['ruta_archivo'],
        ':creado_por' => $docCreadoPor,
    ]);
}

function enviarCorreoFinal(PDO $pdo, int $nsolicitud, array $solicitud, array $participantes, array $pdfInfo): void
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'mail.noetica.cl';
    $mail->SMTPAuth = true;
    $mail->Username = 'ceo@noetica.cl';
    $mail->Password = 'Neotica_1964$';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->setFrom('ceo@noetica.cl', 'Autorización CEO');

    $correosRegistrados = [];
    $destinatarios = [
        ['correo' => 'patricio.acuna@enel.com', 'nombre' => 'Patricio Acuña', 'modo' => 'to'],
        ['correo' => 'yanett.henriquez@enel.com', 'nombre' => 'Yanett Henriquez', 'modo' => 'to'],
        ['correo' => 'leonel.morales2.external@enel.com', 'nombre' => 'Leonel Morales', 'modo' => 'to'],
        ['correo' => 'marcelo.jofre.external@enel.com', 'nombre' => '', 'modo' => 'cc'],
        ['correo' => 'angela.herrera.external@enel.com', 'nombre' => '', 'modo' => 'cc'],
        ['correo' => 'melanie.lopez.external@enel.com', 'nombre' => '', 'modo' => 'cc'],
    ];

    foreach ($destinatarios as $destinatario) {
        $correo = $destinatario['correo'];
        $nombre = $destinatario['nombre'];
        $key = mb_strtolower(trim($correo), 'UTF-8');
        if ($destinatario['modo'] === 'to') {
            $mail->addAddress($correo, $nombre);
        } else {
            $mail->addCC($correo, $nombre);
        }
        $correosRegistrados[$key] = true;
    }

    $stSolicitante = $pdo->prepare("SELECT u.correo, u.nombres FROM ceo_solicitudes s JOIN ceo_usuarios u ON u.id = s.solicitante WHERE s.nsolicitud = :n LIMIT 1");
    $stSolicitante->execute([':n' => $nsolicitud]);
    $sol = $stSolicitante->fetch(PDO::FETCH_ASSOC);
    if ($sol && !empty($sol['correo']) && filter_var($sol['correo'], FILTER_VALIDATE_EMAIL)) {
        $mail->addCC((string)$sol['correo'], (string)($sol['nombres'] ?? ''));
        $correosRegistrados[mb_strtolower(trim((string)$sol['correo']), 'UTF-8')] = true;
    }

    $esSolicitudHabilitacion = in_array(
        mb_strtolower(trim((string)($solicitud['habilitacion_nombre'] ?? '')), 'UTF-8'),
        ['habilitación', 'habilitacion'],
        true
    );
    if ($esSolicitudHabilitacion) {
        $correosEmpresa = obtenerCorreosEmpresa(
            $pdo,
            (int)($solicitud['contratista'] ?? 0),
            (string)($solicitud['empresa_correo'] ?? '')
        );
        agregarCorreosMailerUnicos($mail, $correosEmpresa, 'cc', $correosRegistrados);
    }

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->Subject = 'Listado de Participantes - Solicitud N° ' . $nsolicitud . ' (' . $solicitud['fecha'] . ')';

    $tabla = '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-size:13px;">';
    $tabla .= '<tr style="background:#f0f3f8;"><th>RUT</th><th>Nombre</th><th>Cargo</th><th>Empresa</th></tr>';
    foreach ($participantes as $participante) {
        if ((int)($participante['autorizado'] ?? 0) !== 1) {
            continue;
        }

        $rut = htmlspecialchars(trim((string)($participante['rut'] ?? '')), ENT_QUOTES, 'UTF-8');
        $nombre = htmlspecialchars(trim((string)($participante['nombre'] ?? '') . ' ' . (string)($participante['apellidop'] ?? '') . ' ' . (string)($participante['apellidom'] ?? '')), ENT_QUOTES, 'UTF-8');
        $cargo = htmlspecialchars((string)($participante['cargo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $empresa = htmlspecialchars((string)($participante['empresa'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tabla .= '<tr><td>' . $rut . '</td><td>' . $nombre . '</td><td>' . $cargo . '</td><td>' . $empresa . '</td></tr>';
    }
    $tabla .= '</table>';

    $mail->Body = '<p>Estimados,</p>'
        . '<p>Se informa el proceso de habilitación correspondiente al <b>' . htmlspecialchars((string)$solicitud['fecha'], ENT_QUOTES, 'UTF-8') . '</b> para la <b>Solicitud N° ' . $nsolicitud . '</b>.</p>'
        . '<p>Participantes:</p>'
        . $tabla
        . '<p>Se adjunta el documento PDF de autorización correspondiente.</p>'
        . '<br><p>Saludos,<br>Sistema CEO</p>';
    $mail->AltBody = 'Listado de participantes para la solicitud N° ' . $nsolicitud . ' (' . $solicitud['fecha'] . ')';
    $mail->addAttachment($pdfInfo['ruta_archivo'], $pdfInfo['nombre_archivo']);
    $mail->send();
}

try {
    $pdo = cronDb();
    $fechaObjetivo = date('Y-m-d');

    $template = obtenerPlantilla($pdo, CRON_TEMPLATE_NSOLICITUD);
    $participantes = obtenerParticipantesPlantilla($pdo, CRON_TEMPLATE_NSOLICITUD);

    $duplicado = existeDuplicadoHoy($pdo, $template, $participantes, $fechaObjetivo);
    if ($duplicado !== null) {
        cronLog('Ya existe una solicitud equivalente para hoy: N° ' . $duplicado . '. No se crea un duplicado.');
        exit(0);
    }

    $nuevoNumero = siguienteNumeroSolicitud($pdo);
    $template['fecha'] = $fechaObjetivo;

    $pdo->beginTransaction();
    insertarSolicitud($pdo, $nuevoNumero, $template, $fechaObjetivo);
    insertarParticipantes($pdo, $nuevoNumero, $participantes);
    $bloquesActualizados = actualizarCalendario($pdo, $nuevoNumero, $template, $fechaObjetivo);

    $pdfInfo = generarPdfPermiso($nuevoNumero, $template, $participantes);
    guardarDocumentoPermiso($pdo, $nuevoNumero, $template, $pdfInfo);
    $pdo->commit();

    if ($bloquesActualizados === 0) {
        cronLog('Advertencia: no se encontraron bloques DISPONIBLE en calendario para ocupar; la solicitud se creo igual.');
    } else {
        cronLog('Bloques de calendario actualizados: ' . $bloquesActualizados . '.');
    }

    try {
        enviarCorreoFinal($pdo, $nuevoNumero, $template, $participantes, $pdfInfo);
        cronLog('Solicitud N° ' . $nuevoNumero . ' creada, PDF generado y correo final enviado.');
        exit(0);
    } catch (MailerException $e) {
        cronLog('Solicitud N° ' . $nuevoNumero . ' creada y PDF generado, pero fallo el envio del correo: ' . $e->getMessage());
        exit(1);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    cronLog('Error: ' . $e->getMessage());
    exit(1);
}
