# Analizador de contratos de trabajo

## Acceso y configuración

- Página: `/ceo.noetica.cl/public/contratos_analizar.php`. Requiere una sesión activa del sistema.
- Configura `OPENAI_API_KEY_LOCAL` en `config/ceonext_ai.php`, reemplazando `TU_API_KEY_AQUI`. También se admite la variable de entorno `OPENAI_API_KEY` si no hay una clave local válida.
- El modelo utiliza la configuración existente: `OPENAI_PDF_MODEL_LOCAL`, luego `OPENAI_MODEL_LOCAL`, luego `OPENAI_MODEL` del entorno. Por defecto: `gpt-4.1-mini`. Debe admitir PDF y Structured Outputs en Responses API.
- La cuenta de OpenAI debe tener acceso al modelo y saldo/facturación habilitada.
- PHP necesita cURL y Fileinfo. Para admitir PDF de 15 MB, configura `upload_max_filesize = 15M`, `post_max_size = 20M` y memoria suficiente para codificar el archivo en base64 (recomendado `memory_limit = 256M`). El tiempo de espera HTTP hacia OpenAI es de 150 segundos; el servidor/proxy debe permitirlo.

## Funcionamiento

El formulario envía un PDF mediante POST con CSRF. PHP verifica el tamaño, la extensión, el tipo MIME y la cabecera del documento. Envía el PDF en línea a `POST https://api.openai.com/v1/responses`, con `store: false` y un esquema JSON estricto. No crea archivos en OpenAI Files ni guarda contratos o análisis en la base de datos. El archivo temporal de carga es eliminado por PHP al finalizar la petición. El procesamiento en OpenAI está sujeto a las condiciones de la cuenta de API.

El análisis distingue partes, contrato, funciones/jornada y remuneración. Cada campo contiene `valor` y `referencia`, ambos anulables. También devuelve `es_contrato_laboral`, `resumen`, `clausulas_relevantes` y `observaciones`. El servidor valida la estructura completa antes de presentar los datos; no muestra respuestas truncadas como análisis completos.

La página permite ver, copiar y descargar el JSON. Los valores ausentes aparecen como «No indicado». No supone país ni completa datos con legislación externa; las observaciones describen el contenido del documento.

## Verificación manual

1. Inicia sesión y abre la página.
2. Sube un contrato PDF legible. Comprueba partes, montos, jornada y referencias contra el original.
3. Abre «Ver JSON completo», copia y descarga el resultado; verifica que coincidan con la pantalla.
4. Prueba un PDF ajeno a contratos: debe indicar que no es un contrato laboral.
5. Prueba un archivo que no sea PDF, uno mayor al límite y una clave inválida: debe mostrar el error correspondiente.
6. Comprueba un PDF escaneado y uno ilegible o protegido: el análisis debe marcar lo ilegible o informar que no pudo completarse.

La lectura real mediante OpenAI requiere una clave válida y genera consumo en la cuenta de API.
