<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../config/app.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

$pdo = db();

$rolUsuario    = strtolower($_SESSION['auth']['rol'] ?? '');
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');
$esAdmin = ((int)($_SESSION['auth']['id_rol'] ?? 0) === 1);

$rut = trim($_GET['rut'] ?? '');
$rutNormalizado = preg_replace('/\s+/', '', $rut);
$rows = [];
$rowsByProcess = [];
$persona = null;
$resumenServicios = [];

function formatearFechaHistorial(mixed $valor, bool $conHora = false): string
{
    if ($valor instanceof DateTimeInterface) {
        return $valor->format($conHora ? 'd-m-Y H:i' : 'd-m-Y');
    }

    $fecha = trim((string)$valor);
    if ($fecha === '' || str_starts_with($fecha, '0000-00-00')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($fecha))->format($conHora ? 'd-m-Y H:i' : 'd-m-Y');
    } catch (Throwable $e) {
        return '';
    }
}

function formatearNotaHistorial(mixed $valor): string
{
    if (!is_numeric((string)$valor)) {
        return '';
    }

    return number_format((float)$valor, 2, '.', '');
}

function historialEtiquetaDetallePrueba(array $row): string
{
    $detalle = trim((string)($row['detalle_prueba'] ?? ''));
    if ($detalle !== '') {
        return $detalle;
    }

    if (strtoupper(trim((string)($row['tipo_evaluacion'] ?? ''))) !== 'TEORICA') {
        return '';
    }

    return 'Teórica';
}

function maxDateTimeHistorial(mixed $primary, mixed $secondary): ?DateTimeImmutable
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

function obtenerNotaDetalleHistorial(array $row, array $terrainThresholdsByService): string
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

    return formatearNotaHistorial($nota);
}

function resolverPesosPorCargo(?string $cargo, ?int $idCargo = null): ?array
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

function buildInferredStatusSummary(PDO $pdo, string $rut, array $rows, array $terrainNotesByService, array $terrainThresholdsByService): array
{
    $summary = [];

    $stmtFinal = $pdo->prepare('
        SELECT
            rfs.id_servicio,
            sp.servicio,
            ph.numero_proceso,
            rfs.id_proceso,
            rfs.id_proceso_habilitacion,
            ch.cargo,
            rfs.cargo AS id_cargo,
            rfs.nota_final,
            rfs.resultado_final,
            rfs.observacion,
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
        SELECT
            rpi.fecha_rendicion,
            rpi.hora_rendicion,
            rpi.notafinal,
            rpi.puntaje_total
        FROM ceo_resultado_prueba_intento rpi
        WHERE rpi.rut = :rut
          AND rpi.id_servicio = :id_servicio
          AND rpi.id_proceso_habilitacion = :id_proceso_habilitacion
        ORDER BY rpi.fecha_rendicion DESC, rpi.hora_rendicion DESC, rpi.id DESC
        LIMIT 1
    ');

    $stmtTerreno = $pdo->prepare('
        SELECT
            rti.fecha_rendicion,
            rti.hora_rendicion,
            rti.notafinal,
            rti.puntaje_total
        FROM ceo_resultado_terreno_intento rti
        WHERE rti.rut = :rut
          AND rti.id_servicio = :id_servicio
          AND rti.id_proceso_habilitacion = :id_proceso_habilitacion
        ORDER BY rti.fecha_rendicion DESC, rti.hora_rendicion DESC, rti.id DESC
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

        $fechaPracticaMostrada = maxDateTimeHistorial($ultimaPracticaFecha, $fechaCalculo);
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
            'ultima_teorica_proceso' => isset($finalRow['numero_proceso']) ? (int)$finalRow['numero_proceso'] : null,
            'ultima_teorica_origen' => $teorica ? 'HABILITACION' : null,
            'ultima_teorica_id_proceso_habilitacion' => $processHabId > 0 ? $processHabId : null,
            'ultima_practica_fecha' => $fechaPracticaMostrada,
            'ultima_practica_resultado' => $practica ? (((float)($practica['puntaje_total'] ?? 0) >= 80.0) ? 'APROBADO' : 'REPROBADO') : null,
            'ultima_practica_nota' => $practica && isset($practica['notafinal']) ? (float)$practica['notafinal'] : null,
            'ultima_practica_porcentaje' => $practica && isset($practica['puntaje_total']) ? (float)$practica['puntaje_total'] : null,
            'ultima_practica_proceso' => isset($finalRow['numero_proceso']) ? (int)$finalRow['numero_proceso'] : null,
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
                'numero_proceso' => null,
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
                'ultima_teorica_proceso' => null,
                'ultima_teorica_origen' => null,
                'ultima_teorica_id_proceso_habilitacion' => null,
                'ultima_practica_fecha' => null,
                'ultima_practica_resultado' => null,
                'ultima_practica_nota' => null,
                'ultima_practica_porcentaje' => null,
                'ultima_practica_proceso' => null,
                'nota_final_ponderada' => null,
                'fecha_habilitacion' => null,
                'vigencia_hasta' => null,
                'estado_inferido' => 'No Habilitado',
            ];
        } elseif ($summary[$servicio]['cargo'] === '' && trim((string)($row['cargo'] ?? '')) !== '') {
            $summary[$servicio]['cargo'] = trim((string)$row['cargo']);
            $summary[$servicio]['id_cargo'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : ($summary[$servicio]['id_cargo'] ?? null);
        }

        $fechaHora = trim((string)($row['fecha_hora'] ?? ''));
        try {
            $dt = new DateTimeImmutable($fechaHora);
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
                $summary[$servicio]['ultima_teorica_proceso'] = isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null;
                $summary[$servicio]['ultima_teorica_origen'] = strtoupper(trim((string)($row['origen'] ?? '')));
                $summary[$servicio]['ultima_teorica_id_proceso_habilitacion'] = isset($row['id_proceso_habilitacion']) ? (int)$row['id_proceso_habilitacion'] : null;
                $summary[$servicio]['cargo_teorica'] = trim((string)($row['cargo'] ?? ''));
                $summary[$servicio]['id_cargo_teorica'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : null;
            }
        } elseif ($tipo === 'PRACTICA') {
            if ($summary[$servicio]['ultima_practica_fecha'] === null || $dt > $summary[$servicio]['ultima_practica_fecha']) {
                $serviceId = isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0;
                $notaTerreno = null;
                $porcentajeTerreno = is_numeric((string)($row['nota_mostrada'] ?? '')) ? (float)$row['nota_mostrada'] : null;
                if ($serviceId > 0) {
                    $porcentajeMinimo = (float)($terrainThresholdsByService[$serviceId] ?? 80.0);
                    if ($porcentajeTerreno !== null) {
                        $notaTerreno = calcularNotaFinalDesdePorcentaje($porcentajeTerreno, $porcentajeMinimo);
                    }
                }
                $summary[$servicio]['ultima_practica_fecha'] = $dt;
                $summary[$servicio]['ultima_practica_resultado'] = $resultado;
                $summary[$servicio]['ultima_practica_nota'] = $notaTerreno;
                $summary[$servicio]['ultima_practica_porcentaje'] = $porcentajeTerreno;
                $summary[$servicio]['ultima_practica_proceso'] = isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null;
                $summary[$servicio]['cargo_practica'] = trim((string)($row['cargo'] ?? ''));
                $summary[$servicio]['id_cargo_practica'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : null;
            }
        }
    }

    $today = new DateTimeImmutable('today');

    foreach ($summary as $servicio => $data) {
        $summary[$servicio]['numero_proceso'] = $data['ultima_practica_proceso'] ?? ($data['ultima_teorica_proceso'] ?? null);
        if ($data['ultima_practica_proceso'] !== null) {
            $summary[$servicio]['cargo'] = (string)($data['cargo_practica'] ?? '');
            $summary[$servicio]['id_cargo'] = isset($data['id_cargo_practica']) ? (int)$data['id_cargo_practica'] : null;
        } elseif ($data['ultima_teorica_proceso'] !== null) {
            $summary[$servicio]['cargo'] = (string)($data['cargo_teorica'] ?? '');
            $summary[$servicio]['id_cargo'] = isset($data['id_cargo_teorica']) ? (int)$data['id_cargo_teorica'] : null;
        }
        $cargoResumen = (string)($summary[$servicio]['cargo'] ?? '');
        $idCargoResumen = isset($summary[$servicio]['id_cargo']) ? (int)$summary[$servicio]['id_cargo'] : null;
        $pesos = resolverPesosPorCargo($cargoResumen, $idCargoResumen);
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

function buildProcessHistoryRows(array $rows): array
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
                'id_servicio' => $serviceId,
                'numero_proceso' => $processNumber,
                'empresa' => trim((string)($row['empresa'] ?? '')),
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'teoricas' => [],
                'practica_total' => 0,
                'practica' => null,
                'fecha_orden' => null,
            ];
        }

        $fechaHora = trim((string)($row['fecha_hora'] ?? ''));
        try {
            $dt = new DateTimeImmutable($fechaHora);
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
            $detallePrueba = historialEtiquetaDetallePrueba($row);
            $theoryKey = $detallePrueba !== '' ? $detallePrueba : 'Teórica';
            $current = $grouped[$groupKey]['teoricas'][$theoryKey]['fecha_dt'] ?? null;
            if (!($current instanceof DateTimeImmutable) || ($dt instanceof DateTimeImmutable && $dt > $current)) {
                $grouped[$groupKey]['teoricas'][$theoryKey] = [
                    'row' => $row,
                    'fecha_dt' => $dt,
                    'detalle_prueba' => $theoryKey,
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

if ($rutNormalizado !== '') {

    $stmtPersona = $pdo->prepare("
        SELECT rut, nombre, apellidos
        FROM ceo_contratistas
        WHERE rut = :rut
        LIMIT 1
    ");
    $stmtPersona->execute([':rut' => $rutNormalizado]);
    $persona = $stmtPersona->fetch(PDO::FETCH_ASSOC) ?: null;

    /* 🔐 Seguridad: contratista solo ve lo propio */
    if ($esContratista) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM ceo_contratistas
            WHERE rut = :rut AND id_empresa = :empresa
        ");
        $stmt->execute([
            ':rut'     => $rutNormalizado,
            ':empresa' => $idEmpresaUser
        ]);
        if (!$stmt->fetch()) {
            die('No autorizado para ver este RUT.');
        }
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
                SELECT NULLIF(TRIM(COALESCE(et_recent.contratista, '')), '')
                FROM ceo_evaluacion_terreno et_recent
                WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                  AND et_recent.id_servicio = rpi.id_servicio
                ORDER BY et_recent.fecha_evaluacion DESC, et_recent.id DESC
                LIMIT 1
            ), (
                SELECT emp_recent.nombre
                FROM ceo_habilitacion_participantes hp_recent
                INNER JOIN ceo_habilitacion h_recent ON h_recent.cuadrilla = hp_recent.id_cuadrilla
                LEFT JOIN ceo_empresas emp_recent ON emp_recent.id = h_recent.empresa
                WHERE REPLACE(REPLACE(REPLACE(UPPER(hp_recent.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY h_recent.fecha DESC, h_recent.cuadrilla DESC, hp_recent.id DESC
                LIMIT 1
            ), (
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
                SELECT NULLIF(TRIM(COALESCE(et_recent.cargo, '')), '')
                FROM ceo_evaluacion_terreno et_recent
                WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                  AND et_recent.id_servicio = rpi.id_servicio
                ORDER BY et_recent.fecha_evaluacion DESC, et_recent.id DESC
                LIMIT 1
            ), (
                SELECT NULLIF(TRIM(cc_recent.cargo), '')
                FROM ceo_habilitacion_participantes hp_recent
                INNER JOIN ceo_habilitacion h_recent ON h_recent.cuadrilla = hp_recent.id_cuadrilla
                INNER JOIN ceo_participantes_solicitud ps_recent
                    ON ps_recent.id_solicitud = h_recent.nsolicitud
                   AND REPLACE(REPLACE(REPLACE(UPPER(ps_recent.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(hp_recent.rut), '.', ''), '-', ''), ' ', '')
                LEFT JOIN ceo_cargo_contratistas cc_recent ON cc_recent.id = ps_recent.id_cargo
                WHERE REPLACE(REPLACE(REPLACE(UPPER(hp_recent.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY h_recent.fecha DESC, h_recent.cuadrilla DESC, hp_recent.id DESC
                LIMIT 1
            ), (
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
            '' AS region,
            'HABILITACION' AS origen
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
                SELECT NULLIF(TRIM(COALESCE(et_recent2.contratista, '')), '')
                FROM ceo_evaluacion_terreno et_recent2
                WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                  AND et_recent2.id_servicio = et.id_servicio
                ORDER BY et_recent2.fecha_evaluacion DESC, et_recent2.id DESC
                LIMIT 1
            ), (
                SELECT emp_recent2.nombre
                FROM ceo_habilitacion_participantes hp_recent2
                INNER JOIN ceo_habilitacion h_recent2 ON h_recent2.cuadrilla = hp_recent2.id_cuadrilla
                LEFT JOIN ceo_empresas emp_recent2 ON emp_recent2.id = h_recent2.empresa
                WHERE REPLACE(REPLACE(REPLACE(UPPER(hp_recent2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY h_recent2.fecha DESC, h_recent2.cuadrilla DESC, hp_recent2.id DESC
                LIMIT 1
            ), (
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
                SELECT NULLIF(TRIM(COALESCE(et_recent2.cargo, '')), '')
                FROM ceo_evaluacion_terreno et_recent2
                WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                  AND et_recent2.id_servicio = et.id_servicio
                ORDER BY et_recent2.fecha_evaluacion DESC, et_recent2.id DESC
                LIMIT 1
            ), (
                SELECT NULLIF(TRIM(cc_recent2.cargo), '')
                FROM ceo_habilitacion_participantes hp_recent2
                INNER JOIN ceo_habilitacion h_recent2 ON h_recent2.cuadrilla = hp_recent2.id_cuadrilla
                INNER JOIN ceo_participantes_solicitud ps_recent2
                    ON ps_recent2.id_solicitud = h_recent2.nsolicitud
                   AND REPLACE(REPLACE(REPLACE(UPPER(ps_recent2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(hp_recent2.rut), '.', ''), '-', ''), ' ', '')
                LEFT JOIN ceo_cargo_contratistas cc_recent2 ON cc_recent2.id = ps_recent2.id_cargo
                WHERE REPLACE(REPLACE(REPLACE(UPPER(hp_recent2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                ORDER BY h_recent2.fecha DESC, h_recent2.cuadrilla DESC, hp_recent2.id DESC
                LIMIT 1
            ), (
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
            '' AS region,
            'HABILITACION' AS origen
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

    $lleeConfig = ceoGetLleeConfig();
    if (!empty($rows)) {
        foreach ($rows as &$row) {
            $row['detalle_prueba'] = '';
        }
        unset($row);
    }

    if ((int)($lleeConfig['service_id'] ?? 0) > 0) {
        $stmtLlee = $pdo->prepare("
            SELECT
                base.id_servicio,
                (
                    SELECT rpi2.id_proceso_habilitacion
                    FROM ceo_resultado_prueba_intento rpi2
                    WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(base.rut), '.', ''), '-', ''), ' ', '')
                      AND rpi2.id_servicio = base.id_servicio
                      AND rpi2.fecha_rendicion = base.fecha_rendicion
                    ORDER BY COALESCE(rpi2.hora_rendicion, '00:00:00') DESC, rpi2.id DESC
                    LIMIT 1
                ) AS id_proceso_habilitacion,
                (
                    SELECT ph2.numero_proceso
                    FROM ceo_resultado_prueba_intento rpi2
                    LEFT JOIN ceo_proceso_habilitacion ph2 ON ph2.id = rpi2.id_proceso_habilitacion
                    WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(base.rut), '.', ''), '-', ''), ' ', '')
                      AND rpi2.id_servicio = base.id_servicio
                      AND rpi2.fecha_rendicion = base.fecha_rendicion
                    ORDER BY COALESCE(rpi2.hora_rendicion, '00:00:00') DESC, rpi2.id DESC
                    LIMIT 1
                ) AS numero_proceso,
                CONCAT(base.fecha_rendicion, ' ', base.hora_rendicion) AS fecha_hora,
                base.porcentaje AS puntaje_total,
                base.resultado_mostrado,
                base.detalle_prueba,
                base.id_agrupacion,
                base.intento,
                COALESCE((
                    SELECT NULLIF(TRIM(COALESCE(et_recent.contratista, '')), '')
                    FROM ceo_evaluacion_terreno et_recent
                    WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent.rut), '.', ''), '-', ''), ' ', '') = :rut_llee_cmp_1
                      AND et_recent.id_servicio = base.id_servicio
                    ORDER BY et_recent.fecha_evaluacion DESC, et_recent.id DESC
                    LIMIT 1
                ), emp.nombre) AS empresa,
                COALESCE((
                    SELECT NULLIF(TRIM(COALESCE(et_recent.cargo, '')), '')
                    FROM ceo_evaluacion_terreno et_recent
                    WHERE REPLACE(REPLACE(REPLACE(UPPER(et_recent.rut), '.', ''), '-', ''), ' ', '') = :rut_llee_cmp_2
                      AND et_recent.id_servicio = base.id_servicio
                    ORDER BY et_recent.fecha_evaluacion DESC, et_recent.id DESC
                    LIMIT 1
                ), cargo.cargo) AS cargo,
                ct.id_cargo,
                CASE
                    WHEN (
                        SELECT rpi2.id_evaluador
                        FROM ceo_resultado_prueba_intento rpi2
                        WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(base.rut), '.', ''), '-', ''), ' ', '')
                          AND rpi2.id_servicio = base.id_servicio
                          AND rpi2.fecha_rendicion = base.fecha_rendicion
                        ORDER BY COALESCE(rpi2.hora_rendicion, '00:00:00') DESC, rpi2.id DESC
                        LIMIT 1
                    ) IS NULL THEN 'Carga histórica'
                    ELSE (
                        SELECT TRIM(CONCAT(COALESCE(usr2.nombres, ''), ' ', COALESCE(usr2.apellidos, '')))
                        FROM ceo_resultado_prueba_intento rpi2
                        INNER JOIN ceo_usuarios usr2 ON usr2.id = rpi2.id_evaluador
                        WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(base.rut), '.', ''), '-', ''), ' ', '')
                          AND rpi2.id_servicio = base.id_servicio
                          AND rpi2.fecha_rendicion = base.fecha_rendicion
                        ORDER BY COALESCE(rpi2.hora_rendicion, '00:00:00') DESC, rpi2.id DESC
                        LIMIT 1
                    )
                END AS evaluador,
                uo.desc_uo AS uo,
                '' AS region,
                'HABILITACION' AS origen
            FROM (
                SELECT
                    rpt.rut,
                    rpt.fecha_rendicion,
                    COALESCE(MAX(rpt.hora_rendicion), '00:00:00') AS hora_rendicion,
                    rpt.intento,
                    ps.id_servicio,
                    ps.id_agrupacion,
                    ROUND((SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) AS porcentaje,
                    CASE
                        WHEN ROUND((SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) >= CASE WHEN ps.id_agrupacion = :hotline_group_id_result THEN :hotline_min_pct_result ELSE :theory_min_pct_result END THEN 'APROBADO'
                        ELSE 'REPROBADO'
                    END AS resultado_mostrado,
                    CASE WHEN ps.id_agrupacion = :hotline_group_id_label THEN 'Hotline' ELSE 'LLEE' END AS detalle_prueba
                FROM ceo_resultado_pruebat rpt
                INNER JOIN ceo_preguntas_servicios ps
                    ON ps.id = rpt.id_pregunta
                   AND ps.id_servicio = :llee_service_id
                   AND ps.id_agrupacion IN (:hotline_group_id_filter, :theory_group_id_filter)
                WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), '.', ''), '-', ''), ' ', '') = :rut_llee
                GROUP BY rpt.rut, rpt.fecha_rendicion, rpt.intento, ps.id_servicio, ps.id_agrupacion
                HAVING COUNT(*) > 0
            ) base
            INNER JOIN ceo_servicios_pruebas ps ON ps.id = base.id_servicio
            LEFT JOIN ceo_contratistas ct ON ct.rut = base.rut
            LEFT JOIN ceo_empresas emp ON emp.id = ct.id_empresa
            LEFT JOIN ceo_cargo_contratistas cargo ON cargo.id = ct.id_cargo
            LEFT JOIN ceo_uo uo ON uo.id = ct.uo
            ORDER BY fecha_hora DESC, detalle_prueba ASC
        ");
        $stmtLlee->execute([
            ':rut_llee_cmp_1' => $rutNormalizado,
            ':rut_llee_cmp_2' => $rutNormalizado,
            ':hotline_group_id_result' => (int)($lleeConfig['hotline_group_id'] ?? 0),
            ':hotline_min_pct_result' => (float)($lleeConfig['hotline_min_pct'] ?? 100.0),
            ':theory_min_pct_result' => (float)($lleeConfig['theory_min_pct'] ?? 80.0),
            ':hotline_group_id_label' => (int)($lleeConfig['hotline_group_id'] ?? 0),
            ':llee_service_id' => (int)($lleeConfig['service_id'] ?? 0),
            ':hotline_group_id_filter' => (int)($lleeConfig['hotline_group_id'] ?? 0),
            ':theory_group_id_filter' => (int)($lleeConfig['theory_group_id'] ?? 0),
            ':rut_llee' => $rutNormalizado,
        ]);

        $rowsSinTeoricaLlee = [];
        foreach ($rows as $row) {
            if (
                strtoupper(trim((string)($row['tipo_evaluacion'] ?? ''))) === 'TEORICA'
                && (int)($row['id_servicio'] ?? 0) === (int)($lleeConfig['service_id'] ?? 0)
            ) {
                continue;
            }
            $rowsSinTeoricaLlee[] = $row;
        }

        foreach ($stmtLlee->fetchAll(PDO::FETCH_ASSOC) as $rowLlee) {
            $idAgrupacionLlee = isset($rowLlee['id_agrupacion']) ? (int)$rowLlee['id_agrupacion'] : 0;
            $puntajeLlee = isset($rowLlee['puntaje_total']) ? (float)$rowLlee['puntaje_total'] : null;
            $minimoLlee = $idAgrupacionLlee === (int)($lleeConfig['hotline_group_id'] ?? 0)
                ? (float)($lleeConfig['hotline_min_pct'] ?? 100.0)
                : (float)($lleeConfig['theory_min_pct'] ?? 80.0);
            $rowsSinTeoricaLlee[] = [
                'tipo_evaluacion' => 'TEORICA',
                'servicio' => (string)($rowLlee['servicio'] ?? 'LLEE'),
                'id_servicio' => (int)($rowLlee['id_servicio'] ?? 0),
                'id_proceso_habilitacion' => isset($rowLlee['id_proceso_habilitacion']) ? (int)$rowLlee['id_proceso_habilitacion'] : null,
                'numero_proceso' => $rowLlee['numero_proceso'],
                'fecha_hora' => (string)($rowLlee['fecha_hora'] ?? ''),
                'resultado_mostrado' => (string)($rowLlee['resultado_mostrado'] ?? ''),
                'nota_mostrada' => $puntajeLlee !== null ? round(calcularNotaFinalDesdePorcentaje($puntajeLlee, $minimoLlee), 2) : null,
                'id_cargo' => isset($rowLlee['id_cargo']) ? (int)$rowLlee['id_cargo'] : null,
                'empresa' => (string)($rowLlee['empresa'] ?? ''),
                'cargo' => (string)($rowLlee['cargo'] ?? ''),
                'evaluador' => (string)($rowLlee['evaluador'] ?? ''),
                'uo' => (string)($rowLlee['uo'] ?? ''),
                'region' => '',
                'origen' => (string)($rowLlee['origen'] ?? 'HABILITACION'),
                'detalle_prueba' => (string)($rowLlee['detalle_prueba'] ?? ''),
            ];
        }

        $rows = $rowsSinTeoricaLlee;
        usort($rows, static function (array $a, array $b): int {
            $servicioCmp = strcasecmp((string)($a['servicio'] ?? ''), (string)($b['servicio'] ?? ''));
            if ($servicioCmp !== 0) {
                return $servicioCmp;
            }
            return strcmp((string)($b['fecha_hora'] ?? ''), (string)($a['fecha_hora'] ?? ''));
        });
    }

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

    $resumenServicios = buildInferredStatusSummary($pdo, $rutNormalizado, $rows, $terrainNotesByService, $terrainThresholdsByService);
    $rowsByProcess = buildProcessHistoryRows($rows);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Historial Evaluaciones | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.table thead th { background:#eaf2fb; }
.search-feedback { font-size:.82rem; color:#dc3545; margin-top:.35rem; display:none; }
.search-results-box { display:none; }
.search-results-box .table td,
.search-results-box .table th { vertical-align:middle; }
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;">
      <div>
        <div class="fw-bold"><?= APP_NAME ?></div>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="<?= APP_BASE ?>/public/general.php"
       class="btn btn-outline-secondary btn-sm">
       ← Volver
    </a>
  </div>
</header>

<div class="container-fluid px-4">

<div class="card shadow-sm mb-3">
  <div class="card-body d-flex justify-content-between align-items-center">
    <h5 class="fw-bold text-primary mb-0">
      <i class="bi bi-person-lines-fill me-2"></i>Historial de Evaluaciones por Persona
    </h5>

    <?php if ($rut): ?>
    <a href="historial_evaluaciones_persona_excel.php?rut=<?= urlencode($rut) ?>"
       class="btn btn-success btn-sm">
       <i class="bi bi-file-earmark-excel"></i> Exportar Excel
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" id="formBusquedaHistorialEvaluaciones" autocomplete="off">
      <div class="col-md-5">
        <input type="hidden" name="rut" id="rutSeleccionadoEvaluaciones" value="<?= esc($rut) ?>">
        <input type="text" id="buscadorAlumnoEvaluaciones" value="<?= esc($rut) ?>" class="form-control" placeholder="Buscar alumno por RUT, nombre o apellido" required>
        <div id="feedbackAlumnoEvaluaciones" class="search-feedback">Seleccione un alumno de la lista.</div>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-outline-primary" type="button" id="btnBuscarAlumnoEvaluaciones">
          <i class="bi bi-search"></i> Buscar coincidencias
        </button>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-journal-text"></i> Ver historial
        </button>
      </div>
    </form>
    <div id="resultadosAlumnoEvaluacionesBox" class="search-results-box mt-3"></div>
  </div>
</div>

<?php if ($rut): ?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="mb-3">
      <h6 class="text-primary mb-2"><i class="bi bi-person me-2"></i>Persona consultada</h6>
      <div><strong>RUT:</strong> <?= esc($rutNormalizado) ?></div>
      <?php if ($persona): ?>
        <div><strong>Nombre:</strong> <?= esc(trim((string)$persona['nombre'] . ' ' . (string)$persona['apellidos'])) ?></div>
      <?php else: ?>
        <div class="text-muted">Nombre no disponible en ceo_contratistas.</div>
      <?php endif; ?>
    </div>
    <?php if (!empty($resumenServicios)): ?>
      <div class="mb-4">
        <h6 class="text-primary mb-2"><i class="bi bi-shield-check me-2"></i>Estado inferido por servicio</h6>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="text-center">
              <tr>
                <th>Servicio</th>
                <th>Proceso</th>
                <th>Cargo</th>
                <th>Detalle teórica</th>
                <th>Nota teórica</th>
                <th>Última teórica</th>
                <th>Resultado teórica</th>
                <?php if ($esAdmin): ?><th>Acción</th><?php endif; ?>
                <th>Nota terreno</th>
                <th>Último terreno</th>
                <th>Resultado terreno</th>
                <th>Nota final</th>
                <th>Fecha habilitación</th>
                <th>Vigencia hasta</th>
                <th>Vigencia</th>
                <th>Estado inferido</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($resumenServicios as $resumen): ?>
              <?php
                $estadoBadge = 'secondary';
                if ($resumen['estado_inferido'] === 'Habilitado') {
                  $estadoBadge = 'success';
                } elseif ($resumen['estado_inferido'] === 'No Habilitado') {
                  $estadoBadge = 'danger';
                }
                $notaFinalPonderada = isset($resumen['nota_final_ponderada']) && is_numeric((string)$resumen['nota_final_ponderada'])
                  ? (float)$resumen['nota_final_ponderada']
                  : null;
                $cumpleNotaMinimaVigencia = $notaFinalPonderada !== null && $notaFinalPonderada >= 4.0;
                $mostrarFechasHabilitacion = $cumpleNotaMinimaVigencia;
                $vigenciaActiva = $cumpleNotaMinimaVigencia
                  && ($resumen['vigencia_hasta'] ?? null) instanceof DateTimeImmutable
                  && (new DateTimeImmutable('today')) <= $resumen['vigencia_hasta'];
                $vigenciaBadge = $vigenciaActiva ? 'success' : 'danger';
                $vigenciaTexto = $vigenciaActiva ? 'Vigente' : 'No Vigente';
              ?>
              <tr>
                <td><?= esc((string)$resumen['servicio']) ?></td>
                <td class="text-center"><?= esc($resumen['numero_proceso'] !== null ? (string)$resumen['numero_proceso'] : '') ?></td>
                <td><?= esc((string)($resumen['cargo'] ?? '')) ?></td>
                <td><?= esc((int)($resumen['id_servicio'] ?? 0) === (int)(ceoGetLleeConfig()['service_id'] ?? 0) ? 'LLEE / Hotline' : 'Teórica') ?></td>
                <td><?= esc(formatearNotaHistorial($resumen['ultima_teorica_nota'] ?? null)) ?></td>
                <td><?= esc(formatearFechaHistorial($resumen['ultima_teorica_fecha'] ?? null, true)) ?></td>
                <td><?= esc((string)($resumen['ultima_teorica_resultado'] ?? '')) ?></td>
                <?php if ($esAdmin): ?>
                <td class="text-center">
                  <?php if (($resumen['ultima_teorica_origen'] ?? '') === 'HABILITACION' && !empty($resumen['ultima_teorica_fecha']) && (int)($resumen['id_servicio'] ?? 0) > 0 && (int)($resumen['ultima_teorica_id_proceso_habilitacion'] ?? 0) > 0): ?>
                    <button
                      type="button"
                      class="btn btn-outline-danger btn-sm btn-reiniciar-prueba-hab"
                      data-rut="<?= esc($rutNormalizado) ?>"
                      data-id-servicio="<?= (int)$resumen['id_servicio'] ?>"
                      data-id-proceso-habilitacion="<?= (int)$resumen['ultima_teorica_id_proceso_habilitacion'] ?>"
                      data-servicio="<?= esc((string)$resumen['servicio']) ?>"
                    >Reiniciar</button>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?= esc(formatearNotaHistorial($resumen['ultima_practica_nota'] ?? null)) ?></td>
                <td><?= esc(formatearFechaHistorial($resumen['ultima_practica_fecha'] ?? null, true)) ?></td>
                <td><?= esc((string)($resumen['ultima_practica_resultado'] ?? '')) ?></td>
                <td><?= esc(formatearNotaHistorial($resumen['nota_final_ponderada'] ?? null)) ?></td>
                <td><?= esc($mostrarFechasHabilitacion ? formatearFechaHistorial($resumen['fecha_habilitacion'] ?? null) : '') ?></td>
                <td><?= esc($mostrarFechasHabilitacion ? formatearFechaHistorial($resumen['vigencia_hasta'] ?? null) : '') ?></td>
                <td><span class="badge text-bg-<?= esc($vigenciaBadge) ?>"><?= esc($vigenciaTexto) ?></span></td>
                <td><span class="badge text-bg-<?= esc($estadoBadge) ?>"><?= esc((string)$resumen['estado_inferido']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <?php if (empty($rowsByProcess)): ?>
      <div class="text-muted">No hay historial de evaluaciones para este RUT.</div>
    <?php else: ?>
      <div class="mb-2">
        <h6 class="text-primary mb-2"><i class="bi bi-clock-history me-2"></i>Historial detallado por proceso</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead class="text-center">
            <tr>
              <th>Servicio</th>
              <th>Proceso</th>
              <th>Empresa</th>
              <th>Cargo</th>
              <th>Detalle teórica</th>
              <th>Última teórica</th>
              <th>Resultado teórica</th>
              <th>Nota teórica</th>
              <th>Eval. teórica</th>
              <th>Último terreno</th>
              <th>Resultado terreno</th>
              <th>Nota terreno</th>
              <th>Eval. terreno</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rowsByProcess as $item): ?>
            <?php
              $practica = $item['practica']['row'] ?? null;
              $teoricas = $item['teoricas'] ?? [];
              if (empty($teoricas)) {
                $teoricas = [
                  ['row' => null, 'detalle_prueba' => '']
                ];
              }
            ?>
            <?php foreach ($teoricas as $teoricaItem): ?>
              <?php $teorica = $teoricaItem['row'] ?? null; ?>
              <tr>
                <td><?= esc((string)$item['servicio']) ?></td>
                <td class="text-center"><?= esc((string)($item['numero_proceso'] ?? '')) ?></td>
                <td><?= esc((string)($item['empresa'] ?? '')) ?></td>
                <td><?= esc((string)($item['cargo'] ?? '')) ?></td>
                <td><?= esc((string)($teoricaItem['detalle_prueba'] ?? '')) ?></td>
                <td class="text-center"><?= esc(formatearFechaHistorial($teorica['fecha_hora'] ?? null, true)) ?></td>
                <td class="text-center"><?= esc((string)($teorica['resultado_mostrado'] ?? '')) ?></td>
                <td class="text-center"><?= esc($teorica ? obtenerNotaDetalleHistorial($teorica, $terrainThresholdsByService ?? []) : '') ?></td>
                <td><?= esc((string)($teorica['evaluador'] ?? '')) ?></td>
                <td class="text-center"><?= esc(formatearFechaHistorial($practica['fecha_hora'] ?? null, true)) ?></td>
                <td class="text-center"><?= esc((string)($practica['resultado_mostrado'] ?? '')) ?></td>
                <td class="text-center"><?= esc($practica ? obtenerNotaDetalleHistorial($practica, $terrainThresholdsByService ?? []) : '') ?></td>
                <td><?= esc((string)($practica['evaluador'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div>
<script>
(() => {
  const form = document.getElementById('formBusquedaHistorialEvaluaciones');
  const input = document.getElementById('buscadorAlumnoEvaluaciones');
  const hidden = document.getElementById('rutSeleccionadoEvaluaciones');
  const btnBuscar = document.getElementById('btnBuscarAlumnoEvaluaciones');
  const resultsBox = document.getElementById('resultadosAlumnoEvaluacionesBox');
  const feedback = document.getElementById('feedbackAlumnoEvaluaciones');
  let selectedRut = hidden.value.trim();

  function normalizarRut(value) {
    return String(value || '').replace(/\s+/g, '');
  }

  function hideResults() {
    resultsBox.style.display = 'none';
    resultsBox.innerHTML = '';
  }

  function hideFeedback() {
    feedback.style.display = 'none';
  }

  function showFeedback(message) {
    feedback.textContent = message;
    feedback.style.display = 'block';
  }

  document.querySelectorAll('.btn-reiniciar-prueba-hab').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const rut = btn.dataset.rut || '';
      const idServicio = Number(btn.dataset.idServicio || '0');
      const idProcesoHabilitacion = Number(btn.dataset.idProcesoHabilitacion || '0');
      const servicio = btn.dataset.servicio || '';

      const ok = window.confirm(
        'Se eliminará el resultado de la última prueba teórica de habilitación para el servicio ' + servicio + '.\n\n' +
        'El alumno quedará pendiente para rendir nuevamente.\n\n¿Continuar?'
      );
      if (!ok) {
        return;
      }

      btn.disabled = true;

      try {
        const resp = await fetch('ajax_habilitacion_reiniciar_prueba.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            rut: rut,
            id_servicio: idServicio,
            id_proceso_habilitacion: idProcesoHabilitacion
          })
        });

        const data = await resp.json();
        if (!data || !data.ok) {
          window.alert((data && data.msg) ? data.msg : 'No se pudo reiniciar la prueba.');
          btn.disabled = false;
          return;
        }

        window.alert(data.msg || 'Prueba reiniciada correctamente.');
        window.location.reload();
      } catch (err) {
        window.alert('No se pudo reiniciar la prueba.');
        btn.disabled = false;
      }
    });
  });

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderItems(items) {
    if (!items.length) {
      resultsBox.innerHTML = '<div class="alert alert-light border mb-0">No se encontraron alumnos.</div>';
      resultsBox.style.display = 'block';
      return;
    }

    let html = '';
    html += '<div class="small text-muted mb-2">Se encontraron ' + items.length + ' resultado(s).</div>';
    html += '<div class="table-responsive">';
    html += '<table class="table table-sm table-hover align-middle mb-0">';
    html += '<thead class="table-light"><tr>';
    html += '<th>RUT</th><th>Nombre</th><th>Apellido</th><th>Estado</th><th></th>';
    html += '</tr></thead><tbody>';
    items.forEach((item) => {
      const estadoClass = item.tiene_historial ? 'success' : 'secondary';
      html += '<tr>';
      html += '<td>' + escapeHtml(item.rut) + '</td>';
      html += '<td>' + escapeHtml(item.nombre || '') + '</td>';
      html += '<td>' + escapeHtml(item.apellido || '') + '</td>';
      html += '<td><span class="badge text-bg-' + estadoClass + '">' + escapeHtml(item.estado) + '</span></td>';
      html += '<td class="text-end"><button type="button" class="btn btn-primary btn-sm btn-select-resultado" data-rut="' + escapeHtml(item.rut) + '" data-label="' + escapeHtml(item.label) + '">Seleccionar</button></td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    resultsBox.innerHTML = html;
    resultsBox.style.display = 'block';

    resultsBox.querySelectorAll('.btn-select-resultado').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedRut = normalizarRut(btn.dataset.rut || '');
        hidden.value = selectedRut;
        input.value = btn.dataset.label || selectedRut;
        hideFeedback();
        form.requestSubmit();
      });
    });
  }

  async function search() {
    const q = input.value.trim();
    if (q.length < 2) {
      hideResults();
      showFeedback('Ingrese al menos 2 caracteres para buscar.');
      return;
    }

    hideFeedback();
    resultsBox.innerHTML = '<div class="text-muted">Buscando alumnos...</div>';
    resultsBox.style.display = 'block';

    try {
      const resp = await fetch(`ajax_buscar_alumno_historial.php?tipo=evaluaciones&q=${encodeURIComponent(q)}`);
      const data = await resp.json();
      if (!data.ok) {
        hideResults();
        showFeedback('No se pudieron cargar las coincidencias.');
        return;
      }
      renderItems(data.items || []);
    } catch (err) {
      hideResults();
      showFeedback('No se pudieron cargar las coincidencias.');
    }
  }

  input.addEventListener('input', () => {
    selectedRut = '';
    hidden.value = '';
    hideFeedback();
    hideResults();
  });

  btnBuscar.addEventListener('click', search);

  form.addEventListener('submit', (e) => {
    const q = input.value.trim();
    const qNormalizado = normalizarRut(q);
    if (!q) {
      e.preventDefault();
      showFeedback('Ingrese un RUT, nombre o apellido.');
      return;
    }
    if (/^\d{7,8}-[\dkK]$/.test(qNormalizado)) {
      selectedRut = qNormalizado.toUpperCase();
      hidden.value = selectedRut;
      hideFeedback();
      return;
    }
    if (!selectedRut || !hidden.value || hidden.value !== selectedRut) {
      e.preventDefault();
      showFeedback('Seleccione un alumno de la lista.');
    }
  });
})();
</script>
</body>
</html>
