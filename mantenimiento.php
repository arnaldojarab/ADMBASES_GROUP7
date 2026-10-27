<?php
/*
 * Módulo 04 - Mantenimiento básico (MariaDB 10.11)
 *
 * - Consulta estadísticas de tablas de la base configurada.
 * - Actualiza estadísticas de UNA tabla mediante ANALYZE TABLE.
 * - Valida tablas autorizadas y token CSRF.
 * - Registra intentos en una bitácora privada fuera de public_html.
 * - Evita operaciones simultáneas sobre la misma tabla.
 * - Compara estadísticas anteriores y posteriores.
 *
 * Configuración reutilizada desde includes/auth.php.
 */

require_once __DIR__ . '/includes/auth.php';

date_default_timezone_set('America/Costa_Rica');

/* =========================================================
   1. CONFIGURACIÓN Y UTILIDADES
   ========================================================= */

$cfg = $CONFIG['db'];

$host = $cfg['host'] ?? 'localhost';
$port = (int)($cfg['port'] ?? 3306);
$bd   = $cfg['name'] ?? '';
$user = $cfg['user'] ?? $cfg['usuario'] ?? '';
$pass = $cfg['pass'] ?? $cfg['password'] ?? $cfg['clave'] ?? '';

/*
 * La bitácora se guarda fuera de public_html.
 * Se puede definir log_mantenimiento en la configuración.
 * En su ausencia, se utiliza el directorio padre de respaldos_dir.
 */
$logMantenimiento = $CONFIG['log_mantenimiento']
    ?? (dirname($CONFIG['respaldos_dir']) . '/mantenimiento.jsonl');

$dirBloqueos = dirname($logMantenimiento) . '/.bloqueos_mantenimiento';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function mt04_e($valor): string
{
    return htmlspecialchars(
        (string)($valor ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function mt04_bytes($bytes): string
{
    if ($bytes === null || !is_numeric($bytes)) {
        return 'No disponible';
    }

    $bytes = (float)$bytes;

    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function mt04_filas($filas): string
{
    if ($filas === null || !is_numeric($filas)) {
        return 'No disponible';
    }

    return '~' . number_format((int)$filas, 0, ',', '.');
}

/*
 * Escapado de identificadores MariaDB.
 * Solo se usa después de comprobar que la tabla pertenece
 * a la lista autorizada obtenida del catálogo.
 */
function mt04_identificador(string $nombre): string
{
    return '`' . str_replace('`', '``', $nombre) . '`';
}

/*
 * Bitácora JSONL: una entrada JSON por línea.
 * El archivo se mantiene fuera del directorio público.
 */
function mt04_registrar(string $ruta, array $evento): bool
{
    $archivo = @fopen($ruta, 'ab');

    if ($archivo === false) {
        error_log('Módulo 04: no se pudo abrir la bitácora.');
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
        error_log('Módulo 04: no se pudo completar la escritura de bitácora.');
    }

    return $ok;
}

/*
 * Devuelve estadísticas de una tabla desde information_schema.
 */
function mt04_obtener_estadistica(PDO $pdo, string $tabla): ?array
{
    $sql = "
        SELECT TABLE_ROWS AS filas,
               DATA_LENGTH AS datos,
               INDEX_LENGTH AS indices,
               UPDATE_TIME AS analizada
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_TYPE = 'BASE TABLE'
          AND TABLE_NAME = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tabla]);

    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila ?: null;
}

/*
 * No se registran contraseñas, tokens ni trazas de excepciones.
 * El detalle se limita a un mensaje seguro.
 */
function mt04_evento(
    string $motor,
    string $base,
    string $tabla,
    string $accion,
    string $resultado,
    string $detalle,
    string $inicio,
    float $duracion
): array {
    return [
        'fecha_inicio' => $inicio,
        'motor'        => $motor,
        'base'         => $base,
        'tabla'        => $tabla,
        'accion'       => $accion,
        'resultado'    => $resultado,
        'detalle'      => mb_substr($detalle, 0, 300),
        'duracion_seg' => round($duracion, 3),
    ];
}

/* =========================================================
   2. CONEXIÓN Y CONSULTA DEL CATÁLOGO AUTORIZADO
   ========================================================= */

$pdo = null;
$info = null;
$tablas = [];
$mapaTablas = [];
$errorConexion = '';

$SQL_INFO = "
    SELECT VERSION() AS version,
           DATABASE() AS bd,
           CURRENT_USER() AS usuario,
           NOW() AS ahora
";

$SQL_TABLAS = "
    SELECT TABLE_NAME AS nombre,
           TABLE_ROWS AS filas,
           DATA_LENGTH AS datos,
           INDEX_LENGTH AS indices,
           UPDATE_TIME AS analizada
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY (COALESCE(DATA_LENGTH, 0) +
              COALESCE(INDEX_LENGTH, 0)) DESC
";

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$bd};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    $info = $pdo->query($SQL_INFO)->fetch();
    $tablas = $pdo->query($SQL_TABLAS)->fetchAll();

    foreach ($tablas as $tabla) {
        $mapaTablas[$tabla['nombre']] = $tabla;
    }
} catch (Throwable $ex) {
    error_log('mantenimiento.php: ' . $ex->getMessage());

    $errorConexion =
        'No se pudo conectar o consultar la base de datos. Revise la configuración y los permisos.';
}

/* =========================================================
   3. PROCESAR ACTUALIZACIÓN DE UNA TABLA
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inicioTimestamp = microtime(true);
    $inicio = date('Y-m-d H:i:s P');
    $tablaSolicitada = $_POST['tabla'] ?? '';

    // Limitar el texto utilizado en la bitácora.
    if (!is_string($tablaSolicitada)) {
        $tablaSolicitada = '';
    }

    $tablaSolicitada = mb_substr($tablaSolicitada, 0, 128);

    $motor = 'MariaDB';
    $baseRegistro = is_array($info)
        ? (string)($info['bd'] ?? $bd)
        : (string)$bd;

    $accion = 'Actualizar estadísticas';
    $resultado = 'ERROR';
    $detalle = 'Solicitud rechazada.';
    $antes = null;
    $despues = null;
    $bloqueo = null;

    try {
        // 1. Validar CSRF.
        if (
            !hash_equals(
                (string)$_SESSION['csrf'],
                (string)($_POST['csrf'] ?? '')
            )
        ) {
            $accion = 'Operación rechazada';
            $detalle = 'Token de seguridad inválido.';
            throw new RuntimeException('Solicitud no válida.');
        }

        // 2. No se permite operar si falló la conexión.
        if (!$pdo || !$info) {
            $detalle = 'No hay conexión disponible con la base de datos.';
            throw new RuntimeException('Conexión no disponible.');
        }

        // 3. La tabla debe existir en el catálogo autorizado.
        if (
            $tablaSolicitada === '' ||
            !array_key_exists($tablaSolicitada, $mapaTablas)
        ) {
            $accion = 'Operación rechazada';
            $detalle = 'Tabla inexistente o no autorizada.';
            throw new RuntimeException('Tabla no autorizada.');
        }

        // 4. Preparar un bloqueo por tabla.
        if (
            !is_dir($dirBloqueos) &&
            !@mkdir($dirBloqueos, 0700, true) &&
            !is_dir($dirBloqueos)
        ) {
            $detalle = 'No se pudo preparar el control de concurrencia.';
            throw new RuntimeException('No se pudo preparar el bloqueo.');
        }

        @chmod($dirBloqueos, 0700);

        $nombreBloqueo = hash(
            'sha256',
            $baseRegistro . "\0" . $tablaSolicitada
        );

        $bloqueo = @fopen(
            $dirBloqueos . '/' . $nombreBloqueo . '.lock',
            'c'
        );

        if ($bloqueo === false) {
            $detalle = 'No se pudo establecer el bloqueo de la tabla.';
            throw new RuntimeException('No se pudo abrir el bloqueo.');
        }

        @chmod($dirBloqueos . '/' . $nombreBloqueo . '.lock', 0600);

        if (!flock($bloqueo, LOCK_EX | LOCK_NB)) {
            $accion = 'Operación rechazada';
            $detalle = 'Ya hay una operación en curso sobre esta tabla.';
            throw new RuntimeException('Tabla ocupada.');
        }

        // 5. Obtener valores anteriores.
        $antes = mt04_obtener_estadistica($pdo, $tablaSolicitada);

        if ($antes === null) {
            $accion = 'Operación rechazada';
            $detalle = 'La tabla dejó de estar disponible o autorizada.';
            throw new RuntimeException('Tabla no disponible.');
        }

        /*
         * 6. ANALYZE TABLE sobre una sola tabla.
         * Los identificadores proceden del catálogo autorizado.
         */
        $sqlAnalyze =
            'ANALYZE TABLE ' .
            mt04_identificador($baseRegistro) . '.' .
            mt04_identificador($tablaSolicitada);

        $stmt = $pdo->query($sqlAnalyze);
        $respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * MariaDB normalmente devuelve una fila con:
         * Table, Op, Msg_type y Msg_text.
         * Solo se acepta éxito si existe un estado OK y
         * ninguna fila indica error.
         */
        $hayEstadoOk = false;
        $hayErrorMotor = false;

        foreach ($respuestas as $respuesta) {
            $tipo = strtolower((string)($respuesta['Msg_type'] ?? ''));
            $mensajeMotor = trim((string)($respuesta['Msg_text'] ?? ''));

            if ($tipo === 'error') {
                $hayErrorMotor = true;
            }

            if (
                $tipo === 'status' &&
                strcasecmp($mensajeMotor, 'OK') === 0
            ) {
                $hayEstadoOk = true;
            }
        }

        if ($hayErrorMotor || !$hayEstadoOk) {
            $detalle = 'El motor no confirmó correctamente la actualización de estadísticas.';
            throw new RuntimeException('ANALYZE no confirmó éxito.');
        }

        $resultado = 'OK';
        $detalle = 'MariaDB confirmó la actualización de estadísticas.';

        // 7. Volver a consultar los datos de la tabla.
        $despues = mt04_obtener_estadistica($pdo, $tablaSolicitada);

        if ($despues === null) {
            // La operación sí fue confirmada por el motor.
            $detalle .= ' No fue posible recuperar las estadísticas posteriores.';
        }
    } catch (Throwable $ex) {
        if ($resultado !== 'OK') {
            $resultado = 'ERROR';
        }

        error_log('mantenimiento.php: ' . $ex->getMessage());
    } finally {
        if (is_resource($bloqueo)) {
            @flock($bloqueo, LOCK_UN);
            fclose($bloqueo);
        }

        $duracion = microtime(true) - $inicioTimestamp;

        $evento = mt04_evento(
            $motor,
            $baseRegistro,
            $tablaSolicitada !== '' ? $tablaSolicitada : '(sin identificar)',
            $accion,
            $resultado,
            $detalle,
            $inicio,
            $duracion
        );

        if (!mt04_registrar($logMantenimiento, $evento)) {
            if ($resultado === 'OK') {
                $detalle .= ' Advertencia: no se pudo escribir la bitácora.';
            } else {
                $detalle .= ' No se pudo completar el registro persistente.';
            }
        }

        $_SESSION['mt04_resultado'] = [
            'motor'        => $motor,
            'base'         => $baseRegistro,
            'tabla'        => $tablaSolicitada,
            'accion'       => $accion,
            'resultado'    => $resultado,
            'detalle'      => $detalle,
            'inicio'       => $inicio,
            'fin'          => date('Y-m-d H:i:s P'),
            'duracion'     => round($duracion, 3),
            'antes'        => $antes,
            'despues'      => $despues,
        ];
    }

    // Evita repetir la operación al actualizar el navegador.
    header('Location: mantenimiento.php');
    exit;
}

/* =========================================================
   4. RESULTADO DE LA ÚLTIMA OPERACIÓN
   ========================================================= */

$resultadoOperacion = $_SESSION['mt04_resultado'] ?? null;
unset($_SESSION['mt04_resultado']);

/* =========================================================
   5. LEER LAS ÚLTIMAS DIEZ ENTRADAS DE LA BITÁCORA
   ========================================================= */

$historial = [];

if (is_readable($logMantenimiento)) {
    $lineas = @file(
        $logMantenimiento,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if (is_array($lineas)) {
        foreach (array_reverse($lineas) as $linea) {
            $evento = json_decode($linea, true);

            if (is_array($evento)) {
                $historial[] = $evento;
            }

            if (count($historial) >= 10) {
                break;
            }
        }
    }
}

$titulo = 'Módulo 04: Mantenimiento básico';
$activo = 'mantenimiento.php';

require __DIR__ . '/includes/header.php';
?>

<style>
    .mt04-resumen {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        margin: 20px 0;
    }

    .mt04-resumen > div {
        flex: 1;
        min-width: 170px;
        background: #fff;
        border: 1px solid #dce9e1;
        border-radius: 10px;
        padding: 14px 16px;
    }

    .mt04-resumen span {
        display: block;
        font-size: 13px;
        color: #60776c;
    }

    .mt04-resumen strong {
        display: block;
        margin-top: 5px;
        font-size: 17px;
        color: #064e3b;
        overflow-wrap: anywhere;
    }

    .mt04-caja {
        background: #fff;
        border: 1px solid #dce9e1;
        border-radius: 10px;
        padding: 16px;
        margin: 20px 0;
    }

    .mt04-caja h2 {
        margin-top: 0;
        color: #064e3b;
        font-size: 19px;
    }

    .mt04-tabla {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 14px;
    }

    .mt04-tabla th,
    .mt04-tabla td {
        padding: 10px 12px;
        border-bottom: 1px solid #dce9e1;
        text-align: left;
        vertical-align: top;
    }

    .mt04-tabla th {
        background: #ecfdf5;
        color: #064e3b;
    }

    .mt04-tabla td {
        overflow-wrap: anywhere;
    }

    .mt04-scroll {
        overflow-x: auto;
    }

    .mt04-boton {
        display: inline-block;
        padding: 9px 13px;
        border: 0;
        border-radius: 6px;
        background: #047857;
        color: white;
        font-weight: bold;
        cursor: pointer;
    }

    .mt04-boton:hover {
        background: #064e3b;
    }

    .mt04-ok {
        color: #047857;
        font-weight: bold;
    }

    .mt04-error {
        color: #b91c1c;
        font-weight: bold;
    }

    .mt04-mensaje {
        padding: 12px 15px;
        border-radius: 8px;
        margin: 15px 0;
        background: #d1fae5;
        color: #064e3b;
    }

    .mt04-mensaje-error {
        background: #fee2e2;
        color: #991b1b;
    }

    .mt04-nota {
        color: #52675e;
        font-size: 13px;
    }

    .mt04-explicacion {
        line-height: 1.65;
    }

    @media (max-width: 700px) {
        .mt04-tabla {
            font-size: 12px;
        }

        .mt04-tabla th,
        .mt04-tabla td {
            padding: 8px;
        }
    }
</style>

<h1>Módulo 04: Mantenimiento básico</h1>

<p class="intro">
    Consulta y actualización de estadísticas de las tablas autorizadas
    de la base
    <strong><?= mt04_e($bd) ?></strong>.
</p>

<?php if ($errorConexion !== ''): ?>

    <div class="mt04-mensaje mt04-mensaje-error">
        <?= mt04_e($errorConexion) ?>
    </div>

<?php else: ?>

    <!-- INFORMACIÓN DE CONEXIÓN -->

    <div class="mt04-resumen">
        <div>
            <span>Motor y versión del servidor</span>
            <strong><?= mt04_e($info['version'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Base de datos</span>
            <strong><?= mt04_e($info['bd'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Usuario conectado</span>
            <strong><?= mt04_e($info['usuario'] ?? 'No disponible') ?></strong>
        </div>

        <div>
            <span>Fecha y hora</span>
            <strong>
                <?= mt04_e(
                    isset($info['ahora'])
                        ? date('d/m/Y H:i:s', strtotime($info['ahora']))
                        : 'No disponible'
                ) ?>
                (<?= mt04_e(date('P')) ?>)
            </strong>
        </div>
    </div>

    <!-- RESULTADO DE LA OPERACIÓN -->

    <?php if ($resultadoOperacion !== null): ?>

        <?php
        $esOk = ($resultadoOperacion['resultado'] ?? '') === 'OK';
        ?>

        <div class="mt04-caja">
            <h2>Resultado de la operación</h2>

            <p class="<?= $esOk ? 'mt04-ok' : 'mt04-error' ?>">
                <?= $esOk ? 'OK: operación confirmada por el motor' : 'ERROR: operación fallida o rechazada' ?>
            </p>

            <p>
                <strong>Base:</strong>
                <?= mt04_e($resultadoOperacion['base']) ?>
            </p>

            <p>
                <strong>Tabla solicitada:</strong>
                <?= mt04_e($resultadoOperacion['tabla']) ?>
            </p>

            <p>
                <strong>Acción:</strong>
                <?= mt04_e($resultadoOperacion['accion']) ?>
            </p>

            <p>
                <strong>Inicio:</strong>
                <?= mt04_e($resultadoOperacion['inicio']) ?>
            </p>

            <p>
                <strong>Fin:</strong>
                <?= mt04_e($resultadoOperacion['fin']) ?>
            </p>

            <p>
                <strong>Duración:</strong>
                <?= mt04_e($resultadoOperacion['duracion']) ?> segundos
            </p>

            <p>
                <strong>Detalle:</strong>
                <?= mt04_e($resultadoOperacion['detalle']) ?>
            </p>

            <?php if (
                is_array($resultadoOperacion['antes'] ?? null) &&
                is_array($resultadoOperacion['despues'] ?? null)
            ): ?>

                <h3>Comparación de estadísticas</h3>

                <div class="mt04-scroll">
                    <table class="mt04-tabla">
                        <tr>
                            <th>Dato</th>
                            <th>Antes</th>
                            <th>Después</th>
                        </tr>

                        <tr>
                            <td>Filas estimadas</td>
                            <td><?= mt04_e(mt04_filas($resultadoOperacion['antes']['filas'])) ?></td>
                            <td><?= mt04_e(mt04_filas($resultadoOperacion['despues']['filas'])) ?></td>
                        </tr>

                        <tr>
                            <td>Tamaño de datos</td>
                            <td><?= mt04_e(mt04_bytes($resultadoOperacion['antes']['datos'])) ?></td>
                            <td><?= mt04_e(mt04_bytes($resultadoOperacion['despues']['datos'])) ?></td>
                        </tr>

                        <tr>
                            <td>Tamaño de índices</td>
                            <td><?= mt04_e(mt04_bytes($resultadoOperacion['antes']['indices'])) ?></td>
                            <td><?= mt04_e(mt04_bytes($resultadoOperacion['despues']['indices'])) ?></td>
                        </tr>
                    </table>
                </div>

                <p class="mt04-nota">
                    Los valores pueden permanecer iguales después de ANALYZE.
                    Las filas mostradas son estimaciones, no un COUNT(*) exacto.
                </p>

            <?php endif; ?>
        </div>

    <?php endif; ?>

    <!-- PANEL DE TABLAS -->

    <div class="mt04-caja">
        <h2>Panel de tablas autorizadas</h2>

        <p class="mt04-nota">
            Las filas se muestran como estimaciones de MariaDB.
            Los tamaños son aproximados y pueden variar según el motor.
        </p>

        <?php if (!$tablas): ?>

            <p>No hay tablas disponibles en la base configurada.</p>

        <?php else: ?>

            <div class="mt04-scroll">
                <table class="mt04-tabla">
                    <thead>
                        <tr>
                            <th>Tabla</th>
                            <th>Filas estimadas</th>
                            <th>Datos</th>
                            <th>Índices</th>
                            <th>Tamaño total</th>
                            <th>Última actualización disponible</th>
                            <th>Acción</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($tablas as $t): ?>

                        <?php
                        $total = (
                            is_numeric($t['datos']) &&
                            is_numeric($t['indices'])
                        )
                            ? (float)$t['datos'] + (float)$t['indices']
                            : null;
                        ?>

                        <tr>
                            <td>
                                <strong><?= mt04_e($t['nombre']) ?></strong>
                            </td>

                            <td><?= mt04_e(mt04_filas($t['filas'])) ?></td>

                            <td><?= mt04_e(mt04_bytes($t['datos'])) ?></td>

                            <td><?= mt04_e(mt04_bytes($t['indices'])) ?></td>

                            <td><?= mt04_e(mt04_bytes($total)) ?></td>

                            <td>
                                <?= mt04_e(
                                    $t['analizada'] !== null
                                        ? $t['analizada']
                                        : 'Sin registro'
                                ) ?>
                            </td>

                            <td>
                                <form
                                    method="post"
                                    onsubmit="return confirm(
                                        '¿Confirma actualizar las estadísticas?\\n\\n' +
                                        'Base: <?= mt04_e(addslashes($bd)) ?>\\n' +
                                        'Tabla: <?= mt04_e(addslashes($t['nombre'])) ?>\\n\\n' +
                                        'Se ejecutará ANALYZE TABLE. ' +
                                        'No elimina filas ni reconstruye la tabla.'
                                    );"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= mt04_e($_SESSION['csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="tabla"
                                        value="<?= mt04_e($t['nombre']) ?>"
                                    >

                                    <button class="mt04-boton" type="submit">
                                        Actualizar estadísticas
                                    </button>
                                </form>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

<?php endif; ?>

<!-- HISTORIAL PERSISTENTE -->

<div class="mt04-caja">
    <h2>Historial de mantenimiento</h2>

    <p class="mt04-nota">
        Últimas diez ejecuciones registradas por esta consola.
        Este historial no representa todas las tareas automáticas del servidor.
    </p>

    <?php if (!$historial): ?>

        <p>Sin registro de operaciones anteriores.</p>

    <?php else: ?>

        <div class="mt04-scroll">
            <table class="mt04-tabla">
                <thead>
                    <tr>
                        <th>Fecha y hora de inicio</th>
                        <th>Motor</th>
                        <th>Base</th>
                        <th>Tabla</th>
                        <th>Acción</th>
                        <th>Resultado</th>
                        <th>Duración</th>
                        <th>Detalle</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($historial as $h): ?>

                    <tr>
                        <td><?= mt04_e($h['fecha_inicio'] ?? 'No disponible') ?></td>
                        <td><?= mt04_e($h['motor'] ?? 'No disponible') ?></td>
                        <td><?= mt04_e($h['base'] ?? 'No disponible') ?></td>
                        <td><?= mt04_e($h['tabla'] ?? 'No disponible') ?></td>
                        <td><?= mt04_e($h['accion'] ?? 'No disponible') ?></td>

                        <td class="<?= ($h['resultado'] ?? '') === 'OK' ? 'mt04-ok' : 'mt04-error' ?>">
                            <?= mt04_e($h['resultado'] ?? 'ERROR') ?>
                        </td>

                        <td>
                            <?= mt04_e($h['duracion_seg'] ?? 'No disponible') ?>
                            <?= isset($h['duracion_seg']) ? ' s' : '' ?>
                        </td>

                        <td><?= mt04_e($h['detalle'] ?? 'Sin detalle') ?></td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<!-- EXPLICACIÓN TÉCNICA -->

<div class="mt04-caja mt04-explicacion">
    <h2>Explicación del mantenimiento</h2>

    <ul>
        <li>
            <strong>ANALYZE TABLE:</strong>
            actualiza estadísticas utilizadas por el optimizador
            de consultas de MariaDB.
        </li>

        <li>
            <strong>Filas estimadas:</strong>
            TABLE_ROWS puede ser una estimación, especialmente con InnoDB.
            No equivale necesariamente a COUNT(*).
        </li>

        <li>
            <strong>Tamaños:</strong>
            DATA_LENGTH e INDEX_LENGTH representan valores de espacio
            aproximados informados por el catálogo.
        </li>

        <li>
            <strong>Fecha de actualización:</strong>
            UPDATE_TIME no es un registro fiable de la última ejecución
            de ANALYZE. Puede aparecer como Sin registro.
        </li>

        <li>
            <strong>Control de errores:</strong>
            la operación solo se considera correcta si MariaDB devuelve
            un estado OK y no devuelve filas de error.
        </li>

        <li>
            <strong>Seguridad:</strong>
            se utiliza un token CSRF, se valida la tabla contra el catálogo
            autorizado y se bloquean operaciones simultáneas sobre la misma
            tabla. No se aceptan consultas SQL del navegador.
        </li>

        <li>
            <strong>Bitácora:</strong>
            cada intento procesado se registra en un archivo privado,
            incluyendo las solicitudes rechazadas y su duración.
        </li>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
