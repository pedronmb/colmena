<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Services\InvgateSyncCoordinator;

$root = dirname(__DIR__);
require $root . '/src/Bootstrap.php';
Bootstrap::registerAutoload($root);

if (($argv[1] ?? null) === '--while-running') {
    $coordinator = new InvgateSyncCoordinator($argv[2]);
    echo json_encode([
        'request' => $coordinator->request(1060),
        'manual' => $coordinator->run(false, static function (): array {
            throw new RuntimeException('La ejecución manual no debe empezar con el bloqueo ocupado.');
        }, 1060),
        'result' => $coordinator->status(1060)['result'],
    ]);
    exit;
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo 'OK ' . $label . PHP_EOL;
}

$dbPath = tempnam(sys_get_temp_dir(), 'colmena-sync-');
if ($dbPath === false) {
    throw new RuntimeException('No se pudo crear la base temporal.');
}
$directory = dirname($dbPath) . '/.' . basename($dbPath) . '.invgate-sync';
$coordinator = new InvgateSyncCoordinator($dbPath);
$success = static fn (): array => ['ok' => true, 'errors' => []];

try {
    check(!InvgateSyncCoordinator::isEnabled([]), 'El worker está desactivado por defecto');
    check($coordinator->run(true, $success, 100) === null, 'El worker queda inactivo si no hay solicitudes');
    check($coordinator->request(100) === 'queued' && $coordinator->request(110) === 'queued',
        'Dos solicitudes comparten una sola entrada');
    check($coordinator->run(true, $success, 220) === null, 'Un pedido duplicado no extiende el vencimiento de dos minutos');

    $coordinator->request(1000);
    $first = $coordinator->run(true, static function () use ($dbPath, $success): array {
        $child = proc_open([PHP_BINARY, __FILE__, '--while-running', $dbPath], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($child)) {
            throw new RuntimeException('No se pudo iniciar el segundo proceso PHP.');
        }
        fclose($pipes[0]);
        $response = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($child) === 0 && json_decode($response, true) === [
            'request' => 'busy', 'manual' => null, 'result' => 'running',
        ], 'El widget y el CLI manual respetan el bloqueo de otro proceso. ' . $errors);
        return $success();
    }, 1059);
    check($first === $success() && $coordinator->status(1060)['result'] === 'success', 'Un intento completo queda registrado');
    check($coordinator->request(1100) === 'recent', 'Reabrir pronto el widget no vuelve a encolar el trabajo');

    check($coordinator->request(1900) === 'queued' && $coordinator->run(true, $success, 1900) === null,
        'El pedido de los 15 minutos espera si cron inició la sincronización anterior unos segundos tarde');
    check($coordinator->run(true, $success, 1959) === $success() && $coordinator->run(true, $success, 1960) === null,
        'El próximo tick elegible procesa el pedido una sola vez');

    $coordinator->request(2859);
    $coordinator->run(false, static fn (): array => ['ok' => true, 'errors' => [['person_id' => 1]]], 2860);
    check($coordinator->run(true, $success, 2861) === null && $coordinator->status(2861)['result'] === 'partial',
        'Una ejecución manual consume la solicitud pendiente y registra los resultados parciales');

    try {
        $coordinator->run(false, static function (): array {
            throw new RuntimeException('InvGate no responde');
        }, 3000);
        throw new LogicException('Se esperaba un error del proveedor.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'InvGate no responde' && $coordinator->status(3001) === [
            'last_attempt_at' => gmdate(DATE_ATOM, 3000), 'result' => 'failed', 'queued' => false,
        ], 'La hora y el resultado quedan disponibles después de un fallo de InvGate');
    }
} finally {
    foreach (['state.json', 'run.lock'] as $name) {
        if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
    }
    if (is_dir($directory)) rmdir($directory);
    unlink($dbPath);
}
