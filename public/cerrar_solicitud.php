<?php
declare(strict_types=1);
session_start();
require_once '../config/db.php';

if (empty($_SESSION['auth']) || (int)($_SESSION['auth']['id_rol'] ?? 0) === 6) exit('No autorizado');

$nsol = (int)($_POST['nsol'] ?? 0);
if ($nsol <= 0) exit('Solicitud inválida');

$pdo = db();

$stmt = $pdo->prepare("UPDATE ceo_solicitudes SET estado = 'F', fechafinaliza = CURDATE() WHERE nsolicitud = ?");
$stmt->execute([$nsol]);

echo "OK";
