<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Services\InvgateSyncCoordinator;

$root = dirname(__DIR__);
require $root . '/src/Bootstrap.php';
Bootstrap::registerAutoload($root);

if (($argv[1] ?? null) === '--while-running') {
    $coordinator = new InvgateSyncCoordinator($argv[2]);
    $mustNotRun = static function (): array {
        throw new RuntimeException('La sincronización no debe empezar con el bloqueo ocupado.');
    };
    echo json_encode([
        'widget' => $coordinator->run(true, $mustNotRun, 1060)['state'],
        'manual' => $coordinator->run(false, $mustNotRun, 1060)['state'],
        'result' => $coordinator->status()['result'],
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
    check(!InvgateSyncCoordinator::isEnabled([]), 'La sincronización desde el widget está desactivada por defecto');
    check($coordinator->status() === ['last_attempt_at' => null, 'result' => null], 'Estado antes del primer intento');

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
            'widget' => 'busy', 'manual' => 'busy', 'result' => 'running',
        ], 'El widget y el CLI manual respetan el bloqueo de otro proceso. ' . $errors);
        return $success();
    }, 1059);
    $finished = ['state' => 'finished', 'result' => $success()];
    check($first === $finished && $coordinator->status()['result'] === 'success',
        'La primera solicitud del widget ejecuta y registra el trabajo directamente');
    $mustNotRun = static function (): array {
        throw new LogicException('Una solicitud dentro del intervalo no debe sincronizar.');
    };
    check($coordinator->run(true, $mustNotRun, 1100) === ['state' => 'recent', 'retry_after' => 859],
        'Reabrir pronto el widget conserva el intervalo');
    check($coordinator->run(true, $mustNotRun, 1958) === ['state' => 'recent', 'retry_after' => 1]
        && $coordinator->run(true, $success, 1959) === $finished, 'El widget vuelve a sincronizar al cumplirse los 15 minutos');

    $coordinator->run(false, static fn (): array => ['ok' => true, 'errors' => [['person_id' => 1]]], 1960);
    check($coordinator->run(true, $mustNotRun, 1961)['state'] === 'recent' && $coordinator->status()['result'] === 'partial',
        'Una ejecución manual registra resultados parciales y reinicia el intervalo del widget');

    file_put_contents($directory . '/state.json', json_encode([
        'pending_at' => 2860, 'last_attempt_at' => 1960, 'result' => 'partial',
    ], JSON_THROW_ON_ERROR));
    check($coordinator->run(true, $success, 2860) === $finished && $coordinator->status()['result'] === 'success',
        'El estado de la versión anterior se puede reutilizar');

    try {
        $coordinator->run(true, static function (): array {
            throw new RuntimeException('InvGate no responde');
        }, 3760);
        throw new LogicException('Se esperaba un error del proveedor.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'InvGate no responde' && $coordinator->status() === [
            'last_attempt_at' => gmdate(DATE_ATOM, 3760), 'result' => 'failed',
        ], 'La hora y el resultado quedan disponibles después de un fallo de InvGate');
    }

    file_put_contents($directory . '/state.json', json_encode(['last_attempt_at' => 3800, 'result' => 'running'], JSON_THROW_ON_ERROR));
    check($coordinator->status()['result'] === 'failed', 'Un proceso interrumpido queda marcado como fallido');
} finally {
    foreach (['state.json', 'run.lock'] as $name) {
        if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
    }
    if (is_dir($directory)) rmdir($directory);
    unlink($dbPath);
}
