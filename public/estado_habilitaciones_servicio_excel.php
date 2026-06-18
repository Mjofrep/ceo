<?php
declare(strict_types=1);

session_start();

require_once '../config/db.php';
require_once '../config/app.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$idServicio = (int)($_GET['id_servicio'] ?? 0);
$selectedEstado = trim((string)($_GET['estado'] ?? ''));

if ($idServicio <= 0) {
    exit('Servicio requerido');
}

function parseNullableScoreExcel($value): ?float
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

function fmtDateTimeExcel(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y H:i') : '';
}

function fmtDateExcel(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y') : '';
}

function fmtAreaPercentExcel(?float $value): string
{
    if ($value === null) {
        return '';
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function resumirAnalisisAreasServicioExcel(array $areas, array $meta = []): array
{
    if (empty($areas)) {
        return $meta + [
            'tiene_detalle' => false,
            'estado' => 'sin_detalle',
            'areas_evaluadas' => 0,
            'areas_con_objetivo' => 0,
            'areas_debiles' => 0,
            'resumen_corto' => 'Sin detalle',
            'detalle' => 'Sin detalle por area.',
        ];
    }

    $areasDebiles = [];
    $areasConObjetivo = 0;

    foreach ($areas as $area) {
        $objetivo = $area['objetivo'];
        $porcentaje = $area['porcentaje'];
        if ($objetivo === null) {
            continue;
        }
        $areasConObjetivo++;
        if ($porcentaje < $objetivo) {
            $areasDebiles[] = $area;
        }
    }

    if (!empty($areasDebiles)) {
        $partes = [];
        foreach (array_slice($areasDebiles, 0, 3) as $area) {
            $partes[] = $area['area'] . ' (' . fmtAreaPercentExcel($area['porcentaje']) . '%/' . fmtAreaPercentExcel($area['objetivo']) . '%)';
        }
        if (count($areasDebiles) > 3) {
            $partes[] = '+' . (count($areasDebiles) - 3) . ' area(s)';
        }

        return $meta + [
            'tiene_detalle' => true,
            'estado' => 'debil',
            'areas_evaluadas' => count($areas),
            'areas_con_objetivo' => $areasConObjetivo,
            'areas_debiles' => count($areasDebiles),
            'resumen_corto' => (string)count($areasDebiles),
            'detalle' => implode(', ', $partes),
        ];
    }

    if ($areasConObjetivo > 0) {
        return $meta + [
            'tiene_detalle' => true,
            'estado' => 'ok',
            'areas_evaluadas' => count($areas),
            'areas_con_objetivo' => $areasConObjetivo,
            'areas_debiles' => 0,
            'resumen_corto' => '0',
            'detalle' => 'Sin debilidades detectadas.',
        ];
    }

    return $meta + [
        'tiene_detalle' => true,
        'estado' => 'sin_objetivo',
        'areas_evaluadas' => count($areas),
        'areas_con_objetivo' => 0,
        'areas_debiles' => 0,
        'resumen_corto' => 'S/O',
        'detalle' => 'Sin objetivos configurados.',
    ];
}

function cargarAnalisisAreasTeoricasPorRutExcel(PDO $pdo, int $idServicio): array
{
    $stmtIntentos = $pdo->prepare("
        SELECT
            rpt.rut,
            rpt.proceso,
            rpt.intento,
            MAX(CONCAT(COALESCE(rpt.fecha_rendicion, '0000-00-00'), ' ', COALESCE(rpt.hora_rendicion, '00:00:00'))) AS fecha_hora
        FROM ceo_resultado_pruebat rpt
        INNER JOIN ceo_preguntas_servicios ps
            ON ps.id = rpt.id_pregunta
           AND ps.id_servicio = :id_servicio
        GROUP BY rpt.rut, rpt.proceso, rpt.intento
        ORDER BY rpt.rut ASC, fecha_hora DESC, rpt.intento DESC, rpt.proceso DESC
    ");
    $stmtIntentos->execute([':id_servicio' => $idServicio]);

    $ultimoIntentoPorRut = [];
    foreach ($stmtIntentos->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rut = (string)$row['rut'];
        if (!isset($ultimoIntentoPorRut[$rut])) {
            $ultimoIntentoPorRut[$rut] = [
                'proceso' => isset($row['proceso']) ? (int)$row['proceso'] : null,
                'intento' => isset($row['intento']) ? (int)$row['intento'] : null,
                'fecha_hora' => (string)($row['fecha_hora'] ?? ''),
            ];
        }
    }

    if (empty($ultimoIntentoPorRut)) {
        return [];
    }

    $stmtAreas = $pdo->prepare("
        SELECT
            rpt.rut,
            rpt.proceso,
            rpt.intento,
            COALESCE(ac.id, 0) AS id_area,
            COALESCE(ac.descripcion, 'Sin area de competencia') AS area,
            cfg.porcentaje AS objetivo,
            SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
            SUM(CASE WHEN rpt.validacion = 0 THEN 1 ELSE 0 END) AS incorrectas,
            SUM(CASE WHEN rpt.validacion = -1 THEN 1 ELSE 0 END) AS ncontestadas,
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
        GROUP BY
            rpt.rut,
            rpt.proceso,
            rpt.intento,
            COALESCE(ac.id, 0),
            COALESCE(ac.descripcion, 'Sin area de competencia'),
            cfg.porcentaje
        ORDER BY rpt.rut ASC, area ASC
    ");
    $stmtAreas->execute([':id_servicio' => $idServicio]);

    $areasPorRut = [];
    foreach ($stmtAreas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rut = (string)$row['rut'];
        if (!isset($ultimoIntentoPorRut[$rut])) {
            continue;
        }

        $meta = $ultimoIntentoPorRut[$rut];
        if ((int)$row['proceso'] !== (int)$meta['proceso'] || (int)$row['intento'] !== (int)$meta['intento']) {
            continue;
        }

        $total = (int)($row['total'] ?? 0);
        if ($total <= 0) {
            continue;
        }

        $correctas = (int)($row['correctas'] ?? 0);
        $areasPorRut[$rut][] = [
            'id_area' => (int)($row['id_area'] ?? 0),
            'area' => (string)($row['area'] ?? ''),
            'objetivo' => $row['objetivo'] !== null ? (float)$row['objetivo'] : null,
            'correctas' => $correctas,
            'incorrectas' => (int)($row['incorrectas'] ?? 0),
            'ncontestadas' => (int)($row['ncontestadas'] ?? 0),
            'total' => $total,
            'porcentaje' => round(($correctas / $total) * 100, 2),
        ];
    }

    $resumenPorRut = [];
    foreach ($ultimoIntentoPorRut as $rut => $meta) {
        $resumenPorRut[$rut] = resumirAnalisisAreasServicioExcel($areasPorRut[$rut] ?? [], $meta);
    }

    return $resumenPorRut;
}

$stmtServicio = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
$stmtServicio->execute([':id' => $idServicio]);
$nombreServicio = (string)($stmtServicio->fetchColumn() ?: 'Servicio');
$analisisAreasPorRut = cargarAnalisisAreasTeoricasPorRutExcel($pdo, $idServicio);

$stmtTeorica = $pdo->prepare("
    SELECT rpi.rut, rpi.fecha_rendicion, rpi.hora_rendicion, rpi.puntaje_total, rpi.notafinal,
           (
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
           ) AS empresa_historica
    FROM ceo_resultado_prueba_intento rpi
    WHERE rpi.id_servicio = :id_servicio
    ORDER BY rpi.rut ASC, rpi.fecha_rendicion DESC, rpi.hora_rendicion DESC, rpi.id DESC
");
$stmtTeorica->execute([':id_servicio' => $idServicio]);
$teoricas = $stmtTeorica->fetchAll(PDO::FETCH_ASSOC);

$stmtTerreno = $pdo->prepare("
    SELECT et.rut, et.fecha_evaluacion, et.resultado,
           (
               SELECT emp_h.nombre
               FROM ceo_evaluaciones_programadas ep_h
               INNER JOIN ceo_habilitacion h ON h.cuadrilla = ep_h.cuadrilla AND h.id_servicio = ep_h.id_servicio
               LEFT JOIN ceo_empresas emp_h ON emp_h.id = h.empresa
               WHERE ep_h.id_proceso_habilitacion = et.id_proceso_habilitacion
                 AND ep_h.id_servicio = et.id_servicio
                 AND ep_h.tipo = 'TERRENO'
                 AND REPLACE(REPLACE(REPLACE(UPPER(ep_h.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
               ORDER BY ep_h.id DESC
               LIMIT 1
           ) AS empresa_historica
    FROM ceo_evaluacion_terreno et
    WHERE et.id_servicio = :id_servicio
    ORDER BY et.rut ASC, et.fecha_evaluacion DESC, et.id DESC
");
$stmtTerreno->execute([':id_servicio' => $idServicio]);
$terrenos = $stmtTerreno->fetchAll(PDO::FETCH_ASSOC);

$teoricaPorRut = [];
foreach ($teoricas as $row) {
    $rut = (string)$row['rut'];
    $fechaHora = null;
    try {
        $fechaHora = new DateTimeImmutable((string)$row['fecha_rendicion'] . ' ' . (string)$row['hora_rendicion']);
    } catch (Throwable $e) {
        $fechaHora = null;
    }
    $puntaje = isset($row['puntaje_total']) ? (float)$row['puntaje_total'] : null;
    $resultado = ($puntaje !== null && $puntaje >= 80.0) ? 'APROBADO' : 'REPROBADO';

    if (!isset($teoricaPorRut[$rut])) {
        $teoricaPorRut[$rut] = [
            'ultima_fecha' => $fechaHora,
            'ultima_resultado' => $resultado,
            'aprobada_fecha' => null,
            'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
        ];
    } elseif ($teoricaPorRut[$rut]['empresa_historica'] === '' && trim((string)($row['empresa_historica'] ?? '')) !== '') {
        $teoricaPorRut[$rut]['empresa_historica'] = trim((string)$row['empresa_historica']);
    }

    if ($resultado === 'APROBADO' && $teoricaPorRut[$rut]['aprobada_fecha'] === null) {
        $teoricaPorRut[$rut]['aprobada_fecha'] = $fechaHora;
    }
}

$terrenoPorRut = [];
foreach ($terrenos as $row) {
    $rut = (string)$row['rut'];
    $fecha = null;
    try {
        $fecha = new DateTimeImmutable((string)$row['fecha_evaluacion']);
    } catch (Throwable $e) {
        $fecha = null;
    }
    $puntaje = parseNullableScoreExcel($row['resultado'] ?? null);
    $resultado = ($puntaje !== null && $puntaje >= 80.0) ? 'APROBADO' : 'REPROBADO';

    if (!isset($terrenoPorRut[$rut])) {
        $terrenoPorRut[$rut] = [
            'ultima_fecha' => $fecha,
            'ultima_resultado' => $resultado,
            'aprobada_fecha' => null,
            'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
        ];
    } elseif ($terrenoPorRut[$rut]['empresa_historica'] === '' && trim((string)($row['empresa_historica'] ?? '')) !== '') {
        $terrenoPorRut[$rut]['empresa_historica'] = trim((string)$row['empresa_historica']);
    }

    if ($resultado === 'APROBADO' && $terrenoPorRut[$rut]['aprobada_fecha'] === null) {
        $terrenoPorRut[$rut]['aprobada_fecha'] = $fecha;
    }
}

$ruts = array_values(array_unique(array_merge(array_keys($teoricaPorRut), array_keys($terrenoPorRut))));

$contratistasMap = [];
if (!empty($ruts)) {
    $placeholders = implode(',', array_fill(0, count($ruts), '?'));
    $stmtContratistas = $pdo->prepare("
        SELECT c.rut, c.nombre, c.apellidos, cc.cargo, e.nombre AS empresa
        FROM ceo_contratistas c
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
        WHERE c.rut IN ($placeholders)
    ");
    $stmtContratistas->execute($ruts);
    foreach ($stmtContratistas->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contratistasMap[(string)$row['rut']] = $row;
    }
}

$today = new DateTimeImmutable('today');
$conteos = [
    'Habilitado' => 0,
    'Vencido' => 0,
    'Pendiente teórico' => 0,
    'Pendiente terreno' => 0,
    'Sin información suficiente' => 0,
];
$personas = [];

foreach ($ruts as $rut) {
    $teo = $teoricaPorRut[$rut] ?? null;
    $terr = $terrenoPorRut[$rut] ?? null;

    $teoAprobada = $teo !== null && $teo['aprobada_fecha'] instanceof DateTimeImmutable;
    $terrAprobada = $terr !== null && $terr['aprobada_fecha'] instanceof DateTimeImmutable;

    $fechaHabilitacion = null;
    $vigenciaHasta = null;
    $estado = 'Sin información suficiente';

    if ($teoAprobada && $terrAprobada) {
        $fechaHabilitacion = $terr['aprobada_fecha'];
        $vigenciaHasta = $fechaHabilitacion->modify('+3 years');
        $estado = $today <= $vigenciaHasta ? 'Habilitado' : 'Vencido';
    } elseif ($teoAprobada && !$terrAprobada) {
        $estado = 'Pendiente terreno';
    } elseif ($teo !== null) {
        $estado = 'Pendiente teórico';
    } elseif ($terr !== null) {
        $estado = 'Pendiente teórico';
    }

    $conteos[$estado]++;

    $contratista = $contratistasMap[$rut] ?? null;
    $ultimaEvaluacion = null;
    if (($teo['ultima_fecha'] ?? null) instanceof DateTimeImmutable && ($terr['ultima_fecha'] ?? null) instanceof DateTimeImmutable) {
        $ultimaEvaluacion = $teo['ultima_fecha'] > $terr['ultima_fecha'] ? $teo['ultima_fecha'] : $terr['ultima_fecha'];
    } elseif (($teo['ultima_fecha'] ?? null) instanceof DateTimeImmutable) {
        $ultimaEvaluacion = $teo['ultima_fecha'];
    } elseif (($terr['ultima_fecha'] ?? null) instanceof DateTimeImmutable) {
        $ultimaEvaluacion = $terr['ultima_fecha'];
    }

    $empresaHistorica = trim((string)($terr['empresa_historica'] ?? ''));
    if ($empresaHistorica === '') {
        $empresaHistorica = trim((string)($teo['empresa_historica'] ?? ''));
    }

    $analisisAreas = $analisisAreasPorRut[$rut] ?? resumirAnalisisAreasServicioExcel([]);

    $personas[] = [
        'rut' => $rut,
        'nombre' => trim((string)($contratista['nombre'] ?? '')),
        'apellidos' => trim((string)($contratista['apellidos'] ?? '')),
        'cargo' => trim((string)($contratista['cargo'] ?? '')),
        'empresa' => $empresaHistorica !== '' ? $empresaHistorica : trim((string)($contratista['empresa'] ?? '')),
        'ultima_teorica' => $teo['ultima_fecha'] ?? null,
        'resultado_teorica' => $teo['ultima_resultado'] ?? '',
        'ultima_practica' => $terr['ultima_fecha'] ?? null,
        'resultado_practica' => $terr['ultima_resultado'] ?? '',
        'fecha_habilitacion' => $fechaHabilitacion,
        'vigencia_hasta' => $vigenciaHasta,
        'ultima_evaluacion' => $ultimaEvaluacion,
        'areas_debiles' => (int)($analisisAreas['areas_debiles'] ?? 0),
        'areas_resumen_corto' => (string)($analisisAreas['resumen_corto'] ?? 'Sin detalle'),
        'areas_detalle' => (string)($analisisAreas['detalle'] ?? 'Sin detalle por area.'),
        'estado' => $estado,
    ];
}

$personasFiltradas = $selectedEstado !== ''
    ? array_values(array_filter($personas, static fn(array $p): bool => $p['estado'] === $selectedEstado))
    : $personas;

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename=estado_habilitaciones_servicio_' . $idServicio . '.xls');

echo "<table border='1'>";
echo "<tr><th colspan='7'>Resumen estado inferido - {$nombreServicio}</th></tr>";
echo "<tr><th>Estado</th><th>Cantidad</th></tr>";
foreach ($conteos as $estado => $cantidad) {
    echo "<tr><td>{$estado}</td><td>{$cantidad}</td></tr>";
}
echo "<tr><td colspan='7'></td></tr>";
echo "<tr><th>RUT</th><th>Nombre</th><th>Apellido</th><th>Cargo</th><th>Empresa</th><th>Última teórica</th><th>Último terreno</th><th>Última evaluación</th><th>Fecha habilitación</th><th>Vigencia hasta</th><th>Áreas débiles</th><th>Detalle áreas</th><th>Estado</th></tr>";
foreach ($personasFiltradas as $p) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string)$p['rut'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['apellidos'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['cargo'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['empresa'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(fmtDateTimeExcel($p['ultima_teorica']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(fmtDateTimeExcel($p['ultima_practica']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(fmtDateTimeExcel($p['ultima_evaluacion']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(fmtDateExcel($p['fecha_habilitacion']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(fmtDateExcel($p['vigencia_hasta']), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['areas_resumen_corto'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['areas_detalle'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)$p['estado'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
