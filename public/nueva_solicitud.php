<?php
// --------------------------------------------------------------
// nueva_solicitud.php - Centro de Excelencia Operacional (CEO)
// Página para registrar nueva solicitud y cargar participantes.
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

/* Escapador seguro */
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
$idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$idRol     = (int)($_SESSION['auth']['id_rol'] ?? 0);



/* ===============================================================
   BLOQUE: guardar solicitud y participantes (AJAX POST)
   =============================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'guardar_solicitud') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $pdo->beginTransaction();

    $next = (int)$pdo->query("SELECT COALESCE(MAX(nsolicitud),0)+1 FROM ceo_solicitudes")->fetchColumn();
if ($next === 5030) {
    $next = 5528;
}
      
    $solicitante = $_SESSION['auth']['id'] ?? 'Desconocido';

    // Inserta la solicitud base
    $stmt = $pdo->prepare("
    INSERT INTO ceo_solicitudes
    (nsolicitud, fecha, horainicio, horatermino, contratista, proceso, habilitacionceo,
     tipohabilitacion, patio, resphse, resplinea, uo, servicio, responsable,
     observacion, charla, motivoreinduccion, numerohallazgo, solicitante, estado)
      VALUES
    (:nsolicitud,:fecha,:hini,:hfin,:empresa,:proceso,:habceo,:tipohab,:patio,
     :rhse,:rlinea,:uo,:servicio,:ruo,:obs,:charla,:motivoreinduccion,
     :numerohallazgo,:solicitante,'I')
    ");
    $stmt->execute([
      ':nsolicitud' => $next,
      ':fecha'      => $_POST['fecha'] ?? null,
      ':hini'       => $_POST['hora_inicio'] ?? null,
      ':hfin'       => $_POST['hora_termino'] ?? null,
      ':empresa'    => $_POST['empresa'] ?? null,
      ':proceso'    => $_POST['proceso'] ?? null,
      ':habceo'     => $_POST['habilitacion_ceo'] ?? null,
      ':tipohab'    => $_POST['tipo_habilitacion'] ?? null,
      ':patio'      => $_POST['patio'] ?? null,
      ':rhse'       => $_POST['resp_hse'] ?? null,
      ':rlinea'     => $_POST['resp_linea'] ?? null,
      ':uo'         => $_POST['uo'] ?? null,
      ':servicio'   => $_POST['servicio'] ?? null,
      ':ruo'        => $_POST['resp_uo'] ?? null,
      ':obs'        => $_POST['observacion'] ?? null,
      ':charla'     => $_POST['charla'] ?? null,
      ':motivoreinduccion' => $_POST['motivoreinduccion'] ?? null,
      ':numerohallazgo'    => $_POST['numerohallazgo'] ?? null,
      ':solicitante'=> $solicitante
    ]);

    // Actualiza calendario a OCUPADO si aplica
    $fecha = $_POST['fecha'] ?? null;
    $patio = $_POST['patio'] ?? null;
    $hIni  = $_POST['hora_inicio'] ?? null;
    $hFin  = $_POST['hora_termino'] ?? null;
    if ($fecha && $patio && $hIni && $hFin) {
      try {
        $upd = $pdo->prepare("
          UPDATE ceo_calendario
             SET estado = 'OCUPADO',
                 nsolicitud = :nsolicitud
           WHERE id_patio = :patio
             AND fecha = :fecha
             AND horainicio BETWEEN :hIni AND :hFin
             AND estado = 'DISPONIBLE'
        ");
        $upd->execute([
          ':nsolicitud' => $next,
          ':patio'      => $patio,
          ':fecha'      => $fecha,
          ':hIni'       => $hIni,
          ':hFin'       => $hFin,
        ]);
      } catch (Throwable $ex) {
        error_log("⚠️ Error actualizando calendario: ".$ex->getMessage());
      }
    }

    // Inserta participantes
    $rows = json_decode($_POST['participantes'] ?? '[]', true);
    // === VALIDACIÓN CRÍTICA: no permitir solicitud sin participantes ===
        if (empty($rows) || !is_array($rows)) {
            throw new RuntimeException('No se recibieron participantes desde el archivo Excel.');
        }
        
        // Contar participantes válidos (con RUT)
        $validCount = 0;
        foreach ($rows as $r) {
            if (!empty(trim($r['rut'] ?? ''))) {
                $validCount++;
            }
        }
        
        if ($validCount === 0) {
            throw new RuntimeException('La solicitud debe tener al menos un participante válido.');
        }

if (!empty($rows)) {

  // Buscar cargo
  $cargoSel = $pdo->prepare("
    SELECT id
    FROM ceo_cargo_contratistas
    WHERE LOWER(TRIM(cargo)) = LOWER(TRIM(:cargo))
    LIMIT 1
  ");

  // Insertar cargo si no existe
  $cargoIns = $pdo->prepare("
    INSERT INTO ceo_cargo_contratistas (cargo, estado)
    VALUES (:cargo, 'A')
  ");

  // Insert participante
  $partStmt = $pdo->prepare("
    INSERT INTO ceo_participantes_solicitud
    (id_solicitud, rut, nombre, apellidop, apellidom, id_cargo)
    VALUES (:id, :rut, :n, :ap, :am, :cargo)
  ");

  // WF (preparado una vez, optimización)
  $wfStmt = $pdo->prepare("
    SELECT wf 
    FROM ceo_reportewf
    WHERE rut_empleado = :rut
    LIMIT 1
  ");

  $updWf = $pdo->prepare("
    UPDATE ceo_participantes_solicitud
       SET wf = :wf
     WHERE id_solicitud = :id
       AND rut = :rut
  ");

  foreach ($rows as $r) {

    $rut = trim($r['rut'] ?? '');
    if ($rut === '') continue;

    // ---------------------------
    // Resolver cargo
    // ---------------------------
    $cargoNombre = trim($r['cargo'] ?? '');

    if ($cargoNombre === '') {
      $cargoNombre = 'SIN CARGO';
    }

    $cargoSel->execute([':cargo' => $cargoNombre]);
    $idCargo = $cargoSel->fetchColumn();

    if (!$idCargo) {
      // Crear cargo
      $cargoIns->execute([':cargo' => $cargoNombre]);
      $idCargo = (int)$pdo->lastInsertId();
    } else {
      $idCargo = (int)$idCargo;
    }

    // ---------------------------
    // Insert participante
    // ---------------------------
    $partStmt->execute([
      ':id'    => $next,
      ':rut'   => $rut,
      ':n'     => trim($r['nombre'] ?? ''),
      ':ap'    => trim($r['apellidop'] ?? ''),
      ':am'    => trim($r['apellidom'] ?? ''),
      ':cargo' => $idCargo
    ]);

    // ---------------------------
    // WF
    // ---------------------------
    $wfStmt->execute([':rut' => $rut]);
    $wfVal = $wfStmt->fetchColumn();

    if ($wfVal === false || $wfVal === null || trim((string)$wfVal) === '') {
      $estadoWf = 'No Autorizado';
    } else {
      $numero = (float)str_replace('%', '', trim((string)$wfVal));
      if ($numero == 100.0) {
        $estadoWf = 'Si';
      } elseif ($numero < 100.0) {
        $estadoWf = 'No';
      } else {
        $estadoWf = 'No Autorizado';
      }
    }

    $updWf->execute([
      ':wf'  => $estadoWf,
      ':id'  => $next,
      ':rut' => $rut
    ]);
  }
}


    $pdo->commit();
    echo json_encode(['ok'=>true,'nsolicitud'=>$next]);
 
    exit;
  } catch(Throwable $e){
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}


/* ===============================================================
   ENDPOINTS AJAX (GET)
   =============================================================== */
if (isset($_GET['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  // === NUEVO: validar si una fecha está bloqueada en ceo_bloqueo_horas ===
  if ($_GET['action'] === 'validar_fecha_bloqueo') {
    $fecha = $_GET['fecha'] ?? '';

    if (!$fecha) {
      echo json_encode(['ok'=>false,'bloqueado'=>false]);
      exit;
    }

    $stmt = $pdo->prepare("
      SELECT motivo
      FROM ceo_bloqueo_horas
      WHERE :f BETWEEN fecha_inicio AND fecha_fin
        AND estado = 'A'
      LIMIT 1
    ");
    $stmt->execute([':f' => $fecha]);
    $motivo = $stmt->fetchColumn();

    if ($motivo) {
      echo json_encode(['ok'=>true,'bloqueado'=>true,'motivo'=>$motivo]);
    } else {
      echo json_encode(['ok'=>true,'bloqueado'=>false]);
    }
    exit;
  }

  // Servicios por UO
  if ($_GET['action'] === 'servicios_by_uo') {
    $uoId = (int)($_GET['uo_id'] ?? 0);
    $out = [];
    if ($uoId > 0) {
      $st = $pdo->prepare("SELECT id, servicio FROM ceo_servicios WHERE uo = :uo ORDER BY servicio");
      $st->execute([':uo' => $uoId]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = ['id' => (int)$r['id'], 'text' => (string)$r['servicio']];
      }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Responsable UO por servicio (id_arearesp -> ceo_responsables.id)
  if ($_GET['action'] === 'responsable_by_servicio') {
    $servicioId = (int)($_GET['servicio_id'] ?? 0);
    $out = ['ok' => false];
    if ($servicioId > 0) {
      $st = $pdo->prepare("SELECT id_arearesp FROM ceo_servicios WHERE id = :id LIMIT 1");
      $st->execute([':id' => $servicioId]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if ($r && !empty($r['id_arearesp'])) {
        $out = ['ok' => true, 'id_arearesp' => (int)$r['id_arearesp']];
      }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* ===============================================================
   CATÁLOGOS + AGENDA SEMANAL
   =============================================================== */
//$where = ($idRol === 1 || $idEmpresa === 38) ? '1=1' : 'id = '.$idEmpresa;
// =========================
// Control de empresas visibles
// =========================
if (
    $idRol === 1 ||                // Administrador
    ($idRol === 5 && $idEmpresa === 39) // Rol 5 ENEL
) {
    $where = '1=1'; // Todas las empresas
} else {
    $where = 'id = '.$idEmpresa;   // Solo su empresa
}

function fetchPairs(PDO $pdo, string $sql, string $key='id', string $label='nombre'): array {
  $out=[]; $st=$pdo->query($sql);
  while($r=$st->fetch(PDO::FETCH_ASSOC)) $out[$r[$key]]=$r[$label];
  return $out;
}

$empresas       = fetchPairs($pdo,"SELECT id,nombre FROM ceo_empresas WHERE $where ORDER BY nombre");
$procesos       = fetchPairs($pdo,"SELECT id,desc_proceso AS nombre FROM ceo_procesos ORDER BY desc_proceso");
$habCeo         = fetchPairs($pdo,"SELECT id,desc_tipo AS nombre FROM ceo_habilitaciontipo ORDER BY desc_tipo");
$patios         = fetchPairs($pdo,"SELECT id,desc_patios AS nombre FROM ceo_patios ORDER BY desc_patios");
$uo             = fetchPairs($pdo,"SELECT id,desc_uo AS nombre FROM ceo_uo ORDER BY desc_uo");
$responsables   = fetchPairs($pdo,"SELECT id,responsable AS nombre FROM ceo_responsables ORDER BY responsable");
$resphse        = fetchPairs($pdo,"SELECT id,nombre FROM ceo_responsablehse ORDER BY nombre");
$resplinea      = fetchPairs($pdo,"SELECT id,CONCAT(nombre,' ',apellidop,' ',apellidom) AS nombre FROM ceo_evaluador WHERE tipo=2 ORDER BY nombre");
$charlas        = fetchPairs($pdo,"SELECT id, desc_charlas AS nombre FROM ceo_charlas ORDER BY desc_charlas");
$reinduccion    = fetchPairs($pdo,"SELECT id, reinduccion AS nombre FROM ceo_reinduccion ORDER BY reinduccion");

/* Patios para agenda semanal */
$patiosSemana = $pdo->query("SELECT id, desc_patios FROM ceo_patios ORDER BY desc_patios")->fetchAll(PDO::FETCH_ASSOC);

/* Bloqueos de horas (para agenda y validación visual) */
$bloqueos = [];
try {
  $stBloq = $pdo->query("
      SELECT fecha_inicio, fecha_fin, motivo
      FROM ceo_bloqueo_horas
      WHERE estado = 'A'
  ");
  $bloqueos = $stBloq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log("Error leyendo ceo_bloqueo_horas: ".$e->getMessage());
}

/* Semana actual (Lun - Vie) */
$hoy     = new DateTime();
$inicio = (clone $hoy)->modify('-14 days');
$fin    = (clone $hoy)->modify('+30 days');

/* Ocupaciones por día/patio (agregadas por solicitud) */
$ocupaciones = [];
try {
/*  $sql = "SELECT 
            c.id_patio,
            c.fecha,
            c.nsolicitud,
            MIN(s.horainicio)  AS horainicio,
            MAX(s.horatermino) AS horatermino,
            p.desc_patios
          FROM ceo_calendario c
          LEFT JOIN ceo_solicitudes s ON s.nsolicitud = c.nsolicitud
          LEFT JOIN ceo_patios p      ON p.id = c.id_patio
          WHERE c.fecha BETWEEN :l AND :v
            AND (c.estado <> 'DISPONIBLE' OR c.nsolicitud IS NOT NULL)
          GROUP BY c.id_patio, c.fecha, c.nsolicitud, p.desc_patios
          ORDER BY c.fecha, c.id_patio"; */
 $sql = "SELECT a.patio id_patio, a.fecha, a.nsolicitud, a.horainicio, a.horatermino, b.desc_patios, c.nombre AS empresa, CONCAT(d.nombres, ' ' ,d.apellidos) AS solicitante
FROM `ceo_solicitudes` a
INNER JOIN ceo_patios b ON b.id = a.patio
INNER JOIN ceo_empresas c ON c.id = a.contratista
INNER JOIN ceo_usuarios d ON d.id = a.solicitante
WHERE a.fecha BETWEEN :l AND :v
AND a.estado not in ('C', 'F')";
  $st = $pdo->prepare($sql);
$st->execute([
  ':l' => $inicio->format('Y-m-d'),
  ':v' => $fin->format('Y-m-d')
]);
  $ocupaciones = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log($e->getMessage());
}

$fechaDefault = $hoy->format('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Nueva Solicitud - <?=esc(APP_NAME)?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f7f9fc}
.topbar{background:#fff;border-bottom:1px solid #e3e6ea}
.brand-title{color:#0065a4;font-weight:600}
.card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05)}
.table thead{position:sticky;top:0;z-index:2}
.table th{background:#eaf2fb}
.semana-tabla th,.semana-tabla td{font-size:.8rem}
.ocupado{background:#dc3545!important;color:#fff;cursor:not-allowed}
/* === Validación visual por FONDO del campo === */
.campo-ok {
  background-color: #eaf6ff !important;   /* celeste MUY suave */
}

.campo-error {
  background-color: #fdecec !important;   /* rojo MUY suave */
}

/* === Overlay carga Excel === */
.excel-loading {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.85);
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
}

.excel-loading-content {
  text-align: center;
  padding: 20px 30px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.12);
}

</style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?=APP_LOGO?>" style="height:60px;" alt="Logo">
      <div><div class="brand-title h4 mb-0"><?=APP_NAME?></div><small><?=APP_SUBTITLE?></small></div>
    </div>
    <a href="solicitudes.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container">

  <!-- === Agenda semanal (colapsable) === -->
  <div class="card rounded-4 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
      <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-calendar-week me-2"></i>Agenda semanal patios</h6>
      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAgenda" aria-expanded="true">
        Mostrar / Ocultar
      </button>
    </div>
    <div id="collapseAgenda" class="collapse show">
      <div class="card-body p-2">
            <!-- Controles -->
  <div class="d-flex justify-content-between align-items-center mb-2">
    <button class="btn btn-sm btn-outline-secondary" id="btnPrev">⬅️</button>
    <div class="fw-semibold text-muted small" id="lblRango"></div>
    <button class="btn btn-sm btn-outline-secondary" id="btnNext">➡️</button>
  </div>
        <div id="tablaSemana" class="table-responsive"></div>
      </div>
    </div>
  </div>

  <!-- === Formulario + Participantes === -->
  <div class="row g-4 mb-4">
    <!-- Formulario -->
    <div class="col-lg-6">
      <div class="card h-100 rounded-4">
        <div class="card-body">
          <h6 class="fw-bold mb-3 text-primary">Formulario de Nueva Solicitud</h6>
          <form id="formSolicitud" class="row g-3">
            <div class="col-md-4"><label class="form-label">Fecha</label>
              <input type="date" name="fecha" class="form-control form-control-sm" value="<?=$fechaDefault?>" required>
            </div>
            <div class="col-md-4"><label class="form-label">Inicio</label><input type="time" name="hora_inicio" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><label class="form-label">Término</label><input type="time" name="hora_termino" class="form-control form-control-sm" required></div>
            <div class="col-md-6"><label class="form-label">Empresa</label><select name="empresa" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($empresas as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Proceso</label><select name="proceso" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($procesos as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Habilitación CEO</label><select name="habilitacion_ceo" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($habCeo as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Tipo Habilitación</label>
              <select name="tipo_habilitacion" class="form-select form-select-sm" required>
                <option value="">&nbsp;</option>
                <option>Seguridad</option><option>Técnica</option><option>Ambos</option>
              </select></div>
            <div class="col-md-6"><label class="form-label">Patio</label><select name="patio" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($patios as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Responsable HSE</label><select name="resp_hse" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($resphse as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Responsable Línea</label><select name="resp_linea" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($resplinea as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
             </select></div>
            <div class="col-md-6"><label class="form-label">UO</label><select name="uo" id="selUO" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($uo as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            <div class="col-md-6"><label class="form-label">Servicio</label><select name="servicio" id="selServicio" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option></select></div>
            <div class="col-md-6"><label class="form-label">Responsable UO</label><select name="resp_uo" id="resp_uo" class="form-select form-select-sm" required>
              <option value="">&nbsp;</option><?php foreach($responsables as $i=>$t):?><option value="<?=$i?>"><?=esc($t)?></option><?php endforeach;?>
            </select></div>
            
            <div class="col-md-6 d-none" id="bloqueCharla">
              <label class="form-label">Capacitación</label>
              <select name="charla" class="form-select form-select-sm">
                <option value="">&nbsp;</option>&nbsp;
                <?php foreach($charlas as $i=>$t): ?>
                  <option value="<?=$i?>"><?=esc($t)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Campo Motivo Reinducción -->
            <div class="col-md-6 d-none" id="bloqueMotivoReinduccion">
              <label class="form-label">Motivo Reinducción</label>
              <select name="motivoreinduccion" class="form-select form-select-sm">
                <option value="">&nbsp;</option>
                <?php foreach($reinduccion as $i=>$t): ?>
                  <option value="<?=$i?>"><?=esc($t)?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Campo Número Hallazgo -->
            <div class="col-md-6 d-none" id="bloqueNumeroHallazgo">
              <label class="form-label">N° Hallazgo</label>
              <input type="text" name="numerohallazgo" class="form-control form-control-sm">
            </div>

            <div class="col-12"><label class="form-label">Observación</label><input type="text" name="observacion" class="form-control form-control-sm"></div>
          </form>
        </div>
      </div>
    </div>

    <!-- Participantes Excel -->
    <div class="col-lg-6">
      <div class="card h-100 rounded-4">
        <div class="card-body">
            
<h6 class="fw-bold mb-3 text-primary d-flex justify-content-between align-items-center">
  Participantes desde Excel
  <div class="d-flex gap-2">
    <button id="btnTablaCompleta" type="button" class="btn btn-outline-secondary btn-sm">
      Vista Completa
    </button>
    <button id="btnTablaCondensada" type="button" class="btn btn-outline-secondary btn-sm">
      Vista Condensada
    </button>
    <button id="btnLimpiarExcel" type="button" class="btn btn-outline-danger btn-sm" title="Limpiar datos cargados">
      <i class="bi bi-trash3 me-1"></i>Limpiar carga
    </button>
  </div>
</h6>
 <!-- Overlay lectura Excel -->
<div id="excelLoading" class="excel-loading d-none">
  <div class="excel-loading-content">
    <div class="spinner-border text-primary mb-2" role="status"></div>
    <div class="fw-semibold">Leyendo archivo Excel…</div>
    <div class="small text-muted">Por favor espera</div>
  </div>
</div>
         
<div id="tablaParticipantes" class="table-responsive">
    <div class="text-muted py-4 text-center">
        <i class="bi bi-info-circle me-2"></i>Aún no se ha cargado un archivo Excel.<br>

        <button type="button" class="btn btn-sm btn-outline-success mt-2"
                onclick="document.getElementById('inputExcel').click()">
            <i class="bi bi-folder2-open me-1"></i> Seleccionar archivo
        </button>
    </div>
</div>

<!--  🔥 INPUT MOVIDO FUERA DEL DIV QUE SE BORRA 🔥 -->
<input type="file" id="inputExcel" accept=".xlsx,.xls,.csv" style="display:none;">

          
        </div>
      </div>
    </div>
  </div>

  <div class="text-end mb-5">
    <button id="btnGuardar" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar Solicitud</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/ceo.noetica.cl/public/js/working.js"></script>
<script>
// ========================= Agenda semanal =========================
const ocupacionesSemana = <?= json_encode($ocupaciones, JSON_UNESCAPED_UNICODE) ?>;
const bloqueos = <?= json_encode($bloqueos, JSON_UNESCAPED_UNICODE) ?>; // bloqueos desde ceo_bloqueo_horas
// === Rango de fechas (2 semanas atrás, 1 mes adelante) ===
const hoy = new Date();

const rangoInicio = new Date(hoy);
rangoInicio.setDate(hoy.getDate() - 14);

const rangoFin = new Date(hoy);
rangoFin.setDate(hoy.getDate() + 30);

function generarFechas(inicio, fin) {
  const fechas = [];
  const d = new Date(inicio);
  while (d <= fin) {
    fechas.push(new Date(d));
    d.setDate(d.getDate() + 1);
  }
  return fechas;
}

const todasLasFechas = generarFechas(rangoInicio, rangoFin);
// Índice inicial: hoy
let indiceVista = todasLasFechas.findIndex(d =>
  d.toISOString().split('T')[0] === hoy.toISOString().split('T')[0]
);

// Fallback de seguridad
if (indiceVista < 0) indiceVista = 0;

// Helper: retorna motivo si la fecha está bloqueada
function motivoBloqueo(fecha) {
  if (!bloqueos || !fecha) return null;

  const f = new Date(fecha + 'T00:00:00');

  for (const b of bloqueos) {
    const ini = new Date(b.fecha_inicio.replace(' ', 'T'));
    const fin = new Date(b.fecha_fin.replace(' ', 'T'));

    // normalizar a rango diario
    ini.setHours(0,0,0,0);
    fin.setHours(23,59,59,999);

    if (f >= ini && f <= fin) {
      return b.motivo || 'Bloqueo';
    }
  }
  return null;
}


const contSemana = document.getElementById('tablaSemana');
const diasLabel = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

function renderAgenda() {
  const diasVista = todasLasFechas.slice(indiceVista, indiceVista + 5);

  let header = '<tr><th>Patio</th>';
  diasVista.forEach(d => {
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    header += `<th class="text-center">
      ${diasLabel[d.getDay()]}<br>${dd}-${mm}-${yy}
    </th>`;
  });
  header += '</tr>';

  let body = '';

  <?php foreach ($patiosSemana as $p): ?>
    body += `<tr><td><?= esc($p['desc_patios']) ?></td>`;
    diasVista.forEach(d => {
      const fechaISO = d.toISOString().split('T')[0];

      const mot = motivoBloqueo(fechaISO);
      if (mot) {
        body += `<td class="text-center bg-warning" title="Bloqueado: ${mot}">⛔</td>`;
        return;
      }

      const ocupados = ocupacionesSemana.filter(o =>
        Number(o.id_patio) === <?= (int)$p['id'] ?> &&
        o.fecha === fechaISO
      );

      if (ocupados.length) {
        body += `<td class="ocupado text-center">❌</td>`;
      } else {
        body += `<td class="text-center">
          <input type="checkbox"
                 data-patio="<?= (int)$p['id'] ?>"
                 data-fecha="${fechaISO}">
        </td>`;
      }
    });
    body += '</tr>';
  <?php endforeach; ?>

  contSemana.innerHTML = `
    <table class="table table-bordered table-sm text-center semana-tabla">
      <thead>${header}</thead>
      <tbody>${body}</tbody>
    </table>
  `;
}

document.getElementById('btnPrev').onclick = () => {
  if (indiceVista > 0) {
    indiceVista--;
    renderAgenda();
  }
};

document.getElementById('btnNext').onclick = () => {
  if (indiceVista + 5 < todasLasFechas.length) {
    indiceVista++;
    renderAgenda();
  }
};

// Render inicial
renderAgenda();
// ========================= Validar fecha bloqueada (al cambiar y al cargar) =========================
const inputFecha = document.querySelector('input[name="fecha"]');

async function validarFechaBloqueada(el) {
  const fecha = el.value;
  if (!fecha) return;

  try {
    const r = await fetch(`nueva_solicitud.php?action=validar_fecha_bloqueo&fecha=${encodeURIComponent(fecha)}`);
    const data = await r.json();
    if (data.ok && data.bloqueado) {
      alert("⚠️ Esta fecha no puede seleccionarse.\nMotivo: " + data.motivo);
      el.value = "";
      el.classList.add('border-danger');
      setTimeout(() => el.classList.remove('border-danger'), 2000);
    }
  } catch (e) {
    console.error("Error validando fecha:", e);
  }
}

if (inputFecha) {
  // Validar cuando el usuario cambia la fecha
  inputFecha.addEventListener('change', function() {
    validarFechaBloqueada(this);
  });

  // 🔹 Validar también la fecha por defecto al cargar la página
  if (inputFecha.value) {
    validarFechaBloqueada(inputFecha);
  }
}


// ========================= Cascada UO -> Servicios + Resp UO =========================
const selUO = document.getElementById('selUO');
const selSrv = document.getElementById('selServicio');
const selRespUO = document.getElementById('resp_uo');

if (selUO && selSrv) {
  selUO.addEventListener('change', async function () {
    const uoId = this.value;
    selSrv.innerHTML = '<option value="">Cargando servicios...</option>';
    if (!uoId) { selSrv.innerHTML = '<option value="">— Seleccionar UO primero —</option>'; return; }
    try {
      const r = await fetch(`nueva_solicitud.php?action=servicios_by_uo&uo_id=${encodeURIComponent(uoId)}`);
      const data = await r.json();
      let opts = '<option value="">&nbsp;</option>';
      data.forEach(s => { opts += `<option value="${s.id}">${s.text}</option>`; });
      selSrv.innerHTML = opts || '<option value="">(Sin servicios para esta UO)</option>';
    } catch (e) {
      console.error("❌ Error al cargar servicios:", e);
      selSrv.innerHTML = '<option value="">Error al cargar servicios</option>';
    }
  });
}

if (selSrv && selRespUO) {
  selSrv.addEventListener('change', async function () {
    const servicioId = this.value;
    if (!servicioId) { selRespUO.value = ""; return; }
    try {
      const resp = await fetch(`nueva_solicitud.php?action=responsable_by_servicio&servicio_id=${encodeURIComponent(servicioId)}`);
      const data = await resp.json();
      if (data.ok && data.id_arearesp) {
        selRespUO.value = data.id_arearesp.toString();
        selRespUO.classList.add('border-success');
        setTimeout(() => selRespUO.classList.remove('border-success'), 1800);
      } else {
        selRespUO.value = "";
      }
    } catch (err) {
      console.error("❌ Error al cargar responsable UO:", err);
      selRespUO.value = "";
    }
  });
}

// ========================= Validación RUT y helpers =========================
function validarRut(rutRaw) {
  if (!rutRaw) return false;

  // Limpia todo menos números y K/k
  let rut = String(rutRaw).replace(/[^0-9kK]/g, '').toUpperCase();

  // Si tiene menos de 2 caracteres, no es válido
  if (rut.length < 2) return false;

  // Separa cuerpo y dígito verificador
  const cuerpo = rut.slice(0, -1);
  const dv = rut.slice(-1);

  // Verifica que el cuerpo sea numérico
  if (!/^\d+$/.test(cuerpo)) return false;

  // Calcula DV con algoritmo módulo 11
  let suma = 0;
  let multiplo = 2;
  for (let i = cuerpo.length - 1; i >= 0; i--) {
    suma += parseInt(cuerpo.charAt(i), 10) * multiplo;
    multiplo = multiplo === 7 ? 2 : multiplo + 1;
  }

  const resto = 11 - (suma % 11);
  let dvEsperado = resto === 11 ? '0' : resto === 10 ? 'K' : String(resto);

  return dvEsperado === dv;
}

function filtrarRutsInvalidos(rows){
  const hdr=(rows[0]||[]).map(c=>String(c||'').toUpperCase().trim());
  const idx=hdr.findIndex(h=>h.includes('RUT'));
  const valid=[],invalid=[];
  for(let i=1;i<rows.length;i++){
    const rut=String(rows[i][idx]||'').trim();
    if(rut==='')continue;
    if(validarRut(rut))valid.push(rows[i]);else invalid.push(rut);
  }
  return{validRows:valid,invalids:invalid};
}
function trimEmptyRows(rows){return rows.filter(r=>Array.isArray(r)&&r.some(c=>String(c||'').trim()!==''));}
function looksLikeHeader(row){return(row||[]).some(c=>/RUT|NOMBRE|CARGO|PATERNO|MATERNO/i.test(c));}

// ========================= Carga Excel =========================
const inputExcel = document.getElementById('inputExcel');
const tabla      = document.getElementById('tablaParticipantes');
const btnLimpiarExcel = document.getElementById('btnLimpiarExcel');
let _rows = [], _rutError = false;
// === Overlay carga Excel ===
const excelLoading = document.getElementById('excelLoading');

function mostrarCargaExcel() {
  excelLoading.classList.remove('d-none');
}

function ocultarCargaExcel() {
  excelLoading.classList.add('d-none');
}

// Helper para mostrar mensaje + botón seleccionar archivo
function mostrarMensajeExcel(htmlMensaje) {
  tabla.innerHTML = `
    <div class="py-3 text-center">
      ${htmlMensaje}
      <div class="mt-3">
<button type="button" class="btn btn-sm btn-outline-success"
        onclick="document.getElementById('inputExcel').click()">
    <i class="bi bi-folder2-open me-1"></i> Seleccionar archivo
</button>

      </div>
    </div>
  `;
}
function renderEstadoInicialExcel() {
  mostrarMensajeExcel(`
    <div class="text-muted">
      <i class="bi bi-info-circle me-2"></i>
      Aún no se ha cargado un archivo Excel.<br>
    </div>
  `);
}

function limpiarCargaExcel() {
  _rows = [];
  _rutError = false;
  inputExcel.value = "";

  ocultarCargaExcel();
  renderEstadoInicialExcel();
}
if (btnLimpiarExcel) {
  btnLimpiarExcel.addEventListener('click', () => {
    const hayDatosCargados = Array.isArray(_rows) && _rows.length > 0;
    const hayArchivo = inputExcel.value !== '';

    if (!hayDatosCargados && !hayArchivo) {
      renderEstadoInicialExcel();
      return;
    }

    if (!confirm('¿Deseas limpiar los datos cargados y volver a seleccionar otro archivo Excel?')) {
      return;
    }

    limpiarCargaExcel();
  });
}
inputExcel.addEventListener('change', async () => {
  const file = inputExcel.files?.[0];
  if (!file) return;

  mostrarCargaExcel();

  try {
    const fd = new FormData();
    fd.append('excel', file);

    const resp = await fetch('leer_excel.php?_=' + Date.now(), {
      method: 'POST',
      body: fd
    });

    const raw = await resp.text();
    let data;

    try {
      data = JSON.parse(raw);
    } catch (e) {
      console.error('NO-JSON desde leer_excel.php:\n', raw);
      _rows = [];
      _rutError = true;
      inputExcel.value = "";

      mostrarMensajeExcel(`
        <div class="alert alert-danger small">
          ❌ Error al leer el archivo Excel.<br>
          <strong>Detalle técnico:</strong>
          <pre style="white-space:pre-wrap;max-height:180px;overflow:auto;background:#f8f9fa;border:1px solid #ccc;padding:6px;">${raw}</pre>
        </div>
      `);
      return;
    }

    if (!data || data.ok !== true) {
      _rows = [];
      _rutError = true;
      inputExcel.value = "";

      mostrarMensajeExcel(`
        <div class="alert alert-danger small">
          ❌ Error al procesar el archivo.<br>
          ${(data && data.error) ? data.error : ''}
        </div>
      `);
      return;
    }

    _rows = data.rows || [];
    if (!_rows.length) {
      _rows = [];
      _rutError = true;
      inputExcel.value = "";

      mostrarMensajeExcel(`
        <div class="alert alert-danger small">
          El archivo no contiene filas válidas.
        </div>
      `);
      return;
    }

    const hdr = (_rows[0] || []).map(c => String(c || '').toUpperCase().trim());
    if (!hdr.some(h => h.includes('RUT'))) {
      _rows = [];
      _rutError = true;
      inputExcel.value = "";

      mostrarMensajeExcel(`
        <div class="alert alert-danger small">
          ❌ El archivo no contiene una columna de RUT.
        </div>
      `);
      return;
    }

    const { validRows, invalids } = filtrarRutsInvalidos(_rows);

    if (invalids.length > 0 || validRows.length === 0) {
      const lista = invalids.length ? invalids.join(', ') : '(todos inválidos)';
      _rows = [];
      _rutError = true;
      inputExcel.value = "";

      mostrarMensajeExcel(`
        <div class="alert alert-danger small">
          ❌ Se encontraron RUT inválidos.<br>
          <strong>Corrige y vuelve a cargar.</strong><br>
          RUT con error: ${lista}
        </div>
      `);
      return;
    }

    // ✅ todo OK
    _rutError = false;
    _rows = [_rows[0], ...validRows];
    renderTablaCondensada(_rows);

  } catch (e) {
    console.error(e);
    _rows = [];
    _rutError = true;
    inputExcel.value = "";

    mostrarMensajeExcel(`
      <div class="alert alert-danger small">
        ❌ Error leyendo archivo desde el servidor.
      </div>
    `);
  } finally {
    ocultarCargaExcel();
  }
});


function renderTablaCondensada(rows){
  const clean=trimEmptyRows(rows);
  if(!clean.length){tabla.innerHTML='<div class="text-muted text-center py-4">Sin datos</div>';return;}
  const hdr=(clean[0]||[]).map(c=>String(c||'').toUpperCase().trim());
  const idx={rut:hdr.findIndex(h=>h.includes('RUT')),nombre:hdr.findIndex(h=>h.includes('NOMBRE')),
    ap1:hdr.findIndex(h=>h.includes('PATERNO')),ap2:hdr.findIndex(h=>h.includes('MATERNO')),cargo:hdr.findIndex(h=>h.includes('CARGO'))};
  const start=looksLikeHeader(clean[0])?1:0;
  let html='<table class="table table-bordered table-sm"><thead><tr><th>RUT</th><th>Nombre</th><th>1° Apellido</th><th>2° Apellido</th><th>Cargo</th></tr></thead><tbody>';
  for(let i=start;i<clean.length;i++){const r=clean[i]||[];html+=`<tr><td>${r[idx.rut]||''}</td><td>${r[idx.nombre]||''}</td><td>${r[idx.ap1]||''}</td><td>${r[idx.ap2]||''}</td><td>${r[idx.cargo]||''}</td></tr>`;}
  html+='</tbody></table>';tabla.innerHTML=html;
}
document.getElementById('btnTablaCondensada').onclick=()=>renderTablaCondensada(_rows);
document.getElementById('btnTablaCompleta').onclick=()=>{
  const clean=trimEmptyRows(_rows);if(!clean.length){tabla.innerHTML='<div class="text-muted text-center py-4">Sin datos</div>';return;}
  const cols=Math.max(...clean.map(r=>r.length));let html='<table class="table table-bordered table-sm"><thead><tr>';
  for(let i=0;i<cols;i++)html+='<th>'+String.fromCharCode(65+i)+'</th>';html+='</tr></thead><tbody>';
  clean.forEach(r=>{html+='<tr>'+r.map(c=>`<td>${c||''}</td>`).join('')+'</tr>';});html+='</tbody></table>';tabla.innerHTML=html;
};

// ========================= Guardar =========================
document.getElementById('btnGuardar').onclick = async () => {

  const btn = document.getElementById('btnGuardar');
  const form = document.getElementById('formSolicitud');

  // === VALIDACIONES (SIN OVERLAY) ===
  if (!form.checkValidity()) {
    alert('Completa todos los campos obligatorios.');
    return;
  }
  if (_rutError) {
    alert('Archivo con RUT inválidos. Corrige antes de guardar.');
    return;
  }
  if ((_rows || []).length <= 1) {
    alert('No hay participantes válidos.');
    return;
  }

  // === DESDE AQUÍ SÍ MOSTRAMOS "TRABAJANDO" ===
  btn.disabled = true;
  Working.show('Guardando solicitud…');

  const fd = new FormData(form);
  fd.append('action', 'guardar_solicitud');

  const clean = _rows.filter(r =>
    Array.isArray(r) && r.some(c => String(c || '').trim() !== '')
  );

  const hdr = (clean[0] || []).map(c => String(c || '').toUpperCase().trim());
  const start = looksLikeHeader(clean[0]) ? 1 : 0;

  const idx = {
    rut: hdr.findIndex(h => h.includes('RUT')),
    nombre: hdr.findIndex(h => h.includes('NOMBRE')),
    apellidop: hdr.findIndex(h => h.includes('PATERNO')),
    apellidom: hdr.findIndex(h => h.includes('MATERNO')),
    cargo: hdr.findIndex(h => h.includes('CARGO'))
  };

  const participantes = [];
  for (let i = start; i < clean.length; i++) {
    const r = clean[i] || [];
    participantes.push({
      rut: r[idx.rut] || '',
      nombre: r[idx.nombre] || '',
      apellidop: r[idx.apellidop] || '',
      apellidom: r[idx.apellidom] || '',
      cargo: r[idx.cargo] || ''
    });
  }

  fd.append('participantes', JSON.stringify(participantes));

  try {
    const resp = await fetch('nueva_solicitud.php', {
      method: 'POST',
      body: fd
    });

    const data = await resp.json();

    if (data.ok) {
      alert('✅ Solicitud guardada N° ' + data.nsolicitud);
      window.location.href = 'enviar_correo.php?nsolicitud=' + data.nsolicitud;
      return; // dejamos el overlay, la página cambia
    } else {
      alert('⚠️ Error al guardar: ' + (data.error || 'Error desconocido'));
    }

  } catch (e) {
    alert('❌ Error al conectar con el servidor.');
  } finally {
    // === ASEGURA LIMPIEZA ===
    Working.hide();
    btn.disabled = false;
  }
};

// ========================= Sincronizar Inicio / Término (solo selección sin entrada manual) =========================

// Genera horas 07:00 a 22:00 cada 30 minutos
function generarHoras() {
  const horas = [];
  let h = 7, m = 0;
  while (h < 22 || (h === 22 && m === 0)) {
    horas.push(`${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`);
    m += 30;
    if (m === 60) { m = 0; h++; }
  }
  return horas;
}

// Referencias
const inputInicio  = document.querySelector('input[name="hora_inicio"]');
const inputTermino = document.querySelector('input[name="hora_termino"]');

// Crear select visibles (en vez de permitir input manual)
const selInicio  = document.createElement("select");
const selTermino = document.createElement("select");

selInicio.className  = "form-select form-select-sm";
selTermino.className = "form-select form-select-sm";

selInicio.required = true;
selTermino.required = true;

inputInicio.style.display = "none";
inputTermino.style.display = "none";

inputInicio.parentNode.insertBefore(selInicio, inputInicio);
inputTermino.parentNode.insertBefore(selTermino, inputTermino);

// Cargar horas en select Inicio
function cargarHorasInicio() {
  const horas = generarHoras();
  selInicio.innerHTML = '<option value="">&nbsp;</option>' +
    horas.map(h => `<option value="${h}">${h}</option>`).join('');
}

// Cargar horas válidas en select Término
function cargarHorasTermino(horaInicio) {
  const horas = generarHoras();
  let filtradas = horas;

  if (horaInicio) {
    filtradas = horas.filter(h => h >= horaInicio);
  }

  selTermino.innerHTML = '<option value="">&nbsp;</option>' +
    filtradas.map(h => `<option value="${h}">${h}</option>`).join('');
}

// Sincronizar valores reales ocultos
function syncHidden() {
  inputInicio.value  = selInicio.value;
  inputTermino.value = selTermino.value;
}

// Eventos
selInicio.addEventListener("change", () => {
  cargarHorasTermino(selInicio.value);
  syncHidden();
});

selTermino.addEventListener("change", () => {
  syncHidden();
});

// Inicialización
cargarHorasInicio();
cargarHorasTermino("");
syncHidden();

// ========================= Mostrar campo Capacitación =========================
const selHabCeo = document.querySelector('select[name="habilitacion_ceo"]');
const bloqueCharla = document.getElementById('bloqueCharla');

selHabCeo.addEventListener('change', function () {
    const texto = this.options[this.selectedIndex].text.trim().toLowerCase();

    if (texto === "capacitación" || texto === "capacitacion") {
        bloqueCharla.classList.remove('d-none');
        bloqueCharla.querySelector('select').setAttribute('required', 'required');
    } else {
        bloqueCharla.classList.add('d-none');
        bloqueCharla.querySelector('select').removeAttribute('required');
        bloqueCharla.querySelector('select').value = "";
    }
});

// ========================= Mostrar campos Reinducción =========================
const bloqueMotivo = document.getElementById('bloqueMotivoReinduccion');
const bloqueHallazgo = document.getElementById('bloqueNumeroHallazgo');

selHabCeo.addEventListener('change', function () {
    const texto = this.options[this.selectedIndex].text.trim().toLowerCase();

    // Mostrar Capacitación
    if (texto === "capacitación" || texto === "capacitacion") {
        bloqueCharla.classList.remove('d-none');
        bloqueCharla.querySelector('select').setAttribute('required','required');
    } else {
        bloqueCharla.classList.add('d-none');
        bloqueCharla.querySelector('select').removeAttribute('required');
        bloqueCharla.querySelector('select').value = "";
    }

    // Mostrar Reinducción
    if (texto === "reinducción" || texto === "reinduccion") {
        bloqueMotivo.classList.remove('d-none');
        bloqueHallazgo.classList.remove('d-none');

        bloqueMotivo.querySelector('select').setAttribute('required','required');
        bloqueHallazgo.querySelector('input').setAttribute('required','required');
    } 
    else {
        bloqueMotivo.classList.add('d-none');
        bloqueHallazgo.classList.add('d-none');

        bloqueMotivo.querySelector('select').removeAttribute('required');
        bloqueHallazgo.querySelector('input').removeAttribute('required');

        bloqueMotivo.querySelector('select').value = "";
        bloqueHallazgo.querySelector('input').value = "";
    }
});

// === Validación visual de campos ===
(function () {
  const form = document.getElementById('formSolicitud');
  if (!form) return;

  const campos = form.querySelectorAll('input, select, textarea');

  function evaluarCampo(el) {
    const requerido = el.hasAttribute('required');
    const valor = (el.value || '').trim();

    el.classList.remove('campo-ok', 'campo-error');

    if (!requerido) return;

    if (valor === '') {
      el.classList.add('campo-error');
    } else {
      el.classList.add('campo-ok');
    }
  }

  campos.forEach(el => {
    // evaluación inicial
    evaluarCampo(el);

    // escuchar cambios
    el.addEventListener('change', () => evaluarCampo(el));
    el.addEventListener('keyup', () => evaluarCampo(el));
  });

})();

// === Validación visual SOLO POR FONDO ===
(function () {
  const form = document.getElementById('formSolicitud');
  if (!form) return;

  const campos = form.querySelectorAll('input, select, textarea');

  function evaluarCampo(el) {
    const requerido = el.hasAttribute('required');
    const valor = (el.value || '').trim();

    el.classList.remove('campo-ok', 'campo-error');

    if (!requerido) return;

    if (valor === '') {
      el.classList.add('campo-error'); // fondo rojo suave
    } else {
      el.classList.add('campo-ok');    // fondo celeste suave
    }
  }

  campos.forEach(el => {
    evaluarCampo(el); // al cargar

    el.addEventListener('change', () => evaluarCampo(el));
    el.addEventListener('keyup', () => evaluarCampo(el));
  });
})();
</script>

<!-- =========================================================
     OVERLAY GLOBAL "TRABAJANDO"
========================================================= -->
<div id="workingOverlay" style="
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(255,255,255,0.8);
    z-index:2000;
    align-items:center;
    justify-content:center;
">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <div id="workingMessage" class="fw-semibold text-primary">
            Trabajando...
        </div>
    </div>
</div>
</body>
</html>

