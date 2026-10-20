<?php
/*
 * Encabezado y menú de todas las páginas protegidas.
 * Antes de incluirlo, la página define $titulo y $activo (nombre del archivo).
 */
$titulo = $titulo ?? $APP['nombre'];
$activo = $activo ?? basename($_SERVER['SCRIPT_NAME']);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> | <?= e($APP['nombre']) ?></title>
    <link rel="stylesheet" href="assets/estilos.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><?= e($APP['nombre']) ?> <span><?= e($APP['grupo']) ?></span></a>
    <div class="account">
        <span>Usuario: <strong><?= e($_SESSION['usuario'] ?? '') ?></strong></span>
        <a class="logout" href="logout.php">Cerrar sesión</a>
    </div>
</header>

<nav class="menu" aria-label="Menú principal">
    <a href="index.php" class="<?= $activo === 'index.php' ? 'activo' : '' ?>">Inicio</a>
    <?php foreach ($APP['modulos'] as $m): ?>
        <?php if ($m['archivo']): ?>
            <a href="<?= e($m['archivo']) ?>" class="<?= $activo === $m['archivo'] ? 'activo' : '' ?>">
                Proyecto <?= e($m['num']) ?> <small><?= e($m['titulo']) ?></small>
            </a>
        <?php else: ?>
            <a href="proximamente.php?p=<?= e($m['num']) ?>"
               class="pendiente <?= ($activo === 'proximamente.php' && ($_GET['p'] ?? '') === $m['num']) ? 'activo' : '' ?>">
                Proyecto <?= e($m['num']) ?> <small>Próximamente</small>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<main>
