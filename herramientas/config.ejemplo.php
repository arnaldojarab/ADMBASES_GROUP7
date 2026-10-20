<?php
/*
 * PLANTILLA de ~/dba_config/config.php
 * Configuración privada de UNA cuenta. Va fuera de public_html, con permisos 600.
 * Las rutas se calculan desde la ubicación de este archivo, así que no hay que
 * escribir el usuario de Linux en ningún lado.
 */
$home = dirname(__DIR__);   // /home/<usuario>

return [
    // Acceso a la consola (NO son las credenciales de MariaDB)
    'consola_usuario' => 'admin',
    'consola_nombre'  => 'Administrador',
    'consola_hash'    => 'PEGAR_AQUI_EL_HASH',   // php herramientas/crear_hash.php

    // Credenciales de MariaDB: archivo aparte, formato .my.cnf
    'db_cnf'          => __DIR__ . '/db.cnf',

    // Rutas privadas de la cuenta (fuera de public_html)
    'scripts_dir'     => $home . '/dba_scripts',
    'respaldos_dir'   => $home . '/respaldos',
    'log_respaldos'   => $home . '/respaldos/respaldos.log',
];
