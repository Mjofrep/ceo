<?php
// ============================================================
// enviar_correo.php - Envío de correo Nueva Solicitud CEO
// ============================================================
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require '../vendor/autoload.php';
require_once '../config/db.php';
require_once '../config/app.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

$idSolicitud = (int)($_GET['nsolicitud'] ?? 0);
if ($idSolicitud <= 0) {
  die('Solicitud inválida');
}

$pdo = db();

/* ============================================================
   1) DATOS DE SOLICITUD
   ============================================================ */
$stmt = $pdo->prepare("
  SELECT 
    s.nsolicitud,
    e.nombre AS nombre_empresa,
    p.desc_patios AS patio,
    s.fecha, s.horainicio, s.horatermino,
    r1.nombre AS resp_hse,
    r2.nombre AS resp_linea,
    uo.desc_uo AS unidad,
    srv.servicio AS servicio
    -- Si existe un campo tipo (ej: s.habilitacionceo o s.tipo_proceso), agrégalo aquí
    -- , s.habilitacionceo
  FROM ceo_solicitudes s
  LEFT JOIN ceo_empresas e ON s.contratista = e.id
  LEFT JOIN ceo_patios p ON s.patio = p.id
  LEFT JOIN ceo_responsablehse r1 ON s.resphse = r1.id
  LEFT JOIN ceo_evaluador r2 ON s.resplinea = r2.id
  LEFT JOIN ceo_uo uo ON s.uo = uo.id
  LEFT JOIN ceo_servicios srv ON s.servicio = srv.id
  WHERE s.nsolicitud = :id
");
$stmt->execute([':id' => $idSolicitud]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sol) {
  die('No se encontró la solicitud.');
}

/* ============================================================
   2) ¿EXISTE HABILITACIÓN ASOCIADA?
   - Si no existe, NO es error: puede ser "orden directa"
   ============================================================ */
$stHab = $pdo->prepare("
  SELECT id
  FROM ceo_habilitacion
  WHERE nsolicitud = :n
  ORDER BY id DESC
  LIMIT 1
");
$stHab->execute([':n' => $idSolicitud]);
$idHabilitacionRaw = $stHab->fetchColumn();
$idHabilitacion = $idHabilitacionRaw ? (int)$idHabilitacionRaw : null;

// Esto define el "modo" en base a si hay habilitación asociada
$esProcesoHabilitacion = ($idHabilitacion !== null && $idHabilitacion > 0);

/* ============================================================
   3) CORREOS ADMIN
   ============================================================ */
$stmtAdmins = $pdo->query("
  SELECT correo
  FROM ceo_usuarios
  WHERE id_rol = 1
    AND correo IS NOT NULL
    AND correo <> ''
");
$admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

if (!$admins) {
  die('No hay correos de administradores configurados.');
}

$usuario = $_SESSION['auth']['nombre'] ?? 'Usuario desconocido';
$correoUsuario = $_SESSION['auth']['correo'] ?? 'sin correo';
$fechaHora = date('d-m-Y H:i');

/* ============================================================
   4) MENSAJE HTML (con nota según tipo)
   ============================================================ */
$bloqueTipo = $esProcesoHabilitacion
  ? "<p><b>Tipo:</b> Solicitud asociada a Habilitación (ID habilitación: {$idHabilitacion})</p>"
  : "<p><b>Tipo:</b> Orden directa / visita / proceso sin habilitación asociada</p>";

$mensaje = "
<html><body style='font-family:Arial,sans-serif'>
  <h3 style='color:#0046AD;'>Nueva Solicitud Registrada</h3>
  <p>Se ha ingresado una nueva solicitud en el sistema <b>Centro de Excelencia Operacional (CEO)</b>.</p>
  {$bloqueTipo}
  <table cellpadding='6' cellspacing='0' style='font-size:14px'>
    <tr><td><b>N° Solicitud:</b></td><td>{$sol['nsolicitud']}</td></tr>
    <tr><td><b>Usuario:</b></td><td>{$usuario}</td></tr>
    <tr><td><b>Correo:</b></td><td>{$correoUsuario}</td></tr>
    <tr><td><b>Empresa:</b></td><td>{$sol['nombre_empresa']}</td></tr>
    <tr><td><b>Patio:</b></td><td>{$sol['patio']}</td></tr>
    <tr><td><b>Fecha:</b></td><td>{$sol['fecha']}</td></tr>
    <tr><td><b>Horario:</b></td><td>{$sol['horainicio']} - {$sol['horatermino']}</td></tr>
    <tr><td><b>Servicio:</b></td><td>{$sol['servicio']}</td></tr>
    <tr><td><b>UO:</b></td><td>{$sol['unidad']}</td></tr>
    <tr><td><b>Responsable HSE:</b></td><td>{$sol['resp_hse']}</td></tr>
    <tr><td><b>Responsable Línea:</b></td><td>{$sol['resp_linea']}</td></tr>
  </table>
  <p>Por favor, revise la solicitud en el portal CEO.</p>
  <hr><small>Mensaje generado automáticamente por el sistema CEO.</small>
</body></html>
";

try {
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = 'mail.noetica.cl';
  $mail->SMTPAuth = true;

  // RECOMENDACIÓN: mover estas credenciales a config/app.php o variables de entorno
  $mail->Username = 'ceo@noetica.cl';
  $mail->Password = 'Neotica_1964$';

  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail->Port = 465;

  $mail->setFrom('ceo@noetica.cl', 'Sistema CEO');
  foreach ($admins as $a) {
    $mail->addAddress($a);
  }

  $mail->isHTML(true);
  $mail->Subject = "Nueva Solicitud N {$idSolicitud} registrada";
  $mail->Body = $mensaje;

  /* ============================================================
     5) ADJUNTOS SOLO SI ES HABILITACIÓN
     - Aquí es donde antes probablemente adjuntabas documentos.
     ============================================================ */
  if ($esProcesoHabilitacion) {
    // Ejemplo:
    // $mail->addAttachment('/ruta/al/documento.pdf', 'Documento.pdf');
    // O traer desde BD según idHabilitacion
  }

  $mail->send();
  header('Location: solicitudes.php?msg=correo_ok');
  exit;

} catch (Exception $e) {
  error_log("Error al enviar correo (solicitud {$idSolicitud}): " . ($mail->ErrorInfo ?? $e->getMessage()));
  header('Location: solicitudes.php?msg=correo_error');
  exit;
}
