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
    <title>Colmena — DevOps</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'DevOps';
        $pageLead = 'Work items sincronizados desde Azure DevOps: tablero por estado o lista por persona del equipo.';
        $personalTeamId = $personalTeamId;
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'devops';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel devops-page">
            <div class="devops-page__actions">
                <button type="button" class="btn primary" id="devopsRefresh">Actualizar</button>
            </div>
            <div class="devops-tabs" role="tablist" aria-label="Secciones DevOps">
                <button type="button" class="devops-tab devops-tab--active" role="tab" aria-selected="true" aria-controls="devopsPanelBoard" id="devopsTabBoard" data-panel="board">
                    Tablero
                </button>
                <button type="button" class="devops-tab" role="tab" aria-selected="false" aria-controls="devopsPanelList" id="devopsTabList" data-panel="list">
                    Lista
                </button>
            </div>

            <div id="devopsPanelBoard" class="devops-panel" role="tabpanel" aria-labelledby="devopsTabBoard">
                <div class="devops-toolbar">
                    <div class="devops-toolbar__filter-wrap">
                        <span class="devops-toolbar__filter-label" id="devopsPersonFilterLabel">Persona</span>
                        <div class="devops-filter-combo">
                            <input
                                type="text"
                                id="devopsPersonFilter"
                                class="devops-toolbar__filter-input"
                                placeholder="Nombre o correo (UPN)…"
                                autocomplete="off"
                                spellcheck="false"
                                aria-labelledby="devopsPersonFilterLabel"
                                aria-autocomplete="list"
                                aria-controls="devopsPersonSuggestions"
                                aria-expanded="false"
                            />
                            <ul
                                id="devopsPersonSuggestions"
                                class="devops-suggestions"
                                role="listbox"
                                hidden
                                aria-label="Personas que coinciden"
                            ></ul>
                        </div>
                    </div>
                    <p class="muted devops-toolbar__hint" id="devopsMeta" aria-live="polite"></p>
                </div>
                <div id="devopsRoot" class="devops-board" aria-live="polite"></div>
            </div>

            <div id="devopsPanelList" class="devops-panel" role="tabpanel" aria-labelledby="devopsTabList" hidden>
                <h2 class="panel__title">Lista por persona</h2>
                <p class="muted panel__lead">Work items activos agrupados por fichas del equipo. La asignación usa el <strong>email</strong> de cada ficha (debe coincidir con el UPN de Azure DevOps). En <strong>Otros</strong> aparecen asignados sin coincidencia en el equipo.</p>
                <p class="muted devops-list-meta" id="devopsListMeta" aria-live="polite" hidden></p>
                <div id="devopsListRoot" class="devops-list-wrap" aria-live="polite">
                    <p class="muted" id="devopsListLoading">Cargando…</p>
                </div>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/person-direct-team.js" defer></script>
    <script src="assets/js/devops.js" defer></script>
    <script src="assets/js/devops-list.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
