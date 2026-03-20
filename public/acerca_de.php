<?php
// acerca_de.php - Centro de Excelencia Operacional (CEO)
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Acerca de | Centro de Excelencia Operacional</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Estilos propios -->
  <style>
    body {
      background: #f7f9fc;
    }
    .hero {
      background: linear-gradient(135deg, #0d2a4d, #123f6e);
      color: #fff;
      padding: 80px 20px;
    }
    .hero h1 {
      font-weight: 700;
      letter-spacing: .5px;
    }
    .hero p {
      font-size: 1.2rem;
      opacity: .95;
    }
    .section {
      padding: 70px 0;
    }
    .icon-box {
      background: #fff;
      border-radius: 14px;
      padding: 30px;
      height: 100%;
      box-shadow: 0 6px 18px rgba(0,0,0,.08);
      transition: transform .3s ease;
    }
    .icon-box:hover {
      transform: translateY(-6px);
    }
    .icon {
      font-size: 2.4rem;
      color: #0d6efd;
      margin-bottom: 15px;
    }
    .highlight {
      background: #eef4ff;
      border-left: 6px solid #0d6efd;
      padding: 30px;
      border-radius: 10px;
    }
    .topbar {
  background: #ffffff;
  border-bottom: 1px solid #e5e7eb;
  padding: 15px 0;
}

.topbar .container {
  display: flex;
  align-items: center;
  gap: 20px;
}

.topbar .logo {
  height: 60px;
  width: auto;
}

.brand-title {
  font-weight: 700;
  color: #0d2a4d;
}

  </style>
</head>

<body>
<!-- HERO -->
<section class="hero text-center">
  <div class="container">
    <h1>Centro de Excelencia Operacional</h1>
    <p class="mt-3">
      Plataforma integral para la gestión de habilitación, evaluación y control operacional
    </p>
  </div>
</section>

<div class="container mt-3 d-flex justify-content-start">
  <a href="javascript:history.back()" class="btn btn-sm btn-light border">
    ← Volver al sistema
  </a>
</div>

<!-- PROPÓSITO -->
<section class="section">
  <div class="container">
    <div class="row justify-content-center mb-5">
      <div class="col-lg-8 text-center">
        <h2 class="fw-bold">Nuestro Propósito</h2>
        <p class="mt-3 text-muted">
          Modernizar y centralizar los procesos críticos de habilitación operacional,
          asegurando trazabilidad, eficiencia y seguridad en cada etapa.
        </p>
      </div>
    </div>

    <div class="row">
      <div class="col-md-4 mb-4">
        <div class="icon-box text-center">
          <div class="icon">🛡️</div>
          <h5 class="fw-bold">Seguridad</h5>
          <p class="text-muted">
            Garantizamos que cada persona habilitada cumple con los estándares
            técnicos y de seguridad exigidos.
          </p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="icon-box text-center">
          <div class="icon">📊</div>
          <h5 class="fw-bold">Trazabilidad</h5>
          <p class="text-muted">
            Cada evaluación, permiso y autorización queda registrada
            de forma histórica y auditable.
          </p>
        </div>
      </div>
      <div class="col-md-4 mb-4">
        <div class="icon-box text-center">
          <div class="icon">⚙️</div>
          <h5 class="fw-bold">Eficiencia Operacional</h5>
          <p class="text-muted">
            Eliminamos procesos manuales, planillas dispersas
            y reprocesos innecesarios.
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- QUÉ HACEMOS -->
<section class="section bg-light">
  <div class="container">
    <div class="row align-items-center">
      <div class="col-lg-6 mb-4">
        <h2 class="fw-bold">¿Qué hacemos?</h2>
        <p class="text-muted mt-3">
          El sistema del CEO integra en una sola plataforma todos los procesos
          asociados a la habilitación y evaluación de personas y empresas contratistas.
        </p>
        <ul class="mt-4">
          <li>Planificación y validación de solicitudes</li>
          <li>Gestión de permisos y autorizaciones</li>
          <li>Evaluaciones teóricas y prácticas</li>
          <li>Resultados, vigencias y certificaciones</li>
          <li>Reportes operativos y KPI estratégicos</li>
        </ul>
      </div>
      <div class="col-lg-6">
        <div class="highlight">
          <h5 class="fw-bold mb-3">Una plataforma, un control</h5>
          <p class="mb-0">
            Toda la información relevante se consolida en un sistema único,
            permitiendo decisiones informadas y oportunas.
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CÓMO LO HACEMOS -->
<section class="section">
  <div class="container">
    <div class="row justify-content-center mb-5">
      <div class="col-lg-8 text-center">
        <h2 class="fw-bold">¿Cómo lo hacemos?</h2>
        <p class="mt-3 text-muted">
          Mediante una arquitectura tecnológica moderna, segura y escalable.
        </p>
      </div>
    </div>

    <div class="row text-center">
      <div class="col-md-3 mb-4">
        <div class="icon-box">
          <div class="icon">💻</div>
          <h6 class="fw-bold">Plataforma Web</h6>
          <p class="text-muted">Acceso centralizado y por roles</p>
        </div>
      </div>
      <div class="col-md-3 mb-4">
        <div class="icon-box">
          <div class="icon">🗄️</div>
          <h6 class="fw-bold">Base de Datos</h6>
          <p class="text-muted">Modelo normalizado y auditable</p>
        </div>
      </div>
      <div class="col-md-3 mb-4">
        <div class="icon-box">
          <div class="icon">🔗</div>
          <h6 class="fw-bold">Integraciones</h6>
          <p class="text-muted">Sistemas corporativos y externos</p>
        </div>
      </div>
      <div class="col-md-3 mb-4">
        <div class="icon-box">
          <div class="icon">📈</div>
          <h6 class="fw-bold">KPI & Reportes</h6>
          <p class="text-muted">Análisis operativo y estratégico</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CIERRE -->
<section class="hero text-center">
  <div class="container">
    <h2 class="fw-bold">Excelencia Operacional en acción</h2>
    <p class="mt-3">
      Información confiable, procesos claros y decisiones basadas en datos.
    </p>
  </div>
</section>

</body>
</html>
