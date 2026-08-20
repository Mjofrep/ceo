<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';
require_once __DIR__ . '/../config/sso.php';

$payload = ceoSsoReadPayloadFromRequest('gp');
if ($payload === null) {
    header('Location: ' . GP_LOGIN_PATH);
    exit;
}

if (!ceoSsoRoleAllowed('gp', (int)($payload['id_rol'] ?? 0))) {
    header('Location: ' . GP_LOGIN_PATH);
    exit;
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
if ($email === '') {
    http_response_code(403);
    echo 'No existe un correo valido para acceder al Gestor de Preguntas.';
    exit;
}

$pdo = db();
gpEnsureTables($pdo);

$stmt = $pdo->prepare("SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre
    FROM ceo_gp_usuarios u
    INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
    WHERE LOWER(TRIM(COALESCE(u.correo, ''))) = :correo
      AND u.estado = 'A'
      AND r.estado = 'A'
    LIMIT 1");
$stmt->execute([':correo' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(403);
    echo 'Tu cuenta no tiene acceso al Gestor de Preguntas.';
    exit;
}

session_regenerate_id(true);
gpSetAuthSession($user);
unset($_SESSION['gp_force_password_change'], $_SESSION['gp_force_password_change_user_id']);

$stmtUpd = $pdo->prepare('UPDATE ceo_gp_usuarios SET ultimo_acceso = NOW() WHERE id = :id');
$stmtUpd->execute([':id' => (int)$user['id']]);

header('Location: ' . GP_HOME_PATH);
exit;
