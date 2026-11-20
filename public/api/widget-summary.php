<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\InvgateSyncCoordinator;
use App\Services\WidgetSummaryService;
use App\Support\PersonalTeamBootstrap;

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    $respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

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

    $teamId = PersonalTeamBootstrap::teamId($config, $auth);
    if (!(new TeamRepository($pdo))->isMember($teamId, (int) $user['id'])) {
        $respond(403, ['ok' => false, 'error' => 'Ya no tenés acceso a este espacio de trabajo']);
        exit;
    }
    session_write_close();

    $now = new DateTimeImmutable();
    $summary = (new WidgetSummaryService($pdo))->forTeam($teamId, $now, $config);
    if (InvgateSyncCoordinator::isEnabled($config)) {
        try {
            $summary['invgate_sync'] = (new InvgateSyncCoordinator($config['db']['path']))->status($now->getTimestamp());
        } catch (Throwable $error) {
            error_log('Colmena widget InvGate: ' . $error->getMessage());
            $summary['invgate_sync'] = ['last_attempt_at' => null, 'result' => 'unavailable', 'queued' => false];
        }
    }
    $respond(200, ['ok' => true] + $summary);
} catch (Throwable $error) {
    error_log('Colmena widget: ' . $error->getMessage());
    $respond(500, ['ok' => false, 'error' => 'No se pudo cargar el resumen']);
}
