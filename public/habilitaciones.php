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

$pdo = db();

$rolUsuario    = strtolower($_SESSION['auth']['rol'] ?? '');
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');

/* =========================================================
   EMPRESAS (ORDEN)
========================================================= */
$empresas = $pdo->query("
    SELECT id, nombre
    FROM ceo_empresas
    ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   HABILITACIONES (GRILLA)
========================================================= */
$sql = "
SELECT
    h.cuadrilla,
     h.estado,
    h.empresa,
    ce.nombre AS empresa_nombre,
    cu.desc_uo AS uo,
    sp.servicio,
    h.fecha,
    CONCAT(u.nombres,' ',u.apellidos) AS gestor,
    h.nsolicitud
FROM ceo_habilitacion h
INNER JOIN ceo_empresas ce ON ce.id = h.empresa
INNER JOIN ceo_servicios_pruebas sp ON sp.id = h.id_servicio
LEFT JOIN ceo_uo cu ON cu.id = h.uo
LEFT JOIN ceo_usuarios u ON u.id = h.gestor
";

if ($esContratista) {
    $sql .= " WHERE h.empresa = :empresa ";
}

$sql .= " ORDER BY h.fecha DESC, h.cuadrilla DESC";

$stmt = $pdo->prepare($sql);
if ($esContratista) {
    $stmt->bindValue(':empresa', $idEmpresaUser, PDO::PARAM_INT);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Habilitaciones - <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f7f9fc; }
.topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
.table thead th { background:#eaf2fb; }
.table-hover tbody tr { cursor:pointer; }

/* ✅ Solo para la vista previa en la modal: ocultar títulos de cuadrilla */
#previewParticipantes .titulo-cuadrilla { display:none; }
</style>
</head>

<body>

<!-- ======================================================
     HEADER
====================================================== -->
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;">
      <div>
        <div class="fw-bold"><?= APP_NAME ?></div>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">
      ← Volver
    </a>
  </div>
</header>

<div class="container-fluid px-4">

<!-- ======================================================
     CABECERA
====================================================== -->
<div class="card shadow-sm mb-3">
  <div class="card-body d-flex justify-content-between align-items-center">
    <h5 class="fw-bold text-primary mb-0">
      <i class="bi bi-clipboard-check me-2"></i>Registros de Habilitación
    </h5>

    <div class="d-flex gap-2">

      <button class="btn btn-success btn-sm" id="btnOrden">
        <i class="bi bi-envelope"></i> Generar Orden
      </button>
    </div>
  </div>
</div>

<!-- ======================================================
     TABLA
====================================================== -->
<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="text-center">
          <tr>
            <th style="width:40px;"><input type="checkbox" id="chkAll"></th>
            <th>Cuadrilla</th>
            <th>Empresa</th>
            <th>UO</th>
            <th>Servicio</th>
            <th>Fecha</th>
            <th>Gestor</th>
            <th>N° Solicitud</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <?php
              $cerrado = (strtolower($r['estado']) === 'cerrado');
              $badgeClass = $cerrado ? 'bg-danger' : 'bg-success';
            ?>
            <tr
              data-cuadrilla="<?= (int)$r['cuadrilla'] ?>"
              data-empresa="<?= (int)$r['empresa'] ?>"
              data-uo="<?= esc($r['uo']) ?>"
              data-fecha="<?= esc($r['fecha']) ?>"
            >
              <td class="text-center align-middle">
                <input type="checkbox"
                       class="chkFila"
                       <?= $cerrado ? 'disabled' : '' ?>>
              </td>
            
              <td>
                <span class="badge <?= $badgeClass ?>">
                  C<?= (int)$r['cuadrilla'] ?>
                </span>
              </td>
            
              <td><?= esc($r['empresa_nombre']) ?></td>
              <td><?= esc($r['uo']) ?></td>
              <td><?= esc($r['servicio']) ?></td>
              <td><?= esc($r['fecha']) ?></td>
              <td><?= esc($r['gestor']) ?></td>
              <td><?= esc($r['nsolicitud']) ?></td>
            </tr>
            <?php endforeach; ?>
        
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>

<!-- ======================================================
     MODAL ORDEN
====================================================== -->
<div class="modal fade" id="modalOrden" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form id="formOrden" enctype="multipart/form-data">
      <div class="modal-content">
        <input type="hidden" id="htmlParticipantes" name="html_participantes">
        <input type="hidden" name="cuadrillas" id="cuadrillasOrden">

        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title fw-bold text-white d-block">
            <i class="bi bi-envelope-paper me-2"></i>
            Orden de Evaluación – Envío de Correo
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark d-block">
                Para <span class="text-muted">(Destinatario principal)</span>
              </label>
              <input type="email" name="para" class="form-control" value="marcelo.jofre.external@enel.com" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold text-dark d-block">
                CC <span class="text-muted">(Copia)</span>
              </label>
              <input type="text" name="cc" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label">Asunto</label>
              <input type="text" name="asunto" class="form-control"
                     value="Habilitación dd/mm/yyyy">
            </div>

            <div class="col-md-6">
              <label class="form-label">Empresa Evaluadora</label>
              <select name="empresa_orden" class="form-select">
                <?php foreach ($empresas as $e): ?>
                  <option value="<?= (int)$e['id'] ?>"><?= esc($e['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Cuerpo del correo</label>
              <textarea name="cuerpo" class="form-control" rows="4">Estimados,

Agradecere realizar el proceso de Habilitación. DD/mm/yyyy

Saludos cordiales.</textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label">Adjuntos (Permisos asociados, se adjuntan automáticamente)</label>
              <input type="file" name="adjuntos[]" class="form-control" multiple>
            </div>

            <!-- ✅ Eliminado “Cuadrillas seleccionadas” por solicitud del usuario -->

            <div class="col-12">
              <label class="form-label fw-semibold">Participantes por cuadrilla</label>
              <div id="previewParticipantes"
                   class="border rounded p-2 bg-white"
                   style="max-height:300px; overflow:auto">
                <em class="text-muted">Se mostrará el detalle al seleccionar cuadrillas.</em>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            Cancelar
          </button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-send"></i> Enviar
          </button>
        </div>

      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {

  // CHECK ALL
  document.getElementById('chkAll').addEventListener('change', function () {
    document.querySelectorAll('.chkFila').forEach(c => c.checked = this.checked);
  });

  // DOBLE CLICK (SE MANTIENE)
  document.querySelectorAll('tbody tr').forEach(tr => {
    tr.addEventListener('dblclick', () => {
      const cuadrilla = tr.dataset.cuadrilla;
      const empresa   = tr.dataset.empresa;
      const uo        = tr.dataset.uo;

      window.location.href =
        `revision_cuadrillas.php?cuadrilla=${cuadrilla}&empresa=${empresa}&uo=${encodeURIComponent(uo)}`;
    });
  });

  // GENERAR ORDEN
  const modal = new bootstrap.Modal(document.getElementById('modalOrden'));
function formatFecha(fechaISO) {
  if (!fechaISO) return '';
  const [y, m, d] = fechaISO.split('-');
  return `${d}/${m}/${y}`;
}

  document.getElementById('btnOrden').addEventListener('click', () => {

    const filas = [...document.querySelectorAll('.chkFila:checked')]
      .map(chk => chk.closest('tr'));

    if (filas.length === 0) {
      alert('Debe seleccionar al menos una cuadrilla.');
      return;
    }

    // 👉 Tomamos la fecha de la primera cuadrilla seleccionada
    const fechaISO = filas[0].dataset.fecha;
    const fechaFormateada = formatFecha(fechaISO);
    
    // 👉 Reemplazar asunto
    document.querySelector('input[name="asunto"]').value =
      `Habilitación ${fechaFormateada}`;
    
    // 👉 Reemplazar cuerpo del correo
    document.querySelector('textarea[name="cuerpo"]').value =
    `Estimados,
    
    Agradeceré realizar el proceso de Habilitación con fecha ${fechaFormateada}.
    
    Saludos cordiales.`;

    const cuadrillas = filas.map(tr => tr.dataset.cuadrilla);

    fetch('obtener_participantes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cuadrillas })
    })
    .then(r => r.json())
    .then(resp => {

      let htmlEmail = '';   // para enviar por correo
      let htmlPreview = ''; // para mostrar en modal (sin títulos)

      cuadrillas.forEach(id => {

        // ✅ Título solo para correo (no se verá en modal, por CSS + clase)
        htmlEmail += `<h4 class="titulo-cuadrilla" style="margin-top:14px;margin-bottom:8px">Cuadrilla ${id}</h4>`;

        // Preview: no agregamos títulos
        // (y aunque el correo los tenga, en la modal se ocultan por CSS)

        const tablaInicio = `
          <table border="1" cellpadding="6" width="100%" style="margin-bottom:16px;border-collapse:collapse">
            <tr style="background:#f2f2f2">
              <th>RUT</th>
              <th>Nombre</th>
              <th>Cargo</th>
              <th>Tipo</th>
              <th>Servicio</th>
              <th>UO</th>
              <th>Empresa</th>
            </tr>
        `;

        let filasTabla = '';

        // Importante: backend puede devolver "cuadrilla" o "id_cuadrilla"
        const personas = (resp.data || []).filter(p =>
          String(p.cuadrilla ?? p.id_cuadrilla) === String(id)
        );

        if (personas.length === 0) {
          filasTabla += `<tr><td colspan="3">Sin participantes</td></tr>`;
        } else {
          personas.forEach(p => {
            filasTabla += `
              <tr>
                <td>${p.rut ?? ''}</td>
                <td>${p.nombre_part ?? ''}</td>
                <td>${p.cargo ?? ''}</td>
                <td>${p.tipo ?? ''}</td>
                <td>${p.servicio ?? ''}</td>
                <td>${p.desc_uo ?? ''}</td>
                <td>${p.empresa ?? ''}</td>
              </tr>`;
          });
        }

        const tablaFin = `</table>`;

        // Para correo: título + tabla
        htmlEmail += tablaInicio + filasTabla + tablaFin;

        // Para modal: solo tabla (sin título)
        htmlPreview += tablaInicio + filasTabla + tablaFin;
      });

      document.getElementById('htmlParticipantes').value = htmlEmail;
      document.getElementById('previewParticipantes').innerHTML = htmlPreview;
      document.getElementById('cuadrillasOrden').value = cuadrillas.join(',');
      modal.show();
    })
    .catch(() => {
      alert('Error al obtener participantes. Revisa obtener_participantes.php');
    });

  });

});
</script>
<script>
document.getElementById('formOrden').addEventListener('submit', function (e) {
  e.preventDefault(); // ⛔ evita submit normal

  const form = e.target;
  const formData = new FormData(form);

  console.log('➡️ Enviando correo…');

  fetch('/ceo.noetica.cl/public/enviar_orden_mail.php', {
    method: 'POST',
    body: formData
  })
  .then(r => {
    console.log('HTTP STATUS:', r.status);
    return r.json();
  })
  .then(resp => {
    if (resp.ok) {
      alert('✅ Correo enviado y cuadrillas cerradas.');
      location.reload();
    } else {
      alert('❌ Error: ' + (resp.msg || 'Error desconocido'));
    }
  })
  .catch(err => {
    console.error(err);
    alert('❌ Error de red al enviar correo');
  });
});
</script>

</body>
</html>
