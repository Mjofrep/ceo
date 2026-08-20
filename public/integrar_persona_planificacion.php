<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: ' . app_url('/public/index.php'));
    exit;
}

$pdo = db();
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = strtolower((string)($_SESSION['auth']['rol'] ?? '')) === 'contratista';

$whereEmpresaFormacion = $esContratista && $idEmpresaUser > 0 ? 'WHERE f.empresa = :empresa' : '';
$whereEmpresaHabilitacion = $esContratista && $idEmpresaUser > 0 ? 'WHERE h.empresa = :empresa' : '';

$stmtFormacion = $pdo->prepare('
    SELECT
        f.cuadrilla,
        f.id,
        f.id_servicio,
        f.id_agrupacion,
        f.estado,
        e.nombre AS empresa_nombre,
        u.desc_uo AS uo_nombre,
        s.servicio AS servicio_nombre
    FROM ceo_formacion f
    LEFT JOIN ceo_empresas e ON e.id = f.empresa
    LEFT JOIN ceo_uo u ON u.id = f.uo
    LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
    ' . $whereEmpresaFormacion . '
    ORDER BY f.id DESC
    LIMIT 200
');
if ($esContratista && $idEmpresaUser > 0) {
    $stmtFormacion->execute([':empresa' => $idEmpresaUser]);
} else {
    $stmtFormacion->execute();
}
$formaciones = $stmtFormacion->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtHabilitacion = $pdo->prepare('
    SELECT
        h.cuadrilla,
        h.id,
        h.id_servicio,
        h.estado,
        e.nombre AS empresa_nombre,
        u.desc_uo AS uo_nombre,
        s.servicio AS servicio_nombre
    FROM ceo_habilitacion h
    LEFT JOIN ceo_empresas e ON e.id = h.empresa
    LEFT JOIN ceo_uo u ON u.id = h.uo
    LEFT JOIN ceo_servicios_pruebas s ON s.id = h.id_servicio
    ' . $whereEmpresaHabilitacion . '
    ORDER BY h.id DESC
    LIMIT 200
');
if ($esContratista && $idEmpresaUser > 0) {
    $stmtHabilitacion->execute([':empresa' => $idEmpresaUser]);
} else {
    $stmtHabilitacion->execute();
}
$habilitaciones = $stmtHabilitacion->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cargos = $pdo->query('SELECT id, cargo FROM ceo_cargo_contratistas ORDER BY cargo ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Integrar Persona a Planificacion | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f4f7fb; }
.card-soft { border:0; border-radius:1rem; box-shadow:0 10px 30px rgba(22,34,51,.08); }
.summary-label { color:#667085; font-size:.875rem; }
.summary-value { font-weight:600; }
.mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
</style>
</head>
<body>
<div class="container py-4 py-lg-5">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
      <h1 class="h3 mb-1">Integrar Persona a Planificacion</h1>
      <p class="text-muted mb-0">Herramienta nueva y aislada para integrar o quitar pruebas de formación y habilitación.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= esc(app_url('/public/index.php')) ?>">Volver</a>
  </div>

  <div class="card card-soft mb-4">
    <div class="card-body p-4">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold">Tipo</label>
          <select id="tipo" class="form-select">
            <option value="formacion">Formación</option>
            <option value="habilitacion">Habilitación</option>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold">Planificación / Cuadrilla</label>
          <select id="cuadrilla" class="form-select"></select>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">RUT</label>
          <input id="rut" type="text" class="form-control" placeholder="12.345.678-9">
        </div>
        <div class="col-md-2 d-grid">
          <button id="btnPreview" type="button" class="btn btn-primary"><i class="bi bi-search me-1"></i>Revisar</button>
        </div>
      </div>
      <div id="alerta" class="mt-3"></div>
    </div>
  </div>

  <div id="resultado" class="d-none">
    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card card-soft h-100">
          <div class="card-body p-4">
            <h2 class="h5 mb-3">Planificación</h2>
            <div class="mb-2"><div class="summary-label">Tipo</div><div id="planTipo" class="summary-value"></div></div>
            <div class="mb-2"><div class="summary-label">Cuadrilla</div><div id="planCuadrilla" class="summary-value mono"></div></div>
            <div class="mb-2"><div class="summary-label">Servicio</div><div id="planServicio" class="summary-value"></div></div>
            <div class="mb-2"><div class="summary-label">Empresa</div><div id="planEmpresa" class="summary-value"></div></div>
            <div class="mb-2"><div class="summary-label">UO</div><div id="planUo" class="summary-value"></div></div>
            <div><div class="summary-label">Estado</div><div id="planEstado" class="summary-value"></div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card card-soft h-100">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
              <h2 class="h5 mb-0">Persona y estado</h2>
              <span id="badgeEstado" class="badge text-bg-secondary">Sin revisar</span>
            </div>
            <div id="personaExistente" class="d-none">
              <div class="mb-2"><div class="summary-label">Nombre</div><div id="personaNombre" class="summary-value"></div></div>
              <div class="mb-2"><div class="summary-label">Cargo</div><div id="personaCargo" class="summary-value"></div></div>
              <div class="mb-2"><div class="summary-label">Empresa</div><div id="personaEmpresa" class="summary-value"></div></div>
              <div class="mb-3"><div class="summary-label">UO</div><div id="personaUo" class="summary-value"></div></div>
            </div>

            <div id="crearPersona" class="d-none border rounded-4 p-3 bg-light-subtle mb-3">
              <div class="fw-semibold mb-2">La persona no existe. Se creará heredando empresa y UO desde la cuadrilla.</div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Nombre</label>
                  <input id="nuevoNombre" type="text" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Apellidos</label>
                  <input id="nuevoApellidos" type="text" class="form-control">
                </div>
                <div class="col-md-8">
                  <label class="form-label">Cargo</label>
                  <select id="nuevoCargo" class="form-select">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach ($cargos as $cargo): ?>
                      <option value="<?= (int)$cargo['id'] ?>"><?= esc((string)$cargo['cargo']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Empresa/UO</label>
                  <input id="herenciaPlan" type="text" class="form-control" readonly>
                </div>
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <div class="summary-label">Participante en cuadrilla</div>
                <div id="estadoParticipante" class="summary-value"></div>
              </div>
              <div class="col-md-6">
                <div class="summary-label">Programación actual</div>
                <div id="estadoProgramacion" class="summary-value"></div>
              </div>
            </div>

            <div id="bloqueProcesos" class="d-none mb-3">
              <label class="form-label fw-semibold">Proceso de habilitación abierto</label>
              <select id="procesoHabilitacion" class="form-select"></select>
              <div class="form-text">Si no eliges uno, se usará el más reciente o se creará uno nuevo si no existe.</div>
            </div>

            <div class="d-flex flex-wrap gap-2">
              <button id="btnIntegrar" type="button" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Integrar y programar</button>
              <button id="btnQuitar" type="button" class="btn btn-outline-danger"><i class="bi bi-person-dash me-1"></i>Quitar de prueba</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const dataPlanificaciones = {
  formacion: <?= json_encode($formaciones, JSON_UNESCAPED_UNICODE) ?>,
  habilitacion: <?= json_encode($habilitaciones, JSON_UNESCAPED_UNICODE) ?>
};

const tipoEl = document.getElementById('tipo');
const cuadrillaEl = document.getElementById('cuadrilla');
const rutEl = document.getElementById('rut');
const alertaEl = document.getElementById('alerta');
const resultadoEl = document.getElementById('resultado');
const crearPersonaEl = document.getElementById('crearPersona');
const personaExistenteEl = document.getElementById('personaExistente');
const bloqueProcesosEl = document.getElementById('bloqueProcesos');
const procesoHabilitacionEl = document.getElementById('procesoHabilitacion');

let ultimoPreview = null;

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = value ?? '';
  return div.innerHTML;
}

function setAlert(message, kind = 'info') {
  alertaEl.innerHTML = message ? `<div class="alert alert-${kind} mb-0">${escapeHtml(message)}</div>` : '';
}

function fillCuadrillas() {
  const tipo = tipoEl.value;
  const items = dataPlanificaciones[tipo] || [];
  cuadrillaEl.innerHTML = '';
  if (!items.length) {
    cuadrillaEl.innerHTML = '<option value="">Sin cuadrillas disponibles</option>';
    return;
  }
  for (const item of items) {
    const estado = String(item.estado || '').trim();
    const servicio = String(item.servicio_nombre || 'Sin servicio');
    const empresa = String(item.empresa_nombre || 'Sin empresa');
    const uo = String(item.uo_nombre || 'Sin UO');
    const option = document.createElement('option');
    option.value = String(item.cuadrilla || '');
    option.textContent = `C${item.cuadrilla} | ${servicio} | ${empresa} | ${uo}${estado ? ' | ' + estado : ''}`;
    cuadrillaEl.appendChild(option);
  }
}

async function postJson(payload) {
  const res = await fetch('ajax_integrar_persona_planificacion.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    credentials: 'same-origin',
    body: JSON.stringify(payload)
  });
  const json = await res.json();
  if (!json.ok) {
    throw new Error(json.error || 'No se pudo completar la operación');
  }
  return json;
}

function renderPreview(json) {
  ultimoPreview = json;
  const plan = json.planificacion || {};
  const preview = json.preview || {};
  const persona = preview.persona || null;
  const participante = preview.participante || null;
  const programacion = preview.programacion || null;
  const procesos = Array.isArray(preview.procesos_abiertos) ? preview.procesos_abiertos : [];

  resultadoEl.classList.remove('d-none');

  document.getElementById('planTipo').textContent = plan.tipo === 'habilitacion' ? 'Habilitación' : 'Formación';
  document.getElementById('planCuadrilla').textContent = `C${plan.cuadrilla || ''}`;
  document.getElementById('planServicio').textContent = plan.servicio_nombre || '';
  document.getElementById('planEmpresa').textContent = plan.empresa_nombre || '';
  document.getElementById('planUo').textContent = plan.uo_nombre || '';
  document.getElementById('planEstado').textContent = plan.estado || 'Sin estado';

  document.getElementById('herenciaPlan').value = `${plan.empresa_nombre || ''} / ${plan.uo_nombre || ''}`;

  if (persona) {
    personaExistenteEl.classList.remove('d-none');
    crearPersonaEl.classList.add('d-none');
    document.getElementById('personaNombre').textContent = `${persona.nombre || ''} ${persona.apellidos || ''}`.trim();
    document.getElementById('personaCargo').textContent = persona.cargo_nombre || 'Sin cargo';
    document.getElementById('personaEmpresa').textContent = persona.empresa_nombre || 'Sin empresa';
    document.getElementById('personaUo').textContent = persona.uo_nombre || 'Sin UO';
  } else {
    personaExistenteEl.classList.add('d-none');
    crearPersonaEl.classList.remove('d-none');
    document.getElementById('nuevoNombre').value = participante?.nombre || '';
    document.getElementById('nuevoApellidos').value = participante?.apellidos || '';
    document.getElementById('nuevoCargo').value = '';
  }

  document.getElementById('estadoParticipante').textContent = participante ? 'Sí, ya figura en la cuadrilla' : 'No, se agregará al integrar';

  let estadoProgramacion = 'Sin programación';
  if (programacion) {
    estadoProgramacion = `${programacion.estado || ''}`;
    if (programacion.numero_proceso) {
      estadoProgramacion += ` | Proceso ${programacion.numero_proceso}`;
    }
  }
  if (preview.ya_rindio_misma_prueba) {
    estadoProgramacion = 'Ya rindió esta misma prueba';
  }
  document.getElementById('estadoProgramacion').textContent = estadoProgramacion;

  const badge = document.getElementById('badgeEstado');
  if (preview.ya_rindio_misma_prueba) {
    badge.className = 'badge text-bg-danger';
    badge.textContent = 'Bloqueado por prueba ejecutada';
  } else if (programacion && String(programacion.estado || '').toUpperCase() === 'PENDIENTE') {
    badge.className = 'badge text-bg-warning';
    badge.textContent = 'Ya pendiente';
  } else {
    badge.className = 'badge text-bg-success';
    badge.textContent = 'Operable';
  }

  if (plan.tipo === 'habilitacion') {
    bloqueProcesosEl.classList.remove('d-none');
    procesoHabilitacionEl.innerHTML = '<option value="">Usar automático / crear si falta</option>';
    for (const proceso of procesos) {
      const option = document.createElement('option');
      option.value = String(proceso.id || '');
      const desc = proceso.descripcion ? ` - ${proceso.descripcion}` : '';
      option.textContent = `Proceso ${proceso.numero_proceso || ''} | ${proceso.servicio || ''}${desc} | ${proceso.cargo || ''}`;
      procesoHabilitacionEl.appendChild(option);
    }
    if (programacion && programacion.id_proceso_habilitacion) {
      procesoHabilitacionEl.value = String(programacion.id_proceso_habilitacion);
    }
  } else {
    bloqueProcesosEl.classList.add('d-none');
    procesoHabilitacionEl.innerHTML = '';
  }

  document.getElementById('btnQuitar').disabled = !preview.puede_quitar;
}

function buildPayload(accion) {
  const payload = {
    accion,
    tipo: tipoEl.value,
    cuadrilla: Number(cuadrillaEl.value || 0),
    rut: rutEl.value.trim()
  };

  if (tipoEl.value === 'habilitacion' && procesoHabilitacionEl.value) {
    payload.id_proceso_habilitacion = Number(procesoHabilitacionEl.value);
  }

  const needsCreate = ultimoPreview && !(ultimoPreview.preview?.persona);
  if (needsCreate && accion === 'integrar') {
    payload.nombre = document.getElementById('nuevoNombre').value.trim();
    payload.apellidos = document.getElementById('nuevoApellidos').value.trim();
    payload.id_cargo = Number(document.getElementById('nuevoCargo').value || 0);
  }

  return payload;
}

document.getElementById('btnPreview').addEventListener('click', async () => {
  try {
    setAlert('Consultando...', 'secondary');
    const json = await postJson(buildPayload('preview'));
    renderPreview(json);
    setAlert('Información cargada.', 'success');
  } catch (error) {
    resultadoEl.classList.add('d-none');
    ultimoPreview = null;
    setAlert(error.message || 'Error al consultar', 'danger');
  }
});

document.getElementById('btnIntegrar').addEventListener('click', async () => {
  try {
    setAlert('Procesando integración...', 'secondary');
    const json = await postJson(buildPayload('integrar'));
    renderPreview(json);
    const accion = json.resultado?.accion || 'actualizada';
    setAlert(`Operación completada (${accion}).`, 'success');
  } catch (error) {
    setAlert(error.message || 'Error al integrar', 'danger');
  }
});

document.getElementById('btnQuitar').addEventListener('click', async () => {
  if (!confirm('Se anulará la programación de prueba de esta cuadrilla. ¿Deseas continuar?')) {
    return;
  }
  try {
    setAlert('Anulando programación...', 'secondary');
    const json = await postJson(buildPayload('quitar'));
    renderPreview(json);
    setAlert('Programación anulada.', 'success');
  } catch (error) {
    setAlert(error.message || 'Error al quitar prueba', 'danger');
  }
});

tipoEl.addEventListener('change', () => {
  fillCuadrillas();
  resultadoEl.classList.add('d-none');
  ultimoPreview = null;
  setAlert('', 'info');
});

fillCuadrillas();
</script>
</body>
</html>
