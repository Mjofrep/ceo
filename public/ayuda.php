<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ayuda</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h4 class="mb-3">📘 Ayuda del Sistema</h4>

    <div class="card shadow-sm">
        <div class="card-body text-center">
            <img src="/ceo.noetica.cl/public/ayuda/flujohabilitacion.png"
                 class="img-fluid"
                 alt="Ayuda permisos CEO">
        </div>
    </div>

    <a href="javascript:history.back()" class="btn btn-secondary mt-3">
        ← Volver
    </a>
</div>

</body>
</html>
