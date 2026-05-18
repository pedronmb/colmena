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
    <title>Colmena — Dashboards</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Dashboards';
        $pageLead = 'Elegí cómo ver los temas del equipo y los perfiles en pentágono.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'dashboard';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel dashboard-page">
            <div class="topic-toolbar dashboard-toolbar dashboard-toolbar--top">
                <label class="topic-toolbar__toggle">
                    <input type="checkbox" id="dashboardShowDone" />
                    Mostrar realizados
                </label>
            </div>

            <div class="dashboard-tabs" role="tablist" aria-label="Tipo de dashboard">
                <button type="button" class="dashboard-tab dashboard-tab--active" role="tab" aria-selected="true" aria-controls="dashboardPanelMatrix" id="tabMatrix" data-panel="matrix">
                    Matriz urgencia / importancia
                </button>
                <button type="button" class="dashboard-tab" role="tab" aria-selected="false" aria-controls="dashboardPanelList" id="tabList" data-panel="list">
                    Lista
                </button>
                <button type="button" class="dashboard-tab" role="tab" aria-selected="false" aria-controls="dashboardPanelFocus" id="tabFocus" data-panel="focus">
                    Hacer hoy
                </button>
                <button type="button" class="dashboard-tab" role="tab" aria-selected="false" aria-controls="dashboardPanelCalendar" id="tabCalendar" data-panel="calendar">
                    Calendario
                </button>
                <button type="button" class="dashboard-tab" role="tab" aria-selected="false" aria-controls="dashboardPanelPentagon" id="tabPentagon" data-panel="pentagon">
                    Perfiles (pentágono)
                </button>
            </div>

            <div id="dashboardPanelMatrix" class="dashboard-panel" role="tabpanel" aria-labelledby="tabMatrix">
                <p class="muted dashboard-hint">
                    Cada tema es un <strong>punto</strong> en el cuadrado: horizontal <strong>urgencia</strong> (1 = menor … 10 = mayor), vertical <strong>importancia</strong> (1 … 10). El fondo marca cuatro <strong>cuadrantes Eisenhower</strong> (división en la mitad del rango, entre 5 y 6 en cada eje). Pasá el cursor o el foco para ver el título. Editá en <a href="index.php">Temas</a>.
                </p>
                <div id="dashboardMatrixRoot" class="matrix-plot-root" aria-live="polite"></div>
            </div>

            <div id="dashboardPanelList" class="dashboard-panel" role="tabpanel" aria-labelledby="tabList" hidden>
                <div class="edit-list-wrap">
                    <table class="data-table dashboard-table">
                        <thead>
                            <tr>
                                <th>Tema</th>
                                <th>Urgencia</th>
                                <th>Importancia</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="dashboardListBody">
                            <tr><td colspan="5" class="muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="dashboardPanelFocus" class="dashboard-panel" role="tabpanel" aria-labelledby="tabFocus" hidden>
                <p class="muted dashboard-hint">
                    Orden <strong>foco</strong>: primero por <strong>urgencia</strong> (más alta arriba), luego por <strong>importancia</strong>. Misma fuente que la matriz; respetá «Mostrar realizados» arriba. Editá en <a href="index.php">Temas</a>.
                </p>
                <div class="edit-list-wrap">
                    <table class="data-table dashboard-table">
                        <thead>
                            <tr>
                                <th>Tema</th>
                                <th>Urgencia</th>
                                <th>Importancia</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="dashboardFocusBody">
                            <tr><td colspan="5" class="muted">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="dashboardPanelCalendar" class="dashboard-panel" role="tabpanel" aria-labelledby="tabCalendar" hidden>
                <p class="muted dashboard-hint">
                    Año completo según la <strong>fecha de vencimiento</strong> de las <a href="alerts.php">alertas</a>. Los días con color tienen al menos una alerta.
                </p>
                <div class="year-cal__nav">
                    <button type="button" class="btn btn--small" id="dashboardCalPrev" aria-label="Año anterior">←</button>
                    <span id="dashboardCalYearLabel" class="year-cal__year-label" aria-live="polite"></span>
                    <button type="button" class="btn btn--small" id="dashboardCalNext" aria-label="Año siguiente">→</button>
                </div>
                <div id="dashboardCalendarRoot" class="year-cal" aria-live="polite"></div>
            </div>

            <div id="dashboardPanelPentagon" class="dashboard-panel" role="tabpanel" aria-labelledby="tabPentagon" hidden>
                <div
                    id="pentagonDashboardRoot"
                    class="pentagon-dashboard-embed"
                    data-team-id="<?= (int) $personalTeamId ?>"
                >
                    <p class="muted pentagon-dashboard__lead">
                        <span class="pentagon-dashboard__lead-text">Cada tarjeta del equipo tiene un gráfico radial con cinco ejes (escala <strong>0–10</strong>). Editá valores en <a href="people-edit.php">Editar fichas</a>.</span>
                        <?php require __DIR__ . '/includes/pentagon-help-trigger.php'; ?>
                    </p>
                    <p class="muted" id="pentagonDashboardLoading" aria-live="polite" hidden></p>
                    <div class="pentagon-dashboard__grid" id="pentagonDashboardGrid"></div>
                </div>
            </div>
        </section>
    </div>

    <input type="hidden" id="dashboardTeamId" value="<?= (int) $personalTeamId ?>">

    <?php require __DIR__ . '/includes/topic-modal.php'; ?>
    <?php require __DIR__ . '/includes/pentagon-seniority-help-modal.php'; ?>

    <div id="pentagonCardModal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="pentagonCardModalTitle">
        <div class="modal__backdrop" data-pentagon-modal-close></div>
        <div class="modal__card modal__card--wide pentagon-card-modal__card">
            <header class="modal__head">
                <h2 id="pentagonCardModalTitle">Perfil</h2>
                <button type="button" class="icon-btn" data-pentagon-modal-close aria-label="Cerrar"><?php require __DIR__ . '/includes/icon-close.php'; ?></button>
            </header>
            <div id="pentagonCardModalChart" class="pentagon-modal-chart" aria-live="polite"></div>
            <p class="muted pentagon-card-modal__footnote" id="pentagonCardModalFootnote" hidden></p>
            <p class="muted pentagon-card-modal__actions">
                <a href="people-edit.php" class="btn">Editar fichas</a>
            </p>
        </div>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/pentagon-radar-svg.js" defer></script>
    <script src="assets/js/pentagon-seniority-help.js" defer></script>
    <script src="assets/js/person-direct-team.js" defer></script>
    <script src="assets/js/pentagon-dashboard.js" defer></script>
    <script src="assets/js/dashboard.js" defer></script>
    <script src="assets/js/topics.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
