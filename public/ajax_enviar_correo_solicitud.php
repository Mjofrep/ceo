<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require '../vendor/autoload.php';
require_once '../config/db.php';
require_once '../config/app.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['auth'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
  exit;
}

$idSolicitud = (int)($_POST['nsolicitud'] ?? 0);
if ($idSolicitud <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Solicitud inválida']);
  exit;
}

try {
  $pdo = db();

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
    throw new RuntimeException('No se encontró la solicitud.');
  }

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
  $esProcesoHabilitacion = ($idHabilitacion !== null && $idHabilitacion > 0);

  $stmtAdmins = $pdo->query(" 
    SELECT correo
    FROM ceo_usuarios
    WHERE id_rol = 1
      AND estado = 'A'
      AND correo IS NOT NULL
      AND correo <> ''
  ");
  $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);

  if (!$admins) {
    throw new RuntimeException('No hay correos de administradores configurados.');
  }

  $usuario = $_SESSION['auth']['nombre'] ?? 'Usuario desconocido';
  $correoUsuario = $_SESSION['auth']['correo'] ?? 'sin correo';
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

  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = 'mail.noetica.cl';
  $mail->SMTPAuth = true;
  $mail->Username = 'ceo@noetica.cl';
  $mail->Password = 'Neotica_1964$';
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail->Port = 465;
  $mail->setFrom('ceo@noetica.cl', 'Sistema CEO');
  foreach ($admins as $a) {
    $mail->addAddress($a);
  }
  $mail->isHTML(true);
  $mail->CharSet = 'UTF-8';
  $mail->Encoding = 'base64';
  $mail->Subject = "Nueva Solicitud N {$idSolicitud} registrada";
  $mail->Body = $mensaje;
  $mail->send();

  echo json_encode(['ok' => true, 'nsolicitud' => $idSolicitud]);
} catch (Exception $e) {
  error_log("Error al enviar correo (solicitud {$idSolicitud}): " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("Error al enviar correo (solicitud {$idSolicitud}): " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
