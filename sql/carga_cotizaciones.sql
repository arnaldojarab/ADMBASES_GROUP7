-- =============================================================
-- Consola DBA - Grupo 7 DASK - Módulo 03 (Diagnóstico)
-- Carga de datos de prueba: tabla COTIZACIONES (1 500 filas)
--
-- Requiere las tablas de sql/crear_bd.sql (clientes y vehiculos).
-- Es reproducible: borra y vuelve a crear SOLO la tabla cotizaciones.
-- No toca marcas, clientes, vehiculos ni ventas.
-- =============================================================

SET NAMES utf8mb4;

-- 1. Borrar y crear la tabla (sin índice en fecha: es el caso del ensayo)
DROP TABLE IF EXISTS cotizaciones;

CREATE TABLE cotizaciones (
    id_cotizacion INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente    INT NOT NULL,
    id_vehiculo   INT NOT NULL,
    fecha         DATE NOT NULL,
    monto         DECIMAL(12,2) NOT NULL,
    estado        VARCHAR(12) NOT NULL,
    FOREIGN KEY (id_cliente)  REFERENCES clientes(id_cliente),
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculos(id_vehiculo)
);

-- 2. Generar 1 500 filas
-- MariaDB limita las consultas recursivas a 1 000 vueltas (max_recursive_iterations),
-- así que no se genera una lista de 1 a 1500. Se generan dos listas cortas:
--   a = 0..49  (50 números)   y   b = 0..29  (30 números)
-- y se combinan todas con todas (CROSS JOIN): 50 x 30 = 1 500 filas.
-- Con n = a*30 + b + 1 cada fila recibe un número distinto del 1 al 1500.
-- El % (residuo) reparte clientes, vehículos, fechas y montos de forma
-- variada, y siempre igual en cada ejecución.
INSERT INTO cotizaciones (id_cliente, id_vehiculo, fecha, monto, estado)
WITH RECURSIVE
    a(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM a WHERE i < 49),
    b(j) AS (SELECT 0 UNION ALL SELECT j + 1 FROM b WHERE j < 29),
    numeros AS (SELECT a.i * 30 + b.j + 1 AS n FROM a CROSS JOIN b)
SELECT
    1 + (n % 20)                                           AS id_cliente,   -- clientes 1 a 20
    1 + ((n * 7) % 20)                                     AS id_vehiculo,  -- vehículos 1 a 20
    DATE '2025-11-01' + INTERVAL ((n * 37) % 365) DAY      AS fecha,        -- un año de fechas
    8000000 + ((n * 7919) % 200) * 100000                  AS monto,        -- 8 a 28 millones
    ELT(1 + (n % 3), 'pendiente', 'aceptada', 'rechazada') AS estado
FROM numeros;

-- 3. Actualizar las estadísticas (las usan EXPLAIN y el Monitor)
ANALYZE TABLE cotizaciones;

-- 4. Comprobar la carga
SELECT COUNT(*) AS filas, MIN(fecha) AS desde, MAX(fecha) AS hasta FROM cotizaciones;

SELECT estado, COUNT(*) AS cantidad FROM cotizaciones GROUP BY estado;
