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
    <title>Colmena — Inicio</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Temas';
        $pageLead = 'Hola, ' . $user['display_name'];
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'topics';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel">
            <div class="topic-toolbar">
                <button type="button" class="btn primary" id="openTopicModal">
                    + Nuevo tema
                </button>
                <label class="topic-toolbar__search">
                    <span class="visually-hidden">Buscar en la lista de temas</span>
                    <input type="search" id="topicSearchFilter" class="topic-toolbar__search-input" placeholder="Buscar en la lista…" autocomplete="off" />
                </label>
                <label class="topic-toolbar__person-filter">
                    <span class="visually-hidden">Filtrar por persona</span>
                    <select id="topicPersonFilter" class="topic-toolbar__search-input" aria-label="Filtrar por persona">
                        <option value="">Todas las personas</option>
                    </select>
                </label>
                <div class="topic-toolbar__toggles" role="group" aria-label="Opciones de vista">
                    <label class="topic-toolbar__toggle">
                        <input type="checkbox" id="topicViewByImportance" />
                        Ordenar por importancia
                    </label>
                    <label class="topic-toolbar__toggle">
                        <input type="checkbox" id="topicViewByPriority" />
                        Ordenar por prioridad
                    </label>
                    <label class="topic-toolbar__toggle">
                        <input type="checkbox" id="showCompletedTopics" />
                        Mostrar realizados
                    </label>
                </div>
            </div>
            <div id="topicFeed" class="topic-feed-root" aria-live="polite"></div>
        </section>
    </div>

    <?php require __DIR__ . '/includes/topic-modal.php'; ?>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/topics.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
