<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserFileRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, original_name, mime_type, size_bytes, created_at
             FROM user_files WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int) $row['id'],
                'original_name' => (string) $row['original_name'],
                'mime_type' => isset($row['mime_type']) && $row['mime_type'] !== null ? (string) $row['mime_type'] : null,
                'size_bytes' => (int) $row['size_bytes'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * @return array{id:int,user_id:int,original_name:string,stored_name:string,mime_type:?string,size_bytes:int}|null
     */
    public function findByIdForUser(int $fileId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, original_name, stored_name, mime_type, size_bytes
             FROM user_files WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $fileId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'original_name' => (string) $row['original_name'],
            'stored_name' => (string) $row['stored_name'],
            'mime_type' => isset($row['mime_type']) && $row['mime_type'] !== null ? (string) $row['mime_type'] : null,
            'size_bytes' => (int) $row['size_bytes'],
        ];
    }

    public function create(
        int $userId,
        string $originalName,
        string $storedName,
        ?string $mimeType,
        int $sizeBytes
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_files (user_id, original_name, stored_name, mime_type, size_bytes, created_at)
             VALUES (:uid, :oname, :sname, :mime, :size, datetime(\'now\'))'
        );
        $stmt->execute([
            'uid' => $userId,
            'oname' => $originalName,
            'sname' => $storedName,
            'mime' => $mimeType,
            'size' => $sizeBytes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Elimina la fila y devuelve el nombre almacenado en disco para borrar el fichero, o null si no existía.
     */
    public function deleteByIdForUser(int $fileId, int $userId): ?string
    {
        $row = $this->findByIdForUser($fileId, $userId);
        if ($row === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('DELETE FROM user_files WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $fileId, 'uid' => $userId]);

        return $stmt->rowCount() > 0 ? $row['stored_name'] : null;
    }
}
