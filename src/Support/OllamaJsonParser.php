<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Extrae y parsea JSON devuelto por Ollama (a veces con markdown o texto alrededor).
 */
final class OllamaJsonParser
{
    private const PREVIEW_LEN = 500;

    /**
     * @return array<string, mixed>
     */
    public static function decodeToArray(string $raw): array
    {
        $json = self::extractJsonString($raw);
        if ($json === null) {
            throw new \RuntimeException(
                'La respuesta de Ollama no contiene JSON parseable.' . self::responsePreview($raw)
            );
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $err = json_last_error_msg();
            throw new \RuntimeException(
                'No se pudo parsear el JSON devuelto por Ollama'
                . ($err !== '' ? ' (' . $err . ')' : '')
                . '.' . self::responsePreview($raw)
            );
        }

        return $decoded;
    }

    public static function extractJsonString(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/iu', $trimmed, $block) === 1) {
            $candidate = trim($block[1]);
            if ($candidate !== '' && self::tryDecodeArray($candidate) !== null) {
                return $candidate;
            }
        }

        $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $trimmed) ?? $trimmed;
        $stripped = trim($stripped);
        if ($stripped !== '' && self::tryDecodeArray($stripped) !== null) {
            return $stripped;
        }

        $object = self::extractBalancedObject($trimmed);
        if ($object !== null && self::tryDecodeArray($object) !== null) {
            return $object;
        }

        return null;
    }

    public static function responsePreview(string $raw): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($text === '') {
            return ' Respuesta vacía.';
        }
        if (strlen($text) > self::PREVIEW_LEN) {
            $text = rtrim(substr($text, 0, self::PREVIEW_LEN - 1)) . '…';
        }

        return ' Vista previa: ' . $text;
    }

    private static function extractBalancedObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $len = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tryDecodeArray(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
