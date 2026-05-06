<?php
// /app/functions.php
// ----------------------------------------------------------
// Funciones utilitarias globales para el sistema CEO
// ----------------------------------------------------------

/**
 * Escapa valores para salida HTML, tolerando cualquier tipo.
 * Previene errores de tipo y vulnerabilidades XSS.
 */
 
function debug($label, $data) {
    if (!defined('APP_DEBUG') || APP_DEBUG !== true) return;

    echo "<pre style='background:#111;color:#0f0;padding:10px;border-radius:6px;
                margin:10px 0;font-size:14px;'>";
    echo "<strong>$label</strong>\n";
    print_r($data);
    echo "</pre>";
}

function esc($value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('auditLog')) {
    function auditLog(
        string $accion,
        string $entidad = '',
        ?int $entidadId = null,
        array $detalle = [],
        array $usuario = []
    ): void {
        if (!function_exists('db')) {
            return;
        }

        $sessionUser = $_SESSION['auth'] ?? [];

        $usuarioId = $usuario['id'] ?? $sessionUser['id'] ?? null;
        $usuarioCodigo = $usuario['codigo'] ?? $sessionUser['codigo'] ?? '';
        $usuarioNombre = $usuario['nombre'] ?? $sessionUser['nombre'] ?? '';
        $usuarioRol = $usuario['rol'] ?? $sessionUser['rol'] ?? '';

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
        $url = $_SERVER['REQUEST_URI'] ?? '';

        $detalleJson = '{}';
        if (!empty($detalle)) {
            $encoded = json_encode($detalle, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $detalleJson = $encoded;
            }
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                INSERT INTO ceo_auditoria
                    (usuario_id, usuario_codigo, usuario_nombre, usuario_rol,
                     accion, entidad, entidad_id, detalle, ip, user_agent, metodo, url, created_at)
                VALUES
                    (:usuario_id, :usuario_codigo, :usuario_nombre, :usuario_rol,
                     :accion, :entidad, :entidad_id, :detalle, :ip, :user_agent, :metodo, :url, NOW())
            ");
            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':usuario_codigo' => $usuarioCodigo,
                ':usuario_nombre' => $usuarioNombre,
                ':usuario_rol' => $usuarioRol,
                ':accion' => $accion,
                ':entidad' => $entidad,
                ':entidad_id' => $entidadId,
                ':detalle' => $detalleJson,
                ':ip' => $ip,
                ':user_agent' => $userAgent,
                ':metodo' => $metodo,
                ':url' => $url
            ]);
        } catch (Throwable $e) {
            return;
        }
    }
}

/**
 * Redirige a una ruta dentro del proyecto.
 */
function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

/**
 * Convierte fecha MySQL (YYYY-MM-DD) a formato legible (DD/MM/YYYY)
 */
function formatDate(?string $fecha): string {
    if (!$fecha) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : $fecha;
}



/* ===========================================================
   CALCULO NOTA NORMALIZADA 1 A 7
   =========================================================== */
if (!function_exists('calcularNotaFinalDesdePorcentaje')) {
    function calcularNotaFinalDesdePorcentaje(float $porcentaje, float $porcentajeMinimo): float
    {
        if ($porcentaje < 0) {
            $porcentaje = 0;
        }
        if ($porcentaje > 100) {
            $porcentaje = 100;
        }

        if ($porcentajeMinimo <= 0 || $porcentajeMinimo >= 100) {
            throw new InvalidArgumentException('El porcentaje mínimo debe ser mayor que 0 y menor que 100.');
        }

        if ($porcentaje <= $porcentajeMinimo) {
            $nota = 1 + (($porcentaje / $porcentajeMinimo) * 3);
        } else {
            $nota = 4 + ((($porcentaje - $porcentajeMinimo) / (100 - $porcentajeMinimo)) * 3);
        }

        return round($nota, 2);
    }
}

/* ===========================================================
   PROCESO DE HABILITACION
   =========================================================== */
if (!function_exists('obtenerProcesoHabilitacionAbierto')) {
    function obtenerProcesoHabilitacionAbierto(\PDO $pdo, string $rut, int $idServicio, ?int $idCargo = null): ?array
    {
        $whereCargo = '';
        $params = [
            ':rut' => $rut,
            ':id_servicio' => $idServicio,
        ];

        if ($idCargo !== null && $idCargo > 0) {
            $whereCargo = ' AND id_cargo = :id_cargo';
            $params[':id_cargo'] = $idCargo;
        }

        $sql = "
            SELECT id, rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio, fecha_cierre
            FROM ceo_proceso_habilitacion
            WHERE rut = :rut
              AND id_servicio = :id_servicio
              {$whereCargo}
              AND estado = 'ABIERTO'
            ORDER BY numero_proceso DESC, id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('obtenerProcesoHabilitacionPorId')) {
    function obtenerProcesoHabilitacionPorId(\PDO $pdo, int $idProcesoHabilitacion): ?array
    {
        if ($idProcesoHabilitacion <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('
            SELECT id, rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio, fecha_cierre
            FROM ceo_proceso_habilitacion
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $idProcesoHabilitacion]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('obtenerOCrearProcesoHabilitacion')) {
    function obtenerOCrearProcesoHabilitacion(\PDO $pdo, string $rut, int $idServicio, string $origen = 'CEONEXT', ?int $idCargo = null): array
    {
        $abierto = obtenerProcesoHabilitacionAbierto($pdo, $rut, $idServicio, $idCargo);
        if ($abierto !== null) {
            return $abierto;
        }

        $stmtNext = $pdo->prepare('
            SELECT COALESCE(MAX(numero_proceso), 0) + 1
            FROM ceo_proceso_habilitacion
        ');
        $stmtNext->execute();
        $numeroProceso = (int)$stmtNext->fetchColumn();
        if ($numeroProceso <= 0) {
            $numeroProceso = 1;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO ceo_proceso_habilitacion
                (rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio)
            VALUES
                (:rut, :id_servicio, :id_cargo, :numero_proceso, 'ABIERTO', :origen, NOW())
        ");
        $stmtIns->execute([
            ':rut' => $rut,
            ':id_servicio' => $idServicio,
            ':id_cargo' => ($idCargo !== null && $idCargo > 0) ? $idCargo : null,
            ':numero_proceso' => $numeroProceso,
            ':origen' => $origen,
        ]);

        $nuevo = obtenerProcesoHabilitacionPorId($pdo, (int)$pdo->lastInsertId());
        if ($nuevo === null) {
            throw new RuntimeException('No fue posible crear el proceso de habilitación.');
        }

        return $nuevo;
    }
}

if (!function_exists('resolverProcesoHabilitacionParaProgramacion')) {
    function resolverProcesoHabilitacionParaProgramacion(\PDO $pdo, string $rut, int $idServicio, int $idCargo): ?array
    {
        $seleccionado = (int)($_SESSION['proceso_habilitacion_seleccionado'][$rut][$idServicio][$idCargo] ?? 0);
        if ($seleccionado > 0) {
            $stmt = $pdo->prepare('
                SELECT id, rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio, fecha_cierre
                FROM ceo_proceso_habilitacion
                WHERE id = :id
                  AND rut = :rut
                  AND id_servicio = :id_servicio
                  AND id_cargo = :id_cargo
                  AND estado = "ABIERTO"
                LIMIT 1
            ');
            $stmt->execute([
                ':id' => $seleccionado,
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':id_cargo' => $idCargo,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
            unset($_SESSION['proceso_habilitacion_seleccionado'][$rut][$idServicio][$idCargo]);
        }

        return obtenerProcesoHabilitacionAbierto($pdo, $rut, $idServicio, $idCargo);
    }
}

if (!function_exists('cerrarProcesoHabilitacion')) {
    function cerrarProcesoHabilitacion(\PDO $pdo, int $idProcesoHabilitacion): void
    {
        if ($idProcesoHabilitacion <= 0) {
            return;
        }

        $stmt = $pdo->prepare(""
            . "UPDATE ceo_proceso_habilitacion "
            . "SET estado = 'CERRADO', fecha_cierre = COALESCE(fecha_cierre, NOW()) "
            . "WHERE id = :id AND estado = 'ABIERTO'"
        );
        $stmt->execute([':id' => $idProcesoHabilitacion]);
    }
}

if (!function_exists('anularProcesoHabilitacion')) {
    function anularProcesoHabilitacion(\PDO $pdo, int $idProcesoHabilitacion): void
    {
        if ($idProcesoHabilitacion <= 0) {
            return;
        }

        $stmt = $pdo->prepare(""
            . "UPDATE ceo_proceso_habilitacion "
            . "SET estado = 'ANULADO', fecha_cierre = COALESCE(fecha_cierre, NOW()) "
            . "WHERE id = :id AND estado = 'ABIERTO'"
        );
        $stmt->execute([':id' => $idProcesoHabilitacion]);
    }
}

/* ===========================================================
   VALIDAR VIGENCIA GENERAL ACTIVA
   =========================================================== */
if (!function_exists('existeVigenciaGeneralActiva')) {
    function existeVigenciaGeneralActiva(\PDO $db, string $rut, int $idProceso): bool
    {
        $sql = "
            SELECT 1
            FROM ceo_vigencia_general
            WHERE rut = :rut
              AND id_proceso = :id_proceso
              AND CURDATE() BETWEEN fechavig_ini AND fechavig_fin
            LIMIT 1
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':rut'        => $rut,
            ':id_proceso' => $idProceso
        ]);

        return (bool)$st->fetchColumn();
    }
}

/* ===========================================================
   RECALCULAR VIGENCIA GENERAL
   - Usa el último intento por servicio+tipo
   - Requiere que todas las evaluaciones estén aprobadas
   - Usa el solape de vigencia_detalle
   =========================================================== */
if (!function_exists('recalcularVigenciaGeneral')) {
    function recalcularVigenciaGeneral(\PDO $db, string $rut, int $procesoCuadrilla): void
    {
        if (existeVigenciaGeneralActiva($db, $rut, $procesoCuadrilla)) {
            return;
        }

        $sqlTot = "
            SELECT
                COUNT(*) AS total,
                SUM(
                    CASE
                        WHEN ep.resultado = 'APROBADO' AND ep.estado = 'EJECUTADA' THEN 1
                        ELSE 0
                    END
                ) AS aprobadas
            FROM ceo_evaluaciones_programadas ep
            INNER JOIN (
                SELECT
                    rut,
                    cuadrilla,
                    id_servicio,
                    tipo,
                    MAX(intento) AS max_intento
                FROM ceo_evaluaciones_programadas
                WHERE rut = :rut
                  AND cuadrilla = :cuadrilla
                  AND tipo IN ('PRUEBA','TERRENO')
                GROUP BY rut, cuadrilla, id_servicio, tipo
            ) ult
                ON ult.rut = ep.rut
               AND ult.cuadrilla = ep.cuadrilla
               AND ult.id_servicio = ep.id_servicio
               AND ult.tipo = ep.tipo
               AND ult.max_intento = ep.intento
        ";

        $stTot = $db->prepare($sqlTot);
        $stTot->execute([
            ':rut'       => $rut,
            ':cuadrilla' => $procesoCuadrilla
        ]);

        $r = $stTot->fetch(\PDO::FETCH_ASSOC);

        $total     = (int)($r['total'] ?? 0);
        $aprobadas = (int)($r['aprobadas'] ?? 0);

        if ($total <= 0 || $aprobadas !== $total) {
            return;
        }

        $sqlReq = "
            SELECT COUNT(*) AS total_requeridas
            FROM (
                SELECT
                    id_servicio,
                    tipo,
                    MAX(intento) AS max_intento
                FROM ceo_evaluaciones_programadas
                WHERE rut = :rut
                  AND cuadrilla = :cuadrilla
                  AND tipo IN ('PRUEBA','TERRENO')
                GROUP BY id_servicio, tipo
            ) t
        ";

        $stReq = $db->prepare($sqlReq);
        $stReq->execute([
            ':rut'       => $rut,
            ':cuadrilla' => $procesoCuadrilla
        ]);

        $requeridas = (int)$stReq->fetchColumn();

        if ($requeridas <= 0) {
            return;
        }

        $sqlDet = "
            SELECT
                COUNT(*) AS cnt,
                MAX(fechavig_ini) AS hab_ini,
                MIN(fechavig_fin) AS hab_fin
            FROM ceo_vigencia_detalle
            WHERE rut = :rut
              AND id_proceso = :proceso
        ";

        $stDet = $db->prepare($sqlDet);
        $stDet->execute([
            ':rut'     => $rut,
            ':proceso' => $procesoCuadrilla
        ]);

        $det = $stDet->fetch(\PDO::FETCH_ASSOC);

        $cnt    = (int)($det['cnt'] ?? 0);
        $habIni = $det['hab_ini'] ?? null;
        $habFin = $det['hab_fin'] ?? null;

        if ($cnt !== $requeridas || !$habIni || !$habFin) {
            return;
        }

        if (strtotime((string)$habIni) > strtotime((string)$habFin)) {
            return;
        }

        $sqlUp = "
            INSERT INTO ceo_vigencia_general
            (rut, fechavig_ini, fechavig_fin, id_proceso)
            VALUES
            (:rut, :ini, :fin, :proceso)
            ON DUPLICATE KEY UPDATE
                fechavig_ini = VALUES(fechavig_ini),
                fechavig_fin = VALUES(fechavig_fin)
        ";

        $stUp = $db->prepare($sqlUp);
        $stUp->execute([
            ':rut'     => $rut,
            ':ini'     => $habIni,
            ':fin'     => $habFin,
            ':proceso' => $procesoCuadrilla
        ]);
    }
}

/* ===========================================================
   OBTENER CARGO DEL TRABAJADOR
   =========================================================== */
if (!function_exists('obtenerCargoTrabajador')) {
    function obtenerCargoTrabajador(\PDO $pdo, string $rut, ?int $idServicio = null, ?int $idProceso = null): ?int
    {
        $rutKey = strtoupper(str_replace(['.', '-', ' '], '', $rut));

        if ($idServicio !== null && $idServicio > 0 && $idProceso !== null && $idProceso > 0) {
            $stmtPlanificacion = $pdo->prepare("
                SELECT hp.cargo
                FROM ceo_habilitacion_participantes hp
                INNER JOIN ceo_habilitacion h ON h.cuadrilla = hp.id_cuadrilla
                WHERE REPLACE(REPLACE(REPLACE(UPPER(hp.rut), '.', ''), '-', ''), ' ', '') = :rut
                  AND h.id_servicio = :id_servicio
                  AND h.cuadrilla = :id_proceso
                ORDER BY hp.id DESC
                LIMIT 1
            ");
            $stmtPlanificacion->execute([
                ':rut' => $rutKey,
                ':id_servicio' => $idServicio,
                ':id_proceso' => $idProceso,
            ]);
            $cargoPlanificacion = trim((string)($stmtPlanificacion->fetchColumn() ?: ''));

            if ($cargoPlanificacion !== '') {
                $cargoNorm = normalizarTextoCargoPonderacion($cargoPlanificacion);
                $queriesCargoTexto = [
                    "
                        SELECT id
                        FROM ceo_cargo_contratistas
                        WHERE TRIM(UPPER(cargo)) = :cargo
                        LIMIT 1
                    ",
                    "
                        SELECT id
                        FROM ceo_cargos_habilitacion
                        WHERE TRIM(UPPER(cargo)) = :cargo
                        LIMIT 1
                    ",
                ];

                foreach ($queriesCargoTexto as $sqlCargoTexto) {
                    $stmtCargoTexto = $pdo->prepare($sqlCargoTexto);
                    $stmtCargoTexto->execute([':cargo' => $cargoNorm]);
                    $idCargoPlanificacion = $stmtCargoTexto->fetchColumn();

                    if ($idCargoPlanificacion !== false && (int)$idCargoPlanificacion > 0) {
                        return (int)$idCargoPlanificacion;
                    }
                }

                $categoriaPlanificacion = resolverCategoriaCargoPonderacion($cargoPlanificacion);
                if ($categoriaPlanificacion === 'SUPERVISOR') {
                    return 294;
                }
                if ($categoriaPlanificacion === 'OPERADOR') {
                    return 266;
                }
            }
        }

        $queries = [
            "
                SELECT id_cargo
                FROM ceo_servicios_rut
                WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
                LIMIT 1
            ",
            "
                SELECT id_cargo
                FROM ceo_contratistas
                WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
                  AND id_cargo IS NOT NULL
                  AND id_cargo > 0
                LIMIT 1
            ",
            "
                SELECT ps.id_cargo
                FROM ceo_participantes_solicitud ps
                INNER JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
                WHERE REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', '') = :rut
                  AND ps.id_cargo IS NOT NULL
                  AND ps.id_cargo > 0
                ORDER BY s.fecha DESC, s.nsolicitud DESC
                LIMIT 1
            ",
        ];

        foreach ($queries as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':rut' => $rutKey]);
            $cargo = $stmt->fetchColumn();

            if ($cargo !== false && (int)$cargo > 0) {
                return (int)$cargo;
            }
        }

        return null;
    }
}

if (!function_exists('normalizarTextoCargoPonderacion')) {
    function normalizarTextoCargoPonderacion(string $cargo): string
    {
        $cargoNorm = strtoupper(trim($cargo));
        $cargoNorm = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $cargoNorm);
        $cargoNorm = strtr($cargoNorm, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        $cargoNorm = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargoNorm) ?: $cargoNorm;
        $cargoNorm = preg_replace('/[^A-Z0-9]+/u', ' ', $cargoNorm) ?? $cargoNorm;
        return preg_replace('/\s+/', ' ', $cargoNorm) ?? $cargoNorm;
    }
}

if (!function_exists('resolverCategoriaCargoPonderacion')) {
    function resolverCategoriaCargoPonderacion(?string $cargo, ?int $idCargo = null): ?string
    {
        $operadorIds = [266, 268, 287];
        $supervisorIds = [294];

        if ($idCargo !== null) {
            if (in_array($idCargo, $supervisorIds, true)) {
                return 'SUPERVISOR';
            }
            if (in_array($idCargo, $operadorIds, true)) {
                return 'OPERADOR';
            }
        }

        $cargoNorm = normalizarTextoCargoPonderacion((string)$cargo);
        if ($cargoNorm === '') {
            return null;
        }

        if (
            str_contains($cargoNorm, 'SUPERVISOR') ||
            str_contains($cargoNorm, 'LIDER') ||
            str_contains($cargoNorm, 'CAPATAZ') ||
            str_contains($cargoNorm, 'MAESTRO')
        ) {
            return 'SUPERVISOR';
        }

        if (
            str_contains($cargoNorm, 'OPERADOR') ||
            str_contains($cargoNorm, 'ACOMPAN') ||
            str_contains($cargoNorm, 'AYUDANTE')
        ) {
            return 'OPERADOR';
        }

        return null;
    }
}

if (!function_exists('obtenerNombreCargoPonderacion')) {
    function obtenerNombreCargoPonderacion(\PDO $pdo, int $idCargo): ?string
    {
        $queries = [
            'SELECT cargo FROM ceo_cargo_contratistas WHERE id = :id LIMIT 1',
            'SELECT cargo FROM ceo_cargos_habilitacion WHERE id = :id LIMIT 1',
        ];

        foreach ($queries as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $idCargo]);
            $cargo = $stmt->fetchColumn();
            if ($cargo !== false && trim((string)$cargo) !== '') {
                return (string)$cargo;
            }
        }

        return null;
    }
}

/* ===========================================================
   OBTENER REGLA DE PONDERACION
   =========================================================== */
if (!function_exists('obtenerReglaPonderacion')) {
    function obtenerReglaPonderacion(
        \PDO $pdo,
        int $idServicio,
        int $cargo,
        string $segmento = 'GENERAL'
    ): ?array {
        $sql = "
            SELECT
                id,
                id_servicio,
                cargo,
                segmento,
                ponderacion_prueba,
                ponderacion_terreno,
                exige_prueba_aprobada,
                exige_terreno_aprobado,
                observacion
            FROM ceo_reglas_ponderacion
            WHERE id_servicio = :id_servicio
              AND cargo = :cargo
              AND segmento = :segmento
              AND activo = 'S'
              AND fecha_desde <= CURDATE()
              AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
            ORDER BY fecha_desde DESC, id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_servicio' => $idServicio,
            ':cargo'       => $cargo,
            ':segmento'    => $segmento
        ]);

        $regla = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($regla) {
            return $regla;
        }

        $categoria = resolverCategoriaCargoPonderacion(obtenerNombreCargoPonderacion($pdo, $cargo), $cargo);
        if ($categoria === null) {
            return null;
        }

        $sqlFallback = "
            SELECT
                id,
                id_servicio,
                cargo,
                segmento,
                ponderacion_prueba,
                ponderacion_terreno,
                exige_prueba_aprobada,
                exige_terreno_aprobado,
                observacion
            FROM ceo_reglas_ponderacion
            WHERE id_servicio = :id_servicio
              AND segmento = :segmento
              AND activo = 'S'
              AND fecha_desde <= CURDATE()
              AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
            ORDER BY fecha_desde DESC, id DESC
        ";

        $stmtFallback = $pdo->prepare($sqlFallback);
        $stmtFallback->execute([
            ':id_servicio' => $idServicio,
            ':segmento'    => $segmento,
        ]);

        foreach ($stmtFallback->fetchAll(\PDO::FETCH_ASSOC) as $reglaFallback) {
            $idCargoRegla = (int)($reglaFallback['cargo'] ?? 0);
            $categoriaRegla = resolverCategoriaCargoPonderacion(obtenerNombreCargoPonderacion($pdo, $idCargoRegla), $idCargoRegla);
            if ($categoriaRegla === $categoria) {
                return $reglaFallback;
            }
        }

        return null;
    }
}

/* ===========================================================
   ULTIMA NOTA TEORICA
   =========================================================== */
if (!function_exists('obtenerUltimaNotaTeorica')) {
    function obtenerUltimaNotaTeorica(\PDO $pdo, string $rut, int $idServicio, int $idProceso, ?int $idProcesoHabilitacion = null): ?array
    {
        if ($idProcesoHabilitacion !== null && $idProcesoHabilitacion > 0) {
            $sql = "
                SELECT rpi.notafinal AS nota, rpi.puntaje_total AS porcentaje
                FROM ceo_resultado_prueba_intento rpi
                WHERE rpi.rut = :rut
                  AND rpi.id_servicio = :id_servicio
                  AND rpi.id_proceso_habilitacion = :id_proceso_habilitacion
                ORDER BY rpi.fecha_rendicion DESC, rpi.hora_rendicion DESC, rpi.id DESC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':id_proceso_habilitacion' => $idProcesoHabilitacion,
            ]);

            $fila = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$fila) {
                return null;
            }

            return [
                'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
                'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
            ];
        }

        $sql = "
            SELECT
                rpi.notafinal AS nota,
                rpi.puntaje_total AS porcentaje
            FROM ceo_resultado_prueba_intento rpi
            INNER JOIN ceo_evaluaciones_programadas ep
                ON ep.rut = rpi.rut
               AND ep.id_servicio = rpi.id_servicio
            WHERE rpi.rut = :rut
              AND rpi.id_servicio = :id_servicio
              AND ep.cuadrilla = :id_proceso
              AND ep.tipo IN ('PRUEBA', 'TEORICA')
              AND ep.estado = 'EJECUTADA'
              AND ep.resultado IN ('APROBADO', 'REPROBADO')
            ORDER BY ep.intento DESC, rpi.id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rut'         => $rut,
            ':id_servicio' => $idServicio,
            ':id_proceso'  => $idProceso
        ]);

        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$fila) {
            return null;
        }

        return [
            'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
            'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
        ];
    }
}

/* ===========================================================
   ULTIMA NOTA TERRENO
   =========================================================== */
if (!function_exists('obtenerUltimaNotaTerreno')) {
    function obtenerUltimaNotaTerreno(\PDO $pdo, string $rut, int $idServicio, int $idProceso, ?int $idProcesoHabilitacion = null): ?array
    {
        if ($idProcesoHabilitacion !== null && $idProcesoHabilitacion > 0) {
            $sql = "
                SELECT rti.notafinal AS nota, rti.puntaje_total AS porcentaje
                FROM ceo_resultado_terreno_intento rti
                WHERE rti.rut = :rut
                  AND rti.id_servicio = :id_servicio
                  AND rti.id_proceso_habilitacion = :id_proceso_habilitacion
                ORDER BY rti.fecha_rendicion DESC, rti.hora_rendicion DESC, rti.id DESC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':id_proceso_habilitacion' => $idProcesoHabilitacion,
            ]);

            $fila = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$fila) {
                return null;
            }

            return [
                'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
                'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
            ];
        }

        $sql = "
            SELECT
                rti.notafinal AS nota,
                rti.puntaje_total AS porcentaje
            FROM ceo_resultado_terreno_intento rti
            INNER JOIN ceo_evaluaciones_programadas ep
                ON ep.rut = rti.rut
               AND ep.id_servicio = rti.id_servicio
            WHERE rti.rut = :rut
              AND rti.id_servicio = :id_servicio
              AND ep.cuadrilla = :id_proceso
              AND ep.tipo = 'TERRENO'
              AND ep.estado = 'EJECUTADA'
              AND ep.resultado IN ('APROBADO', 'REPROBADO')
            ORDER BY ep.intento DESC, rti.id DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rut'         => $rut,
            ':id_servicio' => $idServicio,
            ':id_proceso'  => $idProceso
        ]);

        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$fila) {
            return null;
        }

        return [
            'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
            'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
        ];
    }
}

/* ===========================================================
   RECALCULAR RESULTADO FINAL DEL SERVICIO
   =========================================================== */
/* ===========================================================
   RECALCULAR RESULTADO FINAL DEL SERVICIO
   - Calcula SIEMPRE la nota final ponderada
   - El estado final se define después del cálculo
   - No deja nota_final = 0 salvo que realmente no exista base de cálculo
   =========================================================== */
if (!function_exists('recalcularResultadoServicio')) {
    function recalcularResultadoServicio(
        \PDO $pdo,
        string $rut,
        int $idServicio,
        int $idProceso,
        string $segmento = 'GENERAL',
        float $porcentajeMinimoAprobacion = 80.0,
        ?int $idProcesoHabilitacion = null
    ): array {
        $cargo = obtenerCargoTrabajador($pdo, $rut, $idServicio, $idProceso);

        if ($cargo === null) {
            throw new RuntimeException("No se encontró cargo para el rut {$rut}");
        }

        $regla = obtenerReglaPonderacion($pdo, $idServicio, $cargo, $segmento);

        if (!$regla) {
            throw new RuntimeException(
                "No existe regla de ponderación para servicio {$idServicio}, cargo {$cargo}, segmento {$segmento}"
            );
        }

        $pesoPrueba  = round((float)($regla['ponderacion_prueba'] ?? 0), 4);
        $pesoTerreno = round((float)($regla['ponderacion_terreno'] ?? 0), 4);

        $exigePruebaAprobada  = strtoupper(trim((string)($regla['exige_prueba_aprobada'] ?? 'S'))) === 'S';
        $exigeTerrenoAprobado = strtoupper(trim((string)($regla['exige_terreno_aprobado'] ?? 'S'))) === 'S';

        $teorica = obtenerUltimaNotaTeorica($pdo, $rut, $idServicio, $idProceso, $idProcesoHabilitacion);
        $terreno = obtenerUltimaNotaTerreno($pdo, $rut, $idServicio, $idProceso, $idProcesoHabilitacion);

        $notaPrueba        = isset($teorica['nota']) ? (float)$teorica['nota'] : null;
        $porcentajePrueba  = isset($teorica['porcentaje']) ? (float)$teorica['porcentaje'] : null;
        $notaTerreno       = isset($terreno['nota']) ? (float)$terreno['nota'] : null;
        $porcentajeTerreno = isset($terreno['porcentaje']) ? (float)$terreno['porcentaje'] : null;

        $base = [
            'rut'                 => $rut,
            'id_servicio'         => $idServicio,
            'id_proceso'          => $idProceso,
            'id_proceso_habilitacion' => $idProcesoHabilitacion,
            'cargo'               => $cargo,
            'segmento'            => $segmento,
            'nota_prueba'         => $notaPrueba,
            'nota_terreno'        => $notaTerreno,
            'porcentaje_prueba'   => $porcentajePrueba,
            'porcentaje_terreno'  => $porcentajeTerreno,
            'ponderacion_prueba'  => $pesoPrueba,
            'ponderacion_terreno' => $pesoTerreno,
            'nota_final'          => null,
            'resultado_final'     => 'PENDIENTE',
            'observacion'         => null
        ];

        /* -------------------------------------------------------
           1. VALIDAR FALTANTES SEGÚN PONDERACIÓN
           ------------------------------------------------------- */
        if ($pesoPrueba > 0 && ($notaPrueba === null || $porcentajePrueba === null)) {
            $base['observacion'] = 'Falta resultado de prueba teórica';
            return $base;
        }

        if ($pesoTerreno > 0 && ($notaTerreno === null || $porcentajeTerreno === null)) {
            $base['observacion'] = 'Falta resultado de terreno';
            return $base;
        }

        /* -------------------------------------------------------
           2. CALCULAR NOTA FINAL PONDERADA
           - Se calcula con notas (escala 1 a 7)
           - Si ambas ponderaciones vienen como 1.00, se normaliza
             a promedio simple para evitar duplicar peso
           ------------------------------------------------------- */
        $notaFinal = null;

        if ($pesoPrueba == 1.00 && $pesoTerreno == 1.00) {
            $sumaNotas = 0.0;
            $contador  = 0;

            if ($notaPrueba !== null) {
                $sumaNotas += $notaPrueba;
                $contador++;
            }

            if ($notaTerreno !== null) {
                $sumaNotas += $notaTerreno;
                $contador++;
            }

            $notaFinal = ($contador > 0) ? round($sumaNotas / $contador, 2) : null;
        } else {
            $acumulado   = 0.0;
            $sumaPesos   = 0.0;

            if ($pesoPrueba > 0 && $notaPrueba !== null) {
                $acumulado += ($notaPrueba * $pesoPrueba);
                $sumaPesos += $pesoPrueba;
            }

            if ($pesoTerreno > 0 && $notaTerreno !== null) {
                $acumulado += ($notaTerreno * $pesoTerreno);
                $sumaPesos += $pesoTerreno;
            }

            if ($sumaPesos > 0) {
                // Si los pesos vienen como 0.60 / 0.40 => suma 1.00 y no altera.
                // Si por error vinieran como 60 / 40, la división también los normaliza.
                $notaFinal = round($acumulado / $sumaPesos, 2);
            }
        }

        $base['nota_final'] = $notaFinal;

        if ($notaFinal === null) {
            $base['observacion'] = 'No fue posible calcular nota final';
            return $base;
        }

        /* -------------------------------------------------------
           3. EVALUAR APROBACIÓN DE CADA COMPONENTE
           - La exigencia mínima se valida por porcentaje
           ------------------------------------------------------- */
        $apruebaPrueba  = ($porcentajePrueba !== null && $porcentajePrueba >= $porcentajeMinimoAprobacion);
        $apruebaTerreno = ($porcentajeTerreno !== null && $porcentajeTerreno >= $porcentajeMinimoAprobacion);

        if ($exigePruebaAprobada && !$apruebaPrueba) {
            $base['resultado_final'] = 'REPROBADO';
            $base['observacion']     = 'No aprueba prueba teórica';
            return $base;
        }

        if ($pesoTerreno > 0 && $exigeTerrenoAprobado && !$apruebaTerreno) {
            $base['resultado_final'] = 'REPROBADO';
            $base['observacion']     = 'No aprueba terreno';
            return $base;
        }

        /* -------------------------------------------------------
           4. APROBADO
           ------------------------------------------------------- */
        $base['resultado_final'] = 'APROBADO';
        $base['observacion']     = 'OK';

        return $base;
    }
}

/* ===========================================================
   GUARDAR RESULTADO FINAL DEL SERVICIO
   =========================================================== */
if (!function_exists('guardarResultadoFinalServicio')) {
    function guardarResultadoFinalServicio(\PDO $pdo, array $resultado): void
    {
        $sql = "
            INSERT INTO ceo_resultado_final_servicio
            (
                rut,
                id_servicio,
                id_proceso,
                id_proceso_habilitacion,
                cargo,
                segmento,
                nota_prueba,
                nota_terreno,
                porcentaje_prueba,
                porcentaje_terreno,
                ponderacion_prueba,
                ponderacion_terreno,
                nota_final,
                resultado_final,
                observacion,
                fecha_calculo
            )
            VALUES
            (
                :rut,
                :id_servicio,
                :id_proceso,
                :id_proceso_habilitacion,
                :cargo,
                :segmento,
                :nota_prueba,
                :nota_terreno,
                :porcentaje_prueba,
                :porcentaje_terreno,
                :ponderacion_prueba,
                :ponderacion_terreno,
                :nota_final,
                :resultado_final,
                :observacion,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                id_proceso_habilitacion = VALUES(id_proceso_habilitacion),
                cargo = VALUES(cargo),
                nota_prueba = VALUES(nota_prueba),
                nota_terreno = VALUES(nota_terreno),
                porcentaje_prueba = VALUES(porcentaje_prueba),
                porcentaje_terreno = VALUES(porcentaje_terreno),
                ponderacion_prueba = VALUES(ponderacion_prueba),
                ponderacion_terreno = VALUES(ponderacion_terreno),
                nota_final = VALUES(nota_final),
                resultado_final = VALUES(resultado_final),
                observacion = VALUES(observacion),
                fecha_calculo = NOW()
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rut'                 => $resultado['rut'],
            ':id_servicio'         => $resultado['id_servicio'],
            ':id_proceso'          => $resultado['id_proceso'],
            ':id_proceso_habilitacion' => $resultado['id_proceso_habilitacion'] ?? null,
            ':cargo'               => $resultado['cargo'],
            ':segmento'            => $resultado['segmento'],
            ':nota_prueba'         => $resultado['nota_prueba'],
            ':nota_terreno'        => $resultado['nota_terreno'],
            ':porcentaje_prueba'   => $resultado['porcentaje_prueba'],
            ':porcentaje_terreno'  => $resultado['porcentaje_terreno'],
            ':ponderacion_prueba'  => $resultado['ponderacion_prueba'],
            ':ponderacion_terreno' => $resultado['ponderacion_terreno'],
            ':nota_final'          => $resultado['nota_final'],
            ':resultado_final'     => $resultado['resultado_final'],
            ':observacion'         => $resultado['observacion']
        ]);

        if (($resultado['resultado_final'] ?? '') === 'APROBADO' && !empty($resultado['id_proceso_habilitacion'])) {
            cerrarProcesoHabilitacion($pdo, (int)$resultado['id_proceso_habilitacion']);
        }
    }
}
