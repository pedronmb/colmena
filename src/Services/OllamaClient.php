<?php

declare(strict_types=1);

namespace App\Services;

final class OllamaClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $model;

    /** @var int */
    private $timeout;

    public function __construct(string $baseUrl, string $model, int $timeout = 120)
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

        if ($this->model === '') {
            throw new \InvalidArgumentException('Ollama model vacío.');
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $prompt, bool $jsonFormat = false): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP necesita la extensión cURL para Ollama.');
        }

        $body = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
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

        foreach ($endpoints as $endpointUrl) {
            foreach ($payloads as $payloadIndex => $payload) {
                $request = $this->request($endpointUrl, $payload);
                if ($request['ok']) {
                    return $this->parseResponse((string) $request['raw']);
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
                'raw' => '',
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
            throw new \RuntimeException('Respuesta de Ollama inválida (JSON).');
        }

        $response = isset($decoded['response']) ? trim((string) $decoded['response']) : '';
        if ($response === '') {
            throw new \RuntimeException('Ollama no devolvió contenido en "response".');
        }

        return $response;
    }
}
