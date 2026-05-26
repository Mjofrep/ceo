<?php
declare(strict_types=1);

function fisSessionKey(): string
{
    return 'formacion_informe_agrupacion_servicio_analysis';
}

function fisEnsureTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_formacion_informe_servicio_externo (
      id INT NOT NULL AUTO_INCREMENT,
      id_servicio INT NOT NULL,
      cuadrilla INT NOT NULL,
      grupo_excel VARCHAR(50) NOT NULL,
      grupo_orden INT NULL,
      orden_item INT NULL,
      rut VARCHAR(15) NOT NULL,
      nombre_excel VARCHAR(160) NULL,
      apellido_excel VARCHAR(160) NULL,
      cargo_excel VARCHAR(160) NULL,
      prueba_c_integrada VARCHAR(30) NULL,
      rdo VARCHAR(30) NULL,
      resultado_habilitacion_raw VARCHAR(80) NULL,
      resultado_habilitacion_norm ENUM('APROBADA','REPROBADA','PENDIENTE') NOT NULL DEFAULT 'PENDIENTE',
      archivo_origen VARCHAR(255) NULL,
      cargado_por INT NULL,
      fecha_carga DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_formacion_informe_ext (id_servicio, cuadrilla, rut),
      KEY idx_formacion_informe_servicio (id_servicio, cuadrilla),
      KEY idx_formacion_informe_grupo (grupo_orden, orden_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function fisEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fisNormalizeText(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function fisNormalizeHeader(string $value): string
{
    $value = fisNormalizeText($value);
    $value = str_replace(['°', 'º', 'ª'], '', $value);
    if (function_exists('mb_strtoupper')) {
        $value = mb_strtoupper($value, 'UTF-8');
    } else {
        $value = strtoupper($value);
    }
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') {
        $value = $converted;
    }
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim($value);
}

function fisRowContainsHeader(array $normalizedRow, string $needle): bool
{
    foreach ($normalizedRow as $value) {
        if ($value === $needle) {
            return true;
        }
        if ($needle === 'N' && str_starts_with($value, 'N')) {
            return true;
        }
        if ($needle === 'RESULTADO DE HABILITACION' && str_starts_with($value, 'RESULTADO DE HABILITACI')) {
            return true;
        }
    }
    return false;
}

function fisNormalizeRut(string $rut): string
{
    $rut = strtoupper(trim($rut));
    $rut = str_replace(['.', '-', ' '], '', $rut);
    if (strlen($rut) < 2) {
        return $rut;
    }
    return substr($rut, 0, -1) . '-' . substr($rut, -1);
}

function fisIsValidRut(string $rut): bool
{
    if (strlen($rut) < 2) {
        return false;
    }
    $rut = str_replace(['.', '-', ' '], '', strtoupper($rut));
    $cuerpo = substr($rut, 0, -1);
    $dv = substr($rut, -1);
    if ($cuerpo === '' || !ctype_digit($cuerpo)) {
        return false;
    }
    $suma = 0;
    $multiplicador = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += $multiplicador * (int)$cuerpo[$i];
        $multiplicador = $multiplicador < 7 ? $multiplicador + 1 : 2;
    }
    $resto = 11 - ($suma % 11);
    $esperado = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string)$resto);
    return $dv === $esperado;
}

function fisNormalizeResult(mixed $value): string
{
    $text = fisNormalizeHeader((string)$value);
    if ($text === '' || in_array($text, ['PENDIENTE', 'EN PROCESO', 'SIN RESULTADO'], true)) {
        return 'PENDIENTE';
    }
    if (in_array($text, ['APROBADA', 'APROBADO', 'OK'], true)) {
        return 'APROBADA';
    }
    if (in_array($text, ['REPROBADA', 'REPROBADO'], true)) {
        return 'REPROBADA';
    }
    return 'PENDIENTE';
}

function fisNormalizePercentValue(mixed $value): ?float
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return null;
    }
    $normalizedHeader = fisNormalizeHeader($text);
    if (in_array($normalizedHeader, ['PENDIENTE', 'EN PROCESO', 'SIN RESULTADO'], true)) {
        return null;
    }
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return null;
    }
    $number = (float)$text;
    if ($number <= 1.0) {
        $number *= 100;
    }
    return round($number, 2);
}

function fisClassifyRowStatus(mixed $pruebaCIntegrada, mixed $pruebaSe, mixed $rdo, mixed $resultadoHabilitacion): string
{
    $pci = fisNormalizePercentValue($pruebaCIntegrada);
    $pse = fisNormalizePercentValue($pruebaSe);
    $rdoPct = fisNormalizePercentValue($rdo);
    $resultadoPct = fisNormalizePercentValue($resultadoHabilitacion);

    if ($pci === null || $pse === null || $rdoPct === null || $resultadoPct === null) {
        return 'PENDIENTE';
    }

    if ($resultadoPct < 80) {
        return 'REPROBADA';
    }

    return 'APROBADA';
}

function fisDecodeXlsxCellValue(string $cellXml, array $sharedStrings): string
{
    if (str_ends_with(trim($cellXml), '/>')) {
        return '';
    }
    $type = '';
    if (preg_match('/\st="([^"]+)"/i', $cellXml, $m)) {
        $type = $m[1];
    }
    if ($type === 's' && preg_match('/<v[^>]*>(.*?)<\/v>/is', $cellXml, $m)) {
        return fisNormalizeText($sharedStrings[(int)trim($m[1])] ?? '');
    }
    if ($type === 'inlineStr') {
        return fisNormalizeText((string)(preg_replace('/<[^>]+>/u', ' ', $cellXml) ?? $cellXml));
    }
    if (preg_match('/<v[^>]*>(.*?)<\/v>/is', $cellXml, $m)) {
        return fisNormalizeText($m[1]);
    }
    return '';
}

function fisLoadWorkbookSheetRows(string $path, string $sheetName): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive no esta disponible para leer el archivo Excel.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo Excel.');
    }

    $sharedStrings = [];
    $sharedXml = (string)$zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== '' && preg_match_all('/<si[^>]*>(.*?)<\/si>/is', $sharedXml, $matches)) {
        foreach ($matches[1] as $si) {
            $sharedStrings[] = fisNormalizeText((string)(preg_replace('/<[^>]+>/u', ' ', $si) ?? $si));
        }
    }

    $rels = [];
    $relsXml = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml !== '' && preg_match_all('/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i', $relsXml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $rel) {
            $rels[$rel[1]] = $rel[2];
        }
    }

    $workbookXml = (string)$zip->getFromName('xl/workbook.xml');
    if ($workbookXml === '') {
        $zip->close();
        throw new RuntimeException('El archivo no contiene workbook.xml.');
    }

    $target = '';
    if (preg_match_all('/<sheet\b[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"[^>]*\/?/i', $workbookXml, $sheetMatches, PREG_SET_ORDER)) {
        foreach ($sheetMatches as $sheet) {
            if (fisNormalizeText(html_entity_decode($sheet[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $sheetName) {
                $target = $rels[$sheet[2]] ?? '';
                break;
            }
        }
    }

    if ($target === '') {
        $zip->close();
        throw new RuntimeException('El archivo no contiene la hoja requerida: ' . $sheetName . '.');
    }

    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
    $sheetXml = (string)$zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === '') {
        throw new RuntimeException('No se pudo leer la hoja ' . $sheetName . '.');
    }

    $rows = [];
    if (preg_match_all('/<row\b[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/is', $sheetXml, $rowMatches, PREG_SET_ORDER)) {
        foreach ($rowMatches as $rowMatch) {
            $rowNumber = (int)$rowMatch[1];
            $cols = [];
            if (preg_match_all('/<c\b[^>]*r="([A-Z]+)\d+"[^>]*(?:\/>|>.*?<\/c>)/is', $rowMatch[2], $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cellMatch) {
                    $cellXml = $cellMatch[0];
                    $col = strtoupper($cellMatch[1]);
                    $cols[$col] = fisDecodeXlsxCellValue($cellXml, $sharedStrings);
                }
            }
            $rows[] = ['row' => $rowNumber, 'cols' => $cols];
        }
    }

    return $rows;
}

function fisParseWorkbookReport(string $path): array
{
    $rows = fisLoadWorkbookSheetRows($path, 'Hoja1');
    $headerFound = false;
    $currentGroup = '';
    $currentGroupOrder = 0;
    $blocks = [];

    foreach ($rows as $row) {
        $cols = $row['cols'];
        $a = trim((string)($cols['A'] ?? ''));
        $b = trim((string)($cols['B'] ?? ''));
        $c = trim((string)($cols['C'] ?? ''));
        $d = trim((string)($cols['D'] ?? ''));
        $e = trim((string)($cols['E'] ?? ''));
        $f = trim((string)($cols['F'] ?? ''));
        $g = trim((string)($cols['G'] ?? ''));
        $h = trim((string)($cols['H'] ?? ''));
        $i = trim((string)($cols['I'] ?? ''));
        $j = trim((string)($cols['J'] ?? ''));

        if (!$headerFound) {
            $normalizedRow = array_map(static fn(string $value): string => fisNormalizeHeader($value), array_values($cols));
            if (
                fisRowContainsHeader($normalizedRow, 'RUT')
                && fisRowContainsHeader($normalizedRow, 'N')
                && fisRowContainsHeader($normalizedRow, 'PRUEBA C INTEGRADA')
                && fisRowContainsHeader($normalizedRow, 'RDO')
                && fisRowContainsHeader($normalizedRow, 'RESULTADO DE HABILITACION')
            ) {
                $headerFound = true;
            }
            continue;
        }

        if ($a !== '' && preg_match('/^GRUPO\s+(\d+)$/iu', $a, $groupMatch)) {
            $currentGroup = $a;
            $currentGroupOrder = (int)$groupMatch[1];
        }

        $ordenRaw = ctype_digit($b) ? $b : (ctype_digit($a) ? $a : '');
        if ($currentGroup === '' || $ordenRaw === '' || $c === '') {
            continue;
        }

        $rut = fisNormalizeRut($c);
        if (!fisIsValidRut($rut)) {
            continue;
        }

        $blocks[$currentGroup]['group_label'] = $currentGroup;
        $blocks[$currentGroup]['group_order'] = $currentGroupOrder;
        $blocks[$currentGroup]['rows'][] = [
            'excel_row' => (int)$row['row'],
            'orden_item' => (int)$ordenRaw,
            'rut' => $rut,
            'nombre' => $d,
            'apellido' => $e,
            'cargo' => $f,
            'prueba_c_integrada' => $g,
            'prueba_se_excel' => $h,
            'rdo' => $i,
            'resultado_habilitacion_raw' => $j,
            'resultado_habilitacion_norm' => fisNormalizeResult($j),
        ];
    }

    return array_values($blocks);
}

function fisResolveBlocksToCuadrillas(PDO $pdo, int $idServicio, array $blocks): array
{
    $resolved = [];
    foreach ($blocks as $block) {
        $ruts = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['rut'], $block['rows'] ?? [])));
        if (!$ruts) {
            $block['status'] = 'SIN_RUTS_VALIDOS';
            $block['message'] = 'El grupo no contiene RUTs validos.';
            $resolved[] = $block;
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($ruts), '?'));
        $sql = "SELECT p.id_cuadrilla AS cuadrilla, COUNT(DISTINCT p.rut) AS total
            FROM ceo_formacion_participantes p
            INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
            WHERE f.id_servicio = ?
              AND p.rut IN ($placeholders)
            GROUP BY p.id_cuadrilla
            ORDER BY total DESC, p.id_cuadrilla ASC";
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$idServicio], $ruts);
        $stmt->execute($params);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matchedCount = count($ruts);
        $validMatches = array_values(array_filter($matches, static fn(array $row): bool => (int)($row['total'] ?? 0) === $matchedCount));
        if (count($validMatches) === 1) {
            $block['status'] = 'RESUELTO';
            $block['cuadrilla'] = (int)$validMatches[0]['cuadrilla'];
            $block['message'] = 'Grupo asociado a cuadrilla ' . (int)$validMatches[0]['cuadrilla'] . '.';
        } elseif (count($validMatches) > 1) {
            $block['status'] = 'AMBIGUO';
            $block['cuadrilla'] = 0;
            $block['message'] = 'Los RUTs del grupo pertenecen a mas de una cuadrilla del servicio.';
        } else {
            $block['status'] = 'SIN_CUADRILLA';
            $block['cuadrilla'] = 0;
            $block['message'] = 'No se encontro una cuadrilla unica para todos los RUTs del grupo.';
        }

        $resolved[] = $block;
    }

    return $resolved;
}

function fisImportBlocks(PDO $pdo, int $idServicio, string $fileName, int $userId, array $blocks): array
{
    fisEnsureTable($pdo);
    $stmt = $pdo->prepare("INSERT INTO ceo_formacion_informe_servicio_externo
        (id_servicio, cuadrilla, grupo_excel, grupo_orden, orden_item, rut, nombre_excel, apellido_excel, cargo_excel, prueba_c_integrada, rdo, resultado_habilitacion_raw, resultado_habilitacion_norm, archivo_origen, cargado_por)
        VALUES
        (:id_servicio, :cuadrilla, :grupo_excel, :grupo_orden, :orden_item, :rut, :nombre_excel, :apellido_excel, :cargo_excel, :prueba_c_integrada, :rdo, :resultado_habilitacion_raw, :resultado_habilitacion_norm, :archivo_origen, :cargado_por)
        ON DUPLICATE KEY UPDATE
          grupo_excel = VALUES(grupo_excel),
          grupo_orden = VALUES(grupo_orden),
          orden_item = VALUES(orden_item),
          nombre_excel = VALUES(nombre_excel),
          apellido_excel = VALUES(apellido_excel),
          cargo_excel = VALUES(cargo_excel),
          prueba_c_integrada = VALUES(prueba_c_integrada),
          rdo = VALUES(rdo),
          resultado_habilitacion_raw = VALUES(resultado_habilitacion_raw),
          resultado_habilitacion_norm = VALUES(resultado_habilitacion_norm),
          archivo_origen = VALUES(archivo_origen),
          cargado_por = VALUES(cargado_por),
          fecha_carga = CURRENT_TIMESTAMP");

    $imported = 0;
    $skippedGroups = [];
    $pdo->beginTransaction();
    try {
        foreach ($blocks as $block) {
            if (($block['status'] ?? '') !== 'RESUELTO' || (int)($block['cuadrilla'] ?? 0) <= 0) {
                $skippedGroups[] = [
                    'group_label' => (string)($block['group_label'] ?? ''),
                    'message' => (string)($block['message'] ?? 'Grupo omitido.'),
                ];
                continue;
            }
            foreach (($block['rows'] ?? []) as $row) {
                $stmt->execute([
                    ':id_servicio' => $idServicio,
                    ':cuadrilla' => (int)$block['cuadrilla'],
                    ':grupo_excel' => (string)$block['group_label'],
                    ':grupo_orden' => (int)($block['group_order'] ?? 0),
                    ':orden_item' => (int)($row['orden_item'] ?? 0),
                    ':rut' => (string)$row['rut'],
                    ':nombre_excel' => (string)($row['nombre'] ?? ''),
                    ':apellido_excel' => (string)($row['apellido'] ?? ''),
                    ':cargo_excel' => (string)($row['cargo'] ?? ''),
                    ':prueba_c_integrada' => (string)($row['prueba_c_integrada'] ?? ''),
                    ':rdo' => (string)($row['rdo'] ?? ''),
                    ':resultado_habilitacion_raw' => (string)($row['resultado_habilitacion_raw'] ?? ''),
                    ':resultado_habilitacion_norm' => (string)($row['resultado_habilitacion_norm'] ?? 'PENDIENTE'),
                    ':archivo_origen' => $fileName,
                    ':cargado_por' => $userId > 0 ? $userId : null,
                ]);
                $imported++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['imported' => $imported, 'skipped_groups' => $skippedGroups];
}

function fisFetchServiceOptions(PDO $pdo): array
{
    return $pdo->query('SELECT id, servicio FROM ceo_formacion_servicios ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function fisFetchReportData(PDO $pdo, int $idServicio): array
{
    fisEnsureTable($pdo);
    $stmtService = $pdo->prepare('SELECT servicio FROM ceo_formacion_servicios WHERE id = :id LIMIT 1');
    $stmtService->execute([':id' => $idServicio]);
    $servicio = (string)$stmtService->fetchColumn();
    if ($servicio === '') {
        throw new RuntimeException('Servicio no encontrado.');
    }

    $sql = "SELECT ext.*, p.nombre AS nombre_ceo, p.apellidos AS apellido_ceo, p.cargo AS cargo_ceo,
        f.fecha AS fecha_formacion,
        se.puntaje_total AS prueba_se
      FROM ceo_formacion_informe_servicio_externo ext
      LEFT JOIN ceo_formacion_participantes p ON p.id_cuadrilla = ext.cuadrilla AND p.rut = ext.rut
      LEFT JOIN ceo_formacion f ON f.cuadrilla = ext.cuadrilla AND f.id_servicio = ext.id_servicio
      LEFT JOIN (
        SELECT ri1.rut, ri1.id_servicio, ri1.puntaje_total
        FROM ceo_resultado_formacion_intento ri1
        INNER JOIN (
          SELECT rut, id_servicio, MAX(CONCAT(fecha_rendicion,' ',hora_rendicion)) AS max_fecha
          FROM ceo_resultado_formacion_intento
          GROUP BY rut, id_servicio
        ) ri2 ON ri1.rut = ri2.rut AND ri1.id_servicio = ri2.id_servicio AND CONCAT(ri1.fecha_rendicion,' ',ri1.hora_rendicion) = ri2.max_fecha
      ) se ON se.rut = ext.rut AND se.id_servicio = ext.id_servicio
      WHERE ext.id_servicio = :id_servicio
      ORDER BY ext.grupo_orden ASC, ext.orden_item ASC, ext.rut ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_servicio' => $idServicio]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['PENDIENTE' => 0, 'APROBADA' => 0, 'REPROBADA' => 0, 'TOTAL' => count($rows)];
    $groups = [];
    foreach ($rows as $row) {
        $pruebaCIntegradaPct = fisNormalizePercentValue($row['prueba_c_integrada'] ?? null);
        $pruebaSePct = fisNormalizePercentValue($row['prueba_se'] ?? null);
        $rdoPct = fisNormalizePercentValue($row['rdo'] ?? null);
        $resultadoHabilitacionPct = fisNormalizePercentValue($row['resultado_habilitacion_raw'] ?? null);
        $state = fisClassifyRowStatus(
            $row['prueba_c_integrada'] ?? null,
            $row['prueba_se'] ?? null,
            $row['rdo'] ?? null,
            $row['resultado_habilitacion_raw'] ?? null
        );
        $summary[$state]++;

        $groupKey = (string)$row['grupo_excel'] . '|' . (int)$row['cuadrilla'];
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'group_label' => (string)$row['grupo_excel'],
                'cuadrilla' => (int)$row['cuadrilla'],
                'rows' => [],
            ];
        }
        $groups[$groupKey]['rows'][] = [
            'orden_item' => (int)($row['orden_item'] ?? 0),
            'rut' => (string)$row['rut'],
            'nombre' => (string)(($row['nombre_ceo'] ?? '') !== '' ? $row['nombre_ceo'] : ($row['nombre_excel'] ?? '')),
            'apellido' => (string)(($row['apellido_ceo'] ?? '') !== '' ? $row['apellido_ceo'] : ($row['apellido_excel'] ?? '')),
            'cargo' => (string)(($row['cargo_ceo'] ?? '') !== '' ? $row['cargo_ceo'] : ($row['cargo_excel'] ?? '')),
            'prueba_c_integrada' => (string)($row['prueba_c_integrada'] ?? ''),
            'prueba_se' => $row['prueba_se'] !== null ? (string)$row['prueba_se'] : '',
            'rdo' => (string)($row['rdo'] ?? ''),
            'resultado_habilitacion' => (string)($row['resultado_habilitacion_raw'] ?? ''),
            'resultado_habilitacion_norm' => (string)($row['resultado_habilitacion_norm'] ?? 'PENDIENTE'),
            'prueba_c_integrada_pct' => $pruebaCIntegradaPct,
            'prueba_se_pct' => $pruebaSePct,
            'rdo_pct' => $rdoPct,
            'resultado_habilitacion_pct' => $resultadoHabilitacionPct,
            'estado_resumen' => $state,
        ];
    }

    return [
        'id_servicio' => $idServicio,
        'servicio' => $servicio,
        'summary' => $summary,
        'groups' => array_values($groups),
        'rows' => $rows,
    ];
}
