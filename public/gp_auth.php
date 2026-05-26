<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/Csrf.php';

const GP_LOGIN_PATH = '/ceo.noetica.cl/public/gp_login.php';
const GP_HOME_PATH = '/ceo.noetica.cl/public/gp_home.php';
const GP_FORCE_PASSWORD_CHANGE_PATH = '/ceo.noetica.cl/public/gp_force_password_change.php';
const GP_INITIAL_PASSWORD = 'Inicio2026$';

function gpEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gpEnsureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_roles (
      id INT NOT NULL AUTO_INCREMENT,
      codigo VARCHAR(30) NOT NULL,
      nombre VARCHAR(80) NOT NULL,
      descripcion VARCHAR(255) NULL,
      estado ENUM('A','I') NOT NULL DEFAULT 'A',
      PRIMARY KEY (id),
      UNIQUE KEY uq_gp_roles_codigo (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmtRole = $pdo->prepare("INSERT INTO ceo_gp_roles (codigo, nombre, descripcion)
      VALUES (:codigo, :nombre, :descripcion)
      ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), estado = 'A'");
    foreach (gpDefaultRoles() as $role) {
        $stmtRole->execute($role);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_usuarios (
      id INT NOT NULL AUTO_INCREMENT,
      usuario VARCHAR(80) NOT NULL,
      nombres VARCHAR(120) NOT NULL,
      apellidos VARCHAR(160) NULL,
      correo VARCHAR(160) NULL,
      clave_hash VARCHAR(255) NOT NULL,
      id_rol INT NOT NULL,
      estado ENUM('A','I') NOT NULL DEFAULT 'A',
      creado_por INT NULL,
      creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      ultimo_acceso DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_gp_usuarios_usuario (usuario),
      KEY idx_gp_usuarios_rol (id_rol),
      KEY idx_gp_usuarios_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_usuario_servicio (
      id INT NOT NULL AUTO_INCREMENT,
      id_usuario INT NOT NULL,
      destino ENUM('HABILITACION','FORMACION','AMBOS') NOT NULL DEFAULT 'AMBOS',
      id_servicio INT NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_gp_usuario_servicio (id_usuario, destino, id_servicio),
      KEY idx_gp_usuario_servicio_usuario (id_usuario),
      KEY idx_gp_usuario_servicio_servicio (id_servicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_fuentes (
      id INT NOT NULL AUTO_INCREMENT,
      titulo VARCHAR(255) NOT NULL,
      destino ENUM('HABILITACION','FORMACION') NOT NULL,
      id_servicio INT NOT NULL,
      id_agrupacion INT NULL,
      id_area INT NULL,
      tipo_origen ENUM('MANUAL','TXT','PDF','DOCX','XLSX','CSV','MIXTO') NOT NULL DEFAULT 'MANUAL',
      modo_uso ENUM('IA','IMPORTAR_PREGUNTAS','EXTRAER_PREGUNTAS_IA') NOT NULL DEFAULT 'IA',
      parser_tipo VARCHAR(40) NULL,
      import_estado ENUM('PENDIENTE','IMPORTADO','ERROR') NULL,
      import_resumen TEXT NULL,
      texto_fuente MEDIUMTEXT NOT NULL,
      estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_gp_fuentes_destino_servicio (destino, id_servicio),
      KEY idx_gp_fuentes_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_documentos (
      id INT NOT NULL AUTO_INCREMENT,
      id_fuente INT NOT NULL,
      nombre_original VARCHAR(255) NOT NULL,
      ruta_archivo VARCHAR(500) NOT NULL,
      mime_type VARCHAR(120) NULL,
      extension VARCHAR(20) NOT NULL,
      tamano_bytes BIGINT NULL,
      texto_extraido MEDIUMTEXT NULL,
      estado ENUM('ACTIVO','SIN_TEXTO','ERROR','ANULADO') NOT NULL DEFAULT 'ACTIVO',
      error_text TEXT NULL,
      creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_gp_documentos_fuente (id_fuente)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE ceo_gp_documentos MODIFY estado ENUM('ACTIVO','SIN_TEXTO','ERROR','ANULADO') NOT NULL DEFAULT 'ACTIVO'");
    } catch (Throwable $e) {
        // Best effort for existing installations; CREATE TABLE above covers new ones.
    }
    try {
        $pdo->exec("ALTER TABLE ceo_gp_fuentes MODIFY tipo_origen ENUM('MANUAL','TXT','PDF','DOCX','XLSX','CSV','MIXTO') NOT NULL DEFAULT 'MANUAL'");
    } catch (Throwable $e) {
        // Best effort for existing installations; CREATE TABLE above covers new ones.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_generaciones (
      id INT NOT NULL AUTO_INCREMENT,
      id_fuente INT NOT NULL,
      destino ENUM('HABILITACION','FORMACION') NULL,
      id_servicio INT NULL,
      id_agrupacion INT NULL,
      id_area INT NULL,
      cantidad_solicitada INT NOT NULL,
      dificultad VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
      modelo VARCHAR(80) NULL,
      prompt_text LONGTEXT NULL,
      respuesta_json LONGTEXT NULL,
      estado ENUM('GENERADA','ERROR') NOT NULL DEFAULT 'GENERADA',
      error_text TEXT NULL,
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_gp_generaciones_fuente (id_fuente),
      KEY idx_gp_generaciones_contexto (destino, id_servicio, id_agrupacion, id_area),
      KEY idx_gp_generaciones_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        "ALTER TABLE ceo_gp_fuentes ADD COLUMN modo_uso ENUM('IA','IMPORTAR_PREGUNTAS','EXTRAER_PREGUNTAS_IA') NOT NULL DEFAULT 'IA' AFTER tipo_origen",
        "ALTER TABLE ceo_gp_fuentes MODIFY modo_uso ENUM('IA','IMPORTAR_PREGUNTAS','EXTRAER_PREGUNTAS_IA') NOT NULL DEFAULT 'IA'",
        "ALTER TABLE ceo_gp_fuentes ADD COLUMN parser_tipo VARCHAR(40) NULL AFTER modo_uso",
        "ALTER TABLE ceo_gp_fuentes ADD COLUMN import_estado ENUM('PENDIENTE','IMPORTADO','ERROR') NULL AFTER parser_tipo",
        "ALTER TABLE ceo_gp_fuentes ADD COLUMN import_resumen TEXT NULL AFTER import_estado",
        "ALTER TABLE ceo_gp_generaciones ADD COLUMN destino ENUM('HABILITACION','FORMACION') NULL AFTER id_fuente",
        "ALTER TABLE ceo_gp_generaciones ADD COLUMN id_servicio INT NULL AFTER destino",
        "ALTER TABLE ceo_gp_generaciones ADD COLUMN id_agrupacion INT NULL AFTER id_servicio",
        "ALTER TABLE ceo_gp_generaciones ADD COLUMN id_area INT NULL AFTER id_agrupacion",
        "ALTER TABLE ceo_gp_generaciones ADD KEY idx_gp_generaciones_contexto (destino, id_servicio, id_agrupacion, id_area)",
        "ALTER TABLE ceo_gp_preguntas ADD COLUMN id_operador_asignado INT NULL AFTER estado",
        "ALTER TABLE ceo_gp_preguntas ADD COLUMN fecha_asignacion_operacion DATETIME NULL AFTER id_operador_asignado",
        "ALTER TABLE ceo_gp_preguntas ADD COLUMN asignado_operacion_por INT NULL AFTER fecha_asignacion_operacion",
    ] as $sqlAlter) {
        try {
            $pdo->exec($sqlAlter);
        } catch (Throwable $e) {
            // Existing installations may already have these columns/indexes.
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_preguntas (
      id INT NOT NULL AUTO_INCREMENT,
      id_fuente INT NULL,
      id_generacion INT NULL,
      destino ENUM('HABILITACION','FORMACION') NOT NULL,
      id_servicio INT NOT NULL,
      id_agrupacion INT NULL,
      id_area INT NULL,
      pregunta TEXT NOT NULL,
      imagen VARCHAR(500) NULL,
      video VARCHAR(500) NULL,
      retropos TEXT NULL,
      retroneg TEXT NULL,
      referencia TEXT NULL,
      import_referencia VARCHAR(255) NULL,
      origen ENUM('MANUAL','IA') NOT NULL DEFAULT 'MANUAL',
      estado ENUM('BORRADOR','REVISION','OPERACION','OBSERVADA','APROBADA_OPERACION','CERRADA','PUBLICADA','DESCARTADA') NOT NULL DEFAULT 'BORRADOR',
      id_operador_asignado INT NULL,
      fecha_asignacion_operacion DATETIME NULL,
      asignado_operacion_por INT NULL,
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      actualizado_por INT NULL,
      fecha_actualizacion DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_gp_preguntas_estado (estado),
      KEY idx_gp_preguntas_destino_servicio (destino, id_servicio),
      KEY idx_gp_preguntas_fuente (id_fuente),
      KEY idx_gp_preguntas_generacion (id_generacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_alternativas (
      id INT NOT NULL AUTO_INCREMENT,
      id_pregunta INT NOT NULL,
      orden INT NOT NULL DEFAULT 0,
      alternativa TEXT NOT NULL,
      correcta ENUM('S','N') NOT NULL DEFAULT 'N',
      imagen VARCHAR(500) NULL,
      video VARCHAR(500) NULL,
      estado ENUM('A','I') NOT NULL DEFAULT 'A',
      PRIMARY KEY (id),
      KEY idx_gp_alternativas_pregunta (id_pregunta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_revision (
      id INT NOT NULL AUTO_INCREMENT,
      id_pregunta INT NOT NULL,
      estado_desde VARCHAR(40) NULL,
      estado_hasta VARCHAR(40) NOT NULL,
      comentario TEXT NULL,
      creado_por INT NULL,
      fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_gp_revision_pregunta (id_pregunta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE ceo_gp_preguntas ADD COLUMN import_referencia VARCHAR(255) NULL AFTER referencia");
    } catch (Throwable $e) {
        // Existing installations may already have this column.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_gp_publicacion (
      id INT NOT NULL AUTO_INCREMENT,
      id_pregunta INT NOT NULL,
      destino ENUM('HABILITACION','FORMACION') NOT NULL,
      tabla_pregunta VARCHAR(80) NOT NULL,
      id_pregunta_oficial INT NOT NULL,
      publicado_por INT NULL,
      fecha_publicacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_gp_publicacion_pregunta (id_pregunta),
      KEY idx_gp_publicacion_destino (destino)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function gpDefaultRoles(): array
{
    return [
        ['codigo' => 'ADMIN', 'nombre' => 'Administrador', 'descripcion' => 'Administra usuarios, roles y configuracion del Gestor de Preguntas'],
        ['codigo' => 'CREADOR', 'nombre' => 'Creador', 'descripcion' => 'Carga fuentes y genera preguntas manuales o asistidas por IA'],
        ['codigo' => 'REVISOR', 'nombre' => 'Revisor', 'descripcion' => 'Revisa, corrige y envia preguntas a Operacion'],
        ['codigo' => 'OPERACION', 'nombre' => 'Operacion', 'descripcion' => 'Valida contenido, alternativas y respuesta correcta'],
        ['codigo' => 'PUBLICADOR', 'nombre' => 'Publicador', 'descripcion' => 'Cierra y publica preguntas oficiales'],
    ];
}

function gpAuth(): ?array
{
    return isset($_SESSION['gp_auth']) && is_array($_SESSION['gp_auth']) ? $_SESSION['gp_auth'] : null;
}

function gpHasPendingPasswordChange(): bool
{
    return !empty($_SESSION['gp_force_password_change']);
}

function gpSetAuthSession(array $user): void
{
    $_SESSION['gp_auth'] = [
        'id' => (int)$user['id'],
        'usuario' => (string)$user['usuario'],
        'nombre' => trim((string)$user['nombres'] . ' ' . (string)($user['apellidos'] ?? '')),
        'correo' => (string)($user['correo'] ?? ''),
        'rol_codigo' => (string)$user['rol_codigo'],
        'rol_nombre' => (string)$user['rol_nombre'],
    ];
}

function gpPasswordComplexityErrors(string $password): array
{
    $errors = [];
    if (mb_strlen($password, 'UTF-8') < 10) {
        $errors[] = 'La nueva clave debe tener al menos 10 caracteres.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'La nueva clave debe incluir al menos una mayuscula.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'La nueva clave debe incluir al menos una minuscula.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'La nueva clave debe incluir al menos un numero.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'La nueva clave debe incluir al menos un signo especial.';
    }
    if ($password === GP_INITIAL_PASSWORD) {
        $errors[] = 'La nueva clave no puede ser la clave inicial.';
    }
    return $errors;
}

function gpUpdateUserPassword(PDO $pdo, int $userId, string $newPassword): void
{
    $errors = gpPasswordComplexityErrors($newPassword);
    if ($userId <= 0 || $errors) {
        throw new RuntimeException($errors ? implode(' ', $errors) : 'Usuario invalido para cambio de clave.');
    }

    $stmt = $pdo->prepare('UPDATE ceo_gp_usuarios SET clave_hash = :clave_hash, ultimo_acceso = NOW() WHERE id = :id LIMIT 1');
    $stmt->execute([
        ':clave_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $userId,
    ]);
}

function gpRequireAuth(): array
{
    $auth = gpAuth();
    if (!$auth) {
        header('Location: ' . GP_LOGIN_PATH);
        exit;
    }

    if (gpHasPendingPasswordChange()) {
        header('Location: ' . GP_FORCE_PASSWORD_CHANGE_PATH);
        exit;
    }

    return $auth;
}

function gpHasRole(string|array $roles): bool
{
    $auth = gpAuth();
    if (!$auth) {
        return false;
    }

    $allowed = is_array($roles) ? $roles : [$roles];
    return in_array((string)($auth['rol_codigo'] ?? ''), $allowed, true);
}

function gpRequireRole(string|array $roles): void
{
    gpRequireAuth();
    if (!gpHasRole($roles)) {
        http_response_code(403);
        echo '<div class="alert alert-danger m-5">No autorizado para acceder a esta pagina.</div>';
        exit;
    }
}

function gpLogin(PDO $pdo, string $usuario, string $clave): array
{
    gpEnsureTables($pdo);

    $stmt = $pdo->prepare("SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre
        FROM ceo_gp_usuarios u
        INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
        WHERE u.usuario = :usuario
          AND u.estado = 'A'
          AND r.estado = 'A'
        LIMIT 1");
    $stmt->execute([':usuario' => $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($clave, (string)$user['clave_hash'])) {
        return ['ok' => false, 'msg' => 'Usuario o clave incorrecta.'];
    }

    gpSetAuthSession($user);

    if ($clave === GP_INITIAL_PASSWORD) {
        $_SESSION['gp_force_password_change'] = true;
        $_SESSION['gp_force_password_change_user_id'] = (int)$user['id'];
        return ['ok' => false, 'force_password_change' => true, 'msg' => 'Debes cambiar tu clave inicial antes de continuar.'];
    }

    unset($_SESSION['gp_force_password_change'], $_SESSION['gp_force_password_change_user_id']);

    $stmtUpd = $pdo->prepare('UPDATE ceo_gp_usuarios SET ultimo_acceso = NOW() WHERE id = :id');
    $stmtUpd->execute([':id' => (int)$user['id']]);

    return ['ok' => true, 'msg' => 'OK'];
}

function gpLogout(): void
{
    unset($_SESSION['gp_auth']);
    unset($_SESSION['gp_force_password_change'], $_SESSION['gp_force_password_change_user_id']);
}

function gpRoleOptions(PDO $pdo): array
{
    gpEnsureTables($pdo);
    return $pdo->query("SELECT id, codigo, nombre FROM ceo_gp_roles WHERE estado = 'A' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function gpIsAdmin(): bool
{
    return gpHasRole('ADMIN');
}
