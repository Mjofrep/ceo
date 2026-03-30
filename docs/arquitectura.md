# Arquitectura

## Vision general

CEONext es una aplicacion web PHP con estructura principalmente procedural. La capa de presentacion y logica de negocio vive en archivos de pagina dentro de `public/`, la configuracion central se encuentra en `config/` y algunas clases de apoyo en `src/`. El acceso a datos se realiza mediante PDO hacia MySQL.

## Estructura del proyecto

- `public/`: paginas PHP, acciones y endpoints AJAX.
- `config/`: configuracion global, conexion a DB, control de sesion y utilidades.
- `src/`: clases minimas (Auth, Csrf).
- `vendor/`: dependencias PHP empaquetadas (dompdf, phpspreadsheet, phpmailer, etc.).
- `uploads/`, `storage/`, `tmp/`: almacenamiento de archivos y procesos temporales.

## Componentes principales

### Presentacion y endpoints

- Paginas PHP que renderizan HTML y ejecutan la logica del caso de uso en el mismo archivo.
- Endpoints AJAX dedicados para operaciones de listas, guardado y eliminacion (prefijo `ajax_`).
- Flujos especializados: importacion/exportacion Excel, reportes y evaluaciones.

### Autenticacion y sesiones

- Login centralizado en `config/index.php` (formulario de acceso).
- Validacion de credenciales en `src/Auth.php` y sesion en `$_SESSION['auth']`.
- Control de inactividad y cache en `config/auth.php`.
- Tokens CSRF en `src/Csrf.php`.

### Acceso a datos

- Conexion unica via PDO en `config/db.php`.
- Consultas SQL embebidas por pagina.
- Reutilizacion de utilidades en `config/functions.php` y `public/functions.php`.

## Flujo de datos (alto nivel)

1) Usuario inicia sesion en `config/index.php`.
2) Se crea sesion y se redirige a `public/general.php`.
3) Las paginas consultan y modifican datos via PDO.
4) Endpoints AJAX apoyan acciones asincronas en formularios.
5) Exportaciones generan archivos (Excel/PDF) y los entregan al navegador.

## Dominios funcionales detectados

En base a nombres de archivos y consultas SQL en `public/`:

- Solicitudes y planificacion: `nueva_solicitud.php`, `solicitudes.php`, `agenda.php`.
- Evaluaciones: teoricas y terreno (`pruebas_teoricas*.php`, `evaluador_*`, `guardar_terreno.php`).
- Habilitaciones y vigencias: `habilitaciones.php`, `recalcular*` en `public/functions.php`.
- Catalogos y maestros: `mant_*` (usuarios, roles, empresas, servicios, UO, procesos).
- Reportes: `informe_*`, `reporte*`, `historial_*`.
- Integraciones de archivos: `importar_prueba_excel.php`, `export_prueba_excel.php`, `procesa_excel.php`.

## Dependencias relevantes

- `dompdf/dompdf`: generacion de PDF.
- `phpoffice/phpspreadsheet`: lectura/escritura de Excel.
- `phpmailer/phpmailer`: envio de correo.

## Riesgos y consideraciones tecnicas

- Logica y presentacion estan acopladas (PHP procedural por pagina).
- No hay capa ORM ni migraciones registradas en el repo.
- Configuracion de credenciales esta en archivo (evitar exponerlas en ambientes compartidos).
