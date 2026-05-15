CREATE TABLE IF NOT EXISTS ceo_certificados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_certificado INT NOT NULL UNIQUE,
  token VARCHAR(128) NOT NULL UNIQUE,
  rut VARCHAR(20) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  apellidos VARCHAR(160) NOT NULL,
  cargo VARCHAR(160) NOT NULL,
  id_empresa INT NULL,
  empresa VARCHAR(180) NOT NULL,
  id_servicio INT NOT NULL,
  servicio VARCHAR(180) NOT NULL,
  id_proceso INT NULL,
  id_vigencia_general INT NULL,
  id_vigencia_detalle INT NULL,
  fecha_evaluacion DATE NULL,
  fechavig_ini DATE NOT NULL,
  fechavig_fin DATE NOT NULL,
  nombre_archivo VARCHAR(255) NOT NULL,
  ruta_archivo VARCHAR(500) NOT NULL,
  estado ENUM('VIGENTE','REEMPLAZADO','ANULADO') NOT NULL DEFAULT 'VIGENTE',
  generado_por INT NULL,
  fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reemplazado_por INT NULL,
  fecha_reemplazo DATETIME NULL,
  motivo_anulacion VARCHAR(255) NULL,
  fecha_anulacion DATETIME NULL,
  INDEX idx_cert_rut (rut),
  INDEX idx_cert_servicio (id_servicio),
  INDEX idx_cert_empresa (id_empresa),
  INDEX idx_cert_estado (estado),
  INDEX idx_cert_vigencia (fechavig_fin),
  INDEX idx_cert_proceso (id_proceso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_certificado_envio (
  id INT AUTO_INCREMENT PRIMARY KEY,
  para TEXT NOT NULL,
  cc TEXT NULL,
  asunto VARCHAR(255) NOT NULL,
  cuerpo TEXT NULL,
  enviado_por INT NULL,
  estado ENUM('ENVIADO','ERROR') NOT NULL,
  error TEXT NULL,
  fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_certificado_envio_detalle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_envio INT NOT NULL,
  id_certificado INT NOT NULL,
  INDEX idx_envio (id_envio),
  INDEX idx_certificado (id_certificado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
