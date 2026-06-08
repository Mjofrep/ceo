<?php
// --------------------------------------------------------------
// solicitud_detalle.php - Centro de Excelencia Operacional (CEO)
// Muestra detalle de solicitud y permite autorizar/asistencia.
// --------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
  header('Location: /ceo/public/index.php');
  exit;
}

if ((int)($_SESSION['auth']['id_rol'] ?? 0) === 6) {
  echo "<div class='alert alert-danger m-5'>No autorizado para consultar solicitudes.</div>";
  exit;
}

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo "<div class='alert alert-danger m-5'>❌ Solicitud no especificada.</div>";
  exit;
}

// --------------------------------------------------------------
// Determinar si usuario es administrador
// --------------------------------------------------------------
$isAdmin = isset($_SESSION['auth']['rol']) && strtolower($_SESSION['auth']['rol']) === 'administrador';
$isregasist = isset($_SESSION['auth']['rol']) && strtolower($_SESSION['auth']['rol']) === 'registro asistencia';
$isasistio = $isregasist;
$puedeAprobar = $isAdmin || $isregasist;
$puedeObservar    = $isAdmin || $isregasist;
$puedeCerrar      = $isAdmin || $isregasist;
$puedeAutorizar   = $isAdmin;
$puedeAsistir     = $isregasist;
/* ===============================================================
   CABECERA DE SOLICITUD
   =============================================================== */
$stmt = $pdo->prepare("
  SELECT s.*, 
         e.nombre AS empresa_nombre,
         p.desc_proceso AS proceso_nombre,
         pa.desc_patios AS patio_nombre,
         u.desc_uo AS uo_nombre,
          sv.servicio AS servicio_nombre,
          r.responsable AS resp_uo_nombre,
          TRIM(CONCAT(COALESCE(ev.nombre, ''), ' ', COALESCE(ev.apellidop, ''), ' ', COALESCE(ev.apellidom, ''))) AS resp_linea_nombre,
          h.desc_tipo AS habceo_nombre,
         ch.desc_charlas AS charla_nombre,
         s.estado
    FROM ceo_solicitudes s
    LEFT JOIN ceo_empresas e ON e.id = s.contratista
    LEFT JOIN ceo_procesos p ON p.id = s.proceso
    LEFT JOIN ceo_patios pa ON pa.id = s.patio
    LEFT JOIN ceo_uo u ON u.id = s.uo
    LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
    LEFT JOIN ceo_responsables r ON r.id = s.responsable
    LEFT JOIN ceo_evaluador ev ON ev.id = s.resplinea
    LEFT JOIN ceo_habilitaciontipo h ON h.id = s.habilitacionceo
    LEFT JOIN ceo_charlas ch ON ch.id = s.charla
   WHERE s.nsolicitud = :nsol
   LIMIT 1
");
$stmt->execute([':nsol' => $id]);
$sol = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sol) {
  echo "<div class='alert alert-warning m-5'>⚠️ No se encontró la solicitud N° {$id}.</div>";
  exit;
}
$mostrarTooltipHab = false;
$textoTooltipHab   = '';

$habilitacionNombre = trim((string)($sol['habceo_nombre'] ?? ''));
$charlaNombre       = trim((string)($sol['charla_nombre'] ?? ''));

if ($habilitacionNombre !== '' && $charlaNombre !== '') {

    $habNormalizada = mb_strtolower($habilitacionNombre, 'UTF-8');

    if (str_contains($habNormalizada, 'capacit')) {
        $mostrarTooltipHab = true;
        $textoTooltipHab = $charlaNombre;
    }
}
// ===============================================================
// Marcar si solicitud está cerrada
// ===============================================================
$solCerrada = ($sol['estado'] === 'F');
$solAutorizada = ($sol['estado'] === 'A');
// ===============================================================
// Permiso imprimir
// ===============================================================
$puedeImprimir = ($isAdmin || $isregasist) && $solAutorizada;
$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
$puedeAgregarParticipante = ($isAdmin || $idRol === 5) && !$solCerrada;

$esContratista = ($idRol === 3);

// ❌ Contratista NO puede cerrar si está Autorizada
$bloquearCerrar = ($esContratista && $solAutorizada);

/* ===============================================================
   LISTA APROBO
   =============================================================== */
$aproboStmt = $pdo->query("SELECT id, aprobo FROM ceo_aprobo ORDER BY id");
$listaAprobo = $aproboStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================================================
   LISTA DE PARTICIPANTES
   =============================================================== */
$parts = $pdo->prepare("
  SELECT ps.id_solicitud, ps.rut, ps.nombre, ps.apellidop, ps.apellidom,
         (SELECT c.cargo FROM ceo_cargo_contratistas c WHERE c.id = ps.id_cargo LIMIT 1) AS cargo,
         ps.autorizado, ps.asistio, ps.aprobo, ps.observacion, ps.wf
    FROM ceo_participantes_solicitud ps
   WHERE ps.id_solicitud = :nsol
   ORDER BY ps.nombre
");
$parts->execute([':nsol' => $sol['nsolicitud']]);
$participantes = $parts->fetchAll(PDO::FETCH_ASSOC);

function solicitudFetchPairs(PDO $pdo, string $sql, string $key = 'id', string $label = 'nombre'): array
{
  $rows = [];
  foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[(int)$row[$key]] = (string)$row[$label];
  }
  return $rows;
}

$catProcesos = $isAdmin ? solicitudFetchPairs($pdo, 'SELECT id, desc_proceso AS nombre FROM ceo_procesos ORDER BY desc_proceso') : [];
$catPatios = $isAdmin ? solicitudFetchPairs($pdo, 'SELECT id, desc_patios AS nombre FROM ceo_patios ORDER BY desc_patios') : [];
$catUo = $isAdmin ? solicitudFetchPairs($pdo, 'SELECT id, desc_uo AS nombre FROM ceo_uo ORDER BY desc_uo') : [];
$catHabCeo = $isAdmin ? solicitudFetchPairs($pdo, 'SELECT id, desc_tipo AS nombre FROM ceo_habilitaciontipo ORDER BY desc_tipo') : [];
$catServicios = $isAdmin ? $pdo->query('SELECT id, uo, servicio FROM ceo_servicios ORDER BY servicio')->fetchAll(PDO::FETCH_ASSOC) : [];
$tiposHabilitacion = ['Seguridad', 'Técnica', 'Ambos'];

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Detalle Solicitud #<?= htmlspecialchars($sol['nsolicitud']) ?> - <?= APP_NAME ?></title>

<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {background:#f7f9fc; font-size:0.9rem;}
.topbar {background:#fff; border-bottom:1px solid #e3e6ea;}
.brand-title {color:#0065a4; font-weight:600; font-size:1.1rem;}
.card {border:none; box-shadow:0 2px 4px rgba(0,0,0,.05);}
.form-label {font-weight:500; color:#333; font-size:0.85rem;}
.form-control[readonly] {background:#f9fafb; font-size:0.85rem;}
h4, h5, h6 {font-weight:500;}
.table th, .table td {font-size:0.85rem;}
.table th {background:#eaf2fb; font-weight:600;}
.table td {color:#444;}
.table-sm>tbody>tr>td, .table-sm>thead>tr>th {padding:0.35rem 0.5rem;}
.alert-info {font-size:0.9rem;}
@media print {

  /* Ocultar navegación y botones */
  .topbar,
  .btn,
  .modal,
  #boxCerrarSolicitud {
    display: none !important;
  }

  body {
    background: #fff !important;
  }

  /* Quitar margen default */
  @page {
    margin: 15mm;
  }

  /* Footer solo con número de página */
  @page {
    @bottom-center {
      content: "Página " counter(page) " de " counter(pages);
      font-size: 11px;
    }
  }

}
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:55px;">
      <div>
        <div class="brand-title mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="solicitudes.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container mb-5">

  <h5 class="text-primary mb-3">
    <i class="bi bi-file-earmark-text me-2"></i>
    Detalle Solicitud N° <?= htmlspecialchars($sol['nsolicitud']) ?>
  </h5>

<?php if ($puedeImprimir): ?>
  <div class="text-end mb-3">
    <button onclick="window.print();" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-2"></i> Imprimir
    </button>
  </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
  <div class="text-end mb-3">
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalEditarSolicitud">
      <i class="bi bi-pencil-square me-2"></i>Editar datos solicitud
    </button>
  </div>
<?php endif; ?>

<?php if ($puedeAutorizar && !$solCerrada && !$solAutorizada): ?>
  <div class="text-end mb-3">
    <button id="btnAutorizar" class="btn btn-outline-success btn-sm">
      <i class="bi bi-envelope-check me-2"></i>Autorización
    </button>
  </div>
<?php endif; ?>


  <!-- ========== CABECERA ========== -->
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form class="row g-3">
        <div class="col-md-2">
          <label class="form-label">Fecha</label>
          <input type="date" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['fecha']) ?>" readonly>
        </div>

        <div class="col-md-2">
          <label class="form-label">Hora Inicio</label>
          <input type="time" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['horainicio']) ?>" readonly>
        </div>

        <div class="col-md-2">
          <label class="form-label">Hora Término</label>
          <input type="time" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['horatermino']) ?>" readonly>
        </div>

        <div class="col-md-3">
          <label class="form-label">Empresa</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['empresa_nombre']) ?>" readonly>
        </div>

        <div class="col-md-3">
          <label class="form-label">Proceso</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['proceso_nombre']) ?>" readonly>
        </div>

        <div class="col-md-4">
          <label class="form-label">Patio</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['patio_nombre']) ?>" readonly>
        </div>

        <div class="col-md-4">
          <label class="form-label">Unidad Operativa</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['uo_nombre']) ?>" readonly>
        </div>

<div class="col-md-4">
  <label class="form-label">Servicio</label>
  <input
    type="text"
    class="form-control form-control-sm"
    value="<?= htmlspecialchars($sol['servicio_nombre']) ?>"
    readonly
  >
</div>

<div class="col-md-4">
  <label class="form-label">Habilitación CEO</label>
  <input
    type="text"
    class="form-control form-control-sm"
    value="<?= htmlspecialchars($sol['habceo_nombre']) ?>"
    readonly
    <?php if ($mostrarTooltipHab): ?>
      data-bs-toggle="tooltip"
      data-bs-placement="top"
      title="<?= htmlspecialchars($textoTooltipHab) ?>"
      style="cursor: help;"
    <?php endif; ?>
  >
</div>

        <div class="col-md-4">
          <label class="form-label">Tipo Habilitación</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['tipohabilitacion']) ?>" readonly>
        </div>

        <div class="col-md-4">
          <label class="form-label">Responsable UO</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['resp_uo_nombre']) ?>" readonly>
        </div>

        <div class="col-md-4">
          <label class="form-label">Responsable Línea</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['resp_linea_nombre']) ?>" readonly>
        </div>

        <div class="col-md-4">
          <label class="form-label">Capacitación</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['charla_nombre'] ?? '') ?>" readonly>
        </div>

        <div class="col-12">
          <label class="form-label">Observación</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($sol['observacion'] ?? '') ?>" readonly>
        </div>

      </form>
    </div>
  </div>

  <!-- ========== PARTICIPANTES ========== -->
  <div class="card rounded-4">
    <div class="card-body">

      <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
        <h6 class="text-primary mb-0">
          <i class="bi bi-people me-2"></i>Lista de Participantes
        </h6>
        <?php if ($puedeAgregarParticipante): ?>
          <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAgregarParticipante">
            <i class="bi bi-person-plus me-1"></i>Agregar participante
          </button>
        <?php endif; ?>
      </div>

      <!-- Botón cerrar -->
<?php if ($puedeCerrar && !$bloquearCerrar): ?>
  <div class="text-end mb-3" id="boxCerrarSolicitud" style="display:none;">
      <button class="btn btn-danger btn-sm" id="btnCerrarSolicitud">
          <i class="bi bi-lock-fill me-1"></i> Cerrar Solicitud
      </button>
  </div>
<?php endif; ?>

      <div class="table-responsive">
      <?php if (empty($participantes)): ?>
        <div class="alert alert-info text-center mb-0">No hay participantes.</div>
      <?php else: ?>
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th class="text-center">Hist.</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>1° Apellido</th>
              <th>2° Apellido</th>
              <th>Cargo</th>
              <th class="text-center">Autorizado</th>
              <th class="text-center">Asistió</th>
              <th class="text-center">Aprobado</th>
              <th>Observación Rechazo</th>
              <th>WF</th>
            </tr>
          </thead>

          <tbody>

          <?php foreach($participantes as $p): ?>
              <?php
    // En solicitudes no autorizadas, Registro Asistencia mantiene ocultos los no autorizados.
    if ($isregasist && !$solAutorizada && !(int)$p['autorizado']) {
        continue; // saltar esta fila
    }
    ?>
            <tr data-rut="<?= htmlspecialchars($p['rut']) ?>" data-nsol="<?= (int)$p['id_solicitud'] ?>">

              <td class="text-center">
                <button class="btn btn-sm btn-outline-primary ver-historial"
                        data-rut="<?= htmlspecialchars($p['rut']) ?>"
                        title="Ver historial">
                  <i class="bi bi-clock-history"></i>
                </button>
              </td>

              <td><?= htmlspecialchars($p['rut']) ?></td>
              <td><?= htmlspecialchars($p['nombre']) ?></td>
              <td><?= htmlspecialchars($p['apellidop']) ?></td>
              <td><?= htmlspecialchars($p['apellidom']) ?></td>
              <td><?= htmlspecialchars($p['cargo']) ?></td>

              <td class="text-center">
                <input type="checkbox" class="chk-aut"
                       <?= ($p['autorizado'] ? 'checked' : '') ?>
                       <?= (!$solCerrada && $isAdmin) ? '' : 'disabled' ?>>
              </td>

              <td class="text-center">
                <input type="checkbox" class="chk-asistio"
                       <?= ($p['asistio'] ? 'checked' : '') ?>
                       <?= (!$solCerrada && $isasistio) ? '' : 'disabled' ?>>
              </td>

              <td class="text-center">
                <select class="form-select form-select-sm sel-aprobo"
                        <?= (!$solCerrada && $puedeAprobar) ? "" : "disabled" ?>>
                
                    <option value="" selected disabled>-- Seleccione --</option>
                
                    <?php foreach ($listaAprobo as $ap): ?>
                        <option value="<?= htmlspecialchars($ap['aprobo']) ?>"
                            <?= ($p['aprobo'] === $ap['aprobo'] ? "selected" : "") ?>>
                            <?= htmlspecialchars($ap['aprobo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

              </td>

              <td>
                <input type="text" 
                       class="form-control form-control-sm inp-observ"
                       value="<?= htmlspecialchars($p['observacion'] ?? '') ?>"
                       <?= (!$solCerrada && $puedeObservar) ? "" : "readonly" ?>
              </td>

              <td><?= htmlspecialchars($p['wf'] ?? '') ?></td>

            </tr>
          <?php endforeach; ?>

          </tbody>
        </table>
      <?php endif; ?>
      </div>

    </div>
  </div>

</div> <!-- /container -->


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/ceo.noetica.cl/public/js/working.js"></script>
<script>
// =============================================================
// ESPERAR A QUE EL DOM ESTÉ LISTO
// =============================================================
document.addEventListener('DOMContentLoaded', function () {

    verificarCierreSolicitud();

    const modalHistorial = new bootstrap.Modal(document.getElementById('modalHistorial'));

    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
    document.querySelectorAll('.ver-historial').forEach(btn => {
        btn.addEventListener('click', async e => {

            const rut = e.currentTarget.dataset.rut;

            document.getElementById('historialContenido').innerHTML =
              `<div class='text-center text-secondary py-3'>Cargando historial...</div>`;

            modalHistorial.show();

            try {
                const resp = await fetch('solicitud_historial.php?rut=' + encodeURIComponent(rut));
                const html = await resp.text();

                document.getElementById('historialContenido').innerHTML = html;

                document.getElementById('btnExportExcel').href =
                    'solicitud_historial_excel.php?rut=' + encodeURIComponent(rut);

            } catch(err) {
                document.getElementById('historialContenido').innerHTML =
                  `<div class='alert alert-danger'>Error cargando historial</div>`;
            }
        });
    });

});

// ===============================================================
// BLOQUEAR FETCH SI SOLICITUD ESTÁ CERRADA
// ===============================================================
const solicitudCerrada = <?= $solCerrada ? 'true' : 'false' ?>;

// ===============================
// ACTUALIZAR AUTORIZADO
// ===============================
document.querySelectorAll('.chk-aut').forEach(chk => {
    chk.addEventListener('change', async function () {

        if (solicitudCerrada) return;

        const tr = this.closest('tr');
        const rut = tr.dataset.rut;
        const nsol = tr.dataset.nsol;

        await fetch('update_participante.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                rut: rut,
                nsol: nsol,
                campo: 'autorizado',
                valor: this.checked ? 1 : 0
            })
        });

    });
});

// ===============================
// ACTUALIZAR ASISTIÓ
// ===============================
document.querySelectorAll('.chk-asistio').forEach(chk => {
    chk.addEventListener('change', async function () {

        if (solicitudCerrada) return;

        const tr = this.closest('tr');
        const rut = tr.dataset.rut;
        const nsol = tr.dataset.nsol;

        await fetch('update_participante.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                rut: rut,
                nsol: nsol,
                campo: 'asistio',
                valor: this.checked ? 1 : 0
            })
        });

    });
});

// ===============================
// ACTUALIZAR APROBADO
// ===============================
document.querySelectorAll('.sel-aprobo').forEach(sel => {
    sel.addEventListener('change', async function () {

        if (solicitudCerrada) return;

        const tr = this.closest('tr');
        const rut = tr.dataset.rut;
        const nsol = tr.dataset.nsol;
        const valor = this.value;

        await fetch('update_participante.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                rut: rut,
                nsol: nsol,
                campo: 'aprobo',
                valor: valor
            })
        });

        verificarCierreSolicitud();
    });
});

// ===============================
// ACTUALIZAR OBSERVACIÓN
// ===============================
document.querySelectorAll('.inp-observ').forEach(input => {
    input.addEventListener('blur', async function () {

        if (solicitudCerrada) return;

        const tr = this.closest('tr');
        const rut = tr.dataset.rut;
        const nsol = tr.dataset.nsol;
        const valor = this.value;

        await fetch('update_participante.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                rut: rut,
                nsol: nsol,
                campo: 'observacion',
                valor: valor
            })
        });

        verificarCierreSolicitud();
    });
});

// ===========================================================
// NO auto-marcar WF si solicitud está cerrada
// ===========================================================
document.addEventListener('DOMContentLoaded', () => {

    if (solicitudCerrada) return;

    document.querySelectorAll('tbody tr').forEach(tr => {

        const wf = tr.querySelector('td:last-child')?.innerText.trim().toLowerCase();
        const chkAut = tr.querySelector('.chk-aut');

        if (!chkAut) return;

        if (wf === 'si' && !chkAut.checked) {

            chkAut.checked = true;

            const rut = tr.dataset.rut;
            const nsol = tr.dataset.nsol;

            fetch('update_participante.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    rut: rut,
                    nsol: nsol,
                    campo: 'autorizado',
                    valor: 1
                })
            });

        }

    });

});

// ===========================================================
// LÓGICA BOTÓN CERRAR SOLICITUD
// ===========================================================
function verificarCierreSolicitud() {

    if (solicitudCerrada) {
        document.getElementById('boxCerrarSolicitud').style.display = 'none';
        return;
    }

    let ok = true;     // Asumimos que se pueden cerrar
    let existenValidos = false;

    document.querySelectorAll('tbody tr').forEach(tr => {

        const aut = tr.querySelector('.chk-aut')?.checked;
        const asis = tr.querySelector('.chk-asistio')?.checked;
        const apro = tr.querySelector('.sel-aprobo')?.value.trim();

        // Si no está autorizado o no asistió, no se exige aprobación
        if (!(aut && asis)) return;

        existenValidos = true;

        // Si está autorizado + asistió → debe tener aprobación NO VACÍA
        if (apro === "" || apro === null) {
            ok = false;
        }

    });

    // Si hay participantes válidos, y todos tienen aprobación → mostrar botón
    document.getElementById('boxCerrarSolicitud').style.display =
        (ok && existenValidos) ? 'block' : 'none';
}



// ===========================================================
// CLICK BOTÓN CERRAR SOLICITUD
// ===========================================================
document.getElementById('btnCerrarSolicitud')?.addEventListener('click', async function() {

    if (!confirm("¿Seguro que deseas cerrar esta solicitud?")) return;

    await fetch('cerrar_solicitud.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ nsol: <?= (int)$sol['nsolicitud'] ?> })
    });

    alert("Solicitud cerrada correctamente.");
    location.reload();
});



// ===========================================================
// AUTORIZAR SOLICITUD (mostrar "Trabajando" antes de navegar)
// ===========================================================
document.getElementById('btnAutorizar')?.addEventListener('click', function () {

    if (!confirm('¿Deseas autorizar esta solicitud?')) return;

            Working.show('Autorizando solicitud…');
            setTimeout(() => {
              window.location.href =
                'solicitud_autoriza_envio.php?id=<?= (int)$sol['nsolicitud'] ?>';
            }, 50);
});

// ===========================================================
// AGREGAR / ACTUALIZAR PARTICIPANTE
// ===========================================================
document.addEventListener('DOMContentLoaded', () => {
document.getElementById('btnGuardarParticipanteNuevo')?.addEventListener('click', async function () {
    const btn = this;
    const msg = document.getElementById('agregarParticipanteMsg');
    const payload = {
        nsolicitud: <?= (int)$sol['nsolicitud'] ?>,
        rut: document.getElementById('add_rut')?.value.trim() || '',
        nombre: document.getElementById('add_nombre')?.value.trim() || '',
        apellidop: document.getElementById('add_apellidop')?.value.trim() || '',
        apellidom: document.getElementById('add_apellidom')?.value.trim() || '',
        cargo: document.getElementById('add_cargo')?.value.trim() || ''
    };

    function setMsg(text, kind = 'info') {
        if (!msg) return;
        msg.innerHTML = text ? `<div class="alert alert-${kind} py-2 mb-0">${escapeHtml(text)}</div>` : '';
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    if (Object.values(payload).some(value => String(value).trim() === '')) {
        setMsg('Todos los campos son obligatorios.', 'warning');
        return;
    }

    btn.disabled = true;
    setMsg('Grabando participante...', 'info');

    try {
        const resp = await fetch('ajax_solicitud_participante_guardar.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error || 'No se pudo guardar el participante.');
        setMsg(data.accion === 'actualizado' ? 'Participante actualizado correctamente.' : 'Participante agregado correctamente.', 'success');
        setTimeout(() => location.reload(), 700);
    } catch (err) {
        setMsg(err.message || 'Error al guardar participante.', 'danger');
    } finally {
        btn.disabled = false;
    }
});
});
</script>
<?php if ($puedeAgregarParticipante): ?>
<div class="modal fade" id="modalAgregarParticipante" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header bg-primary text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Agregar participante a solicitud</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border small">
          Si el RUT ya existe en esta solicitud, se actualizarán solo sus datos personales y cargo. No se modifican autorización, asistencia, aprobación ni observaciones existentes.
        </div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">RUT</label>
            <input type="text" class="form-control form-control-sm" id="add_rut" placeholder="12345678-9" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Nombre</label>
            <input type="text" class="form-control form-control-sm" id="add_nombre" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Apellido Paterno</label>
            <input type="text" class="form-control form-control-sm" id="add_apellidop" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Apellido Materno</label>
            <input type="text" class="form-control form-control-sm" id="add_apellidom" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">Cargo</label>
            <input type="text" class="form-control form-control-sm" id="add_cargo" required>
          </div>
        </div>
        <div id="agregarParticipanteMsg" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnGuardarParticipanteNuevo">
          <i class="bi bi-save me-1"></i>Grabar
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($isAdmin): ?>
<div class="modal fade" id="modalEditarSolicitud" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header bg-primary text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar datos solicitud</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit_nsolicitud" value="<?= (int)$sol['nsolicitud'] ?>">
        <div class="alert alert-light border small">
          Solo se actualizarán Proceso, Patio, Unidad Operativa, Servicio, Habilitación CEO y Tipo Habilitación.
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Proceso</label>
            <select id="edit_proceso" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($catProcesos as $idCat => $nombreCat): ?>
                <option value="<?= (int)$idCat ?>" <?= (int)$sol['proceso'] === (int)$idCat ? 'selected' : '' ?>><?= esc($nombreCat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Patio</label>
            <select id="edit_patio" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($catPatios as $idCat => $nombreCat): ?>
                <option value="<?= (int)$idCat ?>" <?= (int)$sol['patio'] === (int)$idCat ? 'selected' : '' ?>><?= esc($nombreCat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Unidad Operativa</label>
            <select id="edit_uo" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($catUo as $idCat => $nombreCat): ?>
                <option value="<?= (int)$idCat ?>" <?= (int)$sol['uo'] === (int)$idCat ? 'selected' : '' ?>><?= esc($nombreCat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Servicio</label>
            <select id="edit_servicio" class="form-select form-select-sm" data-current="<?= (int)$sol['servicio'] ?>" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($catServicios as $srv): ?>
                <option value="<?= (int)$srv['id'] ?>" data-uo="<?= (int)$srv['uo'] ?>" <?= (int)$sol['servicio'] === (int)$srv['id'] ? 'selected' : '' ?>><?= esc($srv['servicio']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Habilitación CEO</label>
            <select id="edit_habilitacionceo" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($catHabCeo as $idCat => $nombreCat): ?>
                <option value="<?= (int)$idCat ?>" <?= (int)$sol['habilitacionceo'] === (int)$idCat ? 'selected' : '' ?>><?= esc($nombreCat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Tipo Habilitación</label>
            <select id="edit_tipohabilitacion" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <?php foreach ($tiposHabilitacion as $tipoHab): ?>
                <option value="<?= esc($tipoHab) ?>" <?= (string)$sol['tipohabilitacion'] === $tipoHab ? 'selected' : '' ?>><?= esc($tipoHab) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div id="editSolicitudMsg" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnGuardarDatosSolicitud">
          <i class="bi bi-save me-1"></i>Guardar cambios
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const selUo = document.getElementById('edit_uo');
  const selServicio = document.getElementById('edit_servicio');
  const btnGuardar = document.getElementById('btnGuardarDatosSolicitud');
  const msg = document.getElementById('editSolicitudMsg');

  function setMsg(text, kind = 'info') {
    if (!msg) return;
    msg.innerHTML = text ? `<div class="alert alert-${kind} py-2 mb-0">${escapeHtml(text)}</div>` : '';
  }

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  function filtrarServiciosPorUo() {
    if (!selUo || !selServicio) return;
    const uo = selUo.value;
    let selectedVisible = false;
    Array.from(selServicio.options).forEach((opt) => {
      if (!opt.value) {
        opt.hidden = false;
        return;
      }
      const visible = opt.dataset.uo === uo;
      opt.hidden = !visible;
      if (visible && opt.selected) selectedVisible = true;
    });
    if (!selectedVisible) selServicio.value = '';
  }

  selUo?.addEventListener('change', filtrarServiciosPorUo);
  document.getElementById('modalEditarSolicitud')?.addEventListener('shown.bs.modal', filtrarServiciosPorUo);
  filtrarServiciosPorUo();

  btnGuardar?.addEventListener('click', async () => {
    const payload = {
      nsolicitud: document.getElementById('edit_nsolicitud')?.value || '',
      proceso: document.getElementById('edit_proceso')?.value || '',
      patio: document.getElementById('edit_patio')?.value || '',
      uo: document.getElementById('edit_uo')?.value || '',
      servicio: document.getElementById('edit_servicio')?.value || '',
      habilitacionceo: document.getElementById('edit_habilitacionceo')?.value || '',
      tipohabilitacion: document.getElementById('edit_tipohabilitacion')?.value || ''
    };

    if (Object.values(payload).some((value) => String(value).trim() === '')) {
      setMsg('Debe completar todos los campos.', 'warning');
      return;
    }

    btnGuardar.disabled = true;
    setMsg('Guardando cambios...', 'info');

    try {
      const res = await fetch('ajax_solicitud_mantenedor_update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'No se pudo actualizar la solicitud.');
      setMsg('Solicitud actualizada correctamente.', 'success');
      setTimeout(() => location.reload(), 700);
    } catch (err) {
      setMsg(err.message || 'Error al guardar cambios.', 'danger');
    } finally {
      btnGuardar.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>
<div class="modal fade" id="modalHistorial" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content rounded-4">

      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bi bi-clock-history me-2"></i>Historial del Participante
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="historialContenido">
            <div class="text-center text-secondary py-3">Cargando...</div>
        </div>
      </div>

      <div class="modal-footer">
        <a id="btnExportExcel" href="#" class="btn btn-success btn-sm">
          <i class="bi bi-file-earmark-excel"></i> Exportar Excel
        </a>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>

</body>
</html>
