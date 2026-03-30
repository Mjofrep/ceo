# Requerimientos

## Requerimientos funcionales

1) Autenticacion de usuarios con roles y control de sesion.
2) Gestion de solicitudes (creacion, edicion, historial, estado).
3) Planificacion de evaluaciones y agenda operativa.
4) Ejecucion de evaluaciones teoricas y de terreno.
5) Gestion de habilitaciones y vigencias asociadas a servicios/procesos.
6) Mantenedores de catalogos (usuarios, roles, empresas, servicios, UO, procesos, etc.).
7) Importacion y exportacion de datos en Excel.
8) Generacion de reportes y exportables.
9) Notificaciones y envio de correos asociados a flujos.

## Requerimientos no funcionales

- Seguridad: sesiones con expiracion por inactividad, CSRF en formularios, cache control en paginas protegidas.
- Disponibilidad: operacion en entorno web LAMP/MAMP.
- Rendimiento: consultas directas a DB, paginacion cuando aplique en listados.
- Auditoria basica: logs y registros de acciones en DB (segun tablas y reportes).
- Compatibilidad: navegadores modernos con soporte de Bootstrap 5.

## Requerimientos de datos

- Persistencia en MySQL con tablas prefijo `ceo_`.
- Entidades relevantes detectadas por consultas: usuarios, roles, empresas, solicitudes, evaluaciones, resultados, vigencias, servicios, UO, procesos, patios, responsables.

## Restricciones

- La configuracion de DB esta embebida en `config/db.php` (se recomienda externalizar a variables de entorno en produccion).
- Sin framework MVC formal; la logica reside en cada pagina.
