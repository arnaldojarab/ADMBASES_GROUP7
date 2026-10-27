<?php
/*
 * Módulo 02 - Respaldos
 * 1. Botón "Generar nuevo respaldo": ejecuta ~/dba_scripts/backup.sh
 * 2. Lee la bitácora y la carpeta ~/respaldos
 * 3. Muestra un resumen y la tabla de respaldos
 */
require_once __DIR__ . '/includes/auth.php';
date_default_timezone_set('America/Costa_Rica');

$scriptBackup = $CONFIG['scripts_dir'] . '/backup.sh';   // ~/dba_scripts/backup.sh
$dirRespaldos = $CONFIG['respaldos_dir'];                // ~/respaldos
$logRespaldos = $CONFIG['log_respaldos'];                // ~/respaldos/respaldos.log

// Token del formulario: solo esta página puede pedir un respaldo
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---------- A. Botón "Generar nuevo respaldo" ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['mensaje'] = 'ERROR: solicitud no válida.';
    } else {
        // Siempre el mismo script; nada de lo que envía el navegador entra al comando
        exec(escapeshellarg($scriptBackup) . ' 2>&1', $salida, $codigo);
        $_SESSION['mensaje'] = $codigo === 0
            ? 'Respaldo generado correctamente.'
            : 'ERROR: ' . implode(' ', $salida);
    }
    header('Location: respaldos.php');   // recargar la página no repite el respaldo
    exit;
}
$mensaje = $_SESSION['mensaje'] ?? '';
unset($_SESSION['mensaje']);

// ---------- B. Leer la bitácora: "fecha | archivo | OK o ERROR" ----------
$lineasLog = is_readable($logRespaldos)
    ? file($logRespaldos, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

$estados = [];
foreach ($lineasLog as $linea) {
    $partes = explode(' | ', $linea);
    if (count($partes) === 3) {
        $estados[$partes[1]] = $partes[2];          // archivo => OK / ERROR
    }
}

// ---------- C. Buscar los respaldos (como un ls respaldo_*.sql.gz) ----------
$respaldos = [];
foreach (glob($dirRespaldos . '/respaldo_*.sql.gz') as $ruta) {
    $nombre = basename($ruta);
    $respaldos[] = [
        'nombre' => $nombre,
        'fecha'  => filemtime($ruta),
        'tamano' => filesize($ruta),
        'estado' => $estados[$nombre] ?? 'SIN REGISTRO',
    ];
}
usort($respaldos, fn($a, $b) => $b['fecha'] <=> $a['fecha']);   // más reciente primero (ls -t)

$totalKb = array_sum(array_column($respaldos, 'tamano')) / 1024;

$titulo = 'Módulo 02: Respaldos';
$activo = 'respaldos.php';
require __DIR__ . '/includes/header.php';
?>
<style>
    .boton { padding: 11px 20px; border: 0; border-radius: 6px; background: #047857; color: #fff; font-size: 15px; font-weight: bold; cursor: pointer; }
    .boton:hover { background: #064e3b; }
    .mensaje { padding: 10px 14px; border-radius: 8px; background: #d1fae5; color: #064e3b; }
    .mensaje-error { background: #fee2e2; color: #991b1b; }
    .resumen { display: flex; flex-wrap: wrap; gap: 14px; margin: 20px 0; }
    .resumen div { flex: 1; min-width: 160px; background: #fff; border: 1px solid #dce9e1; border-radius: 10px; padding: 12px 16px; }
    .resumen span { display: block; font-size: 13px; color: #60776c; }
    .resumen strong { font-size: 19px; color: #064e3b; }
    .tabla { width: 100%; border-collapse: collapse; background: #fff; font-size: 14px; }
    .tabla th, .tabla td { padding: 10px 12px; border-bottom: 1px solid #dce9e1; text-align: left; }
    .tabla th { background: #ecfdf5; color: #064e3b; }
    .tabla .num { text-align: right; }
    .estado-ok { color: #047857; font-weight: bold; }
    .estado-error { color: #b91c1c; font-weight: bold; }
</style>

<h1>Módulo 02: Respaldos</h1>
<p class="intro">Respaldos de la base <strong><?= e($CONFIG['db']['name']) ?></strong>, guardados en <code>~/respaldos/</code>.</p>

<?php if ($mensaje !== ''): ?>
    <p class="mensaje <?= str_starts_with($mensaje, 'ERROR') ? 'mensaje-error' : '' ?>"><?= e($mensaje) ?></p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <button type="submit" class="boton">Generar nuevo respaldo</button>
</form>

<div class="resumen">
    <div><span>Cantidad de respaldos</span><strong><?= count($respaldos) ?></strong></div>
    <div><span>Espacio utilizado</span><strong><?= number_format($totalKb, 1) ?> KB</strong></div>
    <div><span>Respaldo más reciente</span><strong><?= $respaldos ? date('d/m/Y H:i:s', $respaldos[0]['fecha']) : '-' ?></strong></div>
</div>

<?php if (!$respaldos): ?>
    <div class="panel vacio">Todavía no hay respaldos.</div>
<?php else: ?>
    <table class="tabla">
        <tr><th>Archivo</th><th>Fecha</th><th class="num">Tamaño</th><th>Estado</th></tr>
        <?php foreach ($respaldos as $r): ?>
            <tr>
                <td><?= e($r['nombre']) ?></td>
                <td><?= date('d/m/Y H:i:s', $r['fecha']) ?></td>
                <td class="num"><?= number_format($r['tamano'] / 1024, 1) ?> KB</td>
                <td class="<?= $r['estado'] === 'OK' ? 'estado-ok' : 'estado-error' ?>"><?= e($r['estado']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
