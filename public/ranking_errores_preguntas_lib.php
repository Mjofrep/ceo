<?php
declare(strict_types=1);

function repNormalizeRut(string $rut): string
{
    return str_replace(['.', '-', ' '], '', strtoupper(trim($rut)));
}

function repFetchServices(PDO $pdo, string $modality): array
{
    $table = $modality === 'formacion' ? 'ceo_formacion_servicios' : 'ceo_servicios_pruebas';
    return $pdo->query("SELECT id, servicio FROM {$table} ORDER BY servicio ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repBuildTerrainRanking(PDO $pdo, int $serviceId, int $companyId = 0): array
{
    $stmtService = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
    $stmtService->execute([':id' => $serviceId]);
    $service = (string)$stmtService->fetchColumn();
    if ($service === '') {
        throw new OutOfBoundsException('Servicio no encontrado.');
    }

    $companyFilter = '';
    $params = [':id_servicio' => $serviceId];
    if ($companyId > 0) {
        $companyFilter = ' AND srt.id_empresa = :id_empresa';
        $params[':id_empresa'] = $companyId;
    }

    $stmt = $pdo->prepare("
        SELECT
            srt.id AS id_resultado,
            srt.fecha_examen,
            srt.hora_examen,
            rpt.rut_contratista AS rut,
            rpt.id_pregunta,
            rpt.cumple,
            rpt.no_cumple,
            rpt.no_aplica,
            p.pregunta,
            COALESCE(p.ponderacion, 0) AS ponderacion,
            COALESCE(NULLIF(TRIM(s.nombre), ''), NULLIF(TRIM(s.seccion), ''), 'Sin área de competencia') AS area_competencia
        FROM ceo_seccion_resultado_terreno srt
        INNER JOIN ceo_resultado_prueba_terreno rpt ON rpt.id_resultado = srt.id
        INNER JOIN ceo_seccion_terreno s ON s.id = rpt.id_seccion AND s.orden > 1
        INNER JOIN ceo_preguntas_seccion_terreno p ON p.id = rpt.id_pregunta
        WHERE srt.id_servicio = :id_servicio {$companyFilter}
        ORDER BY
            REPLACE(REPLACE(REPLACE(UPPER(rpt.rut_contratista), '.', ''), '-', ''), ' ', '') ASC,
            srt.fecha_examen DESC,
            TIME(srt.hora_examen) DESC,
            srt.id DESC
    ");
    $stmt->execute($params);

    $latestResultByRut = [];
    $ranking = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rut = repNormalizeRut((string)$row['rut']);
        if ($rut === '') {
            continue;
        }

        $resultId = (int)$row['id_resultado'];
        if (!isset($latestResultByRut[$rut])) {
            $latestResultByRut[$rut] = $resultId;
        }
        if ($latestResultByRut[$rut] !== $resultId) {
            continue;
        }

        $questionId = (int)$row['id_pregunta'];
        if (!isset($ranking[$questionId])) {
            $ranking[$questionId] = [
                'id_pregunta' => $questionId,
                'pregunta' => (string)$row['pregunta'],
                'area_competencia' => (string)$row['area_competencia'],
                'ponderacion' => (float)$row['ponderacion'],
                'correctas' => 0,
                'incorrectas' => 0,
                'no_aplica' => 0,
            ];
        }

        if ((int)$row['no_aplica'] === 1) {
            $ranking[$questionId]['no_aplica']++;
        } elseif ((int)$row['cumple'] === 1) {
            $ranking[$questionId]['correctas']++;
        } elseif ((int)$row['no_cumple'] === 1) {
            $ranking[$questionId]['incorrectas']++;
        }
    }

    $rows = [];
    foreach ($ranking as $row) {
        $answered = $row['correctas'] + $row['incorrectas'];
        if ($row['incorrectas'] <= 0 || $answered <= 0) {
            continue;
        }
        $row['respondidas'] = $answered;
        $row['no_contestadas'] = $row['no_aplica'];
        $row['porcentaje_error'] = round(($row['incorrectas'] / $answered) * 100, 1);
        $rows[] = $row;
    }

    usort($rows, static function (array $a, array $b): int {
        return $b['incorrectas'] <=> $a['incorrectas']
            ?: $b['porcentaje_error'] <=> $a['porcentaje_error']
            ?: strcmp($a['pregunta'], $b['pregunta']);
    });
    foreach ($rows as $index => &$row) {
        $row['ranking'] = $index + 1;
    }
    unset($row);

    return [
        'servicio' => $service,
        'rows' => $rows,
        'participantes' => count($latestResultByRut),
        'is_terrain' => true,
    ];
}

function repBuildRanking(PDO $pdo, string $modality, int $serviceId, int $companyId = 0, string $type = 'teorica'): array
{
    if (!in_array($modality, ['habilitacion', 'formacion'], true) || $serviceId <= 0) {
        throw new InvalidArgumentException('Parámetros de consulta inválidos.');
    }
    if (!in_array($type, ['teorica', 'terreno'], true) || ($modality === 'formacion' && $type === 'terreno')) {
        throw new InvalidArgumentException('Tipo de evaluación inválido.');
    }
    if ($type === 'terreno') {
        return repBuildTerrainRanking($pdo, $serviceId, $companyId);
    }

    $isTraining = $modality === 'formacion';
    $resultsTable = $isTraining ? 'ceo_resultado_formacion_pruebat' : 'ceo_resultado_pruebat';
    $questionsTable = $isTraining ? 'ceo_formacion_preguntas_servicios' : 'ceo_preguntas_servicios';
    $areasTable = $isTraining ? 'ceo_areacompetencia_formacion' : 'ceo_areacompetencias';
    $serviceTable = $isTraining ? 'ceo_formacion_servicios' : 'ceo_servicios_pruebas';
    $crewTable = $isTraining ? 'ceo_formacion' : 'ceo_habilitacion';

    $stmtService = $pdo->prepare("SELECT servicio FROM {$serviceTable} WHERE id = :id LIMIT 1");
    $stmtService->execute([':id' => $serviceId]);
    $service = (string)$stmtService->fetchColumn();
    if ($service === '') {
        throw new OutOfBoundsException('Servicio no encontrado.');
    }

    $companyFilter = '';
    $params = [':id_servicio' => $serviceId];
    if ($companyId > 0) {
        $companyFilter = "
          AND EXISTS (
              SELECT 1
              FROM {$crewTable} c
              WHERE c.cuadrilla = rpt.proceso
                AND c.id_servicio = ps.id_servicio
                AND c.empresa = :id_empresa
          )";
        $params[':id_empresa'] = $companyId;
    }

    $stmt = $pdo->prepare("
        SELECT
            rpt.rut,
            rpt.proceso,
            rpt.intento,
            rpt.validacion,
            rpt.respuesta,
            rpt.fecha_rendicion,
            rpt.hora_rendicion,
            rpt.id AS id_resultado,
            ps.id AS id_pregunta,
            ps.pregunta,
            COALESCE(ac.descripcion, 'Sin área de competencia') AS area_competencia
        FROM {$resultsTable} rpt
        INNER JOIN {$questionsTable} ps
            ON ps.id = rpt.id_pregunta
           AND ps.id_servicio = :id_servicio
        LEFT JOIN {$areasTable} ac
            ON ac.id = ps.areacomp
           AND ac.id_servicio = ps.id_servicio
        WHERE 1 = 1 {$companyFilter}
        ORDER BY
            REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), '.', ''), '-', ''), ' ', '') ASC,
            rpt.fecha_rendicion DESC,
            rpt.hora_rendicion DESC,
            rpt.id DESC
    ");
    $stmt->execute($params);

    $latestAttemptByRut = [];
    $ranking = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rut = repNormalizeRut((string)$row['rut']);
        if ($rut === '') {
            continue;
        }

        $attemptKey = (string)(int)$row['proceso'] . '|' . (string)(int)$row['intento'];
        if (!isset($latestAttemptByRut[$rut])) {
            $latestAttemptByRut[$rut] = $attemptKey;
        }
        if ($latestAttemptByRut[$rut] !== $attemptKey) {
            continue;
        }

        $questionId = (int)$row['id_pregunta'];
        if (!isset($ranking[$questionId])) {
            $ranking[$questionId] = [
                'pregunta' => (string)$row['pregunta'],
                'area_competencia' => (string)$row['area_competencia'],
                'correctas' => 0,
                'incorrectas' => 0,
                'no_contestadas' => 0,
                'selecciones' => [],
            ];
        }

        $validation = (int)$row['validacion'];
        if ($validation === 1) {
            $ranking[$questionId]['correctas']++;
        } elseif ($validation === 0) {
            $ranking[$questionId]['incorrectas']++;
        } elseif ($validation === -1) {
            $ranking[$questionId]['no_contestadas']++;
        }

        $answerId = (int)($row['respuesta'] ?? 0);
        if ($answerId > 0) {
            $ranking[$questionId]['selecciones'][$answerId] = ($ranking[$questionId]['selecciones'][$answerId] ?? 0) + 1;
        }
    }

    if ($ranking) {
        $questionIds = array_keys($ranking);
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $alternativesTable = $isTraining ? 'ceo_formacion_alternativas_preguntas' : 'ceo_alternativas_preguntas';
        $stmtAlternatives = $pdo->prepare("
            SELECT id, id_pregunta, alternativa, correcta
            FROM {$alternativesTable}
            WHERE id_pregunta IN ({$placeholders})
            ORDER BY id_pregunta ASC, id ASC
        ");
        $stmtAlternatives->execute($questionIds);

        foreach ($stmtAlternatives->fetchAll(PDO::FETCH_ASSOC) as $alternative) {
            $questionId = (int)$alternative['id_pregunta'];
            if (!isset($ranking[$questionId])) {
                continue;
            }
            $alternativeId = (int)$alternative['id'];
            $label = (string)$alternative['alternativa'];
            $ranking[$questionId]['alternativas'][] = ($alternative['correcta'] === 'S' ? '[Correcta] ' : '') . $label;
            $ranking[$questionId]['alternativas_por_id'][$alternativeId] = $label;
        }
    }

    $rows = [];
    foreach ($ranking as $questionId => $row) {
        $answered = $row['correctas'] + $row['incorrectas'];
        if ($row['incorrectas'] <= 0 || $answered <= 0) {
            continue;
        }
        $row['id_pregunta'] = $questionId;
        $row['respondidas'] = $answered;
        $row['porcentaje_error'] = round(($row['incorrectas'] / $answered) * 100, 1);
        $row['alternativas'] = implode(' | ', $row['alternativas'] ?? []);
        $selectedAlternativeId = 0;
        $selectedCount = 0;
        foreach ($row['selecciones'] as $alternativeId => $count) {
            if ($count > $selectedCount) {
                $selectedAlternativeId = (int)$alternativeId;
                $selectedCount = $count;
            }
        }
        $selectedAlternative = (string)($row['alternativas_por_id'][$selectedAlternativeId] ?? 'Sin selección');
        $row['alternativa_habitual'] = $selectedCount > 0 ? $selectedAlternative . ' (' . $selectedCount . ')' : $selectedAlternative;
        unset($row['selecciones'], $row['alternativas_por_id']);
        $rows[] = $row;
    }

    usort($rows, static function (array $a, array $b): int {
        return $b['incorrectas'] <=> $a['incorrectas']
            ?: $b['porcentaje_error'] <=> $a['porcentaje_error']
            ?: strcmp($a['pregunta'], $b['pregunta']);
    });

    foreach ($rows as $index => &$row) {
        $row['ranking'] = $index + 1;
    }
    unset($row);

    return [
        'servicio' => $service,
        'rows' => $rows,
        'participantes' => count($latestAttemptByRut),
        'is_terrain' => false,
    ];
}
