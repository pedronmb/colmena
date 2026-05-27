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
        $pageLead = 'Tickets sincronizados en la base local, agrupados por persona del equipo.';
        require __DIR__ . '/includes/header-app.php';
        ?>

        <?php
        $activeNav = 'invgate';
        require __DIR__ . '/includes/app-nav.php';
        ?>

        <section class="panel invgate-page">
            <h2 class="panel__title">Tickets por persona</h2>
            <p class="muted panel__lead">Los datos provienen del sync CLI (<code>database/sync_invgate_tickets.php</code>). Las personas con ID InvGate configurado aparecen aunque no tengan tickets abiertos.</p>
            <p class="invgate-meta muted" id="invgateMeta" aria-live="polite" hidden></p>
            <div id="invgateListWrap" class="invgate-list-wrap">
                <p class="muted" id="invgateLoading">Cargando…</p>
                <article id="invgateDetail" class="invgate-detail" hidden aria-live="polite"></article>
                <div id="invgateRoot" hidden></div>
            </div>
        </section>
    </div>

    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/invgate.js" defer></script>
    <script src="assets/js/app-shell.js" defer></script>
</body>
</html>
