<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte SENORITY.md (estructura fija) a HTML seguro para el modal de ayuda.
 */
final class SeniorityMarkdownRenderer
{
    public static function renderFromProjectRoot(): string
    {
        $path = dirname(__DIR__, 2) . '/SENORITY.md';

        if (!is_readable($path)) {
            return '<p class="muted">No se encontró <code>SENORITY.md</code>.</p>';
        }

        $markdown = file_get_contents($path);

        return $markdown !== false ? self::render($markdown) : '<p class="muted">No se pudo leer la documentación.</p>';
    }

    public static function render(string $markdown): string
    {
        $html = '';
        $inList = false;
        $inSection = false;
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        $closeList = static function () use (&$html, &$inList): void {
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
        };

        $closeSection = static function () use (&$html, &$inSection, $closeList): void {
            $closeList();
            if ($inSection) {
                $html .= '</section>';
                $inSection = false;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $closeList();
                continue;
            }

            if (preg_match('/^# (.+)$/', $trim, $m) === 1) {
                $closeSection();
                $html .= '<h2 class="seniority-help__doc-title">' . self::inline($m[1]) . '</h2>';
                continue;
            }

            if (preg_match('/^## (.+)$/', $trim, $m) === 1) {
                $closeSection();
                $html .= '<section class="seniority-help__axis-block">';
                $html .= '<h3 class="seniority-help__axis-title">' . self::inline($m[1]) . '</h3>';
                $inSection = true;
                continue;
            }

            if (preg_match('/^### (.+)$/', $trim, $m) === 1) {
                $closeList();
                $html .= '<h4 class="seniority-help__level-title">' . self::inline($m[1]) . '</h4>';
                continue;
            }

            if (strpos($trim, '* ') === 0) {
                if (!$inList) {
                    $html .= '<ul class="seniority-help__list">';
                    $inList = true;
                }
                $html .= '<li>' . self::inline(substr($trim, 2)) . '</li>';
            }
        }

        $closeSection();

        return $html;
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
    }
}
