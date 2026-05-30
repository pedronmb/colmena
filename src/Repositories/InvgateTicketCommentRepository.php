<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class InvgateTicketCommentRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Inserta un comentario si no existe (clave: ticket local + msg_num).
     *
     * @param array<string, mixed> $comment
     */
    public function insertIfNew(int $ticketId, array $comment): bool
    {
        if ($ticketId <= 0) {
            return false;
        }

        $msgNum = isset($comment['msg_num']) ? (int) $comment['msg_num'] : 0;
        $message = isset($comment['message']) ? trim((string) $comment['message']) : '';
        if ($msgNum <= 0 || $message === '') {
            return false;
        }

        $authorId = isset($comment['author_id']) ? (int) $comment['author_id'] : null;
        if ($authorId !== null && $authorId <= 0) {
            $authorId = null;
        }

        $createdAt = isset($comment['created_at']) ? trim((string) $comment['created_at']) : '';
        if ($createdAt === '') {
            $createdAt = '0';
        }

        $isSolution = !empty($comment['is_solution']) ? 1 : 0;

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO invgate_ticket_comments (
                incident_id, author_id, message, created_at, msg_num, is_solution
             ) VALUES (
                :incident_id, :author_id, :message, :created_at, :msg_num, :is_solution
             )'
        );
        $stmt->execute([
            'incident_id' => $ticketId,
            'author_id' => $authorId,
            'message' => $message,
            'created_at' => $createdAt,
            'msg_num' => $msgNum,
            'is_solution' => $isSolution,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array{
     *   id: int,
     *   author_id: ?int,
     *   message: string,
     *   created_at: string,
     *   msg_num: int,
     *   is_solution: bool
     * }>
     */
    public function listByTicketId(int $ticketId): array
    {
        if ($ticketId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, author_id, message, created_at, msg_num, is_solution
             FROM invgate_ticket_comments
             WHERE incident_id = :ticket_id
             ORDER BY created_at DESC, msg_num DESC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);

        $comments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $comments[] = [
                'id' => (int) $row['id'],
                'author_id' => $this->mapOptionalIntColumn($row['author_id'] ?? null),
                'message' => (string) $row['message'],
                'created_at' => (string) ($row['created_at'] ?? '0'),
                'msg_num' => (int) $row['msg_num'],
                'is_solution' => !empty($row['is_solution']),
            ];
        }

        return $comments;
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
