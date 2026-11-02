<?php
/*
 * Módulo 05 - Importar y exportar CSV (parte 2: exportar)
 *
 * Exportar: el usuario elige una tabla de la base y sus columnas, ve una vista
 * previa y descarga el CSV. Las tablas y columnas salen de information_schema
 * (lista permitida); nunca se recibe SQL desde el navegador.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
date_default_timezone_set('America/Costa_Rica');

// ---------- 1. Rutas privadas y bitácora ----------
$dirCsv = $CONFIG['csv_dir'] ?? "$homeCuenta/dba_csv";   // ~/dba_csv (700)
$logCsv = "$dirCsv/csv.log";                             // bitácora (600)

function bitacora(string $log, string $accion, string $detalle, string $estado): void
{
    $nuevo = !file_exists($log);
    $linea = date('Y-m-d H:i:s') . " | $accion | $detalle | $estado" . PHP_EOL;
    if (file_put_contents($log, $linea, FILE_APPEND) !== false && $nuevo) {
        chmod($log, 0600);
    }
}

// Escapa un identificador (tabla o columna) que ya salió de la lista permitida
function ident(string $nombre): string
{
    return '`' . str_replace('`', '``', $nombre) . '`';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$tab = ($_GET['tab'] ?? 'exportar') === 'importar' ? 'importar' : 'exportar';
$SEPARADORES = ['coma' => ',', 'puntoycoma' => ';'];
$error = '';

try {
    $pdo = db();

    // ---------- 2. Lista permitida de tablas: las de la base propia ----------
    $tablas = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                           ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);

    $tabla = $_REQUEST['tabla'] ?? '';
    if (!array_key_exists($tabla, $tablas)) {
        $tabla = '';
    }

    // ---------- 3. Columnas de la tabla elegida ----------
    $columnas = [];
    if ($tabla !== '') {
        $st = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                             ORDER BY ORDINAL_POSITION");
        $st->execute([$tabla]);
        $columnas = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Columnas marcadas: solo se aceptan las que existen en la tabla
    $marcadas = array_values(array_intersect(array_keys($columnas), (array)($_REQUEST['cols'] ?? array_keys($columnas))));
    if (!$marcadas) {
        $marcadas = array_keys($columnas);
    }
    $sep = $_REQUEST['sep'] ?? 'coma';
    if (!array_key_exists($sep, $SEPARADORES)) {
        $sep = 'coma';
    }

    $sqlSelect = $tabla !== ''
        ? 'SELECT ' . implode(', ', array_map('ident', $marcadas)) . ' FROM ' . ident($tabla)
        : '';

    // ---------- 4. Descargar el CSV (POST + CSRF) ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'exportar') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '') || $tabla === '') {
            bitacora($logCsv, 'exportar', 'solicitud rechazada', 'ERROR');
            $_SESSION['mensaje'] = 'ERROR: solicitud no válida.';
            header('Location: csv.php');
            exit;
        }
        $archivo = $tabla . '_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        $salida = fopen('php://output', 'w');
        fwrite($salida, "\xEF\xBB\xBF");                       // BOM: Excel reconoce las tildes
        fputcsv($salida, $marcadas, $SEPARADORES[$sep]);        // encabezado
        $filas = 0;
        foreach ($pdo->query($sqlSelect) as $fila) {
            fputcsv($salida, $fila, $SEPARADORES[$sep]);
            $filas++;
        }
        fclose($salida);
        bitacora($logCsv, 'exportar', "$tabla (" . count($marcadas) . " columnas, $filas filas) -> $archivo", 'OK');
        exit;
    }

    // ---------- 5. Vista previa ----------
    $previa = [];
    $total = 0;
    if ($tabla !== '') {
        $previa = $pdo->query($sqlSelect . ' LIMIT 10')->fetchAll();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM ' . ident($tabla))->fetchColumn();
    }
} catch (Throwable $ex) {
    error_log('csv.php: ' . $ex->getMessage());
    $error = 'No se pudo leer la base de datos. Revise la conexión.';
}

$mensaje = $_SESSION['mensaje'] ?? '';
unset($_SESSION['mensaje']);

$titulo = 'Módulo 05: Importar y exportar CSV';
$activo = 'csv.php';
require __DIR__ . '/includes/header.php';
?>
<style>
    .pestanas { display: flex; gap: 8px; margin: 6px 0 20px; }
    .pestanas a { padding: 10px 20px; border-radius: 6px; border: 1px solid #1d3f8f; color: #1d3f8f; text-decoration: none; font-weight: bold; }
    .pestanas a.sel { background: #1d3f8f; color: #fff; }
    .caja { background: #fff; border: 1px solid #d6ddeb; border-radius: 10px; padding: 16px 20px; margin: 18px 0; }
    .caja h2 { margin-top: 0; font-size: 18px; color: #0e235a; }
    .campo { margin: 12px 0; }
    .campo label.titulo { display: block; font-weight: bold; margin-bottom: 6px; }
    .campo select { padding: 8px 10px; border: 1px solid #d6ddeb; border-radius: 6px; font-size: 15px; min-width: 260px; }
    .columnas { display: flex; flex-wrap: wrap; gap: 8px 18px; }
    .columnas label { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; }
    .columnas small { color: #5b6475; }
    .botones { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    .botones button { padding: 10px 18px; border: 0; border-radius: 6px; background: #1d3f8f; color: #fff; font-weight: bold; cursor: pointer; }
    .botones button.sec { background: #fff; color: #1d3f8f; border: 1px solid #1d3f8f; }
    .tabla-scroll { overflow-x: auto; }
    .tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .tabla th, .tabla td { padding: 8px 10px; border-bottom: 1px solid #d6ddeb; text-align: left; white-space: nowrap; }
    .tabla th { background: #eef2fa; color: #0e235a; }
    .nota { color: #5b6475; font-size: 14px; }
    .caja pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
    .mensaje { padding: 10px 14px; border-radius: 8px; background: #fee2e2; color: #991b1b; }
</style>

<h1>Módulo 05: Importar y exportar CSV</h1>

<div class="pestanas">
    <a href="?tab=exportar" class="<?= $tab === 'exportar' ? 'sel' : '' ?>">Exportar</a>
    <a href="?tab=importar" class="<?= $tab === 'importar' ? 'sel' : '' ?>">Importar</a>
</div>

<?php if ($mensaje !== ''): ?><p class="mensaje"><?= e($mensaje) ?></p><?php endif; ?>

<?php if ($error !== ''): ?>
    <p class="mensaje"><?= e($error) ?></p>

<?php elseif ($tab === 'exportar'): ?>
    <!-- Paso 1: elegir tabla -->
    <div class="caja">
        <h2>1. Elegir la tabla</h2>
        <form method="get">
            <input type="hidden" name="tab" value="exportar">
            <div class="campo">
                <select name="tabla" onchange="this.form.submit()">
                    <option value="">Seleccione una tabla…</option>
                    <?php foreach ($tablas as $nombre => $filasEst): ?>
                        <option value="<?= e($nombre) ?>" <?= $nombre === $tabla ? 'selected' : '' ?>>
                            <?= e($nombre) ?> (aprox. <?= e($filasEst) ?> filas)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <?php if ($tabla !== ''): ?>
        <!-- Paso 2: columnas y separador -->
        <form method="get" class="caja">
            <h2>2. Columnas y formato</h2>
            <input type="hidden" name="tab" value="exportar">
            <input type="hidden" name="tabla" value="<?= e($tabla) ?>">
            <div class="campo">
                <label class="titulo">Columnas a exportar</label>
                <div class="columnas">
                    <?php foreach ($columnas as $col => $tipo): ?>
                        <label><input type="checkbox" name="cols[]" value="<?= e($col) ?>" <?= in_array($col, $marcadas, true) ? 'checked' : '' ?>>
                            <?= e($col) ?> <small><?= e($tipo) ?></small></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="campo">
                <label class="titulo">Separador</label>
                <select name="sep">
                    <option value="coma" <?= $sep === 'coma' ? 'selected' : '' ?>>Coma ( , )</option>
                    <option value="puntoycoma" <?= $sep === 'puntoycoma' ? 'selected' : '' ?>>Punto y coma ( ; ) para Excel en español</option>
                </select>
            </div>
            <div class="botones"><button type="submit" class="sec">Actualizar vista previa</button></div>
        </form>

        <!-- Paso 3: vista previa y descarga -->
        <div class="caja">
            <h2>3. Vista previa y descarga</h2>
            <p class="nota">Primeras <?= count($previa) ?> de <?= number_format($total) ?> filas de <strong><?= e($tabla) ?></strong>. Consulta que se ejecutará:</p>
            <pre><?= e($sqlSelect) ?></pre>
            <div class="tabla-scroll">
                <table class="tabla">
                    <tr><?php foreach ($marcadas as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr>
                    <?php foreach ($previa as $fila): ?>
                        <tr><?php foreach ($fila as $v): ?><td><?= e($v ?? 'NULL') ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <form method="post" action="csv.php?tab=exportar">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="exportar">
                <input type="hidden" name="tabla" value="<?= e($tabla) ?>">
                <input type="hidden" name="sep" value="<?= e($sep) ?>">
                <?php foreach ($marcadas as $c): ?><input type="hidden" name="cols[]" value="<?= e($c) ?>"><?php endforeach; ?>
                <div class="botones"><button type="submit">Descargar CSV (<?= number_format($total) ?> filas)</button></div>
            </form>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="caja">
        <h2>Importar</h2>
        <p class="nota">Esta pestaña se construye en la parte 3.</p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
