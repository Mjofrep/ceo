<?php
declare(strict_types=1);

function hcrtTheoreticalResponses(PDO $pdo, array $headers): array
{
    $responses = [];

    $stmtAttempt = $pdo->prepare('
        SELECT rpt.intento
        FROM ceo_resultado_pruebat rpt
        INNER JOIN ceo_preguntas_servicios ps ON ps.id = rpt.id_pregunta AND ps.id_servicio = :id_servicio
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
          AND rpt.proceso = :cuadrilla
        ORDER BY rpt.fecha_rendicion DESC, rpt.hora_rendicion DESC, rpt.id DESC
        LIMIT 1
    ');
    $stmtResponses = $pdo->prepare('
        SELECT
            rpt.id,
            rpt.validacion,
            COALESCE(ac.descripcion, \'Sin área de competencia\') AS area_competencia,
            ps.pregunta,
            GROUP_CONCAT(
                DISTINCT CONCAT(CASE WHEN ap.correcta = \'S\' THEN \'[Correcta] \' ELSE \'\' END, COALESCE(ap.alternativa, \'\'))
                ORDER BY ap.id SEPARATOR \'\\n\'
            ) AS alternativas,
            MAX(CASE WHEN ap.correcta = \'S\' THEN ap.alternativa END) AS alternativa_correcta,
            COALESCE(ap_elegida.alternativa, \'No contestada\') AS alternativa_elegida
        FROM ceo_resultado_pruebat rpt
        INNER JOIN ceo_preguntas_servicios ps ON ps.id = rpt.id_pregunta AND ps.id_servicio = :id_servicio
        LEFT JOIN ceo_areacompetencias ac ON ac.id = ps.areacomp AND ac.id_servicio = ps.id_servicio
        LEFT JOIN ceo_alternativas_preguntas ap ON ap.id_pregunta = ps.id
        LEFT JOIN ceo_alternativas_preguntas ap_elegida ON ap_elegida.id = rpt.respuesta
        WHERE REPLACE(REPLACE(REPLACE(UPPER(rpt.rut), \'.\', \'\'), \'-\', \'\'), \' \', \'\') = :rut
          AND rpt.proceso = :cuadrilla
          AND rpt.intento = :intento
          AND ps.areacomp IS NOT NULL
        GROUP BY rpt.id, rpt.validacion, ac.descripcion, ps.pregunta, ap_elegida.alternativa
        ORDER BY ac.descripcion ASC, rpt.id_pregunta ASC
    ');

    foreach ($headers as $header) {
        foreach (hcaParticipants($pdo, $header) as $participant) {
            // Match the same latest theoretical attempt used by the current area summary.
            $result = hcaLatestResult($pdo, 'ceo_resultado_prueba_intento', $participant, $header);
            if (!$result) {
                continue;
            }

            $stmtAttempt->execute([
                ':id_servicio' => (int)$header['id_servicio'],
                ':rut' => hcaNormalizeRut((string)$participant['rut']),
                ':cuadrilla' => (int)$header['cuadrilla'],
            ]);
            $attempt = $stmtAttempt->fetchColumn();
            if ($attempt === false) {
                continue;
            }

            $stmtResponses->execute([
                ':id_servicio' => (int)$header['id_servicio'],
                ':rut' => hcaNormalizeRut((string)$participant['rut']),
                ':cuadrilla' => (int)$header['cuadrilla'],
                ':intento' => (int)$attempt,
            ]);

            foreach ($stmtResponses->fetchAll(PDO::FETCH_ASSOC) as $response) {
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
