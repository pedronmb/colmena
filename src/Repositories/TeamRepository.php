<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class TeamRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function isMember(int $teamId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM team_members WHERE team_id = :t AND user_id = :u LIMIT 1'
        );
        $stmt->execute(['t' => $teamId, 'u' => $userId]);
        return $stmt->fetch() !== false;
    }

    public function addMember(int $teamId, int $userId, string $roleInTeam = 'member'): void
    {
        $allowed = ['owner', 'lead', 'member'];
        if (!in_array($roleInTeam, $allowed, true)) {
            $roleInTeam = 'member';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, role_in_team) VALUES (:t, :u, :r)'
        );
        $stmt->execute(['t' => $teamId, 'u' => $userId, 'r' => $roleInTeam]);
    }

    /**
     * @return array<int, array{id:int,email:string,display_name:string,role:string,availability:string}>
     */
    public function listMembers(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.role, u.availability
             FROM users u
             INNER JOIN team_members tm ON tm.user_id = u.id AND tm.team_id = :tid
             ORDER BY u.display_name COLLATE NOCASE ASC'
        );
        $stmt->execute(['tid' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'availability' => (string) $row['availability'],
            ];
        }

        return $out;
    }
}
