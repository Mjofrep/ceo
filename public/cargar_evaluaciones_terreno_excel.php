<?php
// ============================================================
// cargar_evaluaciones_terreno_csv.php
// Carga histórica Evaluaciones de Terreno (CSV)
// Servicio: 6
// ============================================================
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';

$pdo = db();
$ID_SERVICIO = 6;
$mensaje = '';

/* ============================================================
   FUNCIÓN NORMALIZACIÓN UTF-8 (CLAVE)
============================================================ */
function toUtf8(?string $value): ?string
{
    if ($value === null) return null;

    return mb_convert_encoding(
        trim($value),
        'UTF-8',
        'UTF-8, ISO-8859-1, Windows-1252'
    );
}

function csvDateToMysql(?string $value): ?string
{
    if (!$value) return null;

    $value = trim($value);

    // dd/mm/yyyy hh:mm:ss
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4}) (\d{2}:\d{2}:\d{2})$#', $value, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}";
    }

    // dd-mm-yyyy
    if (preg_match('#^(\d{2})-(\d{2})-(\d{4})$#', $value, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00";
    }

    return null;
}

/* ============================================================
   PROCESAR CSV
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['csv']['tmp_name'])) {
        $mensaje = '❌ No se recibió el archivo CSV.';
    } else {

        $tmpFile = $_FILES['csv']['tmp_name'];

        if (($fh = fopen($tmpFile, 'r')) === false) {
            $mensaje = '❌ No se pudo abrir el archivo CSV.';
        } else {

            $pdo->beginTransaction();

            try {

                // Encabezado
                $header = fgetcsv($fh, 0, ';');
                if (!$header) {
                    throw new Exception('CSV sin encabezado.');
                }

                $codigoAnterior = null;
                $idEvaluacion   = null;
                $contCab = 0;
                $contDet = 0;

                while (($row = fgetcsv($fh, 0, ';')) !== false) {

                    $codigoEvaluacion = trim($row[1] ?? '');
                    if ($codigoEvaluacion === '') {
                        continue;
                    }

                    /* ================= CABECERA ================= */
                    if ($codigoEvaluacion !== $codigoAnterior) {

                        $codigoAnterior = $codigoEvaluacion;

                        $stmtCab = $pdo->prepare("
                            INSERT INTO ceo_evaluacion_terreno (
                                codigo_evaluacion,
                                rut,
                                nombre,
                                cargo,
                                contratista,
                                evaluador,
                                usuario,
                                resultado,
                                fecha_evaluacion,
                                id_servicio
                            ) VALUES (
                                :codigo,
                                :rut,
                                :nombre,
                                :cargo,
                                :contratista,
                                :evaluador,
                                :usuario,
                                :resultado,
                                :fecha_eval,
                                :servicio
                            )
                        ");

                        $stmtCab->execute([
                            'codigo'      => toUtf8($row[1]),        // Código evaluación
                            'rut'         => toUtf8($row[32]),       // RUT
                            'nombre'      => toUtf8($row[33]),       // NOMBRE
                            'cargo'       => toUtf8($row[35]),       // CARGO
                            'contratista' => toUtf8($row[37]),       // CONTRATISTA
                            'evaluador'   => toUtf8($row[34]),       // EVALUADOR
                            'usuario'     => toUtf8($row[11]),       // Usuario
                            'resultado'   => toUtf8($row[22]),       // Resultado (95,45)
                            'fecha_eval'  => csvDateToMysql($row[36]), // FECHA
                            'servicio'    => $ID_SERVICIO
                        ]);


                        $idEvaluacion = (int)$pdo->lastInsertId();
                        $contCab++;
                    }

                    /* ================= DETALLE ================= */
                    $stmtDet = $pdo->prepare("
                        INSERT INTO ceo_evaluacion_terreno_detalle (
                            id_evaluacion_terreno,
                            codigo_area,
                            area,
                            codigo_item,
                            item,
                            respuesta,
                            peso,
                            resultado_item,
                            comentario_item,
                            plan_accion
                        ) VALUES (
                            :id_eval,
                            :cod_area,
                            :area,
                            :cod_item,
                            :item,
                            :respuesta,
                            :peso,
                            :resultado_item,
                            :coment_item,
                            :plan_accion
                        )
                    ");

                    $stmtDet->execute([
                        'id_eval'        => $idEvaluacion,
                        'cod_area'       => toUtf8($row[12] ?? null),
                        'area'           => toUtf8($row[13] ?? null),
                        'cod_item'       => toUtf8($row[14] ?? null),
                        'item'           => toUtf8($row[15] ?? null),
                        'respuesta'      => toUtf8($row[16] ?? null),
                        'peso'           => $row[17] ?? null,
                        'resultado_item' => toUtf8($row[22] ?? null),
                        'coment_item'    => toUtf8($row[23] ?? null),
                        'plan_accion'    => toUtf8($row[29] ?? null),
                    ]);

                    $contDet++;
                }

                fclose($fh);
                $pdo->commit();

                $mensaje = "✅ Carga finalizada correctamente.<br>
                            Cabeceras: <b>$contCab</b><br>
                            Detalles: <b>$contDet</b>";

            } catch (Throwable $e) {

                $pdo->rollBack();
                if (is_resource($fh)) fclose($fh);

                $mensaje = "❌ Error durante la carga:<br>" . $e->getMessage();
            }
        }
    }
}
?>

<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Carga Evaluaciones de Terreno</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container py-5">

    <div class="card shadow-sm rounded-4">
        <div class="card-body">

            <h4 class="fw-bold text-primary mb-3">
                📥 Carga Histórica Evaluaciones de Terreno
            </h4>

            <p class="text-muted mb-4">
                Archivo CSV separado por <b>;</b> — Servicio <b>ID 6</b>
            </p>

            <?php if ($mensaje): ?>
                <div class="alert alert-info"><?= $mensaje ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Archivo CSV</label>
                    <input type="file"
                           name="csv"
                           class="form-control"
                           accept=".csv"
                           required>
                </div>

                <button class="btn btn-primary">
                    🚀 Procesar CSV
                </button>
            </form>

        </div>
    </div>

</div>

</body>
</html>
