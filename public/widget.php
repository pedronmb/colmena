<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgateSyncCoordinator;

$config = require dirname(__DIR__) . '/bootstrap_web.php';
header('Cache-Control: no-store');

$syncToken = null;
$isAdmin = false;
if (is_file($config['db']['path'])) {
    $auth = new AuthService(new UserRepository(Connection::get($config)));
    $user = $auth->currentUser();
    if ($user === null) {
        header('Location: login.php?next=widget');
        exit;
    }
    $isAdmin = $user['role'] === 'admin';
    if ($isAdmin && InvgateSyncCoordinator::isEnabled($config)) {
        $syncToken = $_SESSION['widget_invgate_sync_csrf'] ??= bin2hex(random_bytes(32));
    }
}
session_write_close();
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($syncToken !== null): ?>
        <meta name="colmena-invgate-sync" content="<?= htmlspecialchars($syncToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Resumen de Colmena</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/widget.css?v=invgate-sync-status">
    <script src="assets/js/widget.js?v=invgate-sync-status" defer></script>
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
            <div class="widget__metrics" id="widget-section-options" role="group" aria-label="Secciones del widget">
                <button type="button" class="widget__card widget__option" data-widget-section="invgate"
                    id="widget-invgate-option" aria-controls="widget-invgate-panel" aria-expanded="false">
                    <span class="widget__option-name">InvGate</span>
                    <span class="widget__metric" id="widget-invgate">—</span>
                    <span class="widget__caption" id="widget-invgate-label">Tickets abiertos</span>
                </button>
                <button type="button" class="widget__card widget__option" data-widget-section="topics"
                    id="widget-topics-option" aria-controls="widget-topics-panel" aria-expanded="false">
                    <span class="widget__option-name">Temas</span>
                    <span class="widget__metric" id="widget-topic">—</span>
                    <span class="widget__caption" id="widget-topic-label">Temas activos</span>
                </button>
            </div>
            <p class="widget__caption widget__sync-status" id="widget-invgate-sync-status" role="status" hidden></p>
            <?php if ($syncToken !== null): ?>
                <p class="widget__caption widget__sync-status" id="widget-invgate-request-status" role="status" hidden></p>
            <?php endif; ?>
            <section class="widget__card" id="widget-invgate-panel" aria-labelledby="widget-invgate-heading" hidden>
                <h2 id="widget-invgate-heading">Actividad reciente</h2>
                <p class="widget__empty" id="widget-invgate-detail-status" role="status"></p>
                <ul class="widget__list" id="widget-invgate-recent"></ul>
                <div class="widget__actions">
                    <button type="button" class="btn" id="widget-invgate-show-people">Ver los tickets por persona</button>
                    <?php if ($syncToken !== null): ?>
                        <button type="button" class="btn" id="widget-invgate-sync-button">Sincronizar InvGate</button>
                    <?php endif; ?>
                </div>
                <?php if ($isAdmin && $syncToken === null): ?>
                    <p class="widget__caption widget__sync-status">La sincronización desde el widget está desactivada en el servidor.</p>
                <?php endif; ?>
            </section>
            <section class="widget__card" id="widget-invgate-people-panel" aria-labelledby="widget-invgate-people-heading" hidden>
                <button type="button" class="btn widget__back" id="widget-invgate-back">Volver a InvGate</button>
                <div class="widget__section-head">
                    <h2 id="widget-invgate-people-heading" tabindex="-1">Tickets por persona</h2>
                    <a target="_blank" rel="noopener" href="invgate.php">Abrir en Colmena</a>
                </div>
                <p class="widget__empty" id="widget-invgate-people-detail-status" role="status"></p>
                <div class="widget__groups" id="widget-invgate-groups"></div>
            </section>
            <section class="widget__card" id="widget-topics-panel" aria-labelledby="widget-topics-heading" hidden>
                <div class="widget__section-head">
                    <h2 id="widget-topics-heading">Temas activos</h2>
                    <a target="_blank" rel="noopener" href="dashboard.php?panel=focus">Abrir en Colmena</a>
                </div>
                <p class="widget__empty" id="widget-topics-detail-status" role="status"></p>
                <ul class="widget__list" id="widget-topic-list"></ul>
                <div class="widget__section-head widget__alerts">
                    <h3>Alertas de los próximos 7 días <span class="widget__total" id="widget-alert-total"></span></h3>
                    <a target="_blank" rel="noopener" href="alerts.php">Ver alertas</a>
                </div>
                <p class="widget__empty" id="widget-alert-empty" hidden>Sin vencimientos</p>
                <ul class="widget__list" id="widget-alert-list"></ul>
            </section>
        </div>

        <footer class="widget__footer">
            <button type="button" class="widget__sign-out" id="widget-sign-out">Cerrar sesión</button>
            <p class="widget__caption" id="widget-sign-out-error" role="alert" hidden></p>
        </footer>
    </main>
</body>
</html>
