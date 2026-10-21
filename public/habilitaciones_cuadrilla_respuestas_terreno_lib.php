<?php
declare(strict_types=1);

function hcrtrTerrainResponses(PDO $pdo, array $headers): array
{
    $responses = [];
    $stmtResponses = $pdo->prepare('
        SELECT
            COALESCE(NULLIF(TRIM(s.nombre), \'\'), NULLIF(TRIM(s.seccion), \'\'), \'Sin área de competencia\') AS area_competencia,
            p.pregunta,
            COALESCE(p.ponderacion, 0) AS ponderacion,
            rpt.cumple,
            rpt.no_cumple,
            rpt.no_aplica
        FROM ceo_seccion_resultado_terreno srt
        INNER JOIN ceo_resultado_prueba_terreno rpt ON rpt.id_resultado = srt.id
        INNER JOIN ceo_seccion_terreno s ON s.id = rpt.id_seccion AND s.orden > 1
        INNER JOIN ceo_preguntas_seccion_terreno p ON p.id = rpt.id_pregunta
        WHERE srt.id_servicio = :id_servicio
          AND srt.nsolicitud = :cuadrilla
          AND srt.fecha_examen = :fecha
          AND TIME(srt.hora_examen) = TIME(:hora)
          AND REPLACE(REPLACE(REPLACE(UPPER(rpt.rut_contratista), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
        ORDER BY s.orden ASC, p.orden ASC, p.id ASC
    ');

    foreach ($headers as $header) {
        foreach (hcaParticipants($pdo, $header) as $participant) {
            $result = hcaLatestResult($pdo, 'ceo_resultado_terreno_intento', $participant, $header);
            if (!$result) {
                continue;
            }

            $stmtResponses->execute([
                ':id_servicio' => (int)$header['id_servicio'],
                ':cuadrilla' => (int)$header['cuadrilla'],
                ':fecha' => (string)$result['fecha_rendicion'],
                ':hora' => (string)$result['hora_rendicion'],
                ':rut' => hcaNormalizeRut((string)$participant['rut']),
            ]);

            foreach ($stmtResponses->fetchAll(PDO::FETCH_ASSOC) as $response) {
                if ((int)$response['no_cumple'] === 1) {
                    $response['resultado'] = 'NO CUMPLE';
                } elseif ((int)$response['no_aplica'] === 1) {
                    $response['resultado'] = 'NO APLICA';
                } else {
                    $response['resultado'] = 'CUMPLE';
                }
                $response['cuadrilla'] = (string)$header['cuadrilla'];
                $response['rut'] = (string)$participant['rut'];
                $response['empresa'] = (string)$header['empresa'];
                $response['nombre'] = (string)$participant['nombre'];
                $response['apellidos'] = (string)$participant['apellidos'];
                $responses[] = $response;
            }
        }
    }

    return $responses;
}
