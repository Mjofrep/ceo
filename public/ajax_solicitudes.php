<?php
// /public/ajax_solicitudes.php
// -------------------------------------------------------------------
// Versión FINAL estable para PHP 8.4 – sin HY093, paginación funcional
// -------------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '/ceo.noetica.cl/config/db.php';

$DEBUG = true;
$logFile = __DIR__ . '/../logs/debug_ajax.txt';
if (!file_exists(dirname($logFile))) mkdir(dirname($logFile), 0777, true);

try {
    $pdo = db();

    // === Datos sesión ===
    $idRol     = (int)($_SESSION['auth']['id_rol'] ?? 0);
    $idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);

    // === Parámetros DataTables ===
    $draw   = (int)($_REQUEST['draw'] ?? 1);
    $start  = max(0, (int)($_REQUEST['start'] ?? 0));
    $length = max(10, (int)($_REQUEST['length'] ?? 25));
    $search = '';
    if (isset($_REQUEST['search']['value'])) {
        $search = trim((string)$_REQUEST['search']['value']);
    }

    // === WHERE dinámico ===
    $whereParts = [];
    $params = [];

    if ($idRol === 1 || $idEmpresa === 38) {
        $whereParts[] = '1=1';
    } else {
        $whereParts[] = 's.contratista = :empresa';
        $params[':empresa'] = $idEmpresa;
    }

    if ($search !== '') {
        $whereParts[] = "(s.nsolicitud LIKE :q
            OR u.nombres LIKE :q
            OR u.apellidos LIKE :q
            OR ce.nombre LIKE :q
            OR p.desc_patios LIKE :q)";
        $params[':q'] = "%$search%";
    }

    $whereSql = implode(' AND ', $whereParts);

    // === Totales ===
    $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM ceo_solicitudes")->fetchColumn();

    $countSql = "
        SELECT COUNT(*)
        FROM ceo_solicitudes s
        LEFT JOIN ceo_usuarios u ON s.solicitante = u.id
        LEFT JOIN ceo_patios p ON s.patio = p.id
        LEFT JOIN ceo_empresas ce ON s.contratista = ce.id
        WHERE $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $filteredRecords = (int)$countStmt->fetchColumn();

    // === Consulta principal ===
    $dataSql = "
        SELECT 
            s.id,
            s.nsolicitud,
            CONCAT(u.nombres, ' ', u.apellidos) AS solicitante,
            p.desc_patios AS patio,
            s.fecha,
            s.horainicio,
            s.horatermino,
            s.estado,
            ce.nombre AS contratista,
            cp.desc_proceso AS proceso,
            ch.desc_tipo AS habilitacionceo,
            s.tipohabilitacion,
            (
                SELECT COUNT(*) 
                FROM ceo_participantes_solicitud ps 
                WHERE ps.id_solicitud = s.nsolicitud
            ) AS cantidad_personas
        FROM ceo_solicitudes s
        LEFT JOIN ceo_usuarios u ON s.solicitante = u.id
        LEFT JOIN ceo_patios p ON s.patio = p.id
        LEFT JOIN ceo_empresas ce ON s.contratista = ce.id
        LEFT JOIN ceo_procesos cp ON s.proceso = cp.id
        LEFT JOIN ceo_habilitaciontipo ch ON s.habilitacionceo = ch.id
        WHERE $whereSql
        ORDER BY s.nsolicitud DESC
        LIMIT {$length} OFFSET {$start}
    ";

    // 🚫 ya NO se bindean :limit ni :offset
    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // === Log ===
    if ($DEBUG) {
        $log = sprintf(
            "[%s] OK\nSEARCH='%s'\nWHERE=%s\nPARAMS=%s\nLIMIT=%d OFFSET=%d\nRESULTADOS=%d\n\n",
            date('Y-m-d H:i:s'),
            $search,
            $whereSql,
            print_r($params, true),
            $length,
            $start,
            count($data)
        );
        file_put_contents($logFile, $log, FILE_APPEND);
    }

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $totalRecords,
        "recordsFiltered" => $filteredRecords,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
    http_response_code(500);
    $msg = sprintf(
        "[%s] ERROR: %s\nArchivo: %s:%d\nTrace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    file_put_contents($logFile, $msg, FILE_APPEND);
    echo json_encode(["error" => true, "message" => $e->getMessage()]);
}
exit;
