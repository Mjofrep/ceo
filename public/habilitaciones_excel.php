<?php
declare(strict_types=1);

session_start();

require_once '../config/db.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$rawCuadrillas = trim((string)($_POST['cuadrillas'] ?? $_GET['cuadrillas'] ?? ''));
$cuadrillas = array_values(array_unique(array_filter(array_map(
    static fn(string $value): int => (int)trim($value),
    explode(',', $rawCuadrillas)
), static fn(int $value): bool => $value > 0)));

if ($cuadrillas === []) {
    exit('Debe seleccionar al menos una cuadrilla.');
}

$pdo = db();

$rolUsuario = strtolower((string)($_SESSION['auth']['rol'] ?? ''));
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');

function hxFormatDateTime(mixed $value, bool $withTime = false): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format($withTime ? 'd-m-Y H:i' : 'd-m-Y');
    }

    $text = trim((string)$value);
    if ($text === '' || str_starts_with($text, '0000-00-00')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($text))->format($withTime ? 'd-m-Y H:i' : 'd-m-Y');
    } catch (Throwable $e) {
        return '';
    }
}

function hxFormatNote(mixed $value): string
{
    if (!is_numeric((string)$value)) {
        return '';
    }

    return number_format((float)$value, 2, '.', '');
}

function hxNormalizeRut(string $rut): string
{
    return preg_replace('/\s+/', '', str_replace(['.', '-'], '', strtoupper(trim($rut)))) ?: '';
}

function hxResolveWeights(?string $cargo, ?int $idCargo = null): ?array
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

function hxBuildSummaryByRutService(array $rows, array $terrainNotesByRutService, array $terrainThresholdsByService): array
{
    $summary = [];

    foreach ($rows as $row) {
        $rut = hxNormalizeRut((string)($row['rut'] ?? ''));
        $serviceId = isset($row['id_servicio']) ? (int)$row['id_servicio'] : 0;
        $serviceName = trim((string)($row['servicio'] ?? ''));
        if ($rut === '' || $serviceId <= 0 || $serviceName === '') {
            continue;
        }

        $key = $rut . '|' . $serviceId;
        if (!isset($summary[$key])) {
            $summary[$key] = [
                'rut' => $rut,
                'servicio' => $serviceName,
                'id_servicio' => $serviceId,
                'numero_proceso' => null,
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'id_cargo' => isset($row['id_cargo']) ? (int)$row['id_cargo'] : null,
                'ultima_teorica_fecha' => null,
                'ultima_teorica_resultado' => null,
                'ultima_teorica_nota' => null,
                'ultima_teorica_proceso' => null,
                'ultima_practica_fecha' => null,
                'ultima_practica_resultado' => null,
                'ultima_practica_nota' => null,
                'ultima_practica_proceso' => null,
                'nota_final_ponderada' => null,
                'fecha_habilitacion' => null,
                'vigencia_hasta' => null,
                'estado_inferido' => 'No Habilitado',
            ];
        } elseif ($summary[$key]['cargo'] === '' && trim((string)($row['cargo'] ?? '')) !== '') {
            $summary[$key]['cargo'] = trim((string)$row['cargo']);
            $summary[$key]['id_cargo'] = isset($row['id_cargo']) ? (int)$row['id_cargo'] : ($summary[$key]['id_cargo'] ?? null);
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
            if ($summary[$key]['ultima_teorica_fecha'] === null || $dt > $summary[$key]['ultima_teorica_fecha']) {
                $summary[$key]['ultima_teorica_fecha'] = $dt;
                $summary[$key]['ultima_teorica_resultado'] = $resultado;
                $summary[$key]['ultima_teorica_nota'] = $nota;
                $summary[$key]['ultima_teorica_proceso'] = isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null;
            }
            continue;
        }

        if ($tipo === 'PRACTICA') {
            if ($summary[$key]['ultima_practica_fecha'] === null || $dt > $summary[$key]['ultima_practica_fecha']) {
                $terrainNote = null;
                if (isset($terrainNotesByRutService[$key])) {
                    $terrainNote = $terrainNotesByRutService[$key];
                } elseif ($nota !== null) {
                    $minimum = (float)($terrainThresholdsByService[$serviceId] ?? 80.0);
                    $terrainNote = calcularNotaFinalDesdePorcentaje($nota, $minimum);
                }

                $summary[$key]['ultima_practica_fecha'] = $dt;
                $summary[$key]['ultima_practica_resultado'] = $resultado;
                $summary[$key]['ultima_practica_nota'] = $terrainNote;
                $summary[$key]['ultima_practica_proceso'] = isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null;
            }
        }
    }

    $today = new DateTimeImmutable('today');
    foreach ($summary as $key => $data) {
        $summary[$key]['numero_proceso'] = $data['ultima_practica_proceso'] ?? ($data['ultima_teorica_proceso'] ?? null);
        $weights = hxResolveWeights($data['cargo'] ?? '', isset($data['id_cargo']) ? (int)$data['id_cargo'] : null);
        $theory = $data['ultima_teorica_nota'];
        $terrain = $data['ultima_practica_nota'];

        if ($weights !== null && $theory !== null && $terrain !== null && $data['ultima_practica_fecha'] instanceof DateTimeImmutable) {
            $final = round(($theory * $weights['teorica']) + ($terrain * $weights['terreno']), 2);
            $summary[$key]['nota_final_ponderada'] = $final;
            $summary[$key]['fecha_habilitacion'] = $data['ultima_practica_fecha'];
            $summary[$key]['vigencia_hasta'] = $data['ultima_practica_fecha']->modify('+3 years');
            $summary[$key]['estado_inferido'] = ($final >= 4.0 && $today <= $summary[$key]['vigencia_hasta'])
                ? 'Habilitado'
                : 'No Habilitado';
        }
    }

    return $summary;
}

$placeholders = implode(',', array_fill(0, count($cuadrillas), '?'));
$params = $cuadrillas;

$sqlParticipants = "
    SELECT
        h.cuadrilla,
        h.id_servicio,
        sp.servicio,
        e.nombre AS empresa,
        p.rut,
        TRIM(CONCAT(COALESCE(c.nombre, p.nombre, ''), ' ', COALESCE(c.apellidos, p.apellidos, ''))) AS nombre_apellido,
        COALESCE(NULLIF(TRIM(et_ult.cargo), ''), cc.cargo, p.cargo, '') AS cargo,
        c.id_cargo
    FROM ceo_habilitacion_participantes p
    INNER JOIN ceo_habilitacion h ON h.cuadrilla = p.id_cuadrilla
    INNER JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
    INNER JOIN ceo_empresas e ON e.id = h.empresa
    LEFT JOIN ceo_contratistas c
      ON REPLACE(REPLACE(REPLACE(UPPER(c.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(p.rut), '.', ''), '-', ''), ' ', '')
    LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
    LEFT JOIN ceo_evaluacion_terreno et_ult
      ON et_ult.id = (
          SELECT et2.id
          FROM ceo_evaluacion_terreno et2
          WHERE et2.id_servicio = h.id_servicio
            AND REPLACE(REPLACE(REPLACE(UPPER(et2.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(p.rut), '.', ''), '-', ''), ' ', '')
          ORDER BY et2.fecha_evaluacion DESC, et2.id DESC
          LIMIT 1
      )
    WHERE h.cuadrilla IN ($placeholders)
";

if ($esContratista) {
    $sqlParticipants .= ' AND h.empresa = ?';
    $params[] = $idEmpresaUser;
}

$sqlParticipants .= ' ORDER BY h.cuadrilla ASC, nombre_apellido ASC, p.rut ASC';

$stmtParticipants = $pdo->prepare($sqlParticipants);
$stmtParticipants->execute($params);
$participants = $stmtParticipants->fetchAll(PDO::FETCH_ASSOC);

if ($participants === []) {
    exit('No se encontraron participantes para las cuadrillas seleccionadas.');
}

$rutMap = [];
$serviceIds = [];
foreach ($participants as $participant) {
    $rut = hxNormalizeRut((string)($participant['rut'] ?? ''));
    $serviceId = (int)($participant['id_servicio'] ?? 0);
    if ($rut === '' || $serviceId <= 0) {
        continue;
    }
    $rutMap[$rut] = true;
    $serviceIds[$serviceId] = true;
}

$ruts = array_keys($rutMap);
$serviceIds = array_map('intval', array_keys($serviceIds));

$historyRows = [];
$terrainNotesByRutService = [];
$terrainThresholdsByService = [];

if ($ruts !== [] && $serviceIds !== []) {
    $rutPlaceholders = implode(',', array_fill(0, count($ruts), '?'));
    $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $historyParams = array_merge($ruts, $serviceIds, $ruts, $serviceIds);

    $stmtHistory = $pdo->prepare(" 
        SELECT *
        FROM (
            SELECT
                'TEORICA' AS tipo_evaluacion,
                rpi.rut,
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
                cargo.cargo AS cargo
            FROM ceo_resultado_prueba_intento rpi
            INNER JOIN ceo_servicios_pruebas sp ON sp.id = rpi.id_servicio
            LEFT JOIN ceo_contratistas ct ON ct.rut = rpi.rut
            LEFT JOIN ceo_cargo_contratistas cargo ON cargo.id = ct.id_cargo
            LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rpi.id_proceso_habilitacion
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '') IN ($rutPlaceholders)
              AND rpi.id_servicio IN ($servicePlaceholders)

            UNION ALL

            SELECT
                'PRACTICA' AS tipo_evaluacion,
                et.rut,
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
                COALESCE(et.cargo, cargo2.cargo) AS cargo
            FROM ceo_evaluacion_terreno et
            INNER JOIN ceo_servicios_pruebas sp2 ON sp2.id = et.id_servicio
            LEFT JOIN ceo_contratistas ct2 ON ct2.rut = et.rut
            LEFT JOIN ceo_cargo_contratistas cargo2 ON cargo2.id = ct2.id_cargo
            LEFT JOIN ceo_proceso_habilitacion ph2 ON ph2.id = et.id_proceso_habilitacion
            WHERE REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '') IN ($rutPlaceholders)
              AND et.id_servicio IN ($servicePlaceholders)
        ) historial
        ORDER BY servicio ASC, fecha_hora DESC, tipo_evaluacion ASC
    ");
    $stmtHistory->execute($historyParams);
    $historyRows = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    $notesParams = array_merge($ruts, $serviceIds);
    $stmtTerrainNotes = $pdo->prepare(" 
        SELECT rut, id_servicio, fecha_rendicion, hora_rendicion, notafinal
        FROM ceo_resultado_terreno_intento
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') IN ($rutPlaceholders)
          AND id_servicio IN ($servicePlaceholders)
        ORDER BY rut ASC, id_servicio ASC, fecha_rendicion DESC, hora_rendicion DESC, id DESC
    ");
    $stmtTerrainNotes->execute($notesParams);
    foreach ($stmtTerrainNotes->fetchAll(PDO::FETCH_ASSOC) as $terrainRow) {
        $key = hxNormalizeRut((string)$terrainRow['rut']) . '|' . (int)$terrainRow['id_servicio'];
        if (isset($terrainNotesByRutService[$key])) {
            continue;
        }
        $terrainNotesByRutService[$key] = isset($terrainRow['notafinal']) ? (float)$terrainRow['notafinal'] : null;
    }

    $stmtThresholds = $pdo->prepare(" 
        SELECT a.id_servicio, p.porcentaje
        FROM ceo_agrupacion_terreno a
        INNER JOIN ceo_porcentaje_agrup_terreno p ON p.id_agrupacion = a.id
        WHERE p.activo = 'S'
          AND a.id_servicio IN ($servicePlaceholders)
        ORDER BY a.id_servicio ASC, p.fechadesde DESC
    ");
    $stmtThresholds->execute($serviceIds);
    foreach ($stmtThresholds->fetchAll(PDO::FETCH_ASSOC) as $thresholdRow) {
        $serviceId = (int)$thresholdRow['id_servicio'];
        if ($serviceId > 0 && !isset($terrainThresholdsByService[$serviceId])) {
            $terrainThresholdsByService[$serviceId] = (float)$thresholdRow['porcentaje'];
        }
    }
}

$summaryByRutService = hxBuildSummaryByRutService($historyRows, $terrainNotesByRutService, $terrainThresholdsByService);

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename=habilitaciones_cuadrillas.xls');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
echo "<html><head><meta charset='utf-8'></head><body>";
echo "<table border='1'>";
echo '<tr><th colspan="17">Resultados de habilitaciones por cuadrilla</th></tr>';
echo '<tr>';
foreach ([
    'Cuadrilla',
    'Empresa',
    'Nombre / Apellido',
    'Rut',
    'Cargo',
    'Servicio',
    'Proceso',
    'Nota teórica',
    'Última teórica',
    'Resultado teórica',
    'Nota terreno',
    'Último terreno',
    'Resultado terreno',
    'Nota final',
    'Fecha habilitación',
    'Vigencia hasta',
    'Estado inferido',
] as $heading) {
    echo '<th>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</th>';
}
echo '</tr>';

foreach ($participants as $participant) {
    $rut = hxNormalizeRut((string)($participant['rut'] ?? ''));
    $serviceId = (int)($participant['id_servicio'] ?? 0);
    $key = $rut . '|' . $serviceId;
    $summary = $summaryByRutService[$key] ?? null;

    echo '<tr>';
    echo '<td>' . (int)($participant['cuadrilla'] ?? 0) . '</td>';
    echo '<td>' . htmlspecialchars((string)($participant['empresa'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($participant['nombre_apellido'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($participant['rut'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($participant['cargo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($participant['servicio'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($summary !== null && $summary['numero_proceso'] !== null ? (string)$summary['numero_proceso'] : '', ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatNote($summary['ultima_teorica_nota'] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatDateTime($summary['ultima_teorica_fecha'] ?? null, true), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($summary['ultima_teorica_resultado'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatNote($summary['ultima_practica_nota'] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatDateTime($summary['ultima_practica_fecha'] ?? null, true), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($summary['ultima_practica_resultado'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatNote($summary['nota_final_ponderada'] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatDateTime($summary['fecha_habilitacion'] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(hxFormatDateTime($summary['vigencia_hasta'] ?? null), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($summary['estado_inferido'] ?? 'No Habilitado'), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}

echo '</table></body></html>';
