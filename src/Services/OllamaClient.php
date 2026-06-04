<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OllamaJsonParser;
use App\Support\OllamaResponseLogger;

final class OllamaClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $model;

    /** @var int */
    private $timeout;

    /** @var int */
    private $numPredict;

    /** @var bool */
    private $think;

    public function __construct(
        string $baseUrl,
        string $model,
        int $timeout = 120,
        int $numPredict = 4096,
        bool $think = false
    ) {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('Ollama base_url vacío.');
        }
        $this->baseUrl = $baseUrl;
        $this->model = trim($model);
        $this->timeout = max(10, $timeout);
        $this->numPredict = max(256, $numPredict);
        $this->think = $think;

        if ($this->model === '') {
            throw new \InvalidArgumentException('Ollama model vacío.');
        }
    }

    /**
     * @param array{
     *   base_url: string,
     *   model: string,
     *   timeout: int,
     *   num_predict: int,
     *   think: bool
     * } $ollama
     */
    public static function fromParsedConfig(array $ollama): self
    {
        return new self(
            $ollama['base_url'],
            $ollama['model'],
            (int) $ollama['timeout'],
            (int) $ollama['num_predict'],
            (bool) $ollama['think']
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function numPredict(): int
    {
        return $this->numPredict;
    }

    public function thinkEnabled(): bool
    {
        return $this->think;
    }

    public function generate(string $prompt, bool $jsonFormat = false, ?string $logLabel = null): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP necesita la extensión cURL para Ollama.');
        }

        $label = $logLabel !== null && trim($logLabel) !== ''
            ? trim($logLabel)
            : 'ollama-' . substr(md5($this->model . '|' . substr($prompt, 0, 200)), 0, 10);

        try {
            return $this->generateViaGenerateEndpoint($prompt, $jsonFormat, $label);
        } catch (\Throwable $e) {
            if (!$this->shouldTryChatFallback($e)) {
                throw $e;
            }

            try {
                return $this->generateViaChatEndpoint($prompt, $label);
            } catch (\Throwable $chatError) {
                throw new \RuntimeException(
                    $e->getMessage() . ' Reintento vía /api/chat falló: ' . $chatError->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function generateViaGenerateEndpoint(string $prompt, bool $jsonFormat, string $label): string
    {
        $body = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'think' => $this->think,
            'options' => [
                'num_predict' => $this->numPredict,
                'temperature' => 0.2,
            ],
        ];
        if ($jsonFormat) {
            $body['format'] = 'json';
        }

        /** @var list<string> */
        $payloads = [];
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('No se pudo serializar el payload para Ollama.');
        }
        $payloads[] = $encoded;

        if ($jsonFormat) {
            unset($body['format']);
            $fallback = json_encode($body, JSON_UNESCAPED_UNICODE);
            if ($fallback !== false) {
                $payloads[] = $fallback;
            }
        }

        $endpoints = $this->endpointCandidates();
        $lastError = '';
        $lastParseError = null;

        foreach ($endpoints as $endpointUrl) {
            foreach ($payloads as $payloadIndex => $payload) {
                for ($loadRetries = 0; ; $loadRetries++) {
                $attemptLabel = $label . ($payloadIndex > 0 ? '-retry' . ($payloadIndex + 1) : '');
                if ($loadRetries > 0) {
                    $attemptLabel .= '-load' . ($loadRetries + 1);
                }
                OllamaResponseLogger::logRequest($attemptLabel, $endpointUrl, $payload, $this->timeout, [
                    'model' => $this->model,
                    'api' => 'generate',
                    'json_format' => $jsonFormat && $payloadIndex === 0,
                    'payload_attempt' => $payloadIndex + 1,
                    'load_retry' => $loadRetries,
                    'num_predict' => $this->numPredict,
                    'think' => $this->think,
                ]);

                $request = $this->request($endpointUrl, $payload);
                $rawBody = (string) ($request['raw'] ?? '');
                $meta = [
                    'model' => $this->model,
                    'endpoint' => $endpointUrl,
                    'json_format' => $jsonFormat && $payloadIndex === 0,
                    'http_code' => $request['http_code'],
                    'payload_attempt' => $payloadIndex + 1,
                    'load_retry' => $loadRetries,
                    'num_predict' => $this->numPredict,
                ];

                if ($request['ok']) {
                    try {
                        $extracted = $this->parseResponse($rawBody);
                        OllamaResponseLogger::log($label, $rawBody, $extracted, $meta);

                        return $extracted;
                    } catch (\Throwable $e) {
                        $doneReason = $this->extractDoneReasonFromRaw($rawBody);
                        OllamaResponseLogger::log($label, $rawBody, null, array_merge($meta, [
                            'parse_error' => $e->getMessage(),
                            'done_reason' => $doneReason,
                        ]));

                        if ($doneReason === 'load' && $loadRetries < 2) {
                            usleep(2_000_000);
                            continue;
                        }

                        if (
                            $payloadIndex + 1 < count($payloads)
                            && $this->shouldRetryPayload($e, $doneReason, $jsonFormat)
                        ) {
                            $lastParseError = $e;
                            break;
                        }

                        throw $e;
                    }
                }

                if ($rawBody !== '') {
                    OllamaResponseLogger::log($label, $rawBody, null, array_merge($meta, [
                        'request_error' => $request['error'],
                    ]));
                }

                if ($request['http_code'] === 404) {
                    break 2;
                }
                if ($request['http_code'] === 400 && $payloadIndex + 1 < count($payloads)) {
                    break;
                }
                if ($request['http_code'] !== 404) {
                    throw new \RuntimeException($request['error']);
                }

                break;
                }

                if ($lastParseError instanceof \Throwable && $payloadIndex + 1 < count($payloads)) {
                    continue;
                }
            }
            $lastError = $request['error'] ?? $lastError;
        }

        if ($lastParseError instanceof \Throwable) {
            throw $lastParseError;
        }

        if ($lastError === '') {
            $lastError = 'No se pudo contactar un endpoint válido de Ollama.';
        }

        throw new \RuntimeException($lastError);
    }

    private function generateViaChatEndpoint(string $prompt, string $label): string
    {
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'stream' => false,
            'think' => $this->think,
            'options' => [
                'num_predict' => $this->numPredict,
                'temperature' => 0.2,
            ],
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('No se pudo serializar el payload de chat para Ollama.');
        }

        $lastError = '';
        foreach ($this->chatEndpointCandidates() as $endpointUrl) {
            OllamaResponseLogger::logRequest($label . '-chat-fallback', $endpointUrl, $payload, $this->timeout, [
                'model' => $this->model,
                'api' => 'chat',
                'num_predict' => $this->numPredict,
                'think' => $this->think,
            ]);

            $request = $this->request($endpointUrl, $payload);
            $rawBody = (string) ($request['raw'] ?? '');
            $meta = [
                'model' => $this->model,
                'endpoint' => $endpointUrl,
                'api' => 'chat',
                'http_code' => $request['http_code'],
                'num_predict' => $this->numPredict,
            ];

            if ($request['ok']) {
                try {
                    $extracted = $this->parseResponse($rawBody);
                    OllamaResponseLogger::log($label . '-chat-fallback', $rawBody, $extracted, $meta);

                    return $extracted;
                } catch (\Throwable $e) {
                    OllamaResponseLogger::log($label . '-chat-fallback', $rawBody, null, array_merge($meta, [
                        'parse_error' => $e->getMessage(),
                        'done_reason' => $this->extractDoneReason($rawBody),
                    ]));
                    throw $e;
                }
            }

            if ($rawBody !== '') {
                OllamaResponseLogger::log($label . '-chat-fallback', $rawBody, null, array_merge($meta, [
                    'request_error' => $request['error'],
                ]));
            }

            if ($request['http_code'] === 404) {
                break;
            }
            if ($request['http_code'] !== 404) {
                throw new \RuntimeException($request['error']);
            }
            $lastError = $request['error'] ?? $lastError;
        }

        if ($lastError === '') {
            $lastError = 'No se pudo contactar un endpoint de chat de Ollama.';
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * Raíz del servidor Ollama (sin /generate ni /api/generate).
     * Ollama actual expone POST en /api/generate; /generate suele devolver 404.
     */
    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://' . $baseUrl;
        }

        $suffixes = ['/api/generate', '/api/chat', '/generate', '/chat', '/api'];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($suffixes as $suffix) {
                if (str_ends_with($baseUrl, $suffix)) {
                    $baseUrl = rtrim(substr($baseUrl, 0, -strlen($suffix)), '/');
                    $changed = true;
                    break;
                }
            }
        }

        return $baseUrl;
    }

    /**
     * @return list<string>
     */
    private function endpointCandidates(): array
    {
        return [$this->baseUrl . '/api/generate'];
    }

    /**
     * @return list<string>
     */
    private function chatEndpointCandidates(): array
    {
        return [$this->baseUrl . '/api/chat'];
    }

    /**
     * @return array{ok: bool, raw: string, http_code: int, error: string}
     */
    private function request(string $endpointUrl, string $payload): array
    {
        $ch = curl_init($endpointUrl);
        if ($ch === false) {
            return [
                'ok' => false,
                'raw' => '',
                'http_code' => 0,
                'error' => 'No se pudo iniciar la petición HTTP a Ollama.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return [
                'ok' => false,
                'raw' => '',
                'http_code' => 0,
                'error' => 'Error de red al contactar Ollama: ' . ($err !== '' ? $err : 'desconocido'),
            ];
        }
        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'raw' => (string) $raw,
                'http_code' => $code,
                'error' => 'Ollama respondió con código HTTP ' . $code . ' (' . $endpointUrl . ')',
            ];
        }

        return [
            'ok' => true,
            'raw' => (string) $raw,
            'http_code' => $code,
            'error' => '',
        ];
    }

    private function parseResponse(string $raw): string
    {
        $response = $this->extractTextFromRawBody($raw);
        if ($response !== '') {
            return $response;
        }

        $reason = $this->extractDoneReasonFromRaw($raw);
        $suffix = OllamaJsonParser::responsePreview($raw);
        if ($reason !== null && $reason !== '') {
            $suffix .= ' done_reason=' . $reason . '.';
        }

        throw new \RuntimeException(
            'Ollama no devolvió contenido en "response".' . $suffix
        );
    }

    private function extractTextFromRawBody(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, "\n")) {
            $fromLines = $this->extractFromNdjsonLines($trimmed);
            if ($fromLines !== '') {
                return $fromLines;
            }
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $single = $this->extractResponseText($decoded);
            if ($single !== '') {
                return $single;
            }
        }

        $jsonStr = OllamaJsonParser::extractJsonString($trimmed);
        if ($jsonStr !== null) {
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                $single = $this->extractResponseText($decoded);
                if ($single !== '') {
                    return $single;
                }
            }
        }

        return '';
    }

    private function extractFromNdjsonLines(string $raw): string
    {
        $aggregated = '';
        $lastDecoded = null;

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $lastDecoded = $decoded;
            $chunk = $this->extractStreamChunkText($decoded);
            if ($chunk !== '') {
                $aggregated .= $chunk;
            }
        }

        $aggregated = trim($aggregated);
        if ($aggregated !== '') {
            return $aggregated;
        }

        if ($lastDecoded !== null) {
            return $this->extractResponseText($lastDecoded);
        }

        return '';
    }

    /**
     * Fragmento incremental de /api/generate en streaming (NDJSON).
     *
     * @param array<string, mixed> $decoded
     */
    private function extractStreamChunkText(array $decoded): string
    {
        if (isset($decoded['response']) && is_string($decoded['response'])) {
            return $decoded['response'];
        }

        $message = $decoded['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractResponseText(array $decoded): string
    {
        if (isset($decoded['response']) && is_string($decoded['response'])) {
            $text = trim($decoded['response']);
            if ($text !== '') {
                return $text;
            }
        }

        if (isset($decoded['content']) && is_string($decoded['content'])) {
            $text = trim($decoded['content']);
            if ($text !== '') {
                return $text;
            }
        }

        $message = $decoded['message'] ?? null;
        if (is_array($message)) {
            foreach (['content', 'response'] as $key) {
                if (isset($message[$key]) && is_string($message[$key])) {
                    $text = trim($message[$key]);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        $messages = $decoded['messages'] ?? null;
        if (is_array($messages)) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                $entry = $messages[$i];
                if (!is_array($entry)) {
                    continue;
                }
                $role = isset($entry['role']) ? (string) $entry['role'] : '';
                if ($role !== '' && $role !== 'assistant') {
                    continue;
                }
                if (isset($entry['content']) && is_string($entry['content'])) {
                    $text = trim($entry['content']);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    private function extractDoneReasonFromRaw(string $raw): ?string
    {
        $lastReason = null;
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $reason = $decoded['done_reason'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                $lastReason = trim($reason);
            }
        }

        if ($lastReason !== null) {
            return $lastReason;
        }

        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded)) {
            return null;
        }
        $reason = $decoded['done_reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }

        return trim($reason);
    }

    private function shouldRetryPayload(\Throwable $e, ?string $doneReason, bool $jsonFormat): bool
    {
        if ($doneReason === 'load') {
            return true;
        }

        if (!$jsonFormat) {
            return false;
        }

        return $this->isEmptyResponseError($e) || $doneReason === 'length';
    }

    private function shouldTryChatFallback(\Throwable $e): bool
    {
        return $this->isEmptyResponseError($e)
            || str_contains($e->getMessage(), 'Respuesta de Ollama inválida (JSON)');
    }

    private function isEmptyResponseError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'no devolvió contenido en "response"');
    }
}
