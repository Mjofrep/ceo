<?php
// --------------------------------------------------------------
// formacion_autoriza_envio.php - Envio de Autorizacion (PDF adjunto)
// --------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

// libs vía Composer
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$pdo = db();
$idSolicitud = (int)($_GET['id'] ?? 0);
if ($idSolicitud <= 0) {
  echo "<div class='alert alert-danger m-5'>Formacion invalida.</div>";
  exit;
}

if ($idSolicitud > 0) {
    try {
        $upd = $pdo->prepare("
            UPDATE ceo_formacion_solicitudes
               SET estado = 'A'
             WHERE nsolicitud = :id
        ");
        $upd->execute([':id' => $idSolicitud]);

        // Opcional: mensaje o redirección
        // echo "Estado actualizado correctamente";

    } catch (Throwable $e) {
        echo "Error al actualizar estado: " . $e->getMessage();
    }
}
/* ====================== CARGA CABECERA FORMACION ====================== */
$sqlCab = "
  SELECT s.nsolicitud, s.fecha, s.horainicio, s.horatermino,
         e.nombre AS empresa, 
         u.desc_uo AS unidad_op,
         sv.servicio AS servicio,
         pa.desc_patios AS patio,
         h.desc_tipo AS habilitacion,
         r1.nombre AS resp_hse,
         ev.nombre AS eval_nombre, ev.apellidop AS eval_ap, ev.apellidom AS eval_am
    FROM ceo_formacion_solicitudes s
    LEFT JOIN ceo_empresas e       ON e.id = s.contratista
    LEFT JOIN ceo_uo u             ON u.id = s.uo
    LEFT JOIN ceo_formacion_servicios sv     ON sv.id = s.servicio
    LEFT JOIN ceo_patios pa        ON pa.id = s.patio
    LEFT JOIN ceo_formaciontipo h ON h.id = s.habilitacionceo
    LEFT JOIN ceo_responsablehse r1   ON r1.id = s.resphse
    LEFT JOIN ceo_evaluador ev     ON ev.id = s.resplinea
   WHERE s.nsolicitud = :id
   LIMIT 1";
$st = $pdo->prepare($sqlCab);
$st->execute([':id'=>$idSolicitud]);
$S = $st->fetch(PDO::FETCH_ASSOC);

if (!$S) {
  echo "<div class='alert alert-warning m-5'>No se encontro la formacion N° {$idSolicitud}.</div>";
  exit;
}

$fecha     = $S['fecha'] ?? '';
$hini      = substr((string)($S['horainicio'] ?? ''),0,5);
$hfin      = substr((string)($S['horatermino'] ?? ''),0,5);
$empresa   = $S['empresa'] ?? '';
$uo        = $S['unidad_op'] ?? '';
$servicio  = $S['servicio'] ?? '';
$patio     = $S['patio'] ?? '';
$habMotivo = $S['habilitacion'] ?? '';
$respHSE   = $S['resp_hse'] ?? '';
$respLinea = trim(($S['eval_nombre'] ?? '') . ' ' . ($S['eval_ap'] ?? '') . ' ' . ($S['eval_am'] ?? ''));

/* ====================== CARGA PARTICIPANTES ====================== */
$sqlPart = "
SELECT ps.rut, ps.nombre, ps.apellidop, ps.apellidom,
         (SELECT c.cargo FROM ceo_cargo_contratistas c WHERE c.id = ps.id_cargo LIMIT 1) AS cargo,
         em.nombre AS empresa
    FROM ceo_formacion_participantes_solicitud ps, ceo_formacion_solicitudes so, ceo_empresas em
   WHERE ps.id_solicitud = :id
   AND   ps.autorizado = 1
   AND ps.id_solicitud = so.nsolicitud
   AND so.contratista = em.id
   ORDER BY ps.nombre, ps.apellidop, ps.apellidom";
$stp = $pdo->prepare($sqlPart);
$stp->execute([':id'=>$idSolicitud]);
$participantes = $stp->fetchAll(PDO::FETCH_ASSOC);

$stmtSol = $pdo->prepare("
SELECT u.correo, u.nombres
  FROM ceo_formacion_solicitudes s
  JOIN ceo_usuarios u ON u.id = s.solicitante
  WHERE s.nsolicitud = :id
  LIMIT 1
");
$stmtSol->execute([':id' => $idSolicitud]);
$sol = $stmtSol->fetch(PDO::FETCH_ASSOC);

/* ====================== LOGO BASE64 (opcional) ====================== */
$logoBase64 = '';
$logoPath = defined('APP_LOGO') ? APP_LOGO : '';
if ($logoPath) {
  // intenta leer desde ruta absoluta o relativa
  $tryPaths = [$logoPath, __DIR__ . '/' . ltrim($logoPath, '/'), __DIR__ . '/../public/' . ltrim($logoPath,'/')];
  foreach ($tryPaths as $p) {
    if (@is_file($p)) {
      $logoBase64 = 'data:image/png;base64,' . base64_encode(@file_get_contents($p));
      break;
    }
  }
}

/* ====================== PLANTILLA HTML (PDF) ====================== */
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
.badge { padding:2px 6px; border-radius:3px; background:#eef2ff; border:1px solid #d6ddff; }
.meta td { border:none; padding:3px 6px; }
.box { border:none solid #bfc6d4; padding:8px; }
</style>
</head>
<body>

<div class="header">
  <div>
    <h2>Autorizada la Formacion N° <?= htmlspecialchars((string)$idSolicitud) ?></h2>
    <div class="small">[Cada persona tiene que hacer su Autodiagnóstico en https://enel.autodiagnostico.cl/ para ingresar al CEO]</div>
  </div>
  <div>
    <?php if ($logoBase64): ?>
      <img src="<?= $logoBase64 ?>" alt="Logo" style="height:40px;">
    <?php endif; ?>
  </div>
</div>

<table class="tbl meta">
  <tr>
    <td><b>Fecha</b> <span class="box"><?= htmlspecialchars($fecha) ?></span></td>
    <td><b>Inicio</b> <span class="box"><?= htmlspecialchars($hini) ?></span></td>
    <td><b>Término</b> <span class="box"><?= htmlspecialchars($hfin) ?></span></td>
    <td><b>Contratista</b> <span class="box"><?= htmlspecialchars($empresa) ?></span></td>
    <td><b>Capacitación</b> <span class="box">&nbsp;</span></td>
  </tr>
  <tr>
    <td colspan="2"><b>Responsable HSE</b> <span class="box"><?= htmlspecialchars($respHSE) ?></span></td>
    <td colspan="3"><b>Unidad Operativa</b> <span class="box"><?= htmlspecialchars($uo) ?></span></td>
  </tr>
  <tr>
    <td colspan="2"><b>Responsable Línea</b> <span class="box"><?= htmlspecialchars($respLinea) ?></span></td>
    <td colspan="2"><b>Servicio</b> <span class="box"><?= htmlspecialchars($servicio) ?></span></td>
    <td><b>Patio</b> <span class="box"><?= htmlspecialchars($patio) ?></span></td>
  </tr>
  <tr>
    <td colspan="5"><b>Habilitación/Motivo</b> <span class="box"><?= htmlspecialchars($habMotivo ?: 'Habilitación') ?></span></td>
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
    <?php if (!$participantes): ?>
      <tr><td colspan="5" style="text-align:center;color:#666;">(Sin participantes)</td></tr>
    <?php else: ?>
      <?php foreach ($participantes as $p): 
        $rut  = trim($p['rut'] ?? '');
        $nomc = trim(($p['nombre'] ?? '').' '.($p['apellidop'] ?? '').' '.($p['apellidom'] ?? ''));
        $cargo= $p['cargo'] ?? '';
      ?>
      <tr>
        <td><?= htmlspecialchars($rut) ?></td>
        <td><?= htmlspecialchars($nomc) ?></td>
        <td><?= htmlspecialchars($cargo) ?></td>
        <td>SI</td>
        <td>&nbsp;</td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

/* ====================== GENERA PDF ====================== */
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait'); // si quieres apaisado: 'landscape'
$dompdf->render();
$pdfData = $dompdf->output();

/* ====================== GUARDADO DEFINITIVO PDF ====================== */

// Directorio base (puedes cambiar el criterio por año/mes si quieres)
$baseDir = __DIR__ . '/../storage/permisos/' . date('Y');

// Crear carpeta si no existe
if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        throw new RuntimeException('No se pudo crear el directorio de permisos');
    }
}

// Nombre y ruta final
$nombreArchivo = "Permiso_Formacion_{$idSolicitud}.pdf";
$rutaFinal = $baseDir . DIRECTORY_SEPARATOR . $nombreArchivo;
file_put_contents($rutaFinal, $pdfData);



/* ====================== ENVÍA CORREO (PHPMailer) ====================== */
try {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host       = 'mail.noetica.cl';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'ceo@noetica.cl';
  $mail->Password   = 'Neotica_1964$'; // mueve a variables de config en producción
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // ✅ para 465
  $mail->Port       = 465;

  $mail->setFrom('ceo@noetica.cl', 'Autorización CEO');

  $mail->addAddress('marcelo.jofre94@gmail.com');

  $mail->isHTML(true);
  $mail->CharSet  = 'UTF-8';
  $mail->Encoding = 'base64';

  $mail->Subject  = "Autorizacion para Ingresar al CEO Formacion N° {$idSolicitud}";
  $mail->Body     = "<p>Para ingresar a cualquier instalación de ENEL, las personas tienen que portar su RUT.</p>";
  $mail->AltBody  = "Para ingresar a cualquier instalación de ENEL, las personas tienen que portar su RUT.";

  // Adjunta PDF
$mail->addAttachment($rutaFinal, $nombreArchivo);

  $mail->send();

/* Segudo correo a empresa evaluadora  */

/* ====================== ENVÍA SEGUNDO CORREO (Marcelo Jofré) ====================== */
try {
  $mail2 = new PHPMailer(true);
  $mail2->isSMTP();
  $mail2->Host       = 'mail.noetica.cl';
  $mail2->SMTPAuth   = true;
  $mail2->Username   = 'ceo@noetica.cl';
  $mail2->Password   = 'Neotica_1964$'; // mover a config
  $mail2->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail2->Port       = 465;

  $mail2->setFrom('ceo@noetica.cl', 'Autorizacion CEO');
  $mail2->addAddress('marcelo.jofre94@gmail.com');

  $mail2->isHTML(true);
  $mail2->CharSet  = 'UTF-8';
  $mail2->Encoding = 'base64';
  $mail2->Subject  = "Listado de Participantes - Formacion N° {$idSolicitud} ({$fecha})";

  // Armar tabla con participantes
  $tabla = '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-size:13px;">';
  $tabla .= '<tr style="background:#f0f3f8;"><th>RUT</th><th>Nombre</th><th>Cargo</th><th>Empresa</th></tr>';
  foreach ($participantes as $p) {
      $rut  = htmlspecialchars(trim($p['rut'] ?? ''));
      $nomc = htmlspecialchars(trim(($p['nombre'] ?? '').' '.($p['apellidop'] ?? '').' '.($p['apellidom'] ?? '')));
      $cargo= htmlspecialchars($p['cargo'] ?? '');
      $empresa = htmlspecialchars($p['empresa'] ?? '');
      $tabla .= "<tr><td>{$rut}</td><td>{$nomc}</td><td>{$cargo}</td><td>{$empresa}</td></tr>";
  }
  $tabla .= '</table>';

    // Anular permisos anteriores
    $pdo->prepare("
        UPDATE ceo_permiso_documento
           SET estado = 'ANULADO'
         WHERE nsolicitud = :n
           AND tipo = 'PERMISO'
    ")->execute([':n' => $idSolicitud]);

    $stHab = $pdo->prepare("
        SELECT id
        FROM ceo_formacion
        WHERE nsolicitud = :n
        LIMIT 1
    ");
    $stHab->execute([':n' => $idSolicitud]);
    $idHabilitacion = (int)$stHab->fetchColumn();
    $idHabilitacion = $idHabilitacionRaw ? (int)$idHabilitacionRaw : null;

    // Nueva versión
// ====================== CALCULAR NUEVA VERSION ======================
// ====================== INSERTAR DOCUMENTO (SIEMPRE) ======================
$stVer = $pdo->prepare("
    SELECT COALESCE(MAX(version), 0) + 1
    FROM ceo_permiso_documento
    WHERE nsolicitud = :n
      AND tipo = 'PERMISO'
");
$stVer->execute([':n' => $idSolicitud]);
$version = (int)$stVer->fetchColumn();

$stDoc = $pdo->prepare("
    INSERT INTO ceo_permiso_documento
    (
      nsolicitud,
      id_habilitacion,
      nombre_archivo,
      ruta_archivo,
      tipo,
      version,
      creado_por,
      fecha_creacion,
      estado
    )
    VALUES
    (
      :n,
      :hab,
      :nom,
      :ruta,
      'PERMISO',
      :ver,
      :user,
      NOW(),
      'VIGENTE'
    )
");

// ======================================================
// RESOLVER ID_HABILITACION (CLAVE DEL BUG)
// ======================================================
// ======================================================
// RESOLVER ID_HABILITACION (ÚNICA FUENTE DE VERDAD)
// ======================================================
$idHabilitacion = null;

$stHab = $pdo->prepare("
    SELECT id
    FROM ceo_formacion
    WHERE nsolicitud = :n
    LIMIT 1
");
$stHab->execute([':n' => $idSolicitud]);

$tmp = $stHab->fetchColumn();
if ($tmp !== false) {
    $idHabilitacion = (int)$tmp;
}

$stDoc->execute([
    ':n'    => $idSolicitud,
    ':hab'  => $idHabilitacion ?: null, // 👈 puede ser NULL
    ':nom'  => $nombreArchivo,
    ':ruta' => $rutaFinal,
    ':ver'  => $version,
    ':user' => (int)$_SESSION['auth']['id']
]);

  $mail2->Body = "
    <p>Estimados,</p>
    <p>Se informa el proceso de formacion correspondiente al <b>{$fecha}</b> para la <b>Formacion N° {$idSolicitud}</b>.</p>
    <p>Participantes:</p>
    {$tabla}
    <p>Se adjunta el documento PDF de autorización correspondiente.</p>
    <br><p>Saludos,<br>Sistema CEO</p>
  ";
  $mail2->AltBody = "Listado de participantes para la formacion N° {$idSolicitud} ({$fecha})";

  // Adjunta el mismo PDF
$mail2->addAttachment($rutaFinal, $nombreArchivo);


  $mail2->send();

} catch (Exception $e2) {
  error_log("⚠️ Error al enviar correo a Marcelo Jofre (Formacion {$idSolicitud}): {$mail2->ErrorInfo}");
}

  // Limpieza del archivo temporal
  //@unlink($tmpName);
  header("Location: formaciones.php?msg=autorizacion_ok");
  //header("Location: formacion_detalle.php?id={$idSolicitud}&msg=autorizacion_ok");
  exit;

} catch (Exception $e) {
  error_log("❌ Error al enviar autorización N° {$idSolicitud}: {$mail->ErrorInfo}");
  //@unlink($tmpName);
  header("Location: formacion_detalle.php?id={$idSolicitud}&msg=autorizacion_error");
  exit;
}
