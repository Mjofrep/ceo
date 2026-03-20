<?php
// /public/mant_calendario.php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
require_once '../config/db.php';
require_once '../config/functions.php';
require_once __DIR__ . '/../config/app.php';

// Fallbacks por si no están definidas las constantes de marca
if (!defined('APP_NAME'))     define('APP_NAME', 'CEONext');
if (!defined('APP_SUBTITLE')) define('APP_SUBTITLE', 'Centro de Excelencia Operacional — Enel');
if (!defined('APP_LOGO'))     define('APP_LOGO',  '/ceo/public/assets/img/logo.png');

$pdo = db();

/* ─────────────────────────────────────────────────────────────
   Endpoint liviano: check de existencia para un año (AJAX JSON)
   ──────────────────────────────────────────────────────────── */
if (isset($_GET['check']) && isset($_GET['anio'])) {
    header('Content-Type: application/json; charset=utf-8');
    $anio = (int)$_GET['anio'];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ceo_calendario WHERE YEAR(fecha)=:anio");
        $stmt->execute([':anio' => $anio]);
        $count = (int)$stmt->fetchColumn();

        $p = $pdo->query("SELECT COUNT(*) FROM ceo_patios")->fetchColumn();
        echo json_encode(['ok' => true, 'existen' => $count, 'patios' => (int)$p]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ─────────────────────────────────────────────────────────────
   POST: generar calendario
   ──────────────────────────────────────────────────────────── */
$success = '';
$errMsg  = '';
$anioSel = (int)($_POST['anio'] ?? date('Y'));
$reemplazar = isset($_POST['reemplazar']) && $_POST['reemplazar'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'generar') {
    try {
        // Cantidad existente para decidir si borrar
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ceo_calendario WHERE YEAR(fecha)=:anio");
        $stmt->execute([':anio' => $anioSel]);
        $existen = (int)$stmt->fetchColumn();

        if ($existen > 0 && $reemplazar) {
            $del = $pdo->prepare("DELETE FROM ceo_calendario WHERE YEAR(fecha)=:anio");
            $del->execute([':anio' => $anioSel]);
        }

        // Obtener patios
        $patios = $pdo->query("SELECT id, desc_patios FROM ceo_patios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$patios) {
            throw new RuntimeException('No hay patios definidos (tabla ceo_patios).');
        }

        // Preparar inserción
        set_time_limit(0);
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT IGNORE INTO ceo_calendario (fecha, estado, horainicio, id_patio)
            VALUES (:fecha, 'DISPONIBLE', :horainicio, :id_patio)
        ");

        // Slots cada 30 min desde 07:00 hasta 22:00 (INCLUSIVE)
        $slots = [];
        for ($t = strtotime('07:00'); $t <= strtotime('22:00'); $t += 30*60) {
            $slots[] = date('H:i:s', $t);
        }

        // Recorrer días del año
        $fecha = new DateTime("$anioSel-01-01");
        $to    = new DateTime("$anioSel-12-31");

        while ($fecha <= $to) {
            $f = $fecha->format('Y-m-d');
            foreach ($patios as $p) {
                foreach ($slots as $h) {
                    $ins->execute([
                        ':fecha'      => $f,
                        ':horainicio' => $h,
                        ':id_patio'   => (int)$p['id']
                    ]);
                }
            }
            $fecha->modify('+1 day');
        }

        $pdo->commit();

        $txt = $reemplazar
            ? "Se REEMPLAZÓ y generó nuevamente el calendario del año $anioSel."
            : "Calendario del año $anioSel generado correctamente.";
        $success = "✅ $txt";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errMsg = $e->getMessage();
    }
}

/* ─────────────────────────────────────────────────────────────
   Determinar fecha a visualizar
   - Si el año seleccionado = año actual → hoy
   - Si no, 1 de enero del año seleccionado
   - Permite override por selector "ver_fecha"
   ──────────────────────────────────────────────────────────── */
$hoy = new DateTime();
$fechaVer = $_GET['ver_fecha'] ?? null;

if ($fechaVer) {
    // validación simple
    $dt = DateTime::createFromFormat('Y-m-d', $fechaVer);
    $fechaTarget = $dt && $dt->format('Y-m-d') === $fechaVer ? $fechaVer : $hoy->format('Y-m-d');
} else {
    $fechaTarget = ((int)$hoy->format('Y') === $anioSel)
        ? $hoy->format('Y-m-d')
        : "$anioSel-01-01";
}

/* ─────────────────────────────────────────────────────────────
   Traer filas para la fecha a visualizar
   ──────────────────────────────────────────────────────────── */
$list = [];
try {
    $q = $pdo->prepare("
        SELECT c.fecha, c.horainicio, c.estado, c.id_patio, p.desc_patios, c.nsolicitud
        FROM ceo_calendario c
        LEFT JOIN ceo_patios p ON p.id = c.id_patio
        WHERE c.fecha = :f
        ORDER BY c.horainicio, p.desc_patios
    ");
    $q->execute([':f' => $fechaTarget]);
    $list = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errMsg = $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mantención Calendario - <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .topbar{background:#fff;border-bottom:1px solid #e3e6ea}
    .brand-title{color:#0065a4;font-weight:600}
    .card{border:none;box-shadow:0 2px 4px rgba(0,0,0,.05)}
    .table thead{position:sticky;top:0;z-index:2}
    .table th{background:#eaf2fb;font-size:.85rem}
    .sticky-actions{position:sticky;top:0;z-index:3;background:#fff;border-bottom:1px solid #e9ecef}
  </style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= esc(APP_NAME) ?></div>
        <small class="text-secondary"><?= esc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<div class="container-fluid px-4">
  <!-- Acciones -->
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <form id="frmGen" method="post" class="row g-3 align-items-end">
        <input type="hidden" name="accion" value="generar">
        <div class="col-md-2">
          <label class="form-label">Año</label>
          <select name="anio" id="anio" class="form-select form-select-sm">
            <?php for($y=date('Y')-1; $y<=date('Y')+2; $y++): ?>
              <option value="<?= $y ?>" <?= $y===$anioSel?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-2">
          <button type="button" id="btnGenerar" class="btn btn-success btn-sm">
            <i class="bi bi-gear"></i> Generar
          </button>
        </div>

        <div class="col-md-3">
          <label class="form-label">Ver fecha</label>
          <div class="input-group input-group-sm">
            <input type="date" id="ver_fecha" class="form-control" value="<?= esc($fechaTarget) ?>">
            <button class="btn btn-outline-secondary" id="btnIrFecha" type="button">
              <i class="bi bi-calendar-event"></i> Ir
            </button>
            <a class="btn btn-outline-primary" href="?ver_fecha=<?= date('Y-m-d') ?>">Hoy</a>
          </div>
        </div>
      </form>

      <?php if ($success): ?>
        <div class="alert alert-success mt-3 mb-0"><?= esc($success) ?></div>
      <?php endif; ?>
      <?php if ($errMsg): ?>
        <div class="alert alert-danger mt-3 mb-0">Error: <?= esc($errMsg) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabla -->
<div class="row mb-2">
  <div class="col-md-4 ms-auto">
    <input type="text"
           id="buscarCalendario"
           class="form-control form-control-sm"
           placeholder="🔍 Buscar en calendario...">
  </div>
</div>

  <div class="card rounded-4 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold mb-0">Registros para <span class="text-primary"><?= esc($fechaTarget) ?></span></h5>
        <small class="text-secondary">Slots: 07:00 → 22:00 cada 30 min</small>
      </div>
      <div class="table-responsive" style="max-height:460px;overflow-y:auto;">
        <table class="table table-hover table-sm align-middle">
          <thead class="text-center">
            <tr>
              <th style="width:120px">Hora</th>
              <th>Patio</th>
              <th style="width:140px">Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($list): foreach ($list as $r): ?>
            <tr>
              <td class="text-center"><?= esc(substr($r['horainicio'],0,5)) ?></td>
              <td><?= esc($r['desc_patios']) ?></td>
              <td class="text-center">
                <span class="badge <?= $r['estado']==='DISPONIBLE'?'bg-success-subtle text-success border':'bg-danger-subtle text-secondary border' ?>">
                  <?= esc($r['estado']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3" class="text-center text-muted py-4">No hay registros para esta fecha.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal de confirmación -->
<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Registros existentes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="confirmText">
        <!-- Texto dinámico -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btnReemplazar">Reemplazar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Ver fecha
document.getElementById('btnIrFecha').addEventListener('click', () => {
  const f = document.getElementById('ver_fecha').value;
  if (!f) return;
  const url = new URL(window.location.href);
  url.searchParams.set('ver_fecha', f);
  window.location.href = url.toString();
});

// Generar con pre-chequeo
document.getElementById('btnGenerar').addEventListener('click', async () => {
  const anio = document.getElementById('anio').value;
  try {
    const resp = await fetch(`mant_calendario.php?check=1&anio=${encodeURIComponent(anio)}`);
    const data = await resp.json();
    if (!data.ok) { alert('Error al verificar: ' + (data.error||'desconocido')); return; }

    if (data.existen > 0) {
      const txt = `Ya existen <b>${data.existen.toLocaleString('es-CL')}</b> registros para el año <b>${anio}</b>.<br>
                   Hay <b>${data.patios}</b> patios configurados.<br><br>
                   ¿Desea <b>REEMPLAZAR</b> (se borrarán y se volverán a generar) o cancelar?`;
      document.getElementById('confirmText').innerHTML = txt;
      const m = new bootstrap.Modal(document.getElementById('modalConfirm'));
      m.show();
      document.getElementById('btnReemplazar').onclick = () => {
        // Inyectar hidden reemplazar y enviar
        let h = document.querySelector('input[name="reemplazar"]');
        if (!h) {
          h = document.createElement('input');
          h.type = 'hidden';
          h.name = 'reemplazar';
          h.value = '1';
          document.getElementById('frmGen').appendChild(h);
        } else {
          h.value = '1';
        }
        document.getElementById('frmGen').submit();
      };
    } else {
      // No existen → generar directo
      document.getElementById('frmGen').submit();
    }

  } catch (e) {
    console.error(e);
    alert('Error al verificar registros existentes.');
  }
});
</script>
<script>
/* ============================================================
   Buscador general calendario
   ============================================================ */
document.getElementById('buscarCalendario').addEventListener('keyup', function () {
  const texto = this.value.toLowerCase();
  document.querySelectorAll('table tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(texto)
      ? ''
      : 'none';
  });
});
</script>

</body>
</html>

