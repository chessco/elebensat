-- Ejecutar UNA VEZ en la base del visor, antes de instalar los PHP.
-- Solo crea tablas nuevas. No modifica importaciones ni conciliaciones existentes.
CREATE TABLE IF NOT EXISTS nomina_campos (
 id_campo BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_empresa INT NOT NULL,
 clave_hash CHAR(64) NOT NULL,
 clave_campo TEXT NOT NULL,
 encabezado TEXT NOT NULL,
 tipo_valor VARCHAR(10) NOT NULL DEFAULT 'TEXTO',
 estado VARCHAR(15) NOT NULL DEFAULT 'PENDIENTE',
 id_usuario_alta INT DEFAULT NULL,
 fecha_deteccion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 fecha_alta DATETIME DEFAULT NULL,
 PRIMARY KEY (id_campo),
 UNIQUE KEY uk_nc_empresa_clave (id_empresa,clave_hash),
 CONSTRAINT fk_nc_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_fuentes (
 id_fuente BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_importacion BIGINT UNSIGNED NOT NULL,
 id_empresa INT NOT NULL,
 nombre_archivo VARCHAR(255) NOT NULL,
 extension VARCHAR(5) NOT NULL,
 hash_archivo CHAR(64) NOT NULL,
 bytes_archivo BIGINT UNSIGNED NOT NULL,
 hoja VARCHAR(255) NOT NULL,
 fila_encabezado INT UNSIGNED NOT NULL,
 total_columnas INT UNSIGNED NOT NULL,
 total_filas INT UNSIGNED NOT NULL,
 columnas_json LONGTEXT NOT NULL,
 metadata_json LONGTEXT NOT NULL,
 id_usuario INT DEFAULT NULL,
 fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id_fuente),
 KEY idx_nf_empresa_importacion (id_empresa,id_importacion,id_fuente),
 KEY idx_nf_importacion (id_importacion),
 CONSTRAINT fk_nf_importacion FOREIGN KEY (id_importacion) REFERENCES nomina_importaciones(id_importacion) ON DELETE CASCADE,
 CONSTRAINT fk_nf_empresa FOREIGN KEY (id_empresa) REFERENCES empresas(id_empresa) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nomina_fuente_filas (
 id_fuente BIGINT UNSIGNED NOT NULL,
 renglon INT UNSIGNED NOT NULL,
 clase VARCHAR(15) NOT NULL,
 numero_empleado VARCHAR(30) DEFAULT NULL,
 rfc VARCHAR(15) DEFAULT NULL,
 valores_json LONGTEXT NOT NULL,
 PRIMARY KEY (id_fuente,renglon),
 KEY idx_nff_rfc (id_fuente,rfc,renglon),
 KEY idx_nff_empleado (id_fuente,numero_empleado,renglon),
 CONSTRAINT fk_nff_fuente FOREIGN KEY (id_fuente) REFERENCES nomina_fuentes(id_fuente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloques de 256 KiB: evita enviar el archivo de hasta 25 MB en un solo paquete SQL.
CREATE TABLE IF NOT EXISTS nomina_fuente_bloques (
 id_fuente BIGINT UNSIGNED NOT NULL,
 numero_bloque INT UNSIGNED NOT NULL,
 contenido MEDIUMBLOB NOT NULL,
 PRIMARY KEY (id_fuente,numero_bloque),
 CONSTRAINT fk_nfb_fuente FOREIGN KEY (id_fuente) REFERENCES nomina_fuentes(id_fuente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
