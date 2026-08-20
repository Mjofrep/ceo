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

if (!function_exists('ceoGetLleeConfig')) {
    function ceoGetLleeConfig(): array
    {
        return [
            'service_id' => 1,
            'theory_group_id' => 13,
            'hotline_group_id' => 12,
            'terrain_group_id' => 16,
            'theory_min_pct' => 80.0,
            'terrain_min_pct' => 80.0,
            'hotline_min_pct' => 100.0,
        ];
    }
}

if (!function_exists('ceoIsLleeService')) {
    function ceoIsLleeService(int $serviceId): bool
    {
        return $serviceId === (int)(ceoGetLleeConfig()['service_id'] ?? 0);
    }
}

if (!function_exists('ceoGetPodasConfig')) {
    function ceoGetPodasConfig(): array
    {
        return [
            'service_id' => 8,
            'three_d_min_pct' => 80.0,
            'theory_min_pct' => 80.0,
            'terrain_min_pct' => 80.0,
        ];
    }
}

if (!function_exists('ceoIsPodasService')) {
    function ceoIsPodasService(int $serviceId): bool
    {
        return $serviceId === (int)(ceoGetPodasConfig()['service_id'] ?? 0);
    }
}

if (!function_exists('asegurarTablaEmpresaCorreos')) {
    function asegurarTablaEmpresaCorreos(PDO $pdo): void
    {
        static $asegurada = false;
        if ($asegurada) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS ceo_empresa_correos (
                    id INT NOT NULL AUTO_INCREMENT,
                    id_empresa INT NOT NULL,
                    correo VARCHAR(190) NOT NULL,
                    orden INT NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_empresa_correo (id_empresa, correo),
                    KEY idx_empresa (id_empresa),
                    KEY idx_empresa_orden (id_empresa, orden)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) {
            throw new RuntimeException(
                'No fue posible asegurar la tabla ceo_empresa_correos: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $asegurada = true;
    }
}

if (!function_exists('normalizarCorreosEmpresa')) {
    function normalizarCorreosEmpresa(array $correos, ?string $correoFallback = null): array
    {
        $salida = [];
        $vistos = [];

        foreach ($correos as $correo) {
            $correo = trim((string)$correo);
            if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $key = mb_strtolower($correo, 'UTF-8');
            if (isset($vistos[$key])) {
                continue;
            }

            $vistos[$key] = true;
            $salida[] = $correo;
        }

        if (empty($salida)) {
            $correoFallback = trim((string)$correoFallback);
            if ($correoFallback !== '' && filter_var($correoFallback, FILTER_VALIDATE_EMAIL)) {
                $salida[] = $correoFallback;
            }
        }

        return $salida;
    }
}

if (!function_exists('obtenerCorreosEmpresa')) {
    function obtenerCorreosEmpresa(PDO $pdo, int $idEmpresa, ?string $correoFallback = null): array
    {
        if ($idEmpresa <= 0) {
            return normalizarCorreosEmpresa([], $correoFallback);
        }

        asegurarTablaEmpresaCorreos($pdo);

        $stmt = $pdo->prepare("
            SELECT correo
            FROM ceo_empresa_correos
            WHERE id_empresa = :id_empresa
            ORDER BY orden ASC, id ASC
        ");
        $stmt->execute([':id_empresa' => $idEmpresa]);

        return normalizarCorreosEmpresa(
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [],
            $correoFallback
        );
    }
}

if (!function_exists('guardarCorreosEmpresa')) {
    function guardarCorreosEmpresa(PDO $pdo, int $idEmpresa, array $correos, ?string $correoFallback = null): void
    {
        if ($idEmpresa <= 0) {
            return;
        }

        asegurarTablaEmpresaCorreos($pdo);
        $correos = normalizarCorreosEmpresa($correos, $correoFallback);

        $stmtDelete = $pdo->prepare("DELETE FROM ceo_empresa_correos WHERE id_empresa = :id_empresa");
        $stmtDelete->execute([':id_empresa' => $idEmpresa]);

        if (!empty($correos)) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO ceo_empresa_correos (id_empresa, correo, orden)
                VALUES (:id_empresa, :correo, :orden)
            ");

            foreach (array_values($correos) as $index => $correo) {
                $stmtInsert->execute([
                    ':id_empresa' => $idEmpresa,
                    ':correo' => $correo,
                    ':orden' => $index + 1,
                ]);
            }
        }

        $correoPrincipal = $correos[0] ?? '';
        $stmtEmpresa = $pdo->prepare("
            UPDATE ceo_empresas
            SET correo = :correo
            WHERE id = :id
        ");
        $stmtEmpresa->execute([
            ':correo' => $correoPrincipal,
            ':id' => $idEmpresa,
        ]);
    }
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

if (!function_exists('ensureAuditPruebaTable')) {
    function ensureAuditPruebaTable(\PDO $pdo): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_auditoria_prueba (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                evento VARCHAR(80) NOT NULL,
                usuario_id INT NULL,
                usuario_codigo VARCHAR(80) NOT NULL DEFAULT '',
                usuario_nombre VARCHAR(180) NOT NULL DEFAULT '',
                usuario_rol VARCHAR(120) NOT NULL DEFAULT '',
                rut_evaluado VARCHAR(30) NOT NULL DEFAULT '',
                id_servicio INT NULL,
                servicio VARCHAR(255) NOT NULL DEFAULT '',
                id_programada INT NULL,
                id_agrupacion INT NULL,
                cuadrilla INT NULL,
                id_proceso_habilitacion INT NULL,
                intento INT NULL,
                ip VARCHAR(64) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                metodo VARCHAR(10) NOT NULL DEFAULT '',
                url VARCHAR(255) NOT NULL DEFAULT '',
                detalle LONGTEXT NULL,
                KEY idx_aud_prueba_fecha (created_at),
                KEY idx_aud_prueba_evento (evento),
                KEY idx_aud_prueba_rut (rut_evaluado),
                KEY idx_aud_prueba_servicio (id_servicio),
                KEY idx_aud_prueba_programada (id_programada),
                KEY idx_aud_prueba_proceso_hab (id_proceso_habilitacion)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            return;
        }
    }
}

if (!function_exists('auditPrueba')) {
    function auditPrueba(string $evento, array $data = [], array $usuario = []): void
    {
        if (!function_exists('db')) {
            return;
        }

        $sessionUser = $_SESSION['auth'] ?? [];

        $usuarioId = $usuario['id'] ?? $sessionUser['id'] ?? null;
        $usuarioCodigo = (string)($usuario['codigo'] ?? $sessionUser['codigo'] ?? '');
        $usuarioNombre = (string)($usuario['nombre'] ?? $sessionUser['nombre'] ?? '');
        $usuarioRol = (string)($usuario['rol'] ?? $sessionUser['rol'] ?? '');

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $metodo = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        $url = (string)($_SERVER['REQUEST_URI'] ?? '');

        $detalle = $data['detalle'] ?? [];
        $detalleJson = '{}';
        if (!empty($detalle)) {
            $encoded = json_encode($detalle, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $detalleJson = $encoded;
            }
        }

        try {
            $pdo = db();
            ensureAuditPruebaTable($pdo);

            $stmt = $pdo->prepare("INSERT INTO ceo_auditoria_prueba
                (created_at, evento, usuario_id, usuario_codigo, usuario_nombre, usuario_rol,
                 rut_evaluado, id_servicio, servicio, id_programada, id_agrupacion, cuadrilla,
                 id_proceso_habilitacion, intento, ip, user_agent, metodo, url, detalle)
                VALUES
                (NOW(), :evento, :usuario_id, :usuario_codigo, :usuario_nombre, :usuario_rol,
                 :rut_evaluado, :id_servicio, :servicio, :id_programada, :id_agrupacion, :cuadrilla,
                 :id_proceso_habilitacion, :intento, :ip, :user_agent, :metodo, :url, :detalle)");

            $stmt->execute([
                ':evento' => $evento,
                ':usuario_id' => $usuarioId,
                ':usuario_codigo' => $usuarioCodigo,
                ':usuario_nombre' => $usuarioNombre,
                ':usuario_rol' => $usuarioRol,
                ':rut_evaluado' => (string)($data['rut_evaluado'] ?? ''),
                ':id_servicio' => isset($data['id_servicio']) ? (int)$data['id_servicio'] : null,
                ':servicio' => (string)($data['servicio'] ?? ''),
                ':id_programada' => isset($data['id_programada']) ? (int)$data['id_programada'] : null,
                ':id_agrupacion' => isset($data['id_agrupacion']) ? (int)$data['id_agrupacion'] : null,
                ':cuadrilla' => isset($data['cuadrilla']) ? (int)$data['cuadrilla'] : null,
                ':id_proceso_habilitacion' => isset($data['id_proceso_habilitacion']) ? (int)$data['id_proceso_habilitacion'] : null,
                ':intento' => isset($data['intento']) ? (int)$data['intento'] : null,
                ':ip' => $ip,
                ':user_agent' => mb_substr($userAgent, 0, 255),
                ':metodo' => mb_substr($metodo, 0, 10),
                ':url' => mb_substr($url, 0, 255),
                ':detalle' => $detalleJson,
            ]);
        } catch (\Throwable $e) {
            return;
        }
    }
}

if (!function_exists('ensureAuditDataEditTable')) {
    function ensureAuditDataEditTable(\PDO $pdo): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_auditoria_edicion_datos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                usuario_id INT NULL,
                usuario_codigo VARCHAR(80) NOT NULL DEFAULT '',
                usuario_nombre VARCHAR(180) NOT NULL DEFAULT '',
                usuario_rol VARCHAR(120) NOT NULL DEFAULT '',
                tabla VARCHAR(120) NOT NULL,
                llave_registro VARCHAR(255) NOT NULL,
                columna VARCHAR(120) NOT NULL,
                valor_anterior LONGTEXT NULL,
                valor_nuevo LONGTEXT NULL,
                ip VARCHAR(64) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                metodo VARCHAR(10) NOT NULL DEFAULT '',
                url VARCHAR(255) NOT NULL DEFAULT '',
                KEY idx_aud_edicion_fecha (created_at),
                KEY idx_aud_edicion_tabla (tabla),
                KEY idx_aud_edicion_llave (llave_registro)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            return;
        }
    }
}

if (!function_exists('auditDataEdit')) {
    function auditDataEdit(string $tabla, string $llaveRegistro, string $columna, mixed $valorAnterior, mixed $valorNuevo, array $usuario = []): void
    {
        if (!function_exists('db')) {
            return;
        }

        $sessionUser = $_SESSION['auth'] ?? [];

        $usuarioId = $usuario['id'] ?? $sessionUser['id'] ?? null;
        $usuarioCodigo = (string)($usuario['codigo'] ?? $sessionUser['codigo'] ?? '');
        $usuarioNombre = (string)($usuario['nombre'] ?? $sessionUser['nombre'] ?? '');
        $usuarioRol = (string)($usuario['rol'] ?? $sessionUser['rol'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $metodo = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        $url = (string)($_SERVER['REQUEST_URI'] ?? '');

        $normalizar = static function (mixed $valor): ?string {
            if ($valor === null) {
                return null;
            }
            if (is_bool($valor)) {
                return $valor ? '1' : '0';
            }
            if (is_array($valor) || is_object($valor)) {
                $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return $json !== false ? $json : null;
            }
            return (string)$valor;
        };

        try {
            $pdo = db();
            ensureAuditDataEditTable($pdo);

            $stmt = $pdo->prepare("INSERT INTO ceo_auditoria_edicion_datos
                (created_at, usuario_id, usuario_codigo, usuario_nombre, usuario_rol,
                 tabla, llave_registro, columna, valor_anterior, valor_nuevo,
                 ip, user_agent, metodo, url)
                VALUES
                (NOW(), :usuario_id, :usuario_codigo, :usuario_nombre, :usuario_rol,
                 :tabla, :llave_registro, :columna, :valor_anterior, :valor_nuevo,
                 :ip, :user_agent, :metodo, :url)");

            $stmt->execute([
                ':usuario_id' => $usuarioId,
                ':usuario_codigo' => $usuarioCodigo,
                ':usuario_nombre' => $usuarioNombre,
                ':usuario_rol' => $usuarioRol,
                ':tabla' => $tabla,
                ':llave_registro' => $llaveRegistro,
                ':columna' => $columna,
                ':valor_anterior' => $normalizar($valorAnterior),
                ':valor_nuevo' => $normalizar($valorNuevo),
                ':ip' => $ip,
                ':user_agent' => mb_substr($userAgent, 0, 255),
                ':metodo' => mb_substr($metodo, 0, 10),
                ':url' => mb_substr($url, 0, 255),
            ]);
        } catch (\Throwable $e) {
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

        if ($porcentajeMinimo <= 0 || $porcentajeMinimo > 100) {
            throw new InvalidArgumentException('El porcentaje mínimo debe ser mayor que 0 y menor o igual que 100.');
        }

        if ($porcentajeMinimo === 100.0) {
            if ($porcentaje >= 100.0) {
                return 7.0;
            }

            $nota = 1 + (($porcentaje / 100) * 3);
            return round(min($nota, 3.99), 2);
        }

        if ($porcentaje <= $porcentajeMinimo) {
            $nota = 1 + (($porcentaje / $porcentajeMinimo) * 3);
        } else {
            $nota = 4 + ((($porcentaje - $porcentajeMinimo) / (100 - $porcentajeMinimo)) * 3);
        }

        return round($nota, 2);
    }
}

if (!function_exists('asegurarColumnaPorcentajeFormacionAgrupacion')) {
    function asegurarColumnaPorcentajeFormacionAgrupacion(\PDO $pdo): void
    {
        static $asegurada = false;
        if ($asegurada) {
            return;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM ceo_formacion_agrupacion LIKE 'porcentaje'");
            $existe = $stmt !== false && $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$existe) {
                $pdo->exec(
                    "ALTER TABLE ceo_formacion_agrupacion "
                    . "ADD COLUMN porcentaje DECIMAL(5,2) NOT NULL DEFAULT 80.00 AFTER total"
                );
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No fue posible asegurar la columna porcentaje en ceo_formacion_agrupacion: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        $asegurada = true;
    }
}

if (!function_exists('asegurarColumnaAgrupacionEvaluacionesProgramadas')) {
    function asegurarColumnaAgrupacionEvaluacionesProgramadas(\PDO $pdo): void
    {
        static $asegurada = false;
        if ($asegurada) {
            return;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM ceo_evaluaciones_programadas LIKE 'id_agrupacion'");
            $existe = $stmt !== false && $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$existe) {
                $pdo->exec(
                    "ALTER TABLE ceo_evaluaciones_programadas "
                    . "ADD COLUMN id_agrupacion INT NULL AFTER id_servicio"
                );
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No fue posible asegurar la columna id_agrupacion en ceo_evaluaciones_programadas: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        $asegurada = true;
    }
}

if (!function_exists('obtenerPorcentajeMinimoFormacionAgrupacion')) {
    function obtenerPorcentajeMinimoFormacionAgrupacion(\PDO $pdo, int $idAgrupacion): float
    {
        if ($idAgrupacion <= 0) {
            return 80.0;
        }

        asegurarColumnaPorcentajeFormacionAgrupacion($pdo);

        $stmt = $pdo->prepare("
            SELECT porcentaje
            FROM ceo_formacion_agrupacion
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $idAgrupacion]);
        $valor = $stmt->fetchColumn();

        if ($valor === false || $valor === null || $valor === '') {
            return 80.0;
        }

        $porcentaje = (float)$valor;
        if ($porcentaje <= 0) {
            return 80.0;
        }

        if ($porcentaje > 100) {
            throw new InvalidArgumentException(
                'El porcentaje mínimo configurado para la agrupación de formación es inválido.'
            );
        }

        return round($porcentaje, 2);
    }
}

if (!function_exists('formacionDistribuirCuotasPorArea')) {
    function formacionDistribuirCuotasPorArea(
        int $totalPreguntas,
        array $configRows,
        array $availableAdditionalMap,
        array $mandatoryAreaCounts = []
    ): array {
        if ($totalPreguntas <= 0 || empty($configRows)) {
            return ['targets' => [], 'additional' => []];
        }

        $configuredAreaIds = [];
        foreach ($configRows as $cfg) {
            $areaId = (int)($cfg['id_area'] ?? 0);
            $pct = (float)($cfg['porcentaje'] ?? 0);
            if ($areaId > 0 && $pct > 0) {
                $configuredAreaIds[$areaId] = true;
            }
        }

        $mandatoryOutsideConfig = 0;
        foreach ($mandatoryAreaCounts as $areaId => $count) {
            if ($count <= 0) {
                continue;
            }
            if (!isset($configuredAreaIds[(int)$areaId])) {
                $mandatoryOutsideConfig += (int)$count;
            }
        }

        $distributableTotal = max(0, $totalPreguntas - $mandatoryOutsideConfig);
        if ($distributableTotal <= 0) {
            return ['targets' => [], 'additional' => []];
        }

        $areas = [];
        $sumPercent = 0.0;
        foreach ($configRows as $cfg) {
            $areaId = (int)($cfg['id_area'] ?? 0);
            $pct = (float)($cfg['porcentaje'] ?? 0);
            if ($areaId <= 0 || $pct <= 0) {
                continue;
            }

            $mandatory = (int)($mandatoryAreaCounts[$areaId] ?? 0);
            $availableAdditional = max(0, (int)($availableAdditionalMap[$areaId] ?? 0));
            $maxTarget = $mandatory + $availableAdditional;
            if ($maxTarget <= 0) {
                continue;
            }

            $areas[] = [
                'area' => $areaId,
                'pct' => $pct,
                'mandatory' => $mandatory,
                'available_additional' => $availableAdditional,
                'max_target' => $maxTarget,
                'target' => 0,
                'rem' => 0.0,
            ];
            $sumPercent += $pct;
        }

        if ($sumPercent <= 0 || empty($areas)) {
            return ['targets' => [], 'additional' => []];
        }

        $assignedTotal = 0;
        foreach ($areas as $idx => $area) {
            $exact = ($distributableTotal * $area['pct']) / $sumPercent;
            $rounded = (int)round($exact, 0, PHP_ROUND_HALF_UP);
            $target = min($area['max_target'], max($area['mandatory'], $rounded));
            $areas[$idx]['target'] = $target;
            $areas[$idx]['exact'] = $exact;
            $areas[$idx]['delta'] = $exact - $target;
            $assignedTotal += $target;
        }

        while ($assignedTotal > $distributableTotal) {
            $bestIdx = null;
            $bestOver = -INF;
            foreach ($areas as $idx => $area) {
                if ($area['target'] <= $area['mandatory']) {
                    continue;
                }
                $over = $area['target'] - (float)($area['exact'] ?? 0.0);
                if ($over > $bestOver) {
                    $bestOver = $over;
                    $bestIdx = $idx;
                }
            }
            if ($bestIdx === null) {
                break;
            }
            $areas[$bestIdx]['target']--;
            $areas[$bestIdx]['delta'] = (float)($areas[$bestIdx]['exact'] ?? 0.0) - $areas[$bestIdx]['target'];
            $assignedTotal--;
        }

        while ($assignedTotal < $distributableTotal) {
            $bestIdx = null;
            $bestUnder = -INF;
            foreach ($areas as $idx => $area) {
                if ($area['target'] >= $area['max_target']) {
                    continue;
                }
                $under = (float)($area['exact'] ?? 0.0) - $area['target'];
                if ($under > $bestUnder) {
                    $bestUnder = $under;
                    $bestIdx = $idx;
                }
            }
            if ($bestIdx === null) {
                break;
            }
            $areas[$bestIdx]['target']++;
            $areas[$bestIdx]['delta'] = (float)($areas[$bestIdx]['exact'] ?? 0.0) - $areas[$bestIdx]['target'];
            $assignedTotal++;
        }

        $targets = [];
        $additional = [];
        foreach ($areas as $area) {
            $areaId = (int)$area['area'];
            $targets[$areaId] = (int)$area['target'];
            $additional[$areaId] = max(0, (int)$area['target'] - (int)$area['mandatory']);
        }

        return ['targets' => $targets, 'additional' => $additional];
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
                        FROM ceo_cargos_habilitacion
                        WHERE TRIM(UPPER(cargo)) = :cargo
                        LIMIT 1
                    ",
                    "
                        SELECT id
                        FROM ceo_cargo_contratistas
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

if (!function_exists('obtenerUltimaNotaTeoricaPorAgrupacion')) {
    function obtenerUltimaNotaTeoricaPorAgrupacion(
        
        \PDO $pdo,
        string $rut,
        int $idServicio,
        int $idProceso,
        int $idAgrupacion,
        float $porcentajeMinimo
    ): ?array {
        $sql = "
            SELECT
                rpt.intento,
                MAX(CONCAT(COALESCE(rpt.fecha_rendicion, '0000-00-00'), ' ', COALESCE(rpt.hora_rendicion, '00:00:00'))) AS fecha_hora,
                SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
                COUNT(*) AS total
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps
                ON ps.id = rpt.id_pregunta
               AND ps.id_servicio = :id_servicio
               AND ps.id_agrupacion = :id_agrupacion
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(:rut), '.', ''), '-', ''), ' ', '')
              AND rpt.proceso = :id_proceso
            GROUP BY rpt.intento
            ORDER BY fecha_hora DESC, rpt.intento DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':rut' => $rut,
            ':id_servicio' => $idServicio,
            ':id_agrupacion' => $idAgrupacion,
            ':id_proceso' => $idProceso,
        ]);

        $fila = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$fila) {
            return null;
        }

        $total = (int)($fila['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }

        $correctas = (int)($fila['correctas'] ?? 0);
        $porcentaje = round(($correctas / $total) * 100, 2);
        $nota = round(calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo), 2);

        return [
            'nota' => $nota,
            'porcentaje' => $porcentaje,
            'intento' => isset($fila['intento']) ? (int)$fila['intento'] : null,
            'fecha_hora' => (string)($fila['fecha_hora'] ?? ''),
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

if (!function_exists('obtenerResultadoPodas3d')) {
    function obtenerResultadoPodas3d(\PDO $pdo, string $rut): ?array
    {
        $stmt = $pdo->prepare(' 
            SELECT rut_usuario, fecha_registro, puntuacion_final_2
            FROM ceo_prueba_3d
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rut_usuario), ".", ""), "-", ""), " ", "") = REPLACE(REPLACE(REPLACE(UPPER(:rut), ".", ""), "-", ""), " ", "")
            ORDER BY id DESC
            LIMIT 1
        ');

        try {
            $stmt->execute([':rut' => $rut]);
        } catch (Throwable $e) {
            return null;
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'rut' => (string)($row['rut_usuario'] ?? ''),
            'fecha_registro' => trim((string)($row['fecha_registro'] ?? '')),
            'puntaje' => isset($row['puntuacion_final_2']) ? (float)$row['puntuacion_final_2'] : null,
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
        if (ceoIsLleeService($idServicio)) {
            $cargo = obtenerCargoTrabajador($pdo, $rut, $idServicio, $idProceso);

            if ($cargo === null) {
                throw new RuntimeException("No se encontró cargo para el rut {$rut}");
            }

            $cfg = ceoGetLleeConfig();
            $teorica = obtenerUltimaNotaTeoricaPorAgrupacion($pdo, $rut, $idServicio, $idProceso, (int)$cfg['theory_group_id'], (float)$cfg['theory_min_pct']);
            $hotline = obtenerUltimaNotaTeoricaPorAgrupacion($pdo, $rut, $idServicio, $idProceso, (int)$cfg['hotline_group_id'], (float)$cfg['hotline_min_pct']);
            $terreno = obtenerUltimaNotaTerreno($pdo, $rut, $idServicio, $idProceso, $idProcesoHabilitacion);

            $notaPrueba = isset($teorica['nota']) ? (float)$teorica['nota'] : null;
            $porcentajePrueba = isset($teorica['porcentaje']) ? (float)$teorica['porcentaje'] : null;
            $notaTerreno = isset($terreno['nota']) ? (float)$terreno['nota'] : null;
            $porcentajeTerreno = isset($terreno['porcentaje']) ? (float)$terreno['porcentaje'] : null;
            $porcentajeHotline = isset($hotline['porcentaje']) ? (float)$hotline['porcentaje'] : null;

            $notaFinal = null;
            if ($notaPrueba !== null && $notaTerreno !== null) {
                $notaFinal = round(($notaPrueba + $notaTerreno) / 2, 2);
            }

            $base = [
                'rut' => $rut,
                'id_servicio' => $idServicio,
                'id_proceso' => $idProceso,
                'id_proceso_habilitacion' => $idProcesoHabilitacion,
                'cargo' => $cargo,
                'segmento' => $segmento,
                'nota_prueba' => $notaPrueba,
                'nota_terreno' => $notaTerreno,
                'porcentaje_prueba' => $porcentajePrueba,
                'porcentaje_terreno' => $porcentajeTerreno,
                'ponderacion_prueba' => 0.5,
                'ponderacion_terreno' => 0.5,
                'nota_final' => $notaFinal,
                'resultado_final' => 'PENDIENTE',
                'observacion' => null,
            ];

            if ($porcentajePrueba === null) {
                $base['observacion'] = 'Falta resultado de prueba LLEE';
                return $base;
            }
            if ($porcentajeHotline === null) {
                $base['observacion'] = 'Falta resultado de prueba Hotline';
                return $base;
            }
            if ($porcentajeTerreno === null) {
                $base['observacion'] = 'Falta resultado de terreno';
                return $base;
            }

            if ($porcentajePrueba < (float)$cfg['theory_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba prueba LLEE';
                return $base;
            }
            if ($porcentajeHotline < (float)$cfg['hotline_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba Hotline';
                return $base;
            }
            if ($porcentajeTerreno < (float)$cfg['terrain_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba terreno';
                return $base;
            }

            $base['resultado_final'] = 'APROBADO';
            $base['observacion'] = 'OK LLEE';
            return $base;
        }

        if (ceoIsPodasService($idServicio)) {
            $cargo = obtenerCargoTrabajador($pdo, $rut, $idServicio, $idProceso);

            if ($cargo === null) {
                throw new RuntimeException("No se encontró cargo para el rut {$rut}");
            }

            $cfg = ceoGetPodasConfig();
            $teorica = obtenerUltimaNotaTeorica($pdo, $rut, $idServicio, $idProceso, $idProcesoHabilitacion);
            $terreno = obtenerUltimaNotaTerreno($pdo, $rut, $idServicio, $idProceso, $idProcesoHabilitacion);
            $resultado3d = obtenerResultadoPodas3d($pdo, $rut);

            $notaPrueba = isset($teorica['nota']) ? (float)$teorica['nota'] : null;
            $porcentajePrueba = isset($teorica['porcentaje']) ? (float)$teorica['porcentaje'] : null;
            $notaTerreno = isset($terreno['nota']) ? (float)$terreno['nota'] : null;
            $porcentajeTerreno = isset($terreno['porcentaje']) ? (float)$terreno['porcentaje'] : null;
            $puntaje3d = isset($resultado3d['puntaje']) ? (float)$resultado3d['puntaje'] : null;

            $notaFinal = null;
            if ($notaPrueba !== null && $notaTerreno !== null) {
                $notaFinal = round(($notaPrueba + $notaTerreno) / 2, 2);
            }

            $base = [
                'rut' => $rut,
                'id_servicio' => $idServicio,
                'id_proceso' => $idProceso,
                'id_proceso_habilitacion' => $idProcesoHabilitacion,
                'cargo' => $cargo,
                'segmento' => $segmento,
                'nota_prueba' => $notaPrueba,
                'nota_terreno' => $notaTerreno,
                'porcentaje_prueba' => $porcentajePrueba,
                'porcentaje_terreno' => $porcentajeTerreno,
                'ponderacion_prueba' => 0.5,
                'ponderacion_terreno' => 0.5,
                'nota_final' => $notaFinal,
                'resultado_final' => 'PENDIENTE',
                'observacion' => null,
            ];

            if ($porcentajePrueba === null) {
                $base['observacion'] = 'Falta resultado de prueba teórica';
                return $base;
            }
            if ($porcentajeTerreno === null) {
                $base['observacion'] = 'Falta resultado de terreno';
                return $base;
            }
            if ($puntaje3d === null) {
                $base['observacion'] = 'Falta resultado 3D';
                return $base;
            }

            if ($porcentajePrueba < (float)$cfg['theory_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba prueba teórica';
                return $base;
            }
            if ($porcentajeTerreno < (float)$cfg['terrain_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba terreno';
                return $base;
            }
            if ($puntaje3d < (float)$cfg['three_d_min_pct']) {
                $base['resultado_final'] = 'REPROBADO';
                $base['observacion'] = 'No aprueba 3D';
                return $base;
            }

            $base['resultado_final'] = 'APROBADO';
            $base['observacion'] = 'OK PODAS';
            return $base;
        }

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
           3. DEFINIR ESTADO FINAL DESDE LA NOTA FINAL
           ------------------------------------------------------- */
        $base['resultado_final'] = $notaFinal >= 4.0 ? 'APROBADO' : 'REPROBADO';
        $base['observacion']     = 'OK';

        return $base;
    }
}

if (!function_exists('sincronizarVigenciaDetalleHabilitacion')) {
    function sincronizarVigenciaDetalleHabilitacion(\PDO $pdo, array $resultado): void
    {
        $rut = trim((string)($resultado['rut'] ?? ''));
        $idServicio = (int)($resultado['id_servicio'] ?? 0);
        $idProceso = (int)($resultado['id_proceso'] ?? 0);
        $idProcesoHabilitacion = (int)($resultado['id_proceso_habilitacion'] ?? 0);

        if ($rut === '' || $idServicio <= 0 || $idProceso <= 0) {
            return;
        }

        $stmtDelete = $pdo->prepare('
            DELETE FROM ceo_vigencia_detalle
            WHERE rut = :rut
              AND id_servicio = :id_servicio
              AND id_proceso = :id_proceso
        ');
        $stmtDelete->execute([
            ':rut' => $rut,
            ':id_servicio' => $idServicio,
            ':id_proceso' => $idProceso,
        ]);

        if (strtoupper(trim((string)($resultado['resultado_final'] ?? ''))) !== 'APROBADO') {
            return;
        }

        $tiposRequeridos = [];
        if ((float)($resultado['ponderacion_prueba'] ?? 0) > 0) {
            $tiposRequeridos[] = 'PRUEBA';
        }
        if ((float)($resultado['ponderacion_terreno'] ?? 0) > 0) {
            $tiposRequeridos[] = 'TERRENO';
        }

        if (empty($tiposRequeridos)) {
            return;
        }

        $stmtProg = $pdo->prepare('
            SELECT id_proceso_habilitacion
            FROM ceo_evaluaciones_programadas
            WHERE rut = :rut
              AND id_servicio = :id_servicio
              AND cuadrilla = :cuadrilla
              AND tipo = :tipo
              AND estado = "EJECUTADA"
              AND resultado = "APROBADO"
            ORDER BY id DESC
            LIMIT 1
        ');

        $stmtInsert = $pdo->prepare('
            INSERT INTO ceo_vigencia_detalle
                (rut, id_servicio, fechavig_ini, fechavig_fin, id_proceso, id_proceso_habilitacion, tipo)
            VALUES
                (:rut, :id_servicio, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 YEAR), :id_proceso, :id_proceso_habilitacion, :tipo)
        ');

        foreach ($tiposRequeridos as $tipo) {
            $stmtProg->execute([
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':cuadrilla' => $idProceso,
                ':tipo' => $tipo,
            ]);
            $programada = $stmtProg->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$programada) {
                continue;
            }

            $stmtInsert->execute([
                ':rut' => $rut,
                ':id_servicio' => $idServicio,
                ':id_proceso' => $idProceso,
                ':id_proceso_habilitacion' => (int)($programada['id_proceso_habilitacion'] ?? $idProcesoHabilitacion) ?: null,
                ':tipo' => $tipo,
            ]);
        }

        recalcularVigenciaGeneral($pdo, $rut, $idProceso);
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

        sincronizarVigenciaDetalleHabilitacion($pdo, $resultado);

        if (($resultado['resultado_final'] ?? '') === 'APROBADO' && !empty($resultado['id_proceso_habilitacion'])) {
            cerrarProcesoHabilitacion($pdo, (int)$resultado['id_proceso_habilitacion']);
        }
    }
}
