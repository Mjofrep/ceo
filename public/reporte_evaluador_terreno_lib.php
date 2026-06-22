<?php
declare(strict_types=1);

function retEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function retRutKey(string $rut): string
{
    return strtoupper(str_replace(['.', '-', ' '], '', $rut));
}

function retBuildFilters(): array
{
    $fechaMinima = '2026-06-18';
    $fechaDesde = trim((string)($_GET['fecha_desde'] ?? ''));
    $fechaHasta = trim((string)($_GET['fecha_hasta'] ?? ''));
    $idEvaluador = (int)($_GET['id_evaluador'] ?? 0);
    $rut = trim((string)($_GET['rut'] ?? ''));
    $idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
    $idSesion = (int)($_SESSION['auth']['id'] ?? 0);
    $correoSesion = trim((string)($_SESSION['auth']['correo'] ?? ''));
    $esAdmin = ($idRol === 1);

    if ($fechaDesde === '') {
        $fechaDesde = date('Y-m-01');
    }
    if ($fechaHasta === '') {
        $fechaHasta = date('Y-m-d');
    }

    $fechaDesdeConsulta = $fechaDesde;
    $fechaHastaConsulta = $fechaHasta;

    if ($fechaDesdeConsulta < $fechaMinima) {
        $fechaDesdeConsulta = $fechaMinima;
    }
    if ($fechaHastaConsulta < $fechaDesdeConsulta) {
        $fechaHastaConsulta = $fechaDesdeConsulta;
    }

    if (!$esAdmin) {
        $idEvaluador = $idSesion > 0 ? $idSesion : 0;
    }

    return [
        'fecha_desde' => $fechaDesde,
        'fecha_hasta' => $fechaHasta,
        'fecha_desde_consulta' => $fechaDesdeConsulta,
        'fecha_hasta_consulta' => $fechaHastaConsulta,
        'id_evaluador' => $idEvaluador,
        'rut' => $rut,
        'es_admin' => $esAdmin,
        'correo_evaluador' => $correoSesion,
    ];
}

function retFetchEvaluadores(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            rut,
            CONCAT(nombre, ' ', paterno, ' ', materno) AS nombre
        FROM ceo_evaluadores
        ORDER BY nombre, paterno, materno
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function retFetchRows(PDO $pdo, array $filters): array
{
    $where = [
        'rti.fecha_rendicion BETWEEN :fecha_desde AND :fecha_hasta',
    ];
    $params = [
        ':fecha_desde' => $filters['fecha_desde_consulta'] ?? $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta_consulta'] ?? $filters['fecha_hasta'],
    ];

    if (!empty($filters['es_admin']) && (int)($filters['id_evaluador'] ?? 0) > 0) {
        $where[] = 'rti.id_evaluador = :id_evaluador';
        $params[':id_evaluador'] = (int)$filters['id_evaluador'];
    } elseif (empty($filters['es_admin'])) {
        $correoEvaluador = trim((string)($filters['correo_evaluador'] ?? ''));
        if ($correoEvaluador !== '') {
            $where[] = 'LOWER(TRIM(COALESCE(ev.correo, ""))) = LOWER(TRIM(:correo_evaluador))';
            $params[':correo_evaluador'] = $correoEvaluador;
        } elseif ((int)($filters['id_evaluador'] ?? 0) > 0) {
            $where[] = 'rti.id_evaluador = :id_evaluador';
            $params[':id_evaluador'] = (int)$filters['id_evaluador'];
        }
    }

    if (trim((string)($filters['rut'] ?? '')) !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci = :rut";
        $params[':rut'] = retRutKey((string)$filters['rut']);
    }

    $sql = "
        SELECT
            rti.id,
            rti.fecha_rendicion,
            rti.hora_rendicion,
            rti.rut,
            rti.id_servicio,
            rti.id_proceso_habilitacion,
            TRIM(CONCAT(COALESCE(ev.nombre, ''), ' ', COALESCE(ev.paterno, ''), ' ', COALESCE(ev.materno, ''))) AS evaluador,
            sp.servicio,
            ph.numero_proceso,
            COALESCE(ep.cuadrilla, CAST(et.codigo_evaluacion AS UNSIGNED), 0) AS cuadrilla,
            COALESCE(hp.nombre, ct.nombre, '') AS nombre,
            COALESCE(hp.apellidos, ct.apellidos, '') AS apellidos,
            COALESCE(
                emp_h.nombre,
                NULLIF(TRIM(COALESCE(et.contratista, '')), ''),
                emp_c.nombre,
                ''
            ) AS empresa
        FROM ceo_resultado_terreno_intento rti
        LEFT JOIN ceo_evaluadores ev
            ON ev.id = rti.id_evaluador
        INNER JOIN ceo_servicios_pruebas sp
            ON sp.id = rti.id_servicio
        LEFT JOIN ceo_proceso_habilitacion ph
            ON ph.id = rti.id_proceso_habilitacion
        LEFT JOIN ceo_evaluaciones_programadas ep
            ON ep.id = (
                SELECT ep2.id
                FROM ceo_evaluaciones_programadas ep2
                WHERE ep2.id_proceso_habilitacion = rti.id_proceso_habilitacion
                  AND ep2.id_servicio = rti.id_servicio
                  AND ep2.tipo = 'TERRENO'
                  AND REPLACE(REPLACE(REPLACE(UPPER(ep2.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci =
                      REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci
                ORDER BY ep2.intento DESC, ep2.id DESC
                LIMIT 1
            )
        LEFT JOIN ceo_evaluacion_terreno et
            ON et.id = (
                SELECT et2.id
                FROM ceo_evaluacion_terreno et2
                WHERE et2.id_proceso_habilitacion = rti.id_proceso_habilitacion
                  AND et2.id_servicio = rti.id_servicio
                  AND REPLACE(REPLACE(REPLACE(UPPER(et2.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci =
                      REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci
                  AND DATE(et2.fecha_evaluacion) = rti.fecha_rendicion
                ORDER BY et2.id DESC
                LIMIT 1
            )
        LEFT JOIN ceo_habilitacion h
            ON h.cuadrilla = ep.cuadrilla
           AND h.id_servicio = ep.id_servicio
        LEFT JOIN ceo_habilitacion_participantes hp
            ON hp.id_cuadrilla = ep.cuadrilla
           AND REPLACE(REPLACE(REPLACE(UPPER(hp.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci =
               REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_contratistas ct
            ON REPLACE(REPLACE(REPLACE(UPPER(ct.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci =
               REPLACE(REPLACE(REPLACE(UPPER(rti.rut), '.', ''), '-', ''), ' ', '') COLLATE utf8mb4_unicode_ci
        LEFT JOIN ceo_empresas emp_h
            ON emp_h.id = h.empresa
        LEFT JOIN ceo_empresas emp_c
            ON emp_c.id = ct.id_empresa
        WHERE " . implode(' AND ', $where) . "
        ORDER BY rti.fecha_rendicion DESC, rti.hora_rendicion DESC, rti.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
