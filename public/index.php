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

    <div id="topicModal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="modalTitle">
        <div class="modal__backdrop" data-close></div>
        <div class="modal__card modal__card--wide">
            <header class="modal__head">
                <h2 id="modalTitle">Nuevo tema</h2>
                <button type="button" class="icon-btn" data-close aria-label="Cerrar"><?php require __DIR__ . '/includes/icon-close.php'; ?></button>
            </header>
            <form id="topicForm" class="form">
                <input type="hidden" name="topic_id" id="topicIdField" value="">
                <label>
                    Título
                    <input name="title" type="text" required maxlength="200" placeholder="Ej. Revisar API de facturación" autocomplete="off">
                </label>
                <label>
                    Descripción
                    <textarea name="body" rows="4" placeholder="Contexto o criterios de aceptación"></textarea>
                </label>
                <label>
                    Prioridad (urgencia)
                    <select name="priority">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium" selected>Media</option>
                        <option value="high">Alta</option>
                        <option value="critical">Crítica</option>
                    </select>
                </label>
                <label>
                    Importancia
                    <select name="importance">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium" selected>Media</option>
                        <option value="high">Alta</option>
                        <option value="very_high">Muy alta</option>
                    </select>
                </label>
                <label class="form__full topic-person-field">
                    Persona (tarjeta)
                    <div class="topic-person-combobox" id="topicPersonCombobox">
                        <input type="hidden" name="person_id" id="topicPersonId" value="">
                        <input
                            type="text"
                            id="topicPersonSearch"
                            class="topic-person-combobox__input"
                            autocomplete="off"
                            placeholder="Escribe para buscar por nombre o rol…"
                            aria-autocomplete="list"
                            aria-controls="topicPersonListbox"
                            aria-expanded="false"
                            role="combobox"
                        />
                        <ul class="topic-person-combobox__list" id="topicPersonListbox" role="listbox" hidden></ul>
                    </div>
                </label>
                <p class="hint muted">Las tarjetas se dan de alta en <a href="people-edit.php">Editar fichas</a>; la vista por bloques está en <a href="people.php">Personas</a>.</p>
                <input type="hidden" name="team_id" value="<?= (int) $personalTeamId ?>">
                <footer class="form__actions">
                    <button type="button" class="btn" data-close>Cancelar</button>
                    <button type="submit" class="btn primary" id="submitTopic">
                        <span class="btn__label" id="submitTopicLabel">Crear tema</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/topics.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
