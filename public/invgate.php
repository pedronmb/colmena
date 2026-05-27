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
    <title>Colmena — InvGate</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'InvGate';
        $pageLead = 'Tickets sincronizados en la base local y estadísticas por persona del equipo.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'invgate';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel invgate-page">
            <div class="invgate-tabs" role="tablist" aria-label="Secciones InvGate">
                <button type="button" class="invgate-tab invgate-tab--active" role="tab" aria-selected="true" aria-controls="invgatePanelTickets" id="invgateTabTickets" data-panel="tickets">
                    Tickets
                </button>
                <button type="button" class="invgate-tab" role="tab" aria-selected="false" aria-controls="invgatePanelStats" id="invgateTabStats" data-panel="stats">
                    Estadísticas
                </button>
            </div>

            <div id="invgatePanelTickets" class="invgate-panel" role="tabpanel" aria-labelledby="invgateTabTickets">
                <h2 class="panel__title">Tickets por persona</h2>
                <p class="muted panel__lead">Los datos provienen del sync CLI (<code>database/sync_invgate_tickets.php</code>). Las personas con ID InvGate configurado aparecen aunque no tengan tickets abiertos.</p>
                <p class="invgate-meta muted" id="invgateMeta" aria-live="polite" hidden></p>
                <div id="invgateListWrap" class="invgate-list-wrap">
                    <p class="muted" id="invgateLoading">Cargando…</p>
                    <article id="invgateDetail" class="invgate-detail" hidden aria-live="polite"></article>
                    <div id="invgateRoot" hidden></div>
                </div>
            </div>

            <div id="invgatePanelStats" class="invgate-panel" role="tabpanel" aria-labelledby="invgateTabStats" hidden>
                <h2 class="panel__title">Estadísticas por persona</h2>
                <p class="muted panel__lead">Métricas calculadas sobre la base local. Los cierres históricos solo incluyen tickets que estuvieron abiertos al sincronizar.</p>
                <div class="invgate-stats-toolbar">
                    <label class="invgate-stats-toolbar__field">
                        Período histórico
                        <select id="invgateStatsPeriod" class="invgate-stats-toolbar__select">
                            <option value="7">7 días</option>
                            <option value="30" selected>30 días</option>
                            <option value="all">Todo</option>
                        </select>
                    </label>
                    <label class="invgate-stats-toolbar__field">
                        Stale (días sin movimiento)
                        <input type="number" id="invgateStatsStaleDays" class="invgate-stats-toolbar__input" value="3" min="1" max="90" />
                    </label>
                    <button type="button" class="btn btn--small" id="invgateStatsRefresh">Actualizar</button>
                </div>
                <p class="invgate-meta muted" id="invgateStatsMeta" aria-live="polite" hidden></p>
                <p class="muted" id="invgateStatsLoading">Seleccioná esta pestaña para cargar estadísticas.</p>
                <div id="invgateStatsRoot" hidden></div>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/invgate-common.js" defer></script>
    <script src="assets/js/invgate.js" defer></script>
    <script src="assets/js/invgate-stats.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
