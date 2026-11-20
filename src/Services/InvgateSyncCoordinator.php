<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class InvgateSyncCoordinator
{
    private const INTERVAL = 15 * 60;
    private const EMPTY_STATE = ['last_attempt_at' => null, 'result' => null];

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

    /**
     * @param callable(): array $sync
     * @return array{state: 'busy'}|array{state: 'recent', retry_after: int}|array{state: 'finished', result: array}
     */
    public function run(bool $fromWidget, callable $sync, ?int $now = null): array
    {
        $lock = $this->tryLock();
        if ($lock === null) {
            return ['state' => 'busy'];
        }

        try {
            $state = $this->readState();
            $now ??= time();
            if ($fromWidget && $state['last_attempt_at'] !== null) {
                $retryAfter = $state['last_attempt_at'] + self::INTERVAL - $now;
                if ($retryAfter > 0) {
                    return ['state' => 'recent', 'retry_after' => $retryAfter];
                }
            }

            $state = ['last_attempt_at' => $now, 'result' => 'running'];
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
            return ['state' => 'finished', 'result' => $result];
        } finally {
            fclose($lock);
        }
    }

    /** @return array{last_attempt_at: ?string, result: ?string} */
    public function status(): array
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
        ];
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
