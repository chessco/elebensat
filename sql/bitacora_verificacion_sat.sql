-- BITÁCORA VERIFICACIÓN SAT
-- La cabecera acumula TODOS los consultados.
-- verificaciones_sat_detalle guarda SOLO CFDI QUE CAMBIARON DE ESTATUS.
-- No se genera TXT; toda la bitácora queda en tablas MySQL.

CREATE TABLE IF NOT EXISTS verificaciones_sat (
    id_verificacion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_empresa INT NOT NULL,
    id_usuario INT NULL,
    origen ENUM('MANUAL','PROGRAMADO') NOT NULL DEFAULT 'MANUAL',
    fecha_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_fin DATETIME NULL,
    filtros_json LONGTEXT NULL,
    total_cfdi INT UNSIGNED NOT NULL DEFAULT 0,
    total_consultados INT UNSIGNED NOT NULL DEFAULT 0,
    total_vigentes INT UNSIGNED NOT NULL DEFAULT 0,
    total_cancelados INT UNSIGNED NOT NULL DEFAULT 0,
    total_cambios INT UNSIGNED NOT NULL DEFAULT 0,
    total_errores INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_indice_procesado INT UNSIGNED NOT NULL DEFAULT 0,
    estatus_proceso ENUM('PENDIENTE','PROCESANDO','COMPLETADA','CANCELADA','ERROR') NOT NULL DEFAULT 'PROCESANDO',
    duracion_segundos INT UNSIGNED NULL,
    observaciones VARCHAR(500) NULL,
    PRIMARY KEY (id_verificacion),
    KEY idx_ver_sat_empresa_fecha (id_empresa, fecha_inicio),
    KEY idx_ver_sat_estado (estatus_proceso, fecha_inicio),
    KEY idx_ver_sat_origen (origen, fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verificaciones_sat_detalle (
    id_detalle BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_verificacion BIGINT UNSIGNED NOT NULL,
    id_empresa INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    tipo_cfdi VARCHAR(5) NULL,
    serie VARCHAR(100) NULL,
    folio VARCHAR(100) NULL,
    fecha_emision DATETIME NULL,
    rfc_emisor VARCHAR(20) NULL,
    nombre_emisor VARCHAR(500) NULL,
    rfc_receptor VARCHAR(20) NULL,
    nombre_receptor VARCHAR(500) NULL,
    total DECIMAL(18,6) NULL,
    estatus_anterior VARCHAR(50) NULL,
    estatus_sat VARCHAR(50) NULL,
    cambio_estatus TINYINT(1) NOT NULL DEFAULT 0,
    codigo_estatus VARCHAR(500) NULL,
    es_cancelable VARCHAR(100) NULL,
    estatus_cancelacion VARCHAR(150) NULL,
    validacion_efos VARCHAR(100) NULL,
    fecha_consulta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resultado_consulta ENUM('OK','NO_ENCONTRADO','ERROR') NOT NULL DEFAULT 'OK',
    mensaje_error VARCHAR(1000) NULL,
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id_detalle),
    UNIQUE KEY uq_ver_sat_detalle_corrida_uuid (id_verificacion, uuid),
    KEY idx_ver_sat_det_empresa_uuid (id_empresa, uuid),
    KEY idx_ver_sat_det_fecha (id_empresa, fecha_consulta),
    KEY idx_ver_sat_det_cambio (id_empresa, cambio_estatus, fecha_consulta),
    KEY idx_ver_sat_det_estado (id_empresa, estatus_sat, fecha_consulta),
    CONSTRAINT fk_ver_sat_det_corrida
        FOREIGN KEY (id_verificacion) REFERENCES verificaciones_sat(id_verificacion)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
