<?php
/*
 * Módulo 03 - Diagnóstico de consultas (versión 6: ensayo en Q01 y Q02, con la mejora aplicada y su interpretación)
 *
 * Catálogo controlado: la web solo permite ELEGIR Q01 o Q02.
 * El SQL está escrito aquí, en el servidor; nunca se recibe del navegador.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
date_default_timezone_set('America/Costa_Rica');

// ---------- 1. Catálogo de casos (lista permitida) ----------
$CASOS = [
    'Q01' => [
        'titulo'    => 'Cotizaciones de un mes',
        'proposito' => 'Filtra una sola tabla por un dato significativo: la fecha de la cotización.',
        'sql'       => "SELECT id_cotizacion, fecha, estado, monto
FROM cotizaciones
WHERE fecha BETWEEN :desde AND :hasta
ORDER BY fecha",
        'params'    => ['desde' => '2026-03-01', 'hasta' => '2026-03-31'],
        'alias'     => ['cotizaciones' => 'cotizaciones'],
        'ensayo'    => true,    // a este caso se le hace el ensayo antes / después
        'mejora'    => [
            'tabla'   => 'cotizaciones',
            'indice'  => 'idx_cotizaciones_fecha',
            'sql'     => 'CREATE INDEX idx_cotizaciones_fecha ON cotizaciones(fecha);',
            'archivo' => 'sql/indice_cotizaciones.sql',
            'motivo'  => 'Q01 filtra por un rango de fechas y ordena por fecha. Sin un índice sobre fecha, MariaDB lee toda la tabla y ordena el resultado aparte.',
        ],
    ],
    'Q02' => [
        'titulo'    => 'Cotizaciones de una marca',
        'proposito' => 'Relaciona tres tablas con JOIN, filtra por marca y ordena por fecha.',
        'sql'       => "SELECT c.id_cotizacion, c.fecha, m.nombre AS marca, v.modelo, c.monto
FROM cotizaciones c
JOIN vehiculos v ON v.id_vehiculo = c.id_vehiculo
JOIN marcas m    ON m.id_marca    = v.id_marca
WHERE m.nombre = :marca
ORDER BY c.fecha DESC",
        'params'    => ['marca' => 'Toyota'],
        'alias'     => ['c' => 'cotizaciones', 'v' => 'vehiculos', 'm' => 'marcas'],
        'ensayo'    => true,
        'mejora'    => [
            'tabla'   => 'marcas',
            'indice'  => 'idx_marcas_nombre',
            'sql'     => 'CREATE INDEX idx_marcas_nombre ON marcas(nombre);',
            'archivo' => 'sql/indice_marcas.sql',
            'motivo'  => 'Q02 empieza buscando la marca por su nombre. Sin un índice sobre nombre, MariaDB recorre todas las marcas para encontrarla.',
        ],
    ],
];

// ---------- 2. Elegir el caso: solo se acepta una clave del catálogo ----------
$id = $_GET['caso'] ?? 'Q01';
if (!array_key_exists($id, $CASOS)) {
    $id = 'Q01';
}
$caso = $CASOS[$id];

// ---------- 3. Contexto, plan y resultado ----------
$error = '';
try {
    $pdo = db();
    $contexto = $pdo->query("SELECT VERSION() AS version, DATABASE() AS bd, CURRENT_USER() AS usuario, NOW() AS ahora")->fetch();

    // EXPLAIN real de la consulta, con los parámetros como valores preparados
    $st = $pdo->prepare('EXPLAIN ' . $caso['sql']);
    $st->execute($caso['params']);
    $plan = $st->fetchAll();

    // Resultado de la consulta: cantidad de filas y suma de montos
    $st = $pdo->prepare('SELECT COUNT(*) AS filas, SUM(monto) AS total FROM (' . $caso['sql'] . ') AS q');
    $st->execute($caso['params']);
    $resultado = $st->fetch();

    // ¿Ya existe el índice de la mejora? (nombres tomados del catálogo, enlazados como valores)
    $indiceExiste = null;
    if (!empty($caso['mejora'])) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$caso['mejora']['tabla'], $caso['mejora']['indice']]);
        $indiceExiste = (int)$st->fetchColumn() > 0;
    }
} catch (Throwable $ex) {
    error_log('diagnostico.php: ' . $ex->getMessage());
    $error = 'No se pudo obtener el plan de la consulta. Revise la conexión y que exista la tabla cotizaciones.';
}

// ---------- 3b. Guardar el plan "antes" (fuera de public_html) ----------
$dirPlanes    = $CONFIG['planes_dir'] ?? "$homeCuenta/dba_planes";   // ~/dba_planes
$archivoAntes = "$dirPlanes/{$id}_antes.json";                       // $id ya viene del catálogo

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['mensaje'] = 'ERROR: solicitud no válida.';
    } elseif (empty($caso['ensayo'])) {
        $_SESSION['mensaje'] = 'ERROR: este caso no tiene ensayo antes / después.';
    } elseif ($error !== '') {
        $_SESSION['mensaje'] = 'ERROR: no hay plan para guardar.';
    } elseif (file_exists($archivoAntes)) {
        $_SESSION['mensaje'] = "El plan \"antes\" de $id ya estaba guardado; no se sobrescribe.";
    } else {
        $datos = [
            'caso'      => $id,
            'etapa'     => 'antes',
            'fecha'     => date('Y-m-d H:i:s'),
            'cuenta'    => get_current_user(),
            'bd'        => $contexto['bd'],
            'sql'       => $caso['sql'],
            'params'    => $caso['params'],
            'resultado' => $resultado,
            'plan'      => $plan,
        ];
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($archivoAntes, $json) !== false) {
            chmod($archivoAntes, 0600);
            $_SESSION['mensaje'] = "Plan \"antes\" de $id guardado en ~/dba_planes/";
        } else {
            error_log("diagnostico.php: no se pudo escribir $archivoAntes");
            $_SESSION['mensaje'] = 'ERROR: no se pudo guardar el plan.';
        }
    }
    header('Location: diagnostico.php?caso=' . urlencode($id));   // recargar no repite el guardado
    exit;
}

$mensaje = $_SESSION['mensaje'] ?? '';
unset($_SESSION['mensaje']);
$guardado = is_readable($archivoAntes) ? json_decode(file_get_contents($archivoAntes), true) : null;

// ---------- 4. Interpretación del plan ----------
// Qué significa cada tipo de acceso de la columna "type" de EXPLAIN (MariaDB)
$TIPOS = [
    'system' => 'la tabla tiene una sola fila',
    'const'  => 'encuentra a lo sumo una fila, por llave primaria o UNIQUE',
    'eq_ref' => 'por cada fila anterior encuentra exactamente una, por llave primaria o UNIQUE',
    'ref'    => 'busca por un valor en un índice; puede haber varias filas con ese valor',
    'range'  => 'recorre solo un rango dentro de un índice (por ejemplo, un BETWEEN)',
    'index'  => 'recorre el índice completo',
    'ALL'    => 'recorrido completo: lee todas las filas de la tabla, sin usar índice',
];
// Qué significan los avisos más comunes de la columna "Extra"
$EXTRAS = [
    'Using where'           => 'aplica el filtro WHERE sobre las filas que lee',
    'Using filesort'        => 'tiene que ordenar el resultado aparte, porque ningún índice le da el orden del ORDER BY',
    'Using temporary'       => 'usa una tabla temporal para poder ordenar o agrupar',
    'Using index condition' => 'evalúa el filtro dentro del índice antes de leer la fila completa',
    'Using index'           => 'obtiene todo desde el índice, sin leer la tabla',
];

function interpretarFila(array $f, array $alias, array $tipos, array $extras): array
{
    $tabla = $alias[$f['table']] ?? $f['table'];
    $nombre = $f['table'] === $tabla ? $tabla : "{$tabla} (alias {$f['table']})";
    $lineas = [];
    $lineas[] = "Acceso {$f['type']}: " . ($tipos[$f['type']] ?? 'tipo de acceso no catalogado') . '.';
    $lineas[] = $f['key'] !== null
        ? "Índice usado: {$f['key']}" . ($f['possible_keys'] ? " (candidatos: {$f['possible_keys']})." : '.')
        : 'No usa índice' . ($f['possible_keys'] ? " (había candidatos: {$f['possible_keys']}, pero no le sirven para este filtro)." : ': no hay ninguno que sirva para esta consulta.');
    $lineas[] = "Filas estimadas: {$f['rows']}. Es una estimación del motor a partir de sus estadísticas, no un tiempo.";
    foreach ($extras as $clave => $texto) {
        if (str_contains((string)$f['Extra'], $clave)) {
            $lineas[] = "{$clave}: {$texto}.";
        }
    }
    return ['tabla' => $nombre, 'lineas' => $lineas];
}

// ---------- 5. Comparación con el plan "antes" guardado ----------
$comparacion = [];
$equivalente = null;
if ($guardado && $error === '') {
    // Se emparejan las filas de ambos planes por el nombre (o alias) de la tabla
    $antesPorTabla = array_column($guardado['plan'], null, 'table');
    foreach ($plan as $despues) {
        $antes = $antesPorTabla[$despues['table']] ?? null;
        $filas = [];
        foreach (['type', 'key', 'rows', 'Extra'] as $col) {
            $a = $antes[$col] ?? null;
            $d = $despues[$col] ?? null;
            $filas[] = ['col' => $col, 'antes' => $a ?? 'NULL', 'despues' => $d ?? 'NULL', 'cambio' => (string)$a !== (string)$d];
        }
        $comparacion[] = [
            'tabla'   => $caso['alias'][$despues['table']] ?? $despues['table'],
            'filas'   => $filas,
            'rowsA'   => (int)($antes['rows'] ?? 0),
            'rowsD'   => (int)$despues['rows'],
        ];
    }
    // Mismo resultado antes y después: misma cantidad de filas y misma suma de montos
    $equivalente = (int)$guardado['resultado']['filas'] === (int)$resultado['filas']
                && (string)$guardado['resultado']['total'] === (string)$resultado['total'];
}

// Interpretación breve de la mejora, a partir de lo que cambió en el plan
$lecturaMejora = [];
if ($comparacion) {
    $huboCambio = false;
    foreach ($comparacion as $c) {
        $v = array_column($c['filas'], null, 'col');
        if (!$v['type']['cambio'] && !$v['key']['cambio'] && !$v['rows']['cambio']) {
            continue;
        }
        $huboCambio = true;
        $tA = $v['type']['antes'];
        $tD = $v['type']['despues'];
        $l = "En {$c['tabla']}, el acceso pasó de {$tA} a {$tD}. Antes: " . ($TIPOS[$tA] ?? '') . '; ahora: ' . ($TIPOS[$tD] ?? '') . '.';
        if ($v['key']['cambio'] && $v['key']['despues'] !== 'NULL') {
            $l .= " Ahora usa el índice {$v['key']['despues']}.";
        }
        if ($c['rowsA'] > 0 && $c['rowsD'] !== $c['rowsA']) {
            $l .= " Las filas estimadas bajan de {$c['rowsA']} a {$c['rowsD']}.";
        }
        $exA = (string)$v['Extra']['antes'];
        $exD = (string)$v['Extra']['despues'];
        if (str_contains($exA, 'Using filesort') && !str_contains($exD, 'Using filesort')) {
            $l .= ' Desaparece Using filesort: el índice ya entrega las filas en el orden del ORDER BY.';
        } elseif (str_contains($exD, 'Using filesort')) {
            $l .= ' Using filesort se mantiene, porque el orden lo pide una columna que este índice no cubre.';
        }
        $lecturaMejora[] = $l;
    }
    if (!$huboCambio) {
        $lecturaMejora[] = $indiceExiste
            ? 'El índice existe, pero el plan no cambió: MariaDB estimó que su plan original era igual o más barato (por ejemplo, si la tabla es pequeña o el filtro es poco selectivo).'
            : 'Todavía no se ha aplicado la mejora: el plan actual es igual al guardado.';
    } else {
        $lecturaMejora[] = 'Las filas estimadas son una estimación del optimizador, no un tiempo de ejecución.';
    }
}

$interpretacion = [];
$estimadoTotal = 1;
if ($error === '') {
    foreach ($plan as $fila) {
        $interpretacion[] = interpretarFila($fila, $caso['alias'], $TIPOS, $EXTRAS);
        $estimadoTotal *= max(1, (int)$fila['rows']);
    }
}

$titulo = 'Módulo 03: Diagnóstico de consultas';
$activo = 'diagnostico.php';
require __DIR__ . '/includes/header.php';
?>
<style>
    .resumen { display: flex; flex-wrap: wrap; gap: 14px; margin: 20px 0; }
    .resumen div { flex: 1; min-width: 160px; background: #fff; border: 1px solid #dce9e1; border-radius: 10px; padding: 12px 16px; }
    .resumen span { display: block; font-size: 13px; color: #60776c; }
    .resumen strong { font-size: 17px; color: #064e3b; word-break: break-word; }
    .casos { display: flex; gap: 10px; margin: 10px 0 20px; }
    .casos a { padding: 10px 18px; border-radius: 6px; border: 1px solid #047857; color: #047857; text-decoration: none; font-weight: bold; }
    .casos a.sel { background: #047857; color: #fff; }
    .caja { background: #fff; border: 1px solid #dce9e1; border-radius: 10px; padding: 14px 18px; margin: 18px 0; }
    .caja h2 { margin-top: 0; font-size: 18px; color: #064e3b; }
    .caja pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
    .tabla-scroll { overflow-x: auto; }
    .tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .tabla th, .tabla td { padding: 8px 10px; border-bottom: 1px solid #dce9e1; text-align: left; white-space: nowrap; }
    .tabla th { background: #ecfdf5; color: #064e3b; }
    .mensaje { padding: 10px 14px; border-radius: 8px; background: #fee2e2; color: #991b1b; }
    .interp h3 { margin: 14px 0 4px; font-size: 15px; color: #047857; }
    .interp ul { margin: 0; padding-left: 20px; line-height: 1.6; }
    .nota { color: #60776c; font-size: 14px; }
    .aviso { padding: 10px 14px; border-radius: 8px; background: #dcfce7; color: #166534; }
    .tabla td.cambio { background: #fef9c3; font-weight: bold; }
    .ok { color: #166534; font-weight: bold; }
    .mal { color: #991b1b; font-weight: bold; }
    .caja button { padding: 10px 18px; border: 0; border-radius: 6px; background: #047857; color: #fff; font-weight: bold; cursor: pointer; }
</style>

<h1>Módulo 03: Diagnóstico de consultas</h1>

<?php if ($error !== ''): ?>
    <p class="mensaje"><?= e($error) ?></p>
<?php else: ?>

    <!-- Contexto -->
    <div class="resumen">
        <div><span>Motor (versión del servidor)</span><strong>MariaDB <?= e($contexto['version']) ?></strong></div>
        <div><span>Base de datos</span><strong><?= e($contexto['bd']) ?></strong></div>
        <div><span>Usuario conectado</span><strong><?= e($contexto['usuario']) ?></strong></div>
        <div><span>Fecha y hora</span><strong><?= e(date('d/m/Y H:i:s', strtotime($contexto['ahora']))) ?></strong></div>
        <div><span>Consola DBA</span><strong>versión <?= e($APP['version']) ?></strong></div>
    </div>

    <!-- Selector de caso -->
    <div class="casos">
        <?php foreach ($CASOS as $clave => $c): ?>
            <a href="?caso=<?= e($clave) ?>" class="<?= $clave === $id ? 'sel' : '' ?>"><?= e($clave) ?>: <?= e($c['titulo']) ?></a>
        <?php endforeach; ?>
    </div>

    <!-- El caso -->
    <div class="caja">
        <h2><?= e($id) ?>: <?= e($caso['titulo']) ?></h2>
        <p><?= e($caso['proposito']) ?></p>
        <pre><?= e($caso['sql']) ?></pre>
        <p><strong>Parámetros:</strong>
            <?php foreach ($caso['params'] as $nombre => $valor): ?>
                <code>:<?= e($nombre) ?> = <?= e($valor) ?></code>
            <?php endforeach; ?>
        </p>
        <p><strong>Resultado:</strong> <?= e($resultado['filas']) ?> filas, total ₡<?= number_format((float)$resultado['total'], 2) ?></p>
    </div>

    <!-- Plan "antes" guardado (solo los casos con ensayo) -->
    <?php if (empty($caso['ensayo'])): ?>
        <p class="nota">Este caso es solo de diagnóstico: ya usa los índices de las llaves foráneas.
            El ensayo antes / después con un índice nuevo se hace con Q01.</p>
    <?php else: ?>
    <div class="caja">
        <h2>Plan "antes" de <?= e($id) ?></h2>
        <?php if ($mensaje !== ''): ?>
            <p class="<?= str_starts_with($mensaje, 'ERROR') ? 'mensaje' : 'aviso' ?>"><?= e($mensaje) ?></p>
        <?php endif; ?>
        <?php if ($guardado): ?>
            <p>Guardado el <strong><?= e(date('d/m/Y H:i:s', strtotime($guardado['fecha']))) ?></strong>
                por la cuenta <strong><?= e($guardado['cuenta']) ?></strong>
                en <code>~/dba_planes/<?= e(basename($archivoAntes)) ?></code>.</p>
        <?php else: ?>
            <p class="nota">Todavía no se ha guardado. Se guarda una sola vez, antes de crear el índice.</p>
            <form method="post" action="diagnostico.php?caso=<?= e($id) ?>">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <button type="submit">Guardar plan (antes)</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Mejora ensayada (solo los casos con ensayo) -->
    <?php if (!empty($caso['mejora'])): ?>
        <div class="caja">
            <h2>Mejora ensayada en <?= e($id) ?></h2>
            <p><?= e($caso['mejora']['motivo']) ?></p>
            <p><strong>Modificación aplicada</strong> (desde la terminal, archivo <code><?= e($caso['mejora']['archivo']) ?></code>):</p>
            <pre><?= e($caso['mejora']['sql']) ?></pre>
            <p><strong>Estado del índice:</strong>
                <?php if ($indiceExiste): ?>
                    <span class="ok">creado en la tabla <?= e($caso['mejora']['tabla']) ?></span>
                <?php else: ?>
                    <span class="mal">todavía no existe</span>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- El plan -->
    <div class="caja">
        <h2>Plan de ejecución (EXPLAIN)</h2>
        <div class="tabla-scroll">
            <table class="tabla">
                <tr><?php foreach (array_keys($plan[0]) as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr>
                <?php foreach ($plan as $fila): ?>
                    <tr><?php foreach ($fila as $valor): ?><td><?= e($valor ?? 'NULL') ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- Comparación antes / después -->
    <?php if ($guardado): ?>
        <div class="caja">
            <h2>Comparación del plan: antes / después</h2>
            <p class="nota">Antes: plan guardado el <?= e(date('d/m/Y H:i:s', strtotime($guardado['fecha']))) ?>.
                Después: plan actual, obtenido ahora con EXPLAIN. Las celdas resaltadas cambiaron.</p>
            <?php foreach ($comparacion as $c): ?>
                <h3>Tabla <?= e($c['tabla']) ?></h3>
                <div class="tabla-scroll">
                    <table class="tabla">
                        <tr><th>Columna</th><th>Antes</th><th>Después</th></tr>
                        <?php foreach ($c['filas'] as $f): ?>
                            <tr>
                                <td><?= e($f['col']) ?></td>
                                <td><?= e($f['antes']) ?></td>
                                <td class="<?= $f['cambio'] ? 'cambio' : '' ?>"><?= e($f['despues']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php if ($c['rowsA'] > 0 && $c['rowsD'] !== $c['rowsA']): ?>
                    <p>Filas estimadas: de <?= number_format($c['rowsA']) ?> a <?= number_format($c['rowsD']) ?>
                        (<?= number_format(100 * ($c['rowsD'] - $c['rowsA']) / $c['rowsA'], 1) ?> %).</p>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($lecturaMejora): ?>
                <h3>Interpretación de la mejora</h3>
                <ul>
                    <?php foreach ($lecturaMejora as $l): ?><li><?= e($l) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h3>Equivalencia del resultado</h3>
            <table class="tabla">
                <tr><th></th><th>Antes</th><th>Después</th></tr>
                <tr><td>Filas</td><td><?= e($guardado['resultado']['filas']) ?></td><td><?= e($resultado['filas']) ?></td></tr>
                <tr><td>Suma de montos</td>
                    <td>₡<?= number_format((float)$guardado['resultado']['total'], 2) ?></td>
                    <td>₡<?= number_format((float)$resultado['total'], 2) ?></td></tr>
            </table>
            <?php if ($equivalente): ?>
                <p class="ok">✔ Resultado equivalente: la consulta devuelve lo mismo antes y después del cambio.</p>
            <?php else: ?>
                <p class="mal">✘ El resultado no coincide: el cambio alteró los datos que devuelve la consulta.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- La interpretación -->
    <div class="caja interp">
        <h2>Interpretación del plan</h2>
        <p class="nota">MariaDB lee las tablas en el orden en que aparecen en el plan (de arriba hacia abajo).</p>
        <?php foreach ($interpretacion as $i => $p): ?>
            <h3><?= $i + 1 ?>. Tabla <?= e($p['tabla']) ?></h3>
            <ul>
                <?php foreach ($p['lineas'] as $linea): ?><li><?= e($linea) ?></li><?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php if (count($plan) > 1): ?>
            <p class="nota">En un JOIN las filas estimadas se multiplican: MariaDB estima revisar unas
                <?= number_format($estimadoTotal) ?> combinaciones en total. El resultado real fue de <?= e($resultado['filas']) ?> filas.</p>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
