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
    $config = habDataToolTableConfig($table);
    if ($config === null) {
        throw new RuntimeException('Tabla no permitida.');
    }

    $key = is_array($input['key'] ?? null) ? $input['key'] : [];
    $values = is_array($input['values'] ?? null) ? $input['values'] : [];
    $primaryKey = array_values($config['primary_key'] ?? []);
    $editableColumns = array_values($config['editable_columns'] ?? []);

    foreach ($primaryKey as $pkColumn) {
        if (!array_key_exists($pkColumn, $key)) {
            throw new RuntimeException('Llave primaria incompleta.');
        }
    }

    $pdo = db();
    $columnsMap = habDataToolDescribeTable($pdo, $table);

    $whereParts = [];
    $whereParams = [];
    foreach ($primaryKey as $pkColumn) {
        if (!isset($columnsMap[$pkColumn])) {
            throw new RuntimeException('Columna clave no valida.');
        }
        $placeholder = ':pk_' . $pkColumn;
        $whereParts[] = '`' . $pkColumn . '` = ' . $placeholder;
        $whereParams[$placeholder] = $key[$pkColumn];
    }

    $stmtCurrent = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $whereParts) . ' LIMIT 1');
    $stmtCurrent->execute($whereParams);
    $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        throw new RuntimeException('No se encontro el registro a actualizar.');
    }

    $setParts = [];
    $updateParams = [];
    $changes = [];
    foreach ($values as $column => $value) {
        $column = trim((string)$column);
        if ($column === '' || !in_array($column, $editableColumns, true)) {
            continue;
        }
        if (!isset($columnsMap[$column])) {
            continue;
        }

        $normalized = habDataToolNormalizeValue((string)$columnsMap[$column]['type'], $value);
        $currentValue = $current[$column] ?? null;
        $currentComparable = $currentValue === null ? null : (string)$currentValue;
        $newComparable = $normalized === null ? null : (string)$normalized;

        if ($currentComparable === $newComparable) {
            continue;
        }

        $placeholder = ':val_' . $column;
        $setParts[] = '`' . $column . '` = ' . $placeholder;
        $updateParams[$placeholder] = $normalized;
        $changes[$column] = [
            'before' => $currentValue,
            'after' => $normalized,
        ];
    }

    if ($setParts === []) {
        echo json_encode([
            'ok' => true,
            'message' => 'Sin cambios para guardar.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    $stmtUpdate = $pdo->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $whereParts));
    $stmtUpdate->execute($updateParams + $whereParams);

    $keyLabel = habDataToolFormatPrimaryKey($primaryKey, $current);
    foreach ($changes as $column => $change) {
        auditDataEdit($table, $keyLabel, $column, $change['before'], $change['after']);
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Registro actualizado correctamente.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
