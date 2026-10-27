-- Módulo 03 - Paso 7: índice para la consulta Q01 (filtra y ordena por fecha)
CREATE INDEX IF NOT EXISTS idx_cotizaciones_fecha ON cotizaciones(fecha);

-- Actualizar las estadísticas que usa el optimizador
ANALYZE TABLE cotizaciones;

-- Comprobar los índices de la tabla
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones'
ORDER BY INDEX_NAME;
