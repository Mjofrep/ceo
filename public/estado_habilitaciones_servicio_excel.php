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

$stmtServicio = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
$stmtServicio->execute([':id' => $idServicio]);
$nombreServicio = (string)($stmtServicio->fetchColumn() ?: 'Servicio');

$stmtTeorica = $pdo->prepare('
    SELECT rut, fecha_rendicion, hora_rendicion, puntaje_total, notafinal
    FROM ceo_resultado_prueba_intento
    WHERE id_servicio = :id_servicio
    ORDER BY rut ASC, fecha_rendicion DESC, hora_rendicion DESC, id DESC
');
$stmtTeorica->execute([':id_servicio' => $idServicio]);
$teoricas = $stmtTeorica->fetchAll(PDO::FETCH_ASSOC);

$stmtTerreno = $pdo->prepare('
    SELECT rut, fecha_evaluacion, resultado
    FROM ceo_evaluacion_terreno
    WHERE id_servicio = :id_servicio
    ORDER BY rut ASC, fecha_evaluacion DESC, id DESC
');
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
        ];
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
        ];
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

    $personas[] = [
        'rut' => $rut,
        'nombre' => trim((string)($contratista['nombre'] ?? '')),
        'apellidos' => trim((string)($contratista['apellidos'] ?? '')),
        'cargo' => trim((string)($contratista['cargo'] ?? '')),
        'empresa' => trim((string)($contratista['empresa'] ?? '')),
        'ultima_teorica' => $teo['ultima_fecha'] ?? null,
        'resultado_teorica' => $teo['ultima_resultado'] ?? '',
        'ultima_practica' => $terr['ultima_fecha'] ?? null,
        'resultado_practica' => $terr['ultima_resultado'] ?? '',
        'fecha_habilitacion' => $fechaHabilitacion,
        'vigencia_hasta' => $vigenciaHasta,
        'ultima_evaluacion' => $ultimaEvaluacion,
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
echo "<tr><th>RUT</th><th>Nombre</th><th>Apellido</th><th>Cargo</th><th>Empresa</th><th>Última teórica</th><th>Último terreno</th><th>Última evaluación</th><th>Fecha habilitación</th><th>Vigencia hasta</th><th>Estado</th></tr>";
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
    echo '<td>' . htmlspecialchars((string)$p['estado'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
