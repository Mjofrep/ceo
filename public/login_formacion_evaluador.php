<?php
declare(strict_types=1);
ob_start();   // 🔒 INICIO BUFFER

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/../src/Csrf.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../config/functions.php';

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $err = 'Sesión expirada. Recarga la página.';
        if (function_exists('auditLog')) {
            auditLog('LOGIN_FAIL', 'auth_evaluador', null, [
                'motivo' => 'csrf'
            ]);
        }
    } else {

        $rutAlumno = trim($_POST['usuario'] ?? '');
        $clave     = trim($_POST['password'] ?? '');

        if ($rutAlumno === '' || $clave === '') {
            $err = 'Debe ingresar Rut y clave.';
            if (function_exists('auditLog')) {
                auditLog('LOGIN_FAIL', 'auth_evaluador', null, [
                    'motivo' => 'campos_vacios'
                ], [
                    'codigo' => $rutAlumno
                ]);
            }
        } else {

            try {

                $pdo = db();

                /**
                 * 1) VALIDAMOS LOGIN DEL EVALUADOR (tu lógica original)
                 */
                $sql = "
                    SELECT 
                        u.id,
                        u.nombres,
                        u.apellidos,
                        u.correo,
                        u.id_rol,
                        r.rol,
                        u.id_empresa,
                        e.nombre AS empresa
                    FROM ceo_usuarios u
                    LEFT JOIN ceo_rol r ON r.id = u.id_rol
                    LEFT JOIN ceo_empresas e ON e.id = u.id_empresa
                    WHERE u.clavepruebas = :clave
                    AND u.id_rol in (4,5)
                    AND u.estado = 'A'
                    LIMIT 1
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute(['clave' => $clave]);
                $usr = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$usr) {
                    $err = "Usuario o clave incorrecta.";
                    if (function_exists('auditLog')) {
                        auditLog('LOGIN_FAIL', 'auth_evaluador', null, [
                            'motivo' => 'credenciales'
                        ], [
                            'codigo' => $rutAlumno
                        ]);
                    }
                } else {

                    /**
                     * 2) VALIDAMOS RUT DEL ALUMNO EN ceo_formacion_participantes_solicitud
                     */
                    $sql = "SELECT * 
                            FROM ceo_formacion_programadas
                            WHERE rut = :rut
                            and estado like 'PENDIENTE'
                            and resultado like 'PENDIENTE'
                            and tipo = 'PRUEBA'";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(['rut' => $rutAlumno]);
                    $pruebas = $stmt->fetchall(PDO::FETCH_ASSOC);


                    if (!$pruebas) {
                        $err = "El RUT ingresado no registra pruebas pendientes.";
                        if (function_exists('auditLog')) {
                            auditLog('LOGIN_FAIL', 'auth_evaluador', null, [
                                'motivo' => 'sin_pruebas'
                            ], [
                                'codigo' => $rutAlumno
                            ]);
                        }
                    }
                     else {

                        // ✔ Datos base del alumno
                        //$id_solicitud = $alumno['id_solicitud'];
                        //$id_cargo     = $alumno['id_cargo'];

                        // ✔ CARGO
                        //$sql = "SELECT cargo FROM ceo_cargo_contratistas WHERE id = :id";
                        $sql = "SELECT a.*, b.cargo, c.desc_uo, d.nombre AS nom_empresa FROM `ceo_contratistas` a
                                LEFT  JOIN ceo_cargo_contratistas b ON b.id = a.id_cargo
                                LEFT  JOIN ceo_uo c ON c.id  = a.uo
                                LEFT  JOIN ceo_empresas d ON d.id = a.id_empresa
                                where a.rut = :rut";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(['rut' => $rutAlumno]);
                        $cargo = $stmt->fetch(PDO::FETCH_ASSOC);


                        // ✔ SOLICITUD (CORREGIDO)
                        //$sql = "SELECT a.*, b.id_servicio FROM ceo_formacion_solicitudes a INNER JOIN ceo_formacion b ON a.nsolicitud = b.nsolicitud WHERE a.nsolicitud = :id";
                        //$stmt = $pdo->prepare($sql);
                        //$stmt->execute(['id' => $id_solicitud]);
                        //$sol = $stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$cargo) {
                                $err = "El alumno no está registrado como contratista.";
                                if (function_exists('auditLog')) {
                                    auditLog('LOGIN_FAIL', 'auth_evaluador', null, [
                                        'motivo' => 'sin_contratista'
                                    ], [
                                        'codigo' => $rutAlumno
                                    ]);
                                }
                            }
                              else {
                            // ✔ Campos correctos en tu BD
                            $id_servicio = $alumno['id_servicio'];      // este es el ID del servicio
                            $id_empresa  = $cargo['id_empresa'];   // es tu campo de empresa
                            $id_uo       = $cargo['uo'];

                            // ✔ EMPRESA
                            $sql = "SELECT nombre, rut FROM ceo_empresas WHERE id = :id";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute(['id' => $id_empresa]);
                            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

                            // ✔ SERVICIO (nombre)
                            $sql = "SELECT servicio FROM ceo_formacion_servicios WHERE id = :id";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute(['id' => $id_servicio]);
                                $servicio = $stmt->fetchColumn();

                                if (function_exists('auditLog')) {
                                    auditLog('LOGIN_OK', 'auth_evaluador', null, [
                                        'id_servicio' => $id_servicio ?? null,
                                        'id_empresa' => $id_empresa ?? null,
                                        'id_uo' => $id_uo ?? null
                                    ], [
                                        'id' => $usr['id'] ?? null,
                                        'codigo' => $usr['correo'] ?? '',
                                        'nombre' => trim(($usr['nombres'] ?? '').' '.($usr['apellidos'] ?? '')),
                                        'rol' => $usr['rol'] ?? ''
                                    ]);
                                }

                            // ✔ UO
                            $sql = "SELECT desc_uo, subgerencia FROM ceo_uo WHERE id = :id";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute(['id' => $id_uo]);
                            $uo = $stmt->fetch(PDO::FETCH_ASSOC);

                            /**
                             * 3) Sesión del evaluador
                             */
                            $_SESSION['auth'] = [
                                'nombre'      => trim(($usr['nombres'] ?? '').' '.($usr['apellidos'] ?? '')),
                                'correo'      => $usr['correo'] ?? '',
                                'rol'         => $usr['rol'] ?? 'Evaluador',
                                'id_rol'      => (int)($usr['id_rol'] ?? 4),
                                'id_empresa'  => (int)($usr['id_empresa'] ?? 0),
                                'empresa'     => $usr['empresa'] ?? '',
                                'id'          => $usr['id'] ?? 0,
                                'codigo'      => $rutAlumno
                            ];

                            /**
                             * 4) Sesión del ALUMNO con valores REALES
                             */
                            // Datos "base" del alumno (contratista)
                            $_SESSION['evaluado'] = [
                                'rut'     => $rutAlumno,
                                'cargo'   => $cargo,
                                'empresa' => $empresa,
                                'uo'      => $uo,
                                'pruebas' => []
                            ];
                            
                            // Si quieres nombre, t�malo desde contratistas (si existe en esa tabla)
                            // o arma uno simple desde la primera fila si tu tabla lo trae.
                            $_SESSION['evaluado']['nombre'] = trim(
                                ($pruebas[0]['nombres'] ?? '') . ' ' .
                                ($pruebas[0]['apellidop'] ?? '') . ' ' .
                                ($pruebas[0]['apellidom'] ?? '')
                            );
                            
                            // Por cada prueba pendiente, agregamos su info
                            $sqlServ = "SELECT servicio FROM ceo_formacion_servicios WHERE id = :id";
                            $stmtServ = $pdo->prepare($sqlServ);
                            
                            foreach ($pruebas as $p) {
                                $idServicio = (int)($p['id_servicio'] ?? 0);
                            
                                $stmtServ->execute(['id' => $idServicio]);
                                $nombreServicio = (string)$stmtServ->fetchColumn();
                            
                                $_SESSION['evaluado']['pruebas'][] = [
                                    'id_programada' => (int)$p['id'],                 // id de ceo_formacion_programadas
                                    'id_servicio'   => $idServicio,
                                    'servicio'      => $nombreServicio,
                                    'nsolicitud'    => $p['nsolicitud'] ?? null,      // si existe columna
                                    'cuadrilla'     => $p['cuadrilla'] ?? null,
                                    'fecha_prog'    => $p['fecha_programacion'] ?? null,
                                    'intento'       => $p['intento'] ?? null
                                ];
                            }


                            // ✔ Redirección correcta
                            file_put_contents(__DIR__.'/debug_redirect.log',
                                    date('Y-m-d H:i:s')." REDIRIGIENDO\n",
                                    FILE_APPEND
                                );

                            $logFile = __DIR__ . '/debug_redirect.log';
                            
                            $logData = [
                                'fecha'    => date('Y-m-d H:i:s'),
                                'auth'     => $_SESSION['auth'],
                                'evaluado' => $_SESSION['evaluado'],
                                'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                                'script'   => $_SERVER['PHP_SELF'] ?? 'N/A'
                            ];
                            
                            file_put_contents(
                                $logFile,
                                json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL . str_repeat('-', 60) . PHP_EOL,
                                FILE_APPEND
                            );

                            ob_end_clean(); // 🧹 Limpia cualquier salida previa
                            header('Location: /ceo.noetica.cl/public/formacion_evaluador_home.php');
                            exit;
                        }
                    }
                }

            } catch (Throwable $e) {
                $err = "Error interno: " . $e->getMessage();
            }
        }
    }
}

$csrf = Csrf::token();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Formaciones - Ingreso Evaluador Teórica | <?= APP_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
main {
  min-height: calc(100vh - 180px);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
}
:root{
  --brand-1: #f6f9fc;
  --brand-2: #ffffff;
  --accent:  #0d6efd;
  --text-1:  #0f172a;
  --muted:   #6b7280;
}
html,body{height:100%;}
body{
  background: radial-gradient(1000px 800px at 10% -10%, #eef4ff 0%, #ffffff 37%), var(--brand-1);
  color: var(--text-1);
}
.topbar {
  background: linear-gradient(90deg, #f9fbff 0%, #ffffff 100%);
  padding: .5rem;
  border-bottom: 1px solid rgba(13,110,253,0.12);
  margin-bottom: 1rem;
}
.topbar .logo {
  height: 70px;
}
.login-card{
  max-width: 420px;
  background: var(--brand-2);
  border: 1px solid rgba(13,110,253,0.10);
  box-shadow: 0 10px 30px rgba(13,110,253,0.07);
  border-radius: 18px;
}
</style>
</head>

<body>

<header class="topbar text-center">
    <img src="<?= APP_LOGO ?>" class="logo">
    <h1 class="h4"><?= APP_NAME ?></h1>
    <small class="text-secondary"><?= APP_SUBTITLE ?></small>
</header>

<main>

<div class="login-card p-4 p-md-5 m-3 w-100">

  <div class="mb-4 text-center">
    <h1 class="h4 mb-1">Ingreso Evaluador</h1>
    <p class="text-secondary">Acceso exclusivo</p>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>

    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

    <div class="form-floating mb-3">
      <input type="text" class="form-control" id="usuario" name="usuario" placeholder="11.111.111-1" required>
      <label for="usuario">Rut persona a Evaluar</label>
    </div>

    <div class="form-floating mb-3">
      <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
      <label for="password">Clave</label>
    </div>

    <button class="btn btn-primary w-100 btn-lg" type="submit">Ingresar</button>

  </form>

  <hr class="my-4">
  <div class="text-center small text-secondary">
    © <?= date('Y') ?> Centro de Excelencia Operacional — Enel
  </div>

</div>

</main>

</body>
</html>

