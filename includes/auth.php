<?php
/*
 * Se incluye al inicio de cada página protegida.
 * Si no hay una sesión iniciada, manda al login.
 */
require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}
