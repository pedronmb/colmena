<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AzureDevOpsFinalStates;
use PDO;

final class AzureWorkItemRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function exists(int $azureId): bool
    {
        if ($azureId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM azure_work_items WHERE azure_id = :id LIMIT 1');
        $stmt->execute(['id' => $azureId]);

        return (bool) $stmt->fetchColumn();
    }

    public function isFinalLocally(int $azureId, AzureDevOpsFinalStates $finalStates): bool
    {
        if ($azureId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT state FROM azure_work_items WHERE azure_id = :id LIMIT 1');
        $stmt->execute(['id' => $azureId]);
        $state = $stmt->fetchColumn();
        if ($state === false) {
            return false;
        }

        return $finalStates->isFinal(is_string($state) ? $state : null);
    }

    /**
     * Resultado de aplicar reglas de sync para un ítem de la API.
     *
     * @param array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * } $item
     * @return 'inserted'|'updated'|'updated_to_final'|'skipped_final_new'|'skipped_already_final'
     */
    public function applySyncItem(array $item, ?int $personId, AzureDevOpsFinalStates $finalStates): string
    {
        $azureId = (int) ($item['id'] ?? 0);
        if ($azureId <= 0) {
            return 'skipped_final_new';
        }

        $exists = $this->exists($azureId);
        $apiFinal = $finalStates->isFinal(isset($item['state']) ? (string) $item['state'] : null);

        if ($apiFinal) {
            if (!$exists) {
                return 'skipped_final_new';
            }
            if ($this->isFinalLocally($azureId, $finalStates)) {
                return 'skipped_already_final';
            }
            $this->upsert($item, $personId);

            return 'updated_to_final';
        }

        $this->upsert($item, $personId);

        return $exists ? 'updated' : 'inserted';
    }

    /**
     * @param array{
     *   id: int,
     *   title: string,
     *   type: string,
     *   state: string,
     *   assigned_to: string,
     *   assigned_unique_name: string,
     *   url: string,
     *   created_at: string,
     *   changed_at: string
     * } $item
     */
    public function upsert(array $item, ?int $personId): void
    {
        $azureId = (int) ($item['id'] ?? 0);
        $title = trim((string) ($item['title'] ?? ''));
        if ($azureId <= 0 || $title === '') {
            return;
        }

        $state = trim((string) ($item['state'] ?? ''));
        if ($state === '') {
            $state = '(sin estado)';
        }

        $type = trim((string) ($item['type'] ?? ''));
        $assignedTo = trim((string) ($item['assigned_to'] ?? ''));
        $assignedUnique = trim((string) ($item['assigned_unique_name'] ?? ''));
        $url = trim((string) ($item['url'] ?? ''));
        $createdAt = trim((string) ($item['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = null;
        }
        $changedAt = trim((string) ($item['changed_at'] ?? ''));
        if ($changedAt === '') {
            $changedAt = '0';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO azure_work_items (
                azure_id, person_id, title, work_item_type, state,
                assigned_to, assigned_unique_name, url, created_at, changed_at,
                synced_at, removed_at
             ) VALUES (
                :azure_id, :person_id, :title, :work_item_type, :state,
                :assigned_to, :assigned_unique_name, :url, :created_at, :changed_at,
                datetime(\'now\'), NULL
             )
             ON CONFLICT(azure_id) DO UPDATE SET
                person_id = excluded.person_id,
                title = excluded.title,
                work_item_type = excluded.work_item_type,
                state = excluded.state,
                assigned_to = excluded.assigned_to,
                assigned_unique_name = excluded.assigned_unique_name,
                url = excluded.url,
                created_at = excluded.created_at,
                changed_at = excluded.changed_at,
                synced_at = datetime(\'now\'),
                removed_at = NULL'
        );
        $stmt->execute([
            'azure_id' => $azureId,
            'person_id' => $personId,
            'title' => $title,
            'work_item_type' => $type !== '' ? $type : null,
            'state' => $state,
            'assigned_to' => $assignedTo !== '' ? $assignedTo : null,
            'assigned_unique_name' => $assignedUnique !== '' ? $assignedUnique : null,
            'url' => $url !== '' ? $url : null,
            'created_at' => $createdAt,
            'changed_at' => $changedAt,
        ]);
    }

    /**
     * @param array<int, true> $apiIdsSet
     * @return list<array{azure_id: int}>
     */
    public function listForReconciliation(array $apiIdsSet, AzureDevOpsFinalStates $finalStates): array
    {
        $placeholders = $finalStates->sqlNotInPlaceholders();
        $lowerNames = $finalStates->namesLower();
        if ($lowerNames === []) {
            return [];
        }

        $sql = 'SELECT azure_id FROM azure_work_items
                WHERE removed_at IS NULL
                  AND LOWER(state) NOT IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($lowerNames);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $azureId = isset($row['azure_id']) ? (int) $row['azure_id'] : 0;
            if ($azureId <= 0) {
                continue;
            }
            if (isset($apiIdsSet[$azureId])) {
                continue;
            }
            $out[] = ['azure_id' => $azureId];
        }

        return $out;
    }

    public function markRemoved(int $azureId): bool
    {
        if ($azureId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE azure_work_items
             SET removed_at = datetime(\'now\'), synced_at = datetime(\'now\')
             WHERE azure_id = :azure_id'
        );
        $stmt->execute(['azure_id' => $azureId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tablero Kanban: ítems activos (no finales, no eliminados).
     *
     * @return array{
     *   columns: list<array{state: string, items: list<array<string, mixed>>}>,
     *   sync_meta: array{last_synced_at: ?string, item_count: int}
     * }
     */
    public function listGroupedByState(AzureDevOpsFinalStates $finalStates): array
    {
        $placeholders = $finalStates->sqlNotInPlaceholders();
        $lowerNames = $finalStates->namesLower();
        if ($lowerNames === []) {
            return [
                'columns' => [],
                'sync_meta' => $this->getSyncMeta(),
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT azure_id, title, work_item_type, state, assigned_to, assigned_unique_name, url
             FROM azure_work_items
             WHERE removed_at IS NULL
               AND LOWER(state) NOT IN (' . $placeholders . ')
             ORDER BY changed_at DESC'
        );
        $stmt->execute($lowerNames);

        $byState = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $state = isset($row['state']) ? (string) $row['state'] : '(sin estado)';
            if (!isset($byState[$state])) {
                $byState[$state] = [];
            }
            $byState[$state][] = [
                'id' => isset($row['azure_id']) ? (int) $row['azure_id'] : 0,
                'title' => isset($row['title']) ? (string) $row['title'] : '',
                'type' => isset($row['work_item_type']) && $row['work_item_type'] !== null
                    ? (string) $row['work_item_type']
                    : '',
                'state' => $state,
                'assigned_to' => isset($row['assigned_to']) && $row['assigned_to'] !== null
                    ? (string) $row['assigned_to']
                    : '',
                'assigned_unique_name' => isset($row['assigned_unique_name']) && $row['assigned_unique_name'] !== null
                    ? (string) $row['assigned_unique_name']
                    : '',
                'url' => isset($row['url']) && $row['url'] !== null ? (string) $row['url'] : '',
            ];
        }

        $columns = [];
        $states = array_keys($byState);
        usort($states, [\App\Services\AzureDevOpsClient::class, 'compareStates']);
        foreach ($states as $state) {
            $columns[] = [
                'state' => $state,
                'items' => $byState[$state],
            ];
        }

        return [
            'columns' => $columns,
            'sync_meta' => $this->getSyncMeta(),
        ];
    }

    /**
     * @return array{last_synced_at: ?string, item_count: int}
     */
    public function getSyncMeta(): array
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM azure_work_items WHERE removed_at IS NULL')->fetchColumn();
        $last = $this->pdo->query('SELECT MAX(synced_at) FROM azure_work_items')->fetchColumn();
        $lastSynced = is_string($last) && $last !== '' ? $last : null;

        return [
            'last_synced_at' => $lastSynced,
            'item_count' => $count,
        ];
    }

    public function hasAnyRows(): bool
    {
        return (bool) $this->pdo->query('SELECT 1 FROM azure_work_items LIMIT 1')->fetchColumn();
    }

    /**
     * Work items activos agrupados por persona del equipo; "otros" = sin ficha en Colmena.
     *
     * @return array{
     *   groups: list<array{person: array<string, mixed>, work_items: list<array<string, mixed>>}>,
     *   others_work_items: list<array<string, mixed>>,
     *   meta: array{work_item_total: int, people_count: int}
     * }
     */
    public function listGroupedByTeam(int $teamId, AzureDevOpsFinalStates $finalStates): array
    {
        if ($teamId <= 0) {
            return [
                'groups' => [],
                'others_work_items' => [],
                'meta' => ['work_item_total' => 0, 'people_count' => 0],
            ];
        }

        $placeholders = $finalStates->sqlNotInPlaceholders();
        $lowerNames = $finalStates->namesLower();
        if ($lowerNames === []) {
            return [
                'groups' => [],
                'others_work_items' => [],
                'meta' => ['work_item_total' => 0, 'people_count' => 0],
            ];
        }

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, display_name, email, role
             FROM team_people
             WHERE team_id = :team_id
             ORDER BY display_name ASC'
        );
        $peopleStmt->execute(['team_id' => $teamId]);

        /** @var array<string, int> */
        $emailIndex = [];
        /** @var array<int, true> */
        $teamPersonIds = [];
        $peopleRows = [];
        while ($row = $peopleStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $personId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($personId < 1) {
                continue;
            }
            $peopleRows[] = $row;
            $teamPersonIds[$personId] = true;
            $email = isset($row['email']) && $row['email'] !== null ? trim((string) $row['email']) : '';
            if ($email !== '') {
                $emailIndex[strtolower($email)] = $personId;
            }
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT azure_id, title, work_item_type, state, assigned_to,
                    assigned_unique_name, url, created_at, changed_at, person_id
             FROM azure_work_items
             WHERE removed_at IS NULL
               AND LOWER(state) NOT IN (' . $placeholders . ')
             ORDER BY changed_at DESC'
        );
        $itemsStmt->execute($lowerNames);

        /** @var array<int, list<array<string, mixed>>> */
        $itemsByPersonId = [];
        /** @var list<array<string, mixed>> */
        $othersWorkItems = [];
        $workItemTotal = 0;

        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $workItemTotal++;
            $mapped = $this->mapWorkItemRow($row);
            $personId = $this->resolvePersonIdForTeamRow($row, $teamPersonIds, $emailIndex);
            if ($personId !== null) {
                if (!isset($itemsByPersonId[$personId])) {
                    $itemsByPersonId[$personId] = [];
                }
                $itemsByPersonId[$personId][] = $mapped;
            } else {
                $othersWorkItems[] = $mapped;
            }
        }

        $groups = [];
        foreach ($peopleRows as $row) {
            $personId = (int) $row['id'];
            $groups[] = [
                'person' => $this->mapPersonRow($row),
                'work_items' => $itemsByPersonId[$personId] ?? [],
            ];
        }

        return [
            'groups' => $groups,
            'others_work_items' => $othersWorkItems,
            'meta' => [
                'work_item_total' => $workItemTotal,
                'people_count' => count($peopleRows),
            ],
        ];
    }

    /**
     * Actualiza person_id en filas existentes según UPN/email del asignado.
     */
    public function backfillPersonIds(TeamPersonRepository $peopleRepo): int
    {
        $stmt = $this->pdo->query(
            'SELECT azure_id, assigned_unique_name, assigned_to, person_id
             FROM azure_work_items
             WHERE removed_at IS NULL'
        );
        $update = $this->pdo->prepare(
            'UPDATE azure_work_items SET person_id = :person_id WHERE azure_id = :azure_id'
        );
        $updated = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $azureId = isset($row['azure_id']) ? (int) $row['azure_id'] : 0;
            if ($azureId <= 0) {
                continue;
            }
            $current = isset($row['person_id']) && $row['person_id'] !== null && $row['person_id'] !== ''
                ? (int) $row['person_id']
                : null;
            $resolved = $this->resolvePersonIdFromAssignee($row, $peopleRepo);
            if ($resolved === $current) {
                continue;
            }
            $update->execute([
                'person_id' => $resolved,
                'azure_id' => $azureId,
            ]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, true> $teamPersonIds
     * @param array<string, int> $emailIndex
     */
    private function resolvePersonIdForTeamRow(array $row, array $teamPersonIds, array $emailIndex): ?int
    {
        $storedId = isset($row['person_id']) && $row['person_id'] !== null && $row['person_id'] !== ''
            ? (int) $row['person_id']
            : 0;
        if ($storedId > 0 && isset($teamPersonIds[$storedId])) {
            return $storedId;
        }

        $emailKey = $this->assigneeEmailKey($row);
        if ($emailKey !== null && isset($emailIndex[$emailKey])) {
            return $emailIndex[$emailKey];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolvePersonIdFromAssignee(array $row, TeamPersonRepository $peopleRepo): ?int
    {
        $emailKey = $this->assigneeEmailKey($row);
        if ($emailKey === null) {
            return null;
        }

        return $peopleRepo->findPersonIdByEmail($emailKey);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assigneeEmailKey(array $row): ?string
    {
        $upn = trim((string) ($row['assigned_unique_name'] ?? ''));
        if ($upn !== '') {
            return strtolower($upn);
        }
        $name = trim((string) ($row['assigned_to'] ?? ''));
        if ($name !== '' && str_contains($name, '@')) {
            return strtolower($name);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapWorkItemRow(array $row): array
    {
        $azureId = isset($row['azure_id']) ? (int) $row['azure_id'] : 0;

        return [
            'id' => $azureId,
            'azure_id' => $azureId,
            'title' => isset($row['title']) ? (string) $row['title'] : '',
            'state' => isset($row['state']) ? (string) $row['state'] : '',
            'type' => isset($row['work_item_type']) && $row['work_item_type'] !== null
                ? (string) $row['work_item_type']
                : '',
            'assigned_to' => isset($row['assigned_to']) && $row['assigned_to'] !== null
                ? (string) $row['assigned_to']
                : '',
            'assigned_unique_name' => isset($row['assigned_unique_name']) && $row['assigned_unique_name'] !== null
                ? (string) $row['assigned_unique_name']
                : '',
            'url' => isset($row['url']) && $row['url'] !== null ? (string) $row['url'] : '',
            'created_at' => isset($row['created_at']) && $row['created_at'] !== null
                ? (string) $row['created_at']
                : null,
            'changed_at' => isset($row['changed_at']) ? (string) $row['changed_at'] : '0',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPersonRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'email' => isset($row['email']) && $row['email'] !== null && trim((string) $row['email']) !== ''
                ? trim((string) $row['email'])
                : null,
            'role' => isset($row['role']) && $row['role'] !== null && trim((string) $row['role']) !== ''
                ? trim((string) $row['role'])
                : null,
        ];
    }
}
