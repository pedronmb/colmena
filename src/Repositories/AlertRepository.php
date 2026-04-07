<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AlertRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Listado completo para gestión (orden por fecha).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, author_id, title, body, due_date, created_at, updated_at
             FROM team_alerts WHERE team_id = :tid ORDER BY due_date ASC, id ASC'
        );
        $stmt->execute(['tid' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    /**
     * Avisos a mostrar tras login: solo alertas cuya fecha de cumplimiento es hoy (no antes ni después).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDueForBanner(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, author_id, title, body, due_date, created_at, updated_at
             FROM team_alerts
             WHERE team_id = :tid
               AND due_date = date(\'now\')
             ORDER BY id ASC'
        );
        $stmt->execute(['tid' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    public function create(
        int $teamId,
        int $authorId,
        string $title,
        ?string $body,
        string $dueDateYmd
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_alerts (team_id, author_id, title, body, due_date, created_at, updated_at)
             VALUES (:tid, :aid, :title, :body, :due, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([
            'tid' => $teamId,
            'aid' => $authorId,
            'title' => $title,
            'body' => $body,
            'due' => $dueDateYmd,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $alertId,
        int $teamId,
        string $title,
        ?string $body,
        string $dueDateYmd
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE team_alerts
             SET title = :title, body = :body, due_date = :due, updated_at = datetime(\'now\')
             WHERE id = :id AND team_id = :tid'
        );
        $stmt->execute([
            'id' => $alertId,
            'tid' => $teamId,
            'title' => $title,
            'body' => $body,
            'due' => $dueDateYmd,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $alertId, int $teamId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM team_alerts WHERE id = :id AND team_id = :tid');
        $stmt->execute(['id' => $alertId, 'tid' => $teamId]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'author_id' => (int) $row['author_id'],
            'title' => (string) $row['title'],
            'body' => isset($row['body']) && $row['body'] !== null ? (string) $row['body'] : null,
            'due_date' => (string) $row['due_date'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
