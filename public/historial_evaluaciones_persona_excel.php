<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/app.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$rut = trim($_GET['rut'] ?? '');
$rutNormalizado = preg_replace('/\s+/', '', $rut);
if ($rutNormalizado === '') exit('RUT requerido');

function resolverPesosPorCargoExcel(?string $cargo, ?int $idCargo = null): ?array
{
    $operadorIds = [266, 268, 287];
    $supervisorIds = [294];

    if ($idCargo !== null) {
        if (in_array($idCargo, $supervisorIds, true)) {
            return ['teorica' => 0.6, 'terreno' => 0.4];
        }
        if (in_array($idCargo, $operadorIds, true)) {
            return ['teorica' => 0.4, 'terreno' => 0.6];
        }
    }

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
    return null;
}

function buildInferredStatusSummaryExcel(array $rows, array $terrainNotesByService, array $terrainThresholdsByService): array
{
    $summary = [];
    foreach ($rows as $row) {
        $servicio = trim((string)($row['servicio'] ?? ''));
        if ($servicio === '') {
            continue;
        }
        if (!isset($summary[$servicio])) {
            $summary[$servicio] = [
                'servicio' => $servicio,
                'id_servicio' => isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0,
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'id_cargo' => isset($row['id_cargo']) ? (int)$row['id_cargo'] : null,
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
        }
        try {
            $dt = new DateTimeImmutable(trim((string)($row['fecha_hora'] ?? '')));
        } catch (Throwable $e) {
            continue;
        }
        $tipo = strtoupper(trim((string)($row['tipo_evaluacion'] ?? '')));
        $resultado = strtoupper(trim((string)($row['resultado_mostrado'] ?? '')));
        $nota = is_numeric((string)($row['nota_mostrada'] ?? '')) ? (float)$row['nota_mostrada'] : null;
        if ($tipo === 'TEORICA') {
            if ($summary[$servicio]['ultima_teorica_fecha'] === null || $dt > $summary[$servicio]['ultima_teorica_fecha']) {
                $summary[$servicio]['ultima_teorica_fecha'] = $dt;
                $summary[$servicio]['ultima_teorica_resultado'] = $resultado;
                $summary[$servicio]['ultima_teorica_nota'] = $nota;
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
            }
        }
    }
    $today = new DateTimeImmutable('today');
    foreach ($summary as $servicio => $data) {
        $pesos = resolverPesosPorCargoExcel($data['cargo'] ?? '', isset($data['id_cargo']) ? (int)$data['id_cargo'] : null);
        $notaTeorica = $data['ultima_teorica_nota'];
        $notaTerreno = $data['ultima_practica_nota'];
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

$stmt = $pdo->prepare("
    SELECT *
    FROM (
        SELECT
            'TEORICA' AS tipo_evaluacion,
            sp.servicio AS servicio,
            rpi.id_servicio AS id_servicio,
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
            cargo.cargo AS cargo,
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
        WHERE rpi.rut = :rut_teorica

        UNION ALL

        SELECT
            'PRACTICA' AS tipo_evaluacion,
            sp2.servicio AS servicio,
            et.id_servicio AS id_servicio,
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
            COALESCE(et.cargo, cargo2.cargo) AS cargo,
            COALESCE(et.evaluador, '') AS evaluador,
            uo2.desc_uo AS uo,
            '' AS region
        FROM ceo_evaluacion_terreno et
        INNER JOIN ceo_servicios_pruebas sp2 ON sp2.id = et.id_servicio
        LEFT JOIN ceo_contratistas ct2 ON ct2.rut = et.rut
        LEFT JOIN ceo_empresas emp2 ON emp2.id = ct2.id_empresa
        LEFT JOIN ceo_cargo_contratistas cargo2 ON cargo2.id = ct2.id_cargo
        LEFT JOIN ceo_uo uo2 ON uo2.id = ct2.uo
        WHERE et.rut = :rut_terreno
    ) historial
    ORDER BY fecha_hora DESC
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

$resumenServicios = buildInferredStatusSummaryExcel($rows, $terrainNotesByService, $terrainThresholdsByService);

header('Content-Type: application/vnd.ms-excel');
header("Content-Disposition: attachment; filename=historial_evaluaciones_$rut.xls");

echo "<table border='1'>";
echo "<tr><th colspan='12'>Estado inferido por servicio</th></tr>";
echo "<tr><th>Servicio</th><th>Cargo</th><th>Nota teorica</th><th>Ultima teorica</th><th>Resultado teorica</th><th>Nota terreno</th><th>Ultimo terreno</th><th>Resultado terreno</th><th>Nota final</th><th>Fecha habilitacion</th><th>Vigencia hasta</th><th>Estado inferido</th></tr>";
foreach ($resumenServicios as $resumen) {
    $teoFecha = $resumen['ultima_teorica_fecha'] instanceof DateTimeImmutable ? $resumen['ultima_teorica_fecha']->format('Y-m-d H:i:s') : '';
    $terrFecha = $resumen['ultima_practica_fecha'] instanceof DateTimeImmutable ? $resumen['ultima_practica_fecha']->format('Y-m-d H:i:s') : '';
    $vigencia = $resumen['vigencia_hasta'] instanceof DateTimeImmutable ? $resumen['vigencia_hasta']->format('Y-m-d') : '';
    $fechaHab = $resumen['fecha_habilitacion'] instanceof DateTimeImmutable ? $resumen['fecha_habilitacion']->format('Y-m-d') : '';
    echo "<tr>
    <td>{$resumen['servicio']}</td>
    <td>{$resumen['cargo']}</td>
    <td>" . ($resumen['ultima_teorica_nota'] !== null ? number_format((float)$resumen['ultima_teorica_nota'], 2, '.', '') : '') . "</td>
    <td>{$teoFecha}</td>
    <td>{$resumen['ultima_teorica_resultado']}</td>
    <td>" . ($resumen['ultima_practica_porcentaje'] !== null ? number_format((float)$resumen['ultima_practica_porcentaje'], 2, '.', '') . '%' : '') . "</td>
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
<th>Tipo</th><th>Servicio</th><th>Fecha</th><th>Resultado</th>
<th>Nota</th><th>Empresa</th><th>Cargo</th>
<th>Evaluador</th><th>UO</th><th>Región</th>
</tr>";

foreach ($rows as $r) {
    echo "<tr>
    <td>{$r['tipo_evaluacion']}</td>
    <td>{$r['servicio']}</td>
    <td>{$r['fecha_hora']}</td>
    <td>{$r['resultado_mostrado']}</td>
    <td>{$r['nota_mostrada']}</td>
    <td>{$r['empresa']}</td>
    <td>{$r['cargo']}</td>
    <td>{$r['evaluador']}</td>
    <td>{$r['uo']}</td>
    <td>{$r['region']}</td>
    </tr>";
}
echo "</table>";
