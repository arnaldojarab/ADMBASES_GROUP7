<?php
/*
 * Módulo 01 - Monitor de Base de Datos (MariaDB)
 * Muestra: conexión, tamaño de la BD, tablas, registros, tamaños,
 * tablas que más espacio consumen, consultas usadas y explicación.
 *
 * Las credenciales NO están aquí: salen de $CONFIG['db'] (cargado desde ~/dba_config/db.cnf
 * por includes/bootstrap.php).
 */
require_once __DIR__ . '/includes/auth.php';
date_default_timezone_set('America/Costa_Rica');

// ---------- Utilidades ----------
function formatoBytes(float $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes, 0) . ' B';
}

// ---------- Consultas (fijas, sin datos del navegador) ----------
$SQL_INFO = "SELECT VERSION() AS version, DATABASE() AS bd, CURRENT_USER() AS usuario, NOW() AS ahora";

$SQL_TABLAS = "SELECT TABLE_NAME AS nombre,
                      TABLE_ROWS AS registros,
                      DATA_LENGTH AS datos,
                      INDEX_LENGTH AS indices,
                      (DATA_LENGTH + INDEX_LENGTH) AS total
               FROM information_schema.TABLES
               WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_TYPE = 'BASE TABLE'
               ORDER BY total DESC";

// ---------- Conexión con las credenciales centralizadas ----------
$cfg   = $CONFIG['db'];
$host  = $cfg['host'] ?? 'localhost';
$port  = $cfg['port'] ?? 3306;
$bd    = $cfg['name'];
$user  = $cfg['user'] ?? $cfg['usuario'] ?? '';
$pass  = $cfg['pass'] ?? $cfg['password'] ?? $cfg['clave'] ?? '';

$error  = '';
$info   = null;
$tablas = [];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$bd};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $info   = $pdo->query($SQL_INFO)->fetch();
    $tablas = $pdo->query($SQL_TABLAS)->fetchAll();
} catch (PDOException $ex) {
    // Detalle solo en el log del servidor; nunca se muestra al navegador
    error_log('monitoreo.php: ' . $ex->getMessage());
    $error = 'No se pudo conectar o consultar la base de datos. Revise la configuración.';
}
$pdo = null;   // cerrar conexión; la contraseña no se vuelve a usar

// ---------- Cálculos de resumen ----------
$totalBytes     = array_sum(array_map('floatval', array_column($tablas, 'total')));
$totalRegistros = array_sum(array_map('intval', array_column($tablas, 'registros')));
$maxTotal       = $tablas ? max(array_map('floatval', array_column($tablas, 'total'))) : 0;
$top3           = array_slice(array_column($tablas, 'nombre'), 0, 3);

$titulo = 'Módulo 01: Monitor de Base de Datos';
$activo = 'monitor.php';
require __DIR__ . '/includes/header.php';
?>
<style>
    .mensaje { padding: 10px 14px; border-radius: 8px; background: #fee2e2; color: #991b1b; }
    .resumen { display: flex; flex-wrap: wrap; gap: 14px; margin: 20px 0; }
    .resumen div { flex: 1; min-width: 160px; background: #fff; border: 1px solid #dce9e1; border-radius: 10px; padding: 12px 16px; }
    .resumen span { display: block; font-size: 13px; color: #60776c; }
    .resumen strong { font-size: 19px; color: #064e3b; word-break: break-word; }
    .tabla { width: 100%; border-collapse: collapse; background: #fff; font-size: 14px; }
    .tabla th, .tabla td { padding: 10px 12px; border-bottom: 1px solid #dce9e1; text-align: left; }
    .tabla th { background: #ecfdf5; color: #064e3b; }
    .tabla .num { text-align: right; }
    .tabla tr.top td { background: #fffbeb; }
    .barra { background: #e5efe9; border-radius: 6px; height: 10px; min-width: 120px; }
    .barra i { display: block; height: 100%; border-radius: 6px; background: #047857; }
    .top .barra i { background: #d97706; }
    .caja { background: #fff; border: 1px solid #dce9e1; border-radius: 10px; padding: 14px 18px; margin: 20px 0; }
    .caja pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
    .caja h2 { margin-top: 0; font-size: 18px; color: #064e3b; }
    summary { cursor: pointer; font-weight: bold; color: #064e3b; }
</style>

<h1>Módulo 01: Monitor de Base de Datos</h1>
<p class="intro">Espacio y contenido de la base <strong><?= e($bd) ?></strong>.</p>

<?php if ($error !== ''): ?>
    <p class="mensaje"><?= e($error) ?></p>
<?php else: ?>

    <!-- Conexión -->
    <div class="resumen">
        <div><span>Motor</span><strong>MariaDB <?= e($info['version']) ?></strong></div>
        <div><span>Base de datos</span><strong><?= e($info['bd']) ?></strong></div>
        <div><span>Usuario conectado</span><strong><?= e($info['usuario']) ?></strong></div>
        <div><span>Fecha y hora de la consulta</span><strong><?= e(date('d/m/Y H:i:s', strtotime($info['ahora']))) ?></strong></div>
    </div>

    <!-- Tamaño de la BD -->
    <div class="resumen">
        <div><span>Tamaño total aproximado de la BD</span><strong><?= formatoBytes($totalBytes) ?></strong></div>
        <div><span>Cantidad de tablas</span><strong><?= count($tablas) ?></strong></div>
        <div><span>Registros (estimado)</span><strong><?= number_format($totalRegistros, 0, ',', '.') ?></strong></div>
        <div><span>Tablas que más espacio consumen</span><strong><?= $top3 ? e(implode(', ', $top3)) : '-' ?></strong></div>
    </div>

    <!-- Listado de tablas -->
    <?php if (!$tablas): ?>
        <div class="panel vacio">La base de datos todavía no tiene tablas.</div>
    <?php else: ?>
        <table class="tabla">
            <tr>
                <th>Tabla</th>
                <th class="num">Registros</th>
                <th class="num">Datos</th>
                <th class="num">Índices</th>
                <th class="num">Tamaño total</th>
                <th>% de la BD</th>
            </tr>
            <?php foreach ($tablas as $i => $t):
                $pct = $totalBytes > 0 ? ($t['total'] / $totalBytes) * 100 : 0;
                $ancho = $maxTotal > 0 ? ($t['total'] / $maxTotal) * 100 : 0;
            ?>
                <tr class="<?= $i < 3 ? 'top' : '' ?>">
                    <td><?= e($t['nombre']) ?><?= $i === 0 ? ' ⭐' : '' ?></td>
                    <td class="num"><?= number_format((int)$t['registros'], 0, ',', '.') ?></td>
                    <td class="num"><?= formatoBytes((float)$t['datos']) ?></td>
                    <td class="num"><?= formatoBytes((float)$t['indices']) ?></td>
                    <td class="num"><?= formatoBytes((float)$t['total']) ?></td>
                    <td>
                        <div class="barra"><i style="width: <?= number_format($ancho, 1, '.', '') ?>%"></i></div>
                        <small><?= number_format($pct, 1) ?>%</small>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th>Total</th>
                <th class="num"><?= number_format($totalRegistros, 0, ',', '.') ?></th>
                <th class="num"><?= formatoBytes((float)array_sum(array_column($tablas, 'datos'))) ?></th>
                <th class="num"><?= formatoBytes((float)array_sum(array_column($tablas, 'indices'))) ?></th>
                <th class="num"><?= formatoBytes($totalBytes) ?></th>
                <th></th>
            </tr>
        </table>
        <p><small>Resaltadas en amarillo: las 3 tablas que más espacio consumen. ⭐ = la mayor.</small></p>
    <?php endif; ?>

<?php endif; ?>

<!-- Consultas utilizadas -->
<div class="caja">
    <h2>Consultas utilizadas</h2>
    <details open>
        <summary>1. Información de la conexión</summary>
        <pre><?= e($SQL_INFO) ?></pre>
    </details>
    <details open>
        <summary>2. Tablas, registros y tamaños</summary>
        <pre><?= e($SQL_TABLAS) ?></pre>
    </details>
</div>

<!-- Explicación de los resultados -->
<div class="caja">
    <h2>Explicación de los resultados</h2>
    <ul>
        <li><strong>Conexión:</strong> <code>VERSION()</code>, <code>DATABASE()</code> y <code>CURRENT_USER()</code> son funciones de MariaDB que devuelven la versión del motor, la base activa y la cuenta con la que se autenticó PHP. <code>NOW()</code> da la hora del servidor al momento de consultar.</li>
        <li><strong>Origen de los datos:</strong> la vista <code>information_schema.TABLES</code> es un catálogo del propio motor con una fila por tabla; no requiere permisos especiales, solo muestra las tablas a las que el usuario tiene acceso.</li>
        <li><strong>Datos / Índices / Total:</strong> <code>DATA_LENGTH</code> es el espacio de las filas, <code>INDEX_LENGTH</code> el de los índices (llaves primarias y foráneas incluidas). El total por tabla es su suma, y el tamaño de la BD es la suma de todas las tablas.</li>
        <li><strong>Registros:</strong> <code>TABLE_ROWS</code> es una <em>estimación</em> mantenida por InnoDB, por lo que puede diferir un poco de un <code>COUNT(*)</code> exacto. Es la forma recomendada para monitoreo porque es instantánea aun con tablas grandes.</li>
        <li><strong>Tablas que más consumen espacio:</strong> el resultado se ordena por tamaño total descendente (<code>ORDER BY total DESC</code>), así las primeras filas son las más pesadas.</li>
        <li><strong>Seguridad:</strong> las consultas son fijas (ningún valor del navegador entra al SQL) y las credenciales provienen del archivo de configuración protegido, nunca se imprimen en la página.</li>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
