-- Recalculo conservador de terreno para servicio 22
-- Solo corrige intentos que hoy aparecen REPROBADO por puntaje_total < 80
--
-- Regla:
--   Alcanzo / Alcanzo parcial / Alzanzo / Alzanzo parcial = correcta
--   No alcanzo / No alzanzo = incorrecta
--   No se aplica / No aplica = no_aplica
--   no_aplica no entra al porcentaje

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
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                'Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial',
                'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial'
            ) THEN 1
            ELSE 0
        END
    ) AS correctas,
    SUM(
        CASE
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                'No alcanzo', 'No alcanzó', 'No alzanzo', 'No alzanzó'
            ) THEN 1
            ELSE 0
        END
    ) AS incorrectas,
    SUM(
        CASE
            WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                'No se aplica', 'No aplica'
            ) THEN 1
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
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                        'Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial',
                        'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial'
                    ) THEN 1
                    ELSE 0
                END
            ) * 100
        ) / NULLIF(
            SUM(
                CASE
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                        'Alcanzo', 'Alcanzo parcial', 'Alcanzó', 'Alcanzó parcial',
                        'Alzanzo', 'Alzanzo parcial', 'Alzanzó', 'Alzanzó parcial'
                    ) THEN 1
                    ELSE 0
                END
            ) +
            SUM(
                CASE
                    WHEN TRIM(etd.respuesta) COLLATE utf8mb4_general_ci IN (
                        'No alcanzo', 'No alcanzó', 'No alzanzo', 'No alzanzó'
                    ) THEN 1
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

DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_objetivo;
CREATE TEMPORARY TABLE tmp_terreno_serv22_objetivo AS
SELECT
    rti.id,
    rti.rut,
    rti.id_servicio,
    rti.id_proceso_habilitacion,
    rti.fecha_rendicion,
    rti.hora_rendicion
FROM ceo_resultado_terreno_intento rti
WHERE rti.id_servicio = 22
  AND COALESCE(rti.puntaje_total, 0) < 80;

-- Match principal: por proceso de habilitacion
DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_match_proceso;
CREATE TEMPORARY TABLE tmp_terreno_serv22_match_proceso AS
SELECT
    o.id AS id_intento,
    t.id_evaluacion,
    t.correctas,
    t.incorrectas,
    t.noaplica,
    t.ncontestadas,
    t.puntaje_total
FROM tmp_terreno_serv22_objetivo o
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.rut = o.rut
   AND t.id_servicio = o.id_servicio
   AND t.fecha_evaluacion = o.fecha_rendicion
   AND COALESCE(t.id_proceso_habilitacion, 0) = COALESCE(o.id_proceso_habilitacion, 0)
   AND COALESCE(o.id_proceso_habilitacion, 0) <> 0;

-- Match fallback: sin proceso, solo si existe una sola evaluacion ese dia
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

DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_match_dia;
CREATE TEMPORARY TABLE tmp_terreno_serv22_match_dia AS
SELECT
    o.id AS id_intento,
    t.id_evaluacion,
    t.correctas,
    t.incorrectas,
    t.noaplica,
    t.ncontestadas,
    t.puntaje_total
FROM tmp_terreno_serv22_objetivo o
INNER JOIN tmp_terreno_serv22_unico_dia u
    ON u.rut = o.rut
   AND u.id_servicio = o.id_servicio
   AND u.fecha_evaluacion = o.fecha_rendicion
   AND COALESCE(o.id_proceso_habilitacion, 0) = 0
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.id_evaluacion = u.id_evaluacion;

DROP TEMPORARY TABLE IF EXISTS tmp_terreno_serv22_match_final;
CREATE TEMPORARY TABLE tmp_terreno_serv22_match_final AS
SELECT * FROM tmp_terreno_serv22_match_proceso
UNION
SELECT * FROM tmp_terreno_serv22_match_dia;

-- 1) Actualiza solo los intentos objetivo
UPDATE ceo_resultado_terreno_intento rti
INNER JOIN tmp_terreno_serv22_match_final m
    ON m.id_intento = rti.id
SET
    rti.correctas = m.correctas,
    rti.incorrectas = m.incorrectas,
    rti.noaplica = m.noaplica,
    rti.ncontestadas = m.ncontestadas,
    rti.puntaje_total = COALESCE(m.puntaje_total, 0),
    rti.notafinal = ROUND(
        CASE
            WHEN COALESCE(m.puntaje_total, 0) <= 80
                THEN 1 + ((COALESCE(m.puntaje_total, 0) / 80) * 3)
            ELSE 4 + (((COALESCE(m.puntaje_total, 0) - 80) / 20) * 3)
        END,
        2
    )
WHERE rti.id_servicio = 22
  AND COALESCE(rti.puntaje_total, 0) < 80;

-- 2) Actualiza cabeceras de evaluacion solo de las evaluaciones afectadas
UPDATE ceo_evaluacion_terreno et
INNER JOIN (
    SELECT DISTINCT id_evaluacion
    FROM tmp_terreno_serv22_match_final
) a
    ON a.id_evaluacion = et.id
INNER JOIN tmp_terreno_serv22_resumen t
    ON t.id_evaluacion = et.id
SET et.resultado = t.puntaje_total
WHERE et.id_servicio = 22;

COMMIT;

-- Validacion 1: muestra solo intentos del servicio 22 con su estado final
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

-- Validacion 2: revisa cuantas filas fueron realmente vinculadas
SELECT COUNT(*) AS filas_corregidas
FROM tmp_terreno_serv22_match_final;

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
