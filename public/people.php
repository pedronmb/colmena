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

        <?php
        $activeNav = 'people';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel">
            <h2 class="panel__title">Temas por tarjeta</h2>
            <p class="muted panel__lead">Cada bloque es una persona del equipo; los temas creados desde <em>Temas</em> aparecen bajo la tarjeta elegida.</p>
            <div class="form people-board-search">
                <label class="form__full">
                    Buscar persona
                    <div class="topic-person-combobox" id="peoplePersonCombobox">
                        <input
                            type="text"
                            id="peoplePersonSearch"
                            class="topic-person-combobox__input"
                            autocomplete="off"
                            placeholder="Cargando…"
                            disabled
                            aria-autocomplete="list"
                            aria-controls="peoplePersonListbox"
                            aria-expanded="false"
                            role="combobox"
                        />
                        <ul class="topic-person-combobox__list" id="peoplePersonListbox" role="listbox" hidden></ul>
                    </div>
                </label>
                <p class="muted people-board-search__hint">Escribe nombre, rol o email y elige una persona para abrir su tarjeta.</p>
            </div>
            <div id="peopleBoard" class="people-board" data-team-id="1" aria-live="polite">
                <p class="muted people-board__loading">Cargando…</p>
            </div>
        </section>
    </div>

    <div id="personCardModal" class="modal person-card-modal" hidden aria-modal="true" role="dialog" aria-labelledby="personCardModalTitle">
        <div class="modal__backdrop" data-person-card-close></div>
        <div class="modal__card modal__card--wide">
            <header class="modal__head">
                <h2 id="personCardModalTitle">Tarjeta</h2>
                <button type="button" class="icon-btn" data-person-card-close aria-label="Cerrar"><?php require __DIR__ . '/includes/icon-close.php'; ?></button>
            </header>
            <div id="personCardModalDetails" class="person-card-modal__details muted"></div>
            <div id="personCardModalToolbar" class="person-card-modal__toolbar" hidden>
                <a class="btn btn--small" id="personCardModalNewTopic" href="index.php">Nuevo tema</a>
                <button type="button" class="btn btn--small" id="personCardModalToggleDone">Mostrar realizados</button>
            </div>
            <div id="personCardModalTopics" class="person-card-modal__topics" aria-live="polite"></div>
        </div>
    </div>

    <div id="personTopicEditModal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="personTopicEditTitle">
        <div class="modal__backdrop" data-pte-close></div>
        <div class="modal__card modal__card--wide">
            <header class="modal__head">
                <h2 id="personTopicEditTitle">Editar tema</h2>
                <button type="button" class="icon-btn" data-pte-close aria-label="Cerrar"><?php require __DIR__ . '/includes/icon-close.php'; ?></button>
            </header>
            <form id="personTopicEditForm" class="form">
                <div id="personTopicEditError" class="form-error" hidden role="alert"></div>
                <input type="hidden" name="topic_id" id="pteTopicId" value="">
                <input type="hidden" name="team_id" id="pteTeamId" value="1">
                <label>
                    Título
                    <input name="title" id="pteTitle" type="text" required maxlength="200" autocomplete="off">
                </label>
                <label>
                    Descripción
                    <textarea name="body" id="pteBody" rows="4" placeholder="Contexto o criterios"></textarea>
                </label>
                <label>
                    Prioridad (urgencia)
                    <select name="priority" id="ptePriority">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium">Media</option>
                        <option value="high">Alta</option>
                        <option value="critical">Crítica</option>
                    </select>
                </label>
                <label>
                    Importancia
                    <select name="importance" id="pteImportance">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium">Media</option>
                        <option value="high">Alta</option>
                        <option value="very_high">Muy alta</option>
                    </select>
                </label>
                <label id="ptePersonWrap" class="form__full" hidden>
                    Persona (tarjeta)
                    <select id="ptePersonSelect" aria-label="Persona asignada al tema"></select>
                </label>
                <input type="hidden" id="ptePersonId" value="">
                <footer class="form__actions">
                    <button type="button" class="btn" data-pte-close>Cancelar</button>
                    <button type="submit" class="btn primary" id="pteSubmit">
                        <span class="btn__label">Guardar</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/people.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
