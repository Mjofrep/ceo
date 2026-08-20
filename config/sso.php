<?php
declare(strict_types=1);

if (!defined('APP_BASE')) {
    require_once __DIR__ . '/app.php';
}

function ceoSsoSecret(): string
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    $secretFile = __DIR__ . '/sso.secret.php';
    if (is_file($secretFile)) {
        $loaded = require $secretFile;
        if (is_string($loaded) && trim($loaded) !== '') {
            $secret = trim($loaded);
            return $secret;
        }
    }

    $secret = hash('sha256', __FILE__ . '|ceonext-sso|2026');
    return $secret;
}

function ceoSsoApps(): array
{
    return [
        'gp' => [
            'receiver' => '/ceo.noetica.cl/public/gp_sso.php',
            'login' => '/ceo.noetica.cl/public/gp_login.php',
            'allowed_ceo_roles' => [1],
        ],
        'salud' => [
            'receiver' => '/ceo_salud/auth/sso.php',
            'login' => '/ceo_salud/auth/login.php',
            'allowed_ceo_roles' => [1, 5],
        ],
        'forms' => [
            'receiver' => '/form2/sso_login.php',
            'login' => '/form2/index.php?path=/login',
            'allowed_ceo_roles' => [1, 5],
        ],
        'feedback' => [
            'receiver' => '/feedback/admin/sso.php',
            'login' => '/feedback/admin/login.php',
            'allowed_ceo_roles' => [1, 5],
        ],
    ];
}

function ceoSsoIsValidApp(string $app): bool
{
    return array_key_exists($app, ceoSsoApps());
}

function ceoSsoBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function ceoSsoReceiverUrl(string $app): string
{
    $apps = ceoSsoApps();
    if (!isset($apps[$app]['receiver'])) {
        throw new InvalidArgumentException('Aplicacion SSO no valida.');
    }

    return ceoSsoBaseUrl() . (string)$apps[$app]['receiver'];
}

function ceoSsoLoginUrl(string $app): string
{
    $apps = ceoSsoApps();
    if (!isset($apps[$app]['login'])) {
        throw new InvalidArgumentException('Login SSO no valido.');
    }

    return ceoSsoBaseUrl() . (string)$apps[$app]['login'];
}

function ceoSsoAllowedRoleIds(string $app): array
{
    $apps = ceoSsoApps();
    $roles = $apps[$app]['allowed_ceo_roles'] ?? [];
    return is_array($roles) ? array_map('intval', $roles) : [];
}

function ceoSsoRoleAllowed(string $app, int $roleId): bool
{
    $allowedRoles = ceoSsoAllowedRoleIds($app);
    if ($allowedRoles === []) {
        return true;
    }

    return in_array($roleId, $allowedRoles, true);
}

function ceoSsoBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ceoSsoBase64UrlDecode(string $value): string|false
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode(strtr($value, '-_', '+/'), true);
}

function ceoSsoEncodeToken(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('No fue posible generar el token SSO.');
    }

    $encodedPayload = ceoSsoBase64UrlEncode($json);
    $signature = hash_hmac('sha256', $encodedPayload, ceoSsoSecret(), true);

    return $encodedPayload . '.' . ceoSsoBase64UrlEncode($signature);
}

function ceoSsoDecodeToken(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $encodedSignature] = $parts;
    if ($encodedPayload === '' || $encodedSignature === '') {
        return null;
    }

    $expectedSignature = ceoSsoBase64UrlEncode(hash_hmac('sha256', $encodedPayload, ceoSsoSecret(), true));
    if (!hash_equals($expectedSignature, $encodedSignature)) {
        return null;
    }

    $json = ceoSsoBase64UrlDecode($encodedPayload);
    if (!is_string($json) || $json === '') {
        return null;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }

    $app = trim((string)($payload['app'] ?? ''));
    $exp = (int)($payload['exp'] ?? 0);

    if (!ceoSsoIsValidApp($app) || $exp < time()) {
        return null;
    }

    return $payload;
}

function ceoSsoCreatePayload(string $app, array $auth): array
{
    $email = strtolower(trim((string)($auth['correo'] ?? '')));
    if ($email === '') {
        throw new RuntimeException('La sesion actual no tiene correo asociado para SSO.');
    }

    $now = time();

    return [
        'app' => $app,
        'email' => $email,
        'nombre' => trim((string)($auth['nombre'] ?? '')),
        'id_rol' => (int)($auth['id_rol'] ?? 0),
        'iat' => $now,
        'exp' => $now + 60,
        'nonce' => bin2hex(random_bytes(16)),
    ];
}

function ceoSsoBuildLoginUrl(string $app, array $auth): string
{
    $token = ceoSsoEncodeToken(ceoSsoCreatePayload($app, $auth));
    return ceoSsoReceiverUrl($app) . '?token=' . rawurlencode($token);
}

function ceoSsoReadPayloadFromRequest(?string $expectedApp = null): ?array
{
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        return null;
    }

    $payload = ceoSsoDecodeToken($token);
    if ($payload === null) {
        return null;
    }

    if ($expectedApp !== null && (string)($payload['app'] ?? '') !== $expectedApp) {
        return null;
    }

    return $payload;
}
