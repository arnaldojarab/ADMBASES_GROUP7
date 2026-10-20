<?php
/* Cierra la sesión: vacía los datos, borra la cookie y destruye la sesión. */
require_once __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

header('Location: login.php');
exit;
