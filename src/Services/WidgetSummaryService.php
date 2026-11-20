<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamHealthRepository;
use App\Repositories\TeamPersonRepository;
use DateTimeImmutable;
use PDO;

final class WidgetSummaryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $config */
    public function forTeam(int $teamId, DateTimeImmutable $now, array $config, ?string $section = null): array
    {
        $tables = new TeamHealthRepository($this->pdo);
        $invgate = null;
        $invgateDetails = [];
        if ($tables->hasTable('invgate_tickets') && (
            $this->isConfigured($config['invgate'] ?? [], ['server_url', 'user', 'password'])
            || $this->pdo->query('SELECT 1 FROM invgate_tickets LIMIT 1')->fetchColumn() !== false
        )) {
            $tickets = new InvgateTicketRepository($this->pdo);
            $invgate = $tickets->countAssignedOpenForTeam($teamId);
            if ($section === 'invgate-people') {
                $invgateDetails = $tickets->listPrioritizedForWidget($teamId, $tables->hasTable('invgate_ticket_priorities'));
            } elseif ($section === 'invgate') {
                $invgateDetails = $tickets->listRecentForWidget($teamId, $tables->hasTable('invgate_ticket_comments'));
            }
        }
        if ($section === 'invgate-people' && $invgate === null) {
            $people = (new TeamPersonRepository($this->pdo))->listByTeam($teamId);
            $direct = array_values(array_filter($people, static fn (array $person): bool => $person['is_direct_team']));
            $invgateDetails = array_map(static fn (array $person): array => [
                'person_id' => $person['id'], 'name' => $person['display_name'], 'tickets' => [],
            ], $direct);
        }

        return [
            'updated_at' => $now->format(DATE_ATOM),
            'invgate_open' => $invgate,
            'detail' => match ($section) {
                'invgate', 'invgate-people' => $invgateDetails,
                default => null,
            },
        ];
    }

    /** @param list<string> $keys */
    private function isConfigured(array $config, array $keys): bool
    {
        foreach ($keys as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}
