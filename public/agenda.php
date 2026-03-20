<?php
// agenda.php – Agenda del CEO
// --------------------------------------------------------------
// Adaptada para usar el mismo HEADER, estructura y estilos
// que solicitudes.php (Bootstrap + topbar + cards).
// --------------------------------------------------------------
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$rolUsuario     = strtolower($_SESSION['auth']['rol'] ?? '');
$idEmpresaUser  = (int)($_SESSION['auth']['id_empresa'] ?? 0);

$esAdmin       = ($rolUsuario === 'administrador');
$esContratista = ($rolUsuario === 'contratista');

$pdo = db();

/* ========================================================
   1) PARTICIPANTES POR CUADRILLA
   ======================================================== */
$sqlPart = "
SELECT 
    p.id_cuadrilla n_cuadrilla,
    1 AS reevaluacion,
    p.rut,
    p.nombre,
    p.cargo,
    sp.servicio,
    ce.nombre AS empresa,
    cu.desc_uo uo,
    CONCAT(cu2.nombres, ' ', cu2.apellidos) nombre_gestor
FROM ceo_habilitacion_participantes p
INNER JOIN ceo_habilitacion cs ON p.id_cuadrilla = cs.cuadrilla
INNER JOIN ceo_servicios_pruebas sp ON cs.id_servicio = sp.id
INNER JOIN ceo_empresas ce ON cs.empresa = ce.id
INNER JOIN ceo_usuarios cu2 ON cs.gestor = cu2.id
LEFT JOIN ceo_uo cu ON cs.uo = cu.id
";

if ($esContratista) {
    $sqlPart .= " WHERE cs.empresa = :empresa ";
}

$sqlPart .= " ORDER BY p.id_cuadrilla DESC, p.apellidos ASC, p.nombre ASC";

$stmt = $pdo->prepare($sqlPart);

if ($esContratista) {
    $stmt->bindValue(':empresa', $idEmpresaUser, PDO::PARAM_INT);
}

$stmt->execute();
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* ========================================================
   2.1) UNIDADES OPERATIVAS (UO)
   ======================================================== */
$sqlUO = "SELECT id, desc_uo FROM ceo_uo ORDER BY desc_uo";
$uos = $pdo->query($sqlUO)->fetchAll(PDO::FETCH_ASSOC);

/* ========================================================
   2) SERVICIOS
   ======================================================== */
$sqlServ = "SELECT id, servicio, descripcion FROM ceo_servicios_pruebas ORDER BY servicio";
$servicios = $pdo->query($sqlServ)->fetchAll(PDO::FETCH_ASSOC);

/* ========================================================
   3) FECHAS BLOQUEADAS
   ======================================================== */
$sqlBlock = "SELECT fecha_inicio, fecha_fin, motivo FROM ceo_bloqueo_horas WHERE estado = 'A'";
$bloqueos = $pdo->query($sqlBlock)->fetchAll(PDO::FETCH_ASSOC);

$mapBlocked = [];
foreach ($bloqueos as $b) {
    $period = new DatePeriod(
        new DateTime($b['fecha_inicio']),
        new DateInterval('P1D'),
        (new DateTime($b['fecha_fin']))->modify('+0 day')
    );
    foreach ($period as $d) {
        $mapBlocked[$d->format("Y-m-d")] = $b['motivo'];
    }
}

/* ========================================================
   5) CARGAR CUADRILLAS YA PLANIFICADAS
   ======================================================== */
/* ========================================================
   5) CARGAR CUADRILLAS YA PLANIFICADAS (VARIAS POR CELDA)
   ======================================================== */
$sqlCuad = "
SELECT 
    h.fecha,
    h.jornada,
    h.id_servicio,
    h.cuadrilla,
    h.empresa,
    h.estado,
    ce.nombre AS nombre_empresa
FROM ceo_habilitacion h
LEFT JOIN ceo_empresas ce ON h.empresa = ce.id
";

if ($esContratista) {
    $sqlCuad .= " WHERE h.empresa = :empresa ";
}

$stmtCuad = $pdo->prepare($sqlCuad);

if ($esContratista) {
    $stmtCuad->bindValue(':empresa', $idEmpresaUser, PDO::PARAM_INT);
}

$stmtCuad->execute();
$cuadrillas = $stmtCuad->fetchAll(PDO::FETCH_ASSOC);


$mapCuadrillas = [];

/*
  $mapCuadrillas['2025-02-10_Mañana_3'] = [
      [ 'cuadrilla' => 117, 'empresa' => 'ELECSUR' ],
      [ 'cuadrilla' => 222, 'empresa' => 'EMEL' ],
  ];
*/
foreach ($cuadrillas as $c) {
    $key = $c['fecha'] . '_' . $c['jornada'] . '_' . $c['id_servicio'];
    if (!isset($mapCuadrillas[$key])) {
        $mapCuadrillas[$key] = [];
    }
    $mapCuadrillas[$key][] = [
        'cuadrilla' => $c['cuadrilla'],
        'empresa'   => $c['nombre_empresa'] ?: '—',
        'estado'    => $c['estado'] ?? 'Pendiente'
    ];
}


/* ========================================================
   4) GENERAR CALENDARIO (15 días)
   ======================================================== */
$fechas = [];
for ($i=0; $i<15; $i++){
    $fechas[] = date("Y-m-d", strtotime("+$i day"));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Agenda - <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600;}

.tab-pane {border:1px solid #dee2e6; border-top:0; padding:15px;}
.table thead {position:sticky; top:0; z-index:2;}
.table th {background:#eaf2fb;}

.blocked {background:#ffcccc !important;}
.ok {background:#e6ffe6;}

.scroll-box {
    max-height:600px;
    overflow:auto;
    border:1px solid #dee2e6;
    border-radius:6px;
    background:white;
}
.cuadrilla-asignada input {
    background:#d4edda !important; 
    font-weight: bold;
    color:#155724;
    border-color:#8cc38c;
}

/* ... tus estilos actuales ... */

#plan table {
    font-size: 0.78rem;     /* Tamaño de fuente más pequeño */
}

#plan th {
    font-size: 0.75rem;
    font-weight: 600;
}

#plan td {
    font-size: 0.78rem;
}

#plan .btn {
    font-size: 0.70rem;
    padding: 2px 4px;
}

/* =========================
   Overlay carga Excel Agenda
   ========================= */
.modal-loading-wrap {
    position: relative;
}

.modal-loading {
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0.86);
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
}

.modal-loading.d-none {
    display: none !important;
}

.modal-loading-box {
    background: #fff;
    padding: 20px 28px;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(0,0,0,.15);
    text-align: center;
    min-width: 260px;
}

.modal-loading-box .msg {
    font-weight: 600;
    color: #0d6efd;
}

.modal-loading-box .submsg {
    font-size: .88rem;
    color: #6c757d;
}
</style>

<script>
function filtrarParticipantes() {
    let q = document.getElementById("filtro").value.toLowerCase();
    let rows = document.querySelectorAll("#tablaParticipantes tbody tr");

    rows.forEach(r => {
        r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
</head>

<body>

<!-- ============================================================
     HEADER (COPIADO DE solicitudes.php)
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
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<!-- ============================================================
     CONTENIDO
============================================================ -->
<div class="container-fluid px-4">

    <div class="card rounded-4 shadow-sm mb-4">
        <div class="card-body py-3">
            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-calendar-week me-2"></i>Agenda de Planificación</h4>
        </div>
    </div>

    <!-- ============================================================
         NAV TABS
    ============================================================ -->
    <ul class="nav nav-tabs" id="myTab" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="part-tab" data-bs-toggle="tab" data-bs-target="#part" type="button" role="tab">
          <i class="bi bi-people-fill me-1"></i> Participantes por Cuadrilla
        </button>
      </li>

      <li class="nav-item" role="presentation">
        <button class="nav-link" id="plan-tab" data-bs-toggle="tab" data-bs-target="#plan" type="button" role="tab">
          <i class="bi bi-table me-1"></i> Planificación (Clik sobre Celda, para ingresar/editar datos)
        </button>
      </li>
    </ul>

    <div class="tab-content" id="myTabContent">

      <!-- ============================================================
           TAB 1: PARTICIPANTES
      ============================================================ -->
      <div class="tab-pane fade show active" id="part" role="tabpanel">

        <div class="mb-2 mt-2">
            <input type="text" id="filtro" onkeyup="filtrarParticipantes()" 
                   class="form-control form-control-sm" 
                   style="max-width:300px;" 
                   placeholder="Filtrar participantes...">
        </div>

        <div class="scroll-box">
          <table class="table table-hover table-sm" id="tablaParticipantes">
            <thead>
              <tr class="text-center">
                <th>N° Cuadrilla</th>
                <th>Reeval.</th>
                <th>RUT</th>
                <th>Nombre</th>
                <th>Cargo</th>
                <th>Servicio</th>
                <th>Empresa</th>
                <th>UO</th>
                <th>Gestor</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($participantes as $p): ?>
              <tr>
                <td><?= $p['n_cuadrilla'] ?></td>
                <td><?= $p['reevaluacion'] ?></td>
                <td><?= $p['rut'] ?></td>
                <td><?= $p['nombre'] ?></td>
                <td><?= $p['cargo'] ?></td>
                <td><?= $p['servicio'] ?></td>
                <td><?= $p['empresa'] ?></td>
                <td><?= $p['uo'] ?></td>
                <td><?= $p['nombre_gestor'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>

      <!-- ============================================================
           TAB 2: PLANIFICACIÓN
      ============================================================ -->
      <div class="tab-pane fade" id="plan" role="tabpanel">

        <div class="scroll-box">

 <table class="table table-sm table-bordered align-middle">
    <thead class="text-center">
      <tr>
        <th>Fecha</th>
        <th>Jornada</th>
        <?php foreach($servicios as $s): ?>
            <th><?= esc($s['servicio']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>

    <tbody>
    <?php foreach($fechas as $f): 
        $isBlocked = isset($mapBlocked[$f]);
        $motivo = $isBlocked ? $mapBlocked[$f] : "";
    ?>

      <!-- PRIMERA FILA: FECHA + MAÑANA -->
      <tr title="<?= esc($motivo) ?>">

        <!-- FECHA OCUPA 2 FILAS -->
        <td class="<?= $isBlocked ? 'blocked':'ok' ?>" rowspan="2">
            <?= $f ?>
        </td>

        <!-- Jornada mañana -->
        <td class="<?= $isBlocked ? 'blocked':'ok' ?>">Mañana</td>

 <!-- Celdas por servicio (Mañana) -->
<?php foreach($servicios as $s): ?>
    <?php
        $key  = $f . '_Mañana_' . $s['id'];
        $arr  = $mapCuadrillas[$key] ?? [];
        $cant = count($arr);
    ?>
    <td class="text-center <?= $isBlocked ? 'blocked':'' ?>">
        <?php if(!$isBlocked): ?>
            <button 
                type="button"
                class="btn btn-sm btn-outline-primary w-100 celda-planificacion"
                data-fecha="<?= $f ?>"
                data-jornada="Mañana"
                data-servicio="<?= $s['id'] ?>"
            >
                <div class="fw-semibold">
                    <?= $cant ?> cuadrilla<?= $cant === 1 ? '' : 's' ?>
                </div>
                <?php if ($cant > 0): ?>
                    <div class="small mt-1 text-wrap">
                        <?php foreach ($arr as $c): ?>
                                 <?php
                                  $bg = ($c['estado'] === 'Cerrado') ? 'bg-danger' : 'bg-success';
                                ?>
                                <span class="badge <?= $bg ?> me-1 mb-1">
                                    C<?= (int)$c['cuadrilla'] ?> (<?= esc($c['empresa']) ?>)
                                </span>


                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <small class="text-muted">+</small>
                <?php endif; ?>
            </button>
        <?php endif; ?>
    </td>
<?php endforeach; ?>


      </tr>

      <!-- SEGUNDA FILA: TARDE (sin celda Fecha) -->
      <tr title="<?= esc($motivo) ?>">

        <td class="<?= $isBlocked ? 'blocked':'ok' ?>">Tarde</td>
<?php foreach($servicios as $s): ?>
    <?php
        $key  = $f . '_Tarde_' . $s['id'];
        $arr  = $mapCuadrillas[$key] ?? [];
        $cant = count($arr);
    ?>
    <td class="text-center <?= $isBlocked ? 'blocked':'' ?>">
        <?php if(!$isBlocked): ?>
            <button 
                type="button"
                class="btn btn-sm btn-outline-primary w-100 celda-planificacion"
                data-fecha="<?= $f ?>"
                data-jornada="Tarde"
                data-servicio="<?= $s['id'] ?>"
            >
                <div class="fw-semibold">
                    <?= $cant ?> cuadrilla<?= $cant === 1 ? '' : 's' ?>
                </div>
                <?php if ($cant > 0): ?>
                    <div class="small mt-1 text-wrap">
                        <?php foreach ($arr as $c): ?>
                            <span class="badge bg-success me-1 mb-1">
                                C<?= (int)$c['cuadrilla'] ?> (<?= esc($c['empresa']) ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <small class="text-muted">+</small>
                <?php endif; ?>
            </button>
        <?php endif; ?>
    </td>
<?php endforeach; ?>


      </tr>

    <?php endforeach; ?>
    </tbody>
</table>


        </div>

      </div>

    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================================
     MODAL: GESTIÓN DE PARTICIPANTES POR CELDA
============================================================ -->
<div class="modal fade" id="modalParticipantes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">

      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="bi bi-person-add me-2"></i>Agregar Participantes
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body modal-loading-wrap">

        <div id="excelLoadingAgenda" class="modal-loading d-none">
          <div class="modal-loading-box">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div id="excelLoadingAgendaMsg" class="msg">Procesando archivo Excel…</div>
            <div class="submsg">Por favor espera</div>
          </div>
        </div>

        <!-- CONTENEDOR DE CELDA SELECCIONADA -->
        <input type="hidden" id="celdaSeleccionada">

<!-- UO OBLIGATORIA -->
<div class="mb-3">
  <label class="form-label fw-semibold">
    Unidad Operativa <span class="text-danger">*</span>
  </label>
  <select id="uoSeleccionada" class="form-select form-select-sm">
    <option value="">-- Seleccione Unidad Operativa --</option>
    <?php foreach ($uos as $uo): ?>
      <option value="<?= (int)$uo['id'] ?>">
        <?= esc($uo['desc_uo']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <div class="invalid-feedback">
    Debe seleccionar una Unidad Operativa.
  </div>
</div>

        <div class="row">
          <div class="col-md-6">

            <h6 class="fw-bold">Ingreso Manual</h6>

            <div class="mb-2">
              <label class="form-label">RUT</label>
              <input type="text" id="rutManual" class="form-control form-control-sm">
            </div>

            <div class="mb-2">
              <label class="form-label">Nombre</label>
              <input type="text" id="nombreManual" class="form-control form-control-sm">
            </div>

            <div class="mb-2">
              <label class="form-label">Apellido Paterno</label>
              <input type="text" id="apellPatManual" class="form-control form-control-sm">
            </div>

            <div class="mb-2">
              <label class="form-label">Apellido Materno</label>
              <input type="text" id="apellMatManual" class="form-control form-control-sm">
            </div>

            <div class="mb-2">
              <label class="form-label">Cargo</label>
              <input type="text" id="cargoManual" class="form-control form-control-sm">
            </div>

            <button class="btn btn-primary btn-sm" id="btnAgregarManual">
              <i class="bi bi-plus-circle"></i> Agregar
            </button>

          </div>

          <div class="col-md-6 border-start">
            <h6 class="fw-bold">Carga desde Excel</h6>

            <input type="file" id="fileExcel" class="form-control form-control-sm mb-2">

            <div class="alert alert-info p-2 small">
              Formato esperado (Hoja Solicitud):<br>
              RUT | Nombre | Apellido Paterno | Apellido Materno | Cargo
            </div>

            <button class="btn btn-success btn-sm" id="btnCargarExcel">
              <i class="bi bi-file-earmark-spreadsheet"></i> Procesar Excel
            </button>
          </div>
        </div>

        <hr>

        <h6 class="fw-bold">Participantes Cargados</h6>
        <div class="table-responsive" style="max-height:200px; overflow:auto;">
          <table class="table table-sm table-bordered" id="tablaTemp">
            <thead class="table-light">
              <tr>
                <th>RUT</th>
                <th>Nombre</th>
                <th>Apellido Paterno</th>
                <th>Apellido Materno</th>
                <th>Cargo</th>
                <th>Eliminar</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="btnGuardarModal">Guardar Participantes</button>
      </div>

    </div>
  </div>
</div>

<!-- =========================================================
     MODAL: CUADRILLAS POR CELDA
============================================================ -->
<div class="modal fade" id="modalCuadrillasCelda" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">

      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">
          <i class="bi bi-diagram-3 me-2"></i>Cuadrillas en la celda seleccionada
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <!-- Guardamos contexto de la celda -->
        <input type="hidden" id="celdaContexto">

        <div class="mb-3">
          <span class="badge bg-light text-dark" id="resumenCelda"></span>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-bold mb-0">Cuadrillas planificadas</h6>
          <button class="btn btn-sm btn-primary" id="btnNuevaCuadrilla">
            <i class="bi bi-plus-circle me-1"></i> Nueva cuadrilla en esta celda
          </button>
        </div>

        <div class="table-responsive" style="max-height:260px; overflow:auto;">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:80px;">N°</th>
                <th>Empresa</th>
                <th style="width:120px;">Participantes</th>
                <th style="width:160px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="listaCuadrillasCelda">
              <!-- JS -->
            </tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>

<script>

// =========================================================
// OVERLAY DE CARGA EXCEL EN AGENDA
// =========================================================
const excelLoadingAgenda = document.getElementById("excelLoadingAgenda");
const excelLoadingAgendaMsg = document.getElementById("excelLoadingAgendaMsg");
const btnCargarExcel = document.getElementById("btnCargarExcel");
const fileExcel = document.getElementById("fileExcel");

function mostrarCargaExcelAgenda(msg = "Procesando archivo Excel…") {
    if (excelLoadingAgendaMsg) {
        excelLoadingAgendaMsg.textContent = msg;
    }
    excelLoadingAgenda?.classList.remove("d-none");

    if (btnCargarExcel) {
        btnCargarExcel.disabled = true;
        btnCargarExcel.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Procesando...';
    }

    if (fileExcel) fileExcel.disabled = true;
}

function ocultarCargaExcelAgenda() {
    excelLoadingAgenda?.classList.add("d-none");

    if (btnCargarExcel) {
        btnCargarExcel.disabled = false;
        btnCargarExcel.innerHTML = '<i class="bi bi-file-earmark-spreadsheet"></i> Procesar Excel';
    }

    if (fileExcel) fileExcel.disabled = false;
}

// =========================================================
// VALIDAR RUT (módulo 11)
// =========================================================
function validarRut(rut) {
    rut = rut.replace(/[.\-]/g, '').toUpperCase();
    if (rut.length < 2) return false;

    let cuerpo = rut.slice(0, -1);
    let dv = rut.slice(-1);

    let suma = 0, multiplo = 2;

    for (let i = cuerpo.length - 1; i >= 0; i--) {
        suma += multiplo * parseInt(cuerpo[i]);
        multiplo = (multiplo < 7) ? multiplo + 1 : 2;
    }

    let dvEsperado = 11 - (suma % 11);
    dvEsperado = dvEsperado === 11 ? '0' :
                 dvEsperado === 10 ? 'K' : dvEsperado.toString();

    return dv === dvEsperado;
}

// =========================================================
// ABRIR MODAL AL CLIC EN CELDAS
// =========================================================
// =========================================================
// ABRIR MODAL AL CLIC EN CELDAS (MODO NUEVO / EDITAR)
// =========================================================
/*
document.querySelectorAll("#plan input[type='text']").forEach(inp => {

    inp.addEventListener('click', function() {

        // Guardar datos de celda
        document.getElementById("celdaSeleccionada").value = JSON.stringify({
            id: this.id,
            fecha: this.dataset.fecha,
            jornada: this.dataset.jornada,
            servicio: this.dataset.servicio,
            cuadrilla: this.dataset.cuadrilla ?? 0
        });

        // Limpiar tabla temporal
        document.querySelector("#tablaTemp tbody").innerHTML = "";

        let cuadrilla = this.dataset.cuadrilla ?? 0;

        if (cuadrilla > 0) {
            // ===== MODO EDITAR =====
            fetch("../public/get_participantes_cuadrilla.php?cuadrilla=" + cuadrilla)
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    let tbody = document.querySelector("#tablaTemp tbody");

                    data.participantes.forEach(p => {
                        let fila = `
                            <tr>
                                <td>${p.rut}</td>
                                <td>${p.nombre}</td>
                                <td>${p.apellidos.split(" ")[0]}</td>
                                <td>${p.apellidos.split(" ")[1] ?? ""}</td>
                                <td>${p.cargo}</td>
                                <td><button class="btn btn-danger btn-sm btnEliminar">X</button></td>
                            </tr>`;
                        tbody.insertAdjacentHTML("beforeend", fila);
                    });
                }
            });
        }

        // Mostrar modal
        let modal = new bootstrap.Modal(document.getElementById('modalParticipantes'));
        modal.show();
    });
});
*/
// =========================================================
// ABRIR MODAL DE CUADRILLAS POR CELDA
// =========================================================
// =========================================================
// NUEVA CUADRILLA EN LA CELDA
// =========================================================
document.getElementById('btnNuevaCuadrilla').addEventListener('click', function () {
    const ctx = JSON.parse(document.getElementById('celdaContexto').value);

    document.getElementById("celdaSeleccionada").value = JSON.stringify({
        fecha: ctx.fecha,
        jornada: ctx.jornada,
        servicio: ctx.servicio,
        cuadrilla: 0 // NUEVA CUADRILLA
    });

    // Limpiar tabla temporal
    document.querySelector("#tablaTemp tbody").innerHTML = "";
    // Limpiar UO
    const uo = document.getElementById("uoSeleccionada");
    uo.value = "";
    uo.classList.remove("is-invalid");

    // Cerrar modal de cuadrillas → abrir modal participantes
    bootstrap.Modal.getInstance(document.getElementById('modalCuadrillasCelda')).hide();
    new bootstrap.Modal(document.getElementById('modalParticipantes')).show();
});

// =========================================================
// EDITAR CUADRILLA EXISTENTE
// =========================================================
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btnEditarCuadrilla');
    if (!btn) return;

    const cuadrilla = btn.dataset.cuadrilla;
    const ctx = JSON.parse(document.getElementById('celdaContexto').value);

    document.getElementById("celdaSeleccionada").value = JSON.stringify({
        fecha: ctx.fecha,
        jornada: ctx.jornada,
        servicio: ctx.servicio,
        cuadrilla: cuadrilla
    });

    const tbody = document.querySelector("#tablaTemp tbody");
    tbody.innerHTML = "";
    // Limpiar UO (se puede precargar luego si quieres)
    const uo = document.getElementById("uoSeleccionada");
    uo.value = "";
    uo.classList.remove("is-invalid");

fetch("../public/get_participantes_cuadrilla.php?cuadrilla=" + cuadrilla)
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;

        // ✅ PRECARGAR UO
        const uoSel = document.getElementById("uoSeleccionada");
        if (data.uo) {
            uoSel.value = data.uo;
            uoSel.classList.remove("is-invalid");
        }

        const tbody = document.querySelector("#tablaTemp tbody");
        data.participantes.forEach(p => {
            let ap = (p.apellidos || '').split(" ");

            let fila = `
                <tr>
                    <td>${p.rut}</td>
                    <td>${p.nombre}</td>
                    <td>${ap[0] ?? ''}</td>
                    <td>${ap[1] ?? ''}</td>
                    <td>${p.cargo}</td>
                    <td>
                        <button class="btn btn-danger btn-sm btnEliminar">X</button>
                    </td>
                </tr>`;
            tbody.insertAdjacentHTML("beforeend", fila);
        });
    });


    bootstrap.Modal.getInstance(document.getElementById('modalCuadrillasCelda')).hide();
    new bootstrap.Modal(document.getElementById('modalParticipantes')).show();
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.celda-planificacion');
    if (!btn) return;

    const fecha    = btn.dataset.fecha;
    const jornada  = btn.dataset.jornada;
    const servicio = btn.dataset.servicio;

    // Guardar contexto
    document.getElementById('celdaContexto').value = JSON.stringify({
        fecha,
        jornada,
        servicio
    });

    const resumen = `${fecha} · ${jornada} · Serv. ${servicio}`;
    document.getElementById('resumenCelda').textContent = resumen;

    // Limpiar tabla
    const tbody = document.getElementById('listaCuadrillasCelda');
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Cargando...</td></tr>';

    // Llamar a backend
    fetch(`../public/get_cuadrillas_celda.php?fecha=${encodeURIComponent(fecha)}&jornada=${encodeURIComponent(jornada)}&servicio=${encodeURIComponent(servicio)}`)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = '';

            if (!data.ok) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-danger text-center">${data.msg}</td></tr>`;
                return;
            }

            if (!data.cuadrillas || data.cuadrillas.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Sin cuadrillas aún.</td></tr>';
                return;
            }

            data.cuadrillas.forEach(c => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><span class="badge bg-success">C${c.cuadrilla}</span></td>
                    <td>${c.nombre_empresa || '—'}</td>
                    <td>${c.total_participantes || 0}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary me-1 btnEditarCuadrilla"
                                data-cuadrilla="${c.cuadrilla}">
                            Ver / Editar
                        </button>
                        <!-- Aquí podrías agregar botón eliminar a futuro -->
                    </td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(err => {
            console.error(err);
            tbody.innerHTML = '<tr><td colspan="4" class="text-danger text-center">Error al cargar cuadrillas.</td></tr>';
        });

    const modal = new bootstrap.Modal(document.getElementById('modalCuadrillasCelda'));
    modal.show();
});


// =========================================================
// AGREGAR MANUAL
// =========================================================
document.getElementById("btnAgregarManual").addEventListener("click", () => {

    let rut = rutManual.value.trim();
    if (!validarRut(rut)) {
        alert("RUT inválido.");
        return;
    }

    let fila = `
      <tr>
        <td>${rut}</td>
        <td>${nombreManual.value}</td>
        <td>${apellPatManual.value}</td>
        <td>${apellMatManual.value}</td>
        <td>${cargoManual.value}</td>
        <td><button class="btn btn-danger btn-sm btnEliminar">X</button></td>
      </tr>`;

    document.querySelector("#tablaTemp tbody").insertAdjacentHTML("beforeend", fila);
});

// =========================================================
// ELIMINAR FILA
// =========================================================
document.addEventListener("click", function(e) {
    if (e.target.classList.contains("btnEliminar")) {
        e.target.closest("tr").remove();
    }
});

// =========================================================
// GUARDAR (marca número en celda)
// =========================================================
// =========================================================
// GUARDAR PARTICIPANTES DE CUADRILLA
// =========================================================
document.getElementById("btnGuardarModal").addEventListener("click", () => {

    const btnGuardarModal = document.getElementById("btnGuardarModal");

    // 🔹 Validar UO
    const uo = document.getElementById("uoSeleccionada");
    if (!uo.value) {
        uo.classList.add("is-invalid");
        uo.focus();
        return;
    }
    uo.classList.remove("is-invalid");

    // 🔹 Contexto de celda
    let info = JSON.parse(document.getElementById("celdaSeleccionada").value);

    // 🔹 Participantes
    let filas = document.querySelectorAll("#tablaTemp tbody tr");
    if (filas.length === 0) {
        alert("Debe agregar al menos un participante.");
        return;
    }

    let participantes = [];
    filas.forEach(tr => {
        let tds = tr.querySelectorAll("td");
        participantes.push({
            rut: tds[0].innerText.trim(),
            nombre: tds[1].innerText.trim(),
            app: tds[2].innerText.trim(),
            apm: tds[3].innerText.trim(),
            cargo: tds[4].innerText.trim()
        });
    });

    // 🔹 FormData
    let form = new FormData();
    form.append("fecha", info.fecha);
    form.append("jornada", info.jornada);
    form.append("servicio", info.servicio);
    form.append("cuadrilla", info.cuadrilla ?? 0);
    form.append("empresa", 0);
    form.append("uo", uo.value);
    form.append("participantes", JSON.stringify(participantes));

    // 🔹 Mostrar estado visual
    mostrarCargaExcelAgenda("Guardando planificación…");
    btnGuardarModal.disabled = true;

    // 🔹 Enviar
    fetch("../public/grabar_habilitacion.php", {
        method: "POST",
        body: form
    })
    .then(async r => {
        let data;
        try {
            data = await r.json();
        } catch (e) {
            throw new Error("La respuesta del servidor no tiene formato JSON válido.");
        }
        return data;
    })
    .then(data => {

        if (!data.ok) {
            alert("Error: " + data.msg);
            return;
        }

        document.querySelector("#tablaTemp tbody").innerHTML = "";
        bootstrap.Modal.getInstance(
            document.getElementById("modalParticipantes")
        ).hide();

        alert("Guardado correctamente. Cuadrilla " + data.cuadrilla);
        location.reload();
    })
    .catch(err => {
        console.error(err);
        alert("Error al guardar la información.");
    })
    .finally(() => {
        ocultarCargaExcelAgenda();
        btnGuardarModal.disabled = false;
    });
});



// =========================================================
// PROCESAR ARCHIVO EXCEL (llama a procesa_excel.php)
// =========================================================
document.getElementById("btnCargarExcel").addEventListener("click", () => {

    const file = document.getElementById("fileExcel").files[0];
    if (!file) {
        alert("Debe seleccionar un archivo Excel.");
        return;
    }

    const celdaRaw = document.getElementById("celdaSeleccionada").value;
    if (!celdaRaw) {
        alert("No se encontró el contexto de la celda seleccionada.");
        return;
    }

    let info;
    try {
        info = JSON.parse(celdaRaw);
    } catch (e) {
        console.error(e);
        alert("No fue posible leer los datos de la celda seleccionada.");
        return;
    }

    if (!info.servicio || parseInt(info.servicio, 10) <= 0) {
        alert("No se encontró el servicio asociado a la celda seleccionada.");
        return;
    }

    const formData = new FormData();
    formData.append("excel", file);
    formData.append("id_servicio", info.servicio);

    mostrarCargaExcelAgenda("Leyendo y validando archivo Excel…");

    fetch("../public/procesa_excel.php", {
        method: "POST",
        body: formData
    })
    .then(async r => {
        let data;
        try {
            data = await r.json();
        } catch (e) {
            throw new Error("La respuesta del servidor no tiene formato JSON válido.");
        }
        return data;
    })
    .then(data => {
        if (!data.ok) {
            let mensaje = "Error: " + (data.msg || "No fue posible procesar el Excel.");

            if (Array.isArray(data.errores) && data.errores.length > 0) {
                mensaje += "\n\n" + data.errores.join("\n");
            }

            alert(mensaje);
            return;
        }

        const tbody = document.querySelector("#tablaTemp tbody");
        tbody.innerHTML = "";

        data.participantes.forEach(p => {
            const fila = `
                <tr>
                    <td>${p.rut}</td>
                    <td>${p.nombre}</td>
                    <td>${p.app}</td>
                    <td>${p.apm}</td>
                    <td>${p.cargo}</td>
                    <td><button class="btn btn-danger btn-sm btnEliminar">X</button></td>
                </tr>`;
            tbody.insertAdjacentHTML("beforeend", fila);
        });

        alert("Excel procesado correctamente.");
    })
    .catch(err => {
        console.error(err);
        alert("Error procesando el archivo.");
    })
    .finally(() => {
        ocultarCargaExcelAgenda();
    });

});

// Activar tooltips de Bootstrap
document.addEventListener("DOMContentLoaded", function () {
    let tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

</script>

</body>
</html>