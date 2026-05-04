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

const HISTORIA_CYR_PATH = __DIR__ . '/../docs/Historia CyR.csv';
const HISTORIA_CYR_SERVICIO_ID = 2;

$pdo = db();

function cyrNormalizeHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = strtr(trim($value), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
    ]);
    $value = strtoupper($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? $value;
}

function cyrNormalizeRut(string $rut): string
{
    return strtoupper(str_replace(['.', ' '], '', trim($rut)));
}

function cyrRutKey(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

function cyrSqlString(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace("'", "''", $value) . "'";
}

function cyrParseDate(?string $value): ?string
{
    $text = strtolower(trim((string)$value));
    if ($text === '') {
        return null;
    }

    $text = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $text);
    $months = [
        'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04', 'may' => '05', 'jun' => '06',
        'jul' => '07', 'ago' => '08', 'sept' => '09', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12',
    ];

    if (preg_match('/^(\d{1,2})-([a-z]+)-(\d{2,4})$/', $text, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = $months[$m[2]] ?? null;
        if ($month === null) {
            return null;
        }
        $year = (int)$m[3];
        if ($year < 100) {
            $year += 2000;
        }
        return sprintf('%04d-%s-%s', $year, $month, $day);
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $text, $m)) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year = (int)$m[3];
        if ($year < 100) {
            $year += 2000;
        }
        return sprintf('%04d-%s-%s', $year, $month, $day);
    }

    return null;
}

function cyrEstadoProceso(string $estado): string
{
    $estado = strtoupper(trim($estado));
    return in_array($estado, ['SI', 'NO'], true) ? 'CERRADO' : 'ABIERTO';
}

function cyrLoadRows(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('No se encontró el archivo docs/Historia CyR.csv');
    }

    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('No fue posible abrir Historia CyR.csv');
    }

    $headersRaw = fgetcsv($fh, 0, ';', '"', '');
    if (!is_array($headersRaw)) {
        fclose($fh);
        throw new RuntimeException('El archivo no tiene encabezados válidos.');
    }

    $headers = [];
    foreach ($headersRaw as $idx => $header) {
        $headers[cyrNormalizeHeader((string)$header)] = $idx;
    }

    foreach (['N', 'EVALUACION', 'RUT', 'FECHATerreno', 'CARGO', 'FECHAPRUEBA', 'ESTADO'] as $header) {
        if (!array_key_exists(cyrNormalizeHeader($header), $headers)) {
            fclose($fh);
            throw new RuntimeException('Falta columna requerida: ' . $header);
        }
    }

    $rows = [];
    while (($data = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        $numero = (int)trim((string)($data[$headers['N']] ?? '0'));
        $rut = cyrNormalizeRut((string)($data[$headers['RUT']] ?? ''));
        if ($numero <= 0 || $rut === '') {
            continue;
        }

        $rows[] = [
            'numero_proceso' => $numero,
            'evaluacion' => (int)trim((string)($data[$headers['EVALUACION']] ?? '0')),
            'rut' => $rut,
            'rut_original' => trim((string)($data[$headers['RUT']] ?? '')),
            'fecha_terreno' => cyrParseDate((string)($data[$headers[cyrNormalizeHeader('FEcha Terreno')]] ?? '')),
            'cargo' => strtoupper(trim((string)($data[$headers['CARGO']] ?? ''))),
            'fecha_prueba' => cyrParseDate((string)($data[$headers['FECHAPRUEBA']] ?? '')),
            'estado_csv' => trim((string)($data[$headers['ESTADO']] ?? '')),
            'fecha_evaluacion' => cyrParseDate((string)($data[$headers[cyrNormalizeHeader('Fecha Evaluación')]] ?? '')),
        ];
    }

    fclose($fh);
    return $rows;
}

function cyrAnalyze(PDO $pdo, array $rows): array
{
    $stmtTeorica = $pdo->prepare("SELECT COUNT(*) FROM ceo_resultado_prueba_intento WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha");
    $stmtTerreno = $pdo->prepare("SELECT COUNT(*) FROM ceo_evaluacion_terreno WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut AND id_servicio = :servicio AND DATE(fecha_evaluacion) = :fecha");
    $stmtTerrenoIntento = $pdo->prepare("SELECT COUNT(*) FROM ceo_resultado_terreno_intento WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha");

    $procesos = [];
    foreach ($rows as $row) {
        $n = $row['numero_proceso'];
        if (!isset($procesos[$n])) {
            $procesos[$n] = [
                'numero_proceso' => $n,
                'ruts' => [],
                'servicios' => [HISTORIA_CYR_SERVICIO_ID => true],
                'fechas' => [],
                'estado_final' => 'ABIERTO',
                'rows' => [],
                'teoricas_match' => 0,
                'terrenos_match' => 0,
                'terreno_intentos_match' => 0,
                'teoricas_sin_match' => 0,
                'terrenos_sin_match' => 0,
                'inconsistente' => false,
            ];
        }

        $procesos[$n]['ruts'][$row['rut']] = true;
        foreach (['fecha_terreno', 'fecha_prueba', 'fecha_evaluacion'] as $field) {
            if (!empty($row[$field])) {
                $procesos[$n]['fechas'][] = $row[$field];
            }
        }
        $procesos[$n]['rows'][] = $row;
    }

    foreach ($procesos as &$proceso) {
        usort($proceso['rows'], static function (array $a, array $b): int {
            return [max($a['fecha_prueba'] ?? '', $a['fecha_terreno'] ?? '', $a['fecha_evaluacion'] ?? ''), $a['evaluacion']]
                <=> [max($b['fecha_prueba'] ?? '', $b['fecha_terreno'] ?? '', $b['fecha_evaluacion'] ?? ''), $b['evaluacion']];
        });

        $lastRow = end($proceso['rows']);
        $proceso['estado_final'] = cyrEstadoProceso((string)($lastRow['estado_csv'] ?? ''));
        $proceso['rut'] = (string)array_key_first($proceso['ruts']);
        $proceso['inconsistente'] = count($proceso['ruts']) !== 1 || count($proceso['servicios']) !== 1;
        $proceso['fecha_inicio'] = !empty($proceso['fechas']) ? min($proceso['fechas']) : null;
        $proceso['fecha_cierre'] = ($proceso['estado_final'] === 'CERRADO' && !empty($proceso['fechas'])) ? max($proceso['fechas']) : null;

        foreach ($proceso['rows'] as $row) {
            if (!empty($row['fecha_prueba'])) {
                $stmtTeorica->execute([':rut' => cyrRutKey((string)$row['rut']), ':servicio' => HISTORIA_CYR_SERVICIO_ID, ':fecha' => $row['fecha_prueba']]);
                $count = (int)$stmtTeorica->fetchColumn();
                $proceso['teoricas_match'] += $count;
                if ($count <= 0) {
                    $proceso['teoricas_sin_match']++;
                }
            }
            if (!empty($row['fecha_terreno'])) {
                $stmtTerreno->execute([':rut' => cyrRutKey((string)$row['rut']), ':servicio' => HISTORIA_CYR_SERVICIO_ID, ':fecha' => $row['fecha_terreno']]);
                $count = (int)$stmtTerreno->fetchColumn();
                $proceso['terrenos_match'] += $count;
                if ($count <= 0) {
                    $proceso['terrenos_sin_match']++;
                }

                $stmtTerrenoIntento->execute([':rut' => cyrRutKey((string)$row['rut']), ':servicio' => HISTORIA_CYR_SERVICIO_ID, ':fecha' => $row['fecha_terreno']]);
                $proceso['terreno_intentos_match'] += (int)$stmtTerrenoIntento->fetchColumn();
            }
        }
    }
    unset($proceso);

    ksort($procesos);
    return $procesos;
}

function cyrGenerateSql(array $procesos): string
{
    $sql = [];
    $sql[] = '-- Normalización Historia CyR hacia ceo_proceso_habilitacion';
    $sql[] = '-- Revisar antes de ejecutar. No incluye vigencias ni resultado_final_servicio.';
    $sql[] = 'START TRANSACTION;';

    foreach ($procesos as $p) {
        if (!empty($p['inconsistente'])) {
            $sql[] = '-- OMITIDO N ' . (int)$p['numero_proceso'] . ': inconsistente (más de un RUT o servicio).';
            continue;
        }

        $numero = (int)$p['numero_proceso'];
        $rut = (string)$p['rut'];
        $estado = (string)$p['estado_final'];
        $fechaInicio = $p['fecha_inicio'] ? $p['fecha_inicio'] . ' 00:00:00' : null;
        $fechaCierre = $p['fecha_cierre'] ? $p['fecha_cierre'] . ' 00:00:00' : null;

        $sql[] = "\n-- Proceso {$numero} / RUT {$rut}";
        $sql[] = 'INSERT INTO ceo_proceso_habilitacion (rut, id_servicio, numero_proceso, estado, origen, fecha_inicio, fecha_cierre) VALUES ('
            . cyrSqlString($rut) . ', '
            . HISTORIA_CYR_SERVICIO_ID . ', '
            . $numero . ', '
            . cyrSqlString($estado) . ', '
            . "'HISTORICO_CYR', "
            . cyrSqlString($fechaInicio) . ', '
            . cyrSqlString($fechaCierre)
            . ') ON DUPLICATE KEY UPDATE rut = VALUES(rut), id_servicio = VALUES(id_servicio), estado = VALUES(estado), origen = VALUES(origen), fecha_inicio = VALUES(fecha_inicio), fecha_cierre = VALUES(fecha_cierre);';

        foreach ($p['rows'] as $row) {
            if (!empty($row['fecha_prueba'])) {
                $sql[] = 'UPDATE ceo_resultado_prueba_intento rpi INNER JOIN ceo_proceso_habilitacion ph ON ph.numero_proceso = ' . $numero
                    . ' SET rpi.id_proceso_habilitacion = ph.id'
                    . " WHERE REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '') = " . cyrSqlString(cyrRutKey((string)$row['rut']))
                    . ' AND rpi.id_servicio = ' . HISTORIA_CYR_SERVICIO_ID
                    . ' AND rpi.fecha_rendicion = ' . cyrSqlString((string)$row['fecha_prueba']) . ';';
            }

            if (!empty($row['fecha_terreno'])) {
                $sql[] = 'UPDATE ceo_evaluacion_terreno et INNER JOIN ceo_proceso_habilitacion ph ON ph.numero_proceso = ' . $numero
                    . ' SET et.id_proceso_habilitacion = ph.id'
                    . " WHERE REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '') = " . cyrSqlString(cyrRutKey((string)$row['rut']))
                    . ' AND et.id_servicio = ' . HISTORIA_CYR_SERVICIO_ID
                    . ' AND DATE(et.fecha_evaluacion) = ' . cyrSqlString((string)$row['fecha_terreno']) . ';';

                $sql[] = 'UPDATE ceo_resultado_terreno_intento rti INNER JOIN ceo_proceso_habilitacion ph ON ph.numero_proceso = ' . $numero
                    . ' SET rti.id_proceso_habilitacion = ph.id'
                    . " WHERE REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') = " . cyrSqlString(cyrRutKey((string)$row['rut']))
                    . ' AND rti.id_servicio = ' . HISTORIA_CYR_SERVICIO_ID
                    . ' AND rti.fecha_rendicion = ' . cyrSqlString((string)$row['fecha_terreno']) . ';';
            }
        }
    }

    $sql[] = 'COMMIT;';
    return implode("\n", $sql) . "\n";
}

$error = '';
$procesos = [];

try {
    $rows = cyrLoadRows(HISTORIA_CYR_PATH);
    $procesos = cyrAnalyze($pdo, $rows);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (($_GET['accion'] ?? '') === 'sql' && $error === '') {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename=normalizar_historia_cyr_procesos.sql');
    echo cyrGenerateSql($procesos);
    exit;
}

$totales = [
    'procesos' => count($procesos),
    'inconsistentes' => 0,
    'teoricas_match' => 0,
    'terrenos_match' => 0,
    'terreno_intentos_match' => 0,
    'teoricas_sin_match' => 0,
    'terrenos_sin_match' => 0,
];
foreach ($procesos as $p) {
    $totales['inconsistentes'] += !empty($p['inconsistente']) ? 1 : 0;
    foreach (['teoricas_match', 'terrenos_match', 'terreno_intentos_match', 'teoricas_sin_match', 'terrenos_sin_match'] as $k) {
        $totales[$k] += (int)$p[$k];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Normalizar Historia CyR - <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body {background:#f7f9fc;}
    .topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
    .brand-title {color:#0065a4; font-weight:700;}
    .card {border:none; border-radius:18px; box-shadow:0 8px 24px rgba(20,50,80,.08);}
    .metric {background:#0b5f8f; color:#fff; border-radius:16px; padding:14px;}
    .metric strong {display:block; font-size:1.45rem; line-height:1;}
    .table-responsive {max-height:640px; overflow:auto;}
    .table {min-width:1300px;}
    .table thead {position:sticky; top:0; z-index:2;}
    .table th {background:#eaf2fb; font-size:.8rem;}
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= esc(APP_NAME) ?>" style="height:58px;">
      <div>
        <div class="brand-title h4 mb-0"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary">Previsualización normalización Historia CyR</small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container-fluid px-4 pb-4">
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
  <?php else: ?>
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
          <div>
            <h1 class="h4 text-primary mb-1">Historia CyR.csv → procesos de habilitación</h1>
            <p class="text-muted mb-0">No ejecuta cambios. Genera SQL revisable para servicio CyR (`id_servicio = 2`).</p>
          </div>
          <a class="btn btn-success" href="normalizar_historia_cyr_preview.php?accion=sql"><i class="bi bi-download me-1"></i> Descargar SQL</a>
        </div>
        <div class="row g-3">
          <div class="col-6 col-md-2"><div class="metric"><small>Procesos</small><strong><?= (int)$totales['procesos'] ?></strong></div></div>
          <div class="col-6 col-md-2"><div class="metric"><small>Inconsistentes</small><strong><?= (int)$totales['inconsistentes'] ?></strong></div></div>
          <div class="col-6 col-md-2"><div class="metric"><small>Teóricas match</small><strong><?= (int)$totales['teoricas_match'] ?></strong></div></div>
          <div class="col-6 col-md-2"><div class="metric"><small>Terreno match</small><strong><?= (int)$totales['terrenos_match'] ?></strong></div></div>
          <div class="col-6 col-md-2"><div class="metric"><small>Teóricas sin match</small><strong><?= (int)$totales['teoricas_sin_match'] ?></strong></div></div>
          <div class="col-6 col-md-2"><div class="metric"><small>Terreno sin match</small><strong><?= (int)$totales['terrenos_sin_match'] ?></strong></div></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body p-3">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="text-center">
              <tr>
                <th>N</th>
                <th>RUT</th>
                <th>Estado proceso</th>
                <th>Fecha inicio</th>
                <th>Fecha cierre</th>
                <th>Filas CSV</th>
                <th>Teóricas match</th>
                <th>Terreno match</th>
                <th>Intento terreno match</th>
                <th>Teóricas sin match</th>
                <th>Terreno sin match</th>
                <th>Inconsistencia</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($procesos as $p): ?>
              <tr class="<?= !empty($p['inconsistente']) ? 'table-danger' : '' ?>">
                <td class="text-center fw-bold"><?= (int)$p['numero_proceso'] ?></td>
                <td><?= esc(implode(', ', array_keys($p['ruts']))) ?></td>
                <td class="text-center"><?= esc($p['estado_final']) ?></td>
                <td><?= esc((string)$p['fecha_inicio']) ?></td>
                <td><?= esc((string)$p['fecha_cierre']) ?></td>
                <td class="text-center"><?= count($p['rows']) ?></td>
                <td class="text-center"><?= (int)$p['teoricas_match'] ?></td>
                <td class="text-center"><?= (int)$p['terrenos_match'] ?></td>
                <td class="text-center"><?= (int)$p['terreno_intentos_match'] ?></td>
                <td class="text-center"><?= (int)$p['teoricas_sin_match'] ?></td>
                <td class="text-center"><?= (int)$p['terrenos_sin_match'] ?></td>
                <td><?= !empty($p['inconsistente']) ? 'Más de un RUT/servicio para el mismo N' : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
