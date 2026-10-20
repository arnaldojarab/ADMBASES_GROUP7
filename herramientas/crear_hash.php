<?php
/*
 * Genera el hash de la contraseña de la consola.
 * Uso, desde la terminal:  php herramientas/crear_hash.php
 * Pide la clave sin mostrarla, así no queda en el historial de bash.
 * Desde el navegador responde 403 (y además la carpeta está bloqueada por .htaccess).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecute este archivo desde la terminal.');
}

$clave = $argv[1] ?? null;

if ($clave === null) {
    fwrite(STDOUT, 'Nueva contraseña de la consola: ');
    system('stty -echo');
    $clave = trim((string)fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);
}

if ($clave === '') {
    fwrite(STDERR, "La contraseña no puede quedar vacía.\n");
    exit(1);
}

echo password_hash($clave, PASSWORD_DEFAULT) . PHP_EOL;
