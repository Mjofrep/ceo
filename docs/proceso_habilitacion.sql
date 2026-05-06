CREATE TABLE IF NOT EXISTS ceo_proceso_habilitacion (
  id INT NOT NULL AUTO_INCREMENT,
  rut VARCHAR(20) NOT NULL,
  id_servicio INT NOT NULL,
  id_cargo INT NULL,
  numero_proceso INT NOT NULL,
  estado ENUM('ABIERTO','CERRADO','ANULADO') NOT NULL DEFAULT 'ABIERTO',
  origen VARCHAR(30) NOT NULL DEFAULT 'CEONEXT',
  fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proceso_habilitacion_numero (numero_proceso),
  KEY idx_proceso_habilitacion_abierto (rut, id_servicio, id_cargo, estado),
  KEY idx_proceso_habilitacion_servicio (id_servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ceo_evaluaciones_programadas
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER cuadrilla,
  ADD INDEX idx_eval_prog_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_eval_prog_rut_serv_proc (rut, id_servicio, id_proceso_habilitacion);

ALTER TABLE ceo_resultado_prueba_intento
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER id_servicio,
  ADD INDEX idx_rpi_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_rpi_rut_serv_proc (rut, id_servicio, id_proceso_habilitacion);

ALTER TABLE ceo_resultado_terreno_intento
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER id_servicio,
  ADD INDEX idx_rti_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_rti_rut_serv_proc (rut, id_servicio, id_proceso_habilitacion);

ALTER TABLE ceo_evaluacion_terreno
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER id_servicio,
  ADD INDEX idx_et_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_et_rut_serv_proc (rut, id_servicio, id_proceso_habilitacion);

ALTER TABLE ceo_vigencia_detalle
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER id_proceso,
  ADD INDEX idx_vd_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_vd_rut_serv_proc_hab (rut, id_servicio, id_proceso_habilitacion);

ALTER TABLE ceo_resultado_final_servicio
  ADD COLUMN id_proceso_habilitacion INT NULL AFTER id_proceso,
  ADD INDEX idx_rfs_proceso_hab (id_proceso_habilitacion),
  ADD INDEX idx_rfs_rut_serv_proc_hab (rut, id_servicio, id_proceso_habilitacion);

INSERT INTO ceo_proceso_habilitacion
  (rut, id_servicio, numero_proceso, estado, origen, fecha_inicio)
SELECT
  pendientes.rut,
  pendientes.id_servicio,
  COALESCE(base.max_numero, 0) + ROW_NUMBER() OVER (ORDER BY pendientes.fecha_inicio ASC, pendientes.rut ASC, pendientes.id_servicio ASC),
  'ABIERTO',
  'CEONEXT',
  pendientes.fecha_inicio
FROM (
  SELECT
    ep.rut,
    ep.id_servicio,
    COALESCE(MIN(ep.fecha_programacion), NOW()) AS fecha_inicio
  FROM ceo_evaluaciones_programadas ep
  LEFT JOIN ceo_proceso_habilitacion ph
    ON ph.rut = ep.rut
   AND ph.id_servicio = ep.id_servicio
   AND ph.estado = 'ABIERTO'
  WHERE ep.estado <> 'ANULADA'
    AND (ep.estado = 'PENDIENTE' OR ep.resultado IS NULL OR ep.resultado = 'PENDIENTE')
    AND ph.id IS NULL
  GROUP BY ep.rut, ep.id_servicio
) pendientes
CROSS JOIN (
  SELECT COALESCE(MAX(numero_proceso), 0) AS max_numero
  FROM ceo_proceso_habilitacion
) base;

UPDATE ceo_evaluaciones_programadas ep
INNER JOIN ceo_proceso_habilitacion ph
  ON ph.rut = ep.rut
 AND ph.id_servicio = ep.id_servicio
 AND ph.estado = 'ABIERTO'
SET ep.id_proceso_habilitacion = ph.id
WHERE ep.id_proceso_habilitacion IS NULL
  AND ep.estado <> 'ANULADA'
  AND (ep.estado = 'PENDIENTE' OR ep.resultado IS NULL OR ep.resultado = 'PENDIENTE');
