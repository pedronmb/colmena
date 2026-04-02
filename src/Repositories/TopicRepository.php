<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Topic;
use App\Support\TopicScales;
use PDO;

final class TopicRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(
        int $teamId,
        int $authorId,
        int $personId,
        string $title,
        ?string $body,
        string $priority,
        string $importance
    ): Topic {
        if (!in_array($priority, TopicScales::PRIORITY_LEVELS, true)) {
            $priority = 'medium';
        }
        if (!in_array($importance, TopicScales::IMPORTANCE_LEVELS, true)) {
            $importance = 'medium';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO topics (team_id, author_id, person_id, title, body, priority, importance, status, created_at, updated_at, completed_at)
             VALUES (:team_id, :author_id, :person_id, :title, :body, :priority, :importance, :status, datetime(\'now\'), datetime(\'now\'), NULL)'
        );
        $stmt->execute([
            'team_id' => $teamId,
            'author_id' => $authorId,
            'person_id' => $personId,
            'title' => $title,
            'body' => $body,
            'priority' => $priority,
            'importance' => $importance,
            'status' => 'open',
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return $this->findById($id);
    }

    public function findById(int $id): Topic
    {
        $stmt = $this->pdo->prepare('SELECT * FROM topics WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException('Tema no encontrado');
        }
        return Topic::fromRow($row);
    }

    /**
     * @return Topic[]
     */
    public function listByTeam(int $teamId, bool $includeDone = true, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM topics WHERE team_id = :team_id';
        if (!$includeDone) {
            $sql .= " AND status != 'done'";
        }
        $sql .= ' ORDER BY datetime(updated_at) DESC, id DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['team_id' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = Topic::fromRow($row);
        }

        return $out;
    }

    public function updateStatus(int $topicId, string $status): Topic
    {
        $allowed = ['open', 'in_progress', 'blocked', 'done', 'archived'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Estado inválido');
        }
        if ($status === 'done') {
            $stmt = $this->pdo->prepare(
                'UPDATE topics SET status = :st, updated_at = datetime(\'now\'), completed_at = datetime(\'now\') WHERE id = :id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE topics SET status = :st, updated_at = datetime(\'now\'), completed_at = NULL WHERE id = :id'
            );
        }
        $stmt->execute(['st' => $status, 'id' => $topicId]);

        return $this->findById($topicId);
    }

    public function getTeamIdForTopic(int $topicId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT team_id FROM topics WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $topicId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (int) $row['team_id'];
    }

    public function updateContent(
        int $topicId,
        int $teamId,
        int $personId,
        string $title,
        ?string $body,
        string $priority,
        string $importance
    ): Topic {
        if (!in_array($priority, TopicScales::PRIORITY_LEVELS, true)) {
            $priority = 'medium';
        }
        if (!in_array($importance, TopicScales::IMPORTANCE_LEVELS, true)) {
            $importance = 'medium';
        }
        $stmt = $this->pdo->prepare(
            'UPDATE topics SET person_id = :pid, title = :title, body = :body, priority = :pr, importance = :im, updated_at = datetime(\'now\')
             WHERE id = :id AND team_id = :tid'
        );
        $stmt->execute([
            'pid' => $personId,
            'title' => $title,
            'body' => $body,
            'pr' => $priority,
            'im' => $importance,
            'id' => $topicId,
            'tid' => $teamId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Tema no encontrado');
        }

        return $this->findById($topicId);
    }
}
