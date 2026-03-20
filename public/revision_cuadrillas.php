<?php
// --------------------------------------------------------------
// revision_cuadrillas.php - Revisión de Cuadrillas (CEO)
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Si NO existe sesión válida → volver al login
if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}
require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

// Validación de sesión
if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$pdo = db();

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

$data = [];

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

    $sql = "
SELECT 
    p.rut,
    p.nombre,
    p.apellidos AS apellido,
    u.desc_uo AS uo,
    p.cargo,
    e.nombre AS empresa,
    cs.cuadrilla AS n_cuadrilla,
    cs.id_servicio,
    CASE 
        WHEN EXISTS (
            SELECT 1 
            FROM ceo_resultado_prueba_intento x 
            WHERE x.rut = p.rut
        ) THEN 1 ELSE 0 
    END AS existe,

    CASE 
        WHEN EXISTS (
            SELECT 1
            FROM ceo_resultado_prueba_intento x
            WHERE x.rut = p.rut
              AND x.id_servicio = cs.id_servicio
        ) THEN 1 ELSE 0 
    END AS prueba,

    CASE 
        WHEN EXISTS (
            SELECT 1
            FROM ceo_evaluacion_terreno t
            WHERE t.rut = p.rut
              AND t.id_servicio = cs.id_servicio
        ) THEN 1 ELSE 0 
    END AS terreno,
CASE 
    WHEN EXISTS (
        SELECT 1
        FROM ceo_evaluaciones_programadas ep
        WHERE ep.rut = p.rut
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
        WHERE ep.rut = p.rut
          AND ep.id_servicio = cs.id_servicio
          AND ep.cuadrilla = cs.cuadrilla
          AND ep.tipo = 'TERRENO'
          AND ep.estado = 'PENDIENTE'
    ) THEN 1 ELSE 0
END AS eva_terreno


FROM ceo_habilitacion_participantes p
INNER JOIN ceo_habilitacion cs ON cs.cuadrilla = p.id_cuadrilla
INNER JOIN ceo_empresas e      ON cs.empresa = e.id
INNER JOIN ceo_uo u            ON cs.uo = u.id
";

    // Inyectar WHERE dinámico
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY p.apellidos, p.nombre";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $data = $st->fetchAll(PDO::FETCH_ASSOC);
}


$programaId = (int)($_GET['programa'] ?? 0);

$nsolicitudCuadrilla = null;

if ($programaId > 0) {
    $stmt = $pdo->prepare("
        SELECT nsolicitud
        FROM ceo_habilitacion
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $programaId]);
    $nsolicitudCuadrilla = $stmt->fetchColumn(); // null o número
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
                            <th>Existe</th>
                            <th>Prueba</th>
                            <th>Terreno</th>
                            <th>Eva Prueba</th>
                            <th>Eva Terreno</th>
                            <th>Acción</th>
                        </tr>
                    </thead>

<tbody>
<?php foreach ($data as $d): ?>
<tr class="fila-detalle" data-rut="<?= esc($d['rut']) ?>">

    <td><?= esc($d['rut']) ?></td>
    <td><?= esc($d['nombre']) ?></td>
    <td><?= esc($d['apellido']) ?></td>
    <td><?= esc($d['uo']) ?></td>
    <td><?= esc($d['cargo']) ?></td>
    <td><?= esc($d['empresa']) ?></td>

<?php 
$cols = ['existe','prueba','terreno','eva_prueba','eva_terreno'];

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
        <?php endif; ?>
    >
</td>
<?php endforeach; ?>


    <!-- ✅ COLUMNA ACCIÓN (UNA SOLA VEZ) -->
    <td class="text-center">
        <button
            type="button"
            class="btn btn-sm btn-outline-danger btn-eliminar"
            data-rut="<?= esc($d['rut']) ?>"
            data-cuadrilla="<?= (int)$d['n_cuadrilla'] ?>"
            title="Eliminar participante"
            onclick="event.stopPropagation();">
            <i class="bi bi-trash"></i>
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
document.querySelectorAll(".chk-eva").forEach(chk => {

    chk.addEventListener("change", function () {

        const payload = {
            rut: this.dataset.rut,
            servicio: this.dataset.servicio,
            cuadrilla: this.dataset.cuadrilla,
            tipo: this.dataset.tipo,
            checked: this.checked ? 1 : 0
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
</script>

</body>
</html>
