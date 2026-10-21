<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const EI_HEADER_ROW = 2;
const EI_FIRST_DATA_ROW = 3;
const EI_MAX_COLUMN = 23; // W

function eiEnsureTable(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS ceo_evaluaciones_inspectores (\n            id INT NOT NULL AUTO_INCREMENT,\n            rut VARCHAR(20) NOT NULL,\n            grupo_excel VARCHAR(100) NULL,\n            fila_origen INT NOT NULL,\n            datos_json LONGTEXT NOT NULL,\n            columnas_json LONGTEXT NOT NULL,\n            archivo_origen VARCHAR(255) NOT NULL,\n            cargado_por INT NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            PRIMARY KEY (id),\n            KEY idx_evaluaciones_inspectores_rut (rut),\n            KEY idx_evaluaciones_inspectores_carga (created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    // Las personas pueden aparecer más de una vez en el mismo Excel.
    $uniqueRut = $pdo->query("SHOW INDEX FROM ceo_evaluaciones_inspectores WHERE Key_name = 'uq_evaluaciones_inspectores_rut'");
    if ($uniqueRut !== false && $uniqueRut->fetch(PDO::FETCH_ASSOC) !== false) {
        $pdo->exec('ALTER TABLE ceo_evaluaciones_inspectores DROP INDEX uq_evaluaciones_inspectores_rut');
    }

    $cicloColumn = $pdo->query("SHOW COLUMNS FROM ceo_evaluaciones_inspectores LIKE 'ciclo'");
    if ($cicloColumn !== false && $cicloColumn->fetch(PDO::FETCH_ASSOC) === false) {
        $pdo->exec('ALTER TABLE ceo_evaluaciones_inspectores ADD COLUMN ciclo VARCHAR(100) NULL AFTER rut');
    }
}

function eiNormalizeRut(string $rut): string
{
    $rut = strtoupper(trim(str_replace(['.', ' '], '', $rut)));
    $rut = str_replace('-', '', $rut);
    return strlen($rut) > 1 ? substr($rut, 0, -1) . '-' . substr($rut, -1) : $rut;
}

function eiFractionFromExcelValue(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $score = (float)$value;
    if ($score > 1.0) {
        $score /= 100;
    }
    return $score >= 0.0 && $score <= 1.0 ? $score : null;
}

function eiRoundPercentFraction(?float $score): ?float
{
    if ($score === null) {
        return null;
    }
    $percentage = $score * 100;
    $roundedPercentage = ($percentage - floor($percentage)) >= 0.5 ? ceil($percentage) : floor($percentage);
    return $roundedPercentage / 100;
}

function eiRoundedFormationScore(mixed $value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $score = (float)$value;
    if ($score < 0.0 || $score > 100.0) {
        return null;
    }
    return eiRoundPercentFraction($score / 100);
}

function eiPercentDisplay(?float $value, int $decimals = 0): string
{
    return $value === null ? '' : number_format($value * 100, $decimals, ',', '.') . '%';
}

function eiRowMatchesFilters(array $row, array $filters): bool
{
    foreach ($filters as $key => $filter) {
        if (!is_string($key) || !is_scalar($filter)) {
            continue;
        }
        $filter = trim((string)$filter);
        if ($filter === '') {
            continue;
        }
        $value = (string)($row[$key]['display'] ?? '');
        $matches = function_exists('mb_stripos')
            ? mb_stripos($value, $filter, 0, 'UTF-8') !== false
            : stripos($value, $filter) !== false;
        if (!$matches) {
            return false;
        }
    }
    return true;
}

function eiNormalizeGroup(string $value): string
{
    $value = trim($value);
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function eiHeaderToken(string $value): string
{
    $value = strtoupper(eiNormalizeGroup($value));
    $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function eiIsCycleSheet(string $title): bool
{
    return preg_match('/^CICLO\s+\d+$/i', trim($title)) === 1;
}

function eiCycleNumber(string $cycle): int
{
    preg_match('/\d+/', $cycle, $matches);
    return isset($matches[0]) ? (int)$matches[0] : 0;
}

function eiBuildColumns($sheet): array
{
    $columns = [['key' => 'CICLO', 'label' => 'Ciclo', 'is_percent' => false]];
    $percentColumns = ['H', 'I', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W'];
    for ($columnIndex = 1; $columnIndex <= EI_MAX_COLUMN; $columnIndex++) {
        $letter = Coordinate::stringFromColumnIndex($columnIndex);
        $label = eiNormalizeGroup((string)$sheet->getCell($letter . EI_HEADER_ROW)->getFormattedValue());
        if ($label === '') {
            throw new RuntimeException('La hoja "' . $sheet->getTitle() . '" no tiene encabezado en la columna ' . $letter . '.');
        }
        if ($letter === 'W') {
            $label = 'C-Integrada';
        }
        $columns[] = [
            'key' => $letter,
            'label' => $label,
            'is_percent' => in_array($letter, $percentColumns, true),
        ];
    }
    return $columns;
}

function eiBuildSheetColumnMap($sheet, array $columns): array
{
    $canonicalByHeader = [];
    foreach ($columns as $column) {
        if ($column['key'] !== 'CICLO') {
            $canonicalByHeader[eiHeaderToken($column['label'])][] = $column['key'];
        }
    }

    $occurrences = [];
    $map = [];
    $lastColumn = min(EI_MAX_COLUMN, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));
    for ($columnIndex = 1; $columnIndex <= $lastColumn; $columnIndex++) {
        $sourceLetter = Coordinate::stringFromColumnIndex($columnIndex);
        $token = eiHeaderToken((string)$sheet->getCell($sourceLetter . EI_HEADER_ROW)->getFormattedValue());
        if ($token === '' || !isset($canonicalByHeader[$token])) {
            continue;
        }
        $occurrence = $occurrences[$token] ?? 0;
        if (isset($canonicalByHeader[$token][$occurrence])) {
            $map[$canonicalByHeader[$token][$occurrence]] = $sourceLetter;
        }
        $occurrences[$token] = $occurrence + 1;
    }

    foreach (['A', 'B', 'C', 'D', 'W'] as $requiredColumn) {
        if (!isset($map[$requiredColumn])) {
            throw new RuntimeException('La hoja "' . $sheet->getTitle() . '" no tiene la columna requerida ' . $requiredColumn . '.');
        }
    }
    return $map;
}

function eiImportWorkbook(PDO $pdo, string $path, string $fileName, int $userId): array
{
    $spreadsheet = IOFactory::load($path);
    $rows = [];
    $skipped = 0;
    $columns = [];
    $cycles = 0;
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        if (!eiIsCycleSheet($sheet->getTitle())) {
            continue;
        }
        if ($columns === []) {
            $columns = eiBuildColumns($sheet);
        }
        $columnMap = eiBuildSheetColumnMap($sheet, $columns);
        $cycles++;
        for ($rowIndex = EI_FIRST_DATA_ROW; $rowIndex <= $sheet->getHighestDataRow(); $rowIndex++) {
            $rut = eiNormalizeRut((string)$sheet->getCell($columnMap['B'] . $rowIndex)->getFormattedValue());
            $name = trim((string)$sheet->getCell($columnMap['C'] . $rowIndex)->getFormattedValue());
            if ($rut === '' && $name === '') {
                $skipped++;
                continue;
            }
            if ($rut === '') {
                throw new RuntimeException('La hoja "' . $sheet->getTitle() . '" tiene un registro sin RUT en la fila ' . $rowIndex . '.');
            }

            $data = ['CICLO' => ['display' => $sheet->getTitle(), 'numeric' => null]];
            foreach ($columns as $column) {
                if ($column['key'] === 'CICLO') {
                    continue;
                }
                $sourceLetter = $columnMap[$column['key']] ?? null;
                if ($sourceLetter === null) {
                    $data[$column['key']] = ['display' => '', 'numeric' => null];
                    continue;
                }
                $cell = $sheet->getCell($sourceLetter . $rowIndex);
                $raw = $cell->getCalculatedValue();
                $data[$column['key']] = [
                    'display' => trim((string)$cell->getFormattedValue()),
                    'numeric' => is_numeric($raw) ? (float)$raw : null,
                ];
            }
            $data['B']['display'] = $rut;
            $rows[] = ['rut' => $rut, 'ciclo' => $sheet->getTitle(), 'fila' => $rowIndex, 'data' => $data];
        }
    }

    if ($rows === []) {
        throw new RuntimeException('El archivo no contiene registros válidos en hojas "Ciclo N".');
    }

    $rowsByRut = [];
    foreach ($rows as $row) {
        $rut = (string)$row['rut'];
        if (!isset($rowsByRut[$rut]) || eiCycleNumber((string)$row['ciclo']) > eiCycleNumber((string)$rowsByRut[$rut]['ciclo'])) {
            $rowsByRut[$rut] = $row;
        }
    }
    $duplicateRuts = count($rows) - count($rowsByRut);
    $rows = array_values($rowsByRut);

    $columnsJson = json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insert = $pdo->prepare('INSERT INTO ceo_evaluaciones_inspectores
        (rut, ciclo, grupo_excel, fila_origen, datos_json, columnas_json, archivo_origen, cargado_por)
        VALUES
        (:rut, :ciclo, :grupo_excel, :fila_origen, :datos_json, :columnas_json, :archivo_origen, :cargado_por)');

    $pdo->beginTransaction();
    try {
        // Esta tabla representa exactamente el último archivo cargado, no un historial acumulativo.
        $pdo->exec('DELETE FROM ceo_evaluaciones_inspectores');
        foreach ($rows as $row) {
            $insert->execute([
                ':rut' => $row['rut'],
                ':ciclo' => $row['ciclo'],
                ':grupo_excel' => $row['ciclo'],
                ':fila_origen' => $row['fila'],
                ':datos_json' => json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':columnas_json' => $columnsJson,
                ':archivo_origen' => $fileName,
                ':cargado_por' => $userId > 0 ? $userId : null,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['processed' => count($rows), 'skipped' => $skipped, 'duplicate_ruts' => $duplicateRuts, 'cycles' => $cycles];
}

$pdo = db();
eiEnsureTable($pdo);
$flash = $_SESSION['evaluaciones_inspectores_flash'] ?? null;
unset($_SESSION['evaluaciones_inspectores_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf'] ?? null)) {
            throw new RuntimeException('La sesión del formulario expiró. Intente nuevamente.');
        }
        if (empty($_FILES['excel']['tmp_name']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
            throw new RuntimeException('Seleccione un archivo Excel para cargar.');
        }
        $fileName = (string)($_FILES['excel']['name'] ?? 'Resultados evaluación_cintegrada.xlsx');
        if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('El archivo debe tener extensión .xlsx.');
        }
        $result = eiImportWorkbook($pdo, (string)$_FILES['excel']['tmp_name'], basename($fileName), (int)($_SESSION['auth']['id'] ?? 0));
        $_SESSION['evaluaciones_inspectores_flash'] = [
            'type' => 'success',
            'message' => 'Carga finalizada: ' . $result['processed'] . ' registro(s) de ' . $result['cycles'] . ' ciclo(s) reemplazados. Filas omitidas: ' . $result['skipped'] . '. Registros duplicados descartados: ' . $result['duplicate_ruts'] . '.',
        ];
        header('Location: evaluaciones_inspectores.php');
        exit;
    } catch (Throwable $e) {
        $flash = ['type' => 'danger', 'message' => $e->getMessage()];
    }
}

$storedRows = $pdo->query('SELECT datos_json, columnas_json FROM ceo_evaluaciones_inspectores ORDER BY ciclo, fila_origen, id')->fetchAll(PDO::FETCH_ASSOC);
$columns = [];
$rows = [];
foreach ($storedRows as $storedRow) {
    $data = json_decode((string)$storedRow['datos_json'], true);
    if (is_array($data)) {
        $rows[] = $data;
    }
    if ($columns === []) {
        $decodedColumns = json_decode((string)$storedRow['columnas_json'], true);
        if (is_array($decodedColumns)) {
            $columns = $decodedColumns;
        }
    }
}

foreach ($columns as &$column) {
    if (($column['key'] ?? '') === 'W') {
        $column['label'] = 'C-Integrada';
    }
}
unset($column);

$formationScores = [];
$scoreRows = $pdo->query("\n    SELECT rut, id_servicio, puntaje_total\n    FROM ceo_resultado_formacion_intento\n    WHERE id_servicio IN (15, 19)\n    ORDER BY rut ASC, id_servicio ASC, fecha_rendicion DESC, hora_rendicion DESC, id DESC\n")->fetchAll(PDO::FETCH_ASSOC);
foreach ($scoreRows as $scoreRow) {
    $rut = eiNormalizeRut((string)($scoreRow['rut'] ?? ''));
    $serviceId = (int)($scoreRow['id_servicio'] ?? 0);
    if ($rut === '' || isset($formationScores[$rut][$serviceId])) {
        continue;
    }
    $formationScores[$rut][$serviceId] = eiRoundedFormationScore($scoreRow['puntaje_total'] ?? null);
}

$rdoHistoryScores = [];
$rdoHistoryRows = $pdo->query("\n    SELECT rut, nota_prueba\n    FROM ceo_rdo_historia\n    WHERE fecha_vigencia >= CURDATE()\n      AND nota_prueba BETWEEN 0 AND 100\n    ORDER BY rut ASC, fecha DESC, id DESC\n")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rdoHistoryRows as $rdoHistoryRow) {
    $rut = eiNormalizeRut((string)($rdoHistoryRow['rut'] ?? ''));
    if ($rut === '' || isset($rdoHistoryScores[$rut])) {
        continue;
    }
    $score = eiRoundedFormationScore($rdoHistoryRow['nota_prueba'] ?? null);
    if ($score !== null) {
        $rdoHistoryScores[$rut] = $score;
    }
}

if ($columns !== []) {
    $columns[] = ['key' => 'PRUEBA_SE', 'label' => 'Prueba SE', 'is_percent' => true];
    $columns[] = ['key' => 'RDO', 'label' => 'RDO', 'is_percent' => true];
    $columns[] = ['key' => 'RESULTADO_HABILITACION', 'label' => 'Resultado de Habilitación', 'is_percent' => true];
    $columns[] = ['key' => 'ESTADO', 'label' => 'Estado', 'is_percent' => false];

    foreach ($rows as &$row) {
        $rut = eiNormalizeRut((string)($row['B']['display'] ?? ''));
        $cIntegrada = eiRoundPercentFraction(eiFractionFromExcelValue($row['W']['numeric'] ?? null));
        $pruebaSe = $formationScores[$rut][19] ?? null;
        $rdo = $formationScores[$rut][15] ?? $rdoHistoryScores[$rut] ?? null;
        $resultado = $cIntegrada !== null && $pruebaSe !== null && $rdo !== null
            ? round(($cIntegrada * 0.3) + ($pruebaSe * 0.5) + ($rdo * 0.2), 4)
            : null;
        $estado = $resultado === null ? 'PENDIENTE' : ($resultado >= 0.8 ? 'APROBADO' : 'REPROBADO');

        $row['PRUEBA_SE'] = ['display' => eiPercentDisplay($pruebaSe), 'numeric' => $pruebaSe];
        $row['RDO'] = ['display' => eiPercentDisplay($rdo), 'numeric' => $rdo];
        $row['RESULTADO_HABILITACION'] = ['display' => eiPercentDisplay($resultado, 2), 'numeric' => $resultado];
        $row['ESTADO'] = ['display' => $estado, 'numeric' => null];
    }
    unset($row);
}
$summary = $pdo->query("SELECT COUNT(*) AS total, MAX(created_at) AS ultima_carga, MAX(archivo_origen) AS archivo_origen FROM ceo_evaluaciones_inspectores")->fetch(PDO::FETCH_ASSOC) ?: [];

if (($_GET['export'] ?? '') === 'xlsx') {
    $filters = json_decode((string)($_GET['filters'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];
    $exportRows = array_values(array_filter($rows, static fn(array $row): bool => eiRowMatchesFilters($row, $filters)));

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Evaluaciones');
    foreach ($columns as $index => $column) {
        $cell = Coordinate::stringFromColumnIndex($index + 1) . '1';
        $sheet->setCellValue($cell, (string)($column['label'] ?? ''));
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth(20);
    }

    foreach ($exportRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 2;
        foreach ($columns as $columnIndex => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $cell = $columnLetter . $excelRow;
            $key = (string)($column['key'] ?? '');
            $value = (string)($row[$key]['display'] ?? '');
            $score = $row[$key]['numeric'] ?? null;
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);

            $isMissingMovement = ($key === 'PRUEBA_SE' || $key === 'RDO') && !is_numeric($score);
            $isLowScore = !empty($column['is_percent']) && is_numeric($score) && (float)$score < 0.8;
            if ($isMissingMovement || $isLowScore) {
                $sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFCE8E8');
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFA61B1B');
                $sheet->getStyle($cell)->getFont()->setBold(true);
            }
        }
    }

    $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($columns)));
    $lastRow = max(1, count($exportRows) + 1);
    $headerRange = 'A1:' . $lastColumn . '1';
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle($headerRange)->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1F4E78');
    $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FFD9E2F3');
    $sheet->freezePane('A2');
    if ($columns !== []) {
        $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
    }

    $filename = 'evaluaciones_inspectores_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Evaluaciones de Inspectores | <?= esc(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background: #f6f8fc; color: #172033; }
.topbar { background: #fff; border-bottom: 1px solid #e3e6ea; }
.page-card { border: 0; border-radius: 1rem; box-shadow: 0 8px 26px rgba(15, 23, 42, .08); }
.table-wrap { max-height: 72vh; overflow: auto; border: 1px solid #dce3ed; border-radius: .65rem; }
.evaluations-table { margin: 0; font-size: .82rem; white-space: nowrap; }
.evaluations-table th { min-width: 132px; vertical-align: bottom; }
.evaluations-table thead tr:first-child th { position: sticky; top: 0; z-index: 3; background: #e9f1fb; }
.evaluations-table thead tr:nth-child(2) th { position: sticky; top: var(--filter-row-top, 39px); z-index: 2; background: #fff; }
.evaluations-table td { vertical-align: middle; }
.score-low { background: #fce8e8 !important; color: #a61b1b; font-weight: 700; }
.score-missing { background: #fce8e8 !important; color: #a61b1b; font-weight: 700; }
.upload-button { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; }
.chart-button { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; }
.status-summary { width: auto; min-width: 390px; }
.status-summary th { background: #e9f1fb; font-size: .8rem; }
.panorama-title { font-size: 1.3rem; font-weight: 700; letter-spacing: -.02em; color: #132238; }
.panorama-scale { display: flex; justify-content: space-between; margin: 0 3.75rem 0 10.6rem; color: #94a3b8; font-size: .75rem; }
.panorama-row { display: grid; grid-template-columns: 9.5rem minmax(240px, 1fr) 3rem; gap: 1rem; align-items: center; margin: .72rem 0; }
.panorama-label { font-size: .86rem; font-weight: 600; color: #334155; }
.panorama-bar { display: flex; min-height: 1.55rem; overflow: hidden; background: #e9eef5; box-shadow: inset 0 0 0 1px rgba(148, 163, 184, .16); }
.panorama-segment { min-width: 2rem; display: flex; align-items: center; justify-content: center; overflow: hidden; color: #fff; font-size: .75rem; font-weight: 700; line-height: 1; transition: flex-basis .25s ease; }
.panorama-approved { background: #38a548; }
.panorama-failed { background: #ec2e2b; }
.panorama-pending { background: #f2c94c; color: #594400; }
.panorama-total { color: #64748b; font-size: .82rem; font-variant-numeric: tabular-nums; }
.panorama-summary { display: flex; flex-wrap: wrap; gap: .65rem; margin: 0 3.75rem 1.1rem 10.6rem; }
.panorama-summary-item { display: flex; align-items: baseline; gap: .4rem; padding: .38rem .62rem; border-radius: .4rem; font-size: .78rem; font-weight: 600; }
.panorama-summary-item strong { font-size: 1rem; font-variant-numeric: tabular-nums; }
.panorama-summary-approved { background: #e1f4e4; color: #216a2d; }
.panorama-summary-failed { background: #fde5e5; color: #a61b1b; }
.panorama-summary-pending { background: #fff4c9; color: #745800; }
.panorama-legend { display: flex; flex-wrap: wrap; gap: 1.25rem; margin: 1.35rem 0 .25rem 10.6rem; font-size: .8rem; color: #64748b; }
.panorama-dot { display: inline-block; width: .85rem; height: .85rem; margin-right: .38rem; vertical-align: -.1rem; }
@media (max-width: 575.98px) { .panorama-scale { margin-left: 0; margin-right: 0; } .panorama-row { grid-template-columns: 1fr; gap: .3rem; margin-bottom: 1rem; } .panorama-total { text-align: right; } .panorama-summary, .panorama-legend { margin-left: 0; margin-right: 0; gap: .75rem; } }
</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= esc(APP_LOGO) ?>" style="height:55px;" alt="Logo <?= esc(APP_NAME) ?>">
      <div>
        <div class="fw-bold"><?= esc(APP_NAME) ?></div>
        <small class="text-muted"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>
<main class="container-fluid py-4 px-lg-4">
  <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="h3 mb-1">Evaluaciones de Inspectores</h1>
      <p class="text-secondary mb-0">Base vigente del archivo Excel con filtros por columna.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <form id="upload-form" method="post" enctype="multipart/form-data" class="m-0">
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
        <input id="excel-file" type="file" name="excel" accept=".xlsx" class="d-none">
        <button id="upload-button" type="button" class="btn btn-primary btn-sm upload-button" title="Cargar y reemplazar Excel" aria-label="Cargar y reemplazar Excel"><i class="bi bi-upload"></i></button>
      </form>
      <button id="panorama-button" type="button" class="btn btn-outline-primary btn-sm chart-button" title="Panorama de habilitaciones por grupo" aria-label="Panorama de habilitaciones por grupo"><i class="bi bi-bar-chart-line"></i></button>
      <button id="export-button" type="button" class="btn btn-success btn-sm" title="Exportar a Excel"><i class="bi bi-file-earmark-excel me-1"></i>Exportar</button>
    </div>
  </div>

  <?php if (is_array($flash)): ?>
    <div class="alert alert-<?= esc((string)($flash['type'] ?? 'info')) ?>"><?= esc((string)($flash['message'] ?? '')) ?></div>
  <?php endif; ?>

  <section class="card page-card">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex flex-wrap gap-3 align-items-center mb-3 small text-secondary">
        <span>Registros: <strong class="text-dark"><?= (int)($summary['total'] ?? 0) ?></strong></span>
        <?php if (!empty($summary['ultima_carga'])): ?><span>Última carga: <strong class="text-dark"><?= esc((string)$summary['ultima_carga']) ?></strong></span><?php endif; ?>
        <?php if (!empty($summary['archivo_origen'])): ?><span>Archivo: <strong class="text-dark"><?= esc((string)$summary['archivo_origen']) ?></strong></span><?php endif; ?>
        <span class="ms-lg-auto">Los porcentajes inferiores a 80% se muestran en rojo.</span>
      </div>
      <?php if ($rows === []): ?>
        <div class="text-center text-secondary py-5">Aún no hay evaluaciones cargadas. Use el ícono de carga para seleccionar el Excel.</div>
      <?php else: ?>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered status-summary mb-0">
            <thead><tr><th>Aprobados</th><th>Reprobados</th><th>Pendientes</th></tr></thead>
            <tbody><tr><td id="approved-count">0</td><td id="failed-count">0</td><td id="pending-count">0</td></tr></tbody>
          </table>
        </div>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="show-intermediate-columns">
          <label class="form-check-label" for="show-intermediate-columns">Mostrar columnas de evaluaciones intermedias (G a V)</label>
        </div>
        <div class="table-wrap">
          <table class="table table-sm table-bordered evaluations-table" id="evaluations-table">
            <thead><tr id="table-head"></tr><tr id="filter-head"></tr></thead>
            <tbody id="table-body"></tbody>
          </table>
        </div>
        <div id="filtered-count" class="small text-secondary mt-2"></div>
      <?php endif; ?>
    </div>
  </section>
</main>
<div class="modal fade" id="panorama-modal" tabindex="-1" aria-labelledby="panorama-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <div>
          <div class="text-uppercase text-primary fw-semibold small">Evaluaciones de inspectores</div>
          <h2 class="modal-title panorama-title mt-1" id="panorama-modal-title">Panorama de habilitaciones por grupo</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body pt-3 pb-4" id="panorama-content"></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allColumns = <?= json_encode($columns, JSON_UNESCAPED_UNICODE) ?>;
const availableColumns = allColumns;
const defaultColumnKeys = new Set(['CICLO', 'A', 'B', 'C', 'D', 'E', 'F', 'W', 'PRUEBA_SE', 'RDO', 'RESULTADO_HABILITACION', 'ESTADO']);
const intermediateColumnsToggle = document.getElementById('show-intermediate-columns');
let columns = [];
const rows = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
const head = document.getElementById('table-head');
const filterHead = document.getElementById('filter-head');
const body = document.getElementById('table-body');
const filteredCount = document.getElementById('filtered-count');
const evaluationsTable = document.getElementById('evaluations-table');
const statusColumn = allColumns.find((column) => column.key === 'ESTADO');

function updateStatusSummary(filtered) {
  let approved = 0;
  let failed = 0;
  let pending = 0;
  filtered.forEach((row) => {
    const status = String(row[statusColumn?.key]?.display ?? 'PENDIENTE');
    if (status === 'APROBADO') approved++;
    else if (status === 'REPROBADO') failed++;
    else pending++;
  });
  document.getElementById('approved-count').textContent = approved;
  document.getElementById('failed-count').textContent = failed;
  document.getElementById('pending-count').textContent = pending;
}

function renderHeaders() {
  const activeFilters = Object.fromEntries([...filterHead.querySelectorAll('input')].map((input) => [input.dataset.column, input.value]));
  columns = availableColumns.filter((column) => defaultColumnKeys.has(column.key) || intermediateColumnsToggle.checked);
  head.replaceChildren();
  filterHead.replaceChildren();
  columns.forEach((column) => {
    const th = document.createElement('th');
    th.textContent = column.label;
    head.appendChild(th);
    const filterTh = document.createElement('th');
    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control form-control-sm';
    input.placeholder = 'Filtrar';
    input.dataset.column = column.key;
    input.value = activeFilters[column.key] || '';
    input.addEventListener('input', renderRows);
    filterTh.appendChild(input);
    filterHead.appendChild(filterTh);
  });
  evaluationsTable.style.setProperty('--filter-row-top', `${head.getBoundingClientRect().height}px`);
}

function renderRows() {
  if (!body) return;
  const filtered = getFilteredRows();
  body.replaceChildren();
  filtered.forEach((row) => {
    const tr = document.createElement('tr');
    columns.forEach((column) => {
      const cell = row[column.key] || {};
      const td = document.createElement('td');
      td.textContent = cell.display ?? '';
      if ((column.key === 'PRUEBA_SE' || column.key === 'RDO') && typeof cell.numeric !== 'number') td.className = 'score-missing';
      else if (column.is_percent && typeof cell.numeric === 'number' && cell.numeric < 0.8) td.className = 'score-low';
      tr.appendChild(td);
    });
    body.appendChild(tr);
  });
  updateStatusSummary(filtered);
  filteredCount.textContent = `Mostrando ${filtered.length} de ${rows.length} registro(s).`;
}

function getFilteredRows() {
  const filters = [...filterHead.querySelectorAll('input')].map((input) => input.value.trim().toLocaleLowerCase());
  return rows.filter((row) => columns.every((column, index) => !filters[index] || String(row[column.key]?.display ?? '').toLocaleLowerCase().includes(filters[index])));
}

function exportExcel() {
  const filters = {};
  if (filterHead) {
    filterHead.querySelectorAll('input').forEach((input) => {
      if (input.value.trim() !== '') filters[input.dataset.column] = input.value;
    });
  }
  const params = new URLSearchParams({ export: 'xlsx', filters: JSON.stringify(filters) });
  window.location.href = `evaluaciones_inspectores.php?${params.toString()}`;
}

function cycleOrder(value) {
  const match = String(value).match(/(\d+)/);
  return match ? Number(match[1]) : Number.MAX_SAFE_INTEGER;
}

function groupLabel(value) {
  return String(value).replace(/^ciclo\s*/i, 'Grupo ');
}

function buildPanorama() {
  const content = document.getElementById('panorama-content');
  const groups = new Map();
  const totals = { approved: 0, failed: 0, pending: 0 };
  rows.forEach((row) => {
    const cycle = String(row.CICLO?.display || 'Sin grupo');
    if (!groups.has(cycle)) groups.set(cycle, { approved: 0, failed: 0, pending: 0, total: 0 });
    const group = groups.get(cycle);
    const status = String(row.ESTADO?.display || 'PENDIENTE');
    if (status === 'APROBADO') { group.approved++; totals.approved++; }
    else if (status === 'REPROBADO') { group.failed++; totals.failed++; }
    else { group.pending++; totals.pending++; }
    group.total++;
  });

  content.replaceChildren();
  if (groups.size === 0) {
    content.textContent = 'No hay datos de habilitación para mostrar.';
    return;
  }

  const summary = document.createElement('div');
  summary.className = 'panorama-summary';
  [
    ['panorama-summary-approved', 'Aprobados', totals.approved],
    ['panorama-summary-failed', 'Reprobados', totals.failed],
    ['panorama-summary-pending', 'Pendientes', totals.pending],
  ].forEach(([className, label, total]) => {
    const item = document.createElement('div');
    item.className = `panorama-summary-item ${className}`;
    item.innerHTML = `<span>${label}</span><strong>${total}</strong>`;
    summary.appendChild(item);
  });
  content.appendChild(summary);

  const scale = document.createElement('div');
  scale.className = 'panorama-scale';
  ['0%', '50%', '100%'].forEach((label) => {
    const item = document.createElement('span');
    item.textContent = label;
    scale.appendChild(item);
  });
  content.appendChild(scale);

  [...groups.entries()].sort(([a], [b]) => cycleOrder(a) - cycleOrder(b)).forEach(([cycle, group]) => {
    const row = document.createElement('div');
    row.className = 'panorama-row';
    const label = document.createElement('div');
    label.className = 'panorama-label';
    label.textContent = groupLabel(cycle);
    const bar = document.createElement('div');
    bar.className = 'panorama-bar';
    bar.setAttribute('aria-label', `${groupLabel(cycle)}: ${group.approved} aprobados, ${group.failed} reprobados y ${group.pending} pendientes`);
    [
      ['panorama-approved', group.approved],
      ['panorama-failed', group.failed],
      ['panorama-pending', group.pending],
    ].forEach(([className, value]) => {
      if (value === 0) return;
      const segment = document.createElement('div');
      segment.className = `panorama-segment ${className}`;
      segment.style.flexBasis = `${(value / group.total) * 100}%`;
      segment.title = `${value} de ${group.total}`;
      segment.textContent = value;
      bar.appendChild(segment);
    });
    const total = document.createElement('div');
    total.className = 'panorama-total';
    total.textContent = group.total;
    row.append(label, bar, total);
    content.appendChild(row);
  });

  const legend = document.createElement('div');
  legend.className = 'panorama-legend';
  [['panorama-approved', 'Aprobados'], ['panorama-failed', 'Reprobados'], ['panorama-pending', 'Pendientes']].forEach(([className, label]) => {
    const item = document.createElement('span');
    item.innerHTML = `<i class="panorama-dot ${className}"></i>${label}`;
    legend.appendChild(item);
  });
  content.appendChild(legend);
}

if (head) {
  renderHeaders();
  renderRows();
  intermediateColumnsToggle.addEventListener('change', () => {
    renderHeaders();
    renderRows();
  });
  window.addEventListener('resize', () => {
    evaluationsTable.style.setProperty('--filter-row-top', `${head.getBoundingClientRect().height}px`);
  });
}

document.getElementById('upload-button').addEventListener('click', () => document.getElementById('excel-file').click());
document.getElementById('excel-file').addEventListener('change', (event) => { if (event.target.files.length) document.getElementById('upload-form').submit(); });
document.getElementById('export-button').addEventListener('click', exportExcel);
document.getElementById('panorama-button').addEventListener('click', () => {
  buildPanorama();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('panorama-modal')).show();
});
</script>
</body>
</html>
