<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const FCI_TEMPLATE_FILE = __DIR__ . '/../docs/Evaluaciones de Inspectores.xlsx';
const FCI_TEMPLATE_SHEET = 'Ciclo 1';
const FCI_START_ROW = 3;
const FCI_SERVICE_RDO = 15;
const FCI_SERVICE_INSPECTORES = 19;

function fciEnsureTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ceo_formacion_ciclo1_inspectores (
            id INT NOT NULL AUTO_INCREMENT,
            rut VARCHAR(20) NOT NULL,
            grupo_excel VARCHAR(100) NULL,
            hoja_origen VARCHAR(100) NOT NULL DEFAULT 'Ciclo 1',
            fila_origen INT NULL,
            prueba_c_integrada_raw VARCHAR(50) NULL,
            prueba_c_integrada DECIMAL(8,4) NULL,
            archivo_origen VARCHAR(255) NULL,
            cargado_por INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_formacion_ciclo1_inspectores_rut (rut),
            KEY idx_formacion_ciclo1_inspectores_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->query("SHOW COLUMNS FROM ceo_formacion_ciclo1_inspectores LIKE 'grupo_excel'");
    $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($column === false) {
        $pdo->exec("ALTER TABLE ceo_formacion_ciclo1_inspectores ADD COLUMN grupo_excel VARCHAR(100) NULL AFTER rut");
    }
}

function fciNormalizeSpaces(string $value): string
{
    $value = trim($value);
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function fciNormalizeRut(string $rut): string
{
    $rut = strtoupper(trim($rut));
    $rut = str_replace(['.', ' '], '', $rut);
    if ($rut === '') {
        return '';
    }

    $rut = str_replace('-', '', $rut);
    if (strlen($rut) <= 1) {
        return $rut;
    }

    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

function fciParseDecimalPercent(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_numeric($value)) {
        $number = (float)$value;
        if ($number > 1) {
            $number /= 100;
        }
        return round($number, 4);
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $text = str_replace(['%', ' '], '', $text);
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return null;
    }

    $number = (float)$text;
    if ($number > 1) {
        $number /= 100;
    }

    return round($number, 4);
}

function fciFindSheet(Spreadsheet $spreadsheet, string $sheetName): ?Worksheet
{
    $target = fciNormalizeSpaces($sheetName);
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        if (fciNormalizeSpaces($sheet->getTitle()) === $target) {
            return $sheet;
        }
    }

    return null;
}

function fciImportWorkbook(PDO $pdo, string $path, string $fileName, int $userId): array
{
    fciEnsureTable($pdo);

    $spreadsheet = IOFactory::load($path);
    $sheet = fciFindSheet($spreadsheet, FCI_TEMPLATE_SHEET);
    if ($sheet === null) {
        throw new RuntimeException('El archivo no contiene la hoja exacta "Ciclo 1".');
    }

    $stmt = $pdo->prepare("
        INSERT INTO ceo_formacion_ciclo1_inspectores
            (rut, grupo_excel, hoja_origen, fila_origen, prueba_c_integrada_raw, prueba_c_integrada, archivo_origen, cargado_por)
        VALUES
            (:rut, :grupo_excel, :hoja_origen, :fila_origen, :prueba_c_integrada_raw, :prueba_c_integrada, :archivo_origen, :cargado_por)
        ON DUPLICATE KEY UPDATE
            grupo_excel = VALUES(grupo_excel),
            hoja_origen = VALUES(hoja_origen),
            fila_origen = VALUES(fila_origen),
            prueba_c_integrada_raw = VALUES(prueba_c_integrada_raw),
            prueba_c_integrada = VALUES(prueba_c_integrada),
            archivo_origen = VALUES(archivo_origen),
            cargado_por = VALUES(cargado_por),
            updated_at = CURRENT_TIMESTAMP
    ");

    $processed = 0;
    $skipped = 0;
    $grupoActual = '';

    $highestRow = $sheet->getHighestDataRow();
    for ($row = FCI_START_ROW; $row <= $highestRow; $row++) {
        $grupoFila = fciNormalizeSpaces((string)$sheet->getCell("A{$row}")->getFormattedValue());
        if ($grupoFila !== '') {
            $grupoActual = $grupoFila;
        }

        $rut = fciNormalizeRut((string)$sheet->getCell("C{$row}")->getFormattedValue());
        if ($rut === '') {
            $skipped++;
            continue;
        }

        $cellY = $sheet->getCell("Y{$row}");
        $rawY = $cellY->getCalculatedValue();
        if (is_array($rawY)) {
            $rawY = null;
        }
        $formattedY = trim((string)$cellY->getFormattedValue());
        $rawText = trim((string)($formattedY !== '' ? $formattedY : ($rawY ?? '')));
        $valueY = fciParseDecimalPercent($rawY);
        if ($valueY === null) {
            $valueY = fciParseDecimalPercent($formattedY);
        }

        $stmt->execute([
            ':rut' => $rut,
            ':grupo_excel' => $grupoActual !== '' ? $grupoActual : null,
            ':hoja_origen' => FCI_TEMPLATE_SHEET,
            ':fila_origen' => $row,
            ':prueba_c_integrada_raw' => $rawText !== '' ? $rawText : null,
            ':prueba_c_integrada' => $valueY,
            ':archivo_origen' => $fileName,
            ':cargado_por' => $userId > 0 ? $userId : null,
        ]);

        $processed++;
    }

    return [
        'processed' => $processed,
        'skipped' => $skipped,
    ];
}

function fciFetchImportSummary(PDO $pdo): array
{
    fciEnsureTable($pdo);

    $total = (int)($pdo->query('SELECT COUNT(*) FROM ceo_formacion_ciclo1_inspectores')->fetchColumn() ?: 0);
    $latest = $pdo->query("
        SELECT archivo_origen, updated_at
        FROM ceo_formacion_ciclo1_inspectores
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($latest)) {
        $latest = [];
    }

    return [
        'total' => $total,
        'ultima_carga' => $latest['updated_at'] ?? null,
        'archivo_origen' => $latest['archivo_origen'] ?? null,
    ];
}

function fciFetchImportedScores(PDO $pdo): array
{
    fciEnsureTable($pdo);

    $rows = $pdo->query("
        SELECT rut, prueba_c_integrada_raw, prueba_c_integrada
        FROM ceo_formacion_ciclo1_inspectores
    ")->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        $data[(string)$row['rut']] = [
            'raw' => (string)($row['prueba_c_integrada_raw'] ?? ''),
            'value' => $row['prueba_c_integrada'] !== null ? (float)$row['prueba_c_integrada'] : null,
        ];
    }

    return $data;
}

function fciFetchImportedBaseRows(PDO $pdo): array
{
    fciEnsureTable($pdo);

    $stmt = $pdo->query("
        SELECT rut, grupo_excel, fila_origen, prueba_c_integrada_raw, prueba_c_integrada
        FROM ceo_formacion_ciclo1_inspectores
        ORDER BY COALESCE(fila_origen, 999999) ASC, id ASC
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function fciNormalizeServiceKey(string $service): string
{
    $service = fciNormalizeSpaces($service);
    if ($service === '') {
        return '';
    }

    $service = strtoupper($service);
    $service = strtr($service, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
        'Ñ' => 'N',
    ]);

    return $service;
}

function fciParseSelections(array $selections): array
{
    $parsed = [];

    foreach ($selections as $selectionRaw) {
        if (is_int($selectionRaw) || is_float($selectionRaw)) {
            $selectionRaw = (string)$selectionRaw;
        }
        if (!is_string($selectionRaw) || trim($selectionRaw) === '') {
            continue;
        }

        $decoded = json_decode($selectionRaw, true);
        if (!is_array($decoded)) {
            $selectionRaw = trim($selectionRaw);
            if (ctype_digit($selectionRaw)) {
                $decoded = [
                    'cuadrilla' => (int)$selectionRaw,
                    'id_servicio' => 0,
                    'servicio' => '',
                ];
            } else {
                $parts = array_map('trim', explode('|', $selectionRaw));
                $decoded = [
                    'cuadrilla' => (int)($parts[0] ?? 0),
                    'id_servicio' => (int)($parts[1] ?? 0),
                    'servicio' => (string)($parts[2] ?? ''),
                ];
            }
        }

        $cuadrilla = (int)($decoded['cuadrilla'] ?? 0);
        $idServicio = (int)($decoded['id_servicio'] ?? 0);
        $servicio = fciNormalizeSpaces((string)($decoded['servicio'] ?? ''));

        if ($cuadrilla <= 0) {
            continue;
        }

        $parsed[] = [
            'cuadrilla' => $cuadrilla,
            'id_servicio' => $idServicio,
            'servicio' => $servicio,
        ];
    }

    return $parsed;
}

function fciResolveSelections(PDO $pdo, array $selections): array
{
    $parsed = fciParseSelections($selections);
    if ($parsed === []) {
        return [];
    }

    $cuadrillasToResolve = [];
    foreach ($parsed as $selection) {
        if ((int)($selection['id_servicio'] ?? 0) <= 0 || trim((string)($selection['servicio'] ?? '')) === '') {
            $cuadrillasToResolve[] = (int)$selection['cuadrilla'];
        }
    }

    $metadataByCuadrilla = [];
    $cuadrillasToResolve = array_values(array_unique(array_filter($cuadrillasToResolve, static fn(int $v): bool => $v > 0)));
    if ($cuadrillasToResolve !== []) {
        $placeholders = implode(',', array_fill(0, count($cuadrillasToResolve), '?'));
        $stmt = $pdo->prepare("
            SELECT f.cuadrilla, f.id_servicio, COALESCE(s.servicio, '') AS servicio
            FROM ceo_formacion f
            LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
            INNER JOIN (
                SELECT cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion
                WHERE cuadrilla IN ($placeholders)
                GROUP BY cuadrilla
            ) ult ON ult.max_id = f.id
        ");
        $stmt->execute($cuadrillasToResolve);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadataByCuadrilla[(int)$row['cuadrilla']] = [
                'id_servicio' => (int)($row['id_servicio'] ?? 0),
                'servicio' => fciNormalizeSpaces((string)($row['servicio'] ?? '')),
            ];
        }
    }

    $resolved = [];
    foreach ($parsed as $selection) {
        $cuadrilla = (int)$selection['cuadrilla'];
        $idServicio = (int)($selection['id_servicio'] ?? 0);
        $servicio = fciNormalizeSpaces((string)($selection['servicio'] ?? ''));

        if (($idServicio <= 0 || $servicio === '') && isset($metadataByCuadrilla[$cuadrilla])) {
            if ($idServicio <= 0) {
                $idServicio = (int)$metadataByCuadrilla[$cuadrilla]['id_servicio'];
            }
            if ($servicio === '') {
                $servicio = (string)$metadataByCuadrilla[$cuadrilla]['servicio'];
            }
        }

        if ($idServicio <= 0) {
            continue;
        }

        $resolved[] = [
            'cuadrilla' => $cuadrilla,
            'id_servicio' => $idServicio,
            'servicio' => $servicio,
        ];
    }

    return $resolved;
}

function fciBuildServiceColumns(array $resolvedSelections): array
{
    $columns = [];
    $seen = [];
    $columnLetters = ['Z', 'AA'];

    foreach ($resolvedSelections as $selection) {
        $idServicio = (int)($selection['id_servicio'] ?? 0);
        if ($idServicio <= 0 || isset($seen[$idServicio])) {
            continue;
        }

        $label = trim((string)($selection['servicio'] ?? ''));
        if ($label === '') {
            $label = 'Servicio ' . $idServicio;
        }

        $columns[] = [
            'id_servicio' => $idServicio,
            'label' => $label,
            'column' => $columnLetters[count($columns)],
        ];
        $seen[$idServicio] = true;

        if (count($columns) === 2) {
            break;
        }
    }

    if ($columns === []) {
        throw new RuntimeException('Debe seleccionar al menos una cuadrilla con servicio válido.');
    }

    return $columns;
}

function fciFetchFormationScores(PDO $pdo, array $selections): array
{
    $resolvedSelections = fciResolveSelections($pdo, $selections);
    if ($resolvedSelections === []) {
        throw new RuntimeException('Debe seleccionar al menos una cuadrilla con servicio válido.');
    }

    $conditions = [];
    $params = [];
    foreach ($resolvedSelections as $selection) {
        $conditions[] = '(f.cuadrilla = ? AND f.id_servicio = ?)';
        $params[] = (int)$selection['cuadrilla'];
        $params[] = (int)$selection['id_servicio'];
    }

    $sql = "
        SELECT
            f.cuadrilla,
            f.id_servicio,
            s.servicio,
            f.fecha,
            f.id,
            p.rut,
            ri.puntaje_total,
            ri.puntaje_obtenido,
            ri.puntaje_maximo,
            ri.notafinal
        FROM ceo_formacion f
        LEFT JOIN ceo_formacion_servicios s
            ON s.id = f.id_servicio
        INNER JOIN ceo_formacion_participantes p
            ON p.id_cuadrilla = f.cuadrilla
        LEFT JOIN (
            SELECT ri1.rut, ri1.id_servicio, ri1.puntaje_total, ri1.puntaje_obtenido, ri1.puntaje_maximo, ri1.notafinal
            FROM ceo_resultado_formacion_intento ri1
            INNER JOIN (
                SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion, ' ', hora_rendicion)) AS max_fecha
                FROM ceo_resultado_formacion_intento
                GROUP BY rut, id_servicio
            ) ri2
                ON ri1.rut = ri2.rut
               AND ri1.id_servicio = ri2.id_servicio
               AND CONCAT(ri1.fecha_rendicion, ' ', ri1.hora_rendicion) = ri2.max_fecha
        ) ri
            ON ri.rut = p.rut
           AND ri.id_servicio = f.id_servicio
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY p.rut ASC, f.id_servicio ASC, f.fecha DESC, f.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $scores = [];
    foreach ($rows as $row) {
        $rut = fciNormalizeRut((string)($row['rut'] ?? ''));
        $idServicio = (int)($row['id_servicio'] ?? 0);
        if ($rut === '' || $idServicio <= 0) {
            continue;
        }

        if (!isset($scores[$rut])) {
            $scores[$rut] = [];
        }

        $score = fciParseDecimalPercent($row['puntaje_total'] ?? null);
        if ($score === null) {
            $obtenido = $row['puntaje_obtenido'] ?? null;
            $maximo = $row['puntaje_maximo'] ?? null;
            if (is_numeric($obtenido) && is_numeric($maximo) && (float)$maximo > 0.0) {
                $score = round(((float)$obtenido / (float)$maximo), 4);
            }
        }
        if ($score === null) {
            $score = fciParseDecimalPercent($row['notafinal'] ?? null);
        }

        if (!array_key_exists($idServicio, $scores[$rut])) {
            $scores[$rut][$idServicio] = $score;
        }
    }

    return $scores;
}

function fciFetchPersonData(PDO $pdo, array $resolvedSelections): array
{
    if ($resolvedSelections === []) {
        return [];
    }

    $conditions = [];
    $params = [];
    foreach ($resolvedSelections as $selection) {
        $conditions[] = '(f.cuadrilla = ? AND f.id_servicio = ?)';
        $params[] = (int)$selection['cuadrilla'];
        $params[] = (int)$selection['id_servicio'];
    }

    $sql = "
        SELECT
            f.id,
            f.fecha,
            f.id_servicio,
            p.rut,
            p.nombre,
            p.apellidos,
            p.cargo
        FROM ceo_formacion f
        INNER JOIN ceo_formacion_participantes p
            ON p.id_cuadrilla = f.cuadrilla
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY p.rut ASC, f.fecha DESC, f.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $people = [];
    foreach ($rows as $row) {
        $rut = fciNormalizeRut((string)($row['rut'] ?? ''));
        if ($rut === '' || isset($people[$rut])) {
            continue;
        }

        $people[$rut] = [
            'nombre' => fciNormalizeSpaces((string)($row['nombre'] ?? '')),
            'apellidos' => fciNormalizeSpaces((string)($row['apellidos'] ?? '')),
            'cargo' => fciNormalizeSpaces((string)($row['cargo'] ?? '')),
        ];
    }

    return $people;
}

function fciPrepareExportWorkbook(PDO $pdo, array $selections): Spreadsheet
{
    if (!is_file(FCI_TEMPLATE_FILE)) {
        throw new RuntimeException('No se encontró la plantilla docs/Evaluaciones de Inspectores.xlsx.');
    }

    $importedRows = fciFetchImportedBaseRows($pdo);
    if ($importedRows === []) {
        throw new RuntimeException('Primero debe cargar el Excel base de Inspectores.');
    }

    $resolvedSelections = fciResolveSelections($pdo, $selections);
    $serviceColumns = fciBuildServiceColumns($resolvedSelections);
    $formationScores = fciFetchFormationScores($pdo, $selections);
    $personData = fciFetchPersonData($pdo, $resolvedSelections);
    $spreadsheet = IOFactory::load(FCI_TEMPLATE_FILE);
    $sheet = fciFindSheet($spreadsheet, FCI_TEMPLATE_SHEET);
    if ($sheet === null) {
        throw new RuntimeException('La plantilla no contiene la hoja "Ciclo 1".');
    }

    $highestRow = $sheet->getHighestDataRow();
    for ($row = FCI_START_ROW; $row <= $highestRow; $row++) {
        $sheet->setCellValue("D{$row}", null);
        $sheet->setCellValue("E{$row}", null);
        $sheet->setCellValue("F{$row}", null);
        $sheet->setCellValue("Y{$row}", null);
        $sheet->setCellValue("Z{$row}", null);
        $sheet->setCellValue("AA{$row}", null);
        $sheet->setCellValue("AB{$row}", null);
        $sheet->setCellValue("AC{$row}", null);
    }

    $sheet->setCellValue('Z2', $serviceColumns[0]['label'] ?? 'Servicio 1');
    $sheet->setCellValue('AA2', $serviceColumns[1]['label'] ?? '');

    $previousGroup = null;
    foreach ($importedRows as $importRow) {
        $row = (int)($importRow['fila_origen'] ?? 0);
        $rut = fciNormalizeRut((string)($importRow['rut'] ?? ''));
        if ($row < FCI_START_ROW || $rut === '') {
            continue;
        }

        $group = fciNormalizeSpaces((string)($importRow['grupo_excel'] ?? ''));
        $sheet->setCellValue("A{$row}", ($group !== '' && $group !== $previousGroup) ? $group : '');
        $previousGroup = $group !== '' ? $group : $previousGroup;
        $sheet->setCellValueExplicit("C{$row}", $rut, DataType::TYPE_STRING);

        $person = $personData[$rut] ?? null;
        if (is_array($person)) {
            $sheet->setCellValue("D{$row}", (string)($person['nombre'] ?? ''));
            $sheet->setCellValue("E{$row}", (string)($person['apellidos'] ?? ''));
            $sheet->setCellValue("F{$row}", (string)($person['cargo'] ?? ''));
        }

        $pruebaIntegrada = $importRow['prueba_c_integrada'] !== null ? (float)$importRow['prueba_c_integrada'] : null;
        $sheet->setCellValue("Y{$row}", $pruebaIntegrada);

        foreach ($serviceColumns as $serviceColumn) {
            $column = (string)$serviceColumn['column'];
            $idServicio = (int)$serviceColumn['id_servicio'];
            $score = $formationScores[$rut][$idServicio] ?? null;
            $sheet->setCellValue($column . $row, $score);
        }

        $sheet->setCellValue(
            "AB{$row}",
            "=IF(COUNTA(Y{$row}:AA{$row})<3,\"pendiente\",Y{$row}*0.3+Z{$row}*0.5+AA{$row}*0.2)"
        );
        $sheet->setCellValue(
            "AC{$row}",
            "=IF(AB{$row}=\"pendiente\",\"Pendiente\",IF(AB{$row}>=0.8,\"Aprobada\",\"Reprobada\"))"
        );
    }

    $sheet->setSelectedCell('A1');
    return $spreadsheet;
}

function fciDownloadFilename(): string
{
    return 'evaluaciones_inspectores_ciclo_1_' . date('Ymd_His') . '.xlsx';
}
