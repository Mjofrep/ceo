function obtenerCargoTrabajador(PDO $pdo, string $rut): ?int
{
    $sql = "
        SELECT id_cargo
        FROM ceo_contratistas
        WHERE rut = :rut
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':rut' => $rut]);

    $cargo = $stmt->fetchColumn();

    return $cargo !== false ? (int)$cargo : null;
}

function obtenerReglaPonderacion(
    PDO $pdo,
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

    $regla = $stmt->fetch(PDO::FETCH_ASSOC);

    return $regla ?: null;
}

function obtenerUltimaNotaTeorica(PDO $pdo, string $rut, int $idServicio, int $idProceso): ?array
{
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

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        return null;
    }

    return [
        'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
        'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
    ];
}

function obtenerUltimaNotaTerreno(PDO $pdo, string $rut, int $idServicio, int $idProceso): ?array
{
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

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila) {
        return null;
    }

    return [
        'nota'       => isset($fila['nota']) ? (float)$fila['nota'] : null,
        'porcentaje' => isset($fila['porcentaje']) ? (float)$fila['porcentaje'] : null
    ];
}

function obtenerUltimaNotaTeoricaPorAgrupacion(PDO $pdo, string $rut, int $idServicio, int $idProceso, int $idAgrupacion, float $porcentajeMinimo): ?array
{
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
        WHERE rpt.rut = :rut
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

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        return null;
    }

    $total = (int)($fila['total'] ?? 0);
    if ($total <= 0) {
        return null;
    }

    $correctas = (int)($fila['correctas'] ?? 0);
    $porcentaje = round(($correctas / $total) * 100, 2);
    $nota = calcularNotaFinalDesdePorcentaje($porcentaje, $porcentajeMinimo);

    return [
        'nota' => round($nota, 2),
        'porcentaje' => $porcentaje,
        'intento' => isset($fila['intento']) ? (int)$fila['intento'] : null,
        'fecha_hora' => (string)($fila['fecha_hora'] ?? ''),
    ];
}

function recalcularResultadoServicioLlee(PDO $pdo, string $rut, int $idServicio, int $idProceso, string $segmento = 'GENERAL'): array
{
    $cargo = obtenerCargoTrabajador($pdo, $rut);
    if ($cargo === null) {
        throw new RuntimeException("No se encontró cargo para el rut {$rut}");
    }

    $cfg = ceoGetLleeConfig();
    $theory = obtenerUltimaNotaTeoricaPorAgrupacion($pdo, $rut, $idServicio, $idProceso, (int)$cfg['theory_group_id'], (float)$cfg['theory_min_pct']);
    $hotline = obtenerUltimaNotaTeoricaPorAgrupacion($pdo, $rut, $idServicio, $idProceso, (int)$cfg['hotline_group_id'], (float)$cfg['hotline_min_pct']);
    $terrain = obtenerUltimaNotaTerreno($pdo, $rut, $idServicio, $idProceso);

    $notaPrueba = $theory['nota'] ?? null;
    $porcentajePrueba = $theory['porcentaje'] ?? null;
    $notaTerreno = $terrain['nota'] ?? null;
    $porcentajeTerreno = $terrain['porcentaje'] ?? null;
    $notaHotline = $hotline['nota'] ?? null;
    $porcentajeHotline = $hotline['porcentaje'] ?? null;

    $notaFinal = null;
    if ($notaPrueba !== null && $notaTerreno !== null) {
        $notaFinal = round((($notaPrueba + $notaTerreno) / 2), 2);
    }

    if ($porcentajePrueba === null) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
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
            'observacion' => 'Falta resultado de prueba LLEE'
        ];
    }

    if ($porcentajeHotline === null) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
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
            'observacion' => 'Falta resultado de prueba Hotline'
        ];
    }

    if ($porcentajeTerreno === null) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
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
            'observacion' => 'Falta resultado de terreno'
        ];
    }

    if ($porcentajePrueba < (float)$cfg['theory_min_pct']) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => 0.5,
            'ponderacion_terreno' => 0.5,
            'nota_final' => $notaFinal,
            'resultado_final' => 'REPROBADO',
            'observacion' => 'No aprueba prueba LLEE'
        ];
    }

    if ($porcentajeHotline < (float)$cfg['hotline_min_pct']) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => 0.5,
            'ponderacion_terreno' => 0.5,
            'nota_final' => $notaFinal,
            'resultado_final' => 'REPROBADO',
            'observacion' => 'No aprueba Hotline'
        ];
    }

    if ($porcentajeTerreno < (float)$cfg['terrain_min_pct']) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => 0.5,
            'ponderacion_terreno' => 0.5,
            'nota_final' => $notaFinal,
            'resultado_final' => 'REPROBADO',
            'observacion' => 'No aprueba terreno'
        ];
    }

    return [
        'rut' => $rut,
        'id_servicio' => $idServicio,
        'id_proceso' => $idProceso,
        'cargo' => $cargo,
        'segmento' => $segmento,
        'nota_prueba' => $notaPrueba,
        'nota_terreno' => $notaTerreno,
        'porcentaje_prueba' => $porcentajePrueba,
        'porcentaje_terreno' => $porcentajeTerreno,
        'ponderacion_prueba' => 0.5,
        'ponderacion_terreno' => 0.5,
        'nota_final' => $notaFinal,
        'resultado_final' => 'APROBADO',
        'observacion' => 'OK LLEE'
    ];
}

function recalcularResultadoServicio(
    PDO $pdo,
    string $rut,
    int $idServicio,
    int $idProceso,
    string $segmento = 'GENERAL',
    float $porcentajeMinimoAprobacion = 80.0
): array {
    if (ceoIsLleeService($idServicio)) {
        return recalcularResultadoServicioLlee($pdo, $rut, $idServicio, $idProceso, $segmento);
    }

    // ----------------------------------------------------
    // 1) Obtener cargo
    // ----------------------------------------------------
    $cargo = obtenerCargoTrabajador($pdo, $rut);

    if ($cargo === null) {
        throw new RuntimeException("No se encontró cargo para el rut {$rut}");
    }

    // ----------------------------------------------------
    // 2) Obtener regla
    // ----------------------------------------------------
    $regla = obtenerReglaPonderacion($pdo, $idServicio, $cargo, $segmento);

    if (!$regla) {
        throw new RuntimeException(
            "No existe regla de ponderación para servicio {$idServicio}, cargo {$cargo}, segmento {$segmento}"
        );
    }

    $pesoPrueba  = (float)$regla['ponderacion_prueba'];
    $pesoTerreno = (float)$regla['ponderacion_terreno'];

    $exigePruebaAprobada  = (($regla['exige_prueba_aprobada'] ?? 'S') === 'S');
    $exigeTerrenoAprobado = (($regla['exige_terreno_aprobado'] ?? 'S') === 'S');

    // ----------------------------------------------------
    // 3) Obtener resultados
    // ----------------------------------------------------
    $teorica = obtenerUltimaNotaTeorica($pdo, $rut, $idServicio, $idProceso);
    $terreno = obtenerUltimaNotaTerreno($pdo, $rut, $idServicio, $idProceso);

    $notaPrueba        = $teorica['nota'] ?? null;
    $porcentajePrueba  = $teorica['porcentaje'] ?? null;

    $notaTerreno       = $terreno['nota'] ?? null;
    $porcentajeTerreno = $terreno['porcentaje'] ?? null;

    // ----------------------------------------------------
    // 4) Validaciones previas
    // ----------------------------------------------------
    if ($pesoPrueba > 0 && ($notaPrueba === null || $porcentajePrueba === null)) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => $pesoPrueba,
            'ponderacion_terreno' => $pesoTerreno,
            'nota_final' => null,
            'resultado_final' => 'PENDIENTE',
            'observacion' => 'Falta resultado de prueba teórica'
        ];
    }

    if ($pesoTerreno > 0 && ($notaTerreno === null || $porcentajeTerreno === null)) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => $pesoPrueba,
            'ponderacion_terreno' => $pesoTerreno,
            'nota_final' => null,
            'resultado_final' => 'PENDIENTE',
            'observacion' => 'Falta resultado de terreno'
        ];
    }

    // ----------------------------------------------------
    // 5) Verificar aprobación individual
    // ----------------------------------------------------
    $apruebaPrueba = ($porcentajePrueba !== null && $porcentajePrueba >= $porcentajeMinimoAprobacion);
    $apruebaTerreno = ($porcentajeTerreno !== null && $porcentajeTerreno >= $porcentajeMinimoAprobacion);

    if ($exigePruebaAprobada && !$apruebaPrueba) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => $pesoPrueba,
            'ponderacion_terreno' => $pesoTerreno,
            'nota_final' => 0,
            'resultado_final' => 'REPROBADO',
            'observacion' => 'No aprueba prueba teórica'
        ];
    }

    if ($pesoTerreno > 0 && $exigeTerrenoAprobado && !$apruebaTerreno) {
        return [
            'rut' => $rut,
            'id_servicio' => $idServicio,
            'id_proceso' => $idProceso,
            'cargo' => $cargo,
            'segmento' => $segmento,
            'nota_prueba' => $notaPrueba,
            'nota_terreno' => $notaTerreno,
            'porcentaje_prueba' => $porcentajePrueba,
            'porcentaje_terreno' => $porcentajeTerreno,
            'ponderacion_prueba' => $pesoPrueba,
            'ponderacion_terreno' => $pesoTerreno,
            'nota_final' => 0,
            'resultado_final' => 'REPROBADO',
            'observacion' => 'No aprueba terreno'
        ];
    }

    // ----------------------------------------------------
    // 6) Calcular nota final
    // ----------------------------------------------------
    $notaFinal = 0.0;

    if ($pesoPrueba > 0 && $notaPrueba !== null) {
        $notaFinal += ($notaPrueba * $pesoPrueba);
    }

    if ($pesoTerreno > 0 && $notaTerreno !== null) {
        $notaFinal += ($notaTerreno * $pesoTerreno);
    }

    $notaFinal = round($notaFinal, 2);

    return [
        'rut' => $rut,
        'id_servicio' => $idServicio,
        'id_proceso' => $idProceso,
        'cargo' => $cargo,
        'segmento' => $segmento,
        'nota_prueba' => $notaPrueba,
        'nota_terreno' => $notaTerreno,
        'porcentaje_prueba' => $porcentajePrueba,
        'porcentaje_terreno' => $porcentajeTerreno,
        'ponderacion_prueba' => $pesoPrueba,
        'ponderacion_terreno' => $pesoTerreno,
        'nota_final' => $notaFinal,
        'resultado_final' => 'APROBADO',
        'observacion' => 'OK'
    ];
}

function guardarResultadoFinalServicio(PDO $pdo, array $resultado): void
{
    $sql = "
        INSERT INTO ceo_resultado_final_servicio
        (
            rut,
            id_servicio,
            id_proceso,
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
}

$resultadoFinal = recalcularResultadoServicio(
    $pdo,
    $rutAlumno,
    $idServicio,
    $cuadrilla,
    'GENERAL',
    80.0
);



