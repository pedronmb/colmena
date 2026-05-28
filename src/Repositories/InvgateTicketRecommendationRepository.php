<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\InvgateFinalStatuses;
use PDO;

final class InvgateTicketRecommendationRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function upsert(int $ticketId, string $summary, string $recommendation, string $model): void
    {
        if ($ticketId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_ticket_recommendations (
                ticket_id, summary, recommendation, generated_at, model, error
             ) VALUES (
                :ticket_id, :summary, :recommendation, :generated_at, :model, NULL
             )
             ON CONFLICT(ticket_id) DO UPDATE SET
                summary = excluded.summary,
                recommendation = excluded.recommendation,
                generated_at = excluded.generated_at,
                model = excluded.model,
                error = NULL'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'summary' => trim($summary),
            'recommendation' => trim($recommendation),
            'generated_at' => date('c'),
            'model' => trim($model),
        ]);
    }

    public function upsertError(int $ticketId, string $error, string $model): void
    {
        if ($ticketId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO invgate_ticket_recommendations (
                ticket_id, summary, recommendation, generated_at, model, error
             ) VALUES (
                :ticket_id, :summary, :recommendation, :generated_at, :model, :error
             )
             ON CONFLICT(ticket_id) DO UPDATE SET
                generated_at = excluded.generated_at,
                model = excluded.model,
                error = excluded.error'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'summary' => '',
            'recommendation' => '',
            'generated_at' => date('c'),
            'model' => trim($model),
            'error' => trim($error),
        ]);
    }

    /**
     * @return array{summary: string, recommendation: string, generated_at: string, model: ?string}|null
     */
    public function findByTicketId(int $ticketId): ?array
    {
        if ($ticketId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT summary, recommendation, generated_at, model
             FROM invgate_ticket_recommendations
             WHERE ticket_id = :ticket_id
               AND summary <> \'\'
               AND recommendation <> \'\''
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'summary' => (string) ($row['summary'] ?? ''),
            'recommendation' => (string) ($row['recommendation'] ?? ''),
            'generated_at' => (string) ($row['generated_at'] ?? ''),
            'model' => isset($row['model']) && trim((string) $row['model']) !== '' ? (string) $row['model'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTeam(int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $finalStatusIds = InvgateFinalStatuses::ids();
        $finalPlaceholders = InvgateFinalStatuses::sqlNotInPlaceholders();
        $stmt = $this->pdo->prepare(
            'SELECT it.id, it.person_id, it.invgate_incident_id, it.user_id, it.title, it.description,
                    it.status_id, st.name AS status_name,
                    it.type_id, ty.name AS type_name,
                    it.category_id, cat.name AS category_name,
                    it.created_at, it.last_update, it.priority,
                    tp.display_name AS person_display_name,
                    rec.summary, rec.recommendation, rec.generated_at, rec.model
             FROM invgate_tickets it
             LEFT JOIN team_people tp ON tp.id = it.person_id
             LEFT JOIN invgate_statuses st ON st.invgate_id = it.status_id
             LEFT JOIN invgate_types ty ON ty.invgate_id = it.type_id
             LEFT JOIN invgate_categories cat ON cat.invgate_id = it.category_id
             LEFT JOIN invgate_ticket_recommendations rec ON rec.ticket_id = it.id
             WHERE (tp.team_id = ? OR it.person_id IS NULL)
               AND (it.status_id IS NULL OR it.status_id NOT IN (' . $finalPlaceholders . '))
             ORDER BY it.last_update DESC'
        );
        $stmt->execute(array_merge([$teamId], $finalStatusIds));

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $summary = isset($row['summary']) ? trim((string) $row['summary']) : '';
            $recommendation = isset($row['recommendation']) ? trim((string) $row['recommendation']) : '';
            $out[] = [
                'id' => (int) $row['id'],
                'invgate_incident_id' => (int) $row['invgate_incident_id'],
                'title' => (string) ($row['title'] ?? ''),
                'person_display_name' => isset($row['person_display_name']) && trim((string) $row['person_display_name']) !== ''
                    ? (string) $row['person_display_name']
                    : null,
                'status_id' => $this->mapOptionalIntColumn($row['status_id'] ?? null),
                'status_name' => isset($row['status_name']) && trim((string) $row['status_name']) !== ''
                    ? (string) $row['status_name']
                    : null,
                'type_name' => isset($row['type_name']) && trim((string) $row['type_name']) !== ''
                    ? (string) $row['type_name']
                    : null,
                'category_name' => isset($row['category_name']) && trim((string) $row['category_name']) !== ''
                    ? (string) $row['category_name']
                    : null,
                'last_update' => (string) ($row['last_update'] ?? '0'),
                'generated_at' => isset($row['generated_at']) && trim((string) $row['generated_at']) !== ''
                    ? (string) $row['generated_at']
                    : null,
                'has_recommendation' => ($summary !== '' && $recommendation !== ''),
                'summary_preview' => $summary !== '' ? $this->shortText($summary, 160) : null,
            ];
        }

        return $out;
    }

    private function shortText(string $text, int $maxLen): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLen - 1)) . '…';
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
