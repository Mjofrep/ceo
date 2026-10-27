<?php
declare(strict_types=1);

const FORMACIONES_HUKA_EMPRESA = 78;
const FORMACIONES_HUKA_FECHA_MINIMA = '2026-01-01';

function fhEsc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fhFilters(array $source): array
{
    $fechaDesde = trim((string)($source['fecha_desde'] ?? ''));
    $fechaHasta = trim((string)($source['fecha_hasta'] ?? ''));
    $estado = strtoupper(trim((string)($source['estado'] ?? 'TODOS')));

    if (!in_array($estado, ['TODOS', 'APROBADO', 'REPROBADO', 'PENDIENTE'], true)) {
        $estado = 'TODOS';
    }
    if ($fechaDesde !== '' && $fechaDesde < FORMACIONES_HUKA_FECHA_MINIMA) {
        $fechaDesde = FORMACIONES_HUKA_FECHA_MINIMA;
    }

    return [
        'servicio' => max(0, (int)($source['servicio'] ?? 0)),
        'rut' => trim((string)($source['rut'] ?? '')),
        'fecha_desde' => $fechaDesde,
        'fecha_hasta' => $fechaHasta,
        'estado' => $estado,
    ];
}

function fhQueryString(array $filters): string
{
    return http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0));
}

function fhEnsureCertificateColumns(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_rie_aprobados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rut VARCHAR(20) NOT NULL,
        nombres VARCHAR(120) NOT NULL,
        apellidos VARCHAR(160) NOT NULL,
        cargo VARCHAR(160) NOT NULL DEFAULT '',
        empresa VARCHAR(180) NOT NULL,
        servicio VARCHAR(180) NOT NULL DEFAULT '',
        emitido TINYINT(1) NOT NULL DEFAULT 0,
        token_qr VARCHAR(128) NULL DEFAULT NULL,
        qr_actualizado TINYINT(1) NOT NULL DEFAULT 0,
        id_servicio INT NULL,
        id_programacion INT NULL,
        fecha_emision DATE NULL,
        fecha_vigencia DATE NULL,
        fecha_inicio DATE NULL,
        fecha_termino DATE NULL,
        plantilla_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rie_token_qr (token_qr),
        UNIQUE KEY uq_rie_programacion (id_programacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'id_servicio' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN id_servicio INT NULL AFTER servicio',
        'id_programacion' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN id_programacion INT NULL AFTER id_servicio',
        'fecha_emision' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN fecha_emision DATE NULL AFTER id_programacion',
        'fecha_vigencia' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN fecha_vigencia DATE NULL AFTER fecha_emision',
        'fecha_inicio' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN fecha_inicio DATE NULL AFTER fecha_vigencia',
        'fecha_termino' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN fecha_termino DATE NULL AFTER fecha_inicio',
        'plantilla_version' => 'ALTER TABLE ceo_rie_aprobados ADD COLUMN plantilla_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER fecha_termino',
    ];
    foreach ($columns as $column => $sql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM ceo_rie_aprobados LIKE '{$column}'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    }

    $stmt = $pdo->query("SHOW INDEX FROM ceo_rie_aprobados WHERE Key_name = 'uq_rie_rut'");
    if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE ceo_rie_aprobados DROP INDEX uq_rie_rut');
    }
    $stmt = $pdo->query("SHOW INDEX FROM ceo_rie_aprobados WHERE Key_name = 'uq_rie_programacion'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE ceo_rie_aprobados ADD UNIQUE KEY uq_rie_programacion (id_programacion)');
    }
}

function fhFetchRows(PDO $pdo, array $filters): array
{
    fhEnsureCertificateColumns($pdo);
    $where = ['f.fecha >= :fecha_minima', 's.contratista = :empresa'];
    $params = [
        ':fecha_minima' => FORMACIONES_HUKA_FECHA_MINIMA,
        ':empresa' => FORMACIONES_HUKA_EMPRESA,
    ];

    if ($filters['servicio'] > 0) {
        $where[] = 'f.id_servicio = :servicio';
        $params[':servicio'] = $filters['servicio'];
    }
    if ($filters['rut'] !== '') {
        $where[] = "REPLACE(REPLACE(REPLACE(UPPER(p.rut), '.', ''), '-', ''), ' ', '') LIKE :rut";
        $params[':rut'] = '%' . strtoupper(str_replace(['.', '-', ' '], '', $filters['rut'])) . '%';
    }
    if ($filters['fecha_desde'] !== '') {
        $where[] = 'f.fecha >= :fecha_desde';
        $params[':fecha_desde'] = $filters['fecha_desde'];
    }
    if ($filters['fecha_hasta'] !== '') {
        $where[] = 'f.fecha <= :fecha_hasta';
        $params[':fecha_hasta'] = $filters['fecha_hasta'];
    }
    if ($filters['estado'] !== 'TODOS') {
        $where[] = 'UPPER(TRIM(COALESCE(ep.resultado, "PENDIENTE"))) = :estado';
        $params[':estado'] = $filters['estado'];
    }

    $sql = '
        SELECT
            f.id AS id_formacion,
            f.cuadrilla,
            f.fecha,
            f.jornada,
            f.id_servicio,
            f.id_agrupacion,
            fs.servicio,
            e.nombre AS empresa,
            uo.desc_uo AS uo,
            p.rut,
            p.nombre,
            p.apellidos,
            ep.id AS id_programacion,
            ep.intento,
            ep.resultado,
            ep.fecha_inicio,
            ep.fecha_termino,
            ep.cierre_modo,
            ri.notafinal,
            ri.puntaje_total,
            ri.puntaje_obtenido,
            ri.puntaje_maximo,
            ri.correctas,
            ri.incorrectas,
            ri.ncontestadas,
            cert.id AS id_certificado,
            cert.emitido AS certificado_emitido
        FROM ceo_formacion f
        INNER JOIN ceo_solicitudes s ON s.nsolicitud = f.nsolicitud
        INNER JOIN ceo_formacion_participantes p ON p.id_cuadrilla = f.cuadrilla
        LEFT JOIN ceo_formacion_servicios fs ON fs.id = f.id_servicio
        LEFT JOIN ceo_empresas e ON e.id = s.contratista
        LEFT JOIN ceo_uo uo ON uo.id = f.uo
        LEFT JOIN ceo_formacion_programadas ep ON ep.id = (
            SELECT ep2.id
            FROM ceo_formacion_programadas ep2
            WHERE ep2.rut = p.rut
              AND ep2.id_servicio = f.id_servicio
              AND ep2.cuadrilla = f.cuadrilla
            ORDER BY ep2.id DESC
            LIMIT 1
        )
        LEFT JOIN ceo_resultado_formacion_intento ri ON ri.id = (
            SELECT ri2.id
            FROM ceo_resultado_formacion_intento ri2
            WHERE ri2.rut = p.rut
              AND ri2.id_servicio = f.id_servicio
              AND UPPER(TRIM(COALESCE(ep.resultado, "PENDIENTE"))) IN ("APROBADO", "REPROBADO")
              AND (ep.fecha_resultado IS NULL OR TIMESTAMP(ri2.fecha_rendicion, ri2.hora_rendicion) <= ep.fecha_resultado)
            ORDER BY TIMESTAMP(ri2.fecha_rendicion, ri2.hora_rendicion) DESC, ri2.id DESC
            LIMIT 1
        )
        LEFT JOIN ceo_rie_aprobados cert ON cert.id_programacion = ep.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY f.fecha DESC, fs.servicio ASC, p.apellidos ASC, p.nombre ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fhEstado(?string $estado): string
{
    $estado = strtoupper(trim((string)$estado));
    return $estado === '' ? 'PENDIENTE' : $estado;
}

function fhDuracion(?string $inicio, ?string $termino): string
{
    if (!$inicio || !$termino) {
        return '';
    }
    try {
        $segundos = (new DateTime($termino))->getTimestamp() - (new DateTime($inicio))->getTimestamp();
        return $segundos >= 0 ? (int)floor($segundos / 60) . ' min' : '';
    } catch (Throwable) {
        return '';
    }
}

function fhFetchAreaSummary(PDO $pdo, array $filters): array
{
    $rows = fhFetchRows($pdo, $filters);
    $serviceIds = array_values(array_unique(array_map(static fn (array $row): int => (int)$row['id_servicio'], $rows)));
    if (!$rows || count($serviceIds) !== 1) {
        throw new InvalidArgumentException('El resumen requiere resultados de un unico servicio.');
    }

    $idServicio = $serviceIds[0];
    $stmtAreas = $pdo->prepare('SELECT MIN(id) AS id_area, descripcion AS area FROM ceo_areacompetencia_formacion WHERE id_servicio = :id_servicio GROUP BY descripcion ORDER BY descripcion');
    $stmtAreas->execute([':id_servicio' => $idServicio]);
    $areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

    $participants = [];
    $programacionIds = [];
    $minimos = [];
    foreach ($rows as $row) {
        $idProgramacion = (int)($row['id_programacion'] ?? 0);
        if ($idProgramacion <= 0) {
            continue;
        }
        $idAgrupacion = (int)($row['id_agrupacion'] ?? 0);
        if (!isset($minimos[$idAgrupacion])) {
            $minimos[$idAgrupacion] = obtenerPorcentajeMinimoFormacionAgrupacion($pdo, $idAgrupacion);
        }
        $programacionIds[] = $idProgramacion;
        $participants[$idProgramacion] = [
            'rut' => (string)$row['rut'],
            'empresa' => (string)($row['empresa'] ?? ''),
            'nombre_completo' => trim((string)$row['nombre'] . ' ' . (string)$row['apellidos']),
            'nota_final' => $row['notafinal'] !== null ? number_format((float)$row['notafinal'], 2, '.', '') : '',
            'porcentaje_minimo' => $minimos[$idAgrupacion],
            'areas' => [],
        ];
    }

    if (!$participants) {
        return ['areas' => $areas, 'participants' => []];
    }

    $placeholders = [];
    $params = [':id_servicio' => $idServicio];
    foreach (array_values(array_unique($programacionIds)) as $index => $idProgramacion) {
        $placeholder = ':programacion_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $idProgramacion;
    }

    $sql = '
        SELECT ep.id AS id_programacion, MIN(ac.id) AS id_area,
               SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
               COUNT(*) AS total_preguntas
        FROM ceo_formacion_programadas ep
        INNER JOIN ceo_resultado_formacion_pruebat rpt ON rpt.rut = ep.rut AND rpt.proceso = ep.cuadrilla AND rpt.intento = ep.intento
        INNER JOIN ceo_formacion_preguntas_servicios ps ON ps.id = rpt.id_pregunta AND ps.id_servicio = ep.id_servicio
        LEFT JOIN ceo_areacompetencia_formacion ac ON ac.id = ps.areacomp AND ac.id_servicio = ps.id_servicio
        WHERE ep.id IN (' . implode(', ', $placeholders) . ')
          AND ps.id_servicio = :id_servicio
          AND ps.areacomp IS NOT NULL
          AND COALESCE(ps.tipo_pregunta, "ALT") <> "TEXTO_LIBRE"
        GROUP BY ep.id, ac.descripcion
        ORDER BY ac.descripcion';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idProgramacion = (int)$row['id_programacion'];
        $total = (float)$row['total_preguntas'];
        if (!isset($participants[$idProgramacion]) || $total <= 0) {
            continue;
        }
        $porcentaje = round(((float)$row['correctas'] / $total) * 100, 2);
        $minimo = (float)$participants[$idProgramacion]['porcentaje_minimo'];
        $participants[$idProgramacion]['areas'][(string)(int)$row['id_area']] = [
            'nota' => number_format(calcularNotaFinalDesdePorcentaje($porcentaje, $minimo), 2, '.', ''),
            'porcentaje' => number_format($porcentaje, 1, '.', ''),
            'aprobada' => $porcentaje >= $minimo,
        ];
    }

    return ['areas' => $areas, 'participants' => array_values($participants)];
}
