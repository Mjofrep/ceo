<?php
// --------------------------------------------------------------
// mapa_interactivo.php - CEO
// Visualiza ocupación horaria diaria + mapa
// --------------------------------------------------------------
declare(strict_types=1);
session_start();
date_default_timezone_set('America/Santiago');

// Protección básica
if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

// Fecha seleccionada (GET o hoy)
$fechaActual = $_GET['fecha'] ?? date('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mapa Interactivo - CEONext</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body { background:#f7f9fc; }

.map-container {
  position: relative;
  display: inline-block;
  width: 100%;
}

.map-container img {
  width: 100%;
  border-radius: 8px;
  box-shadow: 0 3px 8px rgba(0,0,0,.15);
}

/* Marcadores (futuro uso dinámico) */
.marker {
  position:absolute;
  width:18px;
  height:18px;
  background:#0d6efd;
  color:#fff;
  border-radius:50%;
  font-size:11px;
  font-weight:bold;
  display:flex;
  align-items:center;
  justify-content:center;
  transform:translate(-50%,-50%);
  cursor:pointer;
}

/* Navegación fecha */
.fecha-navegacion {
  display:flex;
  justify-content:center;
  gap:.5rem;
  margin-bottom:1rem;
}

.fecha-navegacion button {
  background:none;
  border:none;
  font-size:1.4rem;
  color:#0d6efd;
  cursor:pointer;
}

.tabla-horarios th {
  background:#eaf2fb;
  text-align:center;
  font-size:.85rem;
}

.tabla-horarios td {
  font-size:.85rem;
  vertical-align:middle;
}

.table-danger {
  background-color:#f8d7da !important;
}
</style>
</head>

<body>

<div class="container my-4">

<h4 class="fw-bold text-primary mb-3">
🗺️ Mapa Interactivo – Ocupación Patio CEO
</h4>

<div class="row g-2">

<!-- MAPA -->
<div class="col-lg-8">
  <div class="map-container">
    <img src="uploads/Inografia-CEO.png" alt="Mapa Patio CEO">
    <!-- Marcadores se activarán dinámicamente más adelante -->
  </div>
</div>

<!-- TABLA -->
<div class="col-lg-4">
  <div class="card shadow-sm border-0 rounded-4">
    <div class="card-body">

      <div class="fecha-navegacion">
        <button id="btnPrev">«</button>
        <input type="date" id="fecha" class="form-control form-control-sm text-center"
               style="max-width:160px;" value="<?=htmlspecialchars($fechaActual)?>">
        <button id="btnNext">»</button>
      </div>

      <div class="table-responsive" style="max-height:420px;overflow:auto;">
        <table class="table table-bordered table-sm tabla-horarios">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Zona</th>
              <th>Patio</th>
              <th>Empresa</th>
            </tr>
          </thead>
          <tbody id="tbodyHorarios">
            <tr>
              <td colspan="4" class="text-center text-muted py-3">
                Cargando ocupaciones…
              </td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

</div>
</div>

<script>
// ------------------------------------------------------
// Convierte HH:MM a minutos
// ------------------------------------------------------
function parseHora(h) {
  if (!h) return 0;
  const [hh, mm] = h.split(':').map(Number);
  return hh * 60 + mm;
}

// ------------------------------------------------------
// Genera tabla por bloques de 30 min
// ------------------------------------------------------
async function generarTabla(fecha) {
  const tbody = document.getElementById('tbodyHorarios');
  tbody.innerHTML = `<tr>
    <td colspan="4" class="text-center text-muted py-3">
      Cargando ocupaciones…
    </td></tr>`;

  let ocupaciones = [];

  try {
    const r = await fetch(`ocupaciones_dia.php?fecha=${encodeURIComponent(fecha)}`);
    const txt = await r.text();
    const json = JSON.parse(txt);
    if (json.ok) ocupaciones = json.data;
  } catch (e) {
    console.error('Error obteniendo ocupaciones', e);
  }

  const inicio = 7 * 60;
  const fin = 22 * 60;
  let html = '';

  for (let m = inicio; m <= fin; m += 30) {

    const hh = String(Math.floor(m / 60)).padStart(2,'0');
    const mm = String(m % 60).padStart(2,'0');
    const hora = `${hh}:${mm}`;

    // 🔴 FILTRAR TODAS LAS OCUPACIONES ACTIVAS
    const activas = ocupaciones.filter(o => {
      const ini = parseHora(o.horainicio);
      const fin = parseHora(o.horatermino);
      return m >= ini && m < fin; // fin EXCLUSIVO
    });

    if (activas.length > 0) {
      activas.forEach((o, idx) => {
        html += `
        <tr class="table-danger">
          <td class="text-center fw-bold">${idx === 0 ? hora : ''}</td>
          <td class="text-center">${o.desc_zona}</td>
          <td class="text-center">${o.desc_patios}</td>
          <td class="text-center">${o.empresa ?? '-'}</td>
        </tr>`;
      });
    } else {
      html += `
      <tr>
        <td class="text-center">${hora}</td>
        <td class="text-center text-muted">—</td>
        <td class="text-center text-muted">Libre</td>
        <td class="text-center text-muted">—</td>
      </tr>`;
    }
  }

  tbody.innerHTML = html;
}

// ------------------------------------------------------
// Navegación fechas
// ------------------------------------------------------
function moverFecha(dias) {
  const f = document.getElementById('fecha');
  const d = new Date(f.value + 'T00:00:00');
  d.setDate(d.getDate() + dias);
  f.value = d.toISOString().slice(0,10);
  generarTabla(f.value);
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('btnPrev').onclick = () => moverFecha(-1);
  document.getElementById('btnNext').onclick = () => moverFecha(1);
  document.getElementById('fecha').onchange = e => generarTabla(e.target.value);
  generarTabla(document.getElementById('fecha').value);
});
</script>

</body>
</html>
