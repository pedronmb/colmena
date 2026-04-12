<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Connection;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\PersonalWorkspaceService;

final class PersonalTeamBootstrap
{
    /**
     * @param array<string, mixed> $config
     */
    public static function teamId(array $config, AuthService $auth): int
    {
        $user = $auth->currentUser();
        if ($user === null) {
            throw new \RuntimeException('No autenticado');
        }
        $pdo = Connection::get($config);

        return PersonalWorkspaceService::ensureForUser($pdo, (int) $user['id']);
    }
}
