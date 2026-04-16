-- Formaciones: esquema y carga inicial
-- Nota: ejecutar en la base noeticac_ceo

-- Secuencia propia de folio
CREATE TABLE IF NOT EXISTS `ceo_secuencia_formacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ultimo_numero` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `ceo_secuencia_formacion` (`id`, `ultimo_numero`)
SELECT 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `ceo_secuencia_formacion` WHERE `id` = 1);

-- Calendario propio de formaciones (equivalente a ceo_calendario)
CREATE TABLE IF NOT EXISTS `ceo_formacion_calendario` (
  `fecha` date NOT NULL,
  `estado` varchar(100) DEFAULT NULL COMMENT 'Disponible / No Disponible',
  `horainicio` time DEFAULT NULL,
  `id_patio` int(11) DEFAULT NULL,
  `nsolicitud` int(11) DEFAULT NULL,
  UNIQUE KEY `ceo_formacion_calendario_unique` (`fecha`,`id_patio`,`horainicio`),
  KEY `fk_formacion_calendario_servicio` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ceo_formacion_calendario` (`fecha`, `estado`, `horainicio`, `id_patio`, `nsolicitud`)
SELECT `fecha`, `estado`, `horainicio`, `id_patio`, NULL
FROM `ceo_calendario`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formacion_calendario` LIMIT 1);

-- Solicitudes de formacion (equivalente a ceo_solicitudes)
CREATE TABLE IF NOT EXISTS `ceo_formacion_solicitudes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nsolicitud` int(11) NOT NULL,
  `solicitante` int(11) NOT NULL,
  `patio` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `estado` varchar(1) NOT NULL,
  `contratista` int(11) NOT NULL,
  `tipohabilitacion` varchar(30) NOT NULL,
  `resphse` int(11) NOT NULL,
  `resplinea` int(11) NOT NULL,
  `proceso` int(11) NOT NULL,
  `habilitacionceo` int(11) NOT NULL,
  `fechacreacion` date NOT NULL,
  `fechacancelacion` date NOT NULL,
  `uo` int(11) NOT NULL,
  `servicio` int(11) NOT NULL,
  `responsable` int(11) NOT NULL,
  `observacion` varchar(200) NOT NULL,
  `charla` int(11) NOT NULL,
  `fechaaprueba` date NOT NULL,
  `usuarioaprueba` int(11) NOT NULL,
  `fechafinaliza` date NOT NULL,
  `usuariofinaliza` int(11) NOT NULL,
  `motivoreinduccion` int(11) NOT NULL,
  `numerohallazgo` varchar(30) NOT NULL,
  `horainicio` time NOT NULL,
  `horatermino` time NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_participantes_solicitud` (
  `id_solicitud` int(11) NOT NULL,
  `rut` varchar(15) NOT NULL,
  `nombre` varchar(300) NOT NULL,
  `apellidom` varchar(200) NOT NULL,
  `apellidop` varchar(200) NOT NULL,
  `id_cargo` int(11) NOT NULL,
  `asistio` varchar(1) NOT NULL,
  `observacion` varchar(300) NOT NULL,
  `autorizado` int(11) NOT NULL,
  `aprobo` varchar(30) DEFAULT NULL,
  `wf` varchar(20) DEFAULT NULL,
  `fechaasistio` datetime DEFAULT NULL,
  KEY `idx_formacion_participantes_solicitud` (`id_solicitud`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Formacion programada (equivalente a ceo_habilitacion)
CREATE TABLE IF NOT EXISTS `ceo_formacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `jornada` varchar(30) NOT NULL,
  `id_servicio` int(11) NOT NULL,
  `id_agrupacion` int(11) DEFAULT NULL,
  `cuadrilla` int(11) NOT NULL,
  `empresa` int(11) DEFAULT NULL,
  `uo` int(11) DEFAULT NULL,
  `gestor` int(11) DEFAULT NULL,
  `nsolicitud` int(11) DEFAULT NULL,
  `Estado` varchar(10) NOT NULL DEFAULT 'Pendiente',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_participantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_cuadrilla` int(11) NOT NULL,
  `reevaluo` int(11) NOT NULL,
  `rut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellidos` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cargo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_personas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_formacion` int(11) NOT NULL,
  `rut` varchar(12) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `apellidos` varchar(150) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `tipo_participacion` enum('EVALUADO','ACOMPANANTE','OBSERVADOR','NO_EVALUA') DEFAULT 'NO_EVALUA',
  `estado` enum('ACTIVO','NO_ASISTE','ELIMINADO') DEFAULT 'ACTIVO',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_formacion` (`id_formacion`,`rut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Evaluaciones programadas (solo teorica)
CREATE TABLE IF NOT EXISTS `ceo_formacion_programadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rut` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_servicio` int(11) NOT NULL,
  `id_agrupacion` int(11) DEFAULT NULL,
  `tipo` enum('PRUEBA') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cuadrilla` int(11) NOT NULL,
  `fecha_programacion` datetime DEFAULT current_timestamp(),
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_termino` datetime DEFAULT NULL,
  `cierre_modo` varchar(20) DEFAULT NULL,
  `usuario_programa` int(11) NOT NULL,
  `estado` enum('PENDIENTE','EJECUTADA','ANULADA') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'PENDIENTE',
  `intento` int(11) NOT NULL DEFAULT 1,
  `resultado` enum('PENDIENTE','APROBADO','REPROBADO','ANULADA') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'PENDIENTE',
  `fecha_resultado` datetime DEFAULT NULL,
  `cobrado` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_formacion_intento` (`rut`,`id_servicio`,`tipo`,`cuadrilla`,`intento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_resultado_formacion_intento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rut` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_servicio` int(11) NOT NULL,
  `id_evaluador` int(11) DEFAULT NULL,
  `fecha_rendicion` date NOT NULL,
  `hora_rendicion` time NOT NULL,
  `puntaje_total` decimal(5,2) DEFAULT NULL,
  `puntaje_obtenido` decimal(10,2) DEFAULT NULL,
  `puntaje_maximo` decimal(10,2) DEFAULT NULL,
  `correctas` int(11) DEFAULT NULL,
  `incorrectas` int(11) DEFAULT NULL,
  `ncontestadas` int(11) DEFAULT NULL,
  `noaplica` int(11) NOT NULL,
  `notafinal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_resultado_formacion_pruebat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rut` varchar(15) DEFAULT NULL,
  `id_pregunta` int(11) DEFAULT NULL,
  `respuesta` int(11) DEFAULT NULL,
  `respuesta_texto` text DEFAULT NULL,
  `puntaje_manual` int(11) DEFAULT NULL,
  `revisada` tinyint(1) NOT NULL DEFAULT 0,
  `observacion` text DEFAULT NULL,
  `fecha_rendicion` date DEFAULT NULL,
  `hora_rendicion` time DEFAULT NULL,
  `proceso` int(11) NOT NULL,
  `validacion` int(11) NOT NULL,
  `intento` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `fk_formacion_resultado_contratista` (`rut`),
  KEY `fk_formacion_resultado_pregunta` (`id_pregunta`),
  KEY `fk_formacion_alternativas_pregunta` (`respuesta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalogos de servicios y banco de preguntas (duplicados)
CREATE TABLE IF NOT EXISTS `ceo_formacion_servicios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `servicio` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_agrupacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(500) NOT NULL,
  `id_servicio` int(11) NOT NULL,
  `tiempo` time NOT NULL,
  `cantidad` int(11) NOT NULL,
  `total` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_areacompetencias_pct` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_servicio` int(11) NOT NULL,
  `id_area` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_formacion_area_servicio` (`id_servicio`,`id_area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ceo_formaciontipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `desc_tipo` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_preguntas_servicios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pregunta` varchar(500) DEFAULT NULL,
  `id_servicio` int(11) DEFAULT NULL,
  `imagen` varchar(100) DEFAULT NULL COMMENT 'Seria link ubicacion imagen',
  `estado` varchar(2) DEFAULT 'S',
  `id_agrupacion` int(11) NOT NULL COMMENT 'Asociado a ceo_agrupacion',
  `retropos` varchar(1000) DEFAULT NULL,
  `retroneg` varchar(1000) DEFAULT NULL,
  `areacomp` int(11) DEFAULT NULL,
  `peso` int(11) NOT NULL DEFAULT 1,
  `tipo_pregunta` varchar(20) NOT NULL DEFAULT 'ALT',
  `obligatoria` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_formacion_preguntas_servicios` (`id_servicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_formacion_alternativas_preguntas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alternativa` varchar(500) DEFAULT NULL,
  `id_pregunta` int(11) DEFAULT NULL,
  `estado` varchar(2) DEFAULT 'S',
  `imagen` varchar(200) DEFAULT NULL,
  `correcta` varchar(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_formacion_alternativa_pregunta` (`id_pregunta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carga inicial de catalogos y preguntas
INSERT INTO `ceo_formacion_servicios` (`id`, `servicio`, `descripcion`)
SELECT `id`, `servicio`, `descripcion`
FROM `ceo_servicios_pruebas`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formacion_servicios` LIMIT 1);

INSERT INTO `ceo_formacion_agrupacion` (`id`, `titulo`, `id_servicio`, `tiempo`, `cantidad`, `total`)
SELECT `id`, `titulo`, `id_servicio`, `tiempo`, `cantidad`, `total`
FROM `ceo_agrupacion`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formacion_agrupacion` LIMIT 1);

INSERT INTO `ceo_formaciontipo` (`id`, `desc_tipo`)
SELECT `id`, `desc_tipo`
FROM `ceo_habilitaciontipo`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formaciontipo` LIMIT 1);

INSERT INTO `ceo_formacion_preguntas_servicios`
  (`id`, `pregunta`, `id_servicio`, `imagen`, `estado`, `id_agrupacion`, `retropos`, `retroneg`, `areacomp`)
SELECT `id`, `pregunta`, `id_servicio`, `imagen`, `estado`, `id_agrupacion`, `retropos`, `retroneg`, `areacomp`
FROM `ceo_preguntas_servicios`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formacion_preguntas_servicios` LIMIT 1);

INSERT INTO `ceo_formacion_alternativas_preguntas`
  (`id`, `alternativa`, `id_pregunta`, `estado`, `imagen`, `correcta`)
SELECT `id`, `alternativa`, `id_pregunta`, `estado`, `imagen`, `correcta`
FROM `ceo_alternativas_preguntas`
WHERE NOT EXISTS (SELECT 1 FROM `ceo_formacion_alternativas_preguntas` LIMIT 1);

-- Ajustes para bases existentes
ALTER TABLE `ceo_formacion_programadas`
  ADD COLUMN `id_agrupacion` INT(11) NULL,
  ADD COLUMN `fecha_inicio` DATETIME NULL,
  ADD COLUMN `fecha_termino` DATETIME NULL,
  ADD COLUMN `cierre_modo` VARCHAR(20) NULL;

ALTER TABLE `ceo_formacion`
  ADD COLUMN `id_agrupacion` INT(11) NULL;

ALTER TABLE `ceo_formacion_preguntas_servicios`
  ADD COLUMN `peso` INT NOT NULL DEFAULT 1,
  ADD COLUMN `tipo_pregunta` VARCHAR(20) NOT NULL DEFAULT 'ALT',
  ADD COLUMN `obligatoria` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `ceo_resultado_formacion_intento`
  ADD COLUMN `puntaje_obtenido` DECIMAL(10,2) NULL,
  ADD COLUMN `puntaje_maximo` DECIMAL(10,2) NULL;

ALTER TABLE `ceo_resultado_formacion_pruebat`
  ADD COLUMN `respuesta_texto` TEXT NULL,
  ADD COLUMN `puntaje_manual` INT NULL,
  ADD COLUMN `revisada` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `observacion` TEXT NULL;
