<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class InvgateStatsRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Personas del equipo con ID InvGate configurado.
     *
     * @return list<array{
     *   id: int,
     *   display_name: string,
     *   invgate_id: int,
     *   email: ?string,
     *   role: ?string
     * }>
     */
    public function listPeopleForTeam(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, invgate_id, email, role
             FROM team_people
             WHERE team_id = :team_id
               AND invgate_id IS NOT NULL
               AND invgate_id > 0
             ORDER BY display_name ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $people = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invgateId = isset($row['invgate_id']) ? (int) $row['invgate_id'] : 0;
            if ($invgateId <= 0) {
                continue;
            }
            $people[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                'invgate_id' => $invgateId,
                'email' => $this->nullableString($row['email'] ?? null),
                'role' => $this->nullableString($row['role'] ?? null),
            ];
        }

        return $people;
    }

    /**
     * Todos los tickets asignados a personas del equipo (abiertos y finales).
     *
     * @return list<array{
     *   id: int,
     *   person_id: int,
     *   invgate_incident_id: int,
     *   status_id: ?int,
     *   status_name: ?string,
     *   type_id: ?int,
     *   type_name: ?string,
     *   category_id: ?int,
     *   category_name: ?string,
     *   priority: ?int,
     *   created_at: string,
     *   last_update: string
     * }>
     */
    public function listTicketsForTeam(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.person_id, it.invgate_incident_id,
                    it.status_id, st.name AS status_name,
                    it.type_id, ty.name AS type_name,
                    it.category_id, cat.name AS category_name,
                    it.priority, it.created_at, it.last_update
             FROM invgate_tickets it
             INNER JOIN team_people tp ON tp.id = it.person_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id
             LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id
             WHERE tp.team_id = :team_id
             ORDER BY it.last_update DESC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $tickets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['person_id']) ? (int) $row['person_id'] : 0;
            if ($personId <= 0) {
                continue;
            }
            $tickets[] = [
                'id' => (int) $row['id'],
                'person_id' => $personId,
                'invgate_incident_id' => (int) $row['invgate_incident_id'],
                'status_id' => $this->optionalInt($row['status_id'] ?? null),
                'status_name' => $this->nullableString($row['status_name'] ?? null),
                'type_id' => $this->optionalInt($row['type_id'] ?? null),
                'type_name' => $this->nullableString($row['type_name'] ?? null),
                'category_id' => $this->optionalInt($row['category_id'] ?? null),
                'category_name' => $this->nullableString($row['category_name'] ?? null),
                'priority' => $this->optionalInt($row['priority'] ?? null),
                'created_at' => (string) ($row['created_at'] ?? '0'),
                'last_update' => (string) ($row['last_update'] ?? '0'),
            ];
        }

        return $tickets;
    }

    /**
     * Comentarios de tickets del equipo.
     *
     * @return list<array{
     *   ticket_id: int,
     *   person_id: int,
     *   author_id: ?int,
     *   created_at: string,
     *   is_solution: bool
     * }>
     */
    public function listCommentsForTeam(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT it.id AS ticket_id, it.person_id,
                    c.author_id, c.created_at, c.is_solution
             FROM invgate_ticket_comments c
             INNER JOIN invgate_tickets it ON it.id = c.incident_id
             INNER JOIN team_people tp ON tp.id = it.person_id
             WHERE tp.team_id = :team_id
             ORDER BY c.created_at ASC, c.msg_num ASC'
        );
        $stmt->execute(['team_id' => $teamId]);

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comments[] = [
                'ticket_id' => (int) $row['ticket_id'],
                'person_id' => (int) $row['person_id'],
                'author_id' => $this->optionalInt($row['author_id'] ?? null),
                'created_at' => (string) ($row['created_at'] ?? '0'),
                'is_solution' => !empty($row['is_solution']),
            ];
        }

        return $comments;
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
