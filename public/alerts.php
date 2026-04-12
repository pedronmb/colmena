<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\PersonalTeamBootstrap;

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

$personalTeamId = PersonalTeamBootstrap::teamId($config, $auth);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Colmena — Alertas</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Alertas';
        $pageLead = 'Recordatorios con fecha; al iniciar sesión solo se avisa el día de la fecha elegida';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'alerts';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel">
            <h2 class="panel__title">Nueva alerta</h2>
            <p class="muted panel__lead">La fecha es el día en que debe cumplirse el recordatorio. El aviso en pantalla aparece si falta una semana o menos, o si ya venció.</p>
            <form id="alertForm" class="form form--inline alerts-form">
                <div id="alertFormError" class="form-error" hidden role="alert"></div>
                <label>
                    Título
                    <input name="title" type="text" required maxlength="200" placeholder="Ej. Entrega informe trimestral" autocomplete="off">
                </label>
                <label>
                    Fecha de cumplimiento
                    <input name="due_date" type="date" required>
                </label>
                <label class="form__full">
                    Notas (opcional)
                    <textarea name="body" rows="2" maxlength="4000" placeholder="Detalle o enlace interno"></textarea>
                </label>
                <input type="hidden" name="team_id" value="<?= (int) $personalTeamId ?>">
                <footer class="form__actions form__actions--block">
                    <button type="button" class="btn" id="alertCancelEdit" hidden>Cancelar edición</button>
                    <button type="submit" class="btn primary" id="alertSubmit">Guardar alerta</button>
                </footer>
            </form>
        </section>

        <section class="panel">
            <h2 class="panel__title">Listado</h2>
            <div id="alertsListWrap" class="alerts-list-wrap">
                <p class="muted" id="alertsLoading">Cargando…</p>
                <div id="alertsListRoot" hidden></div>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/alerts.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
