<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/app.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

if (empty($_SESSION['auth'])) {
    header('Location: /ceo/public/index.php');
    exit;
}

$rol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($rol !== 1) {
    header('Location: /ceo.noetica.cl/public/general.php');
    exit;
}

const HISTORICO_SHEET_AREAS = 'AREAS';
const HISTORICO_SHEET_PREGUNTAS = 'PREGUNTAS';
const HISTORICO_SHEET_DETALLE = 'DETALLE RESPUESTAS';
const HISTORICO_SESSION_KEY = 'historico_prueba_teorica_cyr';

$pdo = db();
$servicios = $pdo->query("SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC")->fetchAll(PDO::FETCH_ASSOC);
$mensaje = '';
$mensajeTipo = 'info';
$analisis = $_SESSION[HISTORICO_SESSION_KEY] ?? null;
$resultadoImportacion = null;
$servicioSeleccionado = (int)($_POST['id_servicio'] ?? ($analisis['id_servicio'] ?? 2));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'analizar') {
        try {
            if (empty($_FILES['excel']['tmp_name'])) {
                throw new RuntimeException('Debe seleccionar un archivo Excel.');
            }

            if ($servicioSeleccionado <= 0) {
                throw new RuntimeException('Debe seleccionar un servicio válido.');
            }

            $analisis = analizarHistoricoTeorico($pdo, $_FILES['excel']['tmp_name'], $servicioSeleccionado);
            $_SESSION[HISTORICO_SESSION_KEY] = $analisis;
            $mensaje = 'Análisis completado. Revise el resumen antes de confirmar la carga.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            unset($_SESSION[HISTORICO_SESSION_KEY]);
            $analisis = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'importar') {
        try {
            if (!is_array($analisis) || empty($analisis['rows_validos'])) {
                throw new RuntimeException('No hay un análisis válido disponible para importar.');
            }

            $resultado = importarHistoricoTeorico(
                $pdo,
                $analisis['rows_validos'],
                $analisis['normalizacion']['detalles'] ?? [],
                (int)$analisis['id_servicio']
            );
            unset($_SESSION[HISTORICO_SESSION_KEY]);
            $analisis = null;
            $resultadoImportacion = $resultado;

            $mensaje = sprintf(
                'Carga finalizada. Importados: %d. Duplicados omitidos: %d. Contratistas creados: %d. Contratistas creados incompletos: %d.',
                $resultado['importados'],
                $resultado['duplicados'],
                $resultado['contratistas_creados'],
                $resultado['contratistas_incompletos']
            );
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'limpiar') {
        unset($_SESSION[HISTORICO_SESSION_KEY]);
        $analisis = null;
        $mensaje = 'Análisis descartado.';
        $mensajeTipo = 'secondary';
    }
}

function analizarHistoricoTeorico(PDO $pdo, string $tmpPath, int $idServicio): array
{
    $spreadsheet = IOFactory::load($tmpPath);

    $sheetAreas = findSheetByName($spreadsheet, HISTORICO_SHEET_AREAS);
    $sheetPreguntas = findSheetByName($spreadsheet, HISTORICO_SHEET_PREGUNTAS);
    $sheetDetalle = findSheetByName($spreadsheet, HISTORICO_SHEET_DETALLE);

    if ($sheetAreas === null || $sheetPreguntas === null || $sheetDetalle === null) {
        throw new RuntimeException('El archivo debe contener las hojas Areas, Preguntas y Detalle Respuestas.');
    }

    $areasInfo = analizarSheetAreas($sheetAreas);
    $preguntasInfo = analizarSheetPreguntas($sheetPreguntas, $idServicio);
    $detalleInfo = analizarSheetDetalle($pdo, $sheetDetalle, $idServicio);

    return [
        'created_at' => date('Y-m-d H:i:s'),
        'id_servicio' => $idServicio,
        'servicio' => obtenerNombreServicio($pdo, $idServicio),
        'areas' => $areasInfo,
        'preguntas' => $preguntasInfo,
        'detalle' => [
            'total_filas' => $detalleInfo['total_filas'],
            'validas' => count($detalleInfo['rows_validos']),
            'duplicadas' => $detalleInfo['duplicadas'],
            'errores' => $detalleInfo['errores'],
            'detalles_filas' => $detalleInfo['detalles_filas'],
        ],
        'normalizacion' => analizarNormalizacionContratistas($pdo, $detalleInfo['rows_normalizacion']),
        'rows_validos' => $detalleInfo['rows_validos'],
    ];
}

function importarHistoricoTeorico(PDO $pdo, array $rowsValidos, array $normalizacionDetalles, int $idServicio): array
{
    $stmtExiste = $pdo->prepare(
        'SELECT 1 FROM ceo_resultado_prueba_intento WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha AND hora_rendicion = :hora LIMIT 1'
    );

    $stmtExisteContratista = $pdo->prepare('SELECT 1 FROM ceo_contratistas WHERE rut = :rut LIMIT 1');

    $stmtInsertContratista = $pdo->prepare("
        INSERT INTO ceo_contratistas (rut, nombre, apellidos, correo, telefono, id_cargo, fecha_ingreso, id_empresa, uo)
        VALUES (:rut, :nombre, :apellidos, NULL, NULL, :id_cargo, CURDATE(), :id_empresa, :uo)
    ");

    $stmtInsert = $pdo->prepare("
        INSERT INTO ceo_resultado_prueba_intento
            (rut, id_servicio, id_evaluador, fecha_rendicion, hora_rendicion, puntaje_total, correctas, incorrectas, ncontestadas, noaplica, notafinal)
        VALUES
            (:rut, :servicio, :evaluador, :fecha, :hora, :puntaje, :correctas, :incorrectas, :ncontestadas, :noaplica, :nota)
    ");

    $evaluadorId = (int)($_SESSION['auth']['id'] ?? 0);
    $importados = 0;
    $duplicados = 0;
    $contratistasCreados = 0;
    $contratistasIncompletos = 0;
    $detalles = [];
    $normalizacionMap = [];
    foreach ($normalizacionDetalles as $detalle) {
        if (!empty($detalle['rut'])) {
            $normalizacionMap[$detalle['rut']] = $detalle;
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ($rowsValidos as $row) {
            $rut = $row['rut'];
            $fechaHora = $row['fecha_rendicion'] . ' ' . $row['hora_rendicion'];
            $contratistaEstado = 'YA_EXISTE';
            $motivoContratista = 'Ya existe en ceo_contratistas.';

            $stmtExisteContratista->execute([':rut' => $rut]);
            $existeContratista = (bool)$stmtExisteContratista->fetchColumn();

            if (!$existeContratista) {
                $detalleNorm = $normalizacionMap[$rut] ?? null;
                if (is_array($detalleNorm) && ($detalleNorm['estado'] ?? '') === 'SE_CREARA') {
                    $stmtInsertContratista->execute([
                        ':rut' => $rut,
                        ':nombre' => $detalleNorm['nombre'],
                        ':apellidos' => $detalleNorm['apellidos'],
                        ':id_cargo' => $detalleNorm['id_cargo'],
                        ':id_empresa' => $detalleNorm['id_empresa'],
                        ':uo' => $detalleNorm['uo'],
                    ]);
                    $contratistasCreados++;
                    $contratistaEstado = 'CREADO';
                    $motivoContratista = 'Contratista creado desde solicitudes.';
                } elseif (is_array($detalleNorm) && ($detalleNorm['estado'] ?? '') === 'SE_CREARA_INCOMPLETO') {
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
                    $contratistaEstado = 'CREADO_INCOMPLETO';
                    $motivoContratista = 'Contratista creado con datos mínimos desde el Excel.';
                } else {
                    $contratistaEstado = 'NO_CREADO';
                    $motivoContratista = is_array($detalleNorm)
                        ? (string)($detalleNorm['detalle'] ?? 'Sin base para crear contratista.')
                        : 'Sin base para crear contratista.';
                }
            }

            $stmtExiste->execute([
                ':rut' => $rut,
                ':servicio' => $idServicio,
                ':fecha' => $row['fecha_rendicion'],
                ':hora' => $row['hora_rendicion'],
            ]);

            if ($stmtExiste->fetchColumn()) {
                $duplicados++;
                $detalles[] = [
                    'rut' => $rut,
                    'fecha_hora' => $fechaHora,
                    'historial_estado' => 'DUPLICADO',
                    'contratista_estado' => $contratistaEstado,
                    'motivo' => 'Registro ya existente en ceo_resultado_prueba_intento. ' . $motivoContratista,
                ];
                continue;
            }

            $stmtInsert->execute([
                ':rut' => $rut,
                ':servicio' => $idServicio,
                ':evaluador' => $evaluadorId > 0 ? $evaluadorId : null,
                ':fecha' => $row['fecha_rendicion'],
                ':hora' => $row['hora_rendicion'],
                ':puntaje' => $row['puntaje_total'],
                ':correctas' => $row['correctas'],
                ':incorrectas' => $row['incorrectas'],
                ':ncontestadas' => $row['ncontestadas'],
                ':noaplica' => $row['noaplica'],
                ':nota' => $row['notafinal'],
            ]);
            $importados++;
            $detalles[] = [
                'rut' => $rut,
                'fecha_hora' => $fechaHora,
                'historial_estado' => 'IMPORTADO',
                'contratista_estado' => $contratistaEstado,
                'motivo' => 'Historial importado correctamente. ' . $motivoContratista,
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'importados' => $importados,
        'duplicados' => $duplicados,
        'contratistas_creados' => $contratistasCreados,
        'contratistas_incompletos' => $contratistasIncompletos,
        'detalles' => $detalles,
    ];
}

function analizarNormalizacionContratistas(PDO $pdo, array $rowsValidos): array
{
    $detalles = [];
    $existentes = 0;
    $normalizables = 0;
    $sinBase = 0;

    foreach ($rowsValidos as $row) {
        $rut = (string)($row['rut'] ?? '');
        if ($rut === '') {
            continue;
        }
        if (isset($detalles[$rut])) {
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
        if ($solicitud === null) {
            $detalles[$rut] = [
                'rut' => $rut,
                'estado' => 'SE_CREARA_INCOMPLETO',
                'nombre' => (string)($row['nombre_excel'] ?? ''),
                'apellidos' => (string)($row['apellidos_excel'] ?? ''),
                'id_cargo' => 0,
                'id_empresa' => 0,
                'uo' => 0,
                'detalle' => 'No existe contratista ni información en solicitudes. Se creará con datos mínimos desde el Excel.',
            ];
            $sinBase++;
            continue;
        }

        $errores = validarDatosNormalizacion($pdo, $solicitud);
        if (!empty($errores)) {
            $detalles[$rut] = [
                'rut' => $rut,
                'estado' => 'SE_CREARA_INCOMPLETO',
                'nombre' => (string)$solicitud['nombre'],
                'apellidos' => (string)$solicitud['apellidos'],
                'id_cargo' => (int)$solicitud['id_cargo'],
                'id_empresa' => (int)$solicitud['id_empresa'],
                'uo' => (int)$solicitud['uo'],
                'detalle' => 'Datos parciales en solicitudes: ' . implode(' ', $errores) . ' Se creará incompleto con nombre/apellidos disponibles.',
            ];
            $sinBase++;
            continue;
        }

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
    }

    return [
        'existentes' => $existentes,
        'normalizables' => $normalizables,
        'sin_base' => $sinBase,
        'detalles' => array_values($detalles),
    ];
}

function analizarSheetAreas($sheet): array
{
    $rows = $sheet->toArray(null, true, true, false);
    $count = 0;

    foreach ($rows as $index => $row) {
        if ($index === 0) {
            continue;
        }
        $numero = trim((string)($row[0] ?? ''));
        $descripcion = trim((string)($row[1] ?? ''));
        if ($numero === '' && $descripcion === '') {
            continue;
        }
        $count++;
    }

    return [
        'registros' => $count,
        'accion' => 'Solo validación referencial. No se insertan áreas.',
    ];
}

function analizarSheetPreguntas($sheet, int $idServicio): array
{
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
        throw new RuntimeException('La hoja Preguntas está vacía.');
    }

    $headerMap = buildHeaderMap($rows[0]);
    $required = ['NIVEL', 'PREGUNTA', 'TIPO', 'N', 'RESPUESTA', 'CORRECTA'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $headerMap)) {
            throw new RuntimeException('La hoja Preguntas no contiene la columna requerida: ' . $key);
        }
    }

    $total = 0;
    $nivelValido = 0;
    $preguntasHistoricas = [];
    $errores = [];

    foreach (array_slice($rows, 1) as $linea => $row) {
        $nivel = trim((string)cellByHeader($row, $headerMap, 'NIVEL'));
        $pregunta = trim((string)cellByHeader($row, $headerMap, 'PREGUNTA'));
        $numeroHistorico = trim((string)cellByHeader($row, $headerMap, 'N'));

        if ($nivel === '' && $pregunta === '' && $numeroHistorico === '') {
            continue;
        }

        $total++;
        if ((int)$nivel === $idServicio) {
            $nivelValido++;
        } else {
            $errores[] = 'Fila ' . ($linea + 2) . ': Nivel distinto del servicio seleccionado (' . $nivel . ').';
        }

        if ($numeroHistorico !== '') {
            $preguntasHistoricas[] = $numeroHistorico;
        }
    }

    return [
        'registros' => $total,
        'nivel_valido' => $nivelValido,
        'preguntas_unicas' => count(array_unique($preguntasHistoricas)),
        'errores' => $errores,
    ];
}

function analizarSheetDetalle(PDO $pdo, $sheet, int $idServicio): array
{
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
        throw new RuntimeException('La hoja Detalle Respuestas está vacía.');
    }

    $headerMap = buildHeaderMap($rows[0]);
    $required = ['RUT', 'APELLIDOPATERNO', 'APELLIDOMATERNO', 'NOMBRES', 'NOTA', 'CORR', 'ERRNO', 'NCON', 'NASI', 'NOTAFINAL'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $headerMap)) {
            throw new RuntimeException('La hoja Detalle Respuestas no contiene la columna requerida: ' . $key);
        }
    }

    $fechaHeader = null;
    foreach (['FECHA', 'FECHAHORA'] as $candidate) {
        if (array_key_exists($candidate, $headerMap)) {
            $fechaHeader = $candidate;
            break;
        }
    }
    if ($fechaHeader === null) {
        throw new RuntimeException('La hoja Detalle Respuestas no contiene la columna requerida: Fecha.');
    }

    $stmtExiste = $pdo->prepare(
        'SELECT 1 FROM ceo_resultado_prueba_intento WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha AND hora_rendicion = :hora LIMIT 1'
    );

    $errores = [];
    $rowsValidos = [];
    $rowsNormalizacion = [];
    $duplicadas = 0;
    $totalFilas = 0;
    $detallesFilas = [];

    foreach (array_slice($rows, 1) as $linea => $row) {
        $rutRaw = trim((string)cellByHeader($row, $headerMap, 'RUT'));
        $fechaHoraRaw = cellByHeader($row, $headerMap, $fechaHeader);
        $nombres = trim((string)cellByHeader($row, $headerMap, 'NOMBRES'));
        $apPat = trim((string)cellByHeader($row, $headerMap, 'APELLIDOPATERNO'));
        $apMat = trim((string)cellByHeader($row, $headerMap, 'APELLIDOMATERNO'));

        if ($rutRaw === '' && $nombres === '' && $apPat === '' && $apMat === '' && trim((string)$fechaHoraRaw) === '') {
            continue;
        }

        $totalFilas++;
        $rut = normalizarRutHistorico($rutRaw);
        if (!validarRutHistorico($rut)) {
            $motivo = 'RUT inválido (' . $rutRaw . ').';
            $errores[] = 'Fila ' . ($linea + 2) . ': ' . $motivo;
            $detallesFilas[] = [
                'fila' => $linea + 2,
                'rut' => $rutRaw,
                'fecha_hora' => (string)$fechaHoraRaw,
                'estado' => 'ERROR',
                'motivo' => $motivo,
            ];
            continue;
        }

        $dt = parseExcelDateTimeValue($fechaHoraRaw);
        if ($dt === null) {
            $motivo = 'Fecha/hora inválida (' . (string)$fechaHoraRaw . ').';
            $errores[] = 'Fila ' . ($linea + 2) . ': ' . $motivo;
            $detallesFilas[] = [
                'fila' => $linea + 2,
                'rut' => $rut,
                'fecha_hora' => (string)$fechaHoraRaw,
                'estado' => 'ERROR',
                'motivo' => $motivo,
            ];
            continue;
        }

        $fecha = $dt->format('Y-m-d');
        $hora = $dt->format('H:i:s');

        $puntaje = parseNullableFloat(cellByHeader($row, $headerMap, 'NOTA'));
        $correctas = parseNullableInt(cellByHeader($row, $headerMap, 'CORR'));
        $incorrectas = parseNullableInt(cellByHeader($row, $headerMap, 'ERRNO'));
        $ncontestadas = parseNullableInt(cellByHeader($row, $headerMap, 'NCON'));
        $noaplica = parseNullableInt(cellByHeader($row, $headerMap, 'NASI'));
        $notafinal = parseNullableFloat(cellByHeader($row, $headerMap, 'NOTAFINAL'));

        if ($puntaje === null || $correctas === null || $incorrectas === null || $ncontestadas === null || $noaplica === null || $notafinal === null) {
            $motivo = 'Valores numéricos incompletos o inválidos.';
            $errores[] = 'Fila ' . ($linea + 2) . ': ' . $motivo;
            $detallesFilas[] = [
                'fila' => $linea + 2,
                'rut' => $rut,
                'fecha_hora' => $fecha . ' ' . $hora,
                'estado' => 'ERROR',
                'motivo' => $motivo,
            ];
            continue;
        }

        $rowsNormalizacion[] = [
            'rut' => $rut,
            'fecha_rendicion' => $fecha,
            'hora_rendicion' => $hora,
            'nombre_excel' => $nombres,
            'apellidos_excel' => trim($apPat . ' ' . $apMat),
        ];

        $stmtExiste->execute([
            ':rut' => $rut,
            ':servicio' => $idServicio,
            ':fecha' => $fecha,
            ':hora' => $hora,
        ]);

        if ($stmtExiste->fetchColumn()) {
            $duplicadas++;
            $detallesFilas[] = [
                'fila' => $linea + 2,
                'rut' => $rut,
                'fecha_hora' => $fecha . ' ' . $hora,
                'estado' => 'DUPLICADO',
                'motivo' => 'Ya existe en ceo_resultado_prueba_intento.',
            ];
            continue;
        }

        $rowsValidos[] = [
            'rut' => $rut,
            'fecha_rendicion' => $fecha,
            'hora_rendicion' => $hora,
            'puntaje_total' => $puntaje,
            'correctas' => $correctas,
            'incorrectas' => $incorrectas,
            'ncontestadas' => $ncontestadas,
            'noaplica' => $noaplica,
            'notafinal' => $notafinal,
        ];
        $detallesFilas[] = [
            'fila' => $linea + 2,
            'rut' => $rut,
            'fecha_hora' => $fecha . ' ' . $hora,
            'estado' => 'IMPORTABLE',
            'motivo' => 'Registro válido para importar.',
        ];
    }

    return [
        'total_filas' => $totalFilas,
        'duplicadas' => $duplicadas,
        'errores' => $errores,
        'rows_validos' => $rowsValidos,
        'rows_normalizacion' => $rowsNormalizacion,
        'detalles_filas' => $detallesFilas,
    ];
}

function obtenerContratistaPorRut(PDO $pdo, string $rut): ?array
{
    $stmt = $pdo->prepare('SELECT rut, nombre, apellidos, id_cargo, id_empresa, uo FROM ceo_contratistas WHERE rut = :rut LIMIT 1');
    $stmt->execute([':rut' => $rut]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function obtenerNombreServicio(PDO $pdo, int $idServicio): string
{
    $stmt = $pdo->prepare('SELECT servicio FROM ceo_servicios_pruebas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idServicio]);
    return (string)($stmt->fetchColumn() ?: ('Servicio ID ' . $idServicio));
}

function obtenerSolicitudRecienteParaRut(PDO $pdo, string $rut): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            ps.id_solicitud,
            TRIM(ps.nombre) AS nombre,
            TRIM(CONCAT(COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS apellidos,
            ps.id_cargo,
            s.contratista AS id_empresa,
            s.uo
        FROM ceo_participantes_solicitud ps
        INNER JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
        WHERE REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(:rut), '.', ''), '-', ''), ' ', '')
        ORDER BY s.fecha DESC, s.nsolicitud DESC
        LIMIT 1
    ");
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

function findSheetByName($spreadsheet, string $expectedName)
{
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        if (normalizeHeaderKey($sheet->getTitle()) === normalizeHeaderKey($expectedName)) {
            return $sheet;
        }
    }
    return null;
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

function cellByHeader(array $row, array $headerMap, string $header)
{
    $idx = $headerMap[$header] ?? null;
    return $idx === null ? null : ($row[$idx] ?? null);
}

function normalizeHeaderKey(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? $value;
    return $value;
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

function parseExcelDateTimeValue($value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            $dt = ExcelDate::excelToDateTimeObject((float)$value);
            return DateTimeImmutable::createFromMutable($dt);
        } catch (Throwable $e) {
            return null;
        }
    }

    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $formats = [
        'd/m/Y H:i:s',
        'j/n/Y H:i:s',
        'd/m/Y G:i:s',
        'j/n/Y G:i:s',
        'd/m/y H:i:s',
        'j/n/y H:i:s',
        'd/m/y G:i:s',
        'j/n/y G:i:s',
        'd/m/Y H:i',
        'j/n/Y H:i',
        'd/m/Y G:i',
        'j/n/Y G:i',
        'd/m/y H:i',
        'j/n/y H:i',
        'd/m/y G:i',
        'j/n/y G:i',
        'd-m-Y H:i:s',
        'j-n-Y H:i:s',
        'd-m-Y G:i:s',
        'j-n-Y G:i:s',
        'd-m-y H:i:s',
        'j-n-y H:i:s',
        'd-m-y G:i:s',
        'j-n-y G:i:s',
        'd-m-Y H:i',
        'j-n-Y H:i',
        'd-m-Y G:i',
        'j-n-Y G:i',
        'd-m-y H:i',
        'j-n-y H:i',
        'd-m-y G:i',
        'j-n-y G:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd/m/Y',
        'j/n/Y',
        'd/m/y',
        'j/n/y',
        'd-m-Y',
        'j-n-Y',
        'd-m-y',
        'j-n-y',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $text);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($hasErrors) {
                continue;
            }
            if (strpos($format, 'H:i') === false) {
                return $dt->setTime(0, 0, 0);
            }
            return $dt;
        }
    }

    // Para formatos con slash o guion, no usar fallback ambiguo.
    if (str_contains($text, '/') || preg_match('/^\d{1,2}-\d{1,2}-\d{4}/', $text)) {
        return null;
    }

    try {
        return new DateTimeImmutable($text);
    } catch (Throwable $e) {
        return null;
    }
}

function parseNullableInt($value): ?int
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (!is_numeric(str_replace(',', '.', $text))) {
        return null;
    }
    return (int)round((float)str_replace(',', '.', $text));
}

function parseNullableFloat($value): ?float
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return null;
    }
    return round((float)$text, 2);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Carga Histórica Prueba Teórica | <?= esc(APP_NAME) ?></title>
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
      <h4 class="text-primary mb-2"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Carga Histórica Prueba Teórica</h4>
      <p class="text-muted mb-0">Importador aislado para historial consultable de habilitaciones teóricas. Seleccione el servicio asociado al archivo antes de analizar.</p>
    </div>
  </div>

  <?php if ($mensaje !== ''): ?>
    <div class="alert alert-<?= esc($mensajeTipo) ?>"><?= esc($mensaje) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
        <input type="hidden" name="accion" value="analizar">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Archivo Excel</label>
          <input type="file" name="excel" class="form-control" accept=".xlsx,.xls" required>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Servicio</label>
          <select name="id_servicio" class="form-select" required>
            <?php foreach ($servicios as $servicio): ?>
              <option value="<?= (int)$servicio['id'] ?>" <?= $servicioSeleccionado === (int)$servicio['id'] ? 'selected' : '' ?>><?= (int)$servicio['id'] ?> - <?= esc((string)$servicio['servicio']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Analizar archivo</button>
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
          <div class="text-muted small">Servicio: <strong><?= esc((string)$analisis['servicio']) ?></strong> (ID <?= (int)$analisis['id_servicio'] ?>)</div>
        </div>
        <form method="post" class="m-0">
          <input type="hidden" name="accion" value="importar">
          <button class="btn btn-success" type="submit" <?= empty($analisis['rows_validos']) ? 'disabled' : '' ?>><i class="bi bi-database-add me-1"></i>Confirmar carga</button>
        </form>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Áreas detectadas</div><div class="fs-4 fw-bold"><?= (int)$analisis['areas']['registros'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Preguntas históricas</div><div class="fs-4 fw-bold"><?= (int)$analisis['preguntas']['preguntas_unicas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Filas válidas</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['detalle']['validas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Duplicadas</div><div class="fs-4 fw-bold text-secondary"><?= (int)$analisis['detalle']['duplicadas'] ?></div></div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">RUTs ya existentes</div><div class="fs-4 fw-bold text-primary"><?= (int)$analisis['normalizacion']['existentes'] ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Contratistas a crear</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['normalizacion']['normalizables'] ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3 bg-light"><div class="small text-muted">Sin base para crear</div><div class="fs-4 fw-bold text-warning"><?= (int)$analisis['normalizacion']['sin_base'] ?></div></div></div>
      </div>

      <div class="row g-4">
        <div class="col-lg-6">
          <h6 class="text-primary">Validación de hojas</h6>
          <ul class="mb-0">
            <li>Áreas: <?= esc((string)$analisis['areas']['accion']) ?></li>
            <li>Preguntas con nivel 2: <?= (int)$analisis['preguntas']['nivel_valido'] ?> de <?= (int)$analisis['preguntas']['registros'] ?></li>
            <li>Filas en Detalle Respuestas: <?= (int)$analisis['detalle']['total_filas'] ?></li>
            <li>La importación seguirá aunque un RUT no pueda normalizarse en contratistas.</li>
          </ul>
        </div>
        <div class="col-lg-6">
          <h6 class="text-primary">Errores detectados</h6>
          <?php $errores = array_merge($analisis['preguntas']['errores'], $analisis['detalle']['errores']); ?>
          <?php if (empty($errores)): ?>
            <div class="text-success">No se detectaron errores bloqueantes.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:260px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead><tr><th>Error</th></tr></thead>
                <tbody>
                <?php foreach ($errores as $err): ?>
                  <tr><td><?= esc((string)$err) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12">
          <h6 class="text-primary">Normalización de contratistas</h6>
          <?php if (empty($analisis['normalizacion']['detalles'])): ?>
            <div class="text-muted">No hay datos para normalizar.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:320px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>RUT</th>
                    <th>Nombre</th>
                    <th>Apellidos</th>
                    <th>ID Cargo</th>
                    <th>ID Empresa</th>
                    <th>UO</th>
                    <th>Estado</th>
                    <th>Detalle</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($analisis['normalizacion']['detalles'] as $detalle): ?>
                  <?php
                    $estado = (string)($detalle['estado'] ?? '');
                    $badge = 'secondary';
                    if ($estado === 'EXISTE') {
                        $badge = 'primary';
                    } elseif ($estado === 'SE_CREARA') {
                        $badge = 'success';
                    } elseif ($estado === 'SIN_BASE') {
                        $badge = 'warning';
                    }
                  ?>
                  <tr>
                    <td><?= esc((string)$detalle['rut']) ?></td>
                    <td><?= esc((string)$detalle['nombre']) ?></td>
                    <td><?= esc((string)$detalle['apellidos']) ?></td>
                    <td><?= esc((string)$detalle['id_cargo']) ?></td>
                    <td><?= esc((string)$detalle['id_empresa']) ?></td>
                    <td><?= esc((string)$detalle['uo']) ?></td>
                    <td><span class="badge text-bg-<?= esc($badge) ?>"><?= esc($estado) ?></span></td>
                    <td><?= esc((string)$detalle['detalle']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12">
          <h6 class="text-primary">Estado por registro detectado</h6>
          <?php if (empty($analisis['detalle']['detalles_filas'])): ?>
            <div class="text-muted">No hay filas para mostrar.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:320px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Fila</th>
                    <th>RUT</th>
                    <th>Fecha/Hora</th>
                    <th>Estado</th>
                    <th>Motivo</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($analisis['detalle']['detalles_filas'] as $detalleFila): ?>
                  <?php
                    $estadoFila = (string)($detalleFila['estado'] ?? '');
                    $badgeFila = 'secondary';
                    if ($estadoFila === 'IMPORTABLE') {
                        $badgeFila = 'success';
                    } elseif ($estadoFila === 'DUPLICADO') {
                        $badgeFila = 'warning';
                    } elseif ($estadoFila === 'ERROR') {
                        $badgeFila = 'danger';
                    }
                  ?>
                  <tr>
                    <td><?= esc((string)$detalleFila['fila']) ?></td>
                    <td><?= esc((string)$detalleFila['rut']) ?></td>
                    <td><?= esc((string)$detalleFila['fecha_hora']) ?></td>
                    <td><span class="badge text-bg-<?= esc($badgeFila) ?>"><?= esc($estadoFila) ?></span></td>
                    <td><?= esc((string)$detalleFila['motivo']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (is_array($resultadoImportacion) && !empty($resultadoImportacion['detalles'])): ?>
    <div class="summary-box p-4 mb-4">
      <h5 class="mb-3">Resultado real de la importación</h5>
      <div class="table-responsive" style="max-height:360px; overflow:auto;">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead>
            <tr>
              <th>RUT</th>
              <th>Fecha/Hora</th>
              <th>Historial</th>
              <th>Contratista</th>
              <th>Motivo</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($resultadoImportacion['detalles'] as $detalleImport): ?>
            <?php
              $badgeHist = ($detalleImport['historial_estado'] ?? '') === 'IMPORTADO' ? 'success' : 'warning';
              $badgeCont = 'secondary';
              if (($detalleImport['contratista_estado'] ?? '') === 'CREADO') {
                  $badgeCont = 'success';
              } elseif (($detalleImport['contratista_estado'] ?? '') === 'NO_CREADO') {
                  $badgeCont = 'warning';
              } elseif (($detalleImport['contratista_estado'] ?? '') === 'YA_EXISTE') {
                  $badgeCont = 'primary';
              }
            ?>
            <tr>
              <td><?= esc((string)$detalleImport['rut']) ?></td>
              <td><?= esc((string)$detalleImport['fecha_hora']) ?></td>
              <td><span class="badge text-bg-<?= esc($badgeHist) ?>"><?= esc((string)$detalleImport['historial_estado']) ?></span></td>
              <td><span class="badge text-bg-<?= esc($badgeCont) ?>"><?= esc((string)$detalleImport['contratista_estado']) ?></span></td>
              <td><?= esc((string)$detalleImport['motivo']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
