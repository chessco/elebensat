-- Visor XML Pro - índice corregido para PUE SIN PAGO EMPRESA
-- Fecha: 2026-08-26
-- Ejecutar una sola vez en visor_xml_db.

USE visor_xml_db;

-- Regla del botón:
--   id_empresa = empresa activa
--   tipo_movimiento_empresa = 'E'
--   metodo_pago = 'PUE'
--   fecha_pago_origen IS NULL
--   fecha_emision entre Desde/Hasta
--
-- El RFC propio se excluye por id_emisor/rfc después de reducir el conjunto principal.
-- Este índice queda exactamente en el orden igualdad + igualdad + igualdad + NULL + rango.
CREATE INDEX IF NOT EXISTS idx_facturas_pue_sin_pago_empresa_v2
    ON facturas (
        id_empresa,
        tipo_movimiento_empresa,
        metodo_pago,
        fecha_pago_origen,
        fecha_emision
    );

-- El índice anterior, si ya se creó, puede quedarse; no afecta el funcionamiento.
-- Opcionalmente, cuando todo esté probado, se puede quitar para no duplicar mantenimiento:
-- DROP INDEX idx_facturas_pue_sin_pago_empresa ON facturas;

-- Verificación opcional:
-- SHOW INDEX FROM facturas WHERE Key_name IN (
--   'idx_facturas_pue_sin_pago_empresa',
--   'idx_facturas_pue_sin_pago_empresa_v2'
-- );
