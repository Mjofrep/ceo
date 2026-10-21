<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

function salaRutComparable(string $field): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER({$field}), '.', ''), '-', ''), ' ', '')";
}

$pdo = db();

$tipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$id = (int)($_GET['id'] ?? 0);
$fecha = trim((string)($_GET['fecha'] ?? ''));

if (!in_array($tipo, ['HABILITACION', 'FORMACION'], true) || $id <= 0) {
    http_response_code(400);
    exit('Parámetros inválidos.');
}

$cabecera = null;
$rows = [];
$error = '';

try {
    if ($tipo === 'HABILITACION') {
        $stmtCab = $pdo->prepare(" 
            SELECT
                h.id,
                h.cuadrilla,
                h.id_servicio,
                h.fecha,
                h.nsolicitud,
                e.nombre AS empresa,
                u.desc_uo AS uo,
                sp.servicio
            FROM ceo_habilitacion h
            LEFT JOIN ceo_empresas e ON e.id = h.empresa
            LEFT JOIN ceo_uo u ON u.id = h.uo
            LEFT JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
            WHERE h.id = :id
            LIMIT 1
        ");
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($cabecera) {
            $rutP = salaRutComparable('p.rut');
            $rutEp = salaRutComparable('ep.rut');
            $sql = "
                SELECT
                    p.rut,
                    p.nombre,
                    p.apellidos,
                    u.desc_uo AS uo,
                    p.cargo,
                    e.nombre AS empresa,
                    COALESCE(
                        (
                            SELECT GROUP_CONCAT(DISTINCT TRIM(COALESCE(a.titulo, '')) ORDER BY a.id SEPARATOR ' / ')
                            FROM ceo_evaluaciones_programadas ep
                            LEFT JOIN ceo_agrupacion a ON a.id = ep.id_agrupacion
                            WHERE {$rutEp} = {$rutP}
                              AND ep.id_servicio = :id_servicio_1
                              AND ep.cuadrilla = :cuadrilla_1
                              AND ep.tipo = 'PRUEBA'
                              AND UPPER(TRIM(COALESCE(ep.estado, ''))) <> 'ANULADA'
                        ),
                        ''
                    ) AS prueba_a_rendir,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_evaluaciones_programadas ep
                            WHERE {$rutEp} = {$rutP}
                              AND ep.id_servicio = :id_servicio_2
                              AND ep.cuadrilla = :cuadrilla_2
                              AND ep.tipo = 'PRUEBA'
                              AND ep.estado = 'PENDIENTE'
                              AND ep.resultado = 'PENDIENTE'
                        ) THEN 1 ELSE 0
                    END AS planificado_prueba,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_evaluaciones_programadas ep
                            WHERE {$rutEp} = {$rutP}
                              AND ep.id_servicio = :id_servicio_3
                              AND ep.cuadrilla = :cuadrilla_3
                              AND ep.tipo = 'PRUEBA'
                               AND ep.asistio_sala = 1
                        ) THEN 1 ELSE 0
                    END AS asistio_sala,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_participantes_solicitud ps
                            WHERE ps.id_solicitud = h.nsolicitud
                              AND " . salaRutComparable('ps.rut') . " = {$rutP}
                              AND ps.asistio = 1
                        ) THEN 1 ELSE 0
                    END AS asistio_solicitud,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_evaluaciones_programadas ep
                            WHERE {$rutEp} = {$rutP}
                              AND ep.id_servicio = :id_servicio_4
                              AND ep.cuadrilla = :cuadrilla_4
                              AND ep.tipo = 'PRUEBA'
                        ) THEN 1 ELSE 0
                    END AS tiene_programacion
                FROM ceo_habilitacion_participantes p
                INNER JOIN ceo_habilitacion h ON h.cuadrilla = p.id_cuadrilla
                LEFT JOIN ceo_empresas e ON e.id = h.empresa
                LEFT JOIN ceo_uo u ON u.id = h.uo
                WHERE h.id = :id
                ORDER BY p.apellidos ASC, p.nombre ASC, p.rut ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':id_servicio_1' => (int)$cabecera['id_servicio'],
                ':cuadrilla_1' => (int)$cabecera['cuadrilla'],
                ':id_servicio_2' => (int)$cabecera['id_servicio'],
                ':cuadrilla_2' => (int)$cabecera['cuadrilla'],
                ':id_servicio_3' => (int)$cabecera['id_servicio'],
                ':cuadrilla_3' => (int)$cabecera['cuadrilla'],
                ':id_servicio_4' => (int)$cabecera['id_servicio'],
                ':cuadrilla_4' => (int)$cabecera['cuadrilla'],
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $stmtCab = $pdo->prepare(" 
            SELECT
                f.id,
                f.cuadrilla,
                f.id_servicio,
                f.fecha,
                f.nsolicitud,
                e.nombre AS empresa,
                u.desc_uo AS uo,
                fs.servicio
            FROM ceo_formacion f
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_uo u ON u.id = f.uo
            LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmtCab->execute([':id' => $id]);
        $cabecera = $stmtCab->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($cabecera) {
            $rutP = salaRutComparable('p.rut');
            $rutFp = salaRutComparable('fp.rut');
            $sql = "
                SELECT
                    p.rut,
                    p.nombre,
                    p.apellidos,
                    u.desc_uo AS uo,
                    p.cargo,
                    e.nombre AS empresa,
                    COALESCE(
                        (
                            SELECT GROUP_CONCAT(DISTINCT TRIM(COALESCE(a.titulo, '')) ORDER BY a.id SEPARATOR ' / ')
                            FROM ceo_formacion_programadas fp
                            LEFT JOIN ceo_formacion_agrupacion a ON a.id = fp.id_agrupacion
                            WHERE {$rutFp} = {$rutP}
                              AND fp.id_servicio = :id_servicio_1
                              AND fp.cuadrilla = :cuadrilla_1
                              AND fp.tipo = 'PRUEBA'
                              AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
                        ),
                        ''
                    ) AS prueba_a_rendir,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_formacion_programadas fp
                            WHERE {$rutFp} = {$rutP}
                              AND fp.id_servicio = :id_servicio_2
                              AND fp.cuadrilla = :cuadrilla_2
                              AND fp.tipo = 'PRUEBA'
                              AND fp.estado = 'PENDIENTE'
                              AND fp.resultado = 'PENDIENTE'
                        ) THEN 1 ELSE 0
                    END AS planificado_prueba,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_formacion_programadas fp
                            WHERE {$rutFp} = {$rutP}
                              AND fp.id_servicio = :id_servicio_3
                              AND fp.cuadrilla = :cuadrilla_3
                              AND fp.tipo = 'PRUEBA'
                               AND fp.asistio_sala = 1
                        ) THEN 1 ELSE 0
                    END AS asistio_sala,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_formacion_participantes_solicitud ps
                            WHERE ps.id_solicitud = f.nsolicitud
                              AND " . salaRutComparable('ps.rut') . " = {$rutP}
                              AND ps.asistio = 1
                        ) THEN 1 ELSE 0
                    END AS asistio_solicitud,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM ceo_formacion_programadas fp
                            WHERE {$rutFp} = {$rutP}
                              AND fp.id_servicio = :id_servicio_4
                              AND fp.cuadrilla = :cuadrilla_4
                              AND fp.tipo = 'PRUEBA'
                        ) THEN 1 ELSE 0
                    END AS tiene_programacion
                FROM ceo_formacion_participantes p
                INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
                LEFT JOIN ceo_empresas e ON e.id = f.empresa
                LEFT JOIN ceo_uo u ON u.id = f.uo
                WHERE f.id = :id
                ORDER BY p.apellidos ASC, p.nombre ASC, p.rut ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':id_servicio_1' => (int)$cabecera['id_servicio'],
                ':cuadrilla_1' => (int)$cabecera['cuadrilla'],
                ':id_servicio_2' => (int)$cabecera['id_servicio'],
                ':cuadrilla_2' => (int)$cabecera['cuadrilla'],
                ':id_servicio_3' => (int)$cabecera['id_servicio'],
                ':cuadrilla_3' => (int)$cabecera['cuadrilla'],
                ':id_servicio_4' => (int)$cabecera['id_servicio'],
                ':cuadrilla_4' => (int)$cabecera['cuadrilla'],
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $error = 'Error SQL: ' . $e->getMessage();
}

if (!$cabecera) {
    http_response_code(404);
    exit('Cuadrilla no encontrada.');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Sala de Pruebas - Detalle | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}
.table th {background:#eaf2fb; white-space:nowrap; text-align:center;}
.table td {vertical-align:middle;}
td input[type=checkbox]{transform:scale(1.15);}
.execution-led {display:inline-block; width:12px; height:12px; border-radius:50%; background:#dc3545; box-shadow:0 0 0 2px rgba(220,53,69,.15);}
.execution-led.is-executed {background:#198754; box-shadow:0 0 0 2px rgba(25,135,84,.15);}
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="sala_pruebas_cuadrillas.php?fecha=<?= urlencode($fecha) ?>" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container-fluid px-4">
    <div class="card rounded-4 shadow-sm mb-4">
        <div class="card-body py-3">
            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-people me-2"></i>Sala de Pruebas - Detalle Cuadrilla</h4>
        </div>
    </div>

    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2"><strong>Tipo:</strong><br><?= esc($tipo) ?></div>
                <div class="col-md-2"><strong>Cuadrilla:</strong><br><?= esc((string)($cabecera['cuadrilla'] ?? '')) ?></div>
                <div class="col-md-2"><strong>Fecha:</strong><br><?= esc((string)($cabecera['fecha'] ?? '')) ?></div>
                <div class="col-md-2"><strong>N° Solicitud:</strong><br><?= esc((string)($cabecera['nsolicitud'] ?? '')) ?></div>
                <div class="col-md-2"><strong>Empresa:</strong><br><?= esc((string)($cabecera['empresa'] ?? '')) ?></div>
                <div class="col-md-2"><strong>UO:</strong><br><?= esc((string)($cabecera['uo'] ?? '')) ?></div>
                <div class="col-md-12"><strong>Servicio:</strong><br><?= esc((string)($cabecera['servicio'] ?? '')) ?></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm rounded-4">
        <div class="card-body">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= esc($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($rows)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>RUT</th>
                                <th>Nombre</th>
                                <th>Apellidos</th>
                                <th>UO</th>
                                <th>Cargo</th>
                                <th>Empresa</th>
                                 <th>Prueba que debe rendir</th>
                                 <th>Planificado prueba</th>
                                 <th>Asistió en solicitud</th>
                                 <th>Asiste a darla</th>
                                 <th>Prueba dada</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= esc((string)$row['rut']) ?></td>
                                <td><?= esc((string)$row['nombre']) ?></td>
                                <td><?= esc((string)$row['apellidos']) ?></td>
                                <td><?= esc((string)($row['uo'] ?? '')) ?></td>
                                <td><?= esc((string)($row['cargo'] ?? '')) ?></td>
                                <td><?= esc((string)($row['empresa'] ?? '')) ?></td>
                                <td><?= esc((string)($row['prueba_a_rendir'] ?? '')) ?></td>
                                 <td class="text-center">
                                     <input type="checkbox" disabled <?= (int)($row['planificado_prueba'] ?? 0) === 1 ? 'checked' : '' ?>>
                                 </td>
                                 <td class="text-center">
                                     <input type="checkbox" disabled <?= (int)($row['asistio_solicitud'] ?? 0) === 1 ? 'checked' : '' ?>>
                                 </td>
                                 <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="chk-asistencia-sala"
                                        data-tipo="<?= esc($tipo) ?>"
                                        data-id="<?= (int)$id ?>"
                                        data-rut="<?= esc((string)$row['rut']) ?>"
                                        <?= (int)($row['tiene_programacion'] ?? 0) === 0 ? 'disabled' : '' ?>
                                        <?= (int)($row['asistio_sala'] ?? 0) === 1 ? 'checked' : '' ?>
                                     >
                                 </td>
                                 <td class="text-center">
                                     <span class="execution-led" data-execution-led data-rut="<?= esc((string)$row['rut']) ?>" title="Prueba no dada" aria-label="Prueba no dada"></span>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center mb-0">No se encontraron participantes para esta cuadrilla.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.chk-asistencia-sala').forEach((chk) => {
    chk.addEventListener('change', function () {
        const payload = {
            tipo: this.dataset.tipo,
            id: Number(this.dataset.id || 0),
            rut: this.dataset.rut || '',
            checked: this.checked ? 1 : 0
        };

        fetch('ajax_sala_pruebas_asistencia_guardar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(resp => {
            if (!resp.ok) {
                alert(resp.msg || 'No fue posible guardar la asistencia.');
                this.checked = !this.checked;
            }
        })
        .catch(() => {
            alert('Error de comunicación con el servidor.');
            this.checked = !this.checked;
        });
    });
});

function normalizarRut(rut) {
    return String(rut || '').toUpperCase().replace(/[.\-\s]/g, '');
}

function actualizarLedsEjecucion() {
    fetch('ajax_sala_pruebas_ejecucion_estado.php?tipo=<?= urlencode($tipo) ?>&id=<?= (int)$id ?>', {cache: 'no-store'})
        .then(r => r.json())
        .then(resp => {
            if (!resp.ok) return;
            document.querySelectorAll('[data-execution-led]').forEach((led) => {
                const ejecutada = Number(resp.estados?.[normalizarRut(led.dataset.rut)]) === 1;
                led.classList.toggle('is-executed', ejecutada);
                led.title = ejecutada ? 'Prueba dada' : 'Prueba no dada';
                led.setAttribute('aria-label', led.title);
            });
        })
        .catch(() => {});
}

actualizarLedsEjecucion();
window.setInterval(actualizarLedsEjecucion, 5000);
</script>
</body>
</html>
