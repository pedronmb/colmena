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
    <title>Colmena — Editar fichas</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Editar fichas';
        $pageLead = 'Modifica nombre, contacto, cumpleaños e información de cada persona';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <nav class="app-nav" aria-label="Secciones">
            <a href="index.php" class="app-nav__link">Temas</a>
            <a href="dashboard.php" class="app-nav__link">Dashboards</a>
            <a href="alerts.php" class="app-nav__link">Alertas</a>
            <a href="people.php" class="app-nav__link">Personas</a>
            <a href="people-edit.php" class="app-nav__link app-nav__link--active" aria-current="page">Editar fichas</a>
        </nav>

        <section class="panel">
            <h2 class="panel__title">Listado</h2>
            <p class="muted panel__lead">Pulsa <strong>Editar</strong> para abrir el formulario.</p>
            <input type="hidden" id="editTeamId" value="1">
            <div id="editListWrap" class="edit-list-wrap">
                <p class="muted" id="editListLoading">Cargando…</p>
                <table class="data-table" id="editListTable" hidden>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Contacto</th>
                            <th>Cumpleaños</th>
                            <th>Notas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="editListBody"></tbody>
                </table>
            </div>
        </section>
    </div>

    <div id="editModal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="editModalTitle">
        <div class="modal__backdrop" data-edit-close></div>
        <div class="modal__card modal__card--wide">
            <header class="modal__head">
                <h2 id="editModalTitle">Editar persona</h2>
                <button type="button" class="icon-btn" data-edit-close aria-label="Cerrar"><?php require __DIR__ . '/includes/icon-close.php'; ?></button>
            </header>
            <form id="editForm" class="form">
                <div id="editFormError" class="form-error" hidden role="alert"></div>
                <input type="hidden" name="id" id="editPersonId">
                <input type="hidden" name="team_id" id="editTeamIdField" value="1">
                <label>
                    Nombre en la tarjeta
                    <input name="display_name" id="editDisplayName" type="text" required maxlength="120" autocomplete="off">
                </label>
                <label>
                    Contacto (opcional)
                    <input name="email" id="editEmail" type="text" maxlength="200" autocomplete="off" placeholder="Email, teléfono…">
                </label>
                <label>
                    Rol (opcional)
                    <input name="role" id="editRole" type="text" maxlength="120" autocomplete="off" placeholder="Ej. Desarrollo, PM…">
                </label>
                <label>
                    Cumpleaños (opcional)
                    <input name="birthday" id="editBirthday" type="date">
                </label>
                <label>
                    Información adicional (opcional)
                    <textarea name="extra_info" id="editExtraInfo" rows="5" maxlength="8000" placeholder="Notas, enlaces, contexto…"></textarea>
                </label>
                <footer class="form__actions">
                    <button type="button" class="btn" data-edit-close>Cancelar</button>
                    <button type="submit" class="btn primary" id="editSubmit">
                        <span class="btn__label">Guardar</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/people-edit.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
