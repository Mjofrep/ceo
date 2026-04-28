<?php
declare(strict_types=1);

session_start();
ini_set('memory_limit', '768M');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($rol !== 1) {
    header('Location: /ceo.noetica.cl/public/general.php');
    exit;
}

const TERRENO_SESSION_KEY = 'historico_terreno_csv_loader';
const TERRENO_PREVIEW_LIMIT = 200;

$pdo = db();
$servicios = $pdo->query("SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC")->fetchAll(PDO::FETCH_ASSOC);
$mensaje = '';
$mensajeTipo = 'info';
$analisis = $_SESSION[TERRENO_SESSION_KEY] ?? null;
$resultadoImportacion = null;
$servicioSeleccionado = (int)($_POST['id_servicio'] ?? ($analisis['id_servicio'] ?? 2));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'analizar') {
        try {
            if ($servicioSeleccionado <= 0) {
                throw new RuntimeException('Debe seleccionar un servicio válido.');
            }
            if (empty($_FILES['csv_terreno']['tmp_name'])) {
                throw new RuntimeException('Debe seleccionar el archivo terreno_histori.csv.');
            }
            if (empty($_FILES['csv_trabajadores']['tmp_name'])) {
                throw new RuntimeException('Debe seleccionar el archivo trabajadores.csv.');
            }

            $analisis = analizarHistoricoTerrenoCsv(
                $pdo,
                $_FILES['csv_terreno']['tmp_name'],
                $_FILES['csv_trabajadores']['tmp_name'],
                $servicioSeleccionado
            );
            $_SESSION[TERRENO_SESSION_KEY] = $analisis;
            $mensaje = 'Análisis completado. Revise mapeos y normalización antes de confirmar la carga.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            if (is_array($analisis) && !empty($analisis['payload_file'])) {
                deleteTerrainPayload((string)$analisis['payload_file']);
            }
            unset($_SESSION[TERRENO_SESSION_KEY]);
            $analisis = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'importar') {
        try {
            if (!is_array($analisis) || empty($analisis['payload_file'])) {
                throw new RuntimeException('No hay un análisis válido disponible para importar.');
            }

            $payload = readTerrainPayload((string)$analisis['payload_file']);
            if (empty($payload['evaluaciones_validas'])) {
                throw new RuntimeException('No hay evaluaciones válidas disponibles para importar.');
            }

            $resultadoImportacion = importarHistoricoTerrenoCsv(
                $pdo,
                $payload['evaluaciones_validas'],
                $payload['normalizacion_detalles'],
                (int)$payload['id_servicio'],
                (int)$payload['id_grupo']
            );

            deleteTerrainPayload((string)$analisis['payload_file']);
            unset($_SESSION[TERRENO_SESSION_KEY]);
            $analisis = null;

            $mensaje = sprintf(
                'Carga finalizada. Evaluaciones importadas: %d. Duplicadas omitidas: %d. Contratistas creados: %d. Contratistas incompletos: %d. Detalles mapeados: %d.',
                $resultadoImportacion['importadas'],
                $resultadoImportacion['duplicadas'],
                $resultadoImportacion['contratistas_creados'],
                $resultadoImportacion['contratistas_incompletos'],
                $resultadoImportacion['detalles_mapeados']
            );
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'limpiar') {
        if (is_array($analisis) && !empty($analisis['payload_file'])) {
            deleteTerrainPayload((string)$analisis['payload_file']);
        }
        unset($_SESSION[TERRENO_SESSION_KEY]);
        $analisis = null;
        $mensaje = 'Análisis descartado.';
        $mensajeTipo = 'secondary';
    }
}

function analizarHistoricoTerrenoCsv(PDO $pdo, string $csvTerreno, string $csvTrabajadores, int $idServicio): array
{
    $grupo = obtenerGrupoTerreno($pdo, $idServicio);
    if ($grupo === null) {
        throw new RuntimeException('No existe agrupación de terreno asociada al servicio seleccionado.');
    }

    $threshold = obtenerPorcentajeMinimoTerreno($pdo, (int)$grupo['id']);
    $workers = loadTrabajadoresCsv($csvTrabajadores);
    $instrumento = cargarInstrumentoTerreno($pdo, (int)$grupo['id']);
    $existingEvalKeys = cargarEvaluacionesTerrenoExistentes($pdo, $idServicio);

    $mainInfo = loadTerrenoHistoriCsv($csvTerreno, $idServicio, $workers, $instrumento, $existingEvalKeys);
    $normalizacion = analizarNormalizacionContratistasTerreno($pdo, $mainInfo['ruts_normalizacion']);

    $payloadFile = writeTerrainPayload([
        'id_servicio' => $idServicio,
        'id_grupo' => (int)$grupo['id'],
        'evaluaciones_validas' => $mainInfo['evaluaciones_validas'],
        'normalizacion_detalles' => $normalizacion['detalles'],
    ]);

    return [
        'created_at' => date('Y-m-d H:i:s'),
        'id_servicio' => $idServicio,
        'servicio' => obtenerNombreServicio($pdo, $idServicio),
        'id_grupo' => (int)$grupo['id'],
        'grupo' => (string)$grupo['grupo'],
        'porcentaje_minimo' => $threshold,
        'workers' => $workers['summary'],
        'evaluaciones' => $mainInfo['summary'],
        'mapeo_secciones' => $mainInfo['mapeo_secciones'],
        'mapeo_preguntas' => $mainInfo['mapeo_preguntas'],
        'normalizacion' => $normalizacion,
        'payload_file' => $payloadFile,
    ];
}

function loadTrabajadoresCsv(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir trabajadores.csv.');
    }

    try {
        $delimiter = detectCsvDelimiter($path);
        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$header) {
            throw new RuntimeException('trabajadores.csv no contiene encabezado.');
        }

        $headerMap = buildHeaderMap($header);
        $resolved = resolveRequiredHeaders($headerMap, [
            'RUT' => ['RUT'],
            'NOMBRE' => ['NOMBRE'],
            'APELLIDO' => ['APELLIDO', 'APELLIDOS'],
            'CARGO' => ['CARGO'],
            'EMPRESA' => ['EMPRESA'],
            'UO' => ['UO'],
            'FECHA' => ['FECHA'],
        ], 'trabajadores.csv');

        $workers = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rut = normalizarRutHistorico((string)cellByHeader($row, $resolved, 'RUT'));
            if ($rut === '' || !validarRutHistorico($rut)) {
                continue;
            }
            $workers[$rut] = [
                'rut' => $rut,
                'nombre' => trim((string)cellByHeader($row, $resolved, 'NOMBRE')),
                'apellidos' => trim((string)cellByHeader($row, $resolved, 'APELLIDO')),
                'cargo_txt' => trim((string)cellByHeader($row, $resolved, 'CARGO')),
                'empresa_txt' => trim((string)cellByHeader($row, $resolved, 'EMPRESA')),
                'uo_txt' => trim((string)cellByHeader($row, $resolved, 'UO')),
                'fecha_txt' => trim((string)cellByHeader($row, $resolved, 'FECHA')),
            ];
        }

        return [
            'data' => $workers,
            'summary' => ['total' => count($workers)],
        ];
    } finally {
        fclose($fh);
    }
}

function loadTerrenoHistoriCsv(string $path, int $idServicio, array $workers, array $instrumento, array $existingEvalKeys): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('No se pudo abrir terreno_histori.csv.');
    }

    try {
        $delimiter = detectCsvDelimiter($path);
        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$header) {
            throw new RuntimeException('terreno_histori.csv no contiene encabezado.');
        }

        $headerMap = buildHeaderMap($header);
        $resolved = resolveRequiredHeaders($headerMap, [
            'CODIGODEEVALUACION' => ['CODIGODEEVALUACION'],
            'CHECKLIST' => ['CHECKLIST'],
            'USUARIO' => ['USUARIO'],
            'CODIGODEAREA' => ['CODIGODEAREA'],
            'AREA' => ['AREA'],
            'CODIGODELITEM' => ['CODIGODELITEM'],
            'ITEM' => ['ITEM'],
            'RESPUESTA' => ['RESPUESTA'],
            'PESO' => ['PESO'],
            'FECHAINICIAL' => ['FECHAINICIAL'],
            'FECHAFINAL' => ['FECHAFINAL'],
            'FECHADEAPROBACION' => ['FECHADEAPROBACION'],
            'RESULTADO' => ['RESULTADO'],
            'COMENTARIODELITEM' => ['COMENTARIODELITEM'],
            'COMENTARIOSFINALES' => ['COMENTARIOSFINALES'],
            'PLANESDEACCION' => ['PLANESDEACCION'],
            'RUT' => ['RUT'],
            'NOMBRE' => ['NOMBRE'],
            'EVALUADOR' => ['EVALUADOR'],
            'CARGO' => ['CARGO'],
            'FECHA' => ['FECHA'],
            'CONTRATISTA' => ['CONTRATISTA'],
            'SOLICITUD' => ['SOLICITUD'],
        ], 'terreno_histori.csv');

        $evaluaciones = [];
        $mapeoSecciones = [];
        $mapeoPreguntas = [];
        $detallesFilas = [];
        $rutsNormalizacion = [];
        $duplicadas = 0;
        $errores = 0;
        $filas = 0;

        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $filas++;
            $excelRow = $filas + 1;
            $codigoEvaluacion = trim((string)cellByHeader($row, $resolved, 'CODIGODEEVALUACION'));
            $rut = normalizarRutHistorico((string)cellByHeader($row, $resolved, 'RUT'));
            $area = trim((string)cellByHeader($row, $resolved, 'AREA'));
            $item = trim((string)cellByHeader($row, $resolved, 'ITEM'));

            if ($codigoEvaluacion === '' && $rut === '' && $area === '' && $item === '') {
                continue;
            }

            if ($codigoEvaluacion === '' || !validarRutHistorico($rut)) {
                $errores++;
                appendLimited($detallesFilas, [
                    'fila' => $excelRow,
                    'codigo' => $codigoEvaluacion,
                    'rut' => $rut,
                    'estado' => 'ERROR',
                    'motivo' => 'Código de evaluación o RUT inválido.',
                ], TERRENO_PREVIEW_LIMIT);
                continue;
            }

            $key = $codigoEvaluacion . '|' . $rut;
            $usuario = trim((string)cellByHeader($row, $resolved, 'USUARIO'));
            $evaluadorTxt = trim((string)cellByHeader($row, $resolved, 'EVALUADOR'));
            $resultado = parseNullableFloat(cellByHeader($row, $resolved, 'RESULTADO'));
            $fechaInicio = parseHistoricalDateTimeByMode(cellByHeader($row, $resolved, 'FECHAINICIAL'), 'DMY');
            $fechaFin = parseHistoricalDateTimeByMode(cellByHeader($row, $resolved, 'FECHAFINAL'), 'DMY');
            $fechaAprob = parseHistoricalDateTimeByMode(cellByHeader($row, $resolved, 'FECHADEAPROBACION'), 'DMY');
            $fechaEval = parseHistoricalDateTimeByMode(cellByHeader($row, $resolved, 'FECHA'), 'MDY');
            $nombreMain = trim((string)cellByHeader($row, $resolved, 'NOMBRE'));
            $cargoMain = trim((string)cellByHeader($row, $resolved, 'CARGO'));
            $empresaMain = trim((string)cellByHeader($row, $resolved, 'CONTRATISTA'));
            $solicitud = trim((string)cellByHeader($row, $resolved, 'SOLICITUD'));
            $comentariosFinales = trim((string)cellByHeader($row, $resolved, 'COMENTARIOSFINALES'));

            if ($resultado === null || $fechaEval === null) {
                $errores++;
                appendLimited($detallesFilas, [
                    'fila' => $excelRow,
                    'codigo' => $codigoEvaluacion,
                    'rut' => $rut,
                    'estado' => 'ERROR',
                    'motivo' => 'Resultado o fecha principal inválida.',
                ], TERRENO_PREVIEW_LIMIT);
                continue;
            }

            if (!isset($evaluaciones[$key])) {
                $duplicada = isset($existingEvalKeys[$key]);
                if ($duplicada) {
                    $duplicadas++;
                }

                $worker = $workers['data'][$rut] ?? null;
                [$nombre, $apellidos] = splitFullNameWithWorker($nombreMain, $worker);
                $cargo = $cargoMain !== '' ? $cargoMain : (string)($worker['cargo_txt'] ?? '');
                $empresa = $empresaMain !== '' ? $empresaMain : (string)($worker['empresa_txt'] ?? '');
                $uoTxt = (string)($worker['uo_txt'] ?? '');

                $rutsNormalizacion[$rut] = [
                    'rut' => $rut,
                    'nombre_excel' => $nombre,
                    'apellidos_excel' => $apellidos,
                    'cargo_txt' => $cargo,
                    'empresa_txt' => $empresa,
                    'uo_txt' => $uoTxt,
                ];

                $evaluaciones[$key] = [
                    'codigo_evaluacion' => $codigoEvaluacion,
                    'rut' => $rut,
                    'nombre' => trim($nombre . ' ' . $apellidos),
                    'cargo' => $cargo,
                    'contratista' => $empresa,
                    'evaluador' => $evaluadorTxt !== '' ? $evaluadorTxt : $usuario,
                    'usuario' => $usuario,
                    'resultado' => $resultado,
                    'id_servicio' => $idServicio,
                    'fecha_evaluacion' => $fechaEval->format('Y-m-d'),
                    'fecha_inicio' => $fechaInicio?->format('Y-m-d H:i:s'),
                    'fecha_fin' => $fechaFin?->format('Y-m-d H:i:s'),
                    'fecha_aprobacion' => $fechaAprob?->format('Y-m-d'),
                    'comentarios_finales' => $comentariosFinales,
                    'solicitud' => ctype_digit($solicitud) ? (int)$solicitud : null,
                    'duplicada' => $duplicada,
                    'detalles' => [],
                ];
            }

            $sectionMatch = matchTerrenoSection($area, $instrumento['secciones']);
            $questionMatch = $sectionMatch['id'] > 0
                ? matchTerrenoQuestion($item, $instrumento['preguntas'][$sectionMatch['id']] ?? [])
                : ['status' => 'NO_ENCONTRADA', 'id' => 0, 'pregunta' => '', 'practico' => '', 'referente' => ''];

            $secKey = normalizeComparableText($area);
            if (!isset($mapeoSecciones[$secKey])) {
                appendLimitedAssoc($mapeoSecciones, $secKey, [
                    'historica' => $area,
                    'status' => $sectionMatch['status'],
                    'id' => $sectionMatch['id'],
                    'destino' => $sectionMatch['nombre'],
                ], TERRENO_PREVIEW_LIMIT);
            }

            $pregKey = normalizeComparableText($area . '|' . $item);
            if (!isset($mapeoPreguntas[$pregKey])) {
                appendLimitedAssoc($mapeoPreguntas, $pregKey, [
                    'seccion_historica' => $area,
                    'item_historico' => $item,
                    'status' => $questionMatch['status'],
                    'id' => $questionMatch['id'],
                    'destino' => $questionMatch['pregunta'],
                ], TERRENO_PREVIEW_LIMIT);
            }

            $evaluaciones[$key]['detalles'][] = [
                'codigo_area' => trim((string)cellByHeader($row, $resolved, 'CODIGODEAREA')),
                'area' => $area,
                'codigo_item' => trim((string)cellByHeader($row, $resolved, 'CODIGODELITEM')),
                'item' => $item,
                'respuesta' => trim((string)cellByHeader($row, $resolved, 'RESPUESTA')),
                'peso' => parseNullableFloat(cellByHeader($row, $resolved, 'PESO')),
                'resultado_item' => $resultado,
                'comentario_item' => trim((string)cellByHeader($row, $resolved, 'COMENTARIODELITEM')),
                'plan_accion' => trim((string)cellByHeader($row, $resolved, 'PLANESDEACCION')),
                'id_seccion' => $sectionMatch['id'],
                'id_pregunta' => $questionMatch['id'],
                'practico' => $questionMatch['practico'],
                'referente' => $questionMatch['referente'],
                'fecha_detalle' => $fechaEval->format('Y-m-d'),
            ];

            appendLimited($detallesFilas, [
                'fila' => $excelRow,
                'codigo' => $codigoEvaluacion,
                'rut' => $rut,
                'estado' => $evaluaciones[$key]['duplicada'] ? 'DUPLICADA' : 'IMPORTABLE',
                'motivo' => $evaluaciones[$key]['duplicada'] ? 'La evaluación ya existe en ceo_evaluacion_terreno.' : 'Evaluación válida para importar.',
            ], TERRENO_PREVIEW_LIMIT);
        }

        $evaluacionesValidas = [];
        foreach ($evaluaciones as $eval) {
            if (!$eval['duplicada']) {
                $evaluacionesValidas[] = $eval;
            }
        }

        return [
            'summary' => [
                'filas' => $filas,
                'evaluaciones_unicas' => count($evaluaciones),
                'evaluaciones_validas' => count($evaluacionesValidas),
                'duplicadas' => $duplicadas,
                'errores' => $errores,
                'detalles_filas' => $detallesFilas,
            ],
            'mapeo_secciones' => array_values($mapeoSecciones),
            'mapeo_preguntas' => array_values($mapeoPreguntas),
            'evaluaciones_validas' => $evaluacionesValidas,
            'ruts_normalizacion' => array_values($rutsNormalizacion),
        ];
    } finally {
        fclose($fh);
    }
}

function detectCsvDelimiter(string $path): string
{
    $line = '';
    $fh = fopen($path, 'r');
    if ($fh !== false) {
        $line = (string)fgets($fh);
        fclose($fh);
    }

    $candidates = [';' => 0, ',' => 0, "\t" => 0];
    foreach (array_keys($candidates) as $delimiter) {
        $parsed = str_getcsv($line, $delimiter, '"', '');
        $candidates[$delimiter] = is_array($parsed) ? count($parsed) : 0;
    }

    arsort($candidates);
    $best = (string)array_key_first($candidates);
    return $candidates[$best] > 1 ? $best : ';';
}

function importarHistoricoTerrenoCsv(PDO $pdo, array $evaluacionesValidas, array $normalizacionDetalles, int $idServicio, int $idGrupo): array
{
    $stmtExisteContratista = $pdo->prepare('SELECT 1 FROM ceo_contratistas WHERE rut = :rut LIMIT 1');
    $stmtInsertContratista = $pdo->prepare('INSERT INTO ceo_contratistas (rut, nombre, apellidos, correo, telefono, id_cargo, fecha_ingreso, id_empresa, uo) VALUES (:rut, :nombre, :apellidos, NULL, NULL, :id_cargo, CURDATE(), :id_empresa, :uo)');
    $stmtExisteEval = $pdo->prepare('SELECT 1 FROM ceo_evaluacion_terreno WHERE codigo_evaluacion = :codigo AND rut = :rut AND id_servicio = :servicio LIMIT 1');
    $stmtEval = $pdo->prepare('INSERT INTO ceo_evaluacion_terreno (codigo_evaluacion, rut, nombre, cargo, contratista, evaluador, usuario, resultado, id_servicio, fecha_evaluacion, fecha_inicio, fecha_fin, fecha_aprobacion, comentarios_finales) VALUES (:codigo, :rut, :nombre, :cargo, :contratista, :evaluador, :usuario, :resultado, :servicio, :fecha_eval, :fecha_inicio, :fecha_fin, :fecha_aprobacion, :comentarios_finales)');
    $stmtEvalDet = $pdo->prepare('INSERT INTO ceo_evaluacion_terreno_detalle (id_evaluacion_terreno, codigo_area, area, codigo_item, item, respuesta, peso, resultado_item, comentario_item, plan_accion) VALUES (:id_eval, :codigo_area, :area, :codigo_item, :item, :respuesta, :peso, :resultado_item, :comentario_item, :plan_accion)');
    $stmtSecRes = $pdo->prepare('INSERT INTO ceo_seccion_resultado_terreno (id_empresa, fecha_examen, hora_examen, id_servicio, nsolicitud) VALUES (:id_empresa, :fecha_examen, :hora_examen, :id_servicio, :nsolicitud)');
    $stmtResPruebaTerr = $pdo->prepare('INSERT INTO ceo_resultado_prueba_terreno (id_resultado, cumple, no_cumple, no_aplica, observaciones, id_pregunta, id_seccion, rut_contratista, practico, referente, fecha) VALUES (:id_resultado, :cumple, :no_cumple, :no_aplica, :observaciones, :id_pregunta, :id_seccion, :rut_contratista, :practico, :referente, :fecha)');
    $stmtIntento = $pdo->prepare('INSERT INTO ceo_resultado_terreno_intento (rut, id_servicio, id_evaluador, fecha_rendicion, hora_rendicion, puntaje_total, correctas, incorrectas, ncontestadas, noaplica, notafinal) VALUES (:rut, :id_servicio, :id_evaluador, :fecha, :hora, :puntaje_total, :correctas, :incorrectas, :ncontestadas, :noaplica, :notafinal)');

    $threshold = obtenerPorcentajeMinimoTerreno($pdo, $idGrupo);
    $normalizacionMap = [];
    foreach ($normalizacionDetalles as $detalle) {
        if (!empty($detalle['rut'])) {
            $normalizacionMap[$detalle['rut']] = $detalle;
        }
    }

    $evaluadorId = (int)($_SESSION['auth']['id'] ?? 0);
    $importadas = 0;
    $duplicadas = 0;
    $contratistasCreados = 0;
    $contratistasIncompletos = 0;
    $detallesMapeados = 0;
    $detalles = [];

    $pdo->beginTransaction();
    try {
        foreach ($evaluacionesValidas as $eval) {
            $rut = (string)$eval['rut'];

            $stmtExisteContratista->execute([':rut' => $rut]);
            if (!$stmtExisteContratista->fetchColumn()) {
                $detalleNorm = $normalizacionMap[$rut] ?? null;
                if (is_array($detalleNorm) && in_array((string)($detalleNorm['estado'] ?? ''), ['SE_CREARA', 'SE_CREARA_TRABAJADORES'], true)) {
                    $stmtInsertContratista->execute([
                        ':rut' => $rut,
                        ':nombre' => $detalleNorm['nombre'],
                        ':apellidos' => $detalleNorm['apellidos'],
                        ':id_cargo' => $detalleNorm['id_cargo'],
                        ':id_empresa' => $detalleNorm['id_empresa'],
                        ':uo' => $detalleNorm['uo'],
                    ]);
                    $contratistasCreados++;
                } elseif (is_array($detalleNorm) && (string)($detalleNorm['estado'] ?? '') === 'SE_CREARA_INCOMPLETO') {
                    $stmtInsertContratista->execute([
                        ':rut' => $rut,
                        ':nombre' => $detalleNorm['nombre'],
                        ':apellidos' => $detalleNorm['apellidos'],
                        ':id_cargo' => null,
                        ':id_empresa' => null,
                        ':uo' => null,
                    ]);
                    $contratistasCreados++;
                    $contratistasIncompletos++;
                }
            }

            $stmtExisteEval->execute([
                ':codigo' => $eval['codigo_evaluacion'],
                ':rut' => $rut,
                ':servicio' => $idServicio,
            ]);
            if ($stmtExisteEval->fetchColumn()) {
                $duplicadas++;
                $detalles[] = [
                    'rut' => $rut,
                    'codigo' => $eval['codigo_evaluacion'],
                    'estado' => 'DUPLICADA',
                    'motivo' => 'La evaluación ya existe en ceo_evaluacion_terreno.',
                ];
                continue;
            }

            $stmtEval->execute([
                ':codigo' => $eval['codigo_evaluacion'],
                ':rut' => $rut,
                ':nombre' => $eval['nombre'],
                ':cargo' => $eval['cargo'],
                ':contratista' => $eval['contratista'],
                ':evaluador' => $eval['evaluador'],
                ':usuario' => $eval['usuario'],
                ':resultado' => $eval['resultado'],
                ':servicio' => $idServicio,
                ':fecha_eval' => $eval['fecha_evaluacion'],
                ':fecha_inicio' => $eval['fecha_inicio'],
                ':fecha_fin' => $eval['fecha_fin'],
                ':fecha_aprobacion' => $eval['fecha_aprobacion'],
                ':comentarios_finales' => $eval['comentarios_finales'],
            ]);
            $idEvaluacion = (int)$pdo->lastInsertId();

            $norm = $normalizacionMap[$rut] ?? null;
            $idEmpresa = is_array($norm) ? ((int)($norm['id_empresa'] ?? 0) ?: null) : null;
            $horaExamen = '00:00:00';
            if (!empty($eval['fecha_fin'])) {
                $horaExamen = (new DateTimeImmutable($eval['fecha_fin']))->format('H:i:s');
            }
            $stmtSecRes->execute([
                ':id_empresa' => $idEmpresa,
                ':fecha_examen' => $eval['fecha_evaluacion'],
                ':hora_examen' => $horaExamen,
                ':id_servicio' => $idServicio,
                ':nsolicitud' => $eval['solicitud'],
            ]);
            $idResultadoSeccion = (int)$pdo->lastInsertId();

            $correctas = 0;
            $incorrectas = 0;
            $noaplica = 0;
            $ncontestadas = 0;

            foreach ($eval['detalles'] as $detalle) {
                $stmtEvalDet->execute([
                    ':id_eval' => $idEvaluacion,
                    ':codigo_area' => $detalle['codigo_area'],
                    ':area' => $detalle['area'],
                    ':codigo_item' => $detalle['codigo_item'],
                    ':item' => $detalle['item'],
                    ':respuesta' => $detalle['respuesta'],
                    ':peso' => $detalle['peso'],
                    ':resultado_item' => $detalle['resultado_item'],
                    ':comentario_item' => $detalle['comentario_item'],
                    ':plan_accion' => $detalle['plan_accion'],
                ]);

                [$cumple, $noCumple, $noAplica] = mapTerrenoRespuestaFlags((string)$detalle['respuesta']);
                if ($cumple) {
                    $correctas++;
                } elseif ($noCumple) {
                    $incorrectas++;
                } elseif ($noAplica) {
                    $noaplica++;
                } else {
                    $ncontestadas++;
                }

                if ((int)$detalle['id_seccion'] > 0 && (int)$detalle['id_pregunta'] > 0) {
                    $stmtResPruebaTerr->execute([
                        ':id_resultado' => $idResultadoSeccion,
                        ':cumple' => $cumple ? '1' : '0',
                        ':no_cumple' => $noCumple ? '1' : '0',
                        ':no_aplica' => $noAplica ? '1' : '0',
                        ':observaciones' => mb_substr((string)$detalle['comentario_item'], 0, 200),
                        ':id_pregunta' => (int)$detalle['id_pregunta'],
                        ':id_seccion' => (int)$detalle['id_seccion'],
                        ':rut_contratista' => $rut,
                        ':practico' => (string)$detalle['practico'],
                        ':referente' => (string)$detalle['referente'],
                        ':fecha' => $detalle['fecha_detalle'],
                    ]);
                    $detallesMapeados++;
                }
            }

            $fechaHoraIntento = !empty($eval['fecha_fin'])
                ? new DateTimeImmutable($eval['fecha_fin'])
                : (!empty($eval['fecha_inicio'])
                    ? new DateTimeImmutable($eval['fecha_inicio'])
                    : new DateTimeImmutable($eval['fecha_evaluacion'] . ' 00:00:00'));

            $notaFinal = calcularNotaFinalDesdePorcentaje((float)$eval['resultado'], $threshold);
            $stmtIntento->execute([
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':id_evaluador' => resolverIdEvaluadorTerreno($pdo, (string)$eval['usuario'], (string)$eval['evaluador']) ?: ($evaluadorId > 0 ? $evaluadorId : null),
                ':fecha' => $fechaHoraIntento->format('Y-m-d'),
                ':hora' => $fechaHoraIntento->format('H:i:s'),
                ':puntaje_total' => $eval['resultado'],
                ':correctas' => $correctas,
                ':incorrectas' => $incorrectas,
                ':ncontestadas' => $ncontestadas,
                ':noaplica' => $noaplica,
                ':notafinal' => $notaFinal,
            ]);

            $importadas++;
            $detalles[] = [
                'rut' => $rut,
                'codigo' => $eval['codigo_evaluacion'],
                'estado' => 'IMPORTADA',
                'motivo' => 'Cabecera, detalle y resumen de terreno importados correctamente.',
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'importadas' => $importadas,
        'duplicadas' => $duplicadas,
        'contratistas_creados' => $contratistasCreados,
        'contratistas_incompletos' => $contratistasIncompletos,
        'detalles_mapeados' => $detallesMapeados,
        'detalles' => $detalles,
    ];
}

function analizarNormalizacionContratistasTerreno(PDO $pdo, array $rutsNormalizacion): array
{
    $detalles = [];
    $existentes = 0;
    $normalizables = 0;
    $incompletos = 0;

    foreach ($rutsNormalizacion as $row) {
        $rut = (string)($row['rut'] ?? '');
        if ($rut === '' || isset($detalles[$rut])) {
            continue;
        }

        $contratista = obtenerContratistaPorRut($pdo, $rut);
        if ($contratista !== null) {
            $detalles[$rut] = [
                'rut' => $rut,
                'estado' => 'EXISTE',
                'nombre' => (string)($contratista['nombre'] ?? ''),
                'apellidos' => (string)($contratista['apellidos'] ?? ''),
                'id_cargo' => (int)($contratista['id_cargo'] ?? 0),
                'id_empresa' => (int)($contratista['id_empresa'] ?? 0),
                'uo' => (int)($contratista['uo'] ?? 0),
                'detalle' => 'Ya existe en ceo_contratistas.',
            ];
            $existentes++;
            continue;
        }

        $solicitud = obtenerSolicitudRecienteParaRut($pdo, $rut);
        if ($solicitud !== null) {
            $errores = validarDatosNormalizacion($pdo, $solicitud);
            if (empty($errores)) {
                $detalles[$rut] = [
                    'rut' => $rut,
                    'estado' => 'SE_CREARA',
                    'nombre' => (string)$solicitud['nombre'],
                    'apellidos' => (string)$solicitud['apellidos'],
                    'id_cargo' => (int)$solicitud['id_cargo'],
                    'id_empresa' => (int)$solicitud['id_empresa'],
                    'uo' => (int)$solicitud['uo'],
                    'detalle' => 'Se creará desde la solicitud más reciente (' . (string)$solicitud['id_solicitud'] . ').',
                ];
                $normalizables++;
                continue;
            }
        }

        $lookup = resolverDatosTrabajadorTerreno($pdo, $row);
        if (!empty($lookup['id_cargo']) && !empty($lookup['id_empresa']) && !empty($lookup['uo'])) {
            $detalles[$rut] = [
                'rut' => $rut,
                'estado' => 'SE_CREARA_TRABAJADORES',
                'nombre' => (string)$lookup['nombre'],
                'apellidos' => (string)$lookup['apellidos'],
                'id_cargo' => (int)$lookup['id_cargo'],
                'id_empresa' => (int)$lookup['id_empresa'],
                'uo' => (int)$lookup['uo'],
                'detalle' => 'Se creará desde el CSV de trabajadores.',
            ];
            $normalizables++;
        } else {
            $detalles[$rut] = [
                'rut' => $rut,
                'estado' => 'SE_CREARA_INCOMPLETO',
                'nombre' => (string)$lookup['nombre'],
                'apellidos' => (string)$lookup['apellidos'],
                'id_cargo' => 0,
                'id_empresa' => 0,
                'uo' => 0,
                'detalle' => 'No existe base completa. Se creará con datos mínimos.',
            ];
            $incompletos++;
        }
    }

    return [
        'existentes' => $existentes,
        'normalizables' => $normalizables,
        'sin_base' => $incompletos,
        'detalles' => array_values($detalles),
    ];
}

function obtenerContratistaPorRut(PDO $pdo, string $rut): ?array
{
    $stmt = $pdo->prepare('SELECT rut, nombre, apellidos, id_cargo, id_empresa, uo FROM ceo_contratistas WHERE rut = :rut LIMIT 1');
    $stmt->execute([':rut' => $rut]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function obtenerSolicitudRecienteParaRut(PDO $pdo, string $rut): ?array
{
    $stmt = $pdo->prepare("SELECT ps.id_solicitud, TRIM(ps.nombre) AS nombre, TRIM(CONCAT(COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS apellidos, ps.id_cargo, s.contratista AS id_empresa, s.uo FROM ceo_participantes_solicitud ps INNER JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud WHERE REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(:rut), '.', ''), '-', ''), ' ', '') ORDER BY s.fecha DESC, s.nsolicitud DESC LIMIT 1");
    $stmt->execute([':rut' => $rut]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function validarDatosNormalizacion(PDO $pdo, array $solicitud): array
{
    $errores = [];
    if (trim((string)($solicitud['nombre'] ?? '')) === '') {
        $errores[] = 'Nombre vacío.';
    }
    if (trim((string)($solicitud['apellidos'] ?? '')) === '') {
        $errores[] = 'Apellidos vacíos.';
    }
    $idCargo = (int)($solicitud['id_cargo'] ?? 0);
    $idEmpresa = (int)($solicitud['id_empresa'] ?? 0);
    $uo = (int)($solicitud['uo'] ?? 0);
    if ($idCargo <= 0 || !existeRegistroPorId($pdo, 'ceo_cargo_contratistas', $idCargo)) {
        $errores[] = 'Cargo no válido.';
    }
    if ($idEmpresa <= 0 || !existeRegistroPorId($pdo, 'ceo_empresas', $idEmpresa)) {
        $errores[] = 'Empresa no válida.';
    }
    if ($uo <= 0 || !existeRegistroPorId($pdo, 'ceo_uo', $uo)) {
        $errores[] = 'UO no válida.';
    }
    return $errores;
}

function existeRegistroPorId(PDO $pdo, string $tabla, int $id): bool
{
    if (!preg_match('/^ceo_[a-z0-9_]+$/', $tabla)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM {$tabla} WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return (bool)$stmt->fetchColumn();
}

function obtenerNombreServicio(PDO $pdo, int $idServicio): string
{
    $stmt = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idServicio]);
    return (string)($stmt->fetchColumn() ?: ('Servicio ID ' . $idServicio));
}

function resolverDatosTrabajadorTerreno(PDO $pdo, array $row): array
{
    return [
        'nombre' => trim((string)($row['nombre_excel'] ?? '')),
        'apellidos' => trim((string)($row['apellidos_excel'] ?? '')),
        'id_cargo' => buscarIdPorNombre($pdo, 'ceo_cargo_contratistas', 'cargo', (string)($row['cargo_txt'] ?? '')),
        'id_empresa' => buscarIdPorNombre($pdo, 'ceo_empresas', 'nombre', (string)($row['empresa_txt'] ?? '')),
        'uo' => buscarIdPorNombre($pdo, 'ceo_uo', 'desc_uo', (string)($row['uo_txt'] ?? '')),
    ];
}

function buscarIdPorNombre(PDO $pdo, string $tabla, string $columna, string $valor): ?int
{
    static $cache = [];
    $valor = trim($valor);
    if ($valor === '' || !preg_match('/^ceo_[a-z0-9_]+$/', $tabla) || !preg_match('/^[a-z0-9_]+$/i', $columna)) {
        return null;
    }
    $cacheKey = $tabla . '|' . $columna;
    if (!isset($cache[$cacheKey])) {
        $rows = $pdo->query("SELECT id, {$columna} AS texto FROM {$tabla}")->fetchAll(PDO::FETCH_ASSOC);
        $cache[$cacheKey] = [];
        foreach ($rows as $row) {
            $cache[$cacheKey][normalizeComparableText((string)$row['texto'])] = (int)$row['id'];
        }
    }
    $needle = normalizeComparableText($valor);
    return $cache[$cacheKey][$needle] ?? null;
}

function obtenerGrupoTerreno(PDO $pdo, int $idServicio): ?array
{
    $stmt = $pdo->prepare('SELECT id, grupo FROM ceo_agrupacion_terreno WHERE id_servicio = :id_servicio ORDER BY id ASC LIMIT 1');
    $stmt->execute([':id_servicio' => $idServicio]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function obtenerPorcentajeMinimoTerreno(PDO $pdo, int $idGrupo): float
{
    $stmt = $pdo->prepare('SELECT porcentaje FROM ceo_porcentaje_agrup_terreno WHERE id_agrupacion = :id AND activo = "S" ORDER BY fechadesde DESC LIMIT 1');
    $stmt->execute([':id' => $idGrupo]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (float)$value : 80.0;
}

function cargarInstrumentoTerreno(PDO $pdo, int $idGrupo): array
{
    $stmtSec = $pdo->prepare('SELECT id, seccion, nombre, orden FROM ceo_seccion_terreno WHERE id_grupo = :id_grupo ORDER BY orden ASC, id ASC');
    $stmtSec->execute([':id_grupo' => $idGrupo]);
    $secciones = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
    $stmtPreg = $pdo->prepare('SELECT id, id_seccion, pregunta, ponderacion, practico, referente, orden FROM ceo_preguntas_seccion_terreno WHERE id_seccion = :id_seccion ORDER BY orden ASC, id ASC');
    $instrumento = ['secciones' => [], 'preguntas' => []];
    foreach ($secciones as $seccion) {
        $sid = (int)$seccion['id'];
        $instrumento['secciones'][$sid] = [
            'id' => $sid,
            'seccion' => (string)$seccion['seccion'],
            'nombre' => (string)$seccion['nombre'],
            'seccion_norm' => normalizeComparableText((string)$seccion['seccion']),
            'nombre_norm' => normalizeComparableText((string)$seccion['nombre']),
        ];
        $stmtPreg->execute([':id_seccion' => $sid]);
        $preguntas = $stmtPreg->fetchAll(PDO::FETCH_ASSOC);
        foreach ($preguntas as $preg) {
            $instrumento['preguntas'][$sid][] = [
                'id' => (int)$preg['id'],
                'id_seccion' => $sid,
                'pregunta' => (string)$preg['pregunta'],
                'pregunta_norm' => normalizeComparableText((string)$preg['pregunta']),
                'ponderacion' => (int)($preg['ponderacion'] ?? 0),
                'practico' => (string)($preg['practico'] ?? ''),
                'referente' => (string)($preg['referente'] ?? ''),
            ];
        }
    }
    return $instrumento;
}

function cargarEvaluacionesTerrenoExistentes(PDO $pdo, int $idServicio): array
{
    $stmt = $pdo->prepare('SELECT codigo_evaluacion, rut FROM ceo_evaluacion_terreno WHERE id_servicio = :id_servicio');
    $stmt->execute([':id_servicio' => $idServicio]);
    $keys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keys[(string)$row['codigo_evaluacion'] . '|' . (string)$row['rut']] = true;
    }
    return $keys;
}

function matchTerrenoSection(string $area, array $sections): array
{
    $needle = normalizeComparableText($area);
    foreach ($sections as $section) {
        if ($section['nombre_norm'] === $needle || $section['seccion_norm'] === $needle) {
            return ['status' => 'MAPEADA', 'id' => (int)$section['id'], 'nombre' => (string)$section['nombre']];
        }
    }
    $matches = [];
    foreach ($sections as $section) {
        if ($needle !== '' && (str_contains($section['nombre_norm'], $needle) || str_contains($needle, $section['nombre_norm']))) {
            $matches[] = $section;
        }
    }
    if (count($matches) === 1) {
        return ['status' => 'POSIBLE', 'id' => (int)$matches[0]['id'], 'nombre' => (string)$matches[0]['nombre']];
    }
    return ['status' => 'NO_ENCONTRADA', 'id' => 0, 'nombre' => ''];
}

function matchTerrenoQuestion(string $item, array $questions): array
{
    $needle = normalizeComparableText($item);
    foreach ($questions as $question) {
        if ($question['pregunta_norm'] === $needle) {
            return ['status' => 'MAPEADA', 'id' => (int)$question['id'], 'pregunta' => (string)$question['pregunta'], 'practico' => (string)$question['practico'], 'referente' => (string)$question['referente']];
        }
    }
    $matches = [];
    foreach ($questions as $question) {
        if ($needle !== '' && (str_contains($question['pregunta_norm'], $needle) || str_contains($needle, $question['pregunta_norm']))) {
            $matches[] = $question;
        }
    }
    if (count($matches) === 1) {
        return ['status' => 'POSIBLE', 'id' => (int)$matches[0]['id'], 'pregunta' => (string)$matches[0]['pregunta'], 'practico' => (string)$matches[0]['practico'], 'referente' => (string)$matches[0]['referente']];
    }
    return ['status' => 'NO_ENCONTRADA', 'id' => 0, 'pregunta' => '', 'practico' => '', 'referente' => ''];
}

function splitFullNameWithWorker(string $fullName, ?array $worker): array
{
    $fullName = trim($fullName);
    if ($worker) {
        $nombre = trim((string)($worker['nombre'] ?? ''));
        $apellidos = trim((string)($worker['apellidos'] ?? ''));
        if ($nombre !== '' || $apellidos !== '') {
            return [$nombre, $apellidos];
        }
    }
    $parts = preg_split('/\s+/', $fullName) ?: [];
    if (count($parts) >= 3) {
        $apellidos = implode(' ', array_slice($parts, -2));
        $nombre = implode(' ', array_slice($parts, 0, -2));
        return [trim($nombre), trim($apellidos)];
    }
    return [$fullName, ''];
}

function mapTerrenoRespuestaFlags(string $respuesta): array
{
    $norm = normalizeComparableText($respuesta);
    $cumple = str_starts_with($norm, 'ALCANZO');
    $noCumple = str_starts_with($norm, 'NOALCANZO');
    $noAplica = str_starts_with($norm, 'NOSEAPLICA');
    return [$cumple, $noCumple, $noAplica];
}

function resolverIdEvaluadorTerreno(PDO $pdo, string $usuario, string $evaluador): ?int
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = $pdo->query('SELECT id, nombre, apellidop, apellidom FROM ceo_evaluador')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $full = trim(($row['nombre'] ?? '') . ' ' . ($row['apellidop'] ?? '') . ' ' . ($row['apellidom'] ?? ''));
            $cache[normalizeComparableText($full)] = (int)$row['id'];
        }
    }
    foreach ([$usuario, $evaluador] as $text) {
        $key = normalizeComparableText($text);
        if ($key !== '' && isset($cache[$key])) {
            return $cache[$key];
        }
    }
    return null;
}

function terrainPayloadDir(): string
{
    $dir = __DIR__ . '/../storage/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function writeTerrainPayload(array $payload): string
{
    $path = terrainPayloadDir() . '/terrain_payload_' . bin2hex(random_bytes(8)) . '.ser';
    file_put_contents($path, serialize($payload));
    return $path;
}

function readTerrainPayload(string $path): array
{
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('No se encontró el payload del análisis.');
    }
    $content = file_get_contents($path);
    $data = @unserialize((string)$content);
    if (!is_array($data)) {
        throw new RuntimeException('El payload del análisis es inválido.');
    }
    return $data;
}

function deleteTerrainPayload(string $path): void
{
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function appendLimited(array &$list, array $row, int $limit): void
{
    if (count($list) < $limit) {
        $list[] = $row;
    }
}

function appendLimitedAssoc(array &$list, string $key, array $row, int $limit): void
{
    if (!isset($list[$key]) && count($list) < $limit) {
        $list[$key] = $row;
    }
}

function buildHeaderMap(array $headerRow): array
{
    $map = [];
    foreach ($headerRow as $index => $value) {
        $key = normalizeHeaderKey((string)$value);
        if ($key !== '') {
            $map[$key] = $index;
        }
    }
    return $map;
}

function resolveRequiredHeaders(array $headerMap, array $aliases, string $fileLabel): array
{
    $resolved = [];
    foreach ($aliases as $canonical => $options) {
        $found = null;
        foreach ($options as $option) {
            if (array_key_exists($option, $headerMap)) {
                $found = $headerMap[$option];
                break;
            }
        }
        if ($found === null) {
            $detected = implode(', ', array_keys($headerMap));
            throw new RuntimeException($fileLabel . ' no contiene la columna requerida: ' . $canonical . '. Encabezados detectados: ' . $detected);
        }
        $resolved[$canonical] = $found;
    }
    return $resolved;
}

function cellByHeader(array $row, array $headerMap, string $header)
{
    $idx = $headerMap[$header] ?? null;
    return $idx === null ? null : ($row[$idx] ?? null);
}

function normalizeHeaderKey(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = strtr($value, [
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
    ]);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? $value;
    return $value;
}

function normalizeComparableText(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function normalizarRutHistorico(string $rut): string
{
    $limpio = strtoupper(trim($rut));
    $limpio = str_replace(['.', '-', ' '], '', $limpio);
    if (strlen($limpio) < 2) {
        return $limpio;
    }
    return substr($limpio, 0, -1) . '-' . substr($limpio, -1);
}

function validarRutHistorico(string $rut): bool
{
    if (strlen($rut) < 2) {
        return false;
    }
    $rut = str_replace(['.', '-', ' '], '', strtoupper($rut));
    $cuerpo = substr($rut, 0, -1);
    $dv = substr($rut, -1);
    if (!ctype_digit($cuerpo)) {
        return false;
    }
    $suma = 0;
    $multiplicador = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += $multiplicador * (int)$cuerpo[$i];
        $multiplicador = $multiplicador < 7 ? $multiplicador + 1 : 2;
    }
    $resto = 11 - ($suma % 11);
    $esperado = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string)$resto);
    return $dv === $esperado;
}

function parseHistoricalDateTimeByMode($value, string $preferredMode): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $parts = preg_split('/\s+/', $text, 2);
    $datePart = $parts[0] ?? '';
    $timePart = $parts[1] ?? '';
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $datePart, $m)) {
        return parseExcelDateTimeValue($text);
    }
    $a = (int)$m[1];
    $b = (int)$m[2];
    $year = $m[3];
    $mode = $preferredMode;
    if ($a > 12 && $b <= 12) {
        $mode = 'DMY';
    } elseif ($b > 12 && $a <= 12) {
        $mode = 'MDY';
    }
    $normalized = $mode === 'DMY'
        ? sprintf('%02d/%02d/%s', $a, $b, $year)
        : sprintf('%02d/%02d/%s', $b, $a, $year);
    return parseExcelDateTimeValue(trim($normalized . ' ' . $timePart));
}

function parseExcelDateTimeValue($value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $formats = ['d/m/Y H:i:s','j/n/Y H:i:s','d/m/Y G:i:s','j/n/Y G:i:s','d/m/y H:i:s','j/n/y H:i:s','d/m/y G:i:s','j/n/y G:i:s','d/m/Y H:i','j/n/Y H:i','d/m/Y G:i','j/n/Y G:i','d/m/y H:i','j/n/y H:i','d/m/y G:i','j/n/y G:i','Y-m-d H:i:s','Y-m-d H:i','d/m/Y','j/n/Y','d/m/y','j/n/y'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $text);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0);
            if (!$hasErrors) {
                return strpos($format, 'H:i') === false ? $dt->setTime(0, 0, 0) : $dt;
            }
        }
    }
    return null;
}

function parseNullableFloat($value): ?float
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = str_replace(',', '.', $text);
    return is_numeric($text) ? round((float)$text, 2) : null;
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Carga Histórica Terreno | <?= esc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .table thead th { background:#eaf2fb; }
    .summary-box { background:#fff; border-radius:1rem; box-shadow:0 2px 8px rgba(0,0,0,.05); }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="d-flex gap-2 align-items-center">
      <img src="<?= APP_LOGO ?>" style="height:55px;" alt="Logo">
      <div>
        <div class="fw-bold"><?= APP_NAME ?></div>
        <small class="text-muted"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="/ceo.noetica.cl/public/general.php" class="btn btn-outline-secondary btn-sm">&larr; Volver</a>
  </div>
</header>

<div class="container mb-5">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h4 class="text-primary mb-2"><i class="bi bi-clipboard-data me-2"></i>Carga Histórica Terreno</h4>
      <p class="text-muted mb-0">Importador aislado para historial consultable de evaluaciones de terreno a partir de <strong>2 CSV</strong>: <code>terreno_histori.csv</code> y <code>trabajadores.csv</code>.</p>
    </div>
  </div>

  <?php if ($mensaje !== ''): ?>
    <div class="alert alert-<?= esc($mensajeTipo) ?>"><?= esc($mensaje) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="accion" value="analizar">
        <div class="col-md-4">
          <label class="form-label fw-semibold">CSV terreno</label>
          <input type="file" name="csv_terreno" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">CSV trabajadores</label>
          <input type="file" name="csv_trabajadores" class="form-control" accept=".csv" required>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select" required>
            <?php foreach ($servicios as $servicio): ?>
              <option value="<?= (int)$servicio['id'] ?>" <?= $servicioSeleccionado === (int)$servicio['id'] ? 'selected' : '' ?>><?= (int)$servicio['id'] ?> - <?= esc((string)$servicio['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Analizar</button>
        </div>
      </form>
      <?php if ($analisis): ?>
        <form method="post" class="mt-3">
          <input type="hidden" name="accion" value="limpiar">
          <button class="btn btn-outline-secondary" type="submit">Limpiar análisis</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (is_array($analisis)): ?>
    <div class="summary-box p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div>
          <h5 class="mb-1">Resumen del análisis</h5>
          <div class="text-muted small">Generado el <?= esc((string)$analisis['created_at']) ?></div>
          <div class="text-muted small">Servicio: <strong><?= esc((string)$analisis['servicio']) ?></strong> (ID <?= (int)$analisis['id_servicio'] ?>) | Agrupación: <strong><?= esc((string)$analisis['grupo']) ?></strong> (ID <?= (int)$analisis['id_grupo'] ?>)</div>
        </div>
        <form method="post" class="m-0">
          <input type="hidden" name="accion" value="importar">
          <button class="btn btn-success" type="submit" <?= empty($analisis['payload_file']) ? 'disabled' : '' ?>><i class="bi bi-database-add me-1"></i>Confirmar carga</button>
        </form>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Trabajadores hoja</div><div class="fs-4 fw-bold"><?= (int)$analisis['workers']['total'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Evaluaciones únicas</div><div class="fs-4 fw-bold"><?= (int)$analisis['evaluaciones']['evaluaciones_unicas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Evaluaciones válidas</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['evaluaciones']['evaluaciones_validas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Duplicadas</div><div class="fs-4 fw-bold text-secondary"><?= (int)$analisis['evaluaciones']['duplicadas'] ?></div></div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">RUTs ya existentes</div><div class="fs-4 fw-bold text-primary"><?= (int)$analisis['normalizacion']['existentes'] ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Contratistas a crear</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['normalizacion']['normalizables'] ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Incompletos</div><div class="fs-4 fw-bold text-warning"><?= (int)$analisis['normalizacion']['sin_base'] ?></div></div></div>
      </div>

      <div class="row g-4">
        <div class="col-lg-6">
          <h6 class="text-primary">Mapeo de secciones</h6>
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead><tr><th>Área histórica</th><th>ID</th><th>Sección CEONEXT</th><th>Estado</th></tr></thead>
              <tbody>
              <?php foreach ($analisis['mapeo_secciones'] as $m): ?>
                <tr><td><?= esc((string)$m['historica']) ?></td><td><?= esc((string)$m['id']) ?></td><td><?= esc((string)$m['destino']) ?></td><td><?= esc((string)$m['status']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="col-lg-6">
          <h6 class="text-primary">Mapeo de preguntas</h6>
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead><tr><th>Sección</th><th>Ítem histórico</th><th>ID</th><th>Pregunta CEONEXT</th><th>Estado</th></tr></thead>
              <tbody>
              <?php foreach ($analisis['mapeo_preguntas'] as $m): ?>
                <tr><td><?= esc((string)$m['seccion_historica']) ?></td><td><?= esc((string)$m['item_historico']) ?></td><td><?= esc((string)$m['id']) ?></td><td><?= esc((string)$m['destino']) ?></td><td><?= esc((string)$m['status']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-lg-6">
          <h6 class="text-primary">Normalización de contratistas</h6>
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead><tr><th>RUT</th><th>Nombre</th><th>Apellidos</th><th>Estado</th><th>Detalle</th></tr></thead>
              <tbody>
              <?php foreach ($analisis['normalizacion']['detalles'] as $d): ?>
                <tr><td><?= esc((string)$d['rut']) ?></td><td><?= esc((string)$d['nombre']) ?></td><td><?= esc((string)$d['apellidos']) ?></td><td><?= esc((string)$d['estado']) ?></td><td><?= esc((string)$d['detalle']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="col-lg-6">
          <h6 class="text-primary">Estado por fila</h6>
          <div class="table-responsive" style="max-height:260px; overflow:auto;">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead><tr><th>Fila</th><th>Código</th><th>RUT</th><th>Estado</th><th>Motivo</th></tr></thead>
              <tbody>
              <?php foreach ($analisis['evaluaciones']['detalles_filas'] as $f): ?>
                <tr><td><?= esc((string)$f['fila']) ?></td><td><?= esc((string)$f['codigo']) ?></td><td><?= esc((string)$f['rut']) ?></td><td><?= esc((string)$f['estado']) ?></td><td><?= esc((string)$f['motivo']) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (is_array($resultadoImportacion) && !empty($resultadoImportacion['detalles'])): ?>
    <div class="summary-box p-4 mb-4">
      <h5 class="mb-3">Resultado real de la importación</h5>
      <div class="table-responsive" style="max-height:360px; overflow:auto;">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead><tr><th>RUT</th><th>Código</th><th>Estado</th><th>Motivo</th></tr></thead>
          <tbody>
          <?php foreach ($resultadoImportacion['detalles'] as $d): ?>
            <tr><td><?= esc((string)$d['rut']) ?></td><td><?= esc((string)$d['codigo']) ?></td><td><?= esc((string)$d['estado']) ?></td><td><?= esc((string)$d['motivo']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
