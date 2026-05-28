<?php

declare(strict_types=1);

namespace App\Services;

final class OllamaClient
{
    /** @var string */
    private $endpointUrl;

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
        $this->endpointUrl = str_ends_with($baseUrl, '/generate') ? $baseUrl : ($baseUrl . '/generate');
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

    public function generate(string $prompt): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP necesita la extensión cURL para Ollama.');
        }

        $payload = json_encode([
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('No se pudo serializar el payload para Ollama.');
        }

        $ch = curl_init($this->endpointUrl);
        if ($ch === false) {
            throw new \RuntimeException('No se pudo iniciar la petición HTTP a Ollama.');
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
            throw new \RuntimeException(
                'Error de red al contactar Ollama: ' . ($err !== '' ? $err : 'desconocido')
            );
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Ollama respondió con código HTTP ' . $code);
        }

        $decoded = json_decode((string) $raw, true);
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
