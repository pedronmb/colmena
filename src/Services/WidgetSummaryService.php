<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamHealthRepository;
use App\Repositories\TeamPersonRepository;
use App\Support\TeamHealthThresholds;
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
        $alerts = $this->pdo->prepare(
            'SELECT title, due_date, COUNT(*) OVER () AS total
             FROM team_alerts
             WHERE team_id = :team AND due_date BETWEEN :today AND :last_day
             ORDER BY due_date ASC, id ASC LIMIT 3'
        );
        $alerts->execute([
            'team' => $teamId,
            'today' => $now->format('Y-m-d'),
            'last_day' => $now->modify('+6 days')->format('Y-m-d'),
        ]);

        $topics = $this->pdo->prepare(
            "SELECT t.title, t.priority, t.importance, t.status, tp.display_name AS person
             FROM topics t
             LEFT JOIN team_people tp ON tp.id = t.person_id AND tp.team_id = t.team_id
             WHERE t.team_id = :team AND t.status IN ('open', 'in_progress', 'blocked')
             ORDER BY t.priority DESC, t.importance DESC, datetime(t.updated_at) DESC, t.id DESC"
        );
        $topics->execute(['team' => $teamId]);
        $activeTopics = 0;
        $criticalTopics = ['total' => 0, 'items' => []];
        $topicDetails = [];
        while ($topic = $topics->fetch(PDO::FETCH_ASSOC)) {
            $activeTopics++;
            $topic['priority'] = (int) $topic['priority'];
            $topic['importance'] = (int) $topic['importance'];
            $topic['critical'] = TeamHealthThresholds::isCriticalTopic($topic['priority'], $topic['importance']);
            if ($topic['critical']) {
                $criticalTopics['total']++;
                if (count($criticalTopics['items']) < 3) {
                    $criticalTopics['items'][] = array_intersect_key($topic, ['title' => true, 'priority' => true, 'importance' => true]);
                }
            }
            if ($section === 'topics') {
                $topicDetails[] = $topic;
            }
        }

        $tables = new TeamHealthRepository($this->pdo);
        $invgate = null;
        $invgateDetails = [];
        if ($tables->hasTable('invgate_tickets') && (
            $this->isConfigured($config['invgate'] ?? [], ['server_url', 'user', 'password'])
            || $this->pdo->query('SELECT 1 FROM invgate_tickets LIMIT 1')->fetchColumn() !== false
        )) {
            $tickets = new InvgateTicketRepository($this->pdo);
            if ($section === 'invgate-people') {
                $invgateDetails = $tickets->listPrioritizedForWidget($teamId, $tables->hasTable('invgate_ticket_priorities'));
                $invgate = array_sum(array_map(static fn (array $group): int => count($group['tickets']), $invgateDetails));
            } else {
                $invgate = $tickets->countAssignedOpenForTeam($teamId);
                if ($section === 'invgate') {
                    $invgateDetails = $tickets->listRecentForWidget($teamId, $tables->hasTable('invgate_ticket_comments'));
                }
            }
        }
        if ($section === 'invgate-people' && $invgate === null) {
            $invgateDetails = array_map(static fn (array $person): array => [
                'person_id' => $person['id'], 'name' => $person['display_name'], 'tickets' => [],
            ], (new TeamPersonRepository($this->pdo))->listByTeam($teamId));
        }

        return [
            'updated_at' => $now->format(DATE_ATOM),
            'alerts' => $this->withTotal($alerts->fetchAll(PDO::FETCH_ASSOC)),
            'critical_topics' => $criticalTopics,
            'topics_active' => $activeTopics,
            'invgate_open' => $invgate,
            'detail' => match ($section) {
                'invgate', 'invgate-people' => $invgateDetails,
                'topics' => $topicDetails,
                default => null,
            },
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function withTotal(array $rows): array
    {
        $total = (int) ($rows[0]['total'] ?? 0);
        foreach ($rows as &$row) {
            unset($row['total']);
        }
        return ['total' => $total, 'items' => $rows];
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
