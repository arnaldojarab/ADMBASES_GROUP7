<?php
/* Inicio: menú principal de la Consola DBA. */
require_once __DIR__ . '/includes/auth.php';

$titulo = 'Inicio';
$activo = 'index.php';
require __DIR__ . '/includes/header.php';
?>
<h1>Menú principal</h1>
<p class="intro">
    Bienvenido/a, <?= e($_SESSION['nombre'] ?? $_SESSION['usuario']) ?>.
    Seleccione un módulo de administración de la base <strong><?= e($CONFIG['db']['name']) ?></strong>.
</p>

<section class="grid" aria-label="Módulos">
    <?php foreach ($APP['modulos'] as $m): ?>
        <article class="card <?= $m['archivo'] ? '' : 'card-pendiente' ?>">
            <p class="num">Proyecto <?= e($m['num']) ?></p>
            <h2><?= e($m['titulo']) ?></h2>
            <p><?= e($m['desc']) ?></p>
            <?php if ($m['archivo']): ?>
                <a class="action" href="<?= e($m['archivo']) ?>">Abrir</a>
            <?php else: ?>
                <span class="badge">Próximamente</span>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
