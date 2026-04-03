<?php

declare(strict_types=1);

$activeNav = $activeNav ?? '';

?>
<nav class="app-nav" aria-label="Secciones">
    <a href="index.php" class="app-nav__link<?= $activeNav === 'topics' ? ' app-nav__link--active' : '' ?>"<?= $activeNav === 'topics' ? ' aria-current="page"' : '' ?>>Temas</a>
    <a href="dashboard.php" class="app-nav__link<?= $activeNav === 'dashboard' ? ' app-nav__link--active' : '' ?>"<?= $activeNav === 'dashboard' ? ' aria-current="page"' : '' ?>>Dashboards</a>
    <a href="alerts.php" class="app-nav__link<?= $activeNav === 'alerts' ? ' app-nav__link--active' : '' ?>"<?= $activeNav === 'alerts' ? ' aria-current="page"' : '' ?>>Alertas</a>
    <a href="people.php" class="app-nav__link<?= $activeNav === 'people' ? ' app-nav__link--active' : '' ?>"<?= $activeNav === 'people' ? ' aria-current="page"' : '' ?>>Personas</a>
    <a href="people-edit.php" class="app-nav__link<?= $activeNav === 'people-edit' ? ' app-nav__link--active' : '' ?>"<?= $activeNav === 'people-edit' ? ' aria-current="page"' : '' ?>>Editar fichas</a>
</nav>
