<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{id:int,email:string,display_name:string,password_hash:string,role:string,availability:string,personal_team_id:?int}|null */
    public function findWithPasswordByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, password_hash, role, availability, personal_team_id FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'password_hash' => (string) $row['password_hash'],
            'role' => (string) $row['role'],
            'availability' => (string) $row['availability'],
            'personal_team_id' => isset($row['personal_team_id']) && $row['personal_team_id'] !== null
                ? (int) $row['personal_team_id']
                : null,
        ];
    }

    /** @return array{id:int,email:string,display_name:string,role:string,availability:string,personal_team_id:?int}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role, availability, personal_team_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'availability' => (string) $row['availability'],
            'personal_team_id' => isset($row['personal_team_id']) && $row['personal_team_id'] !== null
                ? (int) $row['personal_team_id']
                : null,
        ];
    }

    public function setPersonalTeamId(int $userId, int $teamId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET personal_team_id = :tid WHERE id = :uid'
        );
        $stmt->execute(['tid' => $teamId, 'uid' => $userId]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        return $stmt->fetch() !== false;
    }

    /**
     * @return array<int, array{id:int,email:string,display_name:string,role:string,availability:string,created_at:string}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, email, display_name, role, availability, created_at
             FROM users
             ORDER BY display_name COLLATE NOCASE ASC'
        );
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'availability' => (string) $row['availability'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    public function create(
        string $email,
        string $displayName,
        string $passwordHash,
        string $role,
        string $availability
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, password_hash, role, availability)
             VALUES (:email, :name, :hash, :role, :avail)'
        );
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'name' => trim($displayName),
            'hash' => $passwordHash,
            'role' => $role,
            'avail' => $availability,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
