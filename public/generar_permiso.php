<?php
// --------------------------------------------------------------
// generar_permiso.php - Centro de Excelencia Operacional (CEO)
// Genera Permiso desde Revisión de Cuadrillas
// --------------------------------------------------------------
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

date_default_timezone_set('America/Santiago');

require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

/* ============================================================
   Helpers
============================================================ */
if (!function_exists('esc')) {
    function esc(mixed $v): string {
        if ($v === null) return '';
        $s = (string)$v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$pdo = db();

/* ===============================================================
   ENDPOINTS AJAX (GET)
=============================================================== */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Servicios por UO
    if ($_GET['action'] === 'servicios_by_uo') {
        $uoId = (int)($_GET['uo_id'] ?? 0);
        $out = [];

        if ($uoId > 0) {
            $st = $pdo->prepare("
                SELECT id, servicio 
                FROM ceo_servicios 
                WHERE uo = :uo 
                ORDER BY servicio
            ");
            $st->execute([':uo' => $uoId]);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'text' => $r['servicio']
                ];
            }
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Responsable UO por servicio
    if ($_GET['action'] === 'responsable_by_servicio') {
        $servicioId = (int)($_GET['servicio_id'] ?? 0);
        $out = ['ok' => false];

        if ($servicioId > 0) {
            $st = $pdo->prepare("
                SELECT id_arearesp 
                FROM ceo_servicios 
                WHERE id = :id 
                LIMIT 1
            ");
            $st->execute([':id' => $servicioId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            if ($r && !empty($r['id_arearesp'])) {
                $out = [
                    'ok' => true,
                    'id_arearesp' => (int)$r['id_arearesp']
                ];
            }
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ===============================================================
   GUARDAR SOLICITUD (POST desde generar_permiso.php)
   =============================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'guardar_solicitud') {

    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo->beginTransaction();

        // === Número de solicitud ===
        $next = (int)$pdo->query("
            SELECT COALESCE(MAX(nsolicitud),0)+1 
            FROM ceo_solicitudes
        ")->fetchColumn();

        $solicitante = $_SESSION['auth']['id'] ?? null;

        // === Insertar solicitud ===
// === Insertar solicitud ===
 // === Insertar solicitud ===
            $stmt = $pdo->prepare("
                INSERT INTO ceo_solicitudes
                (nsolicitud, fecha, horainicio, horatermino,
                 contratista, proceso, habilitacionceo, tipohabilitacion,
                 patio, uo, servicio, responsable,
                 resphse, resplinea,
                 observacion, solicitante,
                 fechacreacion, estado)
                VALUES
                (:n, :f, :hi, :hf,
                 :emp, :pro, :hab, :tipo,
                 :pat, :uo, :serv, :resp,
                 :rhse, :rlinea,
                 :obs, :sol,
                 :fcrea, 'I')
            ");
            
            $stmt->execute([
                ':n'       => $next,
                ':f'       => $_POST['fecha'] ?? null,
                ':hi'      => $_POST['hora_inicio'] ?? null,
                ':hf'      => $_POST['hora_termino'] ?? null,
                ':emp'     => $_POST['empresa'] ?? null,
                ':pro'     => $_POST['proceso'] ?? null,
                ':hab'     => $_POST['habilitacion_ceo'] ?? null,
                ':tipo'    => $_POST['tipo_habilitacion'] ?? null,
                ':pat'     => $_POST['patio'] ?? null,
                ':uo'      => $_POST['uo'] ?? null,
                ':serv'    => $_POST['servicio'] ?? null,
                ':resp'    => $_POST['resp_uo'] ?? null,
                ':rhse'    => $_POST['resp_hse'] ?? null,
                ':rlinea'  => $_POST['resp_linea'] ?? null,
                ':obs'     => $_POST['observacion'] ?? null,
                ':sol'     => $solicitante,
                ':fcrea'   => date('Y-m-d')
            ]);

                // ======================================================
                // VINCULAR SOLICITUD CON LA CUADRILLA (ceo_habilitacion)
                // ======================================================
                //$programaId = (int)($_GET['programa'] ?? 0);
                $programaId = (int)($_POST['programa'] ?? 0);

                
                if ($programaId > 0) {
                    $updHab = $pdo->prepare("
                        UPDATE ceo_habilitacion
                           SET nsolicitud = :nsol
                         WHERE id = :id
                           AND nsolicitud IS NULL
                    ");
                    $updHab->execute([
                        ':nsol' => $next,
                        ':id'   => $programaId
                    ]);
                }

        // === Actualizar calendario ===
        if (!empty($_POST['fecha']) && !empty($_POST['patio'])) {
            $upd = $pdo->prepare("
                UPDATE ceo_calendario
                   SET estado = 'OCUPADO',
                       nsolicitud = :n
                 WHERE id_patio = :p
                   AND fecha = :f
                   AND horainicio BETWEEN :hi AND :hf
                   AND estado = 'DISPONIBLE'
            ");
            $upd->execute([
                ':n'  => $next,
                ':p'  => $_POST['patio'],
                ':f'  => $_POST['fecha'],
                ':hi' => $_POST['hora_inicio'],
                ':hf' => $_POST['hora_termino']
            ]);
        }

        // === Participantes ===
        $rows = json_decode($_POST['participantes'] ?? '[]', true);

        if (!empty($rows)) {

            $cargoStmt = $pdo->prepare("
                SELECT id 
                FROM ceo_cargo_contratistas 
                WHERE LOWER(TRIM(cargo)) = LOWER(TRIM(:cargo))
                LIMIT 1
            ");

            $partStmt = $pdo->prepare("
                INSERT INTO ceo_participantes_solicitud
                (id_solicitud, rut, nombre, apellidop, apellidom, id_cargo, wf, autorizado)
                VALUES
                (:id, :rut, :nom, :ap, :am, :cargo, :wf, :aut)
            ");


foreach ($rows as $r) {

    if (empty($r['rut'])) continue;

    // === Buscar WF del participante ===
    $wfStmt = $pdo->prepare("
        SELECT wf
        FROM ceo_reportewf
        WHERE rut_empleado = :rut
        ORDER BY id DESC
        LIMIT 1
    ");
    $wfStmt->execute([':rut' => $r['rut']]);
    $wfValor = $wfStmt->fetchColumn(); // puede ser null

    // === Traducir WF numérico a texto ===
    $wfTexto = 'No Autorizado';
    $autorizado = 0;

    if ($wfValor !== false && $wfValor !== null) {
        if ((int)$wfValor >= 100) {
            $wfTexto = 'Si';
            $autorizado = 1;
        } else {
            $wfTexto = 'No';
            $autorizado = 0;
        }
    }

    // === Buscar cargo ===
    $cargoStmt->execute([':cargo' => $r['cargo'] ?? '']);
    $idCargo = $cargoStmt->fetchColumn() ?: null;

    // === Insertar participante ===
    $partStmt->execute([
        ':id'    => $next,
        ':rut'   => $r['rut'],
        ':nom'   => $r['nombre'] ?? '',
        ':ap'    => $r['apellidop'] ?? '',
        ':am'    => $r['apellidom'] ?? '',
        ':cargo' => $idCargo,
        ':wf'    => $wfTexto,      // 👈 TEXTO
        ':aut'   => $autorizado    // 👈 1 solo si WF = Si
    ]);
}

        }

        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'nsolicitud' => $next
        ]);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

/* ============================================================
   CONTEXTO DESDE revision_cuadrilla.php
============================================================ */
$empresaId  = (int)($_GET['empresa'] ?? 0);
$uoId       = (int)($_GET['uo'] ?? 0);
$programaId = (int)($_GET['programa'] ?? 0);

if ($empresaId <= 0 || $uoId <= 0 || $programaId <= 0) {
    die("❌ Parámetros insuficientes para generar permiso.");
}

/* ============================================================
   PARTICIPANTES DE LA CUADRILLA
============================================================ */
$stmt = $pdo->prepare("
SELECT 
  hp.rut,
  hp.nombre,
  hp.apellidos,
  hp.cargo
FROM ceo_habilitacion_personas hp
INNER JOIN ceo_habilitacion h
  ON h.id = hp.id_habilitacion
WHERE h.id = :id
  AND hp.estado = 'ACTIVO'
ORDER BY hp.apellidos, hp.nombre


");
$stmt->execute([':id' => $programaId]);
$participantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$participantes) {
    die("⚠️ La cuadrilla seleccionada no tiene participantes.");
}

/* ============================================================
   CATÁLOGOS (IGUAL A nueva_solicitud.php)
============================================================ */
function fetchPairs(PDO $pdo, string $sql): array {
    $out = [];
    $st = $pdo->query($sql);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[$r['id']] = $r['nombre'];
    }
    return $out;
}

$empresas     = fetchPairs($pdo,"SELECT id,nombre FROM ceo_empresas ORDER BY nombre");
$procesos     = fetchPairs($pdo,"SELECT id,desc_proceso AS nombre FROM ceo_procesos ORDER BY desc_proceso");
$habCeo       = fetchPairs($pdo,"SELECT id,desc_tipo AS nombre FROM ceo_habilitaciontipo ORDER BY desc_tipo");
$patios       = fetchPairs($pdo,"SELECT id,desc_patios AS nombre FROM ceo_patios ORDER BY desc_patios");
$uo           = fetchPairs($pdo,"SELECT id,desc_uo AS nombre FROM ceo_uo ORDER BY desc_uo");
$responsables = fetchPairs($pdo,"SELECT id,responsable AS nombre FROM ceo_responsables ORDER BY responsable");
$resphse      = fetchPairs($pdo,"SELECT id,nombre FROM ceo_responsablehse ORDER BY nombre");
$resplinea    = fetchPairs($pdo,"SELECT id,CONCAT(nombre,' ',apellidop,' ',apellidom) AS nombre FROM ceo_evaluador WHERE tipo=2 ORDER BY nombre");
$charlas      = fetchPairs($pdo,"SELECT id,desc_charlas AS nombre FROM ceo_charlas ORDER BY desc_charlas");
$reinduccion  = fetchPairs($pdo,"SELECT id,reinduccion AS nombre FROM ceo_reinduccion ORDER BY reinduccion");

/* ============================================================
   AGENDA SEMANAL (IGUAL A nueva_solicitud.php)
============================================================ */

// Patios
$patiosSemana = $pdo->query("
    SELECT id, desc_patios 
    FROM ceo_patios 
    ORDER BY desc_patios
")->fetchAll(PDO::FETCH_ASSOC);

// Bloqueos de horas
$bloqueos = [];
try {
    $stBloq = $pdo->query("
        SELECT fecha_inicio, fecha_fin, motivo
        FROM ceo_bloqueo_horas
        WHERE estado = 'A'
    ");
    $bloqueos = $stBloq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Error leyendo bloqueos: ".$e->getMessage());
}

// Semana actual (lunes a viernes)
$hoy     = new DateTime();
$lunes   = (clone $hoy)->modify('Monday this week');
$viernes = (clone $lunes)->modify('+4 days');

// Ocupaciones por patio / fecha
$ocupaciones = [];
try {
    $sql = "
        SELECT 
            c.id_patio,
            c.fecha,
            c.nsolicitud,
            MIN(s.horainicio)  AS horainicio,
            MAX(s.horatermino) AS horatermino,
            p.desc_patios
        FROM ceo_calendario c
        LEFT JOIN ceo_solicitudes s ON s.nsolicitud = c.nsolicitud
        LEFT JOIN ceo_patios p ON p.id = c.id_patio
        WHERE c.fecha BETWEEN :l AND :v
          AND (c.estado <> 'DISPONIBLE' OR c.nsolicitud IS NOT NULL)
        GROUP BY c.id_patio, c.fecha, c.nsolicitud, p.desc_patios
        ORDER BY c.fecha, c.id_patio
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':l' => $lunes->format('Y-m-d'),
        ':v' => $viernes->format('Y-m-d')
    ]);
    $ocupaciones = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log($e->getMessage());
}

$fechaDefault = date('Y-m-d');

/* ===============================================================
   ENDPOINTS AJAX (GET)
=============================================================== */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Servicios por UO
    if ($_GET['action'] === 'servicios_by_uo') {
        $uoId = (int)($_GET['uo_id'] ?? 0);
        $out = [];

        if ($uoId > 0) {
            $st = $pdo->prepare("
                SELECT id, servicio 
                FROM ceo_servicios 
                WHERE uo = :uo 
                ORDER BY servicio
            ");
            $st->execute([':uo' => $uoId]);

            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'id' => (int)$r['id'],
                    'text' => $r['servicio']
                ];
            }
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Responsable UO por servicio
    if ($_GET['action'] === 'responsable_by_servicio') {
        $servicioId = (int)($_GET['servicio_id'] ?? 0);
        $out = ['ok' => false];

        if ($servicioId > 0) {
            $st = $pdo->prepare("
                SELECT id_arearesp 
                FROM ceo_servicios 
                WHERE id = :id 
                LIMIT 1
            ");
            $st->execute([':id' => $servicioId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            if ($r && !empty($r['id_arearesp'])) {
                $out = [
                    'ok' => true,
                    'id_arearesp' => (int)$r['id_arearesp']
                ];
            }
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Generar Permiso - <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body{background:#f7f9fc}
.topbar{background:#fff;border-bottom:1px solid #e3e6ea}
.brand-title{color:#0065a4;font-weight:600}
.card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05)}
.table thead{background:#eaf2fb}
</style>
</head>

<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" style="height:60px;" alt="Logo">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="revision_cuadrillas.php?empresa=<?=$empresaId?>&uo=<?=$uoId?>&programa=<?=$programaId?>"
       class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container">

<div class="row g-4 mb-4">

<!-- ============================================================
     AGENDA SEMANAL DE PLANIFICACIÓN
============================================================ -->
<div class="card rounded-4 mb-4">
  <div class="card-header bg-light d-flex justify-content-between align-items-center">
    <h6 class="fw-bold mb-0 text-primary">
      <i class="bi bi-calendar-week me-2"></i>Agenda semanal patios
    </h6>
    <button class="btn btn-sm btn-outline-primary"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#collapseAgenda">
      Mostrar / Ocultar
    </button>
  </div>

  <div id="collapseAgenda" class="collapse show">
    <div class="card-body p-2">
      <div id="tablaSemana" class="table-responsive"></div>
    </div>
  </div>
</div>

<!-- ============================================================
     FORMULARIO
============================================================ -->
<div class="col-lg-6">
<div class="card rounded-4 h-100">
<div class="card-body">

<h6 class="fw-bold mb-3 text-primary">
<i class="bi bi-file-earmark-plus me-1"></i> Formulario de Nueva Solicitud
</h6>

<form id="formSolicitud" class="row g-3">

<div class="col-md-4">
<label class="form-label">Fecha</label>
<input type="date" name="fecha" class="form-control form-control-sm" value="<?=$fechaDefault?>" required>
</div>

<div class="col-md-4">
<label class="form-label">Inicio</label>
<input type="time" name="hora_inicio" class="form-control form-control-sm" required>
</div>

<div class="col-md-4">
<label class="form-label">Término</label>
<input type="time" name="hora_termino" class="form-control form-control-sm" required>
</div>

<div class="col-md-6">
<label class="form-label">Empresa</label>
<select name="empresa" class="form-select form-select-sm" required readonly>
<option value="<?=$empresaId?>" selected><?= esc($empresas[$empresaId] ?? '') ?></option>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Proceso</label>
<select name="proceso" class="form-select form-select-sm" required>
<option value="">— Seleccionar —</option>
<?php foreach($procesos as $i=>$t): ?>
<option value="<?=$i?>"><?= esc($t) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Habilitación CEO</label>
<select name="habilitacion_ceo" class="form-select form-select-sm" required>
<option value="">— Seleccionar —</option>
<?php foreach($habCeo as $i=>$t): ?>
<option value="<?=$i?>"><?= esc($t) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-6">
<label class="form-label">Tipo Habilitación</label>
<select name="tipo_habilitacion" class="form-select form-select-sm" required>
<option value="">— Seleccionar —</option>
<option>Seguridad</option>
<option>Técnica</option>
<option>Ambos</option>
</select>
</div>

<div class="col-md-6">
<label class="form-label">UO</label>
<select name="uo" id="selUO" class="form-select form-select-sm" required readonly>
<option value="<?=$uoId?>" selected><?= esc($uo[$uoId] ?? '') ?></option>
</select>
</div>

<!-- Servicio -->
<div class="col-md-6">
  <label class="form-label">Servicio</label>
  <select name="servicio" id="selServicio" class="form-select form-select-sm" required>
    <option value="">— Seleccionar UO primero —</option>
  </select>
</div>

<!-- Responsable UO -->
<div class="col-md-6">
  <label class="form-label">Responsable UO</label>
  <select name="resp_uo" id="resp_uo" class="form-select form-select-sm">
    <option value="">— Seleccionar —</option>
    <?php foreach($responsables as $i=>$t): ?>
      <option value="<?=$i?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Responsable HSE -->
<div class="col-md-6">
  <label class="form-label">Responsable HSE</label>
  <select name="resp_hse" class="form-select form-select-sm">
    <option value="">— Seleccionar —</option>
    <?php foreach($resphse as $i=>$t): ?>
      <option value="<?=$i?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Responsable Línea -->
<div class="col-md-6">
  <label class="form-label">Responsable Línea</label>
  <select name="resp_linea" class="form-select form-select-sm">
    <option value="">— Seleccionar —</option>
    <?php foreach($resplinea as $i=>$t): ?>
      <option value="<?=$i?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="col-md-6">
<label class="form-label">Patio</label>
<select name="patio" class="form-select form-select-sm" required>
<option value="">— Seleccionar —</option>
<?php foreach($patios as $i=>$t): ?>
<option value="<?=$i?>"><?= esc($t) ?></option>
<?php endforeach; ?>
</select>
</div>

<!-- ================== Capacitación ================== -->
<div class="col-md-6 d-none" id="bloqueCharla">
  <label class="form-label">Capacitación</label>
  <select name="charla" class="form-select form-select-sm">
    <option value="">— Seleccionar —</option>
    <?php foreach($charlas as $i=>$t): ?>
      <option value="<?=$i?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- ================== Motivo Reinducción ================== -->
<div class="col-md-6 d-none" id="bloqueMotivoReinduccion">
  <label class="form-label">Motivo Reinducción</label>
  <select name="motivoreinduccion" class="form-select form-select-sm">
    <option value="">— Seleccionar —</option>
    <?php foreach($reinduccion as $i=>$t): ?>
      <option value="<?=$i?>"><?= esc($t) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- ================== Número Hallazgo ================== -->
<div class="col-md-6 d-none" id="bloqueNumeroHallazgo">
  <label class="form-label">N° Hallazgo</label>
  <input type="text" name="numerohallazgo" class="form-control form-control-sm">
</div>

<div class="col-12">
<label class="form-label">Observación</label>
<input type="text" name="observacion" class="form-control form-control-sm">
</div>

</form>
</div>
</div>
</div>

<!-- ============================================================
     PARTICIPANTES (SIN EXCEL)
============================================================ -->
<div class="col-lg-6">
<div class="card rounded-4 h-100">
<div class="card-body">

<h6 class="fw-bold mb-3 text-primary">
<i class="bi bi-people-fill me-1"></i> Participantes de la Cuadrilla
</h6>

<div class="table-responsive">
<table class="table table-bordered table-sm">
<thead>
<tr>
<th>RUT</th>
<th>Nombre</th>
<th>Apellido</th>
<th>Cargo</th>
</tr>
</thead>
<tbody>
<?php foreach ($participantes as $p): ?>
<tr>
<td><?= esc($p['rut']) ?></td>
<td><?= esc($p['nombre']) ?></td>
<td><?= esc($p['apellidos']) ?></td>
<td><?= esc($p['cargo']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>
</div>
</div>

</div>

<div class="text-end mb-5">
<button id="btnGuardar" class="btn btn-primary">
<i class="bi bi-save me-1"></i> Guardar Solicitud
</button>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ========================= Cascada UO -> Servicios + Resp UO =========================
const selUO = document.querySelector('select[name="uo"]');
const selSrv = document.getElementById('selServicio');
const selRespUO = document.getElementById('resp_uo');

if (selUO && selSrv) {
  selUO.addEventListener('change', async function () {
    const uoId = this.value;
    selSrv.innerHTML = '<option value="">Cargando servicios...</option>';

    if (!uoId) {
      selSrv.innerHTML = '<option value="">— Seleccionar UO primero —</option>';
      return;
    }

    try {
      const r = await fetch(`generar_permiso.php?action=servicios_by_uo&uo_id=${encodeURIComponent(uoId)}`);
      const data = await r.json();

      let opts = '<option value="">— Seleccionar —</option>';
      data.forEach(s => {
        opts += `<option value="${s.id}">${s.text}</option>`;
      });

      selSrv.innerHTML = opts || '<option value="">(Sin servicios)</option>';
    } catch (e) {
      console.error("❌ Error cargando servicios:", e);
      selSrv.innerHTML = '<option value="">Error al cargar servicios</option>';
    }
  });
}

if (selSrv && selRespUO) {
  selSrv.addEventListener('change', async function () {
    const servicioId = this.value;
    if (!servicioId) {
      selRespUO.value = "";
      return;
    }

    try {
      const r = await fetch(`generar_permiso.php?action=responsable_by_servicio&servicio_id=${encodeURIComponent(servicioId)}`);
      const data = await r.json();

      if (data.ok && data.id_arearesp) {
        selRespUO.value = data.id_arearesp.toString();
        selRespUO.classList.add('border-success');
        setTimeout(() => selRespUO.classList.remove('border-success'), 1500);
      } else {
        selRespUO.value = "";
      }
    } catch (e) {
      console.error("❌ Error cargando responsable UO:", e);
      selRespUO.value = "";
    }
  });
}
</script>

<script>
const participantes = <?= json_encode(
    array_map(fn($p) => [
        'rut'       => $p['rut'],
        'nombre'    => $p['nombre'],
        'apellidop' => $p['apellidos'],
        'apellidom' => '',
        'cargo'     => $p['cargo'],
    ], $participantes),
    JSON_UNESCAPED_UNICODE
); ?>;

document.getElementById('btnGuardar').onclick = async () => {
    const form = document.getElementById('formSolicitud');
    if (!form.checkValidity()) {
        alert('Completa los campos obligatorios.');
        return;
    }

    const fd = new FormData(form);
    fd.append('action', 'guardar_solicitud');
    fd.append('participantes', JSON.stringify(participantes));
    fd.append('programa', <?= (int)$programaId ?>); // 👈 ESTA LÍNEA ES CLAVE
    try {
        const resp = await fetch('generar_permiso.php', {
            method: 'POST',
            body: fd
        });
        const data = await resp.json();

        if (data.ok) {
            alert('✅ Permiso generado. Solicitud N° ' + data.nsolicitud);
            window.location.href = 'enviar_correo.php?nsolicitud=' + data.nsolicitud;
        } else {
            alert('⚠️ Error: ' + (data.error || 'Error desconocido'));
        }
    } catch (e) {
        alert('❌ Error de comunicación con el servidor.');
    }
};
</script>
<script>
const ocupacionesSemana = <?= json_encode($ocupaciones, JSON_UNESCAPED_UNICODE) ?>;
const bloqueos = <?= json_encode($bloqueos, JSON_UNESCAPED_UNICODE) ?>;

function motivoBloqueo(fecha) {
  for (const b of bloqueos) {
    if (fecha >= b.fecha_inicio && fecha <= b.fecha_fin) {
      return b.motivo || 'Bloqueado';
    }
  }
  return null;
}

const contSemana = document.getElementById('tablaSemana');
const lunesBase = new Date("<?= $lunes->format('Y-m-d') ?>T00:00:00");
const dias = ['Lun','Mar','Mié','Jue','Vie'];
let header = '<tr><th>Patio</th>';
const fechas = [];

for (let i = 0; i < 5; i++) {
  const d = new Date(lunesBase);
  d.setDate(lunesBase.getDate() + i);
  const yyyyMMdd = d.toISOString().split('T')[0];
  fechas.push(yyyyMMdd);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = d.getFullYear();
  header += `<th class="text-center">${dias[i]}<br>${dd}-${mm}-${yy}</th>`;
}
header += '</tr>';

let body = '';
<?php foreach ($patiosSemana as $p): ?>
  body += `<tr><td><?= esc($p['desc_patios']) ?></td>`;
  for (let f of fechas) {
    const mot = motivoBloqueo(f);
    if (mot) {
      body += `<td class="text-center bg-warning" title="Bloqueado: ${mot}">⛔</td>`;
      continue;
    }

    const ocupados = ocupacionesSemana.filter(o =>
      Number(o.id_patio) === <?= (int)$p['id'] ?> && o.fecha === f
    );

    if (ocupados.length > 0) {
      let tooltip = ocupados.map(o =>
        `#${o.nsolicitud} ${o.horainicio}-${o.horatermino}`
      ).join('\n');
      body += `<td class="ocupado text-center" title="${tooltip}">❌</td>`;
    } else {
      body += `<td class="text-center"></td>`;
    }
  }
  body += '</tr>';
<?php endforeach; ?>

contSemana.innerHTML =
  `<table class="table table-bordered table-sm semana-tabla">
     <thead>${header}</thead>
     <tbody>${body}</tbody>
   </table>`;
</script>
<script>
// ========================= Mostrar campos por Habilitación CEO =========================
const selHabCeo = document.querySelector('select[name="habilitacion_ceo"]');
const bloqueCharla = document.getElementById('bloqueCharla');
const bloqueMotivo = document.getElementById('bloqueMotivoReinduccion');
const bloqueHallazgo = document.getElementById('bloqueNumeroHallazgo');

if (selHabCeo) {
  selHabCeo.addEventListener('change', function () {
    const texto = this.options[this.selectedIndex].text.trim().toLowerCase();

    // ---- Capacitación ----
    if (texto === "capacitación" || texto === "capacitacion") {
      bloqueCharla.classList.remove('d-none');
      bloqueCharla.querySelector('select').setAttribute('required','required');
    } else {
      bloqueCharla.classList.add('d-none');
      bloqueCharla.querySelector('select').removeAttribute('required');
      bloqueCharla.querySelector('select').value = "";
    }

    // ---- Reinducción ----
    if (texto === "reinducción" || texto === "reinduccion") {
      bloqueMotivo.classList.remove('d-none');
      bloqueHallazgo.classList.remove('d-none');

      bloqueMotivo.querySelector('select').setAttribute('required','required');
      bloqueHallazgo.querySelector('input').setAttribute('required','required');
    } else {
      bloqueMotivo.classList.add('d-none');
      bloqueHallazgo.classList.add('d-none');

      bloqueMotivo.querySelector('select').removeAttribute('required');
      bloqueHallazgo.querySelector('input').removeAttribute('required');

      bloqueMotivo.querySelector('select').value = "";
      bloqueHallazgo.querySelector('input').value = "";
    }
  });
}
</script>
<script>
// ========================= AUTO CARGA SERVICIOS CUANDO UO VIENE FIJA =========================
document.addEventListener('DOMContentLoaded', async () => {
  const selUO = document.getElementById('selUO');
  const selSrv = document.getElementById('selServicio');

  if (!selUO || !selSrv) return;

  const uoId = selUO.value;
  if (!uoId) return;

  selSrv.innerHTML = '<option value="">Cargando servicios...</option>';

  try {
    const r = await fetch(`generar_permiso.php?action=servicios_by_uo&uo_id=${encodeURIComponent(uoId)}`);
    const data = await r.json();

    let opts = '<option value="">— Seleccionar —</option>';
    data.forEach(s => {
      opts += `<option value="${s.id}">${s.text}</option>`;
    });

    selSrv.innerHTML = opts || '<option value="">(Sin servicios para esta UO)</option>';
  } catch (e) {
    console.error('❌ Error cargando servicios:', e);
    selSrv.innerHTML = '<option value="">Error al cargar servicios</option>';
  }
});
</script>

</body>
</html>
