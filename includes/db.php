<?php
/*
 * Conexión única a MariaDB con PDO.
 * Los datos salen de ~/dba_config/db.cnf (cargado en bootstrap.php);
 * aquí no hay ningún usuario, base ni contraseña escritos.
 */
require_once __DIR__ . '/bootstrap.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $CONFIG;
    $d = $CONFIG['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $d['user'], $d['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $ex) {
        // El mensaje del motor se registra, pero no se muestra: puede incluir datos de conexión.
        error_log('Consola DBA - error de conexión: ' . $ex->getMessage());
        throw new RuntimeException('No se pudo conectar a la base de datos. Revise ~/dba_config/db.cnf.');
    }
    return $pdo;
}
