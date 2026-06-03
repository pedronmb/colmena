<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Registro en disco de peticiones y respuestas de Ollama (depuración).
 */
final class OllamaResponseLogger
{
    /**
     * Registra la petición saliente con payload en archivo y comando curl reproducible.
     *
     * @param array<string, mixed> $meta
     * @return string|null Ruta absoluta del archivo payload, o null si no se pudo escribir.
     */
    public static function logRequest(
        string $label,
        string $endpointUrl,
        string $payloadJson,
        int $timeoutSeconds,
        array $meta = []
    ): ?string {
        $dir = self::logDirectory();
        if ($dir === null) {
            return null;
        }

        $stamp = date('c');
        $safeLabel = self::safeLabel($label);
        $payloadFile = self::writePayloadFile($dir, $safeLabel, $payloadJson);
        $payloadPathForCurl = $payloadFile ?? self::inlinePayloadPath($payloadJson);

        $curlBash = self::formatCurlBash($endpointUrl, $payloadPathForCurl, $timeoutSeconds, $payloadFile === null);
        $curlPowerShell = self::formatCurlPowerShell($endpointUrl, $payloadPathForCurl, $timeoutSeconds, $payloadFile === null);

        $metaBlock = array_merge($meta, [
            'endpoint' => $endpointUrl,
            'timeout_seconds' => $timeoutSeconds,
            'payload_bytes' => strlen($payloadJson),
            'payload_file' => $payloadFile !== null ? self::relativeProjectPath($payloadFile) : null,
        ]);
        $encoded = json_encode($metaBlock, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $metaLines = $encoded !== false ? "meta:\n" . $encoded . "\n" : '';

        $block = "=== {$stamp} | {$safeLabel} | REQUEST ===\n"
            . $metaLines
            . "curl_bash:\n"
            . $curlBash . "\n"
            . "curl_powershell:\n"
            . $curlPowerShell . "\n\n";

        self::appendLog($dir, $block);
        self::echoCli($safeLabel, $endpointUrl, $curlBash, $payloadFile);

        return $payloadFile;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function log(
        string $label,
        string $rawHttpBody,
        ?string $extractedResponse = null,
        array $meta = []
    ): void {
        $dir = self::logDirectory();
        if ($dir === null) {
            return;
        }

        $stamp = date('c');
        $safeLabel = self::safeLabel($label);

        $metaLines = '';
        if ($meta !== []) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded !== false) {
                $metaLines = "meta:\n" . $encoded . "\n";
            }
        }

        $block = "=== {$stamp} | {$safeLabel} | RESPONSE ===\n"
            . $metaLines
            . "raw_http:\n"
            . $rawHttpBody . "\n";

        if ($extractedResponse !== null) {
            $block .= "extracted_response:\n" . $extractedResponse . "\n";
        }

        $block .= "\n";

        self::appendLog($dir, $block);
    }

    public static function logDirectory(): ?string
    {
        $base = dirname(__DIR__, 2);
        $dir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs'
            . DIRECTORY_SEPARATOR . 'ollama';

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
        }

        if (!is_writable($dir)) {
            return null;
        }

        return $dir;
    }

    private static function writePayloadFile(string $logDir, string $safeLabel, string $payloadJson): ?string
    {
        $payloadDir = $logDir . DIRECTORY_SEPARATOR . 'payloads';
        if (!is_dir($payloadDir) && !@mkdir($payloadDir, 0775, true) && !is_dir($payloadDir)) {
            return null;
        }

        $name = $safeLabel . '-' . date('Ymd-His') . '-' . substr(sha1($payloadJson), 0, 8) . '.json';
        $path = $payloadDir . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($path, $payloadJson, LOCK_EX) === false) {
            return null;
        }

        return $path;
    }

    private static function formatCurlBash(
        string $endpointUrl,
        string $payloadPathOrJson,
        int $timeoutSeconds,
        bool $inlineJson
    ): string {
        $escapedUrl = str_replace("'", "'\\''", $endpointUrl);
        if ($inlineJson) {
            $escapedPayload = str_replace("'", "'\\''", $payloadPathOrJson);

            return "curl -sS -X POST '{$escapedUrl}' "
                . "-H 'Content-Type: application/json' -H 'Accept: application/json' "
                . "--max-time {$timeoutSeconds} "
                . "-d '{$escapedPayload}'";
        }

        $escapedPath = str_replace("'", "'\\''", $payloadPathOrJson);

        return "curl -sS -X POST '{$escapedUrl}' "
            . "-H 'Content-Type: application/json' -H 'Accept: application/json' "
            . "--max-time {$timeoutSeconds} "
            . "-d @'{$escapedPath}'";
    }

    private static function formatCurlPowerShell(
        string $endpointUrl,
        string $payloadPathOrJson,
        int $timeoutSeconds,
        bool $inlineJson
    ): string {
        $escapedUrl = str_replace('"', '`"', $endpointUrl);
        if ($inlineJson) {
            $escapedPayload = str_replace('"', '`"', $payloadPathOrJson);

            return 'curl.exe -sS -X POST "' . $escapedUrl . '" '
                . '-H "Content-Type: application/json" -H "Accept: application/json" '
                . '--max-time ' . $timeoutSeconds . ' '
                . '-d "' . $escapedPayload . '"';
        }

        $path = str_replace('\\', '/', $payloadPathOrJson);
        if (!preg_match('#^[a-zA-Z]:/#', $path)) {
            $path = str_replace('\\', '/', realpath($payloadPathOrJson) ?: $payloadPathOrJson);
        }
        $escapedPath = str_replace('"', '`"', $path);

        return 'curl.exe -sS -X POST "' . $escapedUrl . '" '
            . '-H "Content-Type: application/json" -H "Accept: application/json" '
            . '--max-time ' . $timeoutSeconds . ' '
            . '-d "@' . $escapedPath . '"';
    }

    /** Si no hay archivo, devuelve el JSON inline (solo payloads pequeños). */
    private static function inlinePayloadPath(string $payloadJson): string
    {
        if (strlen($payloadJson) <= 4000) {
            return $payloadJson;
        }

        return substr($payloadJson, 0, 3997) . '...';
    }

    private static function safeLabel(string $label): string
    {
        return preg_replace('/[^\w\-.:]+/u', '_', $label) ?: 'ollama';
    }

    private static function appendLog(string $dir, string $block): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        @file_put_contents($file, $block, FILE_APPEND | LOCK_EX);
    }

    private static function relativeProjectPath(string $absolutePath): string
    {
        $base = dirname(__DIR__, 2);
        $baseNorm = str_replace('\\', '/', $base);
        $pathNorm = str_replace('\\', '/', $absolutePath);
        if (str_starts_with($pathNorm, $baseNorm)) {
            return ltrim(substr($pathNorm, strlen($baseNorm)), '/');
        }

        return $pathNorm;
    }

    private static function echoCli(
        string $safeLabel,
        string $endpointUrl,
        string $curlBash,
        ?string $payloadFile
    ): void {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $line = '[Ollama] ' . $safeLabel . ' → ' . $endpointUrl . "\n";
        if ($payloadFile !== null) {
            $line .= '[Ollama] payload: ' . $payloadFile . "\n";
        }
        $line .= '[Ollama] curl: ' . $curlBash . "\n";
        fwrite(STDERR, $line);
    }
}
