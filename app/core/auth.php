<?php

final class Auth
{
    private const SESSION_KEY = 'user_id';

    public static function check(): bool
    {
        return Session::get(self::SESSION_KEY) !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);
        return $id !== null ? (int) $id : null;
    }

    public static function login(int $userId): void
    {
        Session::set(self::SESSION_KEY, $userId);
    }

    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::destroy();
    }
}
