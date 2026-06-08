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
const HISTORICO_SHEET_HISTORIA = 'HISTORIA DE EVALUACIONES';
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
            if (!is_array($analisis) || (empty($analisis['rows_validos']) && empty($analisis['historia_rows']))) {
                throw new RuntimeException('No hay un análisis válido disponible para importar.');
            }

            $resultado = importarHistoricoTeorico(
                $pdo,
                $analisis['rows_validos'],
                $analisis['normalizacion']['detalles'] ?? [],
                (int)$analisis['id_servicio'],
                $analisis['historia_rows'] ?? []
            );
            unset($_SESSION[HISTORICO_SESSION_KEY]);
            $analisis = null;
            $resultadoImportacion = $resultado;

            $mensaje = sprintf(
                'Carga finalizada. Importados: %d. Duplicados omitidos: %d. Contratistas creados: %d. Contratistas creados incompletos: %d. Procesos creados: %d. Procesos reutilizados: %d. Teóricas asociadas: %d. Terrenos asociados: %d. Intentos terreno asociados: %d. Conflictos de asociación: %d.',
                $resultado['importados'],
                $resultado['duplicados'],
                $resultado['contratistas_creados'],
                $resultado['contratistas_incompletos'],
                $resultado['procesos_creados'],
                $resultado['procesos_reutilizados'],
                $resultado['teoricas_asociadas'],
                $resultado['terrenos_asociados'],
                $resultado['terreno_intentos_asociados'],
                $resultado['conflictos_asociacion']
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
    $sheetHistoria = findSheetByName($spreadsheet, HISTORICO_SHEET_HISTORIA);

    if ($sheetAreas === null || $sheetPreguntas === null || $sheetDetalle === null || $sheetHistoria === null) {
        throw new RuntimeException('El archivo debe contener las hojas Areas, Preguntas, Detalle Respuestas e Historia de Evaluaciones.');
    }

    $areasInfo = analizarSheetAreas($sheetAreas);
    $preguntasInfo = analizarSheetPreguntas($sheetPreguntas, $idServicio);
    $detalleInfo = analizarSheetDetalle($pdo, $sheetDetalle, $idServicio);
    $historiaInfo = analizarSheetHistoriaEvaluaciones($pdo, $sheetHistoria, $idServicio, $detalleInfo['rows_validos']);

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
        'historia' => $historiaInfo['resumen'],
        'historia_detalles' => $historiaInfo['detalles_filas'],
        'historia_procesos' => $historiaInfo['procesos'],
        'historia_conflictos' => $historiaInfo['conflictos'],
        'normalizacion' => analizarNormalizacionContratistas($pdo, $detalleInfo['rows_normalizacion']),
        'rows_validos' => $detalleInfo['rows_validos'],
        'historia_rows' => $historiaInfo['rows_validos'],
    ];
}

function importarHistoricoTeorico(PDO $pdo, array $rowsValidos, array $normalizacionDetalles, int $idServicio, array $historiaRows = []): array
{
    $stmtExiste = $pdo->prepare(
        'SELECT 1 FROM ceo_resultado_prueba_intento WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha AND hora_rendicion = :hora LIMIT 1'
    );
    $stmtBuscarTeorica = $pdo->prepare(
        'SELECT id, rut, fecha_rendicion, hora_rendicion, id_proceso_habilitacion FROM ceo_resultado_prueba_intento WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha AND hora_rendicion = :hora ORDER BY id ASC LIMIT 1'
    );
    $stmtBuscarTeoricasPorFecha = $pdo->prepare(
        'SELECT id, rut, fecha_rendicion, hora_rendicion, id_proceso_habilitacion FROM ceo_resultado_prueba_intento WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha ORDER BY hora_rendicion ASC, id ASC'
    );
    $stmtAsociarTeoricasPorFecha = $pdo->prepare(
        'UPDATE ceo_resultado_prueba_intento SET id_proceso_habilitacion = :id_proceso_set WHERE rut = :rut AND id_servicio = :servicio AND fecha_rendicion = :fecha AND (id_proceso_habilitacion IS NULL OR id_proceso_habilitacion = :id_proceso_where)'
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
    $procesosCreados = 0;
    $procesosReutilizados = 0;
    $procesosOmitidos = 0;
    $teoricasAsociadas = 0;
    $terrenosAsociados = 0;
    $terrenoIntentosAsociados = 0;
    $conflictosAsociacion = 0;
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
                $stmtBuscarTeorica->execute([
                    ':rut' => $rut,
                    ':servicio' => $idServicio,
                    ':fecha' => $row['fecha_rendicion'],
                    ':hora' => $row['hora_rendicion'],
                ]);
                $teoricaExistente = $stmtBuscarTeorica->fetch(PDO::FETCH_ASSOC) ?: null;
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

        if (!empty($historiaRows)) {
                $terrenosPorClave = cargarEvaluacionesTerrenoPorClave($pdo, $idServicio);
                $terrenoIntentosPorClave = cargarResultadoTerrenoIntentoPorClave($pdo, $idServicio);
                $contratistasPorRut = cargarContratistasPorRut($pdo);
                $procesosHistoria = construirProcesosHistoria($historiaRows);
                $stmtNextProceso = $pdo->query('SELECT COALESCE(MAX(numero_proceso), 0) FROM ceo_proceso_habilitacion');
                $numeroProcesoActual = (int)$stmtNextProceso->fetchColumn();
                $stmtInsertProceso = $pdo->prepare(
                    'INSERT INTO ceo_proceso_habilitacion (rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio, fecha_cierre) VALUES (:rut, :id_servicio, :id_cargo, :numero_proceso, :estado, :origen, :fecha_inicio, :fecha_cierre)'
                );
                $stmtUpdateTeoricaProceso = $pdo->prepare('UPDATE ceo_resultado_prueba_intento SET id_proceso_habilitacion = :id_proceso WHERE id = :id');
                $stmtUpdateTerrenoProceso = $pdo->prepare('UPDATE ceo_evaluacion_terreno SET id_proceso_habilitacion = :id_proceso WHERE id = :id');
                $stmtUpdateTerrenoIntentoProceso = $pdo->prepare('UPDATE ceo_resultado_terreno_intento SET id_proceso_habilitacion = :id_proceso WHERE id = :id');
                $terrenosReservados = [];
                $terrenoIntentosReservados = [];
                $procesoIds = [];

                foreach ($procesosHistoria as $proceso) {
                    $rutProceso = (string)$proceso['rut'];
                    $idCargoProceso = (int)($contratistasPorRut[$rutProceso]['id_cargo'] ?? 0);
                    $fechaBaseProceso = $proceso['fecha_inicio'] ?? $proceso['fecha_cierre'];
                    $fechaInicioProceso = $fechaBaseProceso !== null
                        ? $fechaBaseProceso . ' 00:00:00'
                        : ($proceso['fecha_cierre'] !== null ? $proceso['fecha_cierre'] . ' 00:00:00' : date('Y-m-d H:i:s'));
                    $fechaCierreProceso = $proceso['estado'] === 'CERRADO' && $proceso['fecha_cierre'] !== null
                        ? $proceso['fecha_cierre'] . ' 00:00:00'
                        : null;

                    $numeroProcesoActual++;
                    $stmtInsertProceso->execute([
                        ':rut' => $rutProceso,
                        ':id_servicio' => $idServicio,
                        ':id_cargo' => $idCargoProceso > 0 ? $idCargoProceso : null,
                        ':numero_proceso' => $numeroProcesoActual,
                        ':estado' => $proceso['estado'],
                        ':origen' => 'HISTORICO_CYR',
                        ':fecha_inicio' => $fechaInicioProceso,
                        ':fecha_cierre' => $fechaCierreProceso,
                    ]);
                    $idProceso = (int)$pdo->lastInsertId();
                    $procesosCreados++;

                    $procesoIds[(string)$proceso['key']] = $idProceso;
                }

                foreach ($procesosHistoria as $proceso) {
                    $idProceso = (int)($procesoIds[(string)$proceso['key']] ?? 0);
                    if ($idProceso <= 0) {
                        $procesosOmitidos++;
                        $detalles[] = [
                            'rut' => $proceso['rut'],
                            'fecha_hora' => $proceso['fecha_inicio'] ?? '-',
                            'historial_estado' => 'PROCESO_OMITIDO',
                            'contratista_estado' => 'N/A',
                            'motivo' => 'No fue posible crear o resolver el proceso histórico N ' . $proceso['numero_historico'] . '.',
                        ];
                        continue;
                    }

                    $idsTeoria = [];
                    $idsTerreno = [];
                    $idsTerrenoIntento = [];
                    $motivos = [];
                    $trazasTeoria = [];
                    $fechasTeoriaAsociadas = [];

                    foreach ($proceso['rows'] as $rowProceso) {
                        if (empty($rowProceso['fecha_prueba'])) {
                            continue;
                        }
                        $teoriaKey = historicoClaveRutFecha((string)$rowProceso['rut'], (string)$rowProceso['fecha_prueba']);
                        $candidatosTeoria = cargarTeoricasPorRutYFecha(
                            $stmtBuscarTeoricasPorFecha,
                            (string)$rowProceso['rut'],
                            $idServicio,
                            (string)$rowProceso['fecha_prueba']
                        );
                        $trazasTeoria[] = [
                            'key' => $teoriaKey,
                            'candidatos' => array_map(static function (array $candidato): array {
                                return [
                                    'id' => (int)($candidato['id'] ?? 0),
                                    'fecha' => (string)($candidato['fecha_rendicion'] ?? ''),
                                    'hora' => (string)($candidato['hora_rendicion'] ?? ''),
                                    'id_proceso_habilitacion' => isset($candidato['id_proceso_habilitacion']) ? (string)$candidato['id_proceso_habilitacion'] : 'NULL',
                                ];
                            }, $candidatosTeoria),
                        ];
                        if (empty($candidatosTeoria)) {
                            $trazasTeoria[count($trazasTeoria) - 1]['seleccion'] = ['id' => 0, 'estado' => 'sin_candidato'];
                            $motivos[] = 'Sin match teórico para ' . $teoriaKey . '.';
                        } else {
                            $idsTeoria = array_merge($idsTeoria, array_map(static fn(array $c): int => (int)$c['id'], $candidatosTeoria));
                            $fechasTeoriaAsociadas[(string)$rowProceso['fecha_prueba']] = true;
                            $trazasTeoria[count($trazasTeoria) - 1]['seleccion'] = [
                                'id' => (int)($candidatosTeoria[0]['id'] ?? 0),
                                'estado' => count($candidatosTeoria) === 1 ? 'seleccionado_por_fecha' : 'seleccion_multiple_por_fecha',
                            ];
                            if (count($candidatosTeoria) > 1) {
                                $motivos[] = 'Se asociaron ' . count($candidatosTeoria) . ' teóricas para ' . $teoriaKey . ' por coincidencia de fecha.';
                            }
                        }
                    }

                    foreach ($proceso['terreno_keys'] as $terrenoKey) {
                        $seleccionTerreno = seleccionarRegistroParaProceso(
                            $terrenosPorClave[$terrenoKey] ?? [],
                            $idProceso,
                            $terrenosReservados,
                            (string)$proceso['key']
                        );
                        if ($seleccionTerreno['id'] > 0) {
                            $idsTerreno[] = $seleccionTerreno['id'];
                        } elseif ($seleccionTerreno['estado'] === 'bloqueado_otro_proceso') {
                            $conflictosAsociacion++;
                            $motivos[] = 'El terreno ' . $terrenoKey . ' ya pertenece a otro proceso.';
                        }

                        $seleccionIntento = seleccionarRegistroParaProceso(
                            $terrenoIntentosPorClave[$terrenoKey] ?? [],
                            $idProceso,
                            $terrenoIntentosReservados,
                            (string)$proceso['key']
                        );
                        if ($seleccionIntento['id'] > 0) {
                            $idsTerrenoIntento[] = $seleccionIntento['id'];
                        } elseif ($seleccionIntento['estado'] === 'bloqueado_otro_proceso') {
                            $conflictosAsociacion++;
                            $motivos[] = 'El intento terreno ' . $terrenoKey . ' ya pertenece a otro proceso.';
                        }
                    }

                    $idsTeoria = array_values(array_unique($idsTeoria));
                    $idsTerreno = array_values(array_unique($idsTerreno));
                    $idsTerrenoIntento = array_values(array_unique($idsTerrenoIntento));

                    if (empty($idsTeoria) && empty($idsTerreno) && empty($idsTerrenoIntento)) {
                        $procesosOmitidos++;
                        $detalles[] = [
                            'rut' => $proceso['rut'],
                            'fecha_hora' => $proceso['fecha_inicio'] ?? '-',
                            'historial_estado' => 'PROCESO_CREADO_SIN_ASOCIACION',
                            'contratista_estado' => 'N/A',
                            'motivo' => 'Proceso histórico N ' . $proceso['numero_historico'] . ' creado en CEONext, pero sin matches únicos para asociar teoría o terreno. ' . implode(' ', $motivos) . ' TRAZA_TEORIA=' . json_encode($trazasTeoria, JSON_UNESCAPED_UNICODE),
                        ];
                        continue;
                    }

                    foreach (array_keys($fechasTeoriaAsociadas) as $fechaTeoria) {
                        $stmtAsociarTeoricasPorFecha->execute([
                            ':id_proceso_set' => $idProceso,
                            ':id_proceso_where' => $idProceso,
                            ':rut' => (string)$proceso['rut'],
                            ':servicio' => $idServicio,
                            ':fecha' => $fechaTeoria,
                        ]);
                        $trazasTeoria[] = [
                            'update' => [
                                'id_proceso' => $idProceso,
                                'rut' => (string)$proceso['rut'],
                                'fecha' => $fechaTeoria,
                                'row_count' => $stmtAsociarTeoricasPorFecha->rowCount(),
                            ],
                        ];
                        $teoricasAsociadas += $stmtAsociarTeoricasPorFecha->rowCount();
                    }

                    foreach ($idsTerreno as $idTerreno) {
                        $stmtUpdateTerrenoProceso->execute([':id_proceso' => $idProceso, ':id' => $idTerreno]);
                        $terrenosAsociados++;
                    }

                    foreach ($idsTerrenoIntento as $idTerrenoIntento) {
                        $stmtUpdateTerrenoIntentoProceso->execute([':id_proceso' => $idProceso, ':id' => $idTerrenoIntento]);
                        $terrenoIntentosAsociados++;
                    }

                    $detalles[] = [
                        'rut' => $rutProceso,
                        'fecha_hora' => $proceso['fecha_inicio'] ?? '-',
                        'historial_estado' => 'PROCESO_ASOCIADO',
                        'contratista_estado' => 'N/A',
                        'motivo' => 'Proceso histórico N ' . $proceso['numero_historico'] . ' -> CEONext. Teóricas: ' . count($idsTeoria) . ', Terrenos: ' . count($idsTerreno) . ', Intentos terreno: ' . count($idsTerrenoIntento) . '. ' . implode(' ', $motivos) . ' TRAZA_TEORIA=' . json_encode($trazasTeoria, JSON_UNESCAPED_UNICODE),
                    ];
                }
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
        'procesos_creados' => $procesosCreados,
        'procesos_reutilizados' => $procesosReutilizados,
        'procesos_omitidos' => $procesosOmitidos,
        'teoricas_asociadas' => $teoricasAsociadas,
        'terrenos_asociados' => $terrenosAsociados,
        'terreno_intentos_asociados' => $terrenoIntentosAsociados,
        'conflictos_asociacion' => $conflictosAsociacion,
        'detalles' => $detalles,
    ];
}

function cargarTeoricasPorRutYFecha(PDOStatement $stmt, string $rut, int $idServicio, string $fecha): array
{
    $stmt->execute([
        ':rut' => $rut,
        ':servicio' => $idServicio,
        ':fecha' => $fecha,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function analizarSheetHistoriaEvaluaciones(PDO $pdo, $sheet, int $idServicio, array $rowsValidos): array
{
    $rows = $sheet->toArray(null, true, true, false);
    if (empty($rows)) {
        throw new RuntimeException('La hoja Historia de Evaluaciones está vacía.');
    }

    $headerMap = buildHeaderMap($rows[0]);
    $required = ['N', 'EVALUACION', 'RUT', 'FECHATERRENO', 'FECHAPRUEBA', 'ESTADO', 'SERVICIO'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $headerMap)) {
            throw new RuntimeException('La hoja Historia de Evaluaciones no contiene la columna requerida: ' . $key);
        }
    }

    $servicioNombre = obtenerNombreServicio($pdo, $idServicio);
    $teoricasDb = cargarResultadosTeoricosPorClave($pdo, $idServicio);
    $terrenosDb = cargarEvaluacionesTerrenoPorClave($pdo, $idServicio);
    $terrenoIntentosDb = cargarResultadoTerrenoIntentoPorClave($pdo, $idServicio);
    $pendientesPorClave = [];
    foreach ($rowsValidos as $rowValido) {
        $key = historicoClaveRutFecha((string)$rowValido['rut'], (string)$rowValido['fecha_rendicion']);
        $pendientesPorClave[$key] = ($pendientesPorClave[$key] ?? 0) + 1;
    }

    $errores = [];
    $detallesFilas = [];
    $rowsHistoria = [];
    $totalFilas = 0;

    foreach (array_slice($rows, 1) as $linea => $row) {
        $numeroProceso = parseNullableInt(cellByHeader($row, $headerMap, 'N'));
        $rutRaw = trim((string)cellByHeader($row, $headerMap, 'RUT'));
        $servicioExcel = trim((string)cellByHeader($row, $headerMap, 'SERVICIO'));
        $estado = strtoupper(trim((string)cellByHeader($row, $headerMap, 'ESTADO')));

        if ($numeroProceso === null && $rutRaw === '' && $estado === '' && $servicioExcel === '') {
            continue;
        }

        $totalFilas++;
        $rut = normalizarRutHistorico($rutRaw);
        $fechaPrueba = parseExcelDateTimeValue(cellByHeader($row, $headerMap, 'FECHAPRUEBA'));
        $fechaTerreno = parseExcelDateTimeValue(cellByHeader($row, $headerMap, 'FECHATERRENO'));
        $fechaEvaluacion = parseExcelDateTimeValue(cellByHeader($row, $headerMap, 'FECHAEVALUACION'));
        $estadoValido = in_array($estado, ['SI', 'PENDIENTE', 'ESPERA', 'NO'], true);

        $motivos = [];
        if ($numeroProceso === null || $numeroProceso <= 0) {
            $motivos[] = 'N inválido.';
        }
        if (!validarRutHistorico($rut)) {
            $motivos[] = 'RUT inválido.';
        }
        if (!$estadoValido) {
            $motivos[] = 'Estado no reconocido (' . $estado . ').';
        }
        if ($servicioExcel !== '' && normalizeHeaderKey($servicioExcel) !== normalizeHeaderKey($servicioNombre)) {
            $motivos[] = 'Servicio distinto al seleccionado (' . $servicioExcel . ').';
        }

        $fechaPruebaStr = $fechaPrueba?->format('Y-m-d');
        $fechaTerrenoStr = $fechaTerreno?->format('Y-m-d');
        $fechaEvaluacionStr = $fechaEvaluacion?->format('Y-m-d');
        $teoriaKey = ($rut !== '' && $fechaPruebaStr !== null) ? historicoClaveRutFecha($rut, $fechaPruebaStr) : null;
        $terrenoKey = ($rut !== '' && $fechaTerrenoStr !== null) ? historicoClaveRutFecha($rut, $fechaTerrenoStr) : null;

        $teoriaCount = $teoriaKey !== null ? count($teoricasDb[$teoriaKey] ?? []) + (int)($pendientesPorClave[$teoriaKey] ?? 0) : 0;
        $terrenoCount = $terrenoKey !== null ? count($terrenosDb[$terrenoKey] ?? []) : 0;
        $terrenoIntentoCount = $terrenoKey !== null ? count($terrenoIntentosDb[$terrenoKey] ?? []) : 0;

        if (!empty($motivos)) {
            $errores[] = 'Fila ' . ($linea + 2) . ': ' . implode(' ', $motivos);
            $detallesFilas[] = [
                'fila' => $linea + 2,
                'rut' => $rutRaw,
                'numero_proceso' => $numeroProceso,
                'fecha_prueba' => $fechaPruebaStr ?? '',
                'fecha_terreno' => $fechaTerrenoStr ?? '',
                'estado' => 'ERROR',
                'teorica' => '-',
                'terreno' => '-',
                'motivo' => implode(' ', $motivos),
            ];
            continue;
        }

        $rowsHistoria[] = [
            'numero_proceso_historico' => $numeroProceso,
            'evaluacion' => parseNullableInt(cellByHeader($row, $headerMap, 'EVALUACION')) ?? 0,
            'rut' => $rut,
            'cargo' => trim((string)cellByHeader($row, $headerMap, 'CARGO')),
            'fecha_prueba' => $fechaPruebaStr,
            'fecha_terreno' => $fechaTerrenoStr,
            'fecha_evaluacion' => $fechaEvaluacionStr,
            'nota_terreno' => parseNullableFloat(cellByHeader($row, $headerMap, 'TERRENO')),
            'nota_prueba' => parseNullableFloat(cellByHeader($row, $headerMap, 'PRUEBA')),
            'nota_final' => parseNullableFloat(cellByHeader($row, $headerMap, 'NOTAFINAL')),
            'estado' => $estado,
        ];

        $detallesFilas[] = [
            'fila' => $linea + 2,
            'rut' => $rut,
            'numero_proceso' => $numeroProceso,
            'fecha_prueba' => $fechaPruebaStr ?? '',
            'fecha_terreno' => $fechaTerrenoStr ?? '',
            'estado' => 'VALIDA',
            'teorica' => describirMatchHistorico($teoriaCount, $teoriaKey !== null),
            'terreno' => describirMatchTerrenoHistorico($terrenoCount, $terrenoIntentoCount, $terrenoKey !== null),
            'motivo' => 'Fila histórica válida para crear/asociar procesos.',
        ];
    }

    $procesos = construirProcesosHistoria($rowsHistoria);
    $conflictos = [];
    $teoriaProcesoConflictos = detectarConflictosDeProcesoPorClave($procesos, 'teoria_keys');
    foreach ($procesos as &$proceso) {
        $teoricasUnicas = 0;
        $teoricasSinMatch = 0;
        $teoricasConflicto = 0;
        foreach ($proceso['teoria_keys'] as $teoriaKey) {
            $count = count($teoricasDb[$teoriaKey] ?? []) + (int)($pendientesPorClave[$teoriaKey] ?? 0);
            if (!empty($teoriaProcesoConflictos[$teoriaKey])) {
                $teoricasConflicto++;
                $conflictos[] = 'La teórica ' . $teoriaKey . ' aparece en más de un proceso histórico.';
            } elseif ($count === 1) {
                $teoricasUnicas++;
            } elseif ($count === 0) {
                $teoricasSinMatch++;
            } else {
                $teoricasConflicto++;
                $conflictos[] = 'La teórica ' . $teoriaKey . ' tiene más de un match en CEONext.';
            }
        }

        $terrenosUnicos = 0;
        $terrenoIntentosUnicos = 0;
        foreach ($proceso['terreno_keys'] as $terrenoKey) {
            if (count($terrenosDb[$terrenoKey] ?? []) === 1) {
                $terrenosUnicos++;
            }
            if (count($terrenoIntentosDb[$terrenoKey] ?? []) === 1) {
                $terrenoIntentosUnicos++;
            }
        }

        $proceso['teoricas_unicas'] = $teoricasUnicas;
        $proceso['teoricas_sin_match'] = $teoricasSinMatch;
        $proceso['teoricas_conflicto'] = $teoricasConflicto;
        $proceso['terrenos_unicos'] = $terrenosUnicos;
        $proceso['terreno_intentos_unicos'] = $terrenoIntentosUnicos;
        $proceso['creable'] = ($teoricasUnicas + $terrenosUnicos + $terrenoIntentosUnicos) > 0;
    }
    unset($proceso);

    return [
        'rows_validos' => $rowsHistoria,
        'detalles_filas' => $detallesFilas,
        'procesos' => array_values($procesos),
        'conflictos' => array_values(array_unique($conflictos)),
        'resumen' => [
            'total_filas' => $totalFilas,
            'validas' => count($rowsHistoria),
            'errores' => $errores,
            'procesos_detectados' => count($procesos),
            'procesos_creables' => count(array_filter($procesos, static fn(array $p): bool => !empty($p['creable']))),
            'conflictos' => count(array_unique($conflictos)),
        ],
    ];
}

function construirProcesosHistoria(array $rowsHistoria): array
{
    $procesos = [];
    foreach ($rowsHistoria as $row) {
        $processKey = (string)$row['rut'] . '|' . (int)$row['numero_proceso_historico'];
        if (!isset($procesos[$processKey])) {
            $procesos[$processKey] = [
                'key' => $processKey,
                'rut' => (string)$row['rut'],
                'numero_historico' => (int)$row['numero_proceso_historico'],
                'estado' => estadoProcesoHistoria((string)$row['estado']),
                'fecha_inicio' => null,
                'fecha_cierre' => null,
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'rows' => [],
                'teoria_keys' => [],
                'terreno_keys' => [],
            ];
        }

        $procesos[$processKey]['rows'][] = $row;
        foreach (['fecha_terreno', 'fecha_prueba', 'fecha_evaluacion'] as $campoFecha) {
            if (!empty($row[$campoFecha])) {
                if ($procesos[$processKey]['fecha_inicio'] === null || (string)$row[$campoFecha] < $procesos[$processKey]['fecha_inicio']) {
                    $procesos[$processKey]['fecha_inicio'] = (string)$row[$campoFecha];
                }
                if ($procesos[$processKey]['fecha_cierre'] === null || (string)$row[$campoFecha] > $procesos[$processKey]['fecha_cierre']) {
                    $procesos[$processKey]['fecha_cierre'] = (string)$row[$campoFecha];
                }
            }
        }
        if (!empty($row['cargo'])) {
            $procesos[$processKey]['cargo'] = (string)$row['cargo'];
        }
        if (!empty($row['fecha_prueba'])) {
            $procesos[$processKey]['teoria_keys'][historicoClaveRutFecha((string)$row['rut'], (string)$row['fecha_prueba'])] = true;
        }
        if (!empty($row['fecha_terreno'])) {
            $procesos[$processKey]['terreno_keys'][historicoClaveRutFecha((string)$row['rut'], (string)$row['fecha_terreno'])] = true;
        }
    }

    foreach ($procesos as &$proceso) {
        usort($proceso['rows'], static function (array $a, array $b): int {
            $fechaA = max((string)($a['fecha_evaluacion'] ?? ''), (string)($a['fecha_prueba'] ?? ''), (string)($a['fecha_terreno'] ?? ''));
            $fechaB = max((string)($b['fecha_evaluacion'] ?? ''), (string)($b['fecha_prueba'] ?? ''), (string)($b['fecha_terreno'] ?? ''));
            return [$fechaA, (int)($a['evaluacion'] ?? 0)] <=> [$fechaB, (int)($b['evaluacion'] ?? 0)];
        });
        $ultima = end($proceso['rows']);
        $proceso['estado'] = estadoProcesoHistoria((string)($ultima['estado'] ?? ''));
        if ($proceso['estado'] !== 'CERRADO') {
            $proceso['fecha_cierre'] = null;
        }
        $proceso['teoria_keys'] = array_keys($proceso['teoria_keys']);
        $proceso['terreno_keys'] = array_keys($proceso['terreno_keys']);
    }
    unset($proceso);

    return $procesos;
}

function estadoProcesoHistoria(string $estado): string
{
    return strtoupper(trim($estado)) === 'SI' ? 'CERRADO' : 'ABIERTO';
}

function historicoClaveRutFecha(string $rut, string $fecha): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', $rut)) . '|' . $fecha;
}

function describirMatchHistorico(int $count, bool $hayFecha): string
{
    if (!$hayFecha) {
        return 'SIN FECHA';
    }
    if ($count === 0) {
        return 'SIN MATCH';
    }
    if ($count === 1) {
        return 'MATCH UNICO';
    }
    return 'MATCH MULTIPLE';
}

function describirMatchTerrenoHistorico(int $cabeceraCount, int $intentoCount, bool $hayFecha): string
{
    if (!$hayFecha) {
        return 'SIN FECHA';
    }
    if ($cabeceraCount === 1 || $intentoCount === 1) {
        return 'MATCH PARCIAL/UNICO';
    }
    if ($cabeceraCount === 0 && $intentoCount === 0) {
        return 'SIN MATCH';
    }
    return 'MATCH MULTIPLE';
}

function detectarConflictosDeProcesoPorClave(array $procesos, string $campoClaves): array
{
    $usos = [];
    foreach ($procesos as $proceso) {
        foreach (($proceso[$campoClaves] ?? []) as $key) {
            $usos[$key][(string)$proceso['key']] = true;
        }
    }

    $conflictos = [];
    foreach ($usos as $key => $procesosKey) {
        if (count($procesosKey) > 1) {
            $conflictos[$key] = array_keys($procesosKey);
        }
    }

    return $conflictos;
}

function seleccionarRegistroParaProceso(array $candidatos, int $idProceso, array &$reservas, string $procesoKey): array
{
    if (empty($candidatos)) {
        return ['id' => 0, 'estado' => 'sin_candidato'];
    }

    usort($candidatos, static function (array $a, array $b): int {
        return [(int)($a['id'] ?? 0)] <=> [(int)($b['id'] ?? 0)];
    });

    foreach ($candidatos as $candidato) {
        $id = (int)($candidato['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ((int)($candidato['id_proceso_habilitacion'] ?? 0) === $idProceso) {
            $reservas[$id] = $procesoKey;
            return ['id' => $id, 'estado' => 'ya_asociado_mismo_proceso'];
        }
    }

    foreach ($candidatos as $candidato) {
        $id = (int)($candidato['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $idProcesoActual = (int)($candidato['id_proceso_habilitacion'] ?? 0);
        if ($idProcesoActual > 0 && $idProcesoActual !== $idProceso) {
            continue;
        }
        if (!isset($reservas[$id])) {
            $reservas[$id] = $procesoKey;
            return ['id' => $id, 'estado' => 'seleccionado'];
        }
        if ($reservas[$id] === $procesoKey) {
            return ['id' => $id, 'estado' => 'ya_reservado_mismo_proceso'];
        }
    }

    foreach ($candidatos as $candidato) {
        $id = (int)($candidato['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $idProcesoActual = (int)($candidato['id_proceso_habilitacion'] ?? 0);
        if ($idProcesoActual > 0 && $idProcesoActual !== $idProceso) {
            continue;
        }
        if (isset($reservas[$id])) {
            if ($reservas[$id] === $procesoKey) {
                return ['id' => $id, 'estado' => 'ya_reservado_mismo_proceso'];
            }
            return ['id' => $id, 'estado' => 'reservado_otro_proceso'];
        }
    }

    return ['id' => 0, 'estado' => 'bloqueado_otro_proceso'];
}

function cargarResultadosTeoricosPorClave(PDO $pdo, int $idServicio): array
{
    $stmt = $pdo->prepare('SELECT id, rut, fecha_rendicion, hora_rendicion, id_proceso_habilitacion FROM ceo_resultado_prueba_intento WHERE id_servicio = :id_servicio');
    $stmt->execute([':id_servicio' => $idServicio]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[historicoClaveRutFecha((string)$row['rut'], (string)$row['fecha_rendicion'])][] = $row;
    }
    return $map;
}

function cargarEvaluacionesTerrenoPorClave(PDO $pdo, int $idServicio): array
{
    $stmt = $pdo->prepare('SELECT id, rut, DATE(fecha_evaluacion) AS fecha_evaluacion, id_proceso_habilitacion FROM ceo_evaluacion_terreno WHERE id_servicio = :id_servicio');
    $stmt->execute([':id_servicio' => $idServicio]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[historicoClaveRutFecha((string)$row['rut'], (string)$row['fecha_evaluacion'])][] = $row;
    }
    return $map;
}

function cargarResultadoTerrenoIntentoPorClave(PDO $pdo, int $idServicio): array
{
    $stmt = $pdo->prepare('SELECT id, rut, fecha_rendicion, id_proceso_habilitacion FROM ceo_resultado_terreno_intento WHERE id_servicio = :id_servicio');
    $stmt->execute([':id_servicio' => $idServicio]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[historicoClaveRutFecha((string)$row['rut'], (string)$row['fecha_rendicion'])][] = $row;
    }
    return $map;
}

function cargarContratistasPorRut(PDO $pdo): array
{
    $rows = $pdo->query('SELECT rut, id_cargo FROM ceo_contratistas')->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['rut']] = $row;
    }
    return $map;
}

function buscarRegistroPorId(array $map, int $id): ?array
{
    foreach ($map as $rows) {
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
    }
    return null;
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
          <button class="btn btn-success" type="submit" <?= (empty($analisis['rows_validos']) && empty($analisis['historia']['procesos_creables'])) ? 'disabled' : '' ?>><i class="bi bi-database-add me-1"></i>Confirmar carga</button>
        </form>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Áreas detectadas</div><div class="fs-4 fw-bold"><?= (int)$analisis['areas']['registros'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Preguntas históricas</div><div class="fs-4 fw-bold"><?= (int)$analisis['preguntas']['preguntas_unicas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Filas válidas</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['detalle']['validas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Duplicadas</div><div class="fs-4 fw-bold text-secondary"><?= (int)$analisis['detalle']['duplicadas'] ?></div></div></div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Historia válida</div><div class="fs-4 fw-bold text-primary"><?= (int)$analisis['historia']['validas'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Procesos detectados</div><div class="fs-4 fw-bold"><?= (int)$analisis['historia']['procesos_detectados'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Procesos creables</div><div class="fs-4 fw-bold text-success"><?= (int)$analisis['historia']['procesos_creables'] ?></div></div></div>
        <div class="col-md-3"><div class="border rounded p-3 bg-light"><div class="small text-muted">Conflictos historia</div><div class="fs-4 fw-bold text-warning"><?= (int)$analisis['historia']['conflictos'] ?></div></div></div>
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
            <li>Historia de Evaluaciones válidas: <?= (int)$analisis['historia']['validas'] ?> de <?= (int)$analisis['historia']['total_filas'] ?></li>
          </ul>
        </div>
        <div class="col-lg-6">
          <h6 class="text-primary">Errores detectados</h6>
          <?php $errores = array_merge($analisis['preguntas']['errores'], $analisis['detalle']['errores'], $analisis['historia']['errores']); ?>
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
          <h6 class="text-primary">Procesos históricos detectados</h6>
          <?php if (empty($analisis['historia_procesos'])): ?>
            <div class="text-muted">No hay procesos históricos válidos.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:320px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Proceso Hist.</th>
                    <th>RUT</th>
                    <th>Estado</th>
                    <th>Inicio</th>
                    <th>Cierre</th>
                    <th>Teóricas Únicas</th>
                    <th>Teóricas Conflicto</th>
                    <th>Terrenos Únicos</th>
                    <th>Intentos Terreno</th>
                    <th>Creable</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($analisis['historia_procesos'] as $procesoHist): ?>
                  <tr>
                    <td><?= (int)$procesoHist['numero_historico'] ?></td>
                    <td><?= esc((string)$procesoHist['rut']) ?></td>
                    <td><?= esc((string)$procesoHist['estado']) ?></td>
                    <td><?= esc((string)($procesoHist['fecha_inicio'] ?? '')) ?></td>
                    <td><?= esc((string)($procesoHist['fecha_cierre'] ?? '')) ?></td>
                    <td><?= (int)($procesoHist['teoricas_unicas'] ?? 0) ?></td>
                    <td><?= (int)($procesoHist['teoricas_conflicto'] ?? 0) ?></td>
                    <td><?= (int)($procesoHist['terrenos_unicos'] ?? 0) ?></td>
                    <td><?= (int)($procesoHist['terreno_intentos_unicos'] ?? 0) ?></td>
                    <td><span class="badge text-bg-<?= !empty($procesoHist['creable']) ? 'success' : 'secondary' ?>"><?= !empty($procesoHist['creable']) ? 'SI' : 'NO' ?></span></td>
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
          <h6 class="text-primary">Conflictos de historia</h6>
          <?php if (empty($analisis['historia_conflictos'])): ?>
            <div class="text-success">No se detectaron conflictos de reutilización.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:220px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead><tr><th>Conflicto</th></tr></thead>
                <tbody>
                <?php foreach ($analisis['historia_conflictos'] as $conflicto): ?>
                  <tr><td><?= esc((string)$conflicto) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-4 mt-1">
        <div class="col-12">
          <h6 class="text-primary">Estado por fila de Historia de Evaluaciones</h6>
          <?php if (empty($analisis['historia_detalles'])): ?>
            <div class="text-muted">No hay filas históricas para mostrar.</div>
          <?php else: ?>
            <div class="table-responsive" style="max-height:320px; overflow:auto;">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead>
                  <tr>
                    <th>Fila</th>
                    <th>Proceso Hist.</th>
                    <th>RUT</th>
                    <th>Fecha Prueba</th>
                    <th>Fecha Terreno</th>
                    <th>Estado</th>
                    <th>Teórica</th>
                    <th>Terreno</th>
                    <th>Motivo</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($analisis['historia_detalles'] as $detalleHistoria): ?>
                  <tr>
                    <td><?= esc((string)$detalleHistoria['fila']) ?></td>
                    <td><?= esc((string)$detalleHistoria['numero_proceso']) ?></td>
                    <td><?= esc((string)$detalleHistoria['rut']) ?></td>
                    <td><?= esc((string)$detalleHistoria['fecha_prueba']) ?></td>
                    <td><?= esc((string)$detalleHistoria['fecha_terreno']) ?></td>
                    <td><?= esc((string)$detalleHistoria['estado']) ?></td>
                    <td><?= esc((string)$detalleHistoria['teorica']) ?></td>
                    <td><?= esc((string)$detalleHistoria['terreno']) ?></td>
                    <td><?= esc((string)$detalleHistoria['motivo']) ?></td>
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
