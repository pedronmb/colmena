<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\BirthdayNormalizer;
use PDO;

final class TeamPersonRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(
        int $teamId,
        string $displayName,
        ?string $email,
        ?string $role,
        ?string $birthday,
        ?string $extraInfo
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_people (team_id, display_name, email, role, birthday, extra_info)
             VALUES (:tid, :name, :email, :role, :birthday, :extra)'
        );
        $stmt->execute([
            'tid' => $teamId,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $displayName,
        ?string $email,
        ?string $role,
        ?string $birthday,
        ?string $extraInfo
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE team_people SET
                display_name = :name,
                email = :email,
                role = :role,
                birthday = :birthday,
                extra_info = :extra
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'display_name' => (string) $row['display_name'],
            'email' => isset($row['email']) && $row['email'] !== null && $row['email'] !== ''
                ? (string) $row['email']
                : null,
            'role' => isset($row['role']) && $row['role'] !== null && trim((string) $row['role']) !== ''
                ? trim((string) $row['role'])
                : null,
            'birthday' => BirthdayNormalizer::canonicalMonthDay($row['birthday'] ?? null),
            'extra_info' => isset($row['extra_info']) && $row['extra_info'] !== null && $row['extra_info'] !== ''
                ? (string) $row['extra_info']
                : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array{id:int,team_id:int,display_name:string,email:?string,role:?string,birthday:?string,extra_info:?string,created_at:string}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, display_name, email, role, birthday, extra_info, created_at
             FROM team_people WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<int, array{id:int,team_id:int,display_name:string,email:?string,role:?string,birthday:?string,extra_info:?string,created_at:string}>
     */
    public function listByTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, display_name, email, role, birthday, extra_info, created_at
             FROM team_people
             WHERE team_id = :tid
             ORDER BY display_name COLLATE NOCASE ASC'
        );
        $stmt->execute(['tid' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    public function belongsToTeam(int $personId, int $teamId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM team_people WHERE id = :id AND team_id = :tid LIMIT 1'
        );
        $stmt->execute(['id' => $personId, 'tid' => $teamId]);

        return $stmt->fetch() !== false;
    }
}
