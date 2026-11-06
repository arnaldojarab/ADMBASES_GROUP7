<?php
/*
 * Módulo 05 - Editor de consultas SQL (MariaDB 10.11)
 *
 * - Permite escribir una consulta propia y ejecutarla.
 * - Lectura: SELECT, SHOW, DESCRIBE, EXPLAIN y ANALYZE SELECT.
 * - Plan: EXPLAIN (estimado) y ANALYZE (real, ejecuta la consulta).
 * - Límites: SET STATEMENT max_statement_time=5 FOR <consulta>
 *            y LIMIT automático cuando el SELECT no trae uno propio.
 * - Modificaciones: INSERT, UPDATE y DELETE solo en "modo de prueba":
 *            START TRANSACTION -> sentencia -> ROLLBACK (nunca COMMIT).
 * - Historial: cada intento queda en una bitácora JSONL privada,
 *            fuera de public_html.
 *
 * - Ayudas para escribir: autocompletado de tablas y columnas con JavaScript
 *            y un borrador que se conserva al cambiar de módulo (sessionStorage).
 *
 * Configuración reutilizada desde includes/auth.php ($CONFIG).
 * Claves opcionales de $CONFIG:
 *   'db_editor'    => [...]  cuenta MariaDB propia para este módulo
 *                            (si no existe, se usa $CONFIG['db']).
 *   'log_consultas'=> ruta   archivo del historial
 *                            (si no existe, <padre de respaldos_dir>/consultas/historial.jsonl).
 */

require_once __DIR__ . '/includes/auth.php';

date_default_timezone_set('America/Costa_Rica');

/* =========================================================
   1. CONFIGURACIÓN, CONSTANTES Y UTILIDADES
   ========================================================= */

const ED_MAX_FILAS      = 200;    // filas que se muestran como máximo
const ED_MAX_SEGUNDOS   = 5;      // tiempo máximo por sentencia
const ED_MAX_CARACTERES = 2000;   // largo máximo de la consulta

$cfg = $CONFIG['db_editor'] ?? $CONFIG['db'];

$host = $cfg['host'] ?? 'localhost';
$port = (int)($cfg['port'] ?? 3306);
$bd   = $cfg['name'] ?? '';
$user = $cfg['user'] ?? $cfg['usuario'] ?? '';
$pass = $cfg['pass'] ?? $cfg['password'] ?? $cfg['clave'] ?? '';

$logConsultas = $CONFIG['log_consultas']
    ?? (dirname($CONFIG['respaldos_dir']) . '/consultas/historial.jsonl');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Qué significa cada tipo de acceso del plan (columna "type"). */
const ED_TIPOS = [
    'system' => 'la tabla tiene una sola fila',
    'const'  => 'a lo sumo una fila, por llave primaria o UNIQUE',
    'eq_ref' => 'una fila por cada fila anterior, por llave primaria o UNIQUE',
    'ref'    => 'búsqueda por valor en un índice',
    'range'  => 'recorre solo un rango dentro de un índice',
    'index'  => 'recorre el índice completo',
    'ALL'    => 'recorrido completo: lee todas las filas de la tabla',
];

/* Avisos de la columna "Extra": patrón => explicación. */
const ED_EXTRAS = [
    '/Using where/'                => 'aplica el filtro WHERE sobre las filas que lee',
    '/Using filesort/'             => 'ordena el resultado aparte, ningún índice le da el orden',
    '/Using temporary/'            => 'usa una tabla temporal',
    '/Using index condition/'      => 'evalúa el filtro dentro del índice',
    '/Using index(?! condition)/'  => 'obtiene todo desde el índice, sin leer la tabla',
];

function ed_e($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
 * Celda de resultado ya escapada: NULL se distingue de un texto vacío,
 * lo binario se resume y los textos muy largos se recortan.
 */
function ed_celda($valor): string
{
    if ($valor === null) {
        return '<em class="ed-null">NULL</em>';
    }

    $texto = (string)$valor;

    if (!mb_check_encoding($texto, 'UTF-8')) {
        return '<em class="ed-null">[binario, ' . strlen($texto) . ' bytes]</em>';
    }

    if (mb_strlen($texto) > 4000) {
        $texto = mb_substr($texto, 0, 4000) . '…';
    }

    return ed_e($texto);
}

function ed_ms(?float $ms): string
{
    return $ms === null ? 'No disponible' : number_format($ms, 2, ',', '.') . ' ms';
}

/*
 * Bitácora JSONL: una entrada JSON por línea, fuera del directorio público.
 */
function ed_registrar(string $ruta, array $evento): bool
{
    $dir = dirname($ruta);

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('Módulo 05: no se pudo crear el directorio de la bitácora.');
        return false;
    }

    $archivo = @fopen($ruta, 'ab');

    if ($archivo === false) {
        error_log('Módulo 05: no se pudo abrir la bitácora.');
        return false;
    }

    $ok = false;

    if (flock($archivo, LOCK_EX)) {
        $json = json_encode(
            $evento,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json !== false) {
            $ok = fwrite($archivo, $json . PHP_EOL) !== false;
            fflush($archivo);
            @chmod($ruta, 0600);
        }

        flock($archivo, LOCK_UN);
    }

    fclose($archivo);

    if (!$ok) {
        error_log('Módulo 05: no se pudo completar la escritura de bitácora.');
    }

    return $ok;
}

/*
 * Lee del final del archivo (últimos 256 KB) para no cargar un historial
 * enorme. Devuelve las últimas $max entradas, la más reciente primero,
 * o solo la entrada con el id indicado.
 */
function ed_leer_historial(string $ruta, int $max = 10, ?string $idBuscado = null): array
{
    if (!is_readable($ruta)) {
        return [];
    }

    $tam = (int)@filesize($ruta);
    $fh  = @fopen($ruta, 'rb');

    if ($fh === false) {
        return [];
    }

    $inicio = max(0, $tam - 262144);
    fseek($fh, $inicio);
    $texto = (string)stream_get_contents($fh);
    fclose($fh);

    $lineas = explode("\n", $texto);

    if ($inicio > 0) {
        array_shift($lineas); // primera línea posiblemente cortada
    }

    $resultado = [];

    foreach (array_reverse($lineas) as $linea) {
        $linea = trim($linea);

        if ($linea === '') {
            continue;
        }

        $evento = json_decode($linea, true);

        if (!is_array($evento)) {
            continue;
        }

        if ($idBuscado !== null) {
            if (($evento['id'] ?? '') === $idBuscado) {
                return [$evento];
            }
            continue;
        }

        $resultado[] = $evento;

        if (count($resultado) >= $max) {
            break;
        }
    }

    return $resultado;
}

/* =========================================================
   2. ANÁLISIS Y VALIDACIÓN DE LA CONSULTA
   ========================================================= */

function ed_error(string $mensaje): array
{
    return ['ok' => false, 'mensaje' => $mensaje];
}

/*
 * Recorre el texto carácter a carácter y devuelve:
 *   limpio     : la consulta sin comentarios y sin el ';' final
 *   mascara    : igual, pero con el contenido de los textos '...' y "..." vacío
 *   mascara_id : igual, pero además con el contenido de `identificadores` vacío
 * Las revisiones de palabras prohibidas se hacen sobre las máscaras, así una
 * palabra dentro de un texto ('... INTO ...') no genera falsos rechazos.
 *
 * Rechaza más de una sentencia y los comentarios ejecutables de MariaDB
 * (/*! ... *\/ y /*M! ... *\/). Además, la conexión se abre con
 * MYSQL_ATTR_MULTI_STATEMENTS = false, como segunda barrera.
 */
function ed_analizar(string $sql): array
{
    $n = strlen($sql);
    $limpio = $mascara = $mascaraId = '';
    $puntoComa = false;
    $i = 0;

    while ($i < $n) {
        $c  = $sql[$i];
        $c2 = $sql[$i + 1] ?? '';

        // Comentario "# ..." hasta el fin de línea.
        if ($c === '#') {
            $fin = strpos($sql, "\n", $i);
            $i = $fin === false ? $n : $fin + 1;
            $limpio .= ' '; $mascara .= ' '; $mascaraId .= ' ';
            continue;
        }

        // Comentario "-- ..." (MariaDB exige un espacio o control después).
        if ($c === '-' && $c2 === '-' && ($i + 2 >= $n || ord($sql[$i + 2]) <= 32)) {
            $fin = strpos($sql, "\n", $i);
            $i = $fin === false ? $n : $fin + 1;
            $limpio .= ' '; $mascara .= ' '; $mascaraId .= ' ';
            continue;
        }

        // Comentario "/* ... */" (los ejecutables se rechazan).
        if ($c === '/' && $c2 === '*') {
            $c3 = $sql[$i + 2] ?? '';

            if ($c3 === '!' || ($c3 === 'M' && ($sql[$i + 3] ?? '') === '!')) {
                return ed_error('No se admiten comentarios ejecutables de MariaDB.');
            }

            $fin = strpos($sql, '*/', $i + 2);

            if ($fin === false) {
                return ed_error('Hay un comentario /* sin cerrar.');
            }

            $i = $fin + 2;
            $limpio .= ' '; $mascara .= ' '; $mascaraId .= ' ';
            continue;
        }

        // Punto y coma: solo se tolera al final.
        if ($c === ';') {
            $puntoComa = true;
            $i++;
            continue;
        }

        if ($puntoComa && !ctype_space($c)) {
            return ed_error('Solo se permite una sentencia por ejecución.');
        }

        // Textos e identificadores entre comillas.
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            $cierra = false;

            while ($j < $n) {
                if ($sql[$j] === '\\' && $c !== '`') {
                    $j += 2;
                    continue;
                }

                if ($sql[$j] === $c) {
                    if (($sql[$j + 1] ?? '') === $c) {
                        $j += 2; // comilla doblada dentro del texto
                        continue;
                    }

                    $cierra = true;
                    break;
                }

                $j++;
            }

            if (!$cierra) {
                return ed_error('Hay una comilla sin cerrar.');
            }

            $trozo = substr($sql, $i, $j - $i + 1);
            $limpio .= $trozo;

            if ($c === '`') {
                $mascara   .= $trozo;
                $mascaraId .= '``';
            } else {
                $mascara   .= $c . $c;
                $mascaraId .= $c . $c;
            }

            $i = $j + 1;
            continue;
        }

        $limpio .= $c; $mascara .= $c; $mascaraId .= $c;
        $i++;
    }

    return [
        'ok'         => true,
        'limpio'     => trim($limpio),
        'mascara'    => trim($mascara),
        'mascara_id' => trim($mascaraId),
    ];
}

/*
 * Valida la consulta según el modo:
 *   lectura : SELECT, SHOW, DESCRIBE/DESC, EXPLAIN, ANALYZE SELECT
 *   prueba  : INSERT, UPDATE, DELETE (se revierten con ROLLBACK)
 */
function ed_validar(string $texto, string $modo): array
{
    $texto = trim($texto);

    if ($texto === '') {
        return ed_error('Escriba una consulta.');
    }

    if (mb_strlen($texto) > ED_MAX_CARACTERES) {
        return ed_error('La consulta supera los ' . ED_MAX_CARACTERES . ' caracteres.');
    }

    if (str_contains($texto, "\0") || !mb_check_encoding($texto, 'UTF-8')) {
        return ed_error('La consulta contiene caracteres no válidos.');
    }

    $a = ed_analizar($texto);

    if (!$a['ok']) {
        return $a;
    }

    $limpio = $a['limpio'];
    $mas    = $a['mascara'];
    $masId  = $a['mascara_id'];

    if ($limpio === '') {
        return ed_error('La consulta quedó vacía: solo había comentarios.');
    }

    if (!preg_match('/^([A-Za-z]+)/', $mas, $m)) {
        return ed_error('La consulta debe comenzar con SELECT, SHOW, DESCRIBE, EXPLAIN o ANALYZE.');
    }

    $primera = strtoupper($m[1]);

    if ($primera === 'DESC') {
        $primera = 'DESCRIBE';
    }

    // Los marcadores ? y :nombre los interpretaría PDO, no MariaDB.
    if (str_contains($masId, '?') || preg_match('/:[A-Za-z_]/', $masId)) {
        return ed_error('No se admiten marcadores de parámetros (? o :nombre). Escriba los valores directamente.');
    }

    $lectura = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'ANALYZE'];
    $dml     = ['INSERT', 'UPDATE', 'DELETE'];

    if ($modo === 'prueba') {
        if (!in_array($primera, $dml, true)) {
            return ed_error('El modo de prueba solo acepta INSERT, UPDATE o DELETE.');
        }
    } else {
        if (in_array($primera, $dml, true)) {
            return ed_error('Para ejecutar ' . $primera . ' active «Modificación de prueba (se revierte con ROLLBACK)».');
        }

        if (!in_array($primera, $lectura, true)) {
            return ed_error('Sentencia no permitida. Solo se admiten SELECT, SHOW, DESCRIBE, EXPLAIN y ANALYZE SELECT.');
        }
    }

    if (
        $primera === 'ANALYZE' &&
        !preg_match('/^ANALYZE\s+(FORMAT\s*=\s*JSON\s+)?SELECT\b/i', $mas)
    ) {
        return ed_error('ANALYZE solo se admite como ANALYZE SELECT … (plan real de una consulta).');
    }

    $prohibidos = [
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i'
            => 'Escribir archivos con INTO OUTFILE o INTO DUMPFILE no está permitido.',
        '/\b(FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i'
            => 'Las consultas que bloquean filas no están permitidas.',
        '/\bPROCEDURE\b/i'
            => 'PROCEDURE no está permitido en el editor.',
        '/\b(LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|SYS_EXEC|SYS_EVAL)\s*\(/i'
            => 'Esa función no está permitida en el editor.',
        '/(?<![\w$])`?(mysql|performance_schema|sys)`?\s*\./i'
            => 'No se permite consultar los esquemas internos del servidor (mysql, performance_schema, sys).',
    ];

    foreach ($prohibidos as $patron => $mensaje) {
        if (preg_match($patron, $mas)) {
            return ed_error($mensaje);
        }
    }

    if ($modo !== 'prueba' && preg_match('/\bINTO\b/i', $mas)) {
        return ed_error('INTO no está permitido en las consultas de lectura.');
    }

    return [
        'ok'         => true,
        'tipo'       => $primera,
        'sql'        => $limpio,
        'mascara_id' => $masId,
    ];
}

/*
 * ¿La consulta ya trae un LIMIT en el nivel superior?
 * Los LIMIT dentro de paréntesis (subconsultas) no cuentan.
 */
function ed_tiene_limit_superior(string $mascaraId): bool
{
    preg_match_all('/\(|\)|\bLIMIT\b/i', $mascaraId, $m, PREG_OFFSET_CAPTURE);

    $nivel = 0;

    foreach ($m[0] as $par) {
        $token = strtoupper($par[0]);

        if ($token === '(') {
            $nivel++;
        } elseif ($token === ')') {
            $nivel--;
        } elseif ($nivel === 0) {
            return true;
        }
    }

    return false;
}

/* =========================================================
   3. EJECUCIÓN
   ========================================================= */

/*
 * SELECT, SHOW, DESCRIBE, EXPLAIN y ANALYZE SELECT.
 * La lectura es sin búfer: solo se guardan hasta ED_MAX_FILAS filas,
 * aunque el usuario pida un LIMIT enorme.
 */
function ed_ejecutar_lectura(PDO $pdo, string $tipo, string $sql, string $mascaraId): array
{
    $sqlFinal   = $sql;
    $limitAuto  = false;

    if ($tipo === 'SELECT') {
        if (!ed_tiene_limit_superior($mascaraId)) {
            // Una fila de más para saber si había más resultados.
            $sqlFinal  .= ' LIMIT ' . (ED_MAX_FILAS + 1);
            $limitAuto  = true;
        }

        $sqlFinal = 'SET STATEMENT max_statement_time=' . ED_MAX_SEGUNDOS . ' FOR ' . $sqlFinal;
    }

    $columnas  = [];
    $filas     = [];
    $truncado  = false;
    $inicio    = microtime(true);

    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    try {
        $stmt = $pdo->query($sqlFinal);

        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columnas[] = $meta['name'] ?? ('columna_' . ($i + 1));
        }

        while (($fila = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($filas) >= ED_MAX_FILAS) {
                $truncado = true;
                break;
            }

            $filas[] = $fila;
        }

        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }

    return [
        'columnas'  => $columnas,
        'filas'     => $filas,
        'truncado'  => $truncado,
        'limitAuto' => $limitAuto,
        'ms'        => (microtime(true) - $inicio) * 1000,
    ];
}

/*
 * INSERT, UPDATE o DELETE dentro de una transacción que SIEMPRE se revierte.
 * Solo se permite si todas las tablas de la base son InnoDB: en otros
 * motores (MyISAM, Aria) un ROLLBACK no deshace los cambios.
 */
function ed_probar_modificacion(PDO $pdo, string $sql): array
{
    $noTransaccionales = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
           AND ENGINE <> 'InnoDB'"
    )->fetchColumn();

    if ($noTransaccionales > 0) {
        throw new RuntimeException(
            'La base tiene tablas que no son InnoDB; un ROLLBACK no las revertiría. '
            . 'El modo de prueba está deshabilitado.'
        );
    }

    $inicio = microtime(true);

    $pdo->beginTransaction(); // START TRANSACTION

    try {
        $afectadas = $pdo->exec(
            'SET STATEMENT max_statement_time=' . ED_MAX_SEGUNDOS . ' FOR ' . $sql
        );
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack(); // ROLLBACK, también si la sentencia falló
        }
    }

    return [
        'afectadas' => (int)$afectadas,
        'ms'        => (microtime(true) - $inicio) * 1000,
    ];
}

/*
 * Plan de ejecución:
 *   EXPLAIN <consulta>  : estimado, no ejecuta la consulta.
 *   ANALYZE <consulta>  : real, ejecuta la consulta y añade r_rows, r_filtered.
 */
function ed_plan(PDO $pdo, string $sql, bool $real): array
{
    $inicio = microtime(true);

    $stmt  = $pdo->query(($real ? 'ANALYZE ' : 'EXPLAIN ') . $sql);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'filas' => $filas,
        'real'  => $real,
        'ms'    => (microtime(true) - $inicio) * 1000,
    ];
}

/* Una línea de lectura por cada fila del plan. */
function ed_leer_plan(array $f): string
{
    $partes = [];
    $tipo   = $f['type'] ?? null;

    if ($tipo !== null) {
        $partes[] = 'acceso ' . $tipo . ' (' . (ED_TIPOS[$tipo] ?? 'tipo no catalogado') . ')';
    }

    $partes[] = ($f['key'] ?? null) !== null
        ? 'usa el índice ' . $f['key']
        : 'no usa índice';

    if (isset($f['rows'])) {
        $partes[] = 'filas estimadas: ' . $f['rows'];
    }

    if (isset($f['r_rows']) && $f['r_rows'] !== 'NULL') {
        $partes[] = 'filas reales: ' . $f['r_rows'];
    }

    foreach (ED_EXTRAS as $patron => $texto) {
        if (preg_match($patron, (string)($f['Extra'] ?? ''), $x)) {
            $partes[] = $x[0] . ': ' . $texto;
        }
    }

    return ($f['table'] ?? 'consulta') . ' → ' . implode('; ', $partes) . '.';
}

/* =========================================================
   4. CONEXIÓN
   ========================================================= */

$pdo = null;
$info = null;
$errorConexion = '';

try {
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Emulado: el texto viaja tal cual (SET STATEMENT ... FOR ... no se prepara).
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
        $opciones[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false; // sin sentencias apiladas
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$bd};charset=utf8mb4",
        $user,
        $pass,
        $opciones
    );

    // Primera barrera de tiempo para toda la sesión (incluye SHOW, EXPLAIN, ANALYZE).
    $pdo->exec('SET SESSION max_statement_time = ' . ED_MAX_SEGUNDOS);

    $info = $pdo->query(
        'SELECT VERSION() AS version, DATABASE() AS bd, CURRENT_USER() AS usuario, NOW() AS ahora'
    )->fetch();
} catch (Throwable $ex) {
    error_log('editor.php: ' . $ex->getMessage());

    $pdo = null;
    $errorConexion =
        'No se pudo conectar a la base de datos. Revise la configuración y los permisos.';
}

/* =========================================================
   4b. ESQUEMA (autocompletado)
   ========================================================= */

$esquema = [];

if ($pdo) {
    try {
        $porTabla = [];

        $filas = $pdo->query(
            "SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna, COLUMN_TYPE AS tipo
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        )->fetchAll();

        foreach ($filas as $f) {
            if (!isset($porTabla[$f['tabla']])) {
                $porTabla[$f['tabla']] = ['t' => $f['tabla'], 'c' => []];
            }

            $porTabla[$f['tabla']]['c'][] = [$f['columna'], $f['tipo']];
        }

        $esquema = array_values($porTabla);
    } catch (Throwable $ex) {
        error_log('editor.php (esquema): ' . $ex->getMessage());
    }


}

/* =========================================================
   5. PROCESAR LA SOLICITUD
   ========================================================= */

$sqlTexto = '';
$modo = 'lectura';
$mensajeError = '';
$avisoBitacora = '';
$resultadoSel = null;
$resultadoDml = null;
$resultadoPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inicio = date('Y-m-d H:i:s P');

    $accion = $_POST['accion'] ?? '';
    if (!is_string($accion)) {
        $accion = '';
    }

    $modo = (($_POST['modo'] ?? '') === 'prueba') ? 'prueba' : 'lectura';

    $c = $_POST['consulta'] ?? '';
    $sqlTexto = is_string($c) ? mb_substr($c, 0, ED_MAX_CARACTERES + 1) : '';

    $csrfRecibido = $_POST['csrf'] ?? '';
    if (!is_string($csrfRecibido)) {
        $csrfRecibido = '';
    }

    $idCargar = $_POST['id'] ?? '';
    if (!is_string($idCargar)) {
        $idCargar = '';
    }

    $etiquetas = [
        'ejecutar'  => 'Ejecutar',
        'plan'      => 'Plan (EXPLAIN)',
        'plan_real' => 'Plan real (ANALYZE)',
    ];

    $etiqueta = $etiquetas[$accion] ?? 'Solicitud no válida';
    $resultadoLog = 'ERROR';
    $detalle = 'Solicitud rechazada.';
    $filasLog = null;
    $msLog = null;

    try {
        // 1. CSRF.
        if (!hash_equals((string)$_SESSION['csrf'], $csrfRecibido)) {
            $etiqueta = 'Rechazada';
            $detalle = 'Token de seguridad inválido.';
            throw new RuntimeException('Solicitud no válida.');
        }

        // 2. Cargar una consulta del historial en el editor.
        if ($accion === 'cargar') {
            $encontrado = ed_leer_historial($logConsultas, 1, $idCargar);

            if ($encontrado && is_string($encontrado[0]['consulta'] ?? null)) {
                $_SESSION['ed_sql']  = $encontrado[0]['consulta'];
                $_SESSION['ed_modo'] = ($encontrado[0]['modo'] ?? '') === 'prueba' ? 'prueba' : 'lectura';
            }

            header('Location: editor.php');
            exit;
        }

        if (!isset($etiquetas[$accion])) {
            $etiqueta = 'Rechazada';
            $detalle = 'Acción desconocida.';
            throw new RuntimeException('Acción no válida.');
        }

        // 3. Conexión disponible.
        if (!$pdo) {
            $detalle = 'No hay conexión disponible con la base de datos.';
            throw new RuntimeException('No hay conexión con la base de datos.');
        }

        // 4. Validar la consulta.
        $val = ed_validar($sqlTexto, $modo);

        if (!$val['ok']) {
            $etiqueta = 'Rechazada';
            $detalle = $val['mensaje'];
            throw new RuntimeException($val['mensaje']);
        }

        $tipo = $val['tipo'];
        $sql  = $val['sql'];

        // 5. Ejecutar según la acción.
        if ($accion === 'ejecutar' && $modo === 'prueba') {
            $etiqueta = 'Modificación de prueba (ROLLBACK)';
            $resultadoDml = ed_probar_modificacion($pdo, $sql);
            $resultadoDml['tipo'] = $tipo;

            $resultadoLog = 'OK';
            $filasLog = $resultadoDml['afectadas'];
            $msLog = round($resultadoDml['ms'], 2);
            $detalle = $resultadoDml['afectadas'] . ' fila(s) afectadas; cambios revertidos con ROLLBACK.';
        } elseif ($accion === 'ejecutar') {
            $resultadoSel = ed_ejecutar_lectura($pdo, $tipo, $sql, $val['mascara_id']);

            $resultadoLog = 'OK';
            $filasLog = count($resultadoSel['filas']);
            $msLog = round($resultadoSel['ms'], 2);
            $detalle = $filasLog . ' fila(s) mostradas'
                . ($resultadoSel['truncado'] ? ' (había más; se muestran las primeras ' . ED_MAX_FILAS . ')' : '')
                . '.';
        } else {
            $real = ($accion === 'plan_real');

            if ($real && $tipo !== 'SELECT') {
                $etiqueta = 'Rechazada';
                $detalle = 'El plan real (ANALYZE) solo aplica a consultas SELECT.';
                throw new RuntimeException($detalle);
            }

            if (!in_array($tipo, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
                $etiqueta = 'Rechazada';
                $detalle = 'El plan solo aplica a SELECT (o a INSERT, UPDATE y DELETE en modo de prueba).';
                throw new RuntimeException($detalle);
            }

            $resultadoPlan = ed_plan($pdo, $sql, $real);

            $resultadoLog = 'OK';
            $filasLog = count($resultadoPlan['filas']);
            $msLog = round($resultadoPlan['ms'], 2);
            $detalle = 'Plan obtenido (' . $filasLog . ' fila(s) del plan).';
        }
    } catch (PDOException $ex) {
        // Error de MariaDB: se muestra el mensaje del motor, sin traza.
        error_log('editor.php: ' . $ex->getMessage());

        $codigo = (int)($ex->errorInfo[1] ?? 0);
        $msgMotor = (string)($ex->errorInfo[2] ?? 'Error de base de datos.');

        if (
            $codigo === 1969 ||
            stripos($msgMotor, 'max_statement_time') !== false ||
            stripos($msgMotor, 'interrupted') !== false
        ) {
            $mensajeError = 'La consulta superó el límite de ' . ED_MAX_SEGUNDOS . ' segundos y fue cancelada.';
        } else {
            $mensajeError = 'Error de MariaDB' . ($codigo ? " ({$codigo})" : '') . ': ' . $msgMotor;
        }

        $detalle = $mensajeError;
    } catch (RuntimeException $ex) {
        // Rechazos propios: el mensaje ya es seguro.
        $mensajeError = $ex->getMessage();

        if ($detalle === 'Solicitud rechazada.') {
            $detalle = $mensajeError;
        }
    } catch (Throwable $ex) {
        error_log('editor.php: ' . $ex->getMessage());

        $mensajeError = 'Ocurrió un error inesperado al procesar la consulta.';
        $detalle = $mensajeError;
    }

    // 6. Registrar el intento en el historial.
    $evento = [
        'id'           => bin2hex(random_bytes(6)),
        'fecha_inicio' => $inicio,
        'motor'        => 'MariaDB',
        'base'         => is_array($info) ? (string)($info['bd'] ?? $bd) : (string)$bd,
        'accion'       => $etiqueta,
        'resultado'    => $resultadoLog,
        'modo'         => $modo,
        'consulta'     => mb_substr($sqlTexto, 0, ED_MAX_CARACTERES),
        'filas'        => $filasLog,
        'duracion_ms'  => $msLog,
        'detalle'      => mb_substr($detalle, 0, 300),
    ];

    if (!ed_registrar($logConsultas, $evento)) {
        $avisoBitacora = 'Advertencia: no se pudo escribir en el historial.';
    }
} else {
    // Consulta cargada desde el historial (se usa una sola vez).
    $sqlTexto = (string)($_SESSION['ed_sql'] ?? '');
    $modo = (($_SESSION['ed_modo'] ?? '') === 'prueba') ? 'prueba' : 'lectura';
    unset($_SESSION['ed_sql'], $_SESSION['ed_modo']);
}

/* =========================================================
   6. HISTORIAL (últimas diez entradas)
   ========================================================= */

$historial = ed_leer_historial($logConsultas, 10);

$titulo = 'Módulo 05: Editor de consultas SQL';
$activo = 'editor.php';

require __DIR__ . '/includes/header.php';
?>

<style>
    .ed-resumen {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        margin: 20px 0;
    }

    .ed-resumen > div {
        flex: 1;
        min-width: 160px;
        background: #fff;
        border: 1px solid #d6ddeb;
        border-radius: 10px;
        padding: 12px 16px;
    }

    .ed-resumen span {
        display: block;
        font-size: 13px;
        color: #5b6475;
    }

    .ed-resumen strong {
        font-size: 19px;
        color: #0e235a;
        word-break: break-word;
    }

    .ed-caja {
        background: #fff;
        border: 1px solid #d6ddeb;
        border-radius: 10px;
        padding: 14px 18px;
        margin: 20px 0;
    }

    .ed-caja h2 {
        margin-top: 0;
        color: #0e235a;
        font-size: 18px;
    }

    .ed-caja h3 {
        color: #1d3f8f;
        font-size: 15px;
        margin: 16px 0 6px;
    }

    .ed-editor {
        width: 100%;
        box-sizing: border-box;
        min-height: 150px;
        padding: 12px;
        border: 1px solid #d6ddeb;
        border-radius: 8px;
        background: #0f172a;
        color: #e2e8f0;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 14px;
        line-height: 1.5;
        resize: vertical;
    }

    .ed-editor:focus {
        outline: 3px solid #93b4f5;
        outline-offset: 1px;
    }

    .ed-acciones {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        margin-top: 12px;
    }

    .ed-boton {
        display: inline-block;
        padding: 9px 13px;
        border: 0;
        border-radius: 6px;
        background: #1d3f8f;
        color: #fff;
        font-weight: bold;
        cursor: pointer;
    }

    .ed-boton:hover {
        background: #0e235a;
    }

    .ed-boton-sec {
        display: inline-block;
        padding: 9px 13px;
        border: 1px solid #b6c2de;
        border-radius: 6px;
        background: #fff;
        color: #0e235a;
        font-weight: bold;
        cursor: pointer;
    }

    .ed-boton-sec:hover {
        background: #eef2fa;
    }

    .ed-boton:focus-visible,
    .ed-boton-sec:focus-visible {
        outline: 3px solid #93b4f5;
        outline-offset: 2px;
    }

    .ed-boton-mini {
        padding: 5px 9px;
        font-size: 12px;
    }

    .ed-modo {
        display: block;
        margin-top: 12px;
        padding: 10px 14px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        font-size: 14px;
        color: #92400e;
    }

    .ed-tabla {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 14px;
    }

    .ed-tabla th,
    .ed-tabla td {
        padding: 10px 12px;
        border-bottom: 1px solid #d6ddeb;
        text-align: left;
        vertical-align: top;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .ed-tabla th {
        background: #eef2fa;
        color: #0e235a;
        position: sticky;
        top: 0;
    }

    .ed-scroll {
        overflow: auto;
        max-height: 480px;
    }

    .ed-null {
        color: #94a3b8;
        font-style: italic;
    }

    .ed-ok {
        color: #166534;
        font-weight: bold;
    }

    .ed-error {
        color: #991b1b;
        font-weight: bold;
    }

    .ed-mensaje {
        padding: 10px 14px;
        border-radius: 8px;
        margin: 15px 0;
        background: #eef2fa;
        color: #0e235a;
    }

    .ed-mensaje-error {
        background: #fee2e2;
        color: #991b1b;
    }

    .ed-nota {
        color: #5b6475;
        font-size: 13px;
    }

    .ed-metricas {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin: 10px 0;
    }

    .ed-metricas div {
        padding: 8px 12px;
        background: #eef2fa;
        border: 1px solid #d6ddeb;
        border-radius: 8px;
        font-size: 14px;
    }

    .ed-metricas span {
        color: #5b6475;
    }

    .ed-metricas strong {
        color: #0e235a;
    }

    .ed-consulta {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 12px;
        max-width: 380px;
    }

    .ed-explicacion {
        line-height: 1.65;
    }

    .ed-caja [hidden] {
        display: none !important;
    }

    .ed-envoltura {
        position: relative;
    }

    .ed-envoltura .ed-editor {
        display: block;
    }

    .ed-lista {
        position: absolute;
        left: 12px;
        top: calc(100% - 6px);
        z-index: 20;
        margin: 0;
        padding: 4px;
        list-style: none;
        min-width: 260px;
        max-width: calc(100% - 24px);
        background: #fff;
        border: 1px solid #d6ddeb;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(14, 35, 90, .18);
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 13px;
    }

    .ed-lista li {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding: 6px 10px;
        border-radius: 6px;
        cursor: pointer;
    }

    .ed-lista li strong {
        color: #0e235a;
    }

    .ed-lista li small {
        color: #5b6475;
    }

    .ed-lista li:hover,
    .ed-lista li.ed-sel {
        background: #eef2fa;
    }

    @media (max-width: 700px) {
        .ed-tabla {
            font-size: 12px;
        }

        .ed-tabla th,
        .ed-tabla td {
            padding: 6px;
        }
    }
</style>

<h1>Módulo 05: Editor de consultas SQL</h1>

<p class="intro">
    Escriba una consulta, ejecútela y vea las filas, el tiempo y el plan de
    ejecución en la base
    <strong><?= ed_e($bd) ?></strong>.
</p>

<?php if ($errorConexion !== ''): ?>

    <div class="ed-mensaje ed-mensaje-error">
        <?= ed_e($errorConexion) ?>
    </div>

<?php else: ?>

    <!-- INFORMACIÓN DE CONEXIÓN Y LÍMITES -->

    <div class="ed-resumen">
        <div>
            <span>Motor y versión del servidor</span>
            <strong><?= ed_e($info['version'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Base de datos</span>
            <strong><?= ed_e($info['bd'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Usuario conectado</span>
            <strong><?= ed_e($info['usuario'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Límites por consulta</span>
            <strong><?= ED_MAX_FILAS ?> filas · <?= ED_MAX_SEGUNDOS ?> s</strong>
        </div>
    </div>

    <!-- EDITOR -->

    <div class="ed-caja">
        <h2>Consulta</h2>

        <form method="post" action="editor.php">
            <input type="hidden" name="csrf" value="<?= ed_e($_SESSION['csrf']) ?>">

            <div class="ed-envoltura">
            <textarea
                id="ed-consulta"
                data-bd="<?= ed_e($bd) ?>"
                autocomplete="off"
                class="ed-editor"
                name="consulta"
                maxlength="<?= ED_MAX_CARACTERES ?>"
                spellcheck="false"
                placeholder="SELECT id_cotizacion, fecha, estado, monto&#10;FROM cotizaciones&#10;WHERE fecha BETWEEN '2026-03-01' AND '2026-03-31'&#10;ORDER BY fecha"
                aria-label="Consulta SQL"
            ><?= ed_e($sqlTexto) ?></textarea>

            <ul
                class="ed-lista"
                id="ed-lista"
                role="listbox"
                aria-label="Sugerencias de autocompletado"
                hidden
            ></ul>
            </div>

            <label class="ed-modo">
                <input
                    type="checkbox"
                    id="ed-modo-prueba"
                    name="modo"
                    value="prueba"
                    <?= $modo === 'prueba' ? 'checked' : '' ?>
                >
                <strong>Modificación de prueba (se revierte con ROLLBACK).</strong>
                Permite INSERT, UPDATE y DELETE: se ejecutan dentro de una
                transacción que siempre se deshace, para ver cuántas filas
                afectarían sin guardar cambios.
            </label>

            <div class="ed-acciones">
                <button class="ed-boton" type="submit" name="accion" value="ejecutar">
                    Ejecutar
                </button>

                <button class="ed-boton-sec" type="submit" name="accion" value="plan">
                    Ver plan (EXPLAIN)
                </button>

                <button class="ed-boton-sec" type="submit" name="accion" value="plan_real">
                    Plan real (ANALYZE)
                </button>

                <button class="ed-boton-sec" type="button" id="ed-limpiar" hidden>
                    Limpiar
                </button>

                <span class="ed-nota">
                    Una sola sentencia. Cada intento queda en el historial.
                </span>

                <span class="ed-nota" id="ed-pista" hidden>
                    Autocompletado: escriba y pulse Tab para aceptar;
                    Ctrl + Espacio muestra sugerencias. El texto se conserva
                    al cambiar de módulo.
                </span>
            </div>
        </form>
    </div>

    <!-- AYUDAS DE ESCRITURA (JavaScript opcional: sin él, el editor funciona igual) -->

    <script type="application/json" id="ed-esquema"><?= json_encode(
        $esquema,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '[]' ?></script>

    <script>
(function () {
    'use strict';

    var area  = document.getElementById('ed-consulta');
    var lista = document.getElementById('ed-lista');

    if (!area || !lista) {
        return;
    }

    var check      = document.getElementById('ed-modo-prueba');
    var btnLimpiar = document.getElementById('ed-limpiar');
    var pista      = document.getElementById('ed-pista');
    var clave      = 'ed_borrador:' + (area.getAttribute('data-bd') || '');

    /* ---------- Esquema para el autocompletado ---------- */

    var tablas = {};

    try {
        JSON.parse(document.getElementById('ed-esquema').textContent).forEach(function (x) {
            tablas[x.t.toLowerCase()] = x;
        });
    } catch (e) {
        tablas = {};
    }

    var PALABRAS = [
        'SELECT', 'DISTINCT', 'FROM', 'WHERE', 'JOIN', 'LEFT JOIN', 'INNER JOIN',
        'ON', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT', 'AS', 'AND', 'OR', 'NOT',
        'IN', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL', 'ASC', 'DESC', 'UNION',
        'EXPLAIN', 'ANALYZE', 'DESCRIBE', 'SHOW TABLES', 'SHOW INDEX FROM',
        'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM'
    ];

    var FUNCIONES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'ROUND', 'COALESCE', 'CONCAT',
        'DATE_FORMAT', 'YEAR', 'MONTH', 'NOW', 'CURDATE'
    ];

    var RESERVADAS = /^(WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|ON|USING|GROUP|ORDER|LIMIT|HAVING|SET|VALUES|UNION|AS|AND|OR|SELECT)$/i;

    /* ---------- Borrador: se conserva al cambiar de módulo ---------- */

    function guardar() {
        try {
            if (area.value === '') {
                sessionStorage.removeItem(clave);
                return;
            }

            sessionStorage.setItem(clave, JSON.stringify({
                sql:  area.value,
                modo: (check && check.checked) ? 'prueba' : 'lectura'
            }));
        } catch (e) {
            /* sessionStorage no disponible: el editor sigue funcionando */
        }
    }

    function restaurar() {
        try {
            // Si el servidor ya trajo una consulta (resultado o historial),
            // manda esa y pasa a ser el borrador vigente.
            if (area.value.trim() !== '') {
                guardar();
                return;
            }

            var crudo = sessionStorage.getItem(clave);

            if (!crudo) {
                return;
            }

            var d = JSON.parse(crudo);

            if (d && typeof d.sql === 'string') {
                area.value = d.sql;

                if (check) {
                    check.checked = (d.modo === 'prueba');
                }
            }
        } catch (e) {
            /* borrador ilegible: se ignora */
        }
    }

    /* ---------- Autocompletado ---------- */

    var items = [];
    var sel   = -1;

    function ident(nombre) {
        return /^[A-Za-z_][\w$]*$/.test(nombre)
            ? nombre
            : '`' + nombre.replace(/`/g, '``') + '`';
    }

    // Palabra que se está escribiendo justo antes del cursor.
    function palabraActual(texto) {
        var m = /(?:([A-Za-z_][\w$]*)\.)?([\w$]*)$/.exec(texto);

        return {
            calificador: m[1] || null,
            prefijo:     m[2] || '',
            inicio:      texto.length - (m[2] || '').length
        };
    }

    // Tablas que menciona la consulta y sus alias: alias (o tabla) -> tabla.
    function alias(texto) {
        var mapa = {};
        var re = /\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([A-Za-z_][\w$]*)`?(?:\s+(?:AS\s+)?`?([A-Za-z_][\w$]*)`?)?/gi;
        var m;

        while ((m = re.exec(texto)) !== null) {
            var t = m[1].toLowerCase();

            if (!tablas[t]) {
                continue;
            }

            mapa[t] = t;

            if (m[2] && !RESERVADAS.test(m[2])) {
                mapa[m[2].toLowerCase()] = t;
            }
        }

        return mapa;
    }

    function candidatos(forzar) {
        var antes = area.value.slice(0, area.selectionStart);

        // Dentro de un texto entre comillas no se sugiere nada.
        if (((antes.match(/'/g) || []).length % 2) === 1 ||
            ((antes.match(/"/g) || []).length % 2) === 1) {
            return [];
        }

        var p = palabraActual(antes);

        if (!forzar && p.prefijo === '' && !p.calificador) {
            return [];
        }

        var pre    = p.prefijo.toLowerCase();
        var salida = [];
        var vistos = {};

        function agregar(texto, detalle, tipo) {
            var bajo = texto.toLowerCase();
            var id   = tipo + ':' + bajo;

            if (vistos[id] || bajo.indexOf(pre) !== 0) {
                return;
            }

            if (bajo === pre && !forzar) {
                return; // ya está escrito completo
            }

            vistos[id] = true;
            salida.push({ texto: texto, detalle: detalle, tipo: tipo });
        }

        var mapa = alias(area.value);

        if (p.calificador) {
            // "alias." o "tabla." -> columnas de esa tabla
            var q = p.calificador.toLowerCase();
            var t = mapa[q] || (tablas[q] ? q : null);

            if (t) {
                tablas[t].c.forEach(function (c) {
                    agregar(c[0], c[1], 'columna');
                });
            }
        } else if (/\b(?:FROM|JOIN|UPDATE|INTO|DESCRIBE|DESC)\s+$/i.test(antes.slice(0, p.inicio))) {
            // Después de FROM, JOIN, etc. -> tablas
            Object.keys(tablas).forEach(function (k) {
                agregar(tablas[k].t, 'tabla', 'tabla');
            });
        } else {
            // Columnas de las tablas ya mencionadas, luego tablas, funciones y palabras
            var usadas = {};

            Object.keys(mapa).forEach(function (k) {
                usadas[mapa[k]] = true;
            });

            Object.keys(usadas).forEach(function (k) {
                tablas[k].c.forEach(function (c) {
                    agregar(c[0], tablas[k].t, 'columna');
                });
            });

            Object.keys(tablas).forEach(function (k) {
                agregar(tablas[k].t, 'tabla', 'tabla');
            });

            FUNCIONES.forEach(function (f) {
                agregar(f, 'función', 'funcion');
            });

            PALABRAS.forEach(function (w) {
                agregar(w, 'palabra clave', 'palabra');
            });
        }

        return salida.slice(0, 8);
    }

    function pintar() {
        lista.textContent = '';

        items.forEach(function (c, i) {
            var li = document.createElement('li');
            var a  = document.createElement('strong');
            var b  = document.createElement('small');

            li.setAttribute('role', 'option');
            li.className = (i === sel) ? 'ed-sel' : '';
            a.textContent = c.texto;
            b.textContent = c.detalle;
            li.appendChild(a);
            li.appendChild(b);

            // mousedown (no click) para que el cuadro de texto no pierda el foco
            li.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                aceptar(c);
            });

            lista.appendChild(li);
        });

        lista.hidden = (items.length === 0);
    }

    function mostrar(forzar) {
        items = candidatos(forzar);
        sel = -1;
        pintar();
    }

    function cerrar() {
        items = [];
        sel = -1;
        pintar();
    }

    function aceptar(c) {
        var pos   = area.selectionStart;
        var p     = palabraActual(area.value.slice(0, pos));
        var texto = c.tipo === 'palabra' ? c.texto + ' '
                  : c.tipo === 'funcion' ? c.texto + '('
                  : ident(c.texto);

        area.value = area.value.slice(0, p.inicio) + texto + area.value.slice(pos);

        var nueva = p.inicio + texto.length;
        area.setSelectionRange(nueva, nueva);

        cerrar();
        guardar();
        area.focus();
    }

    area.addEventListener('input', function () {
        guardar();
        mostrar(false);
    });

    area.addEventListener('blur', cerrar);
    area.addEventListener('click', cerrar);

    area.addEventListener('keydown', function (ev) {
        if ((ev.ctrlKey || ev.metaKey) && (ev.key === ' ' || ev.code === 'Space')) {
            ev.preventDefault();
            mostrar(true);
            return;
        }

        if (items.length === 0) {
            return;
        }

        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();

            if (ev.key === 'ArrowDown') {
                sel = (sel + 1) % items.length;
            } else {
                sel = sel <= 0 ? items.length - 1 : sel - 1;
            }

            pintar();

            var el = lista.children[sel];

            if (el && el.scrollIntoView) {
                el.scrollIntoView({ block: 'nearest' });
            }
        } else if (ev.key === 'Tab' && !ev.shiftKey) {
            ev.preventDefault();
            aceptar(items[sel >= 0 ? sel : 0]);
        } else if (ev.key === 'Enter' && sel >= 0) {
            ev.preventDefault();
            aceptar(items[sel]);
        } else if (ev.key === 'Escape') {
            ev.preventDefault();
            cerrar();
        } else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight' ||
                   ev.key === 'Home' || ev.key === 'End') {
            cerrar();
        }
    });

    if (check) {
        check.addEventListener('change', guardar);
    }

    if (btnLimpiar) {
        btnLimpiar.hidden = false;

        btnLimpiar.addEventListener('click', function () {
            area.value = '';
            guardar();
            cerrar();
            area.focus();
        });
    }

    if (pista) {
        pista.hidden = false;
    }

    restaurar();
})();
    </script>

    <!-- ERROR CONTROLADO -->

    <?php if ($mensajeError !== ''): ?>
        <div class="ed-mensaje ed-mensaje-error">
            <?= ed_e($mensajeError) ?>
        </div>
    <?php endif; ?>

    <?php if ($avisoBitacora !== ''): ?>
        <div class="ed-mensaje ed-mensaje-error">
            <?= ed_e($avisoBitacora) ?>
        </div>
    <?php endif; ?>

    <!-- RESULTADO DE UNA CONSULTA DE LECTURA -->

    <?php if ($resultadoSel !== null): ?>

        <div class="ed-caja">
            <h2>Resultado</h2>

            <div class="ed-metricas">
                <div>
                    <span>Filas mostradas:</span>
                    <strong><?= number_format(count($resultadoSel['filas']), 0, ',', '.') ?></strong>
                </div>

                <div>
                    <span>Tiempo de ejecución:</span>
                    <strong><?= ed_e(ed_ms($resultadoSel['ms'])) ?></strong>
                </div>

                <div>
                    <span>Columnas:</span>
                    <strong><?= count($resultadoSel['columnas']) ?></strong>
                </div>
            </div>

            <?php if ($resultadoSel['limitAuto']): ?>
                <p class="ed-nota">
                    La consulta no traía LIMIT: se agregó automáticamente
                    <code>LIMIT <?= ED_MAX_FILAS + 1 ?></code> para proteger el servidor.
                </p>
            <?php endif; ?>

            <?php if ($resultadoSel['truncado']): ?>
                <p class="ed-error">
                    Hay más filas de las que se muestran: se presentan solo las
                    primeras <?= ED_MAX_FILAS ?>.
                </p>
            <?php endif; ?>

            <?php if (!$resultadoSel['filas']): ?>

                <p>La consulta no devolvió filas.</p>

            <?php else: ?>

                <div class="ed-scroll">
                    <table class="ed-tabla">
                        <thead>
                            <tr>
                                <?php foreach ($resultadoSel['columnas'] as $col): ?>
                                    <th><?= ed_e($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($resultadoSel['filas'] as $fila): ?>
                            <tr>
                                <?php foreach ($fila as $valor): ?>
                                    <td><?= ed_celda($valor) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>
        </div>

    <?php endif; ?>

    <!-- RESULTADO DE UNA MODIFICACIÓN DE PRUEBA -->

    <?php if ($resultadoDml !== null): ?>

        <div class="ed-caja">
            <h2>Resultado de la modificación de prueba</h2>

            <p class="ed-ok">
                OK: <?= ed_e($resultadoDml['tipo']) ?> ejecutado y revertido con ROLLBACK.
            </p>

            <div class="ed-metricas">
                <div>
                    <span>Filas afectadas:</span>
                    <strong><?= number_format($resultadoDml['afectadas'], 0, ',', '.') ?></strong>
                </div>

                <div>
                    <span>Tiempo de ejecución:</span>
                    <strong><?= ed_e(ed_ms($resultadoDml['ms'])) ?></strong>
                </div>
            </div>

            <p class="ed-nota">
                No se guardó ningún cambio. En un UPDATE, MariaDB cuenta solo las
                filas cuyo valor realmente cambió. Los contadores
                AUTO_INCREMENT no se revierten con el ROLLBACK.
            </p>
        </div>

    <?php endif; ?>

    <!-- PLAN DE EJECUCIÓN -->

    <?php if ($resultadoPlan !== null): ?>

        <div class="ed-caja">
            <h2>
                <?= $resultadoPlan['real'] ? 'Plan real (ANALYZE)' : 'Plan de ejecución (EXPLAIN)' ?>
            </h2>

            <div class="ed-metricas">
                <div>
                    <span>Tiempo de esta operación:</span>
                    <strong><?= ed_e(ed_ms($resultadoPlan['ms'])) ?></strong>
                </div>
            </div>

            <?php if ($resultadoPlan['real']): ?>
                <p class="ed-nota">
                    ANALYZE ejecuta la consulta de verdad y añade las columnas
                    <code>r_rows</code> y <code>r_filtered</code> con lo que el motor leyó.
                </p>
            <?php else: ?>
                <p class="ed-nota">
                    EXPLAIN no ejecuta la consulta: las filas son estimaciones del
                    optimizador, no un tiempo.
                </p>
            <?php endif; ?>

            <?php if (!$resultadoPlan['filas']): ?>

                <p>El motor no devolvió un plan.</p>

            <?php else: ?>

                <div class="ed-scroll">
                    <table class="ed-tabla">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($resultadoPlan['filas'][0]) as $col): ?>
                                    <th><?= ed_e($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($resultadoPlan['filas'] as $fila): ?>
                            <tr>
                                <?php foreach ($fila as $valor): ?>
                                    <td><?= ed_celda($valor) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3>Lectura del plan</h3>

                <ul>
                    <?php foreach ($resultadoPlan['filas'] as $fila): ?>
                        <li><?= ed_e(ed_leer_plan($fila)) ?></li>
                    <?php endforeach; ?>
                </ul>

            <?php endif; ?>
        </div>

    <?php endif; ?>

<?php endif; ?>

<!-- HISTORIAL PERSISTENTE -->

<div class="ed-caja">
    <h2>Historial de consultas</h2>

    <p class="ed-nota">
        Últimos diez intentos registrados por este editor, incluidos los
        rechazados. Use «Cargar» para volver a poner una consulta en el editor.
    </p>

    <?php if (!$historial): ?>

        <p>Sin consultas registradas.</p>

    <?php else: ?>

        <div class="ed-scroll">
            <table class="ed-tabla">
                <thead>
                    <tr>
                        <th>Fecha y hora de inicio</th>
                        <th>Acción</th>
                        <th>Resultado</th>
                        <th>Consulta</th>
                        <th>Filas</th>
                        <th>Tiempo</th>
                        <th>Detalle</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($historial as $h): ?>

                    <tr>
                        <td><?= ed_e($h['fecha_inicio'] ?? 'No disponible') ?></td>
                        <td><?= ed_e($h['accion'] ?? 'No disponible') ?></td>

                        <td class="<?= ($h['resultado'] ?? '') === 'OK' ? 'ed-ok' : 'ed-error' ?>">
                            <?= ed_e($h['resultado'] ?? 'ERROR') ?>
                        </td>

                        <td class="ed-consulta">
                            <?= ed_e(mb_substr((string)($h['consulta'] ?? ''), 0, 160)) ?><?= mb_strlen((string)($h['consulta'] ?? '')) > 160 ? '…' : '' ?>
                        </td>

                        <td><?= ed_e($h['filas'] ?? '—') ?></td>

                        <td>
                            <?= isset($h['duracion_ms']) ? ed_e(ed_ms((float)$h['duracion_ms'])) : '—' ?>
                        </td>

                        <td><?= ed_e($h['detalle'] ?? 'Sin detalle') ?></td>

                        <td>
                            <?php if (!empty($h['id']) && !empty($h['consulta'])): ?>
                                <form method="post" action="editor.php">
                                    <input type="hidden" name="csrf" value="<?= ed_e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="accion" value="cargar">
                                    <input type="hidden" name="id" value="<?= ed_e($h['id']) ?>">

                                    <button class="ed-boton-sec ed-boton-mini" type="submit">
                                        Cargar
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<!-- EXPLICACIÓN TÉCNICA -->

<div class="ed-caja ed-explicacion">
    <h2>Explicación del editor</h2>

    <ul>
        <li>
            <strong>Sentencias permitidas:</strong>
            SELECT, SHOW, DESCRIBE, EXPLAIN y ANALYZE SELECT. Una sola sentencia
            por ejecución; se descartan los comentarios antes de enviarla.
        </li>

        <li>
            <strong>Tiempo máximo:</strong>
            cada SELECT se envía como
            <code>SET STATEMENT max_statement_time=<?= ED_MAX_SEGUNDOS ?> FOR …</code>
            y la sesión completa tiene el mismo límite. Si se supera, MariaDB
            cancela la consulta.
        </li>

        <li>
            <strong>Límite de filas:</strong>
            si el SELECT no trae LIMIT, se agrega uno automático; además, nunca
            se muestran más de <?= ED_MAX_FILAS ?> filas.
        </li>

        <li>
            <strong>Plan:</strong>
            EXPLAIN estima sin ejecutar; ANALYZE ejecuta la consulta y compara lo
            estimado con lo real. Sirve para probar una consulta antes y después
            de crear un índice.
        </li>

        <li>
            <strong>Modificaciones:</strong>
            INSERT, UPDATE y DELETE solo corren en modo de prueba, dentro de
            <code>START TRANSACTION</code> y con <code>ROLLBACK</code> final. El
            modo se bloquea si hay tablas que no son InnoDB, porque no se
            podrían revertir.
        </li>

        <li>
            <strong>Bloqueos:</strong>
            no se admiten INTO OUTFILE, bloqueos de filas, funciones como SLEEP o
            LOAD_FILE, ni los esquemas internos mysql, performance_schema y sys.
        </li>

        <li>
            <strong>Ayudas para escribir:</strong>
            el autocompletado de tablas y columnas y el borrador que se
            conserva al cambiar de módulo usan JavaScript; el borrador
            vive en el navegador (sessionStorage), solo en esa pestaña, y
            desaparece al cerrarla.
        </li>

        <li>
            <strong>Historial:</strong>
            cada intento (correcto o rechazado) se guarda en un archivo privado
            fuera de public_html, con fecha y zona horaria, acción, resultado,
            filas, tiempo y detalle.
        </li>

        <li>
            <strong>Seguridad:</strong>
            token CSRF en cada envío, mensajes de error sin trazas internas y
            permisos acotados a los de la cuenta MariaDB configurada.
        </li>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
