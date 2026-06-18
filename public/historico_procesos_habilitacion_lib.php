<?php
declare(strict_types=1);

function historicoParseScore($value): ?float
{
    if ($value === null) {
        return null;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $text = str_replace(',', '.', $text);
    return is_numeric($text) ? (float)$text : null;
}

function historicoDateTime($value): ?DateTimeImmutable
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($text);
    } catch (Throwable $e) {
        return null;
    }
}

function historicoNormalizarCargo(string $cargo): string
{
    $cargo = strtoupper(trim($cargo));
    $cargo = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $cargo);
    $cargo = strtr($cargo, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    $cargo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargo) ?: $cargo;
    $cargo = preg_replace('/[^A-Z0-9]+/u', ' ', $cargo) ?? $cargo;
    $cargo = preg_replace('/\s+/', ' ', $cargo) ?? $cargo;
    return trim($cargo);
}

function historicoCategoriaCargoProceso(string $cargo): string
{
    $cargoNorm = historicoNormalizarCargo($cargo);
    if ($cargoNorm === '') {
        return 'SIN_CARGO';
    }

    if (
        str_contains($cargoNorm, 'SUPERVISOR') ||
        str_contains($cargoNorm, 'LIDER') ||
        str_contains($cargoNorm, 'CAPATAZ') ||
        str_contains($cargoNorm, 'MAESTRO')
    ) {
        return 'SUPERVISOR';
    }

    if (
        str_contains($cargoNorm, 'OPERADOR') ||
        str_contains($cargoNorm, 'ACOMPAN') ||
        str_contains($cargoNorm, 'AYUDANTE')
    ) {
        return 'OPERADOR';
    }

    return $cargoNorm;
}

function historicoNuevoProcesoMeta(int $numero, string $rut, int $idServicio, string $servicio): array
{
    return [
        'key' => $rut . '|' . $idServicio . '|' . $numero,
        'rut' => $rut,
        'id_servicio' => $idServicio,
        'servicio' => $servicio,
        'numero' => $numero,
        'estado' => 'ABIERTO',
        'fecha_base' => null,
        'vigente_hasta' => null,
        'teorica_aprobada' => null,
        'terreno_aprobado' => null,
        'rows' => [],
    ];
}

function historicoActualizarProceso(array &$meta, bool $evaluarVencimientoActual = false): void
{
    $aprobadas = array_filter([
        $meta['teorica_aprobada'],
        $meta['terreno_aprobado'],
    ]);

    if (!empty($aprobadas)) {
        usort($aprobadas, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $meta['fecha_base'] = $aprobadas[0];
        $meta['vigente_hasta'] = $aprobadas[0]->modify('+3 years');
    }

    if ($meta['teorica_aprobada'] instanceof DateTimeImmutable && $meta['terreno_aprobado'] instanceof DateTimeImmutable) {
        $ultimaAprobada = $meta['teorica_aprobada'] > $meta['terreno_aprobado']
            ? $meta['teorica_aprobada']
            : $meta['terreno_aprobado'];

        if ($meta['vigente_hasta'] instanceof DateTimeImmutable && $ultimaAprobada <= $meta['vigente_hasta']) {
            $meta['estado'] = 'CERRADO';
            return;
        }
    }

    if ($evaluarVencimientoActual && $meta['vigente_hasta'] instanceof DateTimeImmutable && new DateTimeImmutable('today') > $meta['vigente_hasta']) {
        $meta['estado'] = 'VENCIDO';
        return;
    }

    $meta['estado'] = 'ABIERTO';
}

function historicoCargarEventos(PDO $pdo, int $idServicio, string $rut): array
{
    $whereTeorica = [];
    $whereTerreno = [];
    $params = [];

    if ($idServicio > 0) {
        $whereTeorica[] = 'rpi.id_servicio = :id_servicio_teorica';
        $whereTerreno[] = 'et.id_servicio = :id_servicio_terreno';
        $params[':id_servicio_teorica'] = $idServicio;
        $params[':id_servicio_terreno'] = $idServicio;
    }

    if ($rut !== '') {
        $rutNorm = strtoupper(str_replace(['.', '-', ' '], '', $rut));
        $whereTeorica[] = "REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '') = :rut_teorica";
        $whereTerreno[] = "REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '') = :rut_terreno";
        $params[':rut_teorica'] = $rutNorm;
        $params[':rut_terreno'] = $rutNorm;
    }

    $sqlTeoricaWhere = $whereTeorica ? 'WHERE ' . implode(' AND ', $whereTeorica) : '';
    $sqlTerrenoWhere = $whereTerreno ? 'WHERE ' . implode(' AND ', $whereTerreno) : '';

    $sql = "
        SELECT *
        FROM (
            SELECT
                'TEORICA' AS tipo,
                rpi.id AS id_registro,
                rpi.rut,
                rpi.id_servicio,
                sp.servicio,
                CONCAT(rpi.fecha_rendicion, ' ', rpi.hora_rendicion) AS fecha_hora,
                rpi.puntaje_total AS puntaje,
                rpi.notafinal AS nota_final,
                CASE WHEN rpi.puntaje_total >= 80 THEN 'APROBADO' ELSE 'REPROBADO' END AS resultado,
                TRIM(CONCAT(COALESCE(ct.nombre, ''), ' ', COALESCE(ct.apellidos, ''))) AS nombre,
                COALESCE(cc.cargo, '') AS cargo_contratista,
                '' AS cargo_terreno,
                rpi.id_proceso_habilitacion AS id_proceso_habilitacion,
                ph.numero_proceso AS proceso_real,
                ph.estado AS estado_proceso_real,
                ph.origen AS origen_proceso,
                CASE WHEN rpi.id_evaluador IS NULL THEN 'Histórico' ELSE 'CEONext' END AS origen
            FROM ceo_resultado_prueba_intento rpi
            INNER JOIN ceo_servicios_pruebas sp ON sp.id = rpi.id_servicio
            LEFT JOIN ceo_contratistas ct ON ct.rut = rpi.rut
            LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ct.id_cargo
            LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rpi.id_proceso_habilitacion
            {$sqlTeoricaWhere}

            UNION ALL

            SELECT
                'TERRENO' AS tipo,
                et.id AS id_registro,
                et.rut,
                et.id_servicio,
                sp2.servicio,
                et.fecha_evaluacion AS fecha_hora,
                CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) AS puntaje,
                (
                    SELECT rti.notafinal
                    FROM ceo_resultado_terreno_intento rti
                    WHERE rti.rut = et.rut
                      AND rti.id_servicio = et.id_servicio
                      AND rti.fecha_rendicion = DATE(et.fecha_evaluacion)
                    ORDER BY rti.id DESC
                    LIMIT 1
                ) AS nota_final,
                CASE
                    WHEN CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) >= 80 THEN 'APROBADO'
                    ELSE 'REPROBADO'
                END AS resultado,
                COALESCE(NULLIF(TRIM(et.nombre), ''), TRIM(CONCAT(COALESCE(ct2.nombre, ''), ' ', COALESCE(ct2.apellidos, '')))) AS nombre,
                COALESCE(cc2.cargo, '') AS cargo_contratista,
                COALESCE(et.cargo, '') AS cargo_terreno,
                et.id_proceso_habilitacion AS id_proceso_habilitacion,
                ph2.numero_proceso AS proceso_real,
                ph2.estado AS estado_proceso_real,
                ph2.origen AS origen_proceso,
                'Histórico' AS origen
            FROM ceo_evaluacion_terreno et
            INNER JOIN ceo_servicios_pruebas sp2 ON sp2.id = et.id_servicio
            LEFT JOIN ceo_contratistas ct2 ON ct2.rut = et.rut
            LEFT JOIN ceo_cargo_contratistas cc2 ON cc2.id = ct2.id_cargo
            LEFT JOIN ceo_proceso_habilitacion ph2 ON ph2.id = et.id_proceso_habilitacion
            {$sqlTerrenoWhere}
        ) h
        ORDER BY servicio ASC, rut ASC, fecha_hora ASC, tipo ASC, id_registro ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $terrenosPorRutServicio = [];
    foreach ($eventos as $evento) {
        if ((string)$evento['tipo'] !== 'TERRENO') {
            continue;
        }
        $fecha = historicoDateTime($evento['fecha_hora']);
        $cargoTerreno = trim((string)($evento['cargo_terreno'] ?? ''));
        if (!$fecha || $cargoTerreno === '') {
            continue;
        }
        $terrenosPorRutServicio[(string)$evento['rut'] . '|' . (int)$evento['id_servicio']][] = [
            'fecha' => $fecha,
            'cargo' => $cargoTerreno,
        ];
    }

    foreach ($eventos as &$evento) {
        $tipo = (string)$evento['tipo'];
        if ($tipo === 'TERRENO') {
            $cargo = trim((string)($evento['cargo_terreno'] ?? ''));
            $evento['cargo_evaluacion'] = $cargo !== '' ? $cargo : trim((string)($evento['cargo_contratista'] ?? ''));
            $evento['cargo_origen'] = $cargo !== '' ? 'Terreno' : 'Contratista actual';
            continue;
        }

        $fechaTeorica = historicoDateTime($evento['fecha_hora']);
        $key = (string)$evento['rut'] . '|' . (int)$evento['id_servicio'];
        $cargoCercano = '';
        $menorDiferencia = null;

        if ($fechaTeorica && !empty($terrenosPorRutServicio[$key])) {
            foreach ($terrenosPorRutServicio[$key] as $terreno) {
                $diffSeconds = abs($terreno['fecha']->getTimestamp() - $fechaTeorica->getTimestamp());
                if ($diffSeconds <= 7 * 86400 && ($menorDiferencia === null || $diffSeconds < $menorDiferencia)) {
                    $menorDiferencia = $diffSeconds;
                    $cargoCercano = (string)$terreno['cargo'];
                }
            }
        }

        if ($cargoCercano !== '') {
            $evento['cargo_evaluacion'] = $cargoCercano;
            $evento['cargo_origen'] = 'Terreno cercano 7 dias';
        } else {
            $evento['cargo_evaluacion'] = trim((string)($evento['cargo_contratista'] ?? ''));
            $evento['cargo_origen'] = 'Contratista actual';
        }
    }
    unset($evento);

    return $eventos;
}

function historicoSimularProcesos(PDO $pdo, int $idServicio = 0, string $rut = ''): array
{
    $eventos = historicoCargarEventos($pdo, $idServicio, $rut);
    usort($eventos, static function (array $a, array $b): int {
        $fechaA = historicoDateTime($a['fecha_hora']);
        $fechaB = historicoDateTime($b['fecha_hora']);

        return [
            $fechaA instanceof DateTimeImmutable ? $fechaA->getTimestamp() : 0,
            (string)$a['rut'],
            (int)$a['id_servicio'],
            (string)$a['tipo'],
            (int)$a['id_registro'],
        ] <=> [
            $fechaB instanceof DateTimeImmutable ? $fechaB->getTimestamp() : 0,
            (string)$b['rut'],
            (int)$b['id_servicio'],
            (string)$b['tipo'],
            (int)$b['id_registro'],
        ];
    });

    $rows = [];
    $procesos = [];
    $procesosAbiertos = [];
    $attemptsByProcess = [];
    $numeroProcesoGlobal = 0;
    $summary = [
        'ruts' => [],
        'servicios' => [],
        'teoricas' => 0,
        'terrenos' => 0,
        'procesos' => 0,
        'cerrados' => 0,
        'abiertos' => 0,
        'vencidos' => 0,
    ];

    foreach ($eventos as $evento) {
        $fecha = historicoDateTime($evento['fecha_hora']);
        if (!$fecha) {
            continue;
        }

        $rutEvento = (string)$evento['rut'];
        $idServicioEvento = (int)$evento['id_servicio'];
        $cargoEvaluacion = trim((string)($evento['cargo_evaluacion'] ?? ''));
        $cargoProcesoKey = historicoCategoriaCargoProceso($cargoEvaluacion);
        $rutServicioKey = $rutEvento . '|' . $idServicioEvento . '|' . $cargoProcesoKey;

        if (isset($procesosAbiertos[$rutServicioKey])) {
            $meta = $procesosAbiertos[$rutServicioKey];
            historicoActualizarProceso($meta);

            if ($meta['estado'] === 'CERRADO' || $meta['estado'] === 'VENCIDO' || ($meta['vigente_hasta'] instanceof DateTimeImmutable && $fecha > $meta['vigente_hasta'])) {
                if ($meta['estado'] !== 'CERRADO') {
                    $meta['estado'] = 'VENCIDO';
                }
                $procesos[$meta['key']] = $meta;
                unset($procesosAbiertos[$rutServicioKey]);
            } else {
                $procesosAbiertos[$rutServicioKey] = $meta;
            }
        }

        if (!isset($procesosAbiertos[$rutServicioKey])) {
            $numeroProcesoGlobal++;
            $meta = historicoNuevoProcesoMeta($numeroProcesoGlobal, $rutEvento, $idServicioEvento, (string)$evento['servicio']);
            $procesosAbiertos[$rutServicioKey] = $meta;
            $attemptsByProcess[$meta['key']] = ['TEORICA' => 0, 'TERRENO' => 0];
        }

        $meta = $procesosAbiertos[$rutServicioKey];
        $tipo = (string)$evento['tipo'];
        $attemptsByProcess[$meta['key']][$tipo] = ($attemptsByProcess[$meta['key']][$tipo] ?? 0) + 1;
        $rowIndex = count($rows);

        if ($evento['resultado'] === 'APROBADO') {
            if ($tipo === 'TEORICA' && !$meta['teorica_aprobada']) {
                $meta['teorica_aprobada'] = $fecha;
            }
            if ($tipo === 'TERRENO' && !$meta['terreno_aprobado']) {
                $meta['terreno_aprobado'] = $fecha;
            }
        }

        $meta['rows'][] = $rowIndex;
        $procesosAbiertos[$rutServicioKey] = $meta;
        $rows[] = [
            'servicio' => (string)$evento['servicio'],
            'id_servicio' => $idServicioEvento,
            'rut' => $rutEvento,
            'nombre' => trim((string)$evento['nombre']),
            'cargo_evaluacion' => $cargoEvaluacion,
            'cargo_proceso' => $cargoProcesoKey,
            'cargo_origen' => (string)($evento['cargo_origen'] ?? ''),
            'proceso' => (int)$meta['numero'],
            'id_proceso_habilitacion' => isset($evento['id_proceso_habilitacion']) ? (int)$evento['id_proceso_habilitacion'] : 0,
            'proceso_real' => !empty($evento['proceso_real']) ? (int)$evento['proceso_real'] : null,
            'estado_proceso_real' => (string)($evento['estado_proceso_real'] ?? ''),
            'origen_proceso' => (string)($evento['origen_proceso'] ?? ''),
            'estado_proceso' => 'ABIERTO',
            'tipo' => $tipo,
            'intento_proceso' => $attemptsByProcess[$meta['key']][$tipo],
            'fecha_hora' => $fecha,
            'resultado' => (string)$evento['resultado'],
            'puntaje' => historicoParseScore($evento['puntaje']),
            'nota_final' => historicoParseScore($evento['nota_final']),
            'fecha_base' => null,
            'vigente_hasta' => null,
            'origen' => (string)$evento['origen'],
            'id_registro' => (int)$evento['id_registro'],
            'observacion' => '',
            'process_key' => $meta['key'],
        ];

        $summary['ruts'][$rutEvento] = true;
        $summary['servicios'][$idServicioEvento] = (string)$evento['servicio'];
        if ($tipo === 'TEORICA') {
            $summary['teoricas']++;
        } else {
            $summary['terrenos']++;
        }
    }

    foreach ($procesosAbiertos as $meta) {
        historicoActualizarProceso($meta, true);
        $procesos[$meta['key']] = $meta;
    }

    foreach ($procesos as $meta) {
        $summary['procesos']++;
        if ($meta['estado'] === 'CERRADO') {
            $summary['cerrados']++;
        } elseif ($meta['estado'] === 'VENCIDO') {
            $summary['vencidos']++;
        } else {
            $summary['abiertos']++;
        }

        foreach ($meta['rows'] as $idx) {
            $rows[$idx]['estado_proceso'] = $meta['estado'];
            $rows[$idx]['fecha_base'] = $meta['fecha_base'];
            $rows[$idx]['vigente_hasta'] = $meta['vigente_hasta'];
            $rows[$idx]['observacion'] = match ($meta['estado']) {
                'CERRADO' => 'Teórica y terreno aprobadas dentro de 3 años.',
                'VENCIDO' => 'Proceso incompleto fuera de ventana de 3 años.',
                default => 'Proceso incompleto dentro de ventana vigente.',
            };
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return [
            $a['servicio'],
            $a['rut'],
            $a['proceso'],
            $a['fecha_hora'] instanceof DateTimeImmutable ? $a['fecha_hora']->getTimestamp() : 0,
            $a['tipo'],
            $a['id_registro'],
        ] <=> [
            $b['servicio'],
            $b['rut'],
            $b['proceso'],
            $b['fecha_hora'] instanceof DateTimeImmutable ? $b['fecha_hora']->getTimestamp() : 0,
            $b['tipo'],
            $b['id_registro'],
        ];
    });

    $summary['total_ruts'] = count($summary['ruts']);
    $summary['servicios_texto'] = implode(', ', array_values($summary['servicios']));

    return ['rows' => $rows, 'summary' => $summary];
}

function historicoFmtDateTime(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y H:i') : '';
}

function historicoFmtDate(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y') : '';
}
