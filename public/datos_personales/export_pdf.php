<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/dp_lib.php';

dpModuleGuard();

$pdo = dpCeoDb();
dpEnsureTables($pdo);

$solicitudId = (int)($_GET['solicitud_id'] ?? 0);
$solicitud = $solicitudId > 0 ? dpSolicitudWithConsulta($pdo, $solicitudId) : null;

if ($solicitud === null || (int)($solicitud['consulta_id'] ?? 0) <= 0) {
    http_response_code(404);
    echo 'Solicitud no encontrada.';
    exit;
}

$detalle = dpConsultaDecodedRows($pdo, (int)$solicitud['consulta_id']);
$summary = json_decode((string)($solicitud['resumen_json'] ?? '{}'), true);
if (!is_array($summary)) {
    $summary = [];
}

$html = dpRenderFormalDocument($solicitud, $summary, $detalle, false, true);

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->setChroot(dirname(__DIR__, 2));

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$fileName = 'Informe_Datos_Personales_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$solicitud['folio']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fileName . '"');
echo $dompdf->output();
exit;
