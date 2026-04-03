<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? '';
$pageLead = $pageLead ?? '';
$isAdminHeader = isset($user) && (($user['role'] ?? '') === 'admin');
$usersHeaderActive = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'users.php';

?>
<header class="top top--bar app-header">
    <div class="app-header__intro">
        <?php require __DIR__ . '/app-brand.php'; ?>
        <div class="app-header__titles">
            <h1 class="app-header__title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($pageLead !== '') { ?>
                <p class="muted app-header__lead"><?= htmlspecialchars($pageLead, ENT_QUOTES, 'UTF-8') ?></p>
            <?php } ?>
        </div>
    </div>
    <div class="top__actions">
        <?php require __DIR__ . '/theme-toggle.php'; ?>
        <?php if ($isAdminHeader) { ?>
        <a href="users.php" class="btn<?= $usersHeaderActive ? ' btn--header-nav-active' : '' ?>"<?= $usersHeaderActive ? ' aria-current="page"' : '' ?>>Usuarios</a>
        <?php } ?>
        <button type="button" class="btn" id="logoutBtn" title="Cerrar sesión">Salir</button>
    </div>
</header>
<?php require __DIR__ . '/alerts-flash.php'; ?>
