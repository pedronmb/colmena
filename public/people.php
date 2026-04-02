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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Colmena — Personas</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Personas';
        $pageLead = 'Tarjetas para agrupar temas (no son cuentas de acceso)';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <nav class="app-nav" aria-label="Secciones">
            <a href="index.php" class="app-nav__link">Temas</a>
            <a href="dashboard.php" class="app-nav__link">Dashboards</a>
            <a href="alerts.php" class="app-nav__link">Alertas</a>
            <a href="people.php" class="app-nav__link app-nav__link--active" aria-current="page">Personas</a>
            <a href="people-edit.php" class="app-nav__link">Editar fichas</a>
        </nav>

        <section class="panel panel--form panel--collapsed" id="personFormPanel">
            <div class="panel__head-row">
                <h2 class="panel__title">Nueva persona</h2>
                <button type="button" class="panel-collapse-btn" id="personFormPanelToggle" aria-expanded="false" aria-controls="personFormPanelBody" title="Desplegar formulario">
                    <span class="panel-collapse-btn__chevron" aria-hidden="true"><?php require __DIR__ . '/includes/icon-chevron-down.php'; ?></span>
                </button>
            </div>
            <div id="personFormPanelBody" class="panel__collapsible">
                <p class="muted panel__lead">Solo define la tarjeta (nombre y opcionalmente un contacto). Para crear temas usa <em>Temas</em> y eliges en qué tarjeta van.</p>
                <form id="personForm" class="form form--inline">
                    <div id="personFormError" class="form-error" hidden role="alert"></div>
                    <label>
                        Nombre en la tarjeta
                        <input name="display_name" type="text" required maxlength="120" placeholder="Ej. Ana García" autocomplete="off">
                    </label>
                    <label>
                        Contacto (opcional)
                        <input name="email" type="text" maxlength="200" autocomplete="off" placeholder="Email, teléfono o nota breve">
                    </label>
                    <label>
                        Rol (opcional)
                        <input name="role" type="text" maxlength="120" autocomplete="off" placeholder="Ej. Desarrollo, PM, Diseño…">
                    </label>
                    <label class="form__full">
                        Cumpleaños (opcional)
                        <input name="birthday" type="date">
                    </label>
                    <label class="form__full">
                        Información adicional (opcional)
                        <textarea name="extra_info" rows="3" maxlength="8000" placeholder="Notas, contexto, enlaces…"></textarea>
                    </label>
                    <input type="hidden" name="team_id" value="1">
                    <footer class="form__actions form__actions--inline">
                        <button type="submit" class="btn primary" id="personSubmit">
                            <span class="btn__label">Añadir tarjeta</span>
                        </button>
                    </footer>
                </form>
            </div>
        </section>

        <section class="panel">
            <h2 class="panel__title">Temas por tarjeta</h2>
            <p class="muted panel__lead">Cada bloque es una persona del equipo; los temas creados desde <em>Temas</em> aparecen bajo la tarjeta elegida.</p>
            <div id="peopleBoard" class="people-board" aria-live="polite">
                <p class="muted people-board__loading">Cargando…</p>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/people.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
