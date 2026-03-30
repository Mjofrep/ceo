# Diseno de interfaz

## Principios observados

- UI basada en Bootstrap 5 (incluido via CDN en paginas clave).
- Formularios con validaciones basicas en servidor.
- Layouts responsivos con grillas Bootstrap.

## Elementos visuales

- Identidad visual definida en `config/app.php` (nombre, subtitulo, logo, favicon).
- Barra superior con logo y branding en el login (`config/index.php`).
- Estilos inline por pagina (CSS embebido en varios archivos).

## Patrones de navegacion

- Acceso centralizado en login y redireccion a `public/general.php`.
- Navegacion por paginas funcionales (solicitudes, evaluaciones, reportes, mantenedores).
- Endpoints AJAX para acciones de grilla y formularios dinamicos.

## Componentes UI frecuentes

- Tablas con listados (solicitudes, usuarios, reportes).
- Formularios para alta/edicion (mant_* y flujos de solicitud).
- Modal/alertas (Bootstrap) para mensajes de error/confirmacion.

## Guias para extender el diseno

- Reutilizar variables de `config/app.php` para branding.
- Mantener consistencia con Bootstrap 5 y clases utilitarias.
- Consolidar estilos repetidos en un archivo CSS comun cuando sea posible.
