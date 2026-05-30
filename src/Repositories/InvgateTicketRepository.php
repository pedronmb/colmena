<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\InvgateFinalStatuses;
use PDO;

final class InvgateTicketRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inserta o actualiza un ticket desde un incidente de la API.
     *
     * @param array<string, mixed> $incident
     * @return bool true si se guardó, false si se omitió (datos inválidos)
     */
    public function upsertFromApi(int $personId, array $incident): bool
    {
        $incidentId = isset($incident['id']) ? (int) $incident['id'] : 0;
        $title = isset($incident['title']) ? trim((string) $incident['title']) : '';
        if ($incidentId <= 0 || $title === '') {
            return false;
        }

        $userId = isset($incident['user_id']) ? (int) $incident['user_id'] : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }

        $categoryId = isset($incident['category_id']) ? (int) $incident['category_id'] : null;
        if ($categoryId !== null && $categoryId <= 0) {
            $categoryId = null;
        }

        $sourceId = isset($incident['source_id']) ? (int) $incident['source_id'] : null;
        if ($sourceId !== null && $sourceId <= 0) {
            $sourceId = null;
        }

        $statusId = isset($incident['status_id']) ? (int) $incident['status_id'] : null;
        if ($statusId !== null && $statusId <= 0) {
            $statusId = null;
        }

        $typeId = isset($incident['type_id']) ? (int) $incident['type_id'] : null;
        if ($typeId !== null && $typeId <= 0) {
            $typeId = null;
        }

        $priority = isset($incident['priority_id']) ? (int) $incident['priority_id'] : null;
        if ($priority !== null && $priority <= 0) {
            $priority = null;
        }

        $description = isset($incident['description']) ? (string) $incident['description'] : null;
        if ($description !== null && trim($description) === '') {
            $description = null;
        }

        $createdAt = isset($incident['created_at']) ? trim((string) $incident['created_at']) : '';
        if ($createdAt === '') {
            $createdAt = '0';
        }

        $lastUpdate = isset($incident['last_update']) ? trim((string) $incident['last_update']) : '';
        if ($lastUpdate === '') {
            $lastUpdate = '0';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_tickets (
                person_id, invgate_incident_id, user_id, title, description,
                category_id, source_id, status_id, type_id, created_at, last_update, priority
             ) VALUES (
                :person_id, :invgate_incident_id, :user_id, :title, :description,
                :category_id, :source_id, :status_id, :type_id, :created_at, :last_update, :priority
             )
             ON CONFLICT(invgate_incident_id) DO UPDATE SET
                person_id = excluded.person_id,
                user_id = excluded.user_id,
                title = excluded.title,
                description = excluded.description,
                category_id = excluded.category_id,
                source_id = excluded.source_id,
                status_id = excluded.status_id,
                type_id = excluded.type_id,
                created_at = excluded.created_at,
                last_update = excluded.last_update,
                priority = excluded.priority'
        );
        $stmt->execute([
            'person_id' => $personId,
            'invgate_incident_id' => $incidentId,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'category_id' => $categoryId,
            'source_id' => $sourceId,
            'status_id' => $statusId,
            'type_id' => $typeId,
            'created_at' => $createdAt,
            'last_update' => $lastUpdate,
            'priority' => $priority,
        ]);

        return true;
    }

    /**
     * Tickets locales para sincronizar comentarios desde InvGate.
     *
     * @return list<array{id: int, invgate_incident_id: int}>
     */
    public function listForCommentSync(): array
    {
        $finalStatusIds = InvgateFinalStatuses::ids();
        $placeholders = InvgateFinalStatuses::sqlNotInPlaceholders();
        $stmt = $this->pdo->prepare(
            'SELECT id, invgate_incident_id
             FROM invgate_tickets
             WHERE status_id IS NULL OR status_id NOT IN (' . $placeholders . ')
             ORDER BY last_update DESC'
        );
        $stmt->execute($finalStatusIds);

        $tickets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $incidentId = isset($row['invgate_incident_id']) ? (int) $row['invgate_incident_id'] : 0;
            if ($id > 0 && $incidentId > 0) {
                $tickets[] = [
                    'id' => $id,
                    'invgate_incident_id' => $incidentId,
                ];
            }
        }

        return $tickets;
    }

    /**
     * Tickets abiertos para generar recomendaciones IA.
     *
     * @return list<array{
     *   id: int,
     *   invgate_incident_id: int,
     *   title: string,
     *   description: ?string,
     *   last_update: string,
     *   generated_at: ?string,
     *   existing_summary: ?string,
     *   existing_recommendation: ?string
     * }>
     */
    public function listForRecommendationSync(): array
    {
        $finalStatusIds = InvgateFinalStatuses::ids();
        $placeholders = InvgateFinalStatuses::sqlNotInPlaceholders();
        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.invgate_incident_id, it.title, it.description, it.last_update,
                    rec.generated_at, rec.summary AS existing_summary,
                    rec.recommendation AS existing_recommendation
             FROM invgate_tickets it
             LEFT JOIN invgate_ticket_recommendations rec ON rec.ticket_id = it.id
             WHERE it.status_id IS NULL OR it.status_id NOT IN (' . $placeholders . ')
             ORDER BY it.last_update DESC'
        );
        $stmt->execute($finalStatusIds);

        $tickets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $incidentId = isset($row['invgate_incident_id']) ? (int) $row['invgate_incident_id'] : 0;
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($id <= 0 || $incidentId <= 0 || $title === '') {
                continue;
            }

            $description = isset($row['description']) ? trim((string) $row['description']) : '';
            $generatedAt = isset($row['generated_at']) ? trim((string) $row['generated_at']) : '';
            $existingSummary = isset($row['existing_summary']) ? trim((string) $row['existing_summary']) : '';
            $existingRecommendation = isset($row['existing_recommendation'])
                ? trim((string) $row['existing_recommendation'])
                : '';
            $tickets[] = [
                'id' => $id,
                'invgate_incident_id' => $incidentId,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'last_update' => (string) ($row['last_update'] ?? '0'),
                'generated_at' => $generatedAt !== '' ? $generatedAt : null,
                'existing_summary' => $existingSummary !== '' ? $existingSummary : null,
                'existing_recommendation' => $existingRecommendation !== '' ? $existingRecommendation : null,
            ];
        }

        return $tickets;
    }

    /**
     * Tickets locales de una persona con estado NO final.
     *
     * @param array<int, int> $excludedStatusIds
     * @return list<array{invgate_incident_id: int, status_id: ?int}>
     */
    public function listTicketsForStatusReconciliation(int $personId, array $excludedStatusIds): array
    {
        if ($personId <= 0) {
            return [];
        }

        $excludedStatusIds = array_values(array_filter(
            $excludedStatusIds,
            static fn (mixed $v): bool => is_int($v) && $v > 0
        ));

        // Si no pasás estados excluidos, devolvemos vacio para evitar trabajo inesperado.
        if ($excludedStatusIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($excludedStatusIds), '?'));

        $sql = 'SELECT invgate_incident_id, status_id
                FROM invgate_tickets
                WHERE person_id = ?
                  AND (status_id IS NULL OR status_id NOT IN (' . $placeholders . '))
                ORDER BY last_update DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$personId], $excludedStatusIds));

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $incidentId = isset($row['invgate_incident_id']) ? (int) $row['invgate_incident_id'] : 0;
            if ($incidentId <= 0) {
                continue;
            }
            $out[] = [
                'invgate_incident_id' => $incidentId,
                'status_id' => isset($row['status_id']) ? $this->mapOptionalIntColumn($row['status_id']) : null,
            ];
        }

        return $out;
    }

    /**
     * Actualiza solo status del ticket local identificado por invgate_incident_id.
     */
    public function updateStatusAndAssigneeByInvgateIncidentId(
        int $incidentId,
        ?int $statusId,
        string $lastUpdate,
        ?int $personId
    ): bool
    {
        if ($incidentId <= 0) {
            return false;
        }

        $lastUpdate = trim($lastUpdate);
        if ($lastUpdate === '') {
            $lastUpdate = '0';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE invgate_tickets
             SET status_id = :status_id,
                 last_update = :last_update,
                 person_id = :person_id
             WHERE invgate_incident_id = :incident_id'
        );

        $stmt->execute([
            'status_id' => $statusId,
            'last_update' => $lastUpdate,
            'person_id' => $personId,
            'incident_id' => $incidentId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ticket local si pertenece al equipo (o es huérfano).
     *
     * @return array<string, mixed>|null
     */
    public function findForTeam(int $ticketId, int $teamId): ?array
    {
        if ($ticketId <= 0 || $teamId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.person_id, it.invgate_incident_id, it.user_id, it.title, it.description,
                    it.category_id, cat.name AS category_name,
                    it.source_id,
                    it.status_id, st.name AS status_name,
                    it.type_id, ty.name AS type_name,
                    it.created_at, it.last_update, it.priority
             FROM invgate_tickets it
             LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id
             LEFT JOIN team_people tp ON tp.id = it.person_id
             WHERE it.id = :ticket_id
               AND (tp.team_id = :team_id OR it.person_id IS NULL)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'team_id' => $teamId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->mapTicketRow($row);
    }

    /**
     * Tickets del equipo agrupados por persona, incluyendo personas con invgate_id sin tickets.
     *
     * @return array{
     *   groups: list<array{person: array<string, mixed>, tickets: list<array<string, mixed>>}>,
     *   orphan_tickets: list<array<string, mixed>>,
     *   meta: array{ticket_total: int, people_with_invgate_id: int}
     * }
     */
    public function listGroupedByTeam(int $teamId): array
    {
        $finalStatusIds = InvgateFinalStatuses::ids();
        $finalPlaceholders = InvgateFinalStatuses::sqlNotInPlaceholders();

        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.person_id, it.invgate_incident_id, it.user_id, it.title, it.description,
                    it.category_id, cat.name AS category_name,
                    it.source_id,
                    it.status_id, st.name AS status_name,
                    it.type_id, ty.name AS type_name,
                    it.created_at, it.last_update, it.priority,
                    tp.display_name, tp.invgate_id, tp.email, tp.role
             FROM invgate_tickets it
             LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id
             INNER JOIN team_people tp ON tp.id = it.person_id
             WHERE tp.team_id = ?
               AND (it.status_id IS NULL OR it.status_id NOT IN (' . $finalPlaceholders . '))
             ORDER BY tp.display_name ASC, it.last_update DESC'
        );
        $stmt->execute(array_merge([$teamId], $finalStatusIds));

        /** @var array<int, array{person: array<string, mixed>, tickets: list<array<string, mixed>>}> */
        $groupsByPersonId = [];
        $ticketTotal = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['person_id']) ? (int) $row['person_id'] : 0;
            if ($personId < 1) {
                continue;
            }
            if (!isset($groupsByPersonId[$personId])) {
                $groupsByPersonId[$personId] = [
                    'person' => $this->mapPersonFromTicketRow($personId, $row),
                    'tickets' => [],
                ];
            }
            $groupsByPersonId[$personId]['tickets'][] = $this->mapTicketRow($row);
            $ticketTotal++;
        }

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, display_name, invgate_id, email, role
             FROM team_people
             WHERE team_id = :team_id
               AND invgate_id IS NOT NULL
               AND invgate_id > 0
             ORDER BY display_name ASC'
        );
        $peopleStmt->execute(['team_id' => $teamId]);

        $peopleWithInvgateId = 0;
        while ($row = $peopleStmt->fetch(PDO::FETCH_ASSOC)) {
            $peopleWithInvgateId++;
            $personId = (int) $row['id'];
            if (!isset($groupsByPersonId[$personId])) {
                $groupsByPersonId[$personId] = [
                    'person' => $this->mapPersonRow($row),
                    'tickets' => [],
                ];
            }
        }

        $groups = array_values($groupsByPersonId);
        usort(
            $groups,
            static function (array $a, array $b): int {
                $nameA = isset($a['person']['display_name']) ? (string) $a['person']['display_name'] : '';
                $nameB = isset($b['person']['display_name']) ? (string) $b['person']['display_name'] : '';

                return strcasecmp($nameA, $nameB);
            }
        );

        $orphanStmt = $this->pdo->prepare(
            'SELECT id, person_id, invgate_incident_id, user_id, title, description,
                    category_id, cat.name AS category_name,
                    source_id,
                    status_id, st.name AS status_name,
                    type_id, ty.name AS type_name,
                    created_at, last_update, priority
             FROM invgate_tickets
             LEFT JOIN invgate_categories cat ON cat.invgate_id = invgate_tickets.category_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = invgate_tickets.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = invgate_tickets.type_id
             WHERE person_id IS NULL
               AND (status_id IS NULL OR status_id NOT IN (' . $finalPlaceholders . '))
             ORDER BY last_update DESC'
        );
        $orphanTickets = [];
        $orphanStmt->execute($finalStatusIds);
        while ($row = $orphanStmt->fetch(PDO::FETCH_ASSOC)) {
            $orphanTickets[] = $this->mapTicketRow($row);
            $ticketTotal++;
        }

        return [
            'groups' => $groups,
            'orphan_tickets' => $orphanTickets,
            'meta' => [
                'ticket_total' => $ticketTotal,
                'people_with_invgate_id' => $peopleWithInvgateId,
            ],
        ];
    }

    /**
     * Tickets abiertos del equipo en lista plana (sin agrupar).
     *
     * @return list<array<string, mixed>>
     */
    public function listOpenFlatByTeam(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $finalStatusIds = InvgateFinalStatuses::ids();
        $finalPlaceholders = InvgateFinalStatuses::sqlNotInPlaceholders();
        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.person_id, it.invgate_incident_id, it.user_id, it.title, it.description,
                    it.category_id, cat.name AS category_name,
                    it.source_id,
                    it.status_id, st.name AS status_name,
                    it.type_id, ty.name AS type_name,
                    it.created_at, it.last_update, it.priority,
                    tp.display_name
             FROM invgate_tickets it
             LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id
             LEFT JOIN team_people tp ON tp.id = it.person_id
             WHERE (tp.team_id = ? OR it.person_id IS NULL)
               AND (it.status_id IS NULL OR it.status_id NOT IN (' . $finalPlaceholders . '))
             ORDER BY it.last_update DESC'
        );
        $stmt->execute(array_merge([$teamId], $finalStatusIds));

        $tickets = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ticket = $this->mapTicketRow($row);
            $ticket['person_display_name'] = isset($row['display_name']) && trim((string) $row['display_name']) !== ''
                ? (string) $row['display_name']
                : null;
            $tickets[] = $ticket;
        }

        return $tickets;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapTicketRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'invgate_incident_id' => (int) $row['invgate_incident_id'],
            'user_id' => $this->mapOptionalIntColumn($row['user_id'] ?? null),
            'title' => (string) $row['title'],
            'description' => isset($row['description']) && $row['description'] !== null && trim((string) $row['description']) !== ''
                ? (string) $row['description']
                : null,
            'category_id' => $this->mapOptionalIntColumn($row['category_id'] ?? null),
            'category_name' => isset($row['category_name']) && $row['category_name'] !== null && trim((string) $row['category_name']) !== ''
                ? (string) $row['category_name']
                : null,
            'source_id' => $this->mapOptionalIntColumn($row['source_id'] ?? null),
            'status_id' => $this->mapOptionalIntColumn($row['status_id'] ?? null),
            'status_name' => isset($row['status_name']) && $row['status_name'] !== null && trim((string) $row['status_name']) !== ''
                ? (string) $row['status_name']
                : null,
            'type_id' => $this->mapOptionalIntColumn($row['type_id'] ?? null),
            'type_name' => isset($row['type_name']) && $row['type_name'] !== null && trim((string) $row['type_name']) !== ''
                ? (string) $row['type_name']
                : null,
            'created_at' => (string) ($row['created_at'] ?? '0'),
            'last_update' => (string) ($row['last_update'] ?? '0'),
            'priority' => $this->mapOptionalIntColumn($row['priority'] ?? null),
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
            'display_name' => (string) $row['display_name'],
            'invgate_id' => $this->mapOptionalIntColumn($row['invgate_id'] ?? null),
            'email' => isset($row['email']) && $row['email'] !== null && trim((string) $row['email']) !== ''
                ? trim((string) $row['email'])
                : null,
            'role' => isset($row['role']) && $row['role'] !== null && trim((string) $row['role']) !== ''
                ? trim((string) $row['role'])
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPersonFromTicketRow(int $personId, array $row): array
    {
        return [
            'id' => $personId,
            'display_name' => (string) ($row['display_name'] ?? ''),
            'invgate_id' => $this->mapOptionalIntColumn($row['invgate_id'] ?? null),
            'email' => isset($row['email']) && $row['email'] !== null && trim((string) $row['email']) !== ''
                ? trim((string) $row['email'])
                : null,
            'role' => isset($row['role']) && $row['role'] !== null && trim((string) $row['role']) !== ''
                ? trim((string) $row['role'])
                : null,
        ];
    }

    /** @param mixed $raw */
    private function mapOptionalIntColumn($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }
}
