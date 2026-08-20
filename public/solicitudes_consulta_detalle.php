<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

if (empty($_SESSION['auth'])) {
    header('Location: /ceo.noetica.cl/config/index.php');
    exit;
}

$pdo = db();

function scdEsc(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scdJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function scdBuildScope(): array
{
    $idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
    $idEmpresa = (int)($_SESSION['auth']['id_empresa'] ?? 0);
    $idUsuario = (int)($_SESSION['auth']['id'] ?? 0);
    $empresaEnel = 39;

    if (($idRol === 1 || $idRol === 5) && $idEmpresa === $empresaEnel) {
        return ['1=1', []];
    }

    if ($idRol === 3 || $idRol === 4 || $idRol === 6) {
        return ['s.solicitante = :scope_iduser', [':scope_iduser' => $idUsuario]];
    }

    return [
        '(s.contratista = :scope_empresa OR s.solicitante = :scope_iduser)',
        [
            ':scope_empresa' => $idEmpresa,
            ':scope_iduser' => $idUsuario,
        ],
    ];
}

function scdBaseSql(): string
{
    return "
        SELECT
            s.nsolicitud,
            s.fecha,
            s.tipohabilitacion,
            COALESCE(s.observacion, '') AS observacion,
            COALESCE(s.tipo_visita, '') AS tipo_visita,
            COALESCE(e.nombre, '') AS empresa,
            TRIM(CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))) AS solicitante,
            COALESCE(pa.desc_patios, '') AS patio,
            COALESCE(pr.desc_proceso, '') AS proceso,
            COALESCE(ht.desc_tipo, '') AS habilitacionceo,
            COALESCE(ch.desc_charlas, '') AS capacitacion,
            COALESCE(rd.reinduccion, '') AS motivo_reinduccion,
            COALESCE(ps.rut, '') AS rut,
            COALESCE(ps.nombre, '') AS nombre,
            COALESCE(ps.apellidop, '') AS apellidop,
            COALESCE(ps.apellidom, '') AS apellidom,
            COALESCE(cc.cargo, '') AS cargo,
            TRIM(CONCAT(COALESCE(ps.nombre, ''), ' ', COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS nombre_completo,
            COALESCE(NULLIF(TRIM(CAST(ps.asistio AS CHAR)), ''), '0') AS asistio
        FROM ceo_solicitudes s
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        LEFT JOIN ceo_cargo_contratistas cc ON cc.id = ps.id_cargo
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_usuarios u ON u.id = s.solicitante
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        LEFT JOIN ceo_reinduccion rd ON rd.id = s.motivoreinduccion
    ";
}

function scdFetchRows(PDO $pdo, array $filters): array
{
    [$scopeWhere, $scopeParams] = scdBuildScope();

    $where = [$scopeWhere, 's.fecha BETWEEN :fecha_desde AND :fecha_hasta'];
    $params = $scopeParams;
    $params[':fecha_desde'] = $filters['fecha_desde'];
    $params[':fecha_hasta'] = $filters['fecha_hasta'];

    if ($filters['id'] > 0) {
        $where[] = 's.nsolicitud = :nsolicitud';
        $params[':nsolicitud'] = $filters['id'];
    }
    if ($filters['empresa'] > 0) {
        $where[] = 's.contratista = :empresa';
        $params[':empresa'] = $filters['empresa'];
    }
    if ($filters['solicitante'] > 0) {
        $where[] = 's.solicitante = :solicitante';
        $params[':solicitante'] = $filters['solicitante'];
    }
    if ($filters['patio'] > 0) {
        $where[] = 's.patio = :patio';
        $params[':patio'] = $filters['patio'];
    }
    if ($filters['proceso'] > 0) {
        $where[] = 's.proceso = :proceso';
        $params[':proceso'] = $filters['proceso'];
    }
    if ($filters['habilitacionceo'] > 0) {
        $where[] = 's.habilitacionceo = :habilitacionceo';
        $params[':habilitacionceo'] = $filters['habilitacionceo'];
    }
    if ($filters['tipohabilitacion'] !== '') {
        $where[] = 's.tipohabilitacion = :tipohabilitacion';
        $params[':tipohabilitacion'] = $filters['tipohabilitacion'];
    }
    if ($filters['charla'] > 0) {
        $where[] = 's.charla = :charla';
        $params[':charla'] = $filters['charla'];
    }
    if ($filters['motivoreinduccion'] > 0) {
        $where[] = 's.motivoreinduccion = :motivoreinduccion';
        $params[':motivoreinduccion'] = $filters['motivoreinduccion'];
    }
    if ($filters['tipo_visita'] !== '') {
        $where[] = "COALESCE(s.tipo_visita, '') = :tipo_visita";
        $params[':tipo_visita'] = $filters['tipo_visita'];
    }
    if ($filters['asistio'] !== '') {
        $where[] = "COALESCE(NULLIF(TRIM(CAST(ps.asistio AS CHAR)), ''), '0') = :asistio";
        $params[':asistio'] = $filters['asistio'];
    }

    $sql = scdBaseSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.fecha ASC, s.nsolicitud ASC, ps.apellidop ASC, ps.apellidom ASC, ps.nombre ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdBuildAttendancePersonKeySql(): string
{
    return "
        CASE
            WHEN REPLACE(REPLACE(REPLACE(UPPER(COALESCE(ps.rut, '')), '.', ''), '-', ''), ' ', '') <> '' THEN REPLACE(REPLACE(REPLACE(UPPER(COALESCE(ps.rut, '')), '.', ''), '-', ''), ' ', '')
            ELSE CONCAT(
                'SOL-',
                COALESCE(CAST(s.nsolicitud AS CHAR), ''),
                '|',
                UPPER(TRIM(COALESCE(ps.nombre, ''))),
                '|',
                UPPER(TRIM(COALESCE(ps.apellidop, ''))),
                '|',
                UPPER(TRIM(COALESCE(ps.apellidom, '')))
            )
        END
    ";
}

function scdFetchAttendanceSummary(PDO $pdo, array $filters): array
{
    [$scopeWhere, $scopeParams] = scdBuildScope();

    $where = [
        $scopeWhere,
        's.fecha BETWEEN :fecha_desde AND :fecha_hasta',
        "COALESCE(NULLIF(TRIM(CAST(ps.asistio AS CHAR)), ''), '0') = '1'",
        "UPPER(TRIM(COALESCE(ht.desc_tipo, ''))) <> 'HABILITACIÓN'",
    ];
    $params = $scopeParams;
    $params[':fecha_desde'] = $filters['fecha_desde'];
    $params[':fecha_hasta'] = $filters['fecha_hasta'];

    if ($filters['id'] > 0) {
        $where[] = 's.nsolicitud = :nsolicitud';
        $params[':nsolicitud'] = $filters['id'];
    }
    if ($filters['empresa'] > 0) {
        $where[] = 's.contratista = :empresa';
        $params[':empresa'] = $filters['empresa'];
    }
    if ($filters['solicitante'] > 0) {
        $where[] = 's.solicitante = :solicitante';
        $params[':solicitante'] = $filters['solicitante'];
    }
    if ($filters['patio'] > 0) {
        $where[] = 's.patio = :patio';
        $params[':patio'] = $filters['patio'];
    }
    if ($filters['proceso'] > 0) {
        $where[] = 's.proceso = :proceso';
        $params[':proceso'] = $filters['proceso'];
    }
    if ($filters['habilitacionceo'] > 0) {
        $where[] = 's.habilitacionceo = :habilitacionceo';
        $params[':habilitacionceo'] = $filters['habilitacionceo'];
    }
    if ($filters['tipohabilitacion'] !== '') {
        $where[] = 's.tipohabilitacion = :tipohabilitacion';
        $params[':tipohabilitacion'] = $filters['tipohabilitacion'];
    }
    if ($filters['charla'] > 0) {
        $where[] = 's.charla = :charla';
        $params[':charla'] = $filters['charla'];
    }
    if ($filters['motivoreinduccion'] > 0) {
        $where[] = 's.motivoreinduccion = :motivoreinduccion';
        $params[':motivoreinduccion'] = $filters['motivoreinduccion'];
    }
    if ($filters['tipo_visita'] !== '') {
        $where[] = "COALESCE(s.tipo_visita, '') = :tipo_visita";
        $params[':tipo_visita'] = $filters['tipo_visita'];
    }

    $personKeySql = scdBuildAttendancePersonKeySql();
    $sql = "
        SELECT
            COALESCE(NULLIF(TRIM(ht.desc_tipo), ''), 'Sin habilitación CEO') AS habilitacionceo,
            COALESCE(NULLIF(TRIM(pr.desc_proceso), ''), 'Sin proceso') AS proceso,
            COALESCE(NULLIF(TRIM(ch.desc_charlas), ''), 'Sin capacitación') AS capacitacion,
            COALESCE(NULLIF(TRIM(s.tipo_visita), ''), 'Sin tipo de visita') AS tipo_visita,
            COUNT(DISTINCT {$personKeySql}) AS total
        FROM ceo_solicitudes s
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
        WHERE " . implode(' AND ', $where) . "
        GROUP BY
            COALESCE(NULLIF(TRIM(ht.desc_tipo), ''), 'Sin habilitación CEO'),
            COALESCE(NULLIF(TRIM(pr.desc_proceso), ''), 'Sin proceso'),
            COALESCE(NULLIF(TRIM(ch.desc_charlas), ''), 'Sin capacitación'),
            COALESCE(NULLIF(TRIM(s.tipo_visita), ''), 'Sin tipo de visita')
        ORDER BY
            COALESCE(NULLIF(TRIM(ht.desc_tipo), ''), 'Sin habilitación CEO') ASC,
            COALESCE(NULLIF(TRIM(pr.desc_proceso), ''), 'Sin proceso') ASC,
            COALESCE(NULLIF(TRIM(ch.desc_charlas), ''), 'Sin capacitación') ASC,
            COALESCE(NULLIF(TRIM(s.tipo_visita), ''), 'Sin tipo de visita') ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdFetchScalar(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)($stmt->fetchColumn() ?: 0);
}

function scdFetchFormacionTotal(PDO $pdo, int $idServicio, array $filters): int
{
    $sql = "
        SELECT COUNT(*)
        FROM ceo_formacion_participantes p
        INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
        LEFT JOIN (
            SELECT fp1.*
            FROM ceo_formacion_programadas fp1
            INNER JOIN (
                SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion_programadas
                GROUP BY rut, id_servicio, cuadrilla
            ) fp2 ON fp1.id = fp2.max_id
        ) fp ON fp.rut = p.rut AND fp.id_servicio = f.id_servicio AND fp.cuadrilla = p.id_cuadrilla
        WHERE f.id_servicio = :id_servicio
          AND f.fecha BETWEEN :fecha_desde AND :fecha_hasta
          AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
          AND UPPER(TRIM(COALESCE(fp.resultado, 'PENDIENTE'))) IN ('APROBADO', 'REPROBADO', 'PENDIENTE')
    ";

    return scdFetchScalar($pdo, $sql, [
        ':id_servicio' => $idServicio,
        ':fecha_desde' => $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta'],
    ]);
}

function scdSummaryDefinitions(): array
{
    return [
        [
            'key' => 'habilitaciones',
            'titulo' => 'Habilitaciones',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 3
                AND UPPER(TRIM(COALESCE(pr.desc_proceso, ''))) <> 'ENEL X'
                AND UPPER(TRIM(COALESCE(e.nombre, ''))) NOT LIKE '%ENEL X%'
                AND (
                    UPPER(TRIM(COALESCE(cc.cargo, ''))) LIKE '%SUPERVISOR%'
                    OR UPPER(TRIM(COALESCE(cc.cargo, ''))) LIKE '%OPERADOR%'
                )
            ",
        ],
        [
            'key' => 'apoyo_habilitaciones',
            'titulo' => 'Apoyo Habilitaciones',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 3
                AND UPPER(TRIM(COALESCE(pr.desc_proceso, ''))) <> 'ENEL X'
                AND UPPER(TRIM(COALESCE(e.nombre, ''))) NOT LIKE '%ENEL X%'
                AND TRIM(COALESCE(cc.cargo, '')) <> ''
                AND UPPER(TRIM(COALESCE(cc.cargo, ''))) NOT LIKE '%SUPERVISOR%'
                AND UPPER(TRIM(COALESCE(cc.cargo, ''))) NOT LIKE '%OPERADOR%'
            ",
        ],
        [
            'key' => 'habilitaciones_ecommercial',
            'titulo' => 'Habilitaciones E-Commercial',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 8
                AND UPPER(TRIM(COALESCE(pr.desc_proceso, ''))) = 'ENEL X'
            ",
        ],
        [
            'key' => 'charla_induccion_irl',
            'titulo' => 'Charla de Inducción (IRL)',
            'origen' => 'FORMACION',
            'origen_label' => 'Formación',
            'reference_label' => 'Cuadrilla',
            'context_label' => 'Servicio / UO',
            'service_id' => 23,
        ],
        [
            'key' => 'rdo',
            'titulo' => 'RDO',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.proceso = 24
                AND s.habilitacionceo = 6
            ",
        ],
        [
            'key' => 'reunion_gerencial',
            'titulo' => 'Reunión gerencial',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 5
                AND UPPER(TRIM(COALESCE(s.tipo_visita, ''))) = 'VISITA EMPRESAS'
                AND UPPER(COALESCE(s.observacion, '')) NOT LIKE '%WORKSHOP%'
            ",
        ],
        [
            'key' => 'visitas_municipios',
            'titulo' => 'Visitas Municipios',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 5
                AND UPPER(TRIM(COALESCE(s.tipo_visita, ''))) = 'MUNICIPIOS'
            ",
        ],
        [
            'key' => 'visitas_educacionales',
            'titulo' => 'Visitas Colegios',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND UPPER(TRIM(COALESCE(e.nombre, ''))) = 'VISITAS DE LICEOS'
                AND UPPER(TRIM(COALESCE(pr.desc_proceso, ''))) = 'VISITA'
            ",
        ],
        [
            'key' => 'workshop',
            'titulo' => 'Workshop',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 5
                AND UPPER(TRIM(COALESCE(s.tipo_visita, ''))) = 'VISITA EMPRESAS'
                AND UPPER(COALESCE(s.observacion, '')) LIKE '%WORKSHOP%'
            ",
        ],
        [
            'key' => 'fundacion_colonias_urbanas_cruzando_fronteras_maipu',
            'titulo' => 'FUNDACIÓN COLONIAS URBANAS CRUZANDO FRONTERAS DE MAIPÚ',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 5
                AND UPPER(TRIM(COALESCE(s.tipo_visita, ''))) = 'FUNDACIÓN'
            ",
        ],
        [
            'key' => 'capacitacion_grupo_electrogeno',
            'titulo' => 'CAPACITACIÓN DE GRUPO ELECTRÓGENO',
            'origen' => 'SOLICITUDES',
            'origen_label' => 'Solicitudes',
            'reference_label' => 'Solicitud',
            'context_label' => 'Patio / Proceso',
            'condition' => "
                s.estado IN ('A', 'F')
                AND COALESCE(NULLIF(TRIM(ps.asistio), ''), '0') = '1'
                AND ps.fechaasistio >= :fecha_desde
                AND ps.fechaasistio < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)
                AND s.habilitacionceo = 6
                AND UPPER(TRIM(COALESCE(s.tipohabilitacion, ''))) = 'SEGURIDAD'
                AND (
                    UPPER(COALESCE(s.observacion, '')) LIKE '%ELECTRÓGENO%'
                    OR UPPER(COALESCE(s.observacion, '')) LIKE '%ELECTROGENO%'
                )
                AND UPPER(TRIM(COALESCE(ch.desc_charlas, ''))) = 'OPERACIÓN DE GENERADOR'
            ",
        ],
        [
            'key' => 'turn_the_tide',
            'titulo' => 'Turn the Tide',
            'origen' => 'FORMACION',
            'origen_label' => 'Formación',
            'reference_label' => 'Cuadrilla',
            'context_label' => 'Servicio / UO',
            'service_id' => 22,
        ],
    ];
}

function scdFindSummaryDefinition(string $key): ?array
{
    foreach (scdSummaryDefinitions() as $definition) {
        if ((string)$definition['key'] === $key) {
            return $definition;
        }
    }

    return null;
}

function scdSolicitudesSummaryFromSql(): string
{
    return "
        FROM ceo_solicitudes s
        INNER JOIN ceo_participantes_solicitud ps ON ps.id_solicitud = s.nsolicitud
        LEFT JOIN ceo_cargo_contratistas cc
            ON cc.id = CASE
                WHEN TRIM(COALESCE(ps.id_cargo, '')) REGEXP '^[0-9]+$'
                    THEN CAST(ps.id_cargo AS UNSIGNED)
                ELSE NULL
            END
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_habilitaciontipo ht ON ht.id = s.habilitacionceo
        LEFT JOIN ceo_charlas ch ON ch.id = s.charla
    ";
}

function scdBuildSummaryBaseParams(array $filters): array
{
    [, $scopeParams] = scdBuildScope();

    return $scopeParams + [
        ':fecha_desde' => $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta'],
    ];
}

function scdHabilitacionesSummaryResultBaseSql(): string
{
    return "
        SELECT
            REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), '.', ''), '-', ''), ' ', '') AS rut_norm,
            DATE(rfs.fecha_calculo) AS fecha_visita,
            CASE
                WHEN rfs.id_servicio IN (9, 10, 24) THEN 'SSEE'
                WHEN rfs.id_servicio IN (7, 22) THEN 'INFRAESTRUCTURA AREAS'
                WHEN rfs.id_servicio IN (11, 12) THEN 'INFRAESTRUCTURA SUBTERRANEA'
                ELSE CONCAT('SERVICIO_', rfs.id_servicio)
            END AS servicio_resumen,
            COALESCE(sp.servicio, CONCAT('Servicio ', rfs.id_servicio)) AS servicio_nombre,
            rfs.fecha_calculo,
            rfs.id_servicio,
            rfs.id_proceso,
            rfs.id_proceso_habilitacion,
            s.nsolicitud,
            COALESCE(emp.nombre, '') AS empresa,
            COALESCE(
                CONCAT_WS(
                    ' / ',
                    NULLIF(TRIM(COALESCE(pa.desc_patios, '')), ''),
                    NULLIF(TRIM(COALESCE(pr.desc_proceso, '')), '')
                ),
                ''
            ) AS contexto,
            COALESCE(rfs.rut, '') AS rut,
            TRIM(
                CONCAT(
                    COALESCE(NULLIF(TRIM(COALESCE(c.nombre, '')), ''), NULLIF(TRIM(COALESCE(ps.nombre, '')), ''), ''),
                    ' ',
                    COALESCE(
                        NULLIF(TRIM(COALESCE(c.apellidos, '')), ''),
                        NULLIF(TRIM(CONCAT(COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))), ''),
                        ''
                    )
                )
            ) AS persona,
            COALESCE(NULLIF(TRIM(COALESCE(ch.cargo, '')), ''), NULLIF(TRIM(COALESCE(cc.cargo, '')), ''), '') AS cargo
        FROM ceo_resultado_final_servicio rfs
        LEFT JOIN ceo_servicios_pruebas sp ON sp.id = rfs.id_servicio
        LEFT JOIN ceo_cargos_habilitacion ch ON ch.id = rfs.cargo
        LEFT JOIN ceo_contratistas c
            ON REPLACE(REPLACE(REPLACE(UPPER(c.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), '.', ''), '-', ''), ' ', '')
        LEFT JOIN ceo_habilitacion h
            ON h.cuadrilla = rfs.id_proceso
           AND h.id_servicio = rfs.id_servicio
        LEFT JOIN ceo_solicitudes s ON s.nsolicitud = h.nsolicitud
        LEFT JOIN ceo_empresas emp ON emp.id = COALESCE(h.empresa, s.contratista, c.id_empresa)
        LEFT JOIN ceo_patios pa ON pa.id = s.patio
        LEFT JOIN ceo_procesos pr ON pr.id = s.proceso
        LEFT JOIN ceo_participantes_solicitud ps
            ON ps.id_solicitud = s.nsolicitud
           AND REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), '.', ''), '-', ''), ' ', '')
        LEFT JOIN ceo_cargo_contratistas cc
            ON cc.id = CASE
                WHEN TRIM(COALESCE(ps.id_cargo, '')) REGEXP '^[0-9]+$'
                    THEN CAST(ps.id_cargo AS UNSIGNED)
                ELSE NULL
            END
    ";
}

function scdBuildHabilitacionesSummaryResultWhere(array $filters): array
{
    $where = [
        "rfs.segmento = 'GENERAL'",
        "rfs.resultado_final IN ('APROBADO', 'REPROBADO')",
        'DATE(rfs.fecha_calculo) BETWEEN :fecha_desde AND :fecha_hasta',
        "REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), '.', ''), '-', ''), ' ', '') <> ''",
    ];

    $params = [
        ':fecha_desde' => $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta'],
    ];

    return [$where, $params];
}

function scdFetchHabilitacionesSummaryTotal(PDO $pdo, array $filters): int
{
    $sql = '
        SELECT COUNT(*)
        FROM (
            SELECT DISTINCT
                REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), ".", ""), "-", ""), " ", "") AS rut_norm,
                DATE(rfs.fecha_calculo) AS fecha_visita,
                CASE
                    WHEN rfs.id_servicio IN (9, 10, 24) THEN "SSEE"
                    WHEN rfs.id_servicio IN (7, 22) THEN "INFRAESTRUCTURA AREAS"
                    WHEN rfs.id_servicio IN (11, 12) THEN "INFRAESTRUCTURA SUBTERRANEA"
                    ELSE CONCAT("SERVICIO_", rfs.id_servicio)
                END AS servicio_resumen
            FROM ceo_resultado_final_servicio rfs
            WHERE rfs.segmento = "GENERAL"
              AND rfs.resultado_final IN ("APROBADO", "REPROBADO")
              AND DATE(rfs.fecha_calculo) BETWEEN :fecha_desde AND :fecha_hasta
              AND REPLACE(REPLACE(REPLACE(UPPER(rfs.rut), ".", ""), "-", ""), " ", "") <> ""
        ) agrupado
    ';

    $params = [
        ':fecha_desde' => $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta'],
    ];

    return scdFetchScalar($pdo, $sql, $params);
}

function scdFetchHabilitacionesSummaryDetail(PDO $pdo, array $filters): array
{
    [$where, $params] = scdBuildHabilitacionesSummaryResultWhere($filters);
    $sql = '
        SELECT
            base.fecha_visita AS fecha,
            CASE
                WHEN COUNT(DISTINCT base.nsolicitud) > 1 THEN "Multiples solicitudes"
                ELSE CAST(MAX(base.nsolicitud) AS CHAR)
            END AS referencia,
            MAX(base.empresa) AS empresa,
            CASE
                WHEN COUNT(DISTINCT COALESCE(base.contexto, "")) > 1 THEN "Multiples solicitudes"
                ELSE MAX(base.contexto)
            END AS contexto,
            MAX(base.rut) AS rut,
            MAX(base.persona) AS persona,
            MAX(base.cargo) AS cargo
        FROM (
            ' . scdHabilitacionesSummaryResultBaseSql() . '
            WHERE ' . implode(' AND ', $where) . '
        ) base
        GROUP BY base.rut_norm, base.fecha_visita, base.servicio_resumen
        ORDER BY base.fecha_visita ASC, MAX(base.persona) ASC, MAX(base.rut) ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdFetchSolicitudesSummaryTotal(PDO $pdo, array $filters, string $condition): int
{
    [$scopeWhere] = scdBuildScope();
    $sql = "SELECT COUNT(*) " . scdSolicitudesSummaryFromSql() . " WHERE {$scopeWhere} AND {$condition}";
    return scdFetchScalar($pdo, $sql, scdBuildSummaryBaseParams($filters));
}

function scdFetchSolicitudesSummaryUniquePersonTotal(PDO $pdo, array $filters, string $condition): int
{
    [$scopeWhere] = scdBuildScope();
    $sql = "
        SELECT COUNT(DISTINCT REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', ''))
        " . scdSolicitudesSummaryFromSql() . "
        WHERE {$scopeWhere}
          AND {$condition}
          AND TRIM(COALESCE(ps.rut, '')) <> ''
    ";

    return scdFetchScalar($pdo, $sql, scdBuildSummaryBaseParams($filters));
}

function scdFetchSolicitudesSummaryDetail(PDO $pdo, array $filters, string $condition): array
{
    [$scopeWhere] = scdBuildScope();
    $sql = "
        SELECT
            s.fecha AS fecha,
            s.nsolicitud AS referencia,
            COALESCE(e.nombre, '') AS empresa,
            COALESCE(
                CONCAT_WS(
                    ' / ',
                    NULLIF(TRIM(COALESCE(pa.desc_patios, '')), ''),
                    NULLIF(TRIM(COALESCE(pr.desc_proceso, '')), '')
                ),
                ''
            ) AS contexto,
            COALESCE(ps.rut, '') AS rut,
            TRIM(CONCAT(COALESCE(ps.nombre, ''), ' ', COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS persona,
            COALESCE(cc.cargo, '') AS cargo
        " . scdSolicitudesSummaryFromSql() . "
        WHERE {$scopeWhere}
          AND {$condition}
        ORDER BY s.fecha ASC, s.nsolicitud ASC, ps.apellidop ASC, ps.apellidom ASC, ps.nombre ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(scdBuildSummaryBaseParams($filters));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdFetchSolicitudesSummaryUniquePersonDetail(PDO $pdo, array $filters, string $condition): array
{
    [$scopeWhere] = scdBuildScope();
    $sql = "
        SELECT
            MIN(s.fecha) AS fecha,
            CASE
                WHEN COUNT(DISTINCT s.nsolicitud) > 1 THEN 'Multiples solicitudes'
                ELSE CAST(MIN(s.nsolicitud) AS CHAR)
            END AS referencia,
            COALESCE(e.nombre, '') AS empresa,
            CASE
                WHEN COUNT(DISTINCT s.nsolicitud) > 1 THEN 'Multiples solicitudes'
                ELSE COALESCE(
                    CONCAT_WS(
                        ' / ',
                        NULLIF(TRIM(COALESCE(pa.desc_patios, '')), ''),
                        NULLIF(TRIM(COALESCE(pr.desc_proceso, '')), '')
                    ),
                    ''
                )
            END AS contexto,
            COALESCE(ps.rut, '') AS rut,
            TRIM(CONCAT(COALESCE(ps.nombre, ''), ' ', COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))) AS persona,
            COALESCE(cc.cargo, '') AS cargo
        " . scdSolicitudesSummaryFromSql() . "
        WHERE {$scopeWhere}
          AND {$condition}
          AND TRIM(COALESCE(ps.rut, '')) <> ''
        GROUP BY
            REPLACE(REPLACE(REPLACE(UPPER(ps.rut), '.', ''), '-', ''), ' ', ''),
            COALESCE(e.nombre, ''),
            COALESCE(ps.rut, ''),
            TRIM(CONCAT(COALESCE(ps.nombre, ''), ' ', COALESCE(ps.apellidop, ''), ' ', COALESCE(ps.apellidom, ''))),
            COALESCE(cc.cargo, '')
        ORDER BY MIN(s.fecha) ASC, persona ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(scdBuildSummaryBaseParams($filters));
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdFetchFormacionDetalle(PDO $pdo, int $idServicio, array $filters): array
{
    $sql = "
        SELECT
            f.fecha AS fecha,
            f.cuadrilla AS referencia,
            COALESCE(e.nombre, '') AS empresa,
            COALESCE(
                CONCAT_WS(
                    ' / ',
                    NULLIF(TRIM(COALESCE(fs.servicio, '')), ''),
                    NULLIF(TRIM(COALESCE(uo.desc_uo, '')), '')
                ),
                ''
            ) AS contexto,
            COALESCE(p.rut, '') AS rut,
            TRIM(CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellidos, ''))) AS persona,
            COALESCE(p.cargo, '') AS cargo
        FROM ceo_formacion_participantes p
        INNER JOIN ceo_formacion f ON f.cuadrilla = p.id_cuadrilla
        LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
        LEFT JOIN ceo_empresas e ON e.id = f.empresa
        LEFT JOIN ceo_uo uo ON uo.id = f.uo
        LEFT JOIN (
            SELECT fp1.*
            FROM ceo_formacion_programadas fp1
            INNER JOIN (
                SELECT rut, id_servicio, cuadrilla, MAX(id) AS max_id
                FROM ceo_formacion_programadas
                GROUP BY rut, id_servicio, cuadrilla
            ) fp2 ON fp1.id = fp2.max_id
        ) fp ON fp.rut = p.rut AND fp.id_servicio = f.id_servicio AND fp.cuadrilla = p.id_cuadrilla
        WHERE f.id_servicio = :id_servicio
          AND f.fecha BETWEEN :fecha_desde AND :fecha_hasta
          AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
          AND UPPER(TRIM(COALESCE(fp.resultado, 'PENDIENTE'))) IN ('APROBADO', 'REPROBADO', 'PENDIENTE')
        ORDER BY f.fecha ASC, f.cuadrilla ASC, p.apellidos ASC, p.nombre ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_servicio' => $idServicio,
        ':fecha_desde' => $filters['fecha_desde'],
        ':fecha_hasta' => $filters['fecha_hasta'],
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scdFetchSolicitudesResumen(PDO $pdo, array $filters): array
{
    $rows = [];

    foreach (scdSummaryDefinitions() as $definition) {
        $key = (string)($definition['key'] ?? '');
        $total = 0;

        try {
            if ($key === 'habilitaciones') {
                $total = scdFetchHabilitacionesSummaryTotal($pdo, $filters);
            } elseif ((string)$definition['origen'] === 'FORMACION') {
                $total = scdFetchFormacionTotal($pdo, (int)($definition['service_id'] ?? 0), $filters);
            } elseif (in_array($key, ['habilitaciones', 'apoyo_habilitaciones'], true)) {
                $total = scdFetchSolicitudesSummaryUniquePersonTotal($pdo, $filters, (string)($definition['condition'] ?? '1=0'));
            } else {
                $total = scdFetchSolicitudesSummaryTotal($pdo, $filters, (string)($definition['condition'] ?? '1=0'));
            }
        } catch (Throwable $e) {
            $total = 0;
        }

        if ($total > 0) {
            $rows[] = [
                'key' => (string)$definition['key'],
                'titulo' => (string)$definition['titulo'],
                'total' => $total,
                'origen' => (string)$definition['origen'],
                'origen_label' => (string)$definition['origen_label'],
                'reference_label' => (string)$definition['reference_label'],
                'context_label' => (string)$definition['context_label'],
            ];
        }
    }

    return $rows;
}

$hoy = date('Y-m-d');
$fechaDesde = trim((string)($_GET['fecha_desde'] ?? $hoy));
$fechaHasta = trim((string)($_GET['fecha_hasta'] ?? $hoy));
if ($fechaDesde === '') {
    $fechaDesde = $hoy;
}
if ($fechaHasta === '') {
    $fechaHasta = $hoy;
}
if ($fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$filters = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'id' => max(0, (int)($_GET['id'] ?? 0)),
    'empresa' => max(0, (int)($_GET['empresa'] ?? 0)),
    'solicitante' => max(0, (int)($_GET['solicitante'] ?? 0)),
    'patio' => max(0, (int)($_GET['patio'] ?? 0)),
    'proceso' => max(0, (int)($_GET['proceso'] ?? 0)),
    'habilitacionceo' => max(0, (int)($_GET['habilitacionceo'] ?? 0)),
    'tipohabilitacion' => trim((string)($_GET['tipohabilitacion'] ?? '')),
    'charla' => max(0, (int)($_GET['charla'] ?? 0)),
    'motivoreinduccion' => max(0, (int)($_GET['motivoreinduccion'] ?? 0)),
    'tipo_visita' => trim((string)($_GET['tipo_visita'] ?? '')),
    'asistio' => in_array((string)($_GET['asistio'] ?? ''), ['0', '1'], true) ? (string)$_GET['asistio'] : '',
];

$summaryDetailKey = trim((string)($_GET['summary_detail'] ?? ''));
if ($summaryDetailKey !== '') {
    try {
        $summaryDefinition = scdFindSummaryDefinition($summaryDetailKey);
        if ($summaryDefinition === null) {
            scdJsonResponse([
                'ok' => false,
                'error' => 'El tema solicitado no existe.',
            ], 404);
        }

        $detailRows = (string)$summaryDefinition['key'] === 'habilitaciones'
            ? scdFetchHabilitacionesSummaryDetail($pdo, $filters)
            : ((string)$summaryDefinition['origen'] === 'FORMACION'
                ? scdFetchFormacionDetalle($pdo, (int)($summaryDefinition['service_id'] ?? 0), $filters)
                : ((in_array((string)$summaryDefinition['key'], ['habilitaciones', 'apoyo_habilitaciones'], true))
                ? scdFetchSolicitudesSummaryUniquePersonDetail($pdo, $filters, (string)($summaryDefinition['condition'] ?? '1=0'))
                : scdFetchSolicitudesSummaryDetail($pdo, $filters, (string)($summaryDefinition['condition'] ?? '1=0'))));

        scdJsonResponse([
            'ok' => true,
            'key' => (string)$summaryDefinition['key'],
            'titulo' => (string)$summaryDefinition['titulo'],
            'origen' => (string)$summaryDefinition['origen'],
            'origen_label' => (string)$summaryDefinition['origen_label'],
            'reference_label' => (string)$summaryDefinition['reference_label'],
            'context_label' => (string)$summaryDefinition['context_label'],
            'rows' => $detailRows,
        ]);
    } catch (Throwable $e) {
        scdJsonResponse([
            'ok' => false,
            'error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el detalle del tema.',
        ], 500);
    }
}

$empresas = $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$solicitantes = $pdo->query("SELECT id, TRIM(CONCAT(COALESCE(nombres, ''), ' ', COALESCE(apellidos, ''))) AS nombre FROM ceo_usuarios ORDER BY nombres, apellidos")->fetchAll(PDO::FETCH_ASSOC);
$patios = $pdo->query('SELECT id, desc_patios FROM ceo_patios ORDER BY desc_patios')->fetchAll(PDO::FETCH_ASSOC);
$procesos = $pdo->query('SELECT id, desc_proceso FROM ceo_procesos ORDER BY desc_proceso')->fetchAll(PDO::FETCH_ASSOC);
$habilitaciones = $pdo->query('SELECT id, desc_tipo FROM ceo_habilitaciontipo ORDER BY desc_tipo')->fetchAll(PDO::FETCH_ASSOC);
$tiposHabilitacion = $pdo->query("SELECT DISTINCT TRIM(tipohabilitacion) AS tipohabilitacion FROM ceo_solicitudes WHERE TRIM(COALESCE(tipohabilitacion, '')) <> '' ORDER BY tipohabilitacion")->fetchAll(PDO::FETCH_ASSOC);
$charlas = $pdo->query('SELECT id, desc_charlas FROM ceo_charlas ORDER BY desc_charlas')->fetchAll(PDO::FETCH_ASSOC);
$reinducciones = $pdo->query('SELECT id, reinduccion FROM ceo_reinduccion ORDER BY reinduccion')->fetchAll(PDO::FETCH_ASSOC);
$tiposVisita = [
    'Colegio',
    'Institutos profesionales',
    'Universidades',
    'Visita Empresas',
    'Municipios',
    'Fundación',
    'Carabineros',
    'Bomberos',
    'PDI',
    'Aduana',
];

$rows = [];
$error = '';
$summaryRows = [];
$summaryError = '';
$attendanceSummaryRows = [];
$attendanceSummaryError = '';

try {
    $rows = scdFetchRows($pdo, $filters);
} catch (Throwable $e) {
    $error = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el informe.';
}

try {
    $summaryRows = scdFetchSolicitudesResumen($pdo, $filters);
} catch (Throwable $e) {
    $summaryError = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el resumen temático.';
}

try {
    $attendanceSummaryRows = scdFetchAttendanceSummary($pdo, $filters);
} catch (Throwable $e) {
    $attendanceSummaryError = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'No fue posible cargar el resumen por habilitación y proceso.';
}

$excelUrl = 'solicitudes_consulta_detalle_excel.php?' . http_build_query($filters);
$summaryGrandTotal = array_sum(array_map(static fn(array $row): int => (int)($row['total'] ?? 0), $summaryRows));
$summaryJson = json_encode($summaryRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$attendanceSummaryGrandTotal = array_sum(array_map(static fn(array $row): int => (int)($row['total'] ?? 0), $attendanceSummaryRows));
$attendanceSummaryJson = json_encode($attendanceSummaryRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Consulta Detallada de Solicitudes - <?= scdEsc(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .topbar { background:#fff; border-bottom:1px solid #e3e6ea; }
    .brand-title { color:#0065a4; font-weight:600; }
    .card { border:none; box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .table thead th { background:#eaf2fb; position:sticky; top:0; z-index:2; white-space:nowrap; }
    .summary-trigger { min-width:42px; }
    .summary-modal .modal-content { border:none; border-radius:1.25rem; box-shadow:0 18px 50px rgba(11, 45, 78, .18); overflow:hidden; }
    .summary-modal .modal-header { background:linear-gradient(135deg, #0b5a8f 0%, #17324d 100%); color:#fff; border-bottom:none; }
    .summary-modal .modal-header .btn-close { filter:invert(1); }
    .summary-toolbar .btn { min-width:42px; }
    .summary-period { color:#d8ebff; }
    .summary-table thead th { background:#f2f7fc; color:#17324d; }
    .summary-table td, .summary-table th { vertical-align:middle; }
    .summary-table tbody tr:nth-child(odd) { background:#fbfdff; }
    .summary-total { font-variant-numeric:tabular-nums; font-weight:700; color:#0b5a8f; }
    .summary-total-btn { font-variant-numeric:tabular-nums; font-weight:700; color:#0b5a8f; text-decoration:none; }
    .summary-total-btn:hover, .summary-total-btn:focus { color:#084b74; text-decoration:underline; }
    .summary-detail-modal .modal-content { border:none; border-radius:1.1rem; box-shadow:0 18px 50px rgba(11, 45, 78, .18); }
    .summary-detail-modal .modal-header { background:#f2f7fc; }
    .summary-detail-table thead th { background:#eef4fa; white-space:nowrap; }
    .summary-toast {
      position:fixed;
      right:1rem;
      bottom:1rem;
      z-index:1080;
      background:#17324d;
      color:#fff;
      border-radius:999px;
      padding:.6rem .9rem;
      box-shadow:0 10px 30px rgba(23, 50, 77, .25);
      opacity:0;
      pointer-events:none;
      transform:translateY(8px);
      transition:opacity .2s ease, transform .2s ease;
    }
    .summary-toast.is-visible { opacity:1; transform:translateY(0); }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= scdEsc(APP_LOGO) ?>" alt="Logo" style="height:54px;">
      <div>
        <div class="brand-title h5 mb-0"><?= scdEsc(APP_NAME) ?></div>
        <small class="text-muted"><?= scdEsc(APP_SUBTITLE) ?></small>
      </div>
    </div>
    <a href="general.php" class="btn btn-outline-primary btn-sm">&larr; Volver</a>
  </div>
</header>

<main class="container-fluid px-4 mb-5">
  <div class="card rounded-4 mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
        <div>
          <h1 class="h4 mb-1 text-primary">Consulta Detallada de Solicitudes</h1>
          <p class="mb-0 text-muted">Detalle por participante asociado a cada solicitud del periodo consultado.</p>
        </div>
        <div class="text-muted small">Total registros: <?= count($rows) ?></div>
      </div>

      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
          <label class="form-label">Fecha desde</label>
          <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?= scdEsc($filters['fecha_desde']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Fecha hasta</label>
          <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?= scdEsc($filters['fecha_hasta']) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">ID Solicitud</label>
          <input type="number" min="0" name="id" class="form-control form-control-sm" value="<?= $filters['id'] > 0 ? (int)$filters['id'] : '' ?>" placeholder="Todas">
        </div>
        <div class="col-md-3">
          <label class="form-label">Empresa</label>
          <select name="empresa" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($empresas as $empresa): ?>
              <option value="<?= (int)$empresa['id'] ?>" <?= $filters['empresa'] === (int)$empresa['id'] ? 'selected' : '' ?>><?= scdEsc($empresa['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Solicitante</label>
          <select name="solicitante" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($solicitantes as $solicitante): ?>
              <option value="<?= (int)$solicitante['id'] ?>" <?= $filters['solicitante'] === (int)$solicitante['id'] ? 'selected' : '' ?>><?= scdEsc($solicitante['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Patio</label>
          <select name="patio" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($patios as $patio): ?>
              <option value="<?= (int)$patio['id'] ?>" <?= $filters['patio'] === (int)$patio['id'] ? 'selected' : '' ?>><?= scdEsc($patio['desc_patios']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proceso</label>
          <select name="proceso" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($procesos as $proceso): ?>
              <option value="<?= (int)$proceso['id'] ?>" <?= $filters['proceso'] === (int)$proceso['id'] ? 'selected' : '' ?>><?= scdEsc($proceso['desc_proceso']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Habilitación CEO</label>
          <select name="habilitacionceo" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($habilitaciones as $habilitacion): ?>
              <option value="<?= (int)$habilitacion['id'] ?>" <?= $filters['habilitacionceo'] === (int)$habilitacion['id'] ? 'selected' : '' ?>><?= scdEsc($habilitacion['desc_tipo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo Habilitación</label>
          <select name="tipohabilitacion" class="form-select form-select-sm">
            <option value="">Todas</option>
            <?php foreach ($tiposHabilitacion as $tipo): ?>
              <option value="<?= scdEsc($tipo['tipohabilitacion']) ?>" <?= $filters['tipohabilitacion'] === (string)$tipo['tipohabilitacion'] ? 'selected' : '' ?>><?= scdEsc($tipo['tipohabilitacion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Capacitación</label>
          <select name="charla" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($charlas as $charla): ?>
              <option value="<?= (int)$charla['id'] ?>" <?= $filters['charla'] === (int)$charla['id'] ? 'selected' : '' ?>><?= scdEsc($charla['desc_charlas']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Motivo Reinducción</label>
          <select name="motivoreinduccion" class="form-select form-select-sm">
            <option value="0">Todas</option>
            <?php foreach ($reinducciones as $reinduccion): ?>
              <option value="<?= (int)$reinduccion['id'] ?>" <?= $filters['motivoreinduccion'] === (int)$reinduccion['id'] ? 'selected' : '' ?>><?= scdEsc($reinduccion['reinduccion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo de visita</label>
          <select name="tipo_visita" class="form-select form-select-sm">
            <option value="">Todas</option>
            <?php foreach ($tiposVisita as $tipoVisita): ?>
              <option value="<?= scdEsc($tipoVisita) ?>" <?= $filters['tipo_visita'] === $tipoVisita ? 'selected' : '' ?>><?= scdEsc($tipoVisita) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Asistió</label>
          <select name="asistio" class="form-select form-select-sm">
            <option value="" <?= $filters['asistio'] === '' ? 'selected' : '' ?>>Todos</option>
            <option value="1" <?= $filters['asistio'] === '1' ? 'selected' : '' ?>>Si</option>
            <option value="0" <?= $filters['asistio'] === '0' ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Consultar</button>
          <a href="<?= scdEsc($excelUrl) ?>" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exportar</a>
          <button type="button" class="btn btn-outline-primary btn-sm summary-trigger" data-bs-toggle="modal" data-bs-target="#resumenTemasModal" title="Resumen por tema" aria-label="Resumen por tema">
            <i class="bi bi-table"></i>
          </button>
          <button type="button" class="btn btn-outline-primary btn-sm summary-trigger" data-bs-toggle="modal" data-bs-target="#resumenHabilitacionProcesoModal" title="Resumen por habilitación y proceso" aria-label="Resumen por habilitación y proceso">
            <i class="bi bi-diagram-3"></i>
          </button>
          <a href="general.php" class="btn btn-outline-secondary btn-sm">Volver</a>
        </div>
      </form>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= scdEsc($error) ?></div>
  <?php endif; ?>

  <?php if ($summaryError !== ''): ?>
    <div class="alert alert-warning"><?= scdEsc($summaryError) ?></div>
  <?php endif; ?>

  <?php if ($attendanceSummaryError !== ''): ?>
    <div class="alert alert-warning"><?= scdEsc($attendanceSummaryError) ?></div>
  <?php endif; ?>

  <div class="card rounded-4">
    <div class="card-body p-3">
      <div class="table-responsive" style="max-height:600px;overflow:auto;">
        <table class="table table-bordered table-sm align-middle mb-0">
          <thead class="text-center align-middle">
            <tr>
              <th>Fecha</th>
              <th>N° Solicitud</th>
              <th>Empresa</th>
              <th>Solicitante</th>
              <th>Patio</th>
              <th>Proceso</th>
              <th>Habilitación CEO</th>
              <th>Tipo Habilitación</th>
              <th>Capacitación</th>
              <th>Motivo Reinducción</th>
              <th>Tipo de visita</th>
              <th>Observación</th>
              <th>RUT</th>
              <th>Nombre</th>
              <th>Apellidos</th>
              <th>Cargo</th>
              <th>Asistió</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= scdEsc($row['fecha']) ?></td>
                <td class="text-center"><?= (int)$row['nsolicitud'] ?></td>
                <td><?= scdEsc($row['empresa']) ?></td>
                <td><?= scdEsc($row['solicitante']) ?></td>
                <td><?= scdEsc($row['patio']) ?></td>
                <td><?= scdEsc($row['proceso']) ?></td>
                <td><?= scdEsc($row['habilitacionceo']) ?></td>
                <td><?= scdEsc($row['tipohabilitacion']) ?></td>
                <td><?= scdEsc($row['capacitacion']) ?></td>
                <td><?= scdEsc($row['motivo_reinduccion']) ?></td>
                <td><?= scdEsc($row['tipo_visita']) ?></td>
                <td><?= scdEsc($row['observacion']) ?></td>
                <td><?= scdEsc($row['rut']) ?></td>
                <td><?= scdEsc($row['nombre']) ?></td>
                <td><?= scdEsc(trim($row['apellidop'] . ' ' . $row['apellidom'])) ?></td>
                <td><?= scdEsc($row['cargo']) ?></td>
                <td class="text-center"><?= (int)$row['asistio'] === 1 ? 'Si' : 'No' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
              <td colspan="17" class="text-center text-muted py-4">No se encontraron registros para los filtros seleccionados.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<div class="modal fade summary-modal" id="resumenTemasModal" tabindex="-1" aria-labelledby="resumenTemasModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header px-4 py-3">
        <div>
          <h2 class="h5 mb-1" id="resumenTemasModalLabel">Resumen por tema</h2>
          <div class="small summary-period">Periodo <?= scdEsc($filters['fecha_desde']) ?> al <?= scdEsc($filters['fecha_hasta']) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
          <p class="mb-0 text-muted">Totales calculados por tema para el período seleccionado.</p>
          <div class="d-flex gap-2 summary-toolbar">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="summaryCopyBtn" title="Copiar tabla como imagen o descargar PNG" aria-label="Copiar tabla como imagen o descargar PNG">
              <i class="bi bi-clipboard-image"></i>
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" id="summaryExportBtn" title="Exportar tabla" aria-label="Exportar tabla">
              <i class="bi bi-download"></i>
            </button>
          </div>
        </div>
        <div class="table-responsive" id="summaryCaptureArea">
          <table class="table table-sm table-bordered align-middle mb-0 summary-table" id="summaryTopicsTable">
            <thead>
              <tr>
                <th>Tema</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($summaryRows): ?>
              <?php foreach ($summaryRows as $summaryRow): ?>
                <tr>
                  <td><?= scdEsc($summaryRow['titulo']) ?></td>
                  <td class="text-end summary-total">
                    <?php if ((int)$summaryRow['total'] > 0): ?>
                      <button
                        type="button"
                        class="btn btn-link btn-sm p-0 align-baseline summary-total-btn summary-detail-btn"
                        data-summary-key="<?= scdEsc($summaryRow['key']) ?>"
                        data-summary-title="<?= scdEsc($summaryRow['titulo']) ?>"
                        data-summary-origin="<?= scdEsc($summaryRow['origen_label']) ?>"
                        title="Ver detalle de personas"
                      ><?= (int)$summaryRow['total'] ?></button>
                    <?php else: ?>
                      <span class="text-muted">0</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="2" class="text-center text-muted py-4">No hay métricas disponibles para el período seleccionado.</td>
              </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <th>TOTAL</th>
                <th class="text-end summary-total"><?= (int)$summaryGrandTotal ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade summary-modal" id="resumenHabilitacionProcesoModal" tabindex="-1" aria-labelledby="resumenHabilitacionProcesoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header px-4 py-3">
        <div>
          <h2 class="h5 mb-1" id="resumenHabilitacionProcesoModalLabel">Resumen por Habilitación CEO y Proceso</h2>
          <div class="small summary-period">Periodo <?= scdEsc($filters['fecha_desde']) ?> al <?= scdEsc($filters['fecha_hasta']) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
          <p class="mb-0 text-muted">Personas únicas que asistieron en el período, agrupadas por Habilitación CEO y Proceso.</p>
          <div class="d-flex gap-2 summary-toolbar">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="attendanceSummaryCopyBtn" title="Copiar tabla como imagen o descargar PNG" aria-label="Copiar tabla como imagen o descargar PNG">
              <i class="bi bi-clipboard-image"></i>
            </button>
            <button type="button" class="btn btn-outline-success btn-sm" id="attendanceSummaryExportBtn" title="Exportar tabla" aria-label="Exportar tabla">
              <i class="bi bi-download"></i>
            </button>
          </div>
        </div>
        <div class="table-responsive" id="attendanceSummaryCaptureArea">
          <table class="table table-sm table-bordered align-middle mb-0 summary-table" id="attendanceSummaryTable">
            <thead>
              <tr>
                <th>Habilitación CEO</th>
                <th>Proceso</th>
                <th>Capacitación</th>
                <th>Tipo de visita</th>
                <th class="text-end">Total asistentes</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($attendanceSummaryRows): ?>
              <?php foreach ($attendanceSummaryRows as $attendanceSummaryRow): ?>
                <tr>
                  <td><?= scdEsc($attendanceSummaryRow['habilitacionceo']) ?></td>
                  <td><?= scdEsc($attendanceSummaryRow['proceso']) ?></td>
                  <td><?= scdEsc($attendanceSummaryRow['capacitacion']) ?></td>
                  <td><?= scdEsc($attendanceSummaryRow['tipo_visita']) ?></td>
                  <td class="text-end summary-total"><?= (int)$attendanceSummaryRow['total'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-4">No hay asistentes para los filtros seleccionados.</td>
              </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="4">TOTAL</th>
                <th class="text-end summary-total"><?= (int)$attendanceSummaryGrandTotal ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="modal fade summary-detail-modal" id="summaryDetailModal" tabindex="-1" aria-labelledby="summaryDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content">
      <div class="modal-header px-4 py-3">
        <div>
          <h2 class="h5 mb-1" id="summaryDetailModalLabel">Detalle de personas</h2>
          <div class="small text-muted" id="summaryDetailModalMeta"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-4" id="summaryDetailModalBody">
        <div class="text-center text-muted py-4">Selecciona un total para ver su detalle.</div>
      </div>
    </div>
  </div>
</div>
<div class="summary-toast" id="summaryToast">Tabla copiada.</div>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const summaryRows = <?= $summaryJson ?: '[]' ?>;
  const attendanceSummaryRows = <?= $attendanceSummaryJson ?: '[]' ?>;
  const summaryCopyBtn = document.getElementById('summaryCopyBtn');
  const summaryExportBtn = document.getElementById('summaryExportBtn');
  const attendanceSummaryCopyBtn = document.getElementById('attendanceSummaryCopyBtn');
  const attendanceSummaryExportBtn = document.getElementById('attendanceSummaryExportBtn');
  const summaryToast = document.getElementById('summaryToast');
  const summaryCaptureArea = document.getElementById('summaryCaptureArea');
  const summaryTopicsTable = document.getElementById('summaryTopicsTable');
  const attendanceSummaryCaptureArea = document.getElementById('attendanceSummaryCaptureArea');
  const summaryModalElement = document.getElementById('resumenTemasModal');
  const attendanceSummaryModalElement = document.getElementById('resumenHabilitacionProcesoModal');
  const summaryDetailModalElement = document.getElementById('summaryDetailModal');
  const summaryDetailModalLabel = document.getElementById('summaryDetailModalLabel');
  const summaryDetailModalMeta = document.getElementById('summaryDetailModalMeta');
  const summaryDetailModalBody = document.getElementById('summaryDetailModalBody');
  const summaryModalInstance = summaryModalElement ? bootstrap.Modal.getOrCreateInstance(summaryModalElement) : null;
  const attendanceSummaryModalInstance = attendanceSummaryModalElement ? bootstrap.Modal.getOrCreateInstance(attendanceSummaryModalElement) : null;
  const summaryDetailModalInstance = summaryDetailModalElement ? bootstrap.Modal.getOrCreateInstance(summaryDetailModalElement) : null;
  const summaryPeriod = {
    desde: <?= json_encode($filters['fecha_desde'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    hasta: <?= json_encode($filters['fecha_hasta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  };
  const summaryGrandTotal = <?= (int)$summaryGrandTotal ?>;
  const attendanceSummaryGrandTotal = <?= (int)$attendanceSummaryGrandTotal ?>;
  let reopenSummaryOnDetailClose = false;

  function showSummaryToast(message) {
    if (!summaryToast) return;
    summaryToast.textContent = message;
    summaryToast.classList.add('is-visible');
    window.setTimeout(() => {
      summaryToast.classList.remove('is-visible');
    }, 1800);
  }

  function buildSummaryMatrix() {
    return [
      ['Tema', 'Total'],
      ...summaryRows.map((row) => [String(row.titulo ?? ''), String(row.total ?? 0)]),
      ['TOTAL', String(summaryGrandTotal)],
    ];
  }

  function buildAttendanceSummaryMatrix() {
    return [
      ['Habilitación CEO', 'Proceso', 'Capacitación', 'Tipo de visita', 'Total asistentes'],
      ...attendanceSummaryRows.map((row) => [String(row.habilitacionceo ?? ''), String(row.proceso ?? ''), String(row.capacitacion ?? ''), String(row.tipo_visita ?? ''), String(row.total ?? 0)]),
      ['TOTAL', '', '', '', String(attendanceSummaryGrandTotal)],
    ];
  }

  function escapeCsvValue(value) {
    const text = String(value ?? '');
    if (/[;"\n\r]/.test(text)) {
      return `"${text.replace(/"/g, '""')}"`;
    }
    return text;
  }

  function fallbackCopyText(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (error) {
      copied = false;
    }

    textarea.remove();
    return copied;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setSummaryDetailLoading(title, originLabel) {
    if (summaryDetailModalLabel) {
      summaryDetailModalLabel.textContent = `Detalle de personas - ${title}`;
    }
    if (summaryDetailModalMeta) {
      summaryDetailModalMeta.textContent = `Origen ${originLabel} • Cargando detalle...`;
    }
    if (summaryDetailModalBody) {
      summaryDetailModalBody.innerHTML = `
        <div class="d-flex flex-column align-items-center justify-content-center py-4 text-muted gap-2">
          <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
          <div>Cargando personas...</div>
        </div>
      `;
    }
  }

  function renderSummaryDetail(payload) {
    if (summaryDetailModalLabel) {
      summaryDetailModalLabel.textContent = `Detalle de personas - ${payload.titulo ?? ''}`;
    }
    if (summaryDetailModalMeta) {
      const totalRows = Array.isArray(payload.rows) ? payload.rows.length : 0;
      summaryDetailModalMeta.textContent = `Origen ${payload.origen_label ?? ''} • ${totalRows} registro(s)`;
    }
    if (!summaryDetailModalBody) {
      return;
    }

    if (!Array.isArray(payload.rows) || payload.rows.length === 0) {
      summaryDetailModalBody.innerHTML = '<div class="text-center text-muted py-4">No se encontraron personas para este tema en el período consultado.</div>';
      return;
    }

    const referenceLabel = escapeHtml(payload.reference_label ?? 'Referencia');
    const contextLabel = escapeHtml(payload.context_label ?? 'Contexto');
    const rowsHtml = payload.rows.map((row) => `
      <tr>
        <td>${escapeHtml(row.fecha ?? '')}</td>
        <td class="text-center">${escapeHtml(row.referencia ?? '')}</td>
        <td>${escapeHtml(row.empresa ?? '')}</td>
        <td>${escapeHtml(row.contexto ?? '')}</td>
        <td>${escapeHtml(row.rut ?? '')}</td>
        <td>${escapeHtml(row.persona ?? '')}</td>
        <td>${escapeHtml(row.cargo ?? '')}</td>
      </tr>
    `).join('');

    summaryDetailModalBody.innerHTML = `
      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0 summary-detail-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>${referenceLabel}</th>
              <th>Empresa</th>
              <th>${contextLabel}</th>
              <th>RUT</th>
              <th>Persona</th>
              <th>Cargo</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    `;
  }

  async function fetchSummaryDetail(summaryKey) {
    const url = new URL(window.location.href);
    url.searchParams.set('summary_detail', summaryKey);

    const response = await fetch(url.toString(), {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error(payload && payload.error ? payload.error : 'No fue posible cargar el detalle del tema.');
    }

    return payload;
  }

  async function copyTableAsImage(captureArea) {
    if (!captureArea || typeof html2canvas === 'undefined') {
      throw new Error('capture-unavailable');
    }

    const canvas = await html2canvas(captureArea, {
      backgroundColor: '#ffffff',
      scale: 2,
      useCORS: true,
    });

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    if (!blob) {
      throw new Error('blob-unavailable');
    }

    if (!navigator.clipboard || typeof window.ClipboardItem === 'undefined') {
      throw new Error('clipboard-image-unsupported');
    }

    await navigator.clipboard.write([
      new ClipboardItem({ 'image/png': blob })
    ]);
  }

  async function renderSummaryCanvas(captureArea) {
    if (!captureArea || typeof html2canvas === 'undefined') {
      throw new Error('capture-unavailable');
    }

    return html2canvas(captureArea, {
      backgroundColor: '#ffffff',
      scale: 2,
      useCORS: true,
    });
  }

  async function downloadSummaryPng(captureArea, filename) {
    const canvas = await renderSummaryCanvas(captureArea);
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  if (summaryCopyBtn) {
    summaryCopyBtn.addEventListener('click', async () => {
      try {
        await copyTableAsImage(summaryCaptureArea);
        showSummaryToast('Tabla copiada como imagen.');
      } catch (error) {
        try {
          await downloadSummaryPng(summaryCaptureArea, `resumen_temas_${summaryPeriod.desde}_${summaryPeriod.hasta}.png`);
          showSummaryToast('Tu navegador bloqueó el portapapeles. Se descargó un PNG.');
        } catch (downloadError) {
          const text = buildSummaryMatrix().map((row) => row.join('\t')).join('\n');
          try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
              await navigator.clipboard.writeText(text);
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            if (fallbackCopyText(text)) {
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            showSummaryToast('No fue posible copiar la tabla');
          } catch (fallbackError) {
            if (fallbackCopyText(text)) {
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            showSummaryToast('No fue posible copiar la tabla');
          }
        }
      }
    });
  }

  if (attendanceSummaryCopyBtn) {
    attendanceSummaryCopyBtn.addEventListener('click', async () => {
      try {
        await copyTableAsImage(attendanceSummaryCaptureArea);
        showSummaryToast('Tabla copiada como imagen.');
      } catch (error) {
        try {
          await downloadSummaryPng(attendanceSummaryCaptureArea, `resumen_habilitacion_proceso_${summaryPeriod.desde}_${summaryPeriod.hasta}.png`);
          showSummaryToast('Tu navegador bloqueó el portapapeles. Se descargó un PNG.');
        } catch (downloadError) {
          const text = buildAttendanceSummaryMatrix().map((row) => row.join('\t')).join('\n');
          try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
              await navigator.clipboard.writeText(text);
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            if (fallbackCopyText(text)) {
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            showSummaryToast('No fue posible copiar la tabla');
          } catch (fallbackError) {
            if (fallbackCopyText(text)) {
              showSummaryToast('No fue posible copiar la imagen. Se copió como texto.');
              return;
            }
            showSummaryToast('No fue posible copiar la tabla');
          }
        }
      }
    });
  }

  if (summaryExportBtn) {
    summaryExportBtn.addEventListener('click', () => {
      const csv = '\uFEFF' + buildSummaryMatrix()
        .map((row) => row.map(escapeCsvValue).join(';'))
        .join('\r\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `resumen_temas_${summaryPeriod.desde}_${summaryPeriod.hasta}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      showSummaryToast('Tabla exportada.');
    });
  }

  if (attendanceSummaryExportBtn) {
    attendanceSummaryExportBtn.addEventListener('click', () => {
      const csv = '\uFEFF' + buildAttendanceSummaryMatrix()
        .map((row) => row.map(escapeCsvValue).join(';'))
        .join('\r\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `resumen_habilitacion_proceso_${summaryPeriod.desde}_${summaryPeriod.hasta}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
      showSummaryToast('Tabla exportada.');
    });
  }

  if (summaryTopicsTable) {
    summaryTopicsTable.addEventListener('click', async (event) => {
      const trigger = event.target.closest('.summary-detail-btn');
      if (!trigger) {
        return;
      }

      const { summaryKey = '', summaryTitle = '', summaryOrigin = '' } = trigger.dataset;
      if (!summaryKey) {
        return;
      }

      const originalHtml = trigger.innerHTML;
      trigger.disabled = true;
      trigger.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
      setSummaryDetailLoading(summaryTitle, summaryOrigin);

      try {
        const payload = await fetchSummaryDetail(summaryKey);
        renderSummaryDetail(payload);
        reopenSummaryOnDetailClose = true;
        if (summaryModalElement && summaryModalElement.classList.contains('show') && summaryModalInstance && summaryDetailModalInstance) {
          summaryModalElement.addEventListener('hidden.bs.modal', () => {
            summaryDetailModalInstance.show();
          }, { once: true });
          summaryModalInstance.hide();
        } else if (attendanceSummaryModalElement && attendanceSummaryModalElement.classList.contains('show') && attendanceSummaryModalInstance && summaryDetailModalInstance) {
          attendanceSummaryModalElement.addEventListener('hidden.bs.modal', () => {
            summaryDetailModalInstance.show();
          }, { once: true });
          attendanceSummaryModalInstance.hide();
        } else {
          summaryDetailModalInstance?.show();
        }
      } catch (error) {
        showSummaryToast(error instanceof Error ? error.message : 'No fue posible cargar el detalle del tema.');
      } finally {
        trigger.disabled = false;
        trigger.innerHTML = originalHtml;
      }
    });
  }

  if (summaryDetailModalElement) {
    summaryDetailModalElement.addEventListener('hidden.bs.modal', () => {
      if (!reopenSummaryOnDetailClose) {
        return;
      }

      reopenSummaryOnDetailClose = false;
      summaryModalInstance?.show();
    });
  }
</script>
</body>
</html>
