<?php
/*
 * Datos fijos de la aplicación: son iguales en todas las cuentas del grupo.
 * Lo que cambia por cuenta (credenciales, rutas, hash) vive en ~/dba_config/.
 */
return [
    'nombre'      => 'Consola DBA',
    'version'     => '1.0',
    'grupo'       => 'Grupo 7 - DASK',
    'motor'       => 'MariaDB',
    'integrantes' => [
        'Arnaldo Jara Bartels',
        'José Daniel Garita Guzmán',
        'Sebastián Rodríguez Hernández',
        'Keylor Segura Miranda',
    ],
    // Menú principal: crece en las próximas semanas cambiando solo esta lista.
    'modulos' => [
        ['num' => '01', 'titulo' => 'Monitor de Base de Datos', 'archivo' => 'monitor.php',
         'desc' => 'Tamaño de la base, tablas, registros y tamaño por tabla.'],
        ['num' => '02', 'titulo' => 'Respaldos', 'archivo' => 'respaldos.php',
         'desc' => 'Generar respaldos comprimidos y consultar su historial.'],
        ['num' => '03', 'titulo' => 'Próximamente', 'archivo' => null, 'desc' => 'Módulo disponible próximamente.'],
        ['num' => '04', 'titulo' => 'Próximamente', 'archivo' => null, 'desc' => 'Módulo disponible próximamente.'],
        ['num' => '05', 'titulo' => 'Próximamente', 'archivo' => null, 'desc' => 'Módulo disponible próximamente.'],
        ['num' => '06', 'titulo' => 'Próximamente', 'archivo' => null, 'desc' => 'Módulo disponible próximamente.'],
    ],
];
