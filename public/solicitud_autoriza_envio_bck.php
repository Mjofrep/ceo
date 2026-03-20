<?php
// solicitud_autoriza_envio.php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/functions.php';
require_once '../vendor/autoload.php'; // PHPMailer
require_once __DIR__ . '/../config/app.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) exit('Solicitud inválida');

$pdo = db();

// --- Obtener datos ---
$stmt = $pdo->prepare("
  SELECT s.*, e.nombre AS empresa, p.desc_proceso AS proceso, pa.desc_patios AS patio,
         u.desc_uo AS uo, sv.servicio, r.responsable, h.desc_tipo AS habilitacion
    FROM ceo_solicitudes s
    LEFT JOIN ceo_empresas e ON e.id = s.contratista
    LEFT JOIN ceo_procesos p ON p.id = s.proceso
    LEFT JOIN ceo_patios pa ON pa.id = s.patio
    LEFT JOIN ceo_uo u ON u.id = s.uo
    LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
    LEFT JOIN ceo_responsables r ON r.id = s.responsable
    LEFT JOIN ceo_habilitaciontipo h ON h.id = s.habilitacionceo
   WHERE s.nsolicitud = :id
");
$stmt->execute([':id' => $id]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sol) exit('Solicitud no encontrada.');

// --- Participantes ---
$parts = $pdo->prepare("
  SELECT rut, nombre, apellidop, apellidom, 
         (SELECT c.cargo FROM ceo_cargo_contratistas c WHERE c.id = ps.id_cargo LIMIT 1) AS cargo
    FROM ceo_participantes_solicitud ps
   WHERE ps.id_solicitud = :id
");
$parts->execute([':id' => $id]);
$participantes = $parts->fetchAll(PDO::FETCH_ASSOC);

// === Generar PDF ===
require_once '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

ob_start();
?>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: Arial, sans-serif; font-size:12px; }
h3 { text-align:center; color:#004080; }
table { width:100%; border-collapse:collapse; margin-top:10px; }
th, td { border:1px solid #aaa; padding:4px; text-align:left; font-size:11px; }
</style>
</head>
<body>
<h3>Autorizada la Solicitud N° <?= $sol['nsolicitud'] ?></h3>
<p><strong>Fecha:</strong> <?= $sol['fecha'] ?> &nbsp;&nbsp; 
<strong>Inicio:</strong> <?= $sol['horainicio'] ?> &nbsp;&nbsp;
<strong>Término:</strong> <?= $sol['horatermino'] ?></p>
<p><strong>Contratista:</strong> <?= htmlspecialchars($sol['empresa']) ?><br>
<strong>Responsable UO:</strong> <?= htmlspecialchars($sol['responsable']) ?><br>
<strong>Unidad Operativa:</strong> <?= htmlspecialchars($sol['uo']) ?><br>
<strong>Servicio:</strong> <?= htmlspecialchars($sol['servicio']) ?><br>
<strong>Patio:</strong> <?= htmlspecialchars($sol['patio']) ?><br>
<strong>Motivo:</strong> <?= htmlspecialchars($sol['habceo_nombre'] ?? $sol['habilitacion']) ?></p>

<h4>Participantes</h4>
<table>
<thead><tr><th>RUT</th><th>Nombre</th><th>Apellidos</th><th>Cargo</th></tr></thead>
<tbody>
<?php foreach ($participantes as $p): ?>
<tr>
  <td><?= $p['rut'] ?></td>
  <td><?= $p['nombre'] ?></td>
  <td><?= $p['apellidop'].' '.$p['apellidom'] ?></td>
  <td><?= $p['cargo'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p style="margin-top:15px;">Cada persona debe realizar su Autodiagnóstico en 
<a href="https://enel.autodiagnostico.cl">https://enel.autodiagnostico.cl</a> para ingresar al CEO.</p>
</body>
</html>
<?php
$html = ob_get_clean();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfPath = "../tmp/solicitud_{$id}.pdf";
file_put_contents($pdfPath, $dompdf->output());

// === Enviar correo ===
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'tu_correo@gmail.com';
    $mail->Password = 'tu_clave_segura';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('no-reply@ceo.enel.com', 'Centro Excelencia Operacional');
    $to = [
        'yanett.henriquez@enel.com',
        'lmoralesv@drs.cl',
        'prevencionistaceoenel@gmail.com',
        'melanie.lopez.external@enel.com',
        'angela.herrera.external@enel.com',
        'Natalia.ceronpradenas@enel.com',
        'patricio.acuna@enel.com'
    ];
    foreach ($to as $addr) $mail->addAddress($addr);

    $mail->Subject = "Autorización para Ingreso al CEO Solicitud N° {$id}";
    $mail->Body = "Para ingresar a cualquier instalación de ENEL, las personas deben portar su RUT.";
    $mail->addAttachment($pdfPath);

    $mail->send();
    echo "<script>alert('✅ Autorización enviada correctamente');window.location='solicitud_detalle.php?id={$id}';</script>";
} catch (Exception $e) {
    echo "<div style='color:red'>Error enviando correo: {$mail->ErrorInfo}</div>";
}
