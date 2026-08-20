<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/informe_habilitaciones_empresa_lib.php';

if (!function_exists('ihgNormalizeHabilitadoFilter')) {
    function ihgNormalizeHabilitadoFilter(?string $value): string
    {
        $normalized = iheNormalizeText($value);
        $allowed = ['SI', 'NO', 'PENDIENTE'];
        return in_array($normalized, $allowed, true) ? $normalized : '';
    }
}

if (!function_exists('ihgFetchCompanies')) {
    function ihgFetchCompanies(PDO $pdo): array
    {
        return iheFetchEmpresas($pdo);
    }
}

if (!function_exists('ihgFetchServices')) {
    function ihgFetchServices(PDO $pdo): array
    {
        return iheFetchServicios($pdo);
    }
}

if (!function_exists('ihgResolveSelectedCompanyId')) {
    function ihgResolveSelectedCompanyId(array $sessionAuth, int $requestedEmpresaId): int
    {
        return iheResolveEmpresaSeleccionada($sessionAuth, $requestedEmpresaId);
    }
}

if (!function_exists('ihgFetchContractorsByRut')) {
    function ihgFetchContractorsByRut(PDO $pdo, int $empresaId = 0): array
    {
        $sql = "
            SELECT
                c.rut,
                c.nombre,
                c.apellidos,
                c.id_cargo,
                cc.cargo,
                c.id_empresa,
                e.nombre AS empresa,
                uo.desc_uo AS uo
            FROM ceo_contratistas c
            LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
            LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
            LEFT JOIN ceo_uo uo ON uo.id = c.uo
        ";

        $params = [];
        if ($empresaId > 0) {
            $sql .= ' WHERE c.id_empresa = :empresa';
            $params[':empresa'] = $empresaId;
        }

        $sql .= ' ORDER BY c.apellidos ASC, c.nombre ASC, c.rut ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byRut = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)($row['rut'] ?? ''));
            if ($rutKey === '') {
                continue;
            }
            $byRut[$rutKey] = $row;
        }

        $companyRows = iheFetchEmpresas($pdo);
        $companiesByNormalizedName = [];
        foreach ($companyRows as $companyRow) {
            $companyName = trim((string)($companyRow['nombre'] ?? ''));
            $companyKey = iheNormalizeText($companyName);
            if ($companyKey === '' || isset($companiesByNormalizedName[$companyKey])) {
                continue;
            }
            $companiesByNormalizedName[$companyKey] = [
                'id' => (int)($companyRow['id'] ?? 0),
                'nombre' => $companyName,
            ];
        }

        $selectedCompanyName = '';
        if ($empresaId > 0) {
            foreach ($companyRows as $companyRow) {
                if ((int)($companyRow['id'] ?? 0) !== $empresaId) {
                    continue;
                }
                $selectedCompanyName = trim((string)($companyRow['nombre'] ?? ''));
                break;
            }
        }

        $stmtFallback = $pdo->query(" 
            SELECT rut, nombre, cargo, contratista
            FROM ceo_evaluacion_terreno
            WHERE NULLIF(TRIM(COALESCE(nombre, '')), '') IS NOT NULL
            ORDER BY fecha_evaluacion DESC, id DESC
        ");

        $stmtContractorByRut = $pdo->prepare("
            SELECT
                c.rut,
                c.nombre,
                c.apellidos,
                c.id_cargo,
                cc.cargo,
                c.id_empresa,
                e.nombre AS empresa,
                uo.desc_uo AS uo
            FROM ceo_contratistas c
            LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
            LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
            LEFT JOIN ceo_uo uo ON uo.id = c.uo
            WHERE REPLACE(REPLACE(REPLACE(UPPER(c.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(:rut), '.', ''), '-', ''), ' ', '')
            ORDER BY c.id DESC
            LIMIT 1
        ");

        foreach ($stmtFallback->fetchAll(PDO::FETCH_ASSOC) as $fallbackRow) {
            $rutKey = iheNormalizeRut((string)($fallbackRow['rut'] ?? ''));
            if ($rutKey === '' || isset($byRut[$rutKey])) {
                continue;
            }

            $terrainContratista = trim((string)($fallbackRow['contratista'] ?? ''));
            if ($empresaId > 0 && ($terrainContratista === '' || !iheCompanyNamesMatch($terrainContratista, $selectedCompanyName))) {
                continue;
            }

            $stmtContractorByRut->execute([':rut' => (string)($fallbackRow['rut'] ?? '')]);
            $contractorRow = $stmtContractorByRut->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($contractorRow !== null) {
                $nombre = trim((string)($contractorRow['nombre'] ?? ''));
                $apellidos = trim((string)($contractorRow['apellidos'] ?? ''));
            } else {
                [$nombre, $apellidos] = iheSplitFullName((string)($fallbackRow['nombre'] ?? ''));
            }

            $cargo = trim((string)($contractorRow['cargo'] ?? ''));
            if ($cargo === '') {
                $cargo = trim((string)($fallbackRow['cargo'] ?? ''));
            }

            $resolvedCompanyId = isset($contractorRow['id_empresa']) ? (int)$contractorRow['id_empresa'] : 0;
            $resolvedCompanyName = trim((string)($contractorRow['empresa'] ?? ''));
            if ($empresaId > 0) {
                $resolvedCompanyId = $empresaId;
                $resolvedCompanyName = $selectedCompanyName !== '' ? $selectedCompanyName : $terrainContratista;
            } elseif ($terrainContratista !== '') {
                foreach ($companiesByNormalizedName as $companyKey => $companyMeta) {
                    if (!iheCompanyNamesMatch($terrainContratista, $companyMeta['nombre'])) {
                        continue;
                    }
                    $resolvedCompanyId = (int)$companyMeta['id'];
                    $resolvedCompanyName = (string)$companyMeta['nombre'];
                    break;
                }
                if ($resolvedCompanyName === '') {
                    $resolvedCompanyName = $terrainContratista;
                }
            }

            $byRut[$rutKey] = [
                'rut' => (string)($fallbackRow['rut'] ?? ''),
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'id_cargo' => isset($contractorRow['id_cargo']) ? (int)$contractorRow['id_cargo'] : null,
                'cargo' => $cargo,
                'id_empresa' => $resolvedCompanyId,
                'empresa' => $resolvedCompanyName,
                'uo' => trim((string)($contractorRow['uo'] ?? '')),
            ];
        }

        return $byRut;
    }
}

if (!function_exists('ihgFetchSageByRut')) {
    function ihgFetchSageByRut(PDO $pdo): array
    {
        $stmt = $pdo->query(" 
            SELECT rut_empleado, mandante, contratista
            FROM ceo_reportewf
            ORDER BY id DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byRut = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)($row['rut_empleado'] ?? ''));
            if ($rutKey === '' || isset($byRut[$rutKey])) {
                continue;
            }

            $byRut[$rutKey] = [
                'mandante' => trim((string)($row['mandante'] ?? '')),
                'contratista' => trim((string)($row['contratista'] ?? '')),
            ];
        }

        return $byRut;
    }
}

if (!function_exists('ihgNormalizeThreshold')) {
    function ihgNormalizeThreshold(float $value): float
    {
        return ($value > 0.0 && $value <= 100.0) ? $value : 80.0;
    }
}

if (!function_exists('ihgLoadLatestTheoryAreasByRut')) {
    function ihgLoadLatestTheoryAreasByRut(PDO $pdo, int $idServicio, array $theoryRows): array
    {
        if (empty($theoryRows)) {
            return [];
        }

        $targetsByRut = [];
        foreach ($theoryRows as $rutKey => $theoryRow) {
            $rutKey = iheNormalizeRut((string)$rutKey);
            if ($rutKey === '' || !is_array($theoryRow)) {
                continue;
            }

            $fecha = null;
            $hora = null;
            if (($theoryRow['fecha'] ?? null) instanceof DateTimeImmutable) {
                $fecha = $theoryRow['fecha']->format('Y-m-d');
                $hora = $theoryRow['fecha']->format('H:i:s');
            }

            $targetsByRut[$rutKey] = [
                'proceso' => isset($theoryRow['id_proceso_habilitacion']) ? (int)$theoryRow['id_proceso_habilitacion'] : 0,
                'fecha' => $fecha,
                'hora' => $hora,
            ];
        }

        if (empty($targetsByRut)) {
            return [];
        }

        $stmtAreas = $pdo->prepare(" 
            SELECT
                rpt.rut,
                rpt.proceso,
                rpt.intento,
                rpt.fecha_rendicion,
                rpt.hora_rendicion,
                COALESCE(ac.descripcion, 'Sin area de competencia') AS area,
                cfg.porcentaje AS objetivo,
                SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
                COUNT(*) AS total
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps
                ON ps.id = rpt.id_pregunta
               AND ps.id_servicio = :id_servicio
            LEFT JOIN ceo_areacompetencias ac
                ON ac.id = ps.areacomp
               AND ac.id_servicio = ps.id_servicio
            LEFT JOIN ceo_habilitacion_areascompetencias_pct cfg
                ON cfg.id_servicio = ps.id_servicio
               AND cfg.id_area = ps.areacomp
            GROUP BY rpt.rut, rpt.proceso, rpt.intento, rpt.fecha_rendicion, rpt.hora_rendicion, COALESCE(ac.descripcion, 'Sin area de competencia'), cfg.porcentaje
            ORDER BY area ASC
        ");
        $stmtAreas->execute([':id_servicio' => $idServicio]);

        $result = [];
        foreach ($stmtAreas->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if (!isset($targetsByRut[$rutKey])) {
                continue;
            }

            $target = $targetsByRut[$rutKey];
            $rowProceso = isset($row['proceso']) ? (int)$row['proceso'] : 0;
            if (($target['proceso'] ?? 0) > 0) {
                if ($rowProceso !== (int)$target['proceso']) {
                    continue;
                }
            } elseif (
                ($target['fecha'] ?? null) === null
                || ($target['hora'] ?? null) === null
                || (string)($row['fecha_rendicion'] ?? '') !== (string)$target['fecha']
                || (string)($row['hora_rendicion'] ?? '') !== (string)$target['hora']
            ) {
                continue;
            }

            $total = (int)($row['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }

            $correctas = (int)($row['correctas'] ?? 0);
            $porcentaje = round(($correctas / $total) * 100, 2);
            $threshold = ihgNormalizeThreshold($row['objetivo'] !== null ? (float)$row['objetivo'] : 80.0);
            $note = calcularNotaFinalDesdePorcentaje($porcentaje, $threshold);
            $result[$rutKey][iheNormalizeText((string)$row['area'])] = round($note, 2);
        }

        return $result;
    }
}

if (!function_exists('ihgNormalizeDatasetKey')) {
    function ihgNormalizeDatasetKey(?string $value): string
    {
        $key = strtolower(trim((string)$value));
        return in_array($key, ['data1', 'data2', 'data3'], true) ? $key : 'data1';
    }
}

if (!function_exists('ihgLoadTerrainSectionColumns')) {
    function ihgLoadTerrainSectionColumns(PDO $pdo, int $idServicio): array
    {
        $stmt = $pdo->prepare(" 
            SELECT DISTINCT
                COALESCE(NULLIF(TRIM(s.nombre), ''), NULLIF(TRIM(s.seccion), '')) AS area
            FROM ceo_agrupacion_terreno a
            INNER JOIN ceo_seccion_terreno s
                ON s.id_grupo = a.id
               AND s.orden > 1
            WHERE a.id_servicio = :id_servicio
            ORDER BY s.orden ASC, area ASC
        ");
        $stmt->execute([':id_servicio' => $idServicio]);

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $areaKey = iheNormalizeText((string)($row['area'] ?? ''));
            if ($areaKey === '' || isset($columns[$areaKey])) {
                continue;
            }
            $columns[$areaKey] = true;
        }

        return array_keys($columns);
    }
}

if (!function_exists('ihgBuildBaseRow')) {
    function ihgBuildBaseRow(int $serviceId, string $serviceName, array $contractorsByRut, array $sageByRut, string $rutKey, ?array $teo, ?array $terr, array $theoryAreas, array $terrainBreakdown): ?array
    {
        if ($teo === null && $terr === null) {
            return null;
        }

        $contractor = $contractorsByRut[$rutKey] ?? null;
        $sage = $sageByRut[$rutKey] ?? null;
        $cargoTerreno = trim((string)($terr['cargo'] ?? ''));
        $cargoTeorica = trim((string)($teo['cargo'] ?? ''));
        $cargoContratista = trim((string)($contractor['cargo'] ?? ''));
        $cargo = $cargoTerreno !== '' ? $cargoTerreno : ($cargoTeorica !== '' ? $cargoTeorica : $cargoContratista);

        $pesos = iheResolvePesosPorCargoServicio($cargo, isset($contractor['id_cargo']) ? (int)$contractor['id_cargo'] : null);
        $notaFinal = null;
        if ($pesos !== null && isset($teo['nota'], $terr['nota']) && $teo['nota'] !== null && $terr['nota'] !== null) {
            $notaFinal = round((((float)$teo['nota']) * $pesos['teorica']) + (((float)$terr['nota']) * $pesos['terreno']), 2);
        }

        $ultimaEvaluacion = null;
        if (($terr['fecha'] ?? null) instanceof DateTimeImmutable) {
            $ultimaEvaluacion = $terr['fecha'];
        } elseif (($teo['fecha'] ?? null) instanceof DateTimeImmutable) {
            $ultimaEvaluacion = $teo['fecha'];
        }

        $habilitado = 'Pendiente';
        if ($notaFinal !== null) {
            $habilitado = $notaFinal >= 4.0 ? 'SI' : 'NO';
        }

        $vigenciaHasta = null;
        if (($terr['fecha'] ?? null) instanceof DateTimeImmutable && $notaFinal !== null && $notaFinal >= 4.0) {
            $vigenciaHasta = $terr['fecha']->modify('+3 years');
        }

        $estado = '';
        $today = new DateTimeImmutable('today');
        if ($habilitado === 'SI') {
            $estado = ($vigenciaHasta instanceof DateTimeImmutable && $vigenciaHasta < $today) ? 'NO Vigente' : 'Habilitado';
        } elseif ($habilitado === 'NO') {
            $estado = 'No Habilitado';
        } else {
            $estado = 'Pendiente';
        }

        $empresaHistorica = trim((string)($terr['contratista'] ?? ''));
        if ($empresaHistorica === '') {
            $empresaHistorica = trim((string)($terr['empresa_historica'] ?? ''));
        }
        if ($empresaHistorica === '' && $teo !== null) {
            $empresaHistorica = trim((string)($teo['empresa_historica'] ?? ''));
        }

        $rut = (string)($contractor['rut'] ?? ($terr['rut'] ?? ($teo['rut'] ?? '')));
        $nombre = trim((string)($contractor['nombre'] ?? ''));
        $apellidos = trim((string)($contractor['apellidos'] ?? ''));
        if (($nombre === '' || $apellidos === '') && trim((string)($terr['nombre'] ?? '')) !== '') {
            [$nombreTerreno, $apellidosTerreno] = iheSplitFullName((string)$terr['nombre']);
            if ($nombre === '') {
                $nombre = $nombreTerreno;
            }
            if ($apellidos === '') {
                $apellidos = $apellidosTerreno;
            }
        }

        return [
            'rut_key' => $rutKey,
            'rut' => $rut,
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'cargo' => $cargo,
            'empresa' => $empresaHistorica !== '' ? $empresaHistorica : trim((string)($contractor['empresa'] ?? '')),
            'empresa_id' => isset($contractor['id_empresa']) ? (int)$contractor['id_empresa'] : 0,
            'uo' => trim((string)($contractor['uo'] ?? '')),
            'teorica' => $teo,
            'terreno' => $terr,
            'nota_final' => $notaFinal,
            'habilitado' => $habilitado,
            'estado' => $estado,
            'vigencia_hasta' => $vigenciaHasta,
            'ultima_evaluacion' => $ultimaEvaluacion,
            'numero_proceso' => $terr['numero_proceso'] ?? ($teo['numero_proceso'] ?? null),
            'theory_areas' => $theoryAreas[$rutKey] ?? [],
            'terrain_areas' => $terrainBreakdown[$rutKey]['areas'] ?? [],
            'terrain_items' => $terrainBreakdown[$rutKey]['items'] ?? [],
            'service_name' => $serviceName,
            'service_id' => $serviceId,
            'pesos' => $pesos,
            'sage_contratista' => trim((string)($sage['contratista'] ?? '')),
            'sage_mandante' => trim((string)($sage['mandante'] ?? '')),
            'sage' => $sage !== null ? 'SI' : 'NO',
        ];
    }
}

if (!function_exists('ihgRowMatchesSearch')) {
    function ihgRowMatchesSearch(array $row, string $searchTerm): bool
    {
        $needle = iheNormalizeText($searchTerm);
        if ($needle === '') {
            return true;
        }

        foreach (['rut', 'nombre', 'apellidos', 'cargo', 'empresa', 'service_name', 'estado', 'habilitado', 'uo'] as $field) {
            $value = iheNormalizeText((string)($row[$field] ?? ''));
            if ($value !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ihgBuildData1Rows')) {
    function ihgBuildData1Rows(array $baseRows): array
    {
        $rows = [];
        foreach ($baseRows as $row) {
            $teo = $row['teorica'] ?? null;
            $terr = $row['terreno'] ?? null;

            $rows[] = [
                'RUT' => (string)$row['rut'],
                'Nombres' => (string)$row['nombre'],
                'Apellidos' => (string)$row['apellidos'],
                'Empresas' => (string)$row['empresa'],
                'Servicio' => (string)$row['service_name'],
                'Cargos' => (string)$row['cargo'],
                'Aprobación Prueba' => (string)($teo['aprobacion'] ?? 'Pendiente'),
                'NOTA' => iheTrimmedNumber(isset($teo['nota']) ? (float)$teo['nota'] : null, 1),
                'Aprobación Terreno' => (string)($terr['aprobacion'] ?? 'Pendiente'),
                'NOTA TERRENO' => iheTrimmedNumber(isset($terr['nota']) ? (float)$terr['nota'] : null, 1),
                'Nota Ponderada' => iheTrimmedNumber(isset($row['nota_final']) ? (float)$row['nota_final'] : null, 1),
                'Habilitado' => (string)$row['habilitado'],
                'Fecha de Evaluación' => iheFmtDate($row['ultima_evaluacion'] ?? null),
                'Fecha Prueba' => iheFmtDate($teo['fecha'] ?? null),
                'Contratista' => (string)($row['sage_contratista'] ?? ''),
                'Mandante' => (string)($row['sage_mandante'] ?? ''),
                'SAGE' => (string)($row['sage'] ?? 'NO'),
                'Estado' => (string)$row['estado'],
            ];
        }

        return $rows;
    }
}

if (!function_exists('ihgFormatTruncatedDecimal')) {
    function ihgFormatTruncatedDecimal(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '';
        }

        $factor = 10 ** max(0, $decimals);
        $truncated = $value >= 0
            ? floor($value * $factor) / $factor
            : ceil($value * $factor) / $factor;

        return rtrim(rtrim(number_format($truncated, $decimals, '.', ''), '0'), '.');
    }
}

if (!function_exists('ihgBuildData2Rows')) {
    function ihgBuildData2Rows(array $baseRows): array
    {
        $columns = ['RUT', 'Servicio'];
        $seenColumns = [
            'RUT' => true,
            'Servicio' => true,
        ];

        foreach ($baseRows as $row) {
            foreach (array_keys($row['theory_areas'] ?? []) as $areaKey) {
                $column = trim((string)$areaKey);
                if ($column === '' || isset($seenColumns[$column])) {
                    continue;
                }
                $seenColumns[$column] = true;
                $columns[] = $column;
            }
        }

        $rows = [];
        foreach ($baseRows as $row) {
            if (($row['teorica'] ?? null) === null) {
                continue;
            }

            $areas = $row['theory_areas'] ?? [];

            $exportRow = array_fill_keys($columns, '');
            $exportRow['RUT'] = (string)$row['rut'];
            $exportRow['Servicio'] = (string)$row['service_name'];

            foreach ($areas as $areaKey => $areaValue) {
                $column = trim((string)$areaKey);
                if ($column === '' || !array_key_exists($column, $exportRow)) {
                    continue;
                }
                $exportRow[$column] = iheTrimmedNumber((float)$areaValue, 1);
            }

            $rows[] = $exportRow;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('ihgBuildData3Rows')) {
    function ihgBuildData3Rows(array $baseRows, array $orderedAreaColumns = []): array
    {
        $columns = ['RUT', 'Checklist'];
        $seenColumns = [
            'RUT' => true,
            'Checklist' => true,
        ];

        foreach ($orderedAreaColumns as $areaKey) {
            $column = trim((string)$areaKey);
            if ($column === '' || isset($seenColumns[$column])) {
                continue;
            }
            $seenColumns[$column] = true;
            $columns[] = $column;
        }

        if (empty($orderedAreaColumns)) {
            foreach ($baseRows as $row) {
                foreach (array_keys($row['terrain_areas'] ?? []) as $areaKey) {
                    $column = trim((string)$areaKey);
                    if ($column === '' || isset($seenColumns[$column])) {
                        continue;
                    }
                    $seenColumns[$column] = true;
                    $columns[] = $column;
                }
            }
        }

        $rows = [];
        foreach ($baseRows as $row) {
            $areas = $row['terrain_areas'] ?? [];
            if (empty($areas)) {
                continue;
            }

            $exportRow = array_fill_keys($columns, '');
            $exportRow['RUT'] = (string)$row['rut'];
            $exportRow['Checklist'] = ihgResolveChecklistLabel($row);

            foreach ($areas as $areaKey => $areaValue) {
                $column = trim((string)$areaKey);
                if ($column === '' || !array_key_exists($column, $exportRow)) {
                    continue;
                }
                $exportRow[$column] = iheTrimmedNumber((float)$areaValue, 1);
            }

            $rows[] = $exportRow;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('ihgResolveChecklistLabel')) {
    function ihgResolveChecklistLabel(array $row): string
    {
        $serviceName = trim((string)($row['service_name'] ?? ''));
        $definitions = iheGetSheetDefinitions();
        foreach ($definitions as $definition) {
            if (($definition['mode'] ?? '') !== 'standard') {
                continue;
            }
            $aliases = $definition['aliases'] ?? [];
            if (!is_array($aliases) || !iheServiceMatchesAliases($serviceName, $aliases)) {
                continue;
            }

            $label = trim((string)($definition['cuadrilla_label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return $serviceName;
    }
}

if (!function_exists('ihgBuildSummary')) {
    function ihgBuildSummary(array $baseRows): array
    {
        $summary = [
            'TOTAL' => count($baseRows),
            'SI' => 0,
            'NO' => 0,
            'PENDIENTE' => 0,
            'SERVICIOS' => 0,
            'EMPRESAS' => 0,
        ];

        $services = [];
        $companies = [];
        foreach ($baseRows as $row) {
            $status = (string)($row['habilitado'] ?? 'Pendiente');
            if ($status === 'SI') {
                $summary['SI']++;
            } elseif ($status === 'NO') {
                $summary['NO']++;
            } else {
                $summary['PENDIENTE']++;
            }

            $services[(int)($row['service_id'] ?? 0)] = true;
            $companyName = trim((string)($row['empresa'] ?? ''));
            if ($companyName !== '') {
                $companies[$companyName] = true;
            }
        }

        $summary['SERVICIOS'] = count($services);
        $summary['EMPRESAS'] = count($companies);
        return $summary;
    }
}

if (!function_exists('ihgBuildReport')) {
    function ihgBuildReport(PDO $pdo, array $filters): array
    {
        $serviceId = (int)($filters['service_id'] ?? 0);
        $empresaId = (int)($filters['empresa_id'] ?? 0);
        $habilitado = ihgNormalizeHabilitadoFilter((string)($filters['habilitado'] ?? ''));
        $searchTerm = trim((string)($filters['buscar'] ?? ''));
        $selectedDataset = ihgNormalizeDatasetKey((string)($filters['dataset'] ?? 'data1'));
        $previewLimit = max(1, (int)($filters['preview_limit'] ?? 300));
        $buildAllDatasets = !empty($filters['build_all_datasets']);

        $services = ihgFetchServices($pdo);
        if ($serviceId > 0) {
            $services = array_values(array_filter(
                $services,
                static fn(array $service): bool => (int)($service['id'] ?? 0) === $serviceId
            ));
        }

        $contractorsByRut = ihgFetchContractorsByRut($pdo, $empresaId);
        $sageByRut = ihgFetchSageByRut($pdo);
        $baseRows = [];
        $orderedTerrainAreaColumns = [];
        $orderedTerrainAreaSeen = [];
        $warnings = [];
        foreach ($services as $service) {
            $currentServiceId = (int)($service['id'] ?? 0);
            if ($currentServiceId <= 0) {
                continue;
            }

            $currentServiceName = trim((string)($service['servicio'] ?? ''));
            try {
                $threshold = ihgNormalizeThreshold(iheLoadTerrainThreshold($pdo, $currentServiceId));
                $theory = iheLoadLatestTheoryRows($pdo, $currentServiceId);
                $theoryAreas = ihgLoadLatestTheoryAreasByRut($pdo, $currentServiceId, $theory);
                $terrain = iheLoadLatestTerrainRows($pdo, $currentServiceId);
                $terrainBreakdown = iheLoadLatestTerrainBreakdownByRut($pdo, $currentServiceId, $threshold);
                foreach ($terrain as $terrainRutKey => $_terrainRow) {
                    $serviceColumns = $terrainBreakdown[$terrainRutKey]['ordered_areas'] ?? [];
                    if (empty($serviceColumns)) {
                        continue;
                    }
                    foreach ($serviceColumns as $areaKey) {
                        $column = trim((string)$areaKey);
                        if ($column === '' || isset($orderedTerrainAreaSeen[$column])) {
                            continue;
                        }
                        $orderedTerrainAreaSeen[$column] = true;
                        $orderedTerrainAreaColumns[] = $column;
                    }
                    break;
                }

                $allRuts = array_unique(array_merge(array_keys($theory), array_keys($terrain)));
                foreach ($allRuts as $rutKey) {
                    $rutKey = (string)$rutKey;
                    if ($rutKey === '') {
                        continue;
                    }

                    $baseRow = ihgBuildBaseRow(
                        $currentServiceId,
                        $currentServiceName,
                        $contractorsByRut,
                        $sageByRut,
                        $rutKey,
                        $theory[$rutKey] ?? null,
                        $terrain[$rutKey] ?? null,
                        $theoryAreas,
                        $terrainBreakdown
                    );
                    if ($baseRow === null) {
                        continue;
                    }

                    if ($empresaId > 0 && (int)$baseRow['empresa_id'] !== $empresaId) {
                        continue;
                    }
                    if ($habilitado !== '' && iheNormalizeText((string)$baseRow['habilitado']) !== $habilitado) {
                        continue;
                    }
                    if (!ihgRowMatchesSearch($baseRow, $searchTerm)) {
                        continue;
                    }

                    $baseRows[] = $baseRow;
                }
            } catch (Throwable $e) {
                $warnings[] = [
                    'service_id' => $currentServiceId,
                    'service_name' => $currentServiceName !== '' ? $currentServiceName : ('Servicio ' . $currentServiceId),
                    'message' => $e->getMessage(),
                ];
            }
        }

        usort($baseRows, static function (array $left, array $right): int {
            $cmp = strcmp((string)($left['service_name'] ?? ''), (string)($right['service_name'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string)($left['empresa'] ?? ''), (string)($right['empresa'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string)($left['apellidos'] ?? ''), (string)($right['apellidos'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string)($left['nombre'] ?? ''), (string)($right['nombre'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($left['rut'] ?? ''), (string)($right['rut'] ?? ''));
        });

        $data1RowsFull = [];
        $data2RowsFull = [];
        $data2Columns = ['RUT', 'Servicio'];
        $data3RowsFull = [];
        $data3Columns = ['RUT', 'Checklist'];
        $data1Rows = [];
        $data2Rows = [];
        $data3Rows = [];

        if ($buildAllDatasets || $selectedDataset === 'data1') {
            $data1RowsFull = ihgBuildData1Rows($baseRows);
            $data1Rows = $buildAllDatasets ? $data1RowsFull : array_slice($data1RowsFull, 0, $previewLimit);
        }
        if ($buildAllDatasets || $selectedDataset === 'data2') {
            $data2Matrix = ihgBuildData2Rows($baseRows);
            $data2Columns = $data2Matrix['columns'] ?? $data2Columns;
            $data2RowsFull = $data2Matrix['rows'] ?? [];
            $data2Rows = $buildAllDatasets ? $data2RowsFull : array_slice($data2RowsFull, 0, $previewLimit);
        }
        if ($buildAllDatasets || $selectedDataset === 'data3') {
            $data3Matrix = ihgBuildData3Rows($baseRows, $orderedTerrainAreaColumns);
            $data3Columns = $data3Matrix['columns'] ?? $data3Columns;
            $data3RowsFull = $data3Matrix['rows'] ?? [];
            $data3Rows = $buildAllDatasets ? $data3RowsFull : array_slice($data3RowsFull, 0, $previewLimit);
        }

        return [
            'base_rows' => $baseRows,
            'warnings' => $warnings,
            'selected_dataset' => $selectedDataset,
            'preview_limit' => $previewLimit,
            'build_all_datasets' => $buildAllDatasets,
            'summary' => ihgBuildSummary($baseRows),
            'data1_columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Servicio', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado'],
            'data2_columns' => $data2Columns,
            'data3_columns' => $data3Columns,
            'data1_rows' => $data1Rows,
            'data2_rows' => $data2Rows,
            'data3_rows' => $data3Rows,
        ];
    }
}
