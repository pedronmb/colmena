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
                            $jsonFormat
                            && $payloadIndex + 1 < count($payloads)
                            && $this->shouldRetryWithoutJsonFormat($e, $doneReason)
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

        $response = isset($decoded['response']) ? trim((string) $decoded['response']) : '';
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

    private function shouldRetryWithoutJsonFormat(\Throwable $e, ?string $doneReason): bool
    {
        if (str_contains($e->getMessage(), 'no devolvió contenido en "response"')) {
            return true;
        }

        return in_array($doneReason, ['length', 'load'], true);
    }
}
