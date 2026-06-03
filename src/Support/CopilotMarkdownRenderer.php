<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte markdown simple del Copiloto a HTML seguro.
 */
final class CopilotMarkdownRenderer
{
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

            if ($trim === '---') {
                $closeSection();
                $html .= '<hr class="copiloto-prose__hr">';
                continue;
            }

            if ($trim === '') {
                $closeList();
                continue;
            }

            if (preg_match('/^# (.+)$/', $trim, $m) === 1) {
                $closeSection();
                $html .= '<h2 class="copiloto-prose__title">' . self::inline($m[1]) . '</h2>';
                continue;
            }

            if (preg_match('/^## (.+)$/', $trim, $m) === 1) {
                $closeSection();
                $html .= '<section class="copiloto-prose__section">';
                $html .= '<h3 class="copiloto-prose__heading">' . self::inline($m[1]) . '</h3>';
                $inSection = true;
                continue;
            }

            if (preg_match('/^### (.+)$/', $trim, $m) === 1) {
                $closeList();
                $html .= '<h4 class="copiloto-prose__subheading">' . self::inline($m[1]) . '</h4>';
                continue;
            }

            if (preg_match('/^[-*] (.+)$/', $trim, $m) === 1) {
                if (!$inList) {
                    $html .= '<ul class="copiloto-prose__list">';
                    $inList = true;
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $closeList();
            $html .= '<p class="copiloto-prose__p">' . self::inline($trim) . '</p>';
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
