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
    <title>Colmena — Bloc personal</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'Bloc personal';
        $pageLead = 'Notas de texto solo para tu cuenta y archivos que puedes subir y descargar cuando quieras.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'scratchpad';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel">
            <h2 class="panel__title">Texto</h2>
            <p class="muted panel__lead">Escribe líneas o párrafos; se guardan tal cual en tu espacio personal.</p>
            <div id="scratchpadError" class="form-error" hidden role="alert"></div>
            <div class="scratchpad-editor">
                <textarea
                    id="scratchpadContent"
                    class="scratchpad-editor__field"
                    rows="18"
                    maxlength="200000"
                    placeholder="Apuntes, listas, enlaces…"
                    aria-label="Bloc de notas personales"
                ></textarea>
            </div>
            <footer class="form__actions form__actions--block">
                <button type="button" class="btn primary" id="scratchpadSave">Guardar texto</button>
                <span class="muted" id="scratchpadStatus" aria-live="polite"></span>
            </footer>
        </section>

        <section class="panel">
            <h2 class="panel__title">Archivos</h2>
            <p class="muted panel__lead">Máximo 100 MB por archivo. Tipos permitidos: PDF, imágenes, Office/OpenDocument, ZIP, texto y CSV.</p>
            <div id="filesError" class="form-error" hidden role="alert"></div>
            <form id="fileUploadForm" class="form form--inline scratchpad-upload">
                <label>
                    Subir archivo
                    <input name="file" type="file" required>
                </label>
                <footer class="form__actions">
                    <button type="submit" class="btn primary" id="fileUploadSubmit">Subir</button>
                </footer>
            </form>
            <div class="scratchpad-files-wrap">
                <p class="muted" id="filesLoading">Cargando archivos…</p>
                <div id="filesListRoot" hidden></div>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/scratchpad.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
