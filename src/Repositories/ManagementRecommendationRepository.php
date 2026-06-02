<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ManagementRecommendationRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array{
     *   summary: string,
     *   executive_bullets_json: string,
     *   risks_json: string,
     *   actions_json: string,
     *   people_focus_json: string,
     *   topics_focus_json: string,
     *   delegations_json: string,
     *   one_on_one_json: string,
     *   context_hash: ?string
     * } $data
     */
    public function upsertOk(
        int $teamId,
        string $periodStart,
        string $periodEnd,
        array $data,
        string $model
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO management_recommendations (
                team_id, period_start, period_end, summary,
                executive_bullets_json, risks_json, actions_json,
                people_focus_json, topics_focus_json, delegations_json, one_on_one_json,
                generated_at, model, status, error_message, context_hash
             ) VALUES (
                :team_id, :period_start, :period_end, :summary,
                :executive_bullets_json, :risks_json, :actions_json,
                :people_focus_json, :topics_focus_json, :delegations_json, :one_on_one_json,
                :generated_at, :model, \'ok\', NULL, :context_hash
             )
             ON CONFLICT(team_id, period_start) DO UPDATE SET
                period_end = excluded.period_end,
                summary = excluded.summary,
                executive_bullets_json = excluded.executive_bullets_json,
                risks_json = excluded.risks_json,
                actions_json = excluded.actions_json,
                people_focus_json = excluded.people_focus_json,
                topics_focus_json = excluded.topics_focus_json,
                delegations_json = excluded.delegations_json,
                one_on_one_json = excluded.one_on_one_json,
                generated_at = excluded.generated_at,
                model = excluded.model,
                status = \'ok\',
                error_message = NULL,
                context_hash = excluded.context_hash'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'summary' => $data['summary'],
            'executive_bullets_json' => $data['executive_bullets_json'],
            'risks_json' => $data['risks_json'],
            'actions_json' => $data['actions_json'],
            'people_focus_json' => $data['people_focus_json'],
            'topics_focus_json' => $data['topics_focus_json'],
            'delegations_json' => $data['delegations_json'],
            'one_on_one_json' => $data['one_on_one_json'],
            'generated_at' => date('c'),
            'model' => trim($model),
            'context_hash' => $data['context_hash'],
        ]);
    }

    public function upsertError(
        int $teamId,
        string $periodStart,
        string $periodEnd,
        string $error,
        string $model,
        ?string $contextHash = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO management_recommendations (
                team_id, period_start, period_end, summary,
                executive_bullets_json, risks_json, actions_json,
                people_focus_json, topics_focus_json, delegations_json, one_on_one_json,
                generated_at, model, status, error_message, context_hash
             ) VALUES (
                :team_id, :period_start, :period_end, \'\',
                \'[]\', \'[]\', \'[]\', \'[]\', \'[]\', \'[]\', \'[]\',
                :generated_at, :model, \'error\', :error_message, :context_hash
             )
             ON CONFLICT(team_id, period_start) DO UPDATE SET
                period_end = excluded.period_end,
                generated_at = excluded.generated_at,
                model = excluded.model,
                status = \'error\',
                error_message = excluded.error_message,
                context_hash = excluded.context_hash'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'generated_at' => date('c'),
            'model' => trim($model),
            'error_message' => trim($error),
            'context_hash' => $contextHash,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForTeamPeriod(int $teamId, string $periodStart): ?array
    {
        if ($teamId <= 0 || $periodStart === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM management_recommendations
             WHERE team_id = :team_id AND period_start = :period_start
             LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'period_start' => $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function findLatestOk(int $teamId): ?array
    {
        if ($teamId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM management_recommendations
             WHERE team_id = :team_id AND status = \'ok\'
             ORDER BY period_start DESC
             LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function getContextHash(int $teamId, string $periodStart): ?string
    {
        $row = $this->findForTeamPeriod($teamId, $periodStart);
        if ($row === null) {
            return null;
        }
        $hash = $row['context_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'period_start' => (string) $row['period_start'],
            'period_end' => (string) $row['period_end'],
            'summary' => (string) ($row['summary'] ?? ''),
            'executive_bullets' => $this->decodeJsonList($row['executive_bullets_json'] ?? '[]'),
            'risks' => $this->decodeJsonList($row['risks_json'] ?? '[]'),
            'actions' => $this->decodeJsonList($row['actions_json'] ?? '[]'),
            'people_focus' => $this->decodeJsonList($row['people_focus_json'] ?? '[]'),
            'topics_focus' => $this->decodeJsonList($row['topics_focus_json'] ?? '[]'),
            'delegations' => $this->decodeJsonList($row['delegations_json'] ?? '[]'),
            'one_on_one' => $this->decodeJsonList($row['one_on_one_json'] ?? '[]'),
            'generated_at' => (string) ($row['generated_at'] ?? ''),
            'model' => isset($row['model']) && trim((string) $row['model']) !== ''
                ? (string) $row['model']
                : null,
            'status' => (string) ($row['status'] ?? 'ok'),
            'error_message' => isset($row['error_message']) ? (string) $row['error_message'] : null,
            'context_hash' => isset($row['context_hash']) ? (string) $row['context_hash'] : null,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
