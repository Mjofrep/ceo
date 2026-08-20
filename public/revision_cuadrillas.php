<?php
// --------------------------------------------------------------
// revision_cuadrillas.php - Revisión de Cuadrillas (CEO)
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once '../config/db.php';
require_once '../config/functions.php';

set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
    }

    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Error en revision_cuadrillas</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container py-4">
            <div class="alert alert-danger shadow-sm">
                <h1 class="h5 mb-3">Error al cargar la revision de cuadrillas</h1>
                <p class="mb-2"><strong>Mensaje:</strong> <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mb-2"><strong>Archivo:</strong> <?= htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mb-0"><strong>Linea:</strong> <?= (int)$e->getLine() ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
});

// Si NO existe sesión válida → volver al login
if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

function revisionHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache[$key];
}

function formatearFechaRevision(mixed $valor, bool $conHora = false): string
{
    $fecha = trim((string)$valor);
    if ($fecha === '' || str_starts_with($fecha, '0000-00-00')) {
        return '';
    }

    try {
        return (new DateTimeImmutable($fecha))->format($conHora ? 'd-m-Y H:i' : 'd-m-Y');
    } catch (Throwable $e) {
        return $fecha;
    }
}

// Validación de sesión
if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

$pdo = db();
$hasEvaluacionProgramadaAgrupacion = revisionHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion');

/* ============================================================
   ENTRADA DESDE habilitacion.php (DOBLE CLICK)
============================================================ */
if (
    empty($_GET['programa']) &&
    !empty($_GET['cuadrilla']) &&
    !empty($_GET['empresa'])
) {
    $stmt = $pdo->prepare("
        SELECT id, uo
        FROM ceo_habilitacion
        WHERE cuadrilla = :cuadrilla
          AND empresa   = :empresa
        ORDER BY fecha DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':cuadrilla' => (int)$_GET['cuadrilla'],
        ':empresa'   => (int)$_GET['empresa']
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $_GET['programa'] = (int)$row['id'];   // ✅ ID correcto
        $_GET['uo']       = (int)$row['uo'];   // ✅ ID correcto
    }
}


/* ============================================================
   CARGAR DATOS BASE PARA SELECTS
   ============================================================ */

// EMPRESAS
$stmtEmp = $pdo->query("SELECT id, nombre FROM ceo_empresas ORDER BY nombre");
$empresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

// UO
$stmtUO = $pdo->query("SELECT id, desc_uo FROM ceo_uo ORDER BY desc_uo");
$uos = $stmtUO->fetchAll(PDO::FETCH_ASSOC);

// PROGRAMAS / CUADRILLAS
$stmtProg = $pdo->query("
    SELECT id, cuadrilla
    FROM ceo_habilitacion
    ORDER BY cuadrilla DESC
");
$programas = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   CONSULTA PRINCIPAL
   ============================================================ */

$data = [];
$agrupacionesPorServicio = [];

$where = [];
$params = [];

// Programa ES el filtro base
if (!empty($_GET['programa'])) {
     $where[] = 'cs.id = :prog';
    $params[':prog'] = (int)$_GET['programa'];
}


// Empresa (opcional)
// Empresa (obligatoria para contratista)
if (!empty($_GET['empresa'])) {

    // Contratista solo puede ver su empresa
    if (
        strtolower($_SESSION['auth']['rol']) === 'contratista' &&
        (int)$_GET['empresa'] !== (int)$_SESSION['auth']['id_empresa']
    ) {
        // Forzar empresa correcta
        $_GET['empresa'] = (int)$_SESSION['auth']['id_empresa'];
    }

    $where[] = 'cs.empresa = :emp';
    $params[':emp'] = (int)$_GET['empresa'];
}


// UO (opcional)
if (!empty($_GET['uo'])) {
    $where[] = 'cs.uo = :uo';
    $params[':uo'] = (int)$_GET['uo'];
}

// ⚠️ Si no hay NINGÚN filtro, no consultar
if (empty($where)) {
    $data = [];
} else {

    $selectAgrupacionProgramada = $hasEvaluacionProgramadaAgrupacion
        ? "(
        SELECT ep.id_agrupacion
        FROM ceo_evaluaciones_programadas ep
        WHERE ep.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
          AND ep.id_servicio = cs.id_servicio
          AND ep.cuadrilla = cs.cuadrilla
          AND ep.tipo = 'PRUEBA'
        ORDER BY ep.id DESC
        LIMIT 1
    )"
        : "NULL";

    $sql = "
	SELECT
	    p.rut,
    p.nombre,
    p.apellidos AS apellido,
    cs.id AS id_habilitacion,
    cs.estado AS estado_cuadrilla,
    u.desc_uo AS uo,
    p.cargo,
    e.nombre AS empresa,
    cs.cuadrilla AS n_cuadrilla,
    cs.id_servicio,
    (
        SELECT ph.numero_proceso
        FROM ceo_evaluaciones_programadas ep
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = ep.id_proceso_habilitacion
        WHERE ep.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
          AND ep.id_servicio = cs.id_servicio
          AND ep.cuadrilla = cs.cuadrilla
        ORDER BY ep.id DESC
        LIMIT 1
    ) AS numero_proceso,
	    {$selectAgrupacionProgramada} AS id_agrupacion_programada,
    CASE 
        WHEN EXISTS (
            SELECT 1 
            FROM ceo_resultado_prueba_intento x 
            WHERE x.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
        ) THEN 1 ELSE 0 
    END AS existe,

    CASE 
        WHEN EXISTS (
            SELECT 1
            FROM ceo_resultado_prueba_intento x
            WHERE x.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
              AND x.id_servicio = cs.id_servicio
        ) THEN 1 ELSE 0 
    END AS prueba,

    CASE 
        WHEN EXISTS (
            SELECT 1
            FROM ceo_evaluacion_terreno t
            WHERE t.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
              AND t.id_servicio = cs.id_servicio
        ) THEN 1 ELSE 0 
    END AS terreno,
CASE 
    WHEN EXISTS (
        SELECT 1
        FROM ceo_evaluaciones_programadas ep
        WHERE ep.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
          AND ep.id_servicio = cs.id_servicio
          AND ep.cuadrilla = cs.cuadrilla
          AND ep.tipo = 'PRUEBA'
          AND ep.estado = 'PENDIENTE'
    ) THEN 1 ELSE 0
END AS eva_prueba,
CASE 
    WHEN EXISTS (
        SELECT 1
        FROM ceo_evaluaciones_programadas ep
        WHERE ep.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
          AND ep.id_servicio = cs.id_servicio
          AND ep.cuadrilla = cs.cuadrilla
          AND ep.tipo = 'TERRENO'
          AND ep.estado = 'PENDIENTE'
    ) THEN 1 ELSE 0
END AS eva_terreno

,COALESCE(hp_estado.estado, 'ACTIVO') AS estado_persona


FROM ceo_habilitacion_participantes p
INNER JOIN ceo_habilitacion cs ON cs.cuadrilla = p.id_cuadrilla
INNER JOIN ceo_empresas e      ON cs.empresa = e.id
INNER JOIN ceo_uo u            ON cs.uo = u.id
LEFT JOIN ceo_habilitacion_personas hp_estado
       ON hp_estado.id_habilitacion = cs.id
      AND hp_estado.rut COLLATE utf8mb4_unicode_ci = p.rut COLLATE utf8mb4_unicode_ci
";

    // Inyectar WHERE dinámico
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY p.apellidos, p.nombre";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);

    $serviciosIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int)($row['id_servicio'] ?? 0),
        $data
    )));
    $serviciosIds = array_values(array_filter($serviciosIds, static fn(int $id): bool => $id > 0));

    if (!empty($serviciosIds)) {
        $placeholders = implode(',', array_fill(0, count($serviciosIds), '?'));
        $stmtAgr = $pdo->prepare("
            SELECT id, id_servicio, titulo
            FROM ceo_agrupacion
            WHERE id_servicio IN ($placeholders)
            ORDER BY id_servicio ASC, id ASC
        ");
        $stmtAgr->execute($serviciosIds);

        foreach ($stmtAgr->fetchAll(PDO::FETCH_ASSOC) as $agrupacion) {
            $servicioId = (int)($agrupacion['id_servicio'] ?? 0);
            if ($servicioId <= 0) {
                continue;
            }
            if (!isset($agrupacionesPorServicio[$servicioId])) {
                $agrupacionesPorServicio[$servicioId] = [];
            }
            $agrupacionesPorServicio[$servicioId][] = $agrupacion;
        }
    }
}


$programaId = (int)($_GET['programa'] ?? 0);

$nsolicitudCuadrilla = null;
$servicioCuadrilla = null;
$evaluadorTerrenoCuadrilla = null;
$fechaPlanificacionTerrenoCuadrilla = null;
$fechaEjecucionTerrenoCuadrilla = null;

if ($programaId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            h.nsolicitud,
            sp.servicio,
            TRIM(CONCAT(COALESCE(ev.nombre, ''), ' ', COALESCE(ev.apellidop, ''), ' ', COALESCE(ev.apellidom, ''))) AS responsable_linea,
            (
                SELECT MAX(ep.fecha_programacion)
                FROM ceo_evaluaciones_programadas ep
                WHERE ep.cuadrilla = h.cuadrilla
                  AND ep.id_servicio = h.id_servicio
                  AND ep.tipo = 'TERRENO'
            ) AS fecha_planificacion_terreno,
            (
                SELECT MAX(ep.fecha_resultado)
                FROM ceo_evaluaciones_programadas ep
                WHERE ep.cuadrilla = h.cuadrilla
                  AND ep.id_servicio = h.id_servicio
                  AND ep.tipo = 'TERRENO'
            ) AS fecha_ejecucion_terreno
        FROM ceo_habilitacion h
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
        LEFT JOIN ceo_solicitudes s ON s.nsolicitud = h.nsolicitud
        LEFT JOIN ceo_evaluador ev ON ev.id = s.resplinea
        WHERE h.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $programaId]);
    $programaInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($programaInfo) {
        $nsolicitudCuadrilla = $programaInfo['nsolicitud'] ?? null;
        $servicioCuadrilla = $programaInfo['servicio'] ?? null;
        $evaluadorTerrenoCuadrilla = trim((string)($programaInfo['responsable_linea'] ?? ''));
        $fechaPlanificacionTerrenoCuadrilla = $programaInfo['fecha_planificacion_terreno'] ?? null;
        $fechaEjecucionTerrenoCuadrilla = $programaInfo['fecha_ejecucion_terreno'] ?? null;
    }
}

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Revisión de Cuadrillas - <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}

.scroll-box {
    max-height:500px;
    overflow:auto;
    border:1px solid #dee2e6;
    border-radius:6px;
    background:white;
}

.table thead {
    position:sticky;
    top:0;
    z-index:2;
    background:#eaf2fb;
}

.table th {
    background:#eaf2fb;
    text-align:center;
    white-space:nowrap;
}

.table td {
    vertical-align: middle;
}

.fila-anulada {
    opacity: .65;
}

.fila-anulada td {
    background: #f8f9fa;
}

td input[type=checkbox]{
    transform: scale(1.2);
}
</style>

</head>

<body>

<!-- ============================================================
     HEADER CEO (IGUAL A agenda.php)
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
    <a href="habilitaciones.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>


<!-- ============================================================
     CONTENIDO PRINCIPAL
============================================================ -->
<div class="container-fluid px-4">

    <!-- Card título -->
	    <div class="card rounded-4 shadow-sm mb-4">
	        <div class="card-body py-3">
	            <h4 class="fw-bold text-primary mb-0">
	                <i class="bi bi-search me-2"></i>Revisión de Cuadrillas
	            </h4>
	        </div>
	    </div>

        <?php if (!$hasEvaluacionProgramadaAgrupacion): ?>
        <div class="alert alert-warning shadow-sm rounded-4 mb-4">
            La tabla <code>ceo_evaluaciones_programadas</code> no tiene la columna <code>id_agrupacion</code>.
            La página ya puede abrir normalmente, pero si un servicio tiene más de una prueba no se podrá guardar cuál fue seleccionada hasta agregar esa columna o definir otro mecanismo de persistencia.
        </div>
        <?php endif; ?>

    
    <!-- ============================================================
         FORMULARIO DE BÚSQUEDA
    ============================================================ -->
    <div class="card shadow-sm rounded-4 mb-4">
        <div class="card-body">

            <form class="row g-3" method="GET">

                <div class="col-md-4">
                    <label class="form-label fw-bold">Empresa</label>
                    <select name="empresa" class="form-select" required >
                        <option value="">Seleccione...</option>
                        <?php foreach ($empresas as $e): ?>
                            <option value="<?= $e['id'] ?>" 
                                <?= ($_GET['empresa'] ?? '') == $e['id'] ? 'selected' : '' ?>>
                                <?= esc($e['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Unidad Operativa</label>
                    <select name="uo" class="form-select" required >
                        <option value="">Seleccione...</option>
                        <?php foreach ($uos as $u): ?>
                            <option value="<?= $u['id'] ?>"
                                <?= ($_GET['uo'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                                <?= esc($u['desc_uo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Programa (Cuadrilla)</label>
                    <select name="programa" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($programas as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= ($_GET['programa'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                Cuadrilla #<?= esc($p['cuadrilla']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Servicio</label>
                    <input
                        type="text"
                        class="form-control bg-light"
                        value="<?= esc((string)($servicioCuadrilla ?? '')) ?>"
                        placeholder="Se completará al seleccionar el programa"
                        readonly
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Evaluador terreno</label>
                    <input
                        type="text"
                        class="form-control bg-light"
                        value="<?= esc((string)($evaluadorTerrenoCuadrilla ?? '')) ?>"
                        placeholder="Se completará cuando exista permiso asociado"
                        readonly
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Fecha planificación terreno</label>
                    <input
                        type="text"
                        class="form-control bg-light"
                        value="<?= esc(formatearFechaRevision($fechaPlanificacionTerrenoCuadrilla ?? null, true)) ?>"
                        placeholder="No planificada"
                        readonly
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold">Fecha ejecución terreno</label>
                    <input
                        type="text"
                        class="form-control bg-light"
                        value="<?= esc(formatearFechaRevision($fechaEjecucionTerrenoCuadrilla ?? null, true)) ?>"
                        placeholder="Sin ejecución"
                        readonly
                    >
                </div>

                <div class="col-12 text-end mt-2">
                    <button class="btn btn-success"><i class="bi bi-search"></i> Recuperar</button>
                  <?php if (empty($nsolicitudCuadrilla)): ?>
                    <a href="generar_permiso.php?empresa=<?= $_GET['empresa'] ?? '' ?>
                        &uo=<?= $_GET['uo'] ?? '' ?>
                        &programa=<?= $_GET['programa'] ?? '' ?>"
                        class="btn btn-secondary">
                        <i class="bi bi-file-earmark-plus"></i> Generar Permiso
                    </a>
                <?php else: ?>
                    <span class="badge bg-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Permiso generado (Solicitud N° <?= (int)$nsolicitudCuadrilla ?>
                    </span>
                <?php endif; ?>


                </div>

            </form>
        </div>
    </div>


    <!-- ============================================================
         TABLA RESULTADOS
    ============================================================ -->
    <div class="card shadow-sm rounded-4">
        <div class="card-body">

            <?php if (!empty($data)): ?>

            <div class="scroll-box">
                <table class="table table-hover table-sm table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>RUT</th>
                            <th>Nombre</th>
                            <th>Apellido</th>
                            <th>UO</th>
                            <th>Cargo</th>
                            <th>Empresa</th>
                            <th>N° Proceso</th>
                            <th>N° Cuadrilla</th>
                            <th>Existe</th>
                            <th>Prueba</th>
                            <th>Terreno</th>
                            <th>Eva Prueba</th>
                            <th>Prueba a Aplicar</th>
                            <th>Eva Terreno</th>
                            <th>Acción</th>
                        </tr>
                    </thead>

<tbody>
<?php foreach ($data as $d): ?>
<?php
$estadoCuadrilla = trim((string)($d['estado_cuadrilla'] ?? 'Pendiente'));
$estadoPersona = strtoupper(trim((string)($d['estado_persona'] ?? 'ACTIVO')));
$cuadrillaAnulada = strcasecmp($estadoCuadrilla, 'Anulada') === 0;
$personaAnulada = $estadoPersona === 'ELIMINADO';
?>
<tr class="fila-detalle<?= ($cuadrillaAnulada || $personaAnulada) ? ' fila-anulada' : '' ?>" data-rut="<?= esc($d['rut']) ?>">

    <td><?= esc($d['rut']) ?></td>
    <td><?= esc($d['nombre']) ?></td>
    <td><?= esc($d['apellido']) ?></td>
    <td><?= esc($d['uo']) ?></td>
    <td><?= esc($d['cargo']) ?></td>
    <td><?= esc($d['empresa']) ?></td>
    <td class="text-center"><?= esc($d['numero_proceso'] !== null ? (string)$d['numero_proceso'] : '') ?></td>
    <td class="text-center">
        <?= esc((string)$d['n_cuadrilla']) ?>
        <?php if ($cuadrillaAnulada): ?>
            <div><span class="badge text-bg-secondary mt-1">Anulada</span></div>
        <?php endif; ?>
    </td>

<?php
$cols = ['existe','prueba','terreno','eva_prueba'];
$agrupacionesServicio = $agrupacionesPorServicio[(int)$d['id_servicio']] ?? [];
$idAgrupacionSeleccionada = (int)($d['id_agrupacion_programada'] ?? 0);

foreach ($cols as $c):
    $isEva = in_array($c, ['eva_prueba','eva_terreno']);
    $disabled = $isEva ? '' : 'disabled';
?>
<td class="text-center">
    <input 
        type="checkbox"
        <?= $disabled ?>
        <?= ($d[$c] == 1 ? 'checked' : '') ?>
        <?php if ($isEva): ?>
            class="chk-eva"
            data-tipo="<?= $c === 'eva_prueba' ? 'PRUEBA' : 'TERRENO' ?>"
            data-rut="<?= esc($d['rut']) ?>"
            data-servicio="<?= (int)$d['id_servicio'] ?>"
            data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
            <?= ($cuadrillaAnulada || $personaAnulada) ? 'disabled' : '' ?>
        <?php endif; ?>
    >
</td>
<?php endforeach; ?>

    <td style="min-width:260px;">
        <select
            class="form-select form-select-sm sel-agrupacion-prueba"
            data-rut="<?= esc($d['rut']) ?>"
            data-servicio="<?= (int)$d['id_servicio'] ?>"
            data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
            <?= ($cuadrillaAnulada || $personaAnulada) ? 'disabled' : '' ?>
        >
            <option value="">-- Seleccione prueba --</option>
            <?php foreach ($agrupacionesServicio as $agr): ?>
                <?php $labelAgr = (int)$agr['id'] . ' - ' . trim(strip_tags((string)($agr['titulo'] ?? ''))); ?>
                <option
                    value="<?= (int)$agr['id'] ?>"
                    <?= $idAgrupacionSeleccionada === (int)$agr['id'] ? 'selected' : '' ?>
                >
                    <?= esc($labelAgr) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </td>

<?php $c = 'eva_terreno'; $isEva = true; $disabled = ''; ?>
<td class="text-center">
    <input 
        type="checkbox"
        <?= $disabled ?>
        <?= ($d[$c] == 1 ? 'checked' : '') ?>
        class="chk-eva"
        data-tipo="TERRENO"
        data-rut="<?= esc($d['rut']) ?>"
        data-servicio="<?= (int)$d['id_servicio'] ?>"
        data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
        <?= ($cuadrillaAnulada || $personaAnulada) ? 'disabled' : '' ?>
    >
</td>


    <!-- ✅ COLUMNA ACCIÓN (UNA SOLA VEZ) -->
    <td class="text-center">
        <button
            type="button"
            class="btn btn-sm btn-outline-danger btn-eliminar"
            data-rut="<?= esc($d['rut']) ?>"
            data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
            title="Eliminar participante de la cuadrilla"
            onclick="event.stopPropagation();">
            <i class="bi bi-trash"></i>
        </button>
        <button
            type="button"
            class="btn btn-sm btn-outline-secondary btn-anular ms-1"
            data-rut="<?= esc($d['rut']) ?>"
            data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
            data-servicio="<?= (int)$d['id_servicio'] ?>"
            data-nombre="<?= esc(trim((string)$d['nombre'] . ' ' . (string)$d['apellido'])) ?>"
            title="Anular planificación por no asistencia"
            <?= ($cuadrillaAnulada || $personaAnulada) ? 'disabled' : '' ?>
            onclick="event.stopPropagation();">
            <i class="bi bi-person-x"></i>
        </button>
    </td>

</tr>

<?php endforeach; ?>
</tbody>


                </table>
            </div>

            <?php elseif ($_GET): ?>

                <div class="alert alert-warning text-center">
                    No se encontraron registros para los filtros seleccionados.
                </div>

            <?php endif; ?>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll(".fila-detalle").forEach(fila => {

    fila.style.cursor = "pointer";

    fila.addEventListener("dblclick", function (e) {

        // Evitar disparos desde botones o inputs
        if (e.target.closest("button, input, a")) return;

        const rut = this.dataset.rut;

        window.location.href =
            "revision_cuadrilla_detalle.php?rut=" + encodeURIComponent(rut) +
            "&empresa=<?= (int)($_GET['empresa'] ?? 0) ?>" +
            "&uo=<?= (int)($_GET['uo'] ?? 0) ?>" +
            "&programa=<?= (int)($_GET['programa'] ?? 0) ?>";
    });

});
</script>

<script>
document.querySelectorAll(".btn-eliminar").forEach(btn => {

    btn.addEventListener("click", function () {

        const rut = this.dataset.rut;
        const cuadrilla = this.dataset.cuadrilla;
        const fila = this.closest("tr");

        if (!confirm("¿Está seguro de eliminar este participante de la cuadrilla?")) {
            return;
        }

        fetch("eliminar_participante.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                rut: rut,
                id_cuadrilla: cuadrilla
            })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.ok) {
                fila.remove();
            } else {
                alert(resp.msg || "No fue posible eliminar.");
            }
        })
        .catch(() => alert("Error de comunicación con el servidor"));
    });

});
</script>
<script>
document.querySelectorAll(".btn-anular").forEach(btn => {

    btn.addEventListener("click", function () {

        const rut = this.dataset.rut;
        const cuadrilla = this.dataset.cuadrilla;
        const servicio = this.dataset.servicio;
        const nombre = this.dataset.nombre || rut;
        const fila = this.closest("tr");

        if (!confirm("¿Está seguro de anular la planificación por no asistencia de " + nombre + "?\n\nSolo se anularán evaluaciones pendientes.")) {
            return;
        }

        fetch("anular_participante_cuadrilla.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                rut: rut,
                id_cuadrilla: cuadrilla,
                id_servicio: servicio
            })
        })
        .then(r => r.json())
        .then(resp => {
            if (resp.ok) {
                alert(resp.msg || "Planificación anulada correctamente.");
                if (resp.cuadrilla_anulada) {
                    window.location.reload();
                    return;
                }

                fila.classList.add("fila-anulada");
                fila.querySelectorAll(".chk-eva, .sel-agrupacion-prueba, .btn-anular").forEach(el => {
                    el.disabled = true;
                });

                const tdCuadrilla = fila.children[7] || null;
                if (tdCuadrilla && resp.estado_cuadrilla === 'Anulada' && !tdCuadrilla.querySelector('.badge.text-bg-secondary')) {
                    tdCuadrilla.insertAdjacentHTML('beforeend', '<div><span class="badge text-bg-secondary mt-1">Anulada</span></div>');
                }
            } else {
                alert(resp.msg || "No fue posible anular la planificación.");
            }
        })
        .catch(() => alert("Error de comunicación con el servidor"));
    });

});
</script>
<script>
document.querySelectorAll(".chk-eva").forEach(chk => {

    chk.addEventListener("change", function () {
        const tr = this.closest("tr");
        const selAgrupacion = tr ? tr.querySelector(".sel-agrupacion-prueba") : null;
        const esPrueba = this.dataset.tipo === "PRUEBA";
        const esLlee = Number(this.dataset.servicio || 0) === 1;
        const idAgrupacion = selAgrupacion ? Number(selAgrupacion.value || 0) : 0;

        if (esPrueba && this.checked && !esLlee && idAgrupacion <= 0) {
            alert("Debe seleccionar la prueba a aplicar antes de programarla.");
            this.checked = false;
            if (selAgrupacion) {
                selAgrupacion.focus();
            }
            return;
        }

        const payload = {
            rut: this.dataset.rut,
            servicio: this.dataset.servicio,
            cuadrilla: this.dataset.cuadrilla,
            tipo: this.dataset.tipo,
            checked: this.checked ? 1 : 0,
            id_agrupacion: esPrueba ? idAgrupacion : 0
        };

        fetch("guardar_evaluacion_programada.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(resp => {
            if (!resp.ok) {
                alert(resp.msg || "Error al guardar evaluación");
                this.checked = !this.checked; // rollback visual
            }
        })
        .catch(() => {
            alert("Error de comunicación con el servidor");
            this.checked = !this.checked; // rollback visual
        });

    });

});

document.querySelectorAll(".sel-agrupacion-prueba").forEach(sel => {

    sel.addEventListener("change", function () {
        const tr = this.closest("tr");
        const chkPrueba = tr ? tr.querySelector('.chk-eva[data-tipo="PRUEBA"]') : null;
        const idAgrupacion = Number(this.value || 0);
        const esLlee = Number(this.dataset.servicio || 0) === 1;

        if (!chkPrueba || !chkPrueba.checked) {
            return;
        }

        if (esLlee) {
            return;
        }

        if (idAgrupacion <= 0) {
            alert("Debe seleccionar una prueba válida.");
            this.focus();
            return;
        }

        const payload = {
            rut: chkPrueba.dataset.rut,
            servicio: chkPrueba.dataset.servicio,
            cuadrilla: chkPrueba.dataset.cuadrilla,
            tipo: chkPrueba.dataset.tipo,
            checked: 1,
            id_agrupacion: idAgrupacion
        };

        fetch("guardar_evaluacion_programada.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(resp => {
            if (!resp.ok) {
                alert(resp.msg || "Error al actualizar la prueba programada");
            }
        })
        .catch(() => {
            alert("Error de comunicación con el servidor");
        });
    });

});
</script>

</body>
</html>
