<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgateSyncCoordinator;
use App\Services\InvgateTicketSyncService;

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$responded = false;
try {
    if (!is_file($config['db']['path'])) {
        $respond(503, ['ok' => false, 'error' => 'Base de datos no disponible']);
        exit;
    }

    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $user = $auth->currentUser();
    if ($user === null) {
        $respond(401, ['ok' => false, 'error' => 'Debes iniciar sesión']);
        exit;
    }

    $sessionToken = $_SESSION['widget_invgate_sync_csrf'] ?? null;
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($user['role'] !== 'admin' || !InvgateSyncCoordinator::isEnabled($config)
        || !is_string($sessionToken) || !hash_equals($sessionToken, $requestToken)) {
        $respond(403, ['ok' => false, 'error' => 'Sin permiso para solicitar la sincronización']);
        exit;
    }
    session_write_close();

    $coordinator = new InvgateSyncCoordinator($config['db']['path']);
    $outcome = $coordinator->run(true, static function () use ($config, $pdo, $respond, &$responded): array {
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            set_time_limit(600);
        }

        if (function_exists('fastcgi_finish_request')) {
            $respond(202, ['ok' => true, 'state' => 'started']);
            $responded = true;
            fastcgi_finish_request();
        }

        $result = InvgateTicketSyncService::fromConfig(
            $config,
            new TeamPersonRepository($pdo),
            new InvgateTicketRepository($pdo)
        )->run();

        foreach ($result['errors'] as $error) {
            error_log(sprintf('Colmena widget InvGate (persona #%d): %s', $error['person_id'], $error['error']));
        }
        return $result;
    });

    if (!$responded) {
        unset($outcome['result']);
        $respond(200, ['ok' => true] + $outcome);
    }
} catch (Throwable $error) {
    error_log('Colmena widget InvGate: ' . $error->getMessage());
    if (!$responded) {
        $respond(500, ['ok' => false, 'error' => 'No se pudo ejecutar la sincronización']);
    }
}
