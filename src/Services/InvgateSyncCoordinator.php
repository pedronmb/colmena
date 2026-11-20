<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class InvgateSyncCoordinator
{
    private const INTERVAL = 15 * 60;
    private const QUEUE_TTL = 2 * 60;
    private const EMPTY_STATE = ['pending_at' => null, 'last_attempt_at' => null, 'result' => null];

    private string $directory;

    public function __construct(string $dbPath)
    {
        $path = realpath($dbPath);
        if ($path === false) {
            throw new RuntimeException('No se encontró la base de datos de Colmena.');
        }
        $this->directory = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.invgate-sync';
    }

    public static function isEnabled(array $config): bool
    {
        return ($config['invgate']['widget_sync_enabled'] ?? false) === true;
    }

    public function request(int $now): string
    {
        $lock = $this->tryLock();
        if ($lock === null) {
            return 'busy';
        }

        try {
            $state = $this->readState();
            if ($this->hasPending($state, $now)) {
                return 'queued';
            }
            // Cron can start the previous run up to a minute after the widget's request.
            // Queue the next request if it can still become eligible before expiring.
            if ($state['last_attempt_at'] !== null
                && $state['last_attempt_at'] + self::INTERVAL >= $now + self::QUEUE_TTL) {
                return 'recent';
            }
            $state['pending_at'] = $now;
            $this->writeState($state);
            return 'queued';
        } finally {
            fclose($lock);
        }
    }

    /** @param callable(): array $sync */
    public function run(bool $widgetWorker, callable $sync, ?int $now = null): ?array
    {
        if ($widgetWorker && $this->readState()['pending_at'] === null) {
            return null;
        }
        $lock = $this->tryLock();
        if ($lock === null) {
            return null;
        }

        try {
            $state = $this->readState();
            $now ??= time();
            if ($widgetWorker) {
                if (!$this->hasPending($state, $now)) {
                    if ($state['pending_at'] !== null) {
                        $state['pending_at'] = null;
                        $this->writeState($state);
                    }
                    return null;
                }
                if ($state['last_attempt_at'] !== null && $now < $state['last_attempt_at'] + self::INTERVAL) {
                    return null;
                }
            }

            $state = ['pending_at' => null, 'last_attempt_at' => $now, 'result' => 'running'];
            $this->writeState($state);
            try {
                $result = $sync();
                $state['result'] = $result['ok'] ? ($result['errors'] === [] ? 'success' : 'partial') : 'failed';
            } catch (Throwable $error) {
                $state['result'] = 'failed';
                $this->writeState($state);
                throw $error;
            }
            $this->writeState($state);
            return $result;
        } finally {
            fclose($lock);
        }
    }

    /** @return array{last_attempt_at: ?string, result: ?string, queued: bool} */
    public function status(int $now): array
    {
        $state = $this->readState();
        if ($state['result'] === 'running') {
            // If a worker was terminated, its OS lock is released without writing a result.
            $lock = $this->tryLock();
            if ($lock !== null) {
                try {
                    $state = $this->readState();
                    if ($state['result'] === 'running') {
                        $state['result'] = 'failed';
                    }
                } finally {
                    fclose($lock);
                }
            }
        }

        return [
            'last_attempt_at' => $state['last_attempt_at'] === null ? null : gmdate(DATE_ATOM, $state['last_attempt_at']),
            'result' => $state['result'],
            'queued' => $this->hasPending($state, $now),
        ];
    }

    private function hasPending(array $state, int $now): bool
    {
        return $state['pending_at'] !== null && $now < $state['pending_at'] + self::QUEUE_TTL;
    }

    /** @return resource|null */
    private function tryLock()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear el directorio de sincronización de InvGate.');
        }
        $lock = @fopen($this->directory . '/run.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo de sincronización de InvGate en ' . $this->directory . '.');
        }
        if (flock($lock, LOCK_EX | LOCK_NB, $occupied)) {
            return $lock;
        }
        fclose($lock);
        if (!$occupied) {
            throw new RuntimeException('No se pudo bloquear la sincronización de InvGate.');
        }
        return null;
    }

    private function statePath(): string
    {
        return $this->directory . '/state.json';
    }

    private function readState(): array
    {
        $path = $this->statePath();
        clearstatcache(true, $path);
        if (!is_file($path)) {
            if (is_dir($this->directory) && !is_readable($this->directory)) {
                throw new RuntimeException('No se puede leer el directorio de sincronización de InvGate: ' . $this->directory);
            }
            return self::EMPTY_STATE;
        }
        $json = @file_get_contents($path);
        $state = $json === false ? null : json_decode($json, true);
        if (!is_array($state)) {
            throw new RuntimeException('No se pudo leer el estado de sincronización de InvGate.');
        }
        return $state + self::EMPTY_STATE;
    }

    private function writeState(array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $temp = $this->directory . '/state-' . bin2hex(random_bytes(12)) . '.tmp';
        if (@file_put_contents($temp, $json) !== strlen($json) || !@rename($temp, $this->statePath())) {
            @unlink($temp);
            throw new RuntimeException('No se pudo guardar el estado de sincronización de InvGate.');
        }
    }
}
