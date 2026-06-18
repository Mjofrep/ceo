-- Inventario CEO - Fase 1
-- Base operativa: catalogo, stock y movimientos simples
-- Ejecutar en la base noeticac_ceo

CREATE TABLE IF NOT EXISTS `ceo_inv_categoria` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` char(1) NOT NULL DEFAULT 'A',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceo_inv_categoria_nombre` (`nombre`),
  KEY `idx_ceo_inv_categoria_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_inv_tipo_control` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceo_inv_tipo_control_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_inv_ubicacion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `estado` char(1) NOT NULL DEFAULT 'A',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceo_inv_ubicacion_nombre` (`nombre`),
  KEY `idx_ceo_inv_ubicacion_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_inv_producto` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `codigo_interno` varchar(60) DEFAULT NULL,
  `nombre` varchar(180) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `id_categoria` int unsigned NOT NULL,
  `id_tipo_control` int unsigned NOT NULL,
  `unidad_medida` varchar(30) NOT NULL DEFAULT 'UN',
  `stock_minimo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `usa_serie` tinyint(1) NOT NULL DEFAULT 0,
  `requiere_responsable_salida` tinyint(1) NOT NULL DEFAULT 0,
  `controla_stock` tinyint(1) NOT NULL DEFAULT 1,
  `activo` char(1) NOT NULL DEFAULT 'A',
  `creado_por` int DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_por` int DEFAULT NULL,
  `actualizado_en` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceo_inv_producto_codigo` (`codigo_interno`),
  KEY `idx_ceo_inv_producto_categoria` (`id_categoria`),
  KEY `idx_ceo_inv_producto_tipo` (`id_tipo_control`),
  KEY `idx_ceo_inv_producto_estado` (`activo`),
  CONSTRAINT `fk_ceo_inv_producto_categoria`
    FOREIGN KEY (`id_categoria`) REFERENCES `ceo_inv_categoria` (`id`),
  CONSTRAINT `fk_ceo_inv_producto_tipo_control`
    FOREIGN KEY (`id_tipo_control`) REFERENCES `ceo_inv_tipo_control` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_inv_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_producto` int unsigned NOT NULL,
  `codigo_item` varchar(80) DEFAULT NULL,
  `numero_serie` varchar(120) DEFAULT NULL,
  `marca` varchar(120) DEFAULT NULL,
  `modelo` varchar(120) DEFAULT NULL,
  `estado_item` varchar(40) NOT NULL DEFAULT 'DISPONIBLE',
  `ubicacion_actual` varchar(150) DEFAULT NULL,
  `responsable_actual` varchar(150) DEFAULT NULL,
  `fecha_compra` date DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `activo` char(1) NOT NULL DEFAULT 'A',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceo_inv_item_codigo` (`codigo_item`),
  UNIQUE KEY `uq_ceo_inv_item_serie` (`numero_serie`),
  KEY `idx_ceo_inv_item_producto` (`id_producto`),
  KEY `idx_ceo_inv_item_estado` (`estado_item`),
  CONSTRAINT `fk_ceo_inv_item_producto`
    FOREIGN KEY (`id_producto`) REFERENCES `ceo_inv_producto` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_inv_movimiento` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tipo_movimiento` varchar(20) NOT NULL,
  `id_producto` int unsigned NOT NULL,
  `id_item` int unsigned DEFAULT NULL,
  `cantidad` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fecha_movimiento` datetime NOT NULL DEFAULT current_timestamp(),
  `entregado_a` varchar(180) DEFAULT NULL,
  `rut_entregado_a` varchar(20) DEFAULT NULL,
  `area_destino` varchar(180) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `documento_referencia` varchar(120) DEFAULT NULL,
  `id_movimiento_relacionado` bigint unsigned DEFAULT NULL,
  `estado_resultante` varchar(50) DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `registrado_por` int DEFAULT NULL,
  `registrado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ceo_inv_movimiento_producto` (`id_producto`),
  KEY `idx_ceo_inv_movimiento_item` (`id_item`),
  KEY `idx_ceo_inv_movimiento_tipo` (`tipo_movimiento`),
  KEY `idx_ceo_inv_movimiento_fecha` (`fecha_movimiento`),
  KEY `idx_ceo_inv_movimiento_usuario` (`registrado_por`),
  KEY `idx_ceo_inv_movimiento_relacionado` (`id_movimiento_relacionado`),
  CONSTRAINT `fk_ceo_inv_movimiento_producto`
    FOREIGN KEY (`id_producto`) REFERENCES `ceo_inv_producto` (`id`),
  CONSTRAINT `fk_ceo_inv_movimiento_item`
    FOREIGN KEY (`id_item`) REFERENCES `ceo_inv_item` (`id`),
  CONSTRAINT `fk_ceo_inv_movimiento_relacionado`
    FOREIGN KEY (`id_movimiento_relacionado`) REFERENCES `ceo_inv_movimiento` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ceo_inv_categoria` (`nombre`, `descripcion`, `estado`)
VALUES
  ('Alimentos', 'Jugos, colaciones y otros articulos de consumo rapido.', 'A'),
  ('Equipamiento tecnologico', 'Computadores, tablets, radios, datashow y perifericos.', 'A'),
  ('Herramientas', 'Herramientas manuales, electricas y accesorios.', 'A'),
  ('EPP', 'Elementos de proteccion personal y ropa de trabajo.', 'A')
ON DUPLICATE KEY UPDATE
  `descripcion` = VALUES(`descripcion`),
  `estado` = VALUES(`estado`);

INSERT INTO `ceo_inv_tipo_control` (`codigo`, `nombre`, `descripcion`)
VALUES
  ('SIMPLE', 'Solo inventario', 'Producto catalogado con ajustes manuales y sin flujo diario obligatorio.'),
  ('CONSUMIBLE', 'Consumible', 'Controla entradas y salidas por cantidad.'),
  ('PRESTAMO', 'Prestable', 'Pensado para entregas con devolucion y responsable.'),
  ('SERIALIZADO', 'Serializado', 'Permite trazabilidad por unidad o numero de serie.')
ON DUPLICATE KEY UPDATE
  `nombre` = VALUES(`nombre`),
  `descripcion` = VALUES(`descripcion`);

INSERT INTO `ceo_inv_ubicacion` (`nombre`, `descripcion`, `estado`)
VALUES
  ('Bodega central', 'Ubicacion principal para resguardo del inventario.', 'A'),
  ('Sala de capacitacion', 'Elementos disponibles para jornadas y cursos.', 'A'),
  ('Oficina administrativa', 'Stock menor de apoyo administrativo.', 'A'),
  ('Terreno', 'Material en uso operacional fuera de oficina.', 'A')
ON DUPLICATE KEY UPDATE
  `descripcion` = VALUES(`descripcion`),
  `estado` = VALUES(`estado`);
