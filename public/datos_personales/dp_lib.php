<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/app.php';

function dpCeoDb(): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        return dpExternalDb('noeticac_ceo');
    }
}

function dpModuleGuard(): void
{
    if ((int)($_SESSION['auth']['id_rol'] ?? 0) !== 1) {
        header('Location: ' . app_url('/public/general.php'));
        exit;
    }
}

function dpExternalDbConfig(): array
{
    return [
        'host' => getenv('DP_EXT_DB_HOST') ?: 'localhost',
        'port' => ($port = trim((string)(getenv('DP_EXT_DB_PORT') ?: ''))) !== '' ? (int)$port : null,
        'user' => getenv('DP_EXT_DB_USER') ?: 'noeticac_root',
        'pass' => getenv('DP_EXT_DB_PASS') ?: 'Turing_1964$',
        'charset' => getenv('DP_EXT_DB_CHARSET') ?: 'utf8mb4',
    ];
}

function dpExternalDbCandidates(): array
{
    $primary = dpExternalDbConfig();

    return [
        $primary,
        [
            'host' => 'localhost',
            'port' => null,
            'user' => 'noeticac_root',
            'pass' => 'Turing_1964$',
            'charset' => 'utf8mb4',
        ],
        [
            'host' => '127.0.0.1',
            'port' => null,
            'user' => 'noeticac_root',
            'pass' => 'Turing_1964$',
            'charset' => 'utf8mb4',
        ],
        [
            'host' => '127.0.0.1',
            'port' => 8889,
            'user' => 'root',
            'pass' => 'root',
            'charset' => 'utf8mb4',
        ],
    ];
}

function dpExternalDb(string $database): PDO
{
    static $instances = [];

    if (isset($instances[$database])) {
        return $instances[$database];
    }

    $lastException = null;
    foreach (dpExternalDbCandidates() as $cfg) {
        try {
            $dsn = 'mysql:host=' . $cfg['host'] . ';dbname=' . $database . ';charset=' . $cfg['charset'];
            if (!empty($cfg['port'])) {
                $dsn .= ';port=' . (int)$cfg['port'];
            }

            $instances[$database] = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $instances[$database];
        } catch (Throwable $e) {
            $lastException = $e;
        }
    }

    if ($lastException instanceof Throwable) {
        throw $lastException;
    }

    throw new RuntimeException('No fue posible conectar a la base de datos externa.');
}

function dpNormalizeRut(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

function dpEnsureTables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_dp_catalogo_sistema (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sistema_key VARCHAR(50) NOT NULL,
        sistema_nombre VARCHAR(120) NOT NULL,
        base_datos VARCHAR(120) NOT NULL,
        finalidad VARCHAR(255) NOT NULL,
        conservacion VARCHAR(120) NOT NULL DEFAULT 'POR DEFINIR',
        contiene_datos_sensibles TINYINT(1) NOT NULL DEFAULT 0,
        observaciones TEXT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dp_catalogo_sistema_key (sistema_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_dp_solicitud (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        folio VARCHAR(30) NOT NULL,
        rut_titular VARCHAR(20) NOT NULL,
        rut_normalizado VARCHAR(20) NOT NULL,
        tipo_solicitud VARCHAR(30) NOT NULL,
        canal VARCHAR(50) NOT NULL DEFAULT 'EXTERNO',
        detalle_solicitud TEXT NULL,
        estado VARCHAR(30) NOT NULL DEFAULT 'ABIERTA',
        usuario_id INT NULL,
        usuario_codigo VARCHAR(100) NOT NULL DEFAULT '',
        usuario_nombre VARCHAR(180) NOT NULL DEFAULT '',
        fecha_recepcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_respuesta DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dp_solicitud_folio (folio),
        KEY idx_dp_solicitud_rut (rut_normalizado),
        KEY idx_dp_solicitud_fecha (fecha_recepcion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_dp_consulta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        solicitud_id BIGINT UNSIGNED NULL,
        rut_consultado VARCHAR(20) NOT NULL,
        rut_normalizado VARCHAR(20) NOT NULL,
        motivo VARCHAR(255) NOT NULL DEFAULT '',
        usuario_id INT NULL,
        usuario_codigo VARCHAR(100) NOT NULL DEFAULT '',
        usuario_nombre VARCHAR(180) NOT NULL DEFAULT '',
        resumen_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dp_consulta_rut (rut_normalizado),
        KEY idx_dp_consulta_fecha (created_at),
        KEY idx_dp_consulta_solicitud (solicitud_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_dp_consulta_sistema (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        consulta_id BIGINT UNSIGNED NOT NULL,
        sistema_key VARCHAR(50) NOT NULL,
        sistema_nombre VARCHAR(120) NOT NULL,
        base_datos VARCHAR(120) NOT NULL,
        fuente VARCHAR(120) NOT NULL,
        encontrado TINYINT(1) NOT NULL DEFAULT 0,
        total_registros INT NOT NULL DEFAULT 0,
        datos_resumen LONGTEXT NULL,
        finalidad VARCHAR(255) NOT NULL,
        conservacion VARCHAR(120) NOT NULL DEFAULT 'POR DEFINIR',
        contiene_datos_sensibles TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dp_consulta_sistema_consulta (consulta_id),
        KEY idx_dp_consulta_sistema_key (sistema_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    dpSeedCatalog($pdo);
    $ensured = true;
}

function dpSeedCatalog(PDO $pdo): void
{
    $rows = [
        [
            'sistema_key' => 'ceo',
            'sistema_nombre' => 'CEONext',
            'base_datos' => 'noeticac_ceo',
            'finalidad' => 'Gestion de acreditaciones, habilitaciones, evaluaciones, formacion y trazabilidad operativa.',
            'conservacion' => '10 anos desde la fecha del registro',
            'contiene_datos_sensibles' => 0,
            'observaciones' => 'Incluye solicitudes, procesos, resultados, vigencias y certificados.',
        ],
        [
            'sistema_key' => 'salud',
            'sistema_nombre' => 'Salud',
            'base_datos' => 'noeticac_ceo_salud',
            'finalidad' => 'Evaluacion ocupacional y seguimiento de salud de trabajadores.',
            'conservacion' => '10 anos desde la fecha del registro',
            'contiene_datos_sensibles' => 1,
            'observaciones' => 'Contiene datos clinicos y anamnesis.',
        ],
        [
            'sistema_key' => 'forms',
            'sistema_nombre' => 'Forms',
            'base_datos' => 'noeticac_form2',
            'finalidad' => 'Captura estructurada de datos en formularios, invitaciones e inscripciones.',
            'conservacion' => '10 anos desde la fecha del registro',
            'contiene_datos_sensibles' => 0,
            'observaciones' => 'Los datos personales viven en respuestas de formularios, no en tablas maestras.',
        ],
        [
            'sistema_key' => 'feedback',
            'sistema_nombre' => 'Feedback',
            'base_datos' => 'noeticac_feedback',
            'finalidad' => 'Levantamiento y analisis de comentarios y experiencia.',
            'conservacion' => '10 anos desde la fecha del registro',
            'contiene_datos_sensibles' => 0,
            'observaciones' => 'No se observa identificacion por RUT en el modelo actual.',
        ],
    ];

    $stmt = $pdo->prepare("INSERT INTO ceo_dp_catalogo_sistema
        (sistema_key, sistema_nombre, base_datos, finalidad, conservacion, contiene_datos_sensibles, observaciones)
        VALUES
        (:sistema_key, :sistema_nombre, :base_datos, :finalidad, :conservacion, :contiene_datos_sensibles, :observaciones)
        ON DUPLICATE KEY UPDATE
            sistema_nombre = VALUES(sistema_nombre),
            base_datos = VALUES(base_datos),
            finalidad = VALUES(finalidad),
            conservacion = VALUES(conservacion),
            contiene_datos_sensibles = VALUES(contiene_datos_sensibles),
            observaciones = VALUES(observaciones),
            activo = 1");

    foreach ($rows as $row) {
        $stmt->execute($row);
    }
}

function dpCatalogByKey(PDO $pdo): array
{
    dpEnsureTables($pdo);
    $rows = $pdo->query('SELECT * FROM ceo_dp_catalogo_sistema WHERE activo = 1 ORDER BY id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(string)$row['sistema_key']] = $row;
    }
    return $out;
}

function dpCreateFolio(PDO $pdo): string
{
    $prefix = 'DP-' . date('Ymd') . '-';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ceo_dp_solicitud WHERE folio LIKE :prefix');
    $stmt->execute([':prefix' => $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function dpBuildSystemResult(array $catalog, string $source, int $total, array $categories, array $sample, array $extra = []): array
{
    return [
        'sistema_key' => (string)$catalog['sistema_key'],
        'sistema_nombre' => (string)$catalog['sistema_nombre'],
        'base_datos' => (string)$catalog['base_datos'],
        'fuente' => $source,
        'encontrado' => $total > 0,
        'total_registros' => $total,
        'finalidad' => (string)$catalog['finalidad'],
        'conservacion' => (string)$catalog['conservacion'],
        'contiene_datos_sensibles' => ((int)$catalog['contiene_datos_sensibles']) === 1,
        'datos_categorias' => array_values(array_unique(array_filter($categories))),
        'muestra' => $sample,
        'observaciones' => (string)($extra['observaciones'] ?? $catalog['observaciones'] ?? ''),
    ];
}

function dpTipoSolicitudLabel(string $value): string
{
    return match (strtoupper(trim($value))) {
        'ACCESO' => 'Acceso a datos',
        'RECTIFICACION' => 'Rectificacion de datos',
        'ELIMINACION' => 'Eliminacion de datos',
        'BLOQUEO' => 'Bloqueo de datos',
        default => trim($value),
    };
}

function dpSystemStatus(array $system): array
{
    $observacion = trim((string)($system['observaciones'] ?? ''));
    if (stripos($observacion, 'No fue posible consultar este sistema:') === 0) {
        return ['label' => 'No disponible', 'class' => 'text-bg-warning'];
    }

    if (!empty($system['encontrado'])) {
        return ['label' => 'Con datos', 'class' => 'text-bg-success'];
    }

    return ['label' => 'Sin datos', 'class' => 'text-bg-secondary'];
}

function dpSystemCategoriesText(array $system): string
{
    $categories = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $system['datos_categorias'] ?? [])));
    if ($categories === []) {
        return 'Sin categorias identificadas para esta consulta.';
    }

    return implode(', ', $categories) . '.';
}

function dpSummaryNarrative(array $summary): string
{
    $sistemas = (int)($summary['sistemas_consultados'] ?? 0);
    $hallazgos = (int)($summary['sistemas_con_hallazgo'] ?? 0);
    $registros = (int)($summary['total_registros'] ?? 0);

    return 'Se consultaron ' . $sistemas . ' sistemas. '
        . 'Se identificaron coincidencias en ' . $hallazgos . ' sistema(s) '
        . 'con un total referencial de ' . $registros . ' registro(s) muestreado(s).';
}

function dpFormatShortDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        $hasTime = preg_match('/\d{2}:\d{2}/', $value) === 1;
        return $dt->format($hasTime ? 'd-m-Y H:i' : 'd-m-Y');
    } catch (Throwable $e) {
        return null;
    }
}

function dpCollectSampleDates(mixed $value, array &$dates, ?string $key = null): void
{
    if (is_array($value)) {
        foreach ($value as $childKey => $childValue) {
            dpCollectSampleDates($childValue, $dates, is_string($childKey) ? $childKey : $key);
        }
        return;
    }

    if (!is_scalar($value) || !is_string($key)) {
        return;
    }

    $keyNorm = strtolower(trim($key));
    if (!preg_match('/fecha|submitted_at|created_at|updated_at|vig/', $keyNorm)) {
        return;
    }

    $formatted = dpFormatShortDate((string)$value);
    if ($formatted !== null) {
        $dates[$formatted] = true;
    }
}

function dpSystemDatesText(array $system): string
{
    $dates = [];
    dpCollectSampleDates($system['muestra'] ?? [], $dates);
    $values = array_keys($dates);
    sort($values);

    if ($values === []) {
        return 'Sin fechas registradas en la muestra de esta consulta.';
    }

    if (count($values) === 1) {
        return 'Fecha registrada: ' . $values[0] . '.';
    }

    if (count($values) <= 4) {
        return 'Fechas registradas: ' . implode(', ', $values) . '.';
    }

    return 'Fechas registradas entre ' . $values[0] . ' y ' . $values[count($values) - 1] . '.';
}

function dpUniqueNonEmpty(array $values): array
{
    $out = [];
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        $out[$text] = true;
    }
    return array_keys($out);
}

function dpSystemConceptText(array $system): string
{
    $sample = $system['muestra'] ?? [];
    $sistema = (string)($system['sistema_key'] ?? '');

    if ($sistema === 'ceo') {
        $concepts = [];
        if (!empty($sample['solicitudes'])) {
            $servicios = dpUniqueNonEmpty(array_column((array)$sample['solicitudes'], 'servicio'));
            $concepts[] = 'solicitudes' . ($servicios !== [] ? ' asociadas a ' . implode(', ', array_slice($servicios, 0, 3)) : '');
        }
        if (!empty($sample['procesos'])) {
            $cargos = dpUniqueNonEmpty(array_column((array)$sample['procesos'], 'cargo'));
            $concepts[] = 'procesos de habilitacion' . ($cargos !== [] ? ' para ' . implode(', ', array_slice($cargos, 0, 3)) : '');
        }
        if (!empty($sample['evaluaciones'])) {
            $tipos = dpUniqueNonEmpty(array_column((array)$sample['evaluaciones'], 'tipo_evaluacion'));
            $concepts[] = 'evaluaciones ' . implode(' y ', array_slice($tipos, 0, 2));
        }
        if (!empty($sample['formacion'])) {
            $servicios = dpUniqueNonEmpty(array_column((array)$sample['formacion'], 'servicio'));
            $concepts[] = 'formacion' . ($servicios !== [] ? ' en ' . implode(', ', array_slice($servicios, 0, 3)) : '');
        }
        if (!empty($sample['certificados'])) {
            $concepts[] = 'certificados y vigencias';
        }

        return $concepts === [] ? 'Sin concepto identificado en la muestra.' : 'Concepto: ' . implode('; ', $concepts) . '.';
    }

    if ($sistema === 'salud') {
        $concepts = [];
        if (!empty($sample['paciente'])) {
            $cargo = trim((string)($sample['paciente']['cargo'] ?? ''));
            $concepts[] = 'ficha de paciente' . ($cargo !== '' ? ' con cargo ' . $cargo : '');
        }
        if (!empty($sample['evaluaciones'])) {
            $tipos = dpUniqueNonEmpty(array_column((array)$sample['evaluaciones'], 'tipo'));
            $concepts[] = 'evaluaciones de salud' . ($tipos !== [] ? ' tipo ' . implode(', ', array_slice($tipos, 0, 3)) : '');
        }
        if (!empty($sample['evaluaciones_clinicas'])) {
            $concepts[] = 'registros clinicos ocupacionales';
        }
        if (!empty($sample['certificados'])) {
            $tipos = dpUniqueNonEmpty(array_column((array)$sample['certificados'], 'tipo'));
            $concepts[] = 'certificados' . ($tipos !== [] ? ' de ' . implode(', ', array_slice($tipos, 0, 3)) : '');
        }

        return $concepts === [] ? 'Sin concepto identificado en la muestra.' : 'Concepto: ' . implode('; ', $concepts) . '.';
    }

    if ($sistema === 'forms') {
        $campaigns = [];
        foreach ((array)$sample as $row) {
            $campaigns[] = $row['campana'] ?? '';
        }
        $campaigns = dpUniqueNonEmpty($campaigns);
        if ($campaigns === []) {
            return 'Sin concepto identificado en la muestra.';
        }
        return 'Concepto: envios de formularios en ' . implode(', ', array_slice($campaigns, 0, 4)) . '.';
    }

    if ($sistema === 'feedback') {
        return 'Concepto: sistema revisado sin registros asociados al RUT consultado.';
    }

    return 'Sin concepto identificado en la muestra.';
}

function dpSearchCeo(string $rut, array $catalog): array
{
    $pdo = dpCeoDb();
    $rutNorm = dpNormalizeRut($rut);

    $stmtSolicitudes = $pdo->prepare("SELECT ps.id_solicitud, s.fecha, s.estado, ps.nombre, ps.apellidop, ps.apellidom,
            e.nombre AS empresa, sv.servicio, c.cargo AS cargo
        FROM ceo_participantes_solicitud ps
        LEFT JOIN ceo_solicitudes s ON s.nsolicitud = ps.id_solicitud
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_servicios sv ON sv.id = s.servicio
        LEFT JOIN ceo_cargo_contratistas c ON c.id = ps.id_cargo
        WHERE REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY s.fecha DESC, ps.id_solicitud DESC
        LIMIT 10");
    $stmtSolicitudes->execute([':rut' => $rutNorm]);
    $solicitudes = $stmtSolicitudes->fetchAll();

    $stmtProcesos = $pdo->prepare("SELECT ph.numero_proceso, ph.estado, ph.fecha_inicio, ph.fecha_cierre,
            sp.servicio, ch.cargo,
            rfs.nota_final, rfs.resultado_final,
            vd.fechavig_ini, vd.fechavig_fin, vd.tipo AS tipo_vigencia
        FROM ceo_proceso_habilitacion ph
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = ph.id_servicio
        LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = ph.id_cargo
        LEFT JOIN ceo_resultado_final_servicio rfs
            ON rfs.rut COLLATE utf8mb4_unicode_ci = ph.rut COLLATE utf8mb4_unicode_ci
            AND rfs.id_servicio = ph.id_servicio AND rfs.id_proceso_habilitacion = ph.id AND rfs.segmento = 'GENERAL'
        LEFT JOIN ceo_vigencia_detalle vd
            ON vd.rut COLLATE utf8mb4_unicode_ci = ph.rut COLLATE utf8mb4_unicode_ci
            AND vd.id_servicio = ph.id_servicio AND vd.id_proceso_habilitacion = ph.id
        WHERE REPLACE(REPLACE(REPLACE(UPPER(ph.rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY ph.fecha_inicio DESC, ph.id DESC
        LIMIT 10");
    $stmtProcesos->execute([':rut' => $rutNorm]);
    $procesos = $stmtProcesos->fetchAll();

    $stmtEvals = $pdo->prepare("SELECT tipo_evaluacion, servicio, fecha, resultado, notafinal, cargo, empresa, evaluador, codigo_evaluacion
        FROM vw_ceo_historial_evaluaciones_persona
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY fecha DESC, fecha_hora DESC
        LIMIT 10");
    $stmtEvals->execute([':rut' => $rutNorm]);
    $evaluaciones = $stmtEvals->fetchAll();

    $stmtFormacion = $pdo->prepare("SELECT fp.fecha_programacion, fp.fecha_resultado, fp.estado, fp.resultado, fp.intento,
            fs.servicio, p.nombre, p.apellidos, p.cargo, e.nombre AS empresa
        FROM ceo_formacion_programadas fp
        LEFT JOIN ceo_formacion_servicios fs ON fs.id = fp.id_servicio
        LEFT JOIN ceo_formacion_participantes p
            ON p.rut COLLATE utf8mb4_unicode_ci = fp.rut COLLATE utf8mb4_unicode_ci AND p.id_cuadrilla = fp.cuadrilla
        LEFT JOIN ceo_formacion f ON f.cuadrilla = fp.cuadrilla
        LEFT JOIN ceo_empresas e ON e.id = f.empresa
        WHERE REPLACE(REPLACE(REPLACE(UPPER(fp.rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY COALESCE(fp.fecha_resultado, fp.fecha_programacion) DESC, fp.id DESC
        LIMIT 10");
    $stmtFormacion->execute([':rut' => $rutNorm]);
    $formacion = $stmtFormacion->fetchAll();

    $stmtCertificados = $pdo->prepare("SELECT codigo_certificado, nombre, apellidos, cargo, empresa, servicio, fechavig_fin, estado, ruta_archivo
        FROM ceo_certificados
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY id DESC
        LIMIT 10");
    $stmtCertificados->execute([':rut' => $rutNorm]);
    $certificados = $stmtCertificados->fetchAll();

    $sample = [
        'solicitudes' => $solicitudes,
        'procesos' => $procesos,
        'evaluaciones' => $evaluaciones,
        'formacion' => $formacion,
        'certificados' => $certificados,
    ];

    return dpBuildSystemResult(
        $catalog,
        'solicitudes, habilitaciones, evaluaciones, formacion, certificados',
        count($solicitudes) + count($procesos) + count($evaluaciones) + count($formacion) + count($certificados),
        ['RUT', 'nombre', 'empresa', 'cargo', 'estado', 'servicio', 'resultado', 'certificado'],
        $sample
    );
}

function dpSearchSalud(string $rut, array $catalog): array
{
    $pdo = dpExternalDb('noeticac_ceo_salud');
    $rutNorm = dpNormalizeRut($rut);

    $stmtPaciente = $pdo->prepare("SELECT id, rut, nombre, cargo, area, telefono, email, activo
        FROM pacientes
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
        LIMIT 1");
    $stmtPaciente->execute([':rut' => $rutNorm]);
    $paciente = $stmtPaciente->fetch() ?: null;

    $evaluaciones = [];
    $certificados = [];
    if ($paciente) {
        $stmtEval = $pdo->prepare("SELECT fecha, tipo, estado, resultado, profesional, observaciones
            FROM evaluaciones
            WHERE paciente_id = :paciente_id
            ORDER BY fecha DESC, id DESC
            LIMIT 10");
        $stmtEval->execute([':paciente_id' => (int)$paciente['id']]);
        $evaluaciones = $stmtEval->fetchAll();

        $stmtCert = $pdo->prepare("SELECT fecha_emision, tipo, detalle, url_archivo
            FROM certificados
            WHERE paciente_id = :paciente_id
            ORDER BY fecha_emision DESC, id DESC
            LIMIT 10");
        $stmtCert->execute([':paciente_id' => (int)$paciente['id']]);
        $certificados = $stmtCert->fetchAll();
    }

    $stmtClinica = $pdo->prepare("SELECT fecha, telefono, pa, fc, fr, temp, sat, glucosa, peso, estatura, imc, anamnesis
        FROM evaluaciones_clinicas
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rut), '.', ''), '-', ''), ' ', '') = :rut
        ORDER BY fecha DESC, id DESC
        LIMIT 10");
    $stmtClinica->execute([':rut' => $rutNorm]);
    $clinicas = $stmtClinica->fetchAll();

    $sample = [
        'paciente' => $paciente,
        'evaluaciones' => $evaluaciones,
        'evaluaciones_clinicas' => $clinicas,
        'certificados' => $certificados,
    ];

    return dpBuildSystemResult(
        $catalog,
        'pacientes, evaluaciones, evaluaciones_clinicas, certificados',
        ($paciente ? 1 : 0) + count($evaluaciones) + count($clinicas) + count($certificados),
        ['RUT', 'nombre', 'cargo', 'telefono', 'email', 'datos clinicos', 'anamnesis', 'certificado'],
        $sample,
        ['observaciones' => 'Sistema con datos sensibles de salud.']
    );
}

function dpSearchForms(string $rut, array $catalog): array
{
    $pdo = dpExternalDb('noeticac_form2');
    $rutNorm = dpNormalizeRut($rut);

    $stmt = $pdo->prepare("SELECT s.id AS submission_id, s.submitted_at, c.title AS campana,
            q.label, COALESCE(sa.value_text, '') AS value_text, sa.value_file_path, sa.original_file_name
        FROM submission_answers sa
        INNER JOIN questions q ON q.id = sa.question_id
        INNER JOIN submissions s ON s.id = sa.submission_id
        INNER JOIN campaigns c ON c.id = s.campaign_id
        WHERE REPLACE(REPLACE(REPLACE(UPPER(COALESCE(sa.value_text, '')), '.', ''), '-', ''), ' ', '') = :rut
          AND UPPER(q.label) REGEXP 'RUT'
        ORDER BY s.submitted_at DESC, s.id DESC
        LIMIT 100");
    $stmt->execute([':rut' => $rutNorm]);
    $matches = $stmt->fetchAll();

    $submissionIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['submission_id'], $matches)));
    if ($submissionIds === []) {
        return dpBuildSystemResult(
            $catalog,
            'campaigns, questions, submissions, submission_answers',
            0,
            ['RUT', 'nombre', 'apellidos', 'empresa', 'cargo'],
            []
        );
    }

    $placeholders = implode(', ', array_fill(0, count($submissionIds), '?'));
    $stmtAnswers = $pdo->prepare("SELECT s.id AS submission_id, s.submitted_at, c.title AS campana,
            q.label, COALESCE(sa.value_text, '') AS value_text, sa.value_file_path, sa.original_file_name
        FROM submissions s
        INNER JOIN campaigns c ON c.id = s.campaign_id
        INNER JOIN submission_answers sa ON sa.submission_id = s.id
        INNER JOIN questions q ON q.id = sa.question_id
        WHERE s.id IN ($placeholders)
        ORDER BY s.submitted_at DESC, s.id DESC, q.position ASC, q.id ASC");
    $stmtAnswers->execute($submissionIds);

    $grouped = [];
    foreach ($stmtAnswers->fetchAll() as $row) {
        $submissionId = (int)$row['submission_id'];
        if (!isset($grouped[$submissionId])) {
            $grouped[$submissionId] = [
                'submission_id' => $submissionId,
                'submitted_at' => $row['submitted_at'],
                'campana' => $row['campana'],
                'datos' => [],
            ];
        }
        $label = trim((string)$row['label']);
        $value = trim((string)$row['value_text']);
        if ($label !== '' && $value !== '') {
            $grouped[$submissionId]['datos'][$label] = $value;
        }
        if (!empty($row['value_file_path'])) {
            $grouped[$submissionId]['datos'][$label . ' archivo'] = $row['value_file_path'];
        }
    }

    return dpBuildSystemResult(
        $catalog,
        'campaigns, questions, submissions, submission_answers',
        count($grouped),
        ['RUT', 'nombre', 'apellidos', 'empresa', 'cargo'],
        array_values($grouped)
    );
}

function dpSearchFeedback(array $catalog): array
{
    return dpBuildSystemResult(
        $catalog,
        'fb_respuestas, fb_respuestas_detalle, fb_usuarios',
        0,
        ['comentarios', 'usuarios internos'],
        [],
        ['observaciones' => 'Sistema revisado sin cruce por RUT en el modelo actual.']
    );
}

function dpRunUnifiedSearch(string $rut): array
{
    $catalog = dpCatalogByKey(dpCeoDb());

    $searches = [
        'ceo' => static fn(): array => dpSearchCeo($rut, $catalog['ceo']),
        'salud' => static fn(): array => dpSearchSalud($rut, $catalog['salud']),
        'forms' => static fn(): array => dpSearchForms($rut, $catalog['forms']),
        'feedback' => static fn(): array => dpSearchFeedback($catalog['feedback']),
    ];

    $results = [];
    foreach ($searches as $key => $resolver) {
        try {
            $results[] = $resolver();
        } catch (Throwable $e) {
            $system = $catalog[$key];
            $results[] = dpBuildSystemResult(
                $system,
                'conexion fallida',
                0,
                [],
                [],
                ['observaciones' => 'No fue posible consultar este sistema: ' . $e->getMessage()]
            );
        }
    }

    return $results;
}

function dpCreateSolicitudAndSearch(array $input): array
{
    $pdo = dpCeoDb();
    dpEnsureTables($pdo);

    $rut = trim((string)($input['rut'] ?? ''));
    $tipo = trim((string)($input['tipo_solicitud'] ?? 'ACCESO'));
    $detalle = trim((string)($input['detalle_solicitud'] ?? ''));
    $motivo = trim((string)($input['motivo_interno'] ?? 'Solicitud externa de datos personales'));
    $rutNorm = dpNormalizeRut($rut);

    if ($rut === '' || $rutNorm === '') {
        throw new RuntimeException('Debe ingresar un RUT valido.');
    }

    $usuario = $_SESSION['auth'] ?? [];
    $folio = dpCreateFolio($pdo);
    $results = dpRunUnifiedSearch($rut);

    $summary = [
        'sistemas_consultados' => count($results),
        'sistemas_con_hallazgo' => count(array_filter($results, static fn(array $row): bool => !empty($row['encontrado']))),
        'total_registros' => array_sum(array_map(static fn(array $row): int => (int)$row['total_registros'], $results)),
    ];

    $pdo->beginTransaction();

    $stmtSolicitud = $pdo->prepare("INSERT INTO ceo_dp_solicitud
        (folio, rut_titular, rut_normalizado, tipo_solicitud, canal, detalle_solicitud, estado,
         usuario_id, usuario_codigo, usuario_nombre, fecha_recepcion)
        VALUES
        (:folio, :rut_titular, :rut_normalizado, :tipo_solicitud, 'EXTERNO', :detalle_solicitud, 'ABIERTA',
         :usuario_id, :usuario_codigo, :usuario_nombre, NOW())");
    $stmtSolicitud->execute([
        ':folio' => $folio,
        ':rut_titular' => $rut,
        ':rut_normalizado' => $rutNorm,
        ':tipo_solicitud' => $tipo,
        ':detalle_solicitud' => $detalle,
        ':usuario_id' => $usuario['id'] ?? null,
        ':usuario_codigo' => $usuario['codigo'] ?? '',
        ':usuario_nombre' => $usuario['nombre'] ?? '',
    ]);
    $solicitudId = (int)$pdo->lastInsertId();

    $stmtConsulta = $pdo->prepare("INSERT INTO ceo_dp_consulta
        (solicitud_id, rut_consultado, rut_normalizado, motivo, usuario_id, usuario_codigo, usuario_nombre, resumen_json, created_at)
        VALUES
        (:solicitud_id, :rut_consultado, :rut_normalizado, :motivo, :usuario_id, :usuario_codigo, :usuario_nombre, :resumen_json, NOW())");
    $stmtConsulta->execute([
        ':solicitud_id' => $solicitudId,
        ':rut_consultado' => $rut,
        ':rut_normalizado' => $rutNorm,
        ':motivo' => $motivo,
        ':usuario_id' => $usuario['id'] ?? null,
        ':usuario_codigo' => $usuario['codigo'] ?? '',
        ':usuario_nombre' => $usuario['nombre'] ?? '',
        ':resumen_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $consultaId = (int)$pdo->lastInsertId();

    $stmtSistema = $pdo->prepare("INSERT INTO ceo_dp_consulta_sistema
        (consulta_id, sistema_key, sistema_nombre, base_datos, fuente, encontrado, total_registros,
         datos_resumen, finalidad, conservacion, contiene_datos_sensibles, created_at)
        VALUES
        (:consulta_id, :sistema_key, :sistema_nombre, :base_datos, :fuente, :encontrado, :total_registros,
         :datos_resumen, :finalidad, :conservacion, :contiene_datos_sensibles, NOW())");

    foreach ($results as $result) {
        $stmtSistema->execute([
            ':consulta_id' => $consultaId,
            ':sistema_key' => $result['sistema_key'],
            ':sistema_nombre' => $result['sistema_nombre'],
            ':base_datos' => $result['base_datos'],
            ':fuente' => $result['fuente'],
            ':encontrado' => !empty($result['encontrado']) ? 1 : 0,
            ':total_registros' => (int)$result['total_registros'],
            ':datos_resumen' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':finalidad' => $result['finalidad'],
            ':conservacion' => $result['conservacion'],
            ':contiene_datos_sensibles' => !empty($result['contiene_datos_sensibles']) ? 1 : 0,
        ]);
    }

    $pdo->commit();

    auditLog('DP_QUERY', 'ceo_dp_solicitud', $solicitudId, [
        'folio' => $folio,
        'rut' => $rut,
        'tipo_solicitud' => $tipo,
        'sistemas_con_hallazgo' => $summary['sistemas_con_hallazgo'],
        'total_registros' => $summary['total_registros'],
    ]);

    return [
        'solicitud_id' => $solicitudId,
        'consulta_id' => $consultaId,
        'folio' => $folio,
        'results' => $results,
        'summary' => $summary,
    ];
}

function dpRecentSolicitudes(PDO $pdo, int $limit = 20): array
{
    dpEnsureTables($pdo);
    $stmt = $pdo->prepare("SELECT s.*, c.id AS consulta_id,
            JSON_UNQUOTE(JSON_EXTRACT(c.resumen_json, '$.sistemas_con_hallazgo')) AS sistemas_con_hallazgo,
            JSON_UNQUOTE(JSON_EXTRACT(c.resumen_json, '$.total_registros')) AS total_registros
        FROM ceo_dp_solicitud s
        LEFT JOIN ceo_dp_consulta c ON c.solicitud_id = s.id
        ORDER BY s.fecha_recepcion DESC, s.id DESC
        LIMIT :lim");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function dpConsultaSistemaRows(PDO $pdo, int $consultaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM ceo_dp_consulta_sistema WHERE consulta_id = :id ORDER BY id ASC');
    $stmt->execute([':id' => $consultaId]);
    return $stmt->fetchAll();
}

function dpSolicitudWithConsulta(PDO $pdo, int $solicitudId): ?array
{
    $stmt = $pdo->prepare("SELECT s.*, c.id AS consulta_id, c.motivo, c.resumen_json
        FROM ceo_dp_solicitud s
        LEFT JOIN ceo_dp_consulta c ON c.solicitud_id = s.id
        WHERE s.id = :id
        ORDER BY c.id DESC
        LIMIT 1");
    $stmt->execute([':id' => $solicitudId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dpConsultaDecodedRows(PDO $pdo, int $consultaId): array
{
    $rows = dpConsultaSistemaRows($pdo, $consultaId);
    foreach ($rows as &$row) {
        $row['decoded'] = json_decode((string)$row['datos_resumen'], true);
        if (!is_array($row['decoded'])) {
            $row['decoded'] = [];
        }
    }
    unset($row);
    return $rows;
}

function dpLogoDataUri(): string
{
    $path = app_path_to_filesystem(APP_LOGO);
    if (!is_file($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    if ($content === false) {
        return '';
    }

    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        default => 'image/png',
    };

    return 'data:' . $mime . ';base64,' . base64_encode($content);
}

function dpRenderFormalDocument(array $solicitud, array $summary, array $detalle, bool $includeTechDetails = true, bool $forPdf = false): string
{
    $sistemasSensibles = array_values(array_filter($detalle, static fn(array $row): bool => (int)($row['contiene_datos_sensibles'] ?? 0) === 1));
    $logo = dpLogoDataUri();
    $fechaGeneracion = date('d-m-Y H:i');

    ob_start();
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Informe de datos del titular</title>
  <style>
    @page { size: letter portrait; margin: 18mm 14mm 20mm 14mm; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color:#0f172a; font-size:12px; margin:0; }
    .sheet { width:100%; }
    .topband { height:10px; background:#1d4ed8; border-radius:10px 10px 0 0; margin-bottom:14px; }
    .header { border:1px solid #dbe5f2; border-top:0; border-radius:0 0 14px 14px; padding:14px 16px 12px; margin-bottom:16px; background:#f8fbff; }
    .header-table { width:100%; border-collapse:collapse; }
    .header-table td { vertical-align:top; }
    .logo-box { width:88px; }
    .logo { max-width:78px; max-height:56px; }
    .title { font-size:20px; font-weight:700; margin:0 0 4px; }
    .subtitle { color:#475569; font-size:11px; }
    .folio-box { text-align:right; }
    .label { font-size:10px; color:#64748b; text-transform:uppercase; }
    .value { font-size:12px; font-weight:700; }
    .meta { width:100%; border-collapse:collapse; margin:0 0 14px; }
    .meta td { width:25%; padding:4px 6px 8px 0; vertical-align:top; }
    .section { margin-bottom:16px; }
    .section-title { font-size:14px; font-weight:700; margin:0 0 8px; }
    .box { border:1px solid #d9e2ef; border-radius:10px; padding:10px 12px; background:#fff; }
    .summary { background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 12px; }
    .kpi-table { width:100%; border-collapse:separate; border-spacing:8px 0; margin-top:10px; }
    .kpi { border:1px solid #d9e2ef; border-radius:10px; padding:10px 12px; background:#fff; }
    .kpi-value { font-size:22px; font-weight:700; margin-top:4px; }
    .systems { width:100%; border-collapse:collapse; }
    .systems th, .systems td { border:1px solid #d9e2ef; padding:8px; vertical-align:top; }
    .systems th { background:#eef4ff; text-align:left; font-size:11px; color:#1e3a8a; }
    .badge { display:inline-block; padding:3px 7px; border-radius:999px; font-size:10px; font-weight:700; }
    .badge-success { background:#dcfce7; color:#166534; }
    .badge-secondary { background:#e2e8f0; color:#334155; }
    .badge-warning { background:#fef3c7; color:#92400e; }
    .badge-danger { background:#fee2e2; color:#991b1b; }
    .system-card { border:1px solid #d9e2ef; border-radius:10px; padding:12px; margin-bottom:12px; page-break-inside:avoid; }
    .signature { margin-top:18px; border-top:1px solid #cbd5e1; padding-top:12px; page-break-inside:avoid; }
    .signature-line { margin-top:28px; width:260px; border-top:1px solid #64748b; }
    .footer { margin-top:18px; padding-top:8px; border-top:1px solid #d9e2ef; color:#64748b; font-size:10px; }
    .footer-table { width:100%; border-collapse:collapse; }
    .footer-right { text-align:right; }
    .muted { color:#64748b; }
    .small { font-size:10px; }
    .mono { font-family: DejaVu Sans Mono, monospace; font-size:10px; white-space:pre-wrap; word-break:break-word; }
    .mb-0 { margin-bottom:0; }
    .mb-1 { margin-bottom:4px; }
    .mb-2 { margin-bottom:8px; }
    .mb-3 { margin-bottom:12px; }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="topband"></div>
    <div class="header">
      <table class="header-table">
        <tr>
          <td class="logo-box">
            <?php if ($logo !== ''): ?>
              <img class="logo" src="<?= esc($logo) ?>" alt="Logo">
            <?php endif; ?>
          </td>
          <td>
            <div class="title">Informe de Datos del Titular</div>
            <div class="subtitle">Modulo Datos Personales · <?= esc(APP_NAME) ?> · Centro de Excelencia Operacional</div>
          </td>
          <td class="folio-box">
            <div class="label">Folio</div>
            <div class="value"><?= esc((string)$solicitud['folio']) ?></div>
            <div class="label" style="margin-top:6px;">Fecha de emision</div>
            <div class="small"><?= esc($fechaGeneracion) ?></div>
          </td>
        </tr>
      </table>
    </div>

    <table class="meta">
      <tr>
        <td><div class="label">RUT titular</div><div class="value"><?= esc((string)$solicitud['rut_titular']) ?></div></td>
        <td><div class="label">Tipo de solicitud</div><div class="value"><?= esc(dpTipoSolicitudLabel((string)$solicitud['tipo_solicitud'])) ?></div></td>
        <td><div class="label">Fecha de recepcion</div><div class="value"><?= esc((string)$solicitud['fecha_recepcion']) ?></div></td>
        <td><div class="label">Responsable interno</div><div class="value"><?= esc((string)$solicitud['usuario_nombre']) ?></div></td>
      </tr>
    </table>

    <div class="section">
      <div class="section-title">Solicitud externa</div>
      <div class="box"><?= nl2br(esc((string)($solicitud['detalle_solicitud'] ?? 'Sin detalle informado.'))) ?></div>
    </div>

    <div class="section">
      <div class="section-title">Resumen ejecutivo</div>
      <div class="summary">
        <?= esc(dpSummaryNarrative($summary)) ?>
        <?php if ($sistemasSensibles !== []): ?>
          Se identificaron datos sensibles en <?= count($sistemasSensibles) ?> sistema(s).
        <?php endif; ?>
      </div>
      <table class="kpi-table">
        <tr>
          <td class="kpi"><div class="label">Sistemas consultados</div><div class="kpi-value"><?= (int)($summary['sistemas_consultados'] ?? 0) ?></div></td>
          <td class="kpi"><div class="label">Sistemas con datos</div><div class="kpi-value"><?= (int)($summary['sistemas_con_hallazgo'] ?? 0) ?></div></td>
          <td class="kpi"><div class="label">Registros muestreados</div><div class="kpi-value"><?= (int)($summary['total_registros'] ?? 0) ?></div></td>
        </tr>
      </table>
    </div>

    <div class="section">
      <div class="section-title">Tratamiento por sistema</div>
      <table class="systems">
        <thead>
          <tr>
            <th>Sistema</th>
            <th>Estado</th>
            <th>Datos encontrados</th>
            <th>Finalidad</th>
            <th>Fechas registradas</th>
            <th>Conservacion</th>
            <th>Sensibles</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($detalle as $row): ?>
          <?php $decoded = $row['decoded']; $status = dpSystemStatus($decoded); ?>
          <tr>
            <td><strong><?= esc((string)$row['sistema_nombre']) ?></strong><br><span class="muted small"><?= esc((string)$row['base_datos']) ?></span></td>
            <td>
              <span class="badge <?= $status['class'] === 'text-bg-success' ? 'badge-success' : ($status['class'] === 'text-bg-warning' ? 'badge-warning' : 'badge-secondary') ?>">
                <?= esc($status['label']) ?>
              </span>
            </td>
            <td><?= esc(dpSystemCategoriesText($decoded)) ?></td>
            <td><?= esc((string)$row['finalidad']) ?></td>
            <td><?= esc(dpSystemDatesText($decoded)) ?></td>
            <td><?= esc((string)$row['conservacion']) ?></td>
            <td><?= (int)$row['contiene_datos_sensibles'] === 1 ? 'Si' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php foreach ($detalle as $row): ?>
      <?php $decoded = $row['decoded']; $status = dpSystemStatus($decoded); ?>
      <div class="section system-card">
        <div class="mb-2">
          <strong><?= esc((string)$row['sistema_nombre']) ?></strong>
          <?php if ((int)$row['contiene_datos_sensibles'] === 1): ?>
            <span class="badge badge-danger">Datos sensibles</span>
          <?php endif; ?>
          <span class="badge <?= $status['class'] === 'text-bg-success' ? 'badge-success' : ($status['class'] === 'text-bg-warning' ? 'badge-warning' : 'badge-secondary') ?>">
            <?= esc($status['label']) ?>
          </span>
          <div class="muted small">Fuente consultada: <?= esc((string)$row['fuente']) ?></div>
        </div>
        <div class="mb-1"><strong>Datos encontrados:</strong> <?= esc(dpSystemCategoriesText($decoded)) ?></div>
        <div class="mb-1"><strong>Concepto en el sistema:</strong> <?= esc(dpSystemConceptText($decoded)) ?></div>
        <div class="mb-1"><strong>Fechas registradas:</strong> <?= esc(dpSystemDatesText($decoded)) ?></div>
        <div class="mb-1"><strong>Finalidad:</strong> <?= esc((string)$row['finalidad']) ?></div>
        <div class="mb-1"><strong>Conservacion:</strong> <?= esc((string)$row['conservacion']) ?></div>
        <?php if (trim((string)($decoded['observaciones'] ?? '')) !== ''): ?>
          <div class="mb-2"><strong>Observacion:</strong> <?= esc((string)$decoded['observaciones']) ?></div>
        <?php endif; ?>
        <?php if ($includeTechDetails): ?>
          <div class="mb-1"><strong>Respaldo tecnico:</strong></div>
          <div class="mono"><?= esc(json_encode($decoded['muestra'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="signature">
      <div><strong>Emision interna del informe</strong></div>
      <div class="muted">Documento generado por el modulo de datos personales para responder solicitudes externas del titular.</div>
      <div class="signature-line"></div>
      <div><strong><?= esc((string)$solicitud['usuario_nombre']) ?></strong></div>
      <div class="small">Responsable interno · <?= esc(APP_NAME) ?></div>
    </div>

    <div class="footer">
      <table class="footer-table">
        <tr>
          <td><?= esc(APP_NAME) ?> · Tratamiento de datos personales</td>
          <td class="footer-right">Conservacion declarada: 10 anos desde la fecha del registro</td>
        </tr>
      </table>
    </div>
  </div>
  <?php if ($forPdf): ?>
  <script type="text/php">
  if (isset($pdf)) {
      $font = $fontMetrics->getFont("Helvetica", "normal");
      $size = 9;
      $y = $pdf->get_height() - 24;
      $pdf->page_text(34, $y, "Documento emitido por <?= addslashes(APP_NAME) ?>", $font, $size, [0.39, 0.45, 0.53]);
      $pdf->page_text($pdf->get_width() - 110, $y, "Pagina {PAGE_NUM} de {PAGE_COUNT}", $font, $size, [0.39, 0.45, 0.53]);
  }
  </script>
  <?php endif; ?>
</body>
</html>
    <?php

    return (string)ob_get_clean();
}
