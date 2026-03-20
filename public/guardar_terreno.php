<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {

    /* ============================================================
       1. VALIDACIONES BÁSICAS
       ============================================================ */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método inválido');
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data)) {
        throw new Exception('No se recibieron datos');
    }

    $idEvaluaciones = $data['id_evaluacion'] ?? [];
    $nsolicitudes   = $data['nsolicitud'] ?? [];
    $id_servicio    = (int)($data['id_servicio'] ?? 0);
    $id_empresa     = (int)($data['id_empresa'] ?? 0);
    $respuestas     = $data['respuestas'] ?? [];

    if (!is_array($idEvaluaciones) || empty($idEvaluaciones)) {
        throw new Exception('Evaluaciones inválidas');
    }

    $idEvaluaciones = array_map('intval', $idEvaluaciones);
    $idEvaluaciones = array_values(array_filter($idEvaluaciones, fn($v) => $v > 0));

    if (empty($idEvaluaciones)) {
        throw new Exception('Evaluaciones inválidas');
    }

    if (!is_array($nsolicitudes) || empty($nsolicitudes)) {
        throw new Exception('Solicitud inválida');
    }

    if ($id_servicio <= 0 || $id_empresa <= 0) {
        throw new Exception('Datos de cabecera incompletos');
    }

    if (empty($respuestas) || !is_array($respuestas)) {
        throw new Exception('No se recibieron respuestas');
    }

    $db = db();
    $fecha = date('Y-m-d');
    $hora  = date('H:i:s');

    /* ============================================================
       1.1 VALIDAR RESPUESTAS ANTES DE GRABAR
       - Debe venir SOLO una marca por pregunta: si / no / na
       - Si marca NO o NA => observación obligatoria
       ============================================================ */
    foreach ($respuestas as $rut => $preguntas) {
        if (!is_array($preguntas) || empty($preguntas)) {
            throw new Exception("No hay preguntas válidas para el RUT $rut.");
        }

        foreach ($preguntas as $id_pregunta => $r) {
            if (!is_array($r)) {
                throw new Exception("Formato inválido en respuesta de la pregunta $id_pregunta para RUT $rut.");
            }

            $si  = !empty($r['si']);
            $no  = !empty($r['no']);
            $na  = !empty($r['na']);
            $obs = trim((string)($r['obs'] ?? ''));

            $marcadas = ($si ? 1 : 0) + ($no ? 1 : 0) + ($na ? 1 : 0);

            if ($marcadas !== 1) {
                throw new Exception("Debe seleccionar exactamente una opción (SI / NO / NA) en la pregunta $id_pregunta del RUT $rut.");
            }

            if (($no || $na) && $obs === '') {
                throw new Exception("Debe ingresar observación en la pregunta $id_pregunta del RUT $rut cuando marca NO o NA.");
            }
        }
    }

    /* ============================================================
       2. INICIAR TRANSACCIÓN
       ============================================================ */
    $db->beginTransaction();

    /* ============================================================
       3. OBTENER EVALUACIONES EXACTAMENTE SELECCIONADAS
       ============================================================ */
    $placeholdersEval = implode(',', array_fill(0, count($idEvaluaciones), '?'));

    $stmtEvalsSel = $db->prepare("
        SELECT
            id,
            rut,
            cuadrilla,
            id_servicio,
            tipo,
            estado
        FROM ceo_evaluaciones_programadas
        WHERE id IN ($placeholdersEval)
          AND tipo = 'TERRENO'
          AND estado = 'PENDIENTE'
    ");
    $stmtEvalsSel->execute($idEvaluaciones);
    $evaluacionesSeleccionadas = $stmtEvalsSel->fetchAll(PDO::FETCH_ASSOC);

    if (empty($evaluacionesSeleccionadas)) {
        throw new Exception('No se encontraron evaluaciones seleccionadas válidas.');
    }

    // Validar que todas correspondan al mismo servicio enviado
    $serviciosSel = array_values(array_unique(array_map(
        fn($r) => (int)$r['id_servicio'],
        $evaluacionesSeleccionadas
    )));

    if (count($serviciosSel) !== 1) {
        throw new Exception('Las evaluaciones seleccionadas pertenecen a distintos servicios.');
    }

    if ((int)$serviciosSel[0] !== $id_servicio) {
        throw new Exception('El servicio recibido no coincide con las evaluaciones seleccionadas.');
    }

    // Mapas exactos de la selección
    $mapRutCuadrilla = [];
    $mapRutEvalId    = [];
    $cuadrillasSel   = [];

    foreach ($evaluacionesSeleccionadas as $ev) {
        $rutSel = (string)$ev['rut'];
        $idEval = (int)$ev['id'];
        $cuad   = (int)$ev['cuadrilla'];

        if (isset($mapRutCuadrilla[$rutSel])) {
            throw new Exception("El RUT $rutSel aparece más de una vez en la selección.");
        }

        $mapRutCuadrilla[$rutSel] = $cuad;
        $mapRutEvalId[$rutSel]    = $idEval;
        $cuadrillasSel[]          = $cuad;
    }

    $cuadrillasSel = array_values(array_unique($cuadrillasSel));

    // Validar coherencia entre cuadrillas enviadas y cuadrillas de evaluaciones seleccionadas
    $nsolicitudesNorm = array_values(array_unique(array_map('intval', $nsolicitudes)));
    sort($nsolicitudesNorm);
    $cuadrillasSelOrd = $cuadrillasSel;
    sort($cuadrillasSelOrd);

    if ($nsolicitudesNorm !== $cuadrillasSelOrd) {
        throw new Exception('Las cuadrillas recibidas no coinciden con las evaluaciones seleccionadas.');
    }

    /* ============================================================
       4. PREPARAR SENTENCIAS
       ============================================================ */

    // Resumen intento terreno (por persona)
    $stmtIntentoTerr = $db->prepare("
        INSERT INTO ceo_resultado_terreno_intento
        (
            rut,
            id_servicio,
            id_evaluador,
            fecha_rendicion,
            hora_rendicion,
            puntaje_total,
            correctas,
            incorrectas,
            ncontestadas,
            noaplica,
            notafinal
        )
        VALUES
        (
            :rut,
            :id_servicio,
            :id_evaluador,
            :fecha,
            :hora,
            :puntaje_total,
            :correctas,
            :incorrectas,
            :ncontestadas,
            :noaplica,
            :notafinal
        )
    ");

    // Cabecera resultado terreno (una por cuadrilla)
    $stmtCab = $db->prepare("
        INSERT INTO ceo_seccion_resultado_terreno
        (id_empresa, fecha_examen, hora_examen, id_servicio, nsolicitud)
        VALUES (?, ?, ?, ?, ?)
    ");

    // Detalle preguntas
    $stmtDet = $db->prepare("
        INSERT INTO ceo_resultado_prueba_terreno
        (id_resultado, cumple, no_cumple, no_aplica, observaciones,
         id_pregunta, id_seccion, rut_contratista, practico, referente, fecha)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Cerrar evaluación exacta seleccionada
    $stmtCerrar = $db->prepare("
        UPDATE ceo_evaluaciones_programadas
        SET estado = 'EJECUTADA'
        WHERE id = ?
          AND tipo = 'TERRENO'
          AND estado = 'PENDIENTE'
    ");

    // Insertar vigencia detalle (solo APROBADO y si NO hay general activa)
    $stmtInsVigDet = $db->prepare("
        INSERT INTO ceo_vigencia_detalle
        (rut, id_servicio, fechavig_ini, fechavig_fin, id_proceso, tipo)
        VALUES
        (:rut, :id_servicio, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 YEAR), :id_proceso, 'TERRENO')
    ");

    /* ============================================================
       5. CREAR CABECERAS POR CUADRILLA
       ============================================================ */
    $resultados = []; // cuadrilla => id_resultado

    foreach ($cuadrillasSel as $cuadrilla) {
        $stmtCab->execute([
            $id_empresa,
            $fecha,
            $hora,
            $id_servicio,
            $cuadrilla
        ]);

        $resultados[$cuadrilla] = (int)$db->lastInsertId();
    }

    /* ============================================================
       6. GRABAR DETALLE POR PERSONA Y PREGUNTA
       ============================================================ */

    // Obtener ponderación por pregunta
    $stmtPonderacion = $db->prepare("
        SELECT ponderacion
        FROM ceo_preguntas_seccion_terreno
        WHERE id = ?
    ");

    // Insert evaluación final
    $stmtEval = $db->prepare("
        INSERT INTO ceo_evaluacion_terreno
        (codigo_evaluacion, rut, nombre, cargo, contratista,
         evaluador, usuario, resultado, id_servicio, fecha_evaluacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Actualizar resultado en planificación SOLO del id seleccionado
    $stmtUpdResultado = $db->prepare("
        UPDATE ceo_evaluaciones_programadas
        SET resultado = ?
        WHERE id = ?
          AND rut = ?
          AND tipo = 'TERRENO'
    ");

    // Obtener porcentaje mínimo de aprobación por servicio
    $stmtPorcMin = $db->prepare("
        SELECT p.porcentaje
        FROM ceo_agrupacion_terreno a
        INNER JOIN ceo_porcentaje_agrup_terreno p
            ON p.id_agrupacion = a.id
        WHERE a.id_servicio = ?
        LIMIT 1
    ");

    // Obtener datos del evaluado
    $stmtDatosEvaluado = $db->prepare("
        SELECT
            A.nombre,
            A.apellidos,
            B.cargo,
            C.nombre AS empresa
        FROM ceo_contratistas A
        INNER JOIN ceo_cargo_contratistas B ON A.id_cargo = B.id
        INNER JOIN ceo_empresas C ON A.id_empresa = C.id
        WHERE A.rut = ?
    ");

    // 6.1 Insertar detalle por rut
    foreach ($respuestas as $rut => $preguntas) {

        if (!isset($mapRutCuadrilla[$rut])) {
            throw new Exception("El RUT $rut no forma parte de las evaluaciones seleccionadas.");
        }

        $cuadrillaRut = $mapRutCuadrilla[$rut];

        if (!isset($resultados[$cuadrillaRut])) {
            throw new Exception("Resultado no encontrado para cuadrilla $cuadrillaRut");
        }

        $id_resultado = $resultados[$cuadrillaRut];

        foreach ($preguntas as $id_pregunta => $r) {
            $stmtDet->execute([
                $id_resultado,
                !empty($r['si']) ? 1 : 0,
                !empty($r['no']) ? 1 : 0,
                !empty($r['na']) ? 1 : 0,
                trim((string)($r['obs'] ?? '')),
                (int)$id_pregunta,
                (int)($r['id_seccion'] ?? 0),
                $rut,
                $r['practico'] ?? '',
                $r['referente'] ?? '',
                date('Y-m-d H:i:s')
            ]);
        }
    }

    /* ============================================================
       7. CALCULAR PORCENTAJES / CERRAR POR RUT
       ============================================================ */
    $stmtPorcMin->execute([$id_servicio]);
    $rowPorc = $stmtPorcMin->fetch(PDO::FETCH_ASSOC);

    if (!$rowPorc) {
        throw new Exception("No se encontró porcentaje mínimo para el servicio $id_servicio");
    }

    $porcentajeMinimo = (float)$rowPorc['porcentaje'];

    foreach ($respuestas as $rut => $preguntas) {

        if (!isset($mapRutCuadrilla[$rut])) {
            throw new Exception("El RUT $rut no forma parte de las evaluaciones seleccionadas.");
        }

        if (!isset($mapRutEvalId[$rut])) {
            throw new Exception("No se encontró evaluación seleccionada para RUT $rut");
        }

        $cuadrillaRut = $mapRutCuadrilla[$rut];
        $idEvalRut    = $mapRutEvalId[$rut];

        $ponderacion_total = 0.0;
        $ponderacion_ok    = 0.0;

        foreach ($preguntas as $id_pregunta => $r) {

            // Excluir NO APLICA
            if (!empty($r['na'])) {
                continue;
            }

            // Obtener ponderación de la pregunta
            $stmtPonderacion->execute([(int)$id_pregunta]);
            $p = $stmtPonderacion->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                throw new Exception("Ponderación no encontrada para pregunta $id_pregunta");
            }

            $pond = (float)$p['ponderacion'];
            $ponderacion_total += $pond;

            if (!empty($r['si'])) {
                $ponderacion_ok += $pond;
            }
        }

        $resultado = ($ponderacion_total <= 0)
            ? 0.0
            : round(($ponderacion_ok / $ponderacion_total) * 100, 2);

        $notaFinal = calcularNotaFinalDesdePorcentaje($resultado, $porcentajeMinimo);

        $estadoFinal = ($resultado >= $porcentajeMinimo)
            ? 'APROBADO'
            : 'REPROBADO';

        // Obtener datos del evaluado
        $stmtDatosEvaluado->execute([$rut]);
        $dat = $stmtDatosEvaluado->fetch(PDO::FETCH_ASSOC);

        if (!$dat) {
            throw new Exception("No se encontraron datos del evaluado RUT $rut");
        }

        $nombreCompleto = trim(($dat['nombre'] ?? '') . ' ' . ($dat['apellidos'] ?? ''));
        $cargoEvaluado  = $dat['cargo'] ?? '';
        $empresaEval    = $dat['empresa'] ?? '';

        $evaluadorNombre = $_SESSION['auth']['nombre'] ?? null;

        // Contadores intento terreno
        $correctasTerr    = 0;
        $incorrectasTerr  = 0;
        $noaplicaTerr     = 0;
        $ncontestadasTerr = 0;

        foreach ($preguntas as $id_pregunta => $r) {
            $si = !empty($r['si']);
            $no = !empty($r['no']);
            $na = !empty($r['na']);

            if ($na) {
                $noaplicaTerr++;
                continue;
            }
            if ($si) {
                $correctasTerr++;
                continue;
            }
            if ($no) {
                $incorrectasTerr++;
                continue;
            }

            $ncontestadasTerr++;
        }

        // Insert evaluación final
        $stmtEval->execute([
            $cuadrillaRut,       // codigo_evaluacion
            $rut,
            $nombreCompleto,
            $cargoEvaluado,
            $empresaEval,
            $evaluadorNombre,    // evaluador
            $evaluadorNombre,    // usuario = evaluador
            $resultado,
            $id_servicio,
            date('Y-m-d')
        ]);

        // Actualizar resultado SOLO en la evaluación seleccionada
        $stmtUpdResultado->execute([
            $estadoFinal,
            $idEvalRut,
            $rut
        ]);

        // Cerrar evaluación seleccionada antes de crear reintento / recalcular vigencia
        $stmtCerrar->execute([$idEvalRut]);

        // Insert intento terreno
        $evaluadorId = (int)($_SESSION['auth']['id'] ?? 0);
        $stmtIntentoTerr->execute([
            ':rut'           => $rut,
            ':id_servicio'   => $id_servicio,
            ':id_evaluador'  => ($evaluadorId > 0 ? $evaluadorId : null),
            ':fecha'         => $fecha,
            ':hora'          => $hora,
            ':puntaje_total' => $resultado,
            ':correctas'     => $correctasTerr,
            ':incorrectas'   => $incorrectasTerr,
            ':ncontestadas'  => $ncontestadasTerr,
            ':noaplica'      => $noaplicaTerr,
            ':notafinal'     => $notaFinal
        ]);

        // =====================================================
        // VIGENCIA (DETALLE/GENERAL)
        // =====================================================
        if ($estadoFinal === 'APROBADO') {

            if (function_exists('existeVigenciaGeneralActiva') && function_exists('recalcularVigenciaGeneral')) {
                // Bloqueo: si ya existe general activa para ese rut+proceso, no se inserta nada
                if (!existeVigenciaGeneralActiva($db, $rut, $cuadrillaRut)) {

                    // Insertar vigencia detalle (rut+servicio+proceso)
                    $stmtInsVigDet->execute([
                        ':rut'         => $rut,
                        ':id_servicio' => $id_servicio,
                        ':id_proceso'  => $cuadrillaRut
                    ]);

                    // Intentar generar vigencia general (solo si ya corresponde)
                    recalcularVigenciaGeneral($db, $rut, $cuadrillaRut);
                }
            }
        }

        // =====================================================
        // RESULTADO FINAL DEL SERVICIO
        // =====================================================
        if (function_exists('recalcularResultadoServicio') && function_exists('guardarResultadoFinalServicio')) {
            $resultadoFinalServicio = recalcularResultadoServicio(
                $db,
                $rut,
                $id_servicio,
                (int)$cuadrillaRut,
                'GENERAL',
                80.0
            );

            guardarResultadoFinalServicio($db, $resultadoFinalServicio);
        }
    }

    /* ============================================================
       8. COMMIT
       ============================================================ */
    $db->commit();

    echo json_encode([
        'ok' => true,
        'resultados' => $resultados
    ]);

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}