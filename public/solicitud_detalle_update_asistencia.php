<?php
declare(strict_types=1);
ini_set('display_errors','1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

$idSol = (int)($_POST['id_solicitud'] ?? 0);
$rut   = trim((string)($_POST['rut'] ?? ''));
$asis  = ($_POST['asistio'] ?? '0');

if ($idSol <= 0 || $rut === '') {
  echo json_encode(['ok'=>false,'error'=>'Datos insuficientes']);
  exit;
}

try {
  $st = $pdo->prepare("
    UPDATE ceo_participantes_solicitud 
       SET asistio = :a 
     WHERE id_solicitud = :id AND rut = :rut
  ");
  $st->execute([
    ':a'  => $asis,
    ':id' => $idSol,
    ':rut'=> $rut
  ]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
