<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/sso.php';

$app = trim((string)($_GET['app'] ?? ''));

if (!ceoSsoIsValidApp($app)) {
    http_response_code(404);
    echo 'Aplicacion SSO no valida.';
    exit;
}

$auth = $_SESSION['auth'] ?? null;
if (!is_array($auth) || $auth === []) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

if (!ceoSsoRoleAllowed($app, (int)($auth['id_rol'] ?? 0))) {
    header('Location: ' . ceoSsoLoginUrl($app));
    exit;
}

try {
    header('Location: ' . ceoSsoBuildLoginUrl($app, $auth));
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible iniciar el acceso automatico.';
    exit;
}
