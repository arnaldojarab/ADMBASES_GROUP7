<?php
/*
 * Módulo 05 - Importar y exportar CSV (parte 5: exportar, importar y eliminar)
 *
 * Exportar: el usuario elige una tabla de la base y sus columnas, ve una vista
 * previa y descarga el CSV. Las tablas y columnas salen de information_schema
 * (lista permitida); nunca se recibe SQL desde el navegador.
 *
 * Importar: el usuario sube un CSV; el módulo lo lee, propone nombres y tipos
 * para cada columna y muestra qué valores no calzan. Lo leído se guarda en un
 * archivo privado (~/dba_csv, 600) mientras el usuario lo revisa. Al confirmar,
 * se crea la tabla (con un nombre que no exista) y se insertan las filas en una
 * transacción; si algo falla se deshace todo (ROLLBACK) y se borra la tabla nueva.
 *
 * Eliminar: solo se pueden borrar las tablas que este módulo creó. Esas tablas
 * se anotan en un registro privado (~/dba_csv/importadas.json, 600).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
date_default_timezone_set('America/Costa_Rica');

// ---------- 1. Rutas privadas y bitácora ----------
$dirCsv = $CONFIG['csv_dir'] ?? "$homeCuenta/dba_csv";   // ~/dba_csv (700)
$logCsv = "$dirCsv/csv.log";                             // bitácora (600)
$registro = "$dirCsv/importadas.json";                   // tablas creadas por el módulo (600)

function bitacora(string $log, string $accion, string $detalle, string $estado): void
{
    $nuevo = !file_exists($log);
    $linea = date('Y-m-d H:i:s') . " | $accion | $detalle | $estado" . PHP_EOL;
    if (file_put_contents($log, $linea, FILE_APPEND) !== false && $nuevo) {
        chmod($log, 0600);
    }
}

// Registro de tablas importadas: ['tabla' => ['archivo' => ..., 'filas' => ..., 'fecha' => ...]]
function leerRegistro(string $ruta): array
{
    $datos = is_readable($ruta) ? json_decode(file_get_contents($ruta), true) : null;
    return is_array($datos) ? $datos : [];
}

function guardarRegistro(string $ruta, array $datos): void
{
    file_put_contents($ruta, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    chmod($ruta, 0600);
}

// Escapa un identificador (tabla o columna) que ya salió de la lista permitida
function ident(string $nombre): string
{
    return '`' . str_replace('`', '``', $nombre) . '`';
}

// ---------- Funciones de la importación ----------
const MAX_BYTES = 2 * 1024 * 1024;   // 2 MB, igual que upload_max_filesize
const MAX_FILAS = 5000;
const TIPOS = ['INT', 'BIGINT', 'DECIMAL(15,2)', 'DECIMAL(18,6)', 'DATE', 'VARCHAR(50)', 'VARCHAR(100)', 'VARCHAR(255)', 'TEXT'];

// "Number of employees" -> "number_of_employees"; "Año" -> "ano"
function normalizarNombre(string $s): string
{
    $s = strtr(mb_strtolower(trim($s), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    if ($s === '') {
        $s = 'columna';
    }
    if (ctype_digit($s[0])) {
        $s = 'c_' . $s;
    }
    return substr($s, 0, 40);
}

// ¿El texto del CSV se puede guardar en una columna de ese tipo? (vacío = NULL)
function valorValido(string $v, string $tipo): bool
{
    if ($v === '') {
        return true;
    }
    switch ($tipo) {
        case 'INT':           return preg_match('/^-?\d{1,10}$/', $v) && abs((int)$v) <= 2147483647;
        case 'BIGINT':        return (bool)preg_match('/^-?\d{1,18}$/', $v);
        case 'DECIMAL(15,2)': return (bool)preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $v);
        case 'DECIMAL(18,6)': return (bool)preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', $v);
        case 'DATE':          return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
        case 'VARCHAR(50)':   return mb_strlen($v, 'UTF-8') <= 50;
        case 'VARCHAR(100)':  return mb_strlen($v, 'UTF-8') <= 100;
        case 'VARCHAR(255)':  return mb_strlen($v, 'UTF-8') <= 255;
        case 'TEXT':          return strlen($v) <= 65535;
    }
    return false;
}

// Propone el tipo más ajustado que acepta todos los valores de la columna
function proponerTipo(array $valores): string
{
    $llenos = array_values(array_filter($valores, fn($v) => $v !== ''));
    if (!$llenos) {
        return 'VARCHAR(50)';
    }
    foreach (TIPOS as $tipo) {
        $sirve = true;
        foreach ($llenos as $v) {
            if (!valorValido($v, $tipo)) { $sirve = false; break; }
        }
        if ($sirve) {
            return $tipo;
        }
    }
    return 'TEXT';
}

// Lee el CSV: quita el BOM, pasa a UTF-8 si hace falta y detecta coma o punto y coma
function leerCsv(string $ruta): array
{
    $texto = file_get_contents($ruta);
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $primera = strtok($texto, "\n");
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';

    $h = fopen('php://temp', 'r+');
    fwrite($h, $texto);
    rewind($h);
    $filas = [];
    $errores = [];
    $linea = 0;
    while (($fila = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        $linea++;
        if ($fila === [null] || $fila === ['']) {
            continue;                                   // línea vacía
        }
        $fila = array_map(fn($v) => trim((string)$v), $fila);
        if ($filas && count($fila) !== count($filas[0]) && count($errores) < 5) {
            $errores[] = "la línea $linea tiene " . count($fila) . ' columnas y el encabezado ' . count($filas[0]);
        }
        $filas[] = $fila;
        if (count($filas) > MAX_FILAS + 1) {
            $errores[] = 'el archivo pasa de ' . MAX_FILAS . ' filas';
            break;
        }
    }
    fclose($h);
    if (count($filas) < 2) {
        $errores[] = 'el archivo no tiene encabezado y al menos una fila de datos';
    }
    return [$sep, $filas, $errores];
}

// SQL de la tabla nueva (los nombres ya están normalizados y los tipos salen de TIPOS)
function sqlCrear(string $tabla, array $columnas, int $pk): string
{
    $defs = [];
    foreach ($columnas as $k => $c) {
        $defs[] = '    ' . ident($c['nombre']) . ' ' . $c['tipo'] . ($k === $pk ? ' NOT NULL' : ' NULL');
    }
    if ($pk >= 0) {
        $defs[] = '    PRIMARY KEY (' . ident($columnas[$pk]['nombre']) . ')';
    }
    return 'CREATE TABLE ' . ident($tabla) . " (\n" . implode(",\n", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$tab = ($_GET['tab'] ?? 'exportar') === 'importar' ? 'importar' : 'exportar';
$SEPARADORES = ['coma' => ',', 'puntoycoma' => ';'];
$error = '';

// Lo leído del CSV mientras se revisa: archivo privado por sesión (~/dba_csv, 600)
$pendiente = $dirCsv . '/pendiente_' . hash('sha256', session_id()) . '.json';
$imp = is_readable($pendiente) ? json_decode(file_get_contents($pendiente), true) : null;

// Limpieza: borra revisiones abandonadas hace más de un día (sesiones que nunca terminaron)
foreach (glob("$dirCsv/pendiente_*.json") ?: [] as $viejo) {
    if (filemtime($viejo) < time() - 86400) {
        unlink($viejo);
    }
}

function guardarPendiente(string $ruta, array $datos): void
{
    file_put_contents($ruta, json_encode($datos, JSON_UNESCAPED_UNICODE));
    chmod($ruta, 0600);
}

// Revisa lo leído: valores que no calzan, nombres repetidos y llave primaria
function revisarImp(array $imp): array
{
    $tabla = $imp['tabla'];
    $problemas = [];
    $invalidos = [];
    foreach ($imp['columnas'] as $k => $c) {
        $vals = array_column($imp['filas'], $k);
        $invalidos[$k] = count(array_filter($vals, fn($v) => !valorValido((string)$v, $c['tipo'])));
        if ($invalidos[$k] > 0) {
            $problemas[] = "la columna {$c['nombre']} tiene {$invalidos[$k]} valores que no calzan con {$c['tipo']}";
        }
    }
    $nombres = array_column($imp['columnas'], 'nombre');
    if (count(array_unique($nombres)) !== count($nombres)) {
        $problemas[] = 'hay dos columnas con el mismo nombre';
    }
    if ($imp['pk'] >= 0) {
        $vals = array_column($imp['filas'], $imp['pk']);
        if (in_array('', $vals, true) || count(array_unique($vals)) !== count($vals)) {
            $problemas[] = 'la llave primaria tiene valores vacíos o repetidos';
        }
    }
    return ['tabla' => $tabla, 'problemas' => $problemas, 'invalidos' => $invalidos,
            'sql' => sqlCrear($tabla, $imp['columnas'], $imp['pk'])];
}

// ¿Ya existe una tabla con ese nombre en la base?
function tablaExiste(PDO $pdo, string $tabla): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$tabla]);
    return $st->fetchColumn() > 0;
}

// ---------- Acciones de importación (POST + CSRF) ----------
$accion = $_POST['accion'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['analizar', 'revisar', 'cancelar', 'importar', 'eliminar'], true)) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['mensaje'] = 'ERROR: solicitud no válida.';
        bitacora($logCsv, 'importar', "$accion: solicitud rechazada (token CSRF)", 'ERROR');
    } elseif ($accion === 'cancelar') {
        if (is_file($pendiente)) {
            unlink($pendiente);
            bitacora($logCsv, 'importar', 'cancelar ' . ($imp['archivo'] ?? ''), 'OK');
        }
    } elseif ($accion === 'analizar') {
        $f = $_FILES['archivo'] ?? null;
        $nombreArchivo = basename((string)($f['name'] ?? ''));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $_SESSION['mensaje'] = 'ERROR: no se recibió el archivo (máximo 2 MB).';
        } elseif ($f['size'] > MAX_BYTES) {
            $_SESSION['mensaje'] = 'ERROR: el archivo pasa de 2 MB.';
        } elseif (strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)) !== 'csv') {
            $_SESSION['mensaje'] = 'ERROR: el archivo debe tener extensión .csv.';
        } else {
            [$sep, $filas, $errores] = leerCsv($f['tmp_name']);
            if ($errores) {
                $_SESSION['mensaje'] = 'ERROR: ' . implode('; ', $errores) . '.';
            } else {
                $encabezado = array_shift($filas);
                $columnas = [];
                $usados = [];
                foreach ($encabezado as $k => $orig) {
                    $nombre = normalizarNombre($orig);
                    while (in_array($nombre, $usados, true)) {
                        $nombre .= '_' . ($k + 1);              // nombres repetidos
                    }
                    $usados[] = $nombre;
                    $columnas[] = ['orig' => $orig, 'nombre' => $nombre, 'tipo' => proponerTipo(array_column($filas, $k))];
                }
                // Llave primaria sugerida: la primera columna, si es entera y sin vacíos ni repetidos
                $vals = array_column($filas, 0);
                $pk = (in_array($columnas[0]['tipo'], ['INT', 'BIGINT'], true) && !in_array('', $vals, true)
                       && count(array_unique($vals)) === count($vals)) ? 0 : -1;
                guardarPendiente($pendiente, [
                    'archivo' => $nombreArchivo, 'sep' => $sep, 'filas' => $filas, 'columnas' => $columnas, 'pk' => $pk,
                    'tabla' => normalizarNombre(pathinfo($nombreArchivo, PATHINFO_FILENAME)),
                ]);
                bitacora($logCsv, 'importar', "analizar $nombreArchivo: " . count($filas) . ' filas, ' . count($columnas) . ' columnas', 'OK');
            }
        }
        if (!empty($_SESSION['mensaje'])) {
            bitacora($logCsv, 'importar', "analizar $nombreArchivo: " . substr($_SESSION['mensaje'], 7), 'ERROR');
        }
    } elseif ($accion === 'revisar' && $imp) {
        // Cambios del usuario: nombres normalizados, tipos de la lista y llave válida
        $imp['tabla'] = normalizarNombre((string)($_POST['tabla'] ?? $imp['tabla']));
        foreach ($imp['columnas'] as $k => &$c) {
            $c['nombre'] = normalizarNombre((string)($_POST['nombres'][$k] ?? $c['nombre']));
            $tipo = (string)($_POST['tipos'][$k] ?? $c['tipo']);
            $c['tipo'] = in_array($tipo, TIPOS, true) ? $tipo : $c['tipo'];
        }
        unset($c);
        $pk = (int)($_POST['pk'] ?? -1);
        $imp['pk'] = ($pk >= 0 && $pk < count($imp['columnas'])) ? $pk : -1;
        guardarPendiente($pendiente, $imp);
    } elseif ($accion === 'importar' && $imp) {
        // Se vuelve a revisar todo en el servidor: no se confía en lo que mostró la página
        $r = revisarImp($imp);
        $pdo = null;
        $creada = false;
        $numFila = 0;
        try {
            $pdo = db();
            if (tablaExiste($pdo, $r['tabla'])) {
                $r['problemas'][] = "ya existe una tabla llamada {$r['tabla']}";
            }
            if ($r['problemas']) {
                $_SESSION['mensaje'] = 'ERROR: ' . implode('; ', $r['problemas']) . '.';
            } else {
                // Modo estricto: si un valor no calza, MariaDB da error en vez de recortarlo
                $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES')");
                $pdo->exec($r['sql']);              // CREATE TABLE hace commit implícito: no entra en el ROLLBACK
                $creada = true;

                $cols = array_map(fn($c) => ident($c['nombre']), $imp['columnas']);
                $marcas = implode(', ', array_fill(0, count($cols), '?'));
                $ins = $pdo->prepare('INSERT INTO ' . ident($r['tabla']) . ' (' . implode(', ', $cols) . ") VALUES ($marcas)");

                $pdo->beginTransaction();           // todas las filas o ninguna
                foreach ($imp['filas'] as $i => $fila) {
                    $numFila = $i + 2;              // +1 por el encabezado y +1 porque empieza en 0
                    $ins->execute(array_map(fn($v) => $v === '' ? null : $v, $fila));
                }
                $pdo->commit();

                $n = (int)$pdo->query('SELECT COUNT(*) FROM ' . ident($r['tabla']))->fetchColumn();
                unlink($pendiente);
                $reg = leerRegistro($registro);     // se anota: solo estas tablas se pueden eliminar
                $reg[$r['tabla']] = ['archivo' => $imp['archivo'], 'filas' => $n, 'fecha' => date('Y-m-d H:i')];
                guardarRegistro($registro, $reg);
                $_SESSION['ok'] = "Se creó la tabla {$r['tabla']} con " . number_format($n) . " filas.";
                $_SESSION['ok_tabla'] = $r['tabla'];
                bitacora($logCsv, 'importar', "{$imp['archivo']} -> {$r['tabla']} (" . count($cols) . " columnas, $n filas)", 'OK');
            }
        } catch (Throwable $ex) {
            error_log('csv.php importar: ' . $ex->getMessage());
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();                   // deshace las filas insertadas
            }
            if ($pdo && $creada) {
                try {
                    $pdo->exec('DROP TABLE IF EXISTS ' . ident($r['tabla']));   // y borra la tabla nueva
                } catch (Throwable $ex2) {
                    error_log('csv.php drop: ' . $ex2->getMessage());
                }
            }
            $_SESSION['mensaje'] = $numFila > 0
                ? "ERROR: la línea $numFila del archivo no se pudo guardar (dato no válido o llave repetida). Se deshizo todo y no se creó la tabla."
                : 'ERROR: no se pudo crear la tabla. No se guardó nada.';
        }
        if (!empty($_SESSION['mensaje'])) {
            bitacora($logCsv, 'importar', "{$imp['archivo']} -> {$r['tabla']}: " . substr($_SESSION['mensaje'], 7), 'ERROR');
        }
    } elseif ($accion === 'eliminar') {
        // Solo se borra si la tabla está en el registro del módulo Y existe en la base
        $borrar = (string)($_POST['tabla'] ?? '');
        $reg = leerRegistro($registro);
        try {
            $pdo = db();
            if (!array_key_exists($borrar, $reg) || !tablaExiste($pdo, $borrar)) {
                $_SESSION['mensaje'] = 'ERROR: solo se pueden eliminar tablas creadas por este módulo.';
            } else {
                $pdo->exec('DROP TABLE ' . ident($borrar));
                unset($reg[$borrar]);
                guardarRegistro($registro, $reg);
                $_SESSION['ok'] = "Se eliminó la tabla $borrar.";
                bitacora($logCsv, 'eliminar', $borrar, 'OK');
            }
        } catch (Throwable $ex) {
            error_log('csv.php eliminar: ' . $ex->getMessage());
            $_SESSION['mensaje'] = 'ERROR: no se pudo eliminar la tabla.';
        }
        if (!empty($_SESSION['mensaje'])) {
            bitacora($logCsv, 'eliminar', "$borrar: " . substr($_SESSION['mensaje'], 7), 'ERROR');
        }
    }
    header('Location: csv.php?tab=importar');   // PRG: recargar no repite la acción
    exit;
}

// ---------- Revisión de lo leído (se recalcula en cada visita) ----------
$rev = $imp ? revisarImp($imp) : null;
$importadas = [];

try {
    $pdo = db();
    if ($rev && tablaExiste($pdo, $rev['tabla'])) {
        $rev['problemas'][] = "ya existe una tabla llamada {$rev['tabla']}";
    }

    // ---------- 2. Lista permitida de tablas: las de la base propia ----------
    $tablas = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                           ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Tablas importadas que todavía existen (si alguien borró una a mano, sale del registro)
    $reg = leerRegistro($registro);
    $importadas = array_intersect_key($reg, $tablas);
    if (count($importadas) !== count($reg)) {
        guardarRegistro($registro, $importadas);
    }

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
$ok = $_SESSION['ok'] ?? '';
$okTabla = $_SESSION['ok_tabla'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['ok'], $_SESSION['ok_tabla']);

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
    .aviso { padding: 10px 14px; border-radius: 8px; background: #dcfce7; color: #166534; }
    .tabla td.malo { background: #fee2e2; color: #991b1b; font-weight: bold; }
    .tabla input[type=text] { padding: 6px 8px; border: 1px solid #d6ddeb; border-radius: 6px; width: 190px; }
    .tabla select { padding: 6px 8px; border: 1px solid #d6ddeb; border-radius: 6px; }
    .prefijo { display: inline-flex; align-items: center; gap: 4px; font-family: monospace; font-size: 15px; }
    .prefijo input { padding: 8px 10px; border: 1px solid #d6ddeb; border-radius: 6px; font-size: 15px; width: 240px; }
    .botones button:disabled { background: #9aa3b5; cursor: not-allowed; }
    .rojo { color: #991b1b; font-weight: bold; }
    ul.problemas { margin: 6px 0 0; color: #991b1b; }
    /* Ventana de confirmación propia (reemplaza el confirm() del navegador) */
    dialog.confirmar { border: 0; border-radius: 12px; padding: 0; width: min(440px, 92vw); box-shadow: 0 20px 50px rgba(10, 26, 69, .35); }
    dialog.confirmar::backdrop { background: rgba(10, 26, 69, .45); }
    dialog.confirmar .cab { background: #0e235a; color: #fff; padding: 14px 20px; font-weight: bold; font-size: 16px; display: flex; align-items: center; gap: 10px; }
    dialog.confirmar .cab img { height: 28px; background: #fff; border-radius: 6px; padding: 2px; }
    dialog.confirmar p { margin: 0; padding: 20px 20px 6px; color: #1e2433; font-size: 15px; line-height: 1.5; }
    dialog.confirmar .botones { padding: 10px 20px 20px; margin: 0; justify-content: flex-end; }
    dialog.confirmar.peligro .cab { background: #991b1b; }
    dialog.confirmar.peligro #conf-si { background: #dc2626; }
    .tabla button.peligro { padding: 6px 12px; border: 0; border-radius: 6px; background: #dc2626; color: #fff; font-weight: bold; cursor: pointer; }
</style>

<h1>Módulo 05: Importar y exportar CSV</h1>

<div class="pestanas">
    <a href="?tab=exportar" class="<?= $tab === 'exportar' ? 'sel' : '' ?>">Exportar</a>
    <a href="?tab=importar" class="<?= $tab === 'importar' ? 'sel' : '' ?>">Importar</a>
</div>

<?php if ($mensaje !== ''): ?><p class="mensaje"><?= e($mensaje) ?></p><?php endif; ?>
<?php if ($ok !== ''): ?>
    <p class="aviso"><?= e($ok) ?>
        <?php if ($okTabla !== ''): ?><a href="?tab=exportar&amp;tabla=<?= e(urlencode($okTabla)) ?>">Verla en Exportar</a><?php endif; ?></p>
<?php endif; ?>

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

<?php elseif (!$imp): ?>
    <!-- Importar, paso 1: subir el archivo -->
    <form method="post" action="csv.php?tab=importar" enctype="multipart/form-data" class="caja">
        <h2>1. Subir el archivo CSV</h2>
        <p class="nota">Archivo .csv en UTF-8 o Windows-1252, separado por coma o punto y coma, con encabezado en la primera fila. Máximo 2 MB y <?= number_format(MAX_FILAS) ?> filas.</p>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="analizar">
        <div class="campo"><input type="file" name="archivo" accept=".csv,text/csv" required></div>
        <div class="botones"><button type="submit">Analizar archivo</button></div>
    </form>

    <!-- Tablas que este módulo creó: son las únicas que se pueden eliminar desde aquí -->
    <div class="caja">
        <h2>Tablas creadas desde CSV</h2>
        <?php if (!$importadas): ?>
            <p class="nota">Todavía no se ha importado ninguna tabla.</p>
        <?php else: ?>
            <p class="nota">Solo aparecen las tablas creadas por este módulo. Las demás tablas de la base no se pueden borrar desde aquí.</p>
            <div class="tabla-scroll">
                <table class="tabla">
                    <tr><th>Tabla</th><th>Archivo de origen</th><th>Filas importadas</th><th>Fecha</th><th></th></tr>
                    <?php foreach ($importadas as $nombre => $d): ?>
                        <tr>
                            <td><a href="?tab=exportar&amp;tabla=<?= e(urlencode($nombre)) ?>"><?= e($nombre) ?></a></td>
                            <td><?= e($d['archivo'] ?? '') ?></td>
                            <td><?= number_format((int)($d['filas'] ?? 0)) ?></td>
                            <td><?= e($d['fecha'] ?? '') ?></td>
                            <td>
                                <form method="post" action="csv.php?tab=importar" data-titulo="Eliminar tabla" data-boton="Eliminar" data-peligro="1"
                                      data-confirmar="Se va a eliminar la tabla <?= e($nombre) ?> con todos sus datos. Esta acción no se puede deshacer.">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="tabla" value="<?= e($nombre) ?>">
                                    <button type="submit" class="peligro">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- Importar, paso 2: revisar la estructura propuesta -->
    <div class="caja">
        <h2>2. Revisar la tabla que se va a crear</h2>
        <p class="nota">Archivo <strong><?= e($imp['archivo']) ?></strong>: <?= number_format(count($imp['filas'])) ?> filas,
            <?= count($imp['columnas']) ?> columnas, separador detectado <strong><?= $imp['sep'] === ';' ? 'punto y coma' : 'coma' ?></strong>.</p>
        <form method="post" action="csv.php?tab=importar">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="revisar">
            <div class="campo">
                <label class="titulo">Nombre de la tabla nueva</label>
                <span class="prefijo"><input type="text" name="tabla" value="<?= e($imp['tabla']) ?>" maxlength="40"></span>
                <p class="nota">Debe ser un nombre que no exista en la base; las tablas existentes nunca se sobrescriben.</p>
            </div>
            <div class="tabla-scroll">
                <table class="tabla">
                    <tr><th>Columna del CSV</th><th>Nombre en la tabla</th><th>Tipo</th><th>Llave primaria</th><th>Valores que no calzan</th></tr>
                    <?php foreach ($imp['columnas'] as $k => $c): ?>
                        <tr>
                            <td><?= e($c['orig']) ?></td>
                            <td><input type="text" name="nombres[<?= $k ?>]" value="<?= e($c['nombre']) ?>" maxlength="40"></td>
                            <td><select name="tipos[<?= $k ?>]">
                                <?php foreach (TIPOS as $t): ?><option <?= $t === $c['tipo'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                            </select></td>
                            <td><input type="radio" name="pk" value="<?= $k ?>" <?= $imp['pk'] === $k ? 'checked' : '' ?>></td>
                            <td class="<?= $rev['invalidos'][$k] ? 'malo' : '' ?>"><?= $rev['invalidos'][$k] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr><td colspan="3" class="nota">Sin llave primaria</td><td><input type="radio" name="pk" value="-1" <?= $imp['pk'] === -1 ? 'checked' : '' ?>></td><td></td></tr>
                </table>
            </div>
            <div class="botones"><button type="submit" class="sec">Aplicar cambios</button></div>
        </form>
        <form method="post" action="csv.php?tab=importar">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="cancelar">
            <div class="botones"><button type="submit" class="sec">Cancelar e importar otro archivo</button></div>
        </form>
    </div>

    <div class="caja">
        <h2>3. Vista previa (primeras 10 filas)</h2>
        <p class="nota">En rojo, los valores que no se pueden guardar con el tipo elegido. Un valor vacío se guarda como NULL.</p>
        <div class="tabla-scroll">
            <table class="tabla">
                <tr><?php foreach ($imp['columnas'] as $c): ?><th><?= e($c['nombre']) ?></th><?php endforeach; ?></tr>
                <?php foreach (array_slice($imp['filas'], 0, 10) as $fila): ?>
                    <tr><?php foreach ($imp['columnas'] as $k => $c): $v = (string)($fila[$k] ?? ''); ?>
                        <td class="<?= valorValido($v, $c['tipo']) ? '' : 'malo' ?>"><?= $v === '' ? '<em>NULL</em>' : e($v) ?></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="caja">
        <h2>4. Crear la tabla</h2>
        <p class="nota">Sentencia que se ejecutará:</p>
        <pre><?= e($rev['sql']) ?></pre>
        <?php if ($rev['problemas']): ?>
            <p class="rojo">Hay que corregir antes de crear la tabla:</p>
            <ul class="problemas"><?php foreach ($rev['problemas'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
            <p class="aviso">Todo calza: se puede crear <strong><?= e($rev['tabla']) ?></strong> con <?= number_format(count($imp['filas'])) ?> filas.</p>
        <?php endif; ?>
        <?php if ($rev['problemas']): ?>
            <div class="botones"><button type="button" disabled>Crear tabla e importar</button></div>
        <?php else: ?>
            <form method="post" action="csv.php?tab=importar" data-titulo="Crear tabla" data-boton="Crear e importar"
                  data-confirmar="Se va a crear la tabla <?= e($rev['tabla']) ?> e insertar <?= number_format(count($imp['filas'])) ?> filas. ¿Desea continuar?">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="accion" value="importar">
                <div class="botones"><button type="submit">Crear tabla e importar <?= number_format(count($imp['filas'])) ?> filas</button></div>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Ventana de confirmación: la usan los formularios que tienen data-confirmar -->
<dialog id="confirmar" class="confirmar">
    <div class="cab"><img src="assets/logo_dask.png" alt=""><span id="conf-titulo">Confirmar</span></div>
    <p id="conf-texto"></p>
    <div class="botones">
        <button type="button" class="sec" id="conf-no">Cancelar</button>
        <button type="button" id="conf-si">Aceptar</button>
    </div>
</dialog>
<script>
(function () {
    const dlg = document.getElementById('confirmar');
    let formulario = null;
    // Al enviar un formulario con data-confirmar, primero se muestra la ventana
    document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            ev.preventDefault();
            formulario = f;
            document.getElementById('conf-titulo').textContent = f.dataset.titulo || 'Confirmar';
            document.getElementById('conf-texto').textContent = f.dataset.confirmar;
            document.getElementById('conf-si').textContent = f.dataset.boton || 'Aceptar';
            dlg.classList.toggle('peligro', f.dataset.peligro === '1');
            dlg.showModal();
        });
    });
    document.getElementById('conf-no').addEventListener('click', function () { formulario = null; dlg.close(); });
    document.getElementById('conf-si').addEventListener('click', function () {
        dlg.close();
        if (formulario) { formulario.submit(); }   // submit() envía sin volver a pasar por esta ventana
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
