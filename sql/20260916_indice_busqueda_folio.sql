-- Visor XML Pro - búsqueda rápida por Folio
-- Fecha: 2026-09-16
--
-- Ya existe idx_visor_empresa_fecha_folio (id_empresa, fecha_emision, folio),
-- que sirve para recorrer periodos. Para buscar iniciando por FOLIO necesitamos
-- el orden inverso: empresa + folio + fecha.
--
-- La clasificación CFDI/tipo sigue aplicándose en el WHERE del visor. Se deja
-- este índice compacto para no inflar innecesariamente la tabla facturas.

SET @idx_folio_existe := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'facturas'
      AND INDEX_NAME = 'idx_visor_empresa_folio_fecha'
);

SET @sql_idx_folio := IF(
    @idx_folio_existe = 0,
    'ALTER TABLE facturas ADD INDEX idx_visor_empresa_folio_fecha (id_empresa, folio, fecha_emision)',
    'SELECT ''idx_visor_empresa_folio_fecha ya existe'' AS mensaje'
);

PREPARE stmt_idx_folio FROM @sql_idx_folio;
EXECUTE stmt_idx_folio;
DEALLOCATE PREPARE stmt_idx_folio;
