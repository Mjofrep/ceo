-- Recalculo de terreno para servicio 22
-- Regla:
--   Alcanzo / Alcanzo parcial / Alzanzo / Alzanzo parcial = correcta
--   No alcanzo / No alzanzo = incorrecta
--   No se aplica / No aplica = no_aplica
--   no_aplica no entra al porcentaje
--
-- Recomendacion:
--   1. ejecutar primero en ambiente de prueba o con respaldo
--   2. revisar las consultas de validacion al final

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_resumen;
CREATE TEMPORARY TABLE tmp_terreno_serv22_resumen AS
SELECT
    et.id AS id_evaluacion,
    et.rut,
    et.id_servicio,
    et.id_proceso_habilitacion,
    et.fecha_evaluacion,
    SUM(
        CASE
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial', 'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial')
                THEN 1
            ELSE 0
        END
    ) AS correctas,
    SUM(
        CASE
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('No alcanzo', 'No alcanzó', 'No alzanzo', 'No alzanzó')
                THEN 1
            ELSE 0
        END
    ) AS incorrectas,
    SUM(
        CASE
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('No se aplica', 'No aplica')
                THEN 1
            ELSE 0
        END
    ) AS noaplica,
    SUM(
        CASE
            WHEN TRIM(COALESCE(etd.respuesta, '')) = ''
                OR TRIM(etd.respuesta) COLLATE utf8mb4_general_ci NOT IN (
                    'Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial',
                    'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial',
                    'No alcanzo', 'No alcanzó', 'No alzanzo', 'No alzanzó',
                    'No se aplica', 'No aplica'
                )
                THEN 1
            ELSE 0
        END
    ) AS ncontestadas,
    ROUND(
        (
            SUM(
                CASE
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial', 'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial')
                        THEN 1
                    ELSE 0
                END
            ) * 100
        ) / NULLIF(
            SUM(
                CASE
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial', 'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial')
                        THEN 1
                    ELSE 0
                END
            ) +
            SUM(
                CASE
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN ('No alcanzo', 'No alcanzó', 'No alzanzo', 'No alzanzó')
                        THEN 1
                    ELSE 0
                END
            ),
            0
        ),
        2
    ) AS puntaje_total
FROM ceo_evaluacion_terreno et
INNER JOIN ceo_evaluacion_terreno_detalle etd
    ON etd.id_evaluacion_terreno = et.id
WHERE et.id_servicio = 22
GROUP BY
    et.id,
    et.rut,
    et.id_servicio,
    et.id_proceso_habilitacion,
    et.fecha_evaluacion;

-- 1) Actualiza el porcentaje guardado en la cabecera de la evaluacion terreno
UPDATE ceo_evaluacion_terreno et
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.id_evaluacion = et.id
SET et.resultado = t.puntaje_total
WHERE et.id_servicio = 22;

-- 2) Actualiza los intentos cuando existe vinculo por proceso de habilitacion
UPDATE ceo_resultado_terreno_intento rti
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.rut = rti.rut
   AND t.id_servicio = rti.id_servicio
   AND t.fecha_evaluacion = rti.fecha_rendicion
   AND COALESCE(t.id_proceso_habilitacion, 0) = COALESCE(rti.id_proceso_habilitacion, 0)
   AND COALESCE(rti.id_proceso_habilitacion, 0) <> 0
SET
    rti.correctas = t.correctas,
    rti.incorrectas = t.incorrectas,
    rti.noaplica = t.noaplica,
    rti.ncontestadas = t.ncontestadas,
    rti.puntaje_total = COALESCE(t.puntaje_total, 0),
    rti.notafinal = ROUND(
        CASE
            WHEN COALESCE(t.puntaje_total, 0) <= 80
                THEN 1 + ((COALESCE(t.puntaje_total, 0) / 80) * 3)
            ELSE 4 + (((COALESCE(t.puntaje_total, 0) - 80) / 20) * 3)
        END,
        2
    )
WHERE rti.id_servicio = 22;

-- 3) Fallback para casos sin proceso, solo cuando hay una sola evaluacion por rut + fecha
DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_unico_dia;
CREATE TEMPORARY TABLE tmp_terreno_serv22_unico_dia AS
SELECT
    rut,
    id_servicio,
    fecha_evaluacion,
    MIN(id_evaluacion) AS id_evaluacion
FROM tmp_terreno_serv22_resumen
GROUP BY rut, id_servicio, fecha_evaluacion
HAVING COUNT(*) = 1;

UPDATE ceo_resultado_terreno_intento rti
INNER JOIN tmp_terreno_serv22_unico_dia u
    ON u.rut = rti.rut
   AND u.id_servicio = rti.id_servicio
   AND u.fecha_evaluacion = rti.fecha_rendicion
   AND COALESCE(rti.id_proceso_habilitacion, 0) = 0
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.id_evaluacion = u.id_evaluacion
SET
    rti.correctas = t.correctas,
    rti.incorrectas = t.incorrectas,
    rti.noaplica = t.noaplica,
    rti.ncontestadas = t.ncontestadas,
    rti.puntaje_total = COALESCE(t.puntaje_total, 0),
    rti.notafinal = ROUND(
        CASE
            WHEN COALESCE(t.puntaje_total, 0) <= 80
                THEN 1 + ((COALESCE(t.puntaje_total, 0) / 80) * 3)
            ELSE 4 + (((COALESCE(t.puntaje_total, 0) - 80) / 20) * 3)
        END,
        2
    )
WHERE rti.id_servicio = 22;

COMMIT;

-- Validacion 1: estado final por puntaje
SELECT
    rut,
    id_servicio,
    fecha_rendicion,
    hora_rendicion,
    puntaje_total,
    correctas,
    incorrectas,
    noaplica,
    ncontestadas,
    CASE
        WHEN puntaje_total >= 80 THEN 'APROBADO'
        ELSE 'REPROBADO'
    END AS estado
FROM ceo_resultado_terreno_intento
WHERE id_servicio = 22
ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC;

-- Validacion 2: compara porcentaje guardado versus porcentaje recalculado desde conteo
SELECT
    rut,
    fecha_rendicion,
    hora_rendicion,
    puntaje_total,
    ROUND((correctas * 100) / NULLIF(correctas + incorrectas, 0), 2) AS puntaje_recalculado,
    correctas,
    incorrectas,
    noaplica,
    ncontestadas
FROM ceo_resultado_terreno_intento
WHERE id_servicio = 22
ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC;

-- Validacion 3: detecta casos ambiguos con mas de una evaluacion el mismo rut + fecha
SELECT
    rut,
    fecha_evaluacion,
    COUNT(*) AS evaluaciones_mismo_dia
FROM ceo_evaluacion_terreno
WHERE id_servicio = 22
GROUP BY rut, fecha_evaluacion
HAVING COUNT(*) > 1
ORDER BY fecha_evaluacion, rut;
