<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

function hcaNormalizeRut(string $rut): string
{
    return str_replace(['.', '-', ' '], '', strtoupper(trim($rut)));
}

function hcaHeader(PDO $pdo, int $idHabilitacion): ?array
{
    $sql = '
        SELECT h.id, h.cuadrilla, h.fecha, h.jornada, h.id_servicio, h.estado, h.nsolicitud,
               e.nombre AS empresa, u.desc_uo AS uo, sp.servicio,
               TRIM(CONCAT(COALESCE(usr.nombres, \'\'), \' \', COALESCE(usr.apellidos, \'\'))) AS gestor
        FROM ceo_habilitacion h
        INNER JOIN ceo_empresas e ON e.id = h.empresa
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
        LEFT JOIN ceo_uo u ON u.id = h.uo
        LEFT JOIN ceo_usuarios usr ON usr.id = h.gestor
        WHERE h.id = :id
    ';
    $params = [':id' => $idHabilitacion];

    if (strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista') {
        $sql .= ' AND h.empresa = :empresa';
        $params[':empresa'] = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hcaHeadersFromRequest(PDO $pdo, array $request): array
{
    $rawIds = [];
    if (array_key_exists('ids', $request)) {
        if (!is_array($request['ids'])) {
            throw new InvalidArgumentException('Selección de cuadrillas inválida.');
        }
        $rawIds = $request['ids'];
    }
    if (array_key_exists('id', $request)) {
        $rawIds[] = $request['id'];
    }
    if (!$rawIds) {
        throw new InvalidArgumentException('Debe seleccionar al menos una cuadrilla.');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (!is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Selección de cuadrillas inválida.');
        }
        $ids[(int)$rawId] = (int)$rawId;
    }
    $ids = array_values($ids);
    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $placeholder = ':id' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $id;
    }

    $sql = '
        SELECT h.id, h.cuadrilla, h.fecha, h.jornada, h.id_servicio, h.estado, h.nsolicitud,
               e.nombre AS empresa, u.desc_uo AS uo, sp.servicio,
               TRIM(CONCAT(COALESCE(usr.nombres, \'\'), \' \', COALESCE(usr.apellidos, \'\'))) AS gestor
        FROM ceo_habilitacion h
        INNER JOIN ceo_empresas e ON e.id = h.empresa
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
        LEFT JOIN ceo_uo u ON u.id = h.uo
        LEFT JOIN ceo_usuarios usr ON usr.id = h.gestor
        WHERE h.id IN (' . implode(', ', $placeholders) . ')';
    if (strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista') {
        $sql .= ' AND h.empresa = :empresa';
        $params[':empresa'] = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $headersById = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $header) {
        $headersById[(int)$header['id']] = $header;
    }
    if (count($headersById) !== count($ids)) {
        throw new OutOfBoundsException('Una de las habilitaciones seleccionadas no existe o no está autorizada.');
    }

    $headers = [];
    $serviceId = null;
    foreach ($ids as $id) {
        $header = $headersById[$id];
        if ($serviceId !== null && $serviceId !== (int)$header['id_servicio']) {
            throw new InvalidArgumentException('Las cuadrillas seleccionadas deben pertenecer al mismo servicio.');
        }
        $serviceId = (int)$header['id_servicio'];
        $headers[] = $header;
    }
    return $headers;
}

function hcaParticipants(PDO $pdo, array $header): array
{
    $sql = '
        SELECT p.rut, p.nombre, p.apellidos, p.cargo,
                (
                    SELECT ep.id_proceso_habilitacion
                    FROM ceo_evaluaciones_programadas ep
                   WHERE REPLACE(REPLACE(REPLACE(UPPER(ep.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = REPLACE(REPLACE(REPLACE(UPPER(p.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\')
                     AND ep.id_servicio = :id_servicio_proceso
                     AND ep.cuadrilla = :cuadrilla_proceso
                   ORDER BY ep.id DESC
                   LIMIT 1
                ) AS id_proceso_habilitacion
        FROM ceo_habilitacion_participantes p
        WHERE p.id_cuadrilla = :cuadrilla
    ';
    $params = [
        ':cuadrilla' => (int)$header['cuadrilla'],
        ':id_servicio_proceso' => (int)$header['id_servicio'],
        ':cuadrilla_proceso' => (int)$header['cuadrilla'],
    ];

    if (strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista') {
        $sql .= '
            AND EXISTS (
                SELECT 1
                FROM ceo_reportewf wf
                WHERE REPLACE(REPLACE(UPPER(wf.rut_empleado), \'.\', \'\'), \' \', \'\') = REPLACE(REPLACE(UPPER(p.rut), \'.\', \'\'), \' \', \'\')
                  AND (
                      UPPER(TRIM(wf.contratista)) = :empresa_wf_exacta
                      OR (
                          CHAR_LENGTH(TRIM(wf.contratista)) >= 4
                          AND CHAR_LENGTH(:empresa_wf_largo) >= 4
                          AND (
                              LOCATE(UPPER(TRIM(wf.contratista)), :empresa_wf_contiene_wf) > 0
                              OR LOCATE(:empresa_wf_contiene_empresa, UPPER(TRIM(wf.contratista))) > 0
                          )
                      )
                  )
            )
        ';
        $empresa = mb_strtoupper(trim((string)$header['empresa']), 'UTF-8');
        $params[':empresa_wf_exacta'] = $empresa;
        $params[':empresa_wf_largo'] = $empresa;
        $params[':empresa_wf_contiene_wf'] = $empresa;
        $params[':empresa_wf_contiene_empresa'] = $empresa;
    }

    $sql .= ' ORDER BY p.apellidos, p.nombre';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hcaLatestResult(PDO $pdo, string $table, array $participant, array $header): ?array
{
    if (!in_array($table, ['ceo_resultado_prueba_intento', 'ceo_resultado_terreno_intento'], true)) {
        throw new InvalidArgumentException('Tabla de resultados no permitida.');
    }

    $sql = "
        SELECT notafinal, fecha_rendicion, hora_rendicion, id_proceso_habilitacion
        FROM {$table}
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
          AND id_servicio = :id_servicio
    ";
    $params = [
        ':rut' => hcaNormalizeRut((string)$participant['rut']),
        ':id_servicio' => (int)$header['id_servicio'],
    ];

    if (!empty($participant['id_proceso_habilitacion'])) {
        $sql .= ' AND id_proceso_habilitacion = :id_proceso';
        $params[':id_proceso'] = (int)$participant['id_proceso_habilitacion'];
    }

    $sql .= ' ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hcaTheoreticalSummary(PDO $pdo, array $header): array
{
    $stmtAreas = $pdo->prepare('
        SELECT ac.id AS id_area, ac.descripcion AS area, cfg.porcentaje AS objetivo
        FROM ceo_areacompetencias ac
        LEFT JOIN ceo_habilitacion_areascompetencias_pct cfg
          ON cfg.id_servicio = ac.id_servicio AND cfg.id_area = ac.id
        WHERE ac.id_servicio = :id_servicio
        ORDER BY ac.descripcion, ac.id
    ');
    $stmtAreas->execute([':id_servicio' => (int)$header['id_servicio']]);
    $areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $participants = [];

    foreach (hcaParticipants($pdo, $header) as $person) {
        $result = hcaLatestResult($pdo, 'ceo_resultado_prueba_intento', $person, $header);
        if (!$result) {
            continue;
        }

        $stmtAttempt = $pdo->prepare('
            SELECT rpt.intento
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps ON ps.id = rpt.id_pregunta AND ps.id_servicio = :id_servicio
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
              AND rpt.proceso = :cuadrilla
            ORDER BY rpt.fecha_rendicion DESC, rpt.hora_rendicion DESC, rpt.id DESC
            LIMIT 1
        ');
        $stmtAttempt->execute([
            ':id_servicio' => (int)$header['id_servicio'],
            ':rut' => hcaNormalizeRut((string)$person['rut']),
            ':cuadrilla' => (int)$header['cuadrilla'],
        ]);
        $attempt = $stmtAttempt->fetchColumn();
        if ($attempt === false) {
            continue;
        }

        $stmtStats = $pdo->prepare('
            SELECT COALESCE(ac.id, 0) AS id_area,
                   SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
                   COUNT(*) AS total
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps ON ps.id = rpt.id_pregunta AND ps.id_servicio = :id_servicio
            LEFT JOIN ceo_areacompetencias ac ON ac.id = ps.areacomp AND ac.id_servicio = ps.id_servicio
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
              AND rpt.proceso = :cuadrilla
              AND rpt.intento = :intento
              AND ps.areacomp IS NOT NULL
            GROUP BY ac.id
        ');
        $stmtStats->execute([
            ':id_servicio' => (int)$header['id_servicio'],
            ':rut' => hcaNormalizeRut((string)$person['rut']),
            ':cuadrilla' => (int)$header['cuadrilla'],
            ':intento' => (int)$attempt,
        ]);

        $personData = [
            'rut' => (string)$person['rut'],
            'empresa' => (string)$header['empresa'],
            'nombre_completo' => trim((string)$person['nombre'] . ' ' . (string)$person['apellidos']),
            'fecha_evaluacion' => date('d/m/Y', strtotime((string)$result['fecha_rendicion'])),
            'nota_final' => number_format((float)$result['notafinal'], 2, ',', '.'),
            'areas' => [],
        ];
        foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $stat) {
            $total = (int)$stat['total'];
            if ($total <= 0) {
                continue;
            }
            $percentage = round(((int)$stat['correctas'] / $total) * 100, 2);
            $area = null;
            foreach ($areas as $candidate) {
                if ((int)$candidate['id_area'] === (int)$stat['id_area']) {
                    $area = $candidate;
                    break;
                }
            }
            $goal = $area !== null && $area['objetivo'] !== null ? (float)$area['objetivo'] : 0.0;
            $personData['areas'][(string)(int)$stat['id_area']] = [
                'nota' => number_format(calcularNotaFinalDesdePorcentaje($percentage, $goal > 0 ? $goal : 80), 2, ',', '.'),
                'porcentaje' => number_format($percentage, 1, '.', ''),
                'aprobada' => $percentage >= 80,
                'correctas' => (int)$stat['correctas'],
                'total' => $total,
            ];
        }
        $participants[] = $personData;
    }

    return ['areas' => $areas, 'participants' => $participants];
}

function hcaTerrainSummary(PDO $pdo, array $header): array
{
    $areas = [];
    $participants = [];
    foreach (hcaParticipants($pdo, $header) as $person) {
        $result = hcaLatestResult($pdo, 'ceo_resultado_terreno_intento', $person, $header);
        if (!$result) {
            continue;
        }

        $stmtStats = $pdo->prepare('
            SELECT s.id AS id_area, COALESCE(NULLIF(TRIM(s.nombre), \'\'), NULLIF(TRIM(s.seccion), \'\'), \'Sin área de competencia\') AS area,
                   SUM(CASE WHEN rpt.no_aplica = 0 THEN COALESCE(p.ponderacion, 0) ELSE 0 END) AS ponderacion_evaluable,
                   SUM(CASE WHEN rpt.cumple = 1 THEN COALESCE(p.ponderacion, 0) ELSE 0 END) AS ponderacion_lograda
            FROM ceo_seccion_resultado_terreno srt
            INNER JOIN ceo_resultado_prueba_terreno rpt ON rpt.id_resultado = srt.id
            INNER JOIN ceo_seccion_terreno s ON s.id = rpt.id_seccion AND s.orden > 1
            INNER JOIN ceo_preguntas_seccion_terreno p ON p.id = rpt.id_pregunta
            WHERE srt.id_servicio = :id_servicio
              AND srt.nsolicitud = :cuadrilla
              AND srt.fecha_examen = :fecha
              AND TIME(srt.hora_examen) = TIME(:hora)
              AND REPLACE(REPLACE(REPLACE(UPPER(rpt.rut_contratista), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
            GROUP BY s.id, area, s.orden
            ORDER BY s.orden, area
        ');
        $stmtStats->execute([
            ':id_servicio' => (int)$header['id_servicio'],
            ':cuadrilla' => (int)$header['cuadrilla'],
            ':fecha' => (string)$result['fecha_rendicion'],
            ':hora' => (string)$result['hora_rendicion'],
            ':rut' => hcaNormalizeRut((string)$person['rut']),
        ]);
        $stats = $stmtStats->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$stats) {
            continue;
        }
        $personData = [
            'rut' => (string)$person['rut'],
            'empresa' => (string)$header['empresa'],
            'nombre_completo' => trim((string)$person['nombre'] . ' ' . (string)$person['apellidos']),
            'fecha_evaluacion' => date('d/m/Y', strtotime((string)$result['fecha_rendicion'])),
            'nota_final' => number_format((float)$result['notafinal'], 2, ',', '.'),
            'areas' => [],
        ];
        foreach ($stats as $stat) {
            $evaluable = (float)$stat['ponderacion_evaluable'];
            $percentage = $evaluable > 0 ? round(((float)$stat['ponderacion_lograda'] / $evaluable) * 100, 2) : 0.0;
            $idArea = (int)$stat['id_area'];
            $areas[$idArea] = ['id_area' => $idArea, 'area' => (string)$stat['area']];
            $personData['areas'][(string)$idArea] = [
                'nota' => number_format(calcularNotaFinalDesdePorcentaje($percentage, 80), 2, ',', '.'),
                'porcentaje' => number_format($percentage, 1, '.', ''),
                'aprobada' => $percentage >= 80,
                'correctas' => number_format((float)$stat['ponderacion_lograda'], 2, '.', ''),
                'total' => number_format($evaluable, 2, '.', ''),
            ];
        }
        $participants[] = $personData;
    }
    return ['areas' => array_values($areas), 'participants' => $participants];
}

function hcaCombinedSummary(PDO $pdo, array $headers, string $type): array
{
    if (!in_array($type, ['teorica', 'terreno'], true)) {
        throw new InvalidArgumentException('Tipo de resumen inválido.');
    }
    $areas = [];
    $participants = [];
    foreach ($headers as $header) {
        $summary = $type === 'teorica' ? hcaTheoreticalSummary($pdo, $header) : hcaTerrainSummary($pdo, $header);
        foreach ($summary['areas'] as $area) {
            $areas[(string)$area['id_area']] = $area;
        }
        foreach ($summary['participants'] as $participant) {
            $participant['cuadrilla'] = (string)$header['cuadrilla'];
            $participants[] = $participant;
        }
    }
    return ['areas' => array_values($areas), 'participants' => $participants];
}

function hcaExcelColumn(int $column): string
{
    $letters = '';
    while ($column > 0) {
        $column--;
        $letters = chr(65 + ($column % 26)) . $letters;
        $column = intdiv($column, 26);
    }
    return $letters;
}

function hcaExportSummary(array $header, array $summary, string $type, ?array $selectedHeaders = null): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($type === 'teorica' ? 'Areas teoricas' : 'Areas terreno');
    $multiple = $selectedHeaders !== null && count($selectedHeaders) > 1;
    $headers = $multiple ? ['Cuadrilla', 'RUT', 'Empresa', 'Nombre / Apellidos', 'Fecha de evaluación', 'Nota'] : ['RUT', 'Empresa', 'Nombre / Apellidos', 'Fecha de evaluación', 'Nota'];
    foreach ($summary['areas'] as $area) {
        $headers[] = (string)$area['area'];
    }
    $lastColumn = hcaExcelColumn(count($headers));
    $meta = [
        ['Evaluación', $type === 'teorica' ? 'Áreas de competencia teóricas' : 'Áreas de competencia de terreno'],
        ['Servicio', (string)$header['servicio']],
        ['Cuadrilla', $multiple ? implode(', ', array_map(static fn(array $item): string => (string)$item['cuadrilla'], $selectedHeaders)) : (string)$header['cuadrilla']],
        ['Jornada', $multiple ? 'Varias' : (string)$header['jornada']],
    ];
    $sheet->fromArray($meta, null, 'A1');
    $sheet->mergeCells('B1:' . $lastColumn . '1');
    $sheet->fromArray([$headers], null, 'A6');
    $sheet->getStyle('A1:A4')->getFont()->setBold(true);
    $sheet->getStyle('A6:' . $lastColumn . '6')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '17324D']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EAF4FB']],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
    ]);
    $row = 7;
    foreach ($summary['participants'] as $participant) {
        $values = [$participant['rut'], $participant['empresa'], $participant['nombre_completo'], $participant['fecha_evaluacion'], $participant['nota_final']];
        if ($multiple) {
            array_unshift($values, $participant['cuadrilla']);
        }
        $sheet->fromArray([$values], null, 'A' . $row);
        $column = $multiple ? 7 : 6;
        foreach ($summary['areas'] as $area) {
            $cell = hcaExcelColumn($column) . $row;
            $data = $participant['areas'][(string)$area['id_area']] ?? null;
            if ($data) {
                $sheet->setCellValue($cell, $data['nota']);
                $sheet->getStyle($cell)->applyFromArray([
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $data['aprobada'] ? 'D1E7DD' : 'F8D7DA']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);
            }
            $column++;
        }
        $row++;
    }
    $lastRow = max(6, $row - 1);
    $sheet->getStyle('A6:' . $lastColumn . $lastRow)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'D5DDE5']]],
        'alignment' => ['vertical' => 'center'],
    ]);
    for ($i = 1; $i <= count($headers); $i++) {
        $sheet->getColumnDimension(hcaExcelColumn($i))->setAutoSize(true);
    }
    $filename = 'habilitacion_cuadrilla_' . $type . '_areas_' . ($multiple ? 'consolidado' : (int)$header['cuadrilla']) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}
