CREATE TABLE IF NOT EXISTS `ceo_habilitacion_areascompetencias_pct` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_servicio` int(11) NOT NULL,
  `id_area` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_habilitacion_area_servicio` (`id_servicio`,`id_area`),
  KEY `idx_habilitacion_pct_servicio` (`id_servicio`),
  KEY `idx_habilitacion_pct_area` (`id_area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
