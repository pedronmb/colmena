<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Lee work items vía REST de Azure DevOps (WiQL + detalle por lotes).
 */
final class AzureDevOpsClient
{
    private const API_VERSION = '7.1';

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

    /**
     * @return array{ok: true, columns: list<array{state: string, items: list<array<string, mixed>>}>}
     * @throws \RuntimeException en error de API o red
     */
    public function fetchGroupedByState(?string $wiqlOverride = null): array
    {
        $orgEnc = rawurlencode($this->organization);
        $projEnc = rawurlencode($this->project);
        $base = "https://dev.azure.com/{$orgEnc}/{$projEnc}";

        $wiql = $wiqlOverride !== null && $wiqlOverride !== ''
            ? $wiqlOverride
            : 'SELECT [System.Id] FROM WorkItems WHERE [System.TeamProject] = @project ORDER BY [System.ChangedDate] DESC';

        $wiqlUrl = "{$base}/_apis/wit/wiql?\$top={$this->maxItems}&api-version=" . self::API_VERSION;
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
            return ['ok' => true, 'columns' => []];
        }

        $ids = [];
        foreach ($workItems as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['ok' => true, 'columns' => []];
        }

        // System.Url no es válido en el parámetro `fields` de este API (TF51535).
        $fields = 'System.Id,System.Title,System.State,System.WorkItemType,System.AssignedTo';
        $byState = [];

        foreach ($this->chunkIds($ids, 200) as $chunk) {
            $idsParam = implode(',', $chunk);
            $detailUrl = "{$base}/_apis/wit/workitems?ids={$idsParam}&fields={$fields}&api-version=" . self::API_VERSION;
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
                $f = $item['fields'] ?? [];
                if (!is_array($f)) {
                    continue;
                }
                $id = isset($f['System.Id']) ? (int) $f['System.Id'] : 0;
                $title = isset($f['System.Title']) ? (string) $f['System.Title'] : '';
                $state = isset($f['System.State']) ? (string) $f['System.State'] : '(sin estado)';
                $type = isset($f['System.WorkItemType']) ? (string) $f['System.WorkItemType'] : '';
                $assigned = self::formatAssignedTo($f['System.AssignedTo'] ?? null);
                $url = $id > 0
                    ? "https://dev.azure.com/{$orgEnc}/{$projEnc}/_workitems/edit/{$id}"
                    : '';

                if (!isset($byState[$state])) {
                    $byState[$state] = [];
                }
                $byState[$state][] = [
                    'id' => $id,
                    'title' => $title,
                    'type' => $type,
                    'state' => $state,
                    'assigned_to' => $assigned,
                    'url' => $url,
                ];
            }
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

    private static function compareStates(string $a, string $b): int
    {
        // Orden del tablero (Kanban). Cualquier otro estado va al final, ordenado alfabéticamente.
        $order = [
            'Backlog' => 0,
            'To Do' => 1,
            'In Progress' => 2,
            'In Progess' => 2,
            'To Be Test' => 3,
            'Testing In Progress' => 4,
            'Tsting In Progress' => 4,
            'Blocked' => 5,
            'Completed' => 6,
            'UAT' => 7,
            'Closed' => 8,
        ];
        $ia = $order[$a] ?? 100;
        $ib = $order[$b] ?? 100;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }

        return strcasecmp($a, $b);
    }

    /**
     * @param mixed $value
     */
    private static function formatAssignedTo($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            if (isset($value['displayName']) && is_string($value['displayName'])) {
                return $value['displayName'];
            }
            if (isset($value['uniqueName']) && is_string($value['uniqueName'])) {
                return $value['uniqueName'];
            }
        }

        return '';
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
            throw new \RuntimeException('Organización o proyecto no encontrado en Azure DevOps.');
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
