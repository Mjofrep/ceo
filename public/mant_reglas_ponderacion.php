<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

$pdo = db();
$msg = '';
$msgType = 'info';

function escRule($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function parseRuleDecimal(string $value): float
{
    $normalized = str_replace(',', '.', trim($value));
    return (float)$normalized;
}

$segmentOptions = ['GENERAL'];
$flagOptions = ['S' => 'Sí', 'N' => 'No'];
$activeOptions = ['S' => 'Activa', 'N' => 'Inactiva'];

$services = $pdo->query("
    SELECT id, servicio
    FROM ceo_servicios_pruebas
    ORDER BY servicio ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cargoRows = $pdo->query("
    SELECT id, cargo
    FROM ceo_cargos_habilitacion
    ORDER BY cargo ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string)($_POST['accion'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($accion === 'toggle' && $id > 0) {
            $nuevoEstado = strtoupper(trim((string)($_POST['nuevo_estado'] ?? 'N')));
            $nuevoEstado = $nuevoEstado === 'S' ? 'S' : 'N';

            $stmtToggle = $pdo->prepare("
                UPDATE ceo_reglas_ponderacion
                SET activo = :activo,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtToggle->execute([
                ':activo' => $nuevoEstado,
                ':id' => $id,
            ]);

            $msg = $nuevoEstado === 'S' ? '✅ Regla reactivada.' : '⚠️ Regla desactivada.';
            $msgType = 'info';
        } elseif (in_array($accion, ['crear', 'editar'], true)) {
            $idServicio = (int)($_POST['id_servicio'] ?? 0);
            $cargo = (int)($_POST['cargo'] ?? 0);
            $segmento = 'GENERAL';
            $ponderacionPruebaText = trim((string)($_POST['ponderacion_prueba'] ?? ''));
            $ponderacionTerrenoText = trim((string)($_POST['ponderacion_terreno'] ?? ''));
            $exigePrueba = strtoupper(trim((string)($_POST['exige_prueba_aprobada'] ?? 'S')));
            $exigeTerreno = strtoupper(trim((string)($_POST['exige_terreno_aprobado'] ?? 'S')));
            $activo = strtoupper(trim((string)($_POST['activo'] ?? 'S')));
            $fechaDesde = trim((string)($_POST['fecha_desde'] ?? ''));
            $fechaHasta = trim((string)($_POST['fecha_hasta'] ?? ''));
            $observacion = trim((string)($_POST['observacion'] ?? ''));

            $errors = [];

            if ($idServicio <= 0) {
                $errors[] = 'Debes seleccionar un servicio.';
            }
            if ($cargo <= 0) {
                $errors[] = 'Debes seleccionar un cargo.';
            }
            if (!in_array($segmento, $segmentOptions, true)) {
                $errors[] = 'Debes seleccionar un segmento válido.';
            }
            if ($fechaDesde === '') {
                $errors[] = 'La fecha desde es obligatoria.';
            }
            if ($ponderacionPruebaText === '' || $ponderacionTerrenoText === '') {
                $errors[] = 'Las ponderaciones de prueba y terreno son obligatorias.';
            }
            if (!in_array($exigePrueba, array_keys($flagOptions), true)) {
                $errors[] = 'El valor de exige prueba aprobada es inválido.';
            }
            if (!in_array($exigeTerreno, array_keys($flagOptions), true)) {
                $errors[] = 'El valor de exige terreno aprobado es inválido.';
            }
            if (!in_array($activo, array_keys($activeOptions), true)) {
                $errors[] = 'El estado activo es inválido.';
            }
            if ($fechaHasta !== '' && $fechaHasta < $fechaDesde) {
                $errors[] = 'La fecha hasta no puede ser menor que la fecha desde.';
            }

            $ponderacionPrueba = parseRuleDecimal($ponderacionPruebaText);
            $ponderacionTerreno = parseRuleDecimal($ponderacionTerrenoText);

            if ($ponderacionPrueba < 0 || $ponderacionTerreno < 0) {
                $errors[] = 'Las ponderaciones no pueden ser negativas.';
            }

            $stmtService = $pdo->prepare('SELECT 1 FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
            $stmtService->execute([':id' => $idServicio]);
            if (!$stmtService->fetchColumn()) {
                $errors[] = 'El servicio seleccionado no existe.';
            }

            $stmtCargo = $pdo->prepare('SELECT 1 FROM ceo_cargos_habilitacion WHERE id = :id LIMIT 1');
            $stmtCargo->execute([':id' => $cargo]);
            if (!$stmtCargo->fetchColumn()) {
                $errors[] = 'El cargo seleccionado no existe.';
            }

            if ($errors === []) {
                if ($accion === 'crear') {
                    $stmt = $pdo->prepare("
                        INSERT INTO ceo_reglas_ponderacion
                        (
                            id_servicio,
                            cargo,
                            segmento,
                            ponderacion_prueba,
                            ponderacion_terreno,
                            exige_prueba_aprobada,
                            exige_terreno_aprobado,
                            activo,
                            fecha_desde,
                            fecha_hasta,
                            observacion,
                            created_at,
                            updated_at
                        )
                        VALUES
                        (
                            :id_servicio,
                            :cargo,
                            :segmento,
                            :ponderacion_prueba,
                            :ponderacion_terreno,
                            :exige_prueba_aprobada,
                            :exige_terreno_aprobado,
                            :activo,
                            :fecha_desde,
                            :fecha_hasta,
                            :observacion,
                            NOW(),
                            NOW()
                        )
                    ");
                    $stmt->execute([
                        ':id_servicio' => $idServicio,
                        ':cargo' => $cargo,
                        ':segmento' => $segmento,
                        ':ponderacion_prueba' => $ponderacionPrueba,
                        ':ponderacion_terreno' => $ponderacionTerreno,
                        ':exige_prueba_aprobada' => $exigePrueba,
                        ':exige_terreno_aprobado' => $exigeTerreno,
                        ':activo' => $activo,
                        ':fecha_desde' => $fechaDesde,
                        ':fecha_hasta' => $fechaHasta !== '' ? $fechaHasta : null,
                        ':observacion' => $observacion !== '' ? $observacion : null,
                    ]);
                    $msg = '✅ Regla creada correctamente.';
                    $msgType = 'success';
                } elseif ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE ceo_reglas_ponderacion
                        SET id_servicio = :id_servicio,
                            cargo = :cargo,
                            segmento = :segmento,
                            ponderacion_prueba = :ponderacion_prueba,
                            ponderacion_terreno = :ponderacion_terreno,
                            exige_prueba_aprobada = :exige_prueba_aprobada,
                            exige_terreno_aprobado = :exige_terreno_aprobado,
                            activo = :activo,
                            fecha_desde = :fecha_desde,
                            fecha_hasta = :fecha_hasta,
                            observacion = :observacion,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id_servicio' => $idServicio,
                        ':cargo' => $cargo,
                        ':segmento' => $segmento,
                        ':ponderacion_prueba' => $ponderacionPrueba,
                        ':ponderacion_terreno' => $ponderacionTerreno,
                        ':exige_prueba_aprobada' => $exigePrueba,
                        ':exige_terreno_aprobado' => $exigeTerreno,
                        ':activo' => $activo,
                        ':fecha_desde' => $fechaDesde,
                        ':fecha_hasta' => $fechaHasta !== '' ? $fechaHasta : null,
                        ':observacion' => $observacion !== '' ? $observacion : null,
                        ':id' => $id,
                    ]);
                    $msg = '📝 Regla actualizada.';
                    $msgType = 'success';
                } else {
                    $msg = '❌ No se pudo identificar la regla a editar.';
                    $msgType = 'danger';
                }
            } else {
                $msg = '❌ ' . implode(' ', $errors);
                $msgType = 'danger';
            }
        }
    } catch (Throwable $e) {
        $msg = '❌ Error al guardar la regla: ' . $e->getMessage();
        $msgType = 'danger';
    }
}

$rules = $pdo->query("
    SELECT
        rp.*,
        sp.servicio,
        ch.cargo AS cargo_nombre
    FROM ceo_reglas_ponderacion rp
    INNER JOIN ceo_servicios_pruebas sp ON sp.id = rp.id_servicio
    LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = rp.cargo
    ORDER BY sp.servicio ASC, rp.segmento ASC, ch.cargo ASC, rp.fecha_desde DESC, rp.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?= APP_NAME ?> | Reglas de Ponderación</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f9fbff; font-family:"Segoe UI",Roboto,sans-serif; }
.topbar { background:#fff; border-bottom:1px solid rgba(13,110,253,0.12); box-shadow:0 1px 4px rgba(0,0,0,0.05); }
.brand-title { font-weight:700; color:#0d6efd; }
.card { border-radius:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
table th, table td { vertical-align:middle; }
.small-note { font-size:.9rem; color:#6c757d; }
</style>
</head>
<body>

<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= APP_LOGO ?>" alt="Logo" style="height:60px;">
      <div>
        <div class="brand-title h4 mb-0"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-primary btn-sm">← Volver</a>
  </div>
</header>

<main class="container">

<?php if ($msg !== ''): ?>
<div class="alert alert-<?= escRule($msgType) ?> text-center"><?= escRule($msg) ?></div>
<?php endif; ?>

<div class="card p-4 mb-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h4 class="mb-1">Agregar / Editar Regla de Ponderación</h4>
      <div class="small-note">El flujo actual de evaluación consume principalmente reglas con segmento <strong>GENERAL</strong>.</div>
    </div>
  </div>

  <form method="post" id="frmRegla" class="row g-3">
    <input type="hidden" name="id" id="id">

    <div class="col-md-4">
      <label class="form-label">Servicio</label>
      <select name="id_servicio" id="id_servicio" class="form-select" required>
        <option value="">Seleccione...</option>
        <?php foreach ($services as $service): ?>
          <option value="<?= (int)$service['id'] ?>"><?= escRule($service['servicio']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Cargo</label>
      <select name="cargo" id="cargo" class="form-select" required>
        <option value="">Seleccione...</option>
        <?php foreach ($cargoRows as $cargoRow): ?>
          <option value="<?= (int)$cargoRow['id'] ?>"><?= escRule($cargoRow['cargo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Segmento</label>
      <select name="segmento" id="segmento" class="form-select" required>
        <?php foreach ($segmentOptions as $segmentOption): ?>
          <option value="<?= escRule($segmentOption) ?>"><?= escRule($segmentOption) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Ponderación prueba</label>
      <input type="number" step="0.01" min="0" name="ponderacion_prueba" id="ponderacion_prueba" class="form-control" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Ponderación terreno</label>
      <input type="number" step="0.01" min="0" name="ponderacion_terreno" id="ponderacion_terreno" class="form-control" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Exige prueba aprobada</label>
      <select name="exige_prueba_aprobada" id="exige_prueba_aprobada" class="form-select" required>
        <?php foreach ($flagOptions as $value => $label): ?>
          <option value="<?= escRule($value) ?>"><?= escRule($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Exige terreno aprobado</label>
      <select name="exige_terreno_aprobado" id="exige_terreno_aprobado" class="form-select" required>
        <?php foreach ($flagOptions as $value => $label): ?>
          <option value="<?= escRule($value) ?>"><?= escRule($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Activo</label>
      <select name="activo" id="activo" class="form-select" required>
        <?php foreach ($activeOptions as $value => $label): ?>
          <option value="<?= escRule($value) ?>"><?= escRule($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Fecha desde</label>
      <input type="date" name="fecha_desde" id="fecha_desde" class="form-control" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Fecha hasta</label>
      <input type="date" name="fecha_hasta" id="fecha_hasta" class="form-control">
    </div>

    <div class="col-md-3">
      <label class="form-label">Categoría detectada</label>
      <input type="text" id="categoria_detectada" class="form-control" readonly value="">
    </div>

    <div class="col-12">
      <label class="form-label">Observación</label>
      <input type="text" name="observacion" id="observacion" class="form-control" maxlength="255">
    </div>

    <div class="col-12 text-end mt-3">
      <button type="submit" name="accion" value="crear" class="btn btn-primary" id="btnGuardar">Guardar</button>
      <button type="submit" name="accion" value="editar" class="btn btn-warning d-none" id="btnActualizar">Actualizar</button>
      <button type="button" class="btn btn-secondary d-none" id="btnCancelar">Cancelar</button>
    </div>
  </form>
</div>

<div class="card p-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Reglas Registradas</h4>
    <input type="text" id="buscarReglas" class="form-control form-control-sm" style="max-width:320px;" placeholder="Buscar servicio, cargo, segmento...">
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle" id="tablaReglas">
      <thead class="table-primary">
        <tr>
          <th>ID</th>
          <th>Servicio</th>
          <th>Cargo</th>
          <th>Segmento</th>
          <th>Pond. Prueba</th>
          <th>Pond. Terreno</th>
          <th>Exige Prueba</th>
          <th>Exige Terreno</th>
          <th>Activo</th>
          <th>Desde</th>
          <th>Hasta</th>
          <th>Observación</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rules as $rule): ?>
        <?php
        $categoria = resolverCategoriaCargoPonderacion((string)($rule['cargo_nombre'] ?? ''), (int)($rule['cargo'] ?? 0));
        $rulePayload = [
            'id' => (int)$rule['id'],
            'id_servicio' => (int)$rule['id_servicio'],
            'cargo' => (int)$rule['cargo'],
            'segmento' => (string)$rule['segmento'],
            'ponderacion_prueba' => (string)$rule['ponderacion_prueba'],
            'ponderacion_terreno' => (string)$rule['ponderacion_terreno'],
            'exige_prueba_aprobada' => (string)$rule['exige_prueba_aprobada'],
            'exige_terreno_aprobado' => (string)$rule['exige_terreno_aprobado'],
            'activo' => (string)$rule['activo'],
            'fecha_desde' => (string)$rule['fecha_desde'],
            'fecha_hasta' => (string)($rule['fecha_hasta'] ?? ''),
            'observacion' => (string)($rule['observacion'] ?? ''),
            'categoria' => (string)($categoria ?? ''),
        ];
        ?>
        <tr>
          <td><?= (int)$rule['id'] ?></td>
          <td><?= escRule($rule['servicio']) ?></td>
          <td>
            <?= escRule($rule['cargo_nombre'] ?? ('Cargo #' . (int)$rule['cargo'])) ?>
            <?php if ($categoria !== null): ?>
              <div class="small text-muted"><?= escRule($categoria) ?></div>
            <?php endif; ?>
          </td>
          <td><?= escRule($rule['segmento']) ?></td>
          <td><?= escRule($rule['ponderacion_prueba']) ?></td>
          <td><?= escRule($rule['ponderacion_terreno']) ?></td>
          <td><?= ($rule['exige_prueba_aprobada'] ?? 'N') === 'S' ? 'Sí' : 'No' ?></td>
          <td><?= ($rule['exige_terreno_aprobado'] ?? 'N') === 'S' ? 'Sí' : 'No' ?></td>
          <td>
            <span class="badge <?= ($rule['activo'] ?? 'N') === 'S' ? 'bg-success' : 'bg-secondary' ?>">
              <?= ($rule['activo'] ?? 'N') === 'S' ? 'Activa' : 'Inactiva' ?>
            </span>
          </td>
          <td><?= escRule($rule['fecha_desde']) ?></td>
          <td><?= escRule($rule['fecha_hasta']) ?></td>
          <td><?= escRule($rule['observacion']) ?></td>
          <td>
            <button class="btn btn-info btn-sm btnEditar" data-row='<?= json_encode($rulePayload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>Editar</button>
            <form method="post" class="d-inline">
              <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
              <input type="hidden" name="nuevo_estado" value="<?= ($rule['activo'] ?? 'N') === 'S' ? 'N' : 'S' ?>">
              <button
                type="submit"
                name="accion"
                value="toggle"
                class="btn btn-sm <?= ($rule['activo'] ?? 'N') === 'S' ? 'btn-danger' : 'btn-success' ?>"
                onclick="return confirm('¿Deseas <?= ($rule['activo'] ?? 'N') === 'S' ? 'desactivar' : 'reactivar' ?> esta regla?')"
              >
                <?= ($rule['activo'] ?? 'N') === 'S' ? 'Desactivar' : 'Reactivar' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

</main>

<footer class="text-center mt-4 mb-4 text-secondary"><?= APP_FOOTER ?></footer>

<script>
const categoriaInput = document.getElementById('categoria_detectada');
const cargoSelect = document.getElementById('cargo');
const cargoMeta = <?= json_encode(array_map(static function (array $row): array {
    $categoria = resolverCategoriaCargoPonderacion((string)($row['cargo'] ?? ''), (int)($row['id'] ?? 0));
    return [
        'id' => (int)($row['id'] ?? 0),
        'categoria' => $categoria,
    ];
}, $cargoRows), JSON_UNESCAPED_UNICODE) ?>;

function actualizarCategoriaCargo() {
  const selectedId = parseInt(cargoSelect.value || '0', 10);
  const found = cargoMeta.find((item) => item.id === selectedId);
  categoriaInput.value = found && found.categoria ? found.categoria : '';
}

cargoSelect.addEventListener('change', actualizarCategoriaCargo);
actualizarCategoriaCargo();

document.querySelectorAll('.btnEditar').forEach((btn) => {
  btn.addEventListener('click', () => {
    const row = JSON.parse(btn.dataset.row || '{}');
    document.getElementById('id').value = row.id || '';
    document.getElementById('id_servicio').value = row.id_servicio || '';
    document.getElementById('cargo').value = row.cargo || '';
    document.getElementById('segmento').value = row.segmento || 'GENERAL';
    document.getElementById('ponderacion_prueba').value = row.ponderacion_prueba || '';
    document.getElementById('ponderacion_terreno').value = row.ponderacion_terreno || '';
    document.getElementById('exige_prueba_aprobada').value = row.exige_prueba_aprobada || 'S';
    document.getElementById('exige_terreno_aprobado').value = row.exige_terreno_aprobado || 'S';
    document.getElementById('activo').value = row.activo || 'S';
    document.getElementById('fecha_desde').value = row.fecha_desde || '';
    document.getElementById('fecha_hasta').value = row.fecha_hasta || '';
    document.getElementById('observacion').value = row.observacion || '';
    categoriaInput.value = row.categoria || '';

    document.getElementById('btnGuardar').classList.add('d-none');
    document.getElementById('btnActualizar').classList.remove('d-none');
    document.getElementById('btnCancelar').classList.remove('d-none');

    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});

document.getElementById('btnCancelar').addEventListener('click', () => {
  document.getElementById('frmRegla').reset();
  document.getElementById('id').value = '';
  document.getElementById('segmento').value = 'GENERAL';
  document.getElementById('exige_prueba_aprobada').value = 'S';
  document.getElementById('exige_terreno_aprobado').value = 'S';
  document.getElementById('activo').value = 'S';
  document.getElementById('btnGuardar').classList.remove('d-none');
  document.getElementById('btnActualizar').classList.add('d-none');
  document.getElementById('btnCancelar').classList.add('d-none');
  actualizarCategoriaCargo();
});

document.getElementById('buscarReglas').addEventListener('input', function () {
  const value = this.value.toLowerCase().trim();
  document.querySelectorAll('#tablaReglas tbody tr').forEach((row) => {
    row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
  });
});
</script>

</body>
</html>
