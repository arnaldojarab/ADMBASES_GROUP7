<?php
/* Proyectos 03 a 06: reservados para las próximas semanas. */
require_once __DIR__ . '/includes/auth.php';

// Solo se aceptan los números 03 a 06; cualquier otro valor vuelve al inicio.
$p = $_GET['p'] ?? '';
if (!in_array($p, ['03', '04', '05', '06'], true)) {
    header('Location: index.php');
    exit;
}

$titulo = "Proyecto $p";
$activo = 'proximamente.php';
require __DIR__ . '/includes/header.php';
?>
<h1>Proyecto <?= e($p) ?></h1>
<div class="panel vacio">
    <p><strong>Módulo disponible próximamente.</strong></p>
    <p>Este espacio queda reservado para el Proyecto <?= e($p) ?> de la Consola DBA.</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
