<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;

$dbExists = file_exists($config['db']['path']);
$user = null;
if ($dbExists) {
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $user = $auth->currentUser();
}

if (!$dbExists || $user === null) {
    header('Location: login.php');
    exit;
}

if ($user['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Colmena — Usuarios</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Usuarios';
        $pageLead = 'Alta de cuentas con acceso a la aplicación';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'users';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel panel--form">
            <h2 class="panel__title">Nuevo usuario</h2>
            <p class="muted panel__lead">Cada cuenta obtiene su propio espacio de trabajo (equipo personal). Opcionalmente podés añadir también a otro equipo para compartir datos con quien ya sea miembro de ese equipo.</p>
            <form id="userCreateForm" class="form">
                <div id="userFormError" class="form-error" hidden role="alert"></div>
                <label>
                    Email
                    <input name="email" type="email" required maxlength="200" autocomplete="off" placeholder="correo@empresa.com">
                </label>
                <label>
                    Nombre visible
                    <input name="display_name" type="text" required maxlength="200" autocomplete="off" placeholder="Nombre y apellidos">
                </label>
                <label>
                    Contraseña
                    <input name="password" type="password" required minlength="8" maxlength="200" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                </label>
                <label>
                    Rol en la aplicación
                    <select name="role">
                        <option value="member" selected>Miembro</option>
                        <option value="lead">Líder</option>
                        <option value="admin">Administrador</option>
                        <option value="viewer">Solo lectura</option>
                    </select>
                </label>
                <label>
                    Disponibilidad
                    <select name="availability">
                        <option value="available" selected>Disponible</option>
                        <option value="busy">Ocupado</option>
                        <option value="away">Ausente</option>
                        <option value="offline">Desconectado</option>
                    </select>
                </label>
                <label>
                    También unir a otro equipo (opcional)
                    <select name="team_id" id="userTeamId">
                        <option value="0" selected>Solo espacio personal (recomendado)</option>
                    </select>
                </label>
                <label>
                    Rol en el equipo
                    <select name="role_in_team">
                        <option value="member" selected>Miembro</option>
                        <option value="lead">Líder de equipo</option>
                        <option value="owner">Propietario</option>
                    </select>
                </label>
                <footer class="form__actions">
                    <button type="submit" class="btn primary" id="userSubmit">
                        <span class="btn__label">Crear usuario</span>
                    </button>
                </footer>
            </form>
        </section>

        <section class="panel">
            <h2 class="panel__title">Usuarios registrados</h2>
            <div id="usersListWrap" class="users-list-wrap" aria-live="polite">
                <p class="muted users-list__loading">Cargando…</p>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/users.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
