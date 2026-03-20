<?php
// -------------------------------------------------------------
// terreno_gestion_acciones.php
// Maneja creación, edición y eliminación
// de: agrupaciones, secciones y preguntas
// -------------------------------------------------------------

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header("Location: /ceo/public/index.php");
    exit;
}

$db = db();
$accion = $_REQUEST['accion'] ?? null;

// --------------------------------------------------------------
// 1. CREAR AGRUPACIÓN
// --------------------------------------------------------------
if ($accion === "crear_agrupacion") {

    $grupo = trim($_POST['grupo']);
    $id_servicio = intval($_POST['id_servicio']);

    $stmt = $db->prepare("
        INSERT INTO ceo_agrupacion_terreno (grupo, id_servicio)
        VALUES (?, ?)
    ");
    $stmt->execute([$grupo, $id_servicio]);

    header("Location: terreno_gestion.php?ok=1");
    exit;
}

// --------------------------------------------------------------
// ELIMINAR AGRUPACIÓN
// --------------------------------------------------------------
if ($accion === "eliminar_agrupacion") {

    $id = intval($_GET['id']);

    // Borrado en cascada manual (secciones + preguntas)
    $sec = $db->prepare("SELECT id FROM ceo_seccion_terreno WHERE id_grupo=?");
    $sec->execute([$id]);
    $secciones = $sec->fetchAll(PDO::FETCH_ASSOC);

    foreach ($secciones as $s) {
        $db->prepare("DELETE FROM ceo_preguntas_seccion_terreno WHERE id_seccion=?")
           ->execute([$s['id']]);
    }

    $db->prepare("DELETE FROM ceo_seccion_terreno WHERE id_grupo=?")
       ->execute([$id]);

    $db->prepare("DELETE FROM ceo_agrupacion_terreno WHERE id=?")
       ->execute([$id]);

    header("Location: terreno_gestion.php?deleted=1");
    exit;
}

// --------------------------------------------------------------
// 2. CREAR SECCIÓN
// --------------------------------------------------------------
if ($accion === "crear_seccion") {

    $seccion = trim($_POST['seccion']);
    $nombre = trim($_POST['nombre']);
    $id_grupo = intval($_POST['id_grupo']);

    // Obtener último orden
    $ord = $db->prepare("SELECT COALESCE(MAX(orden),0)+1 AS nextOrd FROM ceo_seccion_terreno WHERE id_grupo=?");
    $ord->execute([$id_grupo]);
    $orden = $ord->fetchColumn();

    $stmt = $db->prepare("
        INSERT INTO ceo_seccion_terreno (seccion, nombre, id_grupo, orden)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$seccion, $nombre, $id_grupo, $orden]);

    header("Location: terreno_gestion.php?modo=secciones&id_grupo=".$id_grupo);
    exit;
}

// --------------------------------------------------------------
// ELIMINAR SECCIÓN
// --------------------------------------------------------------
if ($accion === "eliminar_seccion") {

    $id = intval($_GET['id']);

    // Buscar id_grupo para volver a esa pantalla
    $grupo = $db->prepare("SELECT id_grupo FROM ceo_seccion_terreno WHERE id=?");
    $grupo->execute([$id]);
    $id_grupo = $grupo->fetchColumn();

    // borrar preguntas
    $db->prepare("DELETE FROM ceo_preguntas_seccion_terreno WHERE id_seccion=?")
       ->execute([$id]);

    // borrar sección
    $db->prepare("DELETE FROM ceo_seccion_terreno WHERE id=?")
       ->execute([$id]);

    header("Location: terreno_gestion.php?modo=secciones&id_grupo=".$id_grupo);
    exit;
}

// --------------------------------------------------------------
// 3. CREAR PREGUNTA
// --------------------------------------------------------------
if ($accion === "crear_pregunta") {

    $id_seccion = intval($_POST['id_seccion']);
    $pregunta = trim($_POST['pregunta']);
    $ponderacion = intval($_POST['ponderacion']);
    $practico = trim($_POST['practico']);
    $referente = trim($_POST['referente']);
    $orden = intval($_POST['orden']);

    if ($orden === 0) {
        // Sacar último orden
        $ord = $db->prepare("SELECT COALESCE(MAX(orden),0)+1 AS nextOrd 
                             FROM ceo_preguntas_seccion_terreno WHERE id_seccion=?");
        $ord->execute([$id_seccion]);
        $orden = $ord->fetchColumn();
    }

    $stmt = $db->prepare("
        INSERT INTO ceo_preguntas_seccion_terreno 
        (id_seccion, pregunta, cumplesi, cumpleno, cumplena, ponderacion, practico, referente, orden)
        VALUES (?, ?, 'SI', 'NO', 'N/A', ?, ?, ?, ?)
    ");
    $stmt->execute([$id_seccion, $pregunta, $ponderacion, $practico, $referente, $orden]);

    header("Location: terreno_gestion.php?modo=preguntas&id_seccion=".$id_seccion);
    exit;
}

// --------------------------------------------------------------
// ELIMINAR PREGUNTA
// --------------------------------------------------------------
if ($accion === "eliminar_pregunta") {

    $id = intval($_GET['id']);

    // Recuperar id_seccion
    $sec = $db->prepare("SELECT id_seccion FROM ceo_preguntas_seccion_terreno WHERE id=?");
    $sec->execute([$id]);
    $id_seccion = $sec->fetchColumn();

    $db->prepare("DELETE FROM ceo_preguntas_seccion_terreno WHERE id=?")
       ->execute([$id]);

    header("Location: terreno_gestion.php?modo=preguntas&id_seccion=".$id_seccion);
    exit;
}

// --------------------------------------------------------------
// EDITAR AGRUPACIÓN
// --------------------------------------------------------------
if ($accion === "editar_agrupacion") {

    $id = intval($_POST['id']);
    $grupo = trim($_POST['grupo']);
    $id_servicio = intval($_POST['id_servicio']);

    $stmt = $db->prepare("UPDATE ceo_agrupacion_terreno 
                          SET grupo=?, id_servicio=? 
                          WHERE id=?");
    $stmt->execute([$grupo, $id_servicio, $id]);

    header("Location: terreno_gestion.php?modo=agrupaciones&edit=1");
    exit;
}
// --------------------------------------------------------------
// EDITAR SECCIÓN
// --------------------------------------------------------------
if ($accion === "editar_seccion") {

    $id = intval($_POST['id']);
    $seccion = trim($_POST['seccion']);
    $nombre = trim($_POST['nombre']);
    $orden = intval($_POST['orden']);

    $stmt = $db->prepare("
        UPDATE ceo_seccion_terreno
        SET seccion=?, nombre=?, orden=?
        WHERE id=?
    ");
    $stmt->execute([$seccion, $nombre, $orden, $id]);

    // sacar id_grupo para redirigir
    $g = $db->prepare("SELECT id_grupo FROM ceo_seccion_terreno WHERE id=?");
    $g->execute([$id]);
    $id_grupo = $g->fetchColumn();

    header("Location: terreno_gestion.php?modo=secciones&id_grupo=".$id_grupo);
    exit;
}

// --------------------------------------------------------------
// EDITAR PREGUNTA
// --------------------------------------------------------------
if ($accion === "editar_pregunta") {

    $id = intval($_POST['id']);
    $pregunta = trim($_POST['pregunta']);
    $ponderacion = intval($_POST['ponderacion']);
    $practico = trim($_POST['practico']);
    $referente = trim($_POST['referente']);
    $orden = intval($_POST['orden']);

    $stmt = $db->prepare("
        UPDATE ceo_preguntas_seccion_terreno
        SET pregunta=?, ponderacion=?, practico=?, referente=?, orden=?
        WHERE id=?
    ");
    $stmt->execute([$pregunta, $ponderacion, $practico, $referente, $orden, $id]);

    // id_seccion para volver
    $sec = $db->prepare("SELECT id_seccion FROM ceo_preguntas_seccion_terreno WHERE id=?");
    $sec->execute([$id]);
    $id_seccion = $sec->fetchColumn();

    header("Location: terreno_gestion.php?modo=preguntas&id_seccion=".$id_seccion);
    exit;
}

// --------------------------------------------------------------
// Si no coincide ninguna acción
// --------------------------------------------------------------
header("Location: terreno_gestion.php");
exit;
