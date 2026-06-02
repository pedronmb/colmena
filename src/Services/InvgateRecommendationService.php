<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OllamaJsonParser;
use App\Repositories\InvgateTicketCommentRepository;
use App\Repositories\InvgateTicketRecommendationRepository;
use App\Repositories\InvgateTicketRepository;
use App\Support\InvgateTimestamp;

final class InvgateRecommendationService
{
    /** @var OllamaClient */
    private $client;

    /** @var InvgateTicketRepository */
    private $ticketsRepo;

    /** @var InvgateTicketCommentRepository */
    private $commentsRepo;

    /** @var InvgateTicketRecommendationRepository */
    private $recommendationsRepo;

    public function __construct(
        OllamaClient $client,
        InvgateTicketRepository $ticketsRepo,
        InvgateTicketCommentRepository $commentsRepo,
        InvgateTicketRecommendationRepository $recommendationsRepo
    ) {
        $this->client = $client;
        $this->ticketsRepo = $ticketsRepo;
        $this->commentsRepo = $commentsRepo;
        $this->recommendationsRepo = $recommendationsRepo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        InvgateTicketRepository $ticketsRepo,
        InvgateTicketCommentRepository $commentsRepo,
        InvgateTicketRecommendationRepository $recommendationsRepo
    ): self {
        $ollama = self::parseOllamaConfig($config);
        $client = new OllamaClient(
            $ollama['base_url'],
            $ollama['model'],
            $ollama['timeout']
        );

        return new self($client, $ticketsRepo, $commentsRepo, $recommendationsRepo);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{base_url: string, model: string, timeout: int}
     */
    public static function parseOllamaConfig(array $config): array
    {
        $ollama = is_array($config['ollama'] ?? null) ? $config['ollama'] : [];
        $baseUrl = trim((string) ($ollama['base_url'] ?? ''));
        $model = trim((string) ($ollama['model'] ?? 'llama3.1'));
        $timeout = (int) ($ollama['timeout'] ?? 120);

        if ($baseUrl === '') {
            throw new \RuntimeException('Ollama no está configurado en config.php (ollama.base_url).');
        }
        if ($model === '') {
            throw new \RuntimeException('Ollama no está configurado en config.php (ollama.model).');
        }

        return [
            'base_url' => $baseUrl,
            'model' => $model,
            'timeout' => max(10, $timeout),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   tickets_open: int,
     *   tickets_skipped: int,
     *   tickets_total: int,
     *   tickets_ok: int,
     *   tickets_failed: int,
     *   errors: list<array{ticket_id: int, request_id: int, error: string}>
     * }
     */
    public function run(): array
    {
        $tickets = $this->ticketsRepo->listForRecommendationSync();
        $result = [
            'ok' => true,
            'tickets_open' => count($tickets),
            'tickets_skipped' => 0,
            'tickets_total' => 0,
            'tickets_ok' => 0,
            'tickets_failed' => 0,
            'errors' => [],
        ];

        foreach ($tickets as $ticket) {
            if (!$this->shouldRegenerateRecommendation($ticket)) {
                $result['tickets_skipped']++;
                continue;
            }

            $result['tickets_total']++;
            $ticketId = (int) $ticket['id'];
            $requestId = (int) $ticket['invgate_incident_id'];

            try {
                $prompt = $this->buildPrompt($ticketId, $ticket);
                $raw = $this->client->generate($prompt, true);
                $parsed = $this->parseGeneratedText($raw);
                $this->recommendationsRepo->upsert(
                    $ticketId,
                    $parsed['summary'],
                    $parsed['recommendation'],
                    $this->client->model()
                );
                $result['tickets_ok']++;
            } catch (\Throwable $e) {
                $this->recommendationsRepo->upsertError($ticketId, $e->getMessage(), $this->client->model());
                $result['tickets_failed']++;
                $result['errors'][] = [
                    'ticket_id' => $ticketId,
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($result['tickets_failed'] > 0 && $result['tickets_ok'] === 0) {
            $result['ok'] = false;
        }

        return $result;
    }

    /**
     * @param array{
     *   last_update: string,
     *   generated_at: ?string,
     *   existing_summary: ?string,
     *   existing_recommendation: ?string
     * } $ticket
     */
    private function shouldRegenerateRecommendation(array $ticket): bool
    {
        $summary = trim((string) ($ticket['existing_summary'] ?? ''));
        $recommendation = trim((string) ($ticket['existing_recommendation'] ?? ''));
        if ($summary === '' || $recommendation === '') {
            return true;
        }

        $generatedAt = trim((string) ($ticket['generated_at'] ?? ''));
        if ($generatedAt === '') {
            return true;
        }

        $lastUpdateTs = InvgateTimestamp::epochSeconds($ticket['last_update'] ?? null);
        $generatedTs = InvgateTimestamp::epochSeconds($generatedAt);
        if ($lastUpdateTs === null || $generatedTs === null) {
            return true;
        }

        return $lastUpdateTs >= $generatedTs;
    }

    /**
     * @param array{id: int, invgate_incident_id: int, title: string, description: ?string} $ticket
     */
    private function buildPrompt(int $ticketId, array $ticket): string
    {
        $description = isset($ticket['description']) && $ticket['description'] !== null
            ? $this->normalizeText((string) $ticket['description'])
            : '';
        $comments = $this->commentsRepo->listByTicketId($ticketId);

        $commentsText = [];
        foreach ($comments as $comment) {
            $message = $this->normalizeText((string) ($comment['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $commentsText[] = '- ' . $message;
        }

        $description = $this->cutText($description, 3000);
        $commentsJoined = $this->cutText(implode("\n", $commentsText), 8000);

        if ($commentsJoined === '') {
            $commentsJoined = '(sin comentarios)';
        }
        if ($description === '') {
            $description = '(sin descripción)';
        }

        return "Sos un analista de soporte IT. Te paso un ticket de InvGate.\n"
            . "Respondé únicamente con JSON válido (sin markdown y sin texto extra) con esta estructura exacta:\n"
            . "{\"resumen\":\"...\",\"recomendacion\":\"...\"}\n\n"
            . "Reglas:\n"
            . "- Escribir en español claro.\n"
            . "- Resumen breve de la situación actual.\n"
            . "- Recomendación accionable con próximos pasos.\n"
            . "- No inventar datos que no estén en el ticket.\n\n"
            . "Título:\n" . $this->cutText($this->normalizeText((string) $ticket['title']), 400) . "\n\n"
            . "Descripción:\n" . $description . "\n\n"
            . "Comentarios:\n" . $commentsJoined;
    }

    /**
     * @return array{summary: string, recommendation: string}
     */
    private function parseGeneratedText(string $raw): array
    {
        $decoded = OllamaJsonParser::decodeToArray($raw);

        $summary = $this->pickStringByPaths($decoded, [
            ['resumen'],
            ['summary'],
            ['data', 'resumen'],
            ['data', 'summary'],
            ['result', 'resumen'],
            ['result', 'summary'],
            ['output', 'resumen'],
            ['output', 'summary'],
        ]);
        $recommendation = $this->pickStringByPaths($decoded, [
            ['recomendacion'],
            ['recommendation'],
            ['next_steps'],
            ['data', 'recomendacion'],
            ['data', 'recommendation'],
            ['data', 'next_steps'],
            ['result', 'recomendacion'],
            ['result', 'recommendation'],
            ['output', 'recomendacion'],
            ['output', 'recommendation'],
        ]);
        if ($summary === '' || $recommendation === '') {
            throw new \RuntimeException('El JSON de Ollama no incluye resumen y recomendacion válidos.');
        }

        return [
            'summary' => $this->cutText($summary, 2000),
            'recommendation' => $this->cutText($recommendation, 2500),
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $paths
     */
    private function pickStringByPaths(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($data, $path);
            if ($value === null) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     * @return mixed
     */
    private function valueByPath(array $data, array $path)
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    private function cutText(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLen - 1)) . '…';
    }
}
