# Inventario Interno CEO - Solucion Preliminar

## Objetivo

Disenar un modulo de inventario interno para el CEO que permita:

- mantener un inventario base;
- registrar entradas;
- registrar salidas;
- controlar prestamos y devoluciones;
- saber a quien se entrego un elemento y para que;
- diferenciar productos consumibles, activos serializados, herramientas y elementos de control simple.

La solucion debe integrarse al sistema actual CEONext, reutilizando autenticacion, sesiones y tabla de usuarios existente.

## Contexto tecnico del repo

La propuesta considera la arquitectura actual del sistema:

- login central en `config/index.php`;
- control de sesion en `config/auth.php`;
- uso de `$_SESSION['auth']`;
- aplicacion PHP procedural con paginas y endpoints en `public/`;
- acceso a datos por PDO contra MySQL.

Esto significa que el modulo de inventario debe construirse siguiendo el mismo patron del resto del sistema, sin introducir una arquitectura paralela.

## Alcance funcional inicial

El modulo debe cubrir cuatro grupos principales:

- alimentos y articulos de consumo:
  jugos, colaciones, papel higienico, toalla nova y similares;
- equipamiento tecnologico:
  computadores, tablets, televisores, radios, datashow, mouse, teclados, lentes 3D y similares;
- herramientas:
  herramientas manuales, electricas y accesorios;
- elementos de proteccion personal:
  cascos, guantes, lentes, chalecos, zapatos, ropa de trabajo y similares.

## Problema a resolver

No todos los elementos deben comportarse igual. El modulo debe soportar, desde el inicio, al menos estos escenarios:

- productos que solo requieren existir en un inventario base;
- productos que manejan entradas pero no necesariamente salida nominal por persona;
- productos que manejan entradas y salidas por cantidad;
- productos que deben quedar asociados a una persona responsable;
- herramientas o equipos que deben indicar a quien se entregaron, para que, desde cuando y si fueron devueltos;
- equipos tecnologicos que pueden requerir numero de serie, marca, modelo y estado.

## Propuesta funcional

La recomendacion es no modelar el inventario solo por categoria, sino por **tipo de control**. Una misma categoria puede contener articulos con comportamientos distintos.

### Tipos de control sugeridos

#### 1. Solo inventario

Para elementos que solo necesitan estar registrados y eventualmente ajustarse por conteo manual.

Ejemplos:

- mobiliario menor;
- elementos de apoyo interno sin rotacion frecuente.

Comportamiento:

- existe stock o cantidad base;
- no exige flujo formal de entrada/salida diaria;
- admite ajustes manuales con observacion.

#### 2. Consumible

Para articulos que se compran, almacenan y consumen por cantidad.

Ejemplos:

- jugos;
- colaciones;
- papel higienico;
- toalla nova;
- articulos de aseo;
- EPP desechable.

Comportamiento:

- maneja entradas;
- maneja salidas por cantidad;
- no requiere seguimiento unitario;
- puede registrar salida a area, actividad o responsable.

#### 3. Prestable

Para herramientas o equipos que se entregan temporalmente y deben volver.

Ejemplos:

- radios;
- datashow;
- herramientas electricas;
- cajas de herramientas;
- instrumentos de trabajo compartidos.

Comportamiento:

- maneja entradas;
- maneja prestamos;
- registra responsable de entrega;
- registra destinatario;
- registra motivo o uso;
- registra fecha de entrega, fecha estimada de devolucion y fecha real de devolucion;
- deja historial completo.

#### 4. Serializado o activo identificable

Para equipos que deben seguirse por unidad.

Ejemplos:

- computadores;
- tablets;
- televisores;
- radios individuales;
- lentes 3D si se desea trazabilidad por unidad.

Comportamiento:

- cada unidad puede tener numero de serie o codigo patrimonial;
- permite estado por unidad;
- permite asignacion a persona o ubicacion;
- permite registrar baja, mantencion, reposicion o cambio de responsable.

## Roles y acceso

### Requisito solicitado

El modulo debe manejar pantalla de acceso usando la tabla de usuarios actual, con perfiles:

- `Administrador`
- `Registro asistencia`

### Recomendacion de implementacion

No crear un login aparte. Reutilizar:

- `config/index.php` para autenticacion;
- `config/auth.php` para proteccion de paginas;
- `ceo_usuarios` y `ceo_rol` para autorizacion.

### Permisos iniciales sugeridos

#### Administrador

Puede:

- crear y editar productos;
- registrar entradas;
- registrar salidas;
- registrar prestamos y devoluciones;
- ajustar stock;
- dar de baja elementos;
- ver reportes;
- administrar catalogos auxiliares si se requiere.

#### Registro asistencia

Puede:

- acceder al modulo;
- consultar inventario;
- registrar entradas y salidas operativas si se autoriza;
- registrar entregas y devoluciones;
- ver historial.

No deberia, en principio:

- eliminar productos;
- modificar configuracion base;
- cambiar tipos de control;
- ejecutar bajas definitivas sin validacion.

### Nota de negocio

Aunque el nombre del perfil `Registro asistencia` no fue creado para inventario, se puede reutilizar en una primera etapa porque ya existe en el sistema. Si el modulo crece, convendra evaluar un rol dedicado como `Encargado inventario`, sin cambiar el esquema de login.

## Propuesta de menu y pantallas

### 1. Acceso desde menu principal

Agregar una nueva tarjeta o acceso en `public/general.php`:

- nombre sugerido: `Inventario CEO`
- visible solo para `Administrador` y `Registro asistencia`

### 2. Pantallas base del modulo

#### `public/inventario.php`

Pantalla principal con:

- resumen de stock;
- alertas;
- filtros por categoria, tipo de control, estado y ubicacion;
- tabla de productos;
- accesos rapidos a movimientos.

#### `public/inventario_producto.php`

Formulario de alta y edicion de producto maestro.

Debe permitir definir:

- categoria;
- nombre;
- descripcion;
- unidad de medida;
- tipo de control;
- stock minimo;
- si usa serie;
- si requiere responsable al salir;
- si queda activo o inactivo.

#### `public/inventario_movimientos.php`

Consulta de historial de movimientos con filtros por:

- fecha;
- producto;
- categoria;
- tipo de movimiento;
- usuario que registra;
- persona receptora;
- estado de devolucion.

#### `public/inventario_movimiento_form.php`

Formulario operativo para registrar:

- entrada;
- salida;
- prestamo;
- devolucion;
- ajuste;
- baja.

#### `public/inventario_detalle.php`

Detalle por producto con:

- stock actual;
- historial;
- responsables vigentes;
- series o unidades asociadas;
- prestamos activos.

#### `public/inventario_reportes.php`

Reportes y exportaciones:

- stock actual;
- movimientos por periodo;
- consumos por categoria;
- elementos entregados y no devueltos;
- inventario valorizado si luego se incorpora costo.

## Reglas funcionales recomendadas

### Reglas generales

- no permitir stock negativo para productos consumibles y prestables;
- toda salida debe quedar asociada al usuario que la registra;
- toda devolucion debe enlazarse a la entrega o prestamo original;
- los ajustes manuales deben exigir observacion;
- las bajas deben exigir motivo.

### Para herramientas y equipos prestables

- una entrega debe registrar obligatoriamente:
  persona receptora, finalidad, fecha y estado de salida;
- la devolucion debe registrar:
  fecha, estado de retorno y observacion si vuelve con dano o incompleto;
- debe poder saberse en cualquier momento:
  quien tiene cada herramienta o equipo.

### Para activos serializados

- cada unidad debe poder existir aunque comparta el mismo producto maestro;
- una unidad puede estar:
  disponible, prestada, asignada, en mantencion, dada de baja;
- el historial debe conservar cambios de responsable y ubicacion.

### Para consumibles

- la salida puede ser por cantidad total;
- opcionalmente puede quedar asociada a:
  area, actividad, jornada o responsable;
- debe existir alerta cuando el stock baje del minimo definido.

## Modelo de datos preliminar

La mejor opcion es separar:

- catalogo de productos;
- unidades serializadas;
- movimientos;
- catalogos auxiliares.

### Tabla 1: `ceo_inv_categoria`

Objetivo:

- clasificar productos.

Campos sugeridos:

- `id`
- `nombre`
- `descripcion`
- `estado`

Registros iniciales:

- Alimentos
- Equipamiento tecnologico
- Herramientas
- EPP

### Tabla 2: `ceo_inv_tipo_control`

Objetivo:

- definir comportamiento operativo.

Campos sugeridos:

- `id`
- `codigo`
- `nombre`
- `descripcion`

Codigos sugeridos:

- `SIMPLE`
- `CONSUMIBLE`
- `PRESTAMO`
- `SERIALIZADO`

### Tabla 3: `ceo_inv_producto`

Objetivo:

- maestro de productos.

Campos sugeridos:

- `id`
- `codigo_interno`
- `nombre`
- `descripcion`
- `id_categoria`
- `id_tipo_control`
- `unidad_medida`
- `stock_minimo`
- `usa_serie`
- `requiere_responsable_salida`
- `controla_stock`
- `activo`
- `creado_por`
- `creado_en`
- `actualizado_por`
- `actualizado_en`

Comentarios:

- `controla_stock` permite contemplar productos que solo se catalogan;
- `usa_serie` sirve para activos individuales;
- `requiere_responsable_salida` sera clave para herramientas y equipos.

### Tabla 4: `ceo_inv_item`

Objetivo:

- registrar unidades individuales cuando el producto sea serializado o se quiera trazabilidad por unidad.

Campos sugeridos:

- `id`
- `id_producto`
- `codigo_item`
- `numero_serie`
- `marca`
- `modelo`
- `estado_item`
- `ubicacion_actual`
- `responsable_actual`
- `fecha_compra`
- `observacion`
- `activo`

Comentarios:

- esta tabla no es obligatoria para todos los productos;
- se usa especialmente en tecnologia y algunas herramientas.

### Tabla 5: `ceo_inv_movimiento`

Objetivo:

- guardar toda entrada, salida, prestamo, devolucion, ajuste o baja.

Campos sugeridos:

- `id`
- `tipo_movimiento`
- `id_producto`
- `id_item`
- `cantidad`
- `fecha_movimiento`
- `entregado_a`
- `rut_entregado_a`
- `area_destino`
- `motivo`
- `documento_referencia`
- `id_movimiento_relacionado`
- `estado_resultante`
- `observacion`
- `registrado_por`
- `registrado_en`

Valores sugeridos para `tipo_movimiento`:

- `INICIAL`
- `ENTRADA`
- `SALIDA`
- `PRESTAMO`
- `DEVOLUCION`
- `AJUSTE`
- `BAJA`

Comentarios:

- `id_movimiento_relacionado` enlaza devolucion con prestamo original;
- `id_item` se usa cuando hay trazabilidad unitaria;
- `cantidad` cubre productos por volumen o consumo.

### Tabla 6: `ceo_inv_ubicacion`

Objetivo:

- manejar lugares internos del CEO.

Campos sugeridos:

- `id`
- `nombre`
- `descripcion`
- `estado`

Ejemplos:

- Bodega central
- Sala de capacitacion
- Oficina administrativa
- Terreno

### Tabla 7 opcional: `ceo_inv_stock_corte`

Objetivo:

- guardar snapshots o cierres mensuales.

Uso:

- reporte historico;
- conciliacion;
- auditoria.

## Calculo de stock recomendado

### Opcion sugerida para primera etapa

Calcular stock desde movimientos.

Ventajas:

- evita inconsistencias entre stock y historial;
- deja trazabilidad completa;
- facilita auditoria.

Regla general:

- entradas, devoluciones y ajustes positivos suman;
- salidas, prestamos consumibles, bajas y ajustes negativos restan.

### Opcion complementaria

Mantener un campo de stock resumido por producto solo como apoyo de consulta, recalculado desde movimientos. Esto mejora rendimiento si la tabla crece.

## Flujos operativos principales

### Flujo A: crear inventario base

1. Crear categorias y tipos de control.
2. Crear productos.
3. Registrar movimiento inicial por cada producto o item.

### Flujo B: ingreso de consumibles

1. Seleccionar producto.
2. Registrar entrada con cantidad.
3. Guardar proveedor o referencia si se requiere.
4. Actualizar stock.

### Flujo C: salida de consumibles

1. Seleccionar producto.
2. Ingresar cantidad.
3. Indicar responsable, area o motivo.
4. Validar que exista stock suficiente.

### Flujo D: prestamo de herramienta o equipo

1. Seleccionar producto o item.
2. Registrar a quien se entrega.
3. Registrar para que se entrega.
4. Registrar fecha y observaciones.
5. Dejar estado como `prestado` o equivalente.

### Flujo E: devolucion

1. Buscar prestamo activo.
2. Registrar fecha de devolucion.
3. Indicar estado de retorno.
4. Cerrar relacion con movimiento de salida.

### Flujo F: baja o perdida

1. Seleccionar producto o item.
2. Registrar motivo.
3. Registrar observacion y responsable del registro.
4. Cambiar estado y descontar si corresponde.

## Reportes recomendados

### Reportes operativos

- stock actual por categoria;
- movimientos del dia;
- productos bajo stock minimo;
- prestamos vigentes;
- elementos no devueltos;
- historial por producto;
- historial por persona receptora.

### Reportes de control

- inventario valorizado, si luego se agrega costo unitario;
- diferencias entre conteo fisico y sistema;
- productos sin movimiento por largo periodo;
- herramientas extraviadas o con devolucion vencida.

## Integracion con usuarios actuales

### Uso de tabla de usuarios

El modulo debe tomar desde la sesion:

- `$_SESSION['auth']['id']`
- `$_SESSION['auth']['rol']`
- `$_SESSION['auth']['id_rol']`

Esto permitira:

- filtrar acceso;
- guardar quien registra cada movimiento;
- dejar trazabilidad por usuario del sistema.

### Control de acceso sugerido

En las paginas del modulo:

- incluir `require_once __DIR__ . '/../config/auth.php';`
- validar que el rol sea `Administrador` o `Registro asistencia`

Se recomienda una funcion auxiliar comun para no repetir la validacion en cada archivo del modulo.

## Decisiones que conviene tomar antes del desarrollo

Hay algunas definiciones de negocio que seria ideal cerrar antes de construir:

### 1. Alcance de personas receptoras

Definir si una entrega puede hacerse a:

- solo usuarios internos del sistema;
- cualquier persona escrita manualmente;
- ambos.

Recomendacion:

- permitir ambos, guardando nombre y RUT o identificador libre.

### 2. Nivel de serializacion

Definir si todos los equipos tecnologicos se controlaran por unidad o solo algunos.

Recomendacion:

- serializar desde el inicio al menos computadores, tablets, radios y datashow.

### 3. Devolucion obligatoria

Definir que categorias exigen devolucion.

Recomendacion:

- herramientas y equipos compartidos deben usar prestamo/devolucion;
- consumibles no.

### 4. Ubicaciones internas

Definir si el CEO quiere controlar bodega, salas y oficinas como ubicaciones formales.

Recomendacion:

- si, porque mejora la trazabilidad sin gran complejidad.

### 5. Costos y valorizacion

Definir si en esta etapa se necesita valor unitario o solo cantidades.

Recomendacion:

- dejar el modelo preparado, pero implementar valorizacion en una segunda etapa si no es urgente.

## Fases de desarrollo sugeridas

### Fase 1. Base operativa

Entregables:

- acceso desde menu;
- validacion por roles existentes;
- tablas base;
- catalogo de productos;
- categorias;
- tipos de control;
- movimiento inicial;
- entradas y salidas simples;
- consulta de stock actual.

Resultado:

- ya permite manejar alimentos, aseo y EPP consumible.

### Fase 2. Prestamos y custodias

Entregables:

- prestamos;
- devoluciones;
- historial por responsable;
- alertas de elementos no devueltos.

Resultado:

- ya permite controlar herramientas y equipos compartidos.

### Fase 3. Activos serializados

Entregables:

- unidades por serie;
- estado por unidad;
- asignacion a persona o ubicacion;
- bajas y mantenciones.

Resultado:

- ya permite controlar computadores, tablets, radios y otros activos sensibles.

### Fase 4. Reportes y madurez

Entregables:

- exportaciones Excel;
- cierres de inventario;
- ajustes masivos;
- valorizacion opcional;
- tablero de alertas.

## Recomendacion final

La mejor estrategia para el CEO es construir un **modulo unico de inventario**, pero con comportamiento configurable por producto. Eso evita tener un sistema separado para alimentos, otro para herramientas y otro para tecnologia.

La clave del diseno debe ser:

- un producto maestro flexible;
- movimientos auditables;
- trazabilidad de responsable cuando corresponda;
- control por unidad solo en los activos que realmente lo requieren;
- reutilizacion del login y roles actuales.

## Proxima etapa sugerida

Si esta propuesta queda alineada, el siguiente paso recomendable es preparar:

1. definicion final de tablas MySQL;
2. mapa de pantallas del modulo;
3. primer desarrollo de Fase 1 dentro de `public/`.

