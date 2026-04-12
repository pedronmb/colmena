<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserScratchpadRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getContent(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT content FROM user_scratchpad WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return '';
        }

        return (string) $row['content'];
    }

    public function saveContent(int $userId, string $content): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_scratchpad (user_id, content, updated_at)
             VALUES (:uid, :content, datetime(\'now\'))
             ON CONFLICT(user_id) DO UPDATE SET
               content = excluded.content,
               updated_at = datetime(\'now\')'
        );
        $stmt->execute([
            'uid' => $userId,
            'content' => $content,
        ]);
    }
}
