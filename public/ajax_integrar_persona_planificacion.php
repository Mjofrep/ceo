<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');

function integrarJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function integrarRutKey(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', trim($rut)));
}

function integrarRutComparableSql(string $field): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER({$field}), '.', ''), '-', ''), ' ', '')";
}

function integrarHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
        LIMIT 1
    ');
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function integrarNormalizarNombre(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function integrarFetchPlanificacion(PDO $pdo, string $tipo, int $cuadrilla, array $sessionAuth): array
{
    if ($cuadrilla <= 0) {
        throw new RuntimeException('Debe seleccionar una cuadrilla.');
    }

    if ($tipo === 'formacion') {
        $stmt = $pdo->prepare('
            SELECT
                f.id,
                f.cuadrilla,
                f.empresa,
                f.uo,
                f.id_servicio,
                f.id_agrupacion,
                f.estado,
                e.nombre AS empresa_nombre,
                u.desc_uo AS uo_nombre,
                s.servicio AS servicio_nombre
            FROM ceo_formacion f
            LEFT JOIN ceo_empresas e ON e.id = f.empresa
            LEFT JOIN ceo_uo u ON u.id = f.uo
            LEFT JOIN ceo_formacion_servicios s ON s.id = f.id_servicio
            WHERE f.cuadrilla = :cuadrilla
            ORDER BY f.id DESC
            LIMIT 1
        ');
    } else {
        $stmt = $pdo->prepare('
            SELECT
                h.id,
                h.cuadrilla,
                h.empresa,
                h.uo,
                h.id_servicio,
                h.estado,
                e.nombre AS empresa_nombre,
                u.desc_uo AS uo_nombre,
                s.servicio AS servicio_nombre
            FROM ceo_habilitacion h
            LEFT JOIN ceo_empresas e ON e.id = h.empresa
            LEFT JOIN ceo_uo u ON u.id = h.uo
            LEFT JOIN ceo_servicios_pruebas s ON s.id = h.id_servicio
            WHERE h.cuadrilla = :cuadrilla
            ORDER BY h.id DESC
            LIMIT 1
        ');
    }

    $stmt->execute([':cuadrilla' => $cuadrilla]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('La cuadrilla seleccionada no existe.');
    }

    $empresaPlan = (int)($row['empresa'] ?? 0);
    if (
        strtolower((string)($sessionAuth['rol'] ?? '')) === 'contratista' &&
        $empresaPlan > 0 &&
        $empresaPlan !== (int)($sessionAuth['id_empresa'] ?? 0)
    ) {
        throw new RuntimeException('No puedes operar cuadrillas de otra empresa.');
    }

    $row['tipo'] = $tipo;
    return $row;
}

function integrarFetchPersona(PDO $pdo, string $rut): ?array
{
    $stmt = $pdo->prepare('
        SELECT
            c.rut,
            c.nombre,
            c.apellidos,
            c.id_cargo,
            c.id_empresa,
            c.uo,
            cc.cargo AS cargo_nombre,
            e.nombre AS empresa_nombre,
            u.desc_uo AS uo_nombre
        FROM ceo_contratistas c
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
        LEFT JOIN ceo_uo u ON u.id = c.uo
        WHERE ' . integrarRutComparableSql('c.rut') . ' = :rut_key
        LIMIT 1
    ');
    $stmt->execute([':rut_key' => integrarRutKey($rut)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function integrarFetchCargoContratista(PDO $pdo, int $idCargo): ?array
{
    if ($idCargo <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, cargo FROM ceo_cargo_contratistas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idCargo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function integrarResolveCargoHabilitacion(PDO $pdo, string $cargoNombre): ?int
{
    $cargoNorm = normalizarTextoCargoPonderacion($cargoNombre);
    if ($cargoNorm === '') {
        return null;
    }

    $queries = [
        'SELECT id FROM ceo_cargos_habilitacion WHERE TRIM(UPPER(cargo)) = :cargo LIMIT 1',
        'SELECT id FROM ceo_cargo_contratistas WHERE TRIM(UPPER(cargo)) = :cargo LIMIT 1',
    ];

    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cargo' => $cargoNorm]);
        $id = $stmt->fetchColumn();
        if ($id !== false && (int)$id > 0) {
            return (int)$id;
        }
    }

    return null;
}

function integrarEnsurePersona(PDO $pdo, string $rut, array $plan, array $data): array
{
    $persona = integrarFetchPersona($pdo, $rut);
    if ($persona !== null) {
        return $persona;
    }

    $nombre = integrarNormalizarNombre((string)($data['nombre'] ?? ''));
    $apellidos = integrarNormalizarNombre((string)($data['apellidos'] ?? ''));
    $idCargo = (int)($data['id_cargo'] ?? 0);

    if ($nombre === '' || $apellidos === '' || $idCargo <= 0) {
        throw new RuntimeException('Para crear la persona debes informar nombre, apellidos y cargo.');
    }

    $cargo = integrarFetchCargoContratista($pdo, $idCargo);
    if ($cargo === null) {
        throw new RuntimeException('El cargo seleccionado no existe.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO ceo_contratistas
            (rut, nombre, apellidos, correo, telefono, id_cargo, fecha_ingreso, id_empresa, uo)
        VALUES
            (:rut, :nombre, :apellidos, NULL, NULL, :id_cargo, CURDATE(), :id_empresa, :uo)
    ');
    $stmt->execute([
        ':rut' => trim($rut),
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':id_cargo' => $idCargo,
        ':id_empresa' => (int)($plan['empresa'] ?? 0) ?: null,
        ':uo' => (int)($plan['uo'] ?? 0) ?: null,
    ]);

    $persona = integrarFetchPersona($pdo, $rut);
    if ($persona === null) {
        throw new RuntimeException('No fue posible crear la persona.');
    }

    return $persona;
}

function integrarEnsureParticipanteFormacion(PDO $pdo, array $plan, array $persona, string $rut): void
{
    $rutKey = integrarRutKey($rut);
    $nombre = integrarNormalizarNombre((string)($persona['nombre'] ?? ''));
    $apellidos = integrarNormalizarNombre((string)($persona['apellidos'] ?? ''));
    $cargo = integrarNormalizarNombre((string)($persona['cargo_nombre'] ?? ''));

    $stmtExiste = $pdo->prepare('
        SELECT 1
        FROM ceo_formacion_participantes
        WHERE id_cuadrilla = :cuadrilla
          AND ' . integrarRutComparableSql('rut') . ' = :rut_key
        LIMIT 1
    ');
    $stmtExiste->execute([
        ':cuadrilla' => (int)$plan['cuadrilla'],
        ':rut_key' => $rutKey,
    ]);

    if ($stmtExiste->fetchColumn()) {
        $stmtUpd = $pdo->prepare('
            UPDATE ceo_formacion_participantes
            SET rut = :rut,
                nombre = :nombre,
                apellidos = :apellidos,
                cargo = :cargo
            WHERE id_cuadrilla = :cuadrilla
              AND ' . integrarRutComparableSql('rut') . ' = :rut_key
        ');
        $stmtUpd->execute([
            ':rut' => trim($rut),
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => $cargo,
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut_key' => $rutKey,
        ]);
    } else {
        $stmtIns = $pdo->prepare('
            INSERT INTO ceo_formacion_participantes
                (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo)
            VALUES
                (:cuadrilla, 0, :rut, :nombre, :apellidos, :cargo)
        ');
        $stmtIns->execute([
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut' => trim($rut),
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => $cargo,
        ]);
    }

    $stmtBase = $pdo->prepare('
        INSERT INTO ceo_formacion_personas
            (id_formacion, rut, nombre, apellidos, cargo, tipo_participacion, estado)
        VALUES
            (:id_formacion, :rut, :nombre, :apellidos, :cargo, :tipo, :estado)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            apellidos = VALUES(apellidos),
            cargo = VALUES(cargo),
            estado = VALUES(estado)
    ');
    $stmtBase->execute([
        ':id_formacion' => (int)$plan['id'],
        ':rut' => trim($rut),
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':cargo' => $cargo,
        ':tipo' => 'NO_EVALUA',
        ':estado' => 'ACTIVO',
    ]);
}

function integrarEnsureParticipanteHabilitacion(PDO $pdo, array $plan, array $persona, string $rut): void
{
    $rutKey = integrarRutKey($rut);
    $nombre = integrarNormalizarNombre((string)($persona['nombre'] ?? ''));
    $apellidos = integrarNormalizarNombre((string)($persona['apellidos'] ?? ''));
    $cargo = integrarNormalizarNombre((string)($persona['cargo_nombre'] ?? ''));

    $stmtExiste = $pdo->prepare('
        SELECT 1
        FROM ceo_habilitacion_participantes
        WHERE id_cuadrilla = :cuadrilla
          AND ' . integrarRutComparableSql('rut') . ' = :rut_key
        LIMIT 1
    ');
    $stmtExiste->execute([
        ':cuadrilla' => (int)$plan['cuadrilla'],
        ':rut_key' => $rutKey,
    ]);

    if ($stmtExiste->fetchColumn()) {
        $stmtUpd = $pdo->prepare('
            UPDATE ceo_habilitacion_participantes
            SET rut = :rut,
                nombre = :nombre,
                apellidos = :apellidos,
                cargo = :cargo
            WHERE id_cuadrilla = :cuadrilla
              AND ' . integrarRutComparableSql('rut') . ' = :rut_key
        ');
        $stmtUpd->execute([
            ':rut' => trim($rut),
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => $cargo,
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut_key' => $rutKey,
        ]);
    } else {
        $stmtIns = $pdo->prepare('
            INSERT INTO ceo_habilitacion_participantes
                (id_cuadrilla, reevaluo, rut, nombre, apellidos, cargo)
            VALUES
                (:cuadrilla, 0, :rut, :nombre, :apellidos, :cargo)
        ');
        $stmtIns->execute([
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut' => trim($rut),
            ':nombre' => $nombre,
            ':apellidos' => $apellidos,
            ':cargo' => $cargo,
        ]);
    }

    $stmtBase = $pdo->prepare('
        INSERT INTO ceo_habilitacion_personas
            (id_habilitacion, rut, nombre, apellidos, cargo, tipo_participacion, estado)
        VALUES
            (:id_habilitacion, :rut, :nombre, :apellidos, :cargo, :tipo, :estado)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            apellidos = VALUES(apellidos),
            cargo = VALUES(cargo),
            estado = VALUES(estado)
    ');
    $stmtBase->execute([
        ':id_habilitacion' => (int)$plan['id'],
        ':rut' => trim($rut),
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':cargo' => $cargo,
        ':tipo' => 'NO_EVALUA',
        ':estado' => 'ACTIVO',
    ]);
}

function integrarEnsureServicioRut(PDO $pdo, string $rut, int $idCargoHabilitacion, int $idServicio): void
{
    if ($idCargoHabilitacion <= 0 || $idServicio <= 0) {
        return;
    }

    $stmtExiste = $pdo->prepare('
        SELECT 1
        FROM ceo_servicios_rut
        WHERE rut = :rut
          AND id_cargo = :id_cargo
          AND id_servicio = :id_servicio
        LIMIT 1
    ');
    $stmtExiste->execute([
        ':rut' => trim($rut),
        ':id_cargo' => $idCargoHabilitacion,
        ':id_servicio' => $idServicio,
    ]);

    if ($stmtExiste->fetchColumn()) {
        return;
    }

    $stmtIns = $pdo->prepare('
        INSERT INTO ceo_servicios_rut (id_cargo, id_servicio, otro, rut)
        VALUES (:id_cargo, :id_servicio, 0, :rut)
    ');
    $stmtIns->execute([
        ':id_cargo' => $idCargoHabilitacion,
        ':id_servicio' => $idServicio,
        ':rut' => trim($rut),
    ]);
}

function integrarBuildProgramadaFormacionFilter(array $plan, bool $withAlias = false): array
{
    $prefix = $withAlias ? 'fp.' : '';
    $where = [
        $prefix . 'id_servicio = :id_servicio',
        $prefix . 'cuadrilla = :cuadrilla',
        $prefix . 'tipo = "PRUEBA"',
    ];
    $params = [
        ':id_servicio' => (int)$plan['id_servicio'],
        ':cuadrilla' => (int)$plan['cuadrilla'],
    ];

    if ((int)($plan['id_agrupacion'] ?? 0) > 0) {
        $where[] = $prefix . 'id_agrupacion = :id_agrupacion';
        $params[':id_agrupacion'] = (int)$plan['id_agrupacion'];
    }

    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

function integrarProgramarFormacion(PDO $pdo, array $plan, string $rut, int $userId): array
{
    $rutKey = integrarRutKey($rut);
    $filter = integrarBuildProgramadaFormacionFilter($plan);

    $stmtEjecutada = $pdo->prepare('
        SELECT id, estado, resultado, fecha_programacion
        FROM ceo_formacion_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
          AND estado = "EJECUTADA"
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtEjecutada->execute([':rut_key' => $rutKey] + $filter['params']);
    $ejecutada = $stmtEjecutada->fetch(PDO::FETCH_ASSOC);
    if ($ejecutada) {
        throw new RuntimeException('La persona ya rindió esta misma prueba de formación.');
    }

    $stmtRegistro = $pdo->prepare('
        SELECT id, estado, resultado
        FROM ceo_formacion_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtRegistro->execute([':rut_key' => $rutKey] + $filter['params']);
    $registro = $stmtRegistro->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        $stmtUpd = $pdo->prepare('
            UPDATE ceo_formacion_programadas
            SET estado = "PENDIENTE",
                resultado = "PENDIENTE",
                fecha_resultado = NULL,
                id_agrupacion = :id_agrupacion,
                usuario_programa = :usuario,
                fecha_programacion = NOW(),
                cobrado = 0
            WHERE id = :id
            LIMIT 1
        ');
        $stmtUpd->execute([
            ':id_agrupacion' => (int)($plan['id_agrupacion'] ?? 0) ?: null,
            ':usuario' => $userId > 0 ? $userId : 1,
            ':id' => (int)$registro['id'],
        ]);

        return [
            'accion' => strtoupper(trim((string)($registro['estado'] ?? ''))) === 'PENDIENTE' ? 'ya_pendiente' : 'reactivada',
            'programacion_id' => (int)$registro['id'],
        ];
    }

    $stmtIns = $pdo->prepare('
        INSERT INTO ceo_formacion_programadas
            (rut, id_servicio, id_agrupacion, tipo, cuadrilla, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
        VALUES
            (:rut, :id_servicio, :id_agrupacion, "PRUEBA", :cuadrilla, NOW(), :usuario, "PENDIENTE", 1, "PENDIENTE", NULL, 0)
    ');
    $stmtIns->execute([
        ':rut' => trim($rut),
        ':id_servicio' => (int)$plan['id_servicio'],
        ':id_agrupacion' => (int)($plan['id_agrupacion'] ?? 0) ?: null,
        ':cuadrilla' => (int)$plan['cuadrilla'],
        ':usuario' => $userId > 0 ? $userId : 1,
    ]);

    return [
        'accion' => 'creada',
        'programacion_id' => (int)$pdo->lastInsertId(),
    ];
}

function integrarAnularFormacion(PDO $pdo, array $plan, string $rut): array
{
    $rutKey = integrarRutKey($rut);
    $filter = integrarBuildProgramadaFormacionFilter($plan);
    $stmt = $pdo->prepare('
        SELECT id, estado
        FROM ceo_formacion_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
          AND estado <> "EJECUTADA"
        ORDER BY CASE WHEN estado = "PENDIENTE" THEN 0 ELSE 1 END, id DESC
        LIMIT 1
    ');
    $stmt->execute([':rut_key' => $rutKey] + $filter['params']);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registro) {
        throw new RuntimeException('No existe una prueba de formación pendiente o anulable para esta cuadrilla.');
    }

    $stmtUpd = $pdo->prepare('UPDATE ceo_formacion_programadas SET estado = "ANULADA" WHERE id = :id LIMIT 1');
    $stmtUpd->execute([':id' => (int)$registro['id']]);

    return [
        'accion' => 'anulada',
        'programacion_id' => (int)$registro['id'],
    ];
}

function integrarBuildEvaluacionHabilitacionFilter(PDO $pdo, array $plan, bool $withAlias = false): array
{
    $prefix = $withAlias ? 'ep.' : '';
    $where = [
        $prefix . 'id_servicio = :id_servicio',
        $prefix . 'cuadrilla = :cuadrilla',
        $prefix . 'tipo = "PRUEBA"',
    ];
    $params = [
        ':id_servicio' => (int)$plan['id_servicio'],
        ':cuadrilla' => (int)$plan['cuadrilla'],
    ];

    if (integrarHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion') && (int)($plan['id_agrupacion'] ?? 0) > 0) {
        $where[] = $prefix . 'id_agrupacion = :id_agrupacion';
        $params[':id_agrupacion'] = (int)$plan['id_agrupacion'];
    }

    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

function integrarFetchProcesosAbiertos(PDO $pdo, string $rut, int $idServicio): array
{
    if ($idServicio <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT
            ph.id,
            ph.numero_proceso,
            ph.id_servicio,
            ph.id_cargo,
            ph.estado,
            ph.fecha_inicio,
            sp.servicio,
            sp.descripcion,
            ch.cargo
        FROM ceo_proceso_habilitacion ph
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = ph.id_servicio
        LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = ph.id_cargo
        WHERE ' . integrarRutComparableSql('ph.rut') . ' = :rut_key
          AND ph.id_servicio = :id_servicio
          AND ph.estado = "ABIERTO"
        ORDER BY ph.numero_proceso DESC, ph.id DESC
    ');
    $stmt->execute([
        ':rut_key' => integrarRutKey($rut),
        ':id_servicio' => $idServicio,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function integrarResolverProcesoHabilitacion(PDO $pdo, array $plan, string $rut, string $cargoNombre, array $data): array
{
    $selectedId = (int)($data['id_proceso_habilitacion'] ?? 0);
    if ($selectedId > 0) {
        $stmt = $pdo->prepare('
            SELECT id, rut, id_servicio, id_cargo, numero_proceso, estado, origen, fecha_inicio, fecha_cierre
            FROM ceo_proceso_habilitacion
            WHERE id = :id
              AND ' . integrarRutComparableSql('rut') . ' = :rut_key
              AND id_servicio = :id_servicio
              AND estado = "ABIERTO"
            LIMIT 1
        ');
        $stmt->execute([
            ':id' => $selectedId,
            ':rut_key' => integrarRutKey($rut),
            ':id_servicio' => (int)$plan['id_servicio'],
        ]);
        $proceso = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$proceso) {
            throw new RuntimeException('El proceso de habilitación seleccionado no es válido.');
        }
        return $proceso;
    }

    $idCargo = obtenerCargoTrabajador($pdo, $rut, (int)$plan['id_servicio'], (int)$plan['cuadrilla']);
    if ($idCargo === null) {
        $idCargo = integrarResolveCargoHabilitacion($pdo, $cargoNombre) ?? 0;
    }

    if ($idCargo <= 0) {
        throw new RuntimeException('No se pudo resolver el cargo de habilitación para este trabajador.');
    }

    integrarEnsureServicioRut($pdo, trim($rut), $idCargo, (int)$plan['id_servicio']);

    $proceso = obtenerProcesoHabilitacionAbierto($pdo, trim($rut), (int)$plan['id_servicio'], $idCargo);
    if ($proceso !== null) {
        return $proceso;
    }

    return obtenerOCrearProcesoHabilitacion($pdo, trim($rut), (int)$plan['id_servicio'], 'CEONEXT', $idCargo);
}

function integrarProgramarHabilitacion(PDO $pdo, array $plan, string $rut, int $userId, array $data, string $cargoNombre): array
{
    $rutKey = integrarRutKey($rut);
    $filter = integrarBuildEvaluacionHabilitacionFilter($pdo, $plan);

    $stmtEjecutada = $pdo->prepare('
        SELECT id, estado, resultado
        FROM ceo_evaluaciones_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
          AND estado = "EJECUTADA"
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtEjecutada->execute([':rut_key' => $rutKey] + $filter['params']);
    $ejecutada = $stmtEjecutada->fetch(PDO::FETCH_ASSOC);
    if ($ejecutada) {
        throw new RuntimeException('La persona ya rindió esta misma prueba de habilitación.');
    }

    $proceso = integrarResolverProcesoHabilitacion($pdo, $plan, $rut, $cargoNombre, $data);
    $hasAgrupacion = integrarHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion');
    $idAgrupacion = $hasAgrupacion ? ((int)($data['id_agrupacion'] ?? 0) ?: ((int)($plan['id_agrupacion'] ?? 0) ?: null)) : null;

    $stmtRegistro = $pdo->prepare('
        SELECT id, estado, resultado
        FROM ceo_evaluaciones_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtRegistro->execute([':rut_key' => $rutKey] + $filter['params']);
    $registro = $stmtRegistro->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        if ($hasAgrupacion) {
            $stmtUpd = $pdo->prepare('
                UPDATE ceo_evaluaciones_programadas
                SET estado = "PENDIENTE",
                    resultado = "PENDIENTE",
                    fecha_resultado = NULL,
                    usuario_programa = :usuario,
                    fecha_programacion = NOW(),
                    id_proceso_habilitacion = :id_proceso,
                    id_agrupacion = :id_agrupacion,
                    cobrado = 0
                WHERE id = :id
                LIMIT 1
            ');
            $stmtUpd->execute([
                ':usuario' => $userId > 0 ? $userId : 1,
                ':id_proceso' => (int)$proceso['id'],
                ':id_agrupacion' => $idAgrupacion,
                ':id' => (int)$registro['id'],
            ]);
        } else {
            $stmtUpd = $pdo->prepare('
                UPDATE ceo_evaluaciones_programadas
                SET estado = "PENDIENTE",
                    resultado = "PENDIENTE",
                    fecha_resultado = NULL,
                    usuario_programa = :usuario,
                    fecha_programacion = NOW(),
                    id_proceso_habilitacion = :id_proceso,
                    cobrado = 0
                WHERE id = :id
                LIMIT 1
            ');
            $stmtUpd->execute([
                ':usuario' => $userId > 0 ? $userId : 1,
                ':id_proceso' => (int)$proceso['id'],
                ':id' => (int)$registro['id'],
            ]);
        }

        return [
            'accion' => strtoupper(trim((string)($registro['estado'] ?? ''))) === 'PENDIENTE' ? 'ya_pendiente' : 'reactivada',
            'programacion_id' => (int)$registro['id'],
            'proceso' => $proceso,
        ];
    }

    if ($hasAgrupacion) {
        $stmtIns = $pdo->prepare('
            INSERT INTO ceo_evaluaciones_programadas
                (rut, id_servicio, id_agrupacion, tipo, cuadrilla, id_proceso_habilitacion, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
            VALUES
                (:rut, :id_servicio, :id_agrupacion, "PRUEBA", :cuadrilla, :id_proceso, NOW(), :usuario, "PENDIENTE", 1, "PENDIENTE", NULL, 0)
        ');
        $stmtIns->execute([
            ':rut' => trim($rut),
            ':id_servicio' => (int)$plan['id_servicio'],
            ':id_agrupacion' => $idAgrupacion,
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':id_proceso' => (int)$proceso['id'],
            ':usuario' => $userId > 0 ? $userId : 1,
        ]);
    } else {
        $stmtIns = $pdo->prepare('
            INSERT INTO ceo_evaluaciones_programadas
                (rut, id_servicio, tipo, cuadrilla, id_proceso_habilitacion, fecha_programacion, usuario_programa, estado, intento, resultado, fecha_resultado, cobrado)
            VALUES
                (:rut, :id_servicio, "PRUEBA", :cuadrilla, :id_proceso, NOW(), :usuario, "PENDIENTE", 1, "PENDIENTE", NULL, 0)
        ');
        $stmtIns->execute([
            ':rut' => trim($rut),
            ':id_servicio' => (int)$plan['id_servicio'],
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':id_proceso' => (int)$proceso['id'],
            ':usuario' => $userId > 0 ? $userId : 1,
        ]);
    }

    return [
        'accion' => 'creada',
        'programacion_id' => (int)$pdo->lastInsertId(),
        'proceso' => $proceso,
    ];
}

function integrarAnularHabilitacion(PDO $pdo, array $plan, string $rut): array
{
    $rutKey = integrarRutKey($rut);
    $filter = integrarBuildEvaluacionHabilitacionFilter($pdo, $plan);
    $stmt = $pdo->prepare('
        SELECT id, estado
        FROM ceo_evaluaciones_programadas
        WHERE ' . integrarRutComparableSql('rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
          AND estado <> "EJECUTADA"
        ORDER BY CASE WHEN estado = "PENDIENTE" THEN 0 ELSE 1 END, id DESC
        LIMIT 1
    ');
    $stmt->execute([':rut_key' => $rutKey] + $filter['params']);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registro) {
        throw new RuntimeException('No existe una prueba de habilitación pendiente o anulable para esta cuadrilla.');
    }

    $stmtUpd = $pdo->prepare('UPDATE ceo_evaluaciones_programadas SET estado = "ANULADA" WHERE id = :id LIMIT 1');
    $stmtUpd->execute([':id' => (int)$registro['id']]);

    return [
        'accion' => 'anulada',
        'programacion_id' => (int)$registro['id'],
    ];
}

function integrarBuildPreview(PDO $pdo, array $plan, string $rut): array
{
    $persona = integrarFetchPersona($pdo, $rut);
    $rutKey = integrarRutKey($rut);

    if ($plan['tipo'] === 'formacion') {
        $stmtPart = $pdo->prepare('
            SELECT nombre, apellidos, cargo
            FROM ceo_formacion_participantes
            WHERE id_cuadrilla = :cuadrilla
              AND ' . integrarRutComparableSql('rut') . ' = :rut_key
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmtPart->execute([
            ':cuadrilla' => (int)$plan['cuadrilla'],
            ':rut_key' => $rutKey,
        ]);
        $participante = $stmtPart->fetch(PDO::FETCH_ASSOC) ?: null;

        $filter = integrarBuildProgramadaFormacionFilter($plan, true);
        $stmtProg = $pdo->prepare('
            SELECT id, estado, resultado, fecha_programacion, id_agrupacion
            FROM ceo_formacion_programadas fp
            WHERE ' . integrarRutComparableSql('fp.rut') . ' = :rut_key
              AND ' . $filter['sql'] . '
            ORDER BY fp.id DESC
            LIMIT 1
        ');
        $stmtProg->execute([':rut_key' => $rutKey] + $filter['params']);
        $programacion = $stmtProg->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmtEj = $pdo->prepare('
            SELECT 1
            FROM ceo_formacion_programadas fp
            WHERE ' . integrarRutComparableSql('fp.rut') . ' = :rut_key
              AND ' . $filter['sql'] . '
              AND fp.estado = "EJECUTADA"
            LIMIT 1
        ');
        $stmtEj->execute([':rut_key' => $rutKey] + $filter['params']);
        $ejecutada = (bool)$stmtEj->fetchColumn();

        return [
            'persona' => $persona,
            'participante' => $participante,
            'programacion' => $programacion,
            'ya_rindio_misma_prueba' => $ejecutada,
            'puede_quitar' => $programacion !== null && strtoupper(trim((string)($programacion['estado'] ?? ''))) !== 'EJECUTADA',
            'procesos_abiertos' => [],
        ];
    }

    $stmtPart = $pdo->prepare('
        SELECT nombre, apellidos, cargo
        FROM ceo_habilitacion_participantes
        WHERE id_cuadrilla = :cuadrilla
          AND ' . integrarRutComparableSql('rut') . ' = :rut_key
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtPart->execute([
        ':cuadrilla' => (int)$plan['cuadrilla'],
        ':rut_key' => $rutKey,
    ]);
    $participante = $stmtPart->fetch(PDO::FETCH_ASSOC) ?: null;

    $filter = integrarBuildEvaluacionHabilitacionFilter($pdo, $plan, true);
    $selectAgr = integrarHasColumn($pdo, 'ceo_evaluaciones_programadas', 'id_agrupacion') ? ', ep.id_agrupacion' : ', NULL AS id_agrupacion';
    $stmtProg = $pdo->prepare('
        SELECT ep.id, ep.estado, ep.resultado, ep.fecha_programacion, ep.id_proceso_habilitacion' . $selectAgr . ', ph.numero_proceso
        FROM ceo_evaluaciones_programadas ep
        LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = ep.id_proceso_habilitacion
        WHERE ' . integrarRutComparableSql('ep.rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
        ORDER BY ep.id DESC
        LIMIT 1
    ');
    $stmtProg->execute([':rut_key' => $rutKey] + $filter['params']);
    $programacion = $stmtProg->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtEj = $pdo->prepare('
        SELECT 1
        FROM ceo_evaluaciones_programadas ep
        WHERE ' . integrarRutComparableSql('ep.rut') . ' = :rut_key
          AND ' . $filter['sql'] . '
          AND ep.estado = "EJECUTADA"
        LIMIT 1
    ');
    $stmtEj->execute([':rut_key' => $rutKey] + $filter['params']);
    $ejecutada = (bool)$stmtEj->fetchColumn();

    return [
        'persona' => $persona,
        'participante' => $participante,
        'programacion' => $programacion,
        'ya_rindio_misma_prueba' => $ejecutada,
        'puede_quitar' => $programacion !== null && strtoupper(trim((string)($programacion['estado'] ?? ''))) !== 'EJECUTADA',
        'procesos_abiertos' => integrarFetchProcesosAbiertos($pdo, $rut, (int)$plan['id_servicio']),
    ];
}

if (empty($_SESSION['auth'])) {
    integrarJson(['ok' => false, 'error' => 'No autorizado'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    integrarJson(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    integrarJson(['ok' => false, 'error' => 'JSON inválido'], 400);
}

$accion = strtolower(trim((string)($data['accion'] ?? 'preview')));
$tipo = strtolower(trim((string)($data['tipo'] ?? '')));
$rut = trim((string)($data['rut'] ?? ''));
$cuadrilla = (int)($data['cuadrilla'] ?? 0);

if (!in_array($accion, ['preview', 'integrar', 'quitar'], true)) {
    integrarJson(['ok' => false, 'error' => 'Acción inválida'], 400);
}

if (!in_array($tipo, ['formacion', 'habilitacion'], true)) {
    integrarJson(['ok' => false, 'error' => 'Tipo inválido'], 400);
}

if ($rut === '') {
    integrarJson(['ok' => false, 'error' => 'Debe ingresar un RUT.'], 400);
}

try {
    $pdo = db();
    $plan = integrarFetchPlanificacion($pdo, $tipo, $cuadrilla, $_SESSION['auth']);

    if ($accion === 'preview') {
        $preview = integrarBuildPreview($pdo, $plan, $rut);
        integrarJson([
            'ok' => true,
            'planificacion' => $plan,
            'preview' => $preview,
        ]);
    }

    $pdo->beginTransaction();
    $persona = integrarEnsurePersona($pdo, $rut, $plan, $data);
    $userId = (int)($_SESSION['auth']['id'] ?? 0);

    if ($accion === 'integrar') {
        if ($tipo === 'formacion') {
            integrarEnsureParticipanteFormacion($pdo, $plan, $persona, $rut);
            $resultado = integrarProgramarFormacion($pdo, $plan, $rut, $userId);
        } else {
            integrarEnsureParticipanteHabilitacion($pdo, $plan, $persona, $rut);
            $resultado = integrarProgramarHabilitacion($pdo, $plan, $rut, $userId, $data, (string)($persona['cargo_nombre'] ?? ''));
        }
    } else {
        if ($tipo === 'formacion') {
            $resultado = integrarAnularFormacion($pdo, $plan, $rut);
        } else {
            $resultado = integrarAnularHabilitacion($pdo, $plan, $rut);
        }
    }

    $pdo->commit();

    $preview = integrarBuildPreview($pdo, $plan, $rut);
    integrarJson([
        'ok' => true,
        'planificacion' => $plan,
        'preview' => $preview,
        'resultado' => $resultado,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    integrarJson(['ok' => false, 'error' => $e->getMessage()], 400);
}
