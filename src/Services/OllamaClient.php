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

    public function __construct(string $baseUrl, string $model, int $timeout = 120, int $numPredict = 4096)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('Ollama base_url vacío.');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'http://' . $baseUrl;
        }
        $this->baseUrl = $baseUrl;
        $this->model = trim($model);
        $this->timeout = max(10, $timeout);
        $this->numPredict = max(256, $numPredict);

        if ($this->model === '') {
            throw new \InvalidArgumentException('Ollama model vacío.');
        }
    }

    public function model(): string
    {
        return $this->model;
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
                $attemptLabel = $label . ($payloadIndex > 0 ? '-retry' . ($payloadIndex + 1) : '');
                OllamaResponseLogger::logRequest($attemptLabel, $endpointUrl, $payload, $this->timeout, [
                    'model' => $this->model,
                    'api' => 'generate',
                    'json_format' => $jsonFormat && $payloadIndex === 0,
                    'payload_attempt' => $payloadIndex + 1,
                    'num_predict' => $this->numPredict,
                ]);

                $request = $this->request($endpointUrl, $payload);
                $rawBody = (string) ($request['raw'] ?? '');
                $meta = [
                    'model' => $this->model,
                    'endpoint' => $endpointUrl,
                    'json_format' => $jsonFormat && $payloadIndex === 0,
                    'http_code' => $request['http_code'],
                    'payload_attempt' => $payloadIndex + 1,
                    'num_predict' => $this->numPredict,
                ];

                if ($request['ok']) {
                    try {
                        $extracted = $this->parseResponse($rawBody);
                        OllamaResponseLogger::log($label, $rawBody, $extracted, $meta);

                        return $extracted;
                    } catch (\Throwable $e) {
                        $doneReason = $this->extractDoneReason($rawBody);
                        OllamaResponseLogger::log($label, $rawBody, null, array_merge($meta, [
                            'parse_error' => $e->getMessage(),
                            'done_reason' => $doneReason,
                        ]));

                        if (
                            $payloadIndex + 1 < count($payloads)
                            && $this->shouldRetryPayload($e, $doneReason, $jsonFormat)
                        ) {
                            $lastParseError = $e;
                            continue;
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
                    break;
                }
                if ($request['http_code'] === 400 && $payloadIndex + 1 < count($payloads)) {
                    continue;
                }
                if ($request['http_code'] !== 404) {
                    throw new \RuntimeException($request['error']);
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
     * @return list<string>
     */
    private function endpointCandidates(): array
    {
        if (str_ends_with($this->baseUrl, '/api/generate') || str_ends_with($this->baseUrl, '/generate')) {
            return [$this->baseUrl];
        }

        if (str_ends_with($this->baseUrl, '/api')) {
            return [$this->baseUrl . '/generate', preg_replace('#/api$#', '/generate', $this->baseUrl) ?: ($this->baseUrl . '/generate')];
        }

        return [
            $this->baseUrl . '/generate',
            $this->baseUrl . '/api/generate',
        ];
    }

    /**
     * @return list<string>
     */
    private function chatEndpointCandidates(): array
    {
        if (str_ends_with($this->baseUrl, '/api/chat') || str_ends_with($this->baseUrl, '/chat')) {
            return [$this->baseUrl];
        }

        if (str_ends_with($this->baseUrl, '/api')) {
            return [$this->baseUrl . '/chat'];
        }

        return [
            $this->baseUrl . '/api/chat',
            $this->baseUrl . '/chat',
        ];
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
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
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
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                'Respuesta de Ollama inválida (JSON).'
                . OllamaJsonParser::responsePreview($raw)
            );
        }

        $response = $this->extractResponseText($decoded);
        if ($response === '') {
            $reason = $this->extractDoneReason($raw);
            $suffix = OllamaJsonParser::responsePreview($raw);
            if ($reason !== null && $reason !== '') {
                $suffix .= ' done_reason=' . $reason . '.';
            }

            throw new \RuntimeException(
                'Ollama no devolvió contenido en "response".' . $suffix
            );
        }

        return $response;
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

        $message = $decoded['message'] ?? null;
        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            $text = trim($message['content']);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function extractDoneReason(string $raw): ?string
    {
        $decoded = json_decode($raw, true);
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
        if (!$jsonFormat) {
            return false;
        }

        return $this->isEmptyResponseError($e) || in_array($doneReason, ['length', 'load'], true);
    }

    private function shouldTryChatFallback(\Throwable $e): bool
    {
        return $this->isEmptyResponseError($e);
    }

    private function isEmptyResponseError(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'no devolvió contenido en "response"');
    }
}
