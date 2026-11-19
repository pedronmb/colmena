<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AzureWorkItemRepository;
use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamHealthRepository;
use App\Support\AzureDevOpsFinalStates;
use App\Support\TeamHealthThresholds;
use DateTimeImmutable;
use PDO;

final class WidgetSummaryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $config */
    public function forTeam(int $teamId, DateTimeImmutable $now, array $config): array
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
            "SELECT title, priority, importance, COUNT(*) OVER () AS total
             FROM topics
             WHERE team_id = :team AND status IN ('open', 'in_progress', 'blocked')
               AND priority >= :priority AND importance >= :importance
             ORDER BY priority DESC, importance DESC, datetime(updated_at) DESC, id DESC LIMIT 3"
        );
        $topics->execute([
            'team' => $teamId,
            'priority' => TeamHealthThresholds::CRITICAL_TOPIC_PRIORITY_MIN,
            'importance' => TeamHealthThresholds::CRITICAL_TOPIC_IMPORTANCE_MIN,
        ]);

        $tables = new TeamHealthRepository($this->pdo);
        $invgate = null;
        if ($tables->hasTable('invgate_tickets') && (
            $this->isConfigured($config['invgate'] ?? [], ['server_url', 'user', 'password'])
            || $this->pdo->query('SELECT 1 FROM invgate_tickets LIMIT 1')->fetchColumn() !== false
        )) {
            $invgate = (new InvgateTicketRepository($this->pdo))->countAssignedOpenForTeam($teamId);
        }

        $devops = null;
        $azure = $config['azure_devops'] ?? [];
        if ($tables->hasTable('azure_work_items')) {
            $workItems = new AzureWorkItemRepository($this->pdo);
            if ($this->isConfigured($azure, ['organization', 'project', 'pat']) || $workItems->hasAnyRows()) {
                $finalStates = new AzureDevOpsFinalStates(is_array($azure['final_states'] ?? null) ? $azure['final_states'] : null);
                $work = $workItems->listGroupedByTeam($teamId, $finalStates);
                $devops = $work['meta']['work_item_total'] - count($work['others_work_items']);
            }
        }

        return [
            'updated_at' => $now->format(DATE_ATOM),
            'alerts' => $this->withTotal($alerts->fetchAll(PDO::FETCH_ASSOC)),
            'critical_topics' => $this->withTotal($topics->fetchAll(PDO::FETCH_ASSOC)),
            'invgate_open' => $invgate,
            'devops_active' => $devops,
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
