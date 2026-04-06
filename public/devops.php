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
    <title>Colmena — DevOps</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <div class="shell shell--wide">
        <?php
        $pageTitle = 'DevOps';
        $pageLead = 'Work items de Azure DevOps agrupados por estado.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'devops';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel devops-page">
            <div class="devops-toolbar">
                <button type="button" class="btn primary" id="devopsRefresh">Actualizar</button>
                <p class="muted devops-toolbar__hint" id="devopsMeta" aria-live="polite"></p>
            </div>
            <div id="devopsRoot" class="devops-board" aria-live="polite"></div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/devops.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
