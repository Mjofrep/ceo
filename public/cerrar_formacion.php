<?php
declare(strict_types=1);
session_start();
require_once '../config/db.php';

if (empty($_SESSION['auth'])) exit('No autorizado');

$nsol = (int)($_POST['nsol'] ?? 0);
if ($nsol <= 0) exit('Formacion invalida');

$pdo = db();

$stmt = $pdo->prepare("UPDATE ceo_formacion_solicitudes SET estado = 'F' WHERE nsolicitud = ?");
$stmt->execute([$nsol]);

echo "OK";
