# Manual de Usuario

## Modulo Gestion de Preguntas

El modulo Gestion de Preguntas permite crear, importar, revisar, visar y publicar preguntas para CEONext en los contextos de `HABILITACION` y `FORMACION`.

Su objetivo es controlar el ciclo completo de un cuestionario antes de dejarlo disponible en los bancos oficiales del sistema.

## Objetivos del modulo

- Centralizar fuentes de contenido tecnico.
- Importar preguntas existentes desde documentos.
- Generar nuevas preguntas con IA a partir de una fuente.
- Revisar y corregir preguntas antes de enviarlas a Operacion.
- Visar conceptualmente los cuestionarios en Operacion.
- Publicar preguntas visadas en las tablas oficiales de CEONext.

## Pantallas principales

### Inicio

Pantalla: `gp_home.php`

Desde aqui se accede a las opciones del modulo segun el rol del usuario.

Accesos principales:

- `Fuentes y documentos`
- `Generacion IA`
- `Revision y Correccion`
- `Visacion Operacion`
- `Usuarios y roles` (solo administradores)

## Acceso al sistema

Pantalla: `gp_login.php`

Para ingresar:

1. Escribe usuario.
2. Escribe clave.
3. Presiona `Ingresar`.

### Cambio obligatorio de clave inicial

Si un usuario ingresa usando la clave inicial `Inicio2026$`, el sistema obliga a cambiarla antes de continuar.

Pantalla: `gp_force_password_change.php`

La nueva clave debe cumplir estas reglas:

- minimo 10 caracteres
- al menos una mayuscula
- al menos una minuscula
- al menos un numero
- al menos un signo especial

## Roles del modulo

### Administrador

- administra usuarios
- accede a todas las funciones

### Creador

- carga fuentes
- genera preguntas con IA

### Revisor

- revisa y corrige preguntas
- envia preguntas a Operacion
- publica preguntas visadas

### Operacion

- realiza visacion conceptual
- observa o visa cuestionarios

## Flujo general del modulo

1. Se carga una fuente.
2. Desde la fuente se importan preguntas existentes o se generan nuevas preguntas con IA.
3. Las preguntas se revisan y corrigen.
4. Se envian a Operacion para visacion conceptual.
5. Si son visadas, vuelven a Revision como `VISADA`.
6. Finalmente se publican al banco oficial CEONext.

## Fuentes y documentos

Pantalla: `gp_fuentes.php`

Aqui se registra el material base para importar o generar preguntas.

### Datos principales de una fuente

- Titulo
- Destino: `HABILITACION` o `FORMACION`
- Servicio
- Agrupacion / tematica
- Area de competencia
- Uso del documento
- Documento(s)
- Texto manual opcional

### Tipos de archivo soportados

- `TXT`
- `CSV`
- `XLSX`
- `DOCX`
- `PPTX`
- `PDF`

### Uso del documento

#### 1. Generar preguntas con IA

Se usa cuando el documento contiene contenido tecnico, pero no necesariamente preguntas listas.

El sistema:

- extrae el texto del documento
- guarda la fuente
- deja el contenido disponible para la pantalla `Generacion IA`

Uso recomendado:

- manuales
- presentaciones
- procedimientos
- instructivos tecnicos

#### 2. Importar preguntas existentes para revision

Se usa cuando el archivo ya trae preguntas y alternativas y quieres cargarlas sin usar IA.

Formatos soportados en esta opcion:

- `PDF`
- `XLSX`

El sistema intenta reconocer preguntas usando reglas locales y las deja directamente en `REVISION`.

Uso recomendado:

- Excel estructurado
- PDF con formato ordenado y repetible

#### 3. Extraer preguntas existentes con IA desde PDF

Se usa cuando el archivo ya trae preguntas, pero el formato es complejo o el parser local no basta.

Formato soportado en esta opcion:

- `PDF`

El sistema:

- sube el PDF a OpenAI
- lo procesa por tandas
- extrae preguntas existentes
- las deja en `REVISION`

Uso recomendado:

- pruebas en PDF con maquetacion compleja
- documentos donde el parser local no detecta bien las preguntas

### Mensaje de proceso

Al presionar `Guardar fuente`, el sistema muestra un modal de progreso con mensajes como:

- `Preparando fuente para generacion con IA...`
- `Analizando documento e importando preguntas...`
- `Subiendo PDF y extrayendo preguntas con IA...`

## Agrupaciones automaticas

Cuando no se selecciona agrupacion al crear una fuente, el sistema puede:

- reutilizar una agrupacion existente con el mismo titulo y servicio
- o crear una nueva automaticamente

Esto permite trabajar cada prueba como una agrupacion propia dentro del servicio.

## Generacion IA

Pantalla: `gp_generacion.php`

Esta pantalla toma una fuente activa y genera preguntas nuevas con IA.

### Datos requeridos

- Fuente activa
- Agrupacion / prueba
- Area de competencia
- Cantidad

### Complejidad de preguntas

La generacion usa mezcla fija:

- mitad `MEDIA`
- mitad `AVANZADA`

Si la cantidad es impar, la pregunta adicional queda del lado `AVANZADA`.

### Como funciona

1. Selecciona la fuente.
2. Verifica agrupacion y area.
3. Define cantidad de preguntas.
4. Presiona `Generar borradores`.

El sistema:

- toma el texto de la fuente
- consulta OpenAI en tandas internas
- genera preguntas nuevas
- guarda el resultado en estado `BORRADOR`

### Enviar una generacion a Revision

Las preguntas generadas no pasan automaticamente al flujo de revision.

Despues de revisar la generacion en pantalla:

1. Abre la generacion.
2. Verifica el contenido.
3. Presiona `Enviar generacion a Revision`.

Con eso, todas las preguntas en `BORRADOR` de esa generacion pasan a `REVISION`.

## Revision y Correccion

Pantalla: `gp_revision.php`

Esta es la bandeja principal de trabajo para el revisor.

### Estados visibles

- `REVISION`
- `OBSERVADA`
- `VISADA`
- `PUBLICADA`

### Seleccion por lote

La pantalla trabaja por:

1. `Agrupacion`
2. `Carga o fuente`

Dentro de cada lote se muestran conteos de:

- En revision
- Observadas
- Visadas
- Publicadas

### Acciones disponibles

#### Guardar cambios

Permite corregir:

- pregunta
- alternativas
- correcta
- comentario

Si la pregunta estaba `OBSERVADA`, al guardar vuelve a `REVISION`.

#### Observar

Permite dejar una observacion formal.

Requiere comentario.

La observacion queda registrada en historial y la pregunta pasa a `OBSERVADA`.

#### Enviar a Operacion

Permite enviar preguntas desde `REVISION` a `OPERACION`.

#### Reenviar a Operacion

Las preguntas `OBSERVADAS` tambien pueden reenviarse a Operacion si se desea mantener un flujo de mejora continua.

#### Acciones por lote

- `Enviar seleccionadas a Operacion`
- `Enviar todo el lote a Operacion`
- `Observar seleccionadas`
- `Observar todo el lote`
- `Publicar lote` cuando existan preguntas visadas

### Historial de comentarios

Cada pregunta guarda trazabilidad de estados y comentarios.

Se puede revisar en:

- `Ultima observacion`
- `Comentarios anteriores`

## Visacion Operacion

Pantalla: `gp_operacion.php`

Operacion realiza una revision conceptual del lote.

No es una pantalla de edicion.

### Objetivo de esta etapa

- validar enfoque conceptual
- revisar sentido de la pregunta
- revisar pertinencia del cuestionario
- observar o visar

### Acciones disponibles

- `Visar`
- `Visar seleccionadas`
- `Visar todo el lote`
- `Observar`
- `Observar seleccionadas`
- `Observar todo el lote`

### Resultado de la visacion

Si se visa:

- la pregunta pasa a `APROBADA_OPERACION`
- en Revision se muestra como `VISADA`

Si se observa:

- la pregunta vuelve a `OBSERVADA`

## Publicacion

La publicacion se realiza desde `gp_revision.php` cuando un lote tiene preguntas `VISADAS`.

Boton:

- `Publicar lote`

### Que hace la publicacion

Publica las preguntas del lote completo al banco oficial segun destino.

#### Si el destino es FORMACION

Inserta en:

- `ceo_formacion_preguntas_servicios`
- `ceo_formacion_alternativas_preguntas`

#### Si el destino es HABILITACION

Inserta en:

- `ceo_preguntas_servicios`
- `ceo_alternativas_preguntas`

### Trazabilidad de publicacion

Cada publicacion queda registrada en:

- `ceo_gp_publicacion`

El estado de la pregunta en el gestor cambia a:

- `PUBLICADA`

## Importacion sin IA: formato recomendado

### XLSX

Es el formato mas confiable para importar preguntas existentes sin IA.

Estructura recomendada por bloque:

```text
¿Cual es la funcion del interruptor diferencial?
A. Proteger contra sobrecarga
B. Detectar fugas de corriente a tierra
C. Medir potencia activa
D. Aumentar el voltaje
Respuesta correcta: B
```

### PDF

El parser local intenta detectar:

- preguntas numeradas
- alternativas A/B/C/D

Si el PDF es desordenado o complejo, se recomienda usar la opcion:

- `Extraer preguntas existentes con IA desde PDF`

## Buenas practicas de uso

- Usa una fuente por prueba o cuestionario.
- Asigna bien servicio, agrupacion y area antes de generar o importar.
- Para documentos con preguntas listas, prefiere importar o extraer antes que generar nuevas.
- Revisa siempre los borradores antes de enviarlos a Revision.
- Usa comentarios claros al observar preguntas.
- Publica solo lotes realmente visados y validados.

## Recomendaciones operativas

- Si una fuente es muy extensa, prueba primero con una cantidad menor de preguntas.
- Si una generacion demora demasiado, revisa los logs del modulo.
- Si una pregunta no aparece en Revision, revisa primero su estado actual.
- Si un lote no aparece en Operacion, revisa que haya sido enviado desde Revision.

## Archivos de log utiles

Segun el flujo, el sistema puede dejar trazas en:

- `storage/gestor_preguntas/logs/gp_fuentes_error.log`
- `storage/gestor_preguntas/logs/gp_generacion_error.log`

Estos archivos ayudan a diagnosticar:

- errores de carga
- problemas de extraccion
- tiempos de respuesta de OpenAI
- desconexiones de base de datos

## Resumen rapido del flujo recomendado

### Para preguntas ya existentes en Excel o PDF ordenado

1. Ir a `Fuentes y documentos`
2. Elegir `Importar preguntas existentes para revision`
3. Guardar fuente
4. Revisar en `Revision y Correccion`
5. Enviar a `Visacion Operacion`
6. Publicar lote

### Para preguntas existentes en PDF complejo

1. Ir a `Fuentes y documentos`
2. Elegir `Extraer preguntas existentes con IA desde PDF`
3. Guardar fuente
4. Revisar en `Revision y Correccion`
5. Enviar a `Visacion Operacion`
6. Publicar lote

### Para crear preguntas nuevas desde una fuente

1. Ir a `Fuentes y documentos`
2. Elegir `Generar preguntas con IA`
3. Guardar fuente
4. Ir a `Generacion IA`
5. Generar borradores
6. Enviar generacion a `Revision`
7. Revisar y corregir
8. Enviar a `Visacion Operacion`
9. Publicar lote
