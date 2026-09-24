-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Versión del servidor:         10.4.32-MariaDB - mariadb.org binary distribution
-- SO del servidor:              Win64
-- HeidiSQL Versión:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Volcando estructura de base de datos para visor_xml_db
CREATE DATABASE IF NOT EXISTS `visor_xml_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `visor_xml_db`;

-- Volcando estructura para tabla visor_xml_db.auditoria_xml
CREATE TABLE IF NOT EXISTS `auditoria_xml` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) DEFAULT NULL,
  `status` enum('valid','invalid') NOT NULL,
  `mensaje` text NOT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_auditoria_usuario` (`usuario_id`),
  CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=161199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_emisores
CREATE TABLE IF NOT EXISTS `cat_emisores` (
  `id_emisor` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `rfc` varchar(15) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `correo` varchar(255) DEFAULT NULL,
  `correo_cc` varchar(500) DEFAULT NULL,
  `tipo_tercero` varchar(2) NOT NULL DEFAULT '04',
  `tipo_operacion` varchar(2) NOT NULL DEFAULT '85',
  `aplica_diot` tinyint(1) NOT NULL DEFAULT 1,
  `num_id_fiscal` varchar(40) DEFAULT NULL,
  `nombre_extranjero` varchar(255) DEFAULT NULL,
  `pais_residencia` varchar(3) DEFAULT NULL,
  `nacionalidad` varchar(100) DEFAULT NULL,
  `region_diot` varchar(10) NOT NULL DEFAULT 'RESTO' COMMENT 'RESTO, RFN o RFS',
  `aplica_estimulo_fronterizo` tinyint(1) NOT NULL DEFAULT 0,
  `tasa_iva_fronteriza` decimal(8,6) NOT NULL DEFAULT 0.080000,
  `codigo_postal_fiscal` varchar(5) DEFAULT NULL COMMENT 'Último LugarExpedicion detectado al dar de alta el emisor',
  `origen_region_diot` varchar(20) NOT NULL DEFAULT 'MANUAL' COMMENT 'MANUAL, ALTA_XML o XML_CP_SAT',
  `fecha_validacion_region` datetime DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `aplica_diferencia_base_no_objeto` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Enviar diferencia positiva Subtotal proporcional - bases fiscales a DIOT No objeto',
  PRIMARY KEY (`id_emisor`),
  UNIQUE KEY `idx_rfc_empresa` (`rfc`,`id_empresa`),
  KEY `idx_emisor_region_diot` (`id_empresa`,`region_diot`,`aplica_estimulo_fronterizo`),
  KEY `idx_emisor_cp_region` (`id_empresa`,`codigo_postal_fiscal`,`region_diot`),
  KEY `idx_emisor_empresa_rfc` (`id_empresa`,`rfc`),
  KEY `idx_emisor_empresa_nombre` (`id_empresa`,`nombre`),
  KEY `idx_emisores_empresa_rfc_id` (`id_empresa`,`rfc`,`id_emisor`),
  KEY `idx_emisores_empresa_nombre_id` (`id_empresa`,`nombre`,`id_emisor`),
  CONSTRAINT `fk_emisor_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4292 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_formas_pago
CREATE TABLE IF NOT EXISTS `cat_formas_pago` (
  `id_forma` varchar(2) NOT NULL,
  `descripcion` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_forma`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_impuestos
CREATE TABLE IF NOT EXISTS `cat_impuestos` (
  `id_impuesto` varchar(3) NOT NULL,
  `descripcion` varchar(50) DEFAULT NULL,
  `retencion` tinyint(1) DEFAULT NULL,
  `traslado` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id_impuesto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_metodos_pago
CREATE TABLE IF NOT EXISTS `cat_metodos_pago` (
  `id_metodo` varchar(3) NOT NULL,
  `descripcion` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_metodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_receptores
CREATE TABLE IF NOT EXISTS `cat_receptores` (
  `id_receptor` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `rfc` varchar(15) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_receptor`),
  UNIQUE KEY `idx_rfc_empresa_rec` (`rfc`,`id_empresa`),
  KEY `fk_receptor_empresa` (`id_empresa`),
  KEY `idx_receptor_empresa_rfc` (`id_empresa`,`rfc`),
  KEY `idx_receptor_empresa_nombre` (`id_empresa`,`nombre`),
  KEY `idx_receptores_empresa_rfc_id` (`id_empresa`,`rfc`,`id_receptor`),
  KEY `idx_receptores_empresa_nombre_id` (`id_empresa`,`nombre`,`id_receptor`),
  CONSTRAINT `fk_receptor_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=176829 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_regimenes_fiscales
CREATE TABLE IF NOT EXISTS `cat_regimenes_fiscales` (
  `id_regimen` varchar(3) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  `persona_fisica` tinyint(1) DEFAULT NULL,
  `persona_moral` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id_regimen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_tipos_comprobante
CREATE TABLE IF NOT EXISTS `cat_tipos_comprobante` (
  `id_tipo` varchar(1) NOT NULL,
  `descripcion` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cat_uso_cfdi
CREATE TABLE IF NOT EXISTS `cat_uso_cfdi` (
  `id_uso` varchar(5) NOT NULL,
  `descripcion` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id_uso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cfdi_faltantes_sat
CREATE TABLE IF NOT EXISTS `cfdi_faltantes_sat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `rfc_emisor` varchar(20) DEFAULT NULL,
  `nombre_emisor` varchar(500) DEFAULT NULL,
  `rfc_receptor` varchar(20) DEFAULT NULL,
  `nombre_receptor` varchar(500) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `monto` decimal(18,6) DEFAULT NULL,
  `efecto_comprobante` varchar(5) DEFAULT NULL,
  `estatus_sat` varchar(20) NOT NULL DEFAULT 'Vigente',
  `fecha_cancelacion` datetime DEFAULT NULL,
  `estatus_recuperacion` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 pendiente,1 solicitado,2 resuelto,3 error',
  `intentos` int(11) NOT NULL DEFAULT 0,
  `fecha_detectado` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_solicitud` datetime DEFAULT NULL,
  `fecha_resuelto` datetime DEFAULT NULL,
  `id_paquete_metadata` bigint(20) unsigned DEFAULT NULL,
  `mensaje` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_faltante_empresa_uuid` (`id_empresa`,`uuid`),
  KEY `idx_faltantes_pendientes` (`id_empresa`,`estatus_recuperacion`,`fecha_emision`)
) ENGINE=InnoDB AUTO_INCREMENT=169120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.cfdi_relaciones
CREATE TABLE IF NOT EXISTS `cfdi_relaciones` (
  `id_relacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `uuid_origen` varchar(36) NOT NULL COMMENT 'CFDI nuevo que declara la relación',
  `uuid_relacionado` varchar(36) NOT NULL COMMENT 'CFDI anterior/relacionado',
  `tipo_relacion` varchar(2) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_relacion`),
  UNIQUE KEY `uk_cfdi_relacion` (`id_empresa`,`uuid_origen`,`uuid_relacionado`,`tipo_relacion`),
  KEY `idx_cfdi_relacion_relacionado` (`id_empresa`,`uuid_relacionado`,`tipo_relacion`),
  KEY `idx_cfdi_relacion_origen` (`id_empresa`,`uuid_origen`,`tipo_relacion`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.complementos_pagos
CREATE TABLE IF NOT EXISTS `complementos_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid_complemento` varchar(36) NOT NULL,
  `uuid_factura` varchar(36) NOT NULL,
  `fecha_pago` date NOT NULL,
  `aplicado` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_uuid_factura` (`uuid_factura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.conciliacion_financiera
CREATE TABLE IF NOT EXISTS `conciliacion_financiera` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clave_conciliacion` char(64) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `uuid_factura` varchar(36) NOT NULL,
  `id_detalle` int(11) DEFAULT NULL,
  `origen_pago` varchar(20) NOT NULL COMMENT 'ORACLE o CONTPAQ',
  `tipo_documento_origen` varchar(30) NOT NULL COMMENT 'FACTURA, EXP_DETALLE, etc.',
  `id_documento_origen` bigint(20) unsigned DEFAULT NULL,
  `referencia_documento` varchar(150) DEFAULT NULL,
  `documento_padre` varchar(150) DEFAULT NULL COMMENT 'Ej. EXP000... para caja chica',
  `id_pago_origen` bigint(20) unsigned DEFAULT NULL,
  `folio_pago` varchar(100) DEFAULT NULL,
  `fecha_pago` date DEFAULT NULL,
  `importe_documento` decimal(20,6) DEFAULT NULL,
  `importe_pago` decimal(20,6) DEFAULT NULL,
  `importe_aplicado` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `moneda` varchar(10) DEFAULT NULL,
  `estatus_pago` varchar(80) DEFAULT NULL,
  `conciliado_banco` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_conciliacion_banco` date DEFAULT NULL,
  `fecha_valor` date DEFAULT NULL,
  `estatus_xml` varchar(20) NOT NULL DEFAULT 'OK',
  `estatus_detalle_pago` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `estatus_conciliacion` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `regla_cruce` varchar(100) DEFAULT NULL,
  `diferencia_importe` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `diferencia_dias` int(11) DEFAULT NULL,
  `origen_conciliacion` varchar(20) NOT NULL DEFAULT 'AUTOMATICA',
  `id_usuario_concilia` int(11) DEFAULT NULL,
  `fecha_conciliacion` datetime DEFAULT NULL,
  `fecha_ultima_revision` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `observaciones` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conciliacion_financiera_clave` (`clave_conciliacion`),
  KEY `idx_cf_empresa_uuid` (`id_empresa`,`uuid_factura`),
  KEY `idx_cf_empresa_detalle` (`id_empresa`,`id_detalle`),
  KEY `idx_cf_empresa_origen` (`id_empresa`,`origen_pago`,`tipo_documento_origen`,`id_documento_origen`),
  KEY `idx_cf_empresa_pago` (`id_empresa`,`origen_pago`,`id_pago_origen`),
  KEY `idx_cf_empresa_estado` (`id_empresa`,`estatus_conciliacion`,`fecha_pago`),
  KEY `fk_cf_detalle` (`id_detalle`),
  KEY `fk_cf_usuario` (`id_usuario_concilia`),
  CONSTRAINT `fk_cf_detalle` FOREIGN KEY (`id_detalle`) REFERENCES `facturas_pagos_detalles` (`id_detalle`) ON DELETE CASCADE,
  CONSTRAINT `fk_cf_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_cf_usuario` FOREIGN KEY (`id_usuario_concilia`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=570 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.conciliacion_financiera_jobs
CREATE TABLE IF NOT EXISTS `conciliacion_financiera_jobs` (
  `id_job` char(32) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `desde` date NOT NULL,
  `hasta` date NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'PROCESANDO',
  `fase` varchar(20) NOT NULL DEFAULT 'FACTURAS',
  `total_facturas` int(11) NOT NULL DEFAULT 0,
  `total_gastos` int(11) NOT NULL DEFAULT 0,
  `procesadas_facturas` int(11) NOT NULL DEFAULT 0,
  `procesados_gastos` int(11) NOT NULL DEFAULT 0,
  `conciliados` int(11) NOT NULL DEFAULT 0,
  `parciales` int(11) NOT NULL DEFAULT 0,
  `diferencias` int(11) NOT NULL DEFAULT 0,
  `pendientes` int(11) NOT NULL DEFAULT 0,
  `sin_xml` int(11) NOT NULL DEFAULT 0,
  `sin_uuid` int(11) NOT NULL DEFAULT 0,
  `cancelados` int(11) NOT NULL DEFAULT 0,
  `errores` int(11) NOT NULL DEFAULT 0,
  `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
  `ultimo_error` text DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_actividad` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_job`),
  KEY `idx_cfjob_empresa_estado` (`id_empresa`,`estado`,`fecha_inicio`),
  KEY `fk_cfjob_usuario` (`id_usuario`),
  CONSTRAINT `fk_cfjob_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_cfjob_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.conciliacion_pagos_detalle
CREATE TABLE IF NOT EXISTS `conciliacion_pagos_detalle` (
  `id_conciliacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_detalle` int(11) NOT NULL,
  `id_contpaq_dispersion` bigint(20) unsigned NOT NULL,
  `tipo_origen` char(1) NOT NULL DEFAULT 'C',
  `id_contpaq_cheque` bigint(20) DEFAULT NULL,
  `id_contpaq_egreso` bigint(20) DEFAULT NULL,
  `rfc_emisor` varchar(15) DEFAULT NULL COMMENT 'RFC del proveedor/emisor para consulta rápida DIOT',
  `uuid_factura` varchar(36) NOT NULL,
  `uuid_pago_sat` varchar(36) DEFAULT NULL,
  `uuid_rep_contpaq` varchar(64) DEFAULT NULL,
  `fecha_pago_sat` date DEFAULT NULL,
  `fecha_pago_contpaq` date NOT NULL,
  `moneda_factura` varchar(10) DEFAULT NULL,
  `tc_factura` decimal(20,8) DEFAULT NULL,
  `moneda_pago_sat` varchar(10) DEFAULT NULL,
  `tc_pago_sat` decimal(20,8) DEFAULT NULL,
  `moneda_contpaq` varchar(10) DEFAULT NULL,
  `tc_contpaq` decimal(20,8) DEFAULT NULL,
  `importe_sat` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `importe_contpaq` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `importe_conciliado` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `diferencia_importe` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `diferencia_dias` int(11) DEFAULT NULL,
  `origen_conciliacion` varchar(20) NOT NULL DEFAULT 'AUTOMATICA',
  `estatus` varchar(30) NOT NULL DEFAULT 'CONCILIADO',
  `id_usuario_concilia` int(11) DEFAULT NULL,
  `fecha_conciliacion` datetime NOT NULL DEFAULT current_timestamp(),
  `observaciones` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id_conciliacion`),
  UNIQUE KEY `uk_conciliacion_dispersion` (`id_empresa`,`id_contpaq_dispersion`),
  KEY `idx_conciliacion_detalle` (`id_empresa`,`id_detalle`),
  KEY `idx_conciliacion_factura_fecha` (`id_empresa`,`uuid_factura`,`fecha_pago_contpaq`),
  KEY `idx_conciliacion_rep` (`id_empresa`,`uuid_pago_sat`),
  KEY `fk_conciliacion_detalle` (`id_detalle`),
  KEY `fk_conciliacion_usuario` (`id_usuario_concilia`),
  KEY `idx_cpd_empresa_fecha` (`id_empresa`,`fecha_pago_contpaq`),
  KEY `idx_cpd_empresa_dispersion` (`id_empresa`,`id_contpaq_dispersion`),
  KEY `idx_conciliacion_diot_rfc_fecha` (`id_empresa`,`rfc_emisor`,`fecha_pago_sat`),
  CONSTRAINT `fk_conciliacion_detalle` FOREIGN KEY (`id_detalle`) REFERENCES `facturas_pagos_detalles` (`id_detalle`) ON DELETE CASCADE,
  CONSTRAINT `fk_conciliacion_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_conciliacion_usuario` FOREIGN KEY (`id_usuario_concilia`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1442 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.contpaq_cheques
CREATE TABLE IF NOT EXISTS `contpaq_cheques` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_contpaq_cheque` int(11) NOT NULL,
  `row_version` bigint(20) DEFAULT NULL,
  `id_documento_de` int(11) DEFAULT NULL,
  `tipo_documento` varchar(60) DEFAULT NULL,
  `folio` varchar(80) DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `ejercicio` int(11) DEFAULT NULL,
  `periodo` int(11) DEFAULT NULL,
  `fecha_aplicacion` datetime DEFAULT NULL,
  `ejercicio_ap` int(11) DEFAULT NULL,
  `periodo_ap` int(11) DEFAULT NULL,
  `codigo_persona` varchar(100) DEFAULT NULL,
  `persona_rfc` varchar(30) DEFAULT NULL,
  `beneficiario_pagador` varchar(500) DEFAULT NULL,
  `id_cuenta_cheques` int(11) DEFAULT NULL,
  `nat_bancaria` int(11) DEFAULT NULL,
  `naturaleza` int(11) DEFAULT NULL,
  `codigo_moneda` varchar(30) DEFAULT NULL,
  `codigo_moneda_tipo_cambio` varchar(30) DEFAULT NULL,
  `tipo_cambio` decimal(20,8) DEFAULT NULL,
  `total` decimal(20,6) DEFAULT NULL,
  `referencia` varchar(150) DEFAULT NULL,
  `concepto` text DEFAULT NULL,
  `es_cancelado` tinyint(1) NOT NULL DEFAULT 0,
  `num_pol` varchar(80) DEFAULT NULL,
  `id_poliza` bigint(20) DEFAULT NULL,
  `es_anticipo` tinyint(1) NOT NULL DEFAULT 0,
  `guid` varchar(100) DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  `conciliado` tinyint(1) NOT NULL DEFAULT 0,
  `estatus_conciliacion` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_conciliacion` datetime DEFAULT NULL,
  `id_usuario_concilia` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contpaq_cheque` (`id_empresa`,`id_contpaq_cheque`),
  KEY `idx_contpaq_cheques_fecha` (`id_empresa`,`fecha`),
  KEY `idx_contpaq_cheques_guid` (`id_empresa`,`guid`),
  KEY `idx_contpaq_cheques_folio` (`id_empresa`,`folio`),
  KEY `idx_contpaq_cheques_estado` (`id_empresa`,`conciliado`,`es_cancelado`),
  KEY `fk_contpaq_cheque_usuario` (`id_usuario_concilia`),
  CONSTRAINT `fk_contpaq_cheque_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_contpaq_cheque_usuario` FOREIGN KEY (`id_usuario_concilia`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3635 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.contpaq_correo_sync
CREATE TABLE IF NOT EXISTS `contpaq_correo_sync` (
  `id_sync` bigint(20) NOT NULL AUTO_INCREMENT,
  `token` char(32) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `estatus` enum('INICIADA','PROCESANDO','TERMINADA','CANCELADA','ERROR') NOT NULL DEFAULT 'INICIADA',
  `total` int(11) NOT NULL DEFAULT 0,
  `ultimo_id` bigint(20) NOT NULL DEFAULT 0,
  `leidos` int(11) NOT NULL DEFAULT 0,
  `coinciden` int(11) NOT NULL DEFAULT 0,
  `actualizados` int(11) NOT NULL DEFAULT 0,
  `sin_cambio` int(11) NOT NULL DEFAULT 0,
  `no_encontrados` int(11) NOT NULL DEFAULT 0,
  `invalidos` int(11) NOT NULL DEFAULT 0,
  `error` varchar(1000) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_sync`),
  UNIQUE KEY `uk_contpaq_correo_sync_token` (`token`),
  KEY `idx_contpaq_correo_sync_empresa` (`id_empresa`,`estatus`),
  CONSTRAINT `fk_contpaq_correo_sync_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.contpaq_dispersiones_pagos
CREATE TABLE IF NOT EXISTS `contpaq_dispersiones_pagos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `tipo_origen` char(1) NOT NULL DEFAULT 'C',
  `id_contpaq_dispersion` int(11) NOT NULL,
  `id_contpaq_cheque` bigint(20) DEFAULT NULL,
  `id_contpaq_egreso` bigint(20) DEFAULT NULL,
  `row_version` bigint(20) DEFAULT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `uuid_rep` varchar(64) DEFAULT NULL,
  `guid_ref` varchar(100) DEFAULT NULL,
  `num_nodo_pago` int(11) DEFAULT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  `total_pago` decimal(20,6) DEFAULT NULL,
  `tipo_cambio` decimal(20,8) DEFAULT NULL,
  `total_pago_comprobante` decimal(20,6) DEFAULT NULL,
  `moneda_contpaq` varchar(10) DEFAULT NULL,
  `importe_disponible_conciliar` decimal(20,6) DEFAULT NULL,
  `conciliado` tinyint(1) NOT NULL DEFAULT 0,
  `estatus_conciliacion` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_conciliacion` datetime DEFAULT NULL,
  `id_usuario_concilia` int(11) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `origen_folio` varchar(100) DEFAULT NULL,
  `origen_fecha` datetime DEFAULT NULL,
  `origen_tipo_documento` varchar(50) DEFAULT NULL,
  `codigo_persona` varchar(100) DEFAULT NULL,
  `persona_rfc` varchar(30) DEFAULT NULL,
  `beneficiario_pagador` varchar(255) DEFAULT NULL,
  `concepto` text DEFAULT NULL,
  `total_origen` decimal(20,6) DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contpaq_dispersion` (`id_empresa`,`id_contpaq_dispersion`),
  KEY `idx_contpaq_disp_uuid` (`id_empresa`,`uuid`),
  KEY `idx_contpaq_disp_uuidrep` (`id_empresa`,`uuid_rep`),
  KEY `idx_contpaq_disp_guidref` (`id_empresa`,`guid_ref`),
  KEY `idx_contpaq_disp_cheque` (`id_empresa`,`id_contpaq_cheque`),
  KEY `idx_contpaq_disp_fecha` (`id_empresa`,`fecha_pago`),
  KEY `idx_contpaq_disp_estado` (`id_empresa`,`conciliado`),
  KEY `fk_contpaq_disp_usuario` (`id_usuario_concilia`),
  KEY `idx_contpaq_disp_origen` (`id_empresa`,`tipo_origen`,`id_contpaq_cheque`,`id_contpaq_egreso`),
  KEY `idx_cp_disp_empresa_fecha_id` (`id_empresa`,`fecha_pago`,`id_contpaq_dispersion`),
  KEY `idx_cp_disp_empresa_uuid_rep` (`id_empresa`,`uuid`,`uuid_rep`),
  KEY `idx_disp_empresa_fecha_id` (`id_empresa`,`fecha_pago`,`id_contpaq_dispersion`),
  CONSTRAINT `fk_contpaq_disp_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_contpaq_disp_usuario` FOREIGN KEY (`id_usuario_concilia`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11419 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.contpaq_egresos
CREATE TABLE IF NOT EXISTS `contpaq_egresos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_contpaq_egreso` bigint(20) NOT NULL,
  `row_version` text DEFAULT NULL,
  `tipo_documento` varchar(50) DEFAULT NULL,
  `folio` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT NULL,
  `codigo_persona` varchar(100) DEFAULT NULL,
  `persona_rfc` varchar(30) DEFAULT NULL,
  `beneficiario_pagador` varchar(255) DEFAULT NULL,
  `id_cuenta_cheques` bigint(20) DEFAULT NULL,
  `codigo_moneda` varchar(30) DEFAULT NULL,
  `codigo_moneda_tipo_cambio` varchar(30) DEFAULT NULL,
  `tipo_cambio` decimal(20,8) DEFAULT NULL,
  `total` decimal(20,6) DEFAULT NULL,
  `referencia` varchar(255) DEFAULT NULL,
  `concepto` text DEFAULT NULL,
  `es_cancelado` tinyint(1) NOT NULL DEFAULT 0,
  `num_pol` varchar(100) DEFAULT NULL,
  `id_poliza` bigint(20) DEFAULT NULL,
  `es_anticipo` tinyint(1) NOT NULL DEFAULT 0,
  `guid` varchar(100) DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_contpaq_egreso` (`id_empresa`,`id_contpaq_egreso`),
  KEY `idx_contpaq_egreso_fecha` (`id_empresa`,`fecha`),
  KEY `idx_contpaq_egreso_guid` (`id_empresa`,`guid`),
  KEY `idx_contpaq_egreso_cancelado` (`id_empresa`,`es_cancelado`,`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=6043 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.descargas_sat
CREATE TABLE IF NOT EXISTS `descargas_sat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_solicitud` varchar(100) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `tipo` enum('emitidos','recibidos') NOT NULL,
  `paquete_tipo` enum('xml','metadata','cfdi') NOT NULL DEFAULT 'xml',
  `estado_comprobante` varchar(20) NOT NULL DEFAULT 'todos',
  `origen` enum('manual','automatica') NOT NULL DEFAULT 'automatica',
  `id_usuario_solicita` int(11) DEFAULT NULL,
  `motivo` varchar(100) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `lote_manual` varchar(36) DEFAULT NULL,
  `estatus` int(11) DEFAULT 0 COMMENT '0:Pendiente, 1:En Proceso, 2:Terminada, 3:Error',
  `mensaje_sat` text DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_cancelacion` datetime DEFAULT NULL,
  `id_usuario_cancela` int(11) DEFAULT NULL,
  `motivo_cancelacion` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_descarga_empresa_estado` (`id_empresa`,`estatus`),
  KEY `idx_descarga_lote_manual` (`lote_manual`),
  KEY `idx_descarga_periodo` (`id_empresa`,`fecha_inicio`,`fecha_fin`,`tipo`,`paquete_tipo`),
  KEY `fk_descarga_usuario_solicita` (`id_usuario_solicita`),
  KEY `fk_descarga_usuario_cancela` (`id_usuario_cancela`),
  KEY `idx_descarga_empresa_periodo_tipo` (`id_empresa`,`fecha_inicio`,`fecha_fin`,`tipo`,`paquete_tipo`),
  CONSTRAINT `fk_descarga_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_descarga_usuario_cancela` FOREIGN KEY (`id_usuario_cancela`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL,
  CONSTRAINT `fk_descarga_usuario_solicita` FOREIGN KEY (`id_usuario_solicita`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=264 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.descargas_sat_archivos
CREATE TABLE IF NOT EXISTS `descargas_sat_archivos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_paquete` bigint(20) unsigned NOT NULL,
  `nombre_archivo` varchar(1000) NOT NULL,
  `ruta_temporal` varchar(1500) NOT NULL,
  `tipo_archivo` varchar(20) NOT NULL DEFAULT 'xml',
  `uuid` varchar(36) DEFAULT NULL,
  `estatus` tinyint(4) NOT NULL DEFAULT 0,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `mensaje` varchar(4000) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_procesado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_paquete_archivo` (`id_paquete`,`nombre_archivo`(500)),
  KEY `idx_archivo_pendiente` (`id_paquete`,`estatus`),
  KEY `idx_archivo_reintento_avestruz` (`id_paquete`,`estatus`,`intentos`,`fecha_procesado`),
  CONSTRAINT `fk_descarga_archivo_paquete` FOREIGN KEY (`id_paquete`) REFERENCES `descargas_sat_paquetes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=318582 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.descargas_sat_paquetes
CREATE TABLE IF NOT EXISTS `descargas_sat_paquetes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_descarga_sat` bigint(20) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `id_paquete_sat` varchar(100) NOT NULL,
  `estatus` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 pendiente descarga, 1 ZIP descargado, 2 extraído, 3 procesado, 4 error',
  `estatus_proceso` tinyint(4) NOT NULL DEFAULT 0,
  `ruta_zip` varchar(1000) DEFAULT NULL,
  `ruta_extraccion` varchar(1000) DEFAULT NULL,
  `total_archivos` int(11) NOT NULL DEFAULT 0,
  `archivos_extraidos` int(11) NOT NULL DEFAULT 0,
  `archivos_procesados` int(11) NOT NULL DEFAULT 0,
  `archivos_duplicados` int(11) NOT NULL DEFAULT 0,
  `archivos_error` int(11) NOT NULL DEFAULT 0,
  `tamano_bytes` bigint(20) unsigned DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `intentos_proceso` int(11) NOT NULL DEFAULT 0,
  `mensaje` varchar(4000) DEFAULT NULL,
  `error_proceso` text DEFAULT NULL,
  `procesando_por` varchar(80) DEFAULT NULL,
  `fecha_ultimo_avance` datetime DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_descarga` datetime DEFAULT NULL,
  `fecha_extraccion` datetime DEFAULT NULL,
  `fecha_inicio_proceso` datetime DEFAULT NULL,
  `fecha_fin_proceso` datetime DEFAULT NULL,
  `ultimo_archivo_procesado` varchar(1000) DEFAULT NULL,
  `fecha_procesado` datetime DEFAULT NULL,
  `fecha_error` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_descarga_paquete` (`id_descarga_sat`,`id_paquete_sat`),
  KEY `idx_paquete_empresa_estado` (`id_empresa`,`estatus`),
  KEY `idx_paquete_descarga` (`id_descarga_sat`),
  KEY `idx_paquete_proceso` (`estatus`,`estatus_proceso`,`id_empresa`),
  KEY `idx_paquete_cola_avestruz` (`estatus`,`estatus_proceso`,`id_empresa`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=189 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.documentos_digitales
CREATE TABLE IF NOT EXISTS `documentos_digitales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) DEFAULT NULL,
  `uuid` varchar(36) NOT NULL,
  `serie` varchar(25) DEFAULT NULL,
  `folio` varchar(25) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `rfc_emisor` varchar(15) DEFAULT NULL,
  `nombre_emisor` varchar(255) DEFAULT NULL,
  `rfc_receptor` varchar(15) DEFAULT NULL,
  `nombre_receptor` varchar(255) DEFAULT NULL,
  `tipo_comprobante` char(1) DEFAULT NULL,
  `version_cfdi` varchar(5) DEFAULT NULL,
  `moneda` varchar(3) DEFAULT NULL,
  `tipo_cambio` decimal(18,4) DEFAULT NULL,
  `subtotal` decimal(18,2) DEFAULT NULL,
  `iva_16_base` decimal(18,2) DEFAULT 0.00,
  `iva_16_importe` decimal(18,2) DEFAULT 0.00,
  `ret_iva` decimal(18,2) DEFAULT 0.00,
  `ret_isr` decimal(18,2) DEFAULT 0.00,
  `total` decimal(18,2) DEFAULT NULL,
  `metodo_pago` varchar(3) DEFAULT NULL,
  `forma_pago` varchar(3) DEFAULT NULL,
  `status_pago` int(11) DEFAULT 0,
  `es_gasolina` tinyint(1) DEFAULT 0,
  `xml_interno` longtext DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `id_empresa` (`id_empresa`),
  KEY `fecha_emision` (`fecha_emision`),
  CONSTRAINT `documentos_digitales_ibfk_1` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.empresas
CREATE TABLE IF NOT EXISTS `empresas` (
  `id_empresa` int(11) NOT NULL AUTO_INCREMENT,
  `id_zona` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `rfc` varchar(15) NOT NULL,
  `tipopersona` varchar(20) DEFAULT NULL,
  `razon_social` varchar(255) NOT NULL,
  `modo_diot` enum('normal','simplificada') NOT NULL DEFAULT 'normal',
  `criterio_fecha_pue` enum('EMISION','PAGO') NOT NULL DEFAULT 'EMISION' COMMENT 'EMISION: PUE se aplica en fecha XML. PAGO: espera pago banco/SAT/usuario.',
  `comercial` varchar(100) DEFAULT NULL,
  `calle` varchar(100) DEFAULT NULL,
  `colonia` varchar(100) DEFAULT NULL,
  `nexterior` varchar(10) DEFAULT NULL,
  `ninterior` varchar(10) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `estado` varchar(80) DEFAULT NULL,
  `pais` varchar(30) DEFAULT NULL,
  `codigopostal` varchar(10) DEFAULT NULL,
  `telefono` varchar(100) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `lugarexpedicion` varchar(10) DEFAULT NULL,
  `regimenfiscal` varchar(10) DEFAULT NULL,
  `certificado_fiel` longtext DEFAULT NULL,
  `llave_fiel` longtext DEFAULT NULL,
  `pfx_fiel` longtext DEFAULT NULL,
  `pass_pfx_fiel` text DEFAULT NULL,
  `pfx_fiel_validado` tinyint(1) NOT NULL DEFAULT 0,
  `pfx_fiel_rfc` varchar(20) DEFAULT NULL,
  `pfx_fiel_fecha_inicio` date DEFAULT NULL,
  `pfx_fiel_fecha_vencimiento` date DEFAULT NULL,
  `pfx_fiel_ultima_validacion` datetime DEFAULT NULL,
  `descarga_sat_automatica` tinyint(1) NOT NULL DEFAULT 0,
  `passfiel` text DEFAULT NULL,
  `certificado_csd` longtext DEFAULT NULL,
  `llave_csd` longtext DEFAULT NULL,
  `pfx_csd` longtext DEFAULT NULL,
  `pass_pfx_csd` text DEFAULT NULL,
  `passcsd` text DEFAULT NULL,
  `certificado_pfx` longtext DEFAULT NULL,
  `llave_pfx` longtext DEFAULT NULL,
  `passpfx` text DEFAULT NULL,
  `smptp_correo` varchar(100) DEFAULT NULL,
  `puerto_correo` int(11) DEFAULT NULL,
  `usa_aut` tinyint(1) DEFAULT 0,
  `usa_ssl` tinyint(1) DEFAULT 0,
  `asunto` varchar(100) DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `correo_copia_complementos` varchar(500) DEFAULT NULL,
  `modo_copia_complementos` enum('individual','resumen') NOT NULL DEFAULT 'individual',
  `correo_remitente` varchar(190) DEFAULT NULL,
  `nombre_remitente` varchar(190) DEFAULT NULL,
  `reply_to_correo` varchar(190) DEFAULT NULL,
  `smtp_usuario` varchar(190) DEFAULT NULL,
  `smtp_password_enc` text DEFAULT NULL,
  `seguridad_correo` varchar(20) NOT NULL DEFAULT 'STARTTLS',
  `logo1` longtext DEFAULT NULL,
  `logo2` longtext DEFAULT NULL,
  `nombre_corto` varchar(50) DEFAULT NULL,
  `base_datos_contpaq` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id_empresa`),
  UNIQUE KEY `rfc` (`rfc`),
  KEY `empresas_ibfk_1` (`id_zona`),
  CONSTRAINT `empresas_ibfk_1` FOREIGN KEY (`id_zona`) REFERENCES `zonas` (`id_zona`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.empresa_contpaq_config
CREATE TABLE IF NOT EXISTS `empresa_contpaq_config` (
  `id_empresa` int(11) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 0,
  `servidor` varchar(190) NOT NULL,
  `base_datos` varchar(150) NOT NULL,
  `base_datos_comercial` varchar(150) DEFAULT NULL,
  `usuario` varchar(100) NOT NULL,
  `password_enc` text DEFAULT NULL,
  `ultima_prueba` datetime DEFAULT NULL,
  `ultimo_error` varchar(1000) DEFAULT NULL,
  `ultima_sincronizacion` datetime DEFAULT NULL,
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_empresa`),
  CONSTRAINT `fk_contpaq_config_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.empresa_oracle_pagos_config
CREATE TABLE IF NOT EXISTS `empresa_oracle_pagos_config` (
  `id_empresa` int(11) NOT NULL,
  `unidad_negocio` varchar(100) NOT NULL,
  `usuario` varchar(150) NOT NULL,
  `password_enc` text NOT NULL,
  `ultima_prueba` datetime DEFAULT NULL,
  `ultimo_error` varchar(1000) DEFAULT NULL,
  `ultima_sincronizacion` datetime DEFAULT NULL,
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_empresa`),
  CONSTRAINT `fk_oracle_pagos_config_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas
CREATE TABLE IF NOT EXISTS `facturas` (
  `uuid` varchar(36) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `id_emisor` int(11) NOT NULL,
  `id_receptor` int(11) NOT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `serie` varchar(50) DEFAULT NULL,
  `folio` varchar(50) DEFAULT NULL,
  `subtotal_xml` decimal(16,2) DEFAULT NULL,
  `id_tipo_comprobante` varchar(1) DEFAULT NULL,
  `tipo_movimiento_empresa` char(1) DEFAULT NULL COMMENT 'I=empresa emisora/ingreso, E=empresa receptora/egreso',
  `metodo_pago` varchar(3) DEFAULT NULL,
  `version_cfdi` varchar(10) DEFAULT NULL COMMENT 'Versión del CFDI obtenida del atributo Version del XML',
  `forma_pago` varchar(2) DEFAULT NULL COMMENT 'FormaPago del CFDI o FormaDePagoP del complemento',
  `uso_cfdi` varchar(5) DEFAULT NULL COMMENT 'UsoCFDI indicado en el nodo Receptor',
  `subtotal` decimal(18,4) DEFAULT NULL,
  `iva_16` decimal(18,4) DEFAULT 0.0000,
  `iva_8` decimal(18,4) DEFAULT 0.0000,
  `iva_exento` decimal(18,4) DEFAULT 0.0000,
  `iva_retinido` decimal(18,4) DEFAULT 0.0000,
  `isr_retinido` decimal(18,4) DEFAULT 0.0000,
  `ieps_otros` decimal(18,4) DEFAULT 0.0000,
  `total_xml` decimal(18,4) DEFAULT NULL,
  `moneda` varchar(5) DEFAULT NULL,
  `estatus_sat` varchar(20) DEFAULT 'Vigente',
  `fecha_cancelacion` datetime DEFAULT NULL,
  `motivo_cancelacion` varchar(5) DEFAULT NULL,
  `uuid_sustitucion` varchar(36) DEFAULT NULL,
  `tc_xml_factura` decimal(18,4) DEFAULT 1.0000,
  `tc_xml_pago` decimal(18,4) DEFAULT 1.0000,
  `tc_banco_real` decimal(18,4) DEFAULT 1.0000,
  `moneda_contpaq` varchar(10) DEFAULT NULL,
  `tc_contpaq_real` decimal(20,8) DEFAULT NULL,
  `fecha_pago_sat` datetime DEFAULT NULL,
  `fecha_pago_banco` date DEFAULT NULL,
  `fecha_ultimo_pago_contpaq` date DEFAULT NULL,
  `fecha_pago_usuario` date DEFAULT NULL,
  `referencia_xml` varchar(100) DEFAULT NULL,
  `referencia_banco_real` varchar(100) DEFAULT NULL,
  `origen_pago_financiero` varchar(20) DEFAULT NULL,
  `folio_pago_origen` varchar(500) DEFAULT NULL,
  `fecha_pago_origen` date DEFAULT NULL,
  `estatus_pago_origen` varchar(80) DEFAULT NULL,
  `conciliado_banco_origen` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_conciliacion_banco_origen` date DEFAULT NULL,
  `fecha_valor_origen` date DEFAULT NULL,
  `estatus_conciliacion_financiera` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_ultima_conciliacion_financiera` datetime DEFAULT NULL,
  `banco_origen` varchar(50) DEFAULT NULL,
  `importe_factura_mn` decimal(18,4) DEFAULT 0.0000,
  `total_abonos` decimal(18,4) DEFAULT 0.0000,
  `total_abonos_contpaq` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `saldo_conciliar_contpaq` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `saldo_pendiente` decimal(18,4) DEFAULT 0.0000,
  `ya_pago` tinyint(1) DEFAULT 0,
  `esta_conciliado` tinyint(1) DEFAULT 0,
  `fecha_aplicacion_fiscal` date DEFAULT NULL,
  `mes_fiscal_reporte` varchar(2) DEFAULT NULL,
  `anio_fiscal_reporte` varchar(4) DEFAULT NULL,
  `manual_modificado` tinyint(1) DEFAULT 0,
  `observaciones_fiscales` text DEFAULT NULL,
  `es_gasolina` tinyint(1) DEFAULT 0,
  `total_ieps_gasolina` decimal(18,4) DEFAULT 0.0000,
  `es_donativo` tinyint(1) DEFAULT 0,
  `autorizacion_donativo` varchar(100) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_usuario_cargo` int(11) DEFAULT NULL,
  `iva_traslado` decimal(18,2) DEFAULT 0.00,
  `base_iva` decimal(16,2) DEFAULT 0.00,
  `iva_retenido` decimal(18,2) DEFAULT 0.00,
  `base_ret_iva` decimal(16,2) DEFAULT 0.00,
  `isr_retenido` decimal(18,2) DEFAULT 0.00,
  `base_ret_isr` decimal(16,2) DEFAULT 0.00,
  `tiene_complemento_pagos` tinyint(1) DEFAULT 0,
  `excluir_diot` tinyint(1) DEFAULT 0,
  `efecto_fiscal_diot` char(2) NOT NULL DEFAULT '01' COMMENT 'DIOT col 54: 01=Sí efectos fiscales, 02=No efectos fiscales',
  `iva_tratamiento_especial_diot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=aplica porcentaje especial de IVA acreditable en DIOT',
  `iva_porcentaje_acreditable_diot` decimal(7,4) NOT NULL DEFAULT 100.0000 COMMENT 'Porcentaje de IVA acreditable para DIOT; resto se informa como no acreditable por proporcion',
  `iva_motivo_tratamiento_diot` varchar(255) DEFAULT NULL COMMENT 'Motivo/criterio del tratamiento especial de IVA DIOT',
  `subtotal_no_objeto` decimal(18,2) DEFAULT 0.00,
  `rfc_proveedor_xml` varchar(13) DEFAULT NULL COMMENT 'RFC del emisor/proveedor tal como vino en el XML',
  `es_proveedor_extranjero_xml` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 cuando el RFC del proveedor en el XML es XEXX010101000',
  `lugar_expedicion_xml` varchar(5) DEFAULT NULL,
  `cp_catalogo_encontrado` tinyint(1) NOT NULL DEFAULT 0,
  `cp_catalogo_vigente` tinyint(1) NOT NULL DEFAULT 0,
  `cp_estimulo_fronterizo` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 no aplica, 1 norte, 2 sur',
  `cp_region_fronteriza` varchar(10) NOT NULL DEFAULT 'RESTO',
  `fecha_validacion_cp` datetime DEFAULT NULL,
  `tiene_iva_fronterizo_xml` tinyint(1) NOT NULL DEFAULT 0,
  `base_iva_fronteriza_xml` decimal(18,2) NOT NULL DEFAULT 0.00,
  `iva_fronterizo_xml` decimal(18,2) NOT NULL DEFAULT 0.00,
  `base_iva_frontera_norte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `iva_frontera_norte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `base_iva_frontera_sur` decimal(18,2) NOT NULL DEFAULT 0.00,
  `iva_frontera_sur` decimal(18,2) NOT NULL DEFAULT 0.00,
  `region_diot_detectada` varchar(10) NOT NULL DEFAULT 'RESTO' COMMENT 'RESTO, RFN, RFS o PENDIENTE',
  `region_diot_aplicada` varchar(10) NOT NULL DEFAULT 'RESTO',
  `requiere_revision_region` tinyint(1) NOT NULL DEFAULT 0,
  `mensaje_revision_region` varchar(255) DEFAULT NULL,
  `pdf_generado` tinyint(1) NOT NULL DEFAULT 0,
  `pdf_pendiente_regenerar` tinyint(1) NOT NULL DEFAULT 0,
  `ruta_pdf` varchar(1000) DEFAULT NULL,
  `fecha_generacion_pdf` datetime DEFAULT NULL,
  `hash_pdf` char(64) DEFAULT NULL,
  `version_pdf` varchar(30) DEFAULT NULL,
  `intentos_pdf` int(11) NOT NULL DEFAULT 0,
  `error_pdf` text DEFAULT NULL,
  `nomina_tipo_nomina` varchar(1) DEFAULT NULL COMMENT 'Nomina12 TipoNomina: O ordinaria, E extraordinaria',
  `nomina_fecha_pago` date DEFAULT NULL,
  `nomina_fecha_inicial_pago` date DEFAULT NULL,
  `nomina_fecha_final_pago` date DEFAULT NULL,
  `nomina_num_dias_pagados` decimal(10,3) DEFAULT NULL,
  `nomina_periodicidad_pago` varchar(3) DEFAULT NULL,
  `nomina_num_empleado` varchar(30) DEFAULT NULL,
  `nomina_total_percepciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `nomina_total_deducciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `nomina_total_otros_pagos` decimal(18,2) NOT NULL DEFAULT 0.00,
  `nomina_fecha_procesado` datetime DEFAULT NULL,
  PRIMARY KEY (`id_empresa`,`uuid`),
  KEY `id_emisor` (`id_emisor`),
  KEY `id_receptor` (`id_receptor`),
  KEY `id_empresa` (`id_empresa`,`fecha_emision`),
  KEY `estatus_sat` (`estatus_sat`),
  KEY `saldo_pendiente` (`saldo_pendiente`),
  KEY `anio_fiscal_reporte` (`anio_fiscal_reporte`,`mes_fiscal_reporte`),
  KEY `idx_facturas_proveedor_extranjero_xml` (`id_empresa`,`es_proveedor_extranjero_xml`,`rfc_proveedor_xml`),
  KEY `idx_factura_revision_region` (`id_empresa`,`requiere_revision_region`,`region_diot_aplicada`),
  KEY `idx_factura_cp_frontera` (`id_empresa`,`cp_estimulo_fronterizo`,`requiere_revision_region`),
  KEY `idx_facturas_conciliacion_cfdi` (`id_empresa`,`forma_pago`,`metodo_pago`,`uso_cfdi`,`fecha_emision`),
  KEY `idx_facturas_empresa_movimiento` (`id_empresa`,`tipo_movimiento_empresa`),
  KEY `idx_facturas_pdf_pendiente` (`pdf_generado`,`pdf_pendiente_regenerar`),
  KEY `idx_factura_empresa_fecha_uuid` (`id_empresa`,`fecha_emision`,`uuid`),
  KEY `idx_facturas_pdf_generar` (`pdf_generado`,`intentos_pdf`,`fecha_emision`,`uuid`),
  KEY `idx_facturas_pdf_regenerar` (`pdf_pendiente_regenerar`,`intentos_pdf`,`fecha_emision`,`uuid`),
  KEY `idx_pdf_worker` (`pdf_generado`,`intentos_pdf`,`fecha_emision`,`uuid`),
  KEY `idx_pdf_worker_orden` (`pdf_generado`,`fecha_emision`,`uuid`,`intentos_pdf`),
  KEY `idx_pdf_regenerar_orden` (`pdf_pendiente_regenerar`,`fecha_emision`,`uuid`,`intentos_pdf`),
  KEY `idx_visor_empresa_mov_fecha` (`id_empresa`,`tipo_movimiento_empresa`,`fecha_emision`),
  KEY `idx_visor_empresa_cfdi_fecha` (`id_empresa`,`id_tipo_comprobante`,`fecha_emision`),
  KEY `idx_visor_empresa_metodo_fecha` (`id_empresa`,`metodo_pago`,`fecha_emision`),
  KEY `idx_facturas_empresa_uuid` (`id_empresa`,`uuid`),
  KEY `idx_facturas_empresa_emisor_fecha` (`id_empresa`,`id_emisor`,`fecha_emision`),
  KEY `idx_facturas_empresa_receptor_fecha` (`id_empresa`,`id_receptor`,`fecha_emision`),
  KEY `idx_facturas_empresa_fecha` (`id_empresa`,`fecha_emision`),
  KEY `idx_facturas_iva_especial_diot` (`id_empresa`,`iva_tratamiento_especial_diot`,`fecha_aplicacion_fiscal`),
  KEY `idx_facturas_conciliacion_financiera` (`id_empresa`,`estatus_conciliacion_financiera`,`fecha_pago_origen`),
  KEY `idx_facturas_origen_pago` (`id_empresa`,`origen_pago_financiero`,`folio_pago_origen`),
  KEY `idx_facturas_empresa_uuid_conc` (`id_empresa`,`uuid`),
  KEY `idx_facturas_nomina_periodo` (`id_empresa`,`id_tipo_comprobante`,`nomina_fecha_inicial_pago`,`nomina_fecha_final_pago`),
  KEY `idx_facturas_nomina_empleado` (`id_empresa`,`nomina_num_empleado`,`nomina_fecha_pago`),
  KEY `idx_facturas_nomina_conciliacion` (`id_empresa`,`id_tipo_comprobante`,`nomina_fecha_inicial_pago`,`nomina_fecha_final_pago`,`id_receptor`),
  KEY `idx_facturas_pue_sin_pago_empresa` (`id_empresa`,`metodo_pago`,`fecha_pago_origen`,`fecha_ultimo_pago_contpaq`,`fecha_emision`),
  KEY `idx_facturas_pue_sin_pago_empresa_v2` (`id_empresa`,`tipo_movimiento_empresa`,`metodo_pago`,`fecha_pago_origen`,`fecha_emision`),
  KEY `idx_facturas_nomina_receptor_periodo` (`id_empresa`,`id_receptor`,`id_tipo_comprobante`,`nomina_fecha_inicial_pago`),
  KEY `idx_visor_empresa_fecha_folio` (`id_empresa`,`fecha_emision`,`folio`),
  KEY `idx_facturas_uuid_global` (`uuid`),
  KEY `idx_visor_empresa_folio_fecha` (`id_empresa`,`folio`,`fecha_emision`),
  CONSTRAINT `facturas_ibfk_1` FOREIGN KEY (`id_emisor`) REFERENCES `cat_emisores` (`id_emisor`),
  CONSTRAINT `facturas_ibfk_2` FOREIGN KEY (`id_receptor`) REFERENCES `cat_receptores` (`id_receptor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas_datos
CREATE TABLE IF NOT EXISTS `facturas_datos` (
  `uuid` varchar(36) NOT NULL,
  `xml_base64` longtext NOT NULL,
  `nombre_archivo` varchar(255) DEFAULT NULL,
  `ruta_xml` varchar(1500) DEFAULT NULL,
  `sha256_xml` char(64) DEFAULT NULL,
  `tamano_xml` bigint(20) unsigned DEFAULT NULL,
  `id_paquete_sat` bigint(20) unsigned DEFAULT NULL,
  `fecha_procesado` datetime DEFAULT NULL,
  `acuse_cancelacion_base64` longtext DEFAULT NULL,
  `fecha_subida` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`uuid`),
  KEY `idx_facturas_datos_paquete` (`id_paquete_sat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas_gasolina
CREATE TABLE IF NOT EXISTS `facturas_gasolina` (
  `uuid` varchar(36) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `version` varchar(10) DEFAULT NULL,
  `tipo_operacion` varchar(30) DEFAULT NULL,
  `numero_cuenta` varchar(100) DEFAULT NULL,
  `subtotal_combustible` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `total_combustible` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `iva_total` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `ieps_total` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `fecha_pago` date DEFAULT NULL,
  `total_pagado` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `saldo_pendiente` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `ya_pago` tinyint(1) NOT NULL DEFAULT 0,
  `observaciones` varchar(500) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_empresa`,`uuid`),
  KEY `idx_gasolina_empresa_fecha_pago` (`id_empresa`,`fecha_pago`),
  KEY `idx_gasolina_empresa_pendiente` (`id_empresa`,`ya_pago`,`saldo_pendiente`),
  KEY `idx_facturas_gasolina_uuid` (`uuid`),
  CONSTRAINT `fk_gasolina_factura_multi` FOREIGN KEY (`id_empresa`, `uuid`) REFERENCES `facturas` (`id_empresa`, `uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas_gasolina_detalles
CREATE TABLE IF NOT EXISTS `facturas_gasolina_detalles` (
  `id_detalle` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `clave_detalle` char(64) NOT NULL,
  `identificador` varchar(100) DEFAULT NULL,
  `fecha_operacion` datetime DEFAULT NULL,
  `rfc_gasolinera` varchar(13) NOT NULL,
  `clave_estacion` varchar(10) DEFAULT NULL,
  `tipo_combustible` varchar(10) DEFAULT NULL,
  `unidad` varchar(25) DEFAULT NULL,
  `nombre_combustible` varchar(300) DEFAULT NULL,
  `folio_operacion` varchar(50) DEFAULT NULL,
  `cantidad` decimal(18,3) NOT NULL DEFAULT 0.000,
  `valor_unitario` decimal(18,3) NOT NULL DEFAULT 0.000,
  `importe` decimal(18,2) NOT NULL DEFAULT 0.00,
  `iva` decimal(18,2) NOT NULL DEFAULT 0.00,
  `ieps` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_impuestos` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_operacion` decimal(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `ux_gasolina_empresa_clave` (`id_empresa`,`clave_detalle`),
  KEY `idx_gasolina_detalle_uuid` (`uuid`,`id_empresa`),
  KEY `idx_gasolina_detalle_rfc_fecha` (`id_empresa`,`rfc_gasolinera`,`fecha_operacion`),
  KEY `fk_gasolina_detalle_factura_multi` (`id_empresa`,`uuid`),
  CONSTRAINT `fk_gasolina_detalle_factura_multi` FOREIGN KEY (`id_empresa`, `uuid`) REFERENCES `facturas_gasolina` (`id_empresa`, `uuid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1932 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas_gasolina_impuestos
CREATE TABLE IF NOT EXISTS `facturas_gasolina_impuestos` (
  `id_impuesto` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_detalle` bigint(20) unsigned NOT NULL,
  `impuesto` varchar(10) NOT NULL,
  `tasa_cuota` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `importe` decimal(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_impuesto`),
  KEY `idx_gasolina_impuesto_detalle` (`id_detalle`),
  CONSTRAINT `fk_gasolina_impuesto_detalle` FOREIGN KEY (`id_detalle`) REFERENCES `facturas_gasolina_detalles` (`id_detalle`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1932 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.facturas_pagos_detalles
CREATE TABLE IF NOT EXISTS `facturas_pagos_detalles` (
  `id_detalle` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) DEFAULT NULL,
  `id_emisor` int(11) DEFAULT NULL COMMENT 'Emisor/proveedor de la factura relacionada para acceso rápido DIOT',
  `rfc_emisor` varchar(15) DEFAULT NULL COMMENT 'RFC del emisor/proveedor de la factura relacionada para acceso rápido DIOT',
  `uuid_pago` varchar(36) NOT NULL COMMENT 'UUID del Complemento de Pago (Padre)',
  `uuid_relacionado` varchar(36) NOT NULL COMMENT 'UUID de la Factura que se paga (Hijo)',
  `monto_pagado` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `importe_aplicado` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `parcialidad` int(11) DEFAULT 1,
  `saldo_anterior` decimal(18,4) DEFAULT 0.0000,
  `saldo_insoluto` decimal(18,4) DEFAULT 0.0000,
  `clave_detalle` char(64) DEFAULT NULL COMMENT 'SHA-256 de UUID complemento + posición Pago + posición DoctoRelacionado',
  `aplicado` tinyint(1) DEFAULT 0 COMMENT '0=Pendiente de procesar, 1=Ya afectó saldo',
  `es_sustituido` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=el UUID de pago fue sustituido por otro CFDI relación 04',
  `uuid_sustituto` varchar(36) DEFAULT NULL COMMENT 'UUID del CFDI que sustituye a uuid_pago',
  `origen_pago` varchar(20) NOT NULL DEFAULT 'COMPLEMENTO_SAT',
  `es_sintetico` tinyint(1) NOT NULL DEFAULT 0,
  `moneda_factura` varchar(10) DEFAULT NULL,
  `tc_factura` decimal(20,8) DEFAULT NULL,
  `moneda_pago_sat` varchar(10) DEFAULT NULL,
  `tc_pago_sat` decimal(20,8) DEFAULT NULL,
  `moneda_dr` varchar(10) DEFAULT NULL,
  `equivalencia_dr` decimal(20,10) DEFAULT NULL,
  `fecha_pago_sat` date DEFAULT NULL,
  `fecha_pago_banco` date DEFAULT NULL,
  `fecha_pago_contpaq` date DEFAULT NULL,
  `moneda_contpaq` varchar(10) DEFAULT NULL,
  `tc_contpaq` decimal(20,8) DEFAULT NULL,
  `monto_conciliado_contpaq` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `saldo_por_conciliar` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `estatus_conciliacion_contpaq` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `origen_financiero` varchar(20) DEFAULT NULL,
  `folio_pago_origen` varchar(100) DEFAULT NULL,
  `fecha_pago_origen` date DEFAULT NULL,
  `moneda_pago_origen` varchar(10) DEFAULT NULL,
  `tc_pago_origen` decimal(20,8) DEFAULT NULL,
  `monto_conciliado_origen` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `saldo_por_conciliar_origen` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `estatus_conciliacion_origen` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `conciliado_banco_origen` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_conciliacion_banco_origen` date DEFAULT NULL,
  `fecha_valor_origen` date DEFAULT NULL,
  `fecha_ultima_conciliacion_origen` datetime DEFAULT NULL,
  `observaciones_conciliacion_contpaq` varchar(500) DEFAULT NULL,
  `fecha_ultima_conciliacion_contpaq` datetime DEFAULT NULL,
  `fecha_pago_usuario` date DEFAULT NULL,
  `fecha_aplicacion_fiscal` date DEFAULT NULL,
  `forma_pago` varchar(2) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `proporcion_aplicada` decimal(18,8) NOT NULL DEFAULT 0.00000000,
  `base_iva_16` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `iva_16` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_iva_8` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `iva_8` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_tasa_0` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_exento` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `base_no_objeto` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `iva_retenido` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `isr_retenido` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `ieps_otros` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `ux_facturas_pagos_empresa_clave` (`id_empresa`,`clave_detalle`),
  KEY `idx_uuid_pago` (`uuid_pago`),
  KEY `idx_uuid_relacionado` (`uuid_relacionado`),
  KEY `idx_aplicado` (`aplicado`),
  KEY `idx_diot_empresa_fecha` (`id_empresa`,`fecha_aplicacion_fiscal`),
  KEY `idx_diot_factura_fecha` (`uuid_relacionado`,`fecha_aplicacion_fiscal`),
  KEY `idx_empresa_uuid_relacionado` (`id_empresa`,`uuid_relacionado`),
  KEY `idx_fpd_empresa_uuid_rel_pago` (`id_empresa`,`uuid_relacionado`,`uuid_pago`,`es_sintetico`),
  KEY `idx_fpd_empresa_detalle` (`id_empresa`,`id_detalle`),
  KEY `idx_fpd_empresa_uuid_pago` (`id_empresa`,`uuid_relacionado`,`uuid_pago`),
  KEY `idx_diot_empresa_emisor_fecha` (`id_empresa`,`id_emisor`,`fecha_aplicacion_fiscal`,`aplicado`),
  KEY `idx_diot_empresa_rfc_fecha` (`id_empresa`,`rfc_emisor`,`fecha_aplicacion_fiscal`,`aplicado`),
  KEY `idx_fpd_empresa_uuid_relacionado` (`id_empresa`,`uuid_relacionado`),
  KEY `idx_pagos_det_empresa_fecha_fiscal_uuid` (`id_empresa`,`fecha_aplicacion_fiscal`,`uuid_relacionado`),
  KEY `idx_fpd_empresa_sustituido_fecha` (`id_empresa`,`es_sustituido`,`fecha_aplicacion_fiscal`),
  KEY `idx_fpd_empresa_uuid_pago_sust` (`id_empresa`,`uuid_pago`,`es_sustituido`),
  KEY `idx_fpd_conciliacion_origen` (`id_empresa`,`estatus_conciliacion_origen`,`fecha_pago_origen`),
  KEY `idx_fpd_folio_origen` (`id_empresa`,`origen_financiero`,`folio_pago_origen`),
  KEY `idx_fpd_empresa_uuid_rel_conc` (`id_empresa`,`uuid_relacionado`),
  KEY `idx_fpd_empresa_pago_sustituido` (`id_empresa`,`uuid_pago`,`es_sustituido`)
) ENGINE=InnoDB AUTO_INCREMENT=108247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.factura_conceptos
CREATE TABLE IF NOT EXISTS `factura_conceptos` (
  `id_concepto` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) DEFAULT NULL,
  `cantidad` decimal(18,4) DEFAULT NULL,
  `unidad` varchar(50) DEFAULT NULL,
  `clave_prod_serv` varchar(20) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `valor_unitario` decimal(18,4) DEFAULT NULL,
  `importe` decimal(18,4) DEFAULT NULL,
  `descuento` decimal(18,4) DEFAULT 0.0000,
  `objeto_imp` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`id_concepto`),
  KEY `fk_factura_conceptos` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=2025126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_campos
CREATE TABLE IF NOT EXISTS `nomina_campos` (
  `id_campo` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `clave_hash` char(64) NOT NULL,
  `clave_campo` text NOT NULL,
  `encabezado` text NOT NULL,
  `tipo_valor` varchar(10) NOT NULL DEFAULT 'TEXTO',
  `estado` varchar(15) NOT NULL DEFAULT 'PENDIENTE',
  `id_usuario_alta` int(11) DEFAULT NULL,
  `fecha_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_alta` datetime DEFAULT NULL,
  PRIMARY KEY (`id_campo`),
  UNIQUE KEY `uk_nc_empresa_clave` (`id_empresa`,`clave_hash`),
  CONSTRAINT `fk_nc_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_conciliaciones
CREATE TABLE IF NOT EXISTS `nomina_conciliaciones` (
  `id_conciliacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `modo` varchar(15) NOT NULL DEFAULT 'PERIODOS' COMMENT 'PERIODOS o RANGO',
  `periodo_desde` smallint(5) unsigned DEFAULT NULL,
  `periodo_hasta` smallint(5) unsigned DEFAULT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `tipo_nomina` varchar(30) DEFAULT NULL,
  `total_empleados_reporte` int(11) NOT NULL DEFAULT 0,
  `total_empleados_visor` int(11) NOT NULL DEFAULT 0,
  `total_conciliados` int(11) NOT NULL DEFAULT 0,
  `total_diferencias` int(11) NOT NULL DEFAULT 0,
  `total_solo_reporte` int(11) NOT NULL DEFAULT 0,
  `total_solo_visor` int(11) NOT NULL DEFAULT 0,
  `total_cancelados` int(11) NOT NULL DEFAULT 0,
  `total_reporte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_visor` decimal(18,2) NOT NULL DEFAULT 0.00,
  `diferencia` decimal(18,2) NOT NULL DEFAULT 0.00,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_conciliacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_conciliacion`),
  KEY `idx_nomina_conc_empresa_fecha` (`id_empresa`,`fecha_conciliacion`),
  KEY `idx_nomina_conc_empresa_periodo` (`id_empresa`,`anio`,`periodo_desde`,`periodo_hasta`,`tipo_nomina`),
  KEY `fk_nomina_conc_usuario` (`id_usuario`),
  CONSTRAINT `fk_nomina_conc_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomina_conc_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_conciliacion_detalles
CREATE TABLE IF NOT EXISTS `nomina_conciliacion_detalles` (
  `id_detalle` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_conciliacion` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `numero_empleado` varchar(30) DEFAULT NULL,
  `rfc` varchar(15) NOT NULL,
  `nombre_completo` varchar(350) DEFAULT NULL,
  `usa_desglose` tinyint(1) NOT NULL DEFAULT 0,
  `percepciones_visor` decimal(18,2) NOT NULL DEFAULT 0.00,
  `percepciones_reporte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `diferencia_percepciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `deducciones_visor` decimal(18,2) NOT NULL DEFAULT 0.00,
  `deducciones_reporte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `diferencia_deducciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_visor` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_reporte` decimal(18,2) NOT NULL DEFAULT 0.00,
  `diferencia` decimal(18,2) NOT NULL DEFAULT 0.00,
  `documentos_visor` int(11) NOT NULL DEFAULT 0,
  `documentos_cancelados` int(11) NOT NULL DEFAULT 0,
  `periodos_reporte` int(11) NOT NULL DEFAULT 0,
  `estatus` varchar(30) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `uk_nomina_conc_det_rfc` (`id_conciliacion`,`rfc`),
  KEY `idx_nomina_conc_det_empresa_rfc` (`id_empresa`,`rfc`),
  KEY `idx_nomina_conc_det_estatus` (`id_conciliacion`,`estatus`),
  KEY `idx_nomina_conc_det_conc_emp_rfc` (`id_conciliacion`,`id_empresa`,`rfc`),
  CONSTRAINT `fk_nomina_conc_det_conc` FOREIGN KEY (`id_conciliacion`) REFERENCES `nomina_conciliaciones` (`id_conciliacion`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomina_conc_det_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=305 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_config_reglas
CREATE TABLE IF NOT EXISTS `nomina_config_reglas` (
  `id_regla` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_campo` bigint(20) unsigned NOT NULL,
  `version_regla` int(10) unsigned NOT NULL,
  `definicion_json` longtext NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `comentario` varchar(500) NOT NULL,
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_regla`),
  UNIQUE KEY `uk_ncr_campo` (`id_empresa`,`id_campo`),
  KEY `fk_ncr_campo` (`id_campo`),
  CONSTRAINT `fk_ncr_campo` FOREIGN KEY (`id_campo`) REFERENCES `nomina_campos` (`id_campo`) ON DELETE CASCADE,
  CONSTRAINT `fk_ncr_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_config_reglas_historial
CREATE TABLE IF NOT EXISTS `nomina_config_reglas_historial` (
  `id_regla` bigint(20) unsigned NOT NULL,
  `version_regla` int(10) unsigned NOT NULL,
  `definicion_json` longtext NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `comentario` varchar(500) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_regla`,`version_regla`),
  CONSTRAINT `fk_ncrh_regla` FOREIGN KEY (`id_regla`) REFERENCES `nomina_config_reglas` (`id_regla`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_config_xml_campos
CREATE TABLE IF NOT EXISTS `nomina_config_xml_campos` (
  `id_xml_campo` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `selector_hash` char(64) NOT NULL,
  `selector_json` text NOT NULL,
  `etiqueta` text NOT NULL,
  `ejemplos_json` text NOT NULL,
  `tipo_sugerido` varchar(10) NOT NULL,
  `id_muestra` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id_xml_campo`),
  UNIQUE KEY `uk_ncxc_selector` (`id_empresa`,`selector_hash`),
  KEY `fk_ncxc_muestra` (`id_muestra`),
  CONSTRAINT `fk_ncxc_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_ncxc_muestra` FOREIGN KEY (`id_muestra`) REFERENCES `nomina_config_xml_muestras` (`id_muestra`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_config_xml_muestras
CREATE TABLE IF NOT EXISTS `nomina_config_xml_muestras` (
  `id_muestra` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `hash_archivo` char(64) NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `uuid` varchar(36) DEFAULT NULL,
  `rfc_receptor` varchar(15) DEFAULT NULL,
  `fecha_pago` varchar(10) DEFAULT NULL,
  `xml_texto` mediumtext NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_muestra`),
  UNIQUE KEY `uk_ncxm_hash` (`id_empresa`,`hash_archivo`),
  CONSTRAINT `fk_ncxm_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_detalles
CREATE TABLE IF NOT EXISTS `nomina_detalles` (
  `id_detalle` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_importacion` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `numero_empleado` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `apellido_paterno` varchar(100) DEFAULT NULL,
  `apellido_materno` varchar(100) DEFAULT NULL,
  `rfc` varchar(15) NOT NULL,
  `total_percepciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_deducciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `neto` decimal(18,2) NOT NULL DEFAULT 0.00,
  `renglon_origen` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `uk_nomina_detalle_empleado` (`id_importacion`,`numero_empleado`,`rfc`),
  KEY `idx_nomina_det_empresa_rfc` (`id_empresa`,`rfc`),
  KEY `idx_nomina_det_empresa_empleado` (`id_empresa`,`numero_empleado`),
  KEY `idx_nomina_det_importacion` (`id_importacion`),
  KEY `idx_nomina_conciliacion_reporte` (`id_empresa`,`rfc`,`id_importacion`),
  CONSTRAINT `fk_nomina_det_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomina_det_import` FOREIGN KEY (`id_importacion`) REFERENCES `nomina_importaciones` (`id_importacion`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=343 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_eliminaciones
CREATE TABLE IF NOT EXISTS `nomina_eliminaciones` (
  `id_eliminacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_importacion_original` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `periodo` smallint(5) unsigned NOT NULL,
  `periodo_desde` smallint(5) unsigned DEFAULT NULL,
  `periodo_hasta` smallint(5) unsigned DEFAULT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `tipo_nomina` varchar(30) NOT NULL DEFAULT 'SEMANAL',
  `referencia_nomina` varchar(80) DEFAULT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `total_empleados` int(11) NOT NULL DEFAULT 0,
  `total_neto` decimal(18,2) NOT NULL DEFAULT 0.00,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_eliminacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_eliminacion`),
  KEY `idx_nomina_elim_empresa_fecha` (`id_empresa`,`fecha_eliminacion`),
  KEY `idx_nomina_elim_periodo` (`id_empresa`,`anio`,`periodo`,`tipo_nomina`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_fuentes
CREATE TABLE IF NOT EXISTS `nomina_fuentes` (
  `id_fuente` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_importacion` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `extension` varchar(5) NOT NULL,
  `hash_archivo` char(64) NOT NULL,
  `bytes_archivo` bigint(20) unsigned NOT NULL,
  `hoja` varchar(255) NOT NULL,
  `fila_encabezado` int(10) unsigned NOT NULL,
  `total_columnas` int(10) unsigned NOT NULL,
  `total_filas` int(10) unsigned NOT NULL,
  `columnas_json` longtext NOT NULL,
  `metadata_json` longtext NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_fuente`),
  KEY `idx_nf_empresa_importacion` (`id_empresa`,`id_importacion`,`id_fuente`),
  KEY `idx_nf_importacion` (`id_importacion`),
  CONSTRAINT `fk_nf_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_nf_importacion` FOREIGN KEY (`id_importacion`) REFERENCES `nomina_importaciones` (`id_importacion`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_fuente_bloques
CREATE TABLE IF NOT EXISTS `nomina_fuente_bloques` (
  `id_fuente` bigint(20) unsigned NOT NULL,
  `numero_bloque` int(10) unsigned NOT NULL,
  `contenido` mediumblob NOT NULL,
  PRIMARY KEY (`id_fuente`,`numero_bloque`),
  CONSTRAINT `fk_nfb_fuente` FOREIGN KEY (`id_fuente`) REFERENCES `nomina_fuentes` (`id_fuente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_fuente_filas
CREATE TABLE IF NOT EXISTS `nomina_fuente_filas` (
  `id_fuente` bigint(20) unsigned NOT NULL,
  `renglon` int(10) unsigned NOT NULL,
  `clase` varchar(15) NOT NULL,
  `numero_empleado` varchar(30) DEFAULT NULL,
  `rfc` varchar(15) DEFAULT NULL,
  `valores_json` longtext NOT NULL,
  PRIMARY KEY (`id_fuente`,`renglon`),
  KEY `idx_nff_rfc` (`id_fuente`,`rfc`,`renglon`),
  KEY `idx_nff_empleado` (`id_fuente`,`numero_empleado`,`renglon`),
  CONSTRAINT `fk_nff_fuente` FOREIGN KEY (`id_fuente`) REFERENCES `nomina_fuentes` (`id_fuente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_importaciones
CREATE TABLE IF NOT EXISTS `nomina_importaciones` (
  `id_importacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `periodo` smallint(5) unsigned NOT NULL,
  `periodo_desde` smallint(5) unsigned DEFAULT NULL,
  `periodo_hasta` smallint(5) unsigned DEFAULT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `tipo_nomina` varchar(30) NOT NULL DEFAULT 'SEMANAL',
  `referencia_nomina` varchar(80) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `tipo_nomina_empleado` varchar(50) DEFAULT NULL,
  `tipo_nomina_procesada` varchar(50) DEFAULT NULL,
  `empresa_archivo` varchar(255) DEFAULT NULL,
  `rfc_empresa_archivo` varchar(15) NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `hash_archivo` char(64) NOT NULL,
  `total_empleados` int(11) NOT NULL DEFAULT 0,
  `total_percepciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_deducciones` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_neto` decimal(18,2) NOT NULL DEFAULT 0.00,
  `estatus` enum('IMPORTADO','REIMPORTADO') NOT NULL DEFAULT 'IMPORTADO',
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_importacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_importacion`),
  UNIQUE KEY `uk_nomina_periodo_tipo` (`id_empresa`,`anio`,`periodo`,`fecha_desde`,`fecha_hasta`,`tipo_nomina`),
  KEY `idx_nomina_empresa_fechas` (`id_empresa`,`fecha_desde`,`fecha_hasta`),
  KEY `idx_nomina_empresa_anio_periodo` (`id_empresa`,`anio`,`periodo`),
  KEY `idx_nomina_hash` (`id_empresa`,`hash_archivo`),
  KEY `fk_nomina_import_usuario` (`id_usuario`),
  KEY `idx_nomina_empresa_periodos` (`id_empresa`,`anio`,`periodo_desde`,`periodo_hasta`,`tipo_nomina`),
  CONSTRAINT `fk_nomina_import_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomina_import_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.nomina_periodo_config
CREATE TABLE IF NOT EXISTS `nomina_periodo_config` (
  `id_config` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `fecha_inicio_periodo1` date NOT NULL,
  `dias_periodo` smallint(5) unsigned NOT NULL DEFAULT 7,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_config`),
  UNIQUE KEY `uk_nomina_periodo_config_empresa_anio` (`id_empresa`,`anio`),
  KEY `idx_nomina_periodo_config_empresa` (`id_empresa`,`anio`),
  KEY `fk_nomina_periodo_config_usuario` (`id_usuario`),
  CONSTRAINT `fk_nomina_periodo_config_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_nomina_periodo_config_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.notificaciones_sat
CREATE TABLE IF NOT EXISTS `notificaciones_sat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `tipo_alerta` varchar(30) NOT NULL DEFAULT 'CANCELACION',
  `estatus_anterior` varchar(20) DEFAULT NULL,
  `estatus_nuevo` varchar(20) NOT NULL DEFAULT 'Cancelado',
  `fecha_documento` datetime DEFAULT NULL,
  `fecha_cancelacion_sat` datetime DEFAULT NULL,
  `fecha_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_notificacion` datetime NOT NULL DEFAULT current_timestamp(),
  `estatus_notificacion` varchar(20) NOT NULL DEFAULT 'NUEVA',
  `id_usuario_visto` int(11) DEFAULT NULL,
  `fecha_visto` datetime DEFAULT NULL,
  `id_usuario_atendio` int(11) DEFAULT NULL,
  `fecha_atendido` datetime DEFAULT NULL,
  `observaciones` varchar(2000) DEFAULT NULL,
  `id_paquete_metadata` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notificacion_empresa_uuid_tipo` (`id_empresa`,`uuid`,`tipo_alerta`),
  KEY `idx_notificacion_empresa_estado` (`id_empresa`,`estatus_notificacion`,`fecha_deteccion`),
  KEY `idx_notificacion_empresa_fecha` (`id_empresa`,`fecha_cancelacion_sat`)
) ENGINE=InnoDB AUTO_INCREMENT=2467 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_conciliacion_clasificaciones
CREATE TABLE IF NOT EXISTS `oracle_conciliacion_clasificaciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `tipo_documento` varchar(30) NOT NULL,
  `id_documento_origen` bigint(20) unsigned NOT NULL,
  `estatus_asignado` varchar(40) NOT NULL,
  `origen_clasificacion` varchar(20) NOT NULL DEFAULT 'MANUAL',
  `observaciones` varchar(500) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oracle_clasificacion_doc` (`id_empresa`,`tipo_documento`,`id_documento_origen`),
  KEY `idx_oracle_clasificacion_estado` (`id_empresa`,`estatus_asignado`),
  KEY `fk_occ_usuario` (`id_usuario`),
  CONSTRAINT `fk_occ_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_occ_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_conciliacion_reglas
CREATE TABLE IF NOT EXISTS `oracle_conciliacion_reglas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) DEFAULT NULL COMMENT 'NULL = regla global para todas las empresas',
  `patron` varchar(250) NOT NULL,
  `campo_busqueda` varchar(20) NOT NULL DEFAULT 'TODOS',
  `tipo_coincidencia` varchar(20) NOT NULL DEFAULT 'CONTIENE',
  `estatus_asignado` varchar(40) NOT NULL DEFAULT 'NO_CONSIDERAR',
  `prioridad` int(11) NOT NULL DEFAULT 100,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `observaciones` varchar(500) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ocr_empresa_activo` (`id_empresa`,`activo`,`prioridad`),
  KEY `idx_ocr_estado` (`estatus_asignado`),
  KEY `fk_ocr_usuario` (`id_usuario`),
  CONSTRAINT `fk_ocr_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_ocr_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_conciliacion_uuid_manual
CREATE TABLE IF NOT EXISTS `oracle_conciliacion_uuid_manual` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `tipo_documento` varchar(30) NOT NULL,
  `id_documento_origen` bigint(20) unsigned NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `rfc_validado` varchar(15) NOT NULL,
  `importe_origen` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `importe_xml` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `moneda_origen` varchar(10) DEFAULT NULL,
  `moneda_xml` varchar(10) DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oracle_uuid_manual_doc` (`id_empresa`,`tipo_documento`,`id_documento_origen`),
  KEY `idx_oracle_uuid_manual_uuid` (`id_empresa`,`uuid`,`activo`),
  KEY `fk_oum_usuario` (`id_usuario`),
  CONSTRAINT `fk_oum_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_oum_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_facturas
CREATE TABLE IF NOT EXISTS `oracle_facturas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `oracle_invoice_id` varchar(40) NOT NULL,
  `invoice_number` varchar(150) DEFAULT NULL,
  `invoice_currency` varchar(10) DEFAULT NULL,
  `payment_currency` varchar(10) DEFAULT NULL,
  `invoice_amount` decimal(20,6) DEFAULT NULL,
  `amount_paid` decimal(20,6) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `business_unit` varchar(150) DEFAULT NULL,
  `supplier` varchar(500) DEFAULT NULL,
  `supplier_number` varchar(100) DEFAULT NULL,
  `supplier_tax_registration_number` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `invoice_type` varchar(100) DEFAULT NULL,
  `payment_method_code` varchar(100) DEFAULT NULL,
  `payment_method` varchar(150) DEFAULT NULL,
  `legal_entity` varchar(300) DEFAULT NULL,
  `legal_entity_identifier` varchar(50) DEFAULT NULL,
  `paid_status` varchar(80) DEFAULT NULL,
  `validation_status` varchar(80) DEFAULT NULL,
  `approval_status` varchar(80) DEFAULT NULL,
  `accounting_status` varchar(80) DEFAULT NULL,
  `canceled_flag` tinyint(1) NOT NULL DEFAULT 0,
  `canceled_date` date DEFAULT NULL,
  `canceled_by` varchar(150) DEFAULT NULL,
  `purchase_order_number` varchar(150) DEFAULT NULL,
  `payment_check_id` varchar(40) DEFAULT NULL,
  `payment_number` varchar(80) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `payment_status` varchar(80) DEFAULT NULL,
  `payment_reconciled_flag` tinyint(1) NOT NULL DEFAULT 0,
  `payment_clearing_date` date DEFAULT NULL,
  `payment_clearing_value_date` date DEFAULT NULL,
  `payment_amount` decimal(20,6) DEFAULT NULL,
  `payment_currency_detail` varchar(10) DEFAULT NULL,
  `payment_void_date` date DEFAULT NULL,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `uuid_cfdi` varchar(36) DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  `conciliado` tinyint(1) NOT NULL DEFAULT 0,
  `estatus_conciliacion` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_conciliacion` datetime DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oracle_factura` (`id_empresa`,`oracle_invoice_id`),
  KEY `idx_oracle_factura_periodo` (`id_empresa`,`invoice_date`),
  KEY `idx_oracle_factura_uuid` (`id_empresa`,`uuid_cfdi`),
  KEY `idx_oracle_factura_empresa_numero` (`id_empresa`,`invoice_number`),
  CONSTRAINT `fk_oracle_factura_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_factura_pagos
CREATE TABLE IF NOT EXISTS `oracle_factura_pagos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `oracle_invoice_id` varchar(40) NOT NULL,
  `invoice_number` varchar(150) DEFAULT NULL,
  `check_id` varchar(40) NOT NULL,
  `invoice_payment_id` varchar(40) DEFAULT NULL,
  `payment_id` varchar(40) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `paper_document_number` varchar(100) DEFAULT NULL,
  `payment_number` varchar(100) DEFAULT NULL,
  `payment_amount` decimal(20,6) DEFAULT NULL,
  `invoice_payment_amount` decimal(20,6) DEFAULT NULL,
  `amount_paid_payment_currency` decimal(20,6) DEFAULT NULL,
  `amount_paid_invoice_currency` decimal(20,6) DEFAULT NULL,
  `payment_currency` varchar(10) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `accounting_date` date DEFAULT NULL,
  `payment_description` varchar(1000) DEFAULT NULL,
  `payment_status` varchar(80) DEFAULT NULL,
  `invoice_payment_status` varchar(20) DEFAULT NULL,
  `accounting_status` varchar(80) DEFAULT NULL,
  `reconciled_flag` tinyint(1) NOT NULL DEFAULT 0,
  `clearing_date` date DEFAULT NULL,
  `clearing_value_date` date DEFAULT NULL,
  `clearing_amount` decimal(20,6) DEFAULT NULL,
  `payment_method_code` varchar(80) DEFAULT NULL,
  `payment_type` varchar(80) DEFAULT NULL,
  `payee` varchar(500) DEFAULT NULL,
  `supplier_number` varchar(100) DEFAULT NULL,
  `installment_number` int(11) DEFAULT NULL,
  `void_date` date DEFAULT NULL,
  `void_accounting_date` date DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oracle_factura_pago` (`id_empresa`,`oracle_invoice_id`,`check_id`,`invoice_payment_id`),
  KEY `idx_oracle_factura_pago_fecha` (`id_empresa`,`payment_date`),
  KEY `idx_oracle_factura_pago_check` (`id_empresa`,`check_id`),
  KEY `idx_oracle_factura_pago_invoice` (`id_empresa`,`oracle_invoice_id`),
  CONSTRAINT `fk_oracle_factura_pago_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_gastos
CREATE TABLE IF NOT EXISTS `oracle_gastos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `oracle_expense_id` varchar(40) NOT NULL,
  `oracle_expense_report_id` varchar(40) DEFAULT NULL,
  `business_unit` varchar(150) DEFAULT NULL,
  `person_id` varchar(40) DEFAULT NULL,
  `person_name` varchar(300) DEFAULT NULL,
  `merchant_name` varchar(500) DEFAULT NULL,
  `merchant_taxpayer_id` varchar(50) DEFAULT NULL,
  `expense_type` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `receipt_amount` decimal(20,6) DEFAULT NULL,
  `receipt_currency_code` varchar(10) DEFAULT NULL,
  `reimbursable_amount` decimal(20,6) DEFAULT NULL,
  `reimbursement_currency_code` varchar(10) DEFAULT NULL,
  `receipt_date` date DEFAULT NULL,
  `creation_date` datetime DEFAULT NULL,
  `expense_report_status` varchar(80) DEFAULT NULL,
  `payment_due_from_code` varchar(80) DEFAULT NULL,
  `expense_reference` varchar(200) DEFAULT NULL,
  `reference_number` varchar(200) DEFAULT NULL,
  `uuid_cfdi` varchar(36) DEFAULT NULL,
  `datos_origen_json` longtext DEFAULT NULL,
  `conciliado` tinyint(1) NOT NULL DEFAULT 0,
  `estatus_conciliacion` varchar(30) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_conciliacion` datetime DEFAULT NULL,
  `fecha_primera_sync` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_sync` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oracle_gasto` (`id_empresa`,`oracle_expense_id`),
  KEY `idx_oracle_gasto_report` (`id_empresa`,`oracle_expense_report_id`),
  KEY `idx_oracle_gasto_fecha` (`id_empresa`,`creation_date`),
  KEY `idx_oracle_gasto_uuid` (`id_empresa`,`uuid_cfdi`),
  CONSTRAINT `fk_oracle_gasto_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_iva_archivos
CREATE TABLE IF NOT EXISTS `oracle_iva_archivos` (
  `id_archivo` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `mes` tinyint(3) unsigned NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `ruta_archivo` varchar(1200) NOT NULL,
  `sha256` char(64) NOT NULL,
  `hoja_principal` varchar(190) DEFAULT NULL,
  `filas_importadas` int(11) NOT NULL DEFAULT 0,
  `encabezados_detectados` int(11) NOT NULL DEFAULT 0,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_subida` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_archivo`),
  KEY `idx_oia_empresa_periodo` (`id_empresa`,`anio`,`mes`,`fecha_subida`),
  KEY `fk_oia_usuario` (`id_usuario`),
  CONSTRAINT `fk_oia_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_oia_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_iva_conciliaciones
CREATE TABLE IF NOT EXISTS `oracle_iva_conciliaciones` (
  `id_conciliacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_archivo` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `anio` smallint(5) unsigned NOT NULL,
  `mes` tinyint(3) unsigned NOT NULL,
  `tolerancia` decimal(10,4) NOT NULL DEFAULT 0.0500,
  `resultado_json` longtext NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_conciliacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_conciliacion`),
  KEY `idx_oic_empresa_periodo` (`id_empresa`,`anio`,`mes`,`fecha_conciliacion`),
  KEY `idx_oic_archivo` (`id_archivo`),
  KEY `fk_oic_usuario` (`id_usuario`),
  CONSTRAINT `fk_oic_archivo` FOREIGN KEY (`id_archivo`) REFERENCES `oracle_iva_archivos` (`id_archivo`) ON DELETE CASCADE,
  CONSTRAINT `fk_oic_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_oic_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_iva_movimientos
CREATE TABLE IF NOT EXISTS `oracle_iva_movimientos` (
  `id_movimiento` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_archivo` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `hoja` varchar(190) NOT NULL,
  `renglon_excel` int(11) NOT NULL,
  `cuenta` varchar(100) DEFAULT NULL,
  `cuenta_descripcion` varchar(700) DEFAULT NULL,
  `cuenta_tipo` varchar(20) NOT NULL DEFAULT 'OTRO',
  `origen` varchar(120) DEFAULT NULL,
  `categoria` varchar(120) DEFAULT NULL,
  `fecha_contable` date DEFAULT NULL,
  `clase_evento` varchar(120) DEFAULT NULL,
  `numero_transaccion` varchar(190) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `debito` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `credito` decimal(20,6) NOT NULL DEFAULT 0.000000,
  `referencia_factura` varchar(190) DEFAULT NULL,
  `tipo_referencia` varchar(20) NOT NULL DEFAULT 'OTRO',
  PRIMARY KEY (`id_movimiento`),
  KEY `idx_oim_archivo` (`id_archivo`,`renglon_excel`),
  KEY `idx_oim_empresa_ref` (`id_empresa`,`referencia_factura`),
  KEY `idx_oim_archivo_tipo` (`id_archivo`,`cuenta_tipo`),
  CONSTRAINT `fk_oim_archivo` FOREIGN KEY (`id_archivo`) REFERENCES `oracle_iva_archivos` (`id_archivo`) ON DELETE CASCADE,
  CONSTRAINT `fk_oim_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.oracle_pagos_sync_jobs
CREATE TABLE IF NOT EXISTS `oracle_pagos_sync_jobs` (
  `id_job` varchar(64) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `desde` date NOT NULL,
  `hasta` date NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'PROCESANDO',
  `fase` varchar(20) NOT NULL DEFAULT 'FACTURAS',
  `offset_actual` int(11) NOT NULL DEFAULT 0,
  `total_facturas` int(11) DEFAULT NULL,
  `total_gastos` int(11) DEFAULT NULL,
  `procesados_facturas` int(11) NOT NULL DEFAULT 0,
  `procesados_gastos` int(11) NOT NULL DEFAULT 0,
  `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
  `ultimo_error` text DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_job`),
  KEY `idx_oracle_job_empresa` (`id_empresa`,`estado`,`fecha_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69b_alertas_emisores
CREATE TABLE IF NOT EXISTS `sat_69b_alertas_emisores` (
  `id_alerta` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_emisor` int(11) NOT NULL,
  `id_69b` int(11) DEFAULT NULL,
  `rfc` varchar(20) NOT NULL,
  `nombre_emisor` varchar(255) NOT NULL,
  `nombre_sat` varchar(255) DEFAULT NULL,
  `situacion` varchar(50) NOT NULL,
  `fecha_publicacion_relevante` varchar(100) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `estatus_seguimiento` varchar(20) NOT NULL DEFAULT 'NUEVA',
  `fecha_primera_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario_visto` int(11) DEFAULT NULL,
  `fecha_visto` datetime DEFAULT NULL,
  `id_usuario_atendio` int(11) DEFAULT NULL,
  `fecha_atendido` datetime DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id_alerta`),
  UNIQUE KEY `uk_69b_alerta_emisor_registro` (`id_emisor`,`id_69b`),
  KEY `idx_69b_alerta_empresa` (`id_empresa`,`activa`,`estatus_seguimiento`),
  KEY `idx_69b_alerta_rfc` (`rfc`),
  KEY `idx_69b_alerta_situacion` (`situacion`),
  KEY `idx_69b_alerta_emisor` (`id_emisor`),
  CONSTRAINT `fk_69b_alerta_emisor` FOREIGN KEY (`id_emisor`) REFERENCES `cat_emisores` (`id_emisor`) ON DELETE CASCADE,
  CONSTRAINT `fk_69b_alerta_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69b_contribuyentes
CREATE TABLE IF NOT EXISTS `sat_69b_contribuyentes` (
  `id_69b` int(11) NOT NULL AUTO_INCREMENT,
  `numero_sat` int(11) DEFAULT NULL,
  `rfc` varchar(20) NOT NULL,
  `nombre_contribuyente` varchar(255) NOT NULL,
  `situacion` varchar(50) NOT NULL,
  `oficio_presuncion_sat` text DEFAULT NULL,
  `fecha_sat_presuntos` varchar(100) DEFAULT NULL,
  `oficio_presuncion_dof` text DEFAULT NULL,
  `fecha_dof_presuntos` varchar(100) DEFAULT NULL,
  `oficio_desvirtuado_sat` text DEFAULT NULL,
  `fecha_sat_desvirtuados` varchar(100) DEFAULT NULL,
  `oficio_desvirtuado_dof` text DEFAULT NULL,
  `fecha_dof_desvirtuados` varchar(100) DEFAULT NULL,
  `oficio_definitivo_sat` text DEFAULT NULL,
  `fecha_sat_definitivos` varchar(100) DEFAULT NULL,
  `oficio_definitivo_dof` text DEFAULT NULL,
  `fecha_dof_definitivos` varchar(100) DEFAULT NULL,
  `oficio_sentencia_sat` text DEFAULT NULL,
  `fecha_sat_sentencia` varchar(100) DEFAULT NULL,
  `oficio_sentencia_dof` text DEFAULT NULL,
  `fecha_dof_sentencia` varchar(100) DEFAULT NULL,
  `fecha_sincronizacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_69b`),
  KEY `idx_sat_69b_situacion` (`situacion`),
  KEY `idx_sat_69b_nombre` (`nombre_contribuyente`),
  KEY `idx_sat_69b_numero` (`numero_sat`)
) ENGINE=InnoDB AUTO_INCREMENT=43570 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69b_control
CREATE TABLE IF NOT EXISTS `sat_69b_control` (
  `id_control` tinyint(1) NOT NULL,
  `fuente_url` varchar(500) NOT NULL,
  `leyenda_actualizacion` varchar(255) DEFAULT NULL,
  `fecha_sincronizacion` datetime NOT NULL,
  `total_registros` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_control`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69b_procesos
CREATE TABLE IF NOT EXISTS `sat_69b_procesos` (
  `id_proceso` varchar(64) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `etapa` varchar(80) NOT NULL DEFAULT 'Preparando',
  `porcentaje` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `registros_procesados` int(11) NOT NULL DEFAULT 0,
  `total_registros` int(11) NOT NULL DEFAULT 0,
  `cancelar` tinyint(1) NOT NULL DEFAULT 0,
  `mensaje` varchar(500) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_proceso`),
  KEY `idx_sat_69b_proc_usuario` (`id_usuario`),
  KEY `idx_sat_69b_proc_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69b_staging
CREATE TABLE IF NOT EXISTS `sat_69b_staging` (
  `id_staging` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_proceso` varchar(64) NOT NULL,
  `numero_sat` int(11) DEFAULT NULL,
  `rfc` varchar(20) NOT NULL,
  `nombre_contribuyente` varchar(255) NOT NULL,
  `situacion` varchar(50) NOT NULL,
  `oficio_presuncion_sat` text DEFAULT NULL,
  `fecha_sat_presuntos` varchar(100) DEFAULT NULL,
  `oficio_presuncion_dof` text DEFAULT NULL,
  `fecha_dof_presuntos` varchar(100) DEFAULT NULL,
  `oficio_desvirtuado_sat` text DEFAULT NULL,
  `fecha_sat_desvirtuados` varchar(100) DEFAULT NULL,
  `oficio_desvirtuado_dof` text DEFAULT NULL,
  `fecha_dof_desvirtuados` varchar(100) DEFAULT NULL,
  `oficio_definitivo_sat` text DEFAULT NULL,
  `fecha_sat_definitivos` varchar(100) DEFAULT NULL,
  `oficio_definitivo_dof` text DEFAULT NULL,
  `fecha_dof_definitivos` varchar(100) DEFAULT NULL,
  `oficio_sentencia_sat` text DEFAULT NULL,
  `fecha_sat_sentencia` varchar(100) DEFAULT NULL,
  `oficio_sentencia_dof` text DEFAULT NULL,
  `fecha_dof_sentencia` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id_staging`),
  KEY `idx_sat_69b_staging_proceso` (`id_proceso`),
  KEY `idx_sat_69b_staging_rfc` (`rfc`)
) ENGINE=InnoDB AUTO_INCREMENT=43570 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69_alertas_emisores
CREATE TABLE IF NOT EXISTS `sat_69_alertas_emisores` (
  `id_alerta` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_emisor` int(11) NOT NULL,
  `id_69` bigint(20) unsigned DEFAULT NULL,
  `rfc` varchar(20) NOT NULL,
  `nombre_emisor` varchar(255) NOT NULL,
  `nombre_sat` varchar(500) DEFAULT NULL,
  `tipo_publicacion` varchar(120) NOT NULL,
  `nivel_riesgo` varchar(20) NOT NULL DEFAULT 'INFORMATIVO',
  `fecha_publicacion` varchar(100) DEFAULT NULL,
  `activa` tinyint(4) NOT NULL DEFAULT 1,
  `estatus_seguimiento` varchar(20) NOT NULL DEFAULT 'NUEVA',
  `fecha_primera_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `id_usuario_visto` int(11) DEFAULT NULL,
  `fecha_visto` datetime DEFAULT NULL,
  `id_usuario_atendio` int(11) DEFAULT NULL,
  `fecha_atendido` datetime DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id_alerta`),
  UNIQUE KEY `uk_69_alerta_emisor_registro` (`id_emisor`,`id_69`),
  KEY `idx_69_alerta_empresa` (`id_empresa`,`activa`,`estatus_seguimiento`),
  KEY `idx_69_alerta_rfc` (`rfc`),
  KEY `idx_69_alerta_tipo` (`tipo_publicacion`),
  KEY `idx_69_alerta_riesgo` (`nivel_riesgo`),
  CONSTRAINT `fk_69_alerta_emisor` FOREIGN KEY (`id_emisor`) REFERENCES `cat_emisores` (`id_emisor`) ON DELETE CASCADE,
  CONSTRAINT `fk_69_alerta_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69_contribuyentes
CREATE TABLE IF NOT EXISTS `sat_69_contribuyentes` (
  `id_69` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rfc` varchar(20) NOT NULL,
  `nombre_contribuyente` varchar(500) NOT NULL,
  `tipo_publicacion` varchar(120) NOT NULL,
  `nivel_riesgo` varchar(20) NOT NULL DEFAULT 'INFORMATIVO',
  `fecha_publicacion` varchar(100) DEFAULT NULL,
  `detalle_resumen` text DEFAULT NULL,
  `datos_json` longtext DEFAULT NULL,
  `fuente_archivo` varchar(255) NOT NULL,
  `fecha_sincronizacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_69`),
  KEY `idx_69_rfc` (`rfc`),
  KEY `idx_69_tipo` (`tipo_publicacion`),
  KEY `idx_69_riesgo` (`nivel_riesgo`),
  KEY `idx_69_nombre` (`nombre_contribuyente`(191)),
  KEY `idx_69_tipo_riesgo` (`tipo_publicacion`,`nivel_riesgo`),
  KEY `idx_69_tipo_rfc` (`tipo_publicacion`,`rfc`),
  FULLTEXT KEY `ft_69_nombre` (`nombre_contribuyente`)
) ENGINE=InnoDB AUTO_INCREMENT=1424275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69_control
CREATE TABLE IF NOT EXISTS `sat_69_control` (
  `id_control` tinyint(4) NOT NULL,
  `fuente_url` varchar(500) NOT NULL,
  `leyenda_actualizacion` varchar(255) DEFAULT NULL,
  `fecha_sincronizacion` datetime NOT NULL,
  `total_registros` int(11) NOT NULL DEFAULT 0,
  `archivos_procesados` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_control`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69_procesos
CREATE TABLE IF NOT EXISTS `sat_69_procesos` (
  `id_proceso` varchar(64) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `etapa` varchar(120) NOT NULL DEFAULT 'Preparando',
  `porcentaje` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `registros_procesados` int(11) NOT NULL DEFAULT 0,
  `total_registros` int(11) NOT NULL DEFAULT 0,
  `cancelar` tinyint(4) NOT NULL DEFAULT 0,
  `mensaje` varchar(500) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_proceso`),
  KEY `idx_69proc_user` (`id_usuario`),
  KEY `idx_69proc_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_69_staging
CREATE TABLE IF NOT EXISTS `sat_69_staging` (
  `id_staging` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_proceso` varchar(64) NOT NULL,
  `rfc` varchar(20) NOT NULL,
  `nombre_contribuyente` varchar(500) NOT NULL,
  `tipo_publicacion` varchar(120) NOT NULL,
  `nivel_riesgo` varchar(20) NOT NULL,
  `fecha_publicacion` varchar(100) DEFAULT NULL,
  `detalle_resumen` text DEFAULT NULL,
  `datos_json` longtext DEFAULT NULL,
  `fuente_archivo` varchar(255) NOT NULL,
  PRIMARY KEY (`id_staging`),
  KEY `idx_69s_proc` (`id_proceso`),
  KEY `idx_69s_rfc` (`rfc`)
) ENGINE=InnoDB AUTO_INCREMENT=1424275 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_catalogos_bitacora
CREATE TABLE IF NOT EXISTS `sat_catalogos_bitacora` (
  `id_bitacora` bigint(20) NOT NULL AUTO_INCREMENT,
  `tipo_catalogo` varchar(50) NOT NULL,
  `archivo` varchar(255) DEFAULT NULL,
  `url_origen` text DEFAULT NULL,
  `revision_catalogo` varchar(20) DEFAULT NULL,
  `fecha_publicacion` date DEFAULT NULL,
  `registros_insertados` int(11) NOT NULL DEFAULT 0,
  `registros_actualizados` int(11) NOT NULL DEFAULT 0,
  `registros_leidos` int(11) NOT NULL DEFAULT 0,
  `estatus` varchar(20) NOT NULL,
  `mensaje` text DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id_bitacora`),
  KEY `idx_bitacora_catalogo_fecha` (`tipo_catalogo`,`fecha_inicio`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_codigos_postales
CREATE TABLE IF NOT EXISTS `sat_codigos_postales` (
  `codigo_postal` char(5) NOT NULL,
  `estado` varchar(5) DEFAULT NULL,
  `municipio` varchar(10) DEFAULT NULL,
  `localidad` varchar(10) DEFAULT NULL,
  `estimulo_franja_fronteriza` tinyint(4) NOT NULL DEFAULT 0,
  `fecha_inicio_vigencia` date DEFAULT NULL,
  `fecha_fin_vigencia` date DEFAULT NULL,
  `descripcion_huso_horario` varchar(100) DEFAULT NULL,
  `revision_catalogo` varchar(20) DEFAULT NULL,
  `fecha_publicacion` date DEFAULT NULL,
  `archivo_origen` varchar(255) DEFAULT NULL,
  `fecha_sincronizacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`codigo_postal`),
  KEY `idx_cp_estimulo` (`estimulo_franja_fronteriza`),
  KEY `idx_cp_estado` (`estado`),
  KEY `idx_cp_vigencia` (`fecha_fin_vigencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_metadata_cfdi
CREATE TABLE IF NOT EXISTS `sat_metadata_cfdi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `rfc_emisor` varchar(20) DEFAULT NULL,
  `nombre_emisor` varchar(500) DEFAULT NULL,
  `rfc_receptor` varchar(20) DEFAULT NULL,
  `nombre_receptor` varchar(500) DEFAULT NULL,
  `pac_certifico` varchar(20) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `fecha_certificacion_sat` datetime DEFAULT NULL,
  `monto` decimal(18,6) DEFAULT NULL,
  `efecto_comprobante` varchar(5) DEFAULT NULL,
  `estatus_sat` varchar(20) NOT NULL DEFAULT 'Vigente',
  `fecha_cancelacion` datetime DEFAULT NULL,
  `id_paquete_ultima_revision` bigint(20) unsigned DEFAULT NULL,
  `fecha_primera_deteccion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_ultima_revision` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_metadata_empresa_uuid` (`id_empresa`,`uuid`),
  KEY `idx_metadata_empresa_estatus` (`id_empresa`,`estatus_sat`),
  KEY `idx_metadata_empresa_fecha` (`id_empresa`,`fecha_emision`),
  KEY `idx_metadata_faltantes` (`id_empresa`,`estatus_sat`,`tipo`,`fecha_emision`,`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=217636 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.sat_productos_servicios
CREATE TABLE IF NOT EXISTS `sat_productos_servicios` (
  `clave_prod_serv` varchar(8) NOT NULL,
  `descripcion` varchar(1000) NOT NULL,
  `incluir_iva_trasladado` varchar(50) DEFAULT NULL,
  `incluir_ieps_trasladado` varchar(50) DEFAULT NULL,
  `complemento_debe_incluir` varchar(500) DEFAULT NULL,
  `fecha_inicio_vigencia` date DEFAULT NULL,
  `fecha_fin_vigencia` date DEFAULT NULL,
  `estimulo_franja_fronteriza` varchar(100) DEFAULT NULL,
  `revision_catalogo` varchar(50) DEFAULT NULL,
  `fecha_publicacion` date DEFAULT NULL,
  `archivo_origen` varchar(255) DEFAULT NULL,
  `fecha_sincronizacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`clave_prod_serv`),
  KEY `idx_sat_prodserv_descripcion` (`descripcion`(191)),
  KEY `idx_sat_prodserv_vigencia` (`fecha_inicio_vigencia`,`fecha_fin_vigencia`),
  KEY `idx_sat_prodserv_iva` (`incluir_iva_trasladado`),
  KEY `idx_sat_prodserv_ieps` (`incluir_ieps_trasladado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id_usuario` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) NOT NULL,
  `nombre_real` varchar(100) DEFAULT NULL,
  `correo` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `id_zona` int(11) DEFAULT NULL,
  `es_admin_zona` tinyint(1) DEFAULT 0,
  `es_superadmin` tinyint(1) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_cambio_password` datetime DEFAULT NULL,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `usuario` (`usuario`),
  UNIQUE KEY `uk_usuarios_correo` (`correo`),
  KEY `usuarios_ibfk_1` (`id_zona`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`id_zona`) REFERENCES `zonas` (`id_zona`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.usuario_empresas
CREATE TABLE IF NOT EXISTS `usuario_empresas` (
  `id_usuario` int(11) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  PRIMARY KEY (`id_usuario`,`id_empresa`),
  KEY `id_empresa` (`id_empresa`),
  CONSTRAINT `usuario_empresas_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE,
  CONSTRAINT `usuario_empresas_ibfk_2` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.usuario_empresa_documentos
CREATE TABLE IF NOT EXISTS `usuario_empresa_documentos` (
  `id_usuario` int(11) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `ver_ingreso` tinyint(1) NOT NULL DEFAULT 1,
  `ver_egreso` tinyint(1) NOT NULL DEFAULT 1,
  `ver_pago` tinyint(1) NOT NULL DEFAULT 1,
  `ver_nomina` tinyint(1) NOT NULL DEFAULT 1,
  `ver_traslado` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ver_xml_pdf` tinyint(1) NOT NULL DEFAULT 1,
  `descargar_xml` tinyint(1) NOT NULL DEFAULT 1,
  `descargar_pdf` tinyint(1) NOT NULL DEFAULT 1,
  `exportar` tinyint(1) NOT NULL DEFAULT 1,
  `procesar_pagos` tinyint(1) NOT NULL DEFAULT 1,
  `generar_diot` tinyint(1) NOT NULL DEFAULT 1,
  `ver_solicitudes_sat` tinyint(1) NOT NULL DEFAULT 1,
  `contpaq_cheques` tinyint(1) NOT NULL DEFAULT 0,
  `oracle_pagos` tinyint(1) NOT NULL DEFAULT 0,
  `nomina_archivo` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_cruce_metadata` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_codigos_postales` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_sat_69` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_alertas_69` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_sat_69b` tinyint(1) NOT NULL DEFAULT 0,
  `consulta_alertas_69b` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_usuario`,`id_empresa`),
  KEY `fk_ued_empresa` (`id_empresa`),
  CONSTRAINT `fk_ued_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas` (`id_empresa`) ON DELETE CASCADE,
  CONSTRAINT `fk_ued_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.verificaciones_sat
CREATE TABLE IF NOT EXISTS `verificaciones_sat` (
  `id_verificacion` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `origen` enum('MANUAL','PROGRAMADO') NOT NULL DEFAULT 'MANUAL',
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  `filtros_json` longtext DEFAULT NULL,
  `total_cfdi` int(10) unsigned NOT NULL DEFAULT 0,
  `total_consultados` int(10) unsigned NOT NULL DEFAULT 0,
  `total_vigentes` int(10) unsigned NOT NULL DEFAULT 0,
  `total_cancelados` int(10) unsigned NOT NULL DEFAULT 0,
  `total_cambios` int(10) unsigned NOT NULL DEFAULT 0,
  `total_errores` int(10) unsigned NOT NULL DEFAULT 0,
  `ultimo_indice_procesado` int(10) unsigned NOT NULL DEFAULT 0,
  `estatus_proceso` enum('PENDIENTE','PROCESANDO','COMPLETADA','CANCELADA','ERROR') NOT NULL DEFAULT 'PROCESANDO',
  `duracion_segundos` int(10) unsigned DEFAULT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id_verificacion`),
  KEY `idx_ver_sat_empresa_fecha` (`id_empresa`,`fecha_inicio`),
  KEY `idx_ver_sat_estado` (`estatus_proceso`,`fecha_inicio`),
  KEY `idx_ver_sat_origen` (`origen`,`fecha_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.verificaciones_sat_detalle
CREATE TABLE IF NOT EXISTS `verificaciones_sat_detalle` (
  `id_detalle` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_verificacion` bigint(20) unsigned NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `uuid` char(36) NOT NULL,
  `tipo_cfdi` varchar(5) DEFAULT NULL,
  `serie` varchar(100) DEFAULT NULL,
  `folio` varchar(100) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `rfc_emisor` varchar(20) DEFAULT NULL,
  `nombre_emisor` varchar(500) DEFAULT NULL,
  `rfc_receptor` varchar(20) DEFAULT NULL,
  `nombre_receptor` varchar(500) DEFAULT NULL,
  `total` decimal(18,6) DEFAULT NULL,
  `estatus_anterior` varchar(50) DEFAULT NULL,
  `estatus_sat` varchar(50) DEFAULT NULL,
  `cambio_estatus` tinyint(1) NOT NULL DEFAULT 0,
  `codigo_estatus` varchar(500) DEFAULT NULL,
  `es_cancelable` varchar(100) DEFAULT NULL,
  `estatus_cancelacion` varchar(150) DEFAULT NULL,
  `validacion_efos` varchar(100) DEFAULT NULL,
  `fecha_consulta` datetime NOT NULL DEFAULT current_timestamp(),
  `resultado_consulta` enum('OK','NO_ENCONTRADO','ERROR') NOT NULL DEFAULT 'OK',
  `mensaje_error` varchar(1000) DEFAULT NULL,
  `intentos` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_detalle`),
  UNIQUE KEY `uq_ver_sat_detalle_corrida_uuid` (`id_verificacion`,`uuid`),
  KEY `idx_ver_sat_det_empresa_uuid` (`id_empresa`,`uuid`),
  KEY `idx_ver_sat_det_fecha` (`id_empresa`,`fecha_consulta`),
  KEY `idx_ver_sat_det_cambio` (`id_empresa`,`cambio_estatus`,`fecha_consulta`),
  KEY `idx_ver_sat_det_estado` (`id_empresa`,`estatus_sat`,`fecha_consulta`),
  CONSTRAINT `fk_ver_sat_det_corrida` FOREIGN KEY (`id_verificacion`) REFERENCES `verificaciones_sat` (`id_verificacion`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

-- Volcando estructura para tabla visor_xml_db.zonas
CREATE TABLE IF NOT EXISTS `zonas` (
  `id_zona` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_zona` varchar(100) NOT NULL,
  PRIMARY KEY (`id_zona`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La exportación de datos fue deseleccionada.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
