<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AuthService
{
    private const SESSION_KEY = 'user_id';

    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $row = $this->users->findWithPasswordByEmail($email);
        if ($row === null) {
            return false;
        }
        if (!password_verify($password, $row['password_hash'])) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = $row['id'];
        $_SESSION['flash_due_alerts'] = true;
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
    }

    public function userId(): ?int
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        return (int) $_SESSION[self::SESSION_KEY];
    }

    /** @return array{id:int,email:string,display_name:string,role:string,availability:string,personal_team_id:?int}|null */
    public function currentUser(): ?array
    {
        $id = $this->userId();
        if ($id === null) {
            return null;
        }
        return $this->users->findById($id);
    }
}
