<?php
// /src/Auth.php
declare(strict_types=1);
require_once __DIR__.'/../config/db.php';

final class Auth {
    public static function login(string $codigo, string $password): array {
        // Retorna [ok=>bool, msg=>string]
        $sql = "SELECT a.id, a.codigo, a.nombres, a.apellidos, a.clave_hash, a.estado, r.rol
                FROM ceo_usuarios a
                LEFT JOIN ceo_rol r ON r.id = a.id_rol
                WHERE a.clavepruebas = :codigo LIMIT 1";
        echo $sql;
        $stmt = db()->prepare($sql);
        $stmt->execute([':codigo' => $password]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['ok'=>false, 'msg'=>'Usuario o contraseña inválidos.'];
        }
        if ($user['estado'] !== 'A') {
            return ['ok'=>false, 'msg'=>'Usuario inactivo. Contacte a soporte.'];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['auth'] = [
            'id'        => (int)$user['id'],
            'codigo'    => $user['codigo'],
            'nombre'    => $user['nombres'].' '.$user['apellidos'],
            'logged_at' => time(),
            'rol' => $user['rol']
        ];
        return ['ok'=>true, 'msg'=>'OK'];
    }
}
