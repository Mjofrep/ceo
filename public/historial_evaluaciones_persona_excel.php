<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/app.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$rut = trim($_GET['rut'] ?? '');
$rutNormalizado = preg_replace('/\s+/', '', $rut);
if ($rutNormalizado === '') exit('RUT requerido');

$stmtPersona = $pdo->prepare('
    SELECT
        c.rut,
        c.nombre,
        c.apellidos,
        COALESCE(cc.cargo, "") AS cargo,
        COALESCE(e.nombre, "") AS empresa
    FROM ceo_contratistas c
    LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
    LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
    WHERE c.rut = :rut
    LIMIT 1
');
$stmtPersona->execute([':rut' => $rutNormalizado]);
$persona = $stmtPersona->fetch(PDO::FETCH_ASSOC) ?: null;

function obtenerNotaDetalleHistorialExcel(array $row, array $terrainThresholdsByService): string
{
    $nota = is_numeric((string)($row['nota_mostrada'] ?? '')) ? (float)$row['nota_mostrada'] : null;
    if ($nota === null) {
        return '';
    }

    if (strtoupper(trim((string)($row['tipo_evaluacion'] ?? ''))) === 'PRACTICA') {
        $serviceId = isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0;
        $porcentajeMinimo = (float)($terrainThresholdsByService[$serviceId] ?? 80.0);
        $nota = calcularNotaFinalDesdePorcentaje($nota, $porcentajeMinimo);
    }

    return number_format($nota, 2, '.', '');
}

function resolverPesosPorCargoExcel(?string $cargo, ?int $idCargo = null): ?array
{
    $operadorIds = [266, 268, 287];
    $supervisorIds = [294];

    $cargoNorm = strtoupper(trim((string)$cargo));
    $cargoNorm = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $cargoNorm);
    $cargoNorm = strtr($cargoNorm, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    $cargoNorm = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargoNorm) ?: $cargoNorm;
    $cargoNorm = preg_replace('/[^A-Z0-9]+/u', ' ', $cargoNorm) ?? $cargoNorm;
    $cargoNorm = preg_replace('/\s+/', ' ', $cargoNorm) ?? $cargoNorm;
    if ($cargoNorm === '') {
        return null;
    }
    if (
        str_contains($cargoNorm, 'SUPERVISOR') ||
        str_contains($cargoNorm, 'LIDER') ||
        str_contains($cargoNorm, 'CAPATAZ') ||
        str_contains($cargoNorm, 'MAESTRO')
    ) {
        return ['teorica' => 0.6, 'terreno' => 0.4];
    }
    if (
        str_contains($cargoNorm, 'OPERADOR') ||
        str_contains($cargoNorm, 'ACOMPAN') ||
        str_contains($cargoNorm, 'AYUDANTE')
    ) {
        return ['teorica' => 0.4, 'terreno' => 0.6];
    }
    if ($idCargo !== null) {
        if (in_array($idCargo, $supervisorIds, true)) {
            return ['teorica' => 0.6, 'terreno' => 0.4];
        }
        if (in_array($idCargo, $operadorIds, true)) {
            return ['teorica' => 0.4, 'terreno' => 0.6];
        }
    }
    return null;
}

function maxDateTimeHistorialExcel(mixed $primary, mixed $secondary): ?DateTimeImmutable
{
    $best = null;

    foreach ([$primary, $secondary] as $value) {
        if ($value instanceof DateTimeImmutable) {
            $current = $value;
        } elseif ($value instanceof DateTimeInterface) {
            $current = new DateTimeImmutable($value->format('Y-m-d H:i:s'));
        } else {
            $text = trim((string)$value);
            if ($text === '' || str_starts_with($text, '0000-00-00')) {
                continue;
            }
            try {
                $current = new DateTimeImmutable($text);
            } catch (Throwable $e) {
                continue;
            }
        }

        if ($best === null || $current > $best) {
            $best = $current;
        }
    }

    return $best;
}

function buildInferredStatusSummaryExcel(PDO $pdo, string $rut, array $rows, array $terrainNotesByService, array $terrainThresholdsByService): array
{
    $summary = [];

    $stmtFinal = $pdo->prepare('
        SELECT
            rfs.id_servicio,
            sp.servicio,
            ph.numero_proceso,
            rfs.id_proceso_habilitacion,
            ch.cargo,
            rfs.cargo AS id_cargo,
            rfs.nota_final,
            rfs.resultado_final,
            rfs.fecha_calculo
        FROM ceo_resultado_final_servicio rfs
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = rfs.id_servicio
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rfs.id_proceso_habilitacion
        LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = rfs.cargo
        WHERE rfs.rut = :rut_main
          AND rfs.segmento = "GENERAL"
          AND NOT EXISTS (
              SELECT 1
              FROM ceo_resultado_final_servicio rfs_newer
              WHERE rfs_newer.rut = rfs.rut
                AND rfs_newer.id_servicio = rfs.id_servicio
                AND rfs_newer.segmento = rfs.segmento
                AND (
                    rfs_newer.fecha_calculo > rfs.fecha_calculo
                    OR (rfs_newer.fecha_calculo = rfs.fecha_calculo AND rfs_newer.id > rfs.id)
                )
          )
        ORDER BY sp.servicio ASC, rfs.id_servicio ASC
    ');
    $stmtFinal->execute([
        ':rut_main' => $rut,
    ]);

    $stmtTeorica = $pdo->prepare('
        SELECT fecha_rendicion, hora_rendicion, notafinal, puntaje_total
        FROM ceo_resultado_prueba_intento
        WHERE rut = :rut
          AND id_servicio = :id_servicio
          AND id_proceso_habilitacion = :id_proceso_habilitacion
        ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC
        LIMIT 1
    ');

    $stmtTerreno = $pdo->prepare('
        SELECT fecha_rendicion, hora_rendicion, notafinal, puntaje_total
        FROM ceo_resultado_terreno_intento
        WHERE rut = :rut
          AND id_servicio = :id_servicio
          AND id_proceso_habilitacion = :id_proceso_habilitacion
        ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC
        LIMIT 1
    ');

    foreach ($stmtFinal->fetchAll(PDO::FETCH_ASSOC) as $finalRow) {
        $serviceId = (int)($finalRow['id_servicio'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $serviceName = trim((string)($finalRow['servicio'] ?? ''));
        if ($serviceName === '') {
            $serviceName = 'Servicio ' . $serviceId;
        }

        $processHabId = (int)($finalRow['id_proceso_habilitacion'] ?? 0);
        $teorica = null;
        $practica = null;

        if ($processHabId > 0) {
            $stmtTeorica->execute([
                ':rut' => $rut,
                ':id_servicio' => $serviceId,
                ':id_proceso_habilitacion' => $processHabId,
            ]);
            $teorica = $stmtTeorica->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtTerreno->execute([
                ':rut' => $rut,
                ':id_servicio' => $serviceId,
                ':id_proceso_habilitacion' => $processHabId,
            ]);
            $practica = $stmtTerreno->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $ultimaTeoricaFecha = null;
        if ($teorica && !empty($teorica['fecha_rendicion'])) {
            try {
                $ultimaTeoricaFecha = new DateTimeImmutable(trim((string)$teorica['fecha_rendicion']) . ' ' . trim((string)($teorica['hora_rendicion'] ?? '00:00:00')));
            } catch (Throwable $e) {
                $ultimaTeoricaFecha = null;
            }
        }

        $ultimaPracticaFecha = null;
        if ($practica && !empty($practica['fecha_rendicion'])) {
            try {
                $ultimaPracticaFecha = new DateTimeImmutable(trim((string)$practica['fecha_rendicion']) . ' ' . trim((string)($practica['hora_rendicion'] ?? '00:00:00')));
            } catch (Throwable $e) {
                $ultimaPracticaFecha = null;
            }
        }

        $fechaCalculo = null;
        if (!empty($finalRow['fecha_calculo'])) {
            try {
                $fechaCalculo = new DateTimeImmutable(trim((string)$finalRow['fecha_calculo']));
            } catch (Throwable $e) {
                $fechaCalculo = null;
            }
        }

        $fechaPracticaMostrada = maxDateTimeHistorialExcel($ultimaPracticaFecha, $fechaCalculo);
        $notaFinalPonderada = isset($finalRow['nota_final']) && is_numeric((string)$finalRow['nota_final']) ? (float)$finalRow['nota_final'] : null;
        $resultadoInferido = $notaFinalPonderada === null ? 'Pendiente' : ($notaFinalPonderada >= 4.0 ? 'Habilitado' : 'No Habilitado');
        $fechaHabilitacion = ($notaFinalPonderada !== null && $notaFinalPonderada >= 4.0) ? $fechaPracticaMostrada : null;
        $vigenciaHasta = $fechaHabilitacion instanceof DateTimeImmutable ? $fechaHabilitacion->modify('+3 years') : null;

        $summary[$serviceName] = [
            'servicio' => $serviceName,
            'id_servicio' => $serviceId,
            'numero_proceso' => isset($finalRow['numero_proceso']) ? (int)$finalRow['numero_proceso'] : null,
            'id_proceso_habilitacion_resumen' => $processHabId > 0 ? $processHabId : null,
            'cargo' => trim((string)($finalRow['cargo'] ?? '')),
            'id_cargo' => isset($finalRow['id_cargo']) ? (int)$finalRow['id_cargo'] : null,
            'cargo_teorica' => trim((string)($finalRow['cargo'] ?? '')),
            'id_cargo_teorica' => isset($finalRow['id_cargo']) ? (int)$finalRow['id_cargo'] : null,
            'cargo_practica' => trim((string)($finalRow['cargo'] ?? '')),
            'id_cargo_practica' => isset($finalRow['id_cargo']) ? (int)$finalRow['id_cargo'] : null,
            'ultima_teorica_fecha' => $ultimaTeoricaFecha,
            'ultima_teorica_resultado' => $teorica ? (((float)($teorica['puntaje_total'] ?? 0) >= 80.0) ? 'APROBADO' : 'REPROBADO') : null,
            'ultima_teorica_nota' => $teorica && isset($teorica['notafinal']) ? (float)$teorica['notafinal'] : null,
            'ultima_practica_fecha' => $fechaPracticaMostrada,
            'ultima_practica_resultado' => $practica ? (((float)($practica['puntaje_total'] ?? 0) >= 80.0) ? 'APROBADO' : 'REPROBADO') : null,
            'ultima_practica_nota' => $practica && isset($practica['notafinal']) ? (float)$practica['notafinal'] : null,
            'ultima_practica_porcentaje' => $practica && isset($practica['puntaje_total']) ? (float)$practica['puntaje_total'] : null,
            'nota_final_ponderada' => $notaFinalPonderada,
            'fecha_habilitacion' => $fechaHabilitacion,
            'vigencia_hasta' => $vigenciaHasta,
            'estado_inferido' => $resultadoInferido,
        ];
    }

    foreach ($rows as $row) {
        $servicio = trim((string)($row['servicio'] ?? ''));
        if ($servicio === '') {
            continue;
        }
        if (!isset($summary[$servicio])) {
            $summary[$servicio] = [
                'servicio' => $servicio,
                'id_servicio' => isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0,
                'id_proceso_habilitacion_resumen' => null,
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'id_cargo' => isset($row['id_cargo']) ? (int)$row['id_cargo'] : null,
                'cargo_teorica' => null,
                'id_cargo_teorica' => null,
                'cargo_practica' => null,
                'id_cargo_practica' => null,
                'ultima_teorica_fecha' => null,
                'ultima_teorica_resultado' => null,
                'ultima_teorica_nota' => null,
                'ultima_practica_fecha' => null,
                'ultima_practica_resultado' => null,
                'ultima_practica_nota' => null,
                'ultima_practica_porcentaje' => null,
                'nota_final_ponderada' => null,
                'fecha_habilitacion' => null,
                'vigencia_hasta' => null,
                'estado_inferido' => 'No Habilitado',
            ];
        } elseif ($summary[$servicio]['cargo'] === '' && trim((string)($row['cargo'] ?? '')) !== '') {
            $summary[$servicio]['cargo'] = trim((string)$row['cargo']);
            $summary[$servicio]['id_cargo'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : ($summary[$servicio]['id_cargo'] ?? null);
        }
        try {
            $dt = new DateTimeImmutable(trim((string)($row['fecha_hora'] ?? '')));
        } catch (Throwable $e) {
            continue;
        }
        $tipo = strtoupper(trim((string)($row['tipo_evaluacion'] ?? '')));
        $resultado = strtoupper(trim((string)($row['resultado_mostrado'] ?? '')));
        $nota = is_numeric((string)($row['nota_mostrada'] ?? '')) ? (float)$row['nota_mostrada'] : null;
        $rowProcesoHabId = isset($row['id_proceso_habilitacion']) ? (int)$row['id_proceso_habilitacion'] : 0;
        $resumenProcesoHabId = isset($summary[$servicio]['id_proceso_habilitacion_resumen']) ? (int)$summary[$servicio]['id_proceso_habilitacion_resumen'] : 0;
        if ($resumenProcesoHabId > 0 && $rowProcesoHabId > 0 && $resumenProcesoHabId !== $rowProcesoHabId) {
            continue;
        }

        if ($tipo === 'TEORICA') {
            if ($summary[$servicio]['ultima_teorica_fecha'] === null || $dt > $summary[$servicio]['ultima_teorica_fecha']) {
                $summary[$servicio]['ultima_teorica_fecha'] = $dt;
                $summary[$servicio]['ultima_teorica_resultado'] = $resultado;
                $summary[$servicio]['ultima_teorica_nota'] = $nota;
                $summary[$servicio]['cargo_teorica'] = trim((string)($row['cargo'] ?? ''));
                $summary[$servicio]['id_cargo_teorica'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : null;
            }
        } elseif ($tipo === 'PRACTICA') {
            if ($summary[$servicio]['ultima_practica_fecha'] === null || $dt > $summary[$servicio]['ultima_practica_fecha']) {
                $serviceId = isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0;
                $notaTerreno = null;
                $porcentajeTerreno = is_numeric((string)($row['nota_mostrada'] ?? '')) ? (float)$row['nota_mostrada'] : null;
                if ($serviceId > 0 && isset($terrainNotesByService[$serviceId])) {
                    $notaTerreno = $terrainNotesByService[$serviceId]['nota'];
                } elseif ($serviceId > 0) {
                    $porcentajeMinimo = (float)($terrainThresholdsByService[$serviceId] ?? 80.0);
                    if ($porcentajeTerreno !== null) {
                        $notaTerreno = calcularNotaFinalDesdePorcentaje($porcentajeTerreno, $porcentajeMinimo);
                    }
                }
                $summary[$servicio]['ultima_practica_fecha'] = $dt;
                $summary[$servicio]['ultima_practica_resultado'] = $resultado;
                $summary[$servicio]['ultima_practica_nota'] = $notaTerreno;
                $summary[$servicio]['ultima_practica_porcentaje'] = $porcentajeTerreno;
                $summary[$servicio]['cargo_practica'] = trim((string)($row['cargo'] ?? ''));
                $summary[$servicio]['id_cargo_practica'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : null;
            }
        }
    }
    $today = new DateTimeImmutable('today');
    foreach ($summary as $servicio => $data) {
        if ($data['ultima_practica_fecha'] instanceof DateTimeImmutable) {
            $summary[$servicio]['cargo'] = (string)($data['cargo_practica'] ?? '');
            $summary[$servicio]['id_cargo'] = isset($data['id_cargo_practica']) ? (int)$data['id_cargo_practica'] : null;
        } elseif ($data['ultima_teorica_fecha'] instanceof DateTimeImmutable) {
            $summary[$servicio]['cargo'] = (string)($data['cargo_teorica'] ?? '');
            $summary[$servicio]['id_cargo'] = isset($data['id_cargo_teorica']) ? (int)$data['id_cargo_teorica'] : null;
        }
        $cargoResumen = (string)($summary[$servicio]['cargo'] ?? '');
        $idCargoResumen = isset($summary[$servicio]['id_cargo']) ? (int)$summary[$servicio]['id_cargo'] : null;
        $pesos = resolverPesosPorCargoExcel($cargoResumen, $idCargoResumen);
        $notaTeorica = $data['ultima_teorica_nota'];
        $notaTerreno = $data['ultima_practica_nota'];
        if ((int)($data['id_proceso_habilitacion_resumen'] ?? 0) > 0) {
            continue;
        }

        if ($pesos !== null && $notaTeorica !== null && $notaTerreno !== null && $data['ultima_practica_fecha'] instanceof DateTimeImmutable) {
            $notaFinal = round(($notaTeorica * $pesos['teorica']) + ($notaTerreno * $pesos['terreno']), 2);
            $summary[$servicio]['nota_final_ponderada'] = $notaFinal;
            $summary[$servicio]['fecha_habilitacion'] = $data['ultima_practica_fecha'];
            $vigenciaHasta = $data['ultima_practica_fecha']->modify('+3 years');
            $summary[$servicio]['vigencia_hasta'] = $vigenciaHasta;
            $summary[$servicio]['estado_inferido'] = ($notaFinal >= 4.0 && $today <= $vigenciaHasta) ? 'Habilitado' : 'No Habilitado';
        }
    }
    return array_values($summary);
}

function buildProcessHistoryRowsExcel(array $rows): array
{
    $grouped = [];

    foreach ($rows as $index => $row) {
        $servicio = trim((string)($row['servicio'] ?? ''));
        $servicioLabel = $servicio !== '' ? $servicio : 'Sin servicio';
        $serviceId = isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0;
        $processId = isset($row['id_proceso_habilitacion']) ? (int)$row['id_proceso_habilitacion'] : 0;
        $processNumber = trim((string)($row['numero_proceso'] ?? ''));

        if ($processId > 0) {
            $processKey = 'PID:' . $processId;
        } elseif ($processNumber !== '') {
            $processKey = 'PROC:' . $processNumber;
        } else {
            $processKey = 'ROW:' . $index;
        }

        $serviceKey = $serviceId > 0 ? 'SID:' . $serviceId : 'SERV:' . mb_strtolower($servicioLabel, 'UTF-8');
        $groupKey = $serviceKey . '|' . $processKey;

        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'servicio' => $servicioLabel,
                'numero_proceso' => $processNumber,
                'empresa' => trim((string)($row['empresa'] ?? '')),
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'teorica_total' => 0,
                'practica_total' => 0,
                'teorica' => null,
                'practica' => null,
                'fecha_orden' => null,
            ];
        }

        try {
            $dt = new DateTimeImmutable(trim((string)($row['fecha_hora'] ?? '')));
        } catch (Throwable $e) {
            $dt = null;
        }

        if ($grouped[$groupKey]['empresa'] === '' && trim((string)($row['empresa'] ?? '')) !== '') {
            $grouped[$groupKey]['empresa'] = trim((string)$row['empresa']);
        }
        if ($grouped[$groupKey]['cargo'] === '' && trim((string)($row['cargo'] ?? '')) !== '') {
            $grouped[$groupKey]['cargo'] = trim((string)$row['cargo']);
        }
        if ($grouped[$groupKey]['numero_proceso'] === '' && $processNumber !== '') {
            $grouped[$groupKey]['numero_proceso'] = $processNumber;
        }
        if ($dt instanceof DateTimeImmutable && (
            !($grouped[$groupKey]['fecha_orden'] instanceof DateTimeImmutable) ||
            $dt > $grouped[$groupKey]['fecha_orden']
        )) {
            $grouped[$groupKey]['fecha_orden'] = $dt;
        }

        $tipo = strtoupper(trim((string)($row['tipo_evaluacion'] ?? '')));
        if ($tipo === 'TEORICA') {
            $grouped[$groupKey]['teorica_total']++;
            $current = $grouped[$groupKey]['teorica']['fecha_dt'] ?? null;
            if (!($current instanceof DateTimeImmutable) || ($dt instanceof DateTimeImmutable && $dt > $current)) {
                $grouped[$groupKey]['teorica'] = [
                    'row' => $row,
                    'fecha_dt' => $dt,
                ];
            }
        } elseif ($tipo === 'PRACTICA') {
            $grouped[$groupKey]['practica_total']++;
            $current = $grouped[$groupKey]['practica']['fecha_dt'] ?? null;
            if (!($current instanceof DateTimeImmutable) || ($dt instanceof DateTimeImmutable && $dt > $current)) {
                $grouped[$groupKey]['practica'] = [
                    'row' => $row,
                    'fecha_dt' => $dt,
                ];
            }
        }
    }

    $result = array_values($grouped);
    usort($result, static function (array $a, array $b): int {
        $cmp = strcasecmp((string)($a['servicio'] ?? ''), (string)($b['servicio'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        $fechaA = $a['fecha_orden'] ?? null;
        $fechaB = $b['fecha_orden'] ?? null;
        if ($fechaA instanceof DateTimeImmutable && $fechaB instanceof DateTimeImmutable) {
            return $fechaB <=> $fechaA;
        }
        if ($fechaA instanceof DateTimeImmutable) {
            return -1;
        }
        if ($fechaB instanceof DateTimeImmutable) {
            return 1;
        }
        return 0;
    });

    return $result;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM (
        SELECT
            'TEORICA' AS tipo_evaluacion,
            sp.servicio AS servicio,
            rpi.id_servicio AS id_servicio,
            rpi.id_proceso_habilitacion AS id_proceso_habilitacion,
            ph.numero_proceso AS numero_proceso,
            CONCAT(rpi.fecha_rendicion, ' ', rpi.hora_rendicion) AS fecha_hora,
            CASE
                WHEN rpi.puntaje_total >= 80 THEN 'APROBADO'
                ELSE 'REPROBADO'
            END AS resultado_mostrado,
            rpi.notafinal AS nota_mostrada,
            ct.id_cargo AS id_cargo,
            COALESCE((
                SELECT emp_h.nombre
                FROM ceo_evaluaciones_programadas ep_h
                INNER JOIN ceo_habilitacion h ON h.cuadrilla = ep_h.cuadrilla AND h.id_servicio = ep_h.id_servicio
                LEFT JOIN ceo_empresas emp_h ON emp_h.id = h.empresa
                WHERE ep_h.id_proceso_habilitacion = rpi.id_proceso_habilitacion
                  AND ep_h.id_servicio = rpi.id_servicio
                  AND ep_h.tipo = 'PRUEBA'
                  AND REPLACE(REPLACE(REPLACE(UPPER(ep_h.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY ep_h.id DESC
                LIMIT 1
            ), emp.nombre) AS empresa,
            COALESCE((
                SELECT NULLIF(TRIM(hp_h.cargo), '')
                FROM ceo_evaluaciones_programadas ep_h_cargo
                INNER JOIN ceo_habilitacion_participantes hp_h
                    ON hp_h.id_cuadrilla = ep_h_cargo.cuadrilla
                   AND hp_h.rut COLLATE utf8mb4_unicode_ci = ep_h_cargo.rut COLLATE utf8mb4_unicode_ci
                WHERE ep_h_cargo.id_proceso_habilitacion = rpi.id_proceso_habilitacion
                  AND ep_h_cargo.id_servicio = rpi.id_servicio
                  AND REPLACE(REPLACE(REPLACE(UPPER(ep_h_cargo.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY CASE WHEN ep_h_cargo.tipo = 'PRUEBA' THEN 0 ELSE 1 END, ep_h_cargo.id DESC
                LIMIT 1
            ), cargo.cargo) AS cargo,
            CASE
                WHEN rpi.id_evaluador IS NULL THEN 'Carga histórica'
                ELSE TRIM(CONCAT(COALESCE(usr.nombres, ''), ' ', COALESCE(usr.apellidos, '')))
            END AS evaluador,
            uo.desc_uo AS uo,
            '' AS region
        FROM ceo_resultado_prueba_intento rpi
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = rpi.id_servicio
        LEFT JOIN ceo_contratistas ct ON ct.rut = rpi.rut
        LEFT JOIN ceo_empresas emp ON emp.id = ct.id_empresa
        LEFT JOIN ceo_cargo_contratistas cargo ON cargo.id = ct.id_cargo
        LEFT JOIN ceo_uo uo ON uo.id = ct.uo
        LEFT JOIN ceo_usuarios usr ON usr.id = rpi.id_evaluador
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rpi.id_proceso_habilitacion
        WHERE rpi.rut = :rut_teorica

        UNION ALL

        SELECT
            'PRACTICA' AS tipo_evaluacion,
            sp2.servicio AS servicio,
            et.id_servicio AS id_servicio,
            et.id_proceso_habilitacion AS id_proceso_habilitacion,
            ph2.numero_proceso AS numero_proceso,
            et.fecha_evaluacion AS fecha_hora,
            CASE
                WHEN CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) >= 80 THEN 'APROBADO'
                ELSE 'REPROBADO'
            END AS resultado_mostrado,
            CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) AS nota_mostrada,
            ct2.id_cargo AS id_cargo,
            COALESCE((
                SELECT emp_h2.nombre
                FROM ceo_evaluaciones_programadas ep_h2
                INNER JOIN ceo_habilitacion h2 ON h2.cuadrilla = ep_h2.cuadrilla AND h2.id_servicio = ep_h2.id_servicio
                LEFT JOIN ceo_empresas emp_h2 ON emp_h2.id = h2.empresa
                WHERE ep_h2.id_proceso_habilitacion = et.id_proceso_habilitacion
                  AND ep_h2.id_servicio = et.id_servicio
                  AND ep_h2.tipo = 'TERRENO'
                  AND REPLACE(REPLACE(REPLACE(UPPER(ep_h2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY ep_h2.id DESC
                LIMIT 1
            ), emp2.nombre) AS empresa,
            COALESCE((
                SELECT NULLIF(TRIM(hp_h2.cargo), '')
                FROM ceo_evaluaciones_programadas ep_h2_cargo
                INNER JOIN ceo_habilitacion_participantes hp_h2
                    ON hp_h2.id_cuadrilla = ep_h2_cargo.cuadrilla
                   AND hp_h2.rut COLLATE utf8mb4_unicode_ci = ep_h2_cargo.rut COLLATE utf8mb4_unicode_ci
                WHERE ep_h2_cargo.id_proceso_habilitacion = et.id_proceso_habilitacion
                  AND ep_h2_cargo.id_servicio = et.id_servicio
                  AND REPLACE(REPLACE(REPLACE(UPPER(ep_h2_cargo.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY CASE WHEN ep_h2_cargo.tipo = 'TERRENO' THEN 0 ELSE 1 END, ep_h2_cargo.id DESC
                LIMIT 1
            ), NULLIF(TRIM(et.cargo), ''), cargo2.cargo) AS cargo,
            COALESCE(et.evaluador, '') AS evaluador,
            uo2.desc_uo AS uo,
            '' AS region
        FROM ceo_evaluacion_terreno et
        INNER JOIN ceo_servicios_pruebas sp2 ON sp2.id = et.id_servicio
        LEFT JOIN ceo_contratistas ct2 ON ct2.rut = et.rut
        LEFT JOIN ceo_empresas emp2 ON emp2.id = ct2.id_empresa
        LEFT JOIN ceo_cargo_contratistas cargo2 ON cargo2.id = ct2.id_cargo
        LEFT JOIN ceo_uo uo2 ON uo2.id = ct2.uo
        LEFT JOIN ceo_proceso_habilitacion ph2 ON ph2.id = et.id_proceso_habilitacion
        WHERE et.rut = :rut_terreno
    ) historial
    ORDER BY servicio ASC, fecha_hora DESC, tipo_evaluacion ASC
");
$stmt->execute([
    ':rut_teorica' => $rutNormalizado,
    ':rut_terreno' => $rutNormalizado,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtTerrenoNotas = $pdo->prepare('
    SELECT id_servicio, fecha_rendicion, hora_rendicion, notafinal
    FROM ceo_resultado_terreno_intento
    WHERE rut = :rut
    ORDER BY id_servicio ASC, fecha_rendicion DESC, hora_rendicion DESC, id DESC
');
$stmtTerrenoNotas->execute([':rut' => $rutNormalizado]);
$terrainNotesByService = [];
foreach ($stmtTerrenoNotas->fetchAll(PDO::FETCH_ASSOC) as $rowTerr) {
    $sid = (int)$rowTerr['id_servicio'];
    if ($sid <= 0 || isset($terrainNotesByService[$sid])) {
        continue;
    }
    $terrainNotesByService[$sid] = [
        'nota' => isset($rowTerr['notafinal']) ? (float)$rowTerr['notafinal'] : null,
    ];
}

$stmtThresholds = $pdo->query('
    SELECT a.id_servicio, p.porcentaje
    FROM ceo_agrupacion_terreno a
    INNER JOIN ceo_porcentaje_agrup_terreno p ON p.id_agrupacion = a.id
    WHERE p.activo = "S"
    ORDER BY a.id_servicio ASC, p.fechadesde DESC
');
$terrainThresholdsByService = [];
foreach ($stmtThresholds->fetchAll(PDO::FETCH_ASSOC) as $thr) {
    $sid = (int)$thr['id_servicio'];
    if ($sid > 0 && !isset($terrainThresholdsByService[$sid])) {
        $terrainThresholdsByService[$sid] = (float)$thr['porcentaje'];
    }
}

$resumenServicios = buildInferredStatusSummaryExcel($pdo, $rutNormalizado, $rows, $terrainNotesByService, $terrainThresholdsByService);
$rowsByProcess = buildProcessHistoryRowsExcel($rows);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Content-Disposition: attachment; filename=historial_evaluaciones_$rut.xls");

echo "\xEF\xBB\xBF";
echo "<table border='1'>";
echo "<tr><th colspan='2'>Historial de Evaluaciones por Persona</th></tr>";
echo "<tr><th>RUT</th><td>" . htmlspecialchars($rutNormalizado, ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>Nombre</th><td>" . htmlspecialchars(trim((string)($persona['nombre'] ?? '') . ' ' . (string)($persona['apellidos'] ?? '')) ?: 'No disponible', ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>Cargo</th><td>" . htmlspecialchars(trim((string)($persona['cargo'] ?? '')) ?: 'No disponible', ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><th>Empresa</th><td>" . htmlspecialchars(trim((string)($persona['empresa'] ?? '')) ?: 'No disponible', ENT_QUOTES, 'UTF-8') . "</td></tr>";
echo "<tr><td colspan='12'></td></tr>";
echo "<tr><th colspan='13'>Estado inferido por servicio</th></tr>";
echo "<tr><th>Servicio</th><th>Proceso</th><th>Cargo</th><th>Nota teórica</th><th>Última teórica</th><th>Resultado teórica</th><th>Nota terreno</th><th>Último terreno</th><th>Resultado terreno</th><th>Nota final</th><th>Fecha habilitación</th><th>Vigencia hasta</th><th>Estado inferido</th></tr>";
foreach ($resumenServicios as $resumen) {
    $teoFecha = $resumen['ultima_teorica_fecha'] instanceof DateTimeImmutable ? $resumen['ultima_teorica_fecha']->format('Y-m-d H:i:s') : '';
    $terrFecha = $resumen['ultima_practica_fecha'] instanceof DateTimeImmutable ? $resumen['ultima_practica_fecha']->format('Y-m-d H:i:s') : '';
    $mostrarFechasHabilitacion = ($resumen['estado_inferido'] ?? '') === 'Habilitado';
    $vigencia = $mostrarFechasHabilitacion && $resumen['vigencia_hasta'] instanceof DateTimeImmutable ? $resumen['vigencia_hasta']->format('Y-m-d') : '';
    $fechaHab = $mostrarFechasHabilitacion && $resumen['fecha_habilitacion'] instanceof DateTimeImmutable ? $resumen['fecha_habilitacion']->format('Y-m-d') : '';
    echo "<tr>
    <td>{$resumen['servicio']}</td>
    <td>" . (($resumen['numero_proceso'] ?? null) !== null ? (string)$resumen['numero_proceso'] : '') . "</td>
    <td>{$resumen['cargo']}</td>
    <td>" . ($resumen['ultima_teorica_nota'] !== null ? number_format((float)$resumen['ultima_teorica_nota'], 2, '.', '') : '') . "</td>
    <td>{$teoFecha}</td>
    <td>{$resumen['ultima_teorica_resultado']}</td>
    <td>" . ($resumen['ultima_practica_nota'] !== null ? number_format((float)$resumen['ultima_practica_nota'], 2, '.', '') : '') . "</td>
    <td>{$terrFecha}</td>
    <td>{$resumen['ultima_practica_resultado']}</td>
    <td>" . ($resumen['nota_final_ponderada'] !== null ? number_format((float)$resumen['nota_final_ponderada'], 2, '.', '') : '') . "</td>
    <td>{$fechaHab}</td>
    <td>{$vigencia}</td>
    <td>{$resumen['estado_inferido']}</td>
    </tr>";
}
echo "<tr><td colspan='7'></td></tr>";
echo "<tr>
<th>Servicio</th><th>Proceso</th><th>Empresa</th><th>Cargo</th>
<th>Teóricas</th><th>Última teórica</th><th>Resultado teórica</th><th>Nota teórica</th><th>Eval. teórica</th>
<th>Terrenos</th><th>Último terreno</th><th>Resultado terreno</th><th>Nota terreno</th><th>Eval. terreno</th>
</tr>";

foreach ($rowsByProcess as $item) {
    $teorica = $item['teorica']['row'] ?? null;
    $practica = $item['practica']['row'] ?? null;
    echo "<tr>
    <td>{$item['servicio']}</td>
    <td>{$item['numero_proceso']}</td>
    <td>{$item['empresa']}</td>
    <td>{$item['cargo']}</td>
    <td>{$item['teorica_total']}</td>
    <td>" . (($teorica['fecha_hora'] ?? '') !== '' ? $teorica['fecha_hora'] : '') . "</td>
    <td>" . ($teorica['resultado_mostrado'] ?? '') . "</td>
    <td>" . ($teorica ? obtenerNotaDetalleHistorialExcel($teorica, $terrainThresholdsByService) : '') . "</td>
    <td>" . ($teorica['evaluador'] ?? '') . "</td>
    <td>{$item['practica_total']}</td>
    <td>" . (($practica['fecha_hora'] ?? '') !== '' ? $practica['fecha_hora'] : '') . "</td>
    <td>" . ($practica['resultado_mostrado'] ?? '') . "</td>
    <td>" . ($practica ? obtenerNotaDetalleHistorialExcel($practica, $terrainThresholdsByService) : '') . "</td>
    <td>" . ($practica['evaluador'] ?? '') . "</td>
    </tr>";
}
echo "</table>";
