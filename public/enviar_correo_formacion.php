<?php
// ============================================================
// enviar_correo_formacion.php - Envio de correo Nueva Formacion CEO
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
  die('Formacion invalida');
}

$pdo = db();

/* ============================================================
   1) DATOS DE FORMACION
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
  FROM ceo_formacion_solicitudes s
  LEFT JOIN ceo_empresas e ON s.contratista = e.id
  LEFT JOIN ceo_patios p ON s.patio = p.id
  LEFT JOIN ceo_responsablehse r1 ON s.resphse = r1.id
  LEFT JOIN ceo_evaluador r2 ON s.resplinea = r2.id
  LEFT JOIN ceo_uo uo ON s.uo = uo.id
  LEFT JOIN ceo_formacion_servicios srv ON s.servicio = srv.id
  WHERE s.nsolicitud = :id
");
$stmt->execute([':id' => $idSolicitud]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sol) {
  die('No se encontro la formacion.');
}

/* ============================================================
   2) ¿EXISTE FORMACION ASOCIADA?
   - Si no existe, NO es error: puede ser "orden directa"
   ============================================================ */
$stForm = $pdo->prepare("
  SELECT id
  FROM ceo_formacion
  WHERE nsolicitud = :n
  ORDER BY id DESC
  LIMIT 1
");
$stForm->execute([':n' => $idSolicitud]);
$idFormacionRaw = $stForm->fetchColumn();
$idFormacion = $idFormacionRaw ? (int)$idFormacionRaw : null;

// Esto define el "modo" en base a si hay formacion asociada
$esProcesoFormacion = ($idFormacion !== null && $idFormacion > 0);

/* ============================================================
   3) CORREOS ADMIN
   ============================================================ */
$admins = ['marcelo.jofre94@gmail.com'];

$usuario = $_SESSION['auth']['nombre'] ?? 'Usuario desconocido';
$correoUsuario = $_SESSION['auth']['correo'] ?? 'sin correo';
$fechaHora = date('d-m-Y H:i');

/* ============================================================
   4) MENSAJE HTML (con nota segun tipo)
   ============================================================ */
$bloqueTipo = $esProcesoFormacion
  ? "<p><b>Tipo:</b> Formacion registrada (ID formacion: {$idFormacion})</p>"
  : "<p><b>Tipo:</b> Orden directa / visita / proceso sin formacion asociada</p>";

$mensaje = "
<html><body style='font-family:Arial,sans-serif'>
  <h3 style='color:#0046AD;'>Nueva Formacion Registrada</h3>
  <p>Se ha ingresado una nueva formacion en el sistema <b>Centro de Excelencia Operacional (CEO)</b>.</p>
  {$bloqueTipo}
  <table cellpadding='6' cellspacing='0' style='font-size:14px'>
    <tr><td><b>N° Formacion:</b></td><td>{$sol['nsolicitud']}</td></tr>
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
  <p>Por favor, revise la formacion en el portal CEO.</p>
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
  $mail->Subject = "Nueva Formacion N {$idSolicitud} registrada";
  $mail->Body = $mensaje;

  /* ============================================================
     5) ADJUNTOS SOLO SI ES FORMACION
     - Aqui es donde antes probablemente adjuntabas documentos.
     ============================================================ */
  if ($esProcesoFormacion) {
    // Ejemplo:
    // $mail->addAttachment('/ruta/al/documento.pdf', 'Documento.pdf');
    // O traer desde BD segun idFormacion
  }

  $mail->send();
  header('Location: formaciones.php?msg=correo_ok');
  exit;

} catch (Exception $e) {
  error_log("Error al enviar correo (formacion {$idSolicitud}): " . ($mail->ErrorInfo ?? $e->getMessage()));
  header('Location: formaciones.php?msg=correo_error');
  exit;
}
