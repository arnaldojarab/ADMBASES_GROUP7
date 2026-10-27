-- Módulo 03 - Ensayo de Q02: índice para filtrar marcas por nombre
CREATE INDEX IF NOT EXISTS idx_marcas_nombre ON marcas(nombre);

-- Actualizar las estadísticas que usa el optimizador
ANALYZE TABLE marcas;

-- Comprobar los índices de la tabla
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marcas'
ORDER BY INDEX_NAME;
