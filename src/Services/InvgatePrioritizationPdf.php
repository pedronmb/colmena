<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/** PDF A4 con fuentes estándar: no requiere servicios ni librerías externas. */
final class InvgatePrioritizationPdf
{
    private const WIDTHS = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    ];

    /** @var list<string> */
    private array $pages = [];
    private string $stream = '';
    private float $y = 0;

    /** @param array<string, mixed> $person
     *  @param list<array<string, mixed>> $tickets
     */
    public function render(array $person, array $tickets): string
    {
        $this->pages = [];
        $this->stream = '';
        $generated = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
            ->format('d/m/Y H:i') . ' (Argentina)';
        $header = function () use ($person, $tickets, $generated): void {
            if ($this->stream !== '') {
                $this->pages[] = $this->stream;
            }
            $this->stream = '';
            $this->text(40, 40, 'COLMENA / INVGATE', 9, true);
            $this->text(40, 72, 'Priorización de tickets', 21, true);
            $nameLines = $this->wrap($this->encode((string) ($person['display_name'] ?? 'Persona')), 515, 12);
            $top = 99;
            foreach ($nameLines as $line) {
                $this->text(40, $top, $line, 12, false, true);
                $top += 16;
            }
            $this->text(40, $top + 6, count($tickets) . ' tickets abiertos | Generado: ' . $generated, 9);
            $this->text(40, $top + 24, 'Menor número = mayor prioridad. Sin prioridad al final.', 9);
            $tableTop = $top + 39;
            $this->stream .= sprintf("0.19 0.22 0.32 rg 40 %.2F 515 25 re f\n", 842 - $tableTop - 25);
            foreach ([[47, 'Prioridad'], [117, 'Ticket'], [187, 'Título'], [467, 'Estado']] as [$x, $label]) {
                $this->text($x, $tableTop + 16, $label, 9, true, false, '1 1 1');
            }
            $this->y = $tableTop + 25;
        };
        $header();

        if ($tickets === []) {
            $this->text(47, $this->y + 23, 'Esta persona no tiene tickets abiertos.', 10);
        }
        foreach ($tickets as $index => $ticket) {
            $cells = [
                $this->wrap($this->encode($ticket['manual_priority'] === null ? 'Sin prioridad' : (string) $ticket['manual_priority']), 56, 9),
                $this->wrap($this->encode('#' . $ticket['invgate_incident_id']), 56, 9),
                $this->wrap($this->encode((string) $ticket['title']), 266, 9),
                $this->wrap($this->encode((string) ($ticket['status_name'] ?? $ticket['status_id'] ?? '-')), 74, 9),
            ];
            $lineCount = max(array_map('count', $cells));
            $offset = 0;
            while ($offset < $lineCount) {
                $available = (int) floor((786 - $this->y - 12) / 12);
                if ($available < 1) {
                    $header();
                    $available = max(1, (int) floor((786 - $this->y - 12) / 12));
                }
                $take = min($available, $lineCount - $offset);
                $height = $take * 12 + 12;
                if ($index % 2 === 0) {
                    $this->stream .= sprintf("0.96 0.97 0.98 rg 40 %.2F 515 %.2F re f\n", 842 - $this->y - $height, $height);
                }
                foreach ($cells as $column => $lines) {
                    // Repetir la referencia cuando un título continúa en otra página.
                    $start = $offset > 0 && $column < 2 ? 0 : $offset;
                    foreach (array_slice($lines, $start, $take) as $lineIndex => $line) {
                        $this->text([47, 117, 187, 467][$column], $this->y + 15 + $lineIndex * 12, $line, 9, false, true);
                    }
                }
                $this->y += $height;
                $this->stream .= sprintf("0.85 0.87 0.9 RG 0.4 w 40 %.2F m 555 %.2F l S\n", 842 - $this->y, 842 - $this->y);
                $offset += $take;
                if ($offset < $lineCount) {
                    $header();
                }
            }
        }
        $this->pages[] = $this->stream;

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $total = count($this->pages);
        foreach ($this->pages as $index => $page) {
            $this->stream = $page;
            $this->text(40, 815, 'Colmena | Prioridades locales', 8);
            $this->text(468, 815, 'Página ' . ($index + 1) . ' de ' . $total, 8);
            $pageId = count($objects) + 1;
            $streamId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
            $objects[] = '<< /Length ' . strlen($this->stream) . ">>\nstream\n" . $this->stream . 'endstream';
        }
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . count($offsets) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . count($offsets) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function encode(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return preg_replace('/[\x00-\x1f\x7f]/', '', $encoded === false ? '' : $encoded) ?? '';
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, int $size): array
    {
        $lines = [];
        $line = '';
        $lineWidth = 0.0;
        $lastSpace = null;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            $normalized = iconv('Windows-1252', 'ASCII//TRANSLIT//IGNORE', $char);
            $code = $normalized !== false && strlen($normalized) === 1
                ? ord($normalized)
                : ord($char);
            $charWidth = (self::WIDTHS[$code - 32] ?? 1000) * $size / 1000;
            if ($line !== '' && $lineWidth + $charWidth > $width) {
                if ($lastSpace !== null) {
                    $i -= strlen($line) - $lastSpace;
                    $lines[] = substr($line, 0, $lastSpace);
                } else {
                    $lines[] = $line;
                    $i--;
                }
                $line = '';
                $lineWidth = 0;
                $lastSpace = null;
                continue;
            }
            if ($line === '' && $char === ' ') {
                continue;
            }
            if ($char === ' ') {
                $lastSpace = strlen($line);
            }
            $line .= $char;
            $lineWidth += $charWidth;
        }
        if ($line !== '' || $lines === []) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function text(float $x, float $top, string $text, int $size, bool $bold = false, bool $encoded = false, string $color = '0.12 0.15 0.22'): void
    {
        $text = $encoded ? $text : $this->encode($text);
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $this->stream .= sprintf("%s rg BT /%s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $color, $bold ? 'F2' : 'F1', $size, $x, 842 - $top, $text);
    }
}
