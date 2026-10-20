<?php
/*
 * Pie de todas las páginas protegidas.
 * Muestra lo que pide el enunciado: motor, base, usuario de conexión y versión.
 * Nunca muestra la contraseña.
 */
?>
</main>
<footer class="pie">
    <span><strong>Motor:</strong> <?= e($APP['motor']) ?></span>
    <span><strong>Base:</strong> <?= e($CONFIG['db']['name']) ?></span>
    <span><strong>Usuario de conexión:</strong> <?= e($CONFIG['db']['user']) ?></span>
    <span><strong><?= e($APP['nombre']) ?></strong> versión <?= e($APP['version']) ?></span>
</footer>
</body>
</html>
