-- =============================================================
-- Consola DBA - Grupo 7 DASK
-- Base de prueba: AGENCIA DE CARROS (MariaDB)
--
-- 4 tablas relacionadas:
--   marcas    1 --- N  vehiculos   (cada carro es de una marca)
--   clientes  1 --- N  ventas      (un cliente puede comprar varios)
--   vehiculos 1 --- 1  ventas      (cada carro se vende una vez)
--
-- Se puede ejecutar varias veces: borra y vuelve a crear
-- SOLO estas 4 tablas. No toca otras tablas de la base.
-- =============================================================

SET NAMES utf8mb4;

-- 1. Borrar las tablas si existen (primero la que depende de las demás)
DROP TABLE IF EXISTS ventas;
DROP TABLE IF EXISTS vehiculos;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS marcas;

-- 2. Crear las tablas
CREATE TABLE marcas (
    id_marca  INT AUTO_INCREMENT PRIMARY KEY,
    nombre    VARCHAR(40) NOT NULL,
    pais      VARCHAR(40) NOT NULL
);

CREATE TABLE clientes (
    id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(60) NOT NULL,
    cedula     VARCHAR(20) NOT NULL UNIQUE,
    telefono   VARCHAR(15) NOT NULL,
    provincia  VARCHAR(20) NOT NULL
);

CREATE TABLE vehiculos (
    id_vehiculo INT AUTO_INCREMENT PRIMARY KEY,
    id_marca    INT NOT NULL,
    modelo      VARCHAR(40) NOT NULL,
    anio        INT NOT NULL,
    color       VARCHAR(20) NOT NULL,
    precio      DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (id_marca) REFERENCES marcas(id_marca)
);

CREATE TABLE ventas (
    id_venta     INT AUTO_INCREMENT PRIMARY KEY,
    id_vehiculo  INT NOT NULL UNIQUE,
    id_cliente   INT NOT NULL,
    fecha_venta  DATE NOT NULL,
    precio_final DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (id_vehiculo) REFERENCES vehiculos(id_vehiculo),
    FOREIGN KEY (id_cliente)  REFERENCES clientes(id_cliente)
);

-- 3. Insertar datos de prueba

INSERT INTO marcas (nombre, pais) VALUES
('Toyota', 'Japón'),
('Hyundai', 'Corea del Sur'),
('Nissan', 'Japón'),
('Kia', 'Corea del Sur'),
('Suzuki', 'Japón'),
('Ford', 'Estados Unidos'),
('Mazda', 'Japón'),
('BYD', 'China');

INSERT INTO clientes (nombre, cedula, telefono, provincia) VALUES
('Ana Vargas',        '1-1234-0001', '88112233', 'San José'),
('Bryan Mora',        '2-0456-0002', '87224455', 'Alajuela'),
('Camila Rojas',      '3-0789-0003', '86335566', 'Cartago'),
('Daniel Solís',      '4-0321-0004', '85446677', 'Heredia'),
('Elena Araya',       '5-0654-0005', '84557788', 'Guanacaste'),
('Felipe Castro',     '6-0987-0006', '83668899', 'Puntarenas'),
('Gabriela Chaves',   '7-0147-0007', '72779900', 'Limón'),
('Hugo Quesada',      '1-0258-0008', '71880011', 'San José'),
('Isabel Méndez',     '2-0369-0009', '70991122', 'Alajuela'),
('Jorge Alfaro',      '3-0471-0010', '88001133', 'Cartago'),
('Karla Salazar',     '4-0582-0011', '87112244', 'Heredia'),
('Leonardo Jiménez',  '1-0693-0012', '86223355', 'San José'),
('Melissa Ramírez',   '2-0714-0013', '85334466', 'Alajuela'),
('Nicolás Brenes',    '3-0825-0014', '84445577', 'Cartago'),
('Olga Umaña',        '4-0936-0015', '83556688', 'Heredia'),
('Pedro Calderón',    '5-0147-0016', '62667799', 'Guanacaste'),
('Raquel Arias',      '6-0258-0017', '61778800', 'Puntarenas'),
('Sergio Segura',     '7-0369-0018', '60889911', 'Limón'),
('Tatiana Hernández', '1-0471-0019', '88990022', 'San José'),
('Víctor Rodríguez',  '2-0582-0020', '87001144', 'Alajuela');

INSERT INTO vehiculos (id_marca, modelo, anio, color, precio) VALUES
(1, 'Corolla',  2022, 'Blanco',   14500000),
(1, 'Hilux',    2023, 'Gris',     24900000),
(1, 'RAV4',     2021, 'Negro',    21500000),
(2, 'Tucson',   2022, 'Azul',     18900000),
(2, 'Accent',   2020, 'Plateado',  9800000),
(3, 'Frontier', 2023, 'Blanco',   23500000),
(3, 'Kicks',    2021, 'Rojo',     12300000),
(4, 'Sportage', 2022, 'Gris',     19700000),
(4, 'Rio',      2019, 'Negro',     8500000),
(5, 'Swift',    2021, 'Rojo',      9200000),
(5, 'Vitara',   2023, 'Blanco',   15800000),
(5, 'Jimny',    2022, 'Verde',    16400000),
(6, 'Ranger',   2023, 'Azul',     26800000),
(6, 'Escape',   2020, 'Plateado', 15200000),
(7, 'CX-5',     2022, 'Rojo',     20400000),
(7, 'Mazda 3',  2021, 'Gris',     13900000),
(8, 'Dolphin',  2024, 'Blanco',   17500000),
(8, 'Seal',     2024, 'Negro',    27900000),
(8, 'Atto 3',   2023, 'Azul',     21900000),
(2, 'Creta',    2024, 'Beige',    16900000);

INSERT INTO ventas (id_vehiculo, id_cliente, fecha_venta, precio_final) VALUES
(1,  3,  '2026-01-15', 14000000),
(2,  7,  '2026-01-28', 24500000),
(4,  1,  '2026-02-03', 18500000),
(5,  12, '2026-02-17',  9500000),
(6,  5,  '2026-03-02', 23000000),
(8,  9,  '2026-03-21', 19200000),
(10, 15, '2026-04-08',  9000000),
(11, 2,  '2026-04-25', 15500000),
(13, 18, '2026-05-10', 26000000),
(15, 4,  '2026-05-29', 20000000),
(16, 11, '2026-06-14', 13500000),
(17, 20, '2026-07-03', 17200000),
(18, 3,  '2026-07-22', 27500000),
(19, 14, '2026-08-11', 21500000),
(20, 8,  '2026-09-05', 16600000);

-- 4. Actualizar las estadísticas que usará el Monitor
ANALYZE TABLE marcas, clientes, vehiculos, ventas;

-- 5. Consultar los datos creados
SELECT 'marcas' AS tabla, COUNT(*) AS registros FROM marcas
UNION ALL SELECT 'clientes',  COUNT(*) FROM clientes
UNION ALL SELECT 'vehiculos', COUNT(*) FROM vehiculos
UNION ALL SELECT 'ventas',    COUNT(*) FROM ventas;

-- Ventas con su cliente, marca y modelo (comprueba las relaciones)
SELECT v.id_venta, v.fecha_venta, c.nombre AS cliente,
       m.nombre AS marca, ve.modelo, v.precio_final
FROM ventas v
JOIN clientes  c  ON c.id_cliente   = v.id_cliente
JOIN vehiculos ve ON ve.id_vehiculo = v.id_vehiculo
JOIN marcas    m  ON m.id_marca     = ve.id_marca
ORDER BY v.fecha_venta;
