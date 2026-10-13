<?php
session_start();

if (isset($_SESSION['usuario'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';

    $usuarios = require __DIR__ . '/usuarios.php';

    if (isset($usuarios[$usuario]) &&
        password_verify($clave, $usuarios[$usuario]['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
        $_SESSION['nombre'] = $usuarios[$usuario]['nombre'] ?? $usuario;

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
    <title>Iniciar sesión | Sistema v1.0</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               font-family: Arial, sans-serif; background: #f1f5f9; color: #172033; }
        .card { width: min(92%, 390px); padding: 28px; background: white;
                border-radius: 14px; box-shadow: 0 12px 35px #0f172a18; }
        h1 { margin-top: 0; font-size: 1.6rem; }
        label { display: block; margin: 16px 0 6px; font-weight: 600; }
        input { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1;
                border-radius: 8px; font-size: 1rem; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: 0;
                 border-radius: 8px; background: #052e24; color: white;
                 font-size: 1rem; font-weight: 700; cursor: pointer; }
        .error { padding: 10px; border-radius: 8px; background: #fee2e2; color: #991b1b; }
        .muted { color: #64748b; font-size: .9rem; }
    </style>
</head>
<body>
<main class="card">
    <h1>Iniciar sesión</h1>
    <p class="muted">Sistema de gestión · Versión 1.0</p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label for="usuario">Usuario</label>
        <input id="usuario" name="usuario" type="text" autocomplete="username" required>

        <label for="clave">Contraseña</label>
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>

        <button type="submit">Entrar</button>
    </form>
</main>
</body>
</html>
