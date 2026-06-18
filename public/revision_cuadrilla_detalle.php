<?php
// --------------------------------------------------------------
// revision_cuadrilla_detalle.php - Detalle del Trabajador (CEO)
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/historico_procesos_habilitacion_lib.php';

if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$pdo = db();

$rut = preg_replace('/\s+/', '', trim((string)($_GET['rut'] ?? '')));
$prog = (int)($_GET['programa'] ?? 0);

function revFmtNota($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $number = str_replace(',', '.', (string)$value);
    return is_numeric($number) ? number_format((float)$number, 2, '.', '') : (string)$value;
}

function revFmtFecha($value): string
{
    if ($value instanceof DateTimeImmutable) {
        return $value->format('d-m-Y');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $ts = strtotime($text);
    return $ts ? date('d-m-Y', $ts) : $text;
}

function revFmtFechaHora($value): string
{
    if ($value instanceof DateTimeImmutable) {
        return $value->format('d-m-Y H:i');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $ts = strtotime($text);
    return $ts ? date('d-m-Y H:i', $ts) : $text;
}

function revResolverPesos(?string $cargo): ?array
{
    $cargoNorm = strtoupper(trim((string)$cargo));
    $cargoNorm = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $cargoNorm);
    $cargoNorm = strtr($cargoNorm, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
    $cargoNorm = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargoNorm) ?: $cargoNorm;
    $cargoNorm = preg_replace('/[^A-Z0-9]+/u', ' ', $cargoNorm) ?? $cargoNorm;
    $cargoNorm = preg_replace('/\s+/', ' ', $cargoNorm) ?? $cargoNorm;
    if ($cargoNorm === '') {
        return null;
    }
    if (str_contains($cargoNorm, 'SUPERVISOR') || str_contains($cargoNorm, 'LIDER') || str_contains($cargoNorm, 'CAPATAZ') || str_contains($cargoNorm, 'MAESTRO')) {
        return ['teorica' => 0.6, 'terreno' => 0.4];
    }
    if (str_contains($cargoNorm, 'OPERADOR') || str_contains($cargoNorm, 'ACOMPAN') || str_contains($cargoNorm, 'AYUDANTE')) {
        return ['teorica' => 0.4, 'terreno' => 0.6];
    }
    return null;
}
// ============================================================
// CONSULTA DATOS DEL TRABAJADOR
// ============================================================

$trabajador = null;
$wfRegistros = [];
$agrupaciones = [];
$detalleHistorialEvaluaciones = [];
$historialConsolidado = [];
$analisisAreasTeorica = [];
$analisisAreasMeta = [
    'intento' => null,
    'fecha_hora' => null,
];
$idProcesoHabActual = 0;

if ($rut) {
        $sql = "SELECT a.id, a.cuadrilla, b.rut, b.nombre, b.apellidos, COALESCE(cc.cargo, b.cargo) AS cargo, c.id_cargo, e.nombre as empresa, f.desc_uo as uo, a.id_servicio, g.servicio as servicio_descripcion,
        (
            SELECT ph.numero_proceso
            FROM ceo_evaluaciones_programadas ep
            LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = ep.id_proceso_habilitacion
            WHERE ep.rut = b.rut
              AND ep.cuadrilla = a.cuadrilla
              AND ep.id_servicio = a.id_servicio
            ORDER BY ep.id DESC
            LIMIT 1
        ) AS numero_proceso,
        (
            SELECT ep.id_proceso_habilitacion
            FROM ceo_evaluaciones_programadas ep
            WHERE ep.rut = b.rut
              AND ep.cuadrilla = a.cuadrilla
              AND ep.id_servicio = a.id_servicio
            ORDER BY ep.id DESC
            LIMIT 1
        ) AS id_proceso_habilitacion
        FROM ceo_habilitacion a
        INNER JOIN ceo_habilitacion_participantes b ON a.cuadrilla = b.id_cuadrilla 
        LEFT JOIN ceo_contratistas c ON b.rut COLLATE utf8mb4_unicode_ci = c.rut COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = COALESCE(a.empresa, c.id_empresa)
        LEFT JOIN ceo_uo f ON a.uo = f.id
        LEFT JOIN ceo_servicios_pruebas g ON a.id_servicio = g.id
        where a.id = :programa
        and b.rut = :rut;";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'programa' => $prog,
            'rut' => $rut
        ]);
        $trabajador = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trabajador) {
            $sqlPersona = "
                SELECT
                    c.id,
                    NULL AS cuadrilla,
                    c.rut,
                    c.nombre,
                    c.apellidos,
                    cc.cargo,
                    c.id_cargo,
                    e.nombre AS empresa,
                    u.desc_uo AS uo,
                    NULL AS id_servicio,
                    NULL AS servicio_descripcion,
                    NULL AS numero_proceso,
                    NULL AS id_proceso_habilitacion
                FROM ceo_contratistas c
                LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
                LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
                LEFT JOIN ceo_uo u ON u.id = c.uo
                WHERE c.rut = :rut
                LIMIT 1
            ";
            $stmtPersona = $pdo->prepare($sqlPersona);
            $stmtPersona->execute(['rut' => $rut]);
            $trabajador = $stmtPersona->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $idProcesoHabActual = (int)($trabajador['id_proceso_habilitacion'] ?? 0);

        // ============================================================
        // CONSULTA WF DEL TRABAJADOR
        // ============================================================
        $sqlWF = "
            SELECT
                contratista,
                tipo,
                wf,
                servicio,
                cargo,
                fecha_carga
            FROM ceo_reportewf
            WHERE rut_empleado = :rut
            ORDER BY fecha_carga DESC
        ";
        
        $stmtWF = $pdo->prepare($sqlWF);
        $stmtWF->execute(['rut' => $rut]);
        $wfRegistros = $stmtWF->fetchAll(PDO::FETCH_ASSOC);

    
    // Obtener agrupaciones dinámicas para el servicio
        if (!empty($trabajador['id_servicio'])) {
            $sqlAgr = "SELECT id, titulo 
                       FROM ceo_agrupacion 
                       WHERE id_servicio = :srv
                       ORDER BY id ASC";
            
            $stmtAgr = $pdo->prepare($sqlAgr);
            $stmtAgr->execute(['srv' => $trabajador['id_servicio']]);
            $agrupaciones = $stmtAgr->fetchAll(PDO::FETCH_ASSOC);
        }

        $simulado = historicoSimularProcesos($pdo, (int)($trabajador['id_servicio'] ?? 0), $rut);
        $eventosHistorial = $simulado['rows'];
        if ($idProcesoHabActual > 0) {
            $eventosProcesoActual = array_values(array_filter(
                $eventosHistorial,
                static fn(array $evento): bool => (int)($evento['id_proceso_habilitacion'] ?? 0) === $idProcesoHabActual
            ));
            if (!empty($eventosProcesoActual)) {
                $eventosHistorial = $eventosProcesoActual;
            }
        }

        foreach ($eventosHistorial as $evento) {

            $idServicio = (int)($evento['id_servicio'] ?? 0);
            $proceso = $evento['proceso_real'] !== null ? (string)$evento['proceso_real'] : 'H-' . (string)$evento['proceso'];
            $key = $idServicio . '|' . $proceso;
            $fecha = $evento['fecha_hora'] ?? null;
            $notaDetalle = $evento['nota_final'];
            if (($evento['tipo'] ?? '') === 'TERRENO' && ($notaDetalle === null || $notaDetalle === '') && is_numeric((string)($evento['puntaje'] ?? ''))) {
                $notaDetalle = calcularNotaFinalDesdePorcentaje((float)$evento['puntaje'], 80.0);
            }

            if ($fecha instanceof DateTimeImmutable) {
                $detalleHistorialEvaluaciones[] = [
                    'fecha_hora' => $fecha,
                    'fecha_grupo' => $fecha->format('Y-m-d'),
                    'tipo' => (string)($evento['tipo'] ?? ''),
                    'servicio' => (string)($evento['servicio'] ?? ''),
                    'numero_proceso' => $proceso,
                    'cargo' => (string)($evento['cargo_evaluacion'] ?? ''),
                    'puntaje' => $evento['puntaje'] ?? null,
                    'nota_final' => $notaDetalle,
                    'resultado' => (string)($evento['resultado'] ?? ''),
                    'origen' => (string)($evento['origen'] ?? ''),
                ];
            }

            if (!isset($historialConsolidado[$key])) {
                $historialConsolidado[$key] = [
                    'numero_proceso' => $proceso,
                    'rut' => (string)($evento['rut'] ?? $rut),
                    'fecha_terreno' => null,
                    'cargo' => (string)($evento['cargo_evaluacion'] ?? ''),
                    'fecha_prueba' => null,
                    'nota_terreno' => null,
                    'nota_prueba' => null,
                    'nota_final' => null,
                    'estado' => (string)($evento['estado_proceso'] ?? ''),
                    'fecha_evaluacion' => null,
                ];
            }

            if ($fecha instanceof DateTimeImmutable && (!($historialConsolidado[$key]['fecha_evaluacion'] instanceof DateTimeImmutable) || $fecha > $historialConsolidado[$key]['fecha_evaluacion'])) {
                $historialConsolidado[$key]['fecha_evaluacion'] = $fecha;
            }
            if ($historialConsolidado[$key]['cargo'] === '' && !empty($evento['cargo_evaluacion'])) {
                $historialConsolidado[$key]['cargo'] = (string)$evento['cargo_evaluacion'];
            }

            if (($evento['tipo'] ?? '') === 'TEORICA') {
                if (!($historialConsolidado[$key]['fecha_prueba'] instanceof DateTimeImmutable) || ($fecha instanceof DateTimeImmutable && $fecha > $historialConsolidado[$key]['fecha_prueba'])) {
                    $historialConsolidado[$key]['fecha_prueba'] = $fecha;
                    $historialConsolidado[$key]['nota_prueba'] = $evento['nota_final'];
                }
            } elseif (($evento['tipo'] ?? '') === 'TERRENO') {
                if (!($historialConsolidado[$key]['fecha_terreno'] instanceof DateTimeImmutable) || ($fecha instanceof DateTimeImmutable && $fecha > $historialConsolidado[$key]['fecha_terreno'])) {
                    $notaTerrenoEvento = $evento['nota_final'];
                    if (($notaTerrenoEvento === null || $notaTerrenoEvento === '') && is_numeric((string)($evento['puntaje'] ?? ''))) {
                        $notaTerrenoEvento = calcularNotaFinalDesdePorcentaje((float)$evento['puntaje'], 80.0);
                    }
                    $historialConsolidado[$key]['fecha_terreno'] = $fecha;
                    $historialConsolidado[$key]['nota_terreno'] = $notaTerrenoEvento;
                }
            }
        }

        foreach ($historialConsolidado as &$hc) {
            $notaPrueba = is_numeric((string)$hc['nota_prueba']) ? (float)$hc['nota_prueba'] : null;
            $notaTerreno = is_numeric((string)$hc['nota_terreno']) ? (float)$hc['nota_terreno'] : null;
            $pesos = revResolverPesos((string)$hc['cargo']);
            if ($notaPrueba !== null && $notaTerreno !== null) {
                $hc['nota_final'] = $pesos !== null
                    ? round(($notaPrueba * $pesos['teorica']) + ($notaTerreno * $pesos['terreno']), 2)
                    : round(($notaPrueba + $notaTerreno) / 2, 2);
                $hc['estado'] = $hc['nota_final'] >= 4.0 ? 'APROBADO' : 'REPROBADO';
            } elseif ($notaPrueba !== null || $notaTerreno !== null) {
                $hc['estado'] = 'PENDIENTE';
            }
        }
        unset($hc);

        usort($historialConsolidado, static function (array $a, array $b): int {
            $fechaA = $a['fecha_evaluacion'] instanceof DateTimeImmutable ? $a['fecha_evaluacion']->getTimestamp() : 0;
            $fechaB = $b['fecha_evaluacion'] instanceof DateTimeImmutable ? $b['fecha_evaluacion']->getTimestamp() : 0;
            return $fechaB <=> $fechaA;
        });

        usort($detalleHistorialEvaluaciones, static function (array $a, array $b): int {
            $fechaA = $a['fecha_hora'] instanceof DateTimeImmutable ? $a['fecha_hora']->getTimestamp() : 0;
            $fechaB = $b['fecha_hora'] instanceof DateTimeImmutable ? $b['fecha_hora']->getTimestamp() : 0;
            if ($fechaA !== $fechaB) {
                return $fechaB <=> $fechaA;
            }
            return strcmp((string)$a['tipo'], (string)$b['tipo']);
        });
}

$intentos = [];
$historial = [];
$evaluacionesTerreno = [];
$estadoHab = null;
$vigenciaGeneral = null;
$vigenciaDetalle = [];

if ($rut && !empty($trabajador['id_servicio'])) {

    $cuadrillaProceso = (int)($trabajador['cuadrilla'] ?? 0);

    $sqlPruebas = "
        SELECT
            rpi.id,
            rpi.rut,
            rpi.fecha_rendicion,
            rpi.puntaje_total,
            rpi.notafinal
        FROM ceo_resultado_prueba_intento rpi
        WHERE rpi.rut = :rut
          AND rpi.id_servicio = :servicio
        ORDER BY rpi.fecha_rendicion DESC
    ";

    $stmtPr = $pdo->prepare($sqlPruebas);
	    $stmtPr->execute([
	        'rut'      => $rut,
	        'servicio' => $trabajador['id_servicio']
	    ]);
	
	    $intentos = $stmtPr->fetchAll(PDO::FETCH_ASSOC);

        $stmtUltimoIntentoArea = $pdo->prepare("
            SELECT
                rpt.intento,
                MAX(CONCAT(COALESCE(rpt.fecha_rendicion, CURDATE()), ' ', COALESCE(rpt.hora_rendicion, CURTIME()))) AS fecha_hora
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps
                ON ps.id = rpt.id_pregunta
               AND ps.id_servicio = :servicio
            WHERE rpt.rut = :rut
              AND rpt.proceso = :proceso
            GROUP BY rpt.intento
            ORDER BY rpt.intento DESC, fecha_hora DESC
            LIMIT 1
        ");
        $stmtUltimoIntentoArea->execute([
            'rut' => $rut,
            'proceso' => $cuadrillaProceso,
            'servicio' => $trabajador['id_servicio'],
        ]);
        $ultimoIntentoArea = $stmtUltimoIntentoArea->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($ultimoIntentoArea) {
            $analisisAreasMeta['intento'] = isset($ultimoIntentoArea['intento']) ? (int)$ultimoIntentoArea['intento'] : null;
            $analisisAreasMeta['fecha_hora'] = $ultimoIntentoArea['fecha_hora'] ?? null;

            $stmtAreas = $pdo->prepare("
                SELECT
                    COALESCE(ac.id, 0) AS id_area,
                    COALESCE(ac.descripcion, 'Sin área de competencia') AS area,
                    cfg.porcentaje AS objetivo,
                    SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
                    SUM(CASE WHEN rpt.validacion = 0 THEN 1 ELSE 0 END) AS incorrectas,
                    SUM(CASE WHEN rpt.validacion = -1 THEN 1 ELSE 0 END) AS ncontestadas,
                    COUNT(*) AS total
                FROM ceo_resultado_pruebat rpt
                INNER JOIN ceo_preguntas_servicios ps
                    ON ps.id = rpt.id_pregunta
                   AND ps.id_servicio = :servicio
                LEFT JOIN ceo_areacompetencias ac
                    ON ac.id = ps.areacomp
                   AND ac.id_servicio = ps.id_servicio
                LEFT JOIN ceo_habilitacion_areascompetencias_pct cfg
                    ON cfg.id_servicio = ps.id_servicio
                   AND cfg.id_area = ps.areacomp
                WHERE rpt.rut = :rut
                  AND rpt.proceso = :proceso
                  AND rpt.intento = :intento
                GROUP BY COALESCE(ac.id, 0), COALESCE(ac.descripcion, 'Sin área de competencia'), cfg.porcentaje
                ORDER BY area ASC
            ");
            $stmtAreas->execute([
                'rut' => $rut,
                'proceso' => $cuadrillaProceso,
                'intento' => (int)$ultimoIntentoArea['intento'],
                'servicio' => $trabajador['id_servicio'],
            ]);

            foreach ($stmtAreas->fetchAll(PDO::FETCH_ASSOC) as $rowArea) {
                $totalArea = (int)($rowArea['total'] ?? 0);
                if ($totalArea <= 0) {
                    continue;
                }

                $correctasArea = (int)($rowArea['correctas'] ?? 0);
                $porcentajeArea = round(($correctasArea / $totalArea) * 100, 2);
                $objetivoArea = $rowArea['objetivo'] !== null ? (float)$rowArea['objetivo'] : null;

                $analisisAreasTeorica[] = [
                    'id_area' => (int)($rowArea['id_area'] ?? 0),
                    'area' => (string)($rowArea['area'] ?? ''),
                    'objetivo' => $objetivoArea,
                    'correctas' => $correctasArea,
                    'incorrectas' => (int)($rowArea['incorrectas'] ?? 0),
                    'ncontestadas' => (int)($rowArea['ncontestadas'] ?? 0),
                    'total' => $totalArea,
                    'porcentaje' => $porcentajeArea,
                    'debil' => $objetivoArea !== null && $objetivoArea > 0 ? $porcentajeArea < $objetivoArea : false,
                ];
            }
        }
	
	    $sqlHist = "
	        SELECT
	            rpi.fecha_rendicion AS fecha,
            'Teórica'           AS tipo,
            rpi.notafinal       AS resultado,
            sp.servicio         AS servicio
        FROM ceo_resultado_prueba_intento rpi
        INNER JOIN ceo_servicios_pruebas sp 
                ON rpi.id_servicio = sp.id
        WHERE rpi.rut = :rut_teorica

        UNION ALL

        SELECT
            et.fecha_evaluacion AS fecha,
            'Terreno'           AS tipo,
            et.resultado        AS resultado,
            sp2.servicio        AS servicio
        FROM ceo_evaluacion_terreno et
        INNER JOIN ceo_servicios_pruebas sp2 
                ON et.id_servicio = sp2.id
        WHERE et.rut = :rut_terreno

        ORDER BY fecha DESC
    ";

    $stmtH = $pdo->prepare($sqlHist);
    $stmtH->execute([
        'rut_teorica' => $rut,
        'rut_terreno' => $rut
    ]);

    $historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);

    $sqlTerr = "
        SELECT DISTINCT
            et.id,
            et.codigo_evaluacion,
            et.rut,
            et.cargo,
            et.fecha_evaluacion,
            b.notafinal AS resultado
        FROM ceo_evaluacion_terreno et
            INNER JOIN ceo_resultado_terreno_intento b ON b.rut = et.rut
        WHERE et.rut = :rut
          AND et.id_servicio = :servicio
        ORDER BY et.fecha_evaluacion DESC
    ";

    $stmtT = $pdo->prepare($sqlTerr);
    $stmtT->execute([
        'rut'      => $rut,
        'servicio' => $trabajador['id_servicio']
    ]);

    $evaluacionesTerreno = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // CONSULTA ESTADO HABILITACIÓN (UNA SOLA FILA)
    // ============================================================
    $sqlEstadoHab = "
SELECT
        rfs.rut,
        cc.cargo,
        rfs.fecha_calculo AS fecha_rendicion,
        rfs.nota_terreno AS Terreno,
        rfs.nota_prueba AS Teorica,
        rfs.nota_final AS resultado,
        CASE
            WHEN UPPER(rfs.resultado_final) = 'APROBADO' THEN 'SI'
            ELSE 'NO'
        END AS habilitado
    FROM ceo_resultado_final_servicio rfs
    LEFT JOIN ceo_cargos_habilitacion cc ON cc.id = rfs.cargo
    WHERE rfs.rut = :rut
      AND rfs.id_servicio = :servicio
      AND rfs.segmento = 'GENERAL'
    ORDER BY rfs.fecha_calculo DESC, rfs.id DESC
    LIMIT 1
    ";

    $stmtEH = $pdo->prepare($sqlEstadoHab);
    $stmtEH->execute([
      'rut'      => $rut,
      'servicio' => (int)$trabajador['id_servicio']
    ]);
    $estadoHab = $stmtEH->fetch(PDO::FETCH_ASSOC);

    // ============================================================
    // CONSULTA VIGENCIA GENERAL Y DETALLE POR RUT + PROCESO
    // ============================================================
    $sqlVigGen = "
        SELECT
            vg.id,
            vg.rut,
            vg.fechavig_ini,
            vg.fechavig_fin,
            vg.id_proceso
        FROM ceo_vigencia_general vg
        WHERE vg.rut = :rut
          AND vg.id_proceso = :proceso
        ORDER BY vg.fechavig_fin DESC, vg.id DESC
        LIMIT 1
    ";
    $stmtVG = $pdo->prepare($sqlVigGen);
    $stmtVG->execute([
        'rut'     => $rut,
        'proceso' => $cuadrillaProceso
    ]);
    $vigenciaGeneral = $stmtVG->fetch(PDO::FETCH_ASSOC);

    $sqlVigDet = "
        SELECT
            vd.id,
            vd.rut,
            vd.id_servicio,
            vd.fechavig_ini,
            vd.fechavig_fin,
            vd.id_proceso,
            vd.tipo,
            sp.servicio,
            sp.descripcion,
            CASE
                WHEN UPPER(TRIM(vd.tipo)) = 'PRUEBA' THEN (
                    SELECT rpi.notafinal
                    FROM ceo_resultado_prueba_intento rpi
                    WHERE rpi.rut = vd.rut
                      AND rpi.id_servicio = vd.id_servicio
                    ORDER BY rpi.fecha_rendicion DESC, rpi.id DESC
                    LIMIT 1
                )
                WHEN UPPER(TRIM(vd.tipo)) = 'TERRENO' THEN (
                    SELECT rti.notafinal
                    FROM ceo_resultado_terreno_intento rti
                    WHERE rti.rut = vd.rut
                      AND rti.id_servicio = vd.id_servicio
                    ORDER BY rti.id DESC
                    LIMIT 1
                )
                ELSE NULL
            END AS nota
        FROM ceo_vigencia_detalle vd
        INNER JOIN ceo_servicios_pruebas sp
            ON sp.id = vd.id_servicio
        WHERE vd.rut = :rut
          AND vd.id_proceso = :proceso
        ORDER BY sp.servicio ASC, vd.id DESC
    ";
    $stmtVD = $pdo->prepare($sqlVigDet);
    $stmtVD->execute([
        'rut'     => $rut,
        'proceso' => $cuadrillaProceso
    ]);
    $vigenciaDetalle = $stmtVD->fetchAll(PDO::FETCH_ASSOC);
}

$serviciosPrueba = $pdo->query("
    SELECT id, servicio, descripcion
    FROM ceo_servicios_pruebas
    ORDER BY servicio
")->fetchAll(PDO::FETCH_ASSOC);

$cargos = $pdo->query("
    SELECT id, cargo
    FROM ceo_cargos_habilitacion
    ORDER BY cargo
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Detalle Evaluaciones - <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}

.box-label {
    font-weight: 700;
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.data-box {
    border:1px solid #d0d7de;
    padding:6px 10px;
    border-radius:6px;
    background:white;
    font-size:0.9rem;
}

.scroll-box {
    max-height:260px;
    overflow:auto;
    border:1px solid #dee2e6;
    border-radius:6px;
    background:white;
}

.table thead {
    position:sticky; 
    top:0;
    background:#eaf2fb; 
    z-index:2;
}

.section-title {
    font-weight:bold;
    font-size:1rem;
    margin-bottom:10px;
    color:#0065a4;
}

.badge-soft-success {
    background:#d1e7dd;
    color:#0f5132;
    border:1px solid #badbcc;
}

.badge-soft-danger {
    background:#f8d7da;
    color:#842029;
    border:1px solid #f5c2c7;
}

.badge-soft-secondary {
    background:#e2e3e5;
    color:#41464b;
    border:1px solid #d3d6d8;
}

.excel-like-table th,
.excel-like-table td {
    white-space: nowrap;
}
</style>

</head>

<body>

<!-- ============================================================
     HEADER
============================================================ -->
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>

<a href="revision_cuadrillas.php?empresa=<?= $_GET['empresa'] ?? '' ?>&uo=<?= $_GET['uo'] ?? '' ?>&programa=<?= $_GET['programa'] ?? '' ?>" 
   class="btn btn-outline-primary btn-sm">
   ← Volver
</a>

  </div>
</header>


<div class="container-fluid px-4">

    <!-- ============================================================
         CARD PRINCIPAL
    ============================================================ -->
<div class="card rounded-4 shadow-sm mb-4">
    <div class="card-body py-3 d-flex justify-content-between align-items-center gap-3 flex-wrap">

        <div>
            <h4 class="fw-bold text-primary mb-2">
                <i class="bi bi-person-vcard me-2"></i>
                Detalle de Evaluaciones
            </h4>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-success btn-sm rounded-3 fw-semibold" id="btnGenerarProceso">
                    <i class="bi bi-plus-circle me-1"></i> Generar Proceso Nuevo
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm rounded-3 fw-semibold" id="btnProcesoAnterior">
                    <i class="bi bi-clock-history me-1"></i> Proceso Anterior
                </button>
            </div>
            <div id="procesoMsg" class="small mt-2"></div>
        </div>

        <!-- Botón Servicios por Habilitar -->
        <button class="btn btn-outline-primary rounded-3 fw-semibold"
                data-bs-toggle="modal"
                data-bs-target="#modalHabilitaciones">
            <i class="bi bi-shield-exclamation me-1"></i>
            Servicios y Cargo por Habilitar
        </button>

    </div>
</div>


    <!-- ============================================================
         DATOS DEL TRABAJADOR
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">

            <h5 class="fw-bold text-primary mb-3">Información del Trabajador</h5>

            <div class="row g-3">

                <div class="col-md-3">
                    <div class="box-label">RUT</div>
                    <div class="data-box"><?= esc($rut) ?></div>
                </div>

 <div class="col-md-3">
    <div class="box-label">Nombre</div>
    <div class="data-box"><?= esc($trabajador['nombre'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Apellido</div>
    <div class="data-box"><?= esc($trabajador['apellidos'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Cargo</div>
    <div class="data-box"><?= esc($trabajador['cargo'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Empresa</div>
    <div class="data-box"><?= esc($trabajador['empresa'] ?? '') ?></div>
</div>

<div class="col-md-3">
    <div class="box-label">Unidad Operativa</div>
    <div class="data-box"><?= esc($trabajador['uo'] ?? '') ?></div>
</div>

<div class="col-md-6">
    <div class="box-label">Servicio</div>
    <div class="data-box"><?= esc($trabajador['servicio_descripcion'] ?? '') ?></div>
</div>


            </div>

        </div>
    </div>


<!-- ============================================================
     INFORMACIÓN WF
============================================================ -->
<div class="card shadow-sm rounded-4 mb-4">
    <div class="card-body">

        <div class="section-title">
            <i class="bi bi-diagram-3 me-2"></i>Información WF
        </div>

        <div class="scroll-box">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr class="text-center">
                        <th>Contratista</th>
                        <th>Tipo</th>
                        <th>WF</th>
                        <th>Servicio</th>
                        <th>Cargo</th>
                        <th>Fecha Carga</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($wfRegistros)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            Sin información WF registrada
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($wfRegistros as $wf): ?>
                        <tr class="text-center">
                            <td><?= esc($wf['contratista']) ?></td>
                            <td><?= esc($wf['tipo']) ?></td>
                            <td><?= esc($wf['wf']) ?></td>
                            <td><?= esc($wf['servicio']) ?></td>
                            <td><?= esc($wf['cargo']) ?></td>
                            <td><?= esc(date('d-m-Y H:i', strtotime($wf['fecha_carga']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>
</div>


<!-- ============================================================
     ANALISIS POR AREAS
============================================================ -->
<div class="card shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="section-title">
            <i class="bi bi-bullseye me-2"></i>Desempeño por Áreas de Competencia
        </div>
        <div class="small text-muted mb-3">
            Análisis secundario del último intento teórico de esta habilitación. No afecta la aprobación global.
            <?php if (($analisisAreasMeta['intento'] ?? null) !== null): ?>
                Intento: <strong><?= esc((string)$analisisAreasMeta['intento']) ?></strong>
                <?php if (!empty($analisisAreasMeta['fecha_hora'])): ?>
                    · Fecha: <strong><?= esc(revFmtFechaHora($analisisAreasMeta['fecha_hora'])) ?></strong>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle excel-like-table mb-0">
                <thead>
                    <tr class="text-center">
                        <th>Área</th>
                        <th>Objetivo</th>
                        <th>Correctas</th>
                        <th>Incorrectas</th>
                        <th>No contestadas</th>
                        <th>Total</th>
                        <th>Porcentaje</th>
                        <th>Señal</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($analisisAreasTeorica)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">
                            Sin detalle por áreas disponible para esta habilitación.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($analisisAreasTeorica as $areaRow): ?>
                        <?php
                            if (($areaRow['objetivo'] ?? null) === null || (float)($areaRow['objetivo'] ?? 0) <= 0) {
                                $senalTexto = 'Sin objetivo';
                                $senalClass = 'secondary';
                            } elseif (!empty($areaRow['debil'])) {
                                $senalTexto = 'Debilidad';
                                $senalClass = 'danger';
                            } else {
                                $senalTexto = 'Dentro objetivo';
                                $senalClass = 'success';
                            }
                        ?>
                        <tr>
                            <td><?= esc((string)$areaRow['area']) ?></td>
                            <td class="text-end"><?= $areaRow['objetivo'] !== null ? esc(number_format((float)$areaRow['objetivo'], 2, '.', '')) . '%' : '-' ?></td>
                            <td class="text-center"><?= esc((string)$areaRow['correctas']) ?></td>
                            <td class="text-center"><?= esc((string)$areaRow['incorrectas']) ?></td>
                            <td class="text-center"><?= esc((string)$areaRow['ncontestadas']) ?></td>
                            <td class="text-center"><?= esc((string)$areaRow['total']) ?></td>
                            <td class="text-end fw-semibold"><?= esc(number_format((float)$areaRow['porcentaje'], 2, '.', '')) ?>%</td>
                            <td class="text-center"><span class="badge text-bg-<?= esc($senalClass) ?>"><?= esc($senalTexto) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ============================================================
     DETALLE HISTORIAL DE EVALUACIONES
============================================================ -->
<div class="card shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="section-title">
            <i class="bi bi-list-check me-2"></i>Detalle Historial de Evaluaciones
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle excel-like-table mb-0">
                <thead>
                    <tr class="text-center">
                        <th>Fecha / Hora</th>
                        <th>Tipo</th>
                        <th>Servicio</th>
                        <th>Número Proceso</th>
                        <th>Cargo</th>
                        <th>Puntaje</th>
                        <th>Nota</th>
                        <th>Resultado</th>
                        <th>Origen</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($detalleHistorialEvaluaciones)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted">
                            Sin detalle histórico de evaluaciones para este RUT
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $fechaGrupoAnterior = ''; ?>
                    <?php foreach ($detalleHistorialEvaluaciones as $detalleHist): ?>
                        <?php
                            $fechaGrupo = (string)($detalleHist['fecha_grupo'] ?? '');
                            if ($fechaGrupo !== $fechaGrupoAnterior):
                                $fechaGrupoAnterior = $fechaGrupo;
                        ?>
                            <tr class="table-primary">
                                <td colspan="9" class="fw-semibold">
                                    <?= esc(revFmtFecha($fechaGrupo)) ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php
                            $tipoDetalle = strtoupper(trim((string)$detalleHist['tipo']));
                            $tipoTexto = $tipoDetalle === 'TEORICA' ? 'Teórica' : ($tipoDetalle === 'TERRENO' ? 'Terreno' : $tipoDetalle);
                            $tipoClass = $tipoDetalle === 'TEORICA' ? 'info' : 'warning';
                            $resultadoDetalle = strtoupper(trim((string)$detalleHist['resultado']));
                            $resultadoClass = match ($resultadoDetalle) {
                                'APROBADO' => 'success',
                                'REPROBADO' => 'danger',
                                default => 'secondary',
                            };
                        ?>
                        <tr>
                            <td class="text-center"><?= esc(revFmtFechaHora($detalleHist['fecha_hora'])) ?></td>
                            <td class="text-center"><span class="badge text-bg-<?= esc($tipoClass) ?>"><?= esc($tipoTexto) ?></span></td>
                            <td><?= esc((string)$detalleHist['servicio']) ?></td>
                            <td class="text-center"><?= esc((string)$detalleHist['numero_proceso']) ?></td>
                            <td><?= esc((string)$detalleHist['cargo']) ?></td>
                            <td class="text-end"><?= esc(revFmtNota($detalleHist['puntaje'])) ?></td>
                            <td class="text-end"><?= esc(revFmtNota($detalleHist['nota_final'])) ?></td>
                            <td class="text-center"><span class="badge text-bg-<?= esc($resultadoClass) ?>"><?= esc($resultadoDetalle ?: '-') ?></span></td>
                            <td><?= esc((string)$detalleHist['origen']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ============================================================
     HISTORIAL CONSOLIDADO
============================================================ -->
<div class="card shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="section-title">
            <i class="bi bi-table me-2"></i>Historial Evaluaciones
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle excel-like-table mb-0">
                <thead>
                    <tr class="text-center">
                        <th>Número Proceso</th>
                        <th>RUT</th>
                        <th>Fecha terreno</th>
                        <th>Cargo</th>
                        <th>Fecha Prueba</th>
                        <th>Nota Terreno</th>
                        <th>Nota prueba</th>
                        <th>Nota Final</th>
                        <th>Estado</th>
                        <th>Fecha evaluación</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($historialConsolidado)): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted">
                            Sin historial de evaluaciones para este RUT
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historialConsolidado as $rowHist): ?>
                        <?php
                            $estadoHist = strtoupper(trim((string)$rowHist['estado']));
                            if ($estadoHist === '') {
                                $estadoHist = 'PENDIENTE';
                            }
                            $estadoClass = match ($estadoHist) {
                                'APROBADO', 'CERRADO' => 'success',
                                'REPROBADO', 'VENCIDO' => 'danger',
                                default => 'secondary',
                            };
                        ?>
                        <tr>
                            <td class="text-center"><?= esc((string)$rowHist['numero_proceso']) ?></td>
                            <td class="text-center"><?= esc((string)$rowHist['rut']) ?></td>
                            <td class="text-center"><?= esc(revFmtFecha($rowHist['fecha_terreno'])) ?></td>
                            <td><?= esc((string)$rowHist['cargo']) ?></td>
                            <td class="text-center"><?= esc(revFmtFecha($rowHist['fecha_prueba'])) ?></td>
                            <td class="text-end"><?= esc(revFmtNota($rowHist['nota_terreno'])) ?></td>
                            <td class="text-end"><?= esc(revFmtNota($rowHist['nota_prueba'])) ?></td>
                            <td class="text-end fw-semibold"><?= esc(revFmtNota($rowHist['nota_final'])) ?></td>
                            <td class="text-center"><span class="badge text-bg-<?= esc($estadoClass) ?>"><?= esc($estadoHist) ?></span></td>
                            <td class="text-center"><?= esc(revFmtFecha($rowHist['fecha_evaluacion'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

    <!-- ============================================================
         VIGENCIA GENERAL Y DETALLE
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-5">
        <div class="card-body">

            <div class="section-title">
                <i class="bi bi-calendar-range me-2"></i>Vigencia General y Detalle
            </div>

            <?php
                $hoy = date('Y-m-d');
                $vigenciaActiva = false;

                if (!empty($vigenciaGeneral['fechavig_ini']) && !empty($vigenciaGeneral['fechavig_fin'])) {
                    $vigenciaActiva = ($hoy >= $vigenciaGeneral['fechavig_ini'] && $hoy <= $vigenciaGeneral['fechavig_fin']);
                }
            ?>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="box-label">RUT</div>
                    <div class="data-box"><?= esc($rut) ?></div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">N° Proceso</div>
                    <div class="data-box"><?= esc(($trabajador['numero_proceso'] ?? null) !== null ? (string)$trabajador['numero_proceso'] : 'Sin proceso') ?></div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">N° Cuadrilla</div>
                    <div class="data-box"><?= esc((string)($trabajador['cuadrilla'] ?? '')) ?></div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">Vigencia Inicio</div>
                    <div class="data-box">
                        <?= !empty($vigenciaGeneral['fechavig_ini']) ? esc(date('d-m-Y', strtotime($vigenciaGeneral['fechavig_ini']))) : 'Sin registro' ?>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="box-label">Vigencia Fin</div>
                    <div class="data-box">
                        <?= !empty($vigenciaGeneral['fechavig_fin']) ? esc(date('d-m-Y', strtotime($vigenciaGeneral['fechavig_fin']))) : 'Sin registro' ?>
                    </div>
                </div>

                <div class="col-md-12">
                    <div class="box-label">Estado Vigencia General</div>
                    <div class="data-box">
                        <?php if (empty($vigenciaGeneral)): ?>
                            <span class="badge badge-soft-secondary">Sin vigencia general registrada</span>
                        <?php elseif ($vigenciaActiva): ?>
                            <span class="badge badge-soft-success">Vigente</span>
                        <?php else: ?>
                            <span class="badge badge-soft-danger">Vencida</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="scroll-box">
                <table class="table table-sm table-bordered align-middle">
                    <thead>
                        <tr class="text-center">
                            <th>Servicio</th>
                            <th>Tipo</th>
                            <th>Vigencia Inicio</th>
                            <th>Vigencia Fin</th>
                            <th>Nota</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($vigenciaDetalle)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                Sin detalle de vigencia asociado al proceso
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vigenciaDetalle as $vd): ?>
                            <?php
                                $detalleVigente = false;
                                if (!empty($vd['fechavig_ini']) && !empty($vd['fechavig_fin'])) {
                                    $detalleVigente = ($hoy >= $vd['fechavig_ini'] && $hoy <= $vd['fechavig_fin']);
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= esc($vd['servicio'] ?? '') ?></strong>
                                    <?php if (!empty($vd['descripcion'])): ?>
                                        <div class="small text-muted"><?= esc($vd['descripcion']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <?= esc($vd['tipo'] ?? '-') ?>
                                </td>

                                <td class="text-center">
                                    <?= !empty($vd['fechavig_ini']) ? esc(date('d-m-Y', strtotime($vd['fechavig_ini']))) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?= !empty($vd['fechavig_fin']) ? esc(date('d-m-Y', strtotime($vd['fechavig_fin']))) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?= ($vd['nota'] !== null && $vd['nota'] !== '') ? esc((string)$vd['nota']) : '-' ?>
                                </td>

                                <td class="text-center">
                                    <?php if ($detalleVigente): ?>
                                        <span class="badge badge-soft-success">Vigente</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-danger">Vencida</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>



</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<div class="modal fade" id="modalProcesoOpciones" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header bg-success text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="bi bi-diagram-3 me-2"></i>Generar Proceso</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border">
          Selecciona la combinación de servicio y cargo asociada al trabajador para generar o reutilizar un proceso abierto.
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr class="text-center">
                <th>Servicio</th>
                <th>Cargo</th>
                <th style="width:140px;">Acción</th>
              </tr>
            </thead>
            <tbody id="bodyProcesoOpciones">
              <tr><td colspan="3" class="text-center text-muted">Sin opciones</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalProcesosAnteriores" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header bg-secondary text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2"></i>Procesos Abiertos del Trabajador</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info rounded-3">
          Solo se muestran procesos <strong>ABIERTO</strong>. Los procesos cerrados no pueden seleccionarse para nuevas planificaciones.
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr class="text-center">
                <th>N° Proceso</th>
                <th>Servicio</th>
                <th>Cargo</th>
                <th>Estado</th>
                <th>Fecha inicio</th>
                <th style="width:140px;">Acción</th>
              </tr>
            </thead>
            <tbody id="bodyProcesosAnteriores">
              <tr><td colspan="6" class="text-center text-muted">Sin procesos abiertos</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const rut = <?= json_encode($rut, JSON_UNESCAPED_UNICODE) ?>;
  const btnGenerar = document.getElementById('btnGenerarProceso');
  const btnAnterior = document.getElementById('btnProcesoAnterior');
  const msg = document.getElementById('procesoMsg');
  const modalOpcionesEl = document.getElementById('modalProcesoOpciones');
  const modalAnterioresEl = document.getElementById('modalProcesosAnteriores');
  const bodyOpciones = document.getElementById('bodyProcesoOpciones');
  const bodyAnteriores = document.getElementById('bodyProcesosAnteriores');
  const modalOpciones = modalOpcionesEl ? new bootstrap.Modal(modalOpcionesEl) : null;
  const modalAnteriores = modalAnterioresEl ? new bootstrap.Modal(modalAnterioresEl) : null;

  function setProcesoMsg(text, kind = 'info') {
    if (!msg) return;
    msg.innerHTML = text ? `<span class="text-${kind}">${escapeHtml(text)}</span>` : '';
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  function fmtServicio(row) {
    const desc = String(row.descripcion || '').trim();
    return desc ? `${row.servicio} - ${desc}` : String(row.servicio || '');
  }

  function fmtFecha(value) {
    if (!value) return '';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('es-CL');
  }

  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'No se pudo completar la operación');
    return json;
  }

  async function crearProceso(payload = {}) {
    const json = await postJson('ajax_proceso_habilitacion_crear.php', {rut, ...payload});
    if (json.requires_selection) {
      renderOpciones(json.options || []);
      modalOpciones?.show();
      return;
    }
    const p = json.proceso || {};
    const accion = json.created ? 'Proceso creado' : 'Proceso abierto existente';
    setProcesoMsg(`${accion}: N° ${p.numero_proceso} (${fmtServicio(p)} / ${p.cargo || ''})`, 'success');
    modalOpciones?.hide();
  }

  function renderOpciones(options) {
    if (!options.length) {
      bodyOpciones.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sin servicios/cargos asociados</td></tr>';
      return;
    }
    bodyOpciones.innerHTML = options.map((row) => `
      <tr>
        <td>${escapeHtml(fmtServicio(row))}</td>
        <td>${escapeHtml(row.cargo || '')}</td>
        <td class="text-center">
          <button type="button" class="btn btn-success btn-sm btn-crear-opcion" data-servicio="${Number(row.id_servicio)}" data-cargo="${Number(row.id_cargo)}">Generar</button>
        </td>
      </tr>
    `).join('');
  }

  bodyOpciones?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-crear-opcion');
    if (!btn) return;
    try {
      btn.disabled = true;
      await crearProceso({id_servicio: Number(btn.dataset.servicio), id_cargo: Number(btn.dataset.cargo)});
    } catch (err) {
      setProcesoMsg(err.message || 'Error al generar proceso', 'danger');
    } finally {
      btn.disabled = false;
    }
  });

  btnGenerar?.addEventListener('click', async () => {
    try {
      btnGenerar.disabled = true;
      setProcesoMsg('Procesando...', 'secondary');
      await crearProceso();
    } catch (err) {
      setProcesoMsg(err.message || 'Error al generar proceso', 'danger');
    } finally {
      btnGenerar.disabled = false;
    }
  });

  btnAnterior?.addEventListener('click', async () => {
    try {
      btnAnterior.disabled = true;
      const json = await postJson('ajax_procesos_habilitacion_rut.php', {rut});
      renderProcesos(json.data || []);
      modalAnteriores?.show();
    } catch (err) {
      setProcesoMsg(err.message || 'Error al cargar procesos abiertos', 'danger');
    } finally {
      btnAnterior.disabled = false;
    }
  });

  function renderProcesos(rows) {
    if (!rows.length) {
      bodyAnteriores.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No hay procesos abiertos para este RUT</td></tr>';
      return;
    }
    bodyAnteriores.innerHTML = rows.map((row) => `
      <tr>
        <td class="text-center">${escapeHtml(row.numero_proceso || '')}</td>
        <td>${escapeHtml(fmtServicio(row))}</td>
        <td>${escapeHtml(row.cargo || '')}</td>
        <td class="text-center"><span class="badge text-bg-success">${escapeHtml(row.estado || '')}</span></td>
        <td class="text-center">${escapeHtml(fmtFecha(row.fecha_inicio || ''))}</td>
        <td class="text-center"><button type="button" class="btn btn-outline-primary btn-sm btn-usar-proceso" data-id="${Number(row.id)}" data-numero="${escapeHtml(row.numero_proceso || '')}" data-servicio="${escapeHtml(fmtServicio(row))}" data-cargo="${escapeHtml(row.cargo || '')}">Usar</button></td>
      </tr>
    `).join('');
  }

  bodyAnteriores?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-usar-proceso');
    if (!btn) return;
    try {
      btn.disabled = true;
      await postJson('ajax_proceso_habilitacion_seleccionar.php', {rut, id: Number(btn.dataset.id)});
      setProcesoMsg(`Proceso seleccionado para planificación: N° ${btn.dataset.numero} (${btn.dataset.servicio} / ${btn.dataset.cargo})`, 'success');
      modalAnteriores?.hide();
    } catch (err) {
      setProcesoMsg(err.message || 'Error al seleccionar proceso', 'danger');
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>

<!-- =========================================
     INICIO MODAL SERVICIOS POR HABILITAR
========================================= -->
<div class="modal fade" id="modalHabilitaciones" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">

      <div class="modal-header bg-primary text-white rounded-top-4">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-calendar2-plus me-2"></i>
          Asignar Servicios de habilitación del Trabajador según cargo
        </h5>

        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Datos base ocultos -->
        <input type="hidden" id="mp_rut" value="<?= esc($rut) ?>">
        <input type="hidden" id="mp_cuadrilla" value="<?= (int)($trabajador['cuadrilla'] ?? 0) ?>">
        <input type="hidden" id="mp_edit_id" value="">

        <div class="alert alert-info rounded-3 mb-3">
          Selecciona el <strong>cargo</strong> del trabajador, agrega los <strong>servicios</strong> que debe aprobar y luego presiona <strong>Grabar</strong>.
        </div>

        <!-- Cabecera de ingreso -->
        <div class="row g-2 align-items-end mb-3">

          <div class="col-md-4">
            <label class="form-label fw-semibold">Cargo</label>
            <select id="mp_cargo" class="form-select">
              <option value="">-- Seleccionar --</option>
              <?php foreach ($cargos as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)($trabajador['id_cargo'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                  <?= esc($c['cargo']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Servicio</label>
            <select id="mp_servicio" class="form-select">
              <option value="">-- Seleccionar --</option>
              <?php foreach ($serviciosPrueba as $s): ?>
                <option value="<?= (int)$s['id'] ?>">
                  <?= esc($s['servicio']) ?><?= $s['descripcion'] ? ' - '.esc($s['descripcion']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Otro</label>
            <input type="text" id="mp_otro" class="form-control" maxlength="255" placeholder="Opcional">
          </div>

          <div class="col-12 d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary rounded-3" id="mp_btnAgregar">
              <i class="bi bi-plus-circle me-1"></i> Agregar
            </button>

            <button type="button" class="btn btn-secondary rounded-3 ms-auto" data-bs-dismiss="modal">
              <i class="bi bi-x-circle me-1"></i> Cerrar
            </button>
          </div>
        </div>

        <!-- Tabla líneas -->
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
              <tr class="text-center">
                <th style="width:180px;">Cargo</th>
                <th class="text-start">Servicio</th>
                <th class="text-start">Otro</th>
                <th style="width:90px;">Teórica</th>
                <th style="width:90px;">Terreno</th>
                <th style="width:90px;">Nota final</th>
                <th style="width:130px;">Estado actual</th>
                <th style="width:110px;">Fecha cálculo</th>
                <th style="width:150px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="mp_body">
              <tr>
                <td colspan="9" class="text-center text-muted">Sin líneas agregadas</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div id="mp_resumen_estado" class="small text-muted mt-2"></div>

        <div id="mp_msg" class="mt-2"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-primary rounded-3" id="mp_btnGrabar">
          <i class="bi bi-save me-1"></i> Grabar servicios
        </button>
      </div>

    </div>
  </div>
</div>

<script>
(function () {
  const body = document.getElementById('mp_body');
  const msg  = document.getElementById('mp_msg');
  const resumenEstado = document.getElementById('mp_resumen_estado');

  const selCargo    = document.getElementById('mp_cargo');
  const selServicio = document.getElementById('mp_servicio');
  const inpOtro     = document.getElementById('mp_otro');
  const inpEditId   = document.getElementById('mp_edit_id');

  const rut       = document.getElementById('mp_rut')?.value || '';
  const cuadrilla = parseInt(document.getElementById('mp_cuadrilla')?.value || '0', 10);

  const btnAgregar = document.getElementById('mp_btnAgregar');
  const btnGrabar  = document.getElementById('mp_btnGrabar');

  const lines = [];     // nuevas filas no guardadas
  const persisted = []; // filas ya guardadas en BD

  function setMsg(html, kind='info'){
    msg.innerHTML = `<div class="alert alert-${kind} rounded-3 py-2 mb-0">${html}</div>`;
  }

  function clearForm(keepCargo = true){
    if (!keepCargo) selCargo.value = '';
    selServicio.value = '';
    inpOtro.value = '';
    inpEditId.value = '';
    btnAgregar.innerHTML = `<i class="bi bi-plus-circle me-1"></i> Agregar`;
  }

  function render() {
    if (!persisted.length && !lines.length) {
      body.innerHTML = `<tr><td colspan="9" class="text-center text-muted">Sin líneas agregadas</td></tr>`;
      renderResumenEstado();
      return;
    }

    let html = '';

    persisted.forEach((p, idx) => {
      html += `
        <tr class="text-center table-warning">
          <td>${escapeHtml(p.cargo_txt)}</td>
          <td class="text-start">
            ${escapeHtml(p.servicio_txt)}
            <span class="badge bg-warning text-dark ms-2">Guardado</span>
          </td>
          <td class="text-start">${escapeHtml(p.otro || '')}</td>
          <td class="text-end">${fmtNota(p.nota_teorica)}</td>
          <td class="text-end">${fmtNota(p.nota_terreno)}</td>
          <td class="text-end fw-semibold">${fmtNota(p.nota_final)}</td>
          <td>${badgeResultado(p.resultado_final)}</td>
          <td>${escapeHtml(fmtFecha(p.fecha_calculo || ''))}</td>
          <td>
            <button type="button" class="btn btn-sm btn-outline-primary me-1" data-edit-persisted="${idx}">
              <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-delete-persisted="${idx}">
              <i class="bi bi-trash"></i>
            </button>
          </td>
        </tr>
      `;
    });

    html += lines.map((l, idx) => `
      <tr class="text-center">
        <td>${escapeHtml(l.cargo_txt)}</td>
        <td class="text-start">${escapeHtml(l.servicio_txt)}</td>
        <td class="text-start">${escapeHtml(l.otro || '')}</td>
        <td class="text-end">-</td>
        <td class="text-end">-</td>
        <td class="text-end fw-semibold">-</td>
        <td>${badgeResultado('Sin resultado')}</td>
        <td>-</td>
        <td>
          <button type="button" class="btn btn-sm btn-outline-primary me-1" data-edit-new="${idx}">
            <i class="bi bi-pencil"></i>
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger" data-del="${idx}">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>
    `).join('');

    body.innerHTML = html;
    renderResumenEstado();
  }

  function fmtNota(value) {
    if (value === null || value === undefined || value === '') return '-';
    const n = Number(String(value).replace(',', '.'));
    return Number.isFinite(n) ? n.toFixed(2) : escapeHtml(value);
  }

  function fmtFecha(value) {
    const text = String(value || '').trim();
    if (!text || text.startsWith('0000-00-00')) return '-';
    const parts = text.substring(0, 10).split('-');
    if (parts.length !== 3) return text;
    return `${parts[2]}-${parts[1]}-${parts[0]}`;
  }

  function normalizarResultado(value) {
    const text = String(value || '').trim().toUpperCase();
    if (text === 'APROBADO') return 'APROBADO';
    if (text === 'REPROBADO') return 'REPROBADO';
    if (text === 'PENDIENTE') return 'PENDIENTE';
    return 'SIN RESULTADO';
  }

  function badgeResultado(value) {
    const resultado = normalizarResultado(value);
    const cls = resultado === 'APROBADO' ? 'success' : (resultado === 'REPROBADO' ? 'danger' : (resultado === 'PENDIENTE' ? 'warning text-dark' : 'secondary'));
    const label = resultado === 'SIN RESULTADO' ? 'Sin resultado' : resultado;
    return `<span class="badge text-bg-${cls}">${escapeHtml(label)}</span>`;
  }

  function renderResumenEstado() {
    if (!resumenEstado) return;
    const registros = [
      ...persisted.map(p => normalizarResultado(p.resultado_final)),
      ...lines.map(() => 'SIN RESULTADO')
    ];
    if (!registros.length) {
      resumenEstado.textContent = 'Sin servicios seleccionados para resumir.';
      return;
    }
    const aprobados = registros.filter(x => x === 'APROBADO').length;
    const reprobados = registros.filter(x => x === 'REPROBADO').length;
    const pendientes = registros.filter(x => x === 'PENDIENTE').length;
    const sinResultado = registros.filter(x => x === 'SIN RESULTADO').length;
    resumenEstado.innerHTML = `Resumen estado actual: <strong>${aprobados}</strong> aprobados · <strong>${reprobados}</strong> reprobados · <strong>${pendientes}</strong> pendientes · <strong>${sinResultado}</strong> sin resultado`;
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
  }

  function currentDuplicateExists(idCargo, idServicio, excludeNewIdx = null, excludePersistedId = null) {
    const inNew = lines.some((x, idx) =>
      idx !== excludeNewIdx &&
      Number(x.id_cargo) === Number(idCargo) &&
      Number(x.id_servicio) === Number(idServicio)
    );

    const inPersisted = persisted.some(x =>
      Number(x.id) !== Number(excludePersistedId) &&
      Number(x.id_cargo) === Number(idCargo) &&
      Number(x.id_servicio) === Number(idServicio)
    );

    return inNew || inPersisted;
  }

  body.addEventListener('click', async (e) => {
    const btnDelNew = e.target.closest('button[data-del]');
    if (btnDelNew) {
      const idx = parseInt(btnDelNew.getAttribute('data-del'), 10);
      lines.splice(idx, 1);
      render();
      return;
    }

    const btnEditNew = e.target.closest('button[data-edit-new]');
    if (btnEditNew) {
      const idx = parseInt(btnEditNew.getAttribute('data-edit-new'), 10);
      const row = lines[idx];
      if (!row) return;

      selCargo.value = row.id_cargo;
      selServicio.value = row.id_servicio;
      inpOtro.value = row.otro || '';
      inpEditId.value = `new:${idx}`;
      btnAgregar.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Actualizar`;
      return;
    }

    const btnEditPersisted = e.target.closest('button[data-edit-persisted]');
    if (btnEditPersisted) {
      const idx = parseInt(btnEditPersisted.getAttribute('data-edit-persisted'), 10);
      const row = persisted[idx];
      if (!row) return;

      selCargo.value = row.id_cargo;
      selServicio.value = row.id_servicio;
      inpOtro.value = row.otro || '';
      inpEditId.value = `db:${row.id}`;
      btnAgregar.innerHTML = `<i class="bi bi-check2-circle me-1"></i> Actualizar`;
      return;
    }

    const btnDeletePersisted = e.target.closest('button[data-delete-persisted]');
    if (btnDeletePersisted) {
      const idx = parseInt(btnDeletePersisted.getAttribute('data-delete-persisted'), 10);
      const row = persisted[idx];
      if (!row) return;

      if (!confirm(`¿Eliminar el servicio "${row.servicio_txt}" asociado al trabajador?`)) {
        return;
      }

      try {
        const res = await fetch('ajax_servicios_rut_eliminar.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          credentials: 'same-origin',
          body: JSON.stringify({
            id: row.id,
            rut
          })
        });

        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo eliminar');

        persisted.splice(idx, 1);
        setMsg('✅ Servicio eliminado correctamente.', 'success');
        clearForm(true);
        render();
      } catch (err) {
        setMsg('❌ ' + (err.message || 'Error al eliminar'), 'danger');
      }
    }
  });

  btnAgregar?.addEventListener('click', async () => {
    const id_cargo = parseInt(selCargo.value || '0', 10);
    const cargo_txt = selCargo.options[selCargo.selectedIndex]?.text || '';
    const id_servicio = parseInt(selServicio.value || '0', 10);
    const servicio_txt = selServicio.options[selServicio.selectedIndex]?.text || '';
    const otro = (inpOtro.value || '').trim();
    const editId = inpEditId.value || '';

    if (!id_cargo) return setMsg('Selecciona un cargo.', 'warning');
    if (!id_servicio) return setMsg('Selecciona un servicio.', 'warning');

    if (editId.startsWith('new:')) {
      const idx = parseInt(editId.split(':')[1], 10);
      if (currentDuplicateExists(id_cargo, id_servicio, idx, null)) {
        return setMsg('Ese cargo y servicio ya fue agregado.', 'warning');
      }

      lines[idx] = { id_cargo, cargo_txt, id_servicio, servicio_txt, otro };
      setMsg('✅ Línea actualizada en la grilla.', 'success');
      clearForm(true);
      render();
      return;
    }

    if (editId.startsWith('db:')) {
      const id = parseInt(editId.split(':')[1], 10);
      if (currentDuplicateExists(id_cargo, id_servicio, null, id)) {
        return setMsg('Ese cargo y servicio ya existe asociado al trabajador.', 'warning');
      }

      try {
        const res = await fetch('ajax_servicios_rut_actualizar.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          credentials: 'same-origin',
          body: JSON.stringify({
            id,
            rut,
            id_cargo,
            id_servicio,
            otro
          })
        });

        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo actualizar');

        clearForm(true);
        await loadServiciosRut();
        setMsg('✅ Registro actualizado correctamente.', 'success');
      } catch (err) {
        setMsg('❌ ' + (err.message || 'Error al actualizar'), 'danger');
      }
      return;
    }

    if (currentDuplicateExists(id_cargo, id_servicio, null, null)) {
      return setMsg('Ese cargo y servicio ya fue agregado.', 'warning');
    }

    lines.push({ id_cargo, cargo_txt, id_servicio, servicio_txt, otro });
    msg.innerHTML = '';
    clearForm(true);
    render();
  });

  btnGrabar?.addEventListener('click', async () => {
    if (!rut) return setMsg('No se detectó RUT.', 'danger');
    if (!lines.length) return setMsg('Agrega al menos una línea.', 'warning');

    btnGrabar.disabled = true;
    setMsg('Grabando...', 'info');

    try {
      const res = await fetch('ajax_servicios_rut_guardar.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({
          rut,
          cuadrilla,
          items: lines
        })
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Error al grabar');

      setMsg(`✅ Servicios guardados. Insertados: <strong>${json.insertados ?? 0}</strong>. Omitidos: <strong>${json.omitidos ?? 0}</strong>.`, 'success');
      setTimeout(() => location.reload(), 700);

    } catch (err) {
      setMsg('❌ ' + (err.message || 'Error'), 'danger');
    } finally {
      btnGrabar.disabled = false;
    }
  });

  const modalEl = document.getElementById('modalHabilitaciones');

  async function loadServiciosRut(){
    persisted.length = 0;

    try{
      const res = await fetch('ajax_servicios_rut_listar.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ rut, cuadrilla })
      });

      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Error cargando servicios');

      (json.data || []).forEach(r => {
        persisted.push({
          id: parseInt(r.id, 10),
          id_cargo: parseInt(r.id_cargo, 10),
          cargo_txt: r.cargo || '',
          id_servicio: parseInt(r.id_servicio, 10),
          servicio_txt: r.servicio || '',
          otro: r.otro || '',
          nota_teorica: r.nota_teorica,
          nota_terreno: r.nota_terreno,
          nota_final: r.nota_final,
          resultado_final: r.resultado_final || 'Sin resultado',
          fecha_calculo: r.fecha_calculo || ''
        });
      });

      msg.innerHTML = '';
      clearForm(true);
      render();

    } catch(err){
      setMsg('⚠ No se pudieron cargar los servicios del trabajador: ' + (err.message || 'Error'), 'warning');
    }
  }
  
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', () => {
      loadServiciosRut();
    });
  }
  
  render();
})();
</script>

<!-- =========================================
    FIN MODAL SERVICIOS POR HABILITAR
========================================= -->
</body>
</html>
