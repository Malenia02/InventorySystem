<?php
declare(strict_types=1);

namespace InventorySystem\WebSocket;

final class WebSocketSecurity
{
    private const MAX_TOKEN_AGE_SECONDS = 300;

    public static function buildConnectionUrl(
        string $baseUrl,
        int $userId,
        string $role,
        string $origin,
        ?int $timestamp = null
    ): string {
        $timestamp = $timestamp ?? time();
        $signature = self::signature($userId, $role, $origin, $timestamp);

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query([
            'uid' => $userId,
            'role' => $role,
            'ts' => $timestamp,
            'sig' => $signature,
        ]);
    }

    public static function isValidToken(
        int $userId,
        string $role,
        string $origin,
        int $timestamp,
        string $signature
    ): bool {
        if ($userId <= 0 || $role === '' || $origin === '' || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > self::MAX_TOKEN_AGE_SECONDS) {
            return false;
        }

        return hash_equals(self::signature($userId, $role, $origin, $timestamp), $signature);
    }

    public static function normalizedOrigin(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }

    private static function signature(int $userId, string $role, string $origin, int $timestamp): string
    {
        $payload = implode('|', [
            $userId,
            strtolower(trim($role)),
            self::normalizedOrigin($origin),
            $timestamp,
        ]);

        return hash_hmac('sha256', $payload, self::secret());
    }

    private static function secret(): string
    {
        if (function_exists('app_secret_value')) {
            $secret = app_secret_value('WS_SHARED_SECRET', true);
            if ($secret !== '') {
                return $secret;
            }

            $appKey = app_secret_value('APP_KEY', true);
            if ($appKey !== '') {
                return $appKey;
            }
        }

        if (function_exists('env_value')) {
            $secret = trim((string) env_value('WS_SHARED_SECRET', env_value('APP_KEY', '')));
            if ($secret !== '') {
                return $secret;
            }
        }

        throw new \RuntimeException('WS_SHARED_SECRET or APP_KEY is required for websocket token signing.');
    }
}
