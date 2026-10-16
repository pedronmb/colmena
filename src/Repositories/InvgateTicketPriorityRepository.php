<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\InvgateFinalStatuses;
use App\Support\InvgateManualPriority;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use Throwable;

final class InvgateTicketPriorityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{person: array<string, mixed>, tickets: list<array<string, mixed>>}> */
    public function listGroupedByTeam(int $teamId): array
    {
        $groups = (new InvgateTicketRepository($this->pdo))->listGroupedByTeam($teamId)['groups'];
        $stmt = $this->pdo->prepare(
            'SELECT p.ticket_id, p.priority
             FROM invgate_ticket_priorities p
             INNER JOIN invgate_tickets it ON it.id = p.ticket_id
             INNER JOIN team_people tp ON tp.id = it.person_id
             WHERE tp.team_id = :team_id'
        );
        $stmt->execute(['team_id' => $teamId]);
        $priorities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($groups as &$group) {
            foreach ($group['tickets'] as &$ticket) {
                $ticket['manual_priority'] = isset($priorities[$ticket['id']])
                    ? (int) $priorities[$ticket['id']]
                    : null;
            }
            unset($ticket);
            usort($group['tickets'], static function (array $a, array $b): int {
                if (($a['manual_priority'] === null) !== ($b['manual_priority'] === null)) {
                    return $a['manual_priority'] === null ? 1 : -1;
                }

                return ($a['manual_priority'] <=> $b['manual_priority'])
                    ?: ($a['invgate_incident_id'] <=> $b['invgate_incident_id']);
            });
        }
        unset($group);

        return $groups;
    }

    /** @return array{person: array<string, mixed>, tickets: list<array<string, mixed>>}|null */
    public function findGroup(int $teamId, int $personId): ?array
    {
        foreach ($this->listGroupedByTeam($teamId) as $group) {
            if ($group['person']['id'] === $personId) {
                return $group;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $changes */
    public function saveForPerson(int $teamId, int $personId, array $changes): void
    {
        if ($changes === [] || !array_is_list($changes)) {
            throw new InvalidArgumentException('Indicá los tickets y sus prioridades.');
        }
        $priorities = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !isset($change['ticket_id']) || !is_int($change['ticket_id'])
                || $change['ticket_id'] < 1 || !array_key_exists('priority', $change)
                || array_key_exists($change['ticket_id'], $priorities)
            ) {
                throw new InvalidArgumentException('Lista de tickets inválida o con tickets repetidos.');
            }
            $priorities[$change['ticket_id']] = InvgateManualPriority::parse($change['priority']);
        }

        $this->pdo->beginTransaction();
        try {
            $person = $this->pdo->prepare('SELECT 1 FROM team_people WHERE id = ? AND team_id = ?');
            $person->execute([$personId, $teamId]);
            if (!$person->fetchColumn()) {
                throw new OutOfBoundsException('Persona no encontrada en este equipo.');
            }
            $tickets = $this->pdo->prepare(
                'SELECT id FROM invgate_tickets WHERE person_id = ?
                 AND (status_id IS NULL OR status_id NOT IN (' . InvgateFinalStatuses::sqlNotInPlaceholders() . '))'
            );
            $tickets->execute(array_merge([$personId], InvgateFinalStatuses::ids()));
            $allowed = array_fill_keys($tickets->fetchAll(PDO::FETCH_COLUMN), true);
            foreach ($priorities as $ticketId => $priority) {
                if (!isset($allowed[$ticketId])) {
                    throw new OutOfBoundsException('Un ticket ya no está abierto o no pertenece a esta persona. Actualizá la página.');
                }
            }
            $save = $this->pdo->prepare(
                'INSERT INTO invgate_ticket_priorities (ticket_id, priority) VALUES (:ticket_id, :priority)
                 ON CONFLICT(ticket_id) DO UPDATE SET priority = excluded.priority'
            );
            $remove = $this->pdo->prepare('DELETE FROM invgate_ticket_priorities WHERE ticket_id = ?');
            foreach ($priorities as $ticketId => $priority) {
                if ($priority === null) {
                    $remove->execute([$ticketId]);
                } else {
                    $save->execute(['ticket_id' => $ticketId, 'priority' => $priority]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
