<?php
/*
 * Inicio de sesión de la Consola DBA.
 * El usuario y el HASH de la contraseña están en ~/dba_config/config.php.
 * Usuarios: el administrador de la cuenta y, si config.php lo define, un acceso aparte para el profesor.
 * password_verify() compara la clave escrita con el hash; la clave nunca se guarda.
 * Ojo: esto es solo para entrar a la consola, no son las credenciales de MariaDB.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['clave'] ?? '';

    // Usuarios permitidos: el administrador y, si está configurado, el acceso del profesor
    $usuarios = [
        $CONFIG['consola_usuario'] => [$CONFIG['consola_hash'], $CONFIG['consola_nombre'] ?? 'Administrador'],
    ];
    if (!empty($CONFIG['profesor_usuario']) && !empty($CONFIG['profesor_hash'])) {
        $usuarios[$CONFIG['profesor_usuario']] = [$CONFIG['profesor_hash'], 'Profesor'];
    }

    $datos = $usuarios[$usuario] ?? null;

    if ($datos !== null && password_verify($clave, $datos[0])) {
        session_regenerate_id(true);           // nuevo id de sesión al entrar
        $_SESSION['usuario'] = $usuario;
        $_SESSION['nombre']  = $datos[1];
        header('Location: index.php');
        exit;
    }
    $error = 'Usuario o contraseña incorrectos.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | <?= e($APP['nombre']) ?></title>
    <link rel="stylesheet" href="assets/estilos.css">
</head>
<body class="login-page">
<main class="login-card">
    <h1><?= e($APP['nombre']) ?></h1>
    <p class="muted"><?= e($APP['grupo']) ?> · <?= e($APP['motor']) ?> · versión <?= e($APP['version']) ?></p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label for="usuario">Usuario</label>
        <input id="usuario" name="usuario" type="text" autocomplete="username" required>

        <label for="clave">Contraseña</label>
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>

        <button type="submit">Entrar</button>
    </form>

    <ul class="integrantes">
        <?php foreach ($APP['integrantes'] as $persona): ?>
            <li><?= e($persona) ?></li>
        <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
