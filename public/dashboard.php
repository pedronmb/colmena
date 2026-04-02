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
    <title>Colmena — Dashboards</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Dashboards';
        $pageLead = 'Elegí cómo ver los temas del equipo.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <nav class="app-nav" aria-label="Secciones">
            <a href="index.php" class="app-nav__link">Temas</a>
            <a href="dashboard.php" class="app-nav__link app-nav__link--active" aria-current="page">Dashboards</a>
            <a href="alerts.php" class="app-nav__link">Alertas</a>
            <a href="people.php" class="app-nav__link">Personas</a>
            <a href="people-edit.php" class="app-nav__link">Editar fichas</a>
        </nav>

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
            </div>

            <div id="dashboardPanelMatrix" class="dashboard-panel" role="tabpanel" aria-labelledby="tabMatrix">
                <p class="muted dashboard-hint">
                    Cada tema es un <strong>punto</strong> en el cuadrado: horizontal <strong>urgencia</strong> (muy baja → crítica), vertical <strong>importancia</strong> (muy baja → muy alta). Pasá el cursor o el foco para ver el título. Editá en <a href="index.php">Temas</a>.
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
        </section>
    </div>

    <input type="hidden" id="dashboardTeamId" value="1">

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/dashboard.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
