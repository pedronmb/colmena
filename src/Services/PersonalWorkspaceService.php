<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use PDO;

final class PersonalWorkspaceService
{
    /**
     * Devuelve el team_id del espacio personal del usuario, creándolo o enlazándolo si hace falta.
     */
    public static function ensureForUser(PDO $pdo, int $userId): int
    {
        $users = new UserRepository($pdo);
        $user = $users->findById($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('Usuario no encontrado');
        }
        $tid = isset($user['personal_team_id']) && $user['personal_team_id'] !== null
            ? (int) $user['personal_team_id']
            : 0;
        if ($tid > 0) {
            return $tid;
        }

        $teams = new TeamRepository($pdo);
        $existing = $teams->firstTeamIdForUser($userId);
        if ($existing !== null) {
            $users->setPersonalTeamId($userId, $existing);

            return $existing;
        }

        return self::createAndAssign($pdo, $userId, (string) $user['display_name']);
    }

    /**
     * Crea un equipo nuevo, lo asigna como personal_team_id y añade al usuario como propietario.
     */
    public static function createAndAssign(PDO $pdo, int $userId, string $displayName): int
    {
        $teams = new TeamRepository($pdo);
        $users = new UserRepository($pdo);
        $name = 'Mi espacio — ' . trim($displayName);
        if ($name === 'Mi espacio —') {
            $name = 'Mi espacio';
        }
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }
        $teamId = $teams->create($name, 'Espacio de trabajo personal');
        $teams->addMember($teamId, $userId, 'owner');
        $users->setPersonalTeamId($userId, $teamId);

        return $teamId;
    }
}
