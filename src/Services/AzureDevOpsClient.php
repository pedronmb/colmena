<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lee work items vía REST de Azure DevOps (WiQL + detalle por lotes).
 */
final class AzureDevOpsClient
{
    private const API_VERSION = '7.1';

    private const BATCH_FIELDS = 'System.Id,System.Title,System.State,System.WorkItemType,System.AssignedTo,System.CreatedDate,System.ChangedDate';

    /** WIQL por defecto para sync: solo ítems no finales. */
    public const DEFAULT_SYNC_WIQL = "SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = @project AND [System.State] NOT IN ('Complete','Done','Removed','Closed','Completed') ORDER BY [System.ChangedDate] DESC";

    /** @var string */
    private $organization;

    /** @var string */
    private $project;

    /** @var string */
    private $pat;

    /** @var int */
    private $maxItems;

    public function __construct(string $organization, string $project, string $pat, int $maxItems = 200)
    {
        $this->organization = $organization;
        $this->project = $project;
        $this->pat = $pat;
        $this->maxItems = max(1, min(500, $maxItems));
    }

    public function getOrganization(): string
    {
        return $this->organization;
    }

    public function getProject(): string
    {
        return $this->project;
    }

    /**
     * @return array{ok: true, columns: list<array{state: string, items: list<array<string, mixed>>}>}
     * @throws \RuntimeException en error de API o red
     */
    public function fetchGroupedByState(?string $wiqlOverride = null): array
    {
        $items = $this->fetchWorkItems($wiqlOverride);
        $byState = [];
        foreach ($items as $item) {
            $state = (string) ($item['state'] ?? '(sin estado)');
            if (!isset($byState[$state])) {
                $byState[$state] = [];
            }
            $byState[$state][] = $item;
        }

        $columns = [];
        $states = array_keys($byState);
        usort($states, [self::class, 'compareStates']);
        foreach ($states as $state) {
            $columns[] = [
                'state' => $state,
                'items' => $byState[$state],
            ];
        }

        return ['ok' => true, 'columns' => $columns];
    }

    /**
     * Lista plana de work items normalizados desde WIQL + batch GET.
     *
     * @return list<array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * }>
     * @throws \RuntimeException
     */
    public function fetchWorkItems(?string $wiqlOverride = null): array
    {
        $ids = $this->fetchWorkItemIds($wiqlOverride);
        if ($ids === []) {
            return [];
        }

        return $this->fetchWorkItemsByIds($ids);
    }

    /**
     * @return list<int>
     * @throws \RuntimeException
     */
    public function fetchWorkItemIds(?string $wiqlOverride = null): array
    {
        $wiql = $wiqlOverride !== null && $wiqlOverride !== ''
            ? $wiqlOverride
            : 'SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = @project ORDER BY [System.ChangedDate] DESC';

        $wiqlUrl = $this->apiBase() . '/_apis/wit/wiql?$top=' . $this->maxItems . '&api-version=' . self::API_VERSION;
        $wiqlBody = json_encode(['query' => $wiql], JSON_UNESCAPED_UNICODE);
        if ($wiqlBody === false) {
            throw new \RuntimeException('No se pudo preparar la consulta WIQL.');
        }

        $wiqlResponse = $this->request('POST', $wiqlUrl, $wiqlBody);
        $wiqlData = json_decode($wiqlResponse, true);
        if (!is_array($wiqlData)) {
            throw new \RuntimeException('Respuesta WIQL inválida.');
        }

        if (isset($wiqlData['message']) && is_string($wiqlData['message'])) {
            throw new \RuntimeException($this->shortAzureMessage($wiqlData['message']));
        }

        $workItems = $wiqlData['workItems'] ?? [];
        if (!is_array($workItems) || $workItems === []) {
            return [];
        }

        $ids = [];
        foreach ($workItems as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<int> $ids
     * @return list<array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * }>
     * @throws \RuntimeException
     */
    public function fetchWorkItemsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $orgEnc = rawurlencode($this->organization);
        $projEnc = rawurlencode($this->project);
        $base = "https://dev.azure.com/{$orgEnc}/{$projEnc}";
        $out = [];

        foreach ($this->chunkIds($ids, 200) as $chunk) {
            $idsParam = implode(',', $chunk);
            $detailUrl = "{$base}/_apis/wit/workitems?ids={$idsParam}&fields=" . self::BATCH_FIELDS
                . '&api-version=' . self::API_VERSION;
            $detailRaw = $this->request('GET', $detailUrl, null);
            $detailData = json_decode($detailRaw, true);
            if (!is_array($detailData)) {
                throw new \RuntimeException('Respuesta de work items inválida.');
            }
            if (isset($detailData['message']) && is_string($detailData['message'])) {
                throw new \RuntimeException($this->shortAzureMessage($detailData['message']));
            }
            $values = $detailData['value'] ?? [];
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $normalized = self::normalizeWorkItemRow($item, $orgEnc, $projEnc);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * }|null null si 404
     * @throws \RuntimeException en otros errores
     */
    public function fetchWorkItemById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $orgEnc = rawurlencode($this->organization);
        $projEnc = rawurlencode($this->project);
        $url = "https://dev.azure.com/{$orgEnc}/{$projEnc}/_apis/wit/workitems/{$id}?fields="
            . self::BATCH_FIELDS . '&api-version=' . self::API_VERSION;

        try {
            $raw = $this->request('GET', $url, null);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404') || str_contains($e->getMessage(), 'no encontrado')) {
                return null;
            }
            throw $e;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta de work item inválida.');
        }

        return self::normalizeWorkItemRow($data, $orgEnc, $projEnc);
    }

    private function apiBase(): string
    {
        $orgEnc = rawurlencode($this->organization);
        $projEnc = rawurlencode($this->project);

        return "https://dev.azure.com/{$orgEnc}/{$projEnc}";
    }

    /**
     * @param array<string, mixed> $item
     * @return array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * }|null
     */
    private static function normalizeWorkItemRow(array $item, string $orgEnc, string $projEnc): ?array
    {
        $f = $item['fields'] ?? [];
        if (!is_array($f)) {
            return null;
        }
        $id = isset($f['System.Id']) ? (int) $f['System.Id'] : 0;
        if ($id <= 0 && isset($item['id'])) {
            $id = (int) $item['id'];
        }
        if ($id <= 0) {
            return null;
        }

        $title = isset($f['System.Title']) ? trim((string) $f['System.Title']) : '';
        if ($title === '') {
            return null;
        }

        $state = isset($f['System.State']) ? (string) $f['System.State'] : '(sin estado)';
        $type = isset($f['System.WorkItemType']) ? (string) $f['System.WorkItemType'] : '';
        $assign = self::parseAssignedTo($f['System.AssignedTo'] ?? null);
        $createdAt = self::formatAzureDate($f['System.CreatedDate'] ?? null);
        $changedAt = self::formatAzureDate($f['System.ChangedDate'] ?? null);
        $url = "https://dev.azure.com/{$orgEnc}/{$projEnc}/_workitems/edit/{$id}";

        return [
            'id' => $id,
            'title' => $title,
            'type' => $type,
            'state' => $state,
            'assigned_to' => $assign['name'],
            'assigned_unique_name' => $assign['unique_name'],
            'url' => $url,
            'created_at' => $createdAt,
            'changed_at' => $changedAt,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function formatAzureDate($value): string
    {
        if ($value === null) {
            return '0';
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '0';
        }

        return $s;
    }

    /**
     * @param list<int> $ids
     * @return list<list<int>>
     */
    private function chunkIds(array $ids, int $size): array
    {
        if ($size < 1) {
            $size = 200;
        }
        $out = [];
        $chunk = [];
        foreach ($ids as $id) {
            $chunk[] = $id;
            if (count($chunk) >= $size) {
                $out[] = $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $out[] = $chunk;
        }

        return $out;
    }

    public static function compareStates(string $a, string $b): int
    {
        $order = [
            'Backlog / new' => 0,
            'Backlog' => 0,
            'To Do' => 1,
            'In Progress' => 2,
            'In Progess' => 2,
            'To Be Tested' => 3,
            'To Be Test' => 3,
            'In Testing' => 4,
            'Testing In Progress' => 4,
            'Tsting In Progress' => 4,
            'Blocked' => 5,
            'Resolved' => 6,
            'Completed' => 7,
            'UAT' => 8,
            'Closed' => 9,
        ];
        $ia = $order[$a] ?? 100;
        $ib = $order[$b] ?? 100;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }

        return strcasecmp($a, $b);
    }

    /**
     * @param mixed $value System.AssignedTo desde la API
     * @return array{name: string, unique_name: string}
     */
    public static function parseAssignedTo($value): array
    {
        $name = '';
        $unique = '';
        if ($value === null) {
            return ['name' => '', 'unique_name' => ''];
        }
        if (is_string($value)) {
            return ['name' => $value, 'unique_name' => ''];
        }
        if (is_array($value)) {
            if (isset($value['displayName']) && is_string($value['displayName'])) {
                $name = $value['displayName'];
            }
            if (isset($value['uniqueName']) && is_string($value['uniqueName'])) {
                $unique = $value['uniqueName'];
            }
            if ($name === '' && $unique !== '') {
                $name = $unique;
            }
        }

        return ['name' => $name, 'unique_name' => $unique];
    }

    private function request(string $method, string $url, ?string $body): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP necesita la extensión cURL para Azure DevOps.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('No se pudo iniciar la petición HTTP.');
        }

        $auth = base64_encode(':' . $this->pat);
        $headers = [
            'Authorization: Basic ' . $auth,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException('Error de red al contactar Azure DevOps: ' . ($err !== '' ? $err : 'desconocido'));
        }

        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('Azure DevOps rechazó la autenticación (PAT u organización/proyecto).');
        }
        if ($code === 404) {
            throw new \RuntimeException('Azure DevOps respondió con código HTTP 404');
        }
        if ($code < 200 || $code >= 300) {
            $decoded = json_decode((string) $raw, true);
            $msg = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
                ? $this->shortAzureMessage($decoded['message'])
                : 'Azure DevOps respondió con código HTTP ' . $code;

            throw new \RuntimeException($msg);
        }

        return (string) $raw;
    }

    private function shortAzureMessage(string $message): string
    {
        $message = trim($message);
        if (strlen($message) > 280) {
            return substr($message, 0, 277) . '…';
        }

        return $message;
    }
}
