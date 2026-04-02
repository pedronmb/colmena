<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TopicScales;

final class Topic
{
    /** @var int */
    private $id;
    /** @var int */
    private $teamId;
    /** @var int */
    private $authorId;
    /** @var int|null */
    private $personId;
    /** @var string */
    private $title;
    /** @var string|null */
    private $body;
    /** @var string Prioridad / urgencia (eje típico “urgente” en la matriz) */
    private $priority;
    /** @var string Importancia (eje típico “importante” en la matriz) */
    private $importance;
    /** @var string */
    private $status;
    /** @var string */
    private $createdAt;
    /** @var string */
    private $updatedAt;
    /** @var string|null */
    private $completedAt;

    public function __construct(
        int $id,
        int $teamId,
        int $authorId,
        ?int $personId,
        string $title,
        ?string $body,
        string $priority,
        string $importance,
        string $status,
        string $createdAt,
        string $updatedAt,
        ?string $completedAt
    ) {
        $this->id = $id;
        $this->teamId = $teamId;
        $this->authorId = $authorId;
        $this->personId = $personId;
        $this->title = $title;
        $this->body = $body;
        $this->priority = $priority;
        $this->importance = $importance;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->completedAt = $completedAt;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $pid = null;
        if (isset($row['person_id']) && $row['person_id'] !== null && $row['person_id'] !== '') {
            $pid = (int) $row['person_id'];
        }

        $completed = null;
        if (isset($row['completed_at']) && $row['completed_at'] !== null && $row['completed_at'] !== '') {
            $completed = (string) $row['completed_at'];
        }

        $importance = isset($row['importance']) ? (string) $row['importance'] : 'medium';
        if (!in_array($importance, TopicScales::IMPORTANCE_LEVELS, true)) {
            $importance = 'medium';
        }

        $priority = isset($row['priority']) ? (string) $row['priority'] : 'medium';
        if (!in_array($priority, TopicScales::PRIORITY_LEVELS, true)) {
            $priority = 'medium';
        }

        return new self(
            (int) $row['id'],
            (int) $row['team_id'],
            (int) $row['author_id'],
            $pid,
            (string) $row['title'],
            isset($row['body']) ? (string) $row['body'] : null,
            $priority,
            $importance,
            (string) $row['status'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $completed
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->teamId,
            'author_id' => $this->authorId,
            'person_id' => $this->personId,
            'title' => $this->title,
            'body' => $this->body,
            'priority' => $this->priority,
            'importance' => $this->importance,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
