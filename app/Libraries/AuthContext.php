<?php
namespace App\Libraries;

class AuthContext
{
    private static array $client = [];
    private static array $clientKey = [];

    /* ---------------- Client ---------------- */

    public static function setClient(array $client): void
    {
        self::$client = $client;
    }

    public static function client(): array
    {
        return self::$client;
    }

    public static function clientId(): int
    {
        return (int) (self::$client['id'] ?? 0);
    }

    public static function hasClient(): bool
    {
        return !empty(self::$client);
    }

    /* ---------------- API Key ---------------- */

    public static function setClientKey(array $clientKey): void
    {
        self::$clientKey = $clientKey;
    }

    public static function clientKey(): array
    {
        return self::$clientKey;
    }

    public static function clientKeyId(): int
    {
        return (int) (self::$clientKey['id'] ?? 0);
    }

    /* ---------------- Cleanup ---------------- */

    public static function reset(): void
    {
        self::$client = [];
        self::$clientKey = [];
    }
}
