<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    return;
}
if (empty($_SESSION['flash_due_alerts'])) {
    return;
}
unset($_SESSION['flash_due_alerts']);

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\AlertRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

try {
    if (!file_exists($config['db']['path'])) {
        return;
    }
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $user = $auth->currentUser();
    if ($user === null) {
        return;
    }
    $teamId = 1;
    $repo = new AlertRepository($pdo);
    $list = $repo->listDueForBanner($teamId);
    if ($list === []) {
        return;
    }
} catch (Throwable $e) {
    return;
}

$today = new DateTimeImmutable('today');
?>
<section class="flash-alerts-banner" role="region" aria-label="Recordatorios del día">
    <div class="flash-alerts-banner__inner">
        <h2 class="flash-alerts-banner__title">Recordatorios</h2>
        <p class="flash-alerts-banner__lead muted">Hoy es la fecha de cumplimiento de los siguientes recordatorios.</p>
        <ul class="flash-alerts-banner__list">
            <?php foreach ($list as $a) {
                $due = DateTimeImmutable::createFromFormat('Y-m-d', $a['due_date']) ?: $today;
                $label = $due->format('d/m/Y');
                $badge = 'Hoy';
                $badgeClass = 'flash-alerts-banner__badge flash-alerts-banner__badge--today';
                ?>
                <li class="flash-alerts-banner__item">
                    <span class="<?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="flash-alerts-banner__date"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <strong class="flash-alerts-banner__item-title"><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (!empty($a['body'])) { ?>
                        <span class="flash-alerts-banner__body muted"><?= htmlspecialchars((string) $a['body'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
        <p class="flash-alerts-banner__actions">
            <a href="alerts.php" class="btn btn--small">Gestionar alertas</a>
            <button type="button" class="btn btn--small" data-dismiss-flash-alerts>Cerrar</button>
        </p>
    </div>
</section>
<script>
(function () {
    document.querySelector("[data-dismiss-flash-alerts]")?.addEventListener("click", function () {
        var el = document.querySelector(".flash-alerts-banner");
        if (el) el.hidden = true;
    });
})();
</script>
