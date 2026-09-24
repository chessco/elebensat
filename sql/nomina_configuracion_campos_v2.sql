-- Ejecutar en la base del visor DESPUÉS de nomina_reporte_completo_v1.sql.
-- Sólo crea tablas nuevas. No modifica totales ni conciliaciones generales.
CREATE TABLE IF NOT EXISTS nomina_config_xml_muestras (
 id_muestra BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_empresa INT NOT NULL,
 hash_archivo CHAR(64) NOT NULL,
 nombre_archivo VARCHAR(255) NOT NULL,
 uuid VARCHAR(36) DEFAULT NULL,
 rfc_receptor VARCHAR(15) DEFAULT NULL,
 fecha_pago VARCHAR(10) DEFAULT NULL,
 xml_texto MEDIUMTEXT NOT NULL,
 id_usuario INT NOT NULL,
 fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id_muestra),
 UNIQUE KEY uk_ncxm_hash(id_empresa,hash_archivo),
 CONSTRAINT fk_ncxm_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_config_xml_campos (
 id_xml_campo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_empresa INT NOT NULL,
 selector_hash CHAR(64) NOT NULL,
 selector_json TEXT NOT NULL,
 etiqueta TEXT NOT NULL,
 ejemplos_json TEXT NOT NULL,
 tipo_sugerido VARCHAR(10) NOT NULL,
 id_muestra BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(id_xml_campo),
 UNIQUE KEY uk_ncxc_selector(id_empresa,selector_hash),
 CONSTRAINT fk_ncxc_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE,
 CONSTRAINT fk_ncxc_muestra FOREIGN KEY(id_muestra) REFERENCES nomina_config_xml_muestras(id_muestra) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_config_reglas (
 id_regla BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_empresa INT NOT NULL,
 id_campo BIGINT UNSIGNED NOT NULL,
 version_regla INT UNSIGNED NOT NULL,
 definicion_json LONGTEXT NOT NULL,
 id_usuario INT NOT NULL,
 comentario VARCHAR(500) NOT NULL,
 fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id_regla),
 UNIQUE KEY uk_ncr_campo(id_empresa,id_campo),
 CONSTRAINT fk_ncr_empresa FOREIGN KEY(id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE,
 CONSTRAINT fk_ncr_campo FOREIGN KEY(id_campo) REFERENCES nomina_campos(id_campo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_config_reglas_historial (
 id_regla BIGINT UNSIGNED NOT NULL,
 version_regla INT UNSIGNED NOT NULL,
 definicion_json LONGTEXT NOT NULL,
 id_usuario INT NOT NULL,
 comentario VARCHAR(500) NOT NULL,
 fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id_regla,version_regla),
 CONSTRAINT fk_ncrh_regla FOREIGN KEY(id_regla) REFERENCES nomina_config_reglas(id_regla) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
