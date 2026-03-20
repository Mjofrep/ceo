<?php
declare(strict_types=1);
session_start();
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $fecha = $_GET['fecha'] ?? date('Y-m-d');
  $pdo = db();

  $sql = "SELECT 
            s.nsolicitud,
            z.desc_zona,
            p.desc_patios,
            e.nombre AS empresa,
            s.horainicio,
            s.horatermino
          FROM ceo_solicitudes s
          JOIN ceo_empresas e ON s.contratista = e.id
          JOIN ceo_patios p   ON s.patio = p.id
          JOIN ceo_zona_patio_mapa z ON p.id = z.id_patio
          WHERE s.fecha = :fecha
            AND s.estado in ('I','A','F')";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':fecha' => $fecha]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
