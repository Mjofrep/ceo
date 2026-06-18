<?php
declare(strict_types=1);

session_start();

require_once '../config/db.php';
require_once '../config/app.php';
require_once '../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$pdo = db();

$servicios = $pdo->query('SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC);
$idServicio = (int)($_GET['id_servicio'] ?? 0);
if ($idServicio <= 0 && !empty($servicios)) {
    $idServicio = (int)$servicios[0]['id'];
}

$selectedEstado = trim((string)($_GET['estado'] ?? ''));
$resumen = [];
$personas = [];
$conteos = [
    'Habilitado' => 0,
    'Vencido' => 0,
    'Pendiente teórico' => 0,
    'Pendiente terreno' => 0,
    'Sin información suficiente' => 0,
];
$nombreServicio = '';

function parseNullableScore($value): ?float
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

function resolverPesosPorCargoServicio(?string $cargo, ?int $idCargo = null): ?array
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

function fmtDateTime(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y H:i') : '';
}

function fmtDate(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d-m-Y') : '';
}

function fmtAreaPercent(?float $value): string
{
    if ($value === null) {
        return '';
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function resumirAnalisisAreasServicio(array $areas, array $meta = []): array
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
            $partes[] = $area['area'] . ' (' . fmtAreaPercent($area['porcentaje']) . '%/' . fmtAreaPercent($area['objetivo']) . '%)';
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

function cargarAnalisisAreasTeoricasPorRut(PDO $pdo, int $idServicio): array
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
        $resumenPorRut[$rut] = resumirAnalisisAreasServicio($areasPorRut[$rut] ?? [], $meta);
    }

    return $resumenPorRut;
}

if ($idServicio > 0) {
    $stmtServicio = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
    $stmtServicio->execute([':id' => $idServicio]);
    $nombreServicio = (string)($stmtServicio->fetchColumn() ?: 'Servicio');
    $analisisAreasPorRut = cargarAnalisisAreasTeoricasPorRut($pdo, $idServicio);

    $stmtTeorica = $pdo->prepare("
        SELECT rpi.rut, rpi.fecha_rendicion, rpi.hora_rendicion, rpi.puntaje_total, rpi.notafinal, ph.numero_proceso,
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
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rpi.id_proceso_habilitacion
        WHERE rpi.id_servicio = :id_servicio
        ORDER BY rpi.rut ASC, rpi.fecha_rendicion DESC, rpi.hora_rendicion DESC, rpi.id DESC
    ");
    $stmtTeorica->execute([':id_servicio' => $idServicio]);
    $teoricas = $stmtTeorica->fetchAll(PDO::FETCH_ASSOC);

    $stmtTerreno = $pdo->prepare("
        SELECT et.rut, et.fecha_evaluacion, et.resultado, et.cargo, ph.numero_proceso,
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
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = et.id_proceso_habilitacion
        WHERE et.id_servicio = :id_servicio
        ORDER BY et.rut ASC, et.fecha_evaluacion DESC, et.id DESC
    ");
    $stmtTerreno->execute([':id_servicio' => $idServicio]);
    $terrenos = $stmtTerreno->fetchAll(PDO::FETCH_ASSOC);

    $stmtTerrenoIntento = $pdo->prepare('
        SELECT rut, fecha_rendicion, hora_rendicion, notafinal
        FROM ceo_resultado_terreno_intento
        WHERE id_servicio = :id_servicio
        ORDER BY rut ASC, fecha_rendicion DESC, hora_rendicion DESC, id DESC
    ');
    $stmtTerrenoIntento->execute([':id_servicio' => $idServicio]);
    $terrenoIntentoPorRut = [];
    foreach ($stmtTerrenoIntento->fetchAll(PDO::FETCH_ASSOC) as $rowTi) {
        $rutTi = (string)$rowTi['rut'];
        if (!isset($terrenoIntentoPorRut[$rutTi])) {
            $terrenoIntentoPorRut[$rutTi] = [
                'nota' => isset($rowTi['notafinal']) ? (float)$rowTi['notafinal'] : null,
            ];
        }
    }

    $stmtThreshold = $pdo->prepare('
        SELECT p.porcentaje
        FROM ceo_agrupacion_terreno a
        INNER JOIN ceo_porcentaje_agrup_terreno p ON p.id_agrupacion = a.id
        WHERE a.id_servicio = :id_servicio
          AND p.activo = "S"
        ORDER BY p.fechadesde DESC
        LIMIT 1
    ');
    $stmtThreshold->execute([':id_servicio' => $idServicio]);
    $porcentajeMinimoTerreno = (float)($stmtThreshold->fetchColumn() ?: 80.0);

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
                'ultima_nota' => isset($row['notafinal']) ? (float)$row['notafinal'] : null,
                'numero_proceso' => isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null,
                'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
            ];
        } elseif ($teoricaPorRut[$rut]['empresa_historica'] === '' && trim((string)($row['empresa_historica'] ?? '')) !== '') {
            $teoricaPorRut[$rut]['empresa_historica'] = trim((string)$row['empresa_historica']);
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
        $puntaje = parseNullableScore($row['resultado'] ?? null);
        $resultado = ($puntaje !== null && $puntaje >= 80.0) ? 'APROBADO' : 'REPROBADO';

        if (!isset($terrenoPorRut[$rut])) {
            $terrenoPorRut[$rut] = [
                'ultima_fecha' => $fecha,
                'ultima_resultado' => $resultado,
                'ultima_nota' => $puntaje,
                'ultima_cargo' => trim((string)($row['cargo'] ?? '')),
                'numero_proceso' => isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null,
                'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
            ];
        } elseif ($terrenoPorRut[$rut]['empresa_historica'] === '' && trim((string)($row['empresa_historica'] ?? '')) !== '') {
            $terrenoPorRut[$rut]['empresa_historica'] = trim((string)$row['empresa_historica']);
        }
    }

    $ruts = array_values(array_unique(array_merge(array_keys($teoricaPorRut), array_keys($terrenoPorRut))));

    $contratistasMap = [];
    if (!empty($ruts)) {
        $placeholders = implode(',', array_fill(0, count($ruts), '?'));
        $stmtContratistas = $pdo->prepare("
            SELECT c.rut, c.nombre, c.apellidos, c.id_cargo, cc.cargo, e.nombre AS empresa
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

    foreach ($ruts as $rut) {
        $teo = $teoricaPorRut[$rut] ?? null;
        $terr = $terrenoPorRut[$rut] ?? null;

        $fechaHabilitacion = null;
        $vigenciaHasta = null;
        $estado = 'No Habilitado';
        $notaFinal = null;
        $contratista = $contratistasMap[$rut] ?? null;
        $cargoTexto = trim((string)($contratista['cargo'] ?? ''));
        if ($cargoTexto === '') {
            $cargoTexto = trim((string)($terr['ultima_cargo'] ?? ''));
        }
        $pesos = resolverPesosPorCargoServicio($cargoTexto, isset($contratista['id_cargo']) ? (int)$contratista['id_cargo'] : null);
        $notaTeorica = isset($teo['ultima_nota']) ? (float)$teo['ultima_nota'] : null;
        $notaTerreno = $terrenoIntentoPorRut[$rut]['nota'] ?? null;
        if ($notaTerreno === null && isset($terr['ultima_nota'])) {
            $notaTerreno = calcularNotaFinalDesdePorcentaje((float)$terr['ultima_nota'], $porcentajeMinimoTerreno);
        }

        if ($pesos !== null && $notaTeorica !== null && $notaTerreno !== null && ($terr['ultima_fecha'] ?? null) instanceof DateTimeImmutable) {
            $notaFinal = round(($notaTeorica * $pesos['teorica']) + ($notaTerreno * $pesos['terreno']), 2);
            $fechaHabilitacion = $terr['ultima_fecha'];
            $vigenciaHasta = $fechaHabilitacion->modify('+3 years');
            $estado = ($notaFinal >= 4.0 && $today <= $vigenciaHasta) ? 'Habilitado' : 'No Habilitado';
        }

        $conteos[$estado]++;

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

        $analisisAreas = $analisisAreasPorRut[$rut] ?? resumirAnalisisAreasServicio([]);

        $personas[] = [
            'rut' => $rut,
            'numero_proceso' => $terr['numero_proceso'] ?? ($teo['numero_proceso'] ?? null),
            'nombre' => trim((string)($contratista['nombre'] ?? '')),
            'apellidos' => trim((string)($contratista['apellidos'] ?? '')),
            'cargo' => trim((string)($contratista['cargo'] ?? '')),
            'empresa' => $empresaHistorica !== '' ? $empresaHistorica : trim((string)($contratista['empresa'] ?? '')),
            'nota_teorica' => $notaTeorica,
            'ultima_teorica' => $teo['ultima_fecha'] ?? null,
            'resultado_teorica' => $teo['ultima_resultado'] ?? '',
            'nota_practica' => $notaTerreno,
            'ultima_practica' => $terr['ultima_fecha'] ?? null,
            'resultado_practica' => $terr['ultima_resultado'] ?? '',
            'nota_final' => $notaFinal,
            'fecha_habilitacion' => $fechaHabilitacion,
            'vigencia_hasta' => $vigenciaHasta,
            'ultima_evaluacion' => $ultimaEvaluacion,
            'areas_debiles' => (int)($analisisAreas['areas_debiles'] ?? 0),
            'areas_resumen_corto' => (string)($analisisAreas['resumen_corto'] ?? 'Sin detalle'),
            'areas_detalle' => (string)($analisisAreas['detalle'] ?? 'Sin detalle por area.'),
            'areas_estado' => (string)($analisisAreas['estado'] ?? 'sin_detalle'),
            'estado' => $estado,
        ];
    }

    $conteos = [
        'Habilitado' => $conteos['Habilitado'],
        'No Habilitado' => $conteos['No Habilitado'],
    ];
    $resumen = array_map(static fn(string $label) => ['label' => $label, 'count' => $conteos[$label]], array_keys($conteos));
}

$personasFiltradas = $selectedEstado !== ''
    ? array_values(array_filter($personas, static fn(array $p): bool => $p['estado'] === $selectedEstado))
    : $personas;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Estado Habilitaciones por Servicio | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .table thead th { background:#eaf2fb; }
    .chart-box { max-width: 420px; margin: 0 auto; }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= APP_NAME ?></div>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container-fluid px-4 mb-5">
  <div class="card shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
      <h5 class="fw-bold text-primary mb-0"><i class="bi bi-pie-chart-fill me-2"></i>Estado Inferido de Habilitaciones por Servicio</h5>
      <?php if ($idServicio > 0): ?>
        <a href="estado_habilitaciones_servicio_excel.php?id_servicio=<?= (int)$idServicio ?>&estado=<?= urlencode($selectedEstado) ?>" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel"></i> Exportar Excel
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select" required>
            <?php foreach ($servicios as $servicio): ?>
              <option value="<?= (int)$servicio['id'] ?>" <?= $idServicio === (int)$servicio['id'] ? 'selected' : '' ?>>
                <?= (int)$servicio['id'] ?> - <?= esc((string)$servicio['servicio']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Estado</label>
          <select name="estado" class="form-select">
            <option value="">Todos</option>
            <?php foreach (array_keys($conteos) as $estado): ?>
              <option value="<?= esc($estado) ?>" <?= $selectedEstado === $estado ? 'selected' : '' ?>><?= esc($estado) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Consultar</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($idServicio > 0): ?>
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100">
          <div class="card-body text-center">
            <div class="small text-muted mb-2">Servicio seleccionado</div>
            <div class="h5 mb-0"><?= esc($nombreServicio) ?></div>
            <div class="small text-muted mt-2">Total personas consideradas: <strong><?= count($personas) ?></strong></div>
            <div class="chart-box mt-4">
              <canvas id="chartEstados"></canvas>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="text-primary mb-3">Resumen por estado</h6>
            <div class="row g-3">
              <?php foreach ($resumen as $item): ?>
                <div class="col-md-6 col-xl-4">
                  <a href="?id_servicio=<?= (int)$idServicio ?>&estado=<?= urlencode($item['label']) ?>" class="text-decoration-none">
                    <div class="border rounded p-3 h-100 bg-light">
                      <div class="small text-muted"><?= esc($item['label']) ?></div>
                      <div class="fs-4 fw-bold text-dark"><?= (int)$item['count'] ?></div>
                    </div>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="text-primary mb-0">Detalle <?= $selectedEstado !== '' ? ' - ' . esc($selectedEstado) : '' ?></h6>
          <span class="text-muted small">Registros mostrados: <?= count($personasFiltradas) ?></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead class="text-center">
              <tr>
                <th>RUT</th>
                <th>Proceso</th>
                <th>Nombre</th>
                <th>Apellido</th>
                <th>Cargo</th>
                <th>Empresa</th>
                <th>Nota teórica</th>
                <th>Última teórica</th>
                <th>Nota terreno</th>
                <th>Último terreno</th>
                <th>Nota final</th>
                <th>Última evaluación</th>
                <th>Fecha habilitación</th>
                <th>Vigencia hasta</th>
                <th>Áreas débiles</th>
                <th>Detalle áreas</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($personasFiltradas)): ?>
              <tr><td colspan="17" class="text-center text-muted">No hay registros para el filtro seleccionado.</td></tr>
            <?php else: ?>
              <?php foreach ($personasFiltradas as $p): ?>
                <?php
                  $badge = 'secondary';
                  if ($p['estado'] === 'Habilitado') {
                    $badge = 'success';
                  } elseif ($p['estado'] === 'No Habilitado') {
                    $badge = 'danger';
                  }
                  $badgeAreas = 'secondary';
                  if ($p['areas_estado'] === 'debil') {
                    $badgeAreas = 'warning';
                  } elseif ($p['areas_estado'] === 'ok') {
                    $badgeAreas = 'success';
                  } elseif ($p['areas_estado'] === 'sin_objetivo') {
                    $badgeAreas = 'info';
                  }
                ?>
                <tr>
                  <td><?= esc($p['rut']) ?></td>
                  <td class="text-center"><?= esc($p['numero_proceso'] !== null ? (string)$p['numero_proceso'] : '') ?></td>
                  <td><?= esc($p['nombre']) ?></td>
                  <td><?= esc($p['apellidos']) ?></td>
                  <td><?= esc($p['cargo']) ?></td>
                  <td><?= esc($p['empresa']) ?></td>
                  <td><?= esc($p['nota_teorica'] !== null ? number_format((float)$p['nota_teorica'], 2, '.', '') : '') ?></td>
                  <td><?= esc(fmtDateTime($p['ultima_teorica'])) ?></td>
                  <td><?= esc($p['nota_practica'] !== null ? number_format((float)$p['nota_practica'], 2, '.', '') : '') ?></td>
                  <td><?= esc(fmtDateTime($p['ultima_practica'])) ?></td>
                  <td><?= esc($p['nota_final'] !== null ? number_format((float)$p['nota_final'], 2, '.', '') : '') ?></td>
                  <td><?= esc(fmtDateTime($p['ultima_evaluacion'])) ?></td>
                  <td><?= esc(fmtDate($p['fecha_habilitacion'])) ?></td>
                  <td><?= esc(fmtDate($p['vigencia_hasta'])) ?></td>
                  <td class="text-center"><span class="badge text-bg-<?= esc($badgeAreas) ?>"><?= esc($p['areas_resumen_corto']) ?></span></td>
                  <td class="small"><?= esc($p['areas_detalle']) ?></td>
                  <td><span class="badge text-bg-<?= esc($badge) ?>"><?= esc($p['estado']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
(() => {
  const el = document.getElementById('chartEstados');
  if (!el) return;

  const labels = <?= json_encode(array_column($resumen, 'label'), JSON_UNESCAPED_UNICODE) ?>;
  const data = <?= json_encode(array_map(static fn(array $i): int => (int)$i['count'], $resumen), JSON_UNESCAPED_UNICODE) ?>;
  const colors = ['#198754', '#dc3545', '#0d6efd', '#ffc107', '#6c757d'];

  new Chart(el, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: colors,
        borderWidth: 1,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: {
          position: 'bottom'
        }
      },
      onClick: (event, elements, chart) => {
        if (!elements.length) return;
        const index = elements[0].index;
        const estado = chart.data.labels[index];
        const url = new URL(window.location.href);
        url.searchParams.set('id_servicio', '<?= (int)$idServicio ?>');
        url.searchParams.set('estado', estado);
        window.location.href = url.toString();
      }
    }
  });
})();
</script>
</body>
</html>
