<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cliente HTTP para InvGate Service Desk (API v1).
 */
final class InvgateClient
{
    /** @var string */
    private $apiBase;

    /** @var string */
    private $user;

    /** @var string */
    private $password;

    /** @var int */
    private $limit;

    public function __construct(string $serverUrl, string $user, string $password, int $limit = 100)
    {
        $base = rtrim(trim($serverUrl), '/');
        if ($base === '') {
            throw new \InvalidArgumentException('InvGate server_url vacío.');
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $this->apiBase = $base . '/api/v1';
        $this->user = $user;
        $this->password = $password;
        $this->limit = max(1, min(500, $limit));
    }

    /**
     * Solicitudes abiertas asignadas a un agente (todas las páginas).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchIncidentsByAgent(int $agentId): array
    {
        if ($agentId <= 0) {
            throw new \InvalidArgumentException('ID de agente InvGate inválido.');
        }

        $all = [];
        $pageKey = null;

        do {
            $query = [
                'id' => $agentId,
                'limit' => $this->limit,
            ];
            if ($pageKey !== null && $pageKey !== '') {
                $query['page_key'] = $pageKey;
            }

            $url = $this->apiBase . '/incidents.by.agent?' . http_build_query($query);
            $raw = $this->request('GET', $url);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Respuesta InvGate inválida (JSON).');
            }

            $page = $this->parseIncidentsPage($decoded);
            foreach ($page['incidents'] as $incident) {
                if (is_array($incident)) {
                    $all[] = $incident;
                }
            }

            $pageKey = $page['next_page_key'];
        } while ($pageKey !== null && $pageKey !== '');

        return $all;
    }

    /**
     * Comentarios / respuestas de un incidente (request).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchIncidentComments(int $requestId): array
    {
        if ($requestId <= 0) {
            throw new \InvalidArgumentException('request_id InvGate inválido.');
        }

        $url = $this->apiBase . '/incident.comment?' . http_build_query([
            'request_id' => $requestId,
            'date_format' => 'iso8601',
            'decoded_special_characters' => 1,
        ]);
        $raw = $this->request('GET', $url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta InvGate inválida (JSON).');
        }

        return $this->parseCommentsList($decoded);
    }

    /**
     * Incidente completo por id.
     *
     * InvGate documenta este request como `/incident/?id={id}`.
     *
     * @return array<string, mixed>
     */
    public function fetchIncidentById(int $incidentId): array
    {
        if ($incidentId <= 0) {
            throw new \InvalidArgumentException('incidentId InvGate inválido.');
        }

        $url = $this->apiBase . '/incident/?' . http_build_query([
            'id' => $incidentId,
            // Un parámetro podría no existir en tu instancia; no asumir que responde mejor.
            // Dejamos sin otros parámetros para mantener compatibilidad.
        ]);

        $raw = $this->request('GET', $url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta InvGate inválida (JSON).');
        }

        // Algunos deployments devuelven el incidente envuelto en un objeto/clave distinta.
        // Intentamos normalizar para que el resto del código consuma el mismo formato.
        foreach (['incident', 'request', 'data', 'result'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        // Si viene como lista, nos quedamos con el primer elemento.
        if (array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded[0];
        }

        return $decoded;
    }

    /**
     * Categorías de incidentes (paginado).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchCategories(): array
    {
        $all = [];
        $page = 1;
        $pageSize = $this->limit;

        do {
            $rows = $this->fetchList('/categories', [
                'page' => $page,
                'page_size' => $pageSize,
            ]);

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $count = count($rows);
            $page++;
        } while ($count === $pageSize);

        return $all;
    }

    /**
     * Tipos de incidente.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchIncidentTypes(): array
    {
        return $this->fetchList('/incident.attributes.type');
    }

    /**
     * Estados de incidente.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchIncidentStatuses(): array
    {
        return $this->fetchList('/incident.attributes.status');
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function parseCommentsList(array $decoded): array
    {
        if ($decoded === []) {
            return [];
        }

        foreach (['comments', 'replies', 'messages'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $this->normalizeCommentsList($decoded[$key]);
            }
        }

        if (array_is_list($decoded)) {
            return $this->normalizeCommentsList($decoded);
        }

        throw new \RuntimeException('Formato de comentarios InvGate inesperado.');
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeCommentsList(array $rows): array
    {
        $comments = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $comments[] = $row;
            }
        }

        return $comments;
    }

    /**
     * @param array<mixed> $decoded
     * @return array{incidents: list<array<string, mixed>>, next_page_key: ?string}
     */
    private function parseIncidentsPage(array $decoded): array
    {
        if ($decoded === []) {
            return ['incidents' => [], 'next_page_key' => null];
        }

        if (isset($decoded['requests']) && is_array($decoded['requests'])) {
            return [
                'incidents' => $this->normalizeIncidentsList($decoded['requests']),
                'next_page_key' => $this->normalizePageKey($decoded['next_page_key'] ?? null),
            ];
        }

        if (!array_is_list($decoded)) {
            throw new \RuntimeException('Formato de respuesta InvGate inesperado.');
        }

        $nextPageKey = null;
        $last = $decoded[array_key_last($decoded)];
        if (is_array($last) && array_key_exists('next_page_key', $last)) {
            $nextPageKey = $this->normalizePageKey($last['next_page_key']);
        }

        return [
            'incidents' => $this->normalizeIncidentsList($decoded),
            'next_page_key' => $nextPageKey,
        ];
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeIncidentsList(array $rows): array
    {
        $incidents = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $incidents[] = $row;
            }
        }

        return $incidents;
    }

    /**
     * @param mixed $rawKey
     */
    private function normalizePageKey($rawKey): ?string
    {
        if (!is_string($rawKey)) {
            return null;
        }
        $rawKey = trim($rawKey);

        return $rawKey !== '' ? $rawKey : null;
    }

    /**
     * @param array<string, scalar|null> $query
     * @return list<array<string, mixed>>
     */
    public function fetchList(string $path, array $query = []): array
    {
        $url = $this->apiBase . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $raw = $this->request('GET', $url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta InvGate inválida (JSON).');
        }

        if ($decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $this->normalizeRowsList($decoded);
        }

        foreach (['items', 'results', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                return $this->normalizeRowsList($decoded[$key]);
            }
        }

        throw new \RuntimeException('Formato de respuesta InvGate inesperado.');
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRowsList(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function request(string $method, string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP necesita la extensión cURL para InvGate.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('No se pudo iniciar la petición HTTP a InvGate.');
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERPWD => $this->user . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        if ($method !== 'GET') {
            curl_close($ch);
            throw new \InvalidArgumentException('Método HTTP no soportado: ' . $method);
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException(
                'Error de red al contactar InvGate: ' . ($err !== '' ? $err : 'desconocido')
            );
        }

        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('InvGate rechazó la autenticación (usuario/contraseña).');
        }
        if ($code === 404) {
            throw new \RuntimeException('Endpoint InvGate no encontrado (revisá server_url).');
        }
        if ($code === 301 || $code === 302 || $code === 307 || $code === 308) {
            throw new \RuntimeException(
                'InvGate redirigió la petición (HTTP ' . $code . '). Usá https:// en server_url.'
            );
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('InvGate respondió con código HTTP ' . $code);
        }

        return (string) $raw;
    }
}
