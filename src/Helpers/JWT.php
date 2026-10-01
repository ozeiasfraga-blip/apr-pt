<?php

namespace App\Helpers;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Exception;

class JWT
{
    private static function getSecret(): string
    {
        return $_ENV['JWT_SECRET'] ?? 'default_secret_change_me';
    }

    public static function encode(array $payload): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + (int)($_ENV['JWT_EXPIRY'] ?? 43200);
        return FirebaseJWT::encode($payload, self::getSecret(), 'HS256');
    }

    public static function decode(string $token): ?object
    {
        try {
            return FirebaseJWT::decode($token, new Key(self::getSecret(), 'HS256'));
        } catch (ExpiredException $e) {
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}
