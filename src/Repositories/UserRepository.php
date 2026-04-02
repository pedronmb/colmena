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

    /** @return array{id:int,email:string,display_name:string,password_hash:string,role:string,availability:string}|null */
    public function findWithPasswordByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, password_hash, role, availability FROM users WHERE email = :email LIMIT 1'
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
        ];
    }

    /** @return array{id:int,email:string,display_name:string,role:string,availability:string}|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role, availability FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'availability' => (string) $row['availability'],
        ];
    }
}
