<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header('Location: login.php');
    exit;
}

$nombre = htmlspecialchars($_SESSION['nombre'] ?? $_SESSION['usuario'], ENT_QUOTES, 'UTF-8');
$usuario = htmlspecialchars($_SESSION['usuario'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel DBA - Gestor de Prácticas</title>
    <style>
        :root {
            --green-950: #052e24;
            --green-900: #064e3b;
            --green-800: #065f46;
            --green-700: #047857;
            --green-600: #059669;
            --green-100: #d1fae5;
            --green-50: #ecfdf5;
            --page: #f1f7f4;
            --text: #253b32;
            --muted: #60776c;
            --border: #dce9e1;
            --white: #ffffff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: Arial, Helvetica, sans-serif;
            background: var(--page); color: var(--text);
        }
        .topbar {
            min-height: 66px; padding: 13px 2%;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; background: var(--green-950); color: white;
        }
        .brand { font-size: 22px; font-weight: 700; }
        .account { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .logout {
            display: inline-block; padding: 10px 16px; border-radius: 6px;
            background: #dc2626; color: white; text-decoration: none; font-weight: 700;
        }
        .logout:hover { background: #b91c1c; }
        .coursebar {
            min-height: 39px; padding: 10px 2%; display: flex;
            justify-content: space-between; gap: 10px; flex-wrap: wrap;
            background: var(--green-100); color: var(--green-900); font-size: 14px;
        }
        main { width: min(1340px, 92%); margin: 62px auto 48px; }
        h1 { margin: 0 0 22px; color: var(--green-900); font-size: 28px; }
        .intro { margin: 0 0 20px; line-height: 1.6; color: #425c50; }
        .grid {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 27px;
        }
        .card {
            min-height: 250px; padding: 27px 27px 24px;
            display: flex; flex-direction: column; align-items: flex-start;
            background: var(--white); border: 1px solid var(--border);
            border-radius: 10px; box-shadow: 0 2px 5px #052e240b;
        }
        .card h2 {
            width: 100%; margin: 0 0 20px; padding-bottom: 12px;
            border-bottom: 1px solid #e2eee7; color: var(--green-700);
            font-size: 20px; line-height: 1.4;
        }
        .card p { margin: 0 0 25px; color: var(--muted); line-height: 1.5; font-size: 16px; }
        .action {
            margin-top: auto; display: inline-block; padding: 11px 18px;
            border-radius: 5px; background: var(--green-700); color: white;
            text-decoration: none; font-weight: 700; font-size: 14px;
            transition: background .15s ease;
        }
        .action:hover { background: var(--green-900); }
        .version { color: var(--muted); text-align: center; margin-top: 30px; font-size: 13px; }
        @media (max-width: 950px) {
            main { margin-top: 38px; }
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        }
        @media (max-width: 620px) {
            .topbar { padding: 16px 5%; }
            .brand { font-size: 19px; }
            .coursebar { padding: 12px 5%; }
            main { width: 90%; margin-top: 30px; }
            h1 { font-size: 24px; }
            .grid { grid-template-columns: 1fr; }
            .card { min-height: 220px; padding: 22px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="brand">Panel DBA - Gestor de Prácticas</div>
    <div class="account">
        <span>Usuario: <strong><?= $usuario ?></strong></span>
        <a class="logout" href="logout.php">Cerrar Sesión</a>
    </div>
</header>

<div class="coursebar">
    <span><strong>CURSO:</strong> Administración de Bases de Datos</span>
    <span><strong>Profesor:</strong> Manuel Espinoza G.</span>
</div>

<main>
    <h1>Menú Principal de Tareas de Administrador</h1>
    <p class="intro">
        Bienvenido/a, <?= $nombre ?>. Seleccione una opción de administración para ejecutar
        las prácticas de PostgreSQL / MariaDB:
    </p>

    <section class="grid" aria-label="Opciones de administración">
        <article class="card">
            <h2>1. Conexión y Estado del Servidor</h2>
            <p>Verificar el estado del motor de base de datos, versión activa y parámetros de conexión (PDO / MySQLi).</p>
            <a class="action" href="conexion.php">Gestionar Conexión</a>
        </article>

        <article class="card">
            <h2>2. Gestión de Esquemas y Tablas</h2>
            <p>Crear, modificar o listar tablas, definir tipos de datos y restricciones de integridad referencial.</p>
            <a class="action" href="esquemas.php">Ver Esquemas</a>
        </article>

        <article class="card">
            <h2>3. Usuarios y Privilegios (GRANT/REVOKE)</h2>
            <p>Administrar roles, controlar accesos de usuarios y asignar permisos sobre las bases de datos.</p>
            <a class="action" href="privilegios.php">Control de Accesos</a>
        </article>

        <article class="card">
            <h2>4. Respaldos y Restauración (Dump)</h2>
            <p>Generar respaldos lógicos de bases de datos mediante comandos del sistema y exportación de estructuras.</p>
            <a class="action" href="respaldos.php">Respaldos</a>
        </article>

        <article class="card">
            <h2>5. Monitoreo y Consultas (SQL)</h2>
            <p>Ejecutar consultas de diagnóstico, revisar procesos activos y analizar planes de ejecución (EXPLAIN).</p>
            <a class="action" href="consultas.php">Consola SQL</a>
        </article>

        <article class="card">
            <h2>6. Bitácoras y Logs del Sistema</h2>
            <p>Revisar los registros de errores y auditoría del motor de base de datos desde el entorno web.</p>
            <a class="action" href="logs.php">Ver Logs</a>
        </article>
    </section>

    <p class="version">Panel DBA · Versión 1.0</p>
</main>
</body>
</html>
