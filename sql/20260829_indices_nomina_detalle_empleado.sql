-- Visor XML Pro - optimización del detalle de conciliación por empleado
-- Fecha: 2026-08-29
-- Ejecutar UNA SOLA VEZ en la base visor_xml_db.
--
-- Objetivo:
--   1) localizar rápido al receptor por empresa + RFC;
--   2) localizar sus CFDI de nómina por empresa + receptor + tipo + rango de FechaInicialPago;
--   3) localizar rápido el renglón del empleado dentro de una conciliación guardada.

USE visor_xml_db;

-- RFC receptor. El id_receptor al final deja el índice útil también para el JOIN a facturas.
CREATE INDEX IF NOT EXISTS idx_receptores_empresa_rfc_id
    ON cat_receptores (id_empresa, rfc, id_receptor);

-- Índice hecho a la medida del detalle de conciliación por empleado:
-- empresa = igualdad
-- receptor = igualdad
-- comprobante N = igualdad
-- fecha inicial de pago = rango
CREATE INDEX IF NOT EXISTS idx_facturas_nomina_receptor_periodo
    ON facturas (
        id_empresa,
        id_receptor,
        id_tipo_comprobante,
        nomina_fecha_inicial_pago
    );

-- El modal primero recupera los totales guardados del empleado dentro de la conciliación.
CREATE INDEX IF NOT EXISTS idx_nomina_conc_det_conc_emp_rfc
    ON nomina_conciliacion_detalles (id_conciliacion, id_empresa, rfc);

-- Verificación opcional después de ejecutar:
SHOW INDEX FROM cat_receptores
 WHERE Key_name = 'idx_receptores_empresa_rfc_id';

SHOW INDEX FROM facturas
 WHERE Key_name = 'idx_facturas_nomina_receptor_periodo';

SHOW INDEX FROM nomina_conciliacion_detalles
 WHERE Key_name = 'idx_nomina_conc_det_conc_emp_rfc';
