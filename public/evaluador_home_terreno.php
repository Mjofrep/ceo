<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ============================================================
   PROTECCIÓN ACCESO
   ============================================================ */
if (empty($_SESSION['auth']) ||!in_array((int)$_SESSION['auth']['id_rol'], [4, 5], true)) {
    header('Location: login_evaluador_terreno.php');
    exit;
}


require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

$evaluador = $_SESSION['auth'];

/* ============================================================
   OBTENER SOLICITUDES DEL DÍA (solo fecha actual)
   ============================================================ */
$fechaHoy = date('Y-m-d');

$solicitudes = [];

try {
    $pdo = db();

    $sql = "
SELECT 
    A.id,
    A.cuadrilla,
    B.fecha,
    A.id_servicio,
    C.servicio,
    B.empresa,
    D.nombre,
    A.tipo,
    A.estado
FROM ceo_evaluaciones_programadas A
INNER JOIN ceo_habilitacion B 
    ON A.cuadrilla = B.cuadrilla
INNER JOIN ceo_servicios_pruebas C 
    ON C.id = A.id_servicio
INNER JOIN ceo_empresas D 
    ON D.id = B.empresa
WHERE A.tipo = 'TERRENO'
  AND A.estado = 'PENDIENTE';
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $solicitudes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $errorMsg = "Error cargando solicitudes: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Evaluador Terreno | <?= APP_NAME ?></title>
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
}
.topbar .logo { height: 70px; }

.table-box{
  background:white;
  border-radius:18px;
  padding:25px;
  box-shadow:0 10px 30px rgba(13,110,253,0.1);
  border:1px solid rgba(13,110,253,0.12);
}
</style>
</head>

<body>

<header class="topbar text-center">
    <img src="<?= APP_LOGO ?>" class="logo">
    <h1 class="h4 mt-2"><?= APP_NAME ?></h1>
    <small class="text-secondary"><?= APP_SUBTITLE ?></small>
</header>

<div class="container mt-4">

    <div class="table-box">

        <h3 class="mb-3 text-center">Solicitudes Agendadas Pendientes</h3>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if (empty($solicitudes)): ?>
            <div class="alert alert-warning text-center">
                No existen procesos
            </div>
        <?php else: ?>

<form method="get" action="evaluador_generar_terreno.php">

    <table class="table table-striped table-bordered">
        <thead class="table-primary">
            <tr>
                <th>Sel.</th>
                <th>ID Eval.</th>
                <th>N° Proceso</th>
                <th>Fecha</th>
                <th>Servicio</th>
                <th>Nombre</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($solicitudes as $s): ?>
                <tr>
                    <td class="text-center">
                        <input type="checkbox" name="id_evaluacion[]" value="<?= (int)$s['id'] ?>">
                    </td>
                    <td><?= (int)$s['id'] ?></td>
                    <td><?= htmlspecialchars($s['cuadrilla']) ?></td>
                    <td><?= date('d/m/Y', strtotime($s['fecha'])) ?></td>
                    <td><?= htmlspecialchars($s['servicio']) ?></td>
                    <td><?= htmlspecialchars($s['nombre']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="text-center mt-4">
        <button class="btn btn-primary btn-lg">
            Generar Pruebas de Terreno
        </button>
    </div>
    <div class="text-center mt-4">
        <a href="login_evaluador_terreno.php" class="btn btn-primary btn-lg">
            Salir
        </a>
    </div>

</form>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
