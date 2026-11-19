<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;

$config = require dirname(__DIR__) . '/bootstrap_web.php';
header('Cache-Control: no-store');

if (is_file($config['db']['path'])) {
    $auth = new AuthService(new UserRepository(Connection::get($config)));
    if ($auth->currentUser() === null) {
        header('Location: login.php?next=widget');
        exit;
    }
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Resumen de Colmena</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/widget.css">
    <script src="assets/js/widget.js" defer></script>
</head>
<body class="widget-page">
    <main class="widget">
        <header class="widget__header">
            <div class="widget__brand">
                <span class="widget__mark" aria-hidden="true"><?php require __DIR__ . '/includes/logo-mark.php'; ?></span>
                <h1>Colmena</h1>
            </div>
            <button type="button" class="btn widget__refresh" id="widget-refresh">Actualizar</button>
        </header>
        <p class="widget__status" id="widget-status" role="status">Cargando resumen…</p>

        <div class="widget__sections" id="widget-summary" hidden>
            <div class="widget__metrics">
                <section class="widget__card">
                    <h2><a target="_blank" rel="noopener" href="invgate.php">InvGate</a></h2>
                    <p class="widget__metric" id="widget-invgate">—</p>
                    <p class="widget__caption" id="widget-invgate-label">Tickets abiertos</p>
                </section>
                <section class="widget__card">
                    <h2><a target="_blank" rel="noopener" href="devops.php">DevOps</a></h2>
                    <p class="widget__metric" id="widget-devops">—</p>
                    <p class="widget__caption" id="widget-devops-label">Work items activos</p>
                </section>
            </div>
            <section class="widget__card">
                <div class="widget__section-head">
                    <h2><a target="_blank" rel="noopener" href="alerts.php">Alertas</a></h2>
                    <span class="widget__total" id="widget-alert-total"></span>
                </div>
                <p class="widget__caption">Próximos 7 días</p>
                <p class="widget__empty" id="widget-alert-empty" hidden>Sin vencimientos</p>
                <ul class="widget__list" id="widget-alert-list"></ul>
            </section>
            <section class="widget__card">
                <div class="widget__section-head">
                    <h2><a target="_blank" rel="noopener" href="dashboard.php?panel=focus">Temas críticos</a></h2>
                    <span class="widget__total" id="widget-topic-total"></span>
                </div>
                <p class="widget__empty" id="widget-topic-empty" hidden>Sin temas críticos activos</p>
                <ul class="widget__list" id="widget-topic-list"></ul>
            </section>
        </div>

        <footer class="widget__footer">
            <button type="button" class="widget__sign-out" id="widget-sign-out">Cerrar sesión</button>
            <p class="widget__caption" id="widget-sign-out-error" role="alert" hidden></p>
        </footer>
    </main>
</body>
</html>
