<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AzureDevOpsFinalStates;
use PDO;

final class TeamHealthRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function hasTable(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
        );
        $stmt->execute(['name' => $name]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<array{
     *   id: int,
     *   display_name: string,
     *   role: ?string,
     *   is_direct_team: bool,
     *   invgate_id: ?int,
     *   email: ?string
     * }>
     */
    public function listPeople(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, role, is_direct_team, invgate_id, email
             FROM team_people
             WHERE team_id = :team_id
             ORDER BY display_name ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $people = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $people[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                'role' => $this->nullableString($row['role'] ?? null),
                'is_direct_team' => !empty($row['is_direct_team']),
                'invgate_id' => $this->optionalInt($row['invgate_id'] ?? null),
                'email' => $this->nullableString($row['email'] ?? null),
            ];
        }

        return $people;
    }

    /**
     * @return list<array{
     *   id: int,
     *   person_id: int,
     *   priority: int,
     *   importance: int,
     *   status: string
     * }>
     */
    public function listActiveTopics(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, person_id, priority, importance, status
             FROM topics
             WHERE team_id = :team_id
               AND person_id IS NOT NULL
               AND status NOT IN (\'done\', \'archived\')
             ORDER BY id ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $topics = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['person_id']) ? (int) $row['person_id'] : 0;
            if ($personId < 1) {
                continue;
            }
            $topics[] = [
                'id' => (int) $row['id'],
                'person_id' => $personId,
                'priority' => (int) ($row['priority'] ?? 5),
                'importance' => (int) ($row['importance'] ?? 5),
                'status' => (string) ($row['status'] ?? 'open'),
            ];
        }

        return $topics;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpenTicketsForTeam(int $teamId): array
    {
        if ($teamId <= 0 || !$this->hasTable('invgate_tickets')) {
            return [];
        }

        $joinStatus = $this->hasTable('invgate_statuses');
        $joinType = $this->hasTable('invgate_types');
        $joinCategory = $this->hasTable('invgate_categories');

        $sql = 'SELECT it.id, it.person_id, it.invgate_incident_id,
                       it.status_id, it.priority, it.created_at, it.last_update';
        if ($joinStatus) {
            $sql .= ', st.name AS status_name';
        } else {
            $sql .= ', NULL AS status_name';
        }
        if ($joinType) {
            $sql .= ', ty.name AS type_name';
        } else {
            $sql .= ', NULL AS type_name';
        }
        if ($joinCategory) {
            $sql .= ', cat.name AS category_name';
        } else {
            $sql .= ', NULL AS category_name';
        }
        $sql .= ' FROM invgate_tickets it
                  INNER JOIN team_people tp ON tp.id = it.person_id';
        if ($joinStatus) {
            $sql .= ' LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id';
        }
        if ($joinType) {
            $sql .= ' LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id';
        }
        if ($joinCategory) {
            $sql .= ' LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id';
        }
        $sql .= ' WHERE tp.team_id = :team_id
                  ORDER BY it.last_update DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['team_id' => $teamId]);

        $tickets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['person_id']) ? (int) $row['person_id'] : 0;
            if ($personId < 1) {
                continue;
            }
            $tickets[] = [
                'id' => (int) $row['id'],
                'person_id' => $personId,
                'invgate_incident_id' => (int) $row['invgate_incident_id'],
                'status_id' => $this->optionalInt($row['status_id'] ?? null),
                'status_name' => $this->nullableString($row['status_name'] ?? null),
                'priority' => $this->optionalInt($row['priority'] ?? null),
                'created_at' => (string) ($row['created_at'] ?? '0'),
                'last_update' => (string) ($row['last_update'] ?? '0'),
            ];
        }

        return $tickets;
    }

    /**
     * @return list<array{
     *   ticket_id: int,
     *   author_id: ?int,
     *   created_at: string,
     *   is_solution: bool
     * }>
     */
    public function listCommentsForTeam(int $teamId): array
    {
        if ($teamId <= 0 || !$this->hasTable('invgate_ticket_comments')) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT it.id AS ticket_id, c.author_id, c.created_at, c.is_solution
             FROM invgate_ticket_comments c
             INNER JOIN invgate_tickets it ON it.id = c.incident_id
             INNER JOIN team_people tp ON tp.id = it.person_id
             WHERE tp.team_id = :team_id
             ORDER BY c.created_at ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comments[] = [
                'ticket_id' => (int) $row['ticket_id'],
                'author_id' => $this->optionalInt($row['author_id'] ?? null),
                'created_at' => (string) ($row['created_at'] ?? '0'),
                'is_solution' => !empty($row['is_solution']),
            ];
        }

        return $comments;
    }

    /**
     * Work items activos (no finales) por person_id del equipo.
     *
     * @return array<int, int>
     */
    public function countOpenDevOpsByPerson(int $teamId): array
    {
        if ($teamId <= 0 || !$this->hasTable('azure_work_items')) {
            return [];
        }

        $finalStates = new AzureDevOpsFinalStates();
        $placeholders = $finalStates->sqlNotInPlaceholders();
        $lowerNames = $finalStates->namesLower();
        if ($lowerNames === []) {
            return [];
        }

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, email FROM team_people WHERE team_id = :team_id'
        );
        $peopleStmt->execute(['team_id' => $teamId]);

        /** @var array<string, int> */
        $emailIndex = [];
        /** @var array<int, true> */
        $teamPersonIds = [];
        while ($row = $peopleStmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($personId < 1) {
                continue;
            }
            $teamPersonIds[$personId] = true;
            $email = isset($row['email']) && $row['email'] !== null ? trim((string) $row['email']) : '';
            if ($email !== '') {
                $emailIndex[strtolower($email)] = $personId;
            }
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT person_id, assigned_unique_name, assigned_to
             FROM azure_work_items
             WHERE removed_at IS NULL
               AND LOWER(state) NOT IN (' . $placeholders . ')'
        );
        $itemsStmt->execute($lowerNames);

        /** @var array<int, int> */
        $counts = [];
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $personId = $this->resolvePersonIdForTeamRow($row, $teamPersonIds, $emailIndex);
            if ($personId === null) {
                continue;
            }
            if (!isset($counts[$personId])) {
                $counts[$personId] = 0;
            }
            $counts[$personId]++;
        }

        return $counts;
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

        $upn = isset($row['assigned_unique_name']) ? trim((string) $row['assigned_unique_name']) : '';
        if ($upn !== '') {
            $key = strtolower($upn);
            if (isset($emailIndex[$key])) {
                return $emailIndex[$key];
            }
        }

        $assigned = isset($row['assigned_to']) ? trim((string) $row['assigned_to']) : '';
        if ($assigned !== '') {
            $key = strtolower($assigned);
            if (isset($emailIndex[$key])) {
                return $emailIndex[$key];
            }
        }

        return null;
    }

    /** @param mixed $raw */
    private function optionalInt($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }

    /** @param mixed $raw */
    private function nullableString($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);

        return $s !== '' ? $s : null;
    }
}
