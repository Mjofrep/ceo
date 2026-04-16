<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ============================================================
   PROTECCIÓN DE ACCESO
   ============================================================ */
// Debe estar autenticado y ser rol Evaluador (id_rol = 4)
if (
    empty($_SESSION['auth']) ||
    !in_array((int)$_SESSION['auth']['id_rol'], [4, 5], true)
) {
    header('Location: login_formacion_evaluador.php');
    exit;
}


// Debe existir un alumno cargado en sesión
if (empty($_SESSION['evaluado'])) {
    header('Location: login_formacion_evaluador.php');
    exit;
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

/* ============================================================
   VARIABLES DE SESIÓN
   ============================================================ */
$evaluador = $_SESSION['auth'];      // Datos del evaluador
$alumno    = $_SESSION['evaluado'];  // Datos del alumno a evaluar

// Nombre completo del alumno (puedes ajustar si luego guardas apellido aparte)
$nombreAlumno = trim($alumno['nombre'] ?? '');

// Servicio y RUT del alumno (lo usaremos para iniciar la prueba)
$pruebas = [];
$rutAlumno  = trim($alumno['rut'] ?? '');

if ($rutAlumno !== '') {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT fp.id, fp.id_servicio, fp.id_agrupacion, fp.cuadrilla, fp.fecha_programacion, fp.intento, fp.resultado, fp.estado,
               a.titulo AS titulo_prueba
        FROM ceo_formacion_programadas fp
        LEFT JOIN ceo_formacion_agrupacion a ON a.id = fp.id_agrupacion
        WHERE fp.rut = :rut
          AND fp.estado = 'PENDIENTE'
          AND fp.resultado = 'PENDIENTE'
          AND fp.tipo = 'PRUEBA'
        ORDER BY fp.fecha_programacion ASC, fp.id ASC
    ");
    $stmt->execute([':rut' => $rutAlumno]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $stmtServ = $pdo->prepare("SELECT servicio FROM ceo_formacion_servicios WHERE id = :id");
        foreach ($rows as $row) {
            $stmtServ->execute([':id' => (int)$row['id_servicio']]);
            $nombreServicio = (string)$stmtServ->fetchColumn();
            $pruebas[] = [
                'id_programada' => (int)$row['id'],
                'id_servicio'   => (int)$row['id_servicio'],
                'id_agrupacion' => (int)($row['id_agrupacion'] ?? 0),
                'titulo_prueba' => (string)($row['titulo_prueba'] ?? ''),
                'servicio'      => $nombreServicio,
                'nsolicitud'    => null,
                'cuadrilla'     => $row['cuadrilla'] ?? null,
                'fecha_prog'    => $row['fecha_programacion'] ?? null,
                'intento'       => $row['intento'] ?? null
            ];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Panel Evaluador - Formaciones | <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
  background: radial-gradient(1000px 800px at 10% -10%, #eef4ff 0%, #ffffff 37%), #f6f9fc;
  color: #0f172a;
}
.topbar {
  background: linear-gradient(90deg, #f9fbff 0%, #ffffff 100%);
  padding: .6rem;
  border-bottom: 1px solid rgba(13,110,253,0.12);
  margin-bottom: 1.5rem;
  box-shadow: 0 2px 4px rgba(0,0,0,0.04);
}
.topbar .logo {
  height: 70px;
}
.panel-card{
  max-width: 600px;
  background: white;
  border-radius: 18px;
  padding: 2rem;
  box-shadow: 0 10px 30px rgba(13,110,253,0.08);
  border: 1px solid rgba(13,110,253,0.12);
}
.menu-btn{
  border-radius: 12px;
  padding: 1rem;
  font-size: 1.1rem;
  box-shadow: 0 6px 16px rgba(13,110,253,0.10);
}
</style>
</head>

<body>

<header class="topbar text-center">
    <img src="<?= APP_LOGO ?>" class="logo" alt="Logo">
    <h1 class="h4 mt-2"><?= APP_NAME ?></h1>
    <small class="text-secondary"><?= APP_SUBTITLE ?></small>
</header>

<div class="d-flex justify-content-center mt-4">

<table class="table-borderless" style="margin: 0 auto;">

    <!-- ======================================================
         FILA 1: PANEL EVALUADOR + PANEL ALUMNO
         ====================================================== -->
    <tr>
        <!-- PANEL EVALUADOR -->
        <td>
            <div class="container d-flex justify-content-center">
                <div class="panel-card">
                    <h2 class="h4 text-center mb-4">Panel Evaluador - Formaciones</h2>

                    <div class="mb-3">
                        <strong>Evaluador:</strong><br>
                        <?= htmlspecialchars($evaluador['nombre'] ?? '') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Rol:</strong><br>
                        <?= htmlspecialchars($evaluador['rol'] ?? '') ?>
                    </div>

                    <?php if (!empty($evaluador['empresa'])): ?>
                        <div class="mb-3">
                            <strong>Empresa:</strong><br>
                            <?= htmlspecialchars($evaluador['empresa']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <strong>Fecha:</strong>
                        <?= date('d/m/Y') ?> —
                        <strong>Hora:</strong>
                        <?= date('H:i') ?>
                    </div>

                    <hr class="my-4">
                </div>
            </div>
        </td>

        <!-- PANEL ALUMNO -->
        <td>
            <div class="container d-flex justify-content-center">
                <div class="panel-card">
                    <h2 class="h4 text-center mb-4">Panel Alumno</h2>

                    <div class="mb-3">
                        <strong>Alumno:</strong><br>
                        <?= htmlspecialchars($nombreAlumno) ?><br>
                        <small class="text-muted"><?= htmlspecialchars($rutAlumno) ?></small>
                    </div>

                    <div class="mb-3">
                        <strong>Cargo:</strong><br>
                            <?= htmlspecialchars($alumno['cargo']['cargo'] ?? '') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Empresa Contratista:</strong><br>
                        <?= htmlspecialchars($alumno['empresa']['nombre'] ?? '') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Servicio:</strong><br>
                        <?= htmlspecialchars($pruebas[0]['servicio'] ?? '') ?>
                    </div>

                    <div class="mb-3">
                        <strong>Unidad Operativa:</strong><br>
                        <?= htmlspecialchars($alumno['uo']['desc_uo'] ?? '') ?>
                    </div>

                    <div class="mb-3">
                        <strong>N° Formacion:</strong><br>
                        <?= htmlspecialchars((string)($pruebas[0]['cuadrilla'] ?? '')) ?>
                    </div>

                    <div class="mb-4">
                        <strong>Fecha:</strong>
                        <?= date('d/m/Y') ?> —
                        <strong>Hora:</strong>
                        <?= date('H:i') ?>
                    </div>

                    <hr class="my-4">
                </div>
            </div>
        </td>
    </tr>

    <!-- ======================================================
         FILA 2: BOTONES (INICIAR PRUEBA / CERRAR SESIÓN)
         ====================================================== -->
    <tr>
        <td colspan="2" class="text-center">
            <div class="d-grid gap-3 mt-4" style="max-width: 400px; margin: 0 auto;">

                <!-- Botón para iniciar prueba teórica -->
<?php if (!empty($pruebas) && $rutAlumno !== ''): ?>
    <?php foreach ($pruebas as $p): ?>
        <?php $tituloPrueba = trim((string)($p['titulo_prueba'] ?? '')); ?>
        <a href="formacion_iniciar_prueba.php?id_servicio=<?= urlencode((string)$p['id_servicio']) ?>&rut_alumno=<?= urlencode($rutAlumno) ?>&id_programada=<?= urlencode((string)$p['id_programada']) ?>&id_agrupacion=<?= urlencode((string)($p['id_agrupacion'] ?? 0)) ?>&nsolicitud=<?= urlencode((string)($p['nsolicitud'] ?? '')) ?>"
           class="btn btn-primary menu-btn">
           📝 Iniciar Prueba Teórica — <?= htmlspecialchars($tituloPrueba !== '' ? $tituloPrueba : $p['servicio']) ?>
          </a>
    <?php endforeach; ?>
<?php else: ?>
    <button type="button" class="btn btn-secondary menu-btn" disabled>
        No hay pruebas pendientes para iniciar
    </button>
<?php endif; ?>


                <!-- Botón para cerrar sesión -->
                <a href="login_formacion_evaluador.php"
                   class="btn btn-danger menu-btn">
                   🚪 Cerrar Sesión
                </a>

            </div>
        </td>
    </tr>

</table>

</div>

</body>
</html>
