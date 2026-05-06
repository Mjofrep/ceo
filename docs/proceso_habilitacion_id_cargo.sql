ALTER TABLE ceo_proceso_habilitacion
  ADD COLUMN id_cargo INT NULL AFTER id_servicio,
  DROP INDEX idx_proceso_habilitacion_abierto,
  ADD INDEX idx_proceso_habilitacion_abierto (rut, id_servicio, id_cargo, estado);

UPDATE ceo_proceso_habilitacion ph
INNER JOIN (
  SELECT rut, id_servicio, MIN(id_cargo) AS id_cargo
  FROM ceo_servicios_rut
  WHERE id_cargo IS NOT NULL AND id_cargo > 0
  GROUP BY rut, id_servicio
) sr
  ON sr.rut = ph.rut
 AND sr.id_servicio = ph.id_servicio
SET ph.id_cargo = sr.id_cargo
WHERE ph.id_cargo IS NULL;
