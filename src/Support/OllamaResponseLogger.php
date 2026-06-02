<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Registro en disco de respuestas crudas de Ollama (depuración).
 */
final class OllamaResponseLogger
{
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

        $file = $dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        $stamp = date('c');
        $safeLabel = preg_replace('/[^\w\-.:]+/u', '_', $label) ?: 'ollama';

        $metaLines = '';
        if ($meta !== []) {
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded !== false) {
                $metaLines = "meta:\n" . $encoded . "\n";
            }
        }

        $block = "=== {$stamp} | {$safeLabel} ===\n"
            . $metaLines
            . "raw_http:\n"
            . $rawHttpBody . "\n";

        if ($extractedResponse !== null) {
            $block .= "extracted_response:\n" . $extractedResponse . "\n";
        }

        $block .= "\n";

        @file_put_contents($file, $block, FILE_APPEND | LOCK_EX);
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
}
