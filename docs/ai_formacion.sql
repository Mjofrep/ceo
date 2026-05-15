CREATE TABLE IF NOT EXISTS ceo_ai_formacion_fuentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  id_servicio INT NULL,
  tipo_origen ENUM('MANUAL','TXT') NOT NULL DEFAULT 'MANUAL',
  nombre_archivo VARCHAR(255) NULL,
  ruta_archivo VARCHAR(500) NULL,
  texto_fuente MEDIUMTEXT NOT NULL,
  estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_fuente_servicio (id_servicio),
  INDEX idx_ai_fuente_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_ai_formacion_generaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_fuente INT NOT NULL,
  id_servicio INT NOT NULL,
  id_agrupacion INT NOT NULL,
  id_area INT NOT NULL,
  cantidad_solicitada INT NOT NULL,
  dificultad VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
  modelo VARCHAR(80) NOT NULL,
  prompt_text LONGTEXT NOT NULL,
  respuesta_json LONGTEXT NOT NULL,
  estado ENUM('GENERADA','REVISADA','GUARDADA','ERROR') NOT NULL DEFAULT 'GENERADA',
  error_text TEXT NULL,
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_gen_fuente (id_fuente),
  INDEX idx_ai_gen_servicio (id_servicio),
  INDEX idx_ai_gen_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_ai_formacion_borradores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_generacion INT NOT NULL,
  orden_item INT NOT NULL DEFAULT 0,
  pregunta TEXT NOT NULL,
  alternativas_json LONGTEXT NOT NULL,
  correcta_index INT NOT NULL DEFAULT 0,
  retropos TEXT NULL,
  retroneg TEXT NULL,
  referencia TEXT NULL,
  estado ENUM('BORRADOR','GUARDADA','DESCARTADA') NOT NULL DEFAULT 'BORRADOR',
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_borrador_generacion (id_generacion),
  INDEX idx_ai_borrador_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
