CREATE TABLE IF NOT EXISTS `ceo_formacion_ciclo1_inspectores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rut` varchar(20) NOT NULL,
  `grupo_excel` varchar(100) DEFAULT NULL,
  `hoja_origen` varchar(100) NOT NULL DEFAULT 'Ciclo 1',
  `fila_origen` int DEFAULT NULL,
  `prueba_c_integrada_raw` varchar(50) DEFAULT NULL,
  `prueba_c_integrada` decimal(8,4) DEFAULT NULL,
  `archivo_origen` varchar(255) DEFAULT NULL,
  `cargado_por` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_formacion_ciclo1_inspectores_rut` (`rut`),
  KEY `idx_formacion_ciclo1_inspectores_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
