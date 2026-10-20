<?php
/*
 * Punto de arranque de todas las páginas:
 *  1. Carga los datos fijos de la aplicación (includes/app.php).
 *  2. Carga la configuración privada de ESTA cuenta (~/dba_config/config.php).
 *  3. Lee las credenciales de MariaDB desde ~/dba_config/db.cnf.
 *  4. Inicia la sesión PHP.
 *
 * La ruta a ~/dba_config se calcula desde la ubicación de este archivo:
 *   /home/<usuario>/public_html/dba/includes  ->  subir 3 niveles  ->  /home/<usuario>
 * Por eso funciona en cualquier cuenta sin escribir el usuario a mano.
 */
declare(strict_types=1);

$APP = require __DIR__ . '/app.php';

$homeCuenta = dirname(__DIR__, 3);
$rutaConfig = $homeCuenta . '/dba_config/config.php';

if (!is_readable($rutaConfig)) {
    http_response_code(500);
    exit('Falta la configuración privada de la consola (~/dba_config/config.php). '
       . 'Ejecute la instalación: bash herramientas/instalar.sh');
}
$CONFIG = require $rutaConfig;

// db.cnf tiene el formato de un .my.cnf: lo leen PHP (aquí) y mariadb-dump (backup.sh).
$cnf = is_readable($CONFIG['db_cnf'])
    ? parse_ini_file($CONFIG['db_cnf'], true, INI_SCANNER_RAW)
    : false;

if ($cnf === false || empty($cnf['client']['user']) || !isset($cnf['client']['password'])
    || empty($cnf['dba']['database'])) {
    http_response_code(500);
    exit('El archivo ~/dba_config/db.cnf no existe o está incompleto (user, password, database).');
}

$CONFIG['db'] = [
    'host'     => $cnf['client']['host'] ?? 'localhost',
    'port'     => (int)($cnf['client']['port'] ?? 3306),
    'user'     => $cnf['client']['user'],
    'password' => $cnf['client']['password'],
    'name'     => $cnf['dba']['database'],
];

// Sesión: cookie solo por HTTP (no la lee JavaScript) y segura si el sitio va por HTTPS.
session_name('consola_dba');
session_set_cookie_params([
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

/** Escapa texto antes de mostrarlo en HTML. */
function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
