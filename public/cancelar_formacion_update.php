<?php
declare(strict_types=1);
ini_set('display_errors','1');
error_reporting(E_ALL);
session_start();
require_once '../config/db.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

$idSol = (int)($_POST['id_solicitud'] ?? 0);

if ($idSol <= 0) {
  echo json_encode(['ok'=>false,'error'=>'ID de solicitud inválido']);
  exit;
}

try {
  $st = $pdo->prepare("UPDATE ceo_formacion_solicitudes SET estado = 'C' WHERE nsolicitud = :id");
  $st->execute([':id'=>$idSol]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
