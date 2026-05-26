CREATE TABLE IF NOT EXISTS ceo_gp_roles (
  id INT NOT NULL AUTO_INCREMENT,
  codigo VARCHAR(30) NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) NULL,
  estado ENUM('A','I') NOT NULL DEFAULT 'A',
  PRIMARY KEY (id),
  UNIQUE KEY uq_gp_roles_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ceo_gp_roles (codigo, nombre, descripcion)
VALUES
  ('ADMIN', 'Administrador', 'Administra usuarios, roles y configuracion del Gestor de Preguntas'),
  ('CREADOR', 'Creador', 'Carga fuentes y genera preguntas manuales o asistidas por IA'),
  ('REVISOR', 'Revisor', 'Revisa, corrige y envia preguntas a Operacion'),
  ('OPERACION', 'Operacion', 'Valida contenido, alternativas y respuesta correcta'),
  ('PUBLICADOR', 'Publicador', 'Cierra y publica preguntas oficiales')
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  descripcion = VALUES(descripcion),
  estado = 'A';

CREATE TABLE IF NOT EXISTS ceo_gp_usuarios (
  id INT NOT NULL AUTO_INCREMENT,
  usuario VARCHAR(80) NOT NULL,
  nombres VARCHAR(120) NOT NULL,
  apellidos VARCHAR(160) NULL,
  correo VARCHAR(160) NULL,
  clave_hash VARCHAR(255) NOT NULL,
  id_rol INT NOT NULL,
  estado ENUM('A','I') NOT NULL DEFAULT 'A',
  creado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  ultimo_acceso DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gp_usuarios_usuario (usuario),
  KEY idx_gp_usuarios_rol (id_rol),
  KEY idx_gp_usuarios_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_usuario_servicio (
  id INT NOT NULL AUTO_INCREMENT,
  id_usuario INT NOT NULL,
  destino ENUM('HABILITACION','FORMACION','AMBOS') NOT NULL DEFAULT 'AMBOS',
  id_servicio INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gp_usuario_servicio (id_usuario, destino, id_servicio),
  KEY idx_gp_usuario_servicio_usuario (id_usuario),
  KEY idx_gp_usuario_servicio_servicio (id_servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_fuentes (
  id INT NOT NULL AUTO_INCREMENT,
  titulo VARCHAR(255) NOT NULL,
  destino ENUM('HABILITACION','FORMACION') NOT NULL,
  id_servicio INT NOT NULL,
  id_agrupacion INT NULL,
  id_area INT NULL,
  tipo_origen ENUM('MANUAL','TXT','PDF','DOCX','XLSX','CSV','MIXTO') NOT NULL DEFAULT 'MANUAL',
  modo_uso ENUM('IA','IMPORTAR_PREGUNTAS','EXTRAER_PREGUNTAS_IA') NOT NULL DEFAULT 'IA',
  parser_tipo VARCHAR(40) NULL,
  import_estado ENUM('PENDIENTE','IMPORTADO','ERROR') NULL,
  import_resumen TEXT NULL,
  texto_fuente MEDIUMTEXT NOT NULL,
  estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gp_fuentes_destino_servicio (destino, id_servicio),
  KEY idx_gp_fuentes_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_documentos (
  id INT NOT NULL AUTO_INCREMENT,
  id_fuente INT NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  ruta_archivo VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NULL,
  extension VARCHAR(20) NOT NULL,
  tamano_bytes BIGINT NULL,
  texto_extraido MEDIUMTEXT NULL,
  estado ENUM('ACTIVO','SIN_TEXTO','ERROR','ANULADO') NOT NULL DEFAULT 'ACTIVO',
  error_text TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gp_documentos_fuente (id_fuente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ceo_gp_documentos
  MODIFY estado ENUM('ACTIVO','SIN_TEXTO','ERROR','ANULADO') NOT NULL DEFAULT 'ACTIVO';

ALTER TABLE ceo_gp_fuentes
  MODIFY tipo_origen ENUM('MANUAL','TXT','PDF','DOCX','XLSX','CSV','MIXTO') NOT NULL DEFAULT 'MANUAL';

ALTER TABLE ceo_gp_fuentes
  MODIFY modo_uso ENUM('IA','IMPORTAR_PREGUNTAS','EXTRAER_PREGUNTAS_IA') NOT NULL DEFAULT 'IA';

CREATE TABLE IF NOT EXISTS ceo_gp_generaciones (
  id INT NOT NULL AUTO_INCREMENT,
  id_fuente INT NOT NULL,
  cantidad_solicitada INT NOT NULL,
  dificultad VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
  modelo VARCHAR(80) NULL,
  prompt_text LONGTEXT NULL,
  respuesta_json LONGTEXT NULL,
  estado ENUM('GENERADA','ERROR') NOT NULL DEFAULT 'GENERADA',
  error_text TEXT NULL,
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gp_generaciones_fuente (id_fuente),
  KEY idx_gp_generaciones_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_preguntas (
  id INT NOT NULL AUTO_INCREMENT,
  id_fuente INT NULL,
  id_generacion INT NULL,
  destino ENUM('HABILITACION','FORMACION') NOT NULL,
  id_servicio INT NOT NULL,
  id_agrupacion INT NULL,
  id_area INT NULL,
  pregunta TEXT NOT NULL,
  imagen VARCHAR(500) NULL,
  video VARCHAR(500) NULL,
  retropos TEXT NULL,
  retroneg TEXT NULL,
  referencia TEXT NULL,
  import_referencia VARCHAR(255) NULL,
  origen ENUM('MANUAL','IA') NOT NULL DEFAULT 'MANUAL',
  estado ENUM('BORRADOR','REVISION','OPERACION','OBSERVADA','APROBADA_OPERACION','CERRADA','PUBLICADA','DESCARTADA') NOT NULL DEFAULT 'BORRADOR',
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_por INT NULL,
  fecha_actualizacion DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_gp_preguntas_estado (estado),
  KEY idx_gp_preguntas_destino_servicio (destino, id_servicio),
  KEY idx_gp_preguntas_fuente (id_fuente),
  KEY idx_gp_preguntas_generacion (id_generacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_alternativas (
  id INT NOT NULL AUTO_INCREMENT,
  id_pregunta INT NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  alternativa TEXT NOT NULL,
  correcta ENUM('S','N') NOT NULL DEFAULT 'N',
  imagen VARCHAR(500) NULL,
  video VARCHAR(500) NULL,
  estado ENUM('A','I') NOT NULL DEFAULT 'A',
  PRIMARY KEY (id),
  KEY idx_gp_alternativas_pregunta (id_pregunta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_revision (
  id INT NOT NULL AUTO_INCREMENT,
  id_pregunta INT NOT NULL,
  estado_desde VARCHAR(40) NULL,
  estado_hasta VARCHAR(40) NOT NULL,
  comentario TEXT NULL,
  creado_por INT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gp_revision_pregunta (id_pregunta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ceo_gp_publicacion (
  id INT NOT NULL AUTO_INCREMENT,
  id_pregunta INT NOT NULL,
  destino ENUM('HABILITACION','FORMACION') NOT NULL,
  tabla_pregunta VARCHAR(80) NOT NULL,
  id_pregunta_oficial INT NOT NULL,
  publicado_por INT NULL,
  fecha_publicacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gp_publicacion_pregunta (id_pregunta),
  KEY idx_gp_publicacion_destino (destino)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
