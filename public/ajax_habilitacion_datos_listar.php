<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/habilitacion_datos_lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ((int)($_SESSION['auth']['id_rol'] ?? 0) !== 1) {
        throw new RuntimeException('No autorizado.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Metodo invalido.');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException('Solicitud invalida.');
    }

    $table = trim((string)($input['table'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = (int)($input['per_page'] ?? 20);
    if (!in_array($perPage, [20, 30, 50], true)) {
        $perPage = 20;
    }
    $offset = ($page - 1) * $perPage;

    $pdo = db();
    $query = habDataToolBuildQueryContext($pdo, $table, $input['filters'] ?? []);
    $config = $query['config'];
    $columnsMap = $query['columns_map'];
    $columnNames = $query['column_names'];
    $whereSql = $query['where_sql'];
    $params = $query['params'];
    $filterValues = $query['filter_values'];
    $orderSql = $query['order_sql'];

    $sqlCount = 'SELECT COUNT(*) FROM `' . $table . '`' . $whereSql;
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $perPage;
    }

    $sqlData = 'SELECT * FROM `' . $table . '`' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    $columns = habDataToolBuildColumnsPayload($columnsMap, $filterValues);

    echo json_encode([
        'ok' => true,
        'table' => $table,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
        'total' => $total,
        'primary_key' => array_values($config['primary_key'] ?? []),
        'editable_columns' => array_values($config['editable_columns'] ?? []),
        'columns' => $columns,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
