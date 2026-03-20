<?php
// archivo: editor_preguntas.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Modificar Pregunta</title>

<!-- CDN CKEditor -->
<script src="https://cdn.ckeditor.com/4.22.1/full/ckeditor.js"></script>

<style>
body {
    font-family: Arial;
    margin: 20px;
}

.contenedor {
    width: 90%;
    margin: auto;
    border: 1px solid #ccc;
    padding: 20px;
}

.section-title {
    font-weight: bold;
    font-size: 16px;
    margin-top: 20px;
}

input[type="text"] {
    width: 100%;
    padding: 6px;
    border: 1px solid #ccc;
}
</style>
</head>

<body>

<div class="contenedor">
    <h2>Modificar Pregunta de Banco de Preguntas</h2>

    <!-- Título -->
    <div class="section-title">Título</div>
    <input type="text" name="titulo" placeholder="Ingrese título">

    <!-- Pregunta -->
    <div class="section-title">Pregunta</div>
    <textarea name="pregunta"></textarea>

    <!-- Opciones -->
    <div class="section-title">Opciones</div>

    <div>
        <input type="radio" name="op_correcta" value="a"> a)
        <input type="text" name="op_a" placeholder="Redactar Texto de la Opción">
    </div>

    <div style="margin-top: 10px;">
        <input type="radio" name="op_correcta" value="b"> b)
        <input type="text" name="op_b" placeholder="Redactar Texto de la Opción">
    </div>

    <!-- Retroalimentación Correcta -->
    <div class="section-title">Retroalimentación Correcta</div>
    <textarea name="retro_correcta"></textarea>

    <!-- Retroalimentación Incorrecta -->
    <div class="section-title">Retroalimentación Incorrecta</div>
    <textarea name="retro_incorrecta"></textarea>

    <!-- Botón Grabar -->
    <div style="margin-top: 20px;">
        <button style="padding: 10px 20px;">Grabar</button>
    </div>
</div>

<script>
    CKEDITOR.replace('pregunta', {
        height: 150,
        extraPlugins: 'colorbutton,font,justify'
    });

    CKEDITOR.replace('retro_correcta', {
        height: 150,
        extraPlugins: 'colorbutton,font,justify'
    });

    CKEDITOR.replace('retro_incorrecta', {
        height: 150,
        extraPlugins: 'colorbutton,font,justify'
    });
</script>

</body>
</html>
