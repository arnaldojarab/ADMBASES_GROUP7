<?php
/*
 * Utilidad local para generar un hash válido.
 * Ejecútala temporalmente por terminal: php crear_hash.php "TuClaveSegura"
 * No la publiques en un servidor ni la uses para mostrar contraseñas.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecuta este archivo desde la terminal.');
}

$clave = $argv[1] ?? null;

if ($clave === null || $clave === '') {
    fwrite(STDERR, "Uso: php crear_hash.php \"TuClaveSegura\"\n");
    exit(1);
}

echo password_hash($clave, PASSWORD_DEFAULT) . PHP_EOL;
