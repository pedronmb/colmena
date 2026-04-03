<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;

$dbExists = file_exists($config['db']['path']);
$alreadyIn = false;
if ($dbExists) {
    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    if ($auth->userId() !== null) {
        $alreadyIn = true;
    }
}

if ($alreadyIn) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/theme-head.php'; ?>
    <title>Iniciar sesión — Colmena</title>
    <?php require __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
    <?php require __DIR__ . '/includes/theme-toggle.php'; ?>
    <div class="shell shell--narrow">
        <?php require __DIR__ . '/includes/header-login.php'; ?>

        <?php if (!$dbExists): ?>
            <div class="banner warn">
                Inicializa la base de datos ejecutando <code>database/init.php</code> con PHP.
            </div>
        <?php endif; ?>

        <section class="panel">
            <form id="loginForm" class="form" autocomplete="on">
                <div id="loginError" class="form-error" hidden role="alert"></div>
                <label>
                    Email
                    <input name="email" type="email" required autocomplete="username" value="demo@local.test">
                </label>
                <label>
                    Contraseña
                    <input name="password" type="password" required autocomplete="current-password" value="">
                </label>
                <footer class="form__actions form__actions--block">
                    <button type="submit" class="btn primary btn--block" id="loginSubmit" <?= $dbExists ? '' : 'disabled' ?>>
                        <span class="btn__label">Entrar</span>
                    </button>
                </footer>
            </form>
        </section>
    </div>
    <script src="assets/js/theme.js" defer></script>
    <script src="assets/js/login.js" defer></script>
</body>
</html>
