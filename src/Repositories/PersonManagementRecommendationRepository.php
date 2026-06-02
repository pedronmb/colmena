<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PersonManagementRecommendationRepository
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
     *   risk_level: ?string,
     *   situation_json: string,
     *   risks_json: string,
     *   suggested_actions_json: string,
     *   one_on_one_questions_json: string,
     *   blockers_json: string,
     *   pentagon_note: ?string,
     *   context_hash: ?string
     * } $data
     */
    public function upsertOk(
        int $teamId,
        int $personId,
        string $periodStart,
        string $periodEnd,
        array $data,
        string $model
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO person_management_recommendations (
                team_id, person_id, period_start, period_end, summary, risk_level,
                situation_json, risks_json, suggested_actions_json,
                one_on_one_questions_json, blockers_json, pentagon_note,
                generated_at, model, status, error_message, context_hash
             ) VALUES (
                :team_id, :person_id, :period_start, :period_end, :summary, :risk_level,
                :situation_json, :risks_json, :suggested_actions_json,
                :one_on_one_questions_json, :blockers_json, :pentagon_note,
                :generated_at, :model, \'ok\', NULL, :context_hash
             )
             ON CONFLICT(team_id, person_id, period_start) DO UPDATE SET
                period_end = excluded.period_end,
                summary = excluded.summary,
                risk_level = excluded.risk_level,
                situation_json = excluded.situation_json,
                risks_json = excluded.risks_json,
                suggested_actions_json = excluded.suggested_actions_json,
                one_on_one_questions_json = excluded.one_on_one_questions_json,
                blockers_json = excluded.blockers_json,
                pentagon_note = excluded.pentagon_note,
                generated_at = excluded.generated_at,
                model = excluded.model,
                status = \'ok\',
                error_message = NULL,
                context_hash = excluded.context_hash'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'person_id' => $personId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'summary' => $data['summary'],
            'risk_level' => $data['risk_level'],
            'situation_json' => $data['situation_json'],
            'risks_json' => $data['risks_json'],
            'suggested_actions_json' => $data['suggested_actions_json'],
            'one_on_one_questions_json' => $data['one_on_one_questions_json'],
            'blockers_json' => $data['blockers_json'],
            'pentagon_note' => $data['pentagon_note'],
            'generated_at' => date('c'),
            'model' => trim($model),
            'context_hash' => $data['context_hash'],
        ]);
    }

    public function upsertError(
        int $teamId,
        int $personId,
        string $periodStart,
        string $periodEnd,
        string $error,
        string $model,
        ?string $contextHash = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO person_management_recommendations (
                team_id, person_id, period_start, period_end, summary, risk_level,
                situation_json, risks_json, suggested_actions_json,
                one_on_one_questions_json, blockers_json, pentagon_note,
                generated_at, model, status, error_message, context_hash
             ) VALUES (
                :team_id, :person_id, :period_start, :period_end, \'\', NULL,
                \'{}\', \'[]\', \'[]\', \'[]\', \'[]\', NULL,
                :generated_at, :model, \'error\', :error_message, :context_hash
             )
             ON CONFLICT(team_id, person_id, period_start) DO UPDATE SET
                period_end = excluded.period_end,
                generated_at = excluded.generated_at,
                model = excluded.model,
                status = \'error\',
                error_message = excluded.error_message,
                context_hash = excluded.context_hash'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'person_id' => $personId,
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
    public function findForPersonPeriod(int $teamId, int $personId, string $periodStart): ?array
    {
        if ($teamId <= 0 || $personId <= 0 || $periodStart === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM person_management_recommendations
             WHERE team_id = :team_id AND person_id = :person_id AND period_start = :period_start
             LIMIT 1'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'person_id' => $personId,
            'period_start' => $periodStart,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRow($row);
    }

    public function getContextHash(int $teamId, int $personId, string $periodStart): ?string
    {
        $row = $this->findForPersonPeriod($teamId, $personId, $periodStart);
        if ($row === null) {
            return null;
        }
        $hash = $row['context_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Resúmenes por persona para tarjetas del copiloto (sin cargar JSON completo).
     *
     * @return list<array{
     *   person_id: int,
     *   summary: string,
     *   risk_level: ?string,
     *   status: string,
     *   generated_at: string
     * }>
     */
    public function listSummariesForTeamPeriod(int $teamId, string $periodStart): array
    {
        if ($teamId <= 0 || $periodStart === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT person_id, summary, risk_level, status, generated_at
             FROM person_management_recommendations
             WHERE team_id = :team_id AND period_start = :period_start
             ORDER BY person_id ASC'
        );
        $stmt->execute(['team_id' => $teamId, 'period_start' => $periodStart]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'person_id' => (int) $row['person_id'],
                'summary' => (string) ($row['summary'] ?? ''),
                'risk_level' => isset($row['risk_level']) && $row['risk_level'] !== ''
                    ? (string) $row['risk_level']
                    : null,
                'status' => (string) ($row['status'] ?? 'ok'),
                'generated_at' => (string) ($row['generated_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $situation = json_decode((string) ($row['situation_json'] ?? '{}'), true);

        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'person_id' => (int) $row['person_id'],
            'period_start' => (string) $row['period_start'],
            'period_end' => (string) $row['period_end'],
            'summary' => (string) ($row['summary'] ?? ''),
            'risk_level' => isset($row['risk_level']) && $row['risk_level'] !== ''
                ? (string) $row['risk_level']
                : null,
            'situation' => is_array($situation) ? $situation : [],
            'risks' => $this->decodeJsonList($row['risks_json'] ?? '[]'),
            'suggested_actions' => $this->decodeJsonList($row['suggested_actions_json'] ?? '[]'),
            'one_on_one_questions' => $this->decodeJsonList($row['one_on_one_questions_json'] ?? '[]'),
            'blockers' => $this->decodeJsonList($row['blockers_json'] ?? '[]'),
            'pentagon_note' => isset($row['pentagon_note']) && trim((string) $row['pentagon_note']) !== ''
                ? (string) $row['pentagon_note']
                : null,
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
