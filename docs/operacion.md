# Operacion y despliegue

## Requisitos de entorno

- PHP 7.4+ (recomendado 8.x).
- MySQL/MariaDB.
- Servidor web (Apache o Nginx).

## Configuracion

- `config/app.php`: branding, rutas base y modo debug.
- `config/db.php`: credenciales y DSN de base de datos.
- `config/auth.php`: control de sesion e inactividad.

### Variables y secretos

- Evitar exponer credenciales reales en repositorios compartidos.
- Reemplazar valores en `config/db.php` por variables de entorno en produccion.

## Estructura de despliegue

- El webroot debe apuntar a `public/`.
- Asegurar permisos de escritura en `uploads/`, `storage/` y `tmp/`.

## Operacion diaria

- Revisar logs de PHP/servidor para errores.
- Validar que las rutas de carga/descarga de Excel/PDF funcionen.
- Verificar expiracion de sesiones y flujos de login.

## Seguridad

- Mantener `APP_DEBUG` en `false` en produccion.
- Usar HTTPS en ambientes productivos.
- Aplicar backups periodicos de la base de datos.
